<?php
// session_config.php
// Configuración básica de sesión para PHP

// Iniciar la sesión primero si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    // Asegurar que la carpeta tmp exista y sea escribible (coincide con login.php y ajax.php)
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');

    // Unificar nombre y parámetros de cookie de sesión
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Si se pasó sid por GET/POST (uni_modal lo añade), usarlo para reanudar la sesión
    if (empty($_COOKIE[session_name()]) && (isset($_REQUEST['sid']) && preg_match('/^[a-zA-Z0-9,-]+$/', $_REQUEST['sid']))) {
        session_id($_REQUEST['sid']);
    }

    session_start();
}

// Nota: aquí podrías añadir comprobaciones adicionales (por ejemplo, validación de usuario)
// if (!isset($_SESSION['login_id'])) { header('HTTP/1.1 401 Unauthorized'); exit(); }

