<?php
declare(strict_types=1);

/**
 * Código QR para portería EduSync, versión 1.
 *
 * ESQ1.<school_id>.<student_id>.<HMAC-SHA256-base64url>
 *
 * El QR no incluye DNI ni nombres. El secreto solo existe en el servidor.
 * Los carnés de barras antiguos (student.id_no) se validan por otra vía.
 */
function edusync_attendance_qr_secret(): string {
    $secret = getenv('EDUSYNC_ATTENDANCE_QR_KEY');
    if (!is_string($secret) || strlen($secret) < 32) {
        throw new RuntimeException('El QR firmado aún no está configurado en el servidor.');
    }
    return $secret;
}

function edusync_attendance_qr_issue(int $schoolId, int $studentId): string {
    if ($schoolId <= 0 || $studentId <= 0) {
        throw new InvalidArgumentException('Colegio o estudiante no válido.');
    }
    $payload = 'ESQ1.'.$schoolId.'.'.$studentId;
    $mac = hash_hmac('sha256', $payload, edusync_attendance_qr_secret(), true);
    $signature = rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    return $payload.'.'.$signature;
}

/**
 * Devuelve el ID únicamente tras verificar la firma y el colegio.
 * El llamador debe comprobar además que el alumno existe y está activo.
 */
function edusync_attendance_qr_verify(string $code, int $schoolId): int {
    if (!preg_match('/^ESQ1\.([1-9][0-9]{0,9})\.([1-9][0-9]{0,9})\.([A-Za-z0-9_-]{43})$/D', $code, $parts)) {
        throw new InvalidArgumentException('El QR de EduSync no es válido.');
    }
    if ($schoolId <= 0 || (int)$parts[1] !== $schoolId) {
        throw new DomainException('Este QR pertenece a otra institución.');
    }
    $expected = edusync_attendance_qr_issue($schoolId, (int)$parts[2]);
    if (!hash_equals($expected, $code)) {
        throw new InvalidArgumentException('La firma del QR no es válida.');
    }
    return (int)$parts[2];
}
