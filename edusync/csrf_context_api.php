<?php
ob_start();
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['login_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => 'La sesión ha expirado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

echo json_encode([
    'status' => 1,
    'csrf_token' => (string)$_SESSION['csrf_token']
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
