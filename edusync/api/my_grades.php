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

// Ensure clean JSON output
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/json');

// Funciones para manejo de calificaciones
function is_letter_grade($grade) {
    if (empty($grade)) return false;
    $grade = strtoupper(trim($grade));
    return in_array($grade, ['C', 'B', 'A', 'AD']);
}
function letter_to_numeric_for_calc($grade) {
    if (empty($grade)) return 0;
    $grade = strtoupper(trim($grade));
    switch($grade) {
        case 'C': return 10;
        case 'B': return 13;
        case 'A': return 17;
        case 'AD': return 20;
        default: return is_numeric($grade) ? floatval($grade) : 0;
    }
}

function get_grade_for_calculation($grade) {
    return is_letter_grade($grade) ? letter_to_numeric_for_calc($grade) : floatval($grade);
}

$dni = $_GET['dni'] ?? ($_POST['dni'] ?? '');
$bimestre = $_GET['bimestre'] ?? ($_POST['bimestre'] ?? '');
$data = [];
$debug_errors = [];

if (!$dni) {
    echo json_encode(['status' => 'error', 'message' => 'DNI no recibido']);
    exit;
}

// Busca el ID, nombre, nivel, grado, sección y colegio del estudiante por su DNI
// NOTA: Obtenemos TODOS los IDs históricos vinculados a este DNI para atrapar grados de años anteriores bajo el mismo alumno
$stus_query = $conn->query("SELECT id, name, nivel, grado, seccion, status, school_id FROM student WHERE id_no = '$dni'");
if ($stus_query->num_rows == 0) {
    echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']);
    exit;
}

$student_ids = [];
while ($row = $stus_query->fetch_assoc()) {
    $student_ids[] = $row['id'];
}
$student_ids_sql = implode(',', $student_ids);

// Usar el primer registro para los metadatos globales
$stu_first = $conn->query("SELECT id, name, nivel, grado, seccion, status, school_id FROM student WHERE id_no = '$dni' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$student_id = $stu_first['id'] ?? 0;
$student_school_id = $stu_first['school_id'] ?? 1;
$student_name = $stu_first['name'] ?? '';
$nivel_alumno = $stu_first['nivel'] ?? '';
$grado_alumno = $stu_first['grado'] ?? '';
$seccion_alumno = $stu_first['seccion'] ?? '';

// Recuperar registros históricos del mismo alumno dentro del mismo colegio.
// En años anteriores algunos alumnos quedaron con otro ID o sin DNI, pero conservan el mismo nombre.
if (!empty($student_name)) {
    $name_safe = $conn->real_escape_string($student_name);
    $school_safe = intval($student_school_id);
    $historical_students_q = $conn->query("
        SELECT id
        FROM student
        WHERE school_id = {$school_safe}
          AND name = '$name_safe'
    ");

    if ($historical_students_q) {
        while ($historical_student = $historical_students_q->fetch_assoc()) {
            $historical_id = intval($historical_student['id']);
            if ($historical_id > 0 && !in_array($historical_id, $student_ids, true)) {
                $student_ids[] = $historical_id;
            }
        }
    }
}

// Recalcular la lista después de incorporar posibles IDs históricos.
$student_ids = array_values(array_unique(array_map('intval', $student_ids)));
$student_ids_sql = implode(',', $student_ids);

// Verificar deudas pendientes antes de devolver información de notas
if ($student_id) {
    // Evaluar SOLO las dos últimas asignaciones (por id DESC) y verificar si AMBAS tienen deuda
    $deuda_sql = "
        SELECT
            SUM(CASE WHEN pendiente > 0 THEN 1 ELSE 0 END) AS ultimas_con_deuda,
            COUNT(1) AS filas_consideradas,
            SUM(pendiente) AS total_pendiente
        FROM (
            SELECT
                ef.id,
                CASE
                    -- Si el monto a pagar es 0, no hay deuda (beca total)
                    WHEN (CASE WHEN ef.discounted_amount IS NOT NULL THEN ef.discounted_amount ELSE ef.total_fee END) = 0 THEN 0
                    ELSE GREATEST(
                        (CASE WHEN ef.discounted_amount IS NOT NULL THEN ef.discounted_amount ELSE ef.total_fee END)
                        - COALESCE(SUM(p.amount), 0),
                        0
                    )
                END AS pendiente
            FROM student_ef_list ef
            LEFT JOIN payments p ON p.ef_id = ef.id
            WHERE ef.student_id = {$student_id}
            GROUP BY ef.id, ef.discounted_amount, ef.total_fee
            ORDER BY ef.id DESC
            LIMIT 2
        ) t
    ";

    $deuda_res = $conn->query($deuda_sql);
    if ($deuda_res) {
        $deuda = $deuda_res->fetch_assoc();
        $ultimas_con_deuda = intval($deuda['ultimas_con_deuda'] ?? 0);
        $filas_consideradas = intval($deuda['filas_consideradas'] ?? 0);
        $total_pendiente_ultimas = floatval($deuda['total_pendiente'] ?? 0);

        // Calcular deuda total (mismo criterio que my_debts)
        $total_deuda_sql = "
            SELECT SUM(deuda) AS total_deuda
            FROM (
                SELECT
                    CASE
                        WHEN (CASE WHEN ef.discounted_amount IS NOT NULL THEN ef.discounted_amount ELSE ef.total_fee END) = 0 THEN 0
                        ELSE GREATEST(
                            (CASE WHEN ef.discounted_amount IS NOT NULL THEN ef.discounted_amount ELSE ef.total_fee END)
                            - COALESCE(SUM(p.amount), 0),
                            0
                        )
                    END AS deuda
                FROM student_ef_list ef
                INNER JOIN courses c ON c.id = ef.course_id
                INNER JOIN academic_year ay ON ay.id = c.academic_year_id
                LEFT JOIN payments p ON p.ef_id = ef.id
                WHERE ef.student_id = {$student_id}
                GROUP BY ef.id, ef.discounted_amount, ef.total_fee
            ) t2
            WHERE deuda > 0.01
        ";
        $total_deuda_res = $conn->query($total_deuda_sql);
        $total_deuda = 0;
        if ($total_deuda_res) {
            $total_deuda_row = $total_deuda_res->fetch_assoc();
            $total_deuda = floatval($total_deuda_row['total_deuda'] ?? 0);
        }

        // Bloquear solo si existen al menos 2 asignaciones y ambas tienen deuda
        if ($filas_consideradas >= 2 && $ultimas_con_deuda >= 2) {
            echo json_encode([
                'status' => 'error',
                'reason' => 'debt',
                'message' => 'No es posible mostrar la información de notas por deudas.',
                'total_pendiente_ultimas' => number_format($total_deuda, 2, '.', ''),
                'dni' => $dni,
                'alumno' => $student_name
            ]);
            exit;
        }
    }
}

if ($student_id) {
    // Filtro de bimestre (para API)
    $filtro_bimestre_sql = "";
    if ($bimestre !== '') {
        $filtro_bimestre_sql = " AND e.bimestre = '".$conn->real_escape_string($bimestre)."'";
    }
    
    // Obtener todos los años académicos disponibles en el sistema (Global)
    $years_query = $conn->query("
        SELECT id, year, description, is_active, start_date, end_date
        FROM academic_year
        ORDER BY year DESC
    ");
    
    $años_disponibles = [];
    $años_agrupados = [];
    while($year_row = $years_query->fetch_assoc()) {
        $year_str = $year_row['year'];
        if (!isset($años_agrupados[$year_str])) {
            $años_agrupados[$year_str] = [
                'año' => $year_str,
                'descripcion' => $year_row['description'],
                'es_activo' => false,
                'ids' => [],
                'start_date' => '9999-12-31',
                'end_date' => '0000-00-00 00:00:00'
            ];
            $años_disponibles[] =& $años_agrupados[$year_str];
        }
        $años_agrupados[$year_str]['ids'][] = $year_row['id'];
        
        // Mantener el rango de fechas dinámicamente más amplio posible para este año
        if (!empty($year_row['start_date']) && $year_row['start_date'] < $años_agrupados[$year_str]['start_date']) {
            $años_agrupados[$year_str]['start_date'] = $year_row['start_date'];
        }
        if (!empty($year_row['end_date']) && $year_row['end_date'] > $años_agrupados[$year_str]['end_date']) {
            $años_agrupados[$year_str]['end_date'] = $year_row['end_date'];
        }
        
        if ($year_row['is_active']) {
            $años_agrupados[$year_str]['es_activo'] = true;
        }
    }
    
    $años_con_notas = [];
    
    // Procesar cada año académico (agrupado por nombre de año)
    foreach ($años_disponibles as $año_info) {
        $ids_str = implode(',', $año_info['ids']);
        
        // Validar que tengamos un rango de fecha legítimo (mínimo fallback)
        $start_date = $año_info['start_date'] !== '9999-12-31' ? $año_info['start_date'] : '1970-01-01';
        $end_date = $año_info['end_date'] !== '0000-00-00 00:00:00' ? $año_info['end_date'] . ' 23:59:59' : '2099-12-31 23:59:59';
        
        // Búsqueda profunda temporal (Igual a my_attendance.php)
        $filtro_year_sql = " AND (
            tc.academic_year_id IN ($ids_str) OR 
            e.academic_year_id IN ($ids_str) OR 
            (e.created_at BETWEEN '$start_date' AND '$end_date')
        )";
        
        // Array para almacenar datos de los bimestres y promedios del año
        $bimestres = [];
        $promedio_anual = 0;
        $bimestres_count = 0;
    
        // Determinar qué bimestres procesar
        $procesar_bimestres = [];
        if ($bimestre !== '') {
            // Si se especificó un bimestre concreto, solo procesamos ese
            $procesar_bimestres = [$bimestre];
        } else {
            // Si no, procesamos todos los bimestres (1-4)
            $procesar_bimestres = ['1', '2', '3', '4'];
        }
    
    // Procesar cada bimestre
    foreach ($procesar_bimestres as $num_bimestre) {
        // Filtro específico para este bimestre
        $filtro_bimestre_actual = " AND e.bimestre = '$num_bimestre'";
        
        // Obtener competencias para este bimestre que tienen evaluaciones con notas para el estudiante
        // NO filtramos por grado/nivel/sección actual porque el estudiante pudo estar en otros grados en años anteriores
        $comp_q = $conn->query("SELECT DISTINCT 
                COALESCE(eg.competencia_id, 0) as competencia_id, 
                COALESCE(c.name, 'Evaluación General') as name, 
                COALESCE(c.percentage, 100) as percentage
            FROM evaluation_grades eg
            INNER JOIN evaluations e ON e.id = eg.evaluation_id
            LEFT JOIN teacher_courses tc ON tc.id = e.teacher_course_id
            LEFT JOIN academic_courses ac ON ac.id = tc.course_id
            LEFT JOIN general_course_competencies c ON c.id = eg.competencia_id
            WHERE eg.student_id IN ($student_ids_sql)
            $filtro_year_sql
            $filtro_bimestre_actual");
            
        // Si hay error en la consulta
        if (!$comp_q) {
            $debug_errors[] = "Error comp_q: " . $conn->error;
            continue;
        }
        
        $competencias = [];
        $hay_competencias = false;
        
        while($comp = $comp_q->fetch_assoc()) {
            $hay_competencias = true;
            $competencias[$comp['competencia_id']] = [
                'nombre' => $comp['name'],
                'peso' => floatval($comp['percentage'])/100
            ];
        }
        
        // Solo procesamos el bimestre si tiene competencias con evaluaciones
        if ($hay_competencias) {
            // Primero obtenemos todas las evaluaciones agrupadas por curso y competencia
            $cursos_data = [];
            
            foreach ($competencias as $cid => $cinfo) {
                $evals_q = $conn->query("SELECT e.title, eg.grade, 
                        COALESCE(ac.name, e.title) as curso, 
                        e.description as observacion, 
                        COALESCE(tc.course_id, 0) as course_id,
                        COALESCE(a.name, 'Área General') as area_nombre, 
                        COALESCE(a.color, '#6c757d') as area_color, 
                        a.description as area_descripcion
                    FROM evaluation_grades eg
                    INNER JOIN evaluations e ON e.id = eg.evaluation_id
                    LEFT JOIN teacher_courses tc ON tc.id = e.teacher_course_id
                    LEFT JOIN academic_courses ac ON ac.id = tc.course_id
                    LEFT JOIN areas a ON a.id = ac.area_id
                    WHERE eg.student_id IN ($student_ids_sql) 
                    AND COALESCE(eg.competencia_id, 0) = $cid 
                    $filtro_year_sql
                    $filtro_bimestre_actual");
                    
                if (!$evals_q) {
                    $debug_errors[] = "Error evals_q: " . $conn->error;
                    continue;
                }
                    
                while($n = $evals_q->fetch_assoc()) {
                    $curso_id = $n['course_id'];
                    $curso_nombre = $n['curso'];
                    
                    // Inicializar estructura del curso si no existe
                    if (!isset($cursos_data[$curso_id])) {
                        $cursos_data[$curso_id] = [
                            'nombre' => $curso_nombre,
                            'area' => [
                                'nombre' => $n['area_nombre'] ?: 'Sin área asignada',
                                'color' => $n['area_color'] ?: '#6c757d',
                                'descripcion' => $n['area_descripcion'] ?: ''
                            ],
                            'competencias' => [],
                            'suma_ponderada' => 0
                        ];
                    }
                    
                    // Inicializar competencia si no existe para este curso
                    if (!isset($cursos_data[$curso_id]['competencias'][$cid])) {
                        $cursos_data[$curso_id]['competencias'][$cid] = [
                            'nombre' => $cinfo['nombre'],
                            'peso' => $cinfo['peso'],
                            'notas' => [],
                            'evaluaciones' => []
                        ];
                    }
                    
                    // Agregar nota y evaluación (mantener formato original, usar numérico para cálculos)
                    $nota_original = $n['grade']; // Mantener el formato original
                    $nota_para_calculo = get_grade_for_calculation($n['grade']); // Convertir para cálculos
                    
                    $cursos_data[$curso_id]['competencias'][$cid]['notas'][] = $nota_para_calculo;
                    $cursos_data[$curso_id]['competencias'][$cid]['evaluaciones'][] = [
                        'curso' => $curso_nombre,
                        'evaluacion' => $n['title'],
                        'nota' => $nota_original, // Mostrar el formato original
                        'observacion' => $n['observacion']
                    ];
                }
            }
            
            // Ahora calculamos el promedio para cada curso
            $competencias_bimestre = [];
            $cursos_promedios = [];
            $suma_promedios_cursos = 0;
            $cantidad_cursos = 0;
            
            // Primero agregamos todas las competencias a la lista para mostrar
            foreach ($cursos_data as $curso_id => $curso_info) {
                foreach ($curso_info['competencias'] as $comp_id => $comp_data) {
                    // Calcular promedio de la competencia (simple, sin ponderar)
                    $promedio_competencia = 0;
                    if (count($comp_data['notas']) > 0) {
                        $promedio_competencia = array_sum($comp_data['notas']) / count($comp_data['notas']);
                    }
                    
                    // Calcular promedio ponderado (promedio × peso)
                    $promedio_ponderado = $promedio_competencia * $comp_data['peso'];
                    
                    // Agregar a la lista de competencias para mostrar
                    // Devolver ambos formatos: nuevo (nombre, peso decimal) y antiguo (competencia, peso porcentaje, ponderado)
                    $competencias_bimestre[] = [
                        'nombre' => $comp_data['nombre'],
                        'competencia' => $comp_data['nombre'],  // Para compatibilidad app móvil
                        'peso' => $comp_data['peso'] * 100,  // Porcentaje para app móvil
                        'promedio_simple' => strval(intval(round($promedio_competencia))),
                        'promedio' => strval(round($promedio_ponderado, 2)),
                        'ponderado' => strval(round($promedio_ponderado, 2)),  // Para compatibilidad app móvil - DECIMAL
                        'evaluaciones' => $comp_data['evaluaciones']
                    ];
                }
            }
            
            // Ahora calculamos el promedio final para cada curso
            foreach ($cursos_data as $curso_id => $curso_info) {
                $suma_ponderada_curso = 0;
                
                // Preparar array de competencias para este curso
                $competencias_curso = [];
                
                // Sumar todos los ponderados de todas las competencias del curso
                foreach ($curso_info['competencias'] as $comp_id => $comp_data) {
                    $promedio_competencia = 0;
                    if (count($comp_data['notas']) > 0) {
                        $promedio_competencia = array_sum($comp_data['notas']) / count($comp_data['notas']);
                    }
                    
                    $ponderado_competencia = $promedio_competencia * $comp_data['peso'];
                    $suma_ponderada_curso += $ponderado_competencia;
                    
                    // Agregar competencia al array del curso
                    // Devolver ambos formatos para compatibilidad
                    $competencias_curso[$comp_id] = [
                        'nombre' => $comp_data['nombre'],
                        'competencia' => $comp_data['nombre'],  // Para compatibilidad app móvil
                        'peso' => $comp_data['peso'],  // Decimal
                        'promedio_simple' => strval(intval(round($promedio_competencia))),
                        'promedio' => strval(round($ponderado_competencia, 2)),
                        'ponderado' => strval(round($ponderado_competencia, 2)),  // Para compatibilidad app móvil - DECIMAL
                        'evaluaciones' => $comp_data['evaluaciones']
                    ];
                }
                
                // El promedio del curso es la suma de TODAS sus competencias ponderadas
                $cursos_promedios[] = [
                    'curso' => $curso_info['nombre'],
                    'area' => $curso_info['area'],
                    'promedio' => strval(intval(round($suma_ponderada_curso))),
                    'competencias' => $competencias_curso
                ];
                
                $suma_promedios_cursos += $suma_ponderada_curso;
                $cantidad_cursos++;
            }
            
            // El promedio del bimestre es el promedio de los promedios de todos los cursos
            $promedio_bimestre = $cantidad_cursos > 0 ? $suma_promedios_cursos / $cantidad_cursos : 0;
            
            // Añadir información del bimestre al array de bimestres
            $bimestres[] = [
                'numero' => $num_bimestre,
                'competencias' => $competencias_bimestre,
                'cursos' => $cursos_promedios,
                'promedio_bimestre' => strval(intval(round($promedio_bimestre)))
            ];
            
            // Acumular para promedio anual
            $promedio_anual += $promedio_bimestre;
            $bimestres_count++;
        }
    }
    
    // Calcular promedio anual (solo si hay bimestres procesados)
    $promedio_anual_final = $bimestres_count > 0 ? $promedio_anual / $bimestres_count : 0;
    
        // Calcular promedios anuales por curso para este año
        $cursos_anuales = [];
        foreach ($bimestres as $bimestre) {
            if (isset($bimestre['cursos'])) {
                foreach ($bimestre['cursos'] as $curso) {
                    $curso_nombre = $curso['curso'];
                    $curso_promedio = floatval(str_replace(',', '.', $curso['promedio']));
                    
                    if (!isset($cursos_anuales[$curso_nombre])) {
                        $cursos_anuales[$curso_nombre] = [
                            'area' => $curso['area'],
                            'promedios' => [],
                            'bimestres' => []
                        ];
                    }
                    
                    $cursos_anuales[$curso_nombre]['promedios'][] = $curso_promedio;
                    $cursos_anuales[$curso_nombre]['bimestres'][$bimestre['numero']] = $curso_promedio;
                }
            }
        }
        
        $promedios_anuales_cursos = [];
        foreach ($cursos_anuales as $curso_nombre => $curso_data) {
            $promedio_anual_curso = 0;
            if (count($curso_data['promedios']) > 0) {
                $promedio_anual_curso = array_sum($curso_data['promedios']) / count($curso_data['promedios']);
            }
            
            $promedios_anuales_cursos[] = [
                'curso' => $curso_nombre,
                'area' => $curso_data['area'],
                'promedio_anual' => strval(intval(round($promedio_anual_curso))),
                'detalle_bimestres' => $curso_data['bimestres']
            ];
        }
        
        // Agregar datos del año académico al array de años con notas
        if (!empty($bimestres)) {
            $años_con_notas[] = [
                'año' => $año_info['año'],
                'descripcion' => $año_info['descripcion'],
                'es_activo' => $año_info['es_activo'],
                'bimestres' => $bimestres,
                'promedio_anual' => strval(intval(round($promedio_anual_final))),
                'promedios_por_curso' => $promedios_anuales_cursos
            ];
        }
    }
    
    // Obtener información del año académico activo
    $active_year_result = $conn->query("SELECT year, description FROM academic_year WHERE is_active = 1 LIMIT 1");
    $active_year_info = $active_year_result->fetch_assoc();
    $current_year = $active_year_info ? $active_year_info['year'] : 'N/A';
    
    // Armar objeto de respuesta
    $data = [
        'alumno' => $student_name,
        'dni' => $dni,
        'nivel' => $nivel_alumno,
        'grado' => $grado_alumno,
        'seccion' => $seccion_alumno,
        'anio_academico_actual' => $current_year,
        'años_disponibles' => $años_disponibles,
        'años_academicos' => $años_con_notas,
        'total_años_con_notas' => count($años_con_notas),
        'debug_errors' => $debug_errors
    ];
    
    echo json_encode(['status' => 'ok', 'data' => $data]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'ID de estudiante no encontrado']);
?>
