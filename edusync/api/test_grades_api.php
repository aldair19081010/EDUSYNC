<?php
include '../db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$dni = '80995934';
$student_id = 62;

$ids_str = '1'; // Año 2025
$start_date = '2025-01-01';
$end_date = '2025-12-31 23:59:59';
$num_bimestre = '2';

$filtro_year_sql = " AND (
    tc.academic_year_id IN ($ids_str) OR 
    e.academic_year_id IN ($ids_str) OR 
    (e.created_at BETWEEN '$start_date' AND '$end_date')
)";

$filtro_bimestre_actual = " AND e.bimestre = '$num_bimestre'";

$sql = "SELECT DISTINCT 
        COALESCE(eg.competencia_id, 0) as competencia_id, 
        COALESCE(c.name, 'Evaluación General') as name, 
        COALESCE(c.percentage, 100) as percentage
    FROM evaluation_grades eg
    INNER JOIN evaluations e ON e.id = eg.evaluation_id
    LEFT JOIN teacher_courses tc ON tc.id = e.teacher_course_id
    LEFT JOIN academic_courses ac ON ac.id = tc.course_id
    LEFT JOIN general_course_competencies c ON c.id = eg.competencia_id
    WHERE eg.student_id = $student_id
    $filtro_year_sql
    $filtro_bimestre_actual";

$comp_q = $conn->query($sql);

$output = [
    'sql' => $sql,
    'error' => $conn->error,
    'results' => []
];

if ($comp_q) {
    while($r = $comp_q->fetch_assoc()) {
        $output['results'][] = $r;
    }
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
