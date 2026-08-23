
<?php
// Preparar salida limpia y sesión consistente
ob_start();
ini_set('display_errors', 0);
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
include('db_connect.php');
$school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
// Fallback: intentar obtener school_id desde user
if ($school_id === 0 && isset($_SESSION['login_id'])) {
    $uid = intval($_SESSION['login_id']);
    $ur = $conn->query("SELECT school_id FROM users WHERE id = $uid LIMIT 1");
    if ($ur && $ur->num_rows > 0) {
        $school_id = intval($ur->fetch_assoc()['school_id']);
        if ($school_id > 0) $_SESSION['login_school_id'] = $school_id;
    }
}
// Fallback: si sólo hay 1 colegio, asignarlo
if ($school_id === 0) {
    $sc_q = $conn->query("SELECT id FROM schools LIMIT 2");
    if ($sc_q && $sc_q->num_rows == 1) {
        $school_id = intval($sc_q->fetch_assoc()['id']);
        $_SESSION['login_school_id'] = $school_id;
    }
}

$draw = intval($_GET['draw'] ?? 1);
$start = intval($_GET['start'] ?? 0);
$length = intval($_GET['length'] ?? 10);
$searchValue = $_GET['search']['value'] ?? '';
$orderCol = intval($_GET['order'][0]['column'] ?? 0);
$orderDir = ($_GET['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$columns = [
    'p.id', 'p.date_created', 's.id_no', 'p.receipt_no', 's.name', 'p.amount', 'c.course', 'p.id'
];
$orderBy = isset($columns[$orderCol]) ? $columns[$orderCol] : 'p.id';

// Total sin filtro (query ligera, sin JOIN a courses)
$totalQuery = $conn->query("SELECT COUNT(*) as total FROM payments p INNER JOIN student_ef_list ef ON ef.id = p.ef_id INNER JOIN student s ON s.id = ef.student_id WHERE s.school_id = $school_id");
$totalRecords = $totalQuery ? $totalQuery->fetch_assoc()['total'] : 0;

// Filtro base
$where = "WHERE s.school_id = $school_id";

// Filtros avanzados
$yearFilter = $_GET['year'] ?? '';
if (!empty($yearFilter)) {
    $yearFilter = $conn->real_escape_string($yearFilter);
    // Como academic_year está relacionado a través del curso (courses) que se vincula al fee (student_ef_list),
    // usaremos un EXISTS o simplemente el nombre de la otra tabla si ya está en los JOINs.
    // La macro query base ya tiene INNER JOIN courses y LEFT JOIN academic_year.
    // Pero el COUNT() optimizado no tiene el LEFT JOIN a academic_year, así que mejor usar WHERE exists:
    $where .= " AND EXISTS (SELECT 1 FROM courses c2 LEFT JOIN academic_year ay2 ON c2.academic_year_id = ay2.id WHERE c2.id = ef.course_id AND ay2.year = '$yearFilter')";
}

$nivelFilter = $_GET['nivel'] ?? '';
if (!empty($nivelFilter)) {
    $nivelFilter = $conn->real_escape_string($nivelFilter);
    $where .= " AND s.nivel = '$nivelFilter'";
}

$mesFilter = $_GET['mes'] ?? '';
if (!empty($mesFilter)) {
    // mesFilter viene en formato YYYY-MM
    $mesFilter = $conn->real_escape_string($mesFilter);
    $where .= " AND DATE_FORMAT(p.date_created, '%Y-%m') = '$mesFilter'";
}

$studentStatusFilter = isset($_GET['student_status']) ? $_GET['student_status'] : 'Activo';
if (!empty($studentStatusFilter)) {
    $studentStatusFilter = $conn->real_escape_string($studentStatusFilter);
    $where .= " AND s.status = '$studentStatusFilter'";
}

if ($searchValue !== '') {
    $searchValue = $conn->real_escape_string($searchValue);
    $where .= " AND (s.name LIKE '%$searchValue%' OR s.id_no LIKE '%$searchValue%' OR p.receipt_no LIKE '%$searchValue%' OR c.course LIKE '%$searchValue%' OR p.amount LIKE '%$searchValue%')";
}

$hasFilter = !empty($searchValue) || !empty($nivelFilter) || !empty($mesFilter) || !empty($yearFilter) || !empty($studentStatusFilter);

// Total sin filtro (query ligera)
$totalQuery = $conn->query("SELECT COUNT(*) as total FROM payments p INNER JOIN student_ef_list ef ON ef.id = p.ef_id INNER JOIN student s ON s.id = ef.student_id WHERE s.school_id = $school_id");
$totalRecords = $totalQuery ? $totalQuery->fetch_assoc()['total'] : 0;

// Filtro aplicado
if ($hasFilter) {
    $filteredQuery = $conn->query("SELECT COUNT(*) as total FROM payments p INNER JOIN student_ef_list ef ON ef.id = p.ef_id INNER JOIN student s ON s.id = ef.student_id INNER JOIN courses c ON c.id = ef.course_id $where");
    $filteredRecords = $filteredQuery ? $filteredQuery->fetch_assoc()['total'] : 0;
} else {
    $filteredRecords = $totalRecords;
}

$sql = "SELECT p.id, p.ef_id, p.amount, p.date_created, p.receipt_no, 
        s.name as sname, s.id_no, s.nivel, s.grado, 
        c.course, c.level as course_level, 
        COALESCE(ay.year, 'Sin año') as year
        FROM payments p 
        INNER JOIN student_ef_list ef ON ef.id = p.ef_id 
        INNER JOIN student s ON s.id = ef.student_id 
        INNER JOIN courses c ON c.id = ef.course_id 
        LEFT JOIN academic_year ay ON c.academic_year_id = ay.id 
        $where ORDER BY $orderBy $orderDir LIMIT $start, $length";
$payments = $conn->query($sql);

$data = [];
$i = $start + 1;
if ($payments) {
    while ($row = $payments->fetch_assoc()) {
        $concepto = $row['course'] . ' - ' . $row['year'];
        $data[] = [
            '<div class="custom-control custom-checkbox text-center"><input type="checkbox" class="custom-control-input payment-checkbox" id="check_p_' . $row['id'] . '" value="' . $row['id'] . '"><label class="custom-control-label" for="check_p_' . $row['id'] . '"></label></div>',
            '<span class="text-muted">' . date("d/m/Y", strtotime($row['date_created'])) . '</span><br><small class="text-muted">' . date("H:i A", strtotime($row['date_created'])) . '</small>',
            '<strong>' . $row['id_no'] . '</strong>',
            '<span class="badge badge-info">' . $row['receipt_no'] . '</span>',
            '<strong>' . ucwords($row['sname']) . '</strong>',
            '<span class="text-success font-weight-bold">S/. ' . number_format($row['amount'], 2) . '</span>',
            '<div><strong>' . htmlspecialchars($concepto) . '</strong><br><small class="text-muted"><i class="fa fa-graduation-cap"></i> ' . $row['nivel'] . ' - ' . $row['grado'] . '</small></div>',
            '<div class="text-center">' .
                '<button class="btn btn-info btn-sm view_payment" type="button" data-id="' . $row['id'] . '" data-ef_id="' . $row['ef_id'] . '" title="Ver"><i class="fa fa-eye"></i></button> ' .
                '<button class="btn btn-primary btn-sm edit_payment" type="button" data-id="' . $row['id'] . '" title="Editar"><i class="fa fa-edit"></i></button> ' .
                '<button class="btn btn-danger btn-sm delete_payment" type="button" data-id="' . $row['id'] . '" title="Eliminar"><i class="fa fa-trash-alt"></i></button>' .
            '</div>'
        ];
    }
}
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $totalRecords,
    'recordsFiltered' => $filteredRecords,
    'data' => $data
]);
