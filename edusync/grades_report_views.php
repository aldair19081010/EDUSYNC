<?php
// Vistas avanzadas del reporte de notas.
// La ficha individual original se mantiene en grades_report_table.php.
if (($_POST['report_view'] ?? '') === 'student' && empty($_POST['data_mode'])) {
    $_POST['show_avg'] = '1';
    require __DIR__ . '/grades_report_table.php';
    return;
}

include 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$loginType = (int)($_SESSION['login_type'] ?? 0);
$teacherId = (int)($_SESSION['login_teacher_id'] ?? 0);
require_once __DIR__ . '/includes/grades_report_access.php';
$reportDirector = grades_report_is_director($conn);
if (!in_array($loginType, [1, 2], true) || ($loginType === 2 && $teacherId <= 0 && !$reportDirector)) {
    http_response_code(403);
    exit('No tiene permisos para consultar este reporte.');
}

$view = $_POST['report_view'] ?? 'consolidated';
$dataMode = $_POST['data_mode'] ?? '';
$yearId = (int)($_POST['academic_year_id'] ?? 0);
$courseId = (int)($_POST['course_id'] ?? 0);
$studentId = (int)($_POST['student_id'] ?? 0);
$bimester = (int)($_POST['bimestre'] ?? 0);
$level = trim($_POST['level'] ?? '');
$grade = trim($_POST['grado'] ?? '');
$section = trim($_POST['seccion'] ?? '');

if (!$schoolId || !$yearId || $level === '' || $grade === '' || $section === '') {
    if ($dataMode === 'trend') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 0, 'message' => 'Filtros incompletos.']);
        exit;
    }
    exit('<div class="alert alert-info mb-0"><i class="fas fa-filter mr-2"></i>Seleccione año, nivel, grado y sección para generar el reporte.</div>');
}

function report_numeric_grade($value) {
    $text = strtoupper(trim((string)$value));
    if ($text === '') return null;
    $letters = ['C' => 5, 'B' => 12, 'A' => 15.5, 'AD' => 19];
    return array_key_exists($text, $letters) ? $letters[$text] : (is_numeric($text) ? (float)$text : null);
}
function report_level_key($value) {
    return strtolower(str_replace(' ', '', trim($value)));
}
function report_bind($stmt, $types, &$params) {
    $refs = [$types];
    foreach ($params as &$param) $refs[] = &$param;
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}
function report_average($values) {
    $valid = array_values(array_filter($values, static function($v) { return $v !== null; }));
    return count($valid) ? array_sum($valid) / count($valid) : null;
}
function report_round_grade($value) {
    return $value === null ? null : (int)round((float)$value);
}
function report_status($value) {
    $score = report_round_grade($value);
    if ($score === null) return ['letter' => '—', 'label' => 'Sin datos', 'class' => 'secondary'];
    if ($score >= 18) return ['letter' => 'AD', 'label' => 'Logro destacado', 'class' => 'success'];
    if ($score >= 14) return ['letter' => 'A', 'label' => 'Logro esperado', 'class' => 'success'];
    if ($score >= 11) return ['letter' => 'B', 'label' => 'En proceso', 'class' => 'warning'];
    return ['letter' => 'C', 'label' => 'En inicio', 'class' => 'danger'];
}
function report_fmt($value) {
    $rounded = report_round_grade($value);
    return $rounded === null ? '—' : (string)$rounded;
}
function report_competency_stats($course, $bi) {
    $result = ['B' => 0, 'C' => 0, 'A' => 0, 'AD' => 0, 'rows' => []];
    foreach (($course['competencies'][$bi] ?? []) as $compId => $comp) {
        $avg = $comp['avg'] ?? null;
        if ($avg === null) continue;
        $status = report_status($avg);
        if (isset($result[$status['letter']])) $result[$status['letter']]++;
        $result['rows'][$compId] = [
            'name' => $comp['name'],
            'avg' => $avg,
            'status' => $status,
            'percentage' => $comp['percentage']
        ];
    }
    return $result;
}

// Se parte de las evaluaciones esperadas y se hace LEFT JOIN a las notas.
// Así también podemos detectar evaluaciones aún sin calificación.
$sql = "SELECT s.id student_id, s.name student_name, s.id_no,
               ac.id course_id, ac.name course_name,
               e.id evaluation_id, e.title evaluation_title, e.bimestre,
               eg.grade, ec.competencia_id competency_id,
               gcc.name competency_name, gcc.percentage
        FROM evaluations e
        INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id AND tc.school_id=?
        INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=?
        INNER JOIN evaluation_competencias ec ON ec.evaluation_id=e.id
        INNER JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id
        INNER JOIN student s ON s.school_id=?
            AND LOWER(REPLACE(TRIM(s.nivel),' ',''))=?
            AND s.grado=? AND s.seccion=?
            AND (s.status='Activo' OR s.status='1' OR s.status IS NULL)
        LEFT JOIN evaluation_grades eg ON eg.evaluation_id=e.id
            AND eg.student_id=s.id
            AND (eg.competencia_id=ec.competencia_id OR eg.competencia_id IS NULL)
        WHERE tc.academic_year_id=?
          AND LOWER(REPLACE(TRIM(ac.level),' ',''))=?
          AND tc.grado=? AND tc.seccion=?";

$params = [$schoolId, $schoolId, $schoolId, report_level_key($level), $grade, $section, $yearId, report_level_key($level), $grade, $section];
$types = 'iiisssisss';
if ($loginType === 2 && $teacherId > 0 && !$reportDirector) {
    $sql .= ' AND tc.teacher_id=?';
    $params[] = $teacherId;
    $types .= 'i';
}
if ($courseId > 0) {
    $sql .= ' AND tc.course_id=?';
    $params[] = $courseId;
    $types .= 'i';
}
// Para el gráfico alumno vs aula necesitamos consultar todo el salón.
if ($studentId > 0 && $dataMode !== 'trend') {
    $sql .= ' AND s.id=?';
    $params[] = $studentId;
    $types .= 'i';
}
$sql .= ' ORDER BY s.name, ac.name, e.bimestre, gcc.id, e.id';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    if ($dataMode === 'trend') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 0, 'message' => 'No se pudo preparar la consulta.']);
        exit;
    }
    exit('<div class="alert alert-danger mb-0">No se pudo preparar el reporte.</div>');
}
report_bind($stmt, $types, $params);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['numeric_grade'] = report_numeric_grade($row['grade']);
    $rows[] = $row;
}
$stmt->close();

$students = [];
foreach ($rows as $row) {
    $sid = (int)$row['student_id'];
    $cid = (int)$row['course_id'];
    $bi = (int)$row['bimestre'];
    $compId = (int)$row['competency_id'];
    $evalId = (int)$row['evaluation_id'];

    if (!isset($students[$sid])) {
        $students[$sid] = ['name' => $row['student_name'], 'dni' => $row['id_no'], 'courses' => []];
    }
    if (!isset($students[$sid]['courses'][$cid])) {
        $students[$sid]['courses'][$cid] = [
            'name' => $row['course_name'],
            'competencies' => [],
            'bimesters' => [],
            'values' => [],
            'missing' => [],
            'expected' => [],
            'graded' => []
        ];
    }

    $course =& $students[$sid]['courses'][$cid];
    if (!isset($course['competencies'][$bi][$compId])) {
        $course['competencies'][$bi][$compId] = [
            'name' => $row['competency_name'],
            'percentage' => (float)$row['percentage'],
            'expected' => [],
            'grades' => [],
            'avg' => null
        ];
    }
    $comp =& $course['competencies'][$bi][$compId];
    $comp['expected'][$evalId] = $row['evaluation_title'];
    if ($row['numeric_grade'] !== null) {
        $comp['grades'][$evalId] = $row['numeric_grade'];
    }
    unset($comp, $course);
}

// Aplicar exactamente la misma fórmula de la ficha individual:
// promedio por competencia x porcentaje, sin normalizar los pesos.
foreach ($students as &$student) {
    foreach ($student['courses'] as &$course) {
        foreach ($course['competencies'] as $bi => &$competencies) {
            $weighted = 0;
            $hasGrade = false;
            $missing = 0;
            $expected = 0;
            $graded = 0;
            foreach ($competencies as &$comp) {
                $marks = array_values($comp['grades']);
                $expectedCount = count($comp['expected']);
                $gradedCount = count($marks);
                $expected += $expectedCount;
                $graded += $gradedCount;
                $missing += max(0, $expectedCount - $gradedCount);
                if ($gradedCount > 0) {
                    $comp['avg'] = array_sum($marks) / $gradedCount;
                    $weighted += $comp['avg'] * $comp['percentage'] / 100;
                    $hasGrade = true;
                }
            }
            unset($comp);
            $course['bimesters'][$bi] = $hasGrade ? $weighted : null;
            $course['missing'][$bi] = $missing;
            $course['expected'][$bi] = $expected;
            $course['graded'][$bi] = $graded;
        }
        unset($competencies);
        ksort($course['bimesters']);
        $course['values'] = array_values(array_filter($course['bimesters'], static function($v) { return $v !== null; }));
    }
    unset($course);
}
unset($student);

// Fuente JSON para el gráfico de la ficha: alumno vs promedio del aula.
if ($dataMode === 'trend') {
    header('Content-Type: application/json; charset=utf-8');
    if ($studentId <= 0 || $courseId <= 0) {
        echo json_encode(['status' => 0, 'message' => 'Seleccione alumno y curso.']);
        exit;
    }
    $studentScores = [];
    $classScores = [];
    for ($bi = 1; $bi <= 4; $bi++) {
        $studentValue = $students[$studentId]['courses'][$courseId]['bimesters'][$bi] ?? null;
        $studentScores[$bi] = report_round_grade($studentValue);
        $classValues = [];
        foreach ($students as $classStudent) {
            $value = $classStudent['courses'][$courseId]['bimesters'][$bi] ?? null;
            if ($value !== null) $classValues[] = $value;
        }
        $classScores[$bi] = report_round_grade(report_average($classValues));
    }
    echo json_encode([
        'status' => 1,
        'student_scores' => $studentScores,
        'class_scores' => $classScores,
        'student_name' => $students[$studentId]['name'] ?? ''
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$students) {
    exit('<div class="alert alert-light border text-center mb-0 py-4"><i class="fas fa-info-circle mr-2"></i>No hay evaluaciones configuradas con los filtros seleccionados.</div>');
}

$uniqueCourses = [];
$uniqueEvaluations = [];
foreach ($rows as $row) {
    $uniqueCourses[(int)$row['course_id']] = true;
    $uniqueEvaluations[(int)$row['evaluation_id']] = true;
}
?>
<style>
.gr-kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:16px}.gr-kpi{border:1px solid #e3e6f0;border-radius:8px;background:#fff;padding:11px 13px}.gr-kpi small{display:block;color:#7b8499;font-size:.72rem}.gr-kpi strong{font-size:1.35rem;color:#344767}.gr-status-bars{border:1px solid #e3e6f0;border-radius:8px;padding:14px;margin-bottom:16px;background:#fff}.gr-status-row{display:grid;grid-template-columns:145px 1fr 42px;gap:10px;align-items:center;margin:8px 0}.gr-status-track{height:13px;background:#f0f2f6;border-radius:999px;overflow:hidden}.gr-status-fill{height:100%;border-radius:999px}.gr-fill-ad{background:#13855c}.gr-fill-a{background:#1cc88a}.gr-fill-b{background:#f6c23e}.gr-fill-c{background:#e74a3b}.gr-alert-list{margin:0;padding-left:18px}.gr-matrix th{min-width:110px}.gr-matrix th:first-child{min-width:210px}.gr-status-pill{display:inline-block;border-radius:999px;padding:3px 8px;font-weight:700;font-size:.75rem}.gr-pill-ad,.gr-pill-a{background:#e0f7ee;color:#107354}.gr-pill-b{background:#fff5d6;color:#856404}.gr-pill-c{background:#fde3e1;color:#a3281d}.gr-pill-none{background:#f1f3f5;color:#6c757d}@media(max-width:900px){.gr-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.gr-status-row{grid-template-columns:110px 1fr 36px}}
</style>
<div class="grades-view-meta mb-3">
  <span><i class="fas fa-users mr-1"></i><?php echo count($students); ?> estudiante(s)</span>
  <span><i class="fas fa-book mr-1"></i><?php echo count($uniqueCourses); ?> curso(s)</span>
  <span><i class="fas fa-clipboard-check mr-1"></i><?php echo count($uniqueEvaluations); ?> evaluación(es)</span>
</div>

<?php if ($view === 'comparison'): ?>
  <div class="table-responsive"><table class="table table-sm table-bordered report-clean-table js-report-table">
    <thead><tr><th>Estudiante</th><th>DNI</th><th>Curso</th><th>1°</th><th>2°</th><th>3°</th><th>4°</th><th>Promedio</th><th>Tendencia</th></tr></thead><tbody>
    <?php foreach ($students as $student): foreach ($student['courses'] as $course):
        $first = null; $last = null; $firstBi = null; $lastBi = null;
    ?>
      <tr><td><?php echo htmlspecialchars($student['name']); ?></td><td><?php echo htmlspecialchars($student['dni']); ?></td><td><?php echo htmlspecialchars($course['name']); ?></td>
      <?php for ($i=1; $i<=4; $i++): $value=$course['bimesters'][$i] ?? null; if($value!==null){if($first===null){$first=$value;$firstBi=$i;}$last=$value;$lastBi=$i;} ?><td><?php echo report_fmt($value); ?></td><?php endfor; ?>
      <td><strong><?php echo report_fmt(report_average($course['values'])); ?></strong></td>
      <td><?php if($first===null || $last===null || $firstBi===$lastBi): ?><span class="text-muted">Sin tendencia</span><?php else: $delta=report_round_grade($last)-report_round_grade($first); if($delta>=2): ?><span class="text-success"><i class="fas fa-arrow-up mr-1"></i>Mejora +<?php echo $delta; ?></span><?php elseif($delta<=-2): ?><span class="text-danger"><i class="fas fa-arrow-down mr-1"></i>Desciende <?php echo $delta; ?></span><?php else: ?><span class="text-muted"><i class="fas fa-minus mr-1"></i>Estable</span><?php endif; endif; ?></td></tr>
    <?php endforeach; endforeach; ?></tbody>
  </table></div>

<?php elseif ($view === 'pending'): ?>
  <?php if ($bimester <= 0): ?>
    <div class="alert alert-info mb-0"><i class="fas fa-calendar-alt mr-2"></i>Seleccione un bimestre para analizar el seguimiento académico y compararlo con el periodo anterior.</div>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-bordered report-clean-table js-report-table">
      <thead><tr><th>Estudiante</th><th>DNI</th><th>Curso</th><th>Promedio</th><th>Variación</th><th>Alertas detectadas</th><th>Situación</th></tr></thead><tbody>
      <?php $visible=0; foreach($students as $student): foreach($student['courses'] as $course):
          $current = $course['bimesters'][$bimester] ?? null;
          $previous = $bimester > 1 ? ($course['bimesters'][$bimester-1] ?? null) : null;
          $delta = ($current !== null && $previous !== null) ? report_round_grade($current) - report_round_grade($previous) : null;
          $stats = report_competency_stats($course, $bimester);
          $missing = (int)($course['missing'][$bimester] ?? 0);
          $alerts = [];
          $severity = '';
          if ($stats['C'] > 0) { $alerts[] = $stats['C'].' competencia(s) en C'; $severity='risk'; }
          if ($stats['B'] >= 2) { $alerts[] = $stats['B'].' competencias en B'; if($severity==='')$severity='attention'; }
          if ($delta !== null && $delta <= -2) { $alerts[] = 'Bajó '.abs($delta).' punto(s)'; $severity='risk'; }
          if ($missing > 0) { $alerts[] = $missing.' nota(s) pendiente(s)'; if($severity==='')$severity='attention'; }
          if (!$alerts && $delta !== null && $delta >= 2) { $alerts[] = 'Mejoró '.$delta.' punto(s)'; $severity='favorable'; }
          if (!$alerts) continue;
          $visible++;
      ?>
        <tr><td><?php echo htmlspecialchars($student['name']); ?></td><td><?php echo htmlspecialchars($student['dni']); ?></td><td><?php echo htmlspecialchars($course['name']); ?></td><td><strong><?php echo report_fmt($current); ?></strong></td>
        <td><?php if($delta===null): ?>—<?php elseif($delta>0): ?><span class="text-success">↑ +<?php echo $delta; ?></span><?php elseif($delta<0): ?><span class="text-danger">↓ <?php echo $delta; ?></span><?php else: ?>= 0<?php endif; ?></td>
        <td><ul class="gr-alert-list"><?php foreach($alerts as $alert): ?><li><?php echo htmlspecialchars($alert); ?></li><?php endforeach; ?></ul></td>
        <td><?php if($severity==='risk'): ?><span class="badge badge-danger">Riesgo</span><?php elseif($severity==='attention'): ?><span class="badge badge-warning">Atención</span><?php else: ?><span class="badge badge-success">Favorable</span><?php endif; ?></td></tr>
      <?php endforeach; endforeach; ?>
      <?php if($visible===0): ?><tr><td colspan="7" class="text-center text-muted py-4">No se detectaron alertas académicas para este bimestre.</td></tr><?php endif; ?>
      </tbody></table></div>
  <?php endif; ?>

<?php else: ?>
  <?php
    // Consolidado del aula. Si no hay bimestre, usa promedio acumulado de los bimestres con notas.
    $records = [];
    foreach ($students as $sid => $student) {
        foreach ($student['courses'] as $cid => $course) {
            $value = $bimester > 0 ? ($course['bimesters'][$bimester] ?? null) : report_average($course['values']);
            $records[] = ['student_id'=>$sid,'student'=>$student,'course_id'=>$cid,'course'=>$course,'value'=>$value];
        }
    }
    $validValues = [];
    $statusCounts = ['AD'=>0,'A'=>0,'B'=>0,'C'=>0];
    foreach ($records as $record) {
        if ($record['value'] === null) continue;
        $validValues[] = $record['value'];
        $status = report_status($record['value']);
        if (isset($statusCounts[$status['letter']])) $statusCounts[$status['letter']]++;
    }
    $classAvg = report_average($validValues);
    $maxStatus = max(1, max($statusCounts));
  ?>
  <div class="gr-kpi-grid">
    <div class="gr-kpi"><small>Promedio del aula</small><strong><?php echo report_fmt($classAvg); ?></strong></div>
    <div class="gr-kpi"><small>Logro destacado (AD)</small><strong><?php echo $statusCounts['AD']; ?></strong></div>
    <div class="gr-kpi"><small>Logro esperado (A)</small><strong><?php echo $statusCounts['A']; ?></strong></div>
    <div class="gr-kpi"><small>En proceso (B)</small><strong><?php echo $statusCounts['B']; ?></strong></div>
    <div class="gr-kpi"><small>En inicio (C)</small><strong><?php echo $statusCounts['C']; ?></strong></div>
  </div>
  <div class="gr-status-bars">
    <strong class="d-block mb-2">Distribución del rendimiento</strong>
    <?php foreach(['AD'=>'Logro destacado','A'=>'Logro esperado','B'=>'En proceso','C'=>'En inicio'] as $letter=>$label): $width=($statusCounts[$letter]/$maxStatus)*100; ?>
      <div class="gr-status-row"><span><strong><?php echo $letter; ?></strong> · <?php echo $label; ?></span><div class="gr-status-track"><div class="gr-status-fill gr-fill-<?php echo strtolower($letter); ?>" style="width:<?php echo round($width,1); ?>%"></div></div><strong><?php echo $statusCounts[$letter]; ?></strong></div>
    <?php endforeach; ?>
  </div>

  <div class="table-responsive mb-4"><table class="table table-sm table-bordered report-clean-table js-report-table">
    <thead><tr><th>Estudiante</th><th>DNI</th><th>Curso</th><th>Notas registradas</th><th>Promedio</th><th>Estado</th></tr></thead><tbody>
    <?php foreach($records as $record): $value=$record['value']; $status=report_status($value); $biForCount=$bimester>0?$bimester:0; $graded=$biForCount?($record['course']['graded'][$biForCount]??0):array_sum($record['course']['graded']); ?>
      <tr><td><?php echo htmlspecialchars($record['student']['name']); ?></td><td><?php echo htmlspecialchars($record['student']['dni']); ?></td><td><?php echo htmlspecialchars($record['course']['name']); ?></td><td><?php echo (int)$graded; ?></td><td><strong><?php echo report_fmt($value); ?></strong></td><td><span class="gr-status-pill gr-pill-<?php echo strtolower($status['letter']==='—'?'none':$status['letter']); ?>"><?php echo htmlspecialchars($status['letter'].' · '.$status['label']); ?></span></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>

  <?php if($courseId>0 && $bimester>0):
      $competencyColumns=[];
      foreach($students as $student) {
          if(empty($student['courses'][$courseId]['competencies'][$bimester])) continue;
          foreach($student['courses'][$courseId]['competencies'][$bimester] as $compId=>$comp) $competencyColumns[$compId]=$comp['name'];
      }
      ksort($competencyColumns);
  ?>
    <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-th mr-2"></i>Matriz de competencias</h6>
    <div class="table-responsive"><table class="table table-sm table-bordered report-clean-table js-report-table gr-matrix">
      <thead><tr><th>Estudiante</th><?php foreach($competencyColumns as $compName): ?><th><?php echo htmlspecialchars($compName); ?></th><?php endforeach; ?><th>Promedio</th></tr></thead><tbody>
      <?php foreach($students as $student): $course=$student['courses'][$courseId]??null; if(!$course)continue; ?>
        <tr><td><?php echo htmlspecialchars($student['name']); ?></td>
        <?php foreach($competencyColumns as $compId=>$compName): $comp=$course['competencies'][$bimester][$compId]??null; $value=$comp['avg']??null; $status=report_status($value); ?><td><span class="gr-status-pill gr-pill-<?php echo strtolower($status['letter']==='—'?'none':$status['letter']); ?>"><?php echo report_fmt($value); ?><?php if($value!==null): ?> · <?php echo $status['letter']; ?><?php endif; ?></span></td><?php endforeach; ?>
        <td><strong><?php echo report_fmt($course['bimesters'][$bimester]??null); ?></strong></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php else: ?>
    <div class="alert alert-light border small"><i class="fas fa-info-circle mr-2"></i>Seleccione un curso y un bimestre para mostrar la matriz de competencias del aula.</div>
  <?php endif; ?>
<?php endif; ?>

<div class="small text-muted mt-2"><i class="fas fa-info-circle mr-1"></i>Los promedios se muestran como enteros redondeados. El cálculo conserva la fórmula por competencias: promedio de cada competencia multiplicado por su porcentaje, sin normalizar los pesos.</div>
