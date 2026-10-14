<?php
/**
 * Envío FCM HTTP v1 para navegadores de EduSync.
 * Reutiliza exclusivamente las credenciales de servicio que ya usa la app.
 */
require_once __DIR__ . '/push_notifications.php';

function edwp_send_message(
    string $token,
    string $title,
    string $body,
    string $url,
    string $tag
): array {
    $credentials = push_load_firebase_credentials();
    if (!$credentials) {
        error_log('[EduSync web push] Credenciales FCM ausentes en el entorno PHP.');
        return ['ok'=>false,'code'=>0,'message'=>'FCM not configured'];
    }
    $oauth = push_firebase_access_token($credentials);
    $project = trim((string)($credentials['project_id'] ?? ''));
    if (!$oauth || !$project) {
        error_log('[EduSync web push] No se pudo autenticar Firebase.');
        return ['ok'=>false,'code'=>0,'message'=>'FCM auth failed'];
    }
    // No se usa notification: el Service Worker controla una sola notificación.
    // Todos los valores de data son cadenas según el contrato de FCM.
    $payload = [
        'message'=>[
            'token'=>$token,
            'data'=>[
                'title'=>mb_substr($title,0,150,'UTF-8'),
                'body'=>mb_substr($body,0,450,'UTF-8'),
                'url'=>$url,
                'tag'=>mb_substr($tag,0,96,'UTF-8'),
            ],
            'webpush'=>[
                'headers'=>['Urgency'=>'high','TTL'=>'86400'],
            ],
        ],
    ];
    $endpoint='https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send';
    $response=push_http_post($endpoint,[
        'Authorization: Bearer '.$oauth,
        'Content-Type: application/json; charset=utf-8',
    ],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return [
        'ok'=>(bool)$response['ok'],
        'code'=>(int)$response['code'],
        'message'=>mb_substr((string)($response['body']??'').' '.(string)($response['error']??''),0,500,'UTF-8'),
    ];
}
