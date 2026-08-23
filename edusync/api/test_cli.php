<?php
// Usar 127.0.0.1 para evitar que el socket IPv6 de localhost se cuelgue en la CLI de windows
$conn = new mysqli('127.0.0.1', 'root', '', 'escuela3');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$dni = '80995934';
$stu = $conn->query("SELECT id, name, school_id FROM student WHERE id_no = '$dni'")->fetch_assoc();
$student_id = $stu['id'] ?? 0;
echo "Student ID: $student_id\n";

if ($student_id == 0) {
    die("Paso fallido, sin estudiante\n");
}

$comp_q = $conn->query("SELECT * FROM evaluation_grades WHERE student_id = $student_id LIMIT 5");
if (!$comp_q) {
    echo "SQL ERROR ON EVALUATION GRADES: " . $conn->error . "\n";
} else {
    while($r = $comp_q->fetch_assoc()) {
        print_r($r);
    }
}

$tables = $conn->query("SHOW TABLES LIKE '%competenc%'");
while($t = $tables->fetch_array()) {
    echo "Found table: " . $t[0] . "\n";
}
