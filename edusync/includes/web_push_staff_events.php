<?php
/**
 * Envío Web Push inmediato para los avisos del personal que antes dependían
 * exclusivamente del cron (evaluación, reapertura, alertas, eventos).
 * La cola persistida en web_push_delivery y el candado compartidos con el
 * cron impiden volver a notificar al mismo dispositivo por el mismo evento.
 */
require_once __DIR__ . '/web_push_fcm_sender.php';

function edwp_queue_staff_event(mysqli $db, int $school, string $kind, int $id): void {
    if ($school <= 0 || $id <= 0 || !in_array($kind, ['staff','evaluation','low_grade','reopen_req'], true)) return;
    // El envío ocurre después de finalizar la respuesta, sin cambiar el
    // resultado de la transacción ni bloquear la interfaz durante el guardado.
    register_shutdown_function(static function () use ($db, $school, $kind, $id): void {
        try {
            edwp_send_staff_event_now($db, $school, $kind, $id);
        } catch (Throwable $e) {
            error_log('[EduSync staff Web Push] ' . $e->getMessage());
        }
    });
}

function edwp_send_staff_event_now(mysqli $db, int $school, string $kind, int $id): array {
    $result = ['sent'=>0, 'failed'=>0, 'skipped'=>false];
    if ($school<=0 || $id<=0 || !in_array($kind, ['staff','evaluation','low_grade','reopen_req'], true)
        || !push_table_exists($db, 'web_push_subscriptions')
        || !push_table_exists($db, 'web_push_delivery')) {
        $result['skipped']=true;
        return $result;
    }
    $sourceTable = [
        'staff'=>'notification_events', 'evaluation'=>'evaluations',
        'low_grade'=>'low_grade_notifications', 'reopen_req'=>'grade_reopen_requests',
    ][$kind];
    if (!push_table_exists($db, $sourceTable)) {
        $result['skipped']=true;
        return $result;
    }
    $credentials = edwp_load_firebase_credentials();
    if (!$credentials || !push_firebase_access_token($credentials)) {
        $result['skipped']=true;
        error_log('[EduSync staff Web Push] Faltan credenciales privadas de Firebase o autenticación FCM.');
        return $result;
    }

    // La suscripción (DATETIME), los eventos TIMESTAMP y las fechas de las
    // evaluaciones DATETIME nuevas usan UTC en los envíos.
    $db->query("SET time_zone = '+00:00'");
    $lockQuery = $db->query("SELECT GET_LOCK('edusync_web_push_fcm_v1',0) acquired");
    $locked = $lockQuery ? $lockQuery->fetch_assoc() : null;
    if ((int)($locked['acquired'] ?? 0) !== 1) {
        $result['skipped']=true; // El cron recupera lo pendiente.
        return $result;
    }

    try {
        $userStatus = push_column_exists($db, 'users', 'status') ? " AND u.status='Activo'" : '';
        $joinStaff = "JOIN users u ON u.id=s.account_id AND u.school_id=s.school_id
                         AND u.type=s.role_type $userStatus
                      LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id";
        $eligible = "(s.role_type<>2 OR t.status='Activo')";
        $common = "s.is_active=1 AND s.last_seen_at>=DATE_SUB(NOW(),INTERVAL 45 DAY)
                   AND $eligible
                   AND (d.id IS NULL OR (d.status='retry' AND d.attempts<3
                       AND (d.next_retry_at IS NULL OR d.next_retry_at<=NOW())))";
        $joinDelivery = "LEFT JOIN web_push_delivery d ON d.subscription_id=s.id
                           AND d.event_kind='$kind' AND d.event_id=";

        if ($kind === 'staff') {
            $sql = "SELECT s.id subscription_id,s.fcm_token,s.browser_id,e.title,
                           COALESCE(e.message,'') body,COALESCE(d.attempts,0) attempts
                    FROM notification_events e
                    JOIN web_push_subscriptions s ON s.account_kind='staff' AND s.school_id=e.school_id
                      AND e.created_at>=s.subscribed_at
                    $joinStaff
                    {$joinDelivery}e.id
                    WHERE e.id=? AND e.school_id=? AND $common
                      AND e.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)
                      AND COALESCE(e.source_type,'')<>'attendance_request_result'
                      AND (e.recipient_user_id=s.account_id OR
                          (e.recipient_user_id IS NULL AND e.recipient_role=CASE s.role_type
                            WHEN 1 THEN 'Administrador' WHEN 2 THEN 'Docente'
                            WHEN 3 THEN 'Auxiliar' ELSE '' END)) LIMIT 100";
        } elseif ($kind === 'evaluation') {
            if (!push_table_exists($db,'teacher_courses') || !push_table_exists($db,'academic_courses')) {
                $result['skipped']=true;
                return $result;
            }
            $sql = "SELECT s.id subscription_id,s.fcm_token,s.browser_id,
                           CONCAT('Nueva evaluación: ',e.title) title,
                           CONCAT(COALESCE(ac.name,'Curso'),' · ',COALESCE(te.name,'Docente')) body,
                           COALESCE(d.attempts,0) attempts
                    FROM evaluations e
                    JOIN teacher_courses tc ON tc.id=e.teacher_course_id
                    LEFT JOIN academic_courses ac ON ac.id=tc.course_id
                    LEFT JOIN teacher te ON te.id=tc.teacher_id
                    JOIN web_push_subscriptions s ON s.account_kind='staff' AND s.role_type=1
                       AND s.school_id=tc.school_id AND e.created_at>=s.subscribed_at
                    $joinStaff
                    {$joinDelivery}e.id
                    WHERE e.id=? AND tc.school_id=? AND $common
                      AND e.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)
                      AND NOT EXISTS (SELECT 1 FROM notification_events ne
                          WHERE ne.school_id=tc.school_id AND ne.source_type='evaluation'
                            AND ne.source_id=e.id) LIMIT 100";
        } elseif ($kind === 'low_grade') {
            $sql = "SELECT s.id subscription_id,s.fcm_token,s.browser_id,
                           'EduSync · Seguimiento académico' title,
                           CONCAT('Nuevo caso para revisar · ',st.nivel,' · ',
                                TRIM(TRAILING '°' FROM TRIM(st.grado)),'° ',COALESCE(st.seccion,''),
                                ' · Bimestre ',lgn.bimestre) body,
                           COALESCE(d.attempts,0) attempts
                    FROM low_grade_notifications lgn
                    JOIN student st ON st.id=lgn.student_id
                    JOIN web_push_subscriptions s ON s.account_kind='staff'
                       AND s.school_id=st.school_id AND lgn.created_at>=s.subscribed_at
                    $joinStaff
                    {$joinDelivery}lgn.id
                    WHERE lgn.id=? AND st.school_id=? AND $common
                      AND lgn.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)
                      AND ((s.role_type=1 AND lgn.teacher_id IS NULL)
                        OR (s.role_type=2 AND lgn.teacher_id=u.teacher_id))
                      AND NOT EXISTS (SELECT 1 FROM notification_events ne
                          WHERE ne.school_id=st.school_id AND ne.source_type='low_grade'
                            AND ne.source_id=lgn.id) LIMIT 100";
        } else {
            if (!push_table_exists($db,'teacher_courses') || !push_table_exists($db,'academic_courses')) {
                $result['skipped']=true;
                return $result;
            }
            $sql = "SELECT s.id subscription_id,s.fcm_token,s.browser_id,
                           'Solicitud de reapertura de notas' title,
                           CONCAT(ac.name,' · ',tc.grado,' ',COALESCE(tc.seccion,''),
                                  ' · ',r.bimester,'° Bimestre') body,
                           COALESCE(d.attempts,0) attempts
                    FROM grade_reopen_requests r
                    JOIN teacher_courses tc ON tc.id=r.teacher_course_id AND tc.school_id=r.school_id
                    JOIN academic_courses ac ON ac.id=tc.course_id
                    JOIN web_push_subscriptions s ON s.account_kind='staff' AND s.role_type=1
                       AND s.school_id=r.school_id AND r.created_at>=s.subscribed_at
                    $joinStaff
                    {$joinDelivery}r.id
                    WHERE r.id=? AND r.school_id=? AND $common
                      AND r.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY) LIMIT 100";
        }

        $stmt=$db->prepare($sql);
        if (!$stmt) throw new RuntimeException($db->error);
        $stmt->bind_param('ii', $id, $school);
        $stmt->execute();
        $events=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $url='index.php?page=notifications';
        if ($kind==='staff') $url.='&push_event_id='.$id;
        elseif ($kind==='low_grade') $url.='&push_event_id='.$id.'&push_kind=low_grade';

        foreach ($events as $event) {
            $answer=edwp_send_message((string)$event['fcm_token'],
                (string)$event['title'],(string)$event['body'],$url,
                'edusync-'.$kind.'-'.$school.'-'.$id,
                strncmp((string)$event['browser_id'],'app-',4)===0);
            $ok=!empty($answer['ok']);
            $providerCode=(int)($answer['code'] ?? 0);
            $providerMessage=mb_substr((string)($answer['message'] ?? ''),0,500,'UTF-8');
            $attempts=(int)$event['attempts'];
            $status=$ok?'delivered':($attempts>=2?'failed':'retry');
            $nextRetry=$ok||$status==='failed'?null:gmdate('Y-m-d H:i:s',time()+300*($attempts+1));
            $delivered=$ok?1:0;
            $subscription=(int)$event['subscription_id'];
            $insert=$db->prepare("INSERT INTO web_push_delivery
                (school_id,subscription_id,event_kind,event_id,status,attempts,provider_code,provider_message,next_retry_at,delivered_at)
                VALUES(?,?,?,?,?,1,?,?,?,IF(?=1,NOW(),NULL))
                ON DUPLICATE KEY UPDATE status=VALUES(status),attempts=attempts+1,
                  provider_code=VALUES(provider_code),provider_message=VALUES(provider_message),
                  next_retry_at=VALUES(next_retry_at),delivered_at=VALUES(delivered_at)");
            if (!$insert) throw new RuntimeException($db->error);
            $insert->bind_param('iisisissi',$school,$subscription,$kind,$id,
                $status,$providerCode,$providerMessage,$nextRetry,$delivered);
            if (!$insert->execute()) error_log('[EduSync staff Web Push delivery] '.$insert->error);
            $insert->close();
            if ($ok) $result['sent']++; else $result['failed']++;
            $upper=strtoupper($providerMessage);
            if (!$ok && ($providerCode===404 || strpos($upper,'UNREGISTERED')!==false
                || strpos($upper,'NOT_FOUND')!==false)) {
                $disable=$db->prepare('UPDATE web_push_subscriptions SET is_active=0 WHERE id=? AND school_id=?');
                if ($disable) {
                    $disable->bind_param('ii',$subscription,$school);
                    $disable->execute();
                    $disable->close();
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[EduSync staff Web Push] '.$e->getMessage());
        $result['skipped']=true;
    } finally {
        $db->query("SELECT RELEASE_LOCK('edusync_web_push_fcm_v1')");
    }
    return $result;
}
