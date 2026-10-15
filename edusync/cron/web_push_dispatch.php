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
require_once __DIR__.'/../includes/notification_date_timezone.php';
require_once __DIR__.'/../includes/web_push_fcm_sender.php';
// Comparar TIMESTAMP con subscribed_at/last_seen_at (DATETIME) en la misma zona.
// Las suscripciones Web Push se registran en UTC.
$conn->query("SET time_zone = '+00:00'");

foreach (['web_push_subscriptions','web_push_delivery'] as $table) {
    if (!push_table_exists($conn,$table)) {
        fwrite(STDERR,"Falta la migración sql/web_push_fcm_v1.sql.\n");
        exit(1);
    }
}
// No consumir reintentos si olvidaron configurar Firebase: detener
// el proceso sin registrar fallos hasta que las credenciales estén listas.
$credentials=edwp_load_firebase_credentials();
if (!$credentials || !push_firebase_access_token($credentials)) {
    fwrite(STDERR,"Firebase no está configurado para el proceso de consola.\n");
    exit(1);
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
    // Los demás avisos del Centro de notificaciones web son vistas sobre
    // tablas existentes. Para notificar también con la web cerrada, el cron
    // los trata como eventos virtuales sin duplicar datos en notification_events.
    if ($hasStaff) {
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
            // Evaluations DATETIME se guarda en UTC y TIMESTAMP ya sale UTC por la sesion.
            $evaluationTimeSql = 'e.created_at';
            $parts[]="SELECT 'evaluation' kind,e.id event_id,tc.school_id,s.id subscription_id,s.fcm_token,
                     CONCAT('Nueva evaluación: ',e.title) title,
                     CONCAT(COALESCE(ac.name,'Curso'),' · ',COALESCE(te.name,'Docente')) body,
                     'evaluation' screen,{$evaluationTimeSql} created,COALESCE(d.attempts,0) attempts
                     FROM evaluations e
                     JOIN teacher_courses tc ON tc.id=e.teacher_course_id
                     LEFT JOIN academic_courses ac ON ac.id=tc.course_id
                     LEFT JOIN teacher te ON te.id=tc.teacher_id
                     JOIN web_push_subscriptions s ON s.account_kind='staff' AND s.role_type=1
                       AND s.school_id=tc.school_id AND {$evaluationTimeSql}>=s.subscribed_at
                     $validStaff
                     {$delivery}'evaluation' AND d.event_id=e.id
                     WHERE $active AND $pending AND $staffEligible
                       AND {$evaluationTimeSql}>=DATE_SUB(NOW(),INTERVAL 3 DAY)
                       AND NOT EXISTS (SELECT 1 FROM notification_events ne
                         WHERE ne.school_id=tc.school_id AND ne.source_type='evaluation'
                           AND ne.source_id=e.id)";
        }
        if (push_table_exists($conn,'low_grade_notifications')) {
            $parts[]="SELECT 'low_grade' kind,lgn.id event_id,st.school_id,s.id subscription_id,s.fcm_token,
                     'EduSync · Seguimiento académico' title,
                     CONCAT('Nuevo caso para revisar · ',st.nivel,' · ',
                         TRIM(TRAILING '°' FROM TRIM(st.grado)),'° ',COALESCE(st.seccion,''),
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
    $sql="SELECT * FROM (".implode(' UNION ALL ',$normalized).") events ORDER BY created ASC LIMIT 75";
    $result=$conn->query($sql);
    if (!$result) throw new RuntimeException('Web push query: '.$conn->error);
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
        $next=$ok||$status==='failed' ? null : gmdate('Y-m-d H:i:s',time()+300*((int)$event['attempts']+1));
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
