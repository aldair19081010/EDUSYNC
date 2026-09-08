<?php
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$schoolId=(int)($_SESSION['login_school_id']??0);$userId=(int)($_SESSION['login_id']??0);$id=(int)($_GET['id']??0);
$role=$conn->prepare('SELECT type FROM users WHERE id=? AND school_id=? LIMIT 1');$role->bind_param('ii',$userId,$schoolId);$role->execute();$roleRow=$role->get_result()->fetch_assoc();$role->close();
if(!$roleRow||(int)$roleRow['type']!==1){echo '<div class="alert alert-danger">Solo Administración puede autorizar reaperturas de notas.</div>';return;}
$stmt=$conn->prepare("SELECT grr.*,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,ac.name course_name,ac.level,ay.year,t.name teacher_name,u.name requested_by_name,rv.name reviewed_by_name FROM grade_reopen_requests grr INNER JOIN teacher_courses tc ON tc.id=grr.teacher_course_id AND tc.school_id=grr.school_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=grr.academic_year_id LEFT JOIN teacher t ON t.id=grr.teacher_id LEFT JOIN users u ON u.id=grr.requested_by AND u.school_id=grr.school_id LEFT JOIN users rv ON rv.id=grr.reviewed_by AND rv.school_id=grr.school_id WHERE grr.id=? AND grr.school_id=? LIMIT 1");
$stmt->bind_param('ii',$id,$schoolId);$stmt->execute();$request=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$request){echo '<div class="alert alert-warning">La solicitud no existe o pertenece a otra institución.</div>';return;}
function grr_date($value){if(!$value)return '—';$ts=strtotime($value);return $ts?date('d/m/Y h:i a',$ts):htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
$status=$request['status'];$badge=$status==='Pendiente'?'warning':($status==='Aprobada'?'success':'danger');
?>
<style>.grr-box{border:1px solid #e3e6f0;border-radius:.5rem;background:#f8f9fc;padding:.85rem 1rem}.grr-label{font-size:.72rem;text-transform:uppercase;font-weight:800;color:#6c757d}.grr-value{font-weight:700;color:#344054}.grr-context{border-left:4px solid #4e73df}</style>
<div id="grr-message"></div>
<div class="d-flex justify-content-between align-items-center mb-3"><div><span class="badge badge-<?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span><span class="badge badge-light border ml-1"><?php echo (int)$request['bimester']; ?>° Bimestre</span></div><small class="text-muted"><?php echo grr_date($request['created_at']); ?></small></div>
<div class="grr-box grr-context mb-3"><div class="grr-label">Curso y aula</div><div class="grr-value"><?php echo htmlspecialchars($request['course_name'].' · '.$request['level'].' · '.$request['grado'].' '.$request['seccion'],ENT_QUOTES,'UTF-8'); ?></div><div class="small text-muted mt-1">Año académico <?php echo htmlspecialchars($request['year']); ?></div></div>
<div class="row mb-3"><div class="col-md-6 mb-2 mb-md-0"><div class="grr-box h-100"><div class="grr-label">Docente</div><div class="grr-value"><?php echo htmlspecialchars($request['teacher_name']?:$request['requested_by_name']?:'Docente'); ?></div></div></div><div class="col-md-6"><div class="grr-box h-100"><div class="grr-label">Motivo de reapertura</div><div><?php echo nl2br(htmlspecialchars($request['reason'],ENT_QUOTES,'UTF-8')); ?></div></div></div></div>
<?php if($status==='Pendiente'): ?>
<div class="form-group"><label class="small font-weight-bold">Observación de Administración</label><textarea id="grr-notes" class="form-control" maxlength="500" rows="3" placeholder="Opcional al aprobar; obligatoria al rechazar"></textarea></div>
<div class="d-flex justify-content-end"><button type="button" class="btn btn-outline-danger mr-2 grr-review" data-decision="Rechazada"><i class="fas fa-times mr-1"></i>Rechazar</button><button type="button" class="btn btn-success grr-review" data-decision="Aprobada"><i class="fas fa-unlock mr-1"></i>Autorizar reapertura</button></div>
<?php else: ?>
<div class="alert alert-<?php echo $status==='Aprobada'?'success':'danger'; ?> mb-0"><strong>Solicitud <?php echo strtolower(htmlspecialchars($status)); ?>.</strong><br>Revisada por: <?php echo htmlspecialchars($request['reviewed_by_name']?:'Administración'); ?><br>Fecha: <?php echo grr_date($request['reviewed_at']); ?><?php if(trim((string)$request['review_notes'])!==''): ?><br>Observación: <?php echo nl2br(htmlspecialchars($request['review_notes'],ENT_QUOTES,'UTF-8')); ?><?php endif; ?></div>
<?php endif; ?>
<script>
(function($){
 $('.grr-review').off('click.gradeReopen').on('click.gradeReopen',function(){
   var decision=$(this).data('decision'),notes=$.trim($('#grr-notes').val());
   if(decision==='Rechazada'&&notes.length<3){$('#grr-message').html('<div class="alert alert-warning">Indica el motivo del rechazo.</div>');return;}
   $('.grr-review').prop('disabled',true);
   $.post('grade_closure_api.php?action=review_reopen',{csrf_token:<?php echo json_encode($csrf); ?>,id:<?php echo $id; ?>,decision:decision,notes:notes},null,'json')
    .done(function(r){
      if(!r||Number(r.status)!==1){$('.grr-review').prop('disabled',false);$('#grr-message').html('<div class="alert alert-danger">'+$('<div>').text((r&&r.message)||'No se pudo revisar la solicitud.').html()+'</div>');return;}
      if(typeof alert_toast==='function')alert_toast(r.message,'success');
      $(document).trigger('grade:reopen-reviewed',[<?php echo $id; ?>]);
      if(typeof window.refreshUnifiedNotifications==='function')window.refreshUnifiedNotifications();
      setTimeout(function(){
        $.get('review_grade_reopen_request.php?id=<?php echo $id; ?>').done(function(html){$('#uni_modal_body').html(html);});
      },250);
    })
    .fail(function(x){$('.grr-review').prop('disabled',false);$('#grr-message').html('<div class="alert alert-danger">'+$('<div>').text((x.responseJSON||{}).message||'No se pudo revisar la solicitud.').html()+'</div>');});
 });
})(jQuery);
</script>
