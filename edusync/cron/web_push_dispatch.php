<?php
/**
 * EduSync FCM Web Push: ejecutar desde cron PHP CLI (p. ej., cada minuto).
 * Recoge eventos persistentes existentes; NO modifica la app Android.
 * Requiere sql/web_push_fcm_v1.sql y credenciales FCM de servicio.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ini_set('display_errors','0');
date_default_timezone_set('America/Lima');
require_once __DIR__.'/../db_connect.php';
require_once __DIR__.'/../includes/web_push_fcm_sender.php';
$conn->query("SET time_zone = '-05:00'");

foreach (['web_push_subscriptions','web_push_delivery'] as $table) {
    if (!push_table_exists($conn,$table)) {
        fwrite(STDERR,"Falta la migración sql/web_push_fcm_v1.sql.\n");
        exit(1);
    }
}
$hasStudent=push_table_exists($conn,'student_notification_events');
$hasStaff=push_table_exists($conn,'notification_events');
if (!$hasStudent && !$hasStaff) {
    fwrite(STDERR,"No existen las tablas de notificaciones de EduSync.\n");
    exit(1);
}
$lockQuery=$conn->query("SELECT GET_LOCK('edusync_web_push_fcm_v1',0) AS acquired");
$lock=$lockQuery ? $lockQuery->fetch_assoc() : null;
if ((int)($lock['acquired']??0)!==1) {
    echo "Ya hay un despachador de notificaciones en curso.\n";
    exit(0);
}
try {
    $parts=[];
    $delivery='LEFT JOIN web_push_delivery d ON d.subscription_id=s.id AND d.event_kind=';
    $pending="(d.id IS NULL OR (d.status='retry' AND d.attempts<3 AND (d.next_retry_at IS NULL OR d.next_retry_at<=NOW())))";
    $active="s.is_active=1 AND s.last_seen_at>=DATE_SUB(NOW(),INTERVAL 45 DAY)";
    if ($hasStudent) {
        $parts[]="SELECT 'student' kind,e.id event_id,e.school_id,s.id subscription_id,s.fcm_token,
                  e.title,e.body,e.screen,e.created_at created,
                  COALESCE(d.attempts,0) attempts
                  FROM student_notification_events e
                  JOIN web_push_subscriptions s ON s.account_kind='student'
                     AND s.school_id=e.school_id AND s.account_id=e.student_id
                     AND e.created_at>=s.subscribed_at
                  JOIN student st ON st.id=s.account_id AND st.school_id=s.school_id
                  {$delivery}'student' AND d.event_id=e.id
                  WHERE $active AND $pending
                     AND e.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)
                     AND (e.expires_at IS NULL OR e.expires_at>NOW())";
    }
    if ($hasStaff) {
        $userStatus=push_column_exists($conn,'users','status') ? " AND u.status='Activo'" : '';
        $parts[]="SELECT 'staff' kind,e.id event_id,e.school_id,s.id subscription_id,s.fcm_token,
                  e.title,COALESCE(e.message,'') body,COALESCE(e.source_type,'') screen,e.created_at created,
                  COALESCE(d.attempts,0) attempts
                  FROM notification_events e
                  JOIN web_push_subscriptions s ON s.account_kind='staff'
                     AND s.school_id=e.school_id
                     AND e.created_at>=s.subscribed_at
                  JOIN users u ON u.id=s.account_id AND u.school_id=s.school_id AND u.type=s.role_type $userStatus
                  LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id
                  {$delivery}'staff' AND d.event_id=e.id
                  WHERE $active AND $pending
                    AND (s.role_type<>2 OR t.status='Activo')
                    AND e.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)
                    AND COALESCE(e.source_type,'')<>'attendance_request_result'
                    AND (e.recipient_user_id=s.account_id
                         OR (e.recipient_user_id IS NULL AND e.recipient_role=CASE s.role_type
                            WHEN 1 THEN 'Administrador' WHEN 2 THEN 'Docente'
                            WHEN 3 THEN 'Auxiliar' ELSE '' END))";
    }
    $sql="SELECT * FROM (".implode(' UNION ALL ',$parts).") events ORDER BY created ASC LIMIT 75";
    $result=$conn->query($sql);
    if (!$result) throw new RuntimeException('Web push query: '.$conn->error);
    $sent=0;$failed=0;
    while ($event=$result->fetch_assoc()) {
        $kind=$event['kind'];
        $eventId=(int)$event['event_id'];
        $school=(int)$event['school_id'];
        $subscription=(int)$event['subscription_id'];
        $screen=(string)$event['screen'];
        $url='index.php?page=notifications&push_event_id='.$eventId;
        if ($kind==='student') {
            $routes=[
                'attendance'=>'student_attendances',
                'payments'=>'student_payments',
                'debts'=>'student_debts',
                'grades'=>'student_grades',
                'announcements'=>'notifications',
            ];
            $url=($screen==='announcements')
                ? 'index.php?page=notifications&notification_id='.$eventId
                : 'index.php?page='.($routes[$screen]??'notifications');
        }
        $answer=edwp_send_message(
            (string)$event['fcm_token'],
            (string)$event['title'],(string)$event['body'],$url,
            'edusync-'.$kind.'-'.$school.'-'.$eventId
        );
        $ok=$answer['ok'];
        $message=mb_substr((string)$answer['message'],0,500,'UTF-8');
        $code=(int)$answer['code'];
        $status=$ok ? 'delivered' : ((int)$event['attempts'] >= 2 ? 'failed' : 'retry');
        $next=$ok||$status==='failed' ? null : date('Y-m-d H:i:s',time()+300*((int)$event['attempts']+1));
        $flag=$ok?1:0;
        $stmt=$conn->prepare("INSERT INTO web_push_delivery(school_id,subscription_id,event_kind,event_id,status,attempts,provider_code,provider_message,next_retry_at,delivered_at)
           VALUES(?,?,?,?,?,1,?,?,?,IF(?=1,NOW(),NULL))
           ON DUPLICATE KEY UPDATE status=VALUES(status),attempts=attempts+1,
             provider_code=VALUES(provider_code),provider_message=VALUES(provider_message),
             next_retry_at=VALUES(next_retry_at),delivered_at=VALUES(delivered_at)");
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('iisisissi',$school,$subscription,$kind,$eventId,$status,$code,$message,$next,$flag);
        if (!$stmt->execute()) error_log('[EduSync web push delivery] '.$stmt->error);
        $stmt->close();
        if ($ok) $sent++; else $failed++;
        $up=strtoupper($message);
        if (!$ok && ($code===404 || strpos($up,'UNREGISTERED')!==false ||
             strpos($up,'NOT_FOUND')!==false)) {
            $disable=$conn->prepare('UPDATE web_push_subscriptions SET is_active=0 WHERE id=? AND school_id=?');
            $disable->bind_param('ii',$subscription,$school);
            $disable->execute();$disable->close();
        }
    }
    echo "Web Push: $sent enviados, $failed fallidos.\n";
} catch (Throwable $e) {
    error_log('[EduSync web push cron] '.$e->getMessage());
    fwrite(STDERR,"Error despachando Web Push; consulta el log PHP.\n");
    exit(1);
} finally {
    $conn->query("SELECT RELEASE_LOCK('edusync_web_push_fcm_v1')");
}
