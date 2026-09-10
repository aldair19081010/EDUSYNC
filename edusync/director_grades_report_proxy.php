<?php
// Proxy de solo lectura para que Director consulte Reporte de notas con alcance institucional
// sin convertir su sesión en Administrador para el resto del sistema.
if (session_status() == PHP_SESSION_NONE) {
    $session_save_path = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($session_save_path)) {
        @mkdir($session_save_path, 0755, true);
    }
    ini_set('session.save_path', $session_save_path);
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/db_connect.php';

$school_id = (int)($_SESSION['login_school_id'] ?? 0);
$user_id = (int)($_SESSION['login_id'] ?? 0);
$original_type = (int)($_SESSION['login_type'] ?? 0);

if ($school_id <= 0 || $user_id <= 0) {
    http_response_code(401);
    exit('Sesión no válida.');
}

$stmt = $conn->prepare('SELECT type, is_director FROM users WHERE id = ? AND school_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    exit('No se pudo validar el acceso al reporte.');
}
$stmt->bind_param('ii', $user_id, $school_id);
$stmt->execute();
$role = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$role || (int)$role['is_director'] !== 1) {
    http_response_code(403);
    exit('No tiene permisos para consultar este reporte como Director.');
}

$route = $_GET['route'] ?? '';
$allowed_ajax_actions = [
    'get_grados_by_nivel',
    'get_secciones_by_grado_nivel',
    'get_courses_by_aula',
    'get_levels_by_academic_year',
    'get_students_by_grado_seccion',
    'get_evaluations_by_filters'
];

$restored = false;
$restore_role = static function() use (&$restored, $original_type) {
    if ($restored) return;
    $_SESSION['login_type'] = $original_type;
    $restored = true;
};
register_shutdown_function($restore_role);

// Elevar únicamente dentro de esta petición de lectura del reporte.
$_SESSION['login_type'] = 1;

if ($route === 'ajax') {
    $action = $_GET['action'] ?? '';
    if (!in_array($action, $allowed_ajax_actions, true)) {
        $restore_role();
        http_response_code(403);
        exit('Acción no permitida en el Reporte de notas.');
    }
    $_GET['action'] = $action;
    require __DIR__ . '/ajax.php';
    $restore_role();
    exit;
}

if ($route === 'table') {
    require __DIR__ . '/grades_report_table.php';
    $restore_role();
    exit;
}

if ($route === 'views') {
    require __DIR__ . '/grades_report_views.php';
    $restore_role();
    exit;
}

$restore_role();
http_response_code(400);
exit('Ruta de reporte inválida.');
