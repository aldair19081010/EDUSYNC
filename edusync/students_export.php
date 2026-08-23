
<?php
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
// Exportar estudiantes filtrados a CSV, conservando filtros y seguridad
include_once 'db_connect.php';

// Requerir sesión
if (!isset($_SESSION['login_id'])) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$user_id = (int)$_SESSION['login_id'];
$role_query = $conn->prepare('SELECT type, is_director FROM users WHERE id = ? LIMIT 1');
if (!$role_query) {
    http_response_code(500);
    exit('No se pudo validar el usuario.');
}
$role_query->bind_param('i', $user_id);
$role_query->execute();
$role = $role_query->get_result()->fetch_assoc();
$role_query->close();
if (!$role || ((int)$role['type'] !== 1 && (int)$role['is_director'] !== 1)) {
    http_response_code(403);
    exit('No tienes permisos para exportar estudiantes.');
}

$school_id = isset($_SESSION['login_school_id']) ? (int) $_SESSION['login_school_id'] : 0;
if ($school_id <= 0) {
    http_response_code(403);
    exit('Colegio no válido.');
}
// Entradas de filtro
$nivel   = isset($_GET['nivel'])   ? trim($_GET['nivel'])   : 'all';
$status  = isset($_GET['status'])  ? trim($_GET['status'])  : 'all';
$grado   = isset($_GET['grado'])   ? trim($_GET['grado'])   : '';
$seccion = isset($_GET['seccion']) ? trim($_GET['seccion']) : '';
$search  = isset($_GET['search'])  ? trim($_GET['search'])  : '';
$selected_ids = isset($_GET['selected_ids']) ? array_values(array_filter(array_map('intval', explode(',', $_GET['selected_ids'])), static function ($id) { return $id > 0; })) : [];

// Construir cláusulas y parámetros
$clauses = [];
$params  = [];
$types   = '';

if ($school_id > 0) {
    $clauses[] = 's.school_id = ?';
    $params[]  = $school_id;
    $types    .= 'i';
}
if (!empty($selected_ids)) {
    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
    $clauses[] = 's.id IN (' . $placeholders . ')';
    foreach ($selected_ids as $selected_id) {
        $params[] = $selected_id;
        $types .= 'i';
    }
}
if ($nivel !== 'all' && $nivel !== '') {
    $clauses[] = 's.nivel = ?';
    $params[]  = $nivel;
    $types    .= 's';
}
if ($status !== 'all' && $status !== '') {
    $clauses[] = 's.status = ?';
    $params[]  = $status;
    $types    .= 's';
}
if ($grado !== '') {
    $clauses[] = 's.grado = ?';
    $params[]  = $grado;
    $types    .= 's';
}
if ($seccion !== '') {
    $clauses[] = 's.seccion = ?';
    $params[]  = $seccion;
    $types    .= 's';
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $clauses[] = '(
        s.name LIKE ? OR s.id_no LIKE ? OR s.nivel LIKE ? OR s.grado LIKE ? OR s.seccion LIKE ? OR s.status LIKE ?
        OR s.email LIKE ? OR s.contact LIKE ? OR s.tutor1_nombre LIKE ? OR s.tutor1_apellido LIKE ?
    )';
    // 10 placeholders
    for ($i = 0; $i < 10; $i++) {
        $params[] = $like;
        $types   .= 's';
    }
}

$sql = "SELECT s.id_no, s.name, s.genero, s.email, s.contact, s.address, s.nivel, s.grado, s.seccion, s.status,
           ay.year AS academic_year,
           s.tutor1_nombre, s.tutor1_apellido, s.tutor1_dni, s.tutor1_telefono, s.tutor1_relacion, s.tutor1_direccion,
           s.tutor2_nombre, s.tutor2_apellido, s.tutor2_dni, s.tutor2_telefono, s.tutor2_relacion, s.tutor2_direccion
    FROM student s LEFT JOIN academic_year ay ON ay.id = s.academic_year_id";

if (!empty($clauses)) {
    $sql .= ' WHERE ' . implode(' AND ', $clauses);
}
$sql .= ' ORDER BY name ASC';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Error al preparar la consulta.');
}

if (!empty($params)) {
    // Vincular parámetros dinámicamente
    $bind_params = [];
    $bind_params[] = $types;
    foreach ($params as $k => $v) {
        $bind_params[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_params);
}

$stmt->execute();
$result = $stmt->get_result();

// Preparar headers CSV
$filename = 'estudiantes_filtrados_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=Windows-1252');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
$csv_value = static function ($value) {
    $value = (string)($value ?? '');
    $value = preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    $converted = function_exists('mb_convert_encoding')
        ? mb_convert_encoding($value, 'Windows-1252', 'UTF-8')
        : iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
    return $converted === false ? $value : $converted;
};

// Encabezados
fputcsv($out, array_map($csv_value, [
    'DNI', 'Nombre', 'Genero', 'Email', 'Telefono', 'Direccion', 'Nivel', 'Grado', 'Seccion', 'Estado', 'Año academico',
    'Tutor1_Nombre', 'Tutor1_Apellido', 'Tutor1_DNI', 'Tutor1_Telefono', 'Tutor1_Relacion', 'Tutor1_Direccion',
    'Tutor2_Nombre', 'Tutor2_Apellido', 'Tutor2_DNI', 'Tutor2_Telefono', 'Tutor2_Relacion', 'Tutor2_Direccion'
]), ',');

if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            $csv_value($row['id_no']), $csv_value($row['name']), $csv_value($row['genero']), $csv_value($row['email']), $csv_value($row['contact']), $csv_value($row['address']),
            $csv_value($row['nivel']), $csv_value($row['grado']), $csv_value($row['seccion']), $csv_value($row['status']), $csv_value($row['academic_year']),
            $csv_value($row['tutor1_nombre']), $csv_value($row['tutor1_apellido']), $csv_value($row['tutor1_dni']), $csv_value($row['tutor1_telefono']), $csv_value($row['tutor1_relacion']), $csv_value($row['tutor1_direccion']),
            $csv_value($row['tutor2_nombre']), $csv_value($row['tutor2_apellido']), $csv_value($row['tutor2_dni']), $csv_value($row['tutor2_telefono']), $csv_value($row['tutor2_relacion']), $csv_value($row['tutor2_direccion'])
        ]);
    }
}

fclose($out);
exit;
?>
