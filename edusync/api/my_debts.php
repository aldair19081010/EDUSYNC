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
require_once __DIR__ . '/grade_debt_guard.php';

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


// Orden de presentación exclusivo de la API de Deudas del estudiante.
// El mes del período facturado prevalece sobre fechas de pago o vencimiento.
function my_debts_month_from_text($raw) {
    $text = strtr(strtolower(trim((string)$raw)), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u'
    ]);
    if ($text === '') return null;

    // Períodos numerados: 2026-03, 2026/03, 03/2026 o 03-2026.
    if (preg_match('/(?:^|[^0-9])(?:19|20)\d{2}[-\/](0?[1-9]|1[0-2])(?:$|[^0-9])/', $text, $matches)) {
        return (int)$matches[1];
    }
    if (preg_match('/(?:^|[^0-9])(0?[1-9]|1[0-2])[-\/](?:19|20)\d{2}(?:$|[^0-9])/', $text, $matches)) {
        return (int)$matches[1];
    }

    $months = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'setiembre' => 9, 'septiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12
    ];
    foreach ($months as $name => $number) {
        if (preg_match('/(?:^|[^a-z])' . $name . '(?:$|[^a-z])/', $text)) {
            return $number;
        }
    }
    return null;
}

function my_debts_year_from_text($raw) {
    return preg_match('/(?:^|[^0-9])((?:19|20)\d{2})(?:$|[^0-9])/', (string)$raw, $matches)
        ? (int)$matches[1]
        : null;
}

function my_debts_valid_due_date(array $debt) {
    $due = trim((string)($debt['due_date'] ?? ''));
    if (preg_match('/^((?:19|20)\d{2})-(\d{2})-(\d{2})/', $due, $parts)
        && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        return substr($due, 0, 10);
    }
    return null;
}

function my_debts_chronological_key(array $debt) {
    $period = (string)($debt['billing_period'] ?? '');
    $month = my_debts_month_from_text($period);
    if ($month === null) {
        $month = my_debts_month_from_text($debt['concepto'] ?? '');
    }

    if ($month !== null) {
        $year = my_debts_year_from_text($period)
            ?? my_debts_year_from_text($debt['anio_academico'] ?? '')
            ?? my_debts_year_from_text($debt['due_date'] ?? '')
            ?? my_debts_year_from_text($debt['issue_date'] ?? '');
        if ($year !== null) {
            return sprintf('%04d-%02d-01', $year, $month);
        }
    }

    // Conceptos sin mes: fecha real de vencimiento. Sin período ni fecha,
    // se muestran al final en vez de inventar una fecha.
    return my_debts_valid_due_date($debt) ?? '9999-12-31';
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

    // Orden final enviado al estudiante: período/mes cronológico, no estado.
    // No cambia importes, estados, filtros financieros ni otras API.
    usort($pendingDebts, static function ($a, $b) {
        $order = strcmp(my_debts_chronological_key($a), my_debts_chronological_key($b));
        if ($order !== 0) return $order;

        $dueA = my_debts_valid_due_date($a) ?? '9999-12-31';
        $dueB = my_debts_valid_due_date($b) ?? '9999-12-31';
        $byDue = strcmp($dueA, $dueB);
        if ($byDue !== 0) return $byDue;

        $byConcept = strnatcasecmp((string)($a['concepto'] ?? ''), (string)($b['concepto'] ?? ''));
        if ($byConcept !== 0) return $byConcept;
        return ((int)($a['ef_id'] ?? 0)) <=> ((int)($b['ef_id'] ?? 0));
    });

    $summary = debt_engine_summary($pendingDebts);
    $yearSummary = debt_engine_year_summary($pendingDebts);
    $gradeAccess = grade_debt_blocks_grades($conn, $studentId);

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
        'block_grades' => (bool)$gradeAccess['blocked'],
        'grades_block_enabled' => (bool)$gradeAccess['policy_enabled'],
        'grades_block_threshold' => (int)$gradeAccess['minimum_concepts'],
        'grades_block_scope' => (string)$gradeAccess['scope'],
        'grades_block_count' => (int)$gradeAccess['count'],
        'grades_block_total' => (float)$gradeAccess['total'],
        'grades_block_message' => (string)$gradeAccess['message'],
        'grades_access_reason' => (string)($gradeAccess['reason'] ?? 'allowed'),
        'grades_grace_days' => (int)($gradeAccess['grace_days'] ?? 0),
        'grades_grace_count' => (int)($gradeAccess['grace_count'] ?? 0),
        'grades_temporary_access_until' => $gradeAccess['temporary_access_until'] ?? null
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
