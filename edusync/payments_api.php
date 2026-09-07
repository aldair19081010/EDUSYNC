<?php
ob_start();
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

function paymentOut(array $data, int $code = 200): void
{
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function paymentColumn($db, string $table, string $column): bool
{
    $column = $db->real_escape_string($column);
    $query = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $query && $query->num_rows > 0;
}
function paymentTable($db, string $table): bool
{
    $table = $db->real_escape_string($table);
    $query = $db->query("SHOW TABLES LIKE '$table'");
    return $query && $query->num_rows > 0;
}
function paymentAudit($db, int $school, int $operation, int $payment, string $action, array $details = []): void
{
    $user = (int)($_SESSION['login_id'] ?? 0);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('INSERT INTO payment_audit_log(school_id,operation_id,payment_id,user_id,action,details,ip_address) VALUES(?,NULLIF(?,0),NULLIF(?,0),NULLIF(?,0),?,?,?)');
    if ($stmt) {
        $stmt->bind_param('iiiisss', $school, $operation, $payment, $user, $action, $json, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
function cashSummary($db, int $cashId, int $schoolId): array
{
    $summary = ['opening_balance' => 0.0, 'confirmed_total' => 0.0, 'cash_collected' => 0.0, 'digital_collected' => 0.0, 'cancelled_total' => 0.0, 'operation_count' => 0, 'expected_cash' => 0.0, 'methods' => []];
    $stmt = $db->prepare('SELECT opening_balance FROM cash_sessions WHERE id=? AND school_id=? LIMIT 1');
    $stmt->bind_param('ii', $cashId, $schoolId); $stmt->execute(); $cash = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$cash) return $summary;
    $summary['opening_balance'] = (float)$cash['opening_balance'];
    $stmt = $db->prepare("SELECT SUM(CASE WHEN status='Confirmado' THEN 1 ELSE 0 END) operation_count,
        COALESCE(SUM(CASE WHEN status='Confirmado' THEN total_amount ELSE 0 END),0) confirmed_total,
        COALESCE(SUM(CASE WHEN status='Anulado' THEN total_amount ELSE 0 END),0) cancelled_total
        FROM payment_operations WHERE cash_session_id=? AND school_id=?");
    $stmt->bind_param('ii', $cashId, $schoolId); $stmt->execute(); $totals = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $summary['operation_count'] = (int)$totals['operation_count'];
    $summary['confirmed_total'] = (float)$totals['confirmed_total'];
    $summary['cancelled_total'] = (float)$totals['cancelled_total'];
    $stmt = $db->prepare("SELECT pm.name,SUM(pom.amount) total
        FROM payment_operations po
        INNER JOIN payment_operation_methods pom ON pom.operation_id=po.id
        INNER JOIN payment_methods pm ON pm.id=pom.payment_method_id
        WHERE po.cash_session_id=? AND po.school_id=? AND po.status='Confirmado'
        GROUP BY pm.id,pm.name ORDER BY pm.name");
    $stmt->bind_param('ii', $cashId, $schoolId); $stmt->execute(); $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $amount = (float)$row['total'];
        $summary['methods'][] = ['name' => $row['name'], 'total' => $amount];
        if (mb_strtolower(trim($row['name']), 'UTF-8') === 'efectivo') $summary['cash_collected'] += $amount;
        else $summary['digital_collected'] += $amount;
    }
    $stmt->close();
    $summary['expected_cash'] = $summary['opening_balance'] + $summary['cash_collected'];
    return $summary;
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
$userType = (int)($_SESSION['login_type'] ?? 0);
$action = $_GET['action'] ?? '';
if (!$schoolId || !$userId || $userType !== 1) paymentOut(['status' => 0, 'message' => 'No tiene permisos para gestionar pagos.'], 403);
if (!paymentTable($conn, 'payment_operations') || !paymentColumn($conn, 'payments', 'operation_id')) paymentOut(['status' => 0, 'migration_required' => true, 'message' => 'Ejecute sql/payments_module_upgrade.sql antes de utilizar el módulo.'], 409);
$discountReady = paymentTable($conn, 'debt_discounts');
$discountSnapshotsReady = paymentTable($conn, 'payment_discount_snapshots');
$conceptSnapshotsReady = paymentTable($conn, 'payment_concept_snapshots');
$receiptBalanceSnapshotsReady = $conceptSnapshotsReady && paymentColumn($conn, 'payment_concept_snapshots', 'balance_after');
$cashReportReady = paymentColumn($conn, 'cash_sessions', 'operation_count');
$correctionsReady = paymentColumn($conn, 'payment_operations', 'corrected_from_id');
if (in_array($action, ['save', 'cancel', 'open_cash', 'close_cash', 'create_receipt_share'], true)) {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if (!$postedToken || !$sessionToken || !hash_equals($sessionToken, $postedToken)) paymentOut(['status' => 0, 'message' => 'La sesión de seguridad venció. Recargue la página.'], 403);
}

if ($action === 'create_receipt_share') {
    if (!paymentTable($conn, 'receipt_share_tokens')) paymentOut(['status' => 0, 'migration_required' => true, 'message' => 'Ejecute sql/receipt_share_tokens_upgrade.sql.'], 409);
    $operationId = (int)($_POST['operation_id'] ?? 0);
    $stmt = $conn->prepare('SELECT id FROM payment_operations WHERE id=? AND school_id=? LIMIT 1');
    $stmt->bind_param('ii', $operationId, $schoolId); $stmt->execute(); $exists = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$exists) paymentOut(['status' => 0, 'message' => 'El recibo no pertenece a la institución.'], 404);
    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $stmt = $conn->prepare('INSERT INTO receipt_share_tokens(school_id,operation_id,token_hash,expires_at,created_by) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY),?)');
    $stmt->bind_param('iisi', $schoolId, $operationId, $tokenHash, $userId);
    if (!$stmt->execute()) { $stmt->close(); paymentOut(['status' => 0, 'message' => 'No se pudo crear el enlace seguro.']); }
    $stmt->close();
    paymentAudit($conn, $schoolId, $operationId, 0, 'receipt_share_created', ['expires_in_days' => 7]);
    paymentOut(['status' => 1, 'url' => 'shared_receipt.php?token=' . rawurlencode($plainToken), 'expires_in_days' => 7]);
}

if ($action === 'bootstrap') {
    $methods = [];
    $query = $conn->query('SELECT id,name FROM payment_methods ORDER BY name');
    while ($query && ($row = $query->fetch_assoc())) $methods[] = $row;
    $stmt = $conn->prepare("SELECT * FROM cash_sessions WHERE school_id=? AND user_id=? AND status='Abierta' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('ii', $schoolId, $userId);
    $stmt->execute();
    $cash = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cashSummary = $cash ? cashSummary($conn, (int)$cash['id'], $schoolId) : null;
    paymentOut(['status' => 1, 'methods' => $methods, 'cash_session' => $cash ?: null, 'cash_summary' => $cashSummary, 'discounts_enabled' => $discountReady, 'discount_snapshots_enabled' => $discountSnapshotsReady, 'cash_report_enabled' => $cashReportReady, 'corrections_enabled' => $correctionsReady]);
}

if ($action === 'save') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $concepts = json_decode($_POST['selected_concepts'] ?? '[]', true);
    $methods = json_decode($_POST['payment_splits'] ?? '[]', true);
    $remarks = trim($_POST['remarks'] ?? '');
    $correctionOf = (int)($_POST['correction_of'] ?? 0);
    $adminDiscountId = (int)($_POST['admin_discount_id'] ?? 0);
    $correctionReason = trim($_POST['correction_reason'] ?? '');
    if ($correctionOf > 0 && (!$correctionsReady || strlen($correctionReason) < 3)) paymentOut(['status' => 0, 'message' => !$correctionsReady ? 'Ejecute sql/payment_corrections_upgrade.sql.' : 'Indique el motivo de la corrección.']);
    if (!$discountSnapshotsReady) paymentOut(['status'=>0,'migration_required'=>true,'message'=>'Ejecute sql/unified_discount_corrections_upgrade.sql antes de registrar o corregir pagos.'],409);
    if (!$conceptSnapshotsReady) paymentOut(['status'=>0,'migration_required'=>true,'message'=>'Ejecute sql/payment_concept_snapshots_upgrade.sql antes de registrar o corregir pagos.'],409);
    if (!$receiptBalanceSnapshotsReady) paymentOut(['status'=>0,'migration_required'=>true,'message'=>'Ejecute sql/payment_receipt_balance_snapshots_upgrade.sql antes de registrar o corregir pagos.'],409);
    $paymentDate = trim($_POST['payment_date'] ?? '');
    $paymentDate = $paymentDate ? str_replace('T', ' ', $paymentDate) . (strlen($paymentDate) <= 16 ? ':00' : '') : date('Y-m-d H:i:s');
    if (!$studentId || !is_array($concepts) || !$concepts || !is_array($methods) || !$methods) paymentOut(['status' => 0, 'message' => 'Complete estudiante, conceptos y medios de pago.']);
    $conceptTotal = 0.0;
    $methodTotal = 0.0;
    foreach ($concepts as $concept) $conceptTotal += round((float)($concept['amount'] ?? 0), 2);
    foreach ($methods as $method) $methodTotal += round((float)($method['amount'] ?? 0), 2);
    if ($conceptTotal <= 0 || abs($conceptTotal - $methodTotal) > .009) paymentOut(['status' => 0, 'message' => 'La distribución por conceptos y medios de pago debe coincidir.']);
    $studentStmt = $conn->prepare('SELECT id FROM student WHERE id=? AND school_id=? LIMIT 1');
    $studentStmt->bind_param('ii', $studentId, $schoolId);
    $studentStmt->execute();
    if (!$studentStmt->get_result()->fetch_assoc()) { $studentStmt->close(); paymentOut(['status' => 0, 'message' => 'El estudiante no pertenece a la institución.']); }
    $studentStmt->close();

    $conn->begin_transaction();
    try {
        $originalAmounts = [];
        $adminDiscountCorrection = null;
        if ($correctionOf > 0) {
            $originalStmt = $conn->prepare("SELECT student_id,status FROM payment_operations WHERE id=? AND school_id=? FOR UPDATE");
            $originalStmt->bind_param('ii', $correctionOf, $schoolId); $originalStmt->execute();
            $originalOperation = $originalStmt->get_result()->fetch_assoc(); $originalStmt->close();
            if (!$originalOperation || $originalOperation['status'] !== 'Confirmado' || (int)$originalOperation['student_id'] !== $studentId) throw new Exception('El pago original no está disponible para corrección.');
            $oldPayments = $conn->prepare("SELECT ef_id,SUM(amount) amount FROM payments WHERE operation_id=? AND payment_status='Confirmado' GROUP BY ef_id");
            $oldPayments->bind_param('i', $correctionOf); $oldPayments->execute(); $oldResult = $oldPayments->get_result();
            while ($old = $oldResult->fetch_assoc()) $originalAmounts[(int)$old['ef_id']] = (float)$old['amount'];
            $oldPayments->close();
            if ($adminDiscountId > 0) {
                $adminStmt = $conn->prepare("SELECT * FROM discount_benefits WHERE id=? AND school_id=? AND status='Aplicado' FOR UPDATE");
                $adminStmt->bind_param('ii', $adminDiscountId, $schoolId); $adminStmt->execute();
                $adminDiscountCorrection = $adminStmt->get_result()->fetch_assoc(); $adminStmt->close();
                if (!$adminDiscountCorrection || !isset($originalAmounts[(int)$adminDiscountCorrection['debt_id']])) throw new Exception('El descuento administrativo no pertenece al recibo que se intenta corregir.');
                $adminDebtId = (int)$adminDiscountCorrection['debt_id'];
                $restoreAmount = (float)$adminDiscountCorrection['previous_effective_amount'];
                $restoreAdmin = $conn->prepare('UPDATE student_ef_list SET discounted_amount=IF(ABS(total_fee-?)<0.01,NULL,?) WHERE id=?');
                $restoreAdmin->bind_param('ddi', $restoreAmount, $restoreAmount, $adminDebtId);
                if (!$restoreAdmin->execute()) throw new Exception($restoreAdmin->error); $restoreAdmin->close();
            }
            if ($discountReady) {
                $oldDiscounts = $conn->prepare("SELECT * FROM debt_discounts WHERE payment_operation_id=? AND school_id=? AND status='Aplicado' FOR UPDATE");
                $oldDiscounts->bind_param('ii', $correctionOf, $schoolId); $oldDiscounts->execute(); $oldDiscountResult = $oldDiscounts->get_result();
                while ($discount = $oldDiscountResult->fetch_assoc()) {
                    $debtId = (int)$discount['debt_id'];
                    if ($discount['previous_discounted_amount'] === null) {
                        $restore = $conn->prepare('UPDATE student_ef_list SET discounted_amount=NULL WHERE id=?'); $restore->bind_param('i', $debtId);
                    } else {
                        $previous = (float)$discount['previous_discounted_amount']; $restore = $conn->prepare('UPDATE student_ef_list SET discounted_amount=? WHERE id=?'); $restore->bind_param('di', $previous, $debtId);
                    }
                    if (!$restore->execute()) throw new Exception($restore->error); $restore->close();
                }
                $oldDiscounts->close();
                $reverse = $conn->prepare("UPDATE debt_discounts SET status='Revertido',reversed_at=NOW(),reversed_by=?,reversal_reason=? WHERE payment_operation_id=? AND school_id=? AND status='Aplicado'");
                $reverse->bind_param('isii', $userId, $correctionReason, $correctionOf, $schoolId); if (!$reverse->execute()) throw new Exception($reverse->error); $reverse->close();
            }
        }
        $cashStmt = $conn->prepare("SELECT id FROM cash_sessions WHERE school_id=? AND user_id=? AND status='Abierta' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $cashStmt->bind_param('ii', $schoolId, $userId);
        $cashStmt->execute();
        $cashId = (int)(($cashStmt->get_result()->fetch_assoc())['id'] ?? 0);
        $cashStmt->close();
        $debtStmt = $conn->prepare("SELECT ef.id,ef.total_fee,ef.discounted_amount,ef.debt_status,c.academic_year_id,c.course concept_name,c.level,ay.year academic_year_label,(SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.ef_id=ef.id AND p.payment_status='Confirmado') paid FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id INNER JOIN courses c ON c.id=ef.course_id LEFT JOIN academic_year ay ON ay.id=c.academic_year_id WHERE ef.id=? AND ef.student_id=? AND s.school_id=? FOR UPDATE");
        $validated = [];
        $discountTotal = 0.0;
        foreach ($concepts as $concept) {
            $debtId = (int)($concept['ef_id'] ?? 0);
            $amount = round((float)($concept['amount'] ?? 0), 2);
            if (!$debtId || $amount <= 0) throw new Exception('Existe una aplicación de pago inválida.');
            $debtStmt->bind_param('iii', $debtId, $studentId, $schoolId);
            $debtStmt->execute();
            $debt = $debtStmt->get_result()->fetch_assoc();
            if (!$debt) throw new Exception('Una deuda no pertenece al estudiante.');
            if ($debt['debt_status'] !== 'Activa') throw new Exception('La deuda ' . $debtId . ' está ' . $debt['debt_status'] . '.');
            $original = (float)$debt['total_fee'];
            $previousDiscounted = $debt['discounted_amount'] !== null ? (float)$debt['discounted_amount'] : null;
            $previousEffective = $previousDiscounted !== null ? $previousDiscounted : $original;
            $activeAdminDiscount = null;
            if ($previousDiscounted !== null && $previousDiscounted < $original) {
                $adminSnapshotStmt = $conn->prepare("SELECT id,previous_effective_amount,final_effective_amount,discount_amount,reason FROM discount_benefits WHERE debt_id=? AND school_id=? AND status='Aplicado' ORDER BY id DESC LIMIT 1");
                $adminSnapshotStmt->bind_param('ii', $debtId, $schoolId); $adminSnapshotStmt->execute();
                $activeAdminDiscount = $adminSnapshotStmt->get_result()->fetch_assoc(); $adminSnapshotStmt->close();
            }
            $paid = (float)$debt['paid'];
            $paidBefore = max(0, $paid - ($originalAmounts[$debtId] ?? 0));
            $due = max(0, $previousEffective - $paidBefore);
            $discountType = trim($concept['discount_type'] ?? '');
            $discountValue = round((float)($concept['discount_value'] ?? 0), 2);
            $discountReason = trim($concept['discount_reason'] ?? '');
            $discountAmount = 0.0;
            if ($discountType !== '') {
                if (!$discountReady) throw new Exception('Ejecute sql/payment_discounts_upgrade.sql para aplicar descuentos durante el pago.');
                if (!in_array($discountType, ['final_amount', 'fixed', 'percentage'], true) || $discountValue <= 0) throw new Exception('El descuento requiere un tipo y un valor válido.');
                if ($discountType === 'percentage' && $discountValue > 100) throw new Exception('El descuento porcentual no puede superar el 100%.');
                if ($discountType === 'final_amount' && $discountValue >= $due) throw new Exception('El monto final a cobrar debe ser menor que el saldo pendiente para generar un descuento.');
                $discountAmount = $discountType === 'percentage' ? round($due * $discountValue / 100, 2) : ($discountType === 'final_amount' ? round($due - $discountValue, 2) : $discountValue);
                if ($discountAmount <= 0 || $discountAmount >= $due) throw new Exception('El descuento debe ser menor que el saldo pendiente de la deuda.');
            }
            $available = round($due - $discountAmount, 2);
            // Se admiten sobrepagos excepcionales. El importe se conserva completo
            // en el pago y el saldo documental se cierra en cero, nunca en negativo.
            $overpayment = max(0, round($amount - $available, 2));
            $validated[] = ['ef' => $debtId, 'amount' => $amount, 'year' => (int)$debt['academic_year_id'], 'concept_name'=>$debt['concept_name'], 'level_name'=>$debt['level'], 'academic_year_label'=>$debt['academic_year_label'], 'original' => $original, 'discount_type' => $discountType, 'discount_value' => $discountValue, 'discount_reason' => $discountReason, 'discount_amount' => $discountAmount, 'previous_effective' => $previousEffective, 'previous_discounted' => $previousDiscounted, 'final_effective' => round($previousEffective - $discountAmount, 2), 'paid_before'=>$paidBefore, 'balance_after'=>max(0, round($available-$amount,2)), 'overpayment'=>$overpayment, 'admin_discount' => $activeAdminDiscount];
            $discountTotal += $discountAmount;
        }
        $debtStmt->close();

        $series = 'REC-' . date('Y', strtotime($paymentDate));
        $counter = $conn->prepare('INSERT INTO payment_counters(school_id,series,last_number) VALUES(?,?,0) ON DUPLICATE KEY UPDATE last_number=last_number');
        $counter->bind_param('is', $schoolId, $series); $counter->execute(); $counter->close();
        $counter = $conn->prepare('SELECT last_number FROM payment_counters WHERE school_id=? AND series=? FOR UPDATE');
        $counter->bind_param('is', $schoolId, $series); $counter->execute();
        $number = (int)$counter->get_result()->fetch_assoc()['last_number'] + 1; $counter->close();
        $updateCounter = $conn->prepare('UPDATE payment_counters SET last_number=? WHERE school_id=? AND series=?');
        $updateCounter->bind_param('iis', $number, $schoolId, $series); $updateCounter->execute(); $updateCounter->close();
        $receipt = $series . '-' . str_pad((string)$number, 6, '0', STR_PAD_LEFT);
        $operationStmt = $conn->prepare("INSERT INTO payment_operations(school_id,student_id,receipt_series,receipt_number,receipt_full,total_amount,payment_date,status,remarks,cash_session_id,created_by) VALUES(?,?,?,?,?,?,?,'Confirmado',?,NULLIF(?,0),?)");
        $operationStmt->bind_param('iisisdssii', $schoolId, $studentId, $series, $number, $receipt, $conceptTotal, $paymentDate, $remarks, $cashId, $userId);
        if (!$operationStmt->execute()) throw new Exception($operationStmt->error);
        $operationId = $operationStmt->insert_id; $operationStmt->close();

        $paymentStmt = $conn->prepare("INSERT INTO payments(operation_id,ef_id,receipt_no,amount,remarks,date_created,payment_method_id,payment_status,created_by) VALUES(?,?,?,?,?,?,?,'Confirmado',?)");
        $firstMethod = (int)($methods[0]['method_id'] ?? 0);
        $paymentIds = [];
        $discountStmt = $discountReady ? $conn->prepare("INSERT INTO debt_discounts(school_id,debt_id,payment_operation_id,discount_type,discount_value,discount_amount,previous_effective_amount,previous_discounted_amount,final_effective_amount,reason,status,authorized_by) VALUES(?,?,?,?,?,?,?,?,?,?,'Aplicado',?)") : null;
        $updateDebtStmt = $discountReady ? $conn->prepare('UPDATE student_ef_list SET discounted_amount=? WHERE id=?') : null;
        $snapshotStmt = $conn->prepare('INSERT INTO payment_discount_snapshots(school_id,payment_operation_id,debt_id,source_type,source_id,original_amount,previous_effective_amount,discount_amount,final_effective_amount,reason) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $conceptSnapshotStmt = $conn->prepare('INSERT INTO payment_concept_snapshots(school_id,payment_operation_id,debt_id,course_id,concept_name,level_name,academic_year_label,original_amount_snapshot,effective_amount_snapshot,paid_before,amount_applied,balance_after) SELECT ?,?,?,ef.course_id,?,?,?,?,?,?,?,? FROM student_ef_list ef WHERE ef.id=? ON DUPLICATE KEY UPDATE concept_name=VALUES(concept_name),level_name=VALUES(level_name),academic_year_label=VALUES(academic_year_label),original_amount_snapshot=VALUES(original_amount_snapshot),effective_amount_snapshot=VALUES(effective_amount_snapshot),paid_before=VALUES(paid_before),amount_applied=VALUES(amount_applied),balance_after=VALUES(balance_after)');
        foreach ($validated as $item) {
            $itemDebtId = (int)$item['ef'];
            $itemAmount = (float)$item['amount'];
            $conceptName=(string)$item['concept_name'];$levelName=(string)$item['level_name'];$yearLabel=(string)$item['academic_year_label'];
            $snapshotOriginal=(float)$item['original'];$snapshotEffective=(float)$item['final_effective'];$snapshotPaidBefore=(float)$item['paid_before'];$snapshotApplied=(float)$item['amount'];$snapshotBalance=(float)$item['balance_after'];
            $conceptSnapshotStmt->bind_param('iiisssdddddi',$schoolId,$operationId,$itemDebtId,$conceptName,$levelName,$yearLabel,$snapshotOriginal,$snapshotEffective,$snapshotPaidBefore,$snapshotApplied,$snapshotBalance,$itemDebtId);
            if(!$conceptSnapshotStmt->execute())throw new Exception($conceptSnapshotStmt->error);
            if ($item['discount_amount'] > 0) {
                $previousDiscounted = $item['previous_discounted'];
                $discountType = (string)$item['discount_type'];
                $discountValue = (float)$item['discount_value'];
                $discountAmount = (float)$item['discount_amount'];
                $previousEffective = (float)$item['previous_effective'];
                $finalEffective = (float)$item['final_effective'];
                $discountReason = (string)$item['discount_reason'];
                $discountStmt->bind_param('iiisdddddsi', $schoolId, $itemDebtId, $operationId, $discountType, $discountValue, $discountAmount, $previousEffective, $previousDiscounted, $finalEffective, $discountReason, $userId);
                if (!$discountStmt->execute()) throw new Exception($discountStmt->error);
                $paymentDiscountId = (int)$discountStmt->insert_id;
                $updateDebtStmt->bind_param('di', $finalEffective, $itemDebtId);
                if (!$updateDebtStmt->execute()) throw new Exception($updateDebtStmt->error);
            }
            $adminDiscount = $item['admin_discount'];
            if ($item['discount_amount'] > 0 || $adminDiscount) {
                $sourceType = $item['discount_amount'] > 0 ? ($adminDiscount ? 'Combinado' : 'Pago') : 'Administración';
                $sourceId = $adminDiscount ? (int)$adminDiscount['id'] : $paymentDiscountId;
                $snapshotOriginal = (float)$item['original'];
                $snapshotPrevious = $snapshotOriginal;
                $snapshotFinal = (float)$item['final_effective'];
                $snapshotDiscount = max(0, $snapshotOriginal - $snapshotFinal);
                $snapshotReason = trim(($adminDiscount['reason'] ?? '') . ($adminDiscount && $item['discount_reason'] ? ' | ' : '') . $item['discount_reason']);
                $snapshotStmt->bind_param('iiisidddds', $schoolId, $operationId, $itemDebtId, $sourceType, $sourceId, $snapshotOriginal, $snapshotPrevious, $snapshotDiscount, $snapshotFinal, $snapshotReason);
                if (!$snapshotStmt->execute()) throw new Exception($snapshotStmt->error);
            }
            $paymentStmt->bind_param('iisdssii', $operationId, $itemDebtId, $receipt, $itemAmount, $remarks, $paymentDate, $firstMethod, $userId);
            if (!$paymentStmt->execute()) throw new Exception($paymentStmt->error);
            $paymentIds[] = ['ef_id' => $itemDebtId, 'pid' => $paymentStmt->insert_id];
        }
        if ($discountStmt) $discountStmt->close();
        if ($updateDebtStmt) $updateDebtStmt->close();
        $snapshotStmt->close();
        $conceptSnapshotStmt->close();
        $paymentStmt->close();

        $methodStmt = $conn->prepare('INSERT INTO payment_operation_methods(operation_id,payment_method_id,amount,reference_number,bank_name,operation_date) VALUES(?,?,?,?,?,?)');
        $methodNameStmt = $conn->prepare('SELECT name FROM payment_methods WHERE id=? LIMIT 1');
        foreach ($methods as $method) {
            $methodId = (int)($method['method_id'] ?? 0); $methodAmount = round((float)($method['amount'] ?? 0), 2);
            $reference = trim($method['reference_number'] ?? '') ?: null; $bank = trim($method['bank_name'] ?? '') ?: null; $operationDate = trim($method['operation_date'] ?? '') ?: null;
            if (!$methodId || $methodAmount <= 0) throw new Exception('Medio de pago inválido.');
            $methodNameStmt->bind_param('i', $methodId); $methodNameStmt->execute(); $methodRow = $methodNameStmt->get_result()->fetch_assoc();
            if (!$methodRow) throw new Exception('El medio de pago seleccionado no existe.');
            if ($reference) {
                $duplicate = $conn->prepare('SELECT id FROM payment_operation_methods WHERE payment_method_id=? AND reference_number=? AND operation_id<>? LIMIT 1');
                $duplicate->bind_param('isi', $methodId, $reference, $correctionOf); $duplicate->execute();
                if ($duplicate->get_result()->fetch_assoc()) throw new Exception('El número de operación ' . $reference . ' ya fue registrado.');
                $duplicate->close();
            }
            $methodStmt->bind_param('iidsss', $operationId, $methodId, $methodAmount, $reference, $bank, $operationDate);
            if (!$methodStmt->execute()) throw new Exception($methodStmt->error);
        }
        $methodNameStmt->close();
        $methodStmt->close();
        if ($correctionOf > 0) {
            $stmt = $conn->prepare("UPDATE payment_operations SET status='Anulado',cancelled_at=NOW(),cancelled_by=?,cancellation_reason=?,corrected_by_id=? WHERE id=? AND school_id=?");
            $stmt->bind_param('isiii', $userId, $correctionReason, $operationId, $correctionOf, $schoolId); if (!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
            $stmt = $conn->prepare("UPDATE payments SET payment_status='Anulado' WHERE operation_id=?");
            $stmt->bind_param('i', $correctionOf); if (!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
            $stmt = $conn->prepare('UPDATE payment_operations SET corrected_from_id=?,correction_reason=? WHERE id=? AND school_id=?');
            $stmt->bind_param('isii', $correctionOf, $correctionReason, $operationId, $schoolId); if (!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
            if ($adminDiscountCorrection) {
                $adminDiscountIdValue = (int)$adminDiscountCorrection['id'];
                $stmt = $conn->prepare("UPDATE discount_benefits SET status='Revocado',revoked_at=NOW(),revoked_by=?,revocation_reason=? WHERE id=? AND school_id=? AND status='Aplicado'");
                $stmt->bind_param('isii', $userId, $correctionReason, $adminDiscountIdValue, $schoolId); if (!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
                if (paymentTable($conn, 'discount_audit_log')) {
                    $debtValue=(int)$adminDiscountCorrection['debt_id'];$studentValue=(int)$adminDiscountCorrection['student_id'];$ip=$_SERVER['REMOTE_ADDR']??null;$details=json_encode(['reason'=>$correctionReason,'original_operation_id'=>$correctionOf,'replacement_operation_id'=>$operationId],JSON_UNESCAPED_UNICODE);
                    $stmt=$conn->prepare("INSERT INTO discount_audit_log(school_id,discount_id,debt_id,student_id,user_id,action,details,ip_address) VALUES(?,?,?,?,?,'discount_corrected_with_payment',?,?)");
                    $stmt->bind_param('iiiiiss',$schoolId,$adminDiscountIdValue,$debtValue,$studentValue,$userId,$details,$ip);if(!$stmt->execute())throw new Exception($stmt->error);$stmt->close();
                }
            }
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        paymentOut(['status' => 0, 'message' => $error->getMessage()]);
    }
    paymentAudit($conn, $schoolId, $operationId, 0, $correctionOf > 0 ? 'payment_corrected' : 'payment_created', ['receipt' => $receipt, 'amount' => $conceptTotal, 'concepts' => count($validated), 'discount_amount' => $discountTotal, 'corrected_from_id' => $correctionOf, 'reason' => $correctionReason]);
    if ($correctionOf > 0) paymentAudit($conn, $schoolId, $correctionOf, 0, 'payment_replaced', ['replacement_operation_id' => $operationId, 'reason' => $correctionReason]);
    paymentOut(['status' => 1, 'message' => ($correctionOf > 0 ? 'Pago corregido correctamente. El recibo original quedó anulado.' : 'Pago registrado correctamente.') . ($discountTotal > 0 ? ' Descuento aplicado: S/ ' . number_format($discountTotal, 2) . '.' : ''), 'operation_id' => $operationId, 'receipt' => $receipt, 'payments' => $paymentIds]);
}

if ($action === 'cancel') {
    $operationId = (int)($_POST['operation_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if (!$operationId || strlen($reason) < 3) paymentOut(['status' => 0, 'message' => 'Indique un motivo de anulación.']);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT status FROM payment_operations WHERE id=? AND school_id=? FOR UPDATE");
        $stmt->bind_param('ii', $operationId, $schoolId); $stmt->execute(); $operation = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$operation || $operation['status'] !== 'Confirmado') throw new Exception('La operación no está disponible para anulación.');
        if ($discountReady) {
            $discounts = $conn->prepare("SELECT * FROM debt_discounts WHERE payment_operation_id=? AND school_id=? AND status='Aplicado' FOR UPDATE");
            $discounts->bind_param('ii', $operationId, $schoolId); $discounts->execute(); $discountRows = $discounts->get_result();
            while ($discount = $discountRows->fetch_assoc()) {
                $debtId = (int)$discount['debt_id'];
                $newer = $conn->query("SELECT id FROM debt_discounts WHERE debt_id=$debtId AND status='Aplicado' AND id>" . (int)$discount['id'] . ' LIMIT 1');
                if ($newer && $newer->num_rows) throw new Exception('No se puede anular porque existe un descuento posterior en una de las deudas.');
                if ($discount['previous_discounted_amount'] === null) {
                    $restore = $conn->prepare('UPDATE student_ef_list SET discounted_amount=NULL WHERE id=?');
                    $restore->bind_param('i', $debtId);
                } else {
                    $previous = (float)$discount['previous_discounted_amount'];
                    $restore = $conn->prepare('UPDATE student_ef_list SET discounted_amount=? WHERE id=?');
                    $restore->bind_param('di', $previous, $debtId);
                }
                if (!$restore->execute()) throw new Exception($restore->error); $restore->close();
            }
            $discounts->close();
            $reverse = $conn->prepare("UPDATE debt_discounts SET status='Revertido',reversed_at=NOW(),reversed_by=?,reversal_reason=? WHERE payment_operation_id=? AND school_id=? AND status='Aplicado'");
            $reverse->bind_param('isii', $userId, $reason, $operationId, $schoolId); $reverse->execute(); $reverse->close();
        }
        $stmt = $conn->prepare("UPDATE payment_operations SET status='Anulado',cancelled_at=NOW(),cancelled_by=?,cancellation_reason=? WHERE id=? AND school_id=?");
        $stmt->bind_param('isii', $userId, $reason, $operationId, $schoolId); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare("UPDATE payments SET payment_status='Anulado' WHERE operation_id=?");
        $stmt->bind_param('i', $operationId); $stmt->execute(); $stmt->close();
        $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); paymentOut(['status' => 0, 'message' => $error->getMessage()]); }
    paymentAudit($conn, $schoolId, $operationId, 0, 'payment_cancelled', ['reason' => $reason, 'transaction_discount_reverted' => $discountReady]);
    paymentOut(['status' => 1, 'message' => 'Pago anulado. El saldo y los descuentos aplicados durante esta operación fueron restituidos.']);
}

if ($action === 'open_cash') {
    $opening = max(0, (float)($_POST['opening_balance'] ?? 0));
    $stmt = $conn->prepare("SELECT id FROM cash_sessions WHERE school_id=? AND user_id=? AND status='Abierta' LIMIT 1");
    $stmt->bind_param('ii', $schoolId, $userId); $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) { $stmt->close(); paymentOut(['status' => 0, 'message' => 'Ya tiene una caja abierta.']); }
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO cash_sessions(school_id,user_id,opened_at,opening_balance,status) VALUES(?,?,NOW(),?,'Abierta')");
    $stmt->bind_param('iid', $schoolId, $userId, $opening); $stmt->execute(); $cashId = $stmt->insert_id; $stmt->close();
    paymentAudit($conn, $schoolId, 0, 0, 'cash_opened', ['cash_session_id' => $cashId, 'opening_balance' => $opening]);
    paymentOut(['status' => 1, 'message' => 'Caja abierta correctamente.']);
}

if ($action === 'close_cash') {
    $cashId = (int)($_POST['cash_session_id'] ?? 0); $closing = (float)($_POST['closing_balance'] ?? 0); $notes = trim($_POST['notes'] ?? '');
    $stmt = $conn->prepare("SELECT id FROM cash_sessions WHERE id=? AND school_id=? AND user_id=? AND status='Abierta' LIMIT 1");
    $stmt->bind_param('iii', $cashId, $schoolId, $userId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) paymentOut(['status' => 0, 'message' => 'Caja abierta no encontrada.']);
    $summary = cashSummary($conn, $cashId, $schoolId);
    $expected = (float)$summary['expected_cash']; $difference = $closing - $expected;
    if ($cashReportReady) {
        $stmt = $conn->prepare("UPDATE cash_sessions SET status='Cerrada',closed_at=NOW(),closing_balance=?,expected_balance=?,operation_count=?,cancelled_total=?,difference_amount=?,notes=?,closed_by=? WHERE id=?");
        $stmt->bind_param('ddiddsii', $closing, $expected, $summary['operation_count'], $summary['cancelled_total'], $difference, $notes, $userId, $cashId);
    } else {
        $stmt = $conn->prepare("UPDATE cash_sessions SET status='Cerrada',closed_at=NOW(),closing_balance=?,expected_balance=?,difference_amount=?,notes=? WHERE id=?");
        $stmt->bind_param('dddsi', $closing, $expected, $difference, $notes, $cashId);
    }
    $stmt->execute(); $stmt->close();
    paymentAudit($conn, $schoolId, 0, 0, 'cash_closed', ['cash_session_id' => $cashId, 'expected_cash' => $expected, 'closing_cash' => $closing, 'difference' => $difference]);
    paymentOut(['status' => 1, 'message' => 'Caja cerrada correctamente.', 'difference' => $difference, 'summary' => $summary]);
}

if ($action === 'cash_report') {
    if (!$cashReportReady) paymentOut(['status' => 0, 'migration_required' => true, 'message' => 'Ejecute sql/cash_report_upgrade.sql.'], 409);
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $stmt = $conn->prepare("SELECT cs.*,COALESCE(u.name,'Usuario') user_name,COALESCE(cu.name,'') closed_by_name,
        (SELECT GROUP_CONCAT(CONCAT(pm.name, ': S/ ', FORMAT((SELECT SUM(pom.amount) FROM payment_operations po INNER JOIN payment_operation_methods pom ON pom.operation_id=po.id WHERE po.cash_session_id=cs.id AND po.status='Confirmado' AND pom.payment_method_id=pm.id),2)) ORDER BY pm.name SEPARATOR ' | ')
         FROM payment_methods pm
         WHERE EXISTS (SELECT 1 FROM payment_operations po INNER JOIN payment_operation_methods pom ON pom.operation_id=po.id WHERE po.cash_session_id=cs.id AND po.status='Confirmado' AND pom.payment_method_id=pm.id)) method_summary
        FROM cash_sessions cs
        LEFT JOIN users u ON u.id=cs.user_id
        LEFT JOIN users cu ON cu.id=cs.closed_by
        WHERE cs.school_id=? AND (?='' OR DATE(cs.opened_at)>=?) AND (?='' OR DATE(cs.opened_at)<=?)
        ORDER BY cs.opened_at DESC LIMIT 300");
    $stmt->bind_param('issss', $schoolId, $dateFrom, $dateFrom, $dateTo, $dateTo);
    $stmt->execute(); $sessions = []; $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $sessions[] = $row;
    $stmt->close(); paymentOut(['status' => 1, 'sessions' => $sessions]);
}

if ($action === 'audit') {
    $stmt = $conn->prepare("SELECT l.*,COALESCE(u.name,'Sistema') user_name,po.receipt_full FROM payment_audit_log l LEFT JOIN users u ON u.id=l.user_id LEFT JOIN payment_operations po ON po.id=l.operation_id WHERE l.school_id=? ORDER BY l.created_at DESC LIMIT 200");
    $stmt->bind_param('i', $schoolId); $stmt->execute(); $audit = []; $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $audit[] = $row;
    $stmt->close(); paymentOut(['status' => 1, 'audit' => $audit]);
}

paymentOut(['status' => 0, 'message' => 'Acción no reconocida.'], 404);
