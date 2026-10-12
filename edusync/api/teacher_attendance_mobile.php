<?php
// Vista de asistencia por aula para docentes; consulta únicamente.
// Las modificaciones de asistencia siguen reservadas a los roles autorizados
// por attendance_api.php (Administración / Auxiliar).
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function tam_reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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
$uid = (int)($_SESSION['login_id'] ?? 0);
$school = (int)($_SESSION['login_school_id'] ?? 0);
$teacher = (int)($_SESSION['login_teacher_id'] ?? 0);
if ((int)($_SESSION['login_type'] ?? 0) !== 2 || $school <= 0 || $teacher <= 0 || $uid <= 0) {
    tam_reply(['status' => 'error', 'message' => 'No autorizado.'], 403);
}
$userStatusCol = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$activeUserFilter = ($userStatusCol && $userStatusCol->num_rows)
    ? " AND u.status='Activo'" : '';
$check = $conn->prepare("SELECT u.id FROM users u INNER JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id WHERE u.id=? AND u.school_id=? AND u.teacher_id=? AND u.type=2 AND t.status='Activo' {$activeUserFilter} LIMIT 1");
if (!$check) tam_reply(['status'=>'error','message'=>'Error al validar docente.'],500);
$check->bind_param('iii', $uid, $school, $teacher);
$check->execute();
$allowed = (bool)$check->get_result()->fetch_assoc();
$check->close();
if (!$allowed) tam_reply(['status'=>'error','message'=>'La cuenta docente no está habilitada.'],403);

$action = trim((string)($_POST['action'] ?? 'daily'));
$tcid = (int)($_POST['teacher_course_id'] ?? 0);
if ($tcid <= 0) {
    tam_reply(['status'=>'error','message'=>'Selecciona un curso válido.'],422);
}
$date = trim((string)($_POST['date'] ?? date('Y-m-d')));
if ($action === 'daily') {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        tam_reply(['status'=>'error','message'=>'Selecciona una fecha válida.'],422);
    }
}
$assignmentStmt = $conn->prepare(
    "SELECT tc.id,tc.academic_year_id,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,
            ac.name course_name,ac.level,ay.is_active
     FROM teacher_courses tc
     INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
     INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
     WHERE tc.id=? AND tc.teacher_id=? AND tc.school_id=? LIMIT 1"
);
if (!$assignmentStmt) tam_reply(['status'=>'error','message'=>'Error al consultar curso.'],500);
$assignmentStmt->bind_param('iii', $tcid, $teacher, $school);
$assignmentStmt->execute();
$assignment = $assignmentStmt->get_result()->fetch_assoc();
$assignmentStmt->close();
if (!$assignment) tam_reply(['status'=>'error','message'=>'Este curso no está asignado al docente.'],403);
if ((int)$assignment['is_active'] !== 1) {
    tam_reply(['status'=>'error','message'=>'La consulta de asistencia diaria está disponible para el año activo.'],422);
}

$tableReady = $conn->query("SHOW TABLES LIKE 'asistencia'");
if (!$tableReady || $tableReady->num_rows === 0) {
    tam_reply(['status'=>'error','message'=>'El colegio aún no tiene habilitado el módulo de asistencia.'],409);
}
$cancelCol = $conn->query("SHOW COLUMNS FROM asistencia LIKE 'is_cancelled'");
$cancelFilter = $cancelCol && $cancelCol->num_rows > 0 ? ' AND a.is_cancelled=0' : '';

if ($action === 'student_history') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $bimester = (int)($_POST['bimester'] ?? 0);
    if ($studentId <= 0 || $bimester < 1 || $bimester > 4) {
        tam_reply([
            'status' => 'error',
            'message' => 'Selecciona un estudiante y bimestre válidos.'
        ], 422);
    }

    $level = (string)$assignment['level'];
    $grade = (string)$assignment['grado'];
    $section = (string)$assignment['seccion'];

    $studentStmt = $conn->prepare(
        "SELECT id,id_no,name
         FROM student
         WHERE id=? AND school_id=? AND status='Activo'
           AND nivel=? AND grado=?
           AND COALESCE(NULLIF(seccion,''),'U')=?
         LIMIT 1"
    );
    if (!$studentStmt) {
        tam_reply(['status'=>'error','message'=>'No se pudo validar al estudiante.'],500);
    }
    $studentStmt->bind_param('iisss', $studentId, $school, $level, $grade, $section);
    $studentStmt->execute();
    $student = $studentStmt->get_result()->fetch_assoc();
    $studentStmt->close();
    if (!$student) {
        tam_reply([
            'status'=>'error',
            'message'=>'Este estudiante no pertenece al aula asignada al docente.'
        ],403);
    }

    $periodTable = $conn->query("SHOW TABLES LIKE 'academic_periods'");
    if (!$periodTable || $periodTable->num_rows === 0) {
        tam_reply([
            'status'=>'ok',
            'available'=>false,
            'message'=>'El colegio todavía no ha configurado las fechas de los bimestres.',
            'summary'=>[
                'marks'=>0,'present'=>0,'late'=>0,'absent'=>0,
                'justified'=>0,'permission'=>0,'attendance_rate'=>0
            ],
            'records'=>[]
        ]);
    }

    $periodStmt = $conn->prepare(
        "SELECT name,start_date,end_date
         FROM academic_periods
         WHERE school_id=? AND academic_year_id=? AND period_number=?
         LIMIT 1"
    );
    if (!$periodStmt) {
        tam_reply(['status'=>'error','message'=>'No se pudo consultar el periodo académico.'],500);
    }
    $academicYearId = (int)$assignment['academic_year_id'];
    $periodStmt->bind_param('iii', $school, $academicYearId, $bimester);
    $periodStmt->execute();
    $period = $periodStmt->get_result()->fetch_assoc();
    $periodStmt->close();

    $from = trim((string)($period['start_date'] ?? ''));
    $to = trim((string)($period['end_date'] ?? ''));
    if (!$period || $from === '' || $to === '') {
        tam_reply([
            'status'=>'ok',
            'available'=>false,
            'message'=>'Las fechas de este bimestre aún no están configuradas.',
            'period'=>[
                'bimester'=>$bimester,
                'name'=>(string)($period['name'] ?? ($bimester.'° Bimestre')),
                'start_date'=>$from,
                'end_date'=>$to
            ],
            'summary'=>[
                'marks'=>0,'present'=>0,'late'=>0,'absent'=>0,
                'justified'=>0,'permission'=>0,'attendance_rate'=>0
            ],
            'records'=>[]
        ]);
    }

    $historySql =
        "SELECT a.fecha,a.hora,a.estado
         FROM asistencia a
         WHERE a.school_id=? AND a.student_id=? AND a.tipo='Entrada'
           AND a.fecha BETWEEN ? AND ?$cancelFilter
         ORDER BY a.fecha DESC,a.hora DESC";
    $historyStmt = $conn->prepare($historySql);
    if (!$historyStmt) {
        tam_reply(['status'=>'error','message'=>'No se pudo consultar la asistencia del estudiante.'],500);
    }
    $historyStmt->bind_param('iiss', $school, $studentId, $from, $to);
    $historyStmt->execute();

    $records = [];
    $summary = [
        'marks'=>0,'present'=>0,'late'=>0,'absent'=>0,
        'justified'=>0,'permission'=>0,'attendance_rate'=>0
    ];
    $seenDates = [];
    $historyResult = $historyStmt->get_result();
    while ($row = $historyResult->fetch_assoc()) {
        $day = (string)$row['fecha'];
        if (isset($seenDates[$day])) continue;
        $seenDates[$day] = true;

        $status = trim((string)($row['estado'] ?? ''));
        if (in_array($status, ['Normal','Temprano'], true)) {
            $status = 'Presente';
        }

        $summary['marks']++;
        if ($status === 'Tarde') {
            $summary['late']++;
            $summary['present']++;
        } elseif ($status === 'Ausente') {
            $summary['absent']++;
        } elseif ($status === 'Ausente Justificada') {
            $summary['justified']++;
        } elseif ($status === 'Permiso') {
            $summary['permission']++;
        } elseif ($status !== '') {
            $summary['present']++;
        }

        if (count($records) < 20) {
            $records[] = [
                'date'=>$day,
                'time'=>substr((string)($row['hora'] ?? ''),0,5),
                'status'=>$status
            ];
        }
    }
    $historyStmt->close();

    if ($summary['marks'] > 0) {
        $summary['attendance_rate'] = round(
            ($summary['present'] / $summary['marks']) * 100,
            1
        );
    }

    tam_reply([
        'status'=>'ok',
        'available'=>true,
        'student'=>[
            'id'=>(int)$student['id'],
            'dni'=>(string)$student['id_no'],
            'name'=>(string)$student['name']
        ],
        'period'=>[
            'bimester'=>$bimester,
            'name'=>(string)($period['name'] ?? ($bimester.'° Bimestre')),
            'start_date'=>$from,
            'end_date'=>$to
        ],
        'summary'=>$summary,
        'records'=>$records,
        'read_only'=>true
    ]);
}

if ($action !== 'daily') {
    tam_reply(['status'=>'error','message'=>'Acción de asistencia no válida.'],422);
}
$sql = "SELECT st.id,st.id_no,st.name,a.hora,a.estado
        FROM student st
        LEFT JOIN asistencia a ON a.student_id=st.id AND a.school_id=? AND a.fecha=?
            AND a.tipo='Entrada'$cancelFilter
        WHERE st.school_id=? AND st.status='Activo' AND st.nivel=?
          AND st.grado=? AND COALESCE(NULLIF(st.seccion,''),'U')=?
        ORDER BY st.name";
$stmt = $conn->prepare($sql);
if (!$stmt) tam_reply(['status'=>'error','message'=>'No se pudo consultar asistencia.'],500);
$level = (string)$assignment['level'];
$grade = (string)$assignment['grado'];
$section = (string)$assignment['seccion'];
$stmt->bind_param('isisss', $school, $date, $school, $level, $grade, $section);
$stmt->execute();
$rows = [];
$summary = ['expected'=>0,'present'=>0,'late'=>0,'absent'=>0,'justified'=>0,'unmarked'=>0];
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $status = trim((string)($row['estado'] ?? ''));
    if (in_array($status, ['Normal','Temprano'], true)) $status='Presente';
    if ($status === '') $summary['unmarked']++;
    elseif ($status === 'Tarde') $summary['late']++;
    elseif ($status === 'Ausente Justificada') $summary['justified']++;
    elseif ($status === 'Ausente') $summary['absent']++;
    else $summary['present']++;
    $summary['expected']++;
    $rows[] = ['id'=>(int)$row['id'], 'dni'=>(string)$row['id_no'],
        'name'=>(string)$row['name'], 'status'=>$status, 'time'=>substr((string)($row['hora'] ?? ''),0,5)];
}
$stmt->close();
tam_reply(['status'=>'ok','date'=>$date,'course'=>$assignment,
           'summary'=>$summary,'students'=>$rows,'read_only'=>true]);
