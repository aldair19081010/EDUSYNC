<?php
include_once __DIR__.'/session_config.php';
include_once __DIR__.'/includes/session_check.php';
require_login_modal();
include __DIR__.'/db_connect.php';
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$teacherId=(int)($_SESSION['login_teacher_id']??0);$schoolId=(int)($_SESSION['login_school_id']??0);$type=(int)($_SESSION['login_type']??0);
$tcid=(int)($_GET['teacher_course_id']??0);$bim=(int)($_GET['bimestre']??0);$compId=(int)($_GET['competencia_id']??0);$sourceId=(int)($_GET['source_id']??0);$evaluationId=(int)($_GET['evaluation_id']??0);
if($type!==2||$teacherId<=0||$schoolId<=0||$tcid<=0){echo '<div class="alert alert-danger">Contexto de evaluación no válido.</div>';exit;}
$stmt=$conn->prepare('SELECT tc.academic_year_id,tc.course_id,ac.name course_name,ac.level,ac.area_id,COALESCE(ac.evaluation_model,"weighted") evaluation_model,tc.grado,tc.seccion FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=tc.academic_year_id WHERE tc.id=? AND tc.teacher_id=? AND tc.school_id=? AND ay.is_active=1 LIMIT 1');
$stmt->bind_param('iii',$tcid,$teacherId,$schoolId);$stmt->execute();$context=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$context){echo '<div class="alert alert-danger">La asignación no está disponible.</div>';exit;}
$evaluationModel=($context['evaluation_model']??'weighted')==='competency'?'competency':'weighted';
$areaCompetencies=[];
if($evaluationModel==='competency'){
 if($bim<0||$bim>4||($evaluationId>0&&($bim<1||$compId<=0))){echo '<div class="alert alert-danger">Bimestre o competencia no válidos para la evaluación.</div>';exit;}
 $catalog=$conn->prepare('SELECT id,name FROM area_competencies WHERE school_id=? AND academic_year_id=? AND area_id=? AND level=? AND is_active=1 ORDER BY display_order,id');
 $catalog->bind_param('iiis',$schoolId,$context['academic_year_id'],$context['area_id'],$context['level']);$catalog->execute();$catResult=$catalog->get_result();while($row=$catResult->fetch_assoc())$areaCompetencies[]=$row;$catalog->close();
 if($compId>0){
  $check=$conn->prepare('SELECT id,name FROM area_competencies WHERE id=? AND school_id=? AND academic_year_id=? AND area_id=? AND level=? AND is_active=1 LIMIT 1');
  $check->bind_param('iiiis',$compId,$schoolId,$context['academic_year_id'],$context['area_id'],$context['level']);$check->execute();$competency=$check->get_result()->fetch_assoc();$check->close();
  if(!$competency){echo '<div class="alert alert-danger">La competencia del área no está disponible para este curso.</div>';exit;}
  $context['competency_name']=$competency['name'];
 }else{
  if($evaluationId>0||$sourceId>0){echo '<div class="alert alert-danger">Selecciona una competencia válida para continuar.</div>';exit;}
  $context['competency_name']='Selecciona una competencia del área';
 }
 $context['percentage']=null;
}else{
 if($bim<1||$bim>4||$compId<=0){echo '<div class="alert alert-danger">Selecciona curso, bimestre y componente de calificación.</div>';exit;}
 $check=$conn->prepare('SELECT id,name,percentage FROM general_course_competencies WHERE id=? AND course_id=? AND teacher_id=? AND academic_year_id=? AND is_active=1 LIMIT 1');
 $check->bind_param('iiii',$compId,$context['course_id'],$teacherId,$context['academic_year_id']);$check->execute();$competency=$check->get_result()->fetch_assoc();$check->close();
 if(!$competency){echo '<div class="alert alert-danger">El componente de calificación no está disponible para este curso.</div>';exit;}
 $context['competency_name']=$competency['name'];$context['percentage']=$competency['percentage'];
}
$title='';$evaluationType='Tarea';$description='';
if($sourceId>0){$copy=$conn->prepare('SELECT title,type,description FROM evaluations WHERE id=? AND teacher_id=? AND teacher_course_id=? LIMIT 1');$copy->bind_param('iii',$sourceId,$teacherId,$tcid);$copy->execute();$source=$copy->get_result()->fetch_assoc();$copy->close();if($source){$title='Copia de '.$source['title'];$evaluationType=$source['type'];$description=$source['description'];}}
if($evaluationId>0){
 if($evaluationModel!=='competency'){echo '<div class="alert alert-danger">La edición rápida solo corresponde a evaluaciones del modelo por competencias.</div>';exit;}
 $edit=$conn->prepare("SELECT e.title,e.type,e.description FROM evaluations e INNER JOIN evaluation_area_competencies eac ON eac.evaluation_id=e.id AND eac.area_competencia_id=? WHERE e.id=? AND e.teacher_id=? AND e.teacher_course_id=? AND e.academic_year_id=? AND e.bimestre=? LIMIT 1");
 $edit->bind_param('iiiiis',$compId,$evaluationId,$teacherId,$tcid,$context['academic_year_id'],$bim);$edit->execute();$existing=$edit->get_result()->fetch_assoc();$edit->close();
 if(!$existing){echo '<div class="alert alert-danger">La evaluación no existe o no pertenece a esta competencia.</div>';exit;}
 $title=$existing['title'];$evaluationType=$existing['type'];$description=$existing['description'];
}
?>
<style>
/* Evaluación rápida dentro de la planilla: compacto y uniforme. */
#quick-evaluation-form{font-size:.81rem;color:#344054}
#quick-evaluation-form .qev-context{
  background:#f8fafd;border:1px solid #e3e6f0;border-left:3px solid #4285f4;
  border-radius:.45rem;padding:.63rem .76rem;margin-bottom:.75rem!important;
  line-height:1.42;overflow-wrap:anywhere
}
#quick-evaluation-form .form-group{margin-bottom:.72rem}
#quick-evaluation-form label{font-size:.75rem;font-weight:700;color:#526274;line-height:1.32;margin-bottom:.23rem}
#quick-evaluation-form .form-control{
  min-height:35px;font-size:.8rem;border:1px solid #d8deea;
  border-radius:.42rem;box-shadow:none
}
#quick-evaluation-form textarea.form-control{min-height:65px}
#quick-evaluation-form .border-top{
  display:flex;gap:.45rem;flex-wrap:wrap;
  justify-content:flex-end!important;margin-top:.72rem!important;padding-top:.68rem!important
}
#quick-evaluation-form .border-top .btn{
  min-height:35px;padding:.38rem .7rem;font-size:.79rem;margin:0!important;box-shadow:none!important
}
@media(max-width:575.98px){
  #quick-evaluation-form .qev-context{padding:.6rem .65rem}
  #quick-evaluation-form .border-top{align-items:center}
}
</style>
<form id="quick-evaluation-form">
 <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'],ENT_QUOTES,'UTF-8'); ?>">
 <input type="hidden" name="id" value="<?php echo $evaluationId; ?>"><input type="hidden" name="teacher_course_id" value="<?php echo $tcid; ?>"><input type="hidden" name="academic_year_id" value="<?php echo (int)$context['academic_year_id']; ?>"><input type="hidden" name="teacher_id" value="<?php echo $teacherId; ?>"><?php if($bim>=1&&$bim<=4): ?><input type="hidden" name="bimestre" value="<?php echo $bim; ?>"><?php else: ?><div class="form-group"><label class="small font-weight-bold">Bimestre</label><select class="form-control" name="bimestre" required><option value="">Selecciona un bimestre</option><?php for($i=1;$i<=4;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?>° Bimestre</option><?php endfor; ?></select></div><?php endif; ?><?php if($evaluationModel==='competency'&&$compId>0): ?><input type="hidden" name="area_competencia_id" value="<?php echo $compId; ?>"><?php elseif($evaluationModel==='competency'): ?><div class="form-group"><label class="small font-weight-bold">Competencia del área</label><select class="form-control" name="area_competencia_id" required><option value="">Selecciona una competencia</option><?php foreach($areaCompetencies as $item): ?><option value="<?php echo (int)$item['id']; ?>"><?php echo htmlspecialchars($item['name']); ?></option><?php endforeach; ?></select></div><?php else: ?><input type="hidden" name="competencias[]" value="<?php echo $compId; ?>"><?php endif; ?>
 <div class="qev-context mb-3"><strong><?php echo htmlspecialchars($context['course_name'].' · '.$context['level'].' · '.$context['grado'].' '.($context['seccion']?:'U')); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($context['competency_name']); ?><?php if($evaluationModel==='weighted'): ?> (<?php echo number_format((float)$context['percentage'],2); ?>%)<?php else: ?> · Competencia del área<?php endif; ?> <?php if($bim>=1&&$bim<=4): ?> · <?php echo $bim; ?>° Bimestre<?php else: ?> · Selecciona el bimestre<?php endif; ?></small></div>
 <div class="form-group"><label class="small font-weight-bold">Nombre de la evaluación</label><input class="form-control" name="title" maxlength="150" required autofocus value="<?php echo htmlspecialchars($title,ENT_QUOTES,'UTF-8'); ?>" placeholder="Ej. Práctica 2"></div>
 <div class="form-group"><label class="small font-weight-bold">Tipo</label><select class="form-control" name="type" required><?php foreach(['Tarea','Examen','Práctica','Proyecto','Participación','Otro'] as $option): ?><option value="<?php echo $option; ?>" <?php echo $evaluationType===$option?'selected':''; ?>><?php echo $option; ?></option><?php endforeach; ?></select></div>
 <div class="form-group"><label class="small font-weight-bold">Tema u observación <span class="text-muted font-weight-normal">(opcional)</span></label><textarea class="form-control" name="description" rows="2" placeholder="Se usará el nombre si se deja vacío"><?php echo htmlspecialchars($description); ?></textarea></div>
 <div class="d-flex justify-content-end border-top pt-3 mt-3">
  <button type="button" class="btn btn-light btn-sm mr-2" data-dismiss="modal"><i class="fas fa-times mr-1"></i>Cancelar</button>
  <button type="submit" class="btn btn-primary btn-sm" id="quick-evaluation-save"><i class="fas fa-save mr-1"></i><?php echo $evaluationId>0?"Actualizar evaluación":"Guardar evaluación"; ?></button>
 </div>
</form>
<script>
(function($){let form=$('#quick-evaluation-form'),submitting=false,button=$('#quick-evaluation-save');form.on('submit',function(e){e.preventDefault();if(submitting)return;let title=$.trim(form.find('[name=title]').val());if(!title){alert_toast('Escribe el nombre de la evaluación.','warning');form.find('[name=title]').focus();return}if(!$.trim(form.find('[name=description]').val()))form.find('[name=description]').val(title);submitting=true;button.prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Guardando...');start_load();$.ajax({url:'ajax.php?action=save_evaluation',method:'POST',data:form.serialize(),dataType:'json'}).done(r=>{alert_toast(r.message||'Evaluación guardada.',r.status==1?'success':'danger');if(r.status==1){$(document).trigger('evaluation:saved',[r]);$('#uni_modal').modal('hide')}}).fail(()=>alert_toast('No se pudo guardar la evaluación.','danger')).always(()=>{submitting=false;button.prop('disabled',false).html('<i class="fas fa-save mr-1"></i>Guardar evaluación');end_load()})});$('#uni_modal #submit').hide()})(jQuery);
</script>
