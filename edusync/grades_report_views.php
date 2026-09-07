<?php
// Use the original individual report, including its competency breakdown.
if (($_POST['report_view'] ?? '') === 'student') {
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
if (!in_array($loginType, [1, 2], true) || ($loginType === 2 && $teacherId <= 0)) {
    http_response_code(403);
    exit('No tiene permisos para consultar este reporte.');
}
$view = $_POST['report_view'] ?? 'consolidated';
$yearId = (int)($_POST['academic_year_id'] ?? 0);
$courseId = (int)($_POST['course_id'] ?? 0);
$studentId = (int)($_POST['student_id'] ?? 0);
$bimester = (int)($_POST['bimestre'] ?? 0);
$level = trim($_POST['level'] ?? '');
$grade = trim($_POST['grado'] ?? '');
$section = trim($_POST['seccion'] ?? '');

if (!$schoolId || !$yearId || $level === '' || $grade === '' || $section === '') {
    exit('<div class="alert alert-info mb-0"><i class="fas fa-filter mr-2"></i>Seleccione año, nivel, grado y sección para generar el reporte.</div>');
}
if ($view === 'student' && !$studentId) {
    exit('<div class="alert alert-info mb-0"><i class="fas fa-user-graduate mr-2"></i>Seleccione un estudiante para generar su ficha.</div>');
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

$sql = "SELECT s.id student_id,s.name student_name,s.id_no,ac.id course_id,ac.name course_name,
               e.id evaluation_id,e.title evaluation_title,e.bimestre,eg.grade,
               gcc.id competency_id,gcc.percentage
        FROM evaluation_grades eg
        INNER JOIN student s ON s.id=eg.student_id AND s.school_id=?
        INNER JOIN evaluations e ON e.id=eg.evaluation_id
        INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id AND tc.school_id=?
        INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=?
        INNER JOIN general_course_competencies gcc ON gcc.id=eg.competencia_id
        WHERE tc.academic_year_id=?
          AND LOWER(REPLACE(TRIM(ac.level),' ',''))=?
          AND tc.grado=? AND tc.seccion=?";
$params = [$schoolId, $schoolId, $schoolId, $yearId, report_level_key($level), $grade, $section];
$types = 'iiiisss';
if ($loginType === 2 && $teacherId > 0) { $sql .= ' AND tc.teacher_id=?'; $params[]=$teacherId; $types.='i'; }
if ($courseId > 0) { $sql .= ' AND tc.course_id=?'; $params[]=$courseId; $types.='i'; }
if ($studentId > 0) { $sql .= ' AND s.id=?'; $params[]=$studentId; $types.='i'; }
if ($view !== 'comparison' && $bimester > 0) { $sql .= ' AND e.bimestre=?'; $params[]=$bimester; $types.='i'; }
$sql .= ' ORDER BY s.name,ac.name,e.bimestre,e.id';
$stmt = $conn->prepare($sql);
if (!$stmt) exit('<div class="alert alert-danger mb-0">No se pudo preparar el reporte.</div>');
report_bind($stmt, $types, $params);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) { $row['numeric_grade'] = report_numeric_grade($row['grade']); $rows[] = $row; }
$stmt->close();

$students = [];
foreach ($rows as $row) {
    $sid = (int)$row['student_id']; $cid = (int)$row['course_id']; $bi = (int)$row['bimestre'];
    if (!isset($students[$sid])) $students[$sid] = ['name'=>$row['student_name'],'dni'=>$row['id_no'],'courses'=>[],'values'=>[]];
    if (!isset($students[$sid]['courses'][$cid])) $students[$sid]['courses'][$cid] = ['name'=>$row['course_name'],'values'=>[],'bimesters'=>[],'evaluations'=>[]];
    if ($row['numeric_grade'] !== null) {
        $students[$sid]['values'][] = $row['numeric_grade'];
        $students[$sid]['courses'][$cid]['values'][] = $row['numeric_grade'];
        $students[$sid]['courses'][$cid]['bimesters'][$bi][] = $row['numeric_grade'];
    }
    $students[$sid]['courses'][$cid]['evaluations'][] = $row;
}
// Same formula as the original individual report: mean per competency times
// its configured percentage. Never normalize the weights or average raw marks.
foreach ($students as &$student) {
    foreach ($student['courses'] as &$course) {
        $groups = [];
        foreach ($course['evaluations'] as $evaluation) {
            $bi = (int)$evaluation['bimestre'];
            $ci = (int)$evaluation['competency_id'];
            $groups[$bi][$ci]['percentage'] = (float)$evaluation['percentage'];
            $groups[$bi][$ci]['grades'][$evaluation['evaluation_id']] = $evaluation['numeric_grade'] ?? 0;
        }
        $course['values'] = [];
        $course['bimesters'] = [];
        foreach ($groups as $bi => $competencies) {
            $weighted = 0;
            foreach ($competencies as $competency) {
                $marks = $competency['grades'];
                $weighted += array_sum($marks) / count($marks) * $competency['percentage'] / 100;
            }
            $course['bimesters'][$bi] = [$weighted];
            $course['values'][] = $weighted;
        }
    }
    unset($course);
}
unset($student);
$avg = static function($values) { return count($values) ? array_sum($values)/count($values) : null; };
$fmt = static function($value) { return $value === null ? '—' : number_format($value, 2); };

if (!$students) {
    exit('<div class="alert alert-light border text-center mb-0 py-4"><i class="fas fa-info-circle mr-2"></i>No hay notas registradas con los filtros seleccionados.</div>');
}
?>
<div class="grades-view-meta mb-3">
  <span><i class="fas fa-users mr-1"></i><?php echo count($students); ?> estudiante(s)</span>
  <span><i class="fas fa-book mr-1"></i><?php echo count(array_unique(array_column($rows, 'course_id'))); ?> curso(s)</span>
  <span><i class="fas fa-clipboard-check mr-1"></i><?php echo count(array_unique(array_column($rows, 'evaluation_id'))); ?> evaluación(es)</span>
</div>
<?php if ($view === 'student'): $student = reset($students); ?>
  <div class="student-report-heading">
    <div><small>Estudiante</small><strong><?php echo htmlspecialchars($student['name']); ?></strong></div>
    <div><small>DNI</small><strong><?php echo htmlspecialchars($student['dni']); ?></strong></div>
    <div><small>Promedio ponderado</small><strong><?php echo $fmt($avg($student['values'])); ?></strong></div>
  </div>
  <?php foreach ($student['courses'] as $course): ?>
    <div class="table-responsive mb-3"><table class="table table-sm table-bordered report-clean-table mb-0">
      <thead><tr><th colspan="4"><?php echo htmlspecialchars($course['name']); ?></th></tr><tr><th>Evaluación</th><th>Bimestre</th><th>Nota registrada</th><th>Equivalente numérico</th></tr></thead>
      <tbody><?php foreach ($course['evaluations'] as $evaluation): ?><tr><td><?php echo htmlspecialchars($evaluation['evaluation_title']); ?></td><td><?php echo (int)$evaluation['bimestre']; ?>°</td><td><?php echo htmlspecialchars($evaluation['grade']); ?></td><td><?php echo $fmt($evaluation['numeric_grade']); ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endforeach; ?>
<?php elseif ($view === 'comparison'): ?>
  <div class="table-responsive"><table class="table table-sm table-bordered report-clean-table js-report-table">
    <thead><tr><th>Estudiante</th><th>DNI</th><th>Curso</th><th>1°</th><th>2°</th><th>3°</th><th>4°</th><th>Promedio</th><th>Tendencia</th></tr></thead><tbody>
    <?php foreach($students as $student): foreach($student['courses'] as $course): $first=null;$last=null; ?><tr>
      <td><?php echo htmlspecialchars($student['name']); ?></td><td><?php echo htmlspecialchars($student['dni']); ?></td><td><?php echo htmlspecialchars($course['name']); ?></td>
      <?php for($i=1;$i<=4;$i++): $value=$avg($course['bimesters'][$i] ?? []); if($value!==null){if($first===null)$first=$value;$last=$value;} ?><td><?php echo $fmt($value); ?></td><?php endfor; ?>
      <td><strong><?php echo $fmt($avg($course['values'])); ?></strong></td><td><?php echo ($first===null||$last===null||abs($last-$first)<0.01)?'Estable':($last>$first?'Mejora':'Desciende'); ?></td>
    </tr><?php endforeach; endforeach; ?></tbody>
  </table></div>
<?php elseif ($view === 'pending'): ?>
  <div class="table-responsive"><table class="table table-sm table-bordered report-clean-table js-report-table">
    <thead><tr><th>Estudiante</th><th>DNI</th><th>Curso</th><th>Evaluaciones registradas</th><th>Promedio ponderado</th><th>Situación</th></tr></thead><tbody>
    <?php $visible=0; foreach($students as $student): foreach($student['courses'] as $course): $courseAvg=$avg($course['values']); if($courseAvg!==null && $courseAvg>10) continue; $visible++; ?><tr><td><?php echo htmlspecialchars($student['name']); ?></td><td><?php echo htmlspecialchars($student['dni']); ?></td><td><?php echo htmlspecialchars($course['name']); ?></td><td><?php echo count($course['evaluations']); ?></td><td><strong><?php echo $fmt($courseAvg); ?></strong></td><td><span class="badge badge-warning">Requiere seguimiento</span></td></tr><?php endforeach; endforeach; ?>
    </tbody></table></div>
<?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-bordered report-clean-table js-report-table">
    <thead><tr><th>Estudiante</th><th>DNI</th><th>Curso</th><th>Notas registradas</th><th>Promedio ponderado</th><th>Situación</th></tr></thead><tbody>
    <?php foreach($students as $student): foreach($student['courses'] as $course): $courseAvg=$avg($course['values']); ?><tr><td><?php echo htmlspecialchars($student['name']); ?></td><td><?php echo htmlspecialchars($student['dni']); ?></td><td><?php echo htmlspecialchars($course['name']); ?></td><td><?php echo count($course['evaluations']); ?></td><td><strong><?php echo $fmt($courseAvg); ?></strong></td><td><?php if($courseAvg===null): ?>Sin notas<?php elseif($courseAvg<=10): ?><span class="badge badge-warning">En seguimiento</span><?php else: ?><span class="badge badge-success">Aprobado</span><?php endif; ?></td></tr><?php endforeach; endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
<div class="small text-muted mt-2"><i class="fas fa-info-circle mr-1"></i>Promedio del bimestre: suma de los promedios de cada competencia multiplicados por su porcentaje. Las competencias con 0% no aportan al promedio. Al comparar varios bimestres, se muestra el promedio de los bimestres con notas.</div>
