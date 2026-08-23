<?php
include '../db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$dni = '80995934';
$output = [];

// 1. Buscar estudiante
$stu = $conn->query("SELECT id, school_id, id_no, CONCAT(firstname,' ',lastname) as nombre FROM student WHERE id_no = '$dni'")->fetch_assoc();
$output['estudiante'] = $stu;
$sid = $stu['id'] ?? 0;

// 2. Años académicos en el sistema
$ay = $conn->query("SELECT id, year, description, is_active, school_id, start_date, end_date FROM academic_year ORDER BY year DESC");
$output['academic_years'] = [];
while ($r = $ay->fetch_assoc()) $output['academic_years'][] = $r;

// 3. Total de notas de este estudiante agrupadas por academic_year_id de la evaluación
$q1 = $conn->query("
    SELECT e.academic_year_id, ay.year as ay_year, COUNT(*) as total
    FROM evaluation_grades eg
    JOIN evaluations e ON e.id = eg.evaluation_id
    LEFT JOIN academic_year ay ON e.academic_year_id = ay.id
    WHERE eg.student_id = $sid
    GROUP BY e.academic_year_id
");
$output['notas_por_eval_year'] = [];
while ($r = $q1->fetch_assoc()) $output['notas_por_eval_year'][] = $r;

// 4. Total de notas agrupadas por academic_year_id del teacher_course
$q2 = $conn->query("
    SELECT tc.academic_year_id, ay.year as ay_year, COUNT(*) as total
    FROM evaluation_grades eg
    JOIN evaluations e ON e.id = eg.evaluation_id
    LEFT JOIN teacher_courses tc ON e.teacher_course_id = tc.id
    LEFT JOIN academic_year ay ON tc.academic_year_id = ay.id
    WHERE eg.student_id = $sid
    GROUP BY tc.academic_year_id
");
$output['notas_por_tc_year'] = [];
while ($r = $q2->fetch_assoc()) $output['notas_por_tc_year'][] = $r;

// 5. Columnas de la tabla evaluations (para ver si created_at existe)
$cols = $conn->query("SHOW COLUMNS FROM evaluations");
$output['evaluations_columns'] = [];
while ($r = $cols->fetch_assoc()) $output['evaluations_columns'][] = $r['Field'];

// 6. Muestra de 5 evaluaciones con notas para este estudiante
$q3 = $conn->query("
    SELECT eg.id, eg.grade, e.id as eval_id, e.title, e.bimestre, 
           e.academic_year_id as e_ay_id, e.teacher_course_id,
           tc.academic_year_id as tc_ay_id, tc.course_id,
           ac.name as curso_name
    FROM evaluation_grades eg
    JOIN evaluations e ON e.id = eg.evaluation_id
    LEFT JOIN teacher_courses tc ON e.teacher_course_id = tc.id
    LEFT JOIN academic_courses ac ON ac.id = tc.course_id
    WHERE eg.student_id = $sid
    ORDER BY eg.id DESC
    LIMIT 10
");
$output['muestra_notas'] = [];
while ($r = $q3->fetch_assoc()) $output['muestra_notas'][] = $r;

// 7. Verificar deuda del estudiante
$debt = $conn->query("SELECT * FROM student_fees WHERE student_id = $sid AND status != 'Pagado' LIMIT 5");
$output['deudas'] = [];
while ($r = $debt->fetch_assoc()) $output['deudas'][] = $r;

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
