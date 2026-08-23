<?php
session_start();
$_SESSION['login_id'] = 8;
$_SESSION['login_type'] = 2;
$_SESSION['login_is_director'] = 1;
$_SESSION['login_school_id'] = 1;

include 'db_connect.php';

$school_id = $_SESSION['login_school_id'] ?? null;
$login_type = $_SESSION['login_type'] ?? null;
$is_admin = ($login_type == 1);
$is_teacher = ($login_type == 2);
$is_director = $_SESSION['login_is_director'] ?? 0;

if ($is_director == 1) {
    $is_admin = true;
    $is_teacher = false;
}

echo "is_admin: " . ($is_admin ? "TRUE" : "FALSE") . "\n";
echo "is_teacher: " . ($is_teacher ? "TRUE" : "FALSE") . "\n";

$selected_academic_year = 5; // assuming 5 is active

if ($is_admin) {
    $q = $conn->prepare("SELECT DISTINCT ac.id, ac.name, ac.level FROM academic_courses ac 
                         INNER JOIN teacher_courses tc ON ac.id = tc.course_id
                         WHERE ac.school_id = ? AND tc.academic_year_id = ? ORDER BY ac.name");
    $q->bind_param("ii", $school_id, $selected_academic_year);
    $q->execute();
    $res = $q->get_result();
    echo "Courses count admin: " . $res->num_rows . "\n";
}
