<?php
// Common session initializer and login guard for modal pages
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/../tmp');
    if (!is_dir(__DIR__ . '/../tmp')) @mkdir(__DIR__ . '/../tmp');
    session_name('EDUSYNCSESSID');
    // Allow resuming session by explicit sid for XHR/modal loads if provided (sanitized)
    $sid = null;
    if (!empty($_REQUEST['sid'])) {
        $sid = preg_replace('/[^\w\-]/', '', $_REQUEST['sid']);
    } elseif (!empty($_SERVER['HTTP_X_SESSION_ID'])) {
        $sid = preg_replace('/[^\w\-]/', '', $_SERVER['HTTP_X_SESSION_ID']);
    }
    if ($sid) {
        // Only allow when request appears to be an XHR (modal) to limit exposure
        $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($is_xhr) {
            session_id($sid);
        }
    }
    session_set_cookie_params(["path" => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// Recuperación opcional antes de rechazar modales por expiración PHP.
require_once __DIR__.'/remember_session.php';
if (!edrs_has_session() && isset($_COOKIE[EDRS_COOKIE])) {
    if (!isset($conn) || !($conn instanceof mysqli)) require_once __DIR__.'/../db_connect.php';
    if (isset($conn) && $conn instanceof mysqli) edrs_restore($conn);
}

function require_login_modal() {
    // Consider admin/teacher/student logins
    // Require login if no authenticated identity is present. Accept either an admin/user id, or a login_type (e.g., teacher) or a student session.
    if (!isset($_SESSION['login_id']) && !isset($_SESSION['login_type']) && !isset($_SESSION['student_logged_in'])) {

        // If AJAX or XHR (modal loads via jQuery .load), return HTTP 401 with a short HTML
        $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($is_xhr) {
            header('HTTP/1.1 401 Unauthorized');
            header('Content-Type: text/html; charset=utf-8');
            echo '<div class="alert alert-danger">Sesión expirada. Por favor <a href="login.php">inicie sesión</a>.</div>';
            exit;
        } else {
            header('Location: login.php');
            exit;
        }
    }
}
