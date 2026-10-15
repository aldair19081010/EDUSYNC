<?php
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
$schoolId=(int)($_SESSION['login_school_id']??0);$userId=(int)($_SESSION['login_id']??0);$teacherId=(int)($_SESSION['login_teacher_id']??0);$type=(int)($_SESSION['login_type']??0);$id=(int)($_GET['id']??0);
$stmt=$conn->prepare("SELECT grr.*,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,ac.name course_name,ac.level,ay.year,t.name teacher_name,rv.name reviewed_by_name FROM grade_reopen_requests grr INNER JOIN teacher_courses tc ON tc.id=grr.teacher_course_id AND tc.school_id=grr.school_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=grr.academic_year_id LEFT JOIN teacher t ON t.id=grr.teacher_id LEFT JOIN users rv ON rv.id=grr.reviewed_by AND rv.school_id=grr.school_id WHERE grr.id=? AND grr.school_id=? LIMIT 1");
$stmt->bind_param('ii',$id,$schoolId);$stmt->execute();$request=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$request){echo '<div class="alert alert-warning">No se encontró la solicitud.</div>';return;}
$allowed=$type===1||($type===2&&$teacherId>0&&(int)$request['teacher_id']===$teacherId&&(int)$request['requested_by']===$userId);
if(!$allowed){echo '<div class="alert alert-danger">No tienes permiso para ver esta resolución.</div>';return;}
function gr_result_date($value){if(!$value)return '—';$ts=strtotime($value);return $ts?date('d/m/Y h:i a',$ts):htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
$status=$request['status'];$badge=$status==='Aprobada'?'success':($status==='Rechazada'?'danger':'warning');
?>
<?php
require_once __DIR__ . '/includes/notification_detail_ui.php';
edn_header($status==='Aprobada'?'success':($status==='Rechazada'?'rejected':'reopen'),
    'Resultado de reapertura: '.$request['course_name'],
    $request['level'].' · '.$request['grado'].' '.$request['seccion'],
    $request['reviewed_at']?:$request['created_at'],$status,(int)$request['bimester'].'° bimestre');
?>
<div class="row mb-3">
  <div class="col-md-6 mb-2"><div class="card shadow-sm h-100"><div class="card-body py-3">
    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Curso y aula</div>
    <div class="font-weight-bold text-gray-800"><?= edn_escape($request['course_name']) ?></div>
    <small class="text-muted"><?= edn_escape($request['level'].' · '.$request['grado'].' '.$request['seccion']) ?> · <?= edn_escape($request['year']) ?></small>
  </div></div></div>
  <div class="col-md-6 mb-2"><div class="card shadow-sm h-100"><div class="card-body py-3">
    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Revisada por</div>
    <div class="font-weight-bold text-gray-800"><?= edn_escape($request['reviewed_by_name']?:'Administración') ?></div>
    <small class="text-muted"><?= gr_result_date($request['reviewed_at']) ?></small>
  </div></div></div>
</div>
<div class="card shadow-sm mb-3"><div class="card-body">
  <h6 class="font-weight-bold text-gray-800">Motivo solicitado</h6>
  <div class="small"><?= nl2br(edn_escape($request['reason'])) ?></div>
  <?php if(trim((string)$request['review_notes'])!==''): ?>
    <hr>
    <h6 class="font-weight-bold text-gray-800">Respuesta de Administración</h6>
    <div class="small"><?= nl2br(edn_escape($request['review_notes'])) ?></div>
  <?php endif; ?>
</div></div>
<?php $statusTone=$status==='Aprobada'?'success':($status==='Rechazada'?'danger':'warning'); ?>
<div class="alert alert-<?= $statusTone ?> mb-3">
  <i class="fas <?= $status==='Aprobada'?'fa-unlock':($status==='Rechazada'?'fa-lock':'fa-clock') ?> mr-1"></i>
  <strong><?= edn_escape($status) ?>.</strong>
  <?= $status==='Aprobada'
      ?'El bimestre puede editarse nuevamente hasta que el docente lo cierre.'
      :($status==='Rechazada'?'El bimestre continúa cerrado.':'La solicitud está pendiente de revisión.') ?>
</div>
<?php edn_footer(); ?>
