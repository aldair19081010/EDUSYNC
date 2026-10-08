<?php
// API móvil de docentes: la identidad se obtiene EXCLUSIVAMENTE de la sesión PHP.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function teacher_mobile_reply(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    teacher_mobile_reply(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}

// Se envía por POST, nunca por URL, para evitar exponer la sesión en historiales y logs.
$sid = trim((string)($_POST['sid'] ?? ''));
if ($sid === '' || !preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $sid)) {
    teacher_mobile_reply(['status' => 'error', 'message' => 'Debes iniciar sesión nuevamente.'], 401);
}

ob_start();
require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../db_connect.php';
ob_end_clean();

$sessionUserId = (int)($_SESSION['login_id'] ?? 0);
$sessionSchoolId = (int)($_SESSION['login_school_id'] ?? 0);
$sessionTeacherId = (int)($_SESSION['login_teacher_id'] ?? 0);
$sessionRole = (int)($_SESSION['login_type'] ?? 0);
if ($sessionRole !== 2 || $sessionUserId <= 0 ||
    $sessionSchoolId <= 0 || $sessionTeacherId <= 0) {
    teacher_mobile_reply(['status' => 'error', 'message' => 'No tienes acceso al módulo docente.'], 403);
}

// Revalidamos la relación usuario-docente y el estado de la cuenta en cada consulta.
$hasStatus = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$statusCondition = ($hasStatus && $hasStatus->num_rows > 0)
    ? " AND u.status = 'Activo'" : '';
$check = $conn->prepare(
    "SELECT t.id, t.name FROM users u
     INNER JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id
     WHERE u.id=? AND u.school_id=? AND u.teacher_id=? AND u.type=2
       AND t.status='Activo' {$statusCondition} LIMIT 1"
);
if (!$check) {
    teacher_mobile_reply(['status' => 'error', 'message' => 'No se pudo validar la cuenta.'], 500);
}
$check->bind_param('iii', $sessionUserId, $sessionSchoolId, $sessionTeacherId);
$check->execute();
$teacher = $check->get_result()->fetch_assoc();
$check->close();
if (!$teacher) {
    teacher_mobile_reply(['status' => 'error', 'message' => 'La cuenta docente está inactiva o desvinculada.'], 403);
}

$action = trim((string)($_POST['action'] ?? 'courses'));
if ($action === 'students') {
    $assignmentId = (int)($_POST['teacher_course_id'] ?? 0);
    if ($assignmentId <= 0) {
        teacher_mobile_reply(['status' => 'error', 'message' => 'Asignación inválida.'], 422);
    }
    // La asignación debe pertenecer al docente autenticado Y a su institución.
    $context = $conn->prepare(
        "SELECT tc.id, tc.grado, COALESCE(NULLIF(tc.seccion,''),'U') seccion,
                ac.name course_name, ac.level, ay.year
         FROM teacher_courses tc
         INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
         INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
         WHERE tc.id=? AND tc.teacher_id=? AND tc.school_id=? LIMIT 1"
    );
    $context->bind_param('iii', $assignmentId, $sessionTeacherId, $sessionSchoolId);
    $context->execute();
    $course = $context->get_result()->fetch_assoc();
    $context->close();
    if (!$course) {
        teacher_mobile_reply(['status' => 'error', 'message' => 'Esta asignación no te pertenece.'], 404);
    }
    $studentsStmt = $conn->prepare(
        "SELECT st.id, st.id_no, st.name
         FROM student st
         WHERE st.school_id=? AND st.status='Activo' AND st.nivel=?
           AND st.grado=? AND COALESCE(NULLIF(st.seccion,''),'U')=?
         ORDER BY st.name"
    );
    $studentsStmt->bind_param(
        'isss', $sessionSchoolId, $course['level'], $course['grado'], $course['seccion']
    );
    $studentsStmt->execute();
    $items = [];
    $studentsResult = $studentsStmt->get_result();
    while ($student = $studentsResult->fetch_assoc()) {
        $items[] = [
            'id' => (int)$student['id'],
            'dni' => (string)$student['id_no'],
            'name' => (string)$student['name'],
        ];
    }
    $studentsStmt->close();
    teacher_mobile_reply([
        'status' => 'ok',
        'course' => $course,
        'students' => $items,
    ]);
}

if ($action !== 'courses') {
    teacher_mobile_reply(['status' => 'error', 'message' => 'Acción no válida.'], 400);
}

$yearsStmt = $conn->prepare(
    'SELECT id, year, description, is_active FROM academic_year
     WHERE school_id=? ORDER BY is_active DESC, year DESC, id DESC'
);
$yearsStmt->bind_param('i', $sessionSchoolId);
$yearsStmt->execute();
$years = [];
$activeYearId = 0;
$yearsResult = $yearsStmt->get_result();
while ($year = $yearsResult->fetch_assoc()) {
    $year['id'] = (int)$year['id'];
    $year['is_active'] = (int)$year['is_active'] === 1;
    if ($year['is_active'] && $activeYearId === 0) $activeYearId = $year['id'];
    $years[] = $year;
}
$yearsStmt->close();

$requestedYearId = (int)($_POST['academic_year_id'] ?? 0);
$yearIds = array_column($years, 'id');
if ($requestedYearId > 0 && !in_array($requestedYearId, $yearIds, true)) {
    teacher_mobile_reply(['status' => 'error', 'message' => 'El año no pertenece a la institución.'], 404);
}
$selectedYearId = $requestedYearId > 0 ? $requestedYearId
    : ($activeYearId > 0 ? $activeYearId : ($years[0]['id'] ?? 0));
$selectedYear = null;
foreach ($years as $year) {
    if ($year['id'] === $selectedYearId) { $selectedYear = $year; break; }
}
if ($selectedYearId === 0) {
    teacher_mobile_reply([
        'status' => 'ok', 'teacher_name' => (string)$teacher['name'],
        'years' => $years, 'academic_year' => null, 'courses' => [],
        'summary' => ['courses' => 0, 'students' => 0, 'competencies' => 0, 'evaluations' => 0],
    ]);
}

// Misma definición de asignaciones y métricas que la vista web "Mis cursos".
$sql = "SELECT tc.id teacher_course_id, tc.course_id, tc.academic_year_id,
               tc.grado, COALESCE(NULLIF(tc.seccion,''),'U') seccion,
               ac.name course_name, ac.level, ac.is_active course_is_active,
               COALESCE(ac.course_status,'Activo') course_status,
               COALESCE(a.name,'Sin área') area_name, COALESCE(a.color,'#1565C0') area_color,
               (SELECT COUNT(*) FROM student st
                WHERE st.school_id=tc.school_id AND st.status='Activo'
                  AND st.nivel=ac.level AND st.grado=tc.grado
                  AND COALESCE(NULLIF(st.seccion,''),'U')=COALESCE(NULLIF(tc.seccion,''),'U')
               ) student_count,
               (SELECT COUNT(*) FROM general_course_competencies gcc
                WHERE gcc.course_id=tc.course_id AND gcc.teacher_id=tc.teacher_id
                  AND gcc.academic_year_id=tc.academic_year_id AND gcc.is_active=1
               ) competency_count,
               (SELECT COUNT(*) FROM evaluations ev
                WHERE ev.teacher_course_id=tc.id AND ev.teacher_id=tc.teacher_id
                  AND ev.academic_year_id=tc.academic_year_id
               ) evaluation_count,
               (SELECT COUNT(DISTINCT CONCAT(eg.evaluation_id,'-',eg.student_id))
                FROM evaluation_grades eg INNER JOIN evaluations evg ON evg.id=eg.evaluation_id
                WHERE evg.teacher_course_id=tc.id AND evg.teacher_id=tc.teacher_id
                  AND evg.academic_year_id=tc.academic_year_id AND eg.grade<>''
               ) grade_count
        FROM teacher_courses tc
        INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
        INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
        LEFT JOIN areas a ON a.id=ac.area_id AND a.school_id=tc.school_id
        WHERE tc.teacher_id=? AND tc.school_id=? AND tc.academic_year_id=?
        ORDER BY COALESCE(a.name,'Sin área'), ac.name, ac.level, tc.grado, tc.seccion";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    teacher_mobile_reply(['status' => 'error', 'message' => 'No se pudo consultar cursos.'], 500);
}
$stmt->bind_param('iii', $sessionTeacherId, $sessionSchoolId, $selectedYearId);
$stmt->execute();
$result = $stmt->get_result();
$courses = [];
$groups = [];
$summary = ['courses' => 0, 'students' => 0, 'competencies' => 0, 'evaluations' => 0];
while ($course = $result->fetch_assoc()) {
    foreach (['teacher_course_id','course_id','academic_year_id',
              'student_count','competency_count','evaluation_count','grade_count'] as $key) {
        $course[$key] = (int)$course[$key];
    }
    $expected = $course['student_count'] * $course['evaluation_count'];
    $course['grade_progress'] = $expected > 0
        ? min(100, (int)round(100 * $course['grade_count'] / $expected)) : 0;
    $course['editable'] = (bool)$selectedYear['is_active'] &&
        (int)$course['course_is_active'] === 1 && $course['course_status'] === 'Activo';
    unset($course['course_is_active'], $course['course_status'], $course['grade_count']);
    $group = $course['level'].'|'.$course['grado'].'|'.$course['seccion'];
    if (!isset($groups[$group])) {
        $groups[$group] = true;
        $summary['students'] += $course['student_count'];
    }
    $summary['competencies'] += $course['competency_count'];
    $summary['evaluations'] += $course['evaluation_count'];
    $courses[] = $course;
}
$stmt->close();
$summary['courses'] = count($courses);
teacher_mobile_reply([
    'status' => 'ok',
    'teacher_name' => (string)$teacher['name'],
    'years' => $years,
    'academic_year' => $selectedYear,
    'courses' => $courses,
    'summary' => $summary,
]);
