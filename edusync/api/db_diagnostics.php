<?php
require 'db_connect.php';

header('Content-Type: application/json');

$output = [];

$q_students = $conn->query("
    SELECT eg.student_id, COUNT(*) as grade_count 
    FROM evaluation_grades eg 
    GROUP BY eg.student_id 
    ORDER BY grade_count DESC
    LIMIT 3
");

while ($student = $q_students->fetch_assoc()) {
    $sid = $student['student_id'];
    $stu_data = [
        "student_id" => $sid,
        "total_grades" => $student['grade_count'],
        "years" => []
    ];
    
    // Check what evaluations the student has, and what their academic_year_id is!
    $q_evals = $conn->query("
        SELECT 
            ay.year as evaluation_year, 
            e.academic_year_id as e_ay_id,
            COUNT(eg.id) as grades_per_year
        FROM evaluation_grades eg
        JOIN evaluations e ON eg.evaluation_id = e.id
        LEFT JOIN academic_year ay ON e.academic_year_id = ay.id
        WHERE eg.student_id = $sid
        GROUP BY ay.year, e.academic_year_id
    ");
    
    while ($eval_data = $q_evals->fetch_assoc()) {
        $stu_data["years"][] = $eval_data;
    }
    
    // Also check what teacher courses their evaluations are linked to
    $q_tc = $conn->query("
        SELECT 
            tc_ay.year as tc_year, 
            tc.academic_year_id as tc_ay_id,
            COUNT(eg.id) as grades_per_tc_year
        FROM evaluation_grades eg
        JOIN evaluations e ON eg.evaluation_id = e.id
        JOIN teacher_courses tc ON e.teacher_course_id = tc.id
        LEFT JOIN academic_year tc_ay ON tc.academic_year_id = tc_ay.id
        WHERE eg.student_id = $sid
        GROUP BY tc_ay.year, tc.academic_year_id
    ");
    
    while ($tc_data = $q_tc->fetch_assoc()) {
        $stu_data["teacher_course_years"][] = $tc_data;
    }

    $output[] = $stu_data;
}

echo json_encode(["status" => "success", "data" => $output], JSON_PRETTY_PRINT);
