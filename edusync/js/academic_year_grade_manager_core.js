/* EduSync · Control de cierre por bimestre integrado en la pestaña Años Académicos.
 * No modifica el cierre que realizan los docentes. Reutiliza las API administrativas.
 */
(function($){
'use strict';
if(!$ || !document.getElementById('aym-inline-root'))return;
var qs=new URLSearchParams(location.search),yearId=Number(qs.get('id')||0);
if(!yearId||qs.get('page')!=='academic_year'||qs.get('manage')!=='1')return;
var currentBimester=0,gradeData=null,closureMeta={},list=[],yearStatus='',grid=null,chosen=new Set(),requestIndex=0,action=null;
function esc(v){return $('<span>').text(v==null?'':String(v)).html();}
function toast(s,k){if(typeof window.alert_toast==='function')window.alert_toast(s,k||'success');else alert(s);}
function csrf(){return window.EDUSYNC_CSRF||$('#edusync-global-csrf').val()||$('input[name="csrf_token"]').first().val()||'';}
function roman(x){return ['','I','II','III','IV'][Number(x)]||String(x);}
function grade(x){var v=String(x||'').replace(/[º°]+$/,'');return v?v+'°':'';}
function aula(x){return esc(x.level)+' · '+esc(grade(x.grado))+' '+esc(x.seccion);}
function fmt(v){if(!v)return '—';var a=String(v).match(/^(\d{4})-(\d\d)-(\d\d)/);return a?a[3]+'/'+a[2]+'/'+a[1]:'—';}
function apiGet(file,action,extra){return $.getJSON(file,$.extend({action:action,academic_year_id:yearId,bimester:currentBimester},extra||{}));}
function apiPost(action,extra){return $.ajax({url:'admin_grade_closure_api.php',method:'POST',dataType:'json',data:$.extend({action:action,academic_year_id:yearId,bimester:currentBimester,csrf_token:csrf()},extra||{})});}
function period(){var p=gradeData&&gradeData.periods||{};return p[currentBimester]||p[String(currentBimester)]||{summary:{},items:[]};}
function locked(){return !!Number((period().summary||{}).locked);}
function editable(){return yearStatus==='Activo'&&!locked();}
function stateBadge(x){var tone={'Cerrado':'success','Listo':'primary','Incompleto':'warning','Reabierto':'info','Reapertura pendiente':'danger'};
return '<span class="badge badge-'+(tone[x]||'secondary')+'">'+esc(x||'Desconocido')+'</span>';}
function find(x){return list.find(function(y){return Number(y.teacher_course_id)===Number(x);})||null;}
function notice(xhr,txt){var d=xhr&&xhr.responseJSON||{};return d.message||txt||'No se pudo completar la operación.';}
function tabFocus(){var node=document.getElementById('aym-inline-root');if(node)node.scrollIntoView({behavior:'smooth',block:'nearest'});}
function displayError(msg){$('#aym-inline-root').html('<div class="alert alert-warning mb-3">'+esc(msg)+'</div>');}
function statusBadge(s){return '<span class="badge badge-'+(locked()?'info':yearStatus==='Activo'?'warning':'secondary')+'">'+(locked()?'Bloqueo institucional activo':yearStatus==='Activo'?'Bimestre abierto':'Solo lectura')+'</span>';}
function stat(label,value,icon,color){return '<div class="col-xl-3 col-6 mb-2"><div class="aym-inline-stat"><span class="aym-inline-icon '+color+'"><i class="fas fa-'+icon+'"></i></span><div><small>'+label+'</small><strong>'+Number(value||0).toLocaleString('es-PE')+'</strong></div></div></div>';}
function destroyGrid(){
    if(grid){grid.destroy();grid=null;}
}
function chosenAllowed(){
  var allowed=new Set(list.filter(function(x){return x.ready&&!x.closed;}).map(function(x){return Number(x.teacher_course_id);}));
  chosen.forEach(function(id){if(!allowed.has(id))chosen.delete(id);});
}
function buttons(x){
  var id=Number(x.teacher_course_id);
  if(x.closed)return '<button type="button" class="btn btn-sm btn-outline-info aym-detail" data-id="'+id+'">Ver cierre</button>';
  var normal=x.ready&&editable()&&!x.pending_reopen;
  if(normal)return '<button type="button" class="btn btn-sm btn-outline-success aym-close" data-id="'+id+'">Cerrar</button>';
  return '<button type="button" class="btn btn-sm btn-outline-secondary aym-detail" data-id="'+id+'">'+(x.ready?'Ver detalle':'Ver pendientes')+'</button>';
}
function row(x){
  var id=Number(x.teacher_course_id),meta=closureMeta[id]||{};
  var progress=Math.max(0,Math.min(100,Number(x.grade_progress||0)));
  return '<tr><td><input type="checkbox" class="aym-select" value="'+id+'" '+(chosen.has(id)?'checked ':'')+
    (!x.ready||x.closed||x.pending_reopen||!editable()?'disabled':'')+' aria-label="Seleccionar asignación"></td>'+
    '<td><strong>'+esc(x.course_name)+'</strong><div class="small text-muted">'+aula(x)+'</div></td>'+
    '<td>'+esc(x.teacher_name)+'</td>'+
    '<td data-order="'+progress+'"><strong>'+progress.toFixed(1)+'%</strong><div class="progress aym-inline-progress"><div class="progress-bar '+(progress>=100?'bg-success':'bg-warning')+'" style="width:'+progress+'%"></div></div></td>'+
    '<td>'+stateBadge(x.state)+'</td>'+
    '<td class="small">'+(x.closed?'<strong>'+esc(meta.mode||'Cerrado')+'</strong><div class="text-muted">'+fmt(meta.closed_at||x.closed_at)+'</div>':'—')+'</td>'+
    '<td class="text-nowrap">'+buttons(x)+'</td></tr>';
}
function unique(key){return Array.from(new Set(list.map(function(x){return String(x[key]||'');}).filter(Boolean))).sort(function(a,b){return a.localeCompare(b,'es',{numeric:true});});}
function options(items){return '<option value="">Todos</option>'+items.map(function(x){return '<option value="'+esc(x)+'">'+esc(x)+'</option>';}).join('');}
function renderHeader(){
  var s=period().summary||{};
  var done=Number(s.total||0)>0?Math.round(100*Number(s.closed||0)/Number(s.total)):0;
  var head='<div class="card shadow-sm border-0 mb-3 aym-inline-card"><div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap">'+
    '<div><h6 class="font-weight-bold mb-1 text-gray-800">Control de cierres · '+roman(currentBimester)+' Bimestre</h6>'+
    '<small class="text-muted">Consulta los pendientes y administra las asignaciones sin salir de esta pestaña.</small></div>'+
    '<div class="d-flex align-items-center flex-wrap aym-inline-actions">'+statusBadge(s)+'<button type="button" class="btn btn-sm btn-light border aym-hide">Ocultar detalle</button></div></div>'+
    '<div class="card-body pb-2">'+
    '<div class="row">'+stat('Asignaciones',s.total,'layer-group','info')+stat('Cerradas',s.closed,'check-circle','success')+
      stat('Listas',s.ready,'clipboard-check','primary')+stat('Incompletas',s.incomplete,'exclamation-triangle','warning')+'</div>'+
    '<div class="small d-flex justify-content-between mt-2"><span class="text-muted">Cumplimiento de cierres del bimestre</span><strong>'+done+'%</strong></div>'+
    '<div class="progress aym-inline-big-progress mb-2"><div class="progress-bar bg-success" style="width:'+done+'%"></div></div>'+
    '<div class="small text-muted mb-1">Listo = notas verificadas, pero aún sin cierre. Cerrado = asignación formalmente bloqueada.</div>'+
    '<div class="mt-3 d-flex align-items-end flex-wrap aym-inline-filters"><div><label for="aym-bim">Bimestre</label><select class="form-control form-control-sm" id="aym-bim">'+
    [1,2,3,4].map(function(b){return '<option value="'+b+'"'+(currentBimester===b?' selected':'')+'>'+roman(b)+'</option>';}).join('')+'</select></div>'+
    '<div><label for="aym-level">Nivel</label><select class="form-control form-control-sm" id="aym-level">'+options(unique('level'))+'</select></div>'+
    '<div><label for="aym-teacher">Docente</label><select class="form-control form-control-sm" id="aym-teacher">'+options(unique('teacher_name'))+'</select></div>'+
    '<div><label for="aym-state">Estado</label><select class="form-control form-control-sm" id="aym-state">'+
    options(['Cerrado','Listo','Incompleto','Reabierto','Reapertura pendiente'])+'</select></div>'+
    '<button type="button" class="btn btn-sm btn-light border aym-clear">Limpiar</button></div></div></div>';
  return head;
}
function renderPanel(){
  destroyGrid();
  chosenAllowed();
  var can=editable();
  var tools='<div class="d-flex justify-content-between align-items-center flex-wrap px-3 py-3 aym-inline-tablebar">'+
    '<div><strong>Asignaciones del bimestre</strong><div class="small text-muted">Selecciona solo las asignaciones listas para realizar cierres masivos.</div></div>'+
    '<div class="aym-inline-actions d-flex flex-wrap">'+
    (can?'<button type="button" class="btn btn-sm btn-outline-success aym-close-selected" disabled>Cerrar seleccionados</button>'+
    '<button type="button" class="btn btn-sm btn-success aym-close-ready">Cerrar todos los listos</button>':'')+'</div></div>';
  var table='<div class="table-responsive"><table id="aym-table" class="table table-sm table-hover ed-table w-100 mb-0"><thead class="thead-light"><tr>'+
    '<th><input type="checkbox" id="aym-select-all" aria-label="Seleccionar asignaciones de la página"></th>'+
    '<th>Curso y aula</th><th>Docente</th><th>Notas</th><th>Estado</th><th>Cierre</th><th>Acciones</th>'+
    '</tr></thead><tbody>'+list.map(row).join('')+'</tbody></table></div>';
  $('#aym-inline-root').html(renderHeader()+'<div class="card shadow-sm mb-3 aym-inline-card">'+tools+table+'</div>');
  if($.fn.DataTable&&list.length){
    grid=$('#aym-table').DataTable({pageLength:10,lengthMenu:[[10,25,50],[10,25,50]],order:[[1,'asc']],
      autoWidth:false,columnDefs:[{orderable:false,targets:[0,6]}],
      language:{search:'Buscar:',lengthMenu:'Mostrar _MENU_',info:'_START_ a _END_ de _TOTAL_ asignaciones',infoEmpty:'Sin asignaciones',zeroRecords:'No hay registros para esos filtros',paginate:{previous:'Anterior',next:'Siguiente'}}
    });
    grid.on('draw',function(){ $('#aym-table .aym-select').each(function(){this.checked=chosen.has(Number(this.value));});syncSelection(); });
  }
  applyFilters();
  syncSelection();
}
function applyFilters(){
  if(!grid){
    var lv=$('#aym-level').val(),tc=$('#aym-teacher').val(),state=$('#aym-state').val();
    $('#aym-table tbody tr').each(function(i){var x=list[i];$(this).toggle(!!x&&(!lv||x.level===lv)&&(!tc||x.teacher_name===tc)&&(!state||x.state===state));});
    syncSelection();return;
  }
  var level=$('#aym-level').val()||'',teacher=$('#aym-teacher').val()||'',state=$('#aym-state').val()||'';
  // DataTables mantiene su buscador nativo, sin duplicar controles de búsqueda.
  $.fn.dataTable.ext.search=$.fn.dataTable.ext.search.filter(function(fn){return fn._aymOwner!=='inline-closure';});
  var tester=function(settings,display,idx){
    if(settings.nTable.id!=='aym-table')return true;
    var node=grid.row(idx).node(),id=Number($(node).find('.aym-select').val()),x=find(id);
    return !!x&&(!level||x.level===level)&&(!teacher||x.teacher_name===teacher)&&(!state||x.state===state);
  };
  tester._aymOwner='inline-closure';
  $.fn.dataTable.ext.search.push(tester);
  grid.draw();syncSelection();
}
function syncSelection(){
  var visible=[];
  if(grid){grid.rows({page:'current',search:'applied'}).every(function(){var box=$(this.node()).find('.aym-select');if(box.length&&!box.prop('disabled'))visible.push(box[0]);});}
  else $('#aym-table .aym-select:visible:not(:disabled)').each(function(){visible.push(this);});
  $('#aym-select-all').prop('checked',visible.length>0&&visible.every(function(x){return chosen.has(Number(x.value));}));
  $('.aym-close-selected').prop('disabled',!editable()||chosen.size===0).text('Cerrar seleccionados'+(chosen.size?' ('+chosen.size+')':''));
  $('.aym-close-ready').prop('disabled',!editable()||!list.some(function(x){return x.ready&&!x.closed&&!x.pending_reopen;}));
}
function load(bim,move){
  var seq=++requestIndex;
  currentBimester=Number(bim)||1;chosen.clear();
  // Destruir DataTables ANTES de reemplazar el DOM para evitar tablas huérfanas.
  destroyGrid();
  $('#aym-inline-root').html('<div class="card shadow-sm mb-3"><div class="card-body text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando el '+roman(currentBimester)+' bimestre...</div></div>');
  $.when(apiGet('academic_year_grade_control_api.php','progress'),
    apiGet('admin_grade_closure_api.php','closure_info'))
  .done(function(a,b){
    if(seq!==requestIndex)return;
    var r=a[0],meta=b[0];
    if(Number(r.status)!==1){displayError(r.message||'No se pudo consultar el periodo.');return;}
    gradeData=r;yearStatus=String((r.year||{}).status||'');closureMeta=Number(meta.status)===1?meta.items||{}:{};
    list=period().items||[];renderPanel();
    $('.ay-manage-bim').attr('aria-pressed','false').closest('.ay-close-card').removeClass('aym-selected-bim');
    $('.ay-manage-bim[data-bim="'+currentBimester+'"]').attr('aria-pressed','true').closest('.ay-close-card').addClass('aym-selected-bim');
    if(move)tabFocus();
  }).fail(function(xhr){if(seq===requestIndex)displayError(notice(xhr,'No se pudo consultar el estado del bimestre.'));});
}
function details(x){
  var m=closureMeta[String(x.teacher_course_id)]||{};
  var html='<strong>'+esc(x.course_name)+'</strong> · '+aula(x)+'<br><span class="small text-muted">'+esc(x.teacher_name)+' · '+roman(currentBimester)+' Bimestre</span>';
  if(x.closed){
    html+='<hr class="my-2"><div class="small"><strong>Tipo:</strong> '+esc(m.mode||'Cerrado')+
      '<br><strong>Responsable:</strong> '+esc(m.closed_by_name||x.closed_by_name||'—')+
      '<br><strong>Fecha:</strong> '+fmt(m.closed_at||x.closed_at)+
      '<br><strong>Versión:</strong> #'+Number(m.closure_version||1)+
      '<br><strong>Motivo:</strong> '+esc(m.reason||'Sin observación')+'</div>';
  }
  var problems=x.issues||[];
  $('#admin-close-title').text(x.closed?'Información del cierre':'Pendientes por corregir');
  $('#admin-close-summary').attr('class','alert alert-light border').html(html);
  $('#admin-close-issues').html(!x.closed?(problems.length?'<strong>Pendientes detectados</strong><ul class="small mt-2">'+problems.map(function(v){return '<li>'+esc(v)+'</li>';}).join('')+'</ul>':'<div class="small text-success">No se detectan notas pendientes. Comprueba el estado antes de cerrar.</div>'):'');
  if(!x.closed && !x.ready && editable() && !x.pending_reopen){
    $('#admin-close-issues').append('<div class="mt-3 pt-2 border-top"><div class="small text-muted mb-2">Solo Administración: un cierre excepcional requiere un motivo y autorización expresa.</div>'+
      '<button type="button" class="btn btn-sm btn-outline-danger aym-exceptional" data-id="'+Number(x.teacher_course_id)+'"><i class="fas fa-exclamation-triangle mr-1"></i>Evaluar cierre excepcional</button></div>');
  }
  $('#admin-close-reason').closest('.form-group').hide();
  $('#admin-close-confirm-wrap,#admin-close-confirm').hide();
  action=null;$('#admin-close-modal').modal('show');
}
function askClose(x,exceptional){
  if(!editable()||!x||x.closed||x.pending_reopen)return;
  action={id:x.teacher_course_id,force:exceptional?1:0,bim:currentBimester};
  $('#admin-close-title').text(exceptional?'Cierre excepcional de una asignación':'Confirmar cierre administrativo');
  $('#admin-close-summary').attr('class','alert '+(exceptional?'alert-warning':'alert-light border')).html('<strong>'+esc(x.course_name)+'</strong> · '+aula(x)+
    '<br><span class="small">'+esc(x.teacher_name)+' · '+roman(currentBimester)+' Bimestre · '+Number(x.grade_progress||0).toFixed(1)+'% de calificaciones</span>');
  $('#admin-close-issues').html(exceptional?'<strong class="small">Se cerrará con estos pendientes:</strong><ul class="small mt-2">'+
    (x.issues||[]).map(function(v){return '<li>'+esc(v)+'</li>';}).join('')+'</ul>':
    '<p class="small mb-0 text-muted">El cierre bloqueará el registro de notas de esta asignación.</p>');
  $('#admin-close-reason').val('').closest('.form-group').show();
  $('#admin-close-confirm-check').prop('checked',false);
  $('#admin-close-confirm-wrap').toggle(!!exceptional);
  $('#admin-close-confirm').show().prop('disabled',false).attr('class','btn '+(exceptional?'btn-danger':'btn-success'))
    .html('<i class="fas fa-lock mr-1"></i>'+(exceptional?'Cerrar excepcionalmente':'Confirmar cierre'));
  $('#admin-close-modal').modal('show');
}
function refresh(){if(currentBimester)load(currentBimester,false);}
function submitClose(){
  if(!action||action.bim!==currentBimester||!editable())return;
  var reason=$.trim($('#admin-close-reason').val()||'');
  if(action.force&&(reason.length<5||!$('#admin-close-confirm-check').is(':checked'))){
    toast('Para el cierre excepcional, indica el motivo y marca la autorización.','warning');return;
  }
  var button=$('#admin-close-confirm').prop('disabled',true);
  apiPost('close_assignment',{teacher_course_id:action.id,force:action.force,reason:reason})
  .done(function(r){
    if(Number(r.status)===1){
      $('#admin-close-modal').modal('hide');toast(r.message||'Asignación cerrada.','success');
      $('#ay-refresh-grade-control').trigger('click');
    }else if(Number(r.status)===2){
      toast('Esta asignación tiene pendientes: revísalos antes del cierre.','warning');
      $('#admin-close-modal').modal('hide');
    }else toast(r.message||'No se pudo cerrar la asignación.','danger');
  }).fail(function(xhr){toast(notice(xhr,'Error al registrar el cierre.'),'danger');})
  .always(function(){button.prop('disabled',false);});
}
function closeBatch(ids){
  if(!editable())return;
  ids=Array.from(new Set(ids.map(Number))).filter(function(id){var x=find(id);return x&&x.ready&&!x.closed&&!x.pending_reopen;});
  if(!ids.length){toast('No hay asignaciones listas para cerrar.','warning');return;}
  if(!confirm('Se cerrarán '+ids.length+' asignaciones listas. Las incompletas se omitirán. ¿Continuar?'))return;
  var batches=[];for(var i=0;i<ids.length;i+=500)batches.push(ids.slice(i,i+500));
  var closed=0,skipped=0;
  function next(pos){
    if(pos===batches.length){toast(closed+' asignaciones cerradas; '+skipped+' omitidas.',skipped?'warning':'success');$('#ay-refresh-grade-control').trigger('click');return;}
    apiPost('close_bulk',{teacher_course_ids:JSON.stringify(batches[pos])})
    .done(function(r){
      if(Number(r.status)!==1){toast(r.message||'No se pudo completar el lote.','danger');$('#ay-refresh-grade-control').trigger('click');return;}
      closed+=Number(r.closed_count||0);skipped+=Number(r.skipped_count||0);next(pos+1);
    }).fail(function(xhr){toast(notice(xhr,'Falló el cierre masivo. Se conservaron los cierres de lotes anteriores.'),'danger');$('#ay-refresh-grade-control').trigger('click');});
  }
  next(0);
}
// Captura antes del manejador heredado que abría el modal gigante.
document.addEventListener('click',function(e){
  var manage=e.target.closest&&e.target.closest('.ay-manage-bim');
  if(manage){e.preventDefault();e.stopImmediatePropagation();load(Number(manage.getAttribute('data-bim'))||1,true);return;}
  var confirm=e.target.closest&&e.target.closest('#admin-close-confirm');
  if(confirm&&action){e.preventDefault();e.stopImmediatePropagation();submitClose();}
},true);
$(document).on('change','#aym-bim',function(){load(Number(this.value),false);});
$(document).on('change','#aym-level,#aym-teacher,#aym-state',applyFilters);
$(document).on('click','.aym-clear',function(){$('#aym-level,#aym-teacher,#aym-state').val('');applyFilters();});
$(document).on('click','.aym-hide',function(){++requestIndex;destroyGrid();$('#aym-inline-root').empty();currentBimester=0;chosen.clear();$('.ay-manage-bim').attr('aria-pressed','false').closest('.ay-close-card').removeClass('aym-selected-bim');});
$(document).on('click','.aym-detail',function(){var x=find($(this).data('id'));if(x)details(x);});
$(document).on('click','.aym-close',function(){var x=find($(this).data('id'));if(x)askClose(x,false);});
$(document).on('click','.aym-exceptional',function(){var x=find($(this).data('id'));if(x)askClose(x,true);});
$(document).on('change','#aym-table .aym-select',function(){var id=Number(this.value);if(this.checked)chosen.add(id);else chosen.delete(id);syncSelection();});
$(document).on('change','#aym-select-all',function(){
  var enabled=this.checked;
  if(grid)grid.rows({page:'current',search:'applied'}).every(function(){var el=$(this.node()).find('.aym-select');if(!el.length||el.prop('disabled'))return;el.prop('checked',enabled);if(enabled)chosen.add(Number(el.val()));else chosen.delete(Number(el.val()));});
  else $('#aym-table .aym-select:visible:not(:disabled)').each(function(){this.checked=enabled;if(enabled)chosen.add(Number(this.value));else chosen.delete(Number(this.value));});
  syncSelection();
});
$(document).on('click','.aym-close-selected',function(){closeBatch(Array.from(chosen));});
$(document).on('click','.aym-close-ready',function(){closeBatch(list.filter(function(x){return x.ready&&!x.closed&&!x.pending_reopen;}).map(function(x){return x.teacher_course_id;}));});
$('#admin-close-modal').on('hidden.bs.modal',function(){action=null;$('#admin-close-reason').closest('.form-group').show();$('#admin-close-confirm-wrap').hide();$('#admin-close-confirm').show();});
$('#ay-refresh-grade-control').on('click',function(){refresh();});
})(window.jQuery);
