<?php

// Preparar salida limpia y sesión consistente
ob_start();
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
if (!isset($_SESSION['login_id']) && !isset($_SESSION['login_type'])) {
    http_response_code(401);
    echo json_encode(['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit;
}
include('db_connect.php');
$school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
$can_manage_students = (($_SESSION['login_type'] ?? 0) == 1);
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
$orderCol = intval($_GET['order'][0]['column'] ?? 2);
$orderDir = ($_GET['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$columns = [
    'id', 'id_no', 'name', 'genero', 'name', 'nivel', 'grado', 'seccion', 'status', 'id'
];
$orderBy = isset($columns[$orderCol]) ? $columns[$orderCol] : 'name';

$where = "WHERE school_id = $school_id";

// Filtros personalizados desde DataTables (nivel, estado, grado, seccion)
$nivel = $_GET['nivel'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$gradoFilter = isset($_GET['grado']) ? trim($_GET['grado']) : '';
$seccionFilter = isset($_GET['seccion']) ? trim($_GET['seccion']) : '';
$hasCustomFilter = false;

if ($nivel !== 'all') {
    $where .= " AND nivel = '" . $conn->real_escape_string($nivel) . "'";
    $hasCustomFilter = true;
}
if ($status !== 'all') {
    $where .= " AND status = '" . $conn->real_escape_string($status) . "'";
    $hasCustomFilter = true;
}
if ($gradoFilter !== '') {
    $where .= " AND grado = '" . $conn->real_escape_string($gradoFilter) . "'";
    $hasCustomFilter = true;
}
if ($seccionFilter !== '') {
    $where .= " AND seccion = '" . $conn->real_escape_string($seccionFilter) . "'";
    $hasCustomFilter = true;
}
if ($searchValue !== '') {
    $searchValue = $conn->real_escape_string($searchValue);
    $where .= " AND (name LIKE '%$searchValue%' OR id_no LIKE '%$searchValue%' OR nivel LIKE '%$searchValue%' OR grado LIKE '%$searchValue%' OR seccion LIKE '%$searchValue%' OR status LIKE '%$searchValue%')";
    $hasCustomFilter = true;
}

// Total sin ningún filtro
$totalQuery = $conn->query("SELECT COUNT(*) as total FROM student WHERE school_id = $school_id");
$totalRecords = $totalQuery ? $totalQuery->fetch_assoc()['total'] : 0;

// Total filtrado: solo hacer query extra si hay algún filtro activo
if ($hasCustomFilter) {
    $filteredQuery = $conn->query("SELECT COUNT(*) as total FROM student $where");
    $filteredRecords = $filteredQuery ? $filteredQuery->fetch_assoc()['total'] : 0;
} else {
    $filteredRecords = $totalRecords;
}

// Solo traer las columnas que se usan en la tabla
$sql = "SELECT id, id_no, name, email, contact, address, nivel, grado, seccion, status, genero,
        tutor1_nombre, tutor1_apellido, tutor1_telefono, tutor1_relacion
        FROM student $where ORDER BY $orderBy $orderDir LIMIT $start, $length";
$students = $conn->query($sql);

$data = [];
$i = $start + 1;
$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

if ($students) {
    while ($row = $students->fetch_assoc()) {
    $contacto = '<p class="student-info"><i class="fa fa-envelope text-primary"></i> ' . $escape($row['email'] ?: 'N/A') . '</p>';
    $contacto .= '<p class="student-info"><i class="fa fa-phone text-success"></i> ' . $escape($row['contact'] ?: 'N/A') . '</p>';
    $contacto .= '<p class="student-info"><i class="fa fa-map-marker-alt text-danger"></i> ' . $escape($row['address'] ?: 'N/A') . '</p>';
        if (!empty($row['tutor1_nombre']) || !empty($row['tutor1_telefono'])) {
            $contacto .= '<hr style="margin: 8px 0;">';
            $contacto .= '<small class="text-muted"><strong>Tutor Principal:</strong></small>';
            if (!empty($row['tutor1_nombre'])) {
                $contacto .= '<p class="student-info"><i class="fa fa-user text-info"></i> ' . $escape($row['tutor1_nombre'] . ' ' . $row['tutor1_apellido']) . '</p>';
            }
            if (!empty($row['tutor1_telefono'])) {
                $contacto .= '<p class="student-info"><i class="fa fa-phone text-warning"></i> ' . $escape($row['tutor1_telefono']) . '</p>';
            }
            if (!empty($row['tutor1_relacion'])) {
                $contacto .= '<p class="student-info"><i class="fa fa-heart text-danger"></i> ' . $escape($row['tutor1_relacion']) . '</p>';
            }
        }
        $nivel_badge = '<span class="badge ';
        if ($row['nivel'] == 'Inicial') $nivel_badge .= 'badge-warning';
        elseif ($row['nivel'] == 'Primaria') $nivel_badge .= 'badge-success';
        elseif ($row['nivel'] == 'Secundaria') $nivel_badge .= 'badge-info';
        else $nivel_badge .= 'badge-secondary';
        $nivel_badge .= '" style="padding: 5px 10px; font-weight: 500;">' . $escape($row['nivel'] ?: 'N/A') . '</span>';
        $grado_badge = '<span class="badge badge-light" style="padding: 5px 10px; font-weight: 500; background-color: #f8f9fc;">' . $escape($row['grado'] ?: 'N/A') . '</span>';
        $seccion_badge = '<span class="badge badge-light" style="padding: 5px 10px; font-weight: 500; background-color: #f8f9fc;">' . $escape($row['seccion'] ?? '-') . '</span>';
            $status_badge = '<span class="badge badge-status ';
        if ($row['status'] == 'Egresado') $status_badge .= 'badge-egresado';
        elseif ($row['status'] == 'Retirado') $status_badge .= 'badge-retirado';
        elseif ($row['status'] == 'Activo') $status_badge .= 'badge-activo';
        else $status_badge .= 'badge-activo';
        $status_badge .= '"><i class="fa ';
        if ($row['status'] == 'Egresado') $status_badge .= 'fa-graduation-cap';
        elseif ($row['status'] == 'Retirado') $status_badge .= 'fa-user-slash';
        else $status_badge .= 'fa-user-check';
        $status_badge .= '"></i> ' . $escape($row['status'] ?? 'Activo') . '</span>';
        $acciones = '<div class="btn-group btn-group-sm">' .
            '<button class="btn btn-outline-secondary btn-sm view_student" type="button" data-id="' . (int)$row['id'] . '" data-toggle="tooltip" title="Ver estudiante"><i class="fa fa-eye"></i></button>';
        if ($can_manage_students) {
            $acciones .= '<button class="btn btn-outline-primary btn-sm edit_student" type="button" data-id="' . (int)$row['id'] . '" data-toggle="tooltip" title="Editar estudiante"><i class="fa fa-edit"></i></button>' .
                '<button class="btn btn-outline-danger btn-sm delete_student" type="button" data-id="' . (int)$row['id'] . '" data-name="' . $escape($row['name']) . '" data-dni="' . $escape($row['id_no']) . '" data-toggle="tooltip" title="Eliminar estudiante"><i class="fa fa-trash-alt"></i></button>';
        }
        $acciones .= '</div>';
        // Badge de género
        $genero_val = $row['genero'] ?? '';
        if ($genero_val === 'Masculino') {
            $genero_badge = '<span class="badge" style="background-color:#cce5ff; color:#004085; padding:5px 10px; font-weight:500;"><i class="fas fa-mars mr-1"></i>Masculino</span>';
        } elseif ($genero_val === 'Femenino') {
            $genero_badge = '<span class="badge" style="background-color:#f8d7da; color:#721c24; padding:5px 10px; font-weight:500;"><i class="fas fa-venus mr-1"></i>Femenino</span>';
        } elseif ($genero_val === 'Otro') {
            $genero_badge = '<span class="badge" style="background-color:#e2e3e5; color:#383d41; padding:5px 10px; font-weight:500;"><i class="fas fa-transgender mr-1"></i>Otro</span>';
        } else {
            $genero_badge = '<span class="text-muted">—</span>';
        }
        $data[] = [
            '<div class="text-center"><input type="checkbox" class="select-student" value="' . (int)$row['id'] . '" title="Seleccionar estudiante"><span class="ml-2">' . ($i++) . '</span></div>',
            $escape($row['id_no']),
            $escape(ucwords($row['name'])),
            $genero_badge,
            $contacto,
            $nivel_badge,
            $grado_badge,
            $seccion_badge,
            $status_badge,
            $acciones
        ];
    }
}

// Queries DISTINCT para filtros en todos los requests para que sea verdaderamente dinámico (recalculándose para el nivel/estado actual)
$grados = [];
$secciones = [];

$distinctWhere = "WHERE school_id = $school_id";
if ($nivel !== 'all') $distinctWhere .= " AND nivel = '" . $conn->real_escape_string($nivel) . "'";
if ($status !== 'all') $distinctWhere .= " AND status = '" . $conn->real_escape_string($status) . "'";

$dg = $conn->query("SELECT DISTINCT grado FROM student $distinctWhere AND grado IS NOT NULL AND grado <> '' ORDER BY grado");
if ($dg) while ($r = $dg->fetch_assoc()) { $grados[] = $r['grado']; }

$ds = $conn->query("SELECT DISTINCT seccion FROM student $distinctWhere AND seccion IS NOT NULL AND seccion <> '' ORDER BY seccion");
if ($ds) while ($r = $ds->fetch_assoc()) { $secciones[] = $r['seccion']; }

if (ob_get_length()) ob_end_clean();

header('Content-Type: application/json');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $totalRecords,
    'recordsFiltered' => $filteredRecords,
    'data' => $data,
    'meta' => [
        'grados' => $grados,
        'secciones' => $secciones
    ]
]);
