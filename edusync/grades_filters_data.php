<?php
// Devuelve los niveles, grados y secciones disponibles para el docente y año académico
include('db_connect.php');
header('Content-Type: application/json');

// Validar parámetros
$teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;
$academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;

$levels = [];
$grados = [];
$secciones = [];
$courses = [];

if ($teacher_id) {
    $where_clause = " WHERE tc.teacher_id = $teacher_id ";
    
    // Solo aplicar filtro de año académico si se proporciona uno válido
    if ($academic_year_id > 0) {
        // Buscar en evaluaciones con año académico específico
        $query = "SELECT DISTINCT ac.level, tc.grado, tc.seccion, ac.name as course_name
            FROM teacher_courses tc
            INNER JOIN academic_courses ac ON ac.id = tc.course_id
            INNER JOIN evaluations e ON e.teacher_course_id = tc.id
            WHERE tc.teacher_id = $teacher_id AND e.academic_year_id = $academic_year_id
            ORDER BY ac.level, tc.grado, tc.seccion";
    } else {
        // Si no hay filtro de año, mostrar todos los cursos del docente
        $query = "SELECT DISTINCT ac.level, tc.grado, tc.seccion, ac.name as course_name
            FROM teacher_courses tc
            INNER JOIN academic_courses ac ON ac.id = tc.course_id
            WHERE tc.teacher_id = $teacher_id
            ORDER BY ac.level, tc.grado, tc.seccion";
    }
    
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['level']) && !in_array($row['level'], $levels)) {
                $levels[] = $row['level'];
            }
            if (!empty($row['grado']) && !in_array($row['grado'], $grados)) {
                $grados[] = $row['grado'];
            }
            if (!empty($row['seccion']) && !in_array($row['seccion'], $secciones)) {
                $secciones[] = $row['seccion'];
            }
            if (!empty($row['course_name']) && !in_array($row['course_name'], $courses)) {
                $courses[] = $row['course_name'];
            }
        }
    }
    
    // Ordenar los arrays
    sort($levels);
    sort($grados);
    sort($secciones);
    sort($courses);
}

echo json_encode([
    'status' => 1,
    'levels' => $levels,
    'grados' => $grados,
    'secciones' => $secciones,
    'courses' => $courses
]);
