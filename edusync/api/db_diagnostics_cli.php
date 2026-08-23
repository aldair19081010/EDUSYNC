<?php
$conn = new mysqli('127.0.0.1', 'root', '', 'escuela3');
if ($conn->connect_error) { die($conn->connect_error); }

$q = $conn->query("
    SELECT id_no, COUNT(*) as c 
    FROM student 
    GROUP BY id_no 
    HAVING c > 1
");

$data = [];
while ($r = $q->fetch_assoc()) {
    $data[] = $r;
}

echo json_encode(["duplicate_dnis" => $data], JSON_PRETTY_PRINT);
