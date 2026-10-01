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

function device_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function device_table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    device_reply(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}

if (empty($_SESSION['student_logged_in'])
    || empty($_SESSION['student_id'])
    || empty($_SESSION['student_school_id'])) {
    device_reply([
        'status' => 'error',
        'message' => 'La sesión del estudiante no es válida. Inicia sesión nuevamente.'
    ], 401);
}

if (!device_table_exists($conn, 'student_device_tokens')) {
    device_reply([
        'status' => 'error',
        'migration_required' => true,
        'message' => 'Falta ejecutar sql/student_push_notifications.sql.'
    ], 409);
}

$studentId = (int)$_SESSION['student_id'];
$schoolId = (int)$_SESSION['student_school_id'];
$action = trim((string)($_POST['action'] ?? 'register'));
$deviceId = trim((string)($_POST['device_id'] ?? ''));

if (!preg_match('/^[A-Za-z0-9._:-]{12,128}$/', $deviceId)) {
    device_reply(['status' => 'error', 'message' => 'Identificador de dispositivo inválido.'], 422);
}

$check = $conn->prepare(
    "SELECT id FROM student WHERE id=? AND school_id=? AND status='Activo' LIMIT 1"
);
$check->bind_param('ii', $studentId, $schoolId);
$check->execute();
$student = $check->get_result()->fetch_assoc();
$check->close();

if (!$student) {
    device_reply(['status' => 'error', 'message' => 'Estudiante no disponible.'], 403);
}

if ($action === 'unregister') {
    $stmt = $conn->prepare(
        'UPDATE student_device_tokens
         SET is_active=0,last_seen_at=NOW()
         WHERE school_id=? AND student_id=? AND device_id=?'
    );
    $stmt->bind_param('iis', $schoolId, $studentId, $deviceId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    device_reply([
        'status' => 'ok',
        'message' => 'Dispositivo desvinculado.',
        'updated' => max(0, $affected),
    ]);
}

if ($action !== 'register') {
    device_reply(['status' => 'error', 'message' => 'Acción no válida.'], 400);
}

$fcmToken = trim((string)($_POST['fcm_token'] ?? ''));
$platform = strtolower(trim((string)($_POST['platform'] ?? 'android')));
$appVersion = trim((string)($_POST['app_version'] ?? ''));

if (strlen($fcmToken) < 20 || strlen($fcmToken) > 1024) {
    device_reply(['status' => 'error', 'message' => 'Token de notificación inválido.'], 422);
}

if (!in_array($platform, ['android', 'ios'], true)) {
    $platform = 'android';
}

$appVersion = mb_substr($appVersion, 0, 40, 'UTF-8');
$tokenHash = hash('sha256', $fcmToken);

$conn->begin_transaction();

try {
    // Si Firebase reasignó el token a otro dispositivo/sesión, conserva una sola
    // vinculación activa para evitar notificaciones cruzadas.
    $existingToken = $conn->prepare(
        'SELECT id FROM student_device_tokens WHERE token_hash=? LIMIT 1 FOR UPDATE'
    );
    $existingToken->bind_param('s', $tokenHash);
    $existingToken->execute();
    $tokenRow = $existingToken->get_result()->fetch_assoc();
    $existingToken->close();

    if ($tokenRow) {
        $tokenId = (int)$tokenRow['id'];
        $update = $conn->prepare(
            'UPDATE student_device_tokens
             SET school_id=?,student_id=?,device_id=?,fcm_token=?,platform=?,
                 app_version=?,is_active=1,last_seen_at=NOW()
             WHERE id=?'
        );
        $update->bind_param(
            'iissssi',
            $schoolId,
            $studentId,
            $deviceId,
            $fcmToken,
            $platform,
            $appVersion,
            $tokenId
        );
        if (!$update->execute()) throw new Exception($update->error);
        $update->close();
    } else {
        $existingDevice = $conn->prepare(
            'SELECT id FROM student_device_tokens
             WHERE school_id=? AND student_id=? AND device_id=?
             LIMIT 1 FOR UPDATE'
        );
        $existingDevice->bind_param('iis', $schoolId, $studentId, $deviceId);
        $existingDevice->execute();
        $deviceRow = $existingDevice->get_result()->fetch_assoc();
        $existingDevice->close();

        if ($deviceRow) {
            $tokenId = (int)$deviceRow['id'];
            $update = $conn->prepare(
                'UPDATE student_device_tokens
                 SET fcm_token=?,token_hash=?,platform=?,app_version=?,
                     is_active=1,last_seen_at=NOW()
                 WHERE id=?'
            );
            $update->bind_param(
                'ssssi',
                $fcmToken,
                $tokenHash,
                $platform,
                $appVersion,
                $tokenId
            );
            if (!$update->execute()) throw new Exception($update->error);
            $update->close();
        } else {
            $insert = $conn->prepare(
                'INSERT INTO student_device_tokens
                (school_id,student_id,device_id,fcm_token,token_hash,platform,app_version,is_active,last_seen_at)
                VALUES(?,?,?,?,?,?,?,1,NOW())'
            );
            $insert->bind_param(
                'iisssss',
                $schoolId,
                $studentId,
                $deviceId,
                $fcmToken,
                $tokenHash,
                $platform,
                $appVersion
            );
            if (!$insert->execute()) throw new Exception($insert->error);
            $tokenId = (int)$insert->insert_id;
            $insert->close();
        }
    }

    $conn->commit();

    device_reply([
        'status' => 'ok',
        'message' => 'Dispositivo registrado para notificaciones.',
        'device_token_id' => $tokenId,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[device_notifications] ' . $e->getMessage());
    device_reply([
        'status' => 'error',
        'message' => 'No se pudo registrar el dispositivo.'
    ], 500);
}
