<?php
ini_set('session.save_path', __DIR__ . '/tmp');
if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');
include 'db_connect.php';

function auth_reply($status, $message, $extra = []) {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$school_id = intval($_POST['school_id'] ?? 0);
if ($username === '' || $password === '' || $school_id <= 0) {
    auth_reply(0, 'Completa usuario, contraseña y colegio.');
}

$status_col = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$has_status = $status_col && $status_col->num_rows > 0;
$status_select = $has_status ? 'u.status AS user_status' : "'Activo' AS user_status";

$sql = "SELECT u.*, {$status_select}, t.id AS linked_teacher_id, t.status AS teacher_status
        FROM users u
        LEFT JOIN teacher t ON t.id = u.teacher_id AND t.school_id = u.school_id
        WHERE u.username = ? AND u.school_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) auth_reply(0, 'No se pudo preparar el inicio de sesión.');
$stmt->bind_param('si', $username, $school_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) auth_reply(0, 'Usuario, contraseña o colegio incorrectos.');

if (($row['user_status'] ?? 'Activo') !== 'Activo') {
    auth_reply(0, 'Este usuario está inactivo. Comunícate con el administrador.');
}

$stored = (string)($row['password'] ?? '');
$password_ok = false;
$legacy_md5 = false;
if ($stored !== '' && password_verify($password, $stored)) {
    $password_ok = true;
} elseif (preg_match('/^[a-f0-9]{32}$/i', $stored) && hash_equals(strtolower($stored), md5($password))) {
    $password_ok = true;
    $legacy_md5 = true;
}
if (!$password_ok) auth_reply(0, 'Usuario, contraseña o colegio incorrectos.');

// Las cuentas de apoderado ya se preparan desde Administración, pero el portal
// familiar se habilitará en la siguiente etapa. Evitar que este rol caiga en el
// panel general antes de que existan sus vistas y permisos específicos.
if ((int)$row['type'] === 5) {
    auth_reply(0, 'Tu cuenta de apoderado ya está registrada. El portal de familias aún no está habilitado.');
}

if ((int)$row['type'] === 2) {
    if (empty($row['teacher_id']) || empty($row['linked_teacher_id'])) {
        auth_reply(0, 'Este usuario docente ya no está vinculado a un docente de la institución.');
    }
    if (($row['teacher_status'] ?? 'Inactivo') !== 'Activo') {
        auth_reply(0, 'Este docente está inactivo y no puede iniciar sesión.');
    }
}

// Migración automática: cuando un usuario con contraseña MD5 inicia sesión correctamente,
// se reemplaza por password_hash sin obligar a cambiar todas las claves de golpe.
if ($legacy_md5) {
    $new_hash = password_hash($password, PASSWORD_DEFAULT);
    $up = $conn->prepare('UPDATE users SET password = ? WHERE id = ? AND school_id = ?');
    if ($up) {
        $uid = intval($row['id']);
        $up->bind_param('sii', $new_hash, $uid, $school_id);
        $up->execute();
        $up->close();
    }
}

foreach ($row as $key => $value) {
    if (!in_array($key, ['password', 'linked_teacher_id', 'teacher_status', 'user_status'], true) && !is_numeric($key)) {
        $_SESSION['login_' . $key] = $value;
    }
}
$_SESSION['login_id'] = intval($row['id']);
$_SESSION['login_school_id'] = intval($row['school_id']);
$_SESSION['user_name'] = $row['name'];
if ((int)$row['type'] === 2 && !empty($row['teacher_id'])) {
    $_SESSION['login_teacher_id'] = intval($row['teacher_id']);
}
if (!empty($row['avatar'])) $_SESSION['login_avatar'] = $row['avatar'];

$session_id_value = session_id();
session_write_close();
auth_reply(1, 'Inicio de sesión exitoso.', [
    'login_type' => intval($row['type']),
    'login_id' => intval($row['id']),
    'session_id' => $session_id_value
]);
?>