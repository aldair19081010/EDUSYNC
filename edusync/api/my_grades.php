<?php
include '../db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// Ocultar errores temporalmente de HTML (los guardaremos dinámicamente)
ini_set('display_errors', 0);

function get_grade_for_calculation($grade) {
    if ($grade === null || $grade === '') return 0;
    
    $grade = strtoupper(trim(strval($grade)));
    
    // Si es letra, convertir a valor numérico
    if (is_letter_grade($grade)) {
        return letter_to_numeric_for_calc($grade);
    }
    
    // Si es numérico, extraer y validar
    $numeric = floatval($grade);
    return max(0, min(20, $numeric)); // Asegurar que esté entre 0 y 20
}

function is_letter_grade($grade) {
    $valid_letters = ['AD', 'A', 'B', 'C'];
    return in_array(strtoupper(trim(strval($grade))), $valid_letters);
}

function letter_to_numeric_for_calc($grade) {
    switch (strtoupper(trim(strval($grade)))) {
        case 'AD': return 20;
        case 'A': return 17;
        case 'B': return 13;
        case 'C': return 10;
        default: return 0;
    }
}

$dni = $_GET['dni'] ?? ($_POST['dni'] ?? '');
$bimestre = $_GET['bimestre'] ?? ($_POST['bimestre'] ?? '');
$data = [];
$debug_errors = [];

try {
    if (!$dni) {
        echo json_encode(['status' => 'error', 'message' => 'DNI no recibido']);
        exit;
    }

// 1. Obtener TODOS los IDs del estudiante que coinciden con su DNI
$stus_query = $conn->query("SELECT id, name, nivel, grado, seccion, status, school_id FROM student WHERE id_no = '$dni'");
if ($stus_query->num_rows == 0) {
    // Si no lo encuentra por DNI, intentaremos hacer un rescate broad-match en caso que hayan migrado el ID
    echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']);
    exit;
}

$student_ids = [];
while ($row = $stus_query->fetch_assoc()) {
    $student_ids[] = $row['id'];
}

// Rescatar por NOMBRE para atrapar posibles clones donde el administrador olvidó tipear el DNI en 2025
$stu_first = $conn->query("SELECT id, name, nivel, grado, seccion, status, school_id FROM student WHERE id_no = '$dni' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$student_name = $stu_first['name'] ?? '';

if (!empty($student_name)) {
    $name_safe = $conn->real_escape_string($student_name);
    $stus_name_q = $conn->query("SELECT id FROM student WHERE name = '$name_safe' AND id_no != '$dni'");
    if ($stus_name_q) {
        while ($r = $stus_name_q->fetch_assoc()) {
            if (!in_array($r['id'], $student_ids)) {
                $student_ids[] = $r['id'];
            }
        }
    }
}

$student_ids_sql = implode(',', $student_ids);
$student_id = $stu_first['id'] ?? 0;
$student_school_id = $stu_first['school_id'] ?? 1;
$nivel_alumno = $stu_first['nivel'] ?? '';
$grado_alumno = $stu_first['grado'] ?? '';
$seccion_alumno = $stu_first['seccion'] ?? '';

if ($student_id) {
    // Verificar si las calificaciones están bloqueadas por deuda solo cuando
    // la instalación dispone del módulo/tabla partial_payments.
    $partial_payments_table = $conn->query("SHOW TABLES LIKE 'partial_payments'");
    if ($partial_payments_table && $partial_payments_table->num_rows > 0) {
        $debt_check = $conn->query("
            SELECT * FROM partial_payments 
            WHERE student_id IN ($student_ids_sql) AND status = 'unpaid'
            ORDER BY deadline ASC
        ");
        
        $filas_consideradas = 0;
        $ultimas_con_deuda = 0;
        $total_pendiente_ultimas = 0;

        if ($debt_check && $debt_check->num_rows > 0) {
            $todos_los_pagos = [];
            while ($row = $debt_check->fetch_assoc()) {
                $todos_los_pagos[] = $row;
            }
            $pagos_recientes = array_slice($todos_los_pagos, -2);
            
            foreach ($pagos_recientes as $pago) {
                $filas_consideradas++;
                if ($pago['status'] === 'unpaid') {
                    $ultimas_con_deuda++;
                    $total_pendiente_ultimas += floatval($pago['amount']);
                }
            }

            if ($filas_consideradas >= 2 && $ultimas_con_deuda >= 2) {
                echo json_encode([
                    'status' => 'error',
                    'reason' => 'debt',
                    'message' => 'No es posible mostrar la información de notas por deuda (2 o más cuotas pendientes).',
                    'total_pendiente_ultimas' => number_format($total_pendiente_ultimas, 2)
                ]);
                exit;
            }
        }
    }

    // MAPEO ABSOLUTO: OBTENER TODAS LAS NOTAS, Y MAPEARLAS EN PHP LADO-MEMORIA
    // Obtener todos los años disponibles del colegio
    $years_query = $conn->query("
        SELECT id, year, description, is_active, start_date, end_date
        FROM academic_year
        ORDER BY year DESC
    ");
    
    $años_disponibles = [];
    $años_agrupados = [];
    
    while($y = $years_query->fetch_assoc()) {
        $ys = $y['year'];
        if (!isset($años_agrupados[$ys])) {
            $años_agrupados[$ys] = [
                'año' => $ys,
                'descripcion' => $y['description'],
                'es_activo' => false,
                'ids' => [],
                'start_date' => '9999-12-31',
                'end_date' => '0000-00-00 00:00:00'
            ];
            $años_disponibles[] =& $años_agrupados[$ys];
        }
        $años_agrupados[$ys]['ids'][] = $y['id'];
        if (!empty($y['start_date']) && $y['start_date'] < $años_agrupados[$ys]['start_date']) $años_agrupados[$ys]['start_date'] = $y['start_date'];
        if (!empty($y['end_date']) && $y['end_date'] > $años_agrupados[$ys]['end_date']) $años_agrupados[$ys]['end_date'] = $y['end_date'];
        if ($y['is_active']) $años_agrupados[$ys]['es_activo'] = true;
    }

    // EXTRAER EL MÁXIMO BUCKET DE CALIFICACIONES SIN FILTROS DE WHERE DESTRUCTIVOS
    $sql_all = "SELECT e.title, eg.grade, 
            COALESCE(ac.name, e.title) as curso, 
            e.description as observacion, 
            COALESCE(tc.course_id, 0) as course_id,
            COALESCE(a.name, 'Área General') as area_nombre, 
            COALESCE(a.color, '#6c757d') as area_color, 
            a.description as area_descripcion,
            e.bimestre,
            e.created_at,
            tc.academic_year_id as tc_year,
            e.academic_year_id as e_year,
            COALESCE(eg.competencia_id, 0) as competencia_id,
            COALESCE(c.name, 'Evaluación General') as competencia_nombre,
            COALESCE(c.percentage, 100) as porcentaje
        FROM evaluation_grades eg
        LEFT JOIN evaluations e ON e.id = eg.evaluation_id
        LEFT JOIN teacher_courses tc ON tc.id = e.teacher_course_id
        LEFT JOIN academic_courses ac ON ac.id = tc.course_id
        LEFT JOIN areas a ON a.id = ac.area_id
        LEFT JOIN general_course_competencies c ON c.id = eg.competencia_id
        WHERE eg.student_id IN ($student_ids_sql)";
        
    $all_grades_q = $conn->query($sql_all);
    if (!$all_grades_q) {
        $debug_errors[] = "Error FATAL SQL Extraction: " . $conn->error;
    }

    // Contenedor dinámico estructurado: $bucket_anual[año][bimestre][curso_id] -> nota
    $bucket_anual = [];
    $competencias_usadas = [];
    
    if ($all_grades_q) {
        while ($grade_row = $all_grades_q->fetch_assoc()) {
            
            // Inferir a qué año pertenece
            $pertence_a_año = null;
            $tc_year = $grade_row['tc_year'];
            $e_year = $grade_row['e_year'];
            $created_dt = $grade_row['created_at'];
            
            foreach ($años_disponibles as $año_info) {
                if (in_array($tc_year, $año_info['ids']) || in_array($e_year, $año_info['ids'])) {
                    $pertence_a_año = $año_info['año'];
                    break;
                }
                if ($created_dt) {
                    $y_st = $año_info['start_date'] !== '9999-12-31' ? $año_info['start_date'] : '1970-01-01';
                    $y_en = $año_info['end_date'] !== '0000-00-00 00:00:00' ? $año_info['end_date'] . ' 23:59:59' : '2099-12-31 23:59:59';
                    if ($created_dt >= $y_st && $created_dt <= $y_en) {
                        $pertence_a_año = $año_info['año'];
                        break;
                    }
                }
            }
            
            // Forzar orphans al 2025 si hay un solo año disponible o si queremos agrupar
            if (!$pertence_a_año && count($años_disponibles) > 0) {
                 $pertence_a_año = $años_disponibles[count($años_disponibles)-1]['año'];
            }
            if (!$pertence_a_año) continue;
            
            // Identificar Bimestre
            $b_num = trim($grade_row['bimestre']);
            // Si el bimestre de la BD no dice 1, 2, 3 o 4 (ejemplo I, II, o fue borrado), lo encajamos en 1
            if (empty($b_num) || !in_array($b_num, ['1','2','3','4'])) {
                $b_num = '1';
            }
            
            // Si el filtro UI requirió un bimestre
            if ($bimestre !== '' && $b_num !== $bimestre) continue;
            
            $c_id = $grade_row['course_id'];
            $comp_id = $grade_row['competencia_id'];
            
            if (!isset($bucket_anual[$pertence_a_año])) $bucket_anual[$pertence_a_año] = [];
            if (!isset($bucket_anual[$pertence_a_año][$b_num])) $bucket_anual[$pertence_a_año][$b_num] = [];
            if (!isset($bucket_anual[$pertence_a_año][$b_num][$c_id])) {
                $bucket_anual[$pertence_a_año][$b_num][$c_id] = [
                    'nombre' => $grade_row['curso'],
                    'area' => [
                        'nombre' => $grade_row['area_nombre'],
                        'color' => $grade_row['area_color'],
                        'descripcion' => $grade_row['area_descripcion']
                    ],
                    'competencias' => []
                ];
            }
            
            if (!isset($bucket_anual[$pertence_a_año][$b_num][$c_id]['competencias'][$comp_id])) {
                $bucket_anual[$pertence_a_año][$b_num][$c_id]['competencias'][$comp_id] = [
                    'nombre' => $grade_row['competencia_nombre'],
                    'peso' => floatval($grade_row['porcentaje'])/100,
                    'notas' => []
                ];
            }
            
            $bucket_anual[$pertence_a_año][$b_num][$c_id]['competencias'][$comp_id]['notas'][] = [
                'titulo' => $grade_row['title'] ?: 'Evaluación huérfana',
                'nota' => $grade_row['grade'],
                'numeric' => get_grade_for_calculation($grade_row['grade']),
                'observacion' => $grade_row['observacion'] ?: ''
            ];
            
            if (!isset($competencias_usadas[$pertence_a_año])) $competencias_usadas[$pertence_a_año] = [];
            if (!isset($competencias_usadas[$pertence_a_año][$b_num])) $competencias_usadas[$pertence_a_año][$b_num] = [];
            $competencias_usadas[$pertence_a_año][$b_num][$comp_id] = [
                'nombre' => $grade_row['competencia_nombre'],
                'peso' => floatval($grade_row['porcentaje'])/100
            ];
        }
    }

    $años_con_notas = [];
    
    foreach ($años_disponibles as $año_info) {
        $year_str = $año_info['año'];
        if (!isset($bucket_anual[$year_str])) continue;
        
        $bimestres_export = [];
        $promedio_anual = 0;
        $bimestres_count = 0;
        
        // ORDENAR LAS CLAVES DE BIMESTRE DE MENOR A MAYOR ('1' primero)
        ksort($bucket_anual[$year_str]);
        
        foreach ($bucket_anual[$year_str] as $b_num => $cursos_data) {
            $cursos_promedios = [];
            $suma_promedios_cursos = 0;
            $cantidad_cursos = 0;
            
            foreach ($cursos_data as $c_id => $curso_info) {
                $suma_ponderada_curso = 0;
                $competencias_con_nota = 0;
                $competencias_curso = [];
                
                foreach ($curso_info['competencias'] as $comp_id => $comp_data) {
                    if (count($comp_data['notas']) > 0) {
                        $suma_notas = 0;
                        foreach ($comp_data['notas'] as $nota) {
                            $suma_notas += $nota['numeric'];
                        }
                        $prom_comp = $suma_notas / count($comp_data['notas']);
                        $suma_ponderada_curso += ($prom_comp * $comp_data['peso']);
                        $competencias_con_nota++;
                        
                        $competencias_curso[] = [
                            'competencia' => $comp_data['nombre'],
                            'promedio' => strval(intval(round($prom_comp))),
                            'notas' => $comp_data['notas']
                        ];
                    }
                }
                
                if ($competencias_con_nota > 0) {
                    $cursos_promedios[] = [
                        'curso' => $curso_info['nombre'],
                        'area' => $curso_info['area'],
                        'promedio' => strval(intval(round($suma_ponderada_curso))),
                        'competencias' => $competencias_curso
                    ];
                    $suma_promedios_cursos += $suma_ponderada_curso;
                    $cantidad_cursos++;
                }
            }
            
            if ($cantidad_cursos > 0) {
                $promedio_bimestre = $suma_promedios_cursos / $cantidad_cursos;
                
                $comps_bim_raw = $competencias_usadas[$year_str][$b_num] ?? [];
                $comps_bim = [];
                foreach ($comps_bim_raw as $k => $c) {
                    $comps_bim[] = ['competencia_id' => strval($k), 'nombre' => $c['nombre'], 'peso' => $c['peso']];
                }
                
                $bimestres_export[] = [
                    'numero' => strval($b_num),
                    'competencias' => $comps_bim,
                    'cursos' => $cursos_promedios,
                    'promedio_bimestre' => strval(intval(round($promedio_bimestre)))
                ];
                
                $promedio_anual += $promedio_bimestre;
                $bimestres_count++;
            }
        }
        
        $promedio_anual_final = $bimestres_count > 0 ? $promedio_anual / $bimestres_count : 0;
        
        $cursos_anuales = [];
        foreach ($bimestres_export as $bim) {
            foreach ($bim['cursos'] as $curso) {
                $cn = $curso['curso'];
                $cp = floatval(str_replace(',', '.', $curso['promedio']));
                if (!isset($cursos_anuales[$cn])) $cursos_anuales[$cn] = ['area' => $curso['area'], 'promedios' => [], 'bimestres' => []];
                $cursos_anuales[$cn]['promedios'][] = $cp;
                $cursos_anuales[$cn]['bimestres'][$bim['numero']] = $cp;
            }
        }
        
        $promedios_anuales_cursos = [];
        foreach ($cursos_anuales as $cn => $cd) {
            $pac = count($cd['promedios']) > 0 ? array_sum($cd['promedios'])/count($cd['promedios']) : 0;
            $promedios_anuales_cursos[] = ['curso' => $cn, 'area' => $cd['area'], 'promedio_anual' => strval(intval(round($pac))), 'detalle_bimestres' => $cd['bimestres']];
        }
        
        if (!empty($bimestres_export)) {
            $años_con_notas[] = [
                'año' => $año_info['año'],
                'descripcion' => $año_info['descripcion'],
                'es_activo' => $año_info['es_activo'],
                'bimestres' => $bimestres_export,
                'promedio_anual' => strval(intval(round($promedio_anual_final))),
                'promedios_por_curso' => $promedios_anuales_cursos
            ];
        }
    }
    
    $active_year_result = $conn->query("SELECT year, description FROM academic_year WHERE is_active = 1 LIMIT 1");
    $active_year_info = $active_year_result->fetch_assoc();
    $current_year = $active_year_info ? $active_year_info['year'] : 'N/A';
    
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
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'FATAL PHP EXCEPTION: ' . $e->getMessage(),
        'line' => $e->getLine()
    ]);
}
?>