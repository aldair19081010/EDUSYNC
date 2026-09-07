<?php
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';

$school = (int)($_SESSION['login_school_id'] ?? 0);
$type = (int)($_SESSION['login_type'] ?? 0);
if (!$school || $type !== 1) die('No autorizado.');

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$snapshotCheck = $conn->query("SHOW TABLES LIKE 'payment_concept_snapshots'");
$hasConceptSnapshots = $snapshotCheck && $snapshotCheck->num_rows > 0;
$snapshotJoin = $hasConceptSnapshots
    ? 'LEFT JOIN payment_concept_snapshots pcs ON pcs.payment_operation_id=po.id AND pcs.debt_id=ef.id'
    : '';
$legacyConceptFallback = "CASE WHEN po.school_id=1 AND ef.course_id=26 THEN 'Matrícula' ELSE CONCAT('Concepto histórico #',ef.course_id) END";
$conceptName = $hasConceptSnapshots
    ? "COALESCE(NULLIF(c.course,''),NULLIF(pcs.concept_name,''),$legacyConceptFallback)"
    : "COALESCE(NULLIF(c.course,''),$legacyConceptFallback)";

$where = ['po.school_id=?'];
$types = 'i';
$params = [$school];
$year = (int)($_GET['academic_year_id'] ?? 0);
$from = trim($_GET['date_from'] ?? '');
$to = trim($_GET['date_to'] ?? '');
$status = trim($_GET['status'] ?? '');
$method = (int)($_GET['method_id'] ?? 0);
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')))));

if ($ids) $where[] = 'po.id IN(' . implode(',', $ids) . ')';
if ($from) { $where[] = 'DATE(po.payment_date)>=?'; $types .= 's'; $params[] = $from; }
if ($to) { $where[] = 'DATE(po.payment_date)<=?'; $types .= 's'; $params[] = $to; }
if ($status) { $where[] = 'po.status=?'; $types .= 's'; $params[] = $status; }
if ($method) {
    $where[] = 'EXISTS(SELECT 1 FROM payment_operation_methods px WHERE px.operation_id=po.id AND px.payment_method_id=?)';
    $types .= 'i';
    $params[] = $method;
}
if ($year) {
    $where[] = 'EXISTS(SELECT 1 FROM payments py INNER JOIN student_ef_list ey ON ey.id=py.ef_id INNER JOIN courses cy ON cy.id=ey.course_id WHERE py.operation_id=po.id AND cy.academic_year_id=?)';
    $types .= 'i';
    $params[] = $year;
}

$sql = "SELECT po.receipt_full,po.payment_date,po.status,po.total_amount,po.remarks,
        po.cancellation_reason,s.id_no,s.name,
        (SELECT GROUP_CONCAT(CONCAT(pm.name,': S/ ',FORMAT(pom.amount,2),
            IF(pom.reference_number IS NULL,'',CONCAT(' #',pom.reference_number)))
            ORDER BY pm.name SEPARATOR ' | ')
         FROM payment_operation_methods pom
         INNER JOIN payment_methods pm ON pm.id=pom.payment_method_id
         WHERE pom.operation_id=po.id) methods,
        (SELECT GROUP_CONCAT(CONCAT($conceptName,': S/ ',FORMAT(p.amount,2))
            ORDER BY $conceptName SEPARATOR ' | ')
         FROM payments p
         INNER JOIN student_ef_list ef ON ef.id=p.ef_id
         LEFT JOIN courses c ON c.id=ef.course_id
         $snapshotJoin
         WHERE p.operation_id=po.id) concepts,
        COALESCE(u.name,'Sistema') cashier
    FROM payment_operations po
    INNER JOIN student s ON s.id=po.student_id
    LEFT JOIN users u ON u.id=po.created_by
    WHERE " . implode(' AND ', $where) . '
    ORDER BY po.payment_date DESC';

$stmt = $conn->prepare($sql);
$bind = [$types];
foreach ($params as &$value) $bind[] = &$value;
call_user_func_array([$stmt, 'bind_param'], $bind);
$stmt->execute();
$result = $stmt->get_result();

$book = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle('Pagos');
$headers = ['Recibo','Fecha','DNI','Estudiante','Conceptos','Medios de pago','Total','Estado','Cajero','Observaciones','Motivo anulación'];
$sheet->fromArray($headers, null, 'A1');
$sheet->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F6FED');
$row = 2;
while ($item = $result->fetch_assoc()) {
    $sheet->fromArray([
        $item['receipt_full'], $item['payment_date'], $item['id_no'], $item['name'],
        $item['concepts'], $item['methods'], (float)$item['total_amount'], $item['status'],
        $item['cashier'], $item['remarks'], $item['cancellation_reason']
    ], null, 'A' . $row++);
}
foreach (range('A', 'K') as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:K' . max(1, $row - 1));

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="pagos_' . date('Ymd_His') . '.xlsx"');
(new Xlsx($book))->save('php://output');
exit;
