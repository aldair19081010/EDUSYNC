<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ob_start();
include_once __DIR__ . '/../session_config.php';
include __DIR__ . '/../db_connect.php';
if (ob_get_length()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function student_password_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function student_password_column_exists(mysqli $db, string $column): bool {
    $safe = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM student LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    student_password_reply([
        'status' => 'error',
        'message' => 'Método no permitido.'
    ], 405);
}

if (empty($_SESSION['student_logged_in'])
    || empty($_SESSION['student_id'])
    || empty($_SESSION['student_school_id'])) {
    student_password_reply([
        'status' => 'error',
        'message' => 'Tu sesión no es válida. Inicia sesión nuevamente.'
    ], 401);
}

// La app reanuda la sesión mediante sid. En la web, donde se usa cookie,
// exigimos CSRF para impedir cambios iniciados desde otro sitio.
$explicitSid = trim((string)($_POST['sid'] ?? ''));
if ($explicitSid === '') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $csrf === '' || !hash_equals($expected, $csrf)) {
        student_password_reply([
            'status' => 'error',
            'message' => 'La sesión de seguridad expiró. Vuelve a abrir tu perfil.'
        ], 403);
    }
}

if (!student_password_column_exists($conn, 'portal_password_hash')
    || !student_password_column_exists($conn, 'password_changed_at')) {
    student_password_reply([
        'status' => 'error',
        'migration_required' => true,
        'message' => 'Falta ejecutar sql/student_password_upgrade.sql.'
    ], 409);
}

$studentId = (int)$_SESSION['student_id'];
$schoolId = (int)$_SESSION['student_school_id'];
$currentPassword = (string)($_POST['current_password'] ?? '');
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    student_password_reply([
        'status' => 'error',
        'message' => 'Completa los tres campos de contraseña.'
    ], 422);
}

if (!hash_equals($newPassword, $confirmPassword)) {
    student_password_reply([
        'status' => 'error',
        'message' => 'La nueva contraseña y su confirmación no coinciden.'
    ], 422);
}

$length = function_exists('mb_strlen')
    ? mb_strlen($newPassword, 'UTF-8')
    : strlen($newPassword);

if ($length < 8 || $length > 64) {
    student_password_reply([
        'status' => 'error',
        'message' => 'La nueva contraseña debe tener entre 8 y 64 caracteres.'
    ], 422);
}

if (!preg_match('/\p{L}/u', $newPassword) || !preg_match('/\d/u', $newPassword)) {
    student_password_reply([
        'status' => 'error',
        'message' => 'La nueva contraseña debe incluir al menos una letra y un número.'
    ], 422);
}

$stmt = $conn->prepare(
    "SELECT id_no, portal_password_hash
     FROM student
     WHERE id = ? AND school_id = ?
     LIMIT 1"
);
if (!$stmt) {
    student_password_reply([
        'status' => 'error',
        'message' => 'No se pudo validar la cuenta del estudiante.'
    ], 500);
}

$stmt->bind_param('ii', $studentId, $schoolId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    student_password_reply([
        'status' => 'error',
        'message' => 'La cuenta del estudiante ya no está disponible.'
    ], 404);
}

$dni = trim((string)($student['id_no'] ?? ''));
$storedHash = trim((string)($student['portal_password_hash'] ?? ''));

$currentOk = $storedHash !== ''
    ? password_verify($currentPassword, $storedHash)
    : ($dni !== '' && hash_equals($dni, $currentPassword));

if (!$currentOk) {
    student_password_reply([
        'status' => 'error',
        'message' => 'La contraseña actual es incorrecta.'
    ], 422);
}

if ($dni !== '' && hash_equals($dni, $newPassword)) {
    student_password_reply([
        'status' => 'error',
        'message' => 'La nueva contraseña no puede ser igual a tu DNI o código.'
    ], 422);
}

if ($storedHash !== '' && password_verify($newPassword, $storedHash)) {
    student_password_reply([
        'status' => 'error',
        'message' => 'La nueva contraseña debe ser diferente de la actual.'
    ], 422);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
if (!$newHash) {
    student_password_reply([
        'status' => 'error',
        'message' => 'No se pudo proteger la nueva contraseña.'
    ], 500);
}

$update = $conn->prepare(
    'UPDATE student
     SET portal_password_hash = ?, password_changed_at = NOW()
     WHERE id = ? AND school_id = ?'
);
if (!$update) {
    student_password_reply([
        'status' => 'error',
        'message' => 'No se pudo preparar la actualización de contraseña.'
    ], 500);
}

$update->bind_param('sii', $newHash, $studentId, $schoolId);
$ok = $update->execute();
$update->close();

if (!$ok) {
    student_password_reply([
        'status' => 'error',
        'message' => 'No se pudo actualizar la contraseña.'
    ], 500);
}

student_password_reply([
    'status' => 'ok',
    'message' => 'Contraseña actualizada correctamente.',
    'password_changed_at' => date('Y-m-d H:i:s')
]);
