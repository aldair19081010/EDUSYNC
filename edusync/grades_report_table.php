<?php
// Adaptador de consulta: el Director conserva vista institucional completa.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . DIRECTORY_SEPARATOR . 'tmp');
    if (!is_dir(__DIR__ . DIRECTORY_SEPARATOR . 'tmp')) @mkdir(__DIR__ . DIRECTORY_SEPARATOR . 'tmp', 0755, true);
    session_name('EDUSYNCSESSID');
    session_set_cookie_params(['path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    session_start();
}

$__gr_original_type = $_SESSION['login_type'] ?? null;
$__gr_override = ((int)($_SESSION['login_is_director'] ?? 0) === 1) && (int)$__gr_original_type !== 1;
if ($__gr_override) $_SESSION['login_type'] = 1;
register_shutdown_function(static function() use ($__gr_override, $__gr_original_type) {
    if ($__gr_override && session_status() === PHP_SESSION_ACTIVE) $_SESSION['login_type'] = $__gr_original_type;
});

require __DIR__ . '/grades_report_table_core.php';
if ($__gr_override) $_SESSION['login_type'] = $__gr_original_type;
