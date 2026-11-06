<?php
include_once __DIR__ . '/../includes/session_check.php';
require_login_modal();
include_once __DIR__ . '/../db_connect.php';

$schoolId=(int)($_SESSION['login_school_id']??0);
$userId=(int)($_SESSION['login_id']??0);
$role=(int)($_SESSION['login_type']??0);
if($role!==1||$schoolId<=0||$userId<=0){
    echo '<div class="container-fluid"><div class="alert alert-danger">Solo Administración puede gestionar las competencias del área.</div></div>';
    return;
}
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$csrf=$_SESSION['csrf_token'];
$years=[];
$q=$conn->prepare('SELECT id,year,is_active FROM academic_year WHERE school_id=? ORDER BY is_active DESC,year DESC');
$q->bind_param('i',$schoolId);$q->execute();$yr=$q->get_result();
while($row=$yr->fetch_assoc())$years[]=$row;$q->close();
$selectedYear=(int)($_GET['academic_year_id']??0);
if(!$selectedYear){foreach($years as $year){if((int)$year['is_active']===1){$selectedYear=(int)$year['id'];break;}}}
if(!$selectedYear&&$years)$selectedYear=(int)$years[0]['id'];
$areas=[];
if($selectedYear){
    $a=$conn->prepare('SELECT id,name,color FROM areas WHERE school_id=? AND (academic_year_id=? OR academic_year_id IS NULL) AND is_active=1 ORDER BY name');
    $a->bind_param('ii',$schoolId,$selectedYear);$a->execute();$ar=$a->get_result();while($row=$ar->fetch_assoc())$areas[]=$row;$a->close();
}
?>
<style>
.ac-area-page{padding:4px 2px}.ac-area-head{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:16px}.ac-area-title{display:flex;align-items:center;gap:12px;min-width:0}.ac-area-icon{width:44px;height:44px;flex:0 0 44px;border-radius:11px;background:#edf3ff;color:#2f6fed;display:flex;align-items:center;justify-content:center;font-size:19px}.ac-area-title h5{margin:0;font-weight:700;color:#263b52}.ac-area-title small{color:#6c757d}.ac-area-toolbar{display:grid;grid-template-columns:1.1fr 1.3fr 1fr auto;gap:12px;padding:14px;background:#fff;border:1px solid #e5eaf1;border-radius:10px;margin-bottom:14px}.ac-area-field label{display:block;font-size:.76rem;font-weight:700;color:#526274;margin-bottom:5px}.ac-area-field .form-control{height:38px}.ac-area-panel{background:#fff;border:1px solid #e5eaf1;border-radius:10px;overflow:hidden}.ac-area-panel-head{padding:12px 14px;border-bottom:1px solid #edf0f4;display:flex;justify-content:space-between;align-items:center;gap:10px}.ac-area-panel-body{padding:14px}.ac-area-table th{background:#f7f9fc;color:#526274;font-size:.76rem;text-transform:uppercase;white-space:nowrap}.ac-area-table td,.ac-area-table th{vertical-align:middle}.ac-area-empty{text-align:center;padding:42px 15px;color:#7b8794}.ac-area-muted{font-size:.78rem;color:#6c757d}.ac-area-actions{white-space:nowrap}.ac-area-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ac-area-modal-grid .wide{grid-column:1/-1}@media(max-width:760px){.ac-area-head{align-items:flex-start;flex-direction:column}.ac-area-toolbar{grid-template-columns:1fr 1fr}.ac-area-modal-grid{grid-template-columns:1fr}.ac-area-modal-grid .wide{grid-column:auto}}@media(max-width:480px){.ac-area-toolbar{grid-template-columns:1fr}.ac-area-panel-head{align-items:flex-start;flex-direction:column}.ac-area-actions .btn{padding:.25rem .45rem}}
</style>
<div class="container-fluid ac-area-page" id="ac-area-app" data-csrf="<?php echo htmlspecialchars($csrf,ENT_QUOTES,'UTF-8'); ?>">
 <div class="ac-area-head">
  <div class="ac-area-title"><div class="ac-area-icon"><i class="fas fa-tasks"></i></div><div><h5>Competencias del área</h5><small>Catálogo curricular compartido por los cursos del área y nivel.</small></div></div>
  <button type="button" class="btn btn-primary" id="ac-area-new"><i class="fa fa-plus mr-1"></i>Nueva competencia</button>
 </div>
 <div class="ac-area-toolbar">
  <div class="ac-area-field"><label for="ac-area-year">Año académico</label><select id="ac-area-year" class="form-control"><?php foreach($years as $year):?><option value="<?php echo (int)$year['id'];?>" <?php echo $selectedYear===(int)$year['id']?'selected':'';?>><?php echo htmlspecialchars((string)$year['year']);?><?php echo (int)$year['is_active']===1?' · Activo':'';?></option><?php endforeach;?></select></div>
  <div class="ac-area-field"><label for="ac-area">Área curricular</label><select id="ac-area" class="form-control"><option value="">Selecciona un área</option><?php foreach($areas as $area):?><option value="<?php echo (int)$area['id'];?>"><?php echo htmlspecialchars($area['name']);?></option><?php endforeach;?></select></div>
  <div class="ac-area-field"><label for="ac-area-level">Nivel</label><select id="ac-area-level" class="form-control"><option value="">Selecciona un nivel</option><option>Inicial</option><option>Primaria</option><option>Secundaria</option></select></div>
  <div class="ac-area-field"><label>&nbsp;</label><button type="button" id="ac-area-reload" class="btn btn-outline-secondary btn-block"><i class="fa fa-sync-alt mr-1"></i>Consultar</button></div>
 </div>
 <div class="ac-area-panel">
  <div class="ac-area-panel-head"><div><strong id="ac-area-current">Selecciona un área y nivel</strong><div class="ac-area-muted">Las competencias se comparten entre los cursos vinculados a esta área y nivel.</div></div><span class="badge badge-light" id="ac-area-count">0 competencias</span></div>
  <div class="ac-area-panel-body">
   <div id="ac-area-message"></div>
   <div class="table-responsive"><table class="table table-bordered table-hover ac-area-table mb-0"><thead><tr><th>Código</th><th>Competencia</th><th>Descripción</th><th class="text-center">Evaluaciones</th><th class="text-center">Estado</th><th class="text-center">Acciones</th></tr></thead><tbody id="ac-area-body"><tr><td colspan="6" class="ac-area-empty">Selecciona un área curricular y un nivel para consultar sus competencias.</td></tr></tbody></table></div>
  </div>
 </div>
</div>
<div class="modal fade" id="ac-area-modal" tabindex="-1" role="dialog" aria-labelledby="ac-area-modal-title" aria-hidden="true">
 <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="ac-area-modal-title">Nueva competencia del área</h5><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button></div>
  <form id="ac-area-form">
   <div class="modal-body">
    <input type="hidden" name="id" id="ac-area-id"><input type="hidden" name="academic_year_id" id="ac-area-form-year"><input type="hidden" name="area_id" id="ac-area-form-area"><input type="hidden" name="level" id="ac-area-form-level"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf,ENT_QUOTES,'UTF-8');?>">
    <div class="alert alert-light border small">La competencia pertenece al área curricular, no a un docente ni a un curso específico. No lleva porcentajes.</div>
    <div class="ac-area-modal-grid">
     <div class="form-group"><label for="ac-area-code">Código (opcional)</label><input class="form-control" id="ac-area-code" name="code" maxlength="50" placeholder="Ej. MAT-C1"></div>
     <div class="form-group"><label for="ac-area-order">Orden de visualización</label><input class="form-control" id="ac-area-order" name="display_order" type="number" min="0" step="1" value="0"></div>
     <div class="form-group wide"><label for="ac-area-name">Nombre de la competencia <span class="text-danger">*</span></label><input class="form-control" id="ac-area-name" name="name" maxlength="255" required></div>
     <div class="form-group wide"><label for="ac-area-description">Descripción</label><textarea class="form-control" id="ac-area-description" name="description" rows="3" maxlength="2000"></textarea></div>
    </div>
    <div id="ac-area-form-message"></div>
   </div>
   <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="fa fa-save mr-1"></i>Guardar competencia</button></div>
  </form>
 </div></div>
</div>
<script>
(function($){
 'use strict';
 var $app=$('#ac-area-app'),api='academic_management_api.php',csrf=$app.data('csrf'),items=[];
 function esc(v){return $('<div>').text(v==null?'':String(v)).html()}
 function message(text,type){$('#ac-area-message').html(text?'<div class="alert alert-'+(type||'info')+' py-2">'+esc(text)+'</div>':'')}
 function contextReady(){return !!($('#ac-area-year').val()&&$('#ac-area').val()&&$('#ac-area-level').val())}
 function loadAreas(selected){
  return $.getJSON(api,{action:'areas',academic_year_id:$('#ac-area-year').val()}).done(function(r){
   var html='<option value="">Selecciona un área</option>';
   (r.areas||[]).forEach(function(a){if(+a.is_active)html+='<option value="'+a.id+'">'+esc(a.name)+'</option>'});
   $('#ac-area').html(html);if(selected)$('#ac-area').val(String(selected));
  }).fail(function(x){message((x.responseJSON||{}).message||'No se pudieron cargar las áreas.','danger')});
 }
 function render(){
  var h='';
  items.forEach(function(c){
   h+='<tr><td>'+esc(c.code||'—')+'</td><td><strong>'+esc(c.name)+'</strong></td><td><span class="ac-area-muted">'+esc(c.description||'—')+'</span></td><td class="text-center"><span class="badge badge-info">'+(+c.evaluation_count||0)+'</span></td><td class="text-center"><span class="badge badge-'+(+c.is_active?'success':'secondary')+'">'+(+c.is_active?'Activa':'Inactiva')+'</span></td><td class="text-center ac-area-actions"><button type="button" class="btn btn-sm btn-outline-primary ac-area-edit" data-id="'+c.id+'" title="Editar"><i class="fa fa-edit"></i></button> <button type="button" class="btn btn-sm btn-outline-'+(+c.is_active?'danger':'success')+' ac-area-toggle" data-id="'+c.id+'" data-active="'+(+c.is_active?0:1)+'" title="'+(+c.is_active?'Desactivar':'Activar')+'"><i class="fa fa-'+(+c.is_active?'ban':'check')+'"></i></button></td></tr>';
  });
  $('#ac-area-body').html(h||'<tr><td colspan="6" class="ac-area-empty">No hay competencias registradas para esta área y nivel.</td></tr>');
  $('#ac-area-count').text(items.length+' competencias');
 }
 function load(){
  if(!contextReady()){
   items=[];render();$('#ac-area-current').text('Selecciona un área y nivel');return;
  }
  message('');$('#ac-area-body').html('<tr><td colspan="6" class="ac-area-empty"><i class="fa fa-spinner fa-spin mr-1"></i>Cargando competencias...</td></tr>');
  $.getJSON(api,{action:'area_competencies',academic_year_id:$('#ac-area-year').val(),area_id:$('#ac-area').val(),level:$('#ac-area-level').val()}).done(function(r){
   if(r.status!==1){message(r.message||'No se pudieron cargar las competencias.','warning');items=[];render();return}
   items=r.competencies||[];$('#ac-area-current').text((r.area||{}).name+' · '+$('#ac-area-level').val());render();
  }).fail(function(x){var r=x.responseJSON||{};message(r.message||'No se pudieron cargar las competencias.','danger');items=[];render()});
 }
 function resetForm(){
  $('#ac-area-form')[0].reset();$('#ac-area-id').val('');$('#ac-area-form-year').val($('#ac-area-year').val());$('#ac-area-form-area').val($('#ac-area').val());$('#ac-area-form-level').val($('#ac-area-level').val());$('#ac-area-order').val(0);$('#ac-area-form-message').empty();
 }
 function openNew(){
  if(!contextReady())return alert_toast('Selecciona primero el año, área y nivel.','warning');
  resetForm();$('#ac-area-modal-title').text('Nueva competencia del área');$('#ac-area-modal').modal('show');
 }
 $('#ac-area-year').on('change',function(){loadAreas().always(function(){$('#ac-area').val('');load()})});
 $('#ac-area-reload,#ac-area').on('click change',function(){if(this.id==='ac-area-reload')load();});
 $('#ac-area-level').on('change',load);
 $('#ac-area-new').on('click',openNew);
 $('#ac-area-form').on('submit',function(e){
  e.preventDefault();if(!contextReady())return;
  $.post(api+'?action=save_area_competency',$(this).serialize(),null,'json').done(function(r){
   if(r.status===1){alert_toast(r.message||'Competencia guardada.','success');$('#ac-area-modal').modal('hide');load();$(document).trigger('academic:changed')}
   else $('#ac-area-form-message').html('<div class="alert alert-warning py-2">'+esc(r.message||'No se pudo guardar.')+'</div>');
  }).fail(function(x){$('#ac-area-form-message').html('<div class="alert alert-danger py-2">'+esc((x.responseJSON||{}).message||'Error del servidor.')+'</div>')});
 });
 $(document).on('click','.ac-area-edit',function(){
  var c=items.find(function(x){return +x.id===+$(this).data('id')}.bind(this));if(!c)return;
  resetForm();$('#ac-area-id').val(c.id);$('#ac-area-code').val(c.code||'');$('#ac-area-name').val(c.name||'');$('#ac-area-description').val(c.description||'');$('#ac-area-order').val(c.display_order||0);
  if(+c.evaluation_count>0){$('#ac-area-form-message').html('<div class="alert alert-info py-2">Esta competencia ya tiene evaluaciones. El nombre, código, área y nivel se mantienen protegidos para conservar su historial.</div>');$('#ac-area-name,#ac-area-code').prop('readonly',true)}
  else $('#ac-area-name,#ac-area-code').prop('readonly',false);
  $('#ac-area-modal-title').text('Editar competencia del área');$('#ac-area-modal').modal('show');
 });
 $(document).on('click','.ac-area-toggle',function(){
  var id=+$(this).data('id'),active=+$(this).data('active');
  if(!confirm(active?'¿Activar esta competencia?':'¿Desactivar esta competencia? Las evaluaciones históricas se conservarán.'))return;
  $.post(api+'?action=toggle_area_competency',{id:id,academic_year_id:$('#ac-area-year').val(),is_active:active,csrf_token:csrf},null,'json').done(function(r){alert_toast(r.message||'Operación completada.',r.status===1?'success':'danger');if(r.status===1)load()}).fail(function(x){alert_toast((x.responseJSON||{}).message||'No se pudo actualizar.','danger')});
 });
 $('#ac-area-modal').on('hidden.bs.modal',function(){$('#ac-area-name,#ac-area-code').prop('readonly',false)});
 load();
})(jQuery);
</script>
