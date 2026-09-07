<?php
include __DIR__ . '/db_connect.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$token = trim((string)($_GET['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('<div style="font-family:Arial;padding:30px">El enlace del recibo no es válido.</div>');
}
$tokenHash = hash('sha256', $token);
$table = $conn->query("SHOW TABLES LIKE 'receipt_share_tokens'");
if (!$table || !$table->num_rows) {
    http_response_code(503);
    exit('<div style="font-family:Arial;padding:30px">Los enlaces compartidos todavía no están habilitados.</div>');
}
$stmt = $conn->prepare('SELECT school_id,operation_id FROM receipt_share_tokens WHERE token_hash=? AND revoked_at IS NULL AND expires_at>NOW() LIMIT 1');
$stmt->bind_param('s', $tokenHash); $stmt->execute(); $share = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$share) {
    http_response_code(410);
    exit('<div style="font-family:Arial;padding:30px">Este enlace venció, fue revocado o no existe.</div>');
}
$publicReceiptContext = ['school_id' => (int)$share['school_id'], 'operation_id' => (int)$share['operation_id']];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recibo compartido</title></head><body style="margin:0;background:#f5f7fb;padding:20px"><?php include __DIR__ . '/receipt.php'; ?></body></html>
