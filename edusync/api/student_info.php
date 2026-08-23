<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
ob_start();
ini_set('display_errors', 0);
include '../db_connect.php';
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');

// Recibe el DNI por GET o POST
$dni = $_GET['dni'] ?? ($_POST['dni'] ?? '');

if (!$dni) {
    echo json_encode(['status' => 'error', 'message' => 'DNI no recibido']);
    exit;
}

// Busca al estudiante por su DNI
$q = $conn->query("
    SELECT 
        s.id_no AS dni,
        s.name AS nombre,
        s.contact AS telefono,
        s.email,
        s.nivel,
        s.grado,
        s.seccion,
        s.address AS direccion,
        sc.name AS colegio
    FROM student s
    LEFT JOIN schools sc ON sc.id = s.school_id
    WHERE s.id_no = '$dni'
");

if (!$q || $q->num_rows == 0) {
    echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']);
    exit;
}

$data = $q->fetch_assoc();

// Formato de respuesta JSON
$response = [
    'status' => 'ok',
    'data' => [
        'dni' => $data['dni'],
        'nombre' => $data['nombre'],
        'nivel' => $data['nivel'],
        'grado' => $data['grado'],
        'seccion' => $data['seccion'],
        'telefono' => $data['telefono'],
        'email' => $data['email'],
        'direccion' => $data['direccion'],
        'colegio' => $data['colegio']
    ]
];

echo json_encode($response);
?>