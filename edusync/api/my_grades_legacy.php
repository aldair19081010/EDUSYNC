<?php
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../db_connect.php';

function legacy_reply($payload) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function legacy_has_table($conn, $table) {
    $safe = $conn->real_escape_string($table);
    $q = $conn->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}
function legacy_has_column($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}
function legacy_grade_numeric($grade) {
    $value = strtoupper(trim((string)$grade));
    if ($value === '') return 0.0;
    if ($value === 'C') return 10.0;
    if ($value === 'B') return 13.0;
    if ($value === 'A') return 17.0;
    if ($value === 'AD') return 20.0;
    return is_numeric(str_replace(',', '.', $value)) ? (float)str_replace(',', '.', $value) : 0.0;
}
function legacy_bimester($value) {
    $v = strtoupper(trim((string)$value));
    $compact = preg_replace('/\s+/', '', $v);
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

$dni = trim((string)($_GET['dni'] ?? ($_POST['dni'] ?? '')));
$bimestreFiltro = trim((string)($_GET['bimestre'] ?? ($_POST['bimestre'] ?? '')));
if ($bimestreFiltro !== '') $bimestreFiltro = legacy_bimester($bimestreFiltro);
if ($dni === '') legacy_reply(['status'=>'error','message'=>'DNI no recibido']);

$dniSafe = $conn->real_escape_string($dni);
$studentQ = $conn->query("SELECT id,id_no,name,nivel,grado,seccion,status,school_id FROM student WHERE id_no='$dniSafe' ORDER BY id DESC");
if (!$studentQ || $studentQ->num_rows === 0) legacy_reply(['status'=>'error','message'=>'Estudiante no encontrado']);

$studentIds = [];
$student = null;
while ($row = $studentQ->fetch_assoc()) {
    if ($student === null) $student = $row;
    $studentIds[] = (int)$row['id'];
}
$studentId = (int)$student['id'];
$schoolId = (int)($student['school_id'] ?? 1);
$studentName = trim((string)($student['name'] ?? ''));

if ($studentName !== '') {
    $nameSafe = $conn->real_escape_string($studentName);
    $q = $conn->query("SELECT id FROM student WHERE school_id=$schoolId AND name='$nameSafe' ORDER BY id DESC");
    while ($q && ($r = $q->fetch_assoc())) $studentIds[] = (int)$r['id'];
}
$studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
rsort($studentIds, SORT_NUMERIC);
$studentIdsSql = implode(',', $studentIds);
if ($studentIdsSql === '') $studentIdsSql = (string)$studentId;

if ($studentId && legacy_has_table($conn,'student_ef_list') && legacy_has_table($conn,'payments')) {
    $deudaSql = "SELECT SUM(CASE WHEN pendiente>0 THEN 1 ELSE 0 END) ultimas_con_deuda, COUNT(1) filas_consideradas, SUM(pendiente) total_pendiente
        FROM (SELECT ef.id,
            CASE WHEN (CASE WHEN ef.discounted_amount IS NOT NULL THEN ef.discounted_amount ELSE ef.total_fee END)=0 THEN 0
                 ELSE GREATEST((CASE WHEN ef.discounted_amount IS NOT NULL THEN ef.discounted_amount ELSE ef.total_fee END)-COALESCE(SUM(p.amount),0),0) END pendiente
            FROM student_ef_list ef LEFT JOIN payments p ON p.ef_id=ef.id
            WHERE ef.student_id=$studentId
            GROUP BY ef.id,ef.discounted_amount,ef.total_fee ORDER BY ef.id DESC LIMIT 2) t";
    $deudaRes = $conn->query($deudaSql);
    if ($deudaRes) {
        $deuda = $deudaRes->fetch_assoc();
        if ((int)($deuda['filas_consideradas'] ?? 0) >= 2 && (int)($deuda['ultimas_con_deuda'] ?? 0) >= 2) {
            legacy_reply([
                'status'=>'error','reason'=>'debt','message'=>'No es posible mostrar la información de notas por deudas.',
                'total_pendiente_ultimas'=>number_format((float)($deuda['total_pendiente'] ?? 0),2,'.',''),
                'dni'=>$dni,'alumno'=>$studentName
            ]);
        }
    }
}

$yearHasSchool = legacy_has_column($conn,'academic_year','school_id');
$yearHasStart = legacy_has_column($conn,'academic_year','start_date');
$yearHasEnd = legacy_has_column($conn,'academic_year','end_date');
$startSel = $yearHasStart ? 'start_date' : 'NULL AS start_date';
$endSel = $yearHasEnd ? 'end_date' : 'NULL AS end_date';
$yearSql = "SELECT id,year,description,is_active,$startSel,$endSel FROM academic_year" . ($yearHasSchool ? " WHERE school_id=$schoolId" : '') . " ORDER BY year DESC,id DESC";
$yearQ = $conn->query($yearSql);
if (!$yearQ) legacy_reply(['status'=>'error','message'=>'No se pudieron consultar los años académicos']);

$yearsGrouped = [];
$yearIdToLabel = [];
while ($y = $yearQ->fetch_assoc()) {
    $label = (string)$y['year'];
    if (!isset($yearsGrouped[$label])) {
        $yearsGrouped[$label] = [
            'año'=>$label,
            'descripcion'=>(string)($y['description'] ?? ''),
            'es_activo'=>false,
            'ids'=>[],
            'start_date'=>null,
            'end_date'=>null
        ];
    }
    $id = (int)$y['id'];
    $yearsGrouped[$label]['ids'][] = $id;
    $yearIdToLabel[$id] = $label;
    if ((int)$y['is_active'] === 1) $yearsGrouped[$label]['es_activo'] = true;
    if (!empty($y['start_date']) && ($yearsGrouped[$label]['start_date'] === null || $y['start_date'] < $yearsGrouped[$label]['start_date'])) $yearsGrouped[$label]['start_date'] = $y['start_date'];
    if (!empty($y['end_date']) && ($yearsGrouped[$label]['end_date'] === null || $y['end_date'] > $yearsGrouped[$label]['end_date'])) $yearsGrouped[$label]['end_date'] = $y['end_date'];
}
uksort($yearsGrouped, function($a,$b){ return strnatcmp((string)$b,(string)$a); });
$yearsAvailable = array_values($yearsGrouped);
$yearLabels = array_keys($yearsGrouped);

$fallbackYearByStudent = [];
foreach ($studentIds as $idx => $sid) {
    if (isset($yearLabels[$idx])) $fallbackYearByStudent[$sid] = $yearLabels[$idx];
}

$hasAreas = legacy_has_table($conn,'areas');
$hasComps = legacy_has_table($conn,'general_course_competencies');
$hasAcYear = legacy_has_column($conn,'academic_courses','academic_year_id');
$hasTcYear = legacy_has_column($conn,'teacher_courses','academic_year_id');
$hasEYear = legacy_has_column($conn,'evaluations','academic_year_id');
$hasCompYear = $hasComps && legacy_has_column($conn,'general_course_competencies','academic_year_id');
$hasBim = legacy_has_column($conn,'evaluations','bimestre');
$hasCreated = legacy_has_column($conn,'evaluations','created_at');
$hasDescription = legacy_has_column($conn,'evaluations','description');
$hasEgComp = legacy_has_column($conn,'evaluation_grades','competencia_id');

$areaJoin = $hasAreas ? 'LEFT JOIN areas a ON a.id=ac.area_id' : '';
$compJoin = $hasComps && $hasEgComp ? 'LEFT JOIN general_course_competencies gcc ON gcc.id=eg.competencia_id' : '';
$areaName = $hasAreas && legacy_has_column($conn,'areas','name') ? "COALESCE(a.name,'Área General')" : "'Área General'";
$areaColor = $hasAreas && legacy_has_column($conn,'areas','color') ? "COALESCE(a.color,'#6c757d')" : "'#6c757d'";
$areaDesc = $hasAreas && legacy_has_column($conn,'areas','description') ? "COALESCE(a.description,'')" : "''";
$compId = $hasEgComp ? 'COALESCE(eg.competencia_id,0)' : '0';
$compName = $hasComps ? "COALESCE(gcc.name,'Evaluación General')" : "'Evaluación General'";
$compPct = $hasComps && legacy_has_column($conn,'general_course_competencies','percentage') ? 'COALESCE(gcc.percentage,100)' : '100';
$tcYear = $hasTcYear ? 'tc.academic_year_id' : 'NULL';
$acYear = $hasAcYear ? 'ac.academic_year_id' : 'NULL';
$eYear = $hasEYear ? 'e.academic_year_id' : 'NULL';
$gccYear = $hasCompYear ? 'gcc.academic_year_id' : 'NULL';
$bimSel = $hasBim ? 'e.bimestre' : "'1'";
$createdSel = $hasCreated ? 'e.created_at' : 'NULL';
$obsSel = $hasDescription ? "COALESCE(e.description,'')" : "''";

$sql = "SELECT eg.student_id,e.id evaluation_id,e.title,eg.grade,$obsSel observacion,$bimSel bimestre,$createdSel created_at,
        COALESCE(tc.course_id,0) course_id,COALESCE(ac.name,e.title,'Curso') curso,
        $areaName area_nombre,$areaColor area_color,$areaDesc area_descripcion,
        $compId competencia_id,$compName competencia_nombre,$compPct porcentaje,
        $tcYear tc_year,$acYear ac_year,$eYear e_year,$gccYear comp_year
    FROM evaluation_grades eg
    INNER JOIN evaluations e ON e.id=eg.evaluation_id
    LEFT JOIN teacher_courses tc ON tc.id=e.teacher_course_id
    LEFT JOIN academic_courses ac ON ac.id=tc.course_id
    $areaJoin
    $compJoin
    WHERE eg.student_id IN ($studentIdsSql)
    ORDER BY e.id ASC";
$gradesQ = $conn->query($sql);
$debugErrors = [];
if (!$gradesQ) $debugErrors[] = 'Error SQL notas: ' . $conn->error;

$bucket = [];
if ($gradesQ) {
    while ($g = $gradesQ->fetch_assoc()) {
        $yearLabel = null;
        foreach (['tc_year','ac_year','e_year','comp_year'] as $yearField) {
            $yearId = (int)($g[$yearField] ?? 0);
            if ($yearId > 0 && isset($yearIdToLabel[$yearId])) {
                $yearLabel = $yearIdToLabel[$yearId];
                break;
            }
        }
        if ($yearLabel === null && !empty($g['created_at'])) {
            foreach ($yearsAvailable as $yearInfo) {
                if (!empty($yearInfo['start_date']) && !empty($yearInfo['end_date']) && $g['created_at'] >= $yearInfo['start_date'].' 00:00:00' && $g['created_at'] <= $yearInfo['end_date'].' 23:59:59') {
                    $yearLabel = $yearInfo['año'];
                    break;
                }
            }
            if ($yearLabel === null) {
                $createdYear = substr((string)$g['created_at'],0,4);
                if (isset($yearsGrouped[$createdYear])) $yearLabel = $createdYear;
            }
        }
        if ($yearLabel === null) {
            $sid = (int)$g['student_id'];
            if (isset($fallbackYearByStudent[$sid])) $yearLabel = $fallbackYearByStudent[$sid];
        }
        if ($yearLabel === null && count($yearsAvailable) === 1) $yearLabel = $yearsAvailable[0]['año'];
        if ($yearLabel === null) continue;

        $bim = legacy_bimester($g['bimestre'] ?? '1');
        if ($bimestreFiltro !== '' && $bim !== $bimestreFiltro) continue;

        $courseId = (int)($g['course_id'] ?? 0);
        $courseName = (string)($g['curso'] ?? 'Curso');
        $courseKey = $courseId > 0 ? (string)$courseId : 'name:' . $courseName;
        $cid = (int)($g['competencia_id'] ?? 0);
        $pct = (float)($g['porcentaje'] ?? 100);
        if ($pct < 0) $pct = 0;
        $weight = $pct / 100;

        if (!isset($bucket[$yearLabel][$bim][$courseKey])) {
            $bucket[$yearLabel][$bim][$courseKey] = [
                'nombre'=>$courseName,
                'area'=>[
                    'nombre'=>(string)($g['area_nombre'] ?? 'Área General'),
                    'color'=>(string)($g['area_color'] ?? '#6c757d'),
                    'descripcion'=>(string)($g['area_descripcion'] ?? '')
                ],
                'competencias'=>[]
            ];
        }
        if (!isset($bucket[$yearLabel][$bim][$courseKey]['competencias'][$cid])) {
            $bucket[$yearLabel][$bim][$courseKey]['competencias'][$cid] = [
                'nombre'=>(string)($g['competencia_nombre'] ?? 'Evaluación General'),
                'peso'=>$weight,
                'notas'=>[],
                'evaluaciones'=>[]
            ];
        }
        $numeric = legacy_grade_numeric($g['grade']);
        $bucket[$yearLabel][$bim][$courseKey]['competencias'][$cid]['notas'][] = $numeric;
        $bucket[$yearLabel][$bim][$courseKey]['competencias'][$cid]['evaluaciones'][] = [
            'curso'=>$courseName,
            'evaluacion'=>(string)($g['title'] ?: 'Evaluación'),
            'nota'=>$g['grade'],
            'observacion'=>(string)($g['observacion'] ?? '')
        ];
    }
}

$yearsWithGrades = [];
foreach ($yearsAvailable as $yearInfo) {
    $label = $yearInfo['año'];
    if (empty($bucket[$label])) continue;
    ksort($bucket[$label], SORT_NATURAL);
    $bimestres = [];
    $annualSum = 0.0;
    $annualCount = 0;
    $annualCourses = [];

    foreach ($bucket[$label] as $bim => $coursesData) {
        $coursesOut = [];
        $bimCompetencies = [];
        $bimSum = 0.0;
        $courseCount = 0;

        foreach ($coursesData as $courseInfo) {
            $courseWeighted = 0.0;
            $courseCompsOut = [];
            foreach ($courseInfo['competencias'] as $cid => $comp) {
                if (!$comp['notas']) continue;
                $avg = array_sum($comp['notas']) / count($comp['notas']);
                $weighted = $avg * $comp['peso'];
                $courseWeighted += $weighted;
                $entry = [
                    'nombre'=>$comp['nombre'],
                    'competencia'=>$comp['nombre'],
                    'peso'=>$comp['peso'],
                    'promedio_simple'=>(string)(int)round($avg),
                    'promedio'=>(string)round($weighted,2),
                    'ponderado'=>(string)round($weighted,2),
                    'evaluaciones'=>$comp['evaluaciones']
                ];
                $courseCompsOut[$cid] = $entry;
                $bimEntry = $entry;
                $bimEntry['peso'] = $comp['peso'] * 100;
                $bimCompetencies[] = $bimEntry;
            }
            if (!$courseCompsOut) continue;
            $courseOut = [
                'curso'=>$courseInfo['nombre'],
                'area'=>$courseInfo['area'],
                'promedio'=>(string)(int)round($courseWeighted),
                'competencias'=>$courseCompsOut
            ];
            $coursesOut[] = $courseOut;
            $bimSum += $courseWeighted;
            $courseCount++;

            $cn = $courseInfo['nombre'];
            if (!isset($annualCourses[$cn])) $annualCourses[$cn] = ['area'=>$courseInfo['area'],'promedios'=>[],'bimestres'=>[]];
            $annualCourses[$cn]['promedios'][] = (float)$courseOut['promedio'];
            $annualCourses[$cn]['bimestres'][(string)$bim] = (float)$courseOut['promedio'];
        }
        if (!$coursesOut) continue;
        $bimAvg = $courseCount > 0 ? $bimSum / $courseCount : 0;
        $bimestres[] = [
            'numero'=>(string)$bim,
            'competencias'=>$bimCompetencies,
            'cursos'=>$coursesOut,
            'promedio_bimestre'=>(string)(int)round($bimAvg)
        ];
        $annualSum += $bimAvg;
        $annualCount++;
    }
    if (!$bimestres) continue;

    $annualCoursesOut = [];
    foreach ($annualCourses as $name => $info) {
        $avg = $info['promedios'] ? array_sum($info['promedios']) / count($info['promedios']) : 0;
        $annualCoursesOut[] = [
            'curso'=>$name,
            'area'=>$info['area'],
            'promedio_anual'=>(string)(int)round($avg),
            'detalle_bimestres'=>$info['bimestres']
        ];
    }
    $yearsWithGrades[] = [
        'año'=>$label,
        'descripcion'=>$yearInfo['descripcion'],
        'es_activo'=>$yearInfo['es_activo'],
        'bimestres'=>$bimestres,
        'promedio_anual'=>(string)(int)round($annualCount ? $annualSum/$annualCount : 0),
        'promedios_por_curso'=>$annualCoursesOut
    ];
}

$currentYear = 'N/A';
foreach ($yearsAvailable as $yearInfo) {
    if ($yearInfo['es_activo']) { $currentYear = $yearInfo['año']; break; }
}

legacy_reply([
    'status'=>'ok',
    'data'=>[
        'alumno'=>$studentName,
        'dni'=>$dni,
        'nivel'=>(string)($student['nivel'] ?? ''),
        'grado'=>(string)($student['grado'] ?? ''),
        'seccion'=>(string)($student['seccion'] ?? ''),
        'anio_academico_actual'=>$currentYear,
        'años_disponibles'=>$yearsAvailable,
        'años_academicos'=>$yearsWithGrades,
        'total_años_con_notas'=>count($yearsWithGrades),
        'debug_errors'=>$debugErrors
    ]
]);
