<?php
include '../db_connect.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

function get_grade_for_calculation($grade) {
    if ($grade === null || $grade === '') return 0;
    $grade = strtoupper(trim(strval($grade)));
    if (is_letter_grade($grade)) return letter_to_numeric_for_calc($grade);
    $numeric = floatval($grade);
    return max(0, min(20, $numeric));
}

function is_letter_grade($grade) {
    return in_array(strtoupper(trim(strval($grade))), ['AD', 'A', 'B', 'C']);
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

function legacy_has_table($db, $table) {
    $safe = $db->real_escape_string((string)$table);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function legacy_has_column($db, $table, $column) {
    if (!legacy_has_table($db, $table)) return false;
    $tableSafe = str_replace('`', '', (string)$table);
    $columnSafe = $db->real_escape_string((string)$column);
    $q = $db->query("SHOW COLUMNS FROM `$tableSafe` LIKE '$columnSafe'");
    return $q && $q->num_rows > 0;
}

function legacy_normalize_bimester($value) {
    $raw = strtoupper(trim((string)$value));
    if ($raw === '') return '1';
    $compact = preg_replace('/\s+/', '', $raw);
    $map = [
        '1'=>'1','I'=>'1','1RO'=>'1','1ER'=>'1','PRIMERO'=>'1','PRIMER'=>'1',
        '2'=>'2','II'=>'2','2DO'=>'2','SEGUNDO'=>'2',
        '3'=>'3','III'=>'3','3RO'=>'3','TERCERO'=>'3',
        '4'=>'4','IV'=>'4','4TO'=>'4','CUARTO'=>'4'
    ];
    if (isset($map[$compact])) return $map[$compact];
    if (preg_match('/(?:BIMESTRE|BIM|B)?([1-4])/', $compact, $m)) return $m[1];
    return '1';
}

$dni = $_GET['dni'] ?? ($_POST['dni'] ?? '');
$bimestre = $_GET['bimestre'] ?? ($_POST['bimestre'] ?? '');
$bimestre = $bimestre !== '' ? legacy_normalize_bimester($bimestre) : '';
$data = [];
$debug_errors = [];

try {
    if (!$dni) {
        echo json_encode(['status' => 'error', 'message' => 'DNI no recibido']);
        exit;
    }

    $dni_safe = $conn->real_escape_string((string)$dni);
    $stus_query = $conn->query("SELECT id, name, nivel, grado, seccion, status, school_id FROM student WHERE id_no = '$dni_safe'");
    if (!$stus_query || $stus_query->num_rows == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']);
        exit;
    }

    $student_ids = [];
    while ($row = $stus_query->fetch_assoc()) $student_ids[] = (int)$row['id'];

    $stu_query = $conn->query("SELECT id, name, nivel, grado, seccion, status, school_id FROM student WHERE id_no = '$dni_safe' ORDER BY id DESC LIMIT 1");
    $stu_first = $stu_query ? $stu_query->fetch_assoc() : null;
    $student_name = $stu_first['name'] ?? '';

    if (!empty($student_name)) {
        $name_safe = $conn->real_escape_string($student_name);
        $stus_name_q = $conn->query("SELECT id FROM student WHERE name = '$name_safe' AND id_no != '$dni_safe'");
        if ($stus_name_q) {
            while ($r = $stus_name_q->fetch_assoc()) {
                $rid = (int)$r['id'];
                if (!in_array($rid, $student_ids, true)) $student_ids[] = $rid;
            }
        }
    }

    $student_ids = array_values(array_unique(array_filter(array_map('intval', $student_ids))));
    $student_ids_sql = implode(',', $student_ids);
    $student_id = (int)($stu_first['id'] ?? 0);
    $student_school_id = (int)($stu_first['school_id'] ?? 1);
    $nivel_alumno = $stu_first['nivel'] ?? '';
    $grado_alumno = $stu_first['grado'] ?? '';
    $seccion_alumno = $stu_first['seccion'] ?? '';

    if ($student_id) {
        // Mantener la regla antigua de bloqueo por deuda solo en instalaciones
        // que realmente cuentan con la tabla partial_payments.
        if (legacy_has_table($conn, 'partial_payments')) {
            $debt_check = $conn->query("SELECT * FROM partial_payments WHERE student_id IN ($student_ids_sql) AND status = 'unpaid' ORDER BY deadline ASC");
            $filas_consideradas = 0;
            $ultimas_con_deuda = 0;
            $total_pendiente_ultimas = 0;
            if ($debt_check && $debt_check->num_rows > 0) {
                $todos_los_pagos = [];
                while ($row = $debt_check->fetch_assoc()) $todos_los_pagos[] = $row;
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

        // Años académicos: conservar el contrato legacy, pero tolerar columnas opcionales.
        $year_desc = legacy_has_column($conn, 'academic_year', 'description') ? 'description' : "'' AS description";
        $year_active = legacy_has_column($conn, 'academic_year', 'is_active') ? 'is_active' : '0 AS is_active';
        $year_start = legacy_has_column($conn, 'academic_year', 'start_date') ? 'start_date' : 'NULL AS start_date';
        $year_end = legacy_has_column($conn, 'academic_year', 'end_date') ? 'end_date' : 'NULL AS end_date';
        $years_query = $conn->query("SELECT id, year, $year_desc, $year_active, $year_start, $year_end FROM academic_year ORDER BY year DESC");
        if (!$years_query) throw new Exception('No se pudieron consultar los años académicos: ' . $conn->error);

        $años_disponibles = [];
        $años_agrupados = [];
        while ($y = $years_query->fetch_assoc()) {
            $ys = $y['year'];
            if (!isset($años_agrupados[$ys])) {
                $años_agrupados[$ys] = [
                    'año' => $ys,
                    'descripcion' => $y['description'] ?? '',
                    'es_activo' => false,
                    'ids' => [],
                    'start_date' => '9999-12-31',
                    'end_date' => '0000-00-00 00:00:00'
                ];
                $años_disponibles[] =& $años_agrupados[$ys];
            }
            $años_agrupados[$ys]['ids'][] = (int)$y['id'];
            if (!empty($y['start_date']) && $y['start_date'] < $años_agrupados[$ys]['start_date']) $años_agrupados[$ys]['start_date'] = $y['start_date'];
            if (!empty($y['end_date']) && $y['end_date'] > $años_agrupados[$ys]['end_date']) $años_agrupados[$ys]['end_date'] = $y['end_date'];
            if (!empty($y['is_active'])) $años_agrupados[$ys]['es_activo'] = true;
        }

        // Consulta de notas adaptable. El JSON de salida sigue siendo el de la API antigua.
        $has_evaluations = legacy_has_table($conn, 'evaluations');
        $has_teacher_courses = legacy_has_table($conn, 'teacher_courses');
        $has_academic_courses = legacy_has_table($conn, 'academic_courses');
        $has_areas = legacy_has_table($conn, 'areas');
        $has_competencies = legacy_has_table($conn, 'general_course_competencies');

        $can_join_evaluation = $has_evaluations && legacy_has_column($conn, 'evaluation_grades', 'evaluation_id');
        $evaluation_join = $can_join_evaluation ? 'LEFT JOIN evaluations e ON e.id = eg.evaluation_id' : '';
        $can_join_teacher_course = $can_join_evaluation && $has_teacher_courses && legacy_has_column($conn, 'evaluations', 'teacher_course_id');
        $teacher_course_join = $can_join_teacher_course ? 'LEFT JOIN teacher_courses tc ON tc.id = e.teacher_course_id' : '';
        $can_join_course = $can_join_teacher_course && $has_academic_courses && legacy_has_column($conn, 'teacher_courses', 'course_id');
        $academic_course_join = $can_join_course ? 'LEFT JOIN academic_courses ac ON ac.id = tc.course_id' : '';
        $can_join_area = $can_join_course && $has_areas && legacy_has_column($conn, 'academic_courses', 'area_id');
        $area_join = $can_join_area ? 'LEFT JOIN areas a ON a.id = ac.area_id' : '';
        $can_join_comp = $has_competencies && legacy_has_column($conn, 'evaluation_grades', 'competencia_id');
        $competency_join = $can_join_comp ? 'LEFT JOIN general_course_competencies c ON c.id = eg.competencia_id' : '';

        $evaluation_id_select = legacy_has_column($conn, 'evaluation_grades', 'evaluation_id') ? 'eg.evaluation_id' : '0';
        $title_select = $can_join_evaluation && legacy_has_column($conn, 'evaluations', 'title') ? "COALESCE(e.title, 'Evaluación')" : "'Evaluación'";
        $obs_select = $can_join_evaluation && legacy_has_column($conn, 'evaluations', 'description') ? "COALESCE(e.description, '')" : "''";
        $bim_select = $can_join_evaluation && legacy_has_column($conn, 'evaluations', 'bimestre') ? 'e.bimestre' : "'1'";
        $created_select = $can_join_evaluation && legacy_has_column($conn, 'evaluations', 'created_at') ? 'e.created_at' : 'NULL';
        $tc_year_select = $can_join_teacher_course && legacy_has_column($conn, 'teacher_courses', 'academic_year_id') ? 'tc.academic_year_id' : 'NULL';
        $e_year_select = $can_join_evaluation && legacy_has_column($conn, 'evaluations', 'academic_year_id') ? 'e.academic_year_id' : 'NULL';
        $course_id_select = $can_join_teacher_course && legacy_has_column($conn, 'teacher_courses', 'course_id') ? 'COALESCE(tc.course_id, 0)' : '0';
        $course_name_select = $can_join_course && legacy_has_column($conn, 'academic_courses', 'name') ? "COALESCE(ac.name, $title_select, 'Curso')" : "COALESCE($title_select, 'Curso')";
        $area_name_select = $can_join_area && legacy_has_column($conn, 'areas', 'name') ? "COALESCE(a.name, 'Área General')" : "'Área General'";
        $area_color_select = $can_join_area && legacy_has_column($conn, 'areas', 'color') ? "COALESCE(a.color, '#6c757d')" : "'#6c757d'";
        $area_description_select = $can_join_area && legacy_has_column($conn, 'areas', 'description') ? "COALESCE(a.description, '')" : "''";
        $comp_id_select = legacy_has_column($conn, 'evaluation_grades', 'competencia_id') ? 'COALESCE(eg.competencia_id, 0)' : '0';
        $comp_name_select = $can_join_comp && legacy_has_column($conn, 'general_course_competencies', 'name') ? "COALESCE(c.name, 'Evaluación General')" : "'Evaluación General'";
        $percentage_select = $can_join_comp && legacy_has_column($conn, 'general_course_competencies', 'percentage') ? 'COALESCE(c.percentage, 100)' : '100';

        $sql_all = "SELECT
                $evaluation_id_select AS evaluation_id,
                $title_select AS title,
                eg.grade,
                $course_name_select AS curso,
                $obs_select AS observacion,
                $course_id_select AS course_id,
                $area_name_select AS area_nombre,
                $area_color_select AS area_color,
                $area_description_select AS area_descripcion,
                $bim_select AS bimestre,
                $created_select AS created_at,
                $tc_year_select AS tc_year,
                $e_year_select AS e_year,
                $comp_id_select AS competencia_id,
                $comp_name_select AS competencia_nombre,
                $percentage_select AS porcentaje
            FROM evaluation_grades eg
            $evaluation_join
            $teacher_course_join
            $academic_course_join
            $area_join
            $competency_join
            WHERE eg.student_id IN ($student_ids_sql)";

        $all_grades_q = $conn->query($sql_all);
        if (!$all_grades_q) $debug_errors[] = 'Error FATAL SQL Extraction: ' . $conn->error;

        $bucket_anual = [];
        $competencias_usadas = [];
        if ($all_grades_q) {
            while ($grade_row = $all_grades_q->fetch_assoc()) {
                $pertence_a_año = null;
                $tc_year = $grade_row['tc_year'];
                $e_year = $grade_row['e_year'];
                $created_dt = $grade_row['created_at'];

                foreach ($años_disponibles as $año_info) {
                    if (in_array((int)$tc_year, $año_info['ids'], true) || in_array((int)$e_year, $año_info['ids'], true)) {
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
                if (!$pertence_a_año && count($años_disponibles) > 0) $pertence_a_año = $años_disponibles[count($años_disponibles)-1]['año'];
                if (!$pertence_a_año) continue;

                $b_num = legacy_normalize_bimester($grade_row['bimestre'] ?? '1');
                if ($bimestre !== '' && $b_num !== $bimestre) continue;

                $c_id = (int)($grade_row['course_id'] ?? 0);
                if ($c_id <= 0) $c_id = 'legacy_' . md5((string)($grade_row['curso'] ?? 'Curso'));
                $comp_id = (int)($grade_row['competencia_id'] ?? 0);

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
                        'peso' => floatval($grade_row['porcentaje']) / 100,
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
                    'peso' => floatval($grade_row['porcentaje']) / 100
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
            ksort($bucket_anual[$year_str]);

            foreach ($bucket_anual[$year_str] as $b_num => $cursos_data) {
                $cursos_promedios = [];
                $suma_promedios_cursos = 0;
                $cantidad_cursos = 0;
                foreach ($cursos_data as $curso_info) {
                    $suma_ponderada_curso = 0;
                    $competencias_con_nota = 0;
                    $competencias_curso = [];
                    foreach ($curso_info['competencias'] as $comp_data) {
                        if (count($comp_data['notas']) > 0) {
                            $suma_notas = 0;
                            foreach ($comp_data['notas'] as $nota) $suma_notas += $nota['numeric'];
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
                    foreach ($comps_bim_raw as $k => $c) $comps_bim[] = ['competencia_id' => strval($k), 'nombre' => $c['nombre'], 'peso' => $c['peso']];
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
                $pac = count($cd['promedios']) > 0 ? array_sum($cd['promedios']) / count($cd['promedios']) : 0;
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

        $current_year = 'N/A';
        foreach ($años_disponibles as $año_info) {
            if (!empty($año_info['es_activo'])) {
                $current_year = $año_info['año'];
                break;
            }
        }

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