<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/attendance_qr_code.php';

function qr_expect_failure(callable $callback, string $reason): void {
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        return;
    } catch (DomainException $e) {
        return;
    } catch (RuntimeException $e) {
        return;
    }
    throw new RuntimeException('Debería rechazar: '.$reason);
}

putenv('EDUSYNC_ATTENDANCE_QR_KEY='.str_repeat('safe-test-key-', 4));
$code = edusync_attendance_qr_issue(12, 345);
if (edusync_attendance_qr_verify($code, 12) !== 345) {
    throw new RuntimeException('No verificó el QR correcto.');
}
if (strpos($code, '345') === false || strlen($code) > 128) {
    throw new RuntimeException('Formato QR incorrecto.');
}
qr_expect_failure(static function () use ($code): void {
    edusync_attendance_qr_verify($code, 13);
}, 'QR de otro colegio');
qr_expect_failure(static function () use ($code): void {
    edusync_attendance_qr_verify(substr($code, 0, -1).'X', 12);
}, 'firma adulterada');
qr_expect_failure(static function (): void {
    edusync_attendance_qr_verify('ESQ1.12.345.not-valid', 12);
}, 'QR mal formado');
qr_expect_failure(static function (): void {
    edusync_attendance_qr_issue(0, 345);
}, 'colegio inválido');
putenv('EDUSYNC_ATTENDANCE_QR_KEY');
qr_expect_failure(static function (): void {
    edusync_attendance_qr_issue(12, 345);
}, 'clave no configurada');
echo "QR firmado: pruebas correctas\n";
