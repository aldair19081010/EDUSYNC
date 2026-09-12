<?php
ob_start();
ini_set('display_errors', '0');
include_once __DIR__ . '/includes/session_check.php';
include __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

function paymentTableResponse(array $data): void
{
    if (ob_get_length()) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function paymentTableBind($stmt, string $types, array &$params): void
{
    $bindings = [$types];
    foreach ($params as &$value) $bindings[] = &$value;
    call_user_func_array([$stmt, 'bind_param'], $bindings);
}

$draw = (int)($_GET['draw'] ?? 1);

try {
    $schoolId = (int)($_SESSION['login_school_id'] ?? 0);
    $migration = $conn->query("SHOW TABLES LIKE 'payment_operations'");
    if (!$schoolId || !$migration || !$migration->num_rows) {
        paymentTableResponse(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Ejecute sql/payments_module_upgrade.sql.']);
    }
    $correctionColumn = $conn->query("SHOW COLUMNS FROM payment_operations LIKE 'corrected_from_id'");
    $correctionsReady = $correctionColumn && $correctionColumn->num_rows > 0;
    $conceptSnapshotTable = $conn->query("SHOW TABLES LIKE 'payment_concept_snapshots'");
    $conceptSnapshotsReady = $conceptSnapshotTable && $conceptSnapshotTable->num_rows > 0;
    $conceptSnapshotJoin = $conceptSnapshotsReady ? 'LEFT JOIN payment_concept_snapshots pcs ON pcs.payment_operation_id=po.id AND pcs.debt_id=efc.id' : '';
    $legacyConceptFallback = "CASE WHEN po.school_id=1 AND efc.course_id=26 THEN 'Matrícula' ELSE CONCAT('Concepto histórico #',efc.course_id) END";
    $conceptNameSql = $conceptSnapshotsReady ? "COALESCE(NULLIF(c.course,''),NULLIF(pcs.concept_name,''),$legacyConceptFallback)" : "COALESCE(NULLIF(c.course,''),$legacyConceptFallback)";
    $conceptYearSql = $conceptSnapshotsReady ? "COALESCE(ay.year,pcs.academic_year_label)" : 'ay.year';

    $start = max(0, (int)($_GET['start'] ?? 0));
    $length = min(100, max(10, (int)($_GET['length'] ?? 15)));
    $search = trim($_GET['search']['value'] ?? '');
    $yearId = (int)($_GET['academic_year_id'] ?? 0);
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $methodId = (int)($_GET['method_id'] ?? 0);
    $where = ['po.school_id = ?'];
    $types = 'i';
    $params = [$schoolId];

    if ($dateFrom !== '') { $where[] = 'DATE(po.payment_date) >= ?'; $types .= 's'; $params[] = $dateFrom; }
    if ($dateTo !== '') { $where[] = 'DATE(po.payment_date) <= ?'; $types .= 's'; $params[] = $dateTo; }
    if ($status !== '') { $where[] = 'po.status = ?'; $types .= 's'; $params[] = $status; }
    if ($methodId > 0) { $where[] = 'EXISTS (SELECT 1 FROM payment_operation_methods pom2 WHERE pom2.operation_id = po.id AND pom2.payment_method_id = ?)'; $types .= 'i'; $params[] = $methodId; }
    if ($yearId > 0) { $where[] = 'EXISTS (SELECT 1 FROM payments py INNER JOIN student_ef_list efy ON efy.id = py.ef_id INNER JOIN courses cy ON cy.id = efy.course_id WHERE py.operation_id = po.id AND cy.academic_year_id = ?)'; $types .= 'i'; $params[] = $yearId; }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(s.name LIKE ? OR s.id_no LIKE ? OR po.receipt_full LIKE ? OR EXISTS (SELECT 1 FROM payment_operation_methods pmr WHERE pmr.operation_id = po.id AND pmr.reference_number LIKE ?))';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);
    $fromSql = "FROM payment_operations po INNER JOIN student s ON s.id = po.student_id WHERE $whereSql";
    $totalStmt = $conn->prepare('SELECT COUNT(*) total FROM payment_operations WHERE school_id = ?');
    $totalStmt->bind_param('i', $schoolId);
    $totalStmt->execute();
    $total = (int)$totalStmt->get_result()->fetch_assoc()['total'];
    $totalStmt->close();
    $countStmt = $conn->prepare("SELECT COUNT(*) total $fromSql");
    paymentTableBind($countStmt, $types, $params);
    $countStmt->execute();
    $filtered = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $orderColumns = ['po.id', 'po.payment_date', 's.id_no', 'po.receipt_full', 's.name', 'po.total_amount', 'po.status', 'po.id'];
    $orderColumn = (int)($_GET['order'][0]['column'] ?? 1);
    $orderDirection = strtolower($_GET['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $orderBy = $orderColumns[$orderColumn] ?? 'po.payment_date';
    $sql = "SELECT po.*, s.name student_name, s.id_no,
            (SELECT GROUP_CONCAT(DISTINCT pm.name ORDER BY pm.name SEPARATOR ', ') FROM payment_operation_methods pom INNER JOIN payment_methods pm ON pm.id = pom.payment_method_id WHERE pom.operation_id = po.id) methods,
            (SELECT GROUP_CONCAT(DISTINCT CONCAT($conceptNameSql, IF($conceptYearSql IS NULL OR $conceptYearSql='', '', CONCAT(' · ', $conceptYearSql))) ORDER BY $conceptYearSql, $conceptNameSql SEPARATOR ' | ')
             FROM payments pc
             INNER JOIN student_ef_list efc ON efc.id=pc.ef_id
             LEFT JOIN courses c ON c.id=efc.course_id
             LEFT JOIN academic_year ay ON ay.id=c.academic_year_id
             $conceptSnapshotJoin
             WHERE pc.operation_id=po.id
                OR (pc.operation_id IS NULL AND po.receipt_series='LEG' AND efc.student_id=po.student_id
                    AND CONVERT(po.receipt_full USING utf8mb4) COLLATE utf8mb4_general_ci
                        = CONVERT(CONCAT('LEG-',po.school_id,'-',po.student_id,'-',pc.receipt_no) USING utf8mb4) COLLATE utf8mb4_general_ci)) concepts,
            (SELECT p.id FROM payments p WHERE p.operation_id = po.id ORDER BY p.id LIMIT 1) payment_id,
            (SELECT p.ef_id FROM payments p WHERE p.operation_id = po.id ORDER BY p.id LIMIT 1) ef_id
            $fromSql ORDER BY $orderBy $orderDirection LIMIT $start, $length";
    $stmt = $conn->prepare($sql);
    paymentTableBind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($payment = $result->fetch_assoc()) {
        $operationId = (int)$payment['id'];
        $badge = $payment['status'] === 'Confirmado' ? 'success' : ($payment['status'] === 'Anulado' ? 'danger' : 'secondary');
        $hasSecondaryActions = $payment['status'] === 'Confirmado';

        $actions = '<div class="ed-row-actions">'
            . '<button class="btn btn-sm btn-outline-primary ed-action-primary" type="button" onclick="uni_modal(\'Detalle del pago\',\'view_payment.php?operation_id=' . $operationId . '\',\'modal-xl\')" title="Ver recibo" aria-label="Ver recibo"><i class="fa fa-eye"></i><span class="ed-action-label">Ver</span></button>';

        if ($hasSecondaryActions) {
            $actions .= '<div class="dropdown">'
                . '<button class="btn btn-sm ed-action-more" type="button" data-toggle="dropdown" data-boundary="window" aria-haspopup="true" aria-expanded="false" title="Más acciones" aria-label="Más acciones"><i class="fa fa-ellipsis-v"></i></button>'
                . '<div class="dropdown-menu dropdown-menu-right ed-action-menu">';

            if ($correctionsReady) {
                $actions .= '<button class="dropdown-item correct-payment" type="button" data-id="' . $operationId . '"><i class="fa fa-pen text-warning"></i>Corregir pago</button>';
            }

            $actions .= '<div class="dropdown-divider"></div>'
                . '<button class="dropdown-item cancel-payment ed-action-danger" type="button" data-id="' . $operationId . '"><i class="fa fa-ban"></i>Anular pago</button>'
                . '</div></div>';
        }

        $actions .= '</div>';

        $rows[] = [
            '<input class="payment-check" type="checkbox" value="' . $operationId . '">',
            date('d/m/Y H:i', strtotime($payment['payment_date'])),
            htmlspecialchars($payment['id_no']),
            '<strong>' . htmlspecialchars($payment['receipt_full']) . '</strong>',
            '<strong>' . htmlspecialchars($payment['student_name']) . '</strong><div class="small text-primary"><i class="fa fa-file-invoice-dollar mr-1"></i>' . htmlspecialchars($payment['concepts'] ?: 'Concepto no disponible') . '</div>',
            '<strong>S/ ' . number_format($payment['total_amount'], 2) . '</strong><div class="small text-muted">' . htmlspecialchars($payment['methods'] ?: 'Sin detalle') . '</div>',
            '<span class="badge badge-' . $badge . '">' . htmlspecialchars($payment['status']) . '</span>',
            $actions
        ];
    }
    $stmt->close();
    paymentTableResponse(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $rows]);
} catch (Throwable $error) {
    error_log('payments_table_data: ' . $error->getMessage());
    paymentTableResponse(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'No se pudo consultar el historial de pagos.']);
}
