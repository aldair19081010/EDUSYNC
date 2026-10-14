<?php
/**
 * Fuente única de despachos Web Push para eventos de EduSync.
 * Invocada inmediatamente después del evento y también por el cron de respaldo.
 * No abre sesión, no ejecuta cambios en perfiles y nunca es un endpoint público.
 */
require_once __DIR__.'/web_push_fcm_sender.php';

function edwp_dispatch_pending(mysqli $conn, string $kindFilter='', array $ids=[], int $limit=75): array {
    $out=['sent'=>0,'failed'=>0,'skipped'=>false,'error'=>''];
    $accepted=['student','staff','att_request','att_result','att_admin','reopen_req','evaluation','low_grade'];
    if ($kindFilter!=='' && !in_array($kindFilter,$accepted,true)) {
        $out['error']='Tipo de evento inválido.';
        return $out;
    }
    // Convertir IDs a enteros evita SQL arbitrario, también desde acciones web.
    $eventIds=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
    if ($ids && !$eventIds) return $out;
    $limit=max(1,min(75,$limit));
    foreach (['web_push_subscriptions','web_push_delivery'] as $table) {
        if (!push_table_exists($conn,$table)) {
            $out['error']='Falta la migración Web Push.';
            return $out;
        }
    }
    $hasStudent=push_table_exists($conn,'student_notification_events');
    $hasStaff=push_table_exists($conn,'notification_events');
    if (!$hasStudent && !$hasStaff) return $out;

    // Cron e intentos inmediatos comparten el mismo lock. Esto mantiene el
    // control de duplicados de web_push_delivery y evita carreras entre ellos.
    $lockQuery=$conn->query("SELECT GET_LOCK('edusync_web_push_fcm_v1',0) AS acquired");
    $lock=$lockQuery ? $lockQuery->fetch_assoc() : null;
    if ((int)($lock['acquired']??0)!==1) {
        $out['skipped']=true; // Cron sigue siendo respaldo.
        return $out;
    }
    try {
    $parts=[];
    $delivery='LEFT JOIN web_push_delivery d ON d.subscription_id=s.id AND d.event_kind=';
    $pending="(d.id IS NULL OR (d.status='retry' AND d.attempts<3 AND (d.next_retry_at IS NULL OR d.next_retry_at<=NOW())))";
    $active="s.is_active=1 AND s.last_seen_at>=DATE_SUB(NOW(),INTERVAL 45 DAY)";
    if ($hasStudent && ($kindFilter==='' || $kindFilter==='student')) {
        $parts[]="SELECT 'student' kind,e.id event_id,e.school_id,s.id subscription_id,s.fcm_token,
                  CASE WHEN e.notification_type='collection_notice'
                       THEN 'EduSync · Aviso de cobranza' ELSE e.title END title,
                  CASE WHEN e.notification_type='collection_notice'
                       THEN 'Tienes un aviso de pago pendiente. Consulta Mis Deudas en EduSync.'
                       ELSE e.body END body,
                  e.screen,e.created_at created,
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
    // Las fuentes virtuales de personal también necesitan este filtro.
    $userStatus=$hasStaff && push_column_exists($conn,'users','status')
        ? " AND u.status='Activo'" : '';
    if ($hasStaff && ($kindFilter==='' || $kindFilter==='staff')) {
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
    // Los demás avisos del Centro de notificaciones web son vistas sobre
    // tablas existentes. Para notificar también con la web cerrada, el cron
    // los trata como eventos virtuales sin duplicar datos en notification_events.
    if ($hasStaff && $kindFilter!=='staff' && $kindFilter!=='student') {
        $validStaff="JOIN users u ON u.id=s.account_id AND u.school_id=s.school_id AND u.type=s.role_type $userStatus
                     LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id";
        $staffEligible="(s.role_type<>2 OR t.status='Activo')";
        if (push_table_exists($conn,'attendance_change_requests')) {
            // Solicitud al administrador.
            $parts[]="SELECT 'att_request' kind,r.id event_id,r.school_id,s.id subscription_id,s.fcm_token,
                     CONCAT('Solicitud de asistencia: ',r.request_type) title,
                     COALESCE(NULLIF(r.reason,''),'Requiere autorización') body,
                     'attendance_request' screen,r.created_at created,COALESCE(d.attempts,0) attempts
                     FROM attendance_change_requests r
                     JOIN web_push_subscriptions s ON s.account_kind='staff' AND s.role_type=1
                       AND s.school_id=r.school_id AND r.created_at>=s.subscribed_at
                     $validStaff
                     {$delivery}'att_request' AND d.event_id=r.id
                     WHERE $active AND $pending AND $staffEligible
                       AND r.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)";
            // Resolución al usuario que solicitó el cambio, sea Docente o Auxiliar.
            $resolved="COALESCE(r.reviewed_at,r.updated_at,r.created_at)";
            $parts[]="SELECT 'att_result' kind,r.id event_id,r.school_id,s.id subscription_id,s.fcm_token,
                     IF(r.status='Aprobada','Solicitud de asistencia aprobada','Solicitud de asistencia rechazada') title,
                     CONCAT(r.request_type,IF(COALESCE(r.review_notes,'')<>'',CONCAT(' · ',r.review_notes),'')) body,
                     'attendance_result' screen,$resolved created,COALESCE(d.attempts,0) attempts
                     FROM attendance_change_requests r
                     JOIN web_push_subscriptions s ON s.account_kind='staff'
                       AND s.school_id=r.school_id AND s.account_id=r.requested_by AND $resolved>=s.subscribed_at
                     $validStaff
                     {$delivery}'att_result' AND d.event_id=r.id
                     WHERE $active AND $pending AND $staffEligible
                       AND r.status IN ('Aprobada','Rechazada')
                       AND $resolved>=DATE_SUB(NOW(),INTERVAL 3 DAY)";
            // Otros administradores conocen el resultado sin duplicar el aviso del revisor.
            $parts[]="SELECT 'att_admin' kind,r.id event_id,r.school_id,s.id subscription_id,s.fcm_token,
                     IF(r.status='Aprobada','Asistencia aprobada','Asistencia rechazada') title,
                     CONCAT('Solicitud de ',r.request_type,' resuelta por administración') body,
                     'attendance_admin_result' screen,$resolved created,COALESCE(d.attempts,0) attempts
                     FROM attendance_change_requests r
                     JOIN web_push_subscriptions s ON s.account_kind='staff' AND s.role_type=1
                       AND s.school_id=r.school_id AND $resolved>=s.subscribed_at
                     $validStaff
                     {$delivery}'att_admin' AND d.event_id=r.id
                     WHERE $active AND $pending AND $staffEligible
                       AND r.status IN ('Aprobada','Rechazada') AND r.reviewed_by IS NOT NULL
                       AND r.reviewed_by<>s.account_id
                       AND $resolved>=DATE_SUB(NOW(),INTERVAL 3 DAY)";
        }
        if (push_table_exists($conn,'grade_reopen_requests')
            && push_table_exists($conn,'teacher_courses')
            && push_table_exists($conn,'academic_courses')) {
            $parts[]="SELECT 'reopen_req' kind,r.id event_id,r.school_id,s.id subscription_id,s.fcm_token,
                     'Solicitud de reapertura de notas' title,
                     CONCAT(ac.name,' · ',tc.grado,' ',COALESCE(tc.seccion,''),
                       ' · ',r.bimester,'° Bimestre') body,
                     'grade_reopen_request' screen,r.created_at created,COALESCE(d.attempts,0) attempts
                     FROM grade_reopen_requests r
                     JOIN teacher_courses tc ON tc.id=r.teacher_course_id AND tc.school_id=r.school_id
                     JOIN academic_courses ac ON ac.id=tc.course_id
                     JOIN web_push_subscriptions s ON s.account_kind='staff' AND s.role_type=1
                       AND s.school_id=r.school_id AND r.created_at>=s.subscribed_at
                     $validStaff
                     {$delivery}'reopen_req' AND d.event_id=r.id
                     WHERE $active AND $pending AND $staffEligible
                       AND r.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)";
        }
        if (push_table_exists($conn,'evaluations')
            && push_table_exists($conn,'teacher_courses')
            && push_table_exists($conn,'academic_courses')) {
            $parts[]="SELECT 'evaluation' kind,e.id event_id,tc.school_id,s.id subscription_id,s.fcm_token,
                     CONCAT('Nueva evaluación: ',e.title) title,
                     CONCAT(COALESCE(ac.name,'Curso'),' · ',COALESCE(te.name,'Docente')) body,
                     'evaluation' screen,e.created_at created,COALESCE(d.attempts,0) attempts
                     FROM evaluations e
                     JOIN teacher_courses tc ON tc.id=e.teacher_course_id
                     LEFT JOIN academic_courses ac ON ac.id=tc.course_id
                     LEFT JOIN teacher te ON te.id=tc.teacher_id
                     JOIN web_push_subscriptions s ON s.account_kind='staff' AND s.role_type=1
                       AND s.school_id=tc.school_id AND e.created_at>=s.subscribed_at
                     $validStaff
                     {$delivery}'evaluation' AND d.event_id=e.id
                     WHERE $active AND $pending AND $staffEligible
                       AND e.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)
                       AND NOT EXISTS (SELECT 1 FROM notification_events ne
                         WHERE ne.school_id=tc.school_id AND ne.source_type='evaluation'
                           AND ne.source_id=e.id)";
        }
        if (push_table_exists($conn,'low_grade_notifications')) {
            $parts[]="SELECT 'low_grade' kind,lgn.id event_id,st.school_id,s.id subscription_id,s.fcm_token,
                     'EduSync · Seguimiento académico' title,
                     CONCAT('Nuevo caso para revisar · ',st.nivel,' · ',
                         st.grado,'° ',COALESCE(st.seccion,''),
                         ' · Bimestre ',lgn.bimestre) body,
                     'low_grade' screen,lgn.created_at created,COALESCE(d.attempts,0) attempts
                     FROM low_grade_notifications lgn
                     JOIN student st ON st.id=lgn.student_id
                     JOIN web_push_subscriptions s ON s.account_kind='staff'
                       AND s.school_id=st.school_id AND lgn.created_at>=s.subscribed_at
                     $validStaff
                     {$delivery}'low_grade' AND d.event_id=lgn.id
                     WHERE $active AND $pending AND $staffEligible
                       AND ((s.role_type=1 AND lgn.teacher_id IS NULL)
                         OR (s.role_type=2 AND lgn.teacher_id=u.teacher_id))
                       AND lgn.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY)
                       AND NOT EXISTS (SELECT 1 FROM notification_events ne
                         WHERE ne.school_id=st.school_id AND ne.source_type='low_grade'
                           AND ne.source_id=lgn.id)";
        }
    }

    // Evitar errores por collations diferentes al unir eventos estudiantiles,
    // académicos y administrativos provenientes de tablas antiguas.
    $normalized=[];
    foreach ($parts as $part) {
        $normalized[]="SELECT x.kind,x.event_id,x.school_id,x.subscription_id,x.fcm_token,
            CONVERT(x.title USING utf8mb4) COLLATE utf8mb4_general_ci title,
            CONVERT(x.body USING utf8mb4) COLLATE utf8mb4_general_ci body,
            CONVERT(x.screen USING utf8mb4) COLLATE utf8mb4_general_ci screen,
            x.created,x.attempts FROM ($part) x";
    }
    if (!$parts) return $out;
    $filter=[];
    if ($kindFilter!=='') $filter[]="kind='".$conn->real_escape_string($kindFilter)."'";
    if ($eventIds) $filter[]="event_id IN (".implode(',', $eventIds).")";
    $where=$filter ? ' WHERE '.implode(' AND ',$filter) : '';
    $sql="SELECT * FROM (".implode(' UNION ALL ',$normalized).") events".$where." ORDER BY created ASC LIMIT ".$limit;
    $result=$conn->query($sql);
    if (!$result) throw new RuntimeException('Web push query: '.$conn->error);
    // No solicitar OAuth si no hay notificaciones pendientes para enviar.
    if ($result->num_rows===0) return $out;
    $credentials=edwp_load_firebase_credentials();
    if (!$credentials || !push_firebase_access_token($credentials)) {
        $out['error']='Firebase no está configurado o no respondió.';
        return $out;
    }
    $sent=0;$failed=0;
    while ($event=$result->fetch_assoc()) {
        $kind=$event['kind'];
        $eventId=(int)$event['event_id'];
        $school=(int)$event['school_id'];
        $subscription=(int)$event['subscription_id'];
        $screen=(string)$event['screen'];
        $url=$kind==='staff' ? 'index.php?page=notifications&push_event_id='.$eventId : 'index.php?page=notifications';
        if ($kind==='low_grade') {
            // Verificar colegio y rol en el endpoint peek antes de abrir.
            $url='index.php?page=notifications&push_event_id='.$eventId.'&push_kind=low_grade';
        }
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
        $next=$ok||$status==='failed' ? null : (new DateTimeImmutable(
            'now',new DateTimeZone('America/Lima')
        ))->modify('+'.(300*((int)$event['attempts']+1)).' seconds')->format('Y-m-d H:i:s');
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
        // En caso de caída de red, los pendientes conservan retry para el cron.
        $up=strtoupper($message);
        if (!$ok && ($code===404 || strpos($up,'UNREGISTERED')!==false ||
             strpos($up,'NOT_FOUND')!==false)) {
            $disable=$conn->prepare('UPDATE web_push_subscriptions SET is_active=0 WHERE id=? AND school_id=?');
            $disable->bind_param('ii',$subscription,$school);
            $disable->execute();$disable->close();
        }
    }
    $out['sent']=$sent;
    $out['failed']=$failed;
    return $out;
    } catch (Throwable $exception) {
        error_log('[EduSync web push dispatch] '.$exception->getMessage());
        $out['error']='No se pudo despachar Web Push. Revisar error_log.';
        return $out;
    } finally {
        $conn->query("SELECT RELEASE_LOCK('edusync_web_push_fcm_v1')");
    }
}

/** Envío acotado, posterior al COMMIT o a la creación de un evento persistente. */
function edwp_dispatch_now(mysqli $conn,string $kind,array $ids,int $limit=12): array {
    try {
        return edwp_dispatch_pending($conn,$kind,$ids,$limit);
    } catch (Throwable $e) {
        error_log('[EduSync web push immediate] '.$e->getMessage());
        return ['sent'=>0,'failed'=>0,'skipped'=>false,'error'=>'No se pudo iniciar el envío.'];
    }
}
