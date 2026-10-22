<?php
/**
 * Revisión estática del CORS de pruebas Flutter Web.
 * php tools/test_flutter_web_cors_contract.php
 * Verificar la respuesta HTTP real desde PowerShell con curl.exe OPTIONS.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$rules = [
    'edusync/auth_login.php' => "require_once __DIR__ . '/includes/dev_cors.php';",
    'edusync/session_config.php' => "require_once __DIR__ . '/includes/dev_cors.php';",
    'edusync/includes/session_check.php' => "require_once __DIR__ . '/dev_cors.php';",
    'edusync/api/teacher_courses_mobile.php' => "require_once __DIR__ . '/../includes/dev_cors.php';",
    'edusync/api/teacher_gradebook_mobile.php' => "require_once __DIR__ . '/../includes/dev_cors.php';",
    'edusync/api/teacher_attendance_mobile.php' => "require_once __DIR__ . '/../includes/dev_cors.php';",
    'edusync/api/director_academic_mobile.php' => "require_once __DIR__ . '/../includes/dev_cors.php';",
];
$errors = [];
foreach ($rules as $file => $required) {
    $path = $root . '/' . $file;
    $text = is_file($path) ? (string)file_get_contents($path) : '';
    // En Windows git puede transformar saltos de línea a CRLF.
    $text = str_replace("\r\n", "\n", $text);
    if (!str_starts_with($text, "<?php\n" . $required)) {
        $errors[] = "$file no ejecuta CORS antes de iniciar sesión o validar peticiones.";
    }
}
$corsPath = $root . '/edusync/includes/dev_cors.php';
$cors = is_file($corsPath) ? (string)file_get_contents($corsPath) : '';
foreach ([
    'HTTP_ORIGIN', 'localhost', '127\\.0\\.0\\.1', 'SERVER_ADDR',
    'HTTP_HOST', 'FILTER_FLAG_NO_PRIV_RANGE', 'Access-Control-Allow-Origin',
    'Access-Control-Allow-Methods: GET, POST, OPTIONS',
    'Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Session-Id',
    'http_response_code(204)', 'Vary: Origin',
] as $needle) {
    if (!str_contains($cors, $needle)) $errors[] = "No se encontró en dev_cors.php: $needle";
}
if (str_contains($cors, 'Access-Control-Allow-Origin: *') ||
    str_contains($cors, 'Access-Control-Allow-Credentials: true')) {
    $errors[] = 'El CORS de desarrollo no debe autorizar orígenes irrestrictos ni cookies compartidas.';
}
if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, "[ERROR] $error\n");
    exit(1);
}
echo "Contrato CORS de Flutter Web verificado (estático).\n";
echo "Pendiente: php -l y preflight HTTP real contra la IP de XAMPP.\n";
