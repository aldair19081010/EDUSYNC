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
// Si la sesión PHP expiró mientras la página estaba abierta, recuperar
// primero la identidad recordada para desactivar también sus avisos Push.
require_once __DIR__.'/includes/remember_session.php';
if (!edrs_has_session() && isset($_COOKIE[EDRS_COOKIE])) {
    require_once __DIR__.'/db_connect.php';
    if (isset($conn) && $conn instanceof mysqli) edrs_restore($conn);
}
// Desactivar sólo la suscripción del navegador desde el que se cierra sesión.
// Así no llegan avisos privados a otra persona que utilice el mismo equipo,
// y se mantienen activas las notificaciones en los otros dispositivos.
$browser = trim((string)($_COOKIE['edusync_web_push_browser'] ?? ''));
if (preg_match('/^[a-zA-Z0-9._:-]{20,100}$/D', $browser)) {
    $student = !empty($_SESSION['student_logged_in']);
    $school = (int)($_SESSION[$student ? 'student_school_id' : 'login_school_id'] ?? 0);
    $account = (int)($_SESSION[$student ? 'student_id' : 'login_id'] ?? 0);
    $kind = $student ? 'student' : 'staff';
    if ($school > 0 && $account > 0) {
        try {
            require_once __DIR__ . '/db_connect.php';
            if (isset($conn) && $conn instanceof mysqli) {
                $present = $conn->query("SHOW TABLES LIKE 'web_push_subscriptions'");
                if ($present && $present->num_rows) {
                    $query = $conn->prepare(
                        'UPDATE web_push_subscriptions SET is_active=0 WHERE browser_id=? AND school_id=? AND account_kind=? AND account_id=?'
                    );
                    if ($query) {
                        $query->bind_param('sisi', $browser, $school, $kind, $account);
                        $query->execute();
                        $query->close();
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[EduSync web push logout] ' . $e->getMessage());
            // No bloquear el cierre de sesión si MySQL está sin conexión.
        }
    }
}
// Revocar el acceso recordado de este navegador al cerrar sesión.
require_once __DIR__.'/includes/remember_session.php';
if (isset($_COOKIE[EDRS_COOKIE])) {
    if (!isset($conn) || !($conn instanceof mysqli)) require_once __DIR__.'/db_connect.php';
    if (isset($conn) && $conn instanceof mysqli) edrs_revoke($conn);
    else edrs_clear_cookie();
}
// Cerrar sesión de forma segura
session_unset();
session_destroy();
setcookie(session_name(), '', time() - 42000, '/');
header("Location: login.php");
exit();
?>
