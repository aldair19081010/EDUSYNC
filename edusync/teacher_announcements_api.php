<?php
/**
 * Comunicados a DOCENTES, separado del contrato legacy de estudiantes.
 * La bandeja utiliza notification_events (recipients por usuario);
 * el contenido completo vive en teacher_announcements.
 */
ini_set('display_errors','0');
ob_start();
date_default_timezone_set('America/Lima');
require_once __DIR__.'/session_config.php';
require_once __DIR__.'/includes/session_check.php';
require_login_modal();
require_once __DIR__.'/db_connect.php';
$conn->query("SET time_zone='-05:00'");
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function ta_reply(array $data,int $http=200):void {
    while(ob_get_level())ob_end_clean();
    http_response_code($http);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function ta_exists(mysqli $db,string $table):bool {
    $safe=$db->real_escape_string($table);
    $q=$db->query("SHOW TABLES LIKE '$safe'");
    return (bool)($q && $q->num_rows>0);
}
function ta_users(mysqli $db,int $school,array $ids=[]):array {
    $sql="SELECT u.id,COALESCE(NULLIF(TRIM(u.name),''),t.name) name
        FROM users u
        INNER JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id
          AND t.status='Activo'
        WHERE u.school_id=? AND u.type=2";
    $hasStatus=$db->query("SHOW COLUMNS FROM users LIKE 'status'");
    if($hasStatus&&$hasStatus->num_rows>0)$sql.=" AND u.status='Activo'";
    if($ids)$sql.=" AND u.id IN (".implode(',',array_map('intval',$ids)).")";
    $sql.=" ORDER BY name,u.id";
    $s=$db->prepare($sql);
    if(!$s)throw new RuntimeException($db->error);
    $s->bind_param('i',$school);$s->execute();
    $r=$s->get_result();$rows=[];
    while($row=$r->fetch_assoc())$rows[]=['id'=>(int)$row['id'],'name'=>$row['name']];
    $s->close();
    return $rows;
}
function ta_announcement(mysqli $db,int $id,int $school):?array {
    $s=$db->prepare("SELECT a.*,COALESCE(u.name,'Administración') author
                     FROM teacher_announcements a
                     LEFT JOIN users u ON u.id=a.created_by AND u.school_id=a.school_id
                     WHERE a.id=? AND a.school_id=? LIMIT 1");
    $s->bind_param('ii',$id,$school);$s->execute();
    $row=$s->get_result()->fetch_assoc();$s->close();
    return $row?:null;
}
$school=(int)($_SESSION['login_school_id']??0);
$user=(int)($_SESSION['login_id']??0);
if(!$school||!$user)ta_reply(['status'=>0,'message'=>'Sesión docente o administrativa no válida.'],401);
$stmt=$conn->prepare('SELECT type FROM users WHERE id=? AND school_id=? LIMIT 1');
$stmt->bind_param('ii',$user,$school);$stmt->execute();$account=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$account)ta_reply(['status'=>0,'message'=>'El usuario no pertenece al colegio.'],403);
$role=(int)$account['type'];$isAdmin=$role===1;
$action=(string)($_GET['action']??$_POST['action']??'list');

if(!ta_exists($conn,'teacher_announcements')||!ta_exists($conn,'teacher_announcement_recipients')||
    !ta_exists($conn,'notification_events')) {
    ta_reply(['status'=>0,'migration_required'=>true,'message'=>
        'Ejecuta sql/teacher_announcements_v1.sql y comprueba el módulo de notificaciones.'],409);
}
try {
    // El docente sólo ve un comunicado si tiene un evento de notificación
    // vinculado a su cuenta y a su colegio (nunca a partir del ID solamente).
    if($action==='view') {
        if($role!==2)ta_reply(['status'=>0,'message'=>'Acceso exclusivo para docentes.'],403);
        $eventId=(int)($_GET['event_id']??0);
        if($eventId<=0)ta_reply(['status'=>0,'message'=>'Identificador inválido.'],422);
        $s=$conn->prepare("SELECT a.id,a.title,a.content,a.created_at,COALESCE(u.name,'Administración') author
             FROM notification_events ne
             INNER JOIN teacher_announcement_recipients ar
               ON ar.notification_event_id=ne.id AND ar.school_id=ne.school_id
               AND ar.recipient_user_id=ne.recipient_user_id
             INNER JOIN teacher_announcements a
               ON a.id=ar.announcement_id AND a.school_id=ar.school_id
             LEFT JOIN users u ON u.id=a.created_by AND u.school_id=a.school_id
             WHERE ne.id=? AND ne.school_id=? AND ne.recipient_user_id=?
               AND ne.source_type='teacher_announcement'
               AND ne.source_id=a.id LIMIT 1");
        $s->bind_param('iii',$eventId,$school,$user);$s->execute();
        $row=$s->get_result()->fetch_assoc();$s->close();
        if(!$row)ta_reply(['status'=>0,'message'=>'Comunicado no disponible para esta cuenta.'],404);
        ta_reply(['status'=>1,'announcement'=>$row]);
    }
    if(!$isAdmin)ta_reply(['status'=>0,'message'=>'Solo Administración puede gestionar comunicados.'],403);

    if($action==='options') {
        ta_reply(['status'=>1,'teachers'=>ta_users($conn,$school)]);
    }
    if($action==='summary') {
        $s=$conn->prepare("SELECT COUNT(*) total,
          COALESCE(SUM(created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')),0) month_total,
          COALESCE(SUM(IF(created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01'),recipient_count,0)),0) month_recipients
          FROM teacher_announcements WHERE school_id=?");
        $s->bind_param('i',$school);$s->execute();
        $summary=$s->get_result()->fetch_assoc();$s->close();
        $summary['month_push_sent']=0;
        $summary['month_push_failed']=0;
        if(ta_exists($conn,'web_push_delivery')){
            $p=$conn->prepare("SELECT
                COUNT(DISTINCT CASE WHEN d.status='delivered' THEN ar.id END) sent,
                COUNT(DISTINCT CASE WHEN d.status='failed' THEN ar.id END) failed
                FROM teacher_announcements a
                INNER JOIN teacher_announcement_recipients ar
                    ON ar.announcement_id=a.id AND ar.school_id=a.school_id
                INNER JOIN web_push_delivery d
                    ON d.event_id=ar.notification_event_id
                    AND d.school_id=ar.school_id AND d.event_kind='staff'
                WHERE a.school_id=? AND a.created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')");
            if($p){
                $p->bind_param('i',$school);$p->execute();
                $counts=$p->get_result()->fetch_assoc()?:[];$p->close();
                $summary['month_push_sent']=(int)($counts['sent']??0);
                $summary['month_push_failed']=(int)($counts['failed']??0);
            }
        }
        ta_reply(['status'=>1,'summary'=>$summary]);
    }
    if($action==='list') {
        $statsColumns=ta_exists($conn,'web_push_delivery')
          ? "(SELECT COUNT(DISTINCT ar.recipient_user_id)
              FROM teacher_announcement_recipients ar
              INNER JOIN web_push_delivery d
                ON d.event_id=ar.notification_event_id
                AND d.school_id=ar.school_id AND d.event_kind='staff'
                AND d.status='delivered'
              WHERE ar.school_id=a.school_id AND ar.announcement_id=a.id) push_sent_count"
          : "0 push_sent_count";
        $s=$conn->prepare("SELECT a.id,a.title,a.content,a.audience_type,a.recipient_count,a.created_at,
                COALESCE(u.name,'Administración') created_by_name,$statsColumns
                FROM teacher_announcements a
                LEFT JOIN users u ON u.id=a.created_by AND u.school_id=a.school_id
                WHERE a.school_id=? ORDER BY a.id DESC LIMIT 100");
        $s->bind_param('i',$school);$s->execute();$r=$s->get_result();$items=[];
        while($row=$r->fetch_assoc()) {
            $row['audience']=$row['audience_type']==='all'?'Todos los docentes':'Selección de docentes';
            $row['audience_kind']='teachers';
            $items[]=$row;
        }
        $s->close();ta_reply(['status'=>1,'items'=>$items]);
    }
    if($action==='detail') {
        $id=(int)($_GET['id']??0);
        $announcement=ta_announcement($conn,$id,$school);
        if(!$announcement)ta_reply(['status'=>0,'message'=>'No se encontró el comunicado docente.'],404);
        $hasPush=ta_exists($conn,'web_push_delivery');
        $hasSubscriptions=ta_exists($conn,'web_push_subscriptions');
        $devicesSql=$hasSubscriptions
            ? "(SELECT COUNT(*) FROM web_push_subscriptions ws
                  WHERE ws.school_id=ar.school_id
                    AND ws.account_kind='staff'
                    AND ws.account_id=ar.recipient_user_id AND ws.is_active=1)"
            : "0";
        $pushJoin=$hasPush ? "LEFT JOIN (
              SELECT d.event_id,
               SUM(d.status='delivered') sent,
               SUM(d.status='failed') failed,
               SUM(d.status='retry') retrying
              FROM web_push_delivery d
              INNER JOIN teacher_announcement_recipients match_ar
                ON match_ar.notification_event_id=d.event_id
                AND match_ar.school_id=d.school_id AND match_ar.announcement_id=?
              WHERE d.event_kind='staff' AND d.school_id=?
              GROUP BY d.event_id
            ) p ON p.event_id=ar.notification_event_id" : "";
        $sentCols=$hasPush
            ? "COALESCE(p.sent,0) sent,COALESCE(p.failed,0) failed,
               COALESCE(p.retrying,0) retrying"
            : "0 sent,0 failed,0 retrying";
        $sql="SELECT ar.recipient_user_id,ar.notification_event_id,
            COALESCE(u.name,t.name,'Docente') teacher_name,
            COALESCE(ns.is_read,0) is_read,
            $sentCols,$devicesSql device_count
            FROM teacher_announcement_recipients ar
            INNER JOIN users u ON u.id=ar.recipient_user_id AND u.school_id=ar.school_id
            LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=ar.school_id
            LEFT JOIN notification_user_state ns ON ns.school_id=ar.school_id
               AND ns.user_id=ar.recipient_user_id
               AND ns.notification_key=CONCAT('event:',ar.notification_event_id)
            $pushJoin
            WHERE ar.school_id=? AND ar.announcement_id=?
            ORDER BY teacher_name";
        $s=$conn->prepare($sql);
        if($s){
            if($hasPush)$s->bind_param('iiii',$id,$school,$school,$id);
            else $s->bind_param('ii',$school,$id);
        }
        if(!$s)throw new RuntimeException('No se pudo preparar el historial.');
        $s->execute();$rows=$s->get_result();$delivery=[];
        $counts=['sent'=>0,'failed'=>0,'no_device'=>0,'pending'=>0,'read'=>0];
        while($d=$rows->fetch_assoc()){
            $d['state']=(int)$d['sent']>0?'sent':
                ((int)$d['failed']>0?'failed':((int)$d['device_count']>0?'pending':'no_device'));
            $delivery[]=$d;
            $counts[$d['state']]++;
            if((int)$d['is_read']===1)$counts['read']++;
        }
        $s->close();
        ta_reply(['status'=>1,'announcement'=>[
            'id'=>(int)$announcement['id'],'title'=>$announcement['title'],
            'content'=>$announcement['content'],
            'audience'=>$announcement['audience_type']==='all'?'Todos los docentes':'Selección de docentes',
            'recipient_count'=>(int)$announcement['recipient_count'],
            'created_by_name'=>$announcement['author'],'created_at'=>$announcement['created_at']
        ],'delivery'=>$delivery,'counts'=>$counts]);
    }
    if($action!=='send' || $_SERVER['REQUEST_METHOD']!=='POST')
        ta_reply(['status'=>0,'message'=>'Acción o método inválido.'],405);
    if(!hash_equals((string)($_SESSION['csrf_token']??''),(string)($_POST['csrf_token']??'')))
        ta_reply(['status'=>0,'message'=>'Sesión de seguridad vencida. Recarga EduSync.'],403);

    $title=trim((string)($_POST['title']??''));
    $content=trim((string)($_POST['content']??''));
    $audience=(string)($_POST['audience_type']??'');
    $key=(string)($_POST['request_key']??'');
    if(mb_strlen($title,'UTF-8')<3||mb_strlen($title,'UTF-8')>180||
       mb_strlen($content,'UTF-8')<3||mb_strlen($content,'UTF-8')>5000)
        ta_reply(['status'=>0,'message'=>'Título (3–180) o contenido (3–5000) inválidos.'],422);
    if(!in_array($audience,['all','selected'],true))
        ta_reply(['status'=>0,'message'=>'Selecciona todos o docentes específicos.'],422);
    if(!preg_match('/^[a-f0-9]{32}$/D',$key))
        ta_reply(['status'=>0,'message'=>'Solicitud inválida. Recarga el formulario.'],422);
    $ids=[];
    if($audience==='selected'){
        $raw=$_POST['teacher_ids']??[];
        if(!is_array($raw)||!$raw||count($raw)>500)
            ta_reply(['status'=>0,'message'=>'Selecciona docentes válidos (máximo 500).'],422);
        foreach($raw as $value){
            if(!ctype_digit((string)$value)||(int)$value<=0)
                ta_reply(['status'=>0,'message'=>'Selección de docentes no válida.'],422);
            $ids[]=(int)$value;
        }
        $ids=array_values(array_unique($ids));
    }
    $targets=ta_users($conn,$school,$ids);
    if(!$targets||($audience==='selected'&&count($targets)!==count($ids)))
        ta_reply(['status'=>0,'message'=>'Uno o más docentes no están activos en este colegio.'],422);
    if(count($targets)>500)
        ta_reply(['status'=>0,'message'=>'Hay demasiados destinatarios para un solo envío.'],422);

    // Bloqueo antirreintento: un clic repetido o respuesta perdida no crea
    // otro lote de notificaciones para el mismo anuncio.
    $conn->begin_transaction();
    $events=[];
    try {
        $stmt=$conn->prepare("INSERT INTO teacher_announcements
            (school_id,title,content,audience_type,recipient_count,request_key,created_by)
            VALUES(?,?,?,?,?,?,?)");
        $count=count($targets);
        $stmt->bind_param('isssisi',$school,$title,$content,$audience,$count,$key,$user);
        if(!$stmt->execute())throw new RuntimeException($stmt->error);
        $id=(int)$stmt->insert_id;$stmt->close();
        $short=mb_substr(preg_replace('/\s+/u',' ',$content),0,450,'UTF-8');
        $eventType='teacher_announcement';$notificationType='Comunicado';$priority='Normal';
        $n=$conn->prepare("INSERT INTO notification_events
            (school_id,recipient_user_id,notification_type,priority,title,message,source_type,source_id,created_by)
            VALUES(?,?,?,?,?,?,?,?,?)");
        $r=$conn->prepare("INSERT INTO teacher_announcement_recipients
            (school_id,announcement_id,recipient_user_id,notification_event_id)
            VALUES(?,?,?,?)");
        if(!$n||!$r)throw new RuntimeException('No se pudo preparar el envío.');
        foreach($targets as $target){
            $uid=(int)$target['id'];
            $n->bind_param('iisssssii',$school,$uid,$notificationType,$priority,$title,$short,$eventType,$id,$user);
            if(!$n->execute())throw new RuntimeException($n->error);
            $eventId=(int)$n->insert_id;
            $r->bind_param('iiii',$school,$id,$uid,$eventId);
            if(!$r->execute())throw new RuntimeException($r->error);
            $events[]=$eventId;
        }
        $n->close();$r->close();
        $conn->commit();
    }catch(Throwable $e){
        $conn->rollback();
        if((int)$conn->errno===1062 || strpos($e->getMessage(),'Duplicate entry')!==false)
            ta_reply(['status'=>0,'message'=>'Este comunicado ya fue registrado. Actualiza el historial antes de repetirlo.'],409);
        throw $e;
    }
    // El aviso ya es visible en bandeja incluso si ningún teléfono está
    // conectado. Sólo cola inmediata para lotes pequeños; el cron existente
    // continúa atendiendo el resto sin saturar el request PHP.
    require_once __DIR__.'/includes/web_push_staff_events.php';
    if(count($events)<=15) {
        foreach($events as $eventId)edwp_queue_staff_event($conn,$school,'staff',$eventId);
    }
    ta_reply(['status'=>1,'message'=>'Comunicado registrado para docentes.',
        'announcement_id'=>$id,'recipients'=>$count,'events_created'=>count($events),
        'push_queued'=>true]);
} catch(Throwable $e) {
    error_log('[EduSync teacher announcements] '.$e->getMessage());
    ta_reply(['status'=>0,'message'=>'No se pudo completar la solicitud. Comprueba los registros del servidor.'],500);
}
