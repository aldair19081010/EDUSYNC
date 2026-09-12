<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ob_start();
include_once __DIR__ . '/../session_config.php';
include __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/debt_engine.php';

if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

function my_debts_out(array $data, $statusCode = 200) {
    http_response_code((int)$statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function my_debts_log($message, $details = null) {
    error_log('[my_debts API] ' . $message . ($details !== null ? ' - ' . json_encode($details) : ''));
}

try {
    $dni = trim($_GET['dni'] ?? ($_POST['dni'] ?? ''));
    $requestedSchoolId = (int)($_GET['school_id'] ?? ($_POST['school_id'] ?? 0));

    $student = null;
    $authMode = 'legacy_dni';

    if (!empty($_SESSION['student_logged_in']) && !empty($_SESSION['student_id'])) {
        $studentId = (int)$_SESSION['student_id'];
        $stmt = $conn->prepare("SELECT id, id_no, name, school_id FROM student WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $authMode = 'session';
    } else {
        if ($dni === '') {
            my_debts_out(['status' => 'error', 'message' => 'DNI no recibido'], 400);
        }

        if ($requestedSchoolId > 0) {
            $stmt = $conn->prepare("
                SELECT id, id_no, name, school_id
                FROM student
                WHERE id_no = ? AND school_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->bind_param('si', $dni, $requestedSchoolId);
        } else {
            $stmt = $conn->prepare("
                SELECT id, id_no, name, school_id
                FROM student
                WHERE id_no = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->bind_param('s', $dni);
        }

        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$student) {
        my_debts_out(['status' => 'error', 'message' => 'Estudiante no encontrado'], 404);
    }

    $studentId = (int)$student['id'];
    $schoolId = (int)($student['school_id'] ?? 0);

    $allDebts = debt_engine_get_student_debts($conn, $studentId, $schoolId);
    $pendingDebts = debt_engine_pending_debts($allDebts);
    $summary = debt_engine_summary($pendingDebts);
    $yearSummary = debt_engine_year_summary($pendingDebts);

    $currentYear = 'N/A';
    if (debt_engine_table_exists($conn, 'academic_year')) {
        $hasSchool = debt_engine_column_exists($conn, 'academic_year', 'school_id');

        if ($hasSchool && $schoolId > 0) {
            $stmt = $conn->prepare("
                SELECT year
                FROM academic_year
                WHERE is_active = 1 AND school_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->bind_param('i', $schoolId);
            $stmt->execute();
            $active = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $activeResult = $conn->query("
                SELECT year
                FROM academic_year
                WHERE is_active = 1
                ORDER BY id DESC
                LIMIT 1
            ");
            $active = $activeResult ? $activeResult->fetch_assoc() : null;
        }

        if (!empty($active['year'])) $currentYear = $active['year'];
    }

    my_debts_out([
        'status' => 'ok',
        'data' => $pendingDebts,
        'student' => [
            'id' => $studentId,
            'dni' => (string)($student['id_no'] ?? $dni),
            'name' => (string)$student['name'],
            'school_id' => $schoolId
        ],
        'auth_mode' => $authMode,
        'anio_academico_actual' => $currentYear,
        'summary' => $summary,
        'resumen_por_año' => $yearSummary,
        'block_grades' => $summary['count_concepts'] >= 2,
        'grades_block_threshold' => 2
    ]);
} catch (Throwable $e) {
    my_debts_log('Error inesperado', [
        'message' => $e->getMessage(),
        'line' => $e->getLine()
    ]);

    my_debts_out([
        'status' => 'error',
        'message' => 'Ocurrió un error inesperado. Por favor, intente nuevamente más tarde.',
        'error_code' => 500
    ], 500);
}
