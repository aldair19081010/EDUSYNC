<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ob_start();
include_once __DIR__ . '/../session_config.php';
include __DIR__ . '/../db_connect.php';
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

function attendance_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function attendance_column_exists(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $q = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return $q && $q->num_rows > 0;
}

require_once __DIR__ . '/../includes/attendance_history_helper.php';

try {
    $dni = trim($_GET['dni'] ?? ($_POST['dni'] ?? ''));
    $requestedSchoolId = (int)($_GET['school_id'] ?? ($_POST['school_id'] ?? 0));
    $student = null;
    $authMode = 'legacy_dni';

    if (!empty($_SESSION['student_logged_in']) && !empty($_SESSION['student_id'])) {
        $studentId = (int)$_SESSION['student_id'];
        $stmt = $conn->prepare('SELECT id,id_no,name,school_id FROM student WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $authMode = 'session';
    } else {
        if ($dni === '') attendance_reply(['status' => 'error', 'message' => 'DNI no recibido'], 400);
        if ($requestedSchoolId > 0) {
            $stmt = $conn->prepare('SELECT id,id_no,name,school_id FROM student WHERE id_no=? AND school_id=? ORDER BY id DESC LIMIT 1');
            $stmt->bind_param('si', $dni, $requestedSchoolId);
        } else {
            $stmt = $conn->prepare('SELECT id,id_no,name,school_id FROM student WHERE id_no=? ORDER BY id DESC LIMIT 1');
            $stmt->bind_param('s', $dni);
        }
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$student) attendance_reply(['status' => 'error', 'message' => 'Estudiante no encontrado'], 404);

    $studentId = (int)$student['id'];
    $schoolId = (int)$student['school_id'];

    $years = [];
    $yearsById = [];
    $stmt = $conn->prepare('SELECT id,year,description,is_active,start_date,end_date FROM academic_year WHERE school_id=? ORDER BY year DESC,id DESC');
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'];
        $years[] = $row;
        $yearsById[(int)$row['id']] = $row;
    }
    $stmt->close();

    $activeYear = null;
    foreach ($years as $yearRow) {
        if ((int)$yearRow['is_active'] === 1) {
            $activeYear = (string)$yearRow['year'];
            break;
        }
    }

    $hasSchoolId = attendance_column_exists($conn, 'asistencia', 'school_id');
    $hasAcademicYearId = attendance_column_exists($conn, 'asistencia', 'academic_year_id');
    $hasCancelled = attendance_column_exists($conn, 'asistencia', 'is_cancelled');

    $yearSelect = $hasAcademicYearId ? 'a.academic_year_id' : 'NULL AS academic_year_id';
    $where = ['a.student_id=?'];
    if ($hasSchoolId) $where[] = 'a.school_id=?';
    if ($hasCancelled) $where[] = 'COALESCE(a.is_cancelled,0)=0';

    $sql = "SELECT a.fecha,a.tipo,a.hora,a.estado,$yearSelect FROM asistencia a WHERE " . implode(' AND ', $where) . " ORDER BY a.fecha DESC,a.hora DESC";
    $stmt = $conn->prepare($sql);
    if ($hasSchoolId) $stmt->bind_param('ii', $studentId, $schoolId);
    else $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();

    $raw = [];
    while ($row = $result->fetch_assoc()) {
        $date = substr((string)$row['fecha'], 0, 10);
        $yearInfo = null;
        $yearId = (int)($row['academic_year_id'] ?? 0);
        if ($yearId > 0 && isset($yearsById[$yearId])) {
            $yearInfo = $yearsById[$yearId];
        } else {
            foreach ($years as $candidate) {
                $start = (string)($candidate['start_date'] ?? '');
                $end = (string)($candidate['end_date'] ?? '');
                if ($start !== '' && $end !== '' && $date >= $start && $date <= $end) {
                    $yearInfo = $candidate;
                    break;
                }
            }
        }

        $raw[] = [
            'fecha' => $date,
            'tipo' => (string)($row['tipo'] ?? ''),
            'hora' => attendance_clean_time($row['hora'] ?? null),
            'estado' => attendance_normalize_status($row['estado'] ?? ''),
            'anio_academico' => $yearInfo ? (string)$yearInfo['year'] : '',
            'anio_descripcion' => $yearInfo ? (string)($yearInfo['description'] ?? '') : ''
        ];
    }
    $stmt->close();

    $days = attendance_group_days($raw);

    $summary = [];
    foreach ($days as $day) {
        $year = $day['anio_academico'] !== '' ? $day['anio_academico'] : 'Sin año';
        if (!isset($summary[$year])) {
            $summary[$year] = [
                'año' => $year,
                'total_registros' => 0,
                'total_dias' => 0,
                'total_marcaciones' => 0,
                'presentes' => 0,
                'tardes' => 0,
                'ausentes' => 0,
                'justificadas' => 0,
                'permisos' => 0,
                'otros' => 0,
                'porcentaje_asistencia' => 0
            ];
        }
        $summary[$year]['total_registros']++;
        $summary[$year]['total_dias']++;
        $summary[$year]['total_marcaciones'] += (int)$day['marcaciones'];

        switch ($day['estado']) {
            case 'Presente': $summary[$year]['presentes']++; break;
            case 'Tarde': $summary[$year]['tardes']++; break;
            case 'Ausente': $summary[$year]['ausentes']++; break;
            case 'Ausente Justificada': $summary[$year]['justificadas']++; break;
            case 'Permiso': $summary[$year]['permisos']++; break;
            default: $summary[$year]['otros']++;
        }
    }

    foreach ($summary as &$yearSummary) {
        $total = (int)$yearSummary['total_dias'];
        $attended = (int)$yearSummary['presentes'] + (int)$yearSummary['tardes'];
        $yearSummary['porcentaje_asistencia'] = $total > 0 ? round(($attended / $total) * 100, 1) : 0;
    }
    unset($yearSummary);

    uasort($summary, static function ($a, $b) {
        return strnatcmp((string)$b['año'], (string)$a['año']);
    });

    attendance_reply([
        'status' => 'ok',
        'data' => $raw,
        'days' => $days,
        'student' => [
            'id' => $studentId,
            'dni' => (string)($student['id_no'] ?? $dni),
            'name' => (string)$student['name'],
            'school_id' => $schoolId
        ],
        'auth_mode' => $authMode,
        'anio_academico_actual' => $activeYear,
        'total_registros' => count($raw),
        'total_dias' => count($days),
        'resumen_por_año' => array_values($summary)
    ]);
} catch (Throwable $e) {
    error_log('[my_attendance API] ' . $e->getMessage() . ' line ' . $e->getLine());
    attendance_reply([
        'status' => 'error',
        'message' => 'No se pudo cargar el historial de asistencias.',
        'error_code' => 500
    ], 500);
}
