<?php
$isPublicReceipt = isset($publicReceiptContext) && is_array($publicReceiptContext);
if (!$isPublicReceipt) {
    include_once __DIR__ . '/includes/session_check.php';
    require_login_modal();
    include __DIR__ . '/db_connect.php';
    $isReceiptAdmin = (int)($_SESSION['login_type'] ?? 0) === 1;
    $isReceiptStudent = !empty($_SESSION['student_logged_in']) && !empty($_SESSION['student_id']);
    if (!$isReceiptAdmin && !$isReceiptStudent) {
        http_response_code(403);
        exit('<div class="alert alert-danger">No tiene permisos para consultar recibos.</div>');
    }
}
$schoolId = $isPublicReceipt ? (int)$publicReceiptContext['school_id'] : (int)($_SESSION['login_school_id'] ?? 0);
$paymentId = $isPublicReceipt ? 0 : (int)($_GET['pid'] ?? 0);
$debtId = $isPublicReceipt ? 0 : (int)($_GET['ef_id'] ?? 0);
$operationId = $isPublicReceipt ? (int)$publicReceiptContext['operation_id'] : (int)($_GET['operation_id'] ?? 0);
$hasOperations = false;
$operation = null;
$concepts = [];
$methods = [];
$operationDiscounts = [];

$operationTable = $conn->query("SHOW TABLES LIKE 'payment_operations'");
$hasOperations = $operationTable && $operationTable->num_rows > 0;
$discountTable = $conn->query("SHOW TABLES LIKE 'debt_discounts'");
$hasDiscountHistory = $discountTable && $discountTable->num_rows > 0;
$snapshotTable = $conn->query("SHOW TABLES LIKE 'payment_discount_snapshots'");
$hasDiscountSnapshots = $snapshotTable && $snapshotTable->num_rows > 0;
$conceptSnapshotTable = $conn->query("SHOW TABLES LIKE 'payment_concept_snapshots'");
$hasConceptSnapshots = $conceptSnapshotTable && $conceptSnapshotTable->num_rows > 0;
$receiptBalanceColumn = $hasConceptSnapshots ? $conn->query("SHOW COLUMNS FROM payment_concept_snapshots LIKE 'balance_after'") : false;
$hasReceiptBalanceSnapshots = $receiptBalanceColumn && $receiptBalanceColumn->num_rows > 0;
$receiptConceptJoin = $hasConceptSnapshots ? 'LEFT JOIN payment_concept_snapshots pcs ON pcs.payment_operation_id=p.operation_id AND pcs.debt_id=ef.id' : '';
$receiptLegacyConcept = "CASE WHEN $schoolId=1 AND ef.course_id=26 THEN 'Matrícula' ELSE CONCAT('Concepto histórico #',ef.course_id) END";
$receiptConceptName = $hasConceptSnapshots ? "COALESCE(NULLIF(c.course,''),NULLIF(pcs.concept_name,''),$receiptLegacyConcept)" : "COALESCE(NULLIF(c.course,''),$receiptLegacyConcept)";
$receiptConceptLevel = $hasConceptSnapshots ? 'COALESCE(c.level,pcs.level_name)' : 'c.level';
$receiptConceptYear = $hasConceptSnapshots ? 'COALESCE(ay.year,pcs.academic_year_label)' : 'ay.year';
$receiptBalanceFields = $hasReceiptBalanceSnapshots ? ',pcs.original_amount_snapshot,pcs.effective_amount_snapshot,pcs.paid_before,pcs.amount_applied,pcs.balance_after' : ',NULL original_amount_snapshot,NULL effective_amount_snapshot,NULL paid_before,NULL amount_applied,NULL balance_after';
$correctionColumn = $conn->query("SHOW COLUMNS FROM payment_operations LIKE 'corrected_from_id'");
$hasCorrectionHistory = $correctionColumn && $correctionColumn->num_rows > 0;

if ($operationId <= 0 && $paymentId > 0 && $hasOperations) {
    $stmt = $conn->prepare('SELECT p.operation_id FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id INNER JOIN student s ON s.id=ef.student_id WHERE p.id=? AND s.school_id=? LIMIT 1');
    $stmt->bind_param('ii', $paymentId, $schoolId);
    $stmt->execute();
    $linked = $stmt->get_result()->fetch_assoc();
    $operationId = (int)($linked['operation_id'] ?? 0);
    $stmt->close();
}

if ($operationId > 0 && $hasOperations) {
    $correctionSelect = $hasCorrectionHistory
        ? ", (SELECT receipt_full FROM payment_operations original WHERE original.id=po.corrected_from_id) corrected_from_receipt, (SELECT receipt_full FROM payment_operations replacement WHERE replacement.id=po.corrected_by_id) corrected_by_receipt"
        : ", NULL corrected_from_receipt, NULL corrected_by_receipt, NULL correction_reason";
    $stmt = $conn->prepare("SELECT po.*,s.name student_name,s.id_no,s.nivel,s.grado,s.seccion,
        COALESCE(u.name,'Sistema') cashier_name,COALESCE(cu.name,'') cancelled_by_name,
        cs.opened_at cash_opened_at $correctionSelect
        FROM payment_operations po
        INNER JOIN student s ON s.id=po.student_id
        LEFT JOIN users u ON u.id=po.created_by
        LEFT JOIN users cu ON cu.id=po.cancelled_by
        LEFT JOIN cash_sessions cs ON cs.id=po.cash_session_id
        WHERE po.id=? AND po.school_id=? LIMIT 1");
    $stmt->bind_param('ii', $operationId, $schoolId);
    $stmt->execute();
    $operation = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($operation) {
        $stmt = $conn->prepare("SELECT p.id payment_id,p.ef_id,p.amount payment_amount,p.payment_status,
            ef.total_fee,ef.discounted_amount,ef.billing_period,ef.due_date $receiptBalanceFields,
            $receiptConceptName course,$receiptConceptLevel level,$receiptConceptYear year,
            (SELECT COALESCE(SUM(px.amount),0) FROM payments px WHERE px.ef_id=ef.id AND px.payment_status='Confirmado') confirmed_paid
            FROM payments p
            INNER JOIN student_ef_list ef ON ef.id=p.ef_id
            INNER JOIN student s ON s.id=ef.student_id
            LEFT JOIN courses c ON c.id=ef.course_id
            LEFT JOIN academic_year ay ON ay.id=c.academic_year_id
            $receiptConceptJoin
            WHERE p.operation_id=? AND s.school_id=? ORDER BY p.id");
        $stmt->bind_param('ii', $operationId, $schoolId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $concepts[] = $row;
        $stmt->close();

        // Recuperación para migraciones históricas que crearon la operación LEG
        // pero se detuvieron antes de enlazar payments.operation_id.
        if (!$concepts && ($operation['receipt_series'] ?? '') === 'LEG') {
            $legacyConceptJoin = $hasConceptSnapshots
                ? 'LEFT JOIN payment_concept_snapshots pcs ON pcs.payment_operation_id=? AND pcs.debt_id=ef.id'
                : '';
            $legacyConceptName = $hasConceptSnapshots
                ? "COALESCE(NULLIF(c.course,''),NULLIF(pcs.concept_name,''),$receiptLegacyConcept)"
                : "COALESCE(NULLIF(c.course,''),$receiptLegacyConcept)";
            $legacyConceptLevel = $hasConceptSnapshots ? 'COALESCE(c.level,pcs.level_name)' : 'c.level';
            $legacyConceptYear = $hasConceptSnapshots ? 'COALESCE(ay.year,pcs.academic_year_label)' : 'ay.year';
            $stmt = $conn->prepare("SELECT p.id payment_id,p.ef_id,p.amount payment_amount,p.payment_status,
                ef.total_fee,ef.discounted_amount,ef.billing_period,ef.due_date $receiptBalanceFields,
                $legacyConceptName course,$legacyConceptLevel level,$legacyConceptYear year,
                (SELECT COALESCE(SUM(px.amount),0) FROM payments px WHERE px.ef_id=ef.id AND px.payment_status='Confirmado') confirmed_paid
                FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id
                LEFT JOIN courses c ON c.id=ef.course_id LEFT JOIN academic_year ay ON ay.id=c.academic_year_id
                $legacyConceptJoin
                WHERE p.operation_id IS NULL AND ef.student_id=?
                  AND CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci
                      = CONVERT(CONCAT('LEG-',?,'-',ef.student_id,'-',p.receipt_no) USING utf8mb4) COLLATE utf8mb4_general_ci
                ORDER BY p.id");
            $legacyStudentId = (int)$operation['student_id'];
            $legacyReceipt = (string)$operation['receipt_full'];
            if ($hasConceptSnapshots) {
                $stmt->bind_param('iisi', $operationId, $legacyStudentId, $legacyReceipt, $schoolId);
            } else {
                $stmt->bind_param('isi', $legacyStudentId, $legacyReceipt, $schoolId);
            }
            $stmt->execute(); $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) $concepts[] = $row;
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT pm.name method_name,pom.amount,pom.reference_number,pom.bank_name,pom.operation_date
            FROM payment_operation_methods pom
            INNER JOIN payment_methods pm ON pm.id=pom.payment_method_id
            WHERE pom.operation_id=? ORDER BY pm.name");
        $stmt->bind_param('i', $operationId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $methods[] = $row;
        $stmt->close();

        if ($hasDiscountSnapshots) {
            $stmt = $conn->prepare('SELECT debt_id,discount_amount,previous_effective_amount,final_effective_amount,reason,source_type status FROM payment_discount_snapshots WHERE payment_operation_id=? AND school_id=?');
            $stmt->bind_param('ii', $operationId, $schoolId);
            $stmt->execute();$result=$stmt->get_result();while($row=$result->fetch_assoc())$operationDiscounts[(int)$row['debt_id']]=$row;$stmt->close();
        } elseif ($hasDiscountHistory) {
            $stmt = $conn->prepare('SELECT debt_id,discount_amount,previous_effective_amount,final_effective_amount,reason,status FROM debt_discounts WHERE payment_operation_id=? AND school_id=?');
            $stmt->bind_param('ii', $operationId, $schoolId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) $operationDiscounts[(int)$row['debt_id']] = $row;
            $stmt->close();
        }
    }
}

// Compatibilidad con pagos no migrados o con la vista histórica de una deuda.
if (!$operation) {
    $wherePayment = $paymentId > 0 ? 'p.id=?' : 'p.ef_id=?';
    $lookupId = $paymentId > 0 ? $paymentId : $debtId;
    $stmt = $conn->prepare("SELECT p.id payment_id,p.ef_id,p.amount payment_amount,p.receipt_no,p.date_created,
        COALESCE(p.payment_status,'Confirmado') payment_status,p.remarks,
        ef.total_fee,ef.discounted_amount,ef.billing_period,ef.due_date,
        NULL original_amount_snapshot,NULL effective_amount_snapshot,NULL paid_before,NULL amount_applied,NULL balance_after,
        s.id student_id,s.name student_name,s.id_no,s.nivel,s.grado,s.seccion,
        c.course,c.level,ay.year,pm.name method_name,
        (SELECT COALESCE(SUM(px.amount),0) FROM payments px WHERE px.ef_id=ef.id AND (px.payment_status='Confirmado' OR px.payment_status IS NULL)) confirmed_paid
        FROM payments p
        INNER JOIN student_ef_list ef ON ef.id=p.ef_id
        INNER JOIN student s ON s.id=ef.student_id
        LEFT JOIN courses c ON c.id=ef.course_id
        LEFT JOIN academic_year ay ON ay.id=c.academic_year_id
        LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id
        WHERE $wherePayment AND s.school_id=? ORDER BY p.id");
    $stmt->bind_param('ii', $lookupId, $schoolId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $concepts[] = $row;
    $stmt->close();

    if ($concepts) {
        $first = $concepts[0];
        $total = array_sum(array_map(static function ($row) { return (float)$row['payment_amount']; }, $concepts));
        $legacyNumber = trim((string)($first['receipt_no'] ?? ''));
        if ($legacyNumber === '') $legacyNumber = 'PAGO-' . (int)$first['payment_id'];
        $operation = [
            'id' => 0,
            'student_id' => (int)$first['student_id'],
            'receipt_full' => 'LEG-' . $schoolId . '-' . (int)$first['student_id'] . '-' . $legacyNumber,
            'receipt_series' => 'LEG',
            'total_amount' => $total,
            'payment_date' => $first['date_created'],
            'status' => $first['payment_status'],
            'remarks' => $first['remarks'] ?? '',
            'student_name' => $first['student_name'],
            'id_no' => $first['id_no'],
            'nivel' => $first['nivel'],
            'grado' => $first['grado'],
            'seccion' => $first['seccion'],
            'cashier_name' => 'Registro anterior',
            'cash_session_id' => null,
            'cancelled_at' => null,
            'cancelled_by_name' => '',
            'cancellation_reason' => ''
            ,'corrected_from_receipt' => null
            ,'corrected_by_receipt' => null
            ,'correction_reason' => null
        ];
        if (!empty($first['method_name'])) $methods[] = ['method_name' => $first['method_name'], 'amount' => $total, 'reference_number' => null, 'bank_name' => null, 'operation_date' => null];
    }
}

if (!$isPublicReceipt && !empty($_SESSION['student_logged_in']) && (int)($operation['student_id'] ?? 0) !== (int)($_SESSION['student_id'] ?? 0)) {
    $operation = null;
    $concepts = [];
}

if (!$operation || !$concepts) {
    echo '<div class="alert alert-warning">No se encontró el recibo solicitado.</div>';
    return;
}

// Presentar los conceptos en orden cronológico, independientemente del orden
// en que fueron seleccionados o insertados dentro de la operación.
$monthOrder = [
    'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
    'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
    'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
    'noviembre' => 11, 'diciembre' => 12
];
$conceptSortValue = static function (array $concept) use ($monthOrder): array {
    $dueDate = trim((string)($concept['due_date'] ?? ''));
    if ($dueDate !== '' && $dueDate !== '0000-00-00') {
        return [0, strtotime($dueDate) ?: PHP_INT_MAX, 0, ''];
    }
    $year = (int)($concept['year'] ?? 0);
    $text = mb_strtolower(trim(($concept['billing_period'] ?? '') . ' ' . ($concept['course'] ?? '')), 'UTF-8');
    $month = 99;
    foreach ($monthOrder as $name => $number) {
        if (mb_strpos($text, $name, 0, 'UTF-8') !== false) {
            $month = $number;
            break;
        }
    }
    return [1, $year ?: PHP_INT_MAX, $month, $text];
};
usort($concepts, static function (array $left, array $right) use ($conceptSortValue): int {
    return $conceptSortValue($left) <=> $conceptSortValue($right);
});

$schoolStmt = $conn->prepare('SELECT name,address,contact_number,email,logo_path FROM schools WHERE id=? LIMIT 1');
$schoolStmt->bind_param('i', $schoolId);
$schoolStmt->execute();
$school = $schoolStmt->get_result()->fetch_assoc() ?: [];
$schoolStmt->close();

$logo = trim((string)($school['logo_path'] ?? ''));
$logoExists = $logo !== '' && (file_exists($logo) || file_exists(__DIR__ . '/' . ltrim($logo, '/\\')));
$isLegacy = str_starts_with((string)$operation['receipt_full'], 'LEG-');
$isCancelled = $operation['status'] === 'Anulado';
$originalTotal = 0.0;
$effectiveTotal = 0.0;
$paidBeforeTotal = 0.0;
$balanceBeforeTotal = 0.0;
$currentBalance = 0.0;
foreach ($concepts as &$concept) {
    $original = $concept['original_amount_snapshot'] !== null ? (float)$concept['original_amount_snapshot'] : (float)$concept['total_fee'];
    $operationDiscount = $operationDiscounts[(int)$concept['ef_id']] ?? null;
    // Un recibo emitido es una fotografía histórica: si el descuento nació en
    // esta operación, se usan sus importes guardados aunque luego sea revertido.
    $effective = $concept['effective_amount_snapshot'] !== null
        ? (float)$concept['effective_amount_snapshot']
        : ($operationDiscount
            ? (float)$operationDiscount['final_effective_amount']
            : ($concept['discounted_amount'] !== null && $concept['discounted_amount'] !== '' ? (float)$concept['discounted_amount'] : $original));
    $balance = $concept['balance_after'] !== null
        ? (float)$concept['balance_after']
        : max(0, $effective - (float)$concept['confirmed_paid']);
    $paidBefore = $concept['paid_before'] !== null
        ? (float)$concept['paid_before']
        : max(0, $effective - (float)$concept['payment_amount'] - $balance);
    $balanceBefore = max(0, $effective - $paidBefore);
    $concept['effective_amount'] = $effective;
    $concept['original_amount'] = $original;
    $concept['discount_amount'] = $operationDiscount ? (float)$operationDiscount['discount_amount'] : max(0, $original - $effective);
    $concept['discount_reason'] = $operationDiscount['reason'] ?? '';
    $concept['current_balance'] = $balance;
    $concept['paid_before_snapshot'] = $paidBefore;
    $concept['balance_before_snapshot'] = $balanceBefore;
    $originalTotal += $original;
    $effectiveTotal += $effective;
    $paidBeforeTotal += $paidBefore;
    $balanceBeforeTotal += $balanceBefore;
    $currentBalance += $balance;
}
unset($concept);
$discountTotal = max(0, $originalTotal - $effectiveTotal);
$statusClass = $operation['status'] === 'Confirmado' ? 'success' : ($isCancelled ? 'danger' : 'secondary');
$receipt_operation_id = (int)$operation['id'];
$receipt_payment_id = (int)($concepts[0]['payment_id'] ?? 0);
$receipt_debt_id = (int)($concepts[0]['ef_id'] ?? 0);

function receiptDate(string $value, bool $time = true): string
{
    $months = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
    $timestamp = strtotime($value);
    if (!$timestamp) return '';
    $date = date('d', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    return $time ? $date . ' · ' . date('H:i', $timestamp) : $date;
}
?>
<style>
.receipt-sheet{position:relative;background:#fff;color:#344054;max-width:1050px;margin:auto;padding:22px;font-family:Arial,sans-serif}.receipt-header{display:flex;align-items:center;gap:16px;border-bottom:3px solid #2f6fed;padding-bottom:14px}.receipt-logo{width:72px;height:72px;object-fit:contain}.receipt-school{flex:1}.receipt-school h3{margin:0 0 4px;font-size:1.25rem;color:#1f2937}.receipt-school p{margin:2px 0;color:#667085;font-size:.84rem}.receipt-title{text-align:right}.receipt-title h4{margin:0;color:#344054;font-weight:700}.receipt-number{font-size:1rem;font-weight:700;color:#2f6fed}.receipt-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:16px 0}.receipt-meta-item{background:#f8fafc;border:1px solid #e4e7ec;border-radius:8px;padding:9px 11px}.receipt-meta-item span{display:block;color:#667085;font-size:.72rem}.receipt-meta-item strong{font-size:.88rem}.receipt-section{margin-top:16px}.receipt-section h6{font-weight:700;margin-bottom:8px;color:#344054}.receipt-table{width:100%;border-collapse:collapse}.receipt-table th{background:#f2f4f7;color:#475467;font-size:.75rem;text-transform:uppercase}.receipt-table th,.receipt-table td{padding:9px;border:1px solid #e4e7ec}.receipt-table td{font-size:.86rem}.receipt-table .money{text-align:right;white-space:nowrap}.receipt-summary{display:flex;justify-content:flex-end;margin-top:14px}.receipt-summary table{width:390px}.receipt-summary td{padding:5px 8px}.receipt-summary .grand{background:#edf4ff;color:#175cd3;font-size:1.05rem}.receipt-status{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.75rem;font-weight:700}.receipt-status-success{background:#dcfae6;color:#067647}.receipt-status-danger{background:#fee4e2;color:#b42318}.receipt-status-secondary{background:#eaecf0;color:#475467}.receipt-note{padding:10px 12px;background:#fffaeb;border-left:4px solid #f79009;margin-top:14px}.receipt-cancelled{position:absolute;top:43%;left:22%;transform:rotate(-18deg);font-size:6rem;font-weight:800;color:rgba(180,35,24,.13);pointer-events:none}.receipt-legacy{font-size:.72rem;color:#b54708}.receipt-footer-note{text-align:center;color:#98a2b3;font-size:.7rem;margin-top:20px}@media(max-width:800px){.receipt-meta{grid-template-columns:1fr 1fr}.receipt-header{align-items:flex-start}.receipt-title{text-align:left}.receipt-summary table{width:100%}}@media print{body{background:#fff!important}.receipt-sheet{max-width:none;padding:0}.receipt-cancelled{position:fixed}.no-print,.modal-header,.modal-footer,.sidebar,.topbar{display:none!important}.receipt-table{page-break-inside:auto}.receipt-table tr{page-break-inside:avoid}}
</style>
<div class="receipt-sheet">
    <?php if ($isCancelled): ?><div class="receipt-cancelled">ANULADO</div><?php endif; ?>
    <div class="receipt-header">
        <?php if ($logoExists): ?><img src="<?php echo htmlspecialchars($logo); ?>" class="receipt-logo" alt="Logo"><?php endif; ?>
        <div class="receipt-school">
            <h3><?php echo htmlspecialchars($school['name'] ?? 'Institución educativa'); ?></h3>
            <?php if (!empty($school['address'])): ?><p><?php echo htmlspecialchars($school['address']); ?></p><?php endif; ?>
            <p><?php echo htmlspecialchars(implode(' · ', array_filter([$school['contact_number'] ?? '', $school['email'] ?? '']))); ?></p>
        </div>
        <div class="receipt-title">
            <h4>Recibo de pago interno</h4>
            <div class="receipt-number"><?php echo htmlspecialchars($operation['receipt_full']); ?></div>
            <?php if ($isLegacy): ?><div class="receipt-legacy">Registro histórico</div><?php endif; ?>
            <span class="receipt-status receipt-status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($operation['status']); ?></span>
        </div>
    </div>

    <div class="receipt-meta">
        <div class="receipt-meta-item"><span>Estudiante</span><strong><?php echo htmlspecialchars($operation['student_name']); ?></strong></div>
        <div class="receipt-meta-item"><span>DNI</span><strong><?php echo htmlspecialchars($operation['id_no']); ?></strong></div>
        <div class="receipt-meta-item"><span>Nivel, grado y sección</span><strong><?php echo htmlspecialchars(trim($operation['nivel'] . ' · ' . $operation['grado'] . ' ' . ($operation['seccion'] ?? ''))); ?></strong></div>
        <div class="receipt-meta-item"><span>Fecha y hora</span><strong><?php echo receiptDate($operation['payment_date']); ?></strong></div>
        <div class="receipt-meta-item"><span>Registrado por</span><strong><?php echo htmlspecialchars($operation['cashier_name']); ?></strong></div>
        <div class="receipt-meta-item"><span>Caja</span><strong><?php echo !empty($operation['cash_session_id']) ? '#' . (int)$operation['cash_session_id'] : 'Sin sesión de caja'; ?></strong></div>
        <div class="receipt-meta-item"><span>Conceptos cubiertos</span><strong><?php echo count($concepts); ?></strong></div>
        <div class="receipt-meta-item"><span>Total recibido</span><strong>S/ <?php echo number_format((float)$operation['total_amount'], 2); ?></strong></div>
    </div>

    <div class="receipt-section receipt-application-section">
        <h6>Aplicación del pago</h6>
        <div class="table-responsive"><table class="receipt-table">
            <thead><tr><th>Concepto</th><th>Tarifa original</th><th>Descuento/Beca</th><th>Monto exigible</th><th>Pagado anteriormente</th><th>Saldo antes de esta boleta</th><th>Pago de esta boleta</th><th>Saldo después de esta boleta</th></tr></thead>
            <tbody><?php foreach ($concepts as $concept): ?><tr>
                <td><strong><?php echo htmlspecialchars($concept['course']); ?></strong><div class="small text-muted"><?php echo htmlspecialchars(($concept['billing_period'] ?: ($concept['year'] ?? '')) . (($concept['level'] ?? '') !== '' ? ' · ' . $concept['level'] : '')); ?></div></td>
                <td class="money">S/ <?php echo number_format($concept['original_amount'], 2); ?></td>
                <td class="money <?php echo $concept['discount_amount'] > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo $concept['discount_amount'] > 0 ? '− S/ ' . number_format($concept['discount_amount'], 2) : 'S/ 0.00'; ?><?php if ($concept['discount_reason'] !== ''): ?><div class="small text-muted" style="white-space:normal"><?php echo htmlspecialchars($concept['discount_reason']); ?></div><?php endif; ?></td>
                <td class="money">S/ <?php echo number_format($concept['effective_amount'], 2); ?></td>
                <td class="money">S/ <?php echo number_format($concept['paid_before_snapshot'], 2); ?></td>
                <td class="money"><strong>S/ <?php echo number_format($concept['balance_before_snapshot'], 2); ?></strong></td>
                <td class="money"><strong>S/ <?php echo number_format((float)$concept['payment_amount'], 2); ?></strong></td>
                <td class="money">S/ <?php echo number_format($concept['current_balance'], 2); ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    </div>

    <div class="receipt-section receipt-methods-section">
        <h6>Medios de pago</h6>
        <div class="table-responsive"><table class="receipt-table">
            <thead><tr><th>Medio</th><th>Banco o entidad</th><th>N.º operación</th><th>Fecha operación</th><th>Monto</th></tr></thead>
            <tbody><?php if ($methods): foreach ($methods as $method): ?><tr>
                <td><strong><?php echo htmlspecialchars($method['method_name']); ?></strong></td>
                <td><?php echo htmlspecialchars($method['bank_name'] ?: '—'); ?></td>
                <td><?php echo htmlspecialchars($method['reference_number'] ?: '—'); ?></td>
                <td><?php echo !empty($method['operation_date']) ? receiptDate($method['operation_date'], false) : '—'; ?></td>
                <td class="money"><strong>S/ <?php echo number_format((float)$method['amount'], 2); ?></strong></td>
            </tr><?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted">Sin detalle del medio de pago.</td></tr><?php endif; ?></tbody>
        </table></div>
    </div>

    <div class="receipt-summary"><table>
        <tr><td>Tarifa original</td><td class="text-right">S/ <?php echo number_format($originalTotal, 2); ?></td></tr>
        <tr><td>Descuento o beca aplicada</td><td class="text-right <?php echo $discountTotal > 0 ? 'text-success' : 'text-muted'; ?>"><strong><?php echo $discountTotal > 0 ? '− S/ ' . number_format($discountTotal, 2) : 'S/ 0.00'; ?></strong></td></tr>
        <tr><td>Monto exigible después del descuento</td><td class="text-right">S/ <?php echo number_format($effectiveTotal, 2); ?></td></tr>
        <tr><td>Pagado antes de esta boleta</td><td class="text-right">S/ <?php echo number_format($paidBeforeTotal, 2); ?></td></tr>
        <tr><td><strong>Saldo antes de esta boleta</strong></td><td class="text-right"><strong>S/ <?php echo number_format($balanceBeforeTotal, 2); ?></strong></td></tr>
        <tr class="grand"><td><strong>Total recibido</strong></td><td class="text-right"><strong>S/ <?php echo number_format((float)$operation['total_amount'], 2); ?></strong></td></tr>
        <tr><td>Saldo después de esta boleta</td><td class="text-right"><strong>S/ <?php echo number_format($currentBalance, 2); ?></strong></td></tr>
    </table></div>

    <?php if (trim((string)$operation['remarks']) !== ''): ?><div class="receipt-note"><strong>Observaciones:</strong> <?php echo nl2br(htmlspecialchars($operation['remarks'])); ?></div><?php endif; ?>
    <?php if (!empty($operation['corrected_from_receipt'])): ?><div class="alert alert-info mt-3 mb-0"><strong>Recibo de corrección.</strong> Reemplaza al recibo <?php echo htmlspecialchars($operation['corrected_from_receipt']); ?>. <?php echo htmlspecialchars($operation['correction_reason'] ?? ''); ?></div><?php endif; ?>
    <?php if (!empty($operation['corrected_by_receipt'])): ?><div class="alert alert-danger mt-3 mb-0"><strong>Recibo reemplazado.</strong> La operación válida fue emitida como <?php echo htmlspecialchars($operation['corrected_by_receipt']); ?>.</div><?php endif; ?>
    <?php if ($isCancelled): ?><div class="alert alert-danger mt-3 mb-0"><strong>Operación anulada.</strong> <?php echo htmlspecialchars($operation['cancellation_reason'] ?: 'Sin motivo registrado.'); ?><?php if (!empty($operation['cancelled_at'])): ?> · <?php echo receiptDate($operation['cancelled_at']); ?><?php endif; ?><?php if (!empty($operation['cancelled_by_name'])): ?> · Por <?php echo htmlspecialchars($operation['cancelled_by_name']); ?><?php endif; ?></div><?php endif; ?>
    <div class="receipt-footer-note">Documento interno de control de pagos. No reemplaza un comprobante electrónico.</div>
</div>
