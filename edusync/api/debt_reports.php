<?php
include_once '../includes/session_check.php';
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
include 'db.php';

header('Content-Type: application/json');

try {    // Obtener parámetros de filtrado
    $type = $_GET['type'] ?? 'general';
    $school_id = intval($_SESSION['login_school_id'] ?? 0);

    if ($school_id <= 0) {
        throw new Exception('No hay colegio activo en la sesión. Inicie sesión nuevamente.');
    }

    $academic_year_id = isset($_GET['academic_year_id']) && !empty($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : null;
    $student_id = isset($_GET['student_id']) && !empty($_GET['student_id']) ? intval($_GET['student_id']) : null;
    $nivel = isset($_GET['nivel']) && !empty($_GET['nivel']) ? $conn->real_escape_string($_GET['nivel']) : null;
    $grado = isset($_GET['grado']) && !empty($_GET['grado']) ? $conn->real_escape_string($_GET['grado']) : null;
    $seccion = isset($_GET['seccion']) && !empty($_GET['seccion']) ? $conn->real_escape_string($_GET['seccion']) : null;
    $concepto = isset($_GET['concepto']) && !empty($_GET['concepto']) ? intval($_GET['concepto']) : null;
    $start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : null;
    $end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : null;
    $status = isset($_GET['status']) && !empty($_GET['status']) ? $conn->real_escape_string($_GET['status']) : null;    // Construir consulta SQL base
    $sql = "
        SELECT 
            ef.id as ef_id,
            s.id as student_id,
            s.name as student_name,
            s.nivel,
            s.grado,
            s.seccion,
            s.status,
            c.id as course_id,
            c.course,
            c.level,
            c.grades,
            ay.year,
            CONCAT(c.course, ' - ', c.level, ' - ', s.grado, ' - ', COALESCE(ay.year, 'Sin año')) as concepto,
            ef.total_fee,
            ef.discounted_amount,
            COALESCE(SUM(p.amount), 0) as pagado
        FROM 
            student_ef_list ef
            INNER JOIN student s ON ef.student_id = s.id
            INNER JOIN courses c ON ef.course_id = c.id
            LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
            LEFT JOIN payments p ON ef.id = p.ef_id
    ";

    // Condiciones de filtrado
    $where_conditions = [];
    
    if ($student_id) {
        $where_conditions[] = "s.id = $student_id";
    }

    $where_conditions[] = "s.school_id = $school_id";
    
    if ($academic_year_id) {
        $where_conditions[] = "ay.id = $academic_year_id";
    }
    
    if ($nivel) {
        $where_conditions[] = "s.nivel = '$nivel'";
    }
    
    if ($grado) {
        $where_conditions[] = "s.grado = '$grado'";
    }
      if ($seccion) {
        $where_conditions[] = "s.seccion = '$seccion'";
    }
    
    if ($concepto) {
        $where_conditions[] = "c.id = $concepto";
    }
    
    if ($status) {
        $where_conditions[] = "s.status = '$status'";
    }
    
    if ($start_date && $end_date) {
        $where_conditions[] = "(p.date_created BETWEEN '$start_date' AND '$end_date' OR p.date_created IS NULL)";
    } else if ($start_date) {
        $where_conditions[] = "(p.date_created >= '$start_date' OR p.date_created IS NULL)";
    } else if ($end_date) {
        $where_conditions[] = "(p.date_created <= '$end_date' OR p.date_created IS NULL)";
    }      // Añadir condiciones WHERE si existen
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
      // Agrupar por ef_id para sumar los pagos
    $sql .= " GROUP BY ef.id, s.id, s.name, s.nivel, s.grado, s.seccion, s.status, c.id, c.course, c.level, c.grades, ay.year, ef.total_fee, ef.discounted_amount";
    
    // Ordenar resultados
    $sql .= " ORDER BY s.name ASC, c.course ASC";
    
    // Ejecutar consulta
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Error en la consulta: " . $conn->error);
    }
      // Procesar resultados
    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Usar el monto con descuento si existe, sino usar el monto total
        $amount_to_pay = !empty($row['discounted_amount']) ? floatval($row['discounted_amount']) : floatval($row['total_fee']);
        $row['amount_to_pay'] = $amount_to_pay;
        $row['deuda'] = $amount_to_pay - $row['pagado'];
        $data[] = $row;
    }
    
    // Retornar resultados en formato JSON
    echo json_encode([
        'status' => 'ok',
        'data' => $data,
        'count' => count($data)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
