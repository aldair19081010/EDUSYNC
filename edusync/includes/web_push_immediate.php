<?php
/**
 * Envio inmediato de Web Push a estudiantes tras crear un evento.
 * Reutiliza el remitente FCM, la tabla web_push_delivery y el candado
 * global del cron. Si no hay Firebase/suscripciones, el evento queda
 * persistido para que el cron lo procese cuando corresponda.
 */
require_once __DIR__.'/web_push_fcm_sender.php';

function edwp_send_student_event_now(mysqli $db, int $schoolId, int $eventId): array {
    $summary=['sent'=>0,'failed'=>0,'skipped'=>false];
    if ($schoolId <= 0 || $eventId <= 0
        || !push_table_exists($db,'web_push_subscriptions')
        || !push_table_exists($db,'web_push_delivery')
        || !push_table_exists($db,'student_notification_events')) {
        $summary['skipped']=true;
        return $summary;
    }
    $credentials=edwp_load_firebase_credentials();
    if (!$credentials || !push_firebase_access_token($credentials)) {
        $summary['skipped']=true;
        return $summary;
    }
    $lockQuery=$db->query("SELECT GET_LOCK('edusync_web_push_fcm_v1',0) acquired");
    $lock=$lockQuery ? $lockQuery->fetch_assoc() : null;
    if ((int)($lock['acquired']??0)!==1) {
        $summary['skipped']=true;
        return $summary;
    }
    try {
        $sql="SELECT e.id event_id,e.school_id,s.id subscription_id,s.fcm_token,
                     e.title,e.body,e.screen,COALESCE(d.attempts,0) attempts
              FROM student_notification_events e
              JOIN web_push_subscriptions s ON s.account_kind='student'
                AND s.school_id=e.school_id AND s.account_id=e.student_id
                AND e.created_at>=s.subscribed_at
              JOIN student st ON st.id=s.account_id AND st.school_id=s.school_id
              LEFT JOIN web_push_delivery d ON d.subscription_id=s.id
                AND d.event_kind='student' AND d.event_id=e.id
              WHERE e.id=? AND e.school_id=?
                AND s.is_active=1
                AND s.last_seen_at>=DATE_SUB(NOW(),INTERVAL 45 DAY)
                AND e.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)
                AND (e.expires_at IS NULL OR e.expires_at>NOW())
                AND (d.id IS NULL OR (d.status='retry' AND d.attempts<3
                  AND (d.next_retry_at IS NULL OR d.next_retry_at<=NOW())))
              LIMIT 20";
        $stmt=$db->prepare($sql);
        if (!$stmt) throw new RuntimeException($db->error);
        $stmt->bind_param('ii',$eventId,$schoolId);
        $stmt->execute();
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $routes=[
            'attendance'=>'student_attendances','payments'=>'student_payments',
            'debts'=>'student_debts','grades'=>'student_grades',
            'announcements'=>'notifications',
        ];
        foreach ($rows as $event) {
            $screen=(string)$event['screen'];
            $url=$screen==='announcements'
                ? 'index.php?page=notifications&notification_id='.$eventId
                : 'index.php?page='.($routes[$screen]??'notifications');
            $answer=edwp_send_message(
                (string)$event['fcm_token'],(string)$event['title'],
                (string)$event['body'],$url,'edusync-student-'.$schoolId.'-'.$eventId
            );
            $ok=!empty($answer['ok']);
            $code=(int)($answer['code']??0);
            $message=mb_substr((string)($answer['message']??''),0,500,'UTF-8');
            $attempts=(int)$event['attempts'];
            $status=$ok?'delivered':($attempts>=2?'failed':'retry');
            $next=$ok||$status==='failed'?null:date('Y-m-d H:i:s',time()+300*($attempts+1));
            $flag=$ok?1:0;
            $sub=(int)$event['subscription_id'];
            $insert=$db->prepare("INSERT INTO web_push_delivery
              (school_id,subscription_id,event_kind,event_id,status,attempts,provider_code,provider_message,next_retry_at,delivered_at)
              VALUES(?,?,'student',?,?,1,?,?,?,IF(?=1,NOW(),NULL))
              ON DUPLICATE KEY UPDATE status=VALUES(status),attempts=attempts+1,
                provider_code=VALUES(provider_code),provider_message=VALUES(provider_message),
                next_retry_at=VALUES(next_retry_at),delivered_at=VALUES(delivered_at)");
            if (!$insert) throw new RuntimeException($db->error);
            $insert->bind_param('iiisissi',$schoolId,$sub,$eventId,$status,$code,$message,$next,$flag);
            if (!$insert->execute()) error_log('[EduSync immediate Web Push delivery] '.$insert->error);
            $insert->close();
            if ($ok) $summary['sent']++; else $summary['failed']++;
            $upper=strtoupper($message);
            if (!$ok && ($code===404 || strpos($upper,'UNREGISTERED')!==false
                || strpos($upper,'NOT_FOUND')!==false)) {
                $disable=$db->prepare('UPDATE web_push_subscriptions SET is_active=0 WHERE id=? AND school_id=?');
                if ($disable) {
                    $disable->bind_param('ii',$sub,$schoolId);
                    $disable->execute();
                    $disable->close();
                }
            }
        }
        return $summary;
    } finally {
        $db->query("SELECT RELEASE_LOCK('edusync_web_push_fcm_v1')");
    }
}
