<?php

/**
 * Firebase Cloud Messaging helpers for EduSync student notifications.
 *
 * Credentials are intentionally NOT stored in the repository.
 * Configure one of:
 *   EDUSYNC_FIREBASE_CREDENTIALS=/absolute/path/firebase-service-account.json
 *   EDUSYNC_FIREBASE_CREDENTIALS_JSON={...service account json...}
 */

function push_table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function push_base64url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function push_load_firebase_credentials(): ?array {
    $raw = trim((string)getenv('EDUSYNC_FIREBASE_CREDENTIALS_JSON'));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }

    $path = trim((string)getenv('EDUSYNC_FIREBASE_CREDENTIALS'));
    if ($path !== '' && is_file($path) && is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) return $decoded;
    }

    return null;
}

function push_http_post(string $url, array $headers, string $body): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'cURL no está disponible en PHP.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => $response !== false && $code >= 200 && $code < 300,
        'code' => $code,
        'body' => $response === false ? '' : (string)$response,
        'error' => $error,
    ];
}

function push_firebase_access_token(array $credentials): ?string {
    $projectId = trim((string)($credentials['project_id'] ?? ''));
    $clientEmail = trim((string)($credentials['client_email'] ?? ''));
    $privateKey = (string)($credentials['private_key'] ?? '');

    if ($projectId === '' || $clientEmail === '' || $privateKey === '') return null;

    $cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'edusync_fcm_' . hash('sha256', $projectId) . '.json';

    if (is_file($cacheFile) && is_readable($cacheFile)) {
        $cache = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cache)
            && !empty($cache['access_token'])
            && (int)($cache['expires_at'] ?? 0) > time() + 60) {
            return (string)$cache['access_token'];
        }
    }

    $now = time();
    $header = push_base64url(json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT',
    ], JSON_UNESCAPED_SLASHES));

    $claims = push_base64url(json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ], JSON_UNESCAPED_SLASHES));

    $unsigned = $header . '.' . $claims;
    $signature = '';
    $signed = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$signed) return null;

    $assertion = $unsigned . '.' . push_base64url($signature);
    $postBody = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $assertion,
    ]);

    $response = push_http_post(
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        $postBody
    );

    if (!$response['ok']) {
        error_log('[push] No se pudo obtener token OAuth: ' . $response['code'] . ' ' . $response['error']);
        return null;
    }

    $decoded = json_decode($response['body'], true);
    $token = trim((string)($decoded['access_token'] ?? ''));
    if ($token === '') return null;

    $expiresIn = max(300, (int)($decoded['expires_in'] ?? 3600));
    @file_put_contents($cacheFile, json_encode([
        'access_token' => $token,
        'expires_at' => time() + $expiresIn,
    ], JSON_UNESCAPED_SLASHES));

    return $token;
}

function push_format_time(string $time): string {
    $timestamp = strtotime($time);
    if ($timestamp === false) return $time;
    $hour = (int)date('G', $timestamp);
    $minute = date('i', $timestamp);
    $suffix = $hour >= 12 ? 'p. m.' : 'a. m.';
    $displayHour = $hour % 12;
    if ($displayHour === 0) $displayHour = 12;
    return $displayHour . ':' . $minute . ' ' . $suffix;
}

function push_log_delivery(
    mysqli $db,
    int $schoolId,
    int $studentId,
    int $attendanceId,
    string $notificationType,
    ?int $deviceTokenId,
    string $deliveryStatus,
    int $providerCode,
    string $providerMessage
): void {
    if (!push_table_exists($db, 'push_notification_log')) return;

    $providerMessage = mb_substr($providerMessage, 0, 500, 'UTF-8');
    $stmt = $db->prepare(
        'INSERT INTO push_notification_log
        (school_id,student_id,attendance_id,notification_type,device_token_id,delivery_status,provider_code,provider_message)
        VALUES(?,?,NULLIF(?,0),?,NULLIF(?,0),?,?,?)'
    );
    if (!$stmt) return;

    $stmt->bind_param(
        'iiisiiss',
        $schoolId,
        $studentId,
        $attendanceId,
        $notificationType,
        $deviceTokenId,
        $deliveryStatus,
        $providerCode,
        $providerMessage
    );
    $stmt->execute();
    $stmt->close();
}

function push_send_attendance_notification(
    mysqli $db,
    int $schoolId,
    int $studentId,
    int $attendanceId,
    string $studentName,
    string $kind,
    string $date,
    string $time,
    string $status
): array {
    $result = [
        'configured' => false,
        'devices' => 0,
        'sent' => 0,
        'failed' => 0,
    ];

    if (!push_table_exists($db, 'student_device_tokens')) return $result;

    $credentials = push_load_firebase_credentials();
    if (!$credentials) {
        error_log('[push] Firebase no configurado: faltan credenciales del service account.');
        return $result;
    }

    $projectId = trim((string)($credentials['project_id'] ?? ''));
    $accessToken = push_firebase_access_token($credentials);
    if ($projectId === '' || !$accessToken) {
        error_log('[push] Firebase no configurado correctamente o no se pudo obtener OAuth.');
        return $result;
    }

    $result['configured'] = true;

    $stmt = $db->prepare(
        'SELECT id,fcm_token
         FROM student_device_tokens
         WHERE school_id=? AND student_id=? AND is_active=1
         ORDER BY last_seen_at DESC'
    );
    if (!$stmt) return $result;

    $stmt->bind_param('ii', $schoolId, $studentId);
    $stmt->execute();
    $query = $stmt->get_result();
    $devices = [];
    while ($row = $query->fetch_assoc()) $devices[] = $row;
    $stmt->close();

    $result['devices'] = count($devices);
    if (!$devices) return $result;

    $isExit = mb_strtolower(trim($kind), 'UTF-8') === 'salida';
    $title = $isExit ? 'Salida registrada' : 'Entrada registrada';
    $displayTime = push_format_time($time);

    if ($isExit) {
        $body = $studentName . ' salió del colegio a las ' . $displayTime . '.';
    } elseif (mb_strtolower(trim($status), 'UTF-8') === 'tarde') {
        $body = $studentName . ' registró su ingreso a las ' . $displayTime . ' · Tarde.';
    } else {
        $body = $studentName . ' ingresó al colegio a las ' . $displayTime . '.';
    }

    $endpoint = 'https://fcm.googleapis.com/v1/projects/'
        . rawurlencode($projectId)
        . '/messages:send';

    foreach ($devices as $device) {
        $deviceId = (int)$device['id'];
        $token = trim((string)$device['fcm_token']);
        if ($token === '') continue;

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => [
                    'type' => 'attendance',
                    'screen' => 'attendance',
                    'attendance_type' => $isExit ? 'Salida' : 'Entrada',
                    'attendance_id' => (string)$attendanceId,
                    'student_id' => (string)$studentId,
                    'school_id' => (string)$schoolId,
                    'date' => $date,
                    'time' => $time,
                    'status' => $status,
                ],
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'channel_id' => 'attendance_alerts',
                        'sound' => 'default',
                    ],
                ],
            ],
        ];

        $response = push_http_post(
            $endpoint,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=utf-8',
            ],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($response['ok']) {
            $result['sent']++;
            push_log_delivery(
                $db,
                $schoolId,
                $studentId,
                $attendanceId,
                'attendance_' . ($isExit ? 'exit' : 'entry'),
                $deviceId,
                'sent',
                $response['code'],
                ''
            );
            continue;
        }

        $result['failed']++;
        $providerMessage = $response['body'] !== ''
            ? $response['body']
            : $response['error'];

        push_log_delivery(
            $db,
            $schoolId,
            $studentId,
            $attendanceId,
            'attendance_' . ($isExit ? 'exit' : 'entry'),
            $deviceId,
            'failed',
            $response['code'],
            $providerMessage
        );

        $upper = strtoupper($providerMessage);
        if ($response['code'] === 404
            || strpos($upper, 'UNREGISTERED') !== false
            || strpos($upper, 'NOT_FOUND') !== false) {
            $disable = $db->prepare(
                'UPDATE student_device_tokens SET is_active=0 WHERE id=? AND school_id=?'
            );
            if ($disable) {
                $disable->bind_param('ii', $deviceId, $schoolId);
                $disable->execute();
                $disable->close();
            }
        }
    }

    return $result;
}
