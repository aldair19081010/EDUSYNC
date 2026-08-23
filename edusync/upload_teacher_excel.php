<?php
header('Content-Type: application/json');

// Registrar errores para depuración
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
error_log("Iniciando proceso de carga de Excel para docentes...");

require_once 'vendor/autoload.php';
include 'db_connect.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

try {
    // Verificar si el usuario está logueado y obtener el ID del colegio
    session_start();
    if (!isset($_SESSION['login_id'])) {
        throw new Exception('No hay sesión activa. Por favor, inicie sesión.');
    }

    // Obtener el ID del colegio del administrador logueado
    $school_id = $_SESSION['login_school_id'] ?? 0;
    if (!$school_id) {
        throw new Exception('No se pudo determinar el colegio del usuario actual.');
    }

    error_log("Usuario logueado. School ID: $school_id");

    // Validar que la solicitud sea POST y que se haya subido un archivo
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método de solicitud inválido. Se esperaba POST.');
    }
    
    if (!isset($_FILES['excel_file']) || empty($_FILES['excel_file']['tmp_name'])) {
        throw new Exception('No se recibió ningún archivo.');
    }

    $file = $_FILES['excel_file'];
    error_log("Archivo recibido: " . $file['name']);

    // Validar errores de subida
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por PHP.',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido por el formulario.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente.',
            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal.',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en el disco.',
            UPLOAD_ERR_EXTENSION => 'Una extensión PHP detuvo la subida del archivo.'
        ];
        $errorMsg = isset($errors[$file['error']]) ? $errors[$file['error']] : 'Error desconocido al subir el archivo.';
        throw new Exception($errorMsg);
    }

    // Cargar el archivo Excel
    error_log("Cargando archivo Excel para docentes...");
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();
    error_log("Filas en el Excel: " . count($rows));

    // Validar que el archivo tenga datos
    if (count($rows) <= 1) {
        throw new Exception('El archivo no contiene datos suficientes. Solo tiene encabezados.');
    }

    // Validar encabezados
    $headers = array_map('strtolower', $rows[0]);
    $requiredHeaders = ['dni', 'nombre', 'correo', 'contacto', 'direccion', 'especialidad'];

    foreach ($requiredHeaders as $header) {
        if (!in_array($header, $headers)) {
            throw new Exception("Falta el encabezado requerido: $header");
        }
    }

    // Procesar filas
    if (!$conn->begin_transaction()) {
        throw new Exception("Error al iniciar la transacción en la base de datos.");
    }
    
    error_log("Iniciando procesamiento de datos de docentes para el colegio ID: $school_id");
    $inserted = 0;
    $updated = 0;
    $errors = [];

    for ($i = 1; $i < count($rows); $i++) {
        $rowData = [];
        foreach ($requiredHeaders as $index => $header) {
            $colIndex = array_search($header, $headers);
            if ($colIndex !== false) {
                $rowData[$header] = isset($rows[$i][$colIndex]) ? $rows[$i][$colIndex] : '';
            }
        }

        $dni = $conn->real_escape_string(trim($rowData['dni']));
        $name = $conn->real_escape_string(trim($rowData['nombre']));
        
        // Validar datos obligatorios
        if (empty($dni) || empty($name)) {
            $errors[] = "Fila " . ($i + 1) . ": DNI y Nombre son obligatorios.";
            continue;
        }

        $email = $conn->real_escape_string(trim($rowData['correo']));
        $contact = $conn->real_escape_string(trim($rowData['contacto']));
        $address = $conn->real_escape_string(trim($rowData['direccion']));
        $specialty = $conn->real_escape_string(trim($rowData['especialidad']));

        // Verificar si el docente ya existe en este colegio
        $existing_query = "SELECT id FROM teacher WHERE id_no = '$dni' AND school_id = $school_id";
        $existing = $conn->query($existing_query);
        
        if (!$existing) {
            throw new Exception("Error en la consulta: " . $conn->error);
        }

        if ($existing->num_rows > 0) {
            // Actualizar docente existente
            $teacherId = $existing->fetch_assoc()['id'];
            $update_query = "UPDATE teacher SET 
                name = '$name', 
                email = '$email', 
                contact = '$contact', 
                address = '$address', 
                specialty = '$specialty' 
                WHERE id = $teacherId AND school_id = $school_id";
            
            if (!$conn->query($update_query)) {
                throw new Exception("Error al actualizar docente: " . $conn->error);
            }
            
            $updated++;
            error_log("Docente actualizado: $dni - $name");
        } else {
            // Insertar nuevo docente con el school_id del administrador
            $insert_query = "INSERT INTO teacher (id_no, name, email, contact, address, specialty, school_id) 
                           VALUES ('$dni', '$name', '$email', '$contact', '$address', '$specialty', $school_id)";
            
            if (!$conn->query($insert_query)) {
                throw new Exception("Error al insertar docente: " . $conn->error);
            }
            
            $inserted++;
            error_log("Docente insertado: $dni - $name en colegio ID: $school_id");
        }
    }

    if (!empty($errors)) {
        $conn->rollback();
        throw new Exception("Se encontraron errores: " . implode(", ", $errors));
    }

    if ($conn->commit()) {
        error_log("Transacción completada: $inserted insertados, $updated actualizados para el colegio ID: $school_id");
        echo json_encode(['status' => 'success', 'message' => "$inserted docentes agregados, $updated actualizados."]);
    } else {
        throw new Exception("Error al finalizar la transacción: " . $conn->error);
    }
    
} catch (Exception $e) {
    error_log("Error en upload_teacher_excel.php: " . $e->getMessage());
    
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
