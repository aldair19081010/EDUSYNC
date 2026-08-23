<?php
// Iniciar buffer para controlar la salida y evitar basura previa
if (!headers_sent()) { ob_start(); }
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';

// Configurar cabeceras para descargar archivo Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="Matriz_Asistencia.xls"');
header('Cache-Control: max-age=0');

// Obtener parámetros de filtro
$academic_year_id = $_GET['academic_year_id'] ?? 0;
$student_id = $_GET['student_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$nivel = $_GET['nivel'] ?? '';
$grado = $_GET['grado'] ?? '';
$seccion = $_GET['seccion'] ?? '';
$tipo = $_GET['tipo'] ?? '';
$estado = $_GET['estado'] ?? '';
$school_id = intval($_SESSION['login_school_id'] ?? 0);

// Construcción de la consulta SQL para obtener estudiantes
$student_where = "WHERE 1=1";
if (!empty($student_id)) $student_where .= " AND id = '$student_id'";
if (!empty($nivel)) $student_where .= " AND nivel = '$nivel'";
if (!empty($grado)) $student_where .= " AND grado = '$grado'";
if (!empty($seccion)) $student_where .= " AND seccion = '$seccion'";
$student_where .= " AND status = 'Activo'";
if ($school_id) $student_where .= " AND school_id = $school_id";

// Obtener la lista de estudiantes
$students_query = "SELECT id, id_no, name FROM student $student_where ORDER BY name ASC";
$students_result = $conn->query($students_query);

// Generar array con los días en el rango de fechas
$days_array = array();
$current_date = new DateTime($date_from);
$end_date = new DateTime($date_to);
$end_date->modify('+1 day'); // Para incluir el día final

while ($current_date < $end_date) {
    $days_array[] = $current_date->format('Y-m-d');
    $current_date->modify('+1 day');
}

// Obtener todos los registros de asistencia para el rango de fechas y filtros
$attendance_where = "AND s.status = 'Activo'";
if ($school_id) $attendance_where .= " AND s.school_id = $school_id";
if (!empty($date_from) && !empty($date_to)) $attendance_where .= " AND a.fecha BETWEEN '$date_from' AND '$date_to'";
if (!empty($student_id)) $attendance_where .= " AND a.student_id = '$student_id'";
if (!empty($tipo)) $attendance_where .= " AND a.tipo = '$tipo'";
if (!empty($estado)) $attendance_where .= " AND a.estado = '$estado'";
$attendance_query = "SELECT a.student_id, a.fecha, a.hora, a.tipo, a.estado 
                     FROM asistencia a
                     LEFT JOIN student s ON s.id = a.student_id
                     WHERE 1=1 $attendance_where
                     ORDER BY a.student_id, a.fecha, a.hora";
$attendance_result = $conn->query($attendance_query);

// Crear matriz de asistencias (detallada por día)
$attendance_data = array();
if ($attendance_result && $attendance_result->num_rows > 0) {
    while ($row = $attendance_result->fetch_assoc()) {
        // Solo guardamos los registros de tipo "Entrada" o todos si no se especificó tipo
        if (empty($tipo) || $tipo == "Entrada") {
            $attendance_data[$row['student_id']][$row['fecha']] = [
                'hora' => date('H:i', strtotime($row['hora'])),
                'estado' => $row['estado']
            ];
        }
    }
}

// Totales por estudiante (tardanzas e inasistencias) con mismos filtros (ignorando filtro de estado)
$counts_where = "WHERE 1=1 AND s.status = 'Activo'";
if ($school_id) $counts_where .= " AND s.school_id = $school_id";
if (!empty($date_from) && !empty($date_to)) $counts_where .= " AND a.fecha BETWEEN '$date_from' AND '$date_to'";
if (!empty($student_id)) $counts_where .= " AND a.student_id = '$student_id'";
if (!empty($tipo)) $counts_where .= " AND a.tipo = '$tipo'";
if (!empty($grado)) $counts_where .= " AND s.grado = '$grado'";
if (!empty($seccion)) $counts_where .= " AND s.seccion = '$seccion'";

$counts_query = "SELECT a.student_id,
    SUM(CASE WHEN a.estado = 'Tarde' THEN 1 ELSE 0 END) AS tardanzas,
    SUM(CASE WHEN a.estado = 'Ausente' THEN 1 ELSE 0 END) AS ausencias
FROM asistencia a
LEFT JOIN student s ON s.id = a.student_id
$counts_where
GROUP BY a.student_id";
$counts_res = $conn->query($counts_query);
$counts = [];
if ($counts_res && $counts_res->num_rows > 0) {
    while ($r = $counts_res->fetch_assoc()) {
        $sid = $r['student_id'];
        $counts[$sid] = [
            'tardanzas' => (int)$r['tardanzas'],
            'ausencias' => (int)$r['ausencias']
        ];
    }
}

// Iniciar la salida del documento Excel
echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Matriz de Asistencia</title>
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
            font-size: 11px;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .header {
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .subheader {
            font-size: 12pt;
            margin-bottom: 20px;
        }
        .student-name {
            text-align: left;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">Matriz de Asistencia</div>
    <div class="subheader">Período: ' . date('d/m/Y', strtotime($date_from)) . ' - ' . date('d/m/Y', strtotime($date_to)) . '</div>
    
    <table border="1">
        <thead>
            <tr><th rowspan="2">Alumnos</th>';

// Encabezado con los días del mes (números)
foreach ($days_array as $day) {
    echo '<th>' . date('d', strtotime($day)) . '</th>';
}
// Añadir las columnas de totales al final de la primera fila del encabezado
echo '<th rowspan="2">Tardanzas</th><th rowspan="2">Inasistencias</th></tr>';

// Segunda fila del encabezado: iniciales del día de la semana
echo '<tr>';
foreach ($days_array as $day) {
    $dayOfWeek = date('D', strtotime($day));
    switch ($dayOfWeek) {
        case 'Mon': $dayName = 'L'; break;
        case 'Tue': $dayName = 'M'; break;
        case 'Wed': $dayName = 'X'; break;
        case 'Thu': $dayName = 'J'; break;
        case 'Fri': $dayName = 'V'; break;
        case 'Sat': $dayName = 'S'; break;
        case 'Sun': $dayName = 'D'; break;
        default: $dayName = '';
    }
    echo '<th>' . $dayName . '</th>';
}

echo '</tr></thead>
        <tbody>';

// Filas de estudiantes
if ($students_result->num_rows > 0) {
    while ($student = $students_result->fetch_assoc()) {
        $sid = $student['id'];
        echo '<tr><td class="student-name">' . $student['name'] . '</td>';
        // Para cada día en el rango
        foreach ($days_array as $day) {
            if (isset($attendance_data[$sid][$day])) {
                $cell = $attendance_data[$sid][$day];
                $hora = $cell['hora'];
                $nota = '';
                if (isset($cell['estado']) && $cell['estado'] === 'Ausente Justificada') { $nota = ' (J)'; }
                echo '<td>' . $hora . $nota . '</td>';
            } else {
                echo '<td>-</td>';
            }
        }
        $tard = isset($counts[$sid]) ? (int)$counts[$sid]['tardanzas'] : 0;
        $aus = isset($counts[$sid]) ? (int)$counts[$sid]['ausencias'] : 0;
        echo '<td>' . $tard . '</td><td>' . $aus . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="' . (count($days_array) + 3) . '">No se encontraron estudiantes</td></tr>';
}

echo '</tbody>
    </table>
</body>
</html>';

// Enviar todo el contenido y terminar limpio para que el navegador no quede esperando
if (function_exists('ob_get_length')) {
    @ob_end_flush();
}
exit;
?>
