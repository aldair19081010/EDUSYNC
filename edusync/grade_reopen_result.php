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
<div class="edn-grid">
  <div class="edn-card"><span class="edn-card-label">Curso y aula</span>
    <strong class="edn-card-value"><?= edn_escape($request['course_name']) ?></strong>
    <span class="edn-card-help"><?= edn_escape($request['level'].' · '.$request['grado'].' '.$request['seccion']) ?> · <?= edn_escape($request['year']) ?></span></div>
  <div class="edn-card"><span class="edn-card-label">Resolución administrativa</span>
    <strong class="edn-card-value"><?= edn_escape($request['reviewed_by_name']?:'Administración') ?></strong>
    <span class="edn-card-help"><?= gr_result_date($request['reviewed_at']) ?></span></div>
</div>
<section class="edn-section">
  <h6 class="edn-section-title"><i class="fas fa-comment-alt"></i>Motivo presentado</h6>
  <div><?= nl2br(edn_escape($request['reason'])) ?></div>
</section>
<?php if(trim((string)$request['review_notes'])!==''): ?>
<section class="edn-section"><h6 class="edn-section-title"><i class="fas fa-clipboard-check"></i>Respuesta de Administración</h6>
  <div><?= nl2br(edn_escape($request['review_notes'])) ?></div>
</section>
<?php endif; ?>
<div class="edn-highlight">
  <div class="edn-hero-icon"><i class="fas <?= $status==='Aprobada'?'fa-unlock-alt':($status==='Rechazada'?'fa-lock':'fa-clock') ?>"></i></div>
  <div class="edn-highlight-label"><strong><?= edn_escape($status) ?></strong>
    <div><?= $status==='Aprobada'
      ?'El bimestre ha sido reabierto y permite modificaciones hasta su próximo cierre.'
      :($status==='Rechazada'?'El bimestre continúa cerrado.':'La solicitud aún no tiene una resolución.') ?></div>
  </div>
</div>
<?php edn_footer(); ?>
