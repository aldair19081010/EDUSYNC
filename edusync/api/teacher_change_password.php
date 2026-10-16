<?php
/** Cambio voluntario de contraseña para docentes autenticados. */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../db_connect.php';

function teacher_password_result(int $httpStatus, string $status, string $message): void {
    http_response_code($httpStatus);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    teacher_password_result(405, 'error', 'Método no permitido.');
}
$userId = (int) ($_SESSION['login_id'] ?? 0);
$schoolId = (int) ($_SESSION['login_school_id'] ?? 0);
$role = (int) ($_SESSION['login_type'] ?? 0);
if ($userId <= 0 || $schoolId <= 0 || $role !== 2) {
    teacher_password_result(401, 'error', 'Tu sesión docente expiró. Vuelve a ingresar.');
}

// Para la app se autentica el SID existente y se exige solicitud XHR.
// Para web por cookie se exige el token CSRF de sesión.
$providedSid = trim((string) ($_POST['sid'] ?? ''));
if ($providedSid !== '') {
    if (!hash_equals(session_id(), $providedSid)
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        teacher_password_result(403, 'error', 'La sesión de seguridad no es válida.');
    }
} else {
    $sentCsrf = (string) ($_POST['csrf_token'] ?? '');
    $validCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    if ($sentCsrf === '' || $validCsrf === '' || !hash_equals($validCsrf, $sentCsrf)) {
        teacher_password_result(403, 'error', 'La sesión de seguridad expiró.');
    }
}
$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirm === '') {
    teacher_password_result(422, 'error', 'Completa todos los campos.');
}
if (!hash_equals($newPassword, $confirm)) {
    teacher_password_result(422, 'error', 'La nueva contraseña y su confirmación no coinciden.');
}
$length = function_exists('mb_strlen') ? mb_strlen($newPassword, 'UTF-8') : strlen($newPassword);
if ($length < 8 || $length > 64 || !preg_match('/\\p{L}/u', $newPassword) || !preg_match('/\\d/u', $newPassword)) {
    teacher_password_result(422, 'error', 'La nueva contraseña debe tener entre 8 y 64 caracteres, con letras y números.');
}
try {
    $stmt = $conn->prepare('SELECT u.password, u.type, u.teacher_id, t.status AS teacher_status FROM users u LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id WHERE u.id=? AND u.school_id=? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar la cuenta.');
    }
    $stmt->bind_param('ii', $userId, $schoolId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || (int) $user['type'] !== 2 || (int) $user['teacher_id'] <= 0 || $user['teacher_status'] !== 'Activo') {
        teacher_password_result(403, 'error', 'La cuenta docente no está activa.');
    }
    $storedHash = (string) $user['password'];
    $verified = $storedHash !== '' && (
        password_verify($currentPassword, $storedHash)
        || (preg_match('/^[a-f0-9]{32}$/i', $storedHash) && hash_equals(strtolower($storedHash), md5($currentPassword)))
    );
    if (!$verified) {
        teacher_password_result(422, 'error', 'La contraseña actual es incorrecta.');
    }
    if (hash_equals($currentPassword, $newPassword)) {
        teacher_password_result(422, 'error', 'La nueva contraseña debe ser diferente de la actual.');
    }
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!$passwordHash) {
        throw new RuntimeException('Error al proteger la contraseña.');
    }
    $update = $conn->prepare('UPDATE users SET password=? WHERE id=? AND school_id=? AND type=2');
    if (!$update) {
        throw new RuntimeException('No se pudo actualizar la cuenta.');
    }
    $update->bind_param('sii', $passwordHash, $userId, $schoolId);
    $ok = $update->execute();
    $update->close();
    if (!$ok) {
        throw new RuntimeException('Falló la actualización.');
    }
    teacher_password_result(200, 'ok', 'Contraseña actualizada correctamente.');
} catch (Throwable $error) {
    error_log('[EduSync teacher password] ' . $error->getMessage());
    teacher_password_result(500, 'error', 'No se pudo actualizar la contraseña. Inténtalo nuevamente.');
}
