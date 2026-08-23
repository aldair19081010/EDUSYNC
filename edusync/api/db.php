<?php
$conn = new mysqli('localhost', 'root', '', 'escuela3') or die("Could not connect to mysql" . mysqli_error($conn));
ob_start();
ini_set('display_errors', 0);
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');
?>
