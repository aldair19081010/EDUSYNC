<?php
/**
 * Push token registration for EduSync staff accounts (roles 1, 2 and 3).
 * Student registration remains in api/device_notifications.php.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../db_connect.php';

function spd_reply(array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function spd_table(mysqli $db, string $table): bool {
    $q = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    return (bool)($q && $q->num_rows);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') spd_reply(['status'=>'error','message'=>'Método no permitido.'],405);

$userId=(int)($_SESSION['login_id']??0);
$schoolId=(int)($_SESSION['login_school_id']??0);
$role=(int)($_SESSION['login_type']??0);
if ($userId<=0 || $schoolId<=0 || !in_array($role,[1,2,3],true)) {
    spd_reply(['status'=>'error','message'=>'Inicia sesión con una cuenta de personal.'],401);
}
if (!spd_table($conn,'staff_device_tokens')) {
    spd_reply(['status'=>'error','migration_required'=>true,'message'=>'Falta ejecutar sql/staff_push_notifications.sql.'],409);
}
// Revalidate the account/role at every token operation, never trust POST user_id.
$activeCol=$conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$activeRule=($activeCol && $activeCol->num_rows) ? " AND status='Activo'" : '';
$stmt=$conn->prepare('SELECT id,type,teacher_id FROM users WHERE id=? AND school_id=?'.$activeRule.' LIMIT 1');
$stmt->bind_param('ii',$userId,$schoolId);
$stmt->execute();
$account=$stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$account || (int)$account['type']!==$role) {
    spd_reply(['status'=>'error','message'=>'Cuenta no disponible.'],403);
}
if ($role===2) {
    $teacherId=(int)($account['teacher_id']??0);
    $stmt=$conn->prepare("SELECT id FROM teacher WHERE id=? AND school_id=? AND status='Activo' LIMIT 1");
    $stmt->bind_param('ii',$teacherId,$schoolId);
    $stmt->execute();$activeTeacher=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$activeTeacher) spd_reply(['status'=>'error','message'=>'Docente no disponible.'],403);
}


$action=trim((string)($_POST['action']??'register'));
$deviceId=trim((string)($_POST['device_id']??''));
if (!preg_match('/^[A-Za-z0-9._:-]{12,128}$/D',$deviceId)) {
    spd_reply(['status'=>'error','message'=>'Dispositivo inválido.'],422);
}

if ($action==='unregister') {
    $stmt=$conn->prepare('UPDATE staff_device_tokens SET is_active=0,last_seen_at=NOW() WHERE school_id=? AND user_id=? AND device_id=?');
    $stmt->bind_param('iis',$schoolId,$userId,$deviceId);
    $stmt->execute();
    $stmt->close();
    spd_reply(['status'=>'ok','message'=>'Dispositivo desvinculado.']);
}
if ($action!=='register') spd_reply(['status'=>'error','message'=>'Acción no válida.'],400);
$token=trim((string)($_POST['fcm_token']??''));
if (strlen($token)<20||strlen($token)>1024) spd_reply(['status'=>'error','message'=>'Token FCM inválido.'],422);
$platform=strtolower(trim((string)($_POST['platform']??'android')));
if (!in_array($platform,['ios','android'],true)) $platform='android';
$hash=hash('sha256',$token);

$conn->begin_transaction();
try {
    $stmt=$conn->prepare('SELECT id,school_id,user_id,device_id FROM staff_device_tokens WHERE token_hash=? FOR UPDATE');
    $stmt->bind_param('s',$hash);
    $stmt->execute();
    $existing=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing && (int)$existing['school_id']===$schoolId
        && (int)$existing['user_id']===$userId
        && hash_equals((string)$existing['device_id'],$deviceId)) {
        $stmt=$conn->prepare('UPDATE staff_device_tokens SET is_active=1,role_type=?,platform=?,last_seen_at=NOW() WHERE id=?');
        $id=(int)$existing['id'];
        $stmt->bind_param('isi',$role,$platform,$id);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();
    } else {
        // Ownership changes invalidate all earlier device/token associations;
        // this avoids delivering another account's notifications after logout/login.
        $stmt=$conn->prepare('DELETE FROM staff_device_tokens WHERE token_hash=? OR device_id=? OR (school_id=? AND user_id=? AND device_id=?)');
        $stmt->bind_param('ssiis',$hash,$deviceId,$schoolId,$userId,$deviceId);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();

        $stmt=$conn->prepare('INSERT INTO staff_device_tokens (school_id,user_id,role_type,device_id,fcm_token,token_hash,platform,is_active,created_at,last_seen_at) VALUES (?,?,?,?,?,?,?,1,NOW(),NOW())');
        $stmt->bind_param('iiissss',$schoolId,$userId,$role,$deviceId,$token,$hash,$platform);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();
    }
    // A shared phone must not continue receiving student notifications.
    if (spd_table($conn,'student_device_tokens')) {
        $stmt=$conn->prepare('UPDATE student_device_tokens SET is_active=0,last_seen_at=NOW() WHERE token_hash=? OR device_id=?');
        $stmt->bind_param('ss',$hash,$deviceId);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();
    }
    $conn->commit();
    spd_reply(['status'=>'ok','message'=>'Dispositivo vinculado a la cuenta actual.']);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[EduSync staff push device] '.$e->getMessage());
    spd_reply(['status'=>'error','message'=>'No fue posible registrar las notificaciones.'],500);
}
