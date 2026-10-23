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

// Comprobar únicamente el token ACTUAL de esta instalación y sesión.
// Nunca devolver tokens FCM ni aceptar otros estudiantes/colegios.
if ($action === 'status') {
    $currentToken = trim((string)($_POST['fcm_token'] ?? ''));
    if (strlen($currentToken) < 20 || strlen($currentToken) > 1024) {
        device_reply(['status' => 'error', 'message' => 'Token actual inválido.'], 422);
    }
    $tokenHash = hash('sha256', $currentToken);
    $stmt = $conn->prepare(
        'SELECT id FROM student_device_tokens
         WHERE school_id=? AND student_id=? AND device_id=? AND token_hash=?
           AND is_active=1 LIMIT 1'
    );
    $stmt->bind_param('iiss', $schoolId, $studentId, $deviceId, $tokenHash);
    $stmt->execute();
    $registered = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    device_reply([
        'status' => 'ok',
        'registered' => $registered,
    ]);
}

// Prueba FCM bajo demanda: sólo el estudiante autenticado puede enviar
// un aviso al token que actualmente tiene vinculado a ESTE dispositivo.
// No crea eventos académicos ni expone el token o las credenciales Firebase.
if ($action === 'test_push') {
    $currentToken = trim((string)($_POST['fcm_token'] ?? ''));
    if (strlen($currentToken) < 20 || strlen($currentToken) > 1024) {
        device_reply(['status' => 'error', 'message' => 'Token de Firebase inválido.'], 422);
    }
    $tokenHash = hash('sha256', $currentToken);
    $stmt = $conn->prepare(
        'SELECT fcm_token FROM student_device_tokens
         WHERE school_id=? AND student_id=? AND device_id=? AND token_hash=?
           AND is_active=1 LIMIT 1'
    );
    if (!$stmt) {
        device_reply(['status' => 'error', 'message' => 'No se pudo consultar el dispositivo.'], 500);
    }
    $stmt->bind_param('iiss', $schoolId, $studentId, $deviceId, $tokenHash);
    $stmt->execute();
    $registeredDevice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$registeredDevice) {
        device_reply([
            'status' => 'error',
            'message' => 'El token actual no está registrado para este estudiante en este servidor.'
        ], 409);
    }

    // Limitar pruebas repetidas de FCM incluso si el usuario pulsa varias veces.
    $now = time();
    $last = (int)($_SESSION['student_push_test_last_at'] ?? 0);
    if ($last > 0 && $now - $last < 30) {
        device_reply([
            'status' => 'error',
            'message' => 'Espera 30 segundos entre las pruebas de Firebase.'
        ], 429);
    }
    $_SESSION['student_push_test_last_at'] = $now;

    require_once __DIR__ . '/../includes/push_notifications.php';
    $credentials = push_load_firebase_credentials();
    $projectId = trim((string)($credentials['project_id'] ?? ''));
    if (!$credentials || $projectId === '') {
        device_reply([
            'status' => 'error',
            'message' => 'El servidor no tiene las credenciales de Firebase configuradas.'
        ], 503);
    }
    $oauth = push_firebase_access_token($credentials);
    if (!$oauth) {
        device_reply([
            'status' => 'error',
            'message' => 'El servidor no pudo autenticarse con Firebase.'
        ], 503);
    }
    $payload = [
        'message' => [
            'token' => (string)$registeredDevice['fcm_token'],
            'notification' => [
                'title' => 'EduSync · Prueba desde Firebase',
                'body' => 'Aviso enviado por EduSync para verificar las alertas del teléfono.',
            ],
            'data' => [
                'type' => 'push_test',
                'screen' => 'debts',
                'school_id' => (string)$schoolId,
                'student_id' => (string)$studentId,
            ],
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'channel_id' => 'debt_alerts',
                    'sound' => 'default',
                    'icon' => 'ic_notification',
                ],
            ],
        ],
    ];
    $response = push_http_post(
        'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send',
        [
            'Authorization: Bearer ' . $oauth,
            'Content-Type: application/json; charset=utf-8',
        ],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    if (!$response['ok']) {
        error_log('[EduSync student push test] HTTP ' . (int)$response['code']
            . ' ' . substr((string)($response['body'] ?? $response['error'] ?? ''), 0, 200));
        device_reply([
            'status' => 'error',
            'message' => 'Firebase rechazó el mensaje de prueba (HTTP ' . (int)$response['code'] . ').',
            'provider_code' => (int)$response['code'],
            'sender_project_id' => $projectId,
        ], 502);
    }

    device_reply([
        'status' => 'ok',
        'accepted' => true,
        'sender_project_id' => $projectId,
        'message' => 'Firebase aceptó el envío. Comprueba si Android lo muestra.',
    ]);
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
