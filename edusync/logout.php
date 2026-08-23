<?php
// Unificar ruta y cookie de sesiones antes de iniciar/destrozar
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
// Cerrar sesión de forma segura
session_unset();
session_destroy();
setcookie(session_name(), '', time() - 42000, '/');
header("Location: login.php");
exit();
?>
