<?php
require_once __DIR__ . '/../includes/dev_cors.php';
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

function teacher_school_logo_url(string $logoPath): string {
    $logoPath = trim($logoPath);
    if ($logoPath === '') return '';

    if (preg_match('~^https?://~i', $logoPath)) {
        return $logoPath;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return $logoPath;

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $appBase = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/.');

    return $scheme . '://' . $host
        . ($appBase !== '' ? '/' . ltrim($appBase, '/') : '')
        . '/' . ltrim($logoPath, '/');
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

$schoolInfo = ['name' => '', 'logo_url' => ''];
$schoolStmt = $conn->prepare(
    'SELECT name, logo_path FROM schools WHERE id=? LIMIT 1'
);
if ($schoolStmt) {
    $schoolStmt->bind_param('i', $sessionSchoolId);
    $schoolStmt->execute();
    $schoolRow = $schoolStmt->get_result()->fetch_assoc();
    $schoolStmt->close();
    if ($schoolRow) {
        $schoolInfo = [
            'name' => (string)($schoolRow['name'] ?? ''),
            'logo_url' => teacher_school_logo_url(
                (string)($schoolRow['logo_path'] ?? '')
            ),
        ];
    }
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

$suggestedBimester = 1;
if ($selectedYearId > 0) {
    $bimesterStmt = $conn->prepare(
        "SELECT MAX(CAST(ev.bimestre AS UNSIGNED)) suggested_bimester
         FROM evaluations ev
         INNER JOIN teacher_courses tc
           ON tc.id=ev.teacher_course_id
          AND tc.teacher_id=ev.teacher_id
          AND tc.academic_year_id=ev.academic_year_id
         WHERE ev.teacher_id=? AND ev.academic_year_id=?
           AND tc.school_id=?
           AND CAST(ev.bimestre AS UNSIGNED) BETWEEN 1 AND 4"
    );
    if ($bimesterStmt) {
        $bimesterStmt->bind_param(
            'iii',
            $sessionTeacherId,
            $selectedYearId,
            $sessionSchoolId
        );
        $bimesterStmt->execute();
        $suggestedRow = $bimesterStmt->get_result()->fetch_assoc();
        $bimesterStmt->close();
        $candidate = (int)($suggestedRow['suggested_bimester'] ?? 0);
        if ($candidate >= 1 && $candidate <= 4) {
            $suggestedBimester = $candidate;
        }
    }
}

$requestedBimester = (int)($_POST['bimester'] ?? 0);
if ($requestedBimester !== 0 &&
    ($requestedBimester < 1 || $requestedBimester > 4)) {
    teacher_mobile_reply([
        'status' => 'error',
        'message' => 'Selecciona un bimestre válido.',
    ], 422);
}
$selectedBimester = $requestedBimester > 0
    ? $requestedBimester
    : $suggestedBimester;
if ($selectedYearId === 0) {
    teacher_mobile_reply([
        'status' => 'ok', 'teacher_name' => (string)$teacher['name'],
        'school_name' => $schoolInfo['name'],
        'school_logo' => $schoolInfo['logo_url'],
        'selected_bimester' => $selectedBimester,
        'suggested_bimester' => $suggestedBimester,
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
               ) evaluation_count
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
              'student_count','competency_count','evaluation_count'] as $key) {
        $course[$key] = (int)$course[$key];
    }
    $course['school_name'] = $schoolInfo['name'];
    $course['school_logo'] = $schoolInfo['logo_url'];
    $course['teacher_name'] = (string)$teacher['name'];
    // El llenado se calcula abajo por competencia. No confundir con promedio.
    $course['competency_progress'] = [];
    $course['grade_progress'] = 0;
    $course['progress_available'] = false;
    $course['progress_bimester'] = $selectedBimester;
    $course['bimester_evaluation_count'] = 0;
    $course['editable'] = (bool)$selectedYear['is_active'] &&
        (int)$course['course_is_active'] === 1 && $course['course_status'] === 'Activo';
    unset($course['course_is_active'], $course['course_status']);
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

// Una sola consulta para todos los cursos del año: evita N solicitudes
// cuando un profesor tiene muchas asignaciones. Se cuentan exclusivamente
// notas no vacías de estudiantes activos del curso y evaluaciones vigentes.
if ($courses) {
    $hasEvaluationStatus = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
    $activeEvaluationFilter = ($hasEvaluationStatus && $hasEvaluationStatus->num_rows > 0)
        ? " AND COALESCE(e.status,'Activa') <> 'Anulada'" : '';
    $progressSql = "SELECT tc.id teacher_course_id, gcc.id competency_id,
                           gcc.name competency_name,
                           COUNT(DISTINCT e.id) evaluation_count,
                           GROUP_CONCAT(DISTINCT e.id ORDER BY e.id) evaluation_ids,
                           COUNT(DISTINCT CASE
                             WHEN st.id IS NOT NULL AND eg.grade IS NOT NULL
                                  AND TRIM(eg.grade) <> ''
                             THEN CONCAT(eg.evaluation_id, ':', eg.student_id)
                             ELSE NULL END) filled_count
                    FROM teacher_courses tc
                    INNER JOIN academic_courses ac
                       ON ac.id=tc.course_id AND ac.school_id=tc.school_id
                    INNER JOIN general_course_competencies gcc
                       ON gcc.course_id=tc.course_id
                      AND gcc.teacher_id=tc.teacher_id
                      AND gcc.academic_year_id=tc.academic_year_id
                      AND gcc.is_active=1
                    LEFT JOIN evaluation_competencias ec
                       ON ec.competencia_id=gcc.id
                    LEFT JOIN evaluations e
                       ON e.id=ec.evaluation_id
                      AND e.teacher_course_id=tc.id
                      AND e.teacher_id=tc.teacher_id
                      AND e.academic_year_id=tc.academic_year_id
                      AND e.bimestre=?
                      {$activeEvaluationFilter}
                    LEFT JOIN evaluation_grades eg
                       ON eg.evaluation_id=e.id
                      AND eg.competencia_id=gcc.id
                    LEFT JOIN student st
                       ON st.id=eg.student_id AND st.school_id=tc.school_id
                      AND st.status='Activo'
                      AND st.nivel=ac.level AND st.grado=tc.grado
                      AND COALESCE(NULLIF(st.seccion,''),'U') =
                          COALESCE(NULLIF(tc.seccion,''),'U')
                    WHERE tc.teacher_id=? AND tc.school_id=?
                      AND tc.academic_year_id=?
                    GROUP BY tc.id, gcc.id, gcc.name
                    ORDER BY tc.id, gcc.id";
    $progressStmt = $conn->prepare($progressSql);
    if ($progressStmt) {
        $bimesterParam = (string)$selectedBimester;
        $progressStmt->bind_param(
            'siii',
            $bimesterParam,
            $sessionTeacherId,
            $sessionSchoolId,
            $selectedYearId
        );
        if ($progressStmt->execute()) {
            $byCourse = [];
            foreach ($courses as $index => $course) {
                $byCourse[(int)$course['teacher_course_id']] = $index;
                $courses[$index]['progress_available'] = true;
            }
            $rates = [];
            $evaluationSets = [];
            $progressResult = $progressStmt->get_result();
            while ($progressResult && ($row = $progressResult->fetch_assoc())) {
                $id = (int)$row['teacher_course_id'];
                if (!isset($byCourse[$id])) continue;
                $index = $byCourse[$id];
                $students = (int)$courses[$index]['student_count'];
                $evaluations = (int)$row['evaluation_count'];
                $expected = $students * $evaluations;
                $filled = $expected > 0
                    ? min($expected, (int)$row['filled_count']) : 0;
                $percent = $expected > 0 ? (int)round($filled * 100 / $expected) : 0;
                $courses[$index]['competency_progress'][] = [
                    'id' => (int)$row['competency_id'],
                    'name' => (string)$row['competency_name'],
                    'evaluations' => $evaluations,
                    'filled' => $filled,
                    'expected' => $expected,
                    'percentage' => $percent,
                ];
                $rates[$id][] = $expected > 0 ? $filled / $expected : 0.0;
                $evaluationIds = array_filter(explode(',', (string)($row['evaluation_ids'] ?? '')));
                foreach ($evaluationIds as $evaluationId) {
                    $evaluationSets[$id][(int)$evaluationId] = true;
                }
            }
            // Igual importancia para cada competencia. Las competencias
            // sin evaluaciones quedan en 0%, no desaparecen del denominador.
            foreach ($byCourse as $id => $index) {
                $competencyRates = $rates[$id] ?? [];
                $courses[$index]['grade_progress'] = $competencyRates
                    ? (int)round(100 * array_sum($competencyRates) / count($competencyRates))
                    : 0;
                $courses[$index]['bimester_evaluation_count'] =
                    isset($evaluationSets[$id]) ? count($evaluationSets[$id]) : 0;
            }
        }
        $progressStmt->close();
    }
}
teacher_mobile_reply([
    'status' => 'ok',
    'teacher_name' => (string)$teacher['name'],
    'school_name' => $schoolInfo['name'],
    'school_logo' => $schoolInfo['logo_url'],
    'selected_bimester' => $selectedBimester,
    'suggested_bimester' => $suggestedBimester,
    'years' => $years,
    'academic_year' => $selectedYear,
    'bimester' => $selectedBimester,
    'courses' => $courses,
    'summary' => $summary,
]);
