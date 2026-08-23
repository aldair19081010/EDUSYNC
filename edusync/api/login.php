<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Unificar ruta y cookie de sesiones con login.php
ini_set('session.save_path', __DIR__ . '/../tmp');
if (!is_dir(__DIR__ . '/../tmp')) @mkdir(__DIR__ . '/../tmp');
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header('Content-Type: application/json');
include '../db_connect.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id = $_POST['school_id'] ?? '';
    $dni = $_POST['dni'] ?? $_POST['student_dni'] ?? ''; // Aceptar ambos nombres
    $password = $_POST['password'] ?? '';

    // Verificar datos completos
    if (empty($school_id) || empty($dni) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
        exit;
    }

    // 1. Verificar si el colegio existe
    $school_check = $conn->query("SELECT id FROM schools WHERE id = '$school_id'");
    if (!$school_check || $school_check->num_rows == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Colegio no encontrado']);
        exit;
    }

    // 2. Buscar al estudiante por DNI y colegio
    $student_check = $conn->query("SELECT id, name FROM student WHERE id_no = '$dni' AND school_id = '$school_id'");
    if (!$student_check || $student_check->num_rows == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']);
        exit;
    }
    $student = $student_check->fetch_assoc();
    
    // 3. Verificar que la contraseña sea igual al DNI
    if ($password === $dni) {
        // Login exitoso - Establecer sesión
        $_SESSION['student_logged_in'] = true;
        $_SESSION['login_type'] = 4; // Tipo de usuario: Estudiante
        $_SESSION['student_id'] = $student['id'];
        $_SESSION['student_name'] = $student['name'];
        $_SESSION['student_dni'] = $dni;
        $_SESSION['student_school_id'] = $school_id;
        $_SESSION['login_name'] = $student['name']; // Para compatibilidad con home.php
        $_SESSION['login_school_id'] = $school_id; // Para compatibilidad con home.php
        
        echo json_encode([
            'status' => 'ok', 
            'message' => 'Login exitoso',
            'student_id' => $student['id'],
            'dni' => $dni,
            'nombre' => $student['name']
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Contraseña incorrecta']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
?>