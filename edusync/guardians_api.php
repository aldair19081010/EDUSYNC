<?php
ob_start();
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

function guardian_reply(array $data, int $code = 200): void
{
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function guardian_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '{$safe}'");
    return $q && $q->num_rows > 0;
}

function guardian_column_exists(mysqli $db, string $table, string $column): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safe = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safe}'");
    return $q && $q->num_rows > 0;
}

function guardian_audit(mysqli $db, int $schoolId, int $guardianId, string $action, array $details = []): void
{
    if (!guardian_table_exists($db, 'guardian_audit_log')) return;
    $actor = (int)($_SESSION['login_id'] ?? 0);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('INSERT INTO guardian_audit_log (school_id, guardian_id, actor_user_id, action, details, ip_address) VALUES (?, ?, NULLIF(?,0), ?, ?, ?)');
    if (!$stmt) return;
    $stmt->bind_param('iiisss', $schoolId, $guardianId, $actor, $action, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

function guardian_load(mysqli $db, int $guardianId, int $schoolId): ?array
{
    $stmt = $db->prepare('SELECT g.*, u.username, u.status AS user_status FROM guardians g LEFT JOIN users u ON u.id=g.user_id AND u.school_id=g.school_id WHERE g.id=? AND g.school_id=? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ii', $guardianId, $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function guardian_full_name(array $data): string
{
    return trim(implode(' ', array_filter([
        trim((string)($data['nombres'] ?? '')),
        trim((string)($data['apellido_paterno'] ?? '')),
        trim((string)($data['apellido_materno'] ?? '')),
    ], static fn($v) => $v !== '')));
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
if (!$schoolId || !$userId) guardian_reply(['status' => 0, 'message' => 'Sesión no válida.'], 403);

$role = $conn->prepare('SELECT type FROM users WHERE id=? AND school_id=? LIMIT 1');
if (!$role) guardian_reply(['status' => 0, 'message' => 'No se pudo validar el acceso.'], 500);
$role->bind_param('ii', $userId, $schoolId);
$role->execute();
$roleRow = $role->get_result()->fetch_assoc();
$role->close();
if (!$roleRow || (int)$roleRow['type'] !== 1) guardian_reply(['status' => 0, 'message' => 'No tienes permisos para administrar apoderados.'], 403);

$ready = guardian_table_exists($conn, 'guardians')
    && guardian_table_exists($conn, 'guardian_students')
    && guardian_table_exists($conn, 'guardian_audit_log')
    && guardian_column_exists($conn, 'users', 'status');
if (!$ready) {
    guardian_reply([
        'status' => 0,
        'migration_required' => true,
        'message' => 'Falta actualizar la base de datos. Ejecuta sql/guardians_module_upgrade.sql.'
    ], 409);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$writeActions = ['save', 'link', 'unlink', 'reset_pin', 'toggle_status'];
if (in_array($action, $writeActions, true)) {
    $posted = (string)($_POST['csrf_token'] ?? '');
    $session = (string)($_SESSION['csrf_token'] ?? '');
    if ($posted === '' || $session === '' || !hash_equals($session, $posted)) {
        guardian_reply(['status' => 0, 'message' => 'La sesión de seguridad venció. Recarga la página.'], 403);
    }
}

if ($action === 'list') {
    $stmt = $conn->prepare("SELECT g.id,g.dni,g.nombres,g.apellido_paterno,g.apellido_materno,g.telefono,g.email,g.status,g.created_at,
        COUNT(CASE WHEN gs.status='Activo' THEN 1 END) linked_students,
        SUM(CASE WHEN gs.status='Activo' AND gs.is_primary=1 THEN 1 ELSE 0 END) primary_links,
        GROUP_CONCAT(CASE WHEN gs.status='Activo' THEN s.name END ORDER BY s.name SEPARATOR ' | ') student_names
        FROM guardians g
        LEFT JOIN guardian_students gs ON gs.guardian_id=g.id AND gs.school_id=g.school_id
        LEFT JOIN student s ON s.id=gs.student_id AND s.school_id=g.school_id
        WHERE g.school_id=?
        GROUP BY g.id
        ORDER BY g.apellido_paterno,g.apellido_materno,g.nombres");
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['full_name'] = guardian_full_name($row);
        $row['linked_students'] = (int)$row['linked_students'];
        $row['primary_links'] = (int)$row['primary_links'];
        $rows[] = $row;
    }
    $stmt->close();
    guardian_reply(['status' => 1, 'guardians' => $rows]);
}

if ($action === 'detail') {
    $guardianId = (int)($_GET['id'] ?? 0);
    $guardian = guardian_load($conn, $guardianId, $schoolId);
    if (!$guardian) guardian_reply(['status' => 0, 'message' => 'Apoderado no encontrado.'], 404);
    $guardian['full_name'] = guardian_full_name($guardian);

    $stmt = $conn->prepare("SELECT gs.id AS link_id,gs.student_id,gs.parentesco,gs.is_primary,
        gs.can_view_grades,gs.can_view_attendance,gs.can_view_payments,gs.can_receive_communications,
        s.id_no,s.name,s.nivel,s.grado,s.seccion,s.status AS student_status
        FROM guardian_students gs
        INNER JOIN student s ON s.id=gs.student_id AND s.school_id=gs.school_id
        WHERE gs.guardian_id=? AND gs.school_id=? AND gs.status='Activo'
        ORDER BY gs.is_primary DESC,s.name");
    $stmt->bind_param('ii', $guardianId, $schoolId);
    $stmt->execute();
    $links = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        foreach (['is_primary','can_view_grades','can_view_attendance','can_view_payments','can_receive_communications'] as $key) {
            $row[$key] = (int)$row[$key];
        }
        $links[] = $row;
    }
    $stmt->close();
    guardian_reply(['status' => 1, 'guardian' => $guardian, 'links' => $links]);
}

if ($action === 'students') {
    $q = trim((string)($_GET['q'] ?? ''));
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("SELECT id,id_no,name,nivel,grado,seccion,status
        FROM student
        WHERE school_id=? AND (?='' OR id_no LIKE ? OR name LIKE ?)
        ORDER BY CASE WHEN status='Activo' THEN 0 ELSE 1 END,name
        LIMIT 50");
    $stmt->bind_param('isss', $schoolId, $q, $like, $like);
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'text' => trim($row['id_no'] . ' - ' . $row['name'] . ' · ' . $row['nivel'] . ' ' . $row['grado'] . ' ' . ($row['seccion'] ?: 'U')),
            'status' => $row['status']
        ];
    }
    $stmt->close();
    guardian_reply(['status' => 1, 'results' => $rows]);
}

if ($action === 'save') {
    $guardianId = (int)($_POST['id'] ?? 0);
    $dni = trim((string)($_POST['dni'] ?? ''));
    $nombres = trim((string)($_POST['nombres'] ?? ''));
    $apellidoPaterno = trim((string)($_POST['apellido_paterno'] ?? ''));
    $apellidoMaterno = trim((string)($_POST['apellido_materno'] ?? ''));
    $telefono = trim((string)($_POST['telefono'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $status = (($_POST['status'] ?? 'Activo') === 'Inactivo') ? 'Inactivo' : 'Activo';
    $pin = trim((string)($_POST['pin'] ?? ''));

    if (!preg_match('/^\d{8,12}$/', $dni)) guardian_reply(['status' => 0, 'message' => 'El DNI/documento debe contener entre 8 y 12 dígitos.']);
    if ($nombres === '' || $apellidoPaterno === '') guardian_reply(['status' => 0, 'message' => 'Nombres y apellido paterno son obligatorios.']);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) guardian_reply(['status' => 0, 'message' => 'El correo no es válido.']);
    if ($guardianId === 0 && !preg_match('/^\d{6,12}$/', $pin)) guardian_reply(['status' => 0, 'message' => 'La clave inicial debe tener entre 6 y 12 dígitos.']);
    if ($pin !== '' && !preg_match('/^\d{6,12}$/', $pin)) guardian_reply(['status' => 0, 'message' => 'La clave debe contener únicamente entre 6 y 12 dígitos.']);

    $current = $guardianId ? guardian_load($conn, $guardianId, $schoolId) : null;
    if ($guardianId && !$current) guardian_reply(['status' => 0, 'message' => 'Apoderado no encontrado.'], 404);

    $dup = $conn->prepare('SELECT id FROM guardians WHERE school_id=? AND dni=? AND id<>? LIMIT 1');
    $dup->bind_param('isi', $schoolId, $dni, $guardianId);
    $dup->execute();
    $dupRow = $dup->get_result()->fetch_assoc();
    $dup->close();
    if ($dupRow) guardian_reply(['status' => 0, 'message' => 'Ya existe un apoderado con ese DNI/documento.']);

    $currentUserId = (int)($current['user_id'] ?? 0);
    $userDup = $conn->prepare('SELECT id,type FROM users WHERE school_id=? AND username=? AND id<>? LIMIT 1');
    $userDup->bind_param('isi', $schoolId, $dni, $currentUserId);
    $userDup->execute();
    $userDupRow = $userDup->get_result()->fetch_assoc();
    $userDup->close();
    if ($userDupRow) guardian_reply(['status' => 0, 'message' => 'Ese DNI ya está siendo usado como usuario dentro del colegio.']);

    $fullName = guardian_full_name([
        'nombres' => $nombres,
        'apellido_paterno' => $apellidoPaterno,
        'apellido_materno' => $apellidoMaterno,
    ]);

    $conn->begin_transaction();
    try {
        if ($guardianId === 0) {
            $hash = password_hash($pin, PASSWORD_DEFAULT);
            $type = 5;
            $isDirector = 0;
            $teacherId = 0;
            $userStmt = $conn->prepare('INSERT INTO users (school_id,name,username,password,type,is_director,teacher_id,status) VALUES (?,?,?,?,?,?,NULLIF(?,0),?)');
            if (!$userStmt) throw new Exception('No se pudo preparar la cuenta de acceso del apoderado.');
            $userStmt->bind_param('isssiiis', $schoolId, $fullName, $dni, $hash, $type, $isDirector, $teacherId, $status);
            if (!$userStmt->execute()) throw new Exception($userStmt->error);
            $guardianUserId = (int)$conn->insert_id;
            $userStmt->close();

            $stmt = $conn->prepare('INSERT INTO guardians (school_id,user_id,dni,nombres,apellido_paterno,apellido_materno,telefono,email,status) VALUES (?,?,?,?,?,?,?,?,?)');
            if (!$stmt) throw new Exception('No se pudo preparar el registro del apoderado.');
            $stmt->bind_param('iisssssss', $schoolId, $guardianUserId, $dni, $nombres, $apellidoPaterno, $apellidoMaterno, $telefono, $email, $status);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $guardianId = (int)$conn->insert_id;
            $stmt->close();
            $conn->commit();
            guardian_audit($conn, $schoolId, $guardianId, 'CREATED', ['dni' => $dni, 'name' => $fullName, 'status' => $status]);
            guardian_reply(['status' => 1, 'message' => 'Apoderado creado correctamente.', 'id' => $guardianId]);
        }

        if ($currentUserId <= 0) throw new Exception('La cuenta de acceso vinculada al apoderado no existe.');
        if ($pin !== '') {
            $hash = password_hash($pin, PASSWORD_DEFAULT);
            $userStmt = $conn->prepare('UPDATE users SET name=?,username=?,password=?,status=? WHERE id=? AND school_id=? AND type=5');
            $userStmt->bind_param('ssssii', $fullName, $dni, $hash, $status, $currentUserId, $schoolId);
        } else {
            $userStmt = $conn->prepare('UPDATE users SET name=?,username=?,status=? WHERE id=? AND school_id=? AND type=5');
            $userStmt->bind_param('sssii', $fullName, $dni, $status, $currentUserId, $schoolId);
        }
        if (!$userStmt->execute() || $userStmt->affected_rows < 0) throw new Exception($userStmt->error ?: 'No se pudo actualizar la cuenta de acceso.');
        $userStmt->close();

        $stmt = $conn->prepare('UPDATE guardians SET dni=?,nombres=?,apellido_paterno=?,apellido_materno=?,telefono=?,email=?,status=? WHERE id=? AND school_id=?');
        $stmt->bind_param('sssssssii', $dni, $nombres, $apellidoPaterno, $apellidoMaterno, $telefono, $email, $status, $guardianId, $schoolId);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();
        $conn->commit();
        guardian_audit($conn, $schoolId, $guardianId, 'UPDATED', [
            'before' => ['dni' => $current['dni'], 'name' => guardian_full_name($current), 'status' => $current['status']],
            'after' => ['dni' => $dni, 'name' => $fullName, 'status' => $status],
            'pin_changed' => ($pin !== '')
        ]);
        guardian_reply(['status' => 1, 'message' => 'Apoderado actualizado correctamente.', 'id' => $guardianId]);
    } catch (Throwable $error) {
        $conn->rollback();
        guardian_reply(['status' => 0, 'message' => 'No se pudo guardar el apoderado: ' . $error->getMessage()]);
    }
}

if ($action === 'link') {
    $guardianId = (int)($_POST['guardian_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $parentesco = trim((string)($_POST['parentesco'] ?? ''));
    $isPrimary = !empty($_POST['is_primary']) ? 1 : 0;
    $canGrades = !empty($_POST['can_view_grades']) ? 1 : 0;
    $canAttendance = !empty($_POST['can_view_attendance']) ? 1 : 0;
    $canPayments = !empty($_POST['can_view_payments']) ? 1 : 0;
    $canCommunications = !empty($_POST['can_receive_communications']) ? 1 : 0;
    if (!$guardianId || !$studentId || $parentesco === '') guardian_reply(['status' => 0, 'message' => 'Selecciona estudiante y parentesco.']);
    if (mb_strlen($parentesco, 'UTF-8') > 40) guardian_reply(['status' => 0, 'message' => 'El parentesco es demasiado largo.']);
    $guardian = guardian_load($conn, $guardianId, $schoolId);
    if (!$guardian) guardian_reply(['status' => 0, 'message' => 'Apoderado no encontrado.'], 404);
    $student = $conn->prepare('SELECT id,id_no,name,nivel,grado,seccion FROM student WHERE id=? AND school_id=? LIMIT 1');
    $student->bind_param('ii', $studentId, $schoolId);
    $student->execute();
    $studentRow = $student->get_result()->fetch_assoc();
    $student->close();
    if (!$studentRow) guardian_reply(['status' => 0, 'message' => 'El estudiante no pertenece al colegio.'], 404);

    $conn->begin_transaction();
    try {
        if ($isPrimary) {
            $clear = $conn->prepare("UPDATE guardian_students SET is_primary=0 WHERE school_id=? AND student_id=? AND status='Activo'");
            $clear->bind_param('ii', $schoolId, $studentId);
            if (!$clear->execute()) throw new Exception($clear->error);
            $clear->close();
        }
        $stmt = $conn->prepare("INSERT INTO guardian_students
            (school_id,guardian_id,student_id,parentesco,is_primary,can_view_grades,can_view_attendance,can_view_payments,can_receive_communications,status)
            VALUES (?,?,?,?,?,?,?,?,?,'Activo')
            ON DUPLICATE KEY UPDATE parentesco=VALUES(parentesco),is_primary=VALUES(is_primary),can_view_grades=VALUES(can_view_grades),
            can_view_attendance=VALUES(can_view_attendance),can_view_payments=VALUES(can_view_payments),can_receive_communications=VALUES(can_receive_communications),status='Activo'");
        $stmt->bind_param('iiisiiiii', $schoolId, $guardianId, $studentId, $parentesco, $isPrimary, $canGrades, $canAttendance, $canPayments, $canCommunications);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();
        $conn->commit();
        guardian_audit($conn, $schoolId, $guardianId, 'LINKED_STUDENT', [
            'student_id' => $studentId,
            'student' => $studentRow['name'],
            'parentesco' => $parentesco,
            'is_primary' => $isPrimary,
            'permissions' => ['grades' => $canGrades, 'attendance' => $canAttendance, 'payments' => $canPayments, 'communications' => $canCommunications]
        ]);
        guardian_reply(['status' => 1, 'message' => 'Estudiante vinculado correctamente.']);
    } catch (Throwable $error) {
        $conn->rollback();
        guardian_reply(['status' => 0, 'message' => 'No se pudo vincular: ' . $error->getMessage()]);
    }
}

if ($action === 'unlink') {
    $guardianId = (int)($_POST['guardian_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $guardian = guardian_load($conn, $guardianId, $schoolId);
    if (!$guardian) guardian_reply(['status' => 0, 'message' => 'Apoderado no encontrado.'], 404);
    $stmt = $conn->prepare("UPDATE guardian_students SET status='Inactivo',is_primary=0 WHERE school_id=? AND guardian_id=? AND student_id=? AND status='Activo'");
    $stmt->bind_param('iii', $schoolId, $guardianId, $studentId);
    if (!$stmt->execute()) guardian_reply(['status' => 0, 'message' => 'No se pudo desvincular al estudiante.']);
    $changed = $stmt->affected_rows;
    $stmt->close();
    if ($changed < 1) guardian_reply(['status' => 0, 'message' => 'El vínculo ya no estaba activo.']);
    guardian_audit($conn, $schoolId, $guardianId, 'UNLINKED_STUDENT', ['student_id' => $studentId]);
    guardian_reply(['status' => 1, 'message' => 'Estudiante desvinculado.']);
}

if ($action === 'reset_pin') {
    $guardianId = (int)($_POST['guardian_id'] ?? 0);
    $pin = trim((string)($_POST['pin'] ?? ''));
    if (!preg_match('/^\d{6,12}$/', $pin)) guardian_reply(['status' => 0, 'message' => 'La nueva clave debe tener entre 6 y 12 dígitos.']);
    $guardian = guardian_load($conn, $guardianId, $schoolId);
    if (!$guardian || empty($guardian['user_id'])) guardian_reply(['status' => 0, 'message' => 'Cuenta de apoderado no encontrada.'], 404);
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password=? WHERE id=? AND school_id=? AND type=5');
    $uid = (int)$guardian['user_id'];
    $stmt->bind_param('sii', $hash, $uid, $schoolId);
    if (!$stmt->execute()) guardian_reply(['status' => 0, 'message' => 'No se pudo restablecer la clave.']);
    $stmt->close();
    guardian_audit($conn, $schoolId, $guardianId, 'PIN_RESET', []);
    guardian_reply(['status' => 1, 'message' => 'Clave numérica restablecida correctamente.']);
}

if ($action === 'toggle_status') {
    $guardianId = (int)($_POST['guardian_id'] ?? 0);
    $guardian = guardian_load($conn, $guardianId, $schoolId);
    if (!$guardian || empty($guardian['user_id'])) guardian_reply(['status' => 0, 'message' => 'Apoderado no encontrado.'], 404);
    $newStatus = $guardian['status'] === 'Activo' ? 'Inactivo' : 'Activo';
    $uid = (int)$guardian['user_id'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE guardians SET status=? WHERE id=? AND school_id=?');
        $stmt->bind_param('sii', $newStatus, $guardianId, $schoolId);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();
        $stmt = $conn->prepare('UPDATE users SET status=? WHERE id=? AND school_id=? AND type=5');
        $stmt->bind_param('sii', $newStatus, $uid, $schoolId);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        guardian_reply(['status' => 0, 'message' => 'No se pudo cambiar el estado: ' . $error->getMessage()]);
    }
    guardian_audit($conn, $schoolId, $guardianId, $newStatus === 'Activo' ? 'ACTIVATED' : 'DEACTIVATED', ['previous_status' => $guardian['status'], 'new_status' => $newStatus]);
    guardian_reply(['status' => 1, 'message' => 'Estado actualizado.', 'new_status' => $newStatus]);
}

if ($action === 'history') {
    $guardianId = (int)($_GET['id'] ?? 0);
    $guardian = guardian_load($conn, $guardianId, $schoolId);
    if (!$guardian) guardian_reply(['status' => 0, 'message' => 'Apoderado no encontrado.'], 404);
    $stmt = $conn->prepare("SELECT l.action,l.details,l.ip_address,l.created_at,COALESCE(u.name,'Sistema') actor_name
        FROM guardian_audit_log l
        LEFT JOIN users u ON u.id=l.actor_user_id AND u.school_id=l.school_id
        WHERE l.school_id=? AND l.guardian_id=?
        ORDER BY l.id DESC LIMIT 100");
    $stmt->bind_param('ii', $schoolId, $guardianId);
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['details'] = $row['details'] ? json_decode($row['details'], true) : null;
        $rows[] = $row;
    }
    $stmt->close();
    guardian_reply(['status' => 1, 'history' => $rows]);
}

guardian_reply(['status' => 0, 'message' => 'Acción no reconocida.'], 404);
