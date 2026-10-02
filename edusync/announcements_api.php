<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

ob_start();
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
if (ob_get_length()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function caOut(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function caTableExists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function caBind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $args = [$types];
    foreach ($params as &$param) $args[] = &$param;
    call_user_func_array([$stmt, 'bind_param'], $args);
}

function caAudienceLabel(array $row): string {
    $type = (string)($row['audience_type'] ?? '');
    if ($type === 'all') return 'Todo el colegio';
    if ($type === 'level') return (string)($row['audience_level'] ?? 'Nivel');
    if ($type === 'grade') return trim((string)($row['audience_level'] ?? '') . ' · ' . (string)($row['audience_grade'] ?? ''));
    if ($type === 'section') return trim((string)($row['audience_level'] ?? '') . ' · ' . (string)($row['audience_grade'] ?? '') . ' ' . (string)($row['audience_section'] ?? ''));
    if ($type === 'student') return (string)($row['student_name'] ?? 'Estudiante');
    return 'Destinatarios';
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
$action = trim((string)($_REQUEST['action'] ?? 'list'));

if ($schoolId <= 0 || $userId <= 0) {
    caOut(['status' => 0, 'message' => 'Sesión inválida.'], 401);
}

$role = $conn->prepare('SELECT type,is_director FROM users WHERE id=? AND school_id=? LIMIT 1');
if (!$role) caOut(['status' => 0, 'message' => 'No se pudo validar el usuario.'], 500);
$role->bind_param('ii', $userId, $schoolId);
$role->execute();
$roleRow = $role->get_result()->fetch_assoc();
$role->close();

if (!$roleRow || (int)$roleRow['type'] !== 1) {
    caOut(['status' => 0, 'message' => 'Solo Dirección o Administración puede enviar comunicados.'], 403);
}

if (!caTableExists($conn, 'student_announcements')) {
    caOut([
        'status' => 0,
        'migration_required' => true,
        'message' => 'Ejecuta sql/student_announcements.sql para habilitar Comunicados.'
    ], 409);
}

if (!caTableExists($conn, 'student_notification_events')
    || !caTableExists($conn, 'student_device_tokens')) {
    caOut([
        'status' => 0,
        'migration_required' => true,
        'message' => 'Falta completar la migración del sistema de notificaciones estudiantiles.'
    ], 409);
}

if ($action === 'options') {
    $levels = [];
    $grades = [];
    $sections = [];
    $students = [];

    $q = $conn->prepare(
        "SELECT id,id_no,name,nivel,grado,COALESCE(NULLIF(TRIM(seccion),''),'U') seccion
         FROM student
         WHERE school_id=? AND (status='Activo' OR status IS NULL)
         ORDER BY nivel,grado,seccion,name"
    );
    $q->bind_param('i', $schoolId);
    $q->execute();
    $result = $q->get_result();

    $levelSeen = [];
    $gradeSeen = [];
    $sectionSeen = [];

    while ($row = $result->fetch_assoc()) {
        $level = trim((string)($row['nivel'] ?? ''));
        $grade = trim((string)($row['grado'] ?? ''));
        $section = trim((string)($row['seccion'] ?? 'U')) ?: 'U';

        if ($level !== '' && !isset($levelSeen[$level])) {
            $levelSeen[$level] = true;
            $levels[] = $level;
        }

        $gradeKey = $level . '|' . $grade;
        if ($level !== '' && $grade !== '' && !isset($gradeSeen[$gradeKey])) {
            $gradeSeen[$gradeKey] = true;
            $grades[] = ['level' => $level, 'grade' => $grade];
        }

        $sectionKey = $gradeKey . '|' . $section;
        if ($level !== '' && $grade !== '' && !isset($sectionSeen[$sectionKey])) {
            $sectionSeen[$sectionKey] = true;
            $sections[] = ['level' => $level, 'grade' => $grade, 'section' => $section];
        }

        $students[] = [
            'id' => (int)$row['id'],
            'dni' => (string)($row['id_no'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'level' => $level,
            'grade' => $grade,
            'section' => $section,
        ];
    }
    $q->close();

    caOut([
        'status' => 1,
        'levels' => $levels,
        'grades' => $grades,
        'sections' => $sections,
        'students' => $students,
    ]);
}

if ($action === 'list') {
    $stmt = $conn->prepare(
        "SELECT a.*,u.name created_by_name,s.name student_name
         FROM student_announcements a
         LEFT JOIN users u ON u.id=a.created_by AND u.school_id=a.school_id
         LEFT JOIN student s ON s.id=a.audience_student_id AND s.school_id=a.school_id
         WHERE a.school_id=?
         ORDER BY a.id DESC
         LIMIT 100"
    );
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'content' => (string)$row['content'],
            'audience' => caAudienceLabel($row),
            'recipient_count' => (int)$row['recipient_count'],
            'push_sent_count' => (int)$row['push_sent_count'],
            'push_failed_count' => (int)$row['push_failed_count'],
            'created_by_name' => (string)($row['created_by_name'] ?? 'Administración'),
            'created_at' => (string)$row['created_at'],
        ];
    }
    $stmt->close();
    caOut(['status' => 1, 'items' => $items]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    caOut(['status' => 0, 'message' => 'Método no permitido.'], 405);
}

if (!hash_equals(
    (string)($_SESSION['csrf_token'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    caOut(['status' => 0, 'message' => 'La sesión de seguridad venció. Recarga la página.'], 403);
}

if ($action !== 'send') {
    caOut(['status' => 0, 'message' => 'Acción no válida.'], 400);
}

set_time_limit(180);

$title = trim((string)($_POST['title'] ?? ''));
$content = trim((string)($_POST['content'] ?? ''));
$audienceType = trim((string)($_POST['audience_type'] ?? 'all'));
$level = trim((string)($_POST['level'] ?? ''));
$grade = trim((string)($_POST['grade'] ?? ''));
$section = trim((string)($_POST['section'] ?? ''));
$studentId = max(0, (int)($_POST['student_id'] ?? 0));

if (mb_strlen($title, 'UTF-8') < 3 || mb_strlen($title, 'UTF-8') > 180) {
    caOut(['status' => 0, 'message' => 'El título debe tener entre 3 y 180 caracteres.'], 422);
}
if (mb_strlen($content, 'UTF-8') < 3 || mb_strlen($content, 'UTF-8') > 5000) {
    caOut(['status' => 0, 'message' => 'El comunicado debe tener entre 3 y 5000 caracteres.'], 422);
}
if (!in_array($audienceType, ['all','level','grade','section','student'], true)) {
    caOut(['status' => 0, 'message' => 'Selecciona un tipo de destinatario válido.'], 422);
}
if ($audienceType === 'level' && $level === '') {
    caOut(['status' => 0, 'message' => 'Selecciona el nivel.'], 422);
}
if ($audienceType === 'grade' && ($level === '' || $grade === '')) {
    caOut(['status' => 0, 'message' => 'Selecciona nivel y grado.'], 422);
}
if ($audienceType === 'section' && ($level === '' || $grade === '' || $section === '')) {
    caOut(['status' => 0, 'message' => 'Selecciona nivel, grado y sección.'], 422);
}
if ($audienceType === 'student' && $studentId <= 0) {
    caOut(['status' => 0, 'message' => 'Selecciona un estudiante.'], 422);
}

$sql = "SELECT id,name,nivel,grado,COALESCE(NULLIF(TRIM(seccion),''),'U') seccion
        FROM student
        WHERE school_id=? AND (status='Activo' OR status IS NULL)";
$types = 'i';
$params = [$schoolId];

if ($audienceType === 'level') {
    $sql .= ' AND nivel=?';
    $types .= 's';
    $params[] = $level;
} elseif ($audienceType === 'grade') {
    $sql .= ' AND nivel=? AND grado=?';
    $types .= 'ss';
    array_push($params, $level, $grade);
} elseif ($audienceType === 'section') {
    $sql .= ' AND nivel=? AND grado=?';
    $types .= 'ss';
    array_push($params, $level, $grade);
    $normalizedSection = mb_strtoupper($section, 'UTF-8');
    if (in_array($normalizedSection, ['U','ÚNICA','UNICA'], true)) {
        $sql .= " AND COALESCE(NULLIF(TRIM(seccion),''),'U') IN ('U','u','Única','Unica','única','unica')";
    } else {
        $sql .= ' AND seccion=?';
        $types .= 's';
        $params[] = $section;
    }
} elseif ($audienceType === 'student') {
    $sql .= ' AND id=?';
    $types .= 'i';
    $params[] = $studentId;
}
$sql .= ' ORDER BY id';

$targetStmt = $conn->prepare($sql);
if (!$targetStmt) caOut(['status' => 0, 'message' => 'No se pudieron resolver los destinatarios.'], 500);
caBind($targetStmt, $types, $params);
$targetStmt->execute();
$targetResult = $targetStmt->get_result();
$targets = [];
while ($row = $targetResult->fetch_assoc()) $targets[] = $row;
$targetStmt->close();

if (!$targets) {
    caOut(['status' => 0, 'message' => 'No hay estudiantes activos para los destinatarios seleccionados.'], 422);
}

$audienceStudentId = $audienceType === 'student' ? $studentId : null;
$insert = $conn->prepare(
    'INSERT INTO student_announcements
     (school_id,title,content,audience_type,audience_level,audience_grade,audience_section,audience_student_id,recipient_count,created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
$recipientCount = count($targets);
$levelValue = in_array($audienceType, ['level','grade','section'], true) ? $level : null;
$gradeValue = in_array($audienceType, ['grade','section'], true) ? $grade : null;
$sectionValue = $audienceType === 'section' ? $section : null;
$insert->bind_param(
    'issssssiii',
    $schoolId,
    $title,
    $content,
    $audienceType,
    $levelValue,
    $gradeValue,
    $sectionValue,
    $audienceStudentId,
    $recipientCount,
    $userId
);
if (!$insert->execute()) {
    $insert->close();
    caOut(['status' => 0, 'message' => 'No se pudo guardar el comunicado.'], 500);
}
$announcementId = (int)$conn->insert_id;
$insert->close();

require_once __DIR__ . '/includes/push_notifications.php';

$sent = 0;
$failed = 0;
$events = 0;
$withDevices = 0;

foreach ($targets as $student) {
    try {
        $push = push_send_announcement_notification(
            $conn,
            $schoolId,
            (int)$student['id'],
            $announcementId,
            $title,
            $content
        );
        if ((int)($push['event_id'] ?? 0) > 0) $events++;
        if ((int)($push['devices'] ?? 0) > 0) $withDevices++;
        $sent += (int)($push['sent'] ?? 0);
        $failed += (int)($push['failed'] ?? 0);
    } catch (Throwable $pushError) {
        $failed++;
        error_log('[announcement push] ' . $pushError->getMessage());
    }
}

$update = $conn->prepare(
    'UPDATE student_announcements
     SET push_sent_count=?,push_failed_count=?
     WHERE id=? AND school_id=?'
);
$update->bind_param('iiii', $sent, $failed, $announcementId, $schoolId);
$update->execute();
$update->close();

caOut([
    'status' => 1,
    'message' => 'Comunicado enviado.',
    'announcement_id' => $announcementId,
    'recipients' => $recipientCount,
    'events_created' => $events,
    'students_with_devices' => $withDevices,
    'push_sent' => $sent,
    'push_failed' => $failed,
]);
