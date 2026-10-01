<?php
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
    exit;
}

include __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/receipt_share_token.php';

function receipt_link_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $schoolId = (int)($_POST['school_id'] ?? 0);
    $dni = trim((string)($_POST['dni'] ?? ''));
    $operationId = (int)($_POST['operation_id'] ?? 0);
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $debtId = (int)($_POST['ef_id'] ?? 0);

    if ($schoolId <= 0 || $dni === '') {
        receipt_link_out([
            'status' => 'error',
            'message' => 'Colegio y DNI son obligatorios.'
        ], 400);
    }

    if ($operationId <= 0 && $paymentId <= 0 && $debtId <= 0) {
        receipt_link_out([
            'status' => 'error',
            'message' => 'No se recibió un identificador de pago válido.'
        ], 400);
    }

    $stmt = $conn->prepare('SELECT id FROM student WHERE id_no=? AND school_id=? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('si', $dni, $schoolId);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        receipt_link_out([
            'status' => 'error',
            'message' => 'Estudiante no encontrado.'
        ], 404);
    }

    $studentId = (int)$student['id'];
    $owned = false;

    if ($operationId > 0) {
        $stmt = $conn->prepare('SELECT id FROM payment_operations WHERE id=? AND student_id=? AND school_id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('iii', $operationId, $studentId, $schoolId);
            $stmt->execute();
            $owned = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } elseif ($paymentId > 0) {
        $stmt = $conn->prepare('SELECT p.id,p.ef_id FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id WHERE p.id=? AND ef.student_id=? LIMIT 1');
        $stmt->bind_param('ii', $paymentId, $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $owned = true;
            if ($debtId <= 0) $debtId = (int)$row['ef_id'];
        }
    } elseif ($debtId > 0) {
        $stmt = $conn->prepare('SELECT id FROM student_ef_list WHERE id=? AND student_id=? LIMIT 1');
        $stmt->bind_param('ii', $debtId, $studentId);
        $stmt->execute();
        $owned = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$owned) {
        receipt_link_out([
            'status' => 'error',
            'message' => 'El recibo no pertenece al estudiante autenticado.'
        ], 403);
    }

    $token = receipt_share_create_token([
        'school_id' => $schoolId,
        'student_id' => $studentId,
        'operation_id' => $operationId,
        'payment_id' => $paymentId,
        'ef_id' => $debtId,
    ], 86400);

    receipt_link_out([
        'status' => 'ok',
        'url' => receipt_share_public_url($token),
        'expires_in' => 86400,
        'expires_at' => date('c', time() + 86400),
    ]);
} catch (Throwable $e) {
    error_log('[receipt_link API] ' . $e->getMessage());
    receipt_link_out([
        'status' => 'error',
        'message' => 'No se pudo generar el enlace del recibo.'
    ], 500);
}
