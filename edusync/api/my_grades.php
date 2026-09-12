<?php
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../db_connect.php';
require_once __DIR__ . '/grade_debt_guard.php';

$dni = trim((string)($_GET['dni'] ?? ($_POST['dni'] ?? '')));
if ($dni !== '') {
    $stmt = $conn->prepare('SELECT id, name FROM student WHERE id_no = ? ORDER BY id DESC LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $dni);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($student) {
            $debt = grade_debt_blocks_grades($conn, (int)$student['id']);
            if (!empty($debt['blocked'])) {
                echo json_encode([
                    'status' => 'error',
                    'reason' => 'debt',
                    'message' => 'No es posible mostrar la información de notas porque existen 2 o más deudas pendientes.',
                    'debt_count' => (int)$debt['count'],
                    'total_pendiente_ultimas' => number_format((float)$debt['total'], 2, '.', ''),
                    'dni' => $dni,
                    'alumno' => (string)($student['name'] ?? '')
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }
}

require __DIR__ . '/my_grades_legacy.php';
