<?php
// Asistencia móvil del docente: consulta y registro acotados a sus propios cursos.
// Nuevas marcaciones se guardan directamente. Cambios sobre registros existentes
// pasan por el mismo flujo de autorización usado por asistencia web.
ini_set('display_errors', '0');
date_default_timezone_set('America/Lima');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function tam_reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function tam_table(mysqli $db, string $name): bool {
    $safe = $db->real_escape_string($name);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}
function tam_col(mysqli $db, string $table, string $column): bool {
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM " . $t . " LIKE '$c'");
    return $q && $q->num_rows > 0;
}
function tam_audit(
    mysqli $db,
    int $school,
    int $attendance,
    int $student,
    int $user,
    string $action,
    array $details = []
): void {
    if (!tam_table($db, 'attendance_audit_log')) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $s = $db->prepare(
        'INSERT INTO attendance_audit_log
         (school_id,attendance_id,student_id,user_id,action,details,ip_address)
         VALUES(?,NULLIF(?,0),NULLIF(?,0),NULLIF(?,0),?,?,?)'
    );
    if (!$s) return;
    $s->bind_param('iiiisss', $school, $attendance, $student, $user, $action, $json, $ip);
    $s->execute();
    $s->close();
}
function tam_notify_state(
    mysqli $db,
    int $school,
    int $student,
    int $attendance,
    string $date,
    string $oldStatus,
    string $newStatus,
    string $time = ''
): void {
    try {
        require_once __DIR__ . '/../includes/push_notifications.php';
        push_send_attendance_state_notification(
            $db,
            $school,
            $student,
            $attendance,
            $date,
            $oldStatus,
            $newStatus,
            $time
        );
    } catch (Throwable $e) {
        error_log('[teacher attendance push] ' . $e->getMessage());
    }
}
function tam_create_change_request(
    mysqli $db,
    int $school,
    int $user,
    array $payload,
    string $reason
): int {
    if (!tam_table($db, 'attendance_change_requests')) {
        throw new Exception(
            'La instalación web requiere sql/attendance_module_upgrade.sql '
            . 'para solicitar modificaciones de asistencia.'
        );
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $type = 'Editar nómina';
    $s = $db->prepare(
        "INSERT INTO attendance_change_requests
         (school_id,attendance_id,requested_by,request_type,request_payload,reason,status)
         VALUES(?,NULL,?,?,?,?, 'Pendiente')"
    );
    if (!$s) throw new Exception('No se pudo preparar la solicitud de cambios.');
    $s->bind_param('iisss', $school, $user, $type, $json, $reason);
    if (!$s->execute()) {
        $message = $s->error;
        $s->close();
        throw new Exception($message);
    }
    $id = (int)$s->insert_id;
    $s->close();
    return $id;
}
function tam_normalize_status(string $status): string {
    $status = trim($status);
    if (in_array($status, ['Normal', 'Temprano'], true)) return 'Presente';
    return $status;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tam_reply(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}

$sid = trim((string)($_POST['sid'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $sid)) {
    tam_reply(['status' => 'error', 'message' => 'Inicia sesión nuevamente.'], 401);
}

ob_start();
require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../db_connect.php';
ob_end_clean();
$conn->query("SET time_zone = '-05:00'");

$uid = (int)($_SESSION['login_id'] ?? 0);
$school = (int)($_SESSION['login_school_id'] ?? 0);
$teacher = (int)($_SESSION['login_teacher_id'] ?? 0);
if ((int)($_SESSION['login_type'] ?? 0) !== 2 ||
    $school <= 0 || $teacher <= 0 || $uid <= 0) {
    tam_reply(['status' => 'error', 'message' => 'No autorizado.'], 403);
}

$userStatusCol = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$activeUserFilter = ($userStatusCol && $userStatusCol->num_rows)
    ? " AND u.status='Activo'" : '';
$check = $conn->prepare(
    "SELECT u.id
     FROM users u
     INNER JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id
     WHERE u.id=? AND u.school_id=? AND u.teacher_id=? AND u.type=2
       AND t.status='Activo' {$activeUserFilter}
     LIMIT 1"
);
if (!$check) {
    tam_reply(['status' => 'error', 'message' => 'Error al validar docente.'], 500);
}
$check->bind_param('iii', $uid, $school, $teacher);
$check->execute();
$allowed = (bool)$check->get_result()->fetch_assoc();
$check->close();
if (!$allowed) {
    tam_reply(['status' => 'error', 'message' => 'La cuenta docente no está habilitada.'], 403);
}

$action = trim((string)($_POST['action'] ?? 'load'));
if (!in_array($action, ['load', 'save'], true)) {
    tam_reply(['status' => 'error', 'message' => 'Acción no válida.'], 400);
}

$tcid = (int)($_POST['teacher_course_id'] ?? 0);
$date = trim((string)($_POST['date'] ?? date('Y-m-d')));
$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($tcid <= 0 || !$dt || $dt->format('Y-m-d') !== $date) {
    tam_reply([
        'status' => 'error',
        'message' => 'Selecciona un curso y una fecha válidos.',
    ], 422);
}
$today = new DateTimeImmutable('today');
if ($dt > $today) {
    tam_reply([
        'status' => 'error',
        'message' => 'No se puede registrar asistencia en una fecha futura.',
    ], 422);
}

$assignmentStmt = $conn->prepare(
    "SELECT tc.id,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,
            tc.academic_year_id,ac.name course_name,ac.level,
            ac.is_active course_active,
            COALESCE(ac.course_status,'Activo') course_status,
            ay.is_active,ay.start_date,ay.end_date
     FROM teacher_courses tc
     INNER JOIN academic_courses ac
       ON ac.id=tc.course_id AND ac.school_id=tc.school_id
     INNER JOIN academic_year ay
       ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
     WHERE tc.id=? AND tc.teacher_id=? AND tc.school_id=?
     LIMIT 1"
);
if (!$assignmentStmt) {
    tam_reply(['status' => 'error', 'message' => 'Error al consultar curso.'], 500);
}
$assignmentStmt->bind_param('iii', $tcid, $teacher, $school);
$assignmentStmt->execute();
$assignment = $assignmentStmt->get_result()->fetch_assoc();
$assignmentStmt->close();
if (!$assignment) {
    tam_reply([
        'status' => 'error',
        'message' => 'Este curso no está asignado al docente.',
    ], 403);
}
if ((int)$assignment['is_active'] !== 1 ||
    (int)$assignment['course_active'] !== 1 ||
    (string)$assignment['course_status'] !== 'Activo') {
    tam_reply([
        'status' => 'error',
        'message' => 'La asistencia solo puede gestionarse en cursos del año activo.',
    ], 422);
}
if (!empty($assignment['start_date']) && !empty($assignment['end_date']) &&
    ($date < $assignment['start_date'] || $date > $assignment['end_date'])) {
    tam_reply([
        'status' => 'error',
        'message' => 'La fecha no pertenece al año académico de este curso.',
    ], 422);
}

if (!tam_table($conn, 'asistencia')) {
    tam_reply([
        'status' => 'error',
        'message' => 'El colegio aún no tiene habilitado el módulo de asistencia.',
    ], 409);
}
$upgraded = tam_col($conn, 'asistencia', 'school_id')
    && tam_col($conn, 'asistencia', 'academic_year_id')
    && tam_col($conn, 'asistencia', 'source')
    && tam_col($conn, 'asistencia', 'created_by')
    && tam_col($conn, 'asistencia', 'updated_by')
    && tam_col($conn, 'asistencia', 'is_cancelled');

$level = (string)$assignment['level'];
$grade = (string)$assignment['grado'];
$section = (string)$assignment['seccion'];
$yearId = (int)$assignment['academic_year_id'];

$closed = false;
if (tam_table($conn, 'attendance_day_closures')) {
    $closedStmt = $conn->prepare(
        "SELECT id
         FROM attendance_day_closures
         WHERE school_id=? AND attendance_date=? AND nivel=? AND grado=?
           AND seccion=? AND status='Cerrada'
         LIMIT 1"
    );
    if ($closedStmt) {
        $closedStmt->bind_param('issss', $school, $date, $level, $grade, $section);
        $closedStmt->execute();
        $closed = (bool)$closedStmt->get_result()->fetch_assoc();
        $closedStmt->close();
    }
}

$calendar = null;
if (tam_table($conn, 'attendance_calendar')) {
    $calendarStmt = $conn->prepare(
        'SELECT name,day_type,notes
         FROM attendance_calendar
         WHERE school_id=? AND calendar_date=?
         LIMIT 1'
    );
    if ($calendarStmt) {
        $calendarStmt->bind_param('is', $school, $date);
        $calendarStmt->execute();
        $calendar = $calendarStmt->get_result()->fetch_assoc() ?: null;
        $calendarStmt->close();
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($action === 'save') {
    if (!$upgraded) {
        tam_reply([
            'status' => 'error',
            'migration_required' => true,
            'message' => 'Para registrar asistencia desde la app ejecuta '
                . 'sql/attendance_module_upgrade.sql en esta instalación.',
        ], 409);
    }
    if ($closed) {
        tam_reply([
            'status' => 'error',
            'message' => 'La asistencia de esta aula está cerrada para la fecha seleccionada.',
        ], 409);
    }

    $token = trim((string)($_POST['csrf_token'] ?? ''));
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $sessionToken === '' ||
        !hash_equals($sessionToken, $token)) {
        tam_reply([
            'status' => 'error',
            'message' => 'La sesión de seguridad venció. Recarga la asistencia.',
        ], 403);
    }

    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($rows) || !$rows) {
        tam_reply([
            'status' => 'error',
            'message' => 'No hay cambios de asistencia para guardar.',
        ], 422);
    }

    $validStatuses = [
        'Presente', 'Tarde', 'Ausente', 'Ausente Justificada', 'Permiso'
    ];
    $created = 0;
    $unchanged = 0;
    $changeItems = [];
    $pushItems = [];

    $conn->begin_transaction();
    try {
        foreach ($rows as $row) {
            $studentId = (int)($row['student_id'] ?? 0);
            $status = trim((string)($row['status'] ?? ''));
            $time = trim((string)($row['time'] ?? ''));
            $notes = trim((string)($row['notes'] ?? ''));

            if ($studentId <= 0 || !in_array($status, $validStatuses, true)) {
                throw new Exception('Se recibió una marcación inválida.');
            }

            $studentStmt = $conn->prepare(
                "SELECT id,name,id_no
                 FROM student
                 WHERE id=? AND school_id=? AND status='Activo'
                   AND nivel=? AND grado=?
                   AND COALESCE(NULLIF(seccion,''),'U')=?
                 LIMIT 1"
            );
            $studentStmt->bind_param(
                'iisss',
                $studentId,
                $school,
                $level,
                $grade,
                $section
            );
            $studentStmt->execute();
            $student = $studentStmt->get_result()->fetch_assoc();
            $studentStmt->close();
            if (!$student) {
                throw new Exception('Un estudiante ya no pertenece al aula seleccionada.');
            }

            if (in_array($status, ['Ausente', 'Ausente Justificada', 'Permiso'], true)) {
                $time = '00:00:00';
            } elseif ($time === '') {
                $time = date('H:i:s');
            } elseif (strlen($time) === 5) {
                $time .= ':00';
            }

            $existingStmt = $conn->prepare(
                "SELECT id,hora,estado,notes,justification_status
                 FROM asistencia
                 WHERE student_id=? AND school_id=? AND fecha=?
                   AND tipo='Entrada' AND is_cancelled=0
                 LIMIT 1 FOR UPDATE"
            );
            $existingStmt->bind_param('iis', $studentId, $school, $date);
            $existingStmt->execute();
            $existing = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();

            $justification = $status === 'Ausente Justificada'
                ? 'Aprobada' : null;

            if ($existing) {
                $currentStatus = tam_normalize_status(
                    (string)($existing['estado'] ?? '')
                );
                $currentTime = substr((string)($existing['hora'] ?? ''), 0, 8);
                $changed = $currentStatus !== $status
                    || $currentTime !== substr($time, 0, 8)
                    || (string)($existing['notes'] ?? '') !== $notes;

                if (!$changed) {
                    $unchanged++;
                    continue;
                }

                $changeItems[] = [
                    'attendance_id' => (int)$existing['id'],
                    'student_id' => $studentId,
                    'student_name' => (string)$student['name'],
                    'student_code' => (string)$student['id_no'],
                    'previous' => [
                        'status' => $currentStatus,
                        'time' => (string)$existing['hora'],
                        'notes' => (string)($existing['notes'] ?? ''),
                    ],
                    'requested' => [
                        'status' => $status,
                        'time' => $time,
                        'notes' => $notes,
                        'justification_status' => $justification,
                    ],
                ];
                continue;
            }

            $insert = $conn->prepare(
                "INSERT INTO asistencia
                 (school_id,academic_year_id,student_id,fecha,tipo,hora,estado,
                  source,notes,justification_status,created_by,updated_by)
                 VALUES(?,?,?,?, 'Entrada',?,?, 'Docente App',?,?,?,?)"
            );
            if (!$insert) {
                throw new Exception('No se pudo preparar el registro de asistencia.');
            }
            $insert->bind_param(
                'iiisssssii',
                $school,
                $yearId,
                $studentId,
                $date,
                $time,
                $status,
                $notes,
                $justification,
                $uid,
                $uid
            );
            if (!$insert->execute()) {
                $message = $insert->error;
                $insert->close();
                throw new Exception($message);
            }
            $attendanceId = (int)$insert->insert_id;
            $insert->close();

            tam_audit(
                $conn,
                $school,
                $attendanceId,
                $studentId,
                $uid,
                'teacher_attendance_created',
                [
                    'teacher_course_id' => $tcid,
                    'date' => $date,
                    'status' => $status,
                ]
            );
            $pushItems[] = [
                'student' => $studentId,
                'attendance' => $attendanceId,
                'date' => $date,
                'old' => '',
                'new' => $status,
                'time' => $time,
            ];
            $created++;
        }

        $requested = 0;
        $requestId = 0;
        if ($changeItems) {
            $requested = count($changeItems);
            $requestId = tam_create_change_request(
                $conn,
                $school,
                $uid,
                [
                    'date' => $date,
                    'nivel' => $level,
                    'grado' => $grade,
                    'seccion' => $section,
                    'teacher_course_id' => $tcid,
                    'source' => 'teacher_mobile',
                    'changes' => $changeItems,
                ],
                "$requested cambios solicitados desde la app docente"
            );

            foreach ($changeItems as $item) {
                tam_audit(
                    $conn,
                    $school,
                    (int)$item['attendance_id'],
                    (int)$item['student_id'],
                    $uid,
                    'teacher_attendance_change_requested',
                    [
                        'request_id' => $requestId,
                        'teacher_course_id' => $tcid,
                        'date' => $date,
                        'status' => $item['requested']['status'],
                        'time' => $item['requested']['time'],
                    ]
                );
            }
        }

        $conn->commit();

        foreach ($pushItems as $push) {
            tam_notify_state(
                $conn,
                $school,
                $push['student'],
                $push['attendance'],
                $push['date'],
                $push['old'],
                $push['new'],
                $push['time']
            );
        }

        $parts = [];
        if ($created > 0) $parts[] = "$created marcaciones nuevas guardadas";
        if ($requested > 0) $parts[] = "$requested cambios enviados para autorización";
        if ($unchanged > 0) $parts[] = "$unchanged sin cambios";
        $message = $parts
            ? implode('. ', $parts) . '.'
            : 'No hubo cambios para guardar.';

        tam_reply([
            'status' => 'ok',
            'message' => $message,
            'saved' => $created,
            'requested' => $requested,
            'request_id' => $requestId,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        tam_reply([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 422);
    }
}

$cancelFilter = tam_col($conn, 'asistencia', 'is_cancelled')
    ? ' AND a.is_cancelled=0' : '';
$sql = "SELECT st.id,st.id_no,st.name,a.id attendance_id,a.hora,a.estado
        FROM student st
        LEFT JOIN asistencia a
          ON a.student_id=st.id AND a.school_id=? AND a.fecha=?
         AND a.tipo='Entrada'$cancelFilter
        WHERE st.school_id=? AND st.status='Activo' AND st.nivel=?
          AND st.grado=? AND COALESCE(NULLIF(st.seccion,''),'U')=?
        ORDER BY st.name";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    tam_reply(['status' => 'error', 'message' => 'No se pudo consultar asistencia.'], 500);
}
$stmt->bind_param('isisss', $school, $date, $school, $level, $grade, $section);
$stmt->execute();

$rows = [];
$summary = [
    'expected' => 0,
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'justified' => 0,
    'unmarked' => 0,
];
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $status = tam_normalize_status((string)($row['estado'] ?? ''));
    if ($status === '') $summary['unmarked']++;
    elseif ($status === 'Tarde') $summary['late']++;
    elseif ($status === 'Ausente Justificada') $summary['justified']++;
    elseif ($status === 'Ausente') $summary['absent']++;
    elseif ($status === 'Permiso') $summary['justified']++;
    else $summary['present']++;

    $summary['expected']++;
    $rows[] = [
        'id' => (int)$row['id'],
        'attendance_id' => (int)($row['attendance_id'] ?? 0),
        'dni' => (string)$row['id_no'],
        'name' => (string)$row['name'],
        'status' => $status,
        'time' => substr((string)($row['hora'] ?? ''), 0, 5),
    ];
}
$stmt->close();

tam_reply([
    'status' => 'ok',
    'date' => $date,
    'course' => $assignment,
    'summary' => $summary,
    'students' => $rows,
    'csrf_token' => (string)$_SESSION['csrf_token'],
    'read_only' => $closed || !$upgraded,
    'closed' => $closed,
    'migration_required' => !$upgraded,
    'holiday' => $calendar,
]);
