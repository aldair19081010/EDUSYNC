<?php
// Token temporal y específico para compartir/ver un único recibo sin exponer la sesión.

function receipt_share_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function receipt_share_base64url_decode(string $value): string|false {
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function receipt_share_secret(): string {
    $tmpDir = __DIR__ . '/../tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0700, true);
    }

    $secretFile = $tmpDir . '/receipt_share_secret.key';

    if (!is_file($secretFile)) {
        $secret = bin2hex(random_bytes(32));
        $handle = @fopen($secretFile, 'x');
        if ($handle) {
            fwrite($handle, $secret);
            fclose($handle);
            @chmod($secretFile, 0600);
        }
    }

    $secret = trim((string)@file_get_contents($secretFile));
    if ($secret === '') {
        throw new RuntimeException('No se pudo inicializar la clave de recibos compartidos.');
    }

    return $secret;
}

function receipt_share_create_token(array $payload, int $ttlSeconds = 86400): string {
    $payload['exp'] = time() + max(300, $ttlSeconds);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo generar el token del recibo.');
    }

    $encoded = receipt_share_base64url_encode($json);
    $signature = hash_hmac('sha256', $encoded, receipt_share_secret(), true);

    return $encoded . '.' . receipt_share_base64url_encode($signature);
}

function receipt_share_verify_token(string $token): ?array {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;

    [$encoded, $signatureEncoded] = $parts;
    $signature = receipt_share_base64url_decode($signatureEncoded);
    if ($signature === false) return null;

    $expected = hash_hmac('sha256', $encoded, receipt_share_secret(), true);
    if (!hash_equals($expected, $signature)) return null;

    $json = receipt_share_base64url_decode($encoded);
    if ($json === false) return null;

    $payload = json_decode($json, true);
    if (!is_array($payload)) return null;

    $exp = (int)($payload['exp'] ?? 0);
    if ($exp <= time()) return null;

    return $payload;
}

function receipt_share_public_url(string $token): string {
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwarded !== '') {
        $scheme = trim(explode(',', $forwarded)[0]);
    } else {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        throw new RuntimeException('No se pudo determinar el host de EduSync.');
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/api/receipt_link.php'));
    $base = rtrim(dirname(dirname($script)), '/');
    if ($base === '.' || $base === '/') $base = '';

    return $scheme . '://' . $host . $base . '/public_receipt.php?token=' . rawurlencode($token);
}
