<?php
/**
 * Consulta de calificaciones desde una notificación académica (Administración).
 * No carga el formulario de edición del docente, ni sus scripts de guardado.
 * Las consultas se restringen al colegio activo y todas las notas son lectura.
 */
date_default_timezone_set('America/Lima');
include_once __DIR__.'/includes/session_check.php';
require_login_modal();
require_once __DIR__.'/db_connect.php';
require_once __DIR__.'/includes/notification_detail_ui.php';

$school=(int)($_SESSION['login_school_id']??0);
$user=(int)($_SESSION['login_id']??0);
$evaluationId=(int)($_GET['evaluation_id']??0);
if ($school<=0 || $user<=0 || $evaluationId<=0) {
    echo '<div class="alert alert-warning">No se encontró la evaluación solicitada.</div>';
    return;
}
$role=$conn->prepare('SELECT type FROM users WHERE id=? AND school_id=? LIMIT 1');
if (!$role) { echo '<div class="alert alert-warning">No se pudo verificar el acceso.</div>'; return; }
$role->bind_param('ii',$user,$school);
$role->execute();$identity=$role->get_result()->fetch_assoc();$role->close();
if (!$identity || (int)$identity['type']!==1) {
    echo '<div class="alert alert-danger">Solo Administración puede consultar las calificaciones de esta evaluación.</div>';
    return;
}

$owner=$conn->prepare("SELECT e.id,e.title,e.bimestre,e.type,
    tc.academic_year_id,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,
    ac.name course_name,ac.level,ay.year,ay.is_active,t.name teacher_name
    FROM evaluations e
    INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id AND tc.school_id=?
    INNER JOIN academic_courses ac ON ac.id=tc.course_id
    INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
    LEFT JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
    WHERE e.id=? LIMIT 1");
if (!$owner) { echo '<div class="alert alert-warning">No se pudo consultar la evaluación.</div>'; return; }
$owner->bind_param('ii',$school,$evaluationId);$owner->execute();
$evaluation=$owner->get_result()->fetch_assoc();$owner->close();
if (!$evaluation) {
    echo '<div class="alert alert-warning">La evaluación no existe o no corresponde a este colegio.</div>';
    return;
}

$competencies=[];
$q=$conn->prepare("SELECT gcc.id,gcc.name,gcc.percentage
    FROM evaluation_competencias ec
    INNER JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id
    WHERE ec.evaluation_id=? ORDER BY ec.id");
if ($q) {
    $q->bind_param('i',$evaluationId);$q->execute();
    $result=$q->get_result();
    while ($row=$result->fetch_assoc()) $competencies[]=$row;
    $q->close();
}

$grade=trim((string)$evaluation['grado']);
$section=trim((string)$evaluation['seccion']);
$level=trim((string)$evaluation['level']);
$active=(int)$evaluation['is_active']===1;

// Las columnas y los parámetros pueden usar collations diferentes en producción.
// Comparamos las secciones con una collation explícita, sin usar IN sobre parámetros.
$normalizedSectionSql = "CONVERT(COALESCE(NULLIF(TRIM(s.seccion),''),'U') USING utf8mb4) COLLATE utf8mb4_unicode_ci";
$isSingleSection = preg_match('/^(?:u|[uú]nica)$/iu', $section) === 1;
$sectionFilterSql = $isSingleSection
    ? "($normalizedSectionSql = _utf8mb4'U' COLLATE utf8mb4_unicode_ci OR $normalizedSectionSql = _utf8mb4'Unica' COLLATE utf8mb4_unicode_ci)"
    : "$normalizedSectionSql = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";

$studentSql="SELECT DISTINCT s.id,s.name,s.id_no FROM student s
 WHERE s.school_id=? AND (
    EXISTS (SELECT 1 FROM evaluation_grades eg
        WHERE eg.student_id=s.id AND eg.evaluation_id=?)
    OR ( ?=1 AND (s.status='Activo' OR s.status IS NULL)
         AND LOWER(s.nivel)=LOWER(?) AND s.grado=?
         AND $sectionFilterSql
       )
 ) ORDER BY s.name";
$stmt=$conn->prepare($studentSql);
if (!$stmt) { echo '<div class="alert alert-warning">No se pudo cargar la nómina.</div>'; return; }
$activeFlag=$active?1:0;
if ($isSingleSection) {
    $stmt->bind_param('iiiss', $school, $evaluationId, $activeFlag, $level, $grade);
} else {
    $stmt->bind_param('iiisss', $school, $evaluationId, $activeFlag, $level, $grade, $section);
}
$stmt->execute();$students=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
$notes=[];
$stmt=$conn->prepare("SELECT eg.student_id,eg.competencia_id,eg.grade
    FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id AND s.school_id=?
    WHERE eg.evaluation_id=?");
if ($stmt) {
    $stmt->bind_param('ii',$school,$evaluationId);$stmt->execute();
    $rows=$stmt->get_result();
    while ($row=$rows->fetch_assoc()) $notes[(int)$row['student_id']][(int)$row['competencia_id']]=(string)$row['grade'];
    $stmt->close();
}
$graded=0;$total=0;
foreach ($students as $student) {
    foreach ($competencies as $comp) {
        $total++;
        if (trim((string)($notes[(int)$student['id']][(int)$comp['id']]??''))!=='') $graded++;
    }
}
$percent=$total>0 ? round(($graded/$total)*100) : 0;
$e=static fn($v)=>edn_escape($v);
edn_header('evaluation','Calificaciones de la evaluación',
    (string)$evaluation['title'].' · '.(string)$evaluation['course_name'],
    null,'Solo lectura',(int)$evaluation['bimestre'].'° bimestre');
?>
<div class="row mb-3">
  <div class="col-md-6 mb-2">
    <div class="card shadow-sm h-100"><div class="card-body py-3">
      <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Curso y aula</div>
      <div class="font-weight-bold text-gray-800"><?= $e($evaluation['course_name']) ?></div>
      <div class="small text-muted"><?= $e($level.' · '.$grade.' '.$section) ?> · <?= $e($evaluation['year']) ?></div>
    </div></div>
  </div>
  <div class="col-md-6 mb-2">
    <div class="card shadow-sm h-100"><div class="card-body py-3">
      <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Docente responsable</div>
      <div class="font-weight-bold text-gray-800"><?= $e($evaluation['teacher_name']?:'Docente') ?></div>
      <div class="small text-muted"><?= $e($evaluation['type']?:'Evaluación') ?></div>
    </div></div>
  </div>
</div>
<div class="card shadow-sm mb-3">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between flex-wrap align-items-center mb-2">
      <span class="small font-weight-bold text-gray-800">Avance de calificaciones</span>
      <span class="badge badge-primary"><?= $graded ?> / <?= $total ?> · <?= $percent ?>%</span>
    </div>
    <div class="progress" style="height:7px"><div class="progress-bar bg-primary" role="progressbar" style="width:<?= $percent ?>%" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div></div>
    <small class="text-muted">La consulta no permite editar notas.</small>
  </div>
</div>
<div class="card shadow-sm mb-3">
  <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list-alt mr-2"></i>Registro de notas</h6>
    <span class="badge badge-light border"><?= count($students) ?> estudiantes</span>
  </div>
  <div class="card-body p-0">
    <?php if (!$competencies): ?>
      <div class="alert alert-warning m-3">No hay competencias asociadas a esta evaluación.</div>
    <?php elseif (!$students): ?>
      <div class="text-center text-muted py-4">No se encontraron estudiantes para esta evaluación.</div>
    <?php else: ?>
      <div class="table-responsive edn-evaluation-table">
        <table class="table table-bordered table-hover table-sm mb-0">
          <thead class="thead-light">
            <tr><th class="text-center" style="width:45px">#</th><th>Estudiante</th>
              <?php foreach ($competencies as $comp): ?>
                <th class="text-center">
                  <div class="font-weight-bold"><?= $e($comp['name']) ?></div>
                  <span class="badge badge-primary"><?= $e($comp['percentage']) ?>%</span>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $i=>$student): $sid=(int)$student['id']; ?>
            <tr>
              <td class="text-center text-muted"><?= $i+1 ?></td>
              <td><strong class="text-gray-800"><?= $e($student['name']) ?></strong>
                <small class="d-block text-muted"><?= $e($student['id_no']) ?></small></td>
              <?php foreach ($competencies as $comp):
                $v=trim((string)($notes[$sid][(int)$comp['id']]??''));
                $score=is_numeric($v)?(float)$v:null;
                $letter=mb_strtoupper($v,'UTF-8');
                $low=($score!==null && $score<10.5) || $letter==='C';
                $high=($score!==null && $score>=18) || $letter==='AD';
                $klass=$v===''?'badge-light border':($low?'badge-danger':($high?'badge-success':'badge-primary'));
              ?>
                <td class="text-center"><span class="badge <?= $klass ?> edn-score"><?= $e($v!==''?$v:'—') ?></span></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<div class="d-flex justify-content-between flex-wrap align-items-center border-top pt-3 mt-3">
  <button type="button" class="btn btn-outline-secondary btn-sm edn-back-evaluation" data-id="<?= $evaluationId ?>">
    <i class="fas fa-arrow-left mr-1"></i>Resumen
  </button>
  <button type="button" class="btn btn-light btn-sm border" data-dismiss="modal">Cerrar</button>
</div>
</div>
<script>
(function($){
  $('.edn-back-evaluation').off('click.edn').on('click.edn',function(){
    var id=Number($(this).data('id'));
    if(id>0 && typeof window.uni_modal==='function')
      window.uni_modal('Detalle de evaluación','notification_evaluation_detail.php?id='+id,'modal-lg');
  });
})(jQuery);
</script>
