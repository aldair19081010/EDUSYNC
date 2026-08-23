<?php
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Crear un nuevo archivo Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Agregar encabezados
$headers = ['DNI', 'NOMBRE', 'CORREO', 'CONTACTO', 'DIRECCION', 'ESPECIALIDAD'];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}

// Datos de ejemplo (opcional)
$sheet->setCellValue('A2', '12345678');
$sheet->setCellValue('B2', 'JUAN PÉREZ');
$sheet->setCellValue('C2', 'juan.perez@ejemplo.com');
$sheet->setCellValue('D2', '987654321');
$sheet->setCellValue('E2', 'AV. EJEMPLO 123');
$sheet->setCellValue('F2', 'MATEMÁTICAS');

// Formatear cabeceras
$sheet->getStyle('A1:F1')->getFont()->setBold(true);

// Autoajustar el ancho de las columnas
foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Configurar el tipo de contenido y encabezados para la descarga
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Formato_Docentes.xlsx"');
header('Cache-Control: max-age=0');

// Configurar Writer para descargar el archivo
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
