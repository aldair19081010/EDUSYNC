<?php
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$type = (int)($_SESSION['login_type'] ?? 0);
if (!$schoolId || $type !== 1) {
    http_response_code(403);
    exit('No tiene permisos.');
}

$required = $conn->query("SHOW COLUMNS FROM cash_sessions LIKE 'operation_count'");
if (!$required || !$required->num_rows) exit('Ejecute sql/cash_report_upgrade.sql.');

$from = trim($_GET['date_from'] ?? '');
$to = trim($_GET['date_to'] ?? '');
$stmt = $conn->prepare("SELECT cs.*, COALESCE(u.name,'Usuario') user_name, COALESCE(cu.name,'') closed_by_name,
    (SELECT GROUP_CONCAT(CONCAT(pm.name, ': S/ ', FORMAT((SELECT SUM(pom.amount) FROM payment_operations po INNER JOIN payment_operation_methods pom ON pom.operation_id=po.id WHERE po.cash_session_id=cs.id AND po.status='Confirmado' AND pom.payment_method_id=pm.id),2)) ORDER BY pm.name SEPARATOR ' | ')
     FROM payment_methods pm
     WHERE EXISTS (SELECT 1 FROM payment_operations po INNER JOIN payment_operation_methods pom ON pom.operation_id=po.id WHERE po.cash_session_id=cs.id AND po.status='Confirmado' AND pom.payment_method_id=pm.id)) method_summary
    FROM cash_sessions cs LEFT JOIN users u ON u.id=cs.user_id LEFT JOIN users cu ON cu.id=cs.closed_by
    WHERE cs.school_id=? AND (?='' OR DATE(cs.opened_at)>=?) AND (?='' OR DATE(cs.opened_at)<=?)
    ORDER BY cs.opened_at DESC");
$stmt->bind_param('issss', $schoolId, $from, $from, $to, $to);
$stmt->execute();
$result = $stmt->get_result();

$book = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle('Cierres de caja');
$headers = ['Responsable','Apertura','Cierre','Estado','Saldo inicial','Operaciones','Medios de pago','Efectivo esperado','Efectivo contado','Diferencia','Pagos anulados','Cerrado por','Observaciones'];
$sheet->fromArray($headers, null, 'A1');
$row = 2;
while ($item = $result->fetch_assoc()) {
    $sheet->fromArray([
        $item['user_name'], $item['opened_at'], $item['closed_at'], $item['status'],
        (float)$item['opening_balance'], (int)$item['operation_count'], $item['method_summary'] ?: 'Sin operaciones',
        (float)($item['expected_balance'] ?? 0), (float)($item['closing_balance'] ?? 0),
        (float)($item['difference_amount'] ?? 0), (float)$item['cancelled_total'],
        $item['closed_by_name'], $item['notes']
    ], null, 'A' . $row++);
}
$stmt->close();
$sheet->getStyle('A1:M1')->getFont()->setBold(true);
$sheet->getStyle('E2:F' . max(2, $row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('H2:K' . max(2, $row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
foreach (range('A', 'M') as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
$sheet->freezePane('A2');

$filename = 'reporte_caja_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
(new Xlsx($book))->save('php://output');
exit;
