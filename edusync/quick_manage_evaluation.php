<?php
include_once __DIR__.'/session_config.php';
include_once __DIR__.'/includes/session_check.php';
require_login_modal();
include __DIR__.'/db_connect.php';
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$teacherId=(int)($_SESSION['login_teacher_id']??0);$schoolId=(int)($_SESSION['login_school_id']??0);$type=(int)($_SESSION['login_type']??0);
$tcid=(int)($_GET['teacher_course_id']??0);$bim=(int)($_GET['bimestre']??0);$compId=(int)($_GET['competencia_id']??0);$sourceId=(int)($_GET['source_id']??0);
if($type!==2||$teacherId<=0||$schoolId<=0||$tcid<=0||$bim<1||$bim>4||$compId<=0){echo '<div class="alert alert-danger">Contexto de evaluación no válido.</div>';exit;}
$stmt=$conn->prepare('SELECT tc.academic_year_id,ac.name course_name,ac.level,tc.grado,tc.seccion,gcc.name competency_name,gcc.percentage FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=tc.academic_year_id INNER JOIN general_course_competencies gcc ON gcc.id=? AND gcc.course_id=tc.course_id AND gcc.teacher_id=tc.teacher_id AND gcc.academic_year_id=tc.academic_year_id WHERE tc.id=? AND tc.teacher_id=? AND tc.school_id=? AND ay.is_active=1 LIMIT 1');
$stmt->bind_param('iiii',$compId,$tcid,$teacherId,$schoolId);$stmt->execute();$context=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$context){echo '<div class="alert alert-danger">La competencia o asignación no está disponible.</div>';exit;}
$title='';$evaluationType='Tarea';$description='';
if($sourceId>0){$copy=$conn->prepare('SELECT title,type,description FROM evaluations WHERE id=? AND teacher_id=? AND teacher_course_id=? LIMIT 1');$copy->bind_param('iii',$sourceId,$teacherId,$tcid);$copy->execute();$source=$copy->get_result()->fetch_assoc();$copy->close();if($source){$title='Copia de '.$source['title'];$evaluationType=$source['type'];$description=$source['description'];}}
?>
<style>.qev-context{background:#f8f9fc;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.4rem;padding:.7rem .85rem}</style>
<form id="quick-evaluation-form">
 <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'],ENT_QUOTES,'UTF-8'); ?>">
 <input type="hidden" name="teacher_course_id" value="<?php echo $tcid; ?>"><input type="hidden" name="academic_year_id" value="<?php echo (int)$context['academic_year_id']; ?>"><input type="hidden" name="teacher_id" value="<?php echo $teacherId; ?>"><input type="hidden" name="bimestre" value="<?php echo $bim; ?>"><input type="hidden" name="competencias[]" value="<?php echo $compId; ?>">
 <div class="qev-context mb-3"><strong><?php echo htmlspecialchars($context['course_name'].' · '.$context['level'].' · '.$context['grado'].' '.($context['seccion']?:'U')); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($context['competency_name']); ?> (<?php echo number_format((float)$context['percentage'],2); ?>%) · <?php echo $bim; ?>° Bimestre</small></div>
 <div class="form-group"><label class="small font-weight-bold">Nombre de la evaluación</label><input class="form-control" name="title" maxlength="150" required autofocus value="<?php echo htmlspecialchars($title,ENT_QUOTES,'UTF-8'); ?>" placeholder="Ej. Práctica 2"></div>
 <div class="form-group"><label class="small font-weight-bold">Tipo</label><select class="form-control" name="type" required><?php foreach(['Tarea','Examen','Práctica','Proyecto','Participación','Otro'] as $option): ?><option value="<?php echo $option; ?>" <?php echo $evaluationType===$option?'selected':''; ?>><?php echo $option; ?></option><?php endforeach; ?></select></div>
 <div class="form-group"><label class="small font-weight-bold">Tema u observación <span class="text-muted font-weight-normal">(opcional)</span></label><textarea class="form-control" name="description" rows="2" placeholder="Se usará el nombre si se deja vacío"><?php echo htmlspecialchars($description); ?></textarea></div>
 <div class="d-flex justify-content-end border-top pt-3 mt-3">
  <button type="button" class="btn btn-light btn-sm mr-2" data-dismiss="modal"><i class="fas fa-times mr-1"></i>Cancelar</button>
  <button type="submit" class="btn btn-primary btn-sm" id="quick-evaluation-save"><i class="fas fa-save mr-1"></i>Guardar evaluación</button>
 </div>
</form>
<script>
(function($){let form=$('#quick-evaluation-form'),submitting=false,button=$('#quick-evaluation-save');form.on('submit',function(e){e.preventDefault();if(submitting)return;let title=$.trim(form.find('[name=title]').val());if(!title){alert_toast('Escribe el nombre de la evaluación.','warning');form.find('[name=title]').focus();return}if(!$.trim(form.find('[name=description]').val()))form.find('[name=description]').val(title);submitting=true;button.prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Guardando...');start_load();$.ajax({url:'ajax.php?action=save_evaluation',method:'POST',data:form.serialize(),dataType:'json'}).done(r=>{alert_toast(r.message||'Evaluación guardada.',r.status==1?'success':'danger');if(r.status==1){$(document).trigger('evaluation:saved',[r]);$('#uni_modal').modal('hide')}}).fail(()=>alert_toast('No se pudo guardar la evaluación.','danger')).always(()=>{submitting=false;button.prop('disabled',false).html('<i class="fas fa-save mr-1"></i>Guardar evaluación');end_load()})});$('#uni_modal #submit').hide()})(jQuery);
</script>
