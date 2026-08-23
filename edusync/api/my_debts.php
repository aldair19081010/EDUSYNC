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

// Función para registrar errores
function log_api_error($message, $details = null) {
    error_log("[my_debts API] " . $message . ($details ? " - Detalles: " . json_encode($details) : ""));
}

try {
    // Recibe el DNI por GET o POST con validación
    $dni = trim($_GET['dni'] ?? ($_POST['dni'] ?? ''));
    $data = [];

    if (!$dni) {
        echo json_encode(['status' => 'error', 'message' => 'DNI no recibido']);
        exit;
    }

    // Utilizar prepared statements para prevenir inyección SQL
    $stmt = $conn->prepare("SELECT id, name FROM student WHERE id_no = ?");
    $stmt->bind_param("s", $dni);
    $stmt->execute();
    $result = $stmt->get_result();
    $stu = $result->fetch_assoc();
    $stmt->close();

    if (!$stu) {
        echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']);
        exit;
    }
    
    $student_id = $stu['id'];
    $student_name = $stu['name'];

    if ($student_id) {
        // Consulta mejorada con prepared statement sin filtro de año académico
        $stmt = $conn->prepare("
            SELECT ef.id, ef.course_id, c.course, ef.total_fee, ef.discounted_amount, 
                   ay.year AS anio_academico, ay.description AS anio_descripcion
            FROM student_ef_list ef
            INNER JOIN courses c ON c.id = ef.course_id
            INNER JOIN academic_year ay ON ay.id = c.academic_year_id
            WHERE ef.student_id = ?
            ORDER BY ay.year DESC, c.course ASC
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        while ($row = $result->fetch_assoc()) {
            // Consulta de pagos también con prepared statement
            $stmt_paid = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as pagado FROM payments WHERE ef_id = ?");
            $stmt_paid->bind_param("i", $row['id']);
            $stmt_paid->execute();
            $paid_result = $stmt_paid->get_result();
            $pagado = floatval($paid_result->fetch_assoc()['pagado']);
            $stmt_paid->close();
            
            // Calcular el monto a pagar basado en el descuento (si existe)
            $total_original = floatval($row['total_fee']);
            $has_discount = !is_null($row['discounted_amount']);
            $amount_to_pay = $has_discount ? floatval($row['discounted_amount']) : $total_original;
            
            // Calcular el porcentaje de descuento
            $discount_percentage = 0;
            if ($has_discount && $total_original > 0) {
                $discount_percentage = round((($total_original - $amount_to_pay) / $total_original) * 100, 1);
            }
            
            $deuda = $amount_to_pay - $pagado;
            
            // Solo mostrar los conceptos con deuda pendiente
            if ($deuda > 0.01) {
                $data[] = [
                    'concepto' => $row['course'],
                    'total' => $total_original,
                    'amount_to_pay' => $amount_to_pay,
                    'pagado' => $pagado,
                    'deuda' => $deuda,
                    'has_discount' => $has_discount,
                    'discount_percentage' => $discount_percentage,
                    'course_id' => $row['course_id'],
                    'ef_id' => $row['id'],
                    'anio_academico' => $row['anio_academico'],
                    'anio_descripcion' => $row['anio_descripcion']
                ];
            }
        }
        
        // Obtener resumen por años académicos
        $years_summary = [];
        foreach ($data as $debt) {
            $year = $debt['anio_academico'];
            if (!isset($years_summary[$year])) {
                $years_summary[$year] = [
                    'año' => $year,
                    'total_deuda' => 0,
                    'conceptos' => 0
                ];
            }
            $years_summary[$year]['total_deuda'] += $debt['deuda'];
            $years_summary[$year]['conceptos']++;
        }
    }
    
    // Obtener información del año académico activo para la respuesta
    $active_year_result = $conn->query("SELECT year FROM academic_year WHERE is_active = 1 LIMIT 1");
    $active_year_info = $active_year_result->fetch_assoc();
    $current_year = $active_year_info ? $active_year_info['year'] : 'N/A';
    
    // Incluir información adicional del estudiante en la respuesta
    $response = [
        'status' => 'ok',
        'data' => $data,
        'student' => [
            'id' => $student_id,
            'dni' => $dni,
            'name' => $student_name
        ],
        'anio_academico_actual' => $current_year,
        'summary' => [
            'total_debt' => array_sum(array_column($data, 'deuda')),
            'count_concepts' => count($data)
        ],
        'resumen_por_año' => array_values($years_summary)
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    log_api_error("Error inesperado", $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Ocurrió un error inesperado. Por favor, intente nuevamente más tarde.',
        'error_code' => 500
    ]);
}
?>
