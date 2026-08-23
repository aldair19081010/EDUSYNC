<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
include '../db_connect.php';
header('Content-Type: application/json');

// Recibe el DNI por GET o POST
$dni = $_GET['dni'] ?? ($_POST['dni'] ?? '');
$data = [];

if (!$dni) {
    echo json_encode(['status' => 'error', 'message' => 'DNI no recibido']);
    exit;
}

// Busca el ID del estudiante por su DNI
$stu = $conn->query("SELECT id, school_id FROM student WHERE id_no = '$dni'")->fetch_assoc();
if (!$stu) {
    echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']);
    exit;
}
$student_id = $stu['id'] ?? 0;
$school_id = $stu['school_id'] ?? 1;

if ($student_id) {
    $q = $conn->query("
        SELECT DISTINCT
            a.fecha,
            a.tipo,
            a.hora,
            a.estado,
            ay.year AS anio_academico,
            ay.description AS anio_descripcion
        FROM asistencia a
        INNER JOIN academic_year ay ON ay.school_id = $school_id AND a.fecha BETWEEN ay.start_date AND ay.end_date
        WHERE a.student_id = $student_id
        ORDER BY ay.year DESC, a.fecha DESC, a.hora DESC
    ");
    while ($row = $q->fetch_assoc()) {
        $data[] = [
            'fecha' => $row['fecha'],
            'tipo' => $row['tipo'],      // Entrada o Salida
            'hora' => $row['hora'],
            'estado' => $row['estado'],   // Presente, Tarde, etc.
            'anio_academico' => $row['anio_academico'],
            'anio_descripcion' => $row['anio_descripcion']
        ];
    }
    
    // Obtener resumen por años académicos
    $years_summary = [];
    foreach ($data as $attendance) {
        $year = $attendance['anio_academico'];
        if (!isset($years_summary[$year])) {
            $years_summary[$year] = [
                'año' => $year,
                'total_registros' => 0,
                'presentes' => 0,
                'tardes' => 0,
                'otros' => 0
            ];
        }
        $years_summary[$year]['total_registros']++;
        
        switch(strtolower($attendance['estado'])) {
            case 'presente':
                $years_summary[$year]['presentes']++;
                break;
            case 'tarde':
                $years_summary[$year]['tardes']++;
                break;
            default:
                $years_summary[$year]['otros']++;
        }
    }
    
    // Obtener información del año académico actual
    $current_year_result = $conn->query("SELECT year FROM academic_year WHERE is_active = 1 LIMIT 1");
    $current_year_info = $current_year_result->fetch_assoc();
    $current_year = $current_year_info ? $current_year_info['year'] : 'N/A';
}

echo json_encode([
    'status' => 'ok', 
    'data' => $data,
    'anio_academico_actual' => $current_year,
    'total_registros' => count($data),
    'resumen_por_año' => array_values($years_summary)
]);
?>