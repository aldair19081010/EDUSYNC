<?php
ob_start();
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
header('Content-Type: application/json; charset=utf-8');

function no($data, $code = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function nb($stmt, $types, &$params) {
    if (!$types) return;
    $args = [$types];
    foreach ($params as &$v) $args[] = &$v;
    call_user_func_array([$stmt, 'bind_param'], $args);
}
function nt($db, $name) {
    $n = $db->real_escape_string($name);
    $q = $db->query("SHOW TABLES LIKE '$n'");
    return $q && $q->num_rows > 0;
}

$school = (int)($_SESSION['login_school_id'] ?? 0);
$user = (int)($_SESSION['login_id'] ?? 0);
$type = (int)($_SESSION['login_type'] ?? 0);
$teacher = (int)($_SESSION['login_teacher_id'] ?? 0);
$director = (int)($_SESSION['login_is_director'] ?? 0);
$action = $_GET['action'] ?? 'list';

if (!$school || !$user) no(['status' => 0, 'message' => 'No autorizado.'], 403);

$rr = null;
$role = $conn->prepare('SELECT type,is_director,teacher_id FROM users WHERE id=? AND school_id=? LIMIT 1');
if ($role) {
    $role->bind_param('ii', $user, $school);
    $role->execute();
    $rr = $role->get_result()->fetch_assoc();
    $role->close();
}
if (!$rr) no(['status' => 0, 'message' => 'El usuario no pertenece al colegio activo.'], 403);

$type = (int)$rr['type'];
$director = (int)$rr['is_director'];
$teacher = (int)($rr['teacher_id'] ?? 0);
$approver = $type === 1;

foreach (['notification_events', 'notification_user_state', 'notification_audit_log'] as $table) {
    if (!nt($conn, $table)) {
        no(['status' => 0, 'migration_required' => true, 'message' => 'Ejecute manualmente sql/notifications_module_upgrade.sql.'], 409);
    }
}

if (in_array($action, ['mark', 'mark_all', 'archive', 'restore'], true)
    && !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    no(['status' => 0, 'message' => 'La sesión de seguridad venció.'], 403);
}

$roleName = $approver ? 'Administrador' : ($type === 3 ? 'Auxiliar' : ($type === 2 ? 'Docente' : 'Usuario'));
$parts = [];

$parts[] = "SELECT CONCAT('event:',ne.id) COLLATE utf8mb4_general_ci nkey,
    CASE WHEN ne.source_type LIKE 'attendance%' THEN 'Asistencia' WHEN ne.notification_type LIKE '%acad%' THEN 'Académica' ELSE ne.notification_type END COLLATE utf8mb4_general_ci category,
    ne.priority COLLATE utf8mb4_general_ci priority,
    ne.title COLLATE utf8mb4_general_ci title,
    COALESCE(ne.message,'') COLLATE utf8mb4_general_ci message,
    ne.created_at,
    COALESCE(ne.source_type,'') COLLATE utf8mb4_general_ci source_type,
    COALESCE(ne.source_id,0) source_id,
    '' COLLATE utf8mb4_general_ci workflow_status
    FROM notification_events ne
    WHERE ne.school_id=$school
      AND COALESCE(ne.source_type,'')<>'attendance_request_result'
      AND (ne.recipient_user_id=$user OR (ne.recipient_user_id IS NULL AND ne.recipient_role='" . $conn->real_escape_string($roleName) . "'))";

if ($approver && nt($conn, 'attendance_change_requests')) {
    $parts[] = "SELECT CONCAT('attendance_request:',r.id) COLLATE utf8mb4_general_ci nkey,
        'Asistencia' COLLATE utf8mb4_general_ci category,
        IF(r.status='Pendiente','Alta','Normal') COLLATE utf8mb4_general_ci priority,
        CONCAT(u.name,' solicita: ',r.request_type) COLLATE utf8mb4_general_ci title,
        CASE
            WHEN r.status='Pendiente' THEN COALESCE(NULLIF(r.reason,''),'Requiere revisión')
            ELSE CONCAT(
                COALESCE(NULLIF(r.reason,''),'Solicitud de asistencia'),
                IF(rv.name IS NOT NULL, CONCAT(' · Revisada por ',rv.name), ''),
                IF(r.reviewed_at IS NOT NULL, CONCAT(' · ',DATE_FORMAT(r.reviewed_at,'%d/%m/%Y %H:%i')), ''),
                IF(COALESCE(r.review_notes,'')<>'', CONCAT(' · ',r.review_notes), '')
            )
        END COLLATE utf8mb4_general_ci message,
        r.created_at,
        'attendance_request' COLLATE utf8mb4_general_ci source_type,
        r.id source_id,
        r.status COLLATE utf8mb4_general_ci workflow_status
        FROM attendance_change_requests r
        INNER JOIN users u ON u.id=r.requested_by
        LEFT JOIN users rv ON rv.id=r.reviewed_by AND rv.school_id=r.school_id
        WHERE r.school_id=$school";
}

if (nt($conn, 'attendance_change_requests')) {
    $parts[] = "SELECT CONCAT('attendance_result:',r.id) nkey,
        'Asistencia' category,
        IF(r.status='Rechazada','Alta','Normal') priority,
        IF(r.status='Aprobada','Solicitud de asistencia aprobada','Solicitud de asistencia rechazada') title,
        CONCAT(r.request_type,
            IF(rv.name IS NOT NULL,CONCAT(' · Revisada por ',rv.name),''),
            IF(r.reviewed_at IS NOT NULL,CONCAT(' · ',DATE_FORMAT(r.reviewed_at,'%d/%m/%Y %H:%i')),''),
            IF(COALESCE(r.review_notes,'')<>'',CONCAT(' · ',r.review_notes),'')
        ) message,
        COALESCE(r.reviewed_at,r.created_at) created_at,
        'attendance_result' source_type,
        r.id source_id,
        r.status workflow_status
        FROM attendance_change_requests r
        LEFT JOIN users rv ON rv.id=r.reviewed_by AND rv.school_id=r.school_id
        WHERE r.school_id=$school AND r.requested_by=$user AND r.status IN ('Aprobada','Rechazada')";
}

if ($approver && nt($conn, 'evaluations')) {
    $parts[] = "SELECT CONCAT('evaluation:',e.id) COLLATE utf8mb4_general_ci nkey,
        'Académica' COLLATE utf8mb4_general_ci category,
        'Normal' COLLATE utf8mb4_general_ci priority,
        CONCAT('Nueva evaluación: ',e.title) COLLATE utf8mb4_general_ci title,
        CONCAT(COALESCE(ac.name,'Curso'),' · ',COALESCE(t.name,'Docente')) COLLATE utf8mb4_general_ci message,
        e.created_at,
        'evaluation' COLLATE utf8mb4_general_ci source_type,
        e.id source_id,
        '' COLLATE utf8mb4_general_ci workflow_status
        FROM evaluations e
        INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
        LEFT JOIN academic_courses ac ON ac.id=tc.course_id
        LEFT JOIN teacher t ON t.id=tc.teacher_id
        WHERE tc.school_id=$school";
}

if (nt($conn, 'low_grade_notifications')) {
    $teacherFilter = $type === 2 && $teacher > 0 ? "lgn.teacher_id=$teacher" : ($approver ? 'lgn.teacher_id IS NULL' : '1=0');
    $parts[] = "SELECT CONCAT('low_grade:',lgn.id) COLLATE utf8mb4_general_ci nkey,
        'Alerta estudiantil' COLLATE utf8mb4_general_ci category,
        'Alta' COLLATE utf8mb4_general_ci priority,
        CONCAT('Notas bajas: ',s.name) COLLATE utf8mb4_general_ci title,
        CONCAT(lgn.count_low_grades,' calificaciones · Bimestre ',lgn.bimestre) COLLATE utf8mb4_general_ci message,
        lgn.created_at,
        'low_grade' COLLATE utf8mb4_general_ci source_type,
        lgn.id source_id,
        '' COLLATE utf8mb4_general_ci workflow_status
        FROM low_grade_notifications lgn
        INNER JOIN student s ON s.id=lgn.student_id
        WHERE s.school_id=$school AND $teacherFilter";
}

// Las tablas históricas pueden usar utf8 y las nuevas utf8mb4.
$normalized = [];
foreach ($parts as $part) {
    $part = str_replace(' COLLATE utf8mb4_general_ci', '', $part);
    $normalized[] = "SELECT
        CONVERT(x.nkey USING utf8mb4) COLLATE utf8mb4_general_ci nkey,
        CONVERT(x.category USING utf8mb4) COLLATE utf8mb4_general_ci category,
        CONVERT(x.priority USING utf8mb4) COLLATE utf8mb4_general_ci priority,
        CONVERT(x.title USING utf8mb4) COLLATE utf8mb4_general_ci title,
        CONVERT(x.message USING utf8mb4) COLLATE utf8mb4_general_ci message,
        x.created_at,
        CONVERT(x.source_type USING utf8mb4) COLLATE utf8mb4_general_ci source_type,
        x.source_id,
        CONVERT(x.workflow_status USING utf8mb4) COLLATE utf8mb4_general_ci workflow_status
        FROM ($part) x";
}

$union = implode(' UNION ALL ', $normalized);
$tab = trim($_REQUEST['tab'] ?? 'pending');
$category = trim($_REQUEST['category'] ?? '');
$priority = trim($_REQUEST['priority'] ?? '');
$state = trim($_REQUEST['state'] ?? '');
$from = trim($_REQUEST['date_from'] ?? '');
$to = trim($_REQUEST['date_to'] ?? '');
$searchInput = $_REQUEST['search'] ?? '';
$search = trim(is_array($searchInput) ? ($searchInput['value'] ?? '') : $searchInput);
$archived = $tab === 'archived' ? 1 : 0;

$effectiveRead = "CASE WHEN n.source_type='attendance_request' AND n.workflow_status IN ('Aprobada','Rechazada') THEN 1 ELSE COALESCE(ns.is_read,0) END";
$where = ['COALESCE(ns.is_archived,0)=' . $archived];
$types = '';
$params = [];
$add = function ($sql, $t, $v) use (&$where, &$types, &$params) {
    if ($v === '') return;
    $where[] = $sql;
    $types .= $t;
    $params[] = $v;
};

if ($tab === 'pending') {
    // Una autorización ya resuelta deja de ser una tarea pendiente para TODOS los administradores,
    // aunque alguno de ellos nunca haya abierto su notificación individual.
    $where[] = "((n.source_type='attendance_request' AND n.workflow_status='Pendiente') OR (n.source_type<>'attendance_request' AND ($effectiveRead)=0))";
} elseif (in_array($tab, ['attendance', 'academic', 'student_alerts'], true)) {
    $category = [
        'attendance' => 'Asistencia',
        'academic' => 'Académica',
        'student_alerts' => 'Alerta estudiantil'
    ][$tab];
}

$add('n.category=?', 's', $category);
$add('n.priority=?', 's', $priority);
if ($state === 'unread') $where[] = "($effectiveRead)=0";
elseif ($state === 'read') $where[] = "($effectiveRead)=1";
$add('DATE(n.created_at)>=?', 's', $from);
$add('DATE(n.created_at)<=?', 's', $to);
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(n.title LIKE ? OR n.message LIKE ?)';
    $types .= 'ss';
    array_push($params, $like, $like);
}
$whereSql = implode(' AND ', $where);
$base = "FROM ($union) n LEFT JOIN notification_user_state ns ON ns.school_id=$school AND ns.user_id=$user AND ns.notification_key=n.nkey WHERE $whereSql";

if ($action === 'mark' || $action === 'archive' || $action === 'restore') {
    $key = trim($_POST['key'] ?? '');
    if ($key === '') no(['status' => 0, 'message' => 'Notificación inválida.']);
    $read = $action === 'mark' ? 1 : null;
    $archive = $action === 'archive' ? 1 : ($action === 'restore' ? 0 : null);
    $s = $conn->prepare("INSERT INTO notification_user_state(school_id,user_id,notification_key,is_read,is_archived,read_at,archived_at)
        VALUES(?,?,?,IFNULL(?,0),IFNULL(?,0),IF(?=1,NOW(),NULL),IF(?=1,NOW(),NULL))
        ON DUPLICATE KEY UPDATE
            is_read=IFNULL(?,is_read),is_archived=IFNULL(?,is_archived),
            read_at=IF(?=1,NOW(),read_at),
            archived_at=CASE WHEN ?=1 THEN NOW() WHEN ?=0 THEN NULL ELSE archived_at END");
    $s->bind_param('iisiiiiiiiii', $school, $user, $key, $read, $archive, $read, $archive, $read, $archive, $read, $archive, $archive);
    $s->execute();
    $s->close();

    $audit = $conn->prepare('INSERT INTO notification_audit_log(school_id,user_id,notification_key,action,ip_address) VALUES(?,?,?,?,?)');
    $act = $action;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $audit->bind_param('iisss', $school, $user, $key, $act, $ip);
    $audit->execute();
    $audit->close();

    no(['status' => 1, 'message' => $action === 'archive' ? 'Notificación archivada.' : ($action === 'restore' ? 'Notificación restaurada.' : 'Notificación marcada como leída.')]);
}

if ($action === 'mark_all') {
    $q = $conn->prepare("SELECT n.nkey $base LIMIT 1000");
    nb($q, $types, $params);
    $q->execute();
    $res = $q->get_result();
    $count = 0;
    $s = $conn->prepare("INSERT INTO notification_user_state(school_id,user_id,notification_key,is_read,read_at) VALUES(?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE is_read=1,read_at=NOW()");
    while ($r = $res->fetch_assoc()) {
        $key = $r['nkey'];
        $s->bind_param('iis', $school, $user, $key);
        $s->execute();
        $count++;
    }
    $s->close();
    $q->close();
    no(['status' => 1, 'message' => "Se marcaron $count notificaciones como leídas."]);
}

$draw = (int)($_GET['draw'] ?? 1);
$start = max(0, (int)($_GET['start'] ?? 0));
$length = min(100, max(10, (int)($_GET['length'] ?? 20)));
try {
    $countStmt = $conn->prepare("SELECT COUNT(*) total $base");
    if (!$countStmt) throw new Exception($conn->error);
    nb($countStmt, $types, $params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
} catch (Throwable $e) {
    no(['status' => 0, 'message' => 'No se pudo consultar la bandeja de notificaciones.', 'detail' => $e->getMessage()], 500);
}

$dataStmt = $conn->prepare("SELECT n.*,($effectiveRead) is_read,COALESCE(ns.is_archived,0) is_archived
    $base
    ORDER BY (n.source_type='attendance_request' AND n.workflow_status='Pendiente') DESC,($effectiveRead),n.created_at DESC
    LIMIT $start,$length");
nb($dataStmt, $types, $params);
$dataStmt->execute();
$res = $dataStmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) {
    $r['open_mode'] = 'modal';
    $r['open_url'] = $r['source_type'] === 'attendance_request'
        ? 'review_attendance_request.php?id=' . $r['source_id']
        : ($r['source_type'] === 'evaluation'
            ? 'manage_evaluation_grades.php?evaluation_id=' . $r['source_id'] . '&from=notifications'
            : 'notification_detail.php?type=' . rawurlencode($r['source_type']) . '&id=' . (int)$r['source_id']);
    $rows[] = $r;
}
$dataStmt->close();

$summaryQuery = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN n.source_type='attendance_request' AND n.workflow_status IN ('Aprobada','Rechazada') THEN 0 ELSE COALESCE(ns.is_read,0)=0 END) AS unread,
    SUM(n.priority='Alta') AS `high_priority`,
    SUM(n.source_type='attendance_request' AND n.workflow_status='Pendiente') AS `requires_action`
    FROM ($union) n
    LEFT JOIN notification_user_state ns ON ns.school_id=$school AND ns.user_id=$user AND ns.notification_key=n.nkey
    WHERE COALESCE(ns.is_archived,0)=0");
$summary = $summaryQuery ? $summaryQuery->fetch_assoc() : [];
no(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $total, 'data' => $rows, 'summary' => array_map('intval', $summary ?: [])]);
