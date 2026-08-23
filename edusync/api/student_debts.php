<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
ob_start();
ini_set('display_errors', 0);
include '../db_connect.php';
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');

$students = [];
$q = $conn->query("SELECT s.id, s.name, s.id_no FROM student s ORDER BY s.name ASC");
while ($stu = $q->fetch_assoc()) {    // Buscar todos los conceptos de pago del alumno
    $fees = $conn->query("SELECT ef.id, ef.total_fee, ef.discounted_amount, c.course 
        FROM student_ef_list ef 
        INNER JOIN courses c ON c.id = ef.course_id 
        WHERE ef.student_id = {$stu['id']}");
    $deudas = [];
    $total_deuda = 0.0;
    while ($fee = $fees->fetch_assoc()) {
        $paid_q = $conn->query("SELECT SUM(amount) as pagado FROM payments WHERE ef_id = {$fee['id']}");
        $pagado = 0.0;
        if ($paid_q && $paid_q->num_rows > 0) {
            $pagado = floatval($paid_q->fetch_assoc()['pagado']);
        }
        
        // Usar el monto con descuento si existe, sino usar el monto total
        $amount_to_pay = !empty($fee['discounted_amount']) ? floatval($fee['discounted_amount']) : floatval($fee['total_fee']);
        $deuda = $amount_to_pay - $pagado;
        
        if ($deuda > 0.001) { // evitar problemas de redondeo
            $deudas[] = [
                'concepto' => $fee['course'],
                'total' => floatval($fee['total_fee']),
                'amount_to_pay' => $amount_to_pay,
                'pagado' => $pagado,
                'deuda' => $deuda
            ];
            $total_deuda += $deuda;
        }
    }
    $students[] = [
        'id' => intval($stu['id']),
        'nombre' => $stu['name'],
        'dni' => $stu['id_no'],
        'deudas' => $deudas,
        'total_deuda' => $total_deuda
    ];
}

echo json_encode(['status' => 'ok', 'data' => $students]);
?>
