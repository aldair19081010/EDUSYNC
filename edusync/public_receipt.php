<?php
ini_set('display_errors', 0);
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/includes/receipt_share_token.php';

$token = trim((string)($_GET['token'] ?? ''));
$payload = null;

try {
    if ($token !== '') {
        $payload = receipt_share_verify_token($token);
    }
} catch (Throwable $e) {
    error_log('[public_receipt] ' . $e->getMessage());
}

if (!$payload) {
    http_response_code(403);
    ?><!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Recibo no disponible</title>
        <style>
            body{font-family:Arial,sans-serif;background:#f5f7fb;color:#344054;margin:0;padding:32px}
            .box{max-width:620px;margin:80px auto;background:#fff;padding:28px;border-radius:14px;border:1px solid #e4e7ec;text-align:center}
            h2{margin-top:0;color:#b42318}
        </style>
    </head>
    <body><div class="box"><h2>Recibo no disponible</h2><p>El enlace es inválido o ha expirado. Solicita un nuevo enlace desde EduSync.</p></div></body>
    </html><?php
    exit;
}

include __DIR__ . '/db_connect.php';

$schoolId = (int)($payload['school_id'] ?? 0);
$studentId = (int)($payload['student_id'] ?? 0);
$operationId = (int)($payload['operation_id'] ?? 0);
$paymentId = (int)($payload['payment_id'] ?? 0);
$debtId = (int)($payload['ef_id'] ?? 0);

$valid = false;
if ($schoolId > 0 && $studentId > 0) {
    if ($operationId > 0) {
        $stmt = $conn->prepare('SELECT id FROM payment_operations WHERE id=? AND student_id=? AND school_id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('iii', $operationId, $studentId, $schoolId);
            $stmt->execute();
            $valid = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } elseif ($paymentId > 0) {
        $stmt = $conn->prepare('SELECT p.id FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id INNER JOIN student s ON s.id=ef.student_id WHERE p.id=? AND s.id=? AND s.school_id=? LIMIT 1');
        $stmt->bind_param('iii', $paymentId, $studentId, $schoolId);
        $stmt->execute();
        $valid = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
    } elseif ($debtId > 0) {
        $stmt = $conn->prepare('SELECT ef.id FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id WHERE ef.id=? AND s.id=? AND s.school_id=? LIMIT 1');
        $stmt->bind_param('iii', $debtId, $studentId, $schoolId);
        $stmt->execute();
        $valid = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$valid) {
    http_response_code(404);
    exit('No se encontró el recibo solicitado.');
}

$publicReceiptContext = [
    'school_id' => $schoolId,
    'operation_id' => $operationId,
    'payment_id' => $paymentId,
    'debt_id' => $debtId,
];

?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recibo EduSync</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#f4f6f9;padding:18px;color:#344054}
        .receipt-public-actions{max-width:1050px;margin:0 auto 12px;display:flex;gap:8px;justify-content:flex-end}
        .receipt-public-actions button{border:0;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer}
        .receipt-print{background:#2f6fed;color:#fff}
        .receipt-share{background:#fff;color:#344054;border:1px solid #d0d5dd!important}
        .text-right{text-align:right}.text-success{color:#067647}.text-muted{color:#667085}.small{font-size:.78rem}
        .table-responsive{width:100%;overflow-x:auto}
        .alert{padding:10px 12px;border-radius:8px;margin-top:12px}
        .alert-info{background:#eff8ff;color:#175cd3}.alert-danger{background:#fef3f2;color:#b42318}
        @media print{body{background:#fff;padding:0}.receipt-public-actions{display:none!important}}
    </style>
</head>
<body>
<div class="receipt-public-actions no-print">
    <button class="receipt-share" type="button" onclick="shareReceipt()">Compartir</button>
    <button class="receipt-print" type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
</div>
<?php include __DIR__ . '/receipt.php'; ?>
<script>
async function shareReceipt(){
    const data = {title: document.title, text: 'Recibo de pago EduSync', url: window.location.href};
    if (navigator.share) {
        try { await navigator.share(data); } catch (e) {}
    } else if (navigator.clipboard) {
        await navigator.clipboard.writeText(window.location.href);
        alert('Enlace copiado.');
    }
}
</script>
</body>
</html>
