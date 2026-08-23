<?php
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
error_log("Iniciando proceso de Excel para pagos...");

require_once __DIR__ . '/vendor/autoload.php';
include 'db_connect.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

try {
    // Verificar sesión y archivo
    if (!isset($_SESSION['login_id'])) {
        throw new Exception('No hay sesión activa.');
    }
    
    $school_id = $_SESSION['login_school_id'] ?? 0;
    if (!$school_id) {
        throw new Exception('No se pudo determinar el colegio del usuario.');
    }
    
    if (!isset($_FILES['payment_excel_file']) || empty($_FILES['payment_excel_file']['tmp_name'])) {
        throw new Exception('No se recibió ningún archivo.');
    }
    
    $file = $_FILES['payment_excel_file'];
    
    // Cargar Excel
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();
    
    if (count($rows) <= 1) {
        throw new Exception('El archivo solo contiene encabezados o está vacío.');
    }
    
    error_log("Encabezados recibidos: " . json_encode($rows[0]));
    
    // Asignar índices de columnas de forma más flexible
    $dni_index = 0;      // Por defecto, la primera columna es DNI
    $codigo_index = 1;   // Por defecto, la segunda columna es Código
    
    // Intentar detectar los encabezados si existen
    if (count($rows[0]) >= 2) {
        $headers = $rows[0];
        
        // Normalizar encabezados para evitar problemas con mayúsculas, acentos, etc.
        $normalized_headers = [];
        foreach ($headers as $idx => $header) {
            // Convertir a minúsculas y eliminar acentos
            $clean_header = strtolower(trim($header));
            $clean_header = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $clean_header);
            $normalized_headers[$idx] = $clean_header;
            
            error_log("Encabezado $idx: Original='$header', Normalizado='$clean_header'");
        }
        
        // Buscar por coincidencias parciales en los encabezados
        foreach ($normalized_headers as $idx => $header) {
            if (strpos($header, 'dni') !== false || strpos($header, 'documento') !== false || 
                strpos($header, 'estudiante') !== false) {
                $dni_index = $idx;
                error_log("Columna DNI detectada en índice $idx: '$header'");
            }
            
            if (strpos($header, 'codigo') !== false || strpos($header, 'code') !== false || 
                strpos($header, 'pago') !== false || strpos($header, 'concepto') !== false) {
                $codigo_index = $idx;
                error_log("Columna CÓDIGO detectada en índice $idx: '$header'");
            }
        }
    }
    
    error_log("Índices finales: DNI=$dni_index, CÓDIGO=$codigo_index");
    
    // Procesar datos
    $conn->begin_transaction();
    
    $inserted = 0;
    $errors = [];
    
    for ($i = 1; $i < count($rows); $i++) {
        // Validar que las filas tengan suficientes columnas
        if (count($rows[$i]) <= max($dni_index, $codigo_index)) {
            $errors[] = "Fila ".($i+1).": No tiene suficientes columnas";
            continue;
        }
        
        // Validar que las columnas no estén vacías
        if (empty(trim($rows[$i][$dni_index])) || empty(trim($rows[$i][$codigo_index]))) {
            $errors[] = "Fila ".($i+1).": DNI o Código de pago están vacíos";
            continue;
        }
        
        $dni = $conn->real_escape_string(trim($rows[$i][$dni_index]));
        $codigo = $conn->real_escape_string(trim($rows[$i][$codigo_index]));
        
        // Buscar estudiante
        $student = $conn->query("SELECT id FROM student WHERE id_no = '$dni' AND school_id = $school_id");
        if ($student->num_rows == 0) {
            $errors[] = "Fila ".($i+1).": Estudiante con DNI $dni no encontrado en este colegio.";
            continue;
        }
        $student_id = $student->fetch_assoc()['id'];
        
        // Buscar concepto de pago
        $course = $conn->query("SELECT id, total_amount FROM courses WHERE id = '$codigo'");
        if ($course->num_rows == 0) {
            $errors[] = "Fila ".($i+1).": Concepto de pago con código $codigo no encontrado.";
            continue;
        }
        $course_data = $course->fetch_assoc();
        $course_id = $course_data['id'];
        $total_amount = $course_data['total_amount'];
        
        // Verificar si ya existe
        $check = $conn->query("SELECT id FROM student_ef_list WHERE student_id = $student_id AND course_id = $course_id");
        if ($check->num_rows > 0) {
            $errors[] = "Fila ".($i+1).": Este pago ya está asignado al estudiante.";
            continue;
        }
        
        // Insertar pago
        $insert = $conn->query("INSERT INTO student_ef_list (student_id, course_id, total_fee) VALUES ($student_id, $course_id, $total_amount)");
        if (!$insert) {
            throw new Exception("Error al insertar pago: ".$conn->error);
        }
        
        $inserted++;
    }
    
    // Finalizar transacción
    if ($inserted > 0) {
        $conn->commit();
        $message = "$inserted pagos agregados correctamente.";
        if (count($errors) > 0) {
            $message .= " Hubo ".count($errors)." errores.";
        }
        echo json_encode(['status' => 1, 'message' => $message, 'details' => ['assigned' => $inserted, 'skipped' => 0, 'errors' => count($errors), 'error_list' => array_slice($errors, 0, 5)]]);
    } else {
        $conn->rollback();
        throw new Exception("No se pudo agregar ningún pago. ".implode("; ", $errors));
    }
    
} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    
    error_log("Error en process_payment_excel.php: ".$e->getMessage());
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
?>
