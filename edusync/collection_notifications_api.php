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

function cnOut(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cnTableExists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function cnBind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $args = [$types];
    foreach ($params as &$param) $args[] = &$param;
    call_user_func_array([$stmt, 'bind_param'], $args);
}

function cnAudienceLabel(array $row): string {
    $type = (string)($row['audience_type'] ?? '');
    if ($type === 'all') return 'Todos con deuda';
    if ($type === 'level') return (string)($row['audience_level'] ?? 'Nivel');
    if ($type === 'grade') {
        return trim((string)($row['audience_level'] ?? '') . ' · ' . (string)($row['audience_grade'] ?? ''));
    }
    if ($type === 'section') {
        return trim((string)($row['audience_level'] ?? '') . ' · ' . (string)($row['audience_grade'] ?? '') . ' ' . (string)($row['audience_section'] ?? ''));
    }
    if ($type === 'student') return (string)($row['student_name'] ?? 'Estudiante');
    return 'Destinatarios';
}

function cnNormalizeInput(array $source): array {
    $type = trim((string)($source['audience_type'] ?? 'all'));
    $level = trim((string)($source['level'] ?? ''));
    $grade = trim((string)($source['grade'] ?? ''));
    $section = trim((string)($source['section'] ?? ''));
    $studentId = max(0, (int)($source['student_id'] ?? 0));
    $overdue = !empty($source['include_overdue']) ? 1 : 0;
    $partial = !empty($source['include_partial']) ? 1 : 0;
    $upcoming = !empty($source['include_upcoming']) ? 1 : 0;

    if (!in_array($type, ['all','level','grade','section','student'], true)) {
        cnOut(['status' => 0, 'message' => 'Selecciona un tipo de destinatario válido.'], 422);
    }
    if ($type === 'level' && $level === '') {
        cnOut(['status' => 0, 'message' => 'Selecciona el nivel.'], 422);
    }
    if ($type === 'grade' && ($level === '' || $grade === '')) {
        cnOut(['status' => 0, 'message' => 'Selecciona nivel y grado.'], 422);
    }
    if ($type === 'section' && ($level === '' || $grade === '' || $section === '')) {
        cnOut(['status' => 0, 'message' => 'Selecciona nivel, grado y sección.'], 422);
    }
    if ($type === 'student' && $studentId <= 0) {
        cnOut(['status' => 0, 'message' => 'Selecciona un estudiante.'], 422);
    }
    if (!$overdue && !$partial && !$upcoming) {
        cnOut(['status' => 0, 'message' => 'Selecciona al menos un tipo de deuda.'], 422);
    }

    return [
        'audience_type' => $type,
        'level' => $level,
        'grade' => $grade,
        'section' => $section,
        'student_id' => $studentId,
        'include_overdue' => $overdue,
        'include_partial' => $partial,
        'include_upcoming' => $upcoming,
    ];
}

function cnResolve(mysqli $db, int $schoolId, array $filter): array {
    $where = [
        's.school_id=?',
        "s.status='Activo'",
        "ef.debt_status='Activa'",
    ];
    $types = 'i';
    $params = [$schoolId];

    if ($filter['audience_type'] === 'level') {
        $where[] = 's.nivel=?';
        $types .= 's';
        $params[] = $filter['level'];
    } elseif ($filter['audience_type'] === 'grade') {
        $where[] = 's.nivel=?';
        $where[] = 's.grado=?';
        $types .= 'ss';
        $params[] = $filter['level'];
        $params[] = $filter['grade'];
    } elseif ($filter['audience_type'] === 'section') {
        $where[] = 's.nivel=?';
        $where[] = 's.grado=?';
        $types .= 'ss';
        $params[] = $filter['level'];
        $params[] = $filter['grade'];
        $normalizedSection = mb_strtoupper($filter['section'], 'UTF-8');
        if (in_array($normalizedSection, ['U','ÚNICA','UNICA'], true)) {
            $where[] = "COALESCE(NULLIF(TRIM(s.seccion),''),'U') IN ('U','u','Única','Unica','única','unica')";
        } else {
            $where[] = 's.seccion=?';
            $types .= 's';
            $params[] = $filter['section'];
        }
    } elseif ($filter['audience_type'] === 'student') {
        $where[] = 's.id=?';
        $types .= 'i';
        $params[] = $filter['student_id'];
    }

    $base = "SELECT
                ef.id debt_id,
                ef.student_id,
                ef.due_date,
                ef.billing_period,
                s.id_no dni,
                s.name student_name,
                s.nivel,
                s.grado,
                COALESCE(NULLIF(TRIM(s.seccion),''),'U') seccion,
                c.course,
                COALESCE(ef.discounted_amount,ef.total_fee) effective,
                (SELECT COALESCE(SUM(p.amount),0)
                 FROM payments p
                 WHERE p.ef_id=ef.id
                   AND COALESCE(p.payment_status,'Confirmado')='Confirmado') paid
             FROM student_ef_list ef
             INNER JOIN student s ON s.id=ef.student_id
             INNER JOIN courses c ON c.id=ef.course_id
             WHERE " . implode(' AND ', $where);

    $stateConditions = [];
    if ($filter['include_overdue']) {
        $stateConditions[] = "(q.due_date IS NULL OR q.due_date='' OR q.due_date<CURDATE())";
    }
    if ($filter['include_partial']) {
        $stateConditions[] = 'q.paid>0.009';
    }
    if ($filter['include_upcoming']) {
        $stateConditions[] = "q.due_date IS NOT NULL AND q.due_date<>'' AND q.due_date>=CURDATE()";
    }

    $sql = "SELECT q.*,GREATEST(0,q.effective-q.paid) balance
            FROM ($base) q
            WHERE q.effective-q.paid>0.009
              AND (" . implode(' OR ', $stateConditions) . ")
            ORDER BY q.student_name,q.due_date,q.debt_id";

    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    cnBind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $students = [];
    $debtCount = 0;
    $totalBalance = 0.0;
    $today = date('Y-m-d');

    while ($row = $result->fetch_assoc()) {
        $studentId = (int)$row['student_id'];
        $balance = (float)$row['balance'];
        $dueDate = trim((string)($row['due_date'] ?? ''));
        $isOverdue = $dueDate === '' || $dueDate < $today;
        $isPartial = (float)$row['paid'] > 0.009;
        $isUpcoming = $dueDate !== '' && $dueDate >= $today;

        if (!isset($students[$studentId])) {
            $students[$studentId] = [
                'student_id' => $studentId,
                'student_name' => (string)$row['student_name'],
                'dni' => (string)($row['dni'] ?? ''),
                'level' => (string)($row['nivel'] ?? ''),
                'grade' => (string)($row['grado'] ?? ''),
                'section' => (string)($row['seccion'] ?? 'U'),
                'debt_count' => 0,
                'balance' => 0.0,
                'debt_ids' => [],
                'concepts' => [],
                'overdue_count' => 0,
                'partial_count' => 0,
                'upcoming_count' => 0,
            ];
        }

        $students[$studentId]['debt_count']++;
        $students[$studentId]['balance'] += $balance;
        $students[$studentId]['debt_ids'][] = (int)$row['debt_id'];
        $students[$studentId]['concepts'][] = (string)$row['course'];
        if ($isOverdue) $students[$studentId]['overdue_count']++;
        if ($isPartial) $students[$studentId]['partial_count']++;
        if ($isUpcoming) $students[$studentId]['upcoming_count']++;

        $debtCount++;
        $totalBalance += $balance;
    }
    $stmt->close();

    $items = array_values($students);
    foreach ($items as &$item) {
        $item['balance'] = round((float)$item['balance'], 2);
        $item['debt_ids'] = array_values(array_unique(array_map('intval', $item['debt_ids'])));
        $item['concepts'] = array_values(array_unique(array_filter(array_map('strval', $item['concepts']))));
    }
    unset($item);

    return [
        'students' => $items,
        'recipient_count' => count($items),
        'debt_count' => $debtCount,
        'total_balance' => round($totalBalance, 2),
    ];
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
$action = trim((string)($_REQUEST['action'] ?? 'summary'));

if ($schoolId <= 0 || $userId <= 0) {
    cnOut(['status' => 0, 'message' => 'Sesión inválida.'], 401);
}

$role = $conn->prepare('SELECT type,is_director FROM users WHERE id=? AND school_id=? LIMIT 1');
if (!$role) cnOut(['status' => 0, 'message' => 'No se pudo validar el usuario.'], 500);
$role->bind_param('ii', $userId, $schoolId);
$role->execute();
$roleRow = $role->get_result()->fetch_assoc();
$role->close();

if (!$roleRow || (int)$roleRow['type'] !== 1) {
    cnOut(['status' => 0, 'message' => 'Solo Dirección o Administración puede realizar envíos de cobranza.'], 403);
}

foreach (['student_collection_campaigns','student_collection_recipients'] as $table) {
    if (!cnTableExists($conn, $table)) {
        cnOut([
            'status' => 0,
            'migration_required' => true,
            'message' => 'Ejecuta sql/student_collection_campaigns.sql para habilitar Cobranza.'
        ], 409);
    }
}
if (!cnTableExists($conn, 'student_notification_events')
    || !cnTableExists($conn, 'student_device_tokens')) {
    cnOut([
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

    $stmt = $conn->prepare(
        "SELECT id,id_no,name,nivel,grado,COALESCE(NULLIF(TRIM(seccion),''),'U') seccion
         FROM student
         WHERE school_id=? AND status='Activo'
         ORDER BY nivel,grado,seccion,name"
    );
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $result = $stmt->get_result();

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
    $stmt->close();

    cnOut([
        'status' => 1,
        'levels' => $levels,
        'grades' => $grades,
        'sections' => $sections,
        'students' => $students,
    ]);
}

if ($action === 'summary') {
    $base = "SELECT
                ef.student_id,
                ef.due_date,
                COALESCE(ef.discounted_amount,ef.total_fee) effective,
                (SELECT COALESCE(SUM(p.amount),0)
                 FROM payments p
                 WHERE p.ef_id=ef.id
                   AND COALESCE(p.payment_status,'Confirmado')='Confirmado') paid
             FROM student_ef_list ef
             INNER JOIN student s ON s.id=ef.student_id
             WHERE s.school_id=?
               AND s.status='Activo'
               AND ef.debt_status='Activa'";

    $stmt = $conn->prepare(
        "SELECT
            COUNT(DISTINCT CASE WHEN q.effective-q.paid>0.009 THEN q.student_id END) students_with_debt,
            COALESCE(SUM(GREATEST(0,q.effective-q.paid)),0) balance,
            COALESCE(SUM(CASE WHEN q.effective-q.paid>0.009
                AND (q.due_date IS NULL OR q.due_date='' OR q.due_date<CURDATE())
                THEN GREATEST(0,q.effective-q.paid) ELSE 0 END),0) overdue_balance
         FROM ($base) q"
    );
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $finance = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $campaignStmt = $conn->prepare(
        "SELECT COUNT(*) total
         FROM student_collection_campaigns
         WHERE school_id=? AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')"
    );
    $campaignStmt->bind_param('i', $schoolId);
    $campaignStmt->execute();
    $campaignsMonth = (int)($campaignStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $campaignStmt->close();

    cnOut([
        'status' => 1,
        'summary' => [
            'students_with_debt' => (int)($finance['students_with_debt'] ?? 0),
            'balance' => round((float)($finance['balance'] ?? 0), 2),
            'overdue_balance' => round((float)($finance['overdue_balance'] ?? 0), 2),
            'campaigns_month' => $campaignsMonth,
        ],
    ]);
}

if ($action === 'preview') {
    try {
        $filter = cnNormalizeInput($_GET);
        $resolved = cnResolve($conn, $schoolId, $filter);

        $targetIds = array_column($resolved['students'], 'student_id');
        $withDevices = 0;
        if ($targetIds) {
            $idList = implode(',', array_map('intval', $targetIds));
            $q = $conn->query(
                "SELECT COUNT(DISTINCT student_id) total
                 FROM student_device_tokens
                 WHERE school_id=$schoolId AND is_active=1 AND student_id IN ($idList)"
            );
            $withDevices = $q ? (int)($q->fetch_assoc()['total'] ?? 0) : 0;
        }

        cnOut([
            'status' => 1,
            'preview' => [
                'recipient_count' => $resolved['recipient_count'],
                'debt_count' => $resolved['debt_count'],
                'total_balance' => $resolved['total_balance'],
                'students_with_devices' => $withDevices,
                'students_without_devices' => max(0, $resolved['recipient_count'] - $withDevices),
                'students' => $resolved['students'],
            ],
        ]);
    } catch (Throwable $error) {
        error_log('[collection preview] ' . $error->getMessage());
        cnOut(['status' => 0, 'message' => 'No se pudo calcular la cobranza con los filtros seleccionados.'], 500);
    }
}

if ($action === 'history') {
    $stmt = $conn->prepare(
        "SELECT c.*,u.name created_by_name,s.name student_name
         FROM student_collection_campaigns c
         LEFT JOIN users u ON u.id=c.created_by AND u.school_id=c.school_id
         LEFT JOIN student s ON s.id=c.audience_student_id AND s.school_id=c.school_id
         WHERE c.school_id=?
         ORDER BY c.id DESC
         LIMIT 100"
    );
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $states = [];
        if ((int)$row['include_overdue']) $states[] = 'Vencidas';
        if ((int)$row['include_partial']) $states[] = 'Parciales';
        if ((int)$row['include_upcoming']) $states[] = 'Por vencer';

        $items[] = [
            'id' => (int)$row['id'],
            'audience' => cnAudienceLabel($row),
            'states' => $states,
            'recipient_count' => (int)$row['recipient_count'],
            'debt_count' => (int)$row['debt_count'],
            'total_balance' => (float)$row['total_balance'],
            'students_with_devices' => (int)$row['students_with_devices'],
            'push_sent_count' => (int)$row['push_sent_count'],
            'push_failed_count' => (int)$row['push_failed_count'],
            'created_by_name' => (string)($row['created_by_name'] ?? 'Administración'),
            'created_at' => (string)$row['created_at'],
        ];
    }
    $stmt->close();
    cnOut(['status' => 1, 'items' => $items]);
}

if ($action === 'detail') {
    $campaignId = max(0, (int)($_GET['id'] ?? 0));
    if ($campaignId <= 0) cnOut(['status' => 0, 'message' => 'Campaña inválida.'], 422);

    $stmt = $conn->prepare(
        "SELECT c.*,u.name created_by_name,s.name student_name
         FROM student_collection_campaigns c
         LEFT JOIN users u ON u.id=c.created_by AND u.school_id=c.school_id
         LEFT JOIN student s ON s.id=c.audience_student_id AND s.school_id=c.school_id
         WHERE c.id=? AND c.school_id=? LIMIT 1"
    );
    $stmt->bind_param('ii', $campaignId, $schoolId);
    $stmt->execute();
    $campaign = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$campaign) cnOut(['status' => 0, 'message' => 'La campaña no existe.'], 404);

    $states = [];
    if ((int)$campaign['include_overdue']) $states[] = 'Vencidas';
    if ((int)$campaign['include_partial']) $states[] = 'Parciales';
    if ((int)$campaign['include_upcoming']) $states[] = 'Por vencer';

    $stmt = $conn->prepare(
        "SELECT
            r.*,
            s.name student_name,
            s.id_no dni,
            s.nivel,
            s.grado,
            COALESCE(NULLIF(TRIM(s.seccion),''),'U') seccion,
            e.is_read,
            e.read_at
         FROM student_collection_recipients r
         INNER JOIN student s ON s.id=r.student_id AND s.school_id=r.school_id
         LEFT JOIN student_notification_events e ON e.id=r.notification_event_id
         WHERE r.campaign_id=? AND r.school_id=?
         ORDER BY s.nivel,s.grado,seccion,s.name"
    );
    $stmt->bind_param('ii', $campaignId, $schoolId);
    $stmt->execute();
    $result = $stmt->get_result();
    $recipients = [];
    $counts = ['sent'=>0,'failed'=>0,'no_device'=>0,'pending'=>0,'read'=>0];

    while ($row = $result->fetch_assoc()) {
        $sent = (int)$row['sent_count'];
        $failed = (int)$row['failed_count'];
        $devices = (int)$row['active_device_count'];

        if ($sent > 0) $state = 'sent';
        elseif ($failed > 0) $state = 'failed';
        elseif ($devices <= 0) $state = 'no_device';
        else $state = 'pending';

        $counts[$state]++;
        $isRead = (bool)($row['is_read'] ?? false);
        if ($isRead) $counts['read']++;

        $concepts = json_decode((string)($row['concepts_json'] ?? ''), true);
        if (!is_array($concepts)) $concepts = [];

        $recipients[] = [
            'student_id' => (int)$row['student_id'],
            'student_name' => (string)$row['student_name'],
            'dni' => (string)($row['dni'] ?? ''),
            'level' => (string)($row['nivel'] ?? ''),
            'grade' => (string)($row['grado'] ?? ''),
            'section' => (string)($row['seccion'] ?? 'U'),
            'debt_count' => (int)$row['debt_count'],
            'balance' => (float)$row['balance'],
            'concepts' => array_values(array_unique(array_map('strval', $concepts))),
            'active_devices' => $devices,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'is_read' => $isRead,
            'read_at' => $row['read_at'],
            'state' => $state,
        ];
    }
    $stmt->close();

    cnOut([
        'status' => 1,
        'campaign' => [
            'id' => (int)$campaign['id'],
            'audience' => cnAudienceLabel($campaign),
            'states' => $states,
            'recipient_count' => (int)$campaign['recipient_count'],
            'debt_count' => (int)$campaign['debt_count'],
            'total_balance' => (float)$campaign['total_balance'],
            'students_with_devices' => (int)$campaign['students_with_devices'],
            'push_sent_count' => (int)$campaign['push_sent_count'],
            'push_failed_count' => (int)$campaign['push_failed_count'],
            'created_by_name' => (string)($campaign['created_by_name'] ?? 'Administración'),
            'created_at' => (string)$campaign['created_at'],
        ],
        'counts' => $counts,
        'recipients' => $recipients,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    cnOut(['status' => 0, 'message' => 'Método no permitido.'], 405);
}
if (!hash_equals(
    (string)($_SESSION['csrf_token'] ?? ''),
    (string)($_POST['csrf_token'] ?? '')
)) {
    cnOut(['status' => 0, 'message' => 'La sesión de seguridad venció. Recarga la página.'], 403);
}
if ($action !== 'send') cnOut(['status' => 0, 'message' => 'Acción no válida.'], 400);

set_time_limit(240);

try {
    $filter = cnNormalizeInput($_POST);
    $resolved = cnResolve($conn, $schoolId, $filter);
} catch (Throwable $error) {
    error_log('[collection send resolve] ' . $error->getMessage());
    cnOut(['status' => 0, 'message' => 'No se pudo calcular la cobranza con los filtros seleccionados.'], 500);
}

if ($resolved['recipient_count'] <= 0) {
    cnOut(['status' => 0, 'message' => 'No hay estudiantes con saldo pendiente para los filtros seleccionados.'], 422);
}

$audienceStudentId = $filter['audience_type'] === 'student' ? $filter['student_id'] : null;
$levelValue = in_array($filter['audience_type'], ['level','grade','section'], true) ? $filter['level'] : null;
$gradeValue = in_array($filter['audience_type'], ['grade','section'], true) ? $filter['grade'] : null;
$sectionValue = $filter['audience_type'] === 'section' ? $filter['section'] : null;
$recipientCount = (int)$resolved['recipient_count'];
$debtCount = (int)$resolved['debt_count'];
$totalBalance = (float)$resolved['total_balance'];

$insert = $conn->prepare(
    'INSERT INTO student_collection_campaigns
     (school_id,audience_type,audience_level,audience_grade,audience_section,audience_student_id,
      include_overdue,include_partial,include_upcoming,recipient_count,debt_count,total_balance,created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
if (!$insert) cnOut(['status' => 0, 'message' => 'No se pudo iniciar la campaña de cobranza.'], 500);

$insert->bind_param(
    'issssiiiiiidi',
    $schoolId,
    $filter['audience_type'],
    $levelValue,
    $gradeValue,
    $sectionValue,
    $audienceStudentId,
    $filter['include_overdue'],
    $filter['include_partial'],
    $filter['include_upcoming'],
    $recipientCount,
    $debtCount,
    $totalBalance,
    $userId
);
if (!$insert->execute()) {
    $insert->close();
    cnOut(['status' => 0, 'message' => 'No se pudo guardar la campaña de cobranza.'], 500);
}
$campaignId = (int)$conn->insert_id;
$insert->close();

require_once __DIR__ . '/includes/push_notifications.php';

$studentsWithDevices = 0;
$pushSent = 0;
$pushFailed = 0;

$recipientStmt = $conn->prepare(
    'INSERT INTO student_collection_recipients
     (campaign_id,school_id,student_id,debt_count,balance,debt_ids_json,concepts_json,
      notification_event_id,active_device_count,sent_count,failed_count)
     VALUES (?,?,?,?,?,?,?,NULLIF(?,0),?,?,?)'
);

if (!$recipientStmt) {
    cnOut(['status' => 0, 'message' => 'La campaña fue creada, pero no se pudo preparar su detalle.'], 500);
}

foreach ($resolved['students'] as $student) {
    $studentId = (int)$student['student_id'];
    $studentDebtCount = (int)$student['debt_count'];
    $studentBalance = (float)$student['balance'];
    $debtIdsJson = json_encode($student['debt_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $conceptsJson = json_encode($student['concepts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $eventId = 0;
    $deviceCount = 0;
    $sent = 0;
    $failed = 0;

    try {
        $push = push_send_collection_notification(
            $conn,
            $schoolId,
            $studentId,
            $campaignId,
            $student['debt_ids'],
            $student['concepts'],
            $studentDebtCount,
            $studentBalance
        );
        $eventId = (int)($push['event_id'] ?? 0);
        $deviceCount = (int)($push['devices'] ?? 0);
        $sent = (int)($push['sent'] ?? 0);
        $failed = (int)($push['failed'] ?? 0);
    } catch (Throwable $pushError) {
        $failed = 1;
        error_log('[collection push] ' . $pushError->getMessage());
    }

    if ($deviceCount > 0) $studentsWithDevices++;
    $pushSent += $sent;
    $pushFailed += $failed;

    $recipientStmt->bind_param(
        'iiiidssiiii',
        $campaignId,
        $schoolId,
        $studentId,
        $studentDebtCount,
        $studentBalance,
        $debtIdsJson,
        $conceptsJson,
        $eventId,
        $deviceCount,
        $sent,
        $failed
    );
    if (!$recipientStmt->execute()) {
        error_log('[collection recipient] ' . $recipientStmt->error);
    }
}
$recipientStmt->close();

$update = $conn->prepare(
    'UPDATE student_collection_campaigns
     SET students_with_devices=?,push_sent_count=?,push_failed_count=?
     WHERE id=? AND school_id=?'
);
if ($update) {
    $update->bind_param('iiiii', $studentsWithDevices, $pushSent, $pushFailed, $campaignId, $schoolId);
    $update->execute();
    $update->close();
}

cnOut([
    'status' => 1,
    'message' => 'Notificación de cobranza enviada.',
    'campaign_id' => $campaignId,
    'recipients' => $recipientCount,
    'debts' => $debtCount,
    'total_balance' => $totalBalance,
    'students_with_devices' => $studentsWithDevices,
    'students_without_devices' => max(0, $recipientCount - $studentsWithDevices),
    'push_sent' => $pushSent,
    'push_failed' => $pushFailed,
]);
