<?php
/**
 * Web Push inmediato para solicitudes y resoluciones de asistencia del
 * personal. Usa las mismas reglas, claves y candado que el cron.
 */
require_once __DIR__.'/web_push_fcm_sender.php';

function edwp_send_staff_attendance_now(mysqli $db, int $schoolId, int $requestId, string $kind): array {
    $summary=['sent'=>0,'failed'=>0,'skipped'=>false];
    if ($schoolId<=0 || $requestId<=0 || !in_array($kind,['att_request','att_result','att_admin'],true)
        || !push_table_exists($db,'web_push_subscriptions')
        || !push_table_exists($db,'web_push_delivery')
        || !push_table_exists($db,'attendance_change_requests')) {
        $summary['skipped']=true;
        return $summary;
    }
    $creds=edwp_load_firebase_credentials();
    if (!$creds || !push_firebase_access_token($creds)) {
        $summary['skipped']=true;
        return $summary;
    }
    $q=$db->query("SELECT GET_LOCK('edusync_web_push_fcm_v1',0) acquired");
    $lock=$q?$q->fetch_assoc():null;
    if ((int)($lock['acquired']??0)!==1) {
        $summary['skipped']=true;
        return $summary;
    }
    $priorTimeZone = '';
    try {
        $tz = $db->query("SELECT @@session.time_zone AS tz");
        if ($tz) $priorTimeZone = (string)($tz->fetch_assoc()['tz'] ?? '');
        $db->query("SET time_zone = '+00:00'");
        // reviewed_at es DATETIME guardado en zona local (-05),
        // pero subscribed_at/updated_at son TIMESTAMP. Usar updated_at
        // garantiza comparación homogénea UTC tras SET time_zone.
        $resolved='r.updated_at';
        $when=$kind==='att_request'?'r.created_at':$resolved;
        $userStatus=push_column_exists($db,'users','status')?" AND u.status='Activo'":'';
        $join="JOIN web_push_subscriptions s ON s.account_kind='staff'
                    AND s.school_id=r.school_id AND $when>=s.subscribed_at
                JOIN users u ON u.id=s.account_id AND u.school_id=s.school_id
                    AND u.type=s.role_type $userStatus
                LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id";
        $where="s.is_active=1 AND s.last_seen_at>=DATE_SUB(NOW(),INTERVAL 45 DAY)
               AND (s.role_type<>2 OR t.status='Activo')
               AND $when>=DATE_SUB(NOW(),INTERVAL 3 DAY)";
        if ($kind==='att_request') {
            $where.=" AND s.role_type=1";
            $title="CONCAT('Solicitud de asistencia: ',r.request_type)";
            $body="COALESCE(NULLIF(r.reason,''),'Requiere autorización')";
        } elseif ($kind==='att_result') {
            $where.=" AND s.account_id=r.requested_by AND r.status IN ('Aprobada','Rechazada')";
            $title="IF(r.status='Aprobada','Solicitud de asistencia aprobada','Solicitud de asistencia rechazada')";
            $body="CONCAT(r.request_type,IF(COALESCE(r.review_notes,'')<>'',CONCAT(' · ',r.review_notes),''))";
        } else {
            $where.=" AND s.role_type=1 AND r.status IN ('Aprobada','Rechazada')
                      AND r.reviewed_by IS NOT NULL AND r.reviewed_by<>s.account_id";
            $title="IF(r.status='Aprobada','Asistencia aprobada','Asistencia rechazada')";
            $body="CONCAT('Solicitud de ',r.request_type,' resuelta por administración')";
        }
        $sql="SELECT s.id subscription_id,s.fcm_token,s.browser_id,$title title,$body body,
                 COALESCE(d.attempts,0) attempts
              FROM attendance_change_requests r
              $join
              LEFT JOIN web_push_delivery d ON d.subscription_id=s.id
                AND d.event_kind=? AND d.event_id=r.id
              WHERE r.id=? AND r.school_id=? AND $where
                AND (d.id IS NULL OR (d.status='retry' AND d.attempts<3
                  AND (d.next_retry_at IS NULL OR d.next_retry_at<=NOW())))
              LIMIT 20";
        $stmt=$db->prepare($sql);
        if (!$stmt) throw new RuntimeException($db->error);
        $stmt->bind_param('sii',$kind,$requestId,$schoolId);
        $stmt->execute();
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $event) {
            $answer=edwp_send_message((string)$event['fcm_token'],
                (string)$event['title'],(string)$event['body'],
                'index.php?page=notifications',
                'edusync-'.$kind.'-'.$schoolId.'-'.$requestId,
                strncmp((string)$event['browser_id'],'app-',4)===0);
            $ok=!empty($answer['ok']);
            $code=(int)($answer['code']??0);
            $message=mb_substr((string)($answer['message']??''),0,500,'UTF-8');
            $attempts=(int)$event['attempts'];
            $status=$ok?'delivered':($attempts>=2?'failed':'retry');
            $next=$ok||$status==='failed'?null:gmdate('Y-m-d H:i:s',time()+300*($attempts+1));
            $flag=$ok?1:0;
            $sub=(int)$event['subscription_id'];
            $insert=$db->prepare("INSERT INTO web_push_delivery
              (school_id,subscription_id,event_kind,event_id,status,attempts,provider_code,provider_message,next_retry_at,delivered_at)
              VALUES(?,?,?,?,?,1,?,?,?,IF(?=1,NOW(),NULL))
              ON DUPLICATE KEY UPDATE status=VALUES(status),attempts=attempts+1,
                provider_code=VALUES(provider_code),provider_message=VALUES(provider_message),
                next_retry_at=VALUES(next_retry_at),delivered_at=VALUES(delivered_at)");
            if (!$insert) throw new RuntimeException($db->error);
            $insert->bind_param('iisisissi',$schoolId,$sub,$kind,$requestId,$status,$code,$message,$next,$flag);
            if (!$insert->execute()) error_log('[EduSync staff Web Push delivery] '.$insert->error);
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
        if ($priorTimeZone !== '') $db->query("SET time_zone='" . $db->real_escape_string($priorTimeZone) . "'");
        $db->query("SELECT RELEASE_LOCK('edusync_web_push_fcm_v1')");
    }
}
