<?php
ob_start();
include_once __DIR__ . '/session_config.php';
include __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

function gpa_response(array $data, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int)($_SESSION['login_id'] ?? 0);
$userType = (int)($_SESSION['login_type'] ?? 0);
$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
if ($userId <= 0 || $schoolId <= 0 || $userType !== 2) {
    gpa_response(['status'=>0,'message'=>'No tienes permisos para configurar el autoguardado de notas.'], 403);
}

$column = $conn->query("SHOW COLUMNS FROM users LIKE 'grades_autosave'");
if (!$column || !$column->num_rows) {
    gpa_response([
        'status'=>0,
        'migration_required'=>true,
        'message'=>'Ejecuta sql/grades_autosave_upgrade.sql para sincronizar esta preferencia con tu cuenta.'
    ], 409);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare('SELECT grades_autosave FROM users WHERE id=? AND school_id=? LIMIT 1');
    $stmt->bind_param('ii', $userId, $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) gpa_response(['status'=>0,'message'=>'No se encontró el usuario.'], 404);
    gpa_response(['status'=>1,'enabled'=>(int)$row['grades_autosave'] === 1]);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
$savedCsrf = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf === '' || $savedCsrf === '' || !hash_equals($savedCsrf, $csrf)) {
    gpa_response(['status'=>0,'message'=>'La sesión de seguridad venció. Recarga la página.'], 403);
}

$enabled = !empty($_POST['enabled']) ? 1 : 0;
$stmt = $conn->prepare('UPDATE users SET grades_autosave=? WHERE id=? AND school_id=?');
$stmt->bind_param('iii', $enabled, $userId, $schoolId);
if (!$stmt->execute()) {
    $stmt->close();
    gpa_response(['status'=>0,'message'=>'No se pudo guardar la preferencia de autoguardado.'], 500);
}
$stmt->close();
$_SESSION['login_grades_autosave'] = $enabled;
gpa_response([
    'status'=>1,
    'enabled'=>$enabled === 1,
    'message'=>$enabled ? 'Autoguardado de notas activado.' : 'Autoguardado de notas desactivado.'
]);
