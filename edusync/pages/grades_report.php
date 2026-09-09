<?php
// Adaptador del Reporte de notas para conservar el acceso institucional del Director.
// Un Director puede ser una cuenta administrativa actual o una cuenta heredada marcada
// con is_director=1. Solo dentro de este reporte se le trata como acceso institucional.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp');
    if (!is_dir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp')) @mkdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp', 0755, true);
    session_name('EDUSYNCSESSID');
    session_set_cookie_params(['path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    session_start();
}

$__gr_original_type = $_SESSION['login_type'] ?? null;
$__gr_is_director = ((int)($_SESSION['login_is_director'] ?? 0) === 1);
$__gr_override = $__gr_is_director && (int)$__gr_original_type !== 1;

if ($__gr_override) $_SESSION['login_type'] = 1;
register_shutdown_function(static function() use ($__gr_override, $__gr_original_type) {
    if ($__gr_override && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['login_type'] = $__gr_original_type;
    }
});
?>
<script>window.EDUSYNC_GRADE_REPORT_DIRECTOR = <?php echo $__gr_is_director ? 'true' : 'false'; ?>;</script>
<?php
require __DIR__ . '/grades_report_core.php';
if ($__gr_override) $_SESSION['login_type'] = $__gr_original_type;
