<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
ob_start();
ini_set('display_errors', 0);
include '../db_connect.php';

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

function school_logo_url(string $logoPath): string {
    $logoPath = trim($logoPath);
    if ($logoPath === '') return '';

    if (preg_match('~^https?://~i', $logoPath)) {
        return $logoPath;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return $logoPath;

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $appBase = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/.');
    $relative = ltrim($logoPath, '/');

    return $scheme . '://' . $host
        . ($appBase !== '' ? '/' . ltrim($appBase, '/') : '')
        . '/' . $relative;
}

$schools = [];
$q = $conn->query("SELECT * FROM schools ORDER BY name ASC");
while ($row = $q->fetch_assoc()) {
    $row['logo_url'] = school_logo_url((string)($row['logo_path'] ?? ''));
    $schools[] = $row;
}

echo json_encode(
    ['status' => 'ok', 'data' => $schools],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
?>
