<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ob_start();
include_once __DIR__ . '/../session_config.php';
include __DIR__ . '/../db_connect.php';
if (ob_get_length()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function snOut(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function snTableExists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

if (empty($_SESSION['student_logged_in'])
    || empty($_SESSION['student_id'])
    || empty($_SESSION['student_school_id'])) {
    snOut(['status' => 'error', 'message' => 'La sesión del estudiante no es válida.'], 401);
}

if (!snTableExists($conn, 'student_notification_events')) {
    snOut([
        'status' => 'error',
        'migration_required' => true,
        'message' => 'Falta actualizar sql/student_push_notifications.sql.'
    ], 409);
}

$studentId = (int)$_SESSION['student_id'];
$schoolId = (int)$_SESSION['student_school_id'];
$action = trim((string)($_REQUEST['action'] ?? 'list'));

if ($action === 'announcement_detail') {
    if (!snTableExists($conn, 'student_announcements')) {
        snOut([
            'status' => 'error',
            'migration_required' => true,
            'message' => 'Falta actualizar sql/student_announcements.sql.'
        ], 409);
    }

    $announcementId = max(0, (int)($_GET['announcement_id'] ?? 0));
    if ($announcementId <= 0) {
        snOut(['status' => 'error', 'message' => 'Comunicado inválido.'], 422);
    }

    $stmt = $conn->prepare(
        "SELECT a.id,a.title,a.content,a.created_at,u.name sender_name
         FROM student_announcements a
         INNER JOIN student_notification_events e
           ON e.school_id=a.school_id
          AND e.student_id=?
          AND e.entity_type='announcement'
          AND e.entity_id=a.id
         LEFT JOIN users u
           ON u.id=a.created_by
          AND u.school_id=a.school_id
         WHERE a.id=? AND a.school_id=?
         LIMIT 1"
    );
    if (!$stmt) {
        snOut(['status' => 'error', 'message' => 'No se pudo consultar el comunicado.'], 500);
    }

    $stmt->bind_param('iii', $studentId, $announcementId, $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        snOut(['status' => 'error', 'message' => 'Este comunicado no está disponible para tu cuenta.'], 404);
    }

    snOut([
        'status' => 'ok',
        'announcement' => [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'content' => (string)$row['content'],
            'sender_name' => (string)($row['sender_name'] ?? 'Administración'),
            'created_at' => (string)$row['created_at'],
        ],
    ]);
}

if ($action === 'list') {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $beforeId = max(0, (int)($_GET['before_id'] ?? 0));

    $sql = "SELECT id,notification_type,title,body,screen,entity_type,entity_id,data_json,is_read,read_at,created_at
            FROM student_notification_events
            WHERE school_id=? AND student_id=?
              AND (expires_at IS NULL OR expires_at>NOW())";
    if ($beforeId > 0) $sql .= " AND id<?";
    $sql .= " ORDER BY id DESC LIMIT ?";

    $stmt = $conn->prepare($sql);
    if ($beforeId > 0) {
        $stmt->bind_param('iiii', $schoolId, $studentId, $beforeId, $limit);
    } else {
        $stmt->bind_param('iii', $schoolId, $studentId, $limit);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['entity_id'] = $row['entity_id'] !== null ? (int)$row['entity_id'] : null;
        $row['is_read'] = (bool)$row['is_read'];
        $row['data'] = [];
        if (!empty($row['data_json'])) {
            $decoded = json_decode($row['data_json'], true);
            if (is_array($decoded)) $row['data'] = $decoded;
        }
        unset($row['data_json']);
        $items[] = $row;
    }
    $stmt->close();

    $unreadStmt = $conn->prepare(
        "SELECT COUNT(*) total
         FROM student_notification_events
         WHERE school_id=? AND student_id=? AND is_read=0
           AND (expires_at IS NULL OR expires_at>NOW())"
    );
    $unreadStmt->bind_param('ii', $schoolId, $studentId);
    $unreadStmt->execute();
    $unread = (int)($unreadStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $unreadStmt->close();

    snOut([
        'status' => 'ok',
        'unread_count' => $unread,
        'items' => $items,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    snOut(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}

if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) snOut(['status' => 'error', 'message' => 'Notificación inválida.'], 422);

    $stmt = $conn->prepare(
        'UPDATE student_notification_events
         SET is_read=1,read_at=COALESCE(read_at,NOW())
         WHERE id=? AND school_id=? AND student_id=?'
    );
    $stmt->bind_param('iii', $id, $schoolId, $studentId);
    $stmt->execute();
    $stmt->close();
    snOut(['status' => 'ok']);
}

if ($action === 'mark_all_read') {
    $stmt = $conn->prepare(
        'UPDATE student_notification_events
         SET is_read=1,read_at=COALESCE(read_at,NOW())
         WHERE school_id=? AND student_id=? AND is_read=0'
    );
    $stmt->bind_param('ii', $schoolId, $studentId);
    $stmt->execute();
    $stmt->close();
    snOut(['status' => 'ok']);
}

snOut(['status' => 'error', 'message' => 'Acción no válida.'], 400);
