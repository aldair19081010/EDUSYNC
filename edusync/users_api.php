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

function user_api_reply($status, $message, $extra = []) {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function user_api_column_exists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}

function user_api_table_exists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function user_api_audit($conn, $school_id, $target_user_id, $target_username, $action, $details = []) {
    if (!user_api_table_exists($conn, 'user_audit_log')) return;
    $actor_user_id = intval($_SESSION['login_id'] ?? 0) ?: null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare('INSERT INTO user_audit_log (school_id, target_user_id, target_username, actor_user_id, action, details, ip_address) VALUES (?, NULLIF(?,0), ?, NULLIF(?,0), ?, ?, ?)');
    if (!$stmt) return;
    $target_id = intval($target_user_id);
    $actor_id = intval($actor_user_id);
    $stmt->bind_param('iisisss', $school_id, $target_id, $target_username, $actor_id, $action, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

function load_target_user($conn, $id, $school_id) {
    $stmt = $conn->prepare('SELECT id, school_id, name, username, type, is_director, teacher_id, status FROM users WHERE id = ? AND school_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $school_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function count_active_admins($conn, $school_id, $exclude_id = 0) {
    $sql = "SELECT COUNT(*) AS total FROM users WHERE school_id = ? AND type = 1 AND status = 'Activo'";
    if ($exclude_id > 0) $sql .= ' AND id <> ?';
    $stmt = $conn->prepare($sql);
    if ($exclude_id > 0) $stmt->bind_param('ii', $school_id, $exclude_id);
    else $stmt->bind_param('i', $school_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0);
}

function load_teacher_for_user($conn, $teacher_id, $school_id) {
    if ($teacher_id <= 0) return null;
    $stmt = $conn->prepare('SELECT id, name, status FROM teacher WHERE id = ? AND school_id = ? LIMIT 1');
    $stmt->bind_param('ii', $teacher_id, $school_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function reject_guardian_account_from_users($target) {
    if ($target && intval($target['type'] ?? 0) === 5) {
        user_api_reply(0, 'Las cuentas de apoderado se administran exclusivamente desde el módulo Apoderados.');
    }
}

$login_id = intval($_SESSION['login_id'] ?? 0);
$school_id = intval($_SESSION['login_school_id'] ?? 0);
if (!$login_id || !$school_id) user_api_reply(0, 'Sesión no válida. Vuelve a iniciar sesión.');

if (!user_api_column_exists($conn, 'users', 'status') || !user_api_table_exists($conn, 'user_audit_log')) {
    user_api_reply(0, 'Falta actualizar la base de datos. Ejecuta sql/users_module_upgrade.sql.');
}

$role_stmt = $conn->prepare('SELECT type, status FROM users WHERE id = ? AND school_id = ? LIMIT 1');
$role_stmt->bind_param('ii', $login_id, $school_id);
$role_stmt->execute();
$role_row = $role_stmt->get_result()->fetch_assoc();
$role_stmt->close();
if (!$role_row || intval($role_row['type']) !== 1) user_api_reply(0, 'No tienes permisos para administrar usuarios.');
if (($role_row['status'] ?? 'Inactivo') !== 'Activo') user_api_reply(0, 'Tu cuenta está inactiva. Vuelve a iniciar sesión con una cuenta autorizada.');

$csrf = (string)($_POST['csrf_token'] ?? '');
$session_csrf = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf === '' || $session_csrf === '' || !hash_equals($session_csrf, $csrf)) {
    http_response_code(403);
    user_api_reply(0, 'La sesión de seguridad venció. Recarga la página.');
}

$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $id = intval($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $type = intval($_POST['type'] ?? 0);
    $is_director = ($type === 1 && !empty($_POST['is_director'])) ? 1 : 0;
    $teacher_id = ($type === 2) ? intval($_POST['teacher_id'] ?? 0) : 0;
    $status = (($_POST['status'] ?? 'Activo') === 'Inactivo') ? 'Inactivo' : 'Activo';

    if ($name === '' || $username === '') user_api_reply(0, 'Nombre y usuario son obligatorios.');
    if (!in_array($type, [1, 2, 3], true)) user_api_reply(0, 'Selecciona un rol válido.');
    if ($id === 0 && strlen($password) < 8) user_api_reply(0, 'La contraseña inicial debe tener al menos 8 caracteres.');
    if ($password !== '' && strlen($password) < 8) user_api_reply(0, 'La nueva contraseña debe tener al menos 8 caracteres.');

    $current = $id ? load_target_user($conn, $id, $school_id) : null;
    if ($id && !$current) user_api_reply(0, 'Usuario no encontrado.');
    reject_guardian_account_from_users($current);

    if ($id === $login_id) {
        if ($status !== 'Activo') user_api_reply(0, 'No puedes desactivar tu propia cuenta.');
        if ($type !== 1) user_api_reply(0, 'No puedes quitarte el rol de administrador desde tu propia sesión.');
    }

    if ($current && intval($current['type']) === 1 && $current['status'] === 'Activo') {
        $will_stop_being_active_admin = ($type !== 1 || $status !== 'Activo');
        if ($will_stop_being_active_admin && count_active_admins($conn, $school_id, $id) < 1) {
            user_api_reply(0, 'Debe quedar al menos un administrador activo en el colegio.');
        }
    }

    $dup = $conn->prepare('SELECT id FROM users WHERE school_id = ? AND username = ? AND id <> ? LIMIT 1');
    $dup->bind_param('isi', $school_id, $username, $id);
    $dup->execute();
    $dup_row = $dup->get_result()->fetch_assoc();
    $dup->close();
    if ($dup_row) user_api_reply(2, 'El nombre de usuario ya existe en este colegio.');

    if ($type === 2) {
        if ($teacher_id <= 0) user_api_reply(0, 'Selecciona el docente vinculado a esta cuenta.');
        $teacher = load_teacher_for_user($conn, $teacher_id, $school_id);
        if (!$teacher) user_api_reply(0, 'El docente seleccionado no pertenece a este colegio.');

        $same_existing_link = $current && intval($current['type']) === 2 && intval($current['teacher_id']) === $teacher_id;
        if (($teacher['status'] ?? 'Activo') !== 'Activo') {
            if (!$same_existing_link) user_api_reply(0, 'El docente seleccionado está inactivo.');
            if ($status === 'Activo') user_api_reply(0, 'La cuenta debe permanecer inactiva mientras el docente vinculado esté inactivo.');
        }

        $link = $conn->prepare('SELECT id FROM users WHERE school_id = ? AND teacher_id = ? AND id <> ? LIMIT 1');
        $link->bind_param('iii', $school_id, $teacher_id, $id);
        $link->execute();
        $linked = $link->get_result()->fetch_assoc();
        $link->close();
        if ($linked) user_api_reply(0, 'Ese docente ya tiene una cuenta vinculada.');
    } else {
        $teacher_id = 0;
    }

    if ($id === 0) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (school_id, name, username, password, type, is_director, teacher_id, status) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?,0), ?)');
        $stmt->bind_param('isssiiis', $school_id, $name, $username, $hash, $type, $is_director, $teacher_id, $status);
        $ok = $stmt->execute();
        $new_id = intval($conn->insert_id);
        $error = $stmt->error;
        $stmt->close();
        if (!$ok) user_api_reply(0, 'No se pudo crear el usuario: ' . $error);
        user_api_audit($conn, $school_id, $new_id, $username, 'CREATED', ['name' => $name, 'type' => $type, 'is_director' => $is_director, 'teacher_id' => $teacher_id, 'status' => $status]);
        user_api_reply(1, 'Usuario creado correctamente.', ['id' => $new_id]);
    }

    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET name = ?, username = ?, password = ?, type = ?, is_director = ?, teacher_id = NULLIF(?,0), status = ? WHERE id = ? AND school_id = ?');
        $stmt->bind_param('sssiiisii', $name, $username, $hash, $type, $is_director, $teacher_id, $status, $id, $school_id);
    } else {
        $stmt = $conn->prepare('UPDATE users SET name = ?, username = ?, type = ?, is_director = ?, teacher_id = NULLIF(?,0), status = ? WHERE id = ? AND school_id = ?');
        $stmt->bind_param('ssiiisii', $name, $username, $type, $is_director, $teacher_id, $status, $id, $school_id);
    }
    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();
    if (!$ok) user_api_reply(0, 'No se pudo actualizar el usuario: ' . $error);

    user_api_audit($conn, $school_id, $id, $username, 'UPDATED', [
        'before' => $current,
        'after' => ['name' => $name, 'username' => $username, 'type' => $type, 'is_director' => $is_director, 'teacher_id' => $teacher_id ?: null, 'status' => $status],
        'password_changed' => ($password !== '')
    ]);
    user_api_reply(1, 'Usuario actualizado correctamente.');
}

if ($action === 'toggle_status') {
    $id = intval($_POST['id'] ?? 0);
    $target = load_target_user($conn, $id, $school_id);
    if (!$target) user_api_reply(0, 'Usuario no encontrado.');
    reject_guardian_account_from_users($target);
    if ($id === $login_id) user_api_reply(0, 'No puedes desactivar tu propia cuenta.');

    $new_status = $target['status'] === 'Activo' ? 'Inactivo' : 'Activo';
    if ($new_status === 'Inactivo' && intval($target['type']) === 1 && count_active_admins($conn, $school_id, $id) < 1) {
        user_api_reply(0, 'No puedes desactivar al último administrador activo.');
    }
    if ($new_status === 'Activo' && intval($target['type']) === 2) {
        $teacher = load_teacher_for_user($conn, intval($target['teacher_id']), $school_id);
        if (!$teacher) user_api_reply(0, 'No puedes activar esta cuenta porque no tiene un docente válido vinculado.');
        if (($teacher['status'] ?? 'Inactivo') !== 'Activo') user_api_reply(0, 'No puedes activar esta cuenta porque el docente vinculado está inactivo.');
    }

    $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ? AND school_id = ?');
    $stmt->bind_param('sii', $new_status, $id, $school_id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) user_api_reply(0, 'No se pudo cambiar el estado.');

    user_api_audit($conn, $school_id, $id, $target['username'], $new_status === 'Activo' ? 'ACTIVATED' : 'DEACTIVATED', ['previous_status' => $target['status'], 'new_status' => $new_status]);
    user_api_reply(1, 'Estado actualizado.', ['new_status' => $new_status]);
}

if ($action === 'reset_password') {
    $id = intval($_POST['id'] ?? 0);
    $new_password = (string)($_POST['new_password'] ?? '');
    if (strlen($new_password) < 8) user_api_reply(0, 'La contraseña debe tener al menos 8 caracteres.');
    $target = load_target_user($conn, $id, $school_id);
    if (!$target) user_api_reply(0, 'Usuario no encontrado.');
    reject_guardian_account_from_users($target);

    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ? AND school_id = ?');
    $stmt->bind_param('sii', $hash, $id, $school_id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) user_api_reply(0, 'No se pudo restablecer la contraseña.');

    user_api_audit($conn, $school_id, $id, $target['username'], 'PASSWORD_RESET', []);
    user_api_reply(1, 'Contraseña restablecida correctamente.');
}

if ($action === 'history') {
    $id = intval($_POST['id'] ?? 0);
    $target = load_target_user($conn, $id, $school_id);
    if (!$target) user_api_reply(0, 'Usuario no encontrado.');

    $stmt = $conn->prepare("SELECT l.action, l.details, l.ip_address, l.created_at,
                                  COALESCE(a.name, CONCAT('Usuario #', l.actor_user_id), 'Sistema') AS actor_name
                           FROM user_audit_log l
                           LEFT JOIN users a ON a.id = l.actor_user_id
                           WHERE l.school_id = ? AND l.target_user_id = ?
                           ORDER BY l.id DESC LIMIT 100");
    $stmt->bind_param('ii', $school_id, $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $details = json_decode($row['details'] ?? '', true);
        $row['details'] = is_array($details) ? $details : [];
        $items[] = $row;
    }
    $stmt->close();
    user_api_reply(1, 'Historial cargado.', ['items' => $items, 'user' => ['id' => $id, 'name' => $target['name'], 'username' => $target['username']]]);
}

if ($action === 'delete_permanent') {
    $id = intval($_POST['id'] ?? 0);
    $target = load_target_user($conn, $id, $school_id);
    if (!$target) user_api_reply(0, 'Usuario no encontrado.');
    reject_guardian_account_from_users($target);
    if ($id === $login_id) user_api_reply(0, 'No puedes eliminar tu propia cuenta.');
    if ($target['status'] !== 'Inactivo') user_api_reply(0, 'Primero debes desactivar al usuario antes de eliminarlo definitivamente.');

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND school_id = ? AND status = 'Inactivo'");
    $stmt->bind_param('ii', $id, $school_id);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if (!$ok || $affected < 1) user_api_reply(0, 'No se pudo eliminar el usuario.');

    user_api_audit($conn, $school_id, $id, $target['username'], 'DELETED', ['name' => $target['name'], 'type' => intval($target['type']), 'teacher_id' => $target['teacher_id']]);
    user_api_reply(1, 'Usuario eliminado definitivamente.');
}

user_api_reply(0, 'Acción no válida.');
?>