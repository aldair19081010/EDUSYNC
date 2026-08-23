<?php
// Evitar salida accidental y asegurar JSON limpio
ob_start();
ini_set('display_errors', 0);
// Configuración de sesión consistente
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

include 'db_connect.php';

// Comprobar si el usuario está logueado
if(!isset($_SESSION['login_id'])){
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'draw' => 0,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => []
    ]);
    exit;
}

// Parámetros de DataTables
$draw = intval($_GET['draw'] ?? 1);
$start = intval($_GET['start'] ?? 0);
$length = intval($_GET['length'] ?? 10);
$searchValue = trim($_GET['search']['value'] ?? '');

// Escapar valor de búsqueda
$searchValue = $conn->real_escape_string($searchValue);

// Filtro de colegio y búsqueda
$school_id = intval($_SESSION['login_school_id'] ?? 0);
$where = "WHERE s.school_id = $school_id";

// Filtro por año académico
$yearFilter = $_GET['year'] ?? '';
if (!empty($yearFilter)) {
    $yearFilter = $conn->real_escape_string($yearFilter);
    $where .= " AND EXISTS (SELECT 1 FROM courses c2 LEFT JOIN academic_year ay2 ON c2.academic_year_id = ay2.id WHERE c2.id = ef.course_id AND ay2.year = '$yearFilter')";
}

// Filtro por nivel
$nivelFilter = $_GET['nivel'] ?? '';
if (!empty($nivelFilter)) {
    $nivelFilter = $conn->real_escape_string($nivelFilter);
    $where .= " AND s.nivel = '$nivelFilter'";
}

// Filtro por estado de estudiante
$studentStatusFilter = isset($_GET['student_status']) ? $_GET['student_status'] : 'Activo';
if (!empty($studentStatusFilter)) {
    $studentStatusFilter = $conn->real_escape_string($studentStatusFilter);
    $where .= " AND s.status = '$studentStatusFilter'";
}

// Filtro por estado de pago
$estadoPago = $_GET['estado_pago'] ?? '';
$having = "";
if ($estadoPago === 'pendiente') {
    $having = "HAVING paid = 0";
} elseif ($estadoPago === 'parcial') {
    $having = "HAVING paid > 0 AND paid < amount_to_pay";
} elseif ($estadoPago === 'pagado') {
    $having = "HAVING paid >= amount_to_pay";
}

if (!empty($searchValue)) {
    $where .= " AND (s.name LIKE '%$searchValue%' OR s.id_no LIKE '%$searchValue%' OR c.course LIKE '%$searchValue%')";
}

$hasFilter = !empty($searchValue) || !empty($nivelFilter) || !empty($estadoPago) || !empty($yearFilter) || !empty($studentStatusFilter);

// Total de registros sin filtro (query simple)
$totalQuery = $conn->query("SELECT COUNT(*) as total FROM student_ef_list ef INNER JOIN student s ON s.id = ef.student_id WHERE s.school_id = $school_id");
$totalRecords = $totalQuery ? $totalQuery->fetch_assoc()['total'] : 0;

// Total filtrado
if ($hasFilter) {
    if (!empty($estadoPago)) {
        // Con filtro de estado de pago necesitamos contar con subquery
        $countSql = "SELECT COUNT(*) as total FROM (
            SELECT ef.id,
                (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.ef_id = ef.id) as paid,
                IF(ef.discounted_amount > 0, ef.discounted_amount, ef.total_fee) as amount_to_pay
            FROM student_ef_list ef 
            INNER JOIN student s ON s.id = ef.student_id 
            INNER JOIN courses c ON c.id = ef.course_id 
            $where $having
        ) as filtered";
        $filteredQuery = $conn->query($countSql);
        $filteredRecords = $filteredQuery ? $filteredQuery->fetch_assoc()['total'] : 0;
    } else {
        $filteredQuery = $conn->query("SELECT COUNT(*) as total FROM student_ef_list ef INNER JOIN student s ON s.id = ef.student_id INNER JOIN courses c ON c.id = ef.course_id $where");
        $filteredRecords = $filteredQuery ? $filteredQuery->fetch_assoc()['total'] : 0;
    }
} else {
    $filteredRecords = $totalRecords;
}

// Determinar ordenación desde DataTables
$orderCol = intval($_GET['order'][0]['column'] ?? 2);
$orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$orderColumns = ['ef.id', 's.id_no', 's.name', 'c.course', 'ef.total_fee', 'paid', 'balance', 'ef.id'];
$orderBy = isset($orderColumns[$orderCol]) ? $orderColumns[$orderCol] : 's.name';
// No ordenar por alias calculado, usar nombre directamente
if ($orderBy === 'paid' || $orderBy === 'balance') {
    $orderBy = 's.name';
}

// Usar subquery para pagos en vez de GROUP BY (mucho más rápido)
$sql = "SELECT 
        ef.id,
        ef.total_fee,
        ef.discounted_amount,
        s.id_no,
        s.name as sname,
        s.nivel,
        s.grado,
        c.course,
        c.level as course_level,
        ay.year,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.ef_id = ef.id) as paid,
        IF(ef.discounted_amount > 0, ef.discounted_amount, ef.total_fee) as amount_to_pay
        FROM student_ef_list ef 
        INNER JOIN student s ON s.id = ef.student_id 
        INNER JOIN courses c ON c.id = ef.course_id 
        LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
        $where
        $having
        ORDER BY $orderBy $orderDir
        LIMIT $start, $length";

$fees = $conn->query($sql);

if (!$fees) {
    error_log('fees_table_data SQL error: ' . ($conn->error ?? 'unknown') . ' -- SQL: ' . $sql);
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => []
    ]);
    exit;
}

$data = [];
$i = $start + 1;

while ($row = $fees->fetch_assoc()) {
    $paid = floatval($row['paid'] ?? 0);
    $amount_to_pay = !empty($row['discounted_amount']) ? floatval($row['discounted_amount']) : floatval($row['total_fee'] ?? 0);
    $balance = $amount_to_pay - $paid;
    
    // Construir concepto con año académico en texto lineal
    $concepto = htmlspecialchars($row['course']);
    if ($row['year']) {
        $concepto .= ' (' . htmlspecialchars($row['year']) . ')';
    }
    
    // Determinar color del balance
    $balance_color = $balance > 0 ? '#dc3545' : '#28a745'; // rojo si debe, verde si pagó
    
    // Construir tarifa con descuento si existe
    $tarifa_text = '<span style="color: #17a2b8; font-weight: 600;">S/. ' . number_format($row['total_fee'], 2) . '</span>';
    if (!empty($row['discounted_amount'])) {
        $descuento = floatval($row['total_fee']) - floatval($row['discounted_amount']);
        $tarifa_text .= '<br><small style="color: #28a745;">→ S/. ' . number_format($row['discounted_amount'], 2) . ' (Dcto: S/. ' . number_format($descuento, 2) . ')</small>';
    }
    
    $data[] = [
        '<div class="custom-control custom-checkbox text-center"><input type="checkbox" class="custom-control-input fee-checkbox" id="check_'.intval($row['id']).'" value="'.intval($row['id']).'"><label class="custom-control-label" for="check_'.intval($row['id']).'"></label></div>',
        htmlspecialchars($row['id_no']),
        htmlspecialchars($row['sname']),
        $concepto,
        $tarifa_text,
        '<span style="color: #28a745; font-weight: 600;">S/. ' . number_format($paid, 2) . '</span>',
        '<span style="color: ' . $balance_color . '; font-weight: 600;">S/. ' . number_format($balance, 2) . '</span>',
        '<button class="btn btn-sm btn-primary view_payment" data-id="' . intval($row['id']) . '" style="margin: 2px;"><i class="fa fa-eye"></i></button>' .
        '<button class="btn btn-sm btn-info edit_fees" data-id="' . intval($row['id']) . '" style="margin: 2px;"><i class="fa fa-edit"></i></button>' .
        '<button class="btn btn-sm btn-danger delete_fees" data-id="' . intval($row['id']) . '" style="margin: 2px;"><i class="fa fa-trash"></i></button>'
    ];
}

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $totalRecords,
    'recordsFiltered' => $filteredRecords,
    'data' => $data
]);
?>

