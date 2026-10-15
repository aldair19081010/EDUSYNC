<?php
/** Ficha resumida de una nueva evaluación notificada a Administración. */
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/notification_detail_ui.php';
require_once __DIR__ . '/includes/notification_date_timezone.php';
$conn->query("SET time_zone='-05:00'");
$school=(int)($_SESSION['login_school_id']??0);
$user=(int)($_SESSION['login_id']??0);
$id=(int)($_GET['id']??0);
$role=$conn->prepare('SELECT type FROM users WHERE id=? AND school_id=? LIMIT 1');
if (!$role) { echo '<div class="alert alert-warning">No se puede consultar el aviso.</div>'; return; }
$role->bind_param('ii',$user,$school);
$role->execute(); $who=$role->get_result()->fetch_assoc(); $role->close();
if (!$who || (int)$who['type']!==1 || $id<=0) {
    echo '<div class="alert alert-warning">Esta evaluación está disponible solo para Administración.</div>'; return;
}
$timeSql=edun_evaluation_created_at_lima_sql($conn);
$statusAvailable=false;
$col=$conn->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
if ($col && $col->num_rows) $statusAvailable=true;
$statusSql=$statusAvailable?"e.status":" 'Activa' ";
$stmt=$conn->prepare("SELECT e.id,e.title,e.description,e.type,e.bimestre,
    $timeSql created_at_peru,$statusSql status,
    ac.name course_name,ac.level,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,
    ay.year academic_year,t.name teacher_name,
    (SELECT COUNT(*) FROM evaluation_grades eg WHERE eg.evaluation_id=e.id AND eg.grade<>'') grades_count,
    (SELECT COUNT(DISTINCT student_id) FROM evaluation_grades eg WHERE eg.evaluation_id=e.id AND eg.grade<>'') students_graded
    FROM evaluations e
    INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id AND tc.school_id=?
    INNER JOIN academic_courses ac ON ac.id=tc.course_id
    INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
    LEFT JOIN teacher t ON t.id=e.teacher_id AND t.school_id=tc.school_id
    WHERE e.id=? LIMIT 1");
if (!$stmt) { echo '<div class="alert alert-warning">No se pudo cargar la evaluación.</div>'; return; }
$stmt->bind_param('ii',$school,$id);
$stmt->execute();$evaluation=$stmt->get_result()->fetch_assoc();$stmt->close();
if (!$evaluation) { echo '<div class="alert alert-warning">La evaluación ya no está disponible en este colegio.</div>'; return; }
$e=static function($value): string { return edn_escape($value); };
edn_header('evaluation','Nueva evaluación: '.(string)$evaluation['title'],
    (string)$evaluation['course_name'].' · '.(string)$evaluation['grado'].' '.(string)$evaluation['seccion'],
    $evaluation['created_at_peru'],
    ($evaluation['status']??'Activa')==='Anulada'?'Anulada':'Registrada',
    (int)$evaluation['bimestre'].'° bimestre');
?>
<div class="edn-grid">
    <div class="edn-card"><span class="edn-card-label">Docente</span><strong class="edn-card-value"><?= $e($evaluation['teacher_name']??'Docente') ?></strong></div>
    <div class="edn-card"><span class="edn-card-label">Nivel y aula</span><strong class="edn-card-value"><?= $e($evaluation['level'].' · '.$evaluation['grado'].' '.$evaluation['seccion']) ?></strong></div>
    <div class="edn-card"><span class="edn-card-label">Tipo de evaluación</span><strong class="edn-card-value"><?= $e($evaluation['type']?:'Evaluación') ?></strong></div>
    <div class="edn-card"><span class="edn-card-label">Año académico</span><strong class="edn-card-value"><?= $e($evaluation['academic_year']) ?></strong></div>
</div>
<div class="edn-mini-kpi">
    <span class="edn-mini-kpi-item"><strong><?= (int)$evaluation['grades_count'] ?></strong> calificaciones registradas</span>
    <span class="edn-mini-kpi-item"><strong><?= (int)$evaluation['students_graded'] ?></strong> estudiantes calificados</span>
</div>
<?php if(trim((string)$evaluation['description'])!==''): ?>
<section class="edn-section">
  <h6 class="edn-section-title"><i class="fas fa-align-left"></i>Descripción</h6>
  <div><?= nl2br($e($evaluation['description'])) ?></div>
</section>
<?php endif; ?>
<div class="edn-footer">
  <button type="button" class="btn btn-light border" data-dismiss="modal">Cerrar</button>
  <button type="button" class="btn btn-primary edn-view-evaluation" data-id="<?= (int)$evaluation['id'] ?>"><i class="fas fa-eye mr-1"></i>Ver calificaciones</button>
</div>
</div>
<script>
(function($){
  $('.edn-view-evaluation').off('click.edn').on('click.edn',function(){
    var id=Number($(this).data('id'));
    if (!id || typeof window.uni_modal!=='function') return;
    window.uni_modal('Ver evaluación','manage_evaluation_grades.php?evaluation_id='+id+'&from=notifications','modal-lg');
  });
})(jQuery);
</script>
