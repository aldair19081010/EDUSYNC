<?php
require '../db_connect.php';
$res = $conn->query('
    SELECT eg.student_id, COUNT(DISTINCT tc.academic_year_id) as num_years
    FROM evaluation_grades eg
    JOIN evaluations e ON e.id = eg.evaluation_id
    JOIN teacher_courses tc ON tc.id = e.teacher_course_id
    GROUP BY eg.student_id
    HAVING num_years > 1
');
$data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
echo json_encode($data);