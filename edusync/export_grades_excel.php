<?php
include 'db_connect.php';

// Alinear configuraciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n de sesiÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n con index.php
if (session_status() == PHP_SESSION_NONE) {
    $session_save_path = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($session_save_path)) {
        @mkdir($session_save_path, 0755, true);
    }
    ini_set('session.save_path', $session_save_path);
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// FunciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n para formatear calificaciones segÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºn el formato seleccionado
function format_grade_display($grade, $export_format = 'numeric') {
    if (empty($grade) || $grade === '' || $grade === null) {
        return '-';
    }

    // Si el formato solicitado es letras
    if ($export_format === 'letters') {
        // Si ya es una letra vÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡lida, mostrarla
        if (in_array(strtoupper(trim($grade)), ['C', 'B', 'A', 'AD'])) {
            return strtoupper(trim($grade));
        }

        // Si es un nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºmero, convertirlo a letra usando el valor redondeado
        if (is_numeric($grade)) {
            $num = round(floatval($grade));
            if ($num >= 18) return 'AD';
            if ($num >= 14) return 'A';
            if ($num >= 11) return 'B';
            return 'C';
        }

        return htmlspecialchars($grade);
    }

    // Si el formato solicitado es numÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©rico
    if ($export_format === 'numeric') {
        // Si es una letra vÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡lida, convertirla a nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºmero (valores para presentaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n ya redondeados)
        if (in_array(strtoupper(trim($grade)), ['C', 'B', 'A', 'AD'])) {
            $letter = strtoupper(trim($grade));
            switch ($letter) {
                case 'C':  return '5';   // C = 0-10, promedio 5
                case 'B':  return '12';  // B = 11-13, promedio 12
                case 'A':  return '16';  // A = 14-17, promedio 15.5 -> mostrar 16 redondeado
                case 'AD': return '19';  // AD = 18-20, promedio 19
            }
        }

        // Si es un nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºmero, formatearlo redondeado (sin decimales)
        if (is_numeric($grade)) {
            return strval(intval(round(floatval($grade))));
        }

        return htmlspecialchars($grade);
    }

    // Formato por defecto (como estaba antes)
    return htmlspecialchars($grade);
}

// FunciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n para convertir letras a nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºmeros para cÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡lculos
function letter_to_numeric_for_calc($grade) {
    if (empty($grade) || $grade === '' || $grade === null) {
        return null;
    }
    
    $grade_upper = strtoupper(trim($grade));
    switch ($grade_upper) {
        case 'C':  return 5;   // C = 0-10, promedio 5
        case 'B':  return 12;  // B = 11-13, promedio 12
        case 'A':  return 15.5; // A = 14-17, promedio 15.5
        case 'AD': return 19;  // AD = 18-20, promedio 19
        default:
            return is_numeric($grade) ? floatval($grade) : null;
    }
}

// Verificar autenticaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n
$school_id = $_SESSION['login_school_id'] ?? null;
$login_type = $_SESSION['login_type'] ?? null;
$is_admin = ($login_type == 1);
$is_teacher = ($login_type == 2);
$teacher_id = $_SESSION['login_teacher_id'] ?? null;
require_once __DIR__ . '/includes/grades_report_access.php';
$reportDirector = grades_report_is_director($conn);
$is_admin = $is_admin || $reportDirector;
$is_teacher = $is_teacher && !$reportDirector;

if (!$school_id || !$login_type) {
    die("Acceso no autorizado o configuraciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n de sesiÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n incompleta.");
}

// FunciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n de normalizaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n para el nivel
function normalize_level_for_key($level_name) {
    if (empty($level_name)) return '';
    return strtolower(str_replace(' ', '', trim($level_name)));
}

// Obtener parÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡metros de filtro
$selected_course = $_GET['course_id'] ?? '';
$selected_level = $_GET['level'] ?? '';
$selected_grado = $_GET['grado'] ?? '';
$selected_seccion = $_GET['seccion'] ?? '';
$selected_bimestre = $_GET['bimestre'] ?? '';
$selected_evaluation = $_GET['evaluation_id'] ?? '';
$selected_student = $_GET['student_id'] ?? '';
$selected_academic_year = $_GET['academic_year_id'] ?? '';
$download_token = $_GET['download_token'] ?? '';

// Obtener nombre del curso para incluir en el nombre del archivo
$course_name = '';
if (!empty($selected_course)) {
    $course_query = $conn->query("SELECT name FROM academic_courses WHERE id = " . intval($selected_course) . " LIMIT 1");
    if ($course_query && $course_query->num_rows > 0) {
        $course_row = $course_query->fetch_assoc();
        $course_name = $course_row['name'];
    }
}
$export_format = $_GET['export_format'] ?? 'numeric'; // Nuevo parÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡metro para el formato

// Validar que al menos tengamos el aÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â±o acadÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©mico
if (empty($selected_academic_year)) {
    die("Por favor, seleccione un AÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â±o AcadÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©mico para generar el reporte.");
}

// ValidaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n: requerir nivel, grado, secciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n y bimestre para exportar
if (empty($selected_level) || empty($selected_grado) || empty($selected_seccion) || empty($selected_bimestre)) {
    $faltantes = [];
    if (empty($selected_level)) $faltantes[] = 'Nivel';
    if (empty($selected_grado)) $faltantes[] = 'Grado';
    if (empty($selected_seccion)) $faltantes[] = 'SecciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n';
    if (empty($selected_bimestre)) $faltantes[] = 'Bimestre';
    die('Para exportar el reporte debe seleccionar: ' . implode(', ', $faltantes) . '.');
}

// Obtener informaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n del curso si se ha seleccionado uno
$course_name = "";
if (!empty($selected_course)) {
    $sql_course = "SELECT ac.name FROM academic_courses ac WHERE ac.id = ?";
    $params_course = [$selected_course];
    
    // Note: Courses are now global, no need for academic year filter here
    
    $stmt = $conn->prepare($sql_course);
    $stmt->bind_param(str_repeat('i', count($params_course)), ...$params_course);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $course_name = $row['name'];
    }
    $stmt->close();
}

// Obtain competencies through the evaluation links used by this export.
// evaluations -> evaluation_competencias -> general_course_competencies.
// This prevents a same-named competency belonging to another teacher/course
// assignment from being exported without its evaluations.

$competencias = [];
if (!empty($selected_course)) {
    $sql_comp = "SELECT DISTINCT gcc.id, gcc.name, gcc.percentage 
                 FROM general_course_competencies gcc
                 INNER JOIN evaluation_competencias ec ON ec.competencia_id = gcc.id
                 INNER JOIN evaluations e ON e.id = ec.evaluation_id
                 INNER JOIN teacher_courses tc ON tc.id = e.teacher_course_id
                 WHERE tc.course_id = ?
                 AND tc.school_id = ?
                 AND tc.academic_year_id = ?
                 AND e.bimestre = ?
                 AND gcc.is_active = 1";
    
    $params_comp = [$selected_course, $school_id, $selected_academic_year, $selected_bimestre];
    $types_comp = "iiis";
        $level_key = normalize_level_for_key($selected_level);
    if (!empty($selected_level)) {
        $sql_comp .= " AND LOWER(REPLACE(TRIM(tc.level), ' ', '')) = ?";
        $params_comp[] = $level_key;
        $types_comp .= "s";
    }
    
    if (!empty($selected_grado)) {
        $sql_comp .= " AND LOWER(TRIM(tc.grado)) = LOWER(TRIM(?))";
        $params_comp[] = $selected_grado;
        $types_comp .= "s";
    }
    
    if (!empty($selected_seccion)) {
        $sql_comp .= " AND LOWER(TRIM(tc.seccion)) = LOWER(TRIM(?))";
        $params_comp[] = $selected_seccion;
        $types_comp .= "s";
    }
    
    if (!empty($selected_evaluation)) {
        $sql_comp .= " AND e.id = ?";
        $params_comp[] = $selected_evaluation;
        $types_comp .= "i";
    }
    
    $sql_comp .= " ORDER BY gcc.id";
    
    $stmt_comp = $conn->prepare($sql_comp);
    $stmt_comp->bind_param($types_comp, ...$params_comp);
    $stmt_comp->execute();
    $res_comp = $stmt_comp->get_result();
    
    // Respaldo adicional ante filas repetidas en evaluation_competencias.
    $competencias_temp = [];
    while ($row = $res_comp->fetch_assoc()) {
        $comp_id = $row['id'];
        if (!isset($competencias_temp[$comp_id])) {
            $competencias_temp[$comp_id] = $row;
        }
    }
    
    // Convertir a array indexado manteniendo el orden
    $competencias = array_values($competencias_temp);
    $stmt_comp->close();
}

// Obtener estudiantes que tienen evaluaciones en el aÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â±o acadÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©mico/nivel/grado/secciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n seleccionado
$students = [];
if (!empty($selected_level) && !empty($selected_grado) && !empty($selected_seccion) && !empty($selected_academic_year)) {
    $level_key = normalize_level_for_key($selected_level);
    // Solo consultar estudiantes ACTIVOS con evaluaciones en el aÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â±o acadÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©mico especÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­fico
    $sql = "SELECT DISTINCT s.id, s.name, s.id_no 
            FROM student s
            INNER JOIN evaluation_grades eg ON s.id = eg.student_id
            INNER JOIN evaluations e ON eg.evaluation_id = e.id
            INNER JOIN teacher_courses tc ON e.teacher_course_id = tc.id
            WHERE LOWER(REPLACE(TRIM(s.nivel), ' ', '')) = ? 
            AND s.school_id = ? 
            AND tc.academic_year_id = ?
            AND tc.grado = ?
            AND tc.seccion = ?
            AND s.status = 1
            ORDER BY s.name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sisss", $level_key, $school_id, $selected_academic_year, $selected_grado, $selected_seccion);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
}

// Obtener evaluaciones para el curso y bimestre seleccionados
$evaluations = [];
if (!empty($selected_course) && !empty($selected_bimestre) && !empty($selected_academic_year)) {
    $sql_eval = "SELECT DISTINCT e.id, e.title, ec.competencia_id 
                FROM evaluations e 
                INNER JOIN teacher_courses tc ON e.teacher_course_id = tc.id 
                INNER JOIN evaluation_competencias ec ON ec.evaluation_id = e.id
                WHERE tc.course_id = ? 
                AND tc.school_id = ?
                AND e.bimestre = ?
                AND tc.academic_year_id = ?";
    
    $params_eval = [$selected_course, $school_id, $selected_bimestre, $selected_academic_year];
    $types_eval = "iisi";
    
    if (!empty($selected_level)) {
        $sql_eval .= " AND LOWER(REPLACE(TRIM(tc.level), ' ', '')) = ?";
        $params_eval[] = normalize_level_for_key($selected_level);
        $types_eval .= "s";
    }
    
    if (!empty($selected_grado)) {
        $sql_eval .= " AND LOWER(TRIM(tc.grado)) = LOWER(TRIM(?))";
        $params_eval[] = $selected_grado;
        $types_eval .= "s";
    }
    
    if (!empty($selected_seccion)) {
        $sql_eval .= " AND LOWER(TRIM(tc.seccion)) = LOWER(TRIM(?))";
        $params_eval[] = $selected_seccion;
        $types_eval .= "s";
    }
    
    if (!empty($selected_evaluation)) {
        $sql_eval .= " AND e.id = ?";
        $params_eval[] = $selected_evaluation;
        $types_eval .= "i";
    }
    
    // Ordenar por competencia y luego por tÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­tulo de evaluaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n para mantener consistencia
    $sql_eval .= " ORDER BY ec.competencia_id, e.id, e.title";
    
    $stmt_eval = $conn->prepare($sql_eval);
    $stmt_eval->bind_param($types_eval, ...$params_eval);
    $stmt_eval->execute();
    $res_eval = $stmt_eval->get_result();
    
    // Agrupar evaluaciones por competencia y evitar duplicados
    $seen_evaluations = [];
    while ($row = $res_eval->fetch_assoc()) {
        $eval_key = $row['id'] . '_' . $row['competencia_id'];
        if (!isset($seen_evaluations[$eval_key])) {
            $evaluations[$row['competencia_id']][] = $row;
            $seen_evaluations[$eval_key] = true;
        }
    }
    $stmt_eval->close();
}

// Obtener notas de los estudiantes
$grades = [];
if (!empty($evaluations)) {
    $eval_ids = [];
    foreach ($evaluations as $comp_evals) {
        foreach ($comp_evals as $eval) {
            $eval_ids[] = $eval['id'];
        }
    }
    
    if (!empty($eval_ids)) {
        $placeholders = str_repeat('?,', count($eval_ids) - 1) . '?';
        $sql_grades = "SELECT DISTINCT eg.evaluation_id, eg.student_id, eg.competencia_id, eg.grade 
                      FROM evaluation_grades eg
                      INNER JOIN evaluations e ON eg.evaluation_id = e.id
                      INNER JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                      INNER JOIN academic_courses ac ON tc.course_id = ac.id
                      WHERE eg.evaluation_id IN ($placeholders)
                      AND tc.school_id = ?";
        
        $params = $eval_ids;
        $params[] = $school_id;
        $types = str_repeat('i', count($eval_ids)) . 'i';
        
        if (!empty($selected_academic_year)) {
            $sql_grades .= " AND tc.academic_year_id = ?";
            $params[] = $selected_academic_year;
            $types .= 'i';
        }
        
        if (!empty($selected_level)) {
            $sql_grades .= " AND LOWER(REPLACE(TRIM(tc.level), ' ', '')) = ?";
            $params[] = normalize_level_for_key($selected_level);
            $types .= 's';
        }
        
        if (!empty($selected_grado)) {
            $sql_grades .= " AND LOWER(TRIM(tc.grado)) = LOWER(TRIM(?))";
            $params[] = $selected_grado;
            $types .= 's';
        }
        
        if (!empty($selected_seccion)) {
            $sql_grades .= " AND LOWER(TRIM(tc.seccion)) = LOWER(TRIM(?))";
            $params[] = $selected_seccion;
            $types .= 's';
        }
        
        if (!empty($selected_student)) {
            $sql_grades .= " AND eg.student_id = ?";
            $params[] = $selected_student;
            $types .= 'i';
        }
        
        $stmt_grades = $conn->prepare($sql_grades);
        $stmt_grades->bind_param($types, ...$params);
        $stmt_grades->execute();
        $res_grades = $stmt_grades->get_result();
        
        while ($row = $res_grades->fetch_assoc()) {
            $grades[$row['student_id']][$row['evaluation_id']] = $row['grade'];
        }
        $stmt_grades->close();
    }
}

// Calcular promedios por competencia
$promedios_competencias = [];
foreach ($students as $student) {
    $student_id = $student['id'];
    foreach ($competencias as $comp) {
        $comp_id = $comp['id'];
        $notas = [];
        if (isset($evaluations[$comp_id])) {
            foreach ($evaluations[$comp_id] as $eval) {
                if (isset($grades[$student_id][$eval['id']])) {
                    $grade = $grades[$student_id][$eval['id']];
                    $numeric_grade = letter_to_numeric_for_calc($grade);
                    if ($numeric_grade !== null) {
                        $notas[] = $numeric_grade;
                    }
                }
            }
        }
        
        if (!empty($notas)) {
            $promedio = array_sum($notas) / count($notas);
            $promedios_competencias[$student_id][$comp_id] = $promedio;
        } else {
            $promedios_competencias[$student_id][$comp_id] = null;
        }
    }
}

// Calcular promedio final
$promedios_finales = [];
foreach ($students as $student) {
    $student_id = $student['id'];
    $total_weighted = 0;
    $total_percentage = 0;
    
    foreach ($competencias as $comp) {
        $comp_id = $comp['id'];
        if (isset($promedios_competencias[$student_id][$comp_id]) && !is_null($promedios_competencias[$student_id][$comp_id])) {
            $total_weighted += $promedios_competencias[$student_id][$comp_id] * ($comp['percentage'] / 100);
            $total_percentage += $comp['percentage'];
        }
    }
    if ($total_percentage > 0) {
        // Sin ajuste - usar suma ponderada directa
        $promedios_finales[$student_id] = $total_weighted;
    } else {
        $promedios_finales[$student_id] = null;
    }
}

// Configurar cabeceras para descargar archivo Excel
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
$filename = 'Reporte_Notas_' . 
    (!empty($course_name) ? str_replace(' ', '_', $course_name) . '_' : '') . 
    'Nivel_' . $selected_level . '_' . 
    'Grado_' . $selected_grado . '_' . 
    'Seccion_' . $selected_seccion . '.xls';
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Iniciar la salida del documento Excel en formato HTML
echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reporte de Notas</title>
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid #000;
            padding: 4px;
            text-align: center;
            font-size: 11px;
            vertical-align: middle;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .competencia-header {
            background-color: #E6EFF9;
            font-weight: bold;
            text-align: center;
        }
        .promedio-col {
            background-color: #E8F1FF;
            font-weight: bold;
        }
        .student-name {
            text-align: left;
            font-weight: normal;
        }
        .promedio-final {
            background-color: #D9E8FF;
            font-weight: bold;
        }
        .eval-header {
            background-color: #F8F8F8;
        }
    </style>
</head>
<body>';

// Calcular el nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºmero total de columnas para competencias
$total_columnas = 0;
$eval_counts = [];
foreach ($competencias as $comp) {
    if (isset($evaluations[$comp['id']]) && count($evaluations[$comp['id']]) > 0) {
        $eval_counts[$comp['id']] = count($evaluations[$comp['id']]);
        $total_columnas += $eval_counts[$comp['id']] + 1; // +1 para el promedio
    } else {
        $eval_counts[$comp['id']] = 0;
        $total_columnas += 2; // 1 columna para "Sin evaluaciones" + 1 para el promedio
    }
}

// Antes de generar el HTML, vamos a asegurarnos de que tenemos datos
$has_evaluations = false;
$has_grades = false;

foreach ($competencias as $comp) {
    if (isset($evaluations[$comp['id']]) && count($evaluations[$comp['id']]) > 0) {
        $has_evaluations = true;
        break;
    }
}

foreach ($students as $student) {
    $student_id = $student['id'];
    foreach ($evaluations as $comp_evals) {
        foreach ($comp_evals as $eval) {
            if (isset($grades[$student_id][$eval['id']])) {
                $has_grades = true;
                break 2;
            }
        }
    }
}

// ModificaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n de la generaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n de la tabla para garantizar que se muestran todos los datos
echo '<table border="1">
    <tr>
        <th style="width:250px; text-align:left;" rowspan="2">APELLIDOS Y NOMBRES</th>';

// Encabezados de competencias con sus nombres reales
foreach ($competencias as $comp) {
    $comp_name = mb_strtoupper($comp['name']);
    $comp_percentage = $comp['percentage'];
    // Calcular el nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºmero de columnas para esta competencia
    $num_evaluaciones = isset($evaluations[$comp['id']]) ? count($evaluations[$comp['id']]) : 0;
    $cols = max(1, $num_evaluaciones) + 1; // Al menos 1 columna para evaluaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n + 1 para promedio
    
    echo '<th colspan="' . $cols . '" class="competencia-header">' . $comp_name . ' (' . $comp_percentage . '%)</th>';
}

echo '<th rowspan="2" class="promedio-final" style="background-color:#DAE3F3; width:60px;">PROMEDIO FINAL</th>';
echo '</tr>';

// Fila para los tÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­tulos de evaluaciones
echo '<tr>';
foreach ($competencias as $comp) {
    $comp_id = $comp['id'];
    $evals_comp = isset($evaluations[$comp_id]) ? $evaluations[$comp_id] : [];
    
    // Mostrar nombres de evaluaciones explÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­citamente
    if (count($evals_comp) > 0) {
        foreach ($evals_comp as $eval) {
            // TÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­tulo de evaluaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n con estilo destacado
            echo '<th class="eval-header" style="background-color:#F2F2F2; font-weight:bold; border:1px solid #000;">'. 
                htmlspecialchars($eval['title']) . 
            '</th>';
        }
    } else {
        // Si no hay evaluaciones para esta competencia, mostrar una columna indicativa
        echo '<th class="eval-header" style="background-color:#FFE6E6; font-style:italic; color:#999;">Sin evaluaciones</th>';
    }
    
    // Columna de promedio
    echo '<th class="promedio-col" style="background-color:#DAE3F3; font-weight:bold;">PROMEDIO</th>';
}
echo '</tr>';

// Filas de estudiantes con notas
foreach ($students as $student) {
    $student_id = $student['id'];
    
    echo '<tr>';
    echo '<td class="student-name" style="text-align:left; font-weight:normal;">' . $student['name'] . '</td>';
    
    $weighted_sum = 0;
    $total_percentage = 0;
    
    // Para cada competencia, mostrar notas
    foreach ($competencias as $comp) {
        $comp_id = $comp['id'];
        $comp_percentage = $comp['percentage'];
        $evals_comp = isset($evaluations[$comp_id]) ? $evaluations[$comp_id] : [];
        
        $notas = [];
        
        // Mostrar notas para cada evaluaciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n explÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­citamente
        if (count($evals_comp) > 0) {
            foreach ($evals_comp as $eval) {
                if (isset($grades[$student_id][$eval['id']])) {
                    $nota = $grades[$student_id][$eval['id']];
                    // Usar la funciÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â³n de formato para manejar letras y nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºmeros
                    echo '<td style="border:1px solid #000; background-color:#FFFFFF;">' . 
                        format_grade_display($nota, $export_format) . 
                    '</td>';
                    // Para cÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¡lculos, convertir letra a nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âºmero
                    $numeric_nota = letter_to_numeric_for_calc($nota);
                    if ($numeric_nota !== null) {
                        $notas[] = $numeric_nota;
                    }
                } else {
                    echo '<td style="border:1px solid #000; background-color:#FFFFFF;">-</td>';
                }
            }
        } else {
            // Si no hay evaluaciones para esta competencia, mostrar celda indicativa
            echo '<td style="border:1px solid #000; background-color:#FFF5F5; color:#999; font-style:italic;">Sin evaluaciones</td>';
        }
        
        // Mostrar promedio de competencia
        if (!empty($notas)) {
            $promedio_comp = array_sum($notas) / count($notas);
            $promedio_display = ($export_format === 'letters') ? 
                format_grade_display($promedio_comp, 'letters') : 
                number_format(round($promedio_comp), 0);
            echo '<td class="promedio-col" style="background-color:#DAE3F3; font-weight:bold;">' . 
                $promedio_display . 
            '</td>';
            $weighted_sum += $promedio_comp * ($comp_percentage / 100);
            $total_percentage += $comp_percentage;
        } else {
            echo '<td class="promedio-col" style="background-color:#DAE3F3; font-weight:bold;">-</td>';
        }
    }
    
    // Mostrar promedio final
    if ($total_percentage > 0) {
        $promedio_final = $weighted_sum;
        $promedio_final_display = ($export_format === 'letters') ? 
            format_grade_display($promedio_final, 'letters') : 
            number_format(round($promedio_final), 0);
        echo '<td class="promedio-final" style="background-color:#DAE3F3; font-weight:bold;">' . 
            $promedio_final_display . 
        '</td>';
    } else {
        echo '<td class="promedio-final" style="background-color:#DAE3F3; font-weight:bold;">-</td>';
    }
    
    echo '</tr>';
}

echo '</table>';

// Agregar JavaScript para establecer la cookie de descarga completada
if (!empty($download_token)) {
    echo '<script type="text/javascript">
        // Establecer cookie para indicar que la descarga ha completado
        document.cookie = "download_complete_' . $download_token . '=true; path=/; max-age=60";
    </script>';
}

echo '</body>
</html>';

// Establecer la cookie tambiÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â©n desde PHP por seguridad
if (!empty($download_token)) {
    setcookie("download_complete_" . $download_token, "true", time() + 60, "/");
}
?>
