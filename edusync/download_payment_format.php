<?php
// Preparar salida limpia y sesión consistente
ob_start();
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
if(!isset($_SESSION['login_id'])){
    if (ob_get_length()) ob_end_clean();
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Crear un nuevo archivo Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Agregar encabezados
$headers = ['DNI_ESTUDIANTE', 'CÓDIGO_PAGO'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}

// Datos de ejemplo
$sheet->setCellValue('A2', '12345678');
$sheet->setCellValue('B2', '1');

$sheet->setCellValue('A3', '87654321');
$sheet->setCellValue('B3', '2');

// Formatear cabeceras
$sheet->getStyle('A1:B1')->getFont()->setBold(true);

// Autoajustar el ancho de las columnas
foreach (range('A', 'B') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Agregar instrucciones en una nueva hoja
$spreadsheet->createSheet();
$spreadsheet->setActiveSheetIndex(1);
$instructionSheet = $spreadsheet->getActiveSheet();
$instructionSheet->setTitle('Instrucciones');

$instructionSheet->setCellValue('A1', 'INSTRUCCIONES PARA SUBIR PAGOS');
$instructionSheet->setCellValue('A3', '1. DNI_ESTUDIANTE: DNI del estudiante al que se asignará el pago');
$instructionSheet->setCellValue('A4', '2. CÓDIGO_PAGO: ID del concepto de pago a asignar');
$instructionSheet->setCellValue('A6', 'Notas importantes:');
$instructionSheet->setCellValue('A7', '- El estudiante debe existir en el sistema y pertenecer al colegio actual');
$instructionSheet->setCellValue('A8', '- El concepto de pago debe existir en el sistema');
$instructionSheet->setCellValue('A9', '- No se puede asignar dos veces el mismo concepto a un estudiante');

$instructionSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$instructionSheet->getColumnDimension('A')->setWidth(60);

// Volver a la primera hoja antes de guardar
$spreadsheet->setActiveSheetIndex(0);

// Configurar el tipo de contenido y encabezados para la descarga
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Formato_Asignacion_Pagos.xlsx"');
header('Cache-Control: max-age=0');

// Configurar Writer para descargar el archivo
$writer = new Xlsx($spreadsheet);
if (ob_get_length()) ob_end_clean();
$writer->save('php://output');
exit;
?>
