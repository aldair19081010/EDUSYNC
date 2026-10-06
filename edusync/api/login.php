<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

ini_set('session.save_path', __DIR__ . '/../tmp');
if (!is_dir(__DIR__ . '/../tmp')) @mkdir(__DIR__ . '/../tmp');
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include '../db_connect.php';
session_start();

function student_login_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    student_login_reply([
        'status' => 'error',
        'message' => 'Método no permitido.'
    ], 405);
}

$schoolId = (int)($_POST['school_id'] ?? 0);
$dni = trim((string)($_POST['dni'] ?? $_POST['student_dni'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($schoolId <= 0 || $dni === '' || $password === '') {
    student_login_reply([
        'status' => 'error',
        'message' => 'Completa colegio, DNI/código y contraseña.'
    ]);
}

$schoolStmt = $conn->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
if (!$schoolStmt) {
    student_login_reply([
        'status' => 'error',
        'message' => 'No se pudo validar el colegio.'
    ], 500);
}
$schoolStmt->bind_param('i', $schoolId);
$schoolStmt->execute();
$school = $schoolStmt->get_result()->fetch_assoc();
$schoolStmt->close();

if (!$school) {
    student_login_reply([
        'status' => 'error',
        'message' => 'Colegio no encontrado.'
    ]);
}

$columnCheck = $conn->query("SHOW COLUMNS FROM student LIKE 'portal_password_hash'");
$hasPasswordColumn = $columnCheck && $columnCheck->num_rows > 0;
$passwordSelect = $hasPasswordColumn
    ? 'portal_password_hash'
    : "NULL AS portal_password_hash";

$studentStmt = $conn->prepare(
    "SELECT id, name, id_no, {$passwordSelect}
     FROM student
     WHERE id_no = ? AND school_id = ?
     LIMIT 1"
);
if (!$studentStmt) {
    student_login_reply([
        'status' => 'error',
        'message' => 'No se pudo validar la cuenta del estudiante.'
    ], 500);
}
$studentStmt->bind_param('si', $dni, $schoolId);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();

if (!$student) {
    student_login_reply([
        'status' => 'error',
        'message' => 'Estudiante no encontrado.'
    ]);
}

$storedHash = trim((string)($student['portal_password_hash'] ?? ''));
$passwordOk = $storedHash !== ''
    ? password_verify($password, $storedHash)
    : hash_equals((string)$student['id_no'], $password);

if (!$passwordOk) {
    student_login_reply([
        'status' => 'error',
        'message' => 'Contraseña incorrecta.'
    ]);
}

$lastLoginCol = $conn->query("SHOW COLUMNS FROM student LIKE 'portal_last_login_at'");
if ($lastLoginCol && $lastLoginCol->num_rows > 0) {
    $lastLoginAt = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s');
    $loginUp = $conn->prepare('UPDATE student SET portal_last_login_at = ? WHERE id = ? AND school_id = ?');
    if ($loginUp) {
        $studentIdForLogin = (int)$student['id'];
        $loginUp->bind_param('sii', $lastLoginAt, $studentIdForLogin, $schoolId);
        $loginUp->execute();
        $loginUp->close();
    }
}

$_SESSION['student_logged_in'] = true;
$_SESSION['login_type'] = 4;
$_SESSION['student_id'] = (int)$student['id'];
$_SESSION['student_name'] = (string)$student['name'];
$_SESSION['student_dni'] = (string)$student['id_no'];
$_SESSION['student_school_id'] = $schoolId;
$_SESSION['login_name'] = (string)$student['name'];
$_SESSION['login_school_id'] = $schoolId;

$sessionId = session_id();
session_write_close();

student_login_reply([
    'status' => 'ok',
    'message' => 'Login exitoso',
    'student_id' => (int)$student['id'],
    'dni' => (string)$student['id_no'],
    'nombre' => (string)$student['name'],
    'session_id' => $sessionId,
    'password_customized' => $storedHash !== ''
]);
