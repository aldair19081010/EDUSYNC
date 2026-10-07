<?php
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('session.save_path', __DIR__ . '/../tmp');
if (!is_dir(__DIR__ . '/../tmp')) @mkdir(__DIR__ . '/../tmp');
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../db_connect.php';
require_once __DIR__ . '/grade_debt_guard.php';

function grades_reply($status, $message = '', $data = [], $extra = []) {
    $payload = array_merge(['status' => $status], $extra);
    if ($message !== '') $payload['message'] = $message;
    if ($data !== []) $payload['data'] = $data;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function grades_is_local_request() {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $addr = (string)($_SERVER['SERVER_ADDR'] ?? '');
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return strpos($host, 'localhost') !== false
        || strpos($host, '127.0.0.1') !== false
        || in_array($addr, ['127.0.0.1', '::1'], true)
        || in_array($remote, ['127.0.0.1', '::1'], true);
}
function grades_has_table($db, $table) {
    $safe = $db->real_escape_string((string)$table);
    $q = $db->query("SHOW TABLES LIKE '{$safe}'");
    return $q && $q->num_rows > 0;
}
function grades_has_column($db, $table, $column) {
    $tableSafe = str_replace('`', '', (string)$table);
    $columnSafe = $db->real_escape_string((string)$column);
    $q = $db->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
    return $q && $q->num_rows > 0;
}
function is_letter_grade($grade) {
    return in_array(strtoupper(trim((string)$grade)), ['AD', 'A', 'B', 'C'], true);
}
function letter_to_numeric_for_calc($grade) {
    // Misma equivalencia usada por la ficha individual de
    // grades_report_table.php.
    switch (strtoupper(trim((string)$grade))) {
        case 'AD': return 19.0;
        case 'A': return 15.5;
        case 'B': return 12.0;
        case 'C': return 5.0;
        default: return 0.0;
    }
}
function get_grade_for_calculation($grade) {
    if ($grade === null || trim((string)$grade) === '') return null;
    $value = trim((string)$grade);
    if (is_letter_grade($value)) return letter_to_numeric_for_calc($value);
    if (!is_numeric(str_replace(',', '.', $value))) return null;
    $numeric = (float)str_replace(',', '.', $value);
    return max(0.0, min(20.0, $numeric));
}
function numeric_to_level($value) {
    $value = (float)$value;
    if ($value >= 18) return 'AD';
    if ($value >= 14) return 'A';
    if ($value >= 11) return 'B';
    return 'C';
}
function normalize_bimester($value) {
    $raw = strtoupper(trim((string)$value));
    if ($raw === '') return '1';
    $compact = preg_replace('/\s+/', '', $raw);
    $map = [
        'I' => '1', 'II' => '2', 'III' => '3', 'IV' => '4',
        '1' => '1', '2' => '2', '3' => '3', '4' => '4',
        '1RO' => '1', '1ER' => '1', 'PRIMERO' => '1', 'PRIMER' => '1',
        '2DO' => '2', 'SEGUNDO' => '2', '3RO' => '3', 'TERCERO' => '3',
        '4TO' => '4', 'CUARTO' => '4'
    ];
    if (isset($map[$compact])) return $map[$compact];
    if (preg_match('/(?:BIMESTRE|BIM|B)?([1-4])/', $compact, $m)) return $m[1];
    return '1';
}
function scale_type($letterCount, $numericCount) {
    if ($letterCount > 0 && $numericCount === 0) return 'literal';
    if ($numericCount > 0 && $letterCount === 0) return 'numerica';
    if ($letterCount > 0 && $numericCount > 0) return 'mixta';
    return 'sin_datos';
}
function safe_int_ids($ids) {
    return array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) { return $id > 0; })));
}

try {
    foreach (['student','academic_year','evaluation_grades','evaluations','teacher_courses','academic_courses'] as $requiredTable) {
        if (!grades_has_table($conn, $requiredTable)) {
            throw new RuntimeException("Falta la tabla requerida: {$requiredTable}");
        }
    }

    $sessionStudentId = (int)($_SESSION['student_id'] ?? 0);
    $sessionSchoolId = (int)($_SESSION['student_school_id'] ?? ($_SESSION['login_school_id'] ?? 0));
    $sessionIsStudent = !empty($_SESSION['student_logged_in']) || (int)($_SESSION['login_type'] ?? 0) === 4;
    $requestedDni = trim((string)($_GET['dni'] ?? ($_POST['dni'] ?? '')));
    $requestedSchoolId = (int)($_GET['school_id'] ?? ($_POST['school_id'] ?? 0));
    $bimestreFilter = trim((string)($_GET['bimestre'] ?? ($_POST['bimestre'] ?? '')));
    if ($bimestreFilter !== '') $bimestreFilter = normalize_bimester($bimestreFilter);

    $student = null;
    $authMode = 'legacy_dni';
    if ($sessionIsStudent && $sessionStudentId > 0) {
        $authMode = 'session';
        if ($sessionSchoolId > 0) {
            $stmt = $conn->prepare('SELECT id, id_no, name, nivel, grado, seccion, status, school_id FROM student WHERE id = ? AND school_id = ? LIMIT 1');
            if (!$stmt) throw new RuntimeException('Consulta estudiante: ' . $conn->error);
            $stmt->bind_param('ii', $sessionStudentId, $sessionSchoolId);
        } else {
            $stmt = $conn->prepare('SELECT id, id_no, name, nivel, grado, seccion, status, school_id FROM student WHERE id = ? LIMIT 1');
            if (!$stmt) throw new RuntimeException('Consulta estudiante: ' . $conn->error);
            $stmt->bind_param('i', $sessionStudentId);
        }
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        if ($requestedDni === '') grades_reply('error', 'DNI no recibido');
        if ($requestedSchoolId > 0) {
            $stmt = $conn->prepare('SELECT id, id_no, name, nivel, grado, seccion, status, school_id FROM student WHERE id_no = ? AND school_id = ? ORDER BY id DESC LIMIT 1');
            if (!$stmt) throw new RuntimeException('Consulta estudiante por DNI: ' . $conn->error);
            $stmt->bind_param('si', $requestedDni, $requestedSchoolId);
        } else {
            $stmt = $conn->prepare('SELECT id, id_no, name, nivel, grado, seccion, status, school_id FROM student WHERE id_no = ? ORDER BY id DESC LIMIT 1');
            if (!$stmt) throw new RuntimeException('Consulta estudiante por DNI: ' . $conn->error);
            $stmt->bind_param('s', $requestedDni);
        }
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$student) grades_reply('error', 'Estudiante no encontrado');

    $studentId = (int)$student['id'];
    $studentSchoolId = (int)$student['school_id'];
    $studentDni = trim((string)$student['id_no']);
    $studentName = trim((string)$student['name']);
    // Igual que la ficha individual web: trabajar con el ID exacto
    // del estudiante seleccionado y no mezclar otros registros por nombre/DNI.
    $studentIdsSql = (string)$studentId;

    $debt = grade_debt_blocks_grades($conn, $studentId);
    if (!empty($debt['blocked'])) {
        grades_reply(
            'error',
            (string)($debt['message'] ?? 'Las calificaciones están temporalmente restringidas por obligaciones de pago.'),
            [],
            [
                'reason' => 'debt',
                'debt_count' => (int)$debt['count'],
                'debt_scope' => (string)($debt['scope'] ?? 'overdue'),
                'minimum_debt_concepts' => (int)($debt['minimum_concepts'] ?? 2),
                'total_pendiente_ultimas' => number_format((float)$debt['total'], 2, '.', '')
            ]
        );
    }

    $yearHasSchool = grades_has_column($conn, 'academic_year', 'school_id');
    $yearHasStart = grades_has_column($conn, 'academic_year', 'start_date');
    $yearHasEnd = grades_has_column($conn, 'academic_year', 'end_date');
    $startSelect = $yearHasStart ? 'start_date' : 'NULL AS start_date';
    $endSelect = $yearHasEnd ? 'end_date' : 'NULL AS end_date';

    if ($yearHasSchool) {
        $stmt = $conn->prepare("SELECT id, year, description, is_active, $startSelect, $endSelect FROM academic_year WHERE school_id = ? ORDER BY year DESC, id DESC");
        if (!$stmt) throw new RuntimeException('Consulta años académicos: ' . $conn->error);
        $stmt->bind_param('i', $studentSchoolId);
        $stmt->execute();
        $yearsResult = $stmt->get_result();
    } else {
        $stmt = null;
        $yearsResult = $conn->query("SELECT id, year, description, is_active, $startSelect, $endSelect FROM academic_year ORDER BY year DESC, id DESC");
        if (!$yearsResult) throw new RuntimeException('Consulta años académicos: ' . $conn->error);
    }

    $yearsGrouped = [];
    $yearIdToLabel = [];
    while ($year = $yearsResult->fetch_assoc()) {
        $label = (string)$year['year'];
        if (!isset($yearsGrouped[$label])) {
            $yearsGrouped[$label] = ['año'=>$label,'descripcion'=>(string)($year['description'] ?? ''),'es_activo'=>false,'ids'=>[],'start_date'=>null,'end_date'=>null];
        }
        $yearId = (int)$year['id'];
        $yearsGrouped[$label]['ids'][] = $yearId;
        $yearIdToLabel[$yearId] = $label;
        if ((int)$year['is_active'] === 1) $yearsGrouped[$label]['es_activo'] = true;
        if (!empty($year['start_date']) && ($yearsGrouped[$label]['start_date'] === null || $year['start_date'] < $yearsGrouped[$label]['start_date'])) $yearsGrouped[$label]['start_date'] = $year['start_date'];
        if (!empty($year['end_date']) && ($yearsGrouped[$label]['end_date'] === null || $year['end_date'] > $yearsGrouped[$label]['end_date'])) $yearsGrouped[$label]['end_date'] = $year['end_date'];
    }
    if ($stmt) $stmt->close();
    uksort($yearsGrouped, function ($a, $b) { return strnatcmp((string)$b, (string)$a); });
    $yearsAvailableInternal = array_values($yearsGrouped);

    $hasAreas = grades_has_table($conn, 'areas');
    $hasCompetencies = grades_has_table($conn, 'general_course_competencies');
    $hasEvaluationCompetencies = grades_has_table($conn, 'evaluation_competencias');
    $hasGradeCompetency = grades_has_column($conn, 'evaluation_grades', 'competencia_id');

    $areaJoin = $hasAreas ? 'LEFT JOIN areas a ON a.id = ac.area_id' : '';

    if ($hasEvaluationCompetencies) {
        $evaluationCompetencyJoin = $hasGradeCompetency
            ? 'LEFT JOIN evaluation_competencias ec ON ec.evaluation_id = e.id AND (COALESCE(eg.competencia_id,0) = 0 OR ec.competencia_id = eg.competencia_id)'
            : 'LEFT JOIN evaluation_competencias ec ON ec.evaluation_id = e.id';
    } else {
        $evaluationCompetencyJoin = '';
    }

    if ($hasCompetencies) {
        if ($hasEvaluationCompetencies && $hasGradeCompetency) {
            $competencyJoin = 'LEFT JOIN general_course_competencies c ON c.id = COALESCE(NULLIF(eg.competencia_id,0), ec.competencia_id)';
        } elseif ($hasGradeCompetency) {
            $competencyJoin = 'LEFT JOIN general_course_competencies c ON c.id = eg.competencia_id';
        } elseif ($hasEvaluationCompetencies) {
            $competencyJoin = 'LEFT JOIN general_course_competencies c ON c.id = ec.competencia_id';
        } else {
            $competencyJoin = '';
        }
    } else {
        $competencyJoin = '';
    }
    $areaNameSelect = $hasAreas && grades_has_column($conn, 'areas', 'name') ? "COALESCE(a.name, 'Área General')" : "'Área General'";
    $areaColorSelect = $hasAreas && grades_has_column($conn, 'areas', 'color') ? "COALESCE(a.color, '#6c757d')" : "'#6c757d'";
    $areaDescriptionSelect = $hasAreas && grades_has_column($conn, 'areas', 'description') ? 'a.description' : "''";
    $compNameSelect = $hasCompetencies && grades_has_column($conn, 'general_course_competencies', 'name') ? "COALESCE(c.name, 'Evaluación General')" : "'Evaluación General'";
    $compPercentageSelect = $hasCompetencies && grades_has_column($conn, 'general_course_competencies', 'percentage') ? 'COALESCE(c.percentage, 100)' : '100';
    $egCompSelect = $hasGradeCompetency
        ? ($hasEvaluationCompetencies
            ? 'COALESCE(NULLIF(eg.competencia_id,0), ec.competencia_id, 0)'
            : 'COALESCE(eg.competencia_id, 0)')
        : ($hasEvaluationCompetencies ? 'COALESCE(ec.competencia_id, 0)' : '0');
    $tcYearSelect = grades_has_column($conn, 'teacher_courses', 'academic_year_id') ? 'tc.academic_year_id' : 'NULL';
    $eYearSelect = grades_has_column($conn, 'evaluations', 'academic_year_id') ? 'e.academic_year_id' : 'NULL';
    $bimSelect = grades_has_column($conn, 'evaluations', 'bimestre') ? 'e.bimestre' : "'1'";
    $createdSelect = grades_has_column($conn, 'evaluations', 'created_at') ? 'e.created_at' : 'NULL';
    $obsSelect = grades_has_column($conn, 'evaluations', 'description') ? 'e.description' : "''";
    $courseNameSelect = grades_has_column($conn, 'academic_courses', 'name') ? "COALESCE(ac.name, e.title, 'Curso')" : "COALESCE(e.title, 'Curso')";
    $courseIdSelect = grades_has_column($conn, 'teacher_courses', 'course_id') ? 'COALESCE(tc.course_id, 0)' : '0';

    $sql = "SELECT e.id AS evaluation_id, e.title, $obsSelect AS observacion, $bimSelect AS bimestre, $createdSelect AS created_at,
                   eg.grade, $egCompSelect AS competencia_id,
                   $compNameSelect AS competencia_nombre,
                   $compPercentageSelect AS porcentaje,
                   $courseIdSelect AS course_id,
                   $courseNameSelect AS curso,
                   $areaNameSelect AS area_nombre,
                   $areaColorSelect AS area_color,
                   $areaDescriptionSelect AS area_descripcion,
                   $tcYearSelect AS tc_year, $eYearSelect AS e_year
            FROM evaluation_grades eg
            INNER JOIN evaluations e ON e.id = eg.evaluation_id
            INNER JOIN teacher_courses tc ON tc.id = e.teacher_course_id
            INNER JOIN academic_courses ac ON ac.id = tc.course_id
            $areaJoin
            $evaluationCompetencyJoin
            $competencyJoin
            WHERE eg.student_id IN ($studentIdsSql)
              AND tc.school_id = $studentSchoolId
              AND ac.school_id = $studentSchoolId
            ORDER BY e.id ASC";
    $gradesResult = $conn->query($sql);
    if (!$gradesResult) throw new RuntimeException('Consulta calificaciones: ' . $conn->error);

    $bucket = [];
    $usedCompetencies = [];
    while ($row = $gradesResult->fetch_assoc()) {
        // La ficha individual filtra por teacher_courses.academic_year_id.
        // No inferimos el año por fecha de creación ni por otros campos.
        $yearLabel = null;
        $tcYear = (int)($row['tc_year'] ?? 0);
        if ($tcYear > 0 && isset($yearIdToLabel[$tcYear])) {
            $yearLabel = $yearIdToLabel[$tcYear];
        }
        if ($yearLabel === null) continue;

        $bim = normalize_bimester($row['bimestre'] ?? '');
        if ($bimestreFilter !== '' && $bim !== $bimestreFilter) continue;
        $courseId = (int)($row['course_id'] ?? 0);
        if ($courseId <= 0) $courseId = -1 * max(1, (int)($row['evaluation_id'] ?? 1));
        $compId = (int)($row['competencia_id'] ?? 0);

        if (!isset($bucket[$yearLabel][$bim][$courseId])) {
            $bucket[$yearLabel][$bim][$courseId] = [
                'nombre'=>(string)$row['curso'],
                'area'=>['nombre'=>(string)$row['area_nombre'],'color'=>(string)$row['area_color'],'descripcion'=>(string)($row['area_descripcion'] ?? '')],
                'competencias'=>[]
            ];
        }
        if (!isset($bucket[$yearLabel][$bim][$courseId]['competencias'][$compId])) {
            $bucket[$yearLabel][$bim][$courseId]['competencias'][$compId] = [
                'nombre'=>(string)$row['competencia_nombre'],
                'peso'=>max(0.0, (float)$row['porcentaje']/100.0),
                'notas'=>[]
            ];
        }
        $rawGrade = trim((string)$row['grade']);
        $numericGrade = get_grade_for_calculation($rawGrade);
        if ($numericGrade === null) continue;
        $gradeType = is_letter_grade($rawGrade) ? 'literal' : 'numerica';
        $evaluation = [
            'evaluacion'=>(string)($row['title'] ?: 'Evaluación'),
            'titulo'=>(string)($row['title'] ?: 'Evaluación'),
            'nota'=>$rawGrade,
            'numeric'=>$numericGrade,
            'tipo'=>$gradeType,
            'observacion'=>(string)($row['observacion'] ?? '')
        ];
        $bucket[$yearLabel][$bim][$courseId]['competencias'][$compId]['notas'][] = $evaluation;
        if (!isset($usedCompetencies[$yearLabel][$bim][$compId])) {
            $usedCompetencies[$yearLabel][$bim][$compId] = ['nombre'=>(string)$row['competencia_nombre'],'peso'=>max(0.0,(float)$row['porcentaje']/100.0)];
        }
    }

    $yearsWithGrades = [];
    foreach ($yearsAvailableInternal as $yearInfo) {
        $yearLabel = $yearInfo['año'];
        if (empty($bucket[$yearLabel])) continue;
        ksort($bucket[$yearLabel], SORT_NATURAL);
        $bimestersExport = [];
        $yearBimSum = 0.0;
        $yearBimCount = 0;

        foreach ($bucket[$yearLabel] as $bimNumber => $coursesData) {
            $coursesExport = [];
            $bimCourseSum = 0.0;
            $bimCourseCount = 0;
            $bimLetterCount = 0;
            $bimNumericCount = 0;

            foreach ($coursesData as $courseInfo) {
                $competenciesExport = [];
                $weightedSum = 0.0;
                $evaluatedWeight = 0.0;
                $fallbackSum = 0.0;
                $fallbackCount = 0;
                $courseLetterCount = 0;
                $courseNumericCount = 0;

                foreach ($courseInfo['competencias'] as $compData) {
                    if (empty($compData['notas'])) continue;
                    $sum = 0.0; $count = 0; $compLetterCount = 0; $compNumericCount = 0;
                    foreach ($compData['notas'] as $note) {
                        $sum += (float)$note['numeric']; $count++;
                        if (($note['tipo'] ?? '') === 'literal') $compLetterCount++; else $compNumericCount++;
                    }
                    if ($count === 0) continue;
                    $compAverage = $sum/$count;
                    $weight = (float)$compData['peso'];
                    if ($weight > 0) { $weightedSum += $compAverage*$weight; $evaluatedWeight += $weight; }
                    $fallbackSum += $compAverage; $fallbackCount++;
                    $courseLetterCount += $compLetterCount; $courseNumericCount += $compNumericCount;
                    $compScale = scale_type($compLetterCount,$compNumericCount);
                    $compAverageFormatted = number_format($compAverage, 2, '.', '');
                    $competenciesExport[] = [
                        'competencia'=>$compData['nombre'],'nombre'=>$compData['nombre'],'peso'=>$weight,
                        'promedio'=>$compAverageFormatted,'promedio_simple'=>$compAverageFormatted,
                        'nivel_logro'=>numeric_to_level($compAverage),
                        'resultado'=>$compAverageFormatted,
                        'escala'=>$compScale,'notas'=>$compData['notas'],'evaluaciones'=>$compData['notas']
                    ];
                }
                if ($fallbackCount === 0) continue;

                // La ficha individual NO renormaliza por el porcentaje
                // evaluado. Cada competencia aporta promedio * porcentaje/100.
                // Si no existen pesos configurados, conserva el promedio simple
                // como fallback, equivalente al reporte sin competencias.
                $courseAverage = $weightedSum;
                $courseScale = scale_type($courseLetterCount,$courseNumericCount);
                $courseLevel = numeric_to_level($courseAverage);
                $courseAverageFormatted = number_format($courseAverage, 2, '.', '');
                $coursesExport[] = [
                    'curso'=>$courseInfo['nombre'],'area'=>$courseInfo['area'],'promedio'=>$courseAverageFormatted,
                    'nivel_logro'=>$courseLevel,'resultado'=>$courseAverageFormatted,
                    'escala'=>$courseScale,'peso_evaluado'=>round($evaluatedWeight*100,2),'competencias'=>$competenciesExport
                ];
                $bimCourseSum += $courseAverage; $bimCourseCount++;
                $bimLetterCount += $courseLetterCount; $bimNumericCount += $courseNumericCount;
            }
            if ($bimCourseCount === 0) continue;
            usort($coursesExport,function($a,$b){return strcasecmp($a['curso'],$b['curso']);});
            $bimAverage = $bimCourseSum/$bimCourseCount;
            $bimScale = scale_type($bimLetterCount,$bimNumericCount);
            $attentionCount = 0;
            foreach ($coursesExport as $course) if (in_array($course['nivel_logro'],['B','C'],true)) $attentionCount++;
            $compsExport = [];
            foreach (($usedCompetencies[$yearLabel][$bimNumber] ?? []) as $id=>$comp) $compsExport[] = ['competencia_id'=>(string)$id,'nombre'=>$comp['nombre'],'peso'=>$comp['peso']];
            $bimestersExport[] = [
                'numero'=>(string)$bimNumber,'publicado'=>true,'competencias'=>$compsExport,'cursos'=>$coursesExport,
                'promedio_bimestre'=>number_format($bimAverage,2,'.',''),'nivel_logro'=>numeric_to_level($bimAverage),
                'resultado'=>number_format($bimAverage,2,'.',''),
                'escala'=>$bimScale,'cursos_evaluados'=>$bimCourseCount,'cursos_por_reforzar'=>$attentionCount
            ];
            $yearBimSum += $bimAverage; $yearBimCount++;
        }
        if ($yearBimCount === 0) continue;
        usort($bimestersExport,function($a,$b){return (int)$a['numero']<=>(int)$b['numero'];});
        $yearAverage = $yearBimSum/$yearBimCount;

        $annualCoursesMap = [];
        foreach ($bimestersExport as $bim) {
            foreach ($bim['cursos'] as $course) {
                $name = $course['curso'];
                if (!isset($annualCoursesMap[$name])) $annualCoursesMap[$name] = ['area'=>$course['area'],'promedios'=>[],'bimestres'=>[],'niveles'=>[],'escalas'=>[]];
                $numeric = (float)$course['promedio'];
                $annualCoursesMap[$name]['promedios'][] = $numeric;
                $annualCoursesMap[$name]['bimestres'][$bim['numero']] = $numeric;
                $annualCoursesMap[$name]['niveles'][$bim['numero']] = $course['nivel_logro'];
                $annualCoursesMap[$name]['escalas'][$bim['numero']] = $course['escala'];
            }
        }
        $annualCourses = [];
        foreach ($annualCoursesMap as $name=>$courseData) {
            $avg = count($courseData['promedios']) ? array_sum($courseData['promedios'])/count($courseData['promedios']) : 0;
            $bims = $courseData['bimestres']; ksort($bims,SORT_NATURAL); $values = array_values($bims);
            $trend = 'sin_datos';
            if (count($values)>=2) { $diff=$values[count($values)-1]-$values[count($values)-2]; $trend=$diff>.5?'sube':($diff<-.5?'baja':'estable'); }
            $scales = array_values(array_unique($courseData['escalas']));
            $annualScale = count($scales)===1?$scales[0]:'mixta';
            $annualCourses[] = [
                'curso'=>$name,'area'=>$courseData['area'],'promedio_anual'=>number_format($avg,2,'.',''),'nivel_logro'=>numeric_to_level($avg),
                'resultado'=>number_format($avg,2,'.',''),'escala'=>$annualScale,
                'detalle_bimestres'=>$bims,'detalle_niveles'=>$courseData['niveles'],'tendencia'=>$trend
            ];
        }
        usort($annualCourses,function($a,$b){return strcasecmp($a['curso'],$b['curso']);});
        $yearScales = [];
        foreach ($bimestersExport as $bim) $yearScales[] = $bim['escala'];
        $yearScales = array_values(array_unique($yearScales));
        $yearScale = count($yearScales)===1?$yearScales[0]:'mixta';
        $yearsWithGrades[] = [
            'año'=>$yearInfo['año'],'descripcion'=>$yearInfo['descripcion'],'es_activo'=>$yearInfo['es_activo'],'bimestres'=>$bimestersExport,
            'promedio_anual'=>number_format($yearAverage,2,'.',''),'nivel_logro'=>numeric_to_level($yearAverage),
            'resultado'=>number_format($yearAverage,2,'.','),
            'escala'=>$yearScale,'promedios_por_curso'=>$annualCourses
        ];
    }

    $yearsAvailable = [];
    $currentYear = 'N/A';
    foreach ($yearsAvailableInternal as $yearInfo) {
        $yearsAvailable[] = ['año'=>$yearInfo['año'],'descripcion'=>$yearInfo['descripcion'],'es_activo'=>$yearInfo['es_activo']];
        if ($yearInfo['es_activo'] && $currentYear === 'N/A') $currentYear = $yearInfo['año'];
    }
    grades_reply('ok','',[
        'alumno'=>$studentName,'dni'=>$studentDni,'nivel'=>(string)($student['nivel'] ?? ''),'grado'=>(string)($student['grado'] ?? ''),'seccion'=>(string)($student['seccion'] ?? ''),
        'anio_academico_actual'=>$currentYear,'años_disponibles'=>$yearsAvailable,'años_academicos'=>$yearsWithGrades,
        'total_años_con_notas'=>count($yearsWithGrades),'auth_mode'=>$authMode,
        'access_policy'=>[
            'reason'=>(string)($debt['reason'] ?? 'allowed'),
            'message'=>(string)($debt['message'] ?? ''),
            'grace_days'=>(int)($debt['grace_days'] ?? 0),
            'grace_count'=>(int)($debt['grace_count'] ?? 0),
            'temporary_access_until'=>$debt['temporary_access_until'] ?? null
        ]
    ]);
} catch (Throwable $e) {
    error_log('EduSync my_grades.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    $extra = [];
    if (grades_is_local_request()) {
        $extra['detail'] = $e->getMessage();
        $extra['line'] = $e->getLine();
    }
    grades_reply('error', 'No se pudieron cargar las calificaciones. Intenta nuevamente.', [], $extra);
}
?>