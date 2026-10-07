<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ob_start();
include_once __DIR__ . '/../session_config.php';
include __DIR__ . '/../db_connect.php';
if (ob_get_length()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function student_courses_out(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function student_courses_table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function student_courses_normalize_grade(string $value): string {
    $value = trim($value);
    $value = str_replace(['°', 'º'], '', $value);
    return mb_strtolower(trim($value), 'UTF-8');
}

try {
    foreach (['student', 'academic_year', 'teacher_courses', 'academic_courses', 'teacher'] as $table) {
        if (!student_courses_table_exists($conn, $table)) {
            throw new RuntimeException("Falta la tabla requerida: {$table}");
        }
    }

    $requestedDni = trim((string)($_GET['dni'] ?? ($_POST['dni'] ?? '')));
    $requestedSchoolId = (int)($_GET['school_id'] ?? ($_POST['school_id'] ?? 0));
    $requestedYearId = (int)($_GET['academic_year_id'] ?? ($_POST['academic_year_id'] ?? 0));

    $student = null;
    $authMode = 'legacy_dni';

    if (!empty($_SESSION['student_logged_in']) && !empty($_SESSION['student_id'])) {
        $studentId = (int)$_SESSION['student_id'];
        $sessionSchoolId = (int)($_SESSION['student_school_id'] ?? ($_SESSION['login_school_id'] ?? 0));

        if ($sessionSchoolId > 0) {
            $stmt = $conn->prepare(
                'SELECT id, id_no, name, nivel, grado, seccion, school_id
                 FROM student
                 WHERE id = ? AND school_id = ?
                 LIMIT 1'
            );
            $stmt->bind_param('ii', $studentId, $sessionSchoolId);
        } else {
            $stmt = $conn->prepare(
                'SELECT id, id_no, name, nivel, grado, seccion, school_id
                 FROM student
                 WHERE id = ?
                 LIMIT 1'
            );
            $stmt->bind_param('i', $studentId);
        }

        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $authMode = 'session';
    } else {
        if ($requestedDni === '') {
            student_courses_out([
                'status' => 'error',
                'message' => 'DNI no recibido.'
            ], 400);
        }

        if ($requestedSchoolId > 0) {
            $stmt = $conn->prepare(
                'SELECT id, id_no, name, nivel, grado, seccion, school_id
                 FROM student
                 WHERE id_no = ? AND school_id = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->bind_param('si', $requestedDni, $requestedSchoolId);
        } else {
            $stmt = $conn->prepare(
                'SELECT id, id_no, name, nivel, grado, seccion, school_id
                 FROM student
                 WHERE id_no = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->bind_param('s', $requestedDni);
        }

        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$student) {
        student_courses_out([
            'status' => 'error',
            'message' => 'Estudiante no encontrado.'
        ], 404);
    }

    $schoolId = (int)$student['school_id'];
    $studentLevel = trim((string)($student['nivel'] ?? ''));
    $studentGrade = student_courses_normalize_grade((string)($student['grado'] ?? ''));
    $studentSection = strtoupper(trim((string)($student['seccion'] ?? '')));
    if ($studentSection === '') $studentSection = 'U';

    $years = [];
    $stmt = $conn->prepare(
        'SELECT id, year, description, is_active
         FROM academic_year
         WHERE school_id = ?
         ORDER BY is_active DESC, year DESC, id DESC'
    );
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $result = $stmt->get_result();

    $activeYearId = 0;
    while ($row = $result->fetch_assoc()) {
        $year = [
            'id' => (int)$row['id'],
            'year' => (string)$row['year'],
            'description' => (string)($row['description'] ?? ''),
            'is_active' => (int)$row['is_active'] === 1
        ];
        $years[] = $year;
        if ($year['is_active'] && $activeYearId === 0) {
            $activeYearId = $year['id'];
        }
    }
    $stmt->close();

    if (empty($years)) {
        student_courses_out([
            'status' => 'ok',
            'student' => [
                'id' => (int)$student['id'],
                'dni' => (string)($student['id_no'] ?? ''),
                'name' => (string)($student['name'] ?? ''),
                'nivel' => $studentLevel,
                'grado' => (string)($student['grado'] ?? ''),
                'seccion' => $studentSection,
                'school_id' => $schoolId
            ],
            'auth_mode' => $authMode,
            'years' => [],
            'academic_year' => null,
            'courses' => []
        ]);
    }

    $selectedYearId = $requestedYearId > 0 ? $requestedYearId : $activeYearId;
    if ($selectedYearId <= 0) $selectedYearId = (int)$years[0]['id'];

    $selectedYear = null;
    foreach ($years as $year) {
        if ((int)$year['id'] === $selectedYearId) {
            $selectedYear = $year;
            break;
        }
    }

    if ($selectedYear === null) {
        student_courses_out([
            'status' => 'error',
            'message' => 'El año académico solicitado no pertenece al colegio.'
        ], 404);
    }

    $sql = "
        SELECT
            tc.id AS teacher_course_id,
            tc.course_id,
            tc.teacher_id,
            tc.grado,
            COALESCE(NULLIF(TRIM(tc.seccion), ''), 'U') AS seccion,
            ac.name AS course_name,
            COALESCE(ac.course_code, '') AS course_code,
            ac.level,
            COALESCE(a.id, 0) AS area_id,
            COALESCE(a.name, 'Área General') AS area_name,
            COALESCE(a.color, '#1976D2') AS area_color,
            COALESCE(a.description, '') AS area_description,
            COALESCE(t.name, 'Docente por asignar') AS teacher_name
        FROM teacher_courses tc
        INNER JOIN academic_courses ac
            ON ac.id = tc.course_id
            AND ac.school_id = tc.school_id
        LEFT JOIN teacher t
            ON t.id = tc.teacher_id
            AND t.school_id = tc.school_id
        LEFT JOIN areas a
            ON a.id = ac.area_id
            AND a.school_id = tc.school_id
        WHERE tc.school_id = ?
          AND tc.academic_year_id = ?
          AND LOWER(TRIM(ac.level)) = LOWER(TRIM(?))
          AND LOWER(
                TRIM(
                    REPLACE(
                        REPLACE(tc.grado, '°', ''),
                        'º', ''
                    )
                )
              ) = ?
          AND UPPER(COALESCE(NULLIF(TRIM(tc.seccion), ''), 'U')) = ?
        ORDER BY COALESCE(a.name, 'Área General'), ac.name, t.name
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Consulta de cursos: ' . $conn->error);
    $stmt->bind_param(
        'iisss',
        $schoolId,
        $selectedYearId,
        $studentLevel,
        $studentGrade,
        $studentSection
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $hasCompetencies = student_courses_table_exists($conn, 'general_course_competencies');
    $competencyStmt = null;
    if ($hasCompetencies) {
        $competencyStmt = $conn->prepare(
            'SELECT id, name, percentage
             FROM general_course_competencies
             WHERE course_id = ?
               AND teacher_id = ?
               AND academic_year_id = ?
               AND is_active = 1
             ORDER BY id ASC'
        );
    }

    $courses = [];
    while ($row = $result->fetch_assoc()) {
        $competencies = [];

        if ($competencyStmt) {
            $courseId = (int)$row['course_id'];
            $teacherId = (int)$row['teacher_id'];
            $competencyStmt->bind_param('iii', $courseId, $teacherId, $selectedYearId);
            $competencyStmt->execute();
            $competencyResult = $competencyStmt->get_result();

            while ($competency = $competencyResult->fetch_assoc()) {
                $competencies[] = [
                    'id' => (int)$competency['id'],
                    'name' => (string)$competency['name'],
                    'percentage' => (float)($competency['percentage'] ?? 0)
                ];
            }
        }

        $courses[] = [
            'teacher_course_id' => (int)$row['teacher_course_id'],
            'course_id' => (int)$row['course_id'],
            'course_name' => (string)$row['course_name'],
            'course_code' => (string)$row['course_code'],
            'level' => (string)$row['level'],
            'grado' => (string)$row['grado'],
            'seccion' => (string)$row['seccion'],
            'area' => [
                'id' => (int)$row['area_id'],
                'name' => (string)$row['area_name'],
                'color' => (string)$row['area_color'],
                'description' => (string)$row['area_description']
            ],
            'teacher' => [
                'id' => (int)$row['teacher_id'],
                'name' => (string)$row['teacher_name']
            ],
            'competency_count' => count($competencies),
            'competencies' => $competencies
        ];
    }

    if ($competencyStmt) $competencyStmt->close();
    $stmt->close();

    student_courses_out([
        'status' => 'ok',
        'student' => [
            'id' => (int)$student['id'],
            'dni' => (string)($student['id_no'] ?? ''),
            'name' => (string)($student['name'] ?? ''),
            'nivel' => $studentLevel,
            'grado' => (string)($student['grado'] ?? ''),
            'seccion' => $studentSection,
            'school_id' => $schoolId
        ],
        'auth_mode' => $authMode,
        'years' => $years,
        'academic_year' => $selectedYear,
        'courses' => $courses
    ]);
} catch (Throwable $e) {
    error_log(
        '[student_courses API] ' .
        $e->getMessage() .
        ' in ' .
        $e->getFile() .
        ':' .
        $e->getLine()
    );

    student_courses_out([
        'status' => 'error',
        'message' => 'No se pudieron cargar los cursos del estudiante.'
    ], 500);
}
?>