<?php
// Plantilla de descarga para importación de estudiantes
// Configuración de sesión consistente
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Reanudar sesión por sid (GET/POST/header) antes de cualquier uso de $_SESSION
if (isset($_GET['sid'])) {
    session_id($_GET['sid']);
} elseif (isset($_POST['sid'])) {
    session_id($_POST['sid']);
} elseif (isset($_SERVER['HTTP_SID'])) {
    session_id($_SERVER['HTTP_SID']);
}
if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'db_connect.php';

// Solo usuarios autenticados pueden descargar el formato
if (!isset($_SESSION['login_type'])) {
    http_response_code(403);
    echo 'Acceso no autorizado.';
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

if (!class_exists(Spreadsheet::class) || !class_exists(Xlsx::class)) {
    http_response_code(500);
    echo 'No está instalada la dependencia PhpSpreadsheet. Ejecute: composer require phpoffice/phpspreadsheet';
    exit;
}

// Obtener nombre del colegio (opcional) para el nombre del archivo
$school_id = $_SESSION['login_school_id'] ?? 0;
$school_name = 'Institucion_Educativa';
if (!empty($school_id)) {
    $stmt = $conn->prepare("SELECT name FROM schools WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $school_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (!empty($row['name'])) $school_name = $row['name'];
        }
    }
}

// Construir nombre de archivo seguro
$safe_school = preg_replace('/[^A-Za-z0-9\-_]/', '_', $school_name);
$filename = "Formato_Estudiantes_{$safe_school}.xlsx";

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Estudiantes');

// Encabezados (incluyendo campos de tutores)
$headers = [
    'DNI', 'NOMBRE', 'CORREO', 'CONTACTO', 'DIRECCION', 'NIVEL', 'GRADO', 'SECCION',
    // Tutor 1 (Principal) - Nombres y Apellidos obligatorios
    'TUTOR1_NOMBRE', 'TUTOR1_APELLIDO', 'TUTOR1_DNI', 'TUTOR1_TELEFONO', 'TUTOR1_RELACION', 'TUTOR1_DIRECCION',
    // Tutor 2 (Opcional)
    'TUTOR2_NOMBRE', 'TUTOR2_APELLIDO', 'TUTOR2_DNI', 'TUTOR2_TELEFONO', 'TUTOR2_RELACION', 'TUTOR2_DIRECCION',
    'GENERO', 'STATUS'
];
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '1', $header);
    $col++;
}

// Fila de ejemplo
$sheet->setCellValue('A2', '12345678');
$sheet->setCellValue('B2', 'JUAN PÉREZ');
$sheet->setCellValue('C2', 'juan@ejemplo.com');
$sheet->setCellValue('D2', '987654321');
$sheet->setCellValue('E2', 'AV. EJEMPLO 123');
$sheet->setCellValue('F2', 'PRIMARIA');
$sheet->setCellValue('G2', '5°');
$sheet->setCellValue('H2', 'U'); // U = Única / or A, B, etc.

// Ejemplo tutor 1 (principal) - Nombres y Apellidos obligatorios
$sheet->setCellValue('I2', 'MARIA');
$sheet->setCellValue('J2', 'GÓMEZ');
$sheet->setCellValue('K2', '87654321');
$sheet->setCellValue('L2', '912345678');
$sheet->setCellValue('M2', 'Madre');
$sheet->setCellValue('N2', 'AV. TUTOR 456');

// Ejemplo tutor 2 (opcional)
$sheet->setCellValue('O2', 'LUIS');
$sheet->setCellValue('P2', 'HERNÁNDEZ');
$sheet->setCellValue('Q2', '');
$sheet->setCellValue('R2', '');
$sheet->setCellValue('S2', 'Padre');
$sheet->setCellValue('T2', '');
$sheet->setCellValue('U2', 'Femenino');
$sheet->setCellValue('V2', 'Activo');

// Formato (cabeceras en negrita)
$sheet->getStyle('A1:V1')->getFont()->setBold(true);

// Listas desplegables para evitar valores inválidos al llenar la plantilla.
$validations = [
    'F2:F1000' => '"Inicial,Primaria,Secundaria"',
    'H2:H1000' => '"U,A,B,C,D,E,F"',
    'U2:U1000' => '"Masculino,Femenino,Otro"',
    'V2:V1000' => '"Activo,Egresado,Retirado"'
];
foreach ($validations as $range => $formula) {
    $validation = new DataValidation();
    $validation->setType(DataValidation::TYPE_LIST);
    $validation->setErrorStyle(DataValidation::STYLE_STOP);
    $validation->setAllowBlank(true);
    $validation->setShowInputMessage(true);
    $validation->setShowErrorMessage(true);
    $validation->setShowDropDown(true);
    $validation->setErrorTitle('Valor no permitido');
    $validation->setError('Seleccione un valor de la lista.');
    $validation->setPromptTitle('Seleccione una opción');
    $validation->setPrompt('Use la lista desplegable para elegir un valor válido.');
    $validation->setFormula1($formula);
    $sheet->setDataValidation($range, $validation);
}

// Hoja de referencia para explicar los valores aceptados.
$instructions = $spreadsheet->createSheet();
$instructions->setTitle('Instrucciones');
$instructions->setCellValue('A1', 'CAMPO');
$instructions->setCellValue('B1', 'VALORES PERMITIDOS');
$instructions->setCellValue('A2', 'NIVEL');
$instructions->setCellValue('B2', 'Inicial, Primaria o Secundaria');
$instructions->setCellValue('A3', 'SECCION');
$instructions->setCellValue('B3', 'U, A, B, C, D, E o F');
$instructions->setCellValue('A4', 'GENERO');
$instructions->setCellValue('B4', 'Masculino, Femenino u Otro');
$instructions->setCellValue('A5', 'STATUS');
$instructions->setCellValue('B5', 'Activo, Egresado o Retirado');
$instructions->setCellValue('A7', 'Puedes escribir los valores en mayúsculas o minúsculas. El sistema los normaliza automáticamente.');
$instructions->getStyle('A1:B1')->getFont()->setBold(true);
$instructions->getColumnDimension('A')->setWidth(18);
$instructions->getColumnDimension('B')->setWidth(85);

// Abrir siempre la plantilla en la hoja principal de estudiantes.
$spreadsheet->setActiveSheetIndex(0);

// Autoajustar ancho de columnas
foreach (range('A', 'V') as $c) {
    $sheet->getColumnDimension($c)->setAutoSize(true);
}

// Limpiar buffers previos para evitar corrupción del archivo
if (ob_get_length()) ob_end_clean();

// Headers de descarga
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
// Forzar descarga con nombre seguro
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;