<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
ob_start();
ini_set('display_errors', 0);
include '../db_connect.php';
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');
$academic_courses = [];
$q = $conn->query("SELECT * FROM academic_courses ORDER BY name ASC");
while ($row = $q->fetch_assoc()) {
    $academic_courses[] = $row;
}
echo json_encode(['status' => 'ok', 'data' => $academic_courses]);
?>
