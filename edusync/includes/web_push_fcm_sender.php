<?php
/**
 * Envío FCM HTTP v1 para navegadores de EduSync.
 * Reutiliza exclusivamente las credenciales de servicio que ya usa la app.
 */
require_once __DIR__ . '/push_notifications.php';

/** Utiliza configuración de FCM existente sin guardar secretos en el repositorio. */
function edwp_load_firebase_credentials(): ?array {
    $credentials=push_load_firebase_credentials();
    if ($credentials) return $credentials;

    // Credencial privada fuera de public_html para Hostinger. No es el JSON
    // público de Firebase Web/VAPID; éste no puede firmar mensajes FCM.
    // Respetar primero las variables de entorno de producción.
    $privateFile = dirname(__DIR__, 3) . '/firebase/edusync-service-account.json';
    if (is_file($privateFile) && is_readable($privateFile)) {
        $data = json_decode((string)file_get_contents($privateFile), true);
        if (is_array($data) && !empty($data['project_id'])
            && !empty($data['client_email']) && !empty($data['private_key'])) {
            return $data;
        }
    }

    // Compatibilidad con el archivo privado ya instalado para desarrollo XAMPP.
    if (DIRECTORY_SEPARATOR === '\\') {
        $localFile=dirname(__DIR__,4).'/firebase/edusync-service-account.json';
        if (is_readable($localFile)) {
            $data=json_decode((string)file_get_contents($localFile),true);
            if (is_array($data) && !empty($data['project_id']) && !empty($data['private_key']))
                return $data;
        }
    }
    return null;
}

function edwp_send_message(
    string $token,
    string $title,
    string $body,
    string $url,
    string $tag
): array {
    $credentials = edwp_load_firebase_credentials();
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
