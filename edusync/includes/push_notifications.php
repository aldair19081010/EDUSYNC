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

function push_column_exists(mysqli $db, string $table, string $column): bool {
    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
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

function push_create_student_event(
    mysqli $db,
    int $schoolId,
    int $studentId,
    string $notificationType,
    string $title,
    string $body,
    string $screen,
    ?string $entityType,
    ?int $entityId,
    ?string $dedupeKey,
    array $data = []
): array {
    if (!push_table_exists($db, 'student_notification_events')) {
        return ['id' => 0, 'created' => true];
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $entityIdValue = $entityId ?? 0;
    $dedupe = $dedupeKey !== null && trim($dedupeKey) !== '' ? trim($dedupeKey) : null;

    if ($dedupe !== null) {
        $existing = $db->prepare(
            'SELECT id FROM student_notification_events
             WHERE school_id=? AND student_id=? AND dedupe_key=? LIMIT 1'
        );
        if ($existing) {
            $existing->bind_param('iis', $schoolId, $studentId, $dedupe);
            $existing->execute();
            $row = $existing->get_result()->fetch_assoc();
            $existing->close();
            if ($row) return ['id' => (int)$row['id'], 'created' => false];
        }
    }

    $stmt = $db->prepare(
        'INSERT INTO student_notification_events
        (school_id,student_id,notification_type,title,body,screen,entity_type,entity_id,dedupe_key,data_json)
        VALUES(?,?,?,?,?,?,?,NULLIF(?,0),?,?)'
    );
    if (!$stmt) return ['id' => 0, 'created' => true];

    $stmt->bind_param(
        'iisssssiss',
        $schoolId,
        $studentId,
        $notificationType,
        $title,
        $body,
        $screen,
        $entityType,
        $entityIdValue,
        $dedupe,
        $json
    );

    if (!$stmt->execute()) {
        if ((int)$stmt->errno === 1062 && $dedupe !== null) {
            $stmt->close();
            $existing = $db->prepare(
                'SELECT id FROM student_notification_events
                 WHERE school_id=? AND student_id=? AND dedupe_key=? LIMIT 1'
            );
            if ($existing) {
                $existing->bind_param('iis', $schoolId, $studentId, $dedupe);
                $existing->execute();
                $row = $existing->get_result()->fetch_assoc();
                $existing->close();
                return ['id' => (int)($row['id'] ?? 0), 'created' => false];
            }
            return ['id' => 0, 'created' => false];
        }
        $stmt->close();
        return ['id' => 0, 'created' => true];
    }

    $id = (int)$stmt->insert_id;
    $stmt->close();
    return ['id' => $id, 'created' => true];
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
    string $providerMessage,
    int $notificationEventId = 0
): void {
    if (!push_table_exists($db, 'push_notification_log')) return;

    $providerMessage = mb_substr($providerMessage, 0, 500, 'UTF-8');

    if (push_column_exists($db, 'push_notification_log', 'notification_event_id')) {
        $stmt = $db->prepare(
            'INSERT INTO push_notification_log
            (school_id,student_id,attendance_id,notification_event_id,notification_type,device_token_id,delivery_status,provider_code,provider_message)
            VALUES(?,?,NULLIF(?,0),NULLIF(?,0),?,NULLIF(?,0),?,?,?)'
        );
        if (!$stmt) return;

        $stmt->bind_param(
            'iiiisisis',
            $schoolId,
            $studentId,
            $attendanceId,
            $notificationEventId,
            $notificationType,
            $deviceTokenId,
            $deliveryStatus,
            $providerCode,
            $providerMessage
        );
    } else {
        $stmt = $db->prepare(
            'INSERT INTO push_notification_log
            (school_id,student_id,attendance_id,notification_type,device_token_id,delivery_status,provider_code,provider_message)
            VALUES(?,?,NULLIF(?,0),?,NULLIF(?,0),?,?,?)'
        );
        if (!$stmt) return;

        $stmt->bind_param(
            'iiisisis',
            $schoolId,
            $studentId,
            $attendanceId,
            $notificationType,
            $deviceTokenId,
            $deliveryStatus,
            $providerCode,
            $providerMessage
        );
    }

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
    $isExit = mb_strtolower(trim($kind), 'UTF-8') === 'salida';
    $title = $isExit ? 'Salida registrada' : 'Entrada registrada';
    $displayTime = push_format_time($time);

    if ($isExit) {
        $body = $studentName . ' registró su salida · ' . $displayTime;
    } elseif (mb_strtolower(trim($status), 'UTF-8') === 'tarde') {
        $title = 'Entrada registrada · Tarde';
        $body = $studentName . ' registró su entrada · ' . $displayTime;
    } else {
        $body = $studentName . ' registró su entrada · ' . $displayTime;
    }

    return push_send_student_event(
        $db,
        $schoolId,
        $studentId,
        'attendance_' . ($isExit ? 'exit' : 'entry'),
        $title,
        $body,
        'attendance',
        'attendance_alerts',
        'attendance',
        $attendanceId,
        'attendance:' . $attendanceId . ':' . ($isExit ? 'exit' : 'entry'),
        [
            'attendance_type' => $isExit ? 'Salida' : 'Entrada',
            'attendance_id' => (string)$attendanceId,
            'date' => $date,
            'time' => $time,
            'status' => $status,
        ]
    );
}

function push_send_payment_notification(
    mysqli $db,
    int $schoolId,
    int $studentId,
    int $operationId,
    float $amount,
    array $conceptNames,
    string $paymentDate,
    string $receipt
): array {
    $cleanConcepts = [];
    foreach ($conceptNames as $conceptName) {
        $value = trim((string)$conceptName);
        if ($value !== '' && !in_array($value, $cleanConcepts, true)) {
            $cleanConcepts[] = $value;
        }
    }

    if (count($cleanConcepts) === 1) {
        $conceptLabel = $cleanConcepts[0];
    } elseif (count($cleanConcepts) > 1) {
        $conceptLabel = count($cleanConcepts) . ' conceptos';
    } else {
        $conceptLabel = 'Pago escolar';
    }

    $title = 'Pago registrado';
    $body = 'S/ ' . number_format($amount, 2, '.', '') . ' · ' . $conceptLabel;

    return push_send_student_event(
        $db,
        $schoolId,
        $studentId,
        'payment_created',
        $title,
        $body,
        'payments',
        'payment_alerts',
        'payment_operation',
        $operationId,
        'payment:' . $operationId,
        [
            'operation_id' => (string)$operationId,
            'amount' => number_format($amount, 2, '.', ''),
            'concept' => $conceptLabel,
            'receipt' => $receipt,
            'date' => $paymentDate,
        ]
    );
}

function push_send_student_event(
    mysqli $db,
    int $schoolId,
    int $studentId,
    string $notificationType,
    string $title,
    string $body,
    string $screen,
    string $channelId,
    ?string $entityType,
    ?int $entityId,
    ?string $dedupeKey,
    array $data = []
): array {
    $result = [
        'configured' => false,
        'devices' => 0,
        'pending_devices' => 0,
        'already_sent' => 0,
        'sent' => 0,
        'failed' => 0,
        'event_id' => 0,
        'event_created' => false,
        'retry' => false,
        'duplicate' => false,
    ];

    $event = push_create_student_event(
        $db,
        $schoolId,
        $studentId,
        $notificationType,
        $title,
        $body,
        $screen,
        $entityType,
        $entityId,
        $dedupeKey,
        $data
    );
    $result['event_id'] = (int)$event['id'];
    $result['event_created'] = !empty($event['created']);
    $result['retry'] = !$result['event_created'];

    if (!push_table_exists($db, 'student_device_tokens')) return $result;

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

    $eventAwareLog = $result['event_id'] > 0
        && push_table_exists($db, 'push_notification_log')
        && push_column_exists($db, 'push_notification_log', 'notification_event_id');

    if (!$result['event_created'] && !$eventAwareLog) {
        // Sin la migración de reintentos no existe una forma segura de distinguir
        // un evento ya entregado de uno pendiente. Evitamos duplicar notificaciones.
        $result['duplicate'] = true;
        $result['retry'] = false;
        return $result;
    }

    $pendingDevices = [];
    foreach ($devices as $device) {
        $deviceTokenId = (int)$device['id'];

        if ($eventAwareLog) {
            $delivered = $db->prepare(
                "SELECT id
                 FROM push_notification_log
                 WHERE notification_event_id=?
                   AND device_token_id=?
                   AND delivery_status='sent'
                 LIMIT 1"
            );
            if ($delivered) {
                $delivered->bind_param('ii', $result['event_id'], $deviceTokenId);
                $delivered->execute();
                $wasSent = (bool)$delivered->get_result()->fetch_assoc();
                $delivered->close();
                if ($wasSent) {
                    $result['already_sent']++;
                    continue;
                }
            }
        }

        $pendingDevices[] = $device;
    }

    $result['pending_devices'] = count($pendingDevices);
    if (!$pendingDevices) {
        $result['duplicate'] = true;
        $result['retry'] = false;
        return $result;
    }

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

    $payloadData = array_merge($data, [
        'type' => $notificationType,
        'screen' => $screen,
        'student_id' => (string)$studentId,
        'school_id' => (string)$schoolId,
        'notification_event_id' => (string)$result['event_id'],
    ]);

    $endpoint = 'https://fcm.googleapis.com/v1/projects/'
        . rawurlencode($projectId)
        . '/messages:send';
    $logAttendanceId = $entityType === 'attendance' ? (int)($entityId ?? 0) : 0;

    foreach ($pendingDevices as $device) {
        $deviceTokenId = (int)$device['id'];
        $token = trim((string)$device['fcm_token']);
        if ($token === '') continue;

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => array_map(
                    static fn($value) => is_scalar($value) || $value === null
                        ? (string)$value
                        : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $payloadData
                ),
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'channel_id' => $channelId,
                        'sound' => 'default',
                        'icon' => 'ic_notification',
                        'color' => '#1565C0',
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
                $logAttendanceId,
                $notificationType,
                $deviceTokenId,
                'sent',
                $response['code'],
                '',
                $result['event_id']
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
            $logAttendanceId,
            $notificationType,
            $deviceTokenId,
            'failed',
            $response['code'],
            $providerMessage,
            $result['event_id']
        );

        $upper = strtoupper($providerMessage);
        if ($response['code'] === 404
            || strpos($upper, 'UNREGISTERED') !== false
            || strpos($upper, 'NOT_FOUND') !== false) {
            $disable = $db->prepare(
                'UPDATE student_device_tokens SET is_active=0 WHERE id=? AND school_id=?'
            );
            if ($disable) {
                $disable->bind_param('ii', $deviceTokenId, $schoolId);
                $disable->execute();
                $disable->close();
            }
        }
    }

    return $result;
}

function push_send_attendance_state_notification(
    mysqli $db,
    int $schoolId,
    int $studentId,
    int $attendanceId,
    string $date,
    string $oldStatus,
    string $newStatus,
    string $time = ''
): array {
    $newStatus = trim($newStatus);
    $oldStatus = trim($oldStatus);

    if ($oldStatus === '') {
        $title = 'Estado de asistencia registrado';
        $body = 'Tu asistencia del ' . date('d/m/Y', strtotime($date)) . ' quedó como ' . $newStatus . '.';
        $dedupe = 'attendance:' . $attendanceId . ':created:' . mb_strtolower($newStatus, 'UTF-8');
    } else {
        $title = 'Asistencia actualizada';
        $body = 'Tu asistencia del ' . date('d/m/Y', strtotime($date)) . ' cambió de ' . $oldStatus . ' a ' . $newStatus . '.';
        $dedupe = 'attendance:' . $attendanceId . ':status:' . mb_strtolower($newStatus, 'UTF-8');
    }

    return push_send_student_event(
        $db,
        $schoolId,
        $studentId,
        'attendance_status',
        $title,
        $body,
        'attendance',
        'attendance_alerts',
        'attendance',
        $attendanceId,
        $dedupe,
        [
            'attendance_id' => (string)$attendanceId,
            'date' => $date,
            'time' => $time,
            'old_status' => $oldStatus,
            'status' => $newStatus,
        ]
    );
}

function push_send_debt_notification(
    mysqli $db,
    int $schoolId,
    int $studentId,
    int $debtId,
    string $concept,
    float $balance,
    ?string $dueDate,
    string $stage
): array {
    $amount = 'S/ ' . number_format($balance, 2, '.', '');
    $concept = trim($concept) !== '' ? trim($concept) : 'Obligación pendiente';

    if ($stage === 'assigned') {
        $title = 'Nueva deuda asignada';
        $body = $concept . ' · ' . $amount;
        if ($dueDate) {
            $body .= ' · vence el ' . date('d/m/Y', strtotime((string)$dueDate));
        }
        $body .= '.';
    } elseif ($stage === 'upcoming3') {
        $title = 'Pago próximo a vencer';
        $body = $concept . ' · ' . $amount . ' vence el ' . date('d/m/Y', strtotime((string)$dueDate)) . '.';
    } elseif ($stage === 'due_today') {
        $title = 'Pago vence hoy';
        $body = $concept . ' · ' . $amount . ' vence hoy.';
    } else {
        $title = 'Pago vencido';
        $body = $concept . ' · ' . $amount . ' está pendiente.';
    }

    return push_send_student_event(
        $db,
        $schoolId,
        $studentId,
        'debt_' . $stage,
        $title,
        $body,
        'debts',
        'payment_alerts',
        'debt',
        $debtId,
        'debt:' . $debtId . ':' . $stage,
        [
            'debt_id' => (string)$debtId,
            'concept' => $concept,
            'balance' => number_format($balance, 2, '.', ''),
            'due_date' => (string)$dueDate,
            'stage' => $stage,
        ]
    );
}


function push_send_bulk_debt_notification(
    mysqli $db,
    int $schoolId,
    int $studentId,
    array $debtIds,
    int $count,
    float $totalBalance,
    string $stage = 'overdue',
    ?string $dueDate = null
): array {
    $debtIds = array_values(array_unique(array_filter(array_map('intval', $debtIds))));
    sort($debtIds);

    $amount = 'S/ ' . number_format($totalBalance, 2, '.', '');

    if ($stage === 'assigned') {
        $title = $count === 1 ? 'Nueva deuda asignada' : 'Nuevas deudas asignadas';
        $body = $count === 1
            ? 'Se asignó una obligación por ' . $amount
            : 'Se asignaron ' . $count . ' obligaciones por ' . $amount;
        if ($dueDate) {
            $body .= ' · vencen el ' . date('d/m/Y', strtotime($dueDate));
        }
        $body .= '.';
    } elseif ($stage === 'upcoming3') {
        $title = $count === 1 ? 'Pago próximo a vencer' : 'Pagos próximos a vencer';
        $body = $count === 1
            ? 'Tienes una obligación por ' . $amount
            : 'Tienes ' . $count . ' obligaciones por ' . $amount;
        if ($dueDate) {
            $body .= ' · vencen el ' . date('d/m/Y', strtotime($dueDate));
        }
        $body .= '.';
    } elseif ($stage === 'due_today') {
        $title = $count === 1 ? 'Pago vence hoy' : 'Pagos vencen hoy';
        $body = $count === 1
            ? 'Tienes una obligación por ' . $amount . ' que vence hoy.'
            : 'Tienes ' . $count . ' obligaciones por ' . $amount . ' que vencen hoy.';
    } else {
        $title = $count === 1 ? 'Pago vencido' : 'Pagos vencidos';
        $body = $count === 1
            ? 'Se asignó una obligación pendiente por ' . $amount . '.'
            : 'Se asignaron ' . $count . ' obligaciones pendientes por ' . $amount . '.';
    }

    return push_send_student_event(
        $db,
        $schoolId,
        $studentId,
        'debt_bulk_' . $stage,
        $title,
        $body,
        'debts',
        'payment_alerts',
        'debt_bulk',
        null,
        'debt-bulk:' . $studentId . ':' . $stage . ':' . hash('sha256', implode(',', $debtIds)),
        [
            'debt_ids' => implode(',', $debtIds),
            'count' => (string)$count,
            'balance' => number_format($totalBalance, 2, '.', ''),
            'due_date' => (string)$dueDate,
            'stage' => $stage,
        ]
    );
}


function push_send_grade_notification(
    mysqli $db,
    int $schoolId,
    int $studentId,
    string $courseName,
    array $evaluationNames,
    int $createdCount,
    int $updatedCount,
    int $bimester,
    int $academicYear,
    array $evaluationIds = []
): array {
    $courseName = trim($courseName) !== '' ? trim($courseName) : 'tu curso';
    $evaluationNames = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        $evaluationNames
    ))));
    $evaluationIds = array_values(array_unique(array_filter(array_map('intval', $evaluationIds))));
    $total = max(0, $createdCount) + max(0, $updatedCount);

    if ($total <= 0) {
        return [
            'configured' => false,
            'devices' => 0,
            'pending_devices' => 0,
            'sent' => 0,
            'failed' => 0,
            'event_id' => 0,
            'duplicate' => false,
        ];
    }

    if ($total === 1 && count($evaluationNames) === 1) {
        $isNew = $createdCount > 0;
        $title = $isNew ? 'Nueva calificación' : 'Calificación actualizada';
        $verb = $isNew ? 'Se registró' : 'Se actualizó';
        $body = $verb . ' una calificación en ' . $courseName
            . ' · ' . $evaluationNames[0] . '.';
        $type = $isNew ? 'grade_created' : 'grade_updated';
    } elseif ($createdCount > 0 && $updatedCount === 0) {
        $title = 'Nuevas calificaciones';
        $body = 'Se registraron ' . $createdCount
            . ' calificaciones en ' . $courseName . '.';
        $type = 'grades_created';
    } else {
        $title = 'Calificaciones actualizadas';
        $body = 'Se actualizaron ' . $total
            . ' calificaciones en ' . $courseName . '.';
        $type = 'grades_updated';
    }

    return push_send_student_event(
        $db,
        $schoolId,
        $studentId,
        $type,
        $title,
        $body,
        'grades',
        'payment_alerts',
        'grades',
        null,
        ($createdCount > 0 && $updatedCount === 0)
            ? 'grade-created:' . $studentId . ':' . hash('sha256', implode(',', $evaluationIds))
            : null,
        [
            'course' => $courseName,
            'evaluation_ids' => implode(',', $evaluationIds),
            'bimestre' => (string)$bimester,
            'year' => (string)$academicYear,
            'created_count' => (string)$createdCount,
            'updated_count' => (string)$updatedCount,
        ]
    );
}


function push_send_announcement_notification(
    mysqli $db,
    int $schoolId,
    int $studentId,
    int $announcementId,
    string $title,
    string $content
): array {
    $title = trim($title) !== '' ? trim($title) : 'Comunicado institucional';
    $preview = trim(preg_replace('/\s+/u', ' ', $content));
    if (mb_strlen($preview, 'UTF-8') > 180) {
        $preview = rtrim(mb_substr($preview, 0, 177, 'UTF-8')) . '...';
    }

    return push_send_student_event(
        $db,
        $schoolId,
        $studentId,
        'announcement',
        $title,
        $preview,
        'announcements',
        'announcement_alerts',
        'announcement',
        $announcementId,
        'announcement:' . $announcementId,
        [
            'announcement_id' => (string)$announcementId,
        ]
    );
}


function push_send_collection_notification(
    mysqli $db,
    int $schoolId,
    int $studentId,
    int $campaignId,
    array $debtIds,
    array $conceptNames,
    int $debtCount,
    float $totalBalance
): array {
    $debtIds = array_values(array_unique(array_filter(array_map('intval', $debtIds))));
    $conceptNames = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        $conceptNames
    ))));

    $amount = 'S/ ' . number_format($totalBalance, 2, '.', '');

    if ($debtCount <= 1) {
        $title = 'Pago pendiente';
        $concept = $conceptNames[0] ?? 'obligación pendiente';
        $body = 'Tienes un saldo pendiente de ' . $amount . ' por ' . $concept
            . '. Ingresa a Mis Deudas para revisar el detalle.';
    } else {
        $title = 'Pagos pendientes';
        $body = 'Tienes ' . $debtCount . ' obligaciones pendientes por un total de '
            . $amount . '. Ingresa a Mis Deudas para revisar el detalle.';
    }

    return push_send_student_event(
        $db,
        $schoolId,
        $studentId,
        'collection_notice',
        $title,
        $body,
        'debts',
        'payment_alerts',
        'collection_campaign',
        $campaignId,
        'collection:' . $campaignId . ':' . $studentId,
        [
            'campaign_id' => (string)$campaignId,
            'debt_ids' => implode(',', $debtIds),
            'debt_count' => (string)$debtCount,
            'balance' => number_format($totalBalance, 2, '.', ''),
        ]
    );
}
