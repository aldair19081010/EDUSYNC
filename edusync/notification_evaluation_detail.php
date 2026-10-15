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
<div class="row mb-3">
  <div class="col-md-6 mb-2"><div class="card shadow-sm h-100">
    <div class="card-body py-3">
      <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Docente responsable</div>
      <div class="font-weight-bold text-gray-800"><?= $e($evaluation['teacher_name']??'Docente') ?></div>
      <small class="text-muted"><?= $e($evaluation['type']?:'Evaluación') ?></small>
    </div>
  </div></div>
  <div class="col-md-6 mb-2"><div class="card shadow-sm h-100">
    <div class="card-body py-3">
      <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Curso y aula</div>
      <div class="font-weight-bold text-gray-800"><?= $e($evaluation['course_name']) ?></div>
      <small class="text-muted"><?= $e($evaluation['level'].' · '.$evaluation['grado'].' '.$evaluation['seccion']) ?> · <?= $e($evaluation['academic_year']) ?></small>
    </div>
  </div></div>
</div>
<div class="card shadow-sm mb-3">
  <div class="card-header py-3">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-chart-bar mr-2"></i>Resumen de la evaluación</h6>
  </div>
  <div class="card-body py-3">
    <div class="row text-center">
      <div class="col-6 border-right"><div class="h5 mb-0 font-weight-bold text-gray-800"><?= (int)$evaluation['grades_count'] ?></div><div class="small text-muted mt-1">Notas registradas</div></div>
      <div class="col-6"><div class="h5 mb-0 font-weight-bold text-gray-800"><?= (int)$evaluation['students_graded'] ?></div><div class="small text-muted mt-1">Estudiantes calificados</div></div>
    </div>
    <?php if(trim((string)($evaluation['description']??''))!==''): ?>
      <hr class="my-3">
      <div class="small font-weight-bold text-gray-700 mb-1">Descripción</div>
      <div class="small text-gray-700"><?= nl2br($e($evaluation['description'])) ?></div>
    <?php endif; ?>
  </div>
</div>
<div class="d-flex justify-content-end flex-wrap border-top pt-3 mt-3">
  <button type="button" class="btn btn-light btn-sm border mr-2 mb-1" data-dismiss="modal">Cerrar</button>
  <button type="button" class="btn btn-primary btn-sm mb-1 edn-view-evaluation" data-id="<?= (int)$evaluation['id'] ?>">
    <i class="fas fa-eye mr-1"></i>Ver calificaciones
  </button>
</div>
</div>
<script>
(function($){
  $('.edn-view-evaluation').off('click.edn').on('click.edn',function(){
    var id=Number($(this).data('id'));
    if (!id || typeof window.uni_modal!=='function') return;
    window.uni_modal('Calificaciones de la evaluación','notification_evaluation_grades.php?evaluation_id='+id,'modal-lg');
  });
})(jQuery);
</script>
