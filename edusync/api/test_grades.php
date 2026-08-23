<?php
require '../db_connect.php';
header('Content-Type: application/json');

// Find a student that has evaluation_grades
$q1 = $conn->query("SELECT student_id, COUNT(*) as c FROM evaluation_grades GROUP BY student_id ORDER BY c DESC LIMIT 1");
if (!$q1 || $q1->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "No evaluation grades found in DB"]);
    exit;
}
$s = $q1->fetch_assoc();
$student_id = $s['student_id'];

// Get all their evaluations and their academic year IDs
$q2 = $conn->query("
    SELECT e.id as eval_id, e.title, e.academic_year_id as eval_ay_id, 
           tc.academic_year_id as tc_ay_id, ay.year as eval_year_name, tc_ay.year as tc_year_name
    FROM evaluation_grades eg
    JOIN evaluations e ON eg.evaluation_id = e.id
    JOIN teacher_courses tc ON e.teacher_course_id = tc.id
    JOIN academic_year ay ON e.academic_year_id = ay.id
    JOIN academic_year tc_ay ON tc.academic_year_id = tc_ay.id
    WHERE eg.student_id = $student_id
    LIMIT 20
");
$data = [];
while($r = $q2->fetch_assoc()) {
    $data[] = $r;
}

echo json_encode([
    "student_id" => $student_id,
    "grade_count" => $s['c'],
    "sample_evaluations" => $data
], JSON_PRETTY_PRINT);
