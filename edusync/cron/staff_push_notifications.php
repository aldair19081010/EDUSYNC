<?php
/**
 * EduSync staff push dispatcher. CLI-only; schedule once per minute on the host.
 * Reads existing notification_events; never creates new academic/finance events.
 * Does not affect the existing student push pipeline.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ini_set('display_errors','0');
date_default_timezone_set('America/Lima');
require_once __DIR__.'/../db_connect.php';
require_once __DIR__.'/../includes/push_notifications.php';

foreach (['notification_events','staff_device_tokens','staff_push_delivery'] as $table) {
    if (!push_table_exists($conn,$table)) {
        fwrite(STDERR,"Missing table $table; execute sql/staff_push_notifications.sql.\n");
        exit(1);
    }
}
$credentials=push_load_firebase_credentials();
if (!$credentials) { fwrite(STDERR,"Firebase credentials not configured.\n"); exit(1); }
$accessToken=push_firebase_access_token($credentials);
$project=trim((string)($credentials['project_id']??''));
if (!$accessToken || $project==='') { fwrite(STDERR,"Firebase authentication failed.\n"); exit(1); }

$q=$conn->query("SELECT GET_LOCK('edusync_staff_push',0) held");
$lock=$q ? $q->fetch_assoc() : null;
if ((int)($lock['held']??0)!==1) { echo "Another dispatcher is running.\n"; exit(0); }
try {
    // Only messages created after registration, and only the logged-in user's
    // own messages or role-wide messages within the SAME school.
    $userActive=push_column_exists($conn,'users','status') ? " AND u.status='Activo'" : '';
    $sql="SELECT n.id event_id,n.school_id,n.notification_type,n.title,n.message,n.source_type,n.source_id,
                 d.id token_id,d.user_id,d.fcm_token,d.role_type,
                 COALESCE(del.attempts,0) attempts
        FROM notification_events n
        JOIN staff_device_tokens d ON d.school_id=n.school_id AND d.is_active=1 AND n.created_at >= d.created_at
        JOIN users u ON u.id=d.user_id AND u.school_id=d.school_id AND u.type=d.role_type
        LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id
        LEFT JOIN staff_push_delivery del ON del.notification_event_id=n.id AND del.device_token_id=d.id
        WHERE n.created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)".$userActive."
          AND (d.role_type<>2 OR t.status='Activo')
          AND (n.recipient_user_id=d.user_id OR (
             n.recipient_user_id IS NULL AND n.recipient_role=CASE d.role_type
               WHEN 1 THEN 'Administrador' WHEN 2 THEN 'Docente' WHEN 3 THEN 'Auxiliar' ELSE '' END
          ))
          AND (del.id IS NULL OR (
             del.status='failed' AND del.attempts<3 AND (del.next_retry_at IS NULL OR del.next_retry_at<=NOW())
          ))
        ORDER BY n.id ASC LIMIT 75";
    $rows=$conn->query($sql);
    if (!$rows) throw new RuntimeException($conn->error);
    $sent=0;$failed=0;
    $endpoint='https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send';
    while ($row=$rows->fetch_assoc()) {
        $token=trim((string)$row['fcm_token']);
        if ($token==='') continue;
        $eventId=(int)$row['event_id'];
        $deviceId=(int)$row['token_id'];
        $schoolId=(int)$row['school_id'];
        $data=[
            'screen'=>'staff_notifications',
            'type'=>'staff_notification',
            'notification_event_id'=>(string)$eventId,
            'source_type'=>(string)($row['source_type']??''),
            'source_id'=>(string)($row['source_id']??'0'),
            'school_id'=>(string)$schoolId,
            'user_id'=>(string)(int)$row['user_id'],
        ];
        $payload=['message'=>[
            'token'=>$token,
            'notification'=>[
                'title'=>(string)$row['title'],
                'body'=>(string)($row['message']??'')
            ],
            'data'=>$data,
            'android'=>[
                'priority'=>'HIGH',
                'notification'=>['channel_id'=>'staff_alerts','icon'=>'ic_notification','color'=>'#1565C0']
            ]
        ]];
        $response=push_http_post($endpoint,[
            'Authorization: Bearer '.$accessToken,
            'Content-Type: application/json; charset=utf-8'
        ],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $success=(bool)$response['ok'];
        $status=$success?'delivered':'failed';
        $code=(int)$response['code'];
        $raw=(string)$response['body'].' '.(string)$response['error'];
        $message=mb_substr($raw,0,500,'UTF-8');
        $retryTime=$success?null:date('Y-m-d H:i:s',time()+(min(3,(int)$row['attempts']+1)*300));
        $stmt=$conn->prepare('INSERT INTO staff_push_delivery(school_id,notification_event_id,device_token_id,status,attempts,provider_code,provider_message,next_retry_at,delivered_at) VALUES(?,?,?,?,1,?,?,?,IF(?=1,NOW(),NULL)) ON DUPLICATE KEY UPDATE status=VALUES(status),attempts=attempts+1,provider_code=VALUES(provider_code),provider_message=VALUES(provider_message),next_retry_at=VALUES(next_retry_at),delivered_at=VALUES(delivered_at)');
        $ok=$success?1:0;
        $stmt->bind_param('iiisissi',$schoolId,$eventId,$deviceId,$status,$code,$message,$retryTime,$ok);
        if (!$stmt->execute()) error_log('[staff push log] '.$stmt->error);
        $stmt->close();
        if ($success) {
            $sent++;
        } else {
            $failed++;
            $upper=strtoupper($raw);
            if ($code===404||strpos($upper,'UNREGISTERED')!==false||strpos($upper,'NOT_FOUND')!==false) {
                $stmt=$conn->prepare('UPDATE staff_device_tokens SET is_active=0 WHERE id=? AND school_id=?');
                $stmt->bind_param('ii',$deviceId,$schoolId);
                $stmt->execute();$stmt->close();
            }
        }
    }
    echo "Staff notifications: $sent sent, $failed failed.\n";
} catch (Throwable $e) {
    error_log('[EduSync staff push worker] '.$e->getMessage());
    fwrite(STDERR,"Staff push dispatch failed; inspect PHP error log.\n");
    exit(1);
} finally {
    $conn->query("SELECT RELEASE_LOCK('edusync_staff_push')");
}
