<?php
include_once __DIR__.'/includes/session_check.php';
require_login_modal();
include __DIR__.'/db_connect.php';
$schoolId=(int)($_SESSION['login_school_id']??0);
$loginType=(int)($_SESSION['login_type']??0);
$yearId=(int)($_GET['academic_year_id']??0);$initialAreaId=(int)($_GET['area_id']??0);$initialLevel=trim((string)($_GET['level']??''));if(!in_array($initialLevel,['Inicial','Primaria','Secundaria'],true))$initialLevel='';
if($loginType!==1||$schoolId<=0){http_response_code(403);echo '<div class="alert alert-danger">No tienes permisos para administrar competencias del área.</div>';exit;}
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$years=[];
$stmt=$conn->prepare('SELECT id,year FROM academic_year WHERE school_id=? ORDER BY year DESC');
$stmt->bind_param('i',$schoolId);$stmt->execute();$q=$stmt->get_result();while($row=$q->fetch_assoc())$years[]=$row;$stmt->close();
if(!$yearId&&$years)$yearId=(int)$years[0]['id'];
?>
<style>
.acat-page{font-size:.84rem;color:#344054}.acat-note{background:#f5f8fd;border-left:3px solid #4285f4;border-radius:5px;padding:10px 12px}.acat-table td{vertical-align:middle!important}.acat-inactive{opacity:.58}
@media(max-width:575px){.acat-page .form-row>[class*=col-]{margin-bottom:.55rem}.acat-table{font-size:.76rem}}
</style>
<div class="container-fluid acat-page">
 <div class="acat-note mb-3"><strong>Competencias curriculares del área</strong><div class="small text-muted">Se comparten con los cursos del mismo colegio, área, nivel y año académico. No llevan porcentajes; cada evaluación se asignará a una sola competencia.</div></div>
 <div id="acat-message"></div>
 <div class="form-row">
  <div class="form-group col-md-4"><label>Año académico</label><select id="acat-year" class="form-control form-control-sm"><?php foreach($years as $y):?><option value="<?=$y['id']?>" <?=$yearId===(int)$y['id']?'selected':''?>><?=htmlspecialchars($y['year'])?></option><?php endforeach?></select></div>
  <div class="form-group col-md-4"><label>Área curricular</label><select id="acat-area" class="form-control form-control-sm"><option value="">Cargando áreas...</option></select></div>
  <div class="form-group col-md-4"><label>Nivel</label><select id="acat-level" class="form-control form-control-sm"><option value="Inicial" <?=$initialLevel==='Inicial'?'selected':''?>>Inicial</option><option value="Primaria" <?=$initialLevel===''||$initialLevel==='Primaria'?'selected':''?>>Primaria</option><option value="Secundaria" <?=$initialLevel==='Secundaria'?'selected':''?>>Secundaria</option></select></div>
 </div>
 <form id="acat-form" class="border rounded p-3 mb-3">
  <input type="hidden" id="acat-id" value="">
  <div class="form-row align-items-end">
   <div class="form-group col-md-8 mb-2"><label for="acat-name">Nombre de la competencia</label><input id="acat-name" class="form-control form-control-sm" maxlength="255" required placeholder="Ej. Resuelve problemas de cantidad"></div>
   <div class="form-group col-md-2 mb-2"><label for="acat-order">Orden</label><input id="acat-order" type="number" min="0" max="999" class="form-control form-control-sm" value="0"></div>
   <div class="form-group col-md-2 mb-2 d-flex"><button class="btn btn-primary btn-sm btn-block" id="acat-save"><i class="fa fa-save mr-1"></i>Guardar</button></div>
  </div>
  <div class="text-right"><button type="button" id="acat-cancel" class="btn btn-light btn-sm d-none">Cancelar edición</button></div>
 </form>
 <div class="table-responsive"><table class="table table-sm table-bordered table-hover acat-table mb-0"><thead class="thead-light"><tr><th>Competencia</th><th class="text-center">Evaluaciones vinculadas</th><th class="text-center">Estado</th><th class="text-center">Acciones</th></tr></thead><tbody id="acat-body"><tr><td colspan="4" class="text-center text-muted py-4">Selecciona área y nivel.</td></tr></tbody></table></div>
</div>
<script>
(function($){
 const api='academic_management_api.php',csrf=<?php echo json_encode($_SESSION['csrf_token']); ?>,initialArea=<?php echo (int)$initialAreaId; ?>;
 let items=[];
 function esc(v){return $('<div>').text(v==null?'':String(v)).html()}
 function message(text,type){$('#acat-message').html('<div class="alert alert-'+type+' py-2">'+esc(text)+'</div>')}
 function reset(){ $('#acat-id').val('');$('#acat-form')[0].reset();$('#acat-order').val(0);$('#acat-cancel').addClass('d-none'); }
 function loadAreas(){
  const year=$('#acat-year').val();
  return $.getJSON(api,{action:'areas',academic_year_id:year}).done(function(r){
   let html='<option value="">Selecciona un área</option>';
   (r.areas||[]).filter(a=>+a.is_active===1).forEach(a=>html+='<option value="'+a.id+'">'+esc(a.name)+'</option>');
   $('#acat-area').html(html);
   if(initialArea && (r.areas||[]).some(a=>+a.id===initialArea&&+a.is_active===1))$('#acat-area').val(String(initialArea));
   else if((r.areas||[]).filter(a=>+a.is_active===1).length===1)$('#acat-area').val(String((r.areas||[]).filter(a=>+a.is_active===1)[0].id));
   load();
  }).fail(function(x){message((x.responseJSON||{}).message||'No se pudieron cargar las áreas.','danger')});
 }
 function load(){
  const year=$('#acat-year').val(),area=$('#acat-area').val(),level=$('#acat-level').val();
  if(!year||!area||!level){$('#acat-body').html('<tr><td colspan="4" class="text-center text-muted py-4">Selecciona área y nivel para consultar sus competencias.</td></tr>');return}
  $('#acat-body').html('<tr><td colspan="4" class="text-center py-4"><i class="fa fa-spinner fa-spin"></i></td></tr>');
  $.getJSON(api,{action:'area_competencies',academic_year_id:year,area_id:area,level:level}).done(function(r){
   items=r.competencies||[];let html='';
   items.forEach(function(c){
    const used=Number(c.usage_count||0)>0,canDeactivate=!(+c.is_active===1&&used);
    const toggleTitle=+c.is_active?(used?'No se puede desactivar: tiene evaluaciones vinculadas':'Desactivar'):'Activar';
    html+='<tr class="'+(+c.is_active?'':'acat-inactive')+'"><td>'+esc(c.name)+'</td><td class="text-center">'+esc(c.usage_count||0)+'</td><td class="text-center"><span class="badge badge-'+(+c.is_active?'success':'secondary')+'">'+(+c.is_active?'Activa':'Inactiva')+'</span></td><td class="text-center"><button type="button" class="btn btn-sm btn-outline-primary acat-edit" data-id="'+c.id+'" title="Editar"><i class="fa fa-edit"></i></button> <button type="button" class="btn btn-sm btn-outline-'+(+c.is_active?'danger':'success')+' acat-toggle" data-id="'+c.id+'" data-active="'+(+c.is_active?0:1)+'" title="'+toggleTitle+'" '+(canDeactivate?'':'disabled')+'><i class="fa fa-'+(+c.is_active?'ban':'check')+'"></i></button></td></tr>';
   });
   $('#acat-body').html(html||'<tr><td colspan="4" class="text-center text-muted py-4">Todavía no hay competencias para esta área y nivel.</td></tr>');
  }).fail(function(x){message((x.responseJSON||{}).message||'No se pudieron consultar las competencias.','danger');});
 }
 $('#acat-year').on('change',function(){reset();loadAreas()});
 $('#acat-area,#acat-level').on('change',function(){reset();load()});
 $('#acat-form').on('submit',function(e){
  e.preventDefault();
  const name=$.trim($('#acat-name').val()),area=$('#acat-area').val(),level=$('#acat-level').val(),year=$('#acat-year').val();
  if(!name||!area||!level||!year){message('Completa año, área, nivel y nombre.','warning');return}
  start_load();
  $.post(api+'?action=save_area_competency',{csrf_token:csrf,id:$('#acat-id').val(),academic_year_id:year,area_id:area,level:level,name:name,display_order:$('#acat-order').val()},null,'json').done(function(r){
   if(r.status===1){message(r.message,'success');reset();load();$(document).trigger('academic:changed')}
   else message(r.message||'No se pudo guardar.','danger');
  }).fail(function(x){message((x.responseJSON||{}).message||'No se pudo guardar.','danger')}).always(end_load);
 });
 $(document).off('click.acatEdit','.acat-edit').on('click.acatEdit','.acat-edit',function(){const c=items.find(x=>+x.id===+this.dataset.id);if(!c)return;$('#acat-id').val(c.id);$('#acat-name').val(c.name);$('#acat-order').val(c.display_order||0);$('#acat-cancel').removeClass('d-none');$('#acat-name').focus()});
 $('#acat-cancel').on('click',reset);
 $(document).off('click.acatToggle','.acat-toggle').on('click.acatToggle','.acat-toggle',function(){
  const id=this.dataset.id,active=this.dataset.active==='1';
  if(!confirm(active?'¿Activar esta competencia?':'¿Desactivar esta competencia? Solo se permite cuando no tiene evaluaciones vinculadas.'))return;
  start_load();
  $.post(api+'?action=toggle_area_competency',{csrf_token:csrf,id:id,academic_year_id:$('#acat-year').val(),is_active:active?1:0},null,'json').done(function(r){message(r.message,r.status===1?'success':'danger');if(r.status===1){load();$(document).trigger('academic:changed')}}).fail(function(x){message((x.responseJSON||{}).message||'No se pudo actualizar.','danger')}).always(end_load);
 });
 loadAreas();
})(jQuery);
</script>
