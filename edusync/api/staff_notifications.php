<?php
/**
 * Mobile inbox for authenticated admin / teacher / auxiliary users.
 * Shares notification_events and notification_user_state with the web inbox.
 */
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD']??'')==='OPTIONS') { http_response_code(204); exit; }
require_once __DIR__.'/../session_config.php';
require_once __DIR__.'/../db_connect.php';

function sn_reply(array $data,int $code=200): void {
    http_response_code($code);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function sn_exists(mysqli $db,string $name): bool {
    $r=$db->query("SHOW TABLES LIKE '".$db->real_escape_string($name)."'");
    return (bool)($r&&$r->num_rows);
}
$user=(int)($_SESSION['login_id']??0);
$school=(int)($_SESSION['login_school_id']??0);
$role=(int)($_SESSION['login_type']??0);
if ($user<=0||$school<=0||!in_array($role,[1,2,3],true))
    sn_reply(['status'=>'error','message'=>'Sesión de personal no válida.'],401);

$roleNames=[1=>'Administrador',2=>'Docente',3=>'Auxiliar'];
$roleName=$roleNames[$role];
if (!sn_exists($conn,'notification_events')||!sn_exists($conn,'notification_user_state'))
    sn_reply(['status'=>'error','migration_required'=>true,'message'=>'Falta ejecutar sql/notifications_module_upgrade.sql.'],409);
$stmt=$conn->prepare('SELECT id,type FROM users WHERE id=? AND school_id=? LIMIT 1');
$stmt->bind_param('ii',$user,$school);
$stmt->execute();$account=$stmt->get_result()->fetch_assoc();$stmt->close();
if (!$account||(int)$account['type']!==$role)
    sn_reply(['status'=>'error','message'=>'Cuenta no disponible.'],403);

$action=trim((string)($_REQUEST['action']??'list'));
if ($action==='mark_read') {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST')
        sn_reply(['status'=>'error','message'=>'Método no permitido.'],405);
    $eventId=(int)($_POST['event_id']??0);
    if ($eventId<=0) sn_reply(['status'=>'error','message'=>'Aviso inválido.'],422);
    $stmt=$conn->prepare("SELECT id FROM notification_events WHERE id=? AND school_id=? AND (recipient_user_id=? OR (recipient_user_id IS NULL AND recipient_role=?)) LIMIT 1");
    $stmt->bind_param('iiis',$eventId,$school,$user,$roleName);
    $stmt->execute();$matched=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$matched) sn_reply(['status'=>'error','message'=>'Aviso no disponible para tu cuenta.'],404);
    $key='event:'.$eventId;
    $stmt=$conn->prepare("INSERT INTO notification_user_state(school_id,user_id,notification_key,is_read,read_at) VALUES(?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE is_read=1,read_at=NOW()");
    $stmt->bind_param('iis',$school,$user,$key);
    if (!$stmt->execute()) sn_reply(['status'=>'error','message'=>'No se pudo marcar el aviso.'],500);
    $stmt->close();
    sn_reply(['status'=>'ok']);
}
if ($action!=='list') sn_reply(['status'=>'error','message'=>'Acción no válida.'],400);
if (($_SERVER['REQUEST_METHOD']??'')!=='GET')
    sn_reply(['status'=>'error','message'=>'Método no permitido.'],405);

$limit=max(1,min(100,(int)($_GET['limit']??50)));
$after=max(0,(int)($_GET['before_id']??0));
$filter="ne.school_id=? AND (ne.recipient_user_id=? OR (ne.recipient_user_id IS NULL AND ne.recipient_role=?))";
if ($after>0) $filter.=" AND ne.id<?";
$sql="SELECT ne.id,ne.notification_type,ne.priority,ne.title,COALESCE(ne.message,'') body,
        COALESCE(ne.source_type,'') source_type,COALESCE(ne.source_id,0) source_id,
        ne.created_at,COALESCE(ns.is_read,0) is_read
      FROM notification_events ne
      LEFT JOIN notification_user_state ns ON ns.school_id=ne.school_id AND ns.user_id=?
          AND ns.notification_key=CONCAT('event:',ne.id)
      WHERE $filter AND COALESCE(ns.is_archived,0)=0
      ORDER BY ne.id DESC LIMIT ?";
$stmt=$conn->prepare($sql);
if ($after>0) $stmt->bind_param('iiisii',$user,$school,$user,$roleName,$after,$limit);
else $stmt->bind_param('iiisi',$user,$school,$user,$roleName,$limit);
if (!$stmt->execute()) sn_reply(['status'=>'error','message'=>'No se pudieron consultar los avisos.'],500);
$rows=[];$q=$stmt->get_result();
while($x=$q->fetch_assoc()) {
    $x['id']=(int)$x['id'];
    $x['source_id']=(int)$x['source_id'];
    $x['is_read']=(int)$x['is_read'];
    $rows[]=$x;
}
$stmt->close();
$cnt="SELECT COUNT(*) count FROM notification_events ne
  LEFT JOIN notification_user_state ns ON ns.school_id=ne.school_id AND ns.user_id=? AND ns.notification_key=CONCAT('event:',ne.id)
  WHERE ne.school_id=? AND (ne.recipient_user_id=? OR (ne.recipient_user_id IS NULL AND ne.recipient_role=?))
  AND COALESCE(ns.is_read,0)=0 AND COALESCE(ns.is_archived,0)=0";
$stmt=$conn->prepare($cnt);
$stmt->bind_param('iiis',$user,$school,$user,$roleName);
$stmt->execute();$result=$stmt->get_result()->fetch_assoc();$stmt->close();
sn_reply(['status'=>'ok','items'=>$rows,'unread'=>(int)($result['count']??0)]);
