/* EduSync · Control de cierre administrativo.
 * Modal exclusivo de la pestaña Cierre de notas. No modifica el flujo docente.
 * Reutiliza las API de validación, cierre normal y cierre excepcional.
 */
(function($){
'use strict';
if(!$)return;
var qs=new URLSearchParams(location.search);
if(qs.get('page')!=='academic_year'||qs.get('manage')!=='1')return;
var yearId=Number(qs.get('id')||0);if(!yearId)return;

var bimester=1,yearStatus='',matrix=null,meta={},items=[],table=null;
var selected=new Set(),mode='list',detailId=0,confirmData=null,requestSerial=0,activeRequest=false;
var selectors=['aym-level','aym-teacher','aym-state','aym-course','aym-grade','aym-section'];
var filterState={level:'',teacher:'',state:'',course:'',grade:'',section:''};
var extFilter=null;

function esc(v){return $('<span>').text(v==null?'':String(v)).html();}
function num(v){return Number(v||0);}
function roman(v){return ['','I','II','III','IV'][Number(v)]||String(v);}
function grade(v){var text=String(v==null?'':v).replace(/[º°]+$/,'').trim();return text?esc(text)+'°':'';}
function fmt(v){if(!v)return '—';var m=String(v).match(/^(\d{4})-(\d\d)-(\d\d)(?:[ T](\d\d):(\d\d))?/);return m?m[3]+'/'+m[2]+'/'+m[1]+(m[4]?' '+m[4]+':'+m[5]:''):esc(v);}
function toast(t,type){if(typeof window.alert_toast==='function')window.alert_toast(t,type||'success');else window.alert(t);}
function csrf(){return window.EDUSYNC_CSRF||$('#edusync-global-csrf').val()||$('input[name="csrf_token"]').first().val()||'';}
function get(file,action,extra){return $.getJSON(file,$.extend({action:action,academic_year_id:yearId,bimester:bimester},extra||{}));}
function post(action,extra){return $.ajax({url:'admin_grade_closure_api.php',method:'POST',dataType:'json',
  data:$.extend({action:action,academic_year_id:yearId,bimester:bimester,csrf_token:csrf()},extra||{})});}
function apiError(xhr,defaultMsg){var r=xhr&&xhr.responseJSON||{};return r.message||defaultMsg||'Error al procesar la solicitud.';}
function current(){var p=matrix&&matrix.periods||{};return p[bimester]||p[String(bimester)]||{summary:{},items:[]};}
function summary(){return current().summary||{};}
function locked(){return !!num(summary().locked);}
function editable(){return yearStatus==='Activo'&&!locked()&&!activeRequest;}
function eligible(x){return x&&x.ready&&!x.closed&&!x.pending_reopen&&yearStatus==='Activo'&&!locked();}
function lookup(id){return items.find(function(x){return num(x.teacher_course_id)===num(id);})||null;}
function stateBadge(v){var t={'Cerrado':'success','Listo':'primary','Incompleto':'warning','Reabierto':'info','Reapertura pendiente':'danger'};
  return '<span class="badge badge-'+(t[v]||'secondary')+'">'+esc(v||'Sin estado')+'</span>';}
function modal(){return $('#aym-control-modal');}
function resetBodyScroll(){modal().find('.modal-body').scrollTop(0);}
function kpi(label,value,icon,tone){
  return '<div class="col-sm-6 col-lg-3 mb-2"><div class="aym-stat"><div class="aym-stat-icon '+(tone||'')+'"><i class="fas fa-'+icon+'"></i></div><div><small>'+esc(label)+'</small><strong>'+num(value).toLocaleString('es-PE')+'</strong></div></div></div>';
}
function modalTemplate(){
  if(modal().length)return;
  $('body').append(
    '<div class="modal fade" id="aym-control-modal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="aym-modal-title" data-backdrop="static" data-keyboard="false">'+
    '<div class="modal-dialog modal-xl modal-dialog-centered" role="document"><div class="modal-content">'+
    '<div class="modal-header"><div class="flex-grow-1"><div class="aym-kicker">EDUSYNC · GESTIÓN ACADÉMICA</div>'+
    '<h5 class="modal-title" id="aym-modal-title">Control de cierre de notas</h5>'+
    '<div class="aym-subtitle" id="aym-modal-subtitle">Cargando bimestre...</div></div>'+
    '<button class="close" type="button" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button></div>'+
    '<div class="modal-body" id="aym-modal-body"></div>'+
    '<div class="modal-footer"><span class="small text-muted" id="aym-modal-footnote"></span>'+
    '<div class="aym-modal-actions">'+
      '<button class="btn btn-sm btn-light border d-none" id="aym-back" type="button"><i class="fas fa-arrow-left mr-1"></i>Volver</button>'+
      '<button class="btn btn-sm btn-light border" id="aym-dismiss" type="button" data-dismiss="modal">Cerrar</button>'+
      '<button class="btn btn-sm btn-outline-success d-none" id="aym-close-selected" type="button">Cerrar seleccionados</button>'+
      '<button class="btn btn-sm btn-success d-none" id="aym-close-ready" type="button">Cerrar todos los listos</button>'+
      '<button class="btn btn-sm btn-primary d-none" id="aym-commit" type="button">Confirmar cierre</button>'+
    '</div></div></div></div></div>'
  );
}
function destroyWidgets(){
  selectors.forEach(function(id){var $el=$('#'+id);if($el.length&&$el.hasClass('select2-hidden-accessible')&&$.fn.select2)$el.select2('destroy');});
  if(table){table.destroy();table=null;}
  if(extFilter&&$.fn.dataTable&&$.fn.dataTable.ext){
    var f=$.fn.dataTable.ext.search,i=f.indexOf(extFilter);
    if(i>=0)f.splice(i,1);extFilter=null;
  }
}
function statusesText(){return locked()?'Bloqueo institucional activo':yearStatus==='Activo'?'Bimestre abierto':'Periodo de consulta histórica';}
function unique(key){
  return Array.from(new Set(items.map(function(x){return String(x[key]||'').trim();}).filter(Boolean)))
    .sort(function(a,b){return a.localeCompare(b,'es',{numeric:true});});
}
function options(values){
  return '<option value="">Todos</option>'+values.map(function(x){return '<option value="'+esc(x)+'">'+esc(x)+'</option>';}).join('');
}
function rowHtml(x){
  var id=num(x.teacher_course_id),m=meta[String(id)]||{},pct=Math.min(100,Math.max(0,num(x.grade_progress))),can=eligible(x);
  var action=x.closed?'Ver cierre':can?'Cerrar':'Ver pendientes';
  var btn='<button type="button" class="btn btn-sm btn-outline-'+(can?'success':'primary')+' aym-open-detail" data-id="'+id+'">'+esc(action)+'</button>';
  return '<tr><td><input type="checkbox" class="aym-check" value="'+id+'" aria-label="Seleccionar asignación"'+
    (selected.has(id)?' checked':'')+(can?'':' disabled')+'></td>'+
    '<td><div class="aym-cell-title">'+esc(x.course_name)+'</div><div class="aym-cell-sub">'+esc(x.level)+' · '+grade(x.grado)+' '+esc(x.seccion)+'</div></td>'+
    '<td><span class="font-weight-semibold">'+esc(x.teacher_name)+'</span></td>'+
    '<td data-order="'+pct+'"><strong>'+pct.toFixed(1)+'%</strong><div class="progress aym-table-mini-progress"><div class="progress-bar '+(pct>=100?'bg-success':'bg-warning')+'" style="width:'+pct+'%"></div></div></td>'+
    '<td>'+stateBadge(x.state)+'</td>'+
    '<td><div class="small">'+(x.closed?'<strong>'+esc(m.mode||'Cerrado')+'</strong><div class="text-muted">'+fmt(m.closed_at||x.closed_at)+'</div>':'—')+'</div></td>'+
    '<td>'+btn+'</td></tr>';
}
function tableFilter(settings,data,index){
  if(!settings||!settings.nTable||settings.nTable.id!=='aym-grid')return true;
  if(!table)return true;
  var node=table.row(index).node();if(!node)return true;
  var id=num($(node).find('.aym-check').val()),x=lookup(id);if(!x)return false;
  return (!filterState.level||String(x.level||'')===filterState.level)
    &&(!filterState.teacher||String(x.teacher_name||'')===filterState.teacher)
    &&(!filterState.state||String(x.state||'')===filterState.state)
    &&(!filterState.course||String(x.course_name||'')===filterState.course)
    &&(!filterState.grade||String(x.grado||'')===filterState.grade)
    &&(!filterState.section||String(x.seccion||'')===filterState.section);
}
function visibleChecks(){
  var visible=[];
  if(table){
    table.rows({page:'current',search:'applied'}).every(function(){
      var input=$(this.node()).find('.aym-check');
      if(input.length&&!input.prop('disabled'))visible.push(input[0]);
    });
  }else $('#aym-grid .aym-check:visible:not(:disabled)').each(function(){visible.push(this);});
  return visible;
}
function syncSelected(){
  var candidates=new Set(items.filter(eligible).map(function(x){return num(x.teacher_course_id);}));
  selected.forEach(function(id){if(!candidates.has(id))selected.delete(id);});
  $('#aym-grid .aym-check').each(function(){this.checked=selected.has(num(this.value));});
  var visible=visibleChecks();
  $('#aym-check-all').prop('disabled',!visible.length).prop('checked',!!visible.length&&visible.every(function(x){return selected.has(num(x.value));}));
  $('#aym-close-selected').prop('disabled',!selected.size||activeRequest).text('Cerrar seleccionados'+(selected.size?' ('+selected.size+')':''));
  $('#aym-close-ready').prop('disabled',!items.some(eligible)||activeRequest);
  $('#aym-modal-footnote').text(selected.size?selected.size+' asignación(es) seleccionada(s)':'Selecciona únicamente asignaciones listas');
}
function applyFilters(){
  if(!table)return;
  selectors.forEach(function(id){
    var field=id.replace(/^aym-/,'');
    filterState[field]=String($('#'+id).val()||'');
  });
  table.draw();
  syncSelected();
}
function activateSelect2(){
  if(typeof $.fn.select2!=='function')return;
  selectors.forEach(function(id){
    var $el=$('#'+id);
    // Los valores cortos se mantienen como select nativo.
    if($el.length&&$el.find('option').length>9){
      $el.select2({theme:'bootstrap',width:'100%',dropdownParent:modal(),minimumResultsForSearch:0,
        placeholder:'Todos',language:{noResults:function(){return 'Sin resultados';},searching:function(){return 'Buscando...';}}});
    }
  });
}
function listView(){
  destroyWidgets();mode='list';detailId=0;confirmData=null;
  var s=summary(),count=num(s.total),closed=num(s.closed),pending=Math.max(0,count-closed);
  var pct=count?Math.round(closed/count*100):0;
  var editing=yearStatus==='Activo'&&!locked();
  $('#aym-modal-title').text('Control de cierre de notas');
  $('#aym-modal-subtitle').text(roman(bimester)+' Bimestre · Año '+String((matrix.year||{}).year||'')+' · '+statusesText());
  var top='<div class="row mb-1">'+kpi('Asignaciones',count,'layer-group')+
    kpi('Cerradas',closed,'check-circle','success')+kpi('Listas para cerrar',s.ready,'clipboard-check','info')+
    kpi('Incompletas',s.incomplete,'exclamation-triangle','warning')+'</div>'+
    '<div class="small d-flex justify-content-between mb-2"><span class="text-muted">Cumplimiento del cierre</span><strong>'+pct+'%</strong></div>'+
    '<div class="progress aym-progress mb-2"><div class="progress-bar bg-success" style="width:'+pct+'%"></div></div>'+
    '<div class="small text-muted mb-3">Listo = notas completas, pero sin cerrar · Cerrado = registro de cierre efectuado.</div>';
  var fields='<div class="aym-filter-bar"><div class="form-row">'+
    '<div class="col-md-3 form-group mb-2"><label for="aym-level">Nivel</label><select class="form-control form-control-sm aym-select-wide" id="aym-level">'+options(unique('level'))+'</select></div>'+
    '<div class="col-md-5 form-group mb-2"><label for="aym-teacher">Docente</label><select class="form-control form-control-sm aym-select-wide" id="aym-teacher">'+options(unique('teacher_name'))+'</select></div>'+
    '<div class="col-md-4 form-group mb-2"><label for="aym-state">Estado</label><select class="form-control form-control-sm aym-select-wide" id="aym-state">'+options(['Cerrado','Listo','Incompleto','Reabierto','Reapertura pendiente'])+'</select></div>'+
    '</div><div class="d-flex align-items-center justify-content-between">'+
    '<button type="button" class="btn btn-link btn-sm p-0 aym-more" id="aym-toggle-extra" aria-expanded="false"><i class="fas fa-sliders-h mr-1"></i>Más filtros</button>'+
    '<button type="button" class="btn btn-sm btn-light border" id="aym-clear"><i class="fas fa-eraser mr-1"></i>Limpiar</button>'+
    '</div><div id="aym-extra-filters" class="d-none"><div class="form-row">'+
    '<div class="col-md-5 form-group mb-2"><label for="aym-course">Curso</label><select class="form-control form-control-sm aym-select-wide" id="aym-course">'+options(unique('course_name'))+'</select></div>'+
    '<div class="col-md-4 form-group mb-2"><label for="aym-grade">Grado</label><select class="form-control form-control-sm aym-select-wide" id="aym-grade">'+options(unique('grado'))+'</select></div>'+
    '<div class="col-md-3 form-group mb-2"><label for="aym-section">Sección</label><select class="form-control form-control-sm aym-select-wide" id="aym-section">'+options(unique('seccion'))+'</select></div>'+
    '</div></div></div>';
  var gridHtml='<div class="aym-panel">'+fields+
    '<div class="aym-table-head"><div><strong>Asignaciones docentes</strong><div class="small text-muted">Usa el buscador de la tabla para localizar cursos o docentes.</div></div>'+
    '<span class="badge badge-light border">'+items.length+' registros</span></div>'+
    '<div class="table-responsive"><table class="table table-sm table-hover ed-table" id="aym-grid">'+
    '<thead class="thead-light"><tr><th><input type="checkbox" id="aym-check-all" aria-label="Seleccionar asignaciones visibles"></th>'+
    '<th>Curso y aula</th><th>Docente</th><th>Notas</th><th>Estado</th><th>Cierre</th><th>Acción</th></tr></thead>'+
    '<tbody>'+items.map(rowHtml).join('')+'</tbody></table></div></div>';
  $('#aym-modal-body').html(top+gridHtml);
  showFooter();
  // Select2 usa este modal como dropdownParent, para no quedar detrás del backdrop.
  activateSelect2();
  if($.fn.DataTable && items.length){
    extFilter=tableFilter;
    $.fn.dataTable.ext.search.push(extFilter);
    table=$('#aym-grid').DataTable({autoWidth:false,pageLength:10,lengthMenu:[[10,25,50],[10,25,50]],
      order:[[1,'asc']],columnDefs:[{targets:[0,6],orderable:false}],
      language:{search:'Buscar:',lengthMenu:'Mostrar _MENU_',info:'_START_–_END_ de _TOTAL_ asignaciones',
        infoEmpty:'Sin asignaciones',zeroRecords:'No hay registros con estos filtros',
        paginate:{previous:'Anterior',next:'Siguiente'}}
    });
    table.on('draw.dt',syncSelected);
  }
  syncSelected();
  resetBodyScroll();
  if(table)window.setTimeout(function(){if(table)table.columns.adjust();},0);
}
function showFooter(){
  var main=mode==='list',confirmation=mode==='confirm',view=mode==='detail',can=yearStatus==='Activo'&&!locked();
  $('#aym-back').toggleClass('d-none',main).prop('disabled',activeRequest);
  $('#aym-dismiss').toggleClass('d-none',!main);
  $('#aym-close-selected,#aym-close-ready').toggleClass('d-none',!main||!can);
  $('#aym-commit').toggleClass('d-none',!confirmation);
  if(view)$('#aym-modal-footnote').text('Solo consulta: no se modifican las calificaciones.');
  if(confirmation)$('#aym-modal-footnote').text('Esta acción quedará registrada en el historial.');
}
function viewDetail(id){
  var x=lookup(id);if(!x)return;
  // Desmontar Select2 y DataTables antes de sustituir la vista del listado.
  destroyWidgets();
  mode='detail';detailId=id;confirmData=null;
  $('#aym-modal-title').text(x.closed?'Detalle del cierre':'Detalle de pendientes');
  $('#aym-modal-subtitle').text(roman(bimester)+' Bimestre · '+String((matrix.year||{}).year||''));
  var m=meta[String(id)]||{},problems=Array.isArray(x.issues)?x.issues:[];
  var title='<div class="aym-detail-panel mb-3"><div class="d-flex justify-content-between align-items-center flex-wrap mb-2">'+
    '<h6 class="aym-detail-heading mb-0">'+esc(x.course_name)+' · '+grade(x.grado)+' '+esc(x.seccion)+'</h6>'+stateBadge(x.state)+'</div>'+
    '<div class="text-muted small">'+esc(x.level)+' · '+esc(x.teacher_name)+'</div>'+
    '<div class="aym-action-separator"></div>'+
    '<div class="d-flex justify-content-between small"><span>Avance de calificaciones</span><strong>'+num(x.grade_progress).toFixed(1)+'%</strong></div>'+
    '<div class="progress aym-progress mt-2"><div class="progress-bar '+(num(x.grade_progress)>=100?'bg-success':'bg-warning')+'" style="width:'+Math.max(0,Math.min(100,num(x.grade_progress)))+'%"></div></div></div>';
  if(x.closed){
    title+='<div class="aym-detail-panel">'+
      '<div class="aym-detail-item"><strong>Tipo de cierre:</strong> '+esc(m.mode||'Cerrado')+'</div>'+
      '<div class="aym-detail-item"><strong>Responsable:</strong> '+esc(m.closed_by_name||x.closed_by_name||'—')+'</div>'+
      '<div class="aym-detail-item"><strong>Fecha de cierre:</strong> '+fmt(m.closed_at||x.closed_at)+'</div>'+
      '<div class="aym-detail-item"><strong>Versión:</strong> #'+num(m.closure_version||1)+'</div>'+
      '<div class="aym-detail-item"><strong>Observación:</strong> '+esc(m.reason||'Sin observación')+'</div></div>';
  }else{
    title+='<div class="aym-detail-panel">'+
      '<h6 class="font-weight-bold mb-2">Validaciones de la asignación</h6>'+
      (problems.length?'<ul class="small mb-2 pl-3">'+problems.map(function(p){return '<li class="mb-2">'+esc(p)+'</li>';}).join('')+'</ul>':
        '<p class="small text-success mb-2">No se registran calificaciones pendientes.</p>')+
      (x.pending_reopen?'<div class="alert alert-warning small">Existe una solicitud de reapertura pendiente. Resuélvela antes de gestionar el cierre.</div>':'')+
      (eligible(x)?'<button class="btn btn-sm btn-outline-success mt-2 aym-confirm-single" data-id="'+id+'"><i class="fas fa-lock mr-1"></i>Cerrar asignación lista</button>':'')+
      (!x.ready && yearStatus==='Activo'&&!locked()&&!x.pending_reopen?
        '<div class="aym-action-separator"></div><details><summary class="small text-muted" style="cursor:pointer">Acción extraordinaria de Administración</summary>'+
        '<div class="alert alert-warning small mt-2">Un cierre excepcional bloquea la asignación con información pendiente y exige motivo y autorización.</div>'+
        '<button class="btn btn-sm btn-outline-danger aym-confirm-exceptional" data-id="'+id+'">Evaluar cierre excepcional</button></details>':'')+
      '</div>';
  }
  $('#aym-modal-body').html(title);
  showFooter();resetBodyScroll();
}
function beginConfirm(kind,ids){
  if(yearStatus!=='Activo'||locked()||activeRequest)return;
  ids=Array.from(new Set((ids||[]).map(Number))).filter(function(id){var x=lookup(id);return kind==='exceptional'?
    (x&&!x.closed&&!x.ready&&!x.pending_reopen):eligible(x);});
  if(!ids.length){toast('No hay asignaciones válidas para cerrar.','warning');return;}
  var force=kind==='exceptional',bulk=ids.length>1||kind==='bulk';
  if(force&&!lookup(ids[0]))return;
  confirmData={kind:force?'exceptional':bulk?'bulk':'single',ids:ids,bimester:bimester};
  // Evitar tablas huérfanas y dropdowns Select2 residuales al confirmar.
  destroyWidgets();
  mode='confirm';
  $('#aym-modal-title').text(force?'Cierre administrativo excepcional':bulk?'Confirmar cierre de asignaciones':'Confirmar cierre de notas');
  $('#aym-modal-subtitle').text(roman(bimester)+' Bimestre · Año '+String((matrix.year||{}).year||''));
  var first=lookup(ids[0]),head=bulk?
    '<strong>'+ids.length+' asignaciones listas</strong> serán verificadas de nuevo por el servidor antes de registrar sus cierres.':
    '<strong>'+esc(first.course_name)+' · '+grade(first.grado)+' '+esc(first.seccion)+'</strong>'+
      '<div class="small text-muted">'+esc(first.teacher_name)+'</div>';
  var html='<div class="aym-detail-panel '+(force?'aym-danger':'')+' mb-3"><h6 class="aym-detail-heading mb-2">'+
    (force?'Cierre con pendientes':bulk?'Cierre masivo de asignaciones listas':'Cierre de asignación lista')+'</h6>'+
    '<div class="small">'+head+'</div></div>'+
    '<div class="alert '+(force?'alert-warning':'alert-light border')+' small">'+
    (force?'La información de esta asignación aún está incompleta. El cierre excepcional requiere una justificación y autorización explícita.':
      'Después del cierre, las notas quedan en modo lectura. Para corregirlas posteriormente se debe solicitar una reapertura.')+
    '</div>';
  if(force){
    var issueList=first.issues||[];
    html+='<div class="aym-detail-panel mb-3"><strong class="small">Pendientes detectados</strong><ul class="small pl-3 mb-0 mt-2">'+
      issueList.map(function(t){return '<li>'+esc(t)+'</li>';}).join('')+'</ul></div>'+
      '<div class="form-group"><label for="aym-confirm-reason" class="font-weight-bold small">Motivo obligatorio (mínimo 5 caracteres)</label>'+
      '<textarea class="form-control" id="aym-confirm-reason" maxlength="500" rows="3" placeholder="Explica por qué debe realizarse este cierre..."></textarea></div>'+
      '<div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="aym-confirm-check">'+
      '<label class="custom-control-label small" for="aym-confirm-check">Autorizo el cierre excepcional con las observaciones indicadas.</label></div>';
  }
  if(bulk)html+='<p class="small text-muted">Se procesarán grupos de hasta 500 asignaciones. Se omitirán aquellas que dejen de cumplir los requisitos.</p>';
  $('#aym-modal-body').html(html+'<div id="aym-submit-status" class="small text-muted mt-3" role="status"></div>');
  $('#aym-commit').text(force?'Cerrar excepcionalmente':bulk?'Cerrar asignaciones':'Confirmar cierre')
    .removeClass('btn-primary btn-danger btn-success').addClass(force?'btn-danger':'btn-success').prop('disabled',false);
  showFooter();resetBodyScroll();
}
function finalizeClose(message,kind){
  toast(message,kind||'success');
  load(bimester,false);
  $('#ay-refresh-grade-control').trigger('click');
}
function submit(){
  if(!confirmData||activeRequest||confirmData.bimester!==bimester||yearStatus!=='Activo'||locked())return;
  var cd=confirmData,force=cd.kind==='exceptional',reason=String($('#aym-confirm-reason').val()||'').trim();
  if(force&&(reason.length<5||!$('#aym-confirm-check').is(':checked'))){toast('Indica el motivo y confirma expresamente el cierre excepcional.','warning');return;}
  if(!csrf()){toast('La sesión de seguridad no está disponible. Recarga EduSync.','warning');return;}
  activeRequest=true;
  $('#aym-commit,#aym-back,#aym-control-modal .close,#aym-dismiss').prop('disabled',true);
  var ended=false;
  function release(){activeRequest=false;$('#aym-commit,#aym-back,#aym-control-modal .close,#aym-dismiss').prop('disabled',false);}
  if(cd.kind!=='bulk'){
    post('close_assignment',{teacher_course_id:cd.ids[0],force:force?1:0,reason:force?reason:''})
      .done(function(r){
        if(num(r.status)===1){ended=true;release();finalizeClose(r.message||'Asignación cerrada.');}
        else if(num(r.status)===2)toast('La asignación ya no está lista. Revisa sus pendientes antes de cerrar.','warning');
        else toast(r.message||'No se pudo registrar el cierre.','danger');
      }).fail(function(xhr){toast(apiError(xhr,'No se pudo registrar el cierre.'),'danger');})
      .always(function(){if(!ended)release();});
    return;
  }
  var batches=[];for(var i=0;i<cd.ids.length;i+=500)batches.push(cd.ids.slice(i,i+500));
  var closed=0,skipped=0;
  function process(i){
    if(i>=batches.length){release();finalizeClose(closed+' asignaciones cerradas; '+skipped+' omitidas.',skipped?'warning':'success');return;}
    $('#aym-submit-status').text('Procesando grupo '+(i+1)+' de '+batches.length+'...');
    post('close_bulk',{teacher_course_ids:JSON.stringify(batches[i])}).done(function(r){
      if(num(r.status)!==1){release();toast(r.message||'No se pudo procesar el grupo '+(i+1)+'.','danger');load(bimester,false);return;}
      closed+=num(r.closed_count);skipped+=num(r.skipped_count);process(i+1);
    }).fail(function(xhr){release();toast(apiError(xhr,'Error en grupo '+(i+1))+'. Cierres anteriores: '+closed,'danger');load(bimester,false);});
  }
  process(0);
}
function back(){
  if(activeRequest)return;
  if(mode==='confirm' && confirmData && confirmData.ids.length===1 && confirmData.kind!=='bulk')viewDetail(confirmData.ids[0]);
  else listView();
}
function load(b,open){
  if(activeRequest)return;
  var next=Number(b)||1;if(next<1||next>4)return;
  var token=++requestSerial;bimester=next;selected.clear();detailId=0;confirmData=null;mode='list';
  destroyWidgets();
  $('#aym-modal-title').text('Control de cierre de notas');
  $('#aym-modal-subtitle').text(roman(next)+' Bimestre · Consultando asignaciones...');
  $('#aym-modal-body').html('<div class="py-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin mr-2"></i>Consultando cierres del bimestre...</div>');
  $('#aym-close-ready,#aym-close-selected,#aym-commit,#aym-back').addClass('d-none');
  $('#aym-dismiss').removeClass('d-none');
  $('#aym-modal-footnote').empty();
  if(open)modal().modal('show');
  $.when(get('academic_year_grade_control_api.php','progress'),get('admin_grade_closure_api.php','closure_info'))
    .done(function(a,c){
      if(token!==requestSerial)return;
      var progress=a&&a[0]||{},closures=c&&c[0]||{};
      if(num(progress.status)!==1||num(closures.status)!==1){
        $('#aym-modal-body').html('<div class="alert alert-warning">'+esc(progress.message||closures.message||'No se pudo cargar el control de cierres.')+'</div>');return;
      }
      matrix=progress;yearStatus=String((progress.year||{}).status||'');meta=closures.items||{};
      items=(current().items||[]);
      listView();
    }).fail(function(xhr){
      if(token!==requestSerial)return;
      $('#aym-modal-body').html('<div class="alert alert-warning">'+esc(apiError(xhr,'No se pudo consultar el control de cierre.'))+'</div>'+
        '<button type="button" class="btn btn-sm btn-outline-primary aym-retry">Reintentar</button>');
    });
}
// Captura antes del controlador antiguo incrustado en academic_year.php.
document.addEventListener('click',function(e){
  var manage=e.target.closest&&e.target.closest('.ay-manage-bim');
  if(!manage)return;
  e.preventDefault();e.stopImmediatePropagation();
  modalTemplate();load(num(manage.getAttribute('data-bim'))||1,true);
},true);
$(document).on('change','#aym-level,#aym-teacher,#aym-state,#aym-course,#aym-grade,#aym-section',applyFilters);
$(document).on('click','#aym-toggle-extra',function(){
  var extra=$('#aym-extra-filters'),open=extra.hasClass('d-none');
  extra.toggleClass('d-none',!open);$(this).attr('aria-expanded',open?'true':'false').html('<i class="fas fa-sliders-h mr-1"></i>'+(open?'Menos filtros':'Más filtros'));
});
$(document).on('click','#aym-clear',function(){
  selectors.forEach(function(id){$('#'+id).val('').trigger('change.select2');});
  applyFilters();
});
$(document).on('change','#aym-grid .aym-check',function(){
  var id=num(this.value);if(this.checked)selected.add(id);else selected.delete(id);syncSelected();
});
$(document).on('change','#aym-check-all',function(){
  var enable=this.checked;
  visibleChecks().forEach(function(box){box.checked=enable;if(enable)selected.add(num(box.value));else selected.delete(num(box.value));});
  syncSelected();
});
$(document).on('click','#aym-grid .aym-open-detail',function(){viewDetail(num($(this).data('id')));});
$(document).on('click','.aym-confirm-single',function(){beginConfirm('single',[num($(this).data('id'))]);});
$(document).on('click','.aym-confirm-exceptional',function(){beginConfirm('exceptional',[num($(this).data('id'))]);});
$(document).on('click','#aym-close-selected',function(){beginConfirm('bulk',Array.from(selected));});
$(document).on('click','#aym-close-ready',function(){beginConfirm('bulk',items.filter(eligible).map(function(x){return num(x.teacher_course_id);}));});
$(document).on('click','#aym-back',back);
$(document).on('click','#aym-commit',submit);
$(document).on('click','.aym-retry',function(){load(bimester,false);});
$(document).on('hidden.bs.modal','#aym-control-modal',function(){
  ++requestSerial;destroyWidgets();selected.clear();confirmData=null;mode='list';activeRequest=false;
});
$(document).on('shown.bs.modal','#aym-control-modal',function(){if(table)table.columns.adjust();});
})(window.jQuery);
