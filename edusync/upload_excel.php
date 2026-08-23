<?php
header('Content-Type: application/json');

// Registrar errores para depuración (no mostrar al usuario)
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
error_log("Iniciando proceso de carga de Excel...");

require_once __DIR__ . '/vendor/autoload.php';
include 'db_connect.php';

// Verificar que la extensión Zip (necesaria para leer archivos .xlsx) esté disponible
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    error_log("ZipArchive missing: la extensión zip no está habilitada en PHP.");
    throw new Exception('La extensión PHP Zip (ZipArchive) no está habilitada. Habilítela en su php.ini (habilite extension=zip o extension=php_zip.dll) y reinicie Apache/XAMPP. En XAMPP: abra Config > PHP (php.ini) o edite C:\\xampp\\php\\php.ini, busque "extension=zip" y quite el ";" al inicio, luego reinicie Apache. Verifique con php -m o en phpinfo().');
}

use PhpOffice\PhpSpreadsheet\IOFactory;

function audit_imported_student($conn, $student_id, $school_id, $action, $details) {
    $user_id = intval($_SESSION['login_id'] ?? 0) ?: null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $details_json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $audit = $conn->prepare('INSERT INTO student_audit_log (student_id, school_id, user_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$audit) return;
    $audit->bind_param('iiisss', $student_id, $school_id, $user_id, $action, $details_json, $ip_address);
    $audit->execute();
    $audit->close();
}

function normalize_student_label($value, $allowed) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $normalized = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    $normalized = strtoupper(trim((string)$normalized));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    foreach ($allowed as $canonical => $aliases) {
        if ($normalized === strtoupper($canonical)) return $canonical;
        foreach ($aliases as $alias) {
            if ($normalized === strtoupper($alias)) return $canonical;
        }
    }
    return $value;
}

function normalize_student_header($value) {
    $value = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$value) : (string)$value;
    $value = strtoupper(trim($value));
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = preg_replace('/[^A-Z0-9]+/', '_', $value);
    return strtolower(trim($value, '_'));
}

$level_values = [
    'Inicial' => ['INICIAL'],
    'Primaria' => ['PRIMARIA'],
    'Secundaria' => ['SECUNDARIA']
];
$gender_values = [
    'Masculino' => ['MASCULINO', 'M'],
    'Femenino' => ['FEMENINO', 'F'],
    'Otro' => ['OTRO', 'O']
];
$status_values = [
    'Activo' => ['ACTIVO'],
    'Egresado' => ['EGRESADO'],
    'Retirado' => ['RETIRADO']
];

try {
    // Verificar si el usuario está logueado y obtener el ID del colegio
    session_start();
    if (!isset($_SESSION['login_id'])) {
        http_response_code(401);
        throw new Exception('No hay sesión activa. Por favor, inicie sesión.');
    }

    // Obtener el ID del colegio del administrador logueado (FORZAR tipo entero)
    $school_id = intval($_SESSION['login_school_id'] ?? 0);
    if (!$school_id) {
        http_response_code(403);
        throw new Exception('No se pudo determinar el colegio del usuario actual.');
    }

    $academic_year_id = intval($_POST['academic_year_id'] ?? 0);
    $year_check = $conn->prepare('SELECT id FROM academic_year WHERE id = ? AND school_id = ? LIMIT 1');
    if (!$year_check) throw new Exception('No se pudo validar el año académico.');
    $year_check->bind_param('ii', $academic_year_id, $school_id);
    $year_check->execute();
    $valid_year = $year_check->get_result()->num_rows > 0;
    $year_check->close();
    if (!$valid_year) {
        http_response_code(400);
        throw new Exception('Seleccione un año académico válido para este colegio.');
    }

    error_log("Usuario logueado. School ID: $school_id");

    // Validar que la solicitud sea POST y que se haya subido un archivo
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(400);
        throw new Exception('Método de solicitud inválido. Se esperaba POST.');
    }
    
    if (!isset($_FILES['excel_file']) || empty($_FILES['excel_file']['tmp_name'])) {
        http_response_code(400);
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
        http_response_code(400);
        throw new Exception($errorMsg);
    }

    // Validar tamaño (ej. 8 MB)
    $maxBytes = 8 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        http_response_code(400);
        throw new Exception('El archivo es demasiado grande. Tamaño máximo: 8MB.');
    }

    // Validar extensión y tipo MIME básico
    $allowedExt = ['xls', 'xlsx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt)) {
        http_response_code(400);
        throw new Exception('Extensión de archivo no permitida. Use .xls o .xlsx.');
    }

    // Opcional: validación MIME con finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    $allowedMimes = [
        'application/vnd.ms-excel', // xls
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
        'application/octet-stream'
    ];
    if ($mime && !in_array($mime, $allowedMimes)) {
        // No siempre es fiable; permitir con aviso de log
        error_log("MIME detectado: $mime para archivo {$file['name']}");
    }

    // Cargar el archivo Excel
    error_log("Cargando archivo Excel...");
    set_time_limit(300);
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);
    // Buscar la hoja de estudiantes si el libro tiene una pestaña de instrucciones activa.
    foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
        $candidateRows = $worksheet->toArray(null, true, true, true);
        foreach (array_slice($candidateRows, 0, 10, true) as $candidateRow) {
            $candidateHeaders = array_map('normalize_student_header', $candidateRow);
            $candidateHeaderText = implode(' ', $candidateHeaders);
            if (preg_match('/(^|_)(dni|documento|identidad)(_|$)/', $candidateHeaderText)) {
                $sheet = $worksheet;
                $rows = $candidateRows;
                break 2;
            }
        }
    }
    // Normalizar encabezados para aceptar plantillas nuevas y antiguas.
    $headerRow = [];
    $firstRow = null;
    foreach (array_slice($rows, 0, 10, true) as $rowIndex => $candidateRow) {
        $candidateHeaders = array_map('normalize_student_header', $candidateRow);
        $candidateHeaderText = implode(' ', $candidateHeaders);
        if (preg_match('/(^|_)(dni|documento|identidad)(_|$)/', $candidateHeaderText) || strpos($candidateHeaderText, 'DNI') !== false) {
            $firstRow = $candidateRow;
            unset($rows[$rowIndex]);
            break;
        }
    }
    if ($firstRow === null) {
        $firstRow = array_shift($rows);
    }
    foreach ($firstRow as $col => $value) {
        $headerRow[normalize_student_header($value)] = $col;
    }

    $headerAliases = [
        'dni' => ['dni', 'id_no', 'id', 'documento', 'documento_identidad', 'dni_estudiante', 'n_dni', 'numero_dni', 'numero_documento', 'n_documento'],
        'nombre' => ['nombre', 'name', 'nombre_completo', 'estudiante', 'nombre_estudiante'],
        'correo' => ['correo', 'email', 'e_mail'],
        'contacto' => ['contacto', 'telefono', 'teléfono', 'phone'],
        'direccion' => ['direccion', 'dirección', 'address'],
        'nivel' => ['nivel', 'level'],
        'grado' => ['grado', 'grade'],
        'seccion' => ['seccion', 'sección', 'section'],
        'genero' => ['genero', 'género', 'gender', 'sexo'],
        'status' => ['status', 'estado'],
        'tutor1_nombre' => ['tutor1_nombre', 'tutor_nombre', 'apoderado_nombre'],
        'tutor1_apellido' => ['tutor1_apellido', 'tutor_apellido', 'apoderado_apellido'],
        'tutor1_dni' => ['tutor1_dni', 'tutor_dni', 'apoderado_dni'],
        'tutor1_telefono' => ['tutor1_telefono', 'tutor_telefono', 'apoderado_telefono'],
        'tutor1_relacion' => ['tutor1_relacion', 'tutor_relacion', 'apoderado_parentesco'],
        'tutor1_direccion' => ['tutor1_direccion', 'tutor_direccion', 'apoderado_direccion'],
        'tutor2_nombre' => ['tutor2_nombre'],
        'tutor2_apellido' => ['tutor2_apellido'],
        'tutor2_dni' => ['tutor2_dni'],
        'tutor2_telefono' => ['tutor2_telefono'],
        'tutor2_relacion' => ['tutor2_relacion'],
        'tutor2_direccion' => ['tutor2_direccion']
    ];
    foreach ($headerAliases as $canonical => $aliases) {
        foreach ($aliases as $alias) {
            $normalizedAlias = normalize_student_header($alias);
            if (isset($headerRow[$normalizedAlias])) {
                $headerRow[$canonical] = $headerRow[$normalizedAlias];
                break;
            }
        }
    }

    // Los tutores son opcionales porque la tabla permite valores nulos.
    $requiredHeaders = ['dni', 'nombre', 'correo', 'contacto', 'direccion', 'nivel', 'grado', 'seccion'];
    foreach ($requiredHeaders as $h) {
        if (!isset($headerRow[$h])) {
            $detectedHeaders = implode(', ', array_keys($headerRow));
            http_response_code(400);
            throw new Exception("Falta el encabezado requerido: $h. Encabezados detectados: " . ($detectedHeaders ?: 'ninguno'));
        }
    }

    // Procesar filas
    if (!$conn->begin_transaction()) {
        http_response_code(500);
        throw new Exception("Error al iniciar la transacción en la base de datos.");
    }
    
    error_log("Iniciando procesamiento de datos para el colegio ID: $school_id");
    $inserted = 0;
    $updated = 0;
    $rowErrors = [];

    // Preparar sentencias (incluyendo campos de tutor)
    $selectSQL = "SELECT id FROM student WHERE id_no = ? AND school_id = ? LIMIT 1";
    $updateSQL = "UPDATE student SET name = ?, genero = ?, email = ?, contact = ?, address = ?, nivel = ?, grado = ?, seccion = ?, academic_year_id = ?, status = ?, 
        tutor1_nombre = ?, tutor1_apellido = ?, tutor1_dni = ?, tutor1_telefono = ?, tutor1_relacion = ?, tutor1_direccion = ?,
        tutor2_nombre = ?, tutor2_apellido = ?, tutor2_dni = ?, tutor2_telefono = ?, tutor2_relacion = ?, tutor2_direccion = ? 
        WHERE id = ? AND school_id = ?";
    $insertSQL = "INSERT INTO student (id_no, name, genero, email, contact, address, nivel, grado, seccion, academic_year_id, status, 
        tutor1_nombre, tutor1_apellido, tutor1_dni, tutor1_telefono, tutor1_relacion, tutor1_direccion, 
        tutor2_nombre, tutor2_apellido, tutor2_dni, tutor2_telefono, tutor2_relacion, tutor2_direccion, school_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $selectStmt = $conn->prepare($selectSQL);
    $updateStmt = $conn->prepare($updateSQL);
    $insertStmt = $conn->prepare($insertSQL);
    if (!$selectStmt || !$updateStmt || !$insertStmt) {
        // Detallar errores al preparar las sentencias
        $err = "prepare errors: ";
        if ($selectStmt === false) $err .= 'selectStmt: ' . $conn->error . '; ';
        if ($updateStmt === false) $err .= 'updateStmt: ' . $conn->error . '; ';
        if ($insertStmt === false) $err .= 'insertStmt: ' . $conn->error . '; ';
        error_log('upload_excel.php - ' . $err);
        http_response_code(500);
        throw new Exception('Error al preparar sentencias en la base de datos. Detalle: ' . $err);
    }

    // Verificar que el número de parámetros esperado coincide (detección temprana de "Column count doesn't match")
    $expectedUpdateParams = 24; // 20 strings + academic_year_id + status + id + school_id
    $expectedInsertParams = 24; // 22 strings + academic_year_id + school_id
    if ($updateStmt->param_count != $expectedUpdateParams || $insertStmt->param_count != $expectedInsertParams) {
        error_log('upload_excel.php - Param count mismatch: update param_count=' . $updateStmt->param_count . ', insert param_count=' . $insertStmt->param_count);
        error_log('upload_excel.php - Update SQL: ' . $updateSQL);
        error_log('upload_excel.php - Insert SQL: ' . $insertSQL);
        http_response_code(500);
        throw new Exception('Error de configuración: los parámetros preparados no coinciden con los esperados. Contacte al administrador.');
    }

    $currentRowNumber = 1 + 1; // because we popped header and rows is 0-indexed
    // Log header mapping and a small sample of rows to help debug column count mismatches
    error_log('upload_excel.php - headerRow keys: ' . implode(',', array_keys($headerRow)));
    $sampleRows = array_slice($rows, 0, 3);
    foreach ($sampleRows as $i => $sr) {
        $vals = [];
        foreach ($sr as $c => $v) {
            $vals[] = substr($v, 0, 100);
        }
        error_log('upload_excel.php - sample row '.($i+1)." cols=".count($sr)." values=".implode('|', $vals));
    }
    foreach ($rows as $rr) {
        $currentRowNumber++;
        // Obtener valores por nombre de columna
        $dni = isset($rr[$headerRow['dni']]) ? trim($rr[$headerRow['dni']]) : '';
        $nombre = isset($rr[$headerRow['nombre']]) ? trim($rr[$headerRow['nombre']]) : '';

        if ($dni === '' || $nombre === '') {
            $rowErrors[] = "Fila $currentRowNumber: DNI y Nombre son obligatorios.";
            continue;
        }

        // extraer otros campos (siempre en string)
        $correo = isset($rr[$headerRow['correo']]) ? trim($rr[$headerRow['correo']]) : '';
        $genero = isset($headerRow['genero']) ? normalize_student_label($rr[$headerRow['genero']], $gender_values) : '';
        $contacto = isset($rr[$headerRow['contacto']]) ? trim($rr[$headerRow['contacto']]) : '';
        $direccion = isset($rr[$headerRow['direccion']]) ? trim($rr[$headerRow['direccion']]) : '';
        $nivel = isset($rr[$headerRow['nivel']]) ? normalize_student_label($rr[$headerRow['nivel']], $level_values) : '';
        $grado = isset($rr[$headerRow['grado']]) ? trim($rr[$headerRow['grado']]) : '';
        $seccion = isset($rr[$headerRow['seccion']]) ? trim($rr[$headerRow['seccion']]) : '';
        $status = isset($headerRow['status']) ? normalize_student_label($rr[$headerRow['status']], $status_values) : 'Activo';

        if (!in_array($nivel, ['Inicial', 'Primaria', 'Secundaria'], true)) {
            $rowErrors[] = "Fila $currentRowNumber: Nivel inválido.";
            continue;
        }
        if ($genero !== '' && !in_array($genero, ['Masculino', 'Femenino', 'Otro'], true)) {
            $rowErrors[] = "Fila $currentRowNumber: Género inválido.";
            continue;
        }
        if (!in_array($status, ['Activo', 'Egresado', 'Retirado'], true)) {
            $rowErrors[] = "Fila $currentRowNumber: Estado inválido.";
            continue;
        }

        // Campos de tutor (pueden no existir si la plantilla anterior fue usada)
        $tutor1_nombre = isset($headerRow['tutor1_nombre']) ? trim($rr[$headerRow['tutor1_nombre']]) : '';
        $tutor1_apellido = isset($headerRow['tutor1_apellido']) ? trim($rr[$headerRow['tutor1_apellido']]) : '';
        $tutor1_dni = isset($headerRow['tutor1_dni']) ? trim($rr[$headerRow['tutor1_dni']]) : '';
        $tutor1_telefono = isset($headerRow['tutor1_telefono']) ? trim($rr[$headerRow['tutor1_telefono']]) : '';
        $tutor1_relacion = isset($headerRow['tutor1_relacion']) ? trim($rr[$headerRow['tutor1_relacion']]) : '';
        $tutor1_direccion = isset($headerRow['tutor1_direccion']) ? trim($rr[$headerRow['tutor1_direccion']]) : '';

        $tutor2_nombre = isset($headerRow['tutor2_nombre']) ? trim($rr[$headerRow['tutor2_nombre']]) : '';
        $tutor2_apellido = isset($headerRow['tutor2_apellido']) ? trim($rr[$headerRow['tutor2_apellido']]) : '';
        $tutor2_dni = isset($headerRow['tutor2_dni']) ? trim($rr[$headerRow['tutor2_dni']]) : '';
        $tutor2_telefono = isset($headerRow['tutor2_telefono']) ? trim($rr[$headerRow['tutor2_telefono']]) : '';
        $tutor2_relacion = isset($headerRow['tutor2_relacion']) ? trim($rr[$headerRow['tutor2_relacion']]) : '';
        $tutor2_direccion = isset($headerRow['tutor2_direccion']) ? trim($rr[$headerRow['tutor2_direccion']]) : '';

        // Check existing
        $selectStmt->bind_param('si', $dni, $school_id);
        if (!$selectStmt->execute()) {
            $rowErrors[] = "Fila $currentRowNumber: error en verificación existente.";
            continue;
        }
        $res = $selectStmt->get_result();

        if ($res && $res->num_rows > 0) {
            $studentId = $res->fetch_assoc()['id'];
            $updateStmt->bind_param(
                str_repeat('s',8) . 'i' . 's' . str_repeat('s',12) . 'ii',
                $nombre, $genero, $correo, $contacto, $direccion, $nivel, $grado, $seccion, $academic_year_id, $status,
                $tutor1_nombre, $tutor1_apellido, $tutor1_dni, $tutor1_telefono, $tutor1_relacion, $tutor1_direccion,
                $tutor2_nombre, $tutor2_apellido, $tutor2_dni, $tutor2_telefono, $tutor2_relacion, $tutor2_direccion,
                $studentId, $school_id
            );
            if (!$updateStmt->execute()) {
                $err = $updateStmt->error ?: $conn->error;
                error_log("upload_excel.php - Update failed at row $currentRowNumber: " . $err);
                $rowErrors[] = "Fila $currentRowNumber: error al actualizar estudiante ({$err}).";
                continue;
            }
            audit_imported_student($conn, $studentId, $school_id, 'import', ['mode' => 'update', 'id_no' => $dni]);
            $updated++;
        } else {
            $insertStmt->bind_param(
                str_repeat('s',9) . 'i' . 's' . str_repeat('s',12) . 'i',
                $dni, $nombre, $genero, $correo, $contacto, $direccion, $nivel, $grado, $seccion, $academic_year_id, $status,
                $tutor1_nombre, $tutor1_apellido, $tutor1_dni, $tutor1_telefono, $tutor1_relacion, $tutor1_direccion,
                $tutor2_nombre, $tutor2_apellido, $tutor2_dni, $tutor2_telefono, $tutor2_relacion, $tutor2_direccion,
                $school_id
            );
            if (!$insertStmt->execute()) {
                $err = $insertStmt->error ?: $conn->error;
                error_log("upload_excel.php - Insert failed at row $currentRowNumber: " . $err);
                $rowErrors[] = "Fila $currentRowNumber: error al insertar estudiante ({$err}).";
                continue;
            }
            audit_imported_student($conn, $insertStmt->insert_id, $school_id, 'import', ['mode' => 'create', 'id_no' => $dni]);
            $inserted++;
        }
    }

    if ($conn->commit()) {
        error_log("Transacción completada: $inserted insertados, $updated actualizados para el colegio ID: $school_id");
        $message = "$inserted estudiantes agregados, $updated actualizados.";
        if (!empty($rowErrors)) {
            $message .= ' Filas rechazadas: ' . count($rowErrors) . '. ' . implode(' | ', array_slice($rowErrors, 0, 10));
        }
        echo json_encode(['status' => 'success', 'message' => $message, 'inserted' => $inserted, 'updated' => $updated, 'errors' => $rowErrors]);
        exit;
    } else {
        http_response_code(500);
        throw new Exception("Error al finalizar la transacción: " . $conn->error);
    }
    
} catch (Exception $e) {
    error_log("Error en upload_excel.php: " . $e->getMessage());
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    $msg = $e->getMessage();
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}
?>