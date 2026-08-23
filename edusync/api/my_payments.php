<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
ob_start();
ini_set('display_errors', 0);
include '../db_connect.php';

// Ensure clean JSON output
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');

// Recibe el DNI por GET o POST
$dni = $_GET['dni'] ?? ($_POST['dni'] ?? '');
$data = [];

if (!$dni) {
    echo json_encode(['status' => 'error', 'message' => 'DNI no recibido']);
    exit;
}

// Busca el ID del estudiante por su DNI
$stu = $conn->query("SELECT id FROM student WHERE id_no = '$dni'")->fetch_assoc();
if (!$stu) {
    echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']);
    exit;
}
$student_id = $stu['id'] ?? 0;

if ($student_id) {
    $q = $conn->query("
        SELECT 
            p.id AS pid,
            p.ef_id,
            p.date_created, 
            c.course AS concepto, 
            p.amount, 
            p.receipt_no,
            pm.name AS medio_pago,
            ay.year AS anio_academico,
            ay.description AS anio_descripcion
        FROM payments p
        INNER JOIN student_ef_list ef ON ef.id = p.ef_id
        INNER JOIN courses c ON c.id = ef.course_id
        INNER JOIN academic_year ay ON ay.id = c.academic_year_id
        LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
        WHERE ef.student_id = $student_id
        ORDER BY ay.year DESC, p.date_created DESC
    ");
    if (!$q) {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
        exit;
    }
    while ($row = $q->fetch_assoc()) {
        $data[] = [
            'pid' => $row['pid'],
            'ef_id' => $row['ef_id'],
            'fecha' => $row['date_created'],
            'concepto' => $row['concepto'],
            'monto' => floatval($row['amount']),
            'recibo' => $row['receipt_no'],
            'medio_pago' => $row['medio_pago'] ?? '',
            'anio_academico' => $row['anio_academico'],
            'anio_descripcion' => $row['anio_descripcion']
        ];
    }
    
    // Obtener resumen por años académicos
    $years_summary = [];
    foreach ($data as $payment) {
        $year = $payment['anio_academico'];
        if (!isset($years_summary[$year])) {
            $years_summary[$year] = [
                'año' => $year,
                'total_pagos' => 0,
                'monto_total' => 0
            ];
        }
        $years_summary[$year]['total_pagos']++;
        $years_summary[$year]['monto_total'] += $payment['monto'];
    }
}

echo json_encode([
    'status' => 'ok', 
    'data' => $data,
    'total_pagos' => count($data),
    'monto_total' => array_sum(array_column($data, 'monto')),
    'resumen_por_año' => array_values($years_summary)
]);
?>