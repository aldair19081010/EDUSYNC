<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ob_start();
include_once __DIR__ . '/../session_config.php';
include __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/debt_engine.php';

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

function my_payments_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function my_payments_is_confirmed($status): bool {
    $value = mb_strtolower(trim((string)$status), 'UTF-8');
    return $value === '' || in_array($value, ['confirmado', 'confirmed', 'pagado', 'completado'], true);
}

function my_payments_operation_is_void(array $operation): bool {
    if (!empty($operation['corrected_by_id'])) return true;
    $status = mb_strtolower(trim((string)($operation['status'] ?? '')), 'UTF-8');
    if ($status === '') return false;
    return strpos($status, 'anul') !== false
        || strpos($status, 'cancel') !== false
        || strpos($status, 'correg') !== false;
}

function my_payments_unique_values(array $values): array {
    $clean = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '' && !in_array($value, $clean, true)) $clean[] = $value;
    }
    return $clean;
}

try {
    $dni = trim($_GET['dni'] ?? ($_POST['dni'] ?? ''));
    $requestedSchoolId = (int)($_GET['school_id'] ?? ($_POST['school_id'] ?? 0));
    $student = null;
    $authMode = 'legacy_dni';

    if (!empty($_SESSION['student_logged_in']) && !empty($_SESSION['student_id'])) {
        $studentId = (int)$_SESSION['student_id'];
        $stmt = $conn->prepare('SELECT id,id_no,name,school_id FROM student WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $authMode = 'session';
    } else {
        if ($dni === '') my_payments_out(['status' => 'error', 'message' => 'DNI no recibido'], 400);

        if ($requestedSchoolId > 0) {
            $stmt = $conn->prepare('SELECT id,id_no,name,school_id FROM student WHERE id_no=? AND school_id=? ORDER BY id DESC LIMIT 1');
            $stmt->bind_param('si', $dni, $requestedSchoolId);
        } else {
            $stmt = $conn->prepare('SELECT id,id_no,name,school_id FROM student WHERE id_no=? ORDER BY id DESC LIMIT 1');
            $stmt->bind_param('s', $dni);
        }

        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$student) my_payments_out(['status' => 'error', 'message' => 'Estudiante no encontrado'], 404);

    $studentId = (int)$student['id'];
    $schoolId = (int)($student['school_id'] ?? 0);
    $data = [];

    $hasPaymentStatus = debt_engine_column_exists($conn, 'payments', 'payment_status');
    $hasOperationId = debt_engine_column_exists($conn, 'payments', 'operation_id');
    $hasOperations = debt_engine_table_exists($conn, 'payment_operations') && $hasOperationId;

    $operationRows = [];
    $operationLines = [];
    $operationMethods = [];

    if ($hasOperations) {
        $hasCorrection = debt_engine_column_exists($conn, 'payment_operations', 'corrected_by_id');
        $correctionExpr = $hasCorrection ? 'po.corrected_by_id' : 'NULL';
        $statusExpr = debt_engine_column_exists($conn, 'payment_operations', 'status') ? 'po.status' : "'Confirmado'";
        $dateExpr = debt_engine_column_exists($conn, 'payment_operations', 'payment_date') ? 'po.payment_date' : 'po.date_created';
        $receiptExpr = debt_engine_column_exists($conn, 'payment_operations', 'receipt_full') ? 'po.receipt_full' : "CONCAT('OP-',po.id)";

        $stmt = $conn->prepare("SELECT po.id,$receiptExpr receipt_full,$dateExpr payment_date,$statusExpr status,$correctionExpr corrected_by_id FROM payment_operations po WHERE po.student_id=? AND po.school_id=? ORDER BY payment_date DESC,po.id DESC");
        $stmt->bind_param('ii', $studentId, $schoolId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $operationRows[(int)$row['id']] = $row;
        $stmt->close();

        $paymentStatusExpr = $hasPaymentStatus ? "COALESCE(p.payment_status,'Confirmado')" : "'Confirmado'";
        $stmt = $conn->prepare("SELECT p.operation_id,p.id pid,p.ef_id,p.amount,$paymentStatusExpr payment_status,c.course,ay.year anio_academico FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id LEFT JOIN courses c ON c.id=ef.course_id LEFT JOIN academic_year ay ON ay.id=c.academic_year_id WHERE ef.student_id=? AND p.operation_id IS NOT NULL ORDER BY p.id");
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $oid = (int)$row['operation_id'];
            if (!isset($operationLines[$oid])) $operationLines[$oid] = [];
            $operationLines[$oid][] = $row;
        }
        $stmt->close();

        if (debt_engine_table_exists($conn, 'payment_operation_methods') && debt_engine_table_exists($conn, 'payment_methods')) {
            $stmt = $conn->prepare('SELECT pom.operation_id,pm.name method_name,pom.amount FROM payment_operation_methods pom INNER JOIN payment_methods pm ON pm.id=pom.payment_method_id INNER JOIN payment_operations po ON po.id=pom.operation_id WHERE po.student_id=? AND po.school_id=? ORDER BY pom.operation_id,pom.id');
            $stmt->bind_param('ii', $studentId, $schoolId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $oid = (int)$row['operation_id'];
                if (!isset($operationMethods[$oid])) $operationMethods[$oid] = [];
                $operationMethods[$oid][] = [
                    'nombre' => (string)$row['method_name'],
                    'monto' => (float)$row['amount']
                ];
            }
            $stmt->close();
        }

        foreach ($operationRows as $oid => $operation) {
            if (my_payments_operation_is_void($operation)) continue;

            $lines = $operationLines[$oid] ?? [];
            if (!$lines) continue;

            $concepts = [];
            $years = [];
            $confirmedAmount = 0.0;
            $yearAmounts = [];
            $firstPid = 0;
            $firstEf = 0;

            foreach ($lines as $line) {
                if (!my_payments_is_confirmed($line['payment_status'])) continue;

                if (!$firstPid) $firstPid = (int)$line['pid'];
                if (!$firstEf) $firstEf = (int)$line['ef_id'];

                $concepts[] = (string)($line['course'] ?: 'Concepto de pago');
                $year = trim((string)($line['anio_academico'] ?? ''));
                if ($year !== '') $years[] = $year;

                $amount = max(0, (float)$line['amount']);
                $confirmedAmount += $amount;
                $key = $year !== '' ? $year : 'Sin año';
                $yearAmounts[$key] = ($yearAmounts[$key] ?? 0) + $amount;
            }

            if ($confirmedAmount <= EDUSYNC_DEBT_TOLERANCE) continue;

            $concepts = my_payments_unique_values($concepts);
            $years = my_payments_unique_values($years);
            $methods = $operationMethods[$oid] ?? [];
            $methodNames = my_payments_unique_values(array_column($methods, 'nombre'));

            $data[] = [
                'operation_id' => $oid,
                'pid' => $firstPid,
                'ef_id' => $firstEf,
                'fecha' => $operation['payment_date'],
                'recibo' => (string)($operation['receipt_full'] ?: ('OP-' . $oid)),
                'conceptos' => $concepts,
                'concepto' => implode(' + ', $concepts),
                'anios_academicos' => $years,
                'anio_academico' => count($years) === 1 ? $years[0] : (count($years) > 1 ? 'Varios' : ''),
                'monto' => round($confirmedAmount, 2),
                'monto_contabilizado' => round($confirmedAmount, 2),
                'estado' => 'Confirmado',
                'metodos_pago' => $methods,
                'medio_pago' => implode(' + ', $methodNames),
                'year_amounts' => array_map(static fn($v) => round((float)$v, 2), $yearAmounts),
                'legacy' => false
            ];
        }
    }

    $paymentStatusExpr = $hasPaymentStatus ? "COALESCE(p.payment_status,'Confirmado')" : "'Confirmado'";
    $operationFilter = $hasOperationId ? 'AND p.operation_id IS NULL' : '';
    $legacyStatusFilter = $hasPaymentStatus ? "AND COALESCE(p.payment_status,'Confirmado')='Confirmado'" : '';

    $stmt = $conn->prepare("SELECT p.id pid,p.ef_id,p.date_created,p.amount,p.receipt_no,$paymentStatusExpr payment_status,c.course concepto,ay.year anio_academico,ay.description anio_descripcion,pm.name medio_pago FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id LEFT JOIN courses c ON c.id=ef.course_id LEFT JOIN academic_year ay ON ay.id=c.academic_year_id LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id WHERE ef.student_id=? $operationFilter $legacyStatusFilter ORDER BY p.date_created DESC,p.id DESC");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (!my_payments_is_confirmed($row['payment_status'])) continue;

        $year = trim((string)($row['anio_academico'] ?? ''));
        $amount = max(0, (float)$row['amount']);
        if ($amount <= EDUSYNC_DEBT_TOLERANCE) continue;

        $receipt = trim((string)$row['receipt_no']);
        if ($receipt === '') $receipt = 'PAGO-' . (int)$row['pid'];

        $data[] = [
            'operation_id' => 0,
            'pid' => (int)$row['pid'],
            'ef_id' => (int)$row['ef_id'],
            'fecha' => $row['date_created'],
            'recibo' => $receipt,
            'conceptos' => [(string)($row['concepto'] ?: 'Concepto de pago')],
            'concepto' => (string)($row['concepto'] ?: 'Concepto de pago'),
            'anios_academicos' => $year !== '' ? [$year] : [],
            'anio_academico' => $year,
            'anio_descripcion' => $row['anio_descripcion'],
            'monto' => round($amount, 2),
            'monto_contabilizado' => round($amount, 2),
            'estado' => 'Confirmado',
            'metodos_pago' => !empty($row['medio_pago']) ? [['nombre' => $row['medio_pago'], 'monto' => $amount]] : [],
            'medio_pago' => (string)($row['medio_pago'] ?? ''),
            'year_amounts' => [($year !== '' ? $year : 'Sin año') => round($amount, 2)],
            'legacy' => true
        ];
    }
    $stmt->close();

    usort($data, static function ($a, $b) {
        $ta = strtotime((string)($a['fecha'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['fecha'] ?? '')) ?: 0;
        if ($ta === $tb) return ((int)($b['operation_id'] ?: $b['pid'])) <=> ((int)($a['operation_id'] ?: $a['pid']));
        return $tb <=> $ta;
    });

    $totalPaid = 0.0;
    $confirmedOperations = 0;
    $lastPaymentDate = null;
    $yearsSummary = [];

    foreach ($data as $payment) {
        $confirmedOperations++;
        $totalPaid += (float)$payment['monto_contabilizado'];

        $date = trim((string)($payment['fecha'] ?? ''));
        if ($date !== '' && ($lastPaymentDate === null || strtotime($date) > strtotime($lastPaymentDate))) {
            $lastPaymentDate = $date;
        }

        foreach (($payment['year_amounts'] ?? []) as $year => $amount) {
            if (!isset($yearsSummary[$year])) {
                $yearsSummary[$year] = ['año' => $year, 'total_pagos' => 0, 'monto_total' => 0.0];
            }
            $yearsSummary[$year]['total_pagos']++;
            $yearsSummary[$year]['monto_total'] += (float)$amount;
        }
    }

    uasort($yearsSummary, static fn($a, $b) => strnatcmp((string)$b['año'], (string)$a['año']));
    foreach ($yearsSummary as &$yearSummary) {
        $yearSummary['monto_total'] = round((float)$yearSummary['monto_total'], 2);
    }
    unset($yearSummary);

    $pendingDebts = debt_engine_pending_debts(
        debt_engine_get_student_debts($conn, $studentId, $schoolId)
    );
    $debtSummary = debt_engine_summary($pendingDebts);
    $debtByYear = debt_engine_year_summary($pendingDebts);

    my_payments_out([
        'status' => 'ok',
        'data' => $data,
        'student' => [
            'id' => $studentId,
            'dni' => (string)($student['id_no'] ?? $dni),
            'name' => (string)$student['name'],
            'school_id' => $schoolId
        ],
        'auth_mode' => $authMode,
        'total_pagos' => $confirmedOperations,
        'total_operaciones' => $confirmedOperations,
        'monto_total' => round($totalPaid, 2),
        'ultimo_pago' => $lastPaymentDate,
        'resumen_por_año' => array_values($yearsSummary),
        'debt_summary' => $debtSummary,
        'debt_summary_by_year' => $debtByYear
    ]);
} catch (Throwable $e) {
    error_log('[my_payments API] ' . $e->getMessage() . ' line ' . $e->getLine());
    my_payments_out([
        'status' => 'error',
        'message' => 'No se pudo cargar el historial de pagos.',
        'error_code' => 500
    ], 500);
}
