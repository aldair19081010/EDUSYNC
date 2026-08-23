<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
ob_start();
ini_set('display_errors', 0);
include '../db_connect.php';

// Ensure clean JSON output
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');
$students = [];
$q = $conn->query("SELECT * FROM student ORDER BY name ASC");
while ($row = $q->fetch_assoc()) {
    $students[] = $row;
}
echo json_encode(['status' => 'ok', 'data' => $students]);
?>
