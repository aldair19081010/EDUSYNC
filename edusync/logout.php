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
// En un cierre de sesión explícito se desactiva el dispositivo actual, no
// todos los teléfonos/navegadores que usan esta misma cuenta.
// Preferir el identificador HttpOnly ya registrado por la API, pero aceptar
// el respaldo local enviado por el formulario sólo con CSRF válido.
$browserIds=[];
$cookieBrowser=trim((string)($_COOKIE['edusync_web_push_browser'] ?? ''));
if(preg_match('/^[a-zA-Z0-9._:-]{20,100}$/D',$cookieBrowser)) {
    $browserIds[]=$cookieBrowser;
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    $expected=(string)($_SESSION['csrf_token']??'');
    $provided=(string)($_POST['logout_csrf_token']??'');
    $postBrowser=trim((string)($_POST['push_browser_id']??''));
    if($expected!=='' && $provided!=='' && hash_equals($expected,$provided)
        && preg_match('/^[a-zA-Z0-9._:-]{20,100}$/D',$postBrowser)) {
        $browserIds[]=$postBrowser;
    }
}
$browserIds=array_values(array_unique($browserIds));
$student=!empty($_SESSION['student_logged_in']);
$school=(int)($_SESSION[$student?'student_school_id':'login_school_id']??0);
$account=(int)($_SESSION[$student?'student_id':'login_id']??0);
$kind=$student?'student':'staff';
if($browserIds && $school>0 && $account>0) {
    try {
        if(!isset($conn)||!($conn instanceof mysqli))require_once __DIR__.'/db_connect.php';
        if(isset($conn)&&$conn instanceof mysqli) {
            $present=$conn->query("SHOW TABLES LIKE 'web_push_subscriptions'");
            if($present&&$present->num_rows) {
                $query=$conn->prepare(
                    'UPDATE web_push_subscriptions SET is_active=0 WHERE browser_id=? AND school_id=? AND account_kind=? AND account_id=?'
                );
                if(!$query)throw new RuntimeException('No se pudo preparar la baja Web Push.');
                foreach($browserIds as $browser) {
                    $query->bind_param('sisi',$browser,$school,$kind,$account);
                    if(!$query->execute())throw new RuntimeException('No se pudo aplicar la baja Web Push.');
                }
                $query->close();
            }
        }
    } catch(Throwable $e) {
        error_log('[EduSync web push logout] '.$e->getMessage());
        // Una caída puntual de BD no debe impedir finalizar la sesión.
    }
}
// El identificador sólo se vuelve a guardar después de reactivar avisos.
// No borrar la preferencia global del navegador para otros sitios.
setcookie('edusync_web_push_browser','',[
    'expires'=>time()-3600,'path'=>'/',
    'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off',
    'httponly'=>true,'samesite'=>'Lax'
]);
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
