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
            ac.name course_name,ac.level,ay.is_active,ay.year academic_year_name
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
if ($action === 'daily' && (int)$assignment['is_active'] !== 1) {
    tam_reply([
        'status'=>'error',
        'message'=>'La consulta diaria está disponible para el año activo.'
    ],422);
}

$tableReady = $conn->query("SHOW TABLES LIKE 'asistencia'");
if (!$tableReady || $tableReady->num_rows === 0) {
    tam_reply(['status'=>'error','message'=>'El colegio aún no tiene habilitado el módulo de asistencia.'],409);
}
$cancelCol = $conn->query("SHOW COLUMNS FROM asistencia LIKE 'is_cancelled'");
$cancelFilter = $cancelCol && $cancelCol->num_rows > 0 ? ' AND a.is_cancelled=0' : '';

if ($action === 'history') {
    $assignmentYear = (int)($assignment['academic_year_name'] ?? 0);
    $year = $assignmentYear > 0
        ? $assignmentYear
        : (int)($_POST['year'] ?? date('Y'));
    $month = (int)($_POST['month'] ?? date('n'));
    if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
        tam_reply([
            'status'=>'error',
            'message'=>'Selecciona un mes y año válidos.'
        ],422);
    }

    $fromObj = DateTimeImmutable::createFromFormat(
        '!Y-n-j',
        $year.'-'.$month.'-1'
    );
    if (!$fromObj) {
        tam_reply(['status'=>'error','message'=>'Periodo no válido.'],422);
    }
    $from = $fromObj->format('Y-m-d');
    $to = $fromObj->modify('last day of this month')->format('Y-m-d');

    $level = (string)$assignment['level'];
    $grade = (string)$assignment['grado'];
    $section = (string)$assignment['seccion'];

    $availableYears = [$year];

    $historySql =
        "SELECT st.id,st.id_no,st.name,
                a.fecha,a.hora,a.estado
         FROM student st
         LEFT JOIN asistencia a
           ON a.student_id=st.id
          AND a.school_id=?
          AND a.tipo='Entrada'
          AND a.fecha BETWEEN ? AND ?$cancelFilter
         WHERE st.school_id=? AND st.status='Activo'
           AND st.nivel=? AND st.grado=?
           AND COALESCE(NULLIF(st.seccion,''),'U')=?
         ORDER BY st.name,a.fecha DESC,a.hora DESC";
    $historyStmt = $conn->prepare($historySql);
    if (!$historyStmt) {
        tam_reply(['status'=>'error','message'=>'No se pudo consultar el historial de asistencia.'],500);
    }
    $historyStmt->bind_param(
        'ississs',
        $school,
        $from,
        $to,
        $school,
        $level,
        $grade,
        $section
    );
    $historyStmt->execute();

    $students = [];
    $daily = [];
    $records = [];
    $summary = [
        'marks'=>0,
        'present'=>0,
        'late'=>0,
        'attended'=>0,
        'absent'=>0,
        'justified'=>0,
        'permission'=>0,
        'attendance_rate'=>0
    ];
    $seenStudentDays = [];

    $historyResult = $historyStmt->get_result();
    while ($row = $historyResult->fetch_assoc()) {
        $studentId = (int)$row['id'];
        if (!isset($students[$studentId])) {
            $students[$studentId] = [
                'id'=>$studentId,
                'dni'=>(string)$row['id_no'],
                'name'=>(string)$row['name'],
                'marks'=>0,
                'present'=>0,
                'late'=>0,
                'attended'=>0,
                'absent'=>0,
                'justified'=>0,
                'permission'=>0,
                'attendance_rate'=>0,
                'last_date'=>'',
                'last_time'=>'',
                'last_status'=>''
            ];
        }

        $day = trim((string)($row['fecha'] ?? ''));
        if ($day === '') continue;

        $key = $studentId.'|'.$day;
        if (isset($seenStudentDays[$key])) continue;
        $seenStudentDays[$key] = true;

        $status = trim((string)($row['estado'] ?? ''));
        if (in_array($status, ['Normal','Temprano'], true)) {
            $status = 'Presente';
        }
        $time = substr((string)($row['hora'] ?? ''),0,5);

        $summary['marks']++;
        $students[$studentId]['marks']++;

        if ($status === 'Tarde') {
            $summary['late']++;
            $summary['attended']++;
            $students[$studentId]['late']++;
            $students[$studentId]['attended']++;
        } elseif ($status === 'Ausente') {
            $summary['absent']++;
            $students[$studentId]['absent']++;
        } elseif ($status === 'Ausente Justificada') {
            $summary['justified']++;
            $students[$studentId]['justified']++;
        } elseif ($status === 'Permiso') {
            $summary['permission']++;
            $students[$studentId]['permission']++;
        } elseif ($status !== '') {
            $summary['present']++;
            $summary['attended']++;
            $students[$studentId]['present']++;
            $students[$studentId]['attended']++;
        }

        if ($students[$studentId]['last_date'] === '') {
            $students[$studentId]['last_date'] = $day;
            $students[$studentId]['last_time'] = $time;
            $students[$studentId]['last_status'] = $status;
        }

        if (!isset($daily[$day])) {
            $daily[$day] = [
                'date'=>$day,
                'marked'=>0,
                'present'=>0,
                'late'=>0,
                'attended'=>0,
                'absent'=>0,
                'justified'=>0,
                'permission'=>0,
                'attendance_rate'=>0
            ];
        }
        $daily[$day]['marked']++;

        if ($status === 'Tarde') {
            $daily[$day]['late']++;
            $daily[$day]['attended']++;
        } elseif ($status === 'Ausente') {
            $daily[$day]['absent']++;
        } elseif ($status === 'Ausente Justificada') {
            $daily[$day]['justified']++;
        } elseif ($status === 'Permiso') {
            $daily[$day]['permission']++;
        } elseif ($status !== '') {
            $daily[$day]['present']++;
            $daily[$day]['attended']++;
        }

        $records[] = [
            'student_id'=>$studentId,
            'dni'=>(string)$row['id_no'],
            'name'=>(string)$row['name'],
            'date'=>$day,
            'time'=>$time,
            'status'=>$status
        ];
    }
    $historyStmt->close();

    foreach ($students as &$studentRow) {
        if ($studentRow['marks'] > 0) {
            $studentRow['attendance_rate'] = round(
                ($studentRow['attended'] / $studentRow['marks']) * 100,
                1
            );
        }
    }
    unset($studentRow);

    foreach ($daily as &$dailyRow) {
        if ($dailyRow['marked'] > 0) {
            $dailyRow['attendance_rate'] = round(
                ($dailyRow['attended'] / $dailyRow['marked']) * 100,
                1
            );
        }
    }
    unset($dailyRow);

    if ($summary['marks'] > 0) {
        $summary['attendance_rate'] = round(
            ($summary['attended'] / $summary['marks']) * 100,
            1
        );
    }

    uasort($students, function($a,$b) {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    krsort($daily);

    tam_reply([
        'status'=>'ok',
        'available'=>true,
        'year'=>$year,
        'month'=>$month,
        'date_from'=>$from,
        'date_to'=>$to,
        'available_years'=>array_values($availableYears),
        'course'=>$assignment,
        'summary'=>$summary,
        'students'=>array_values($students),
        'days'=>array_values($daily),
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
