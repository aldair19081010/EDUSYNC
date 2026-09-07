<?php
include_once 'includes/session_check.php';
require_login_modal();
include 'db_connect.php';

$school_id = intval($_SESSION['login_school_id'] ?? 0);
if ((int)($_SESSION['login_type'] ?? 0) !== 1) {
    http_response_code(403);
    exit('No tiene permiso para exportar docentes.');
}
$status = $_GET['status'] ?? 'Activo';
if (!in_array($status, ['Activo', 'Inactivo', 'all'], true)) $status = 'Activo';
$selected_ids = array_values(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? ''))));

$year_id = 0; $year_name = 'Sin año activo';
$stmt = $conn->prepare('SELECT id, year FROM academic_year WHERE school_id = ? AND is_active = 1 LIMIT 1');
$stmt->bind_param('i', $school_id); $stmt->execute();
$year = $stmt->get_result()->fetch_assoc(); $stmt->close();
if ($year) { $year_id = (int)$year['id']; $year_name = $year['year']; }

$birth_check = $conn->query("SHOW COLUMNS FROM teacher LIKE 'birth_date'");
$birth_select = ($birth_check && $birth_check->num_rows > 0) ? 't.birth_date' : 'NULL AS birth_date';
$ids_sql = $selected_ids ? ' AND t.id IN (' . implode(',', $selected_ids) . ')' : '';
$sql = "SELECT t.id_no, t.name, $birth_select, t.email, t.contact, t.address, t.specialty, t.status,
    (SELECT u.username FROM users u WHERE u.teacher_id=t.id AND u.school_id=t.school_id AND u.type=2 LIMIT 1) AS username,
    (SELECT COUNT(*) FROM teacher_courses tc WHERE tc.teacher_id=t.id AND tc.school_id=t.school_id AND tc.academic_year_id=?) AS course_count
    FROM teacher t WHERE t.school_id=? AND (?='all' OR t.status=?) $ids_sql ORDER BY t.name";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iiss', $year_id, $school_id, $status, $status); $stmt->execute();
$result = $stmt->get_result();

$filename = 'docentes_' . date('Y-m-d_H-i') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['DNI','Nombre','Fecha de nacimiento','Edad','Correo','Teléfono','Dirección','Especialidad','Estado','Usuario','Cursos '.$year_name], ',');
while ($row = $result->fetch_assoc()) {
    $age = '';
    if (!empty($row['birth_date'])) $age = (new DateTime($row['birth_date']))->diff(new DateTime('today'))->y;
    fputcsv($out, [$row['id_no'],$row['name'],$row['birth_date'] ? date('d/m/Y', strtotime($row['birth_date'])) : '',$age,$row['email'],$row['contact'],$row['address'],$row['specialty'],$row['status'],$row['username'] ?: 'Sin usuario',$row['course_count']], ',');
}
fclose($out);
$stmt->close();
exit;
