(function($){
'use strict';
if(!$) return;

var params = new URLSearchParams(window.location.search);
if(params.get('page') !== 'academic_year' || params.get('manage') !== '1') return;
var yearId = Number(params.get('id') || 0);
if(!yearId) return;

var currentBimester = 1;
var gradeData = null;
var closureMeta = {};
var currentItems = [];
var yearStatus = '';
var csrf = '';

function esc(v){ return $('<div>').text(v == null ? '' : v).html(); }
function toast(msg,type){ if(typeof window.alert_toast === 'function') window.alert_toast(msg,type||'success'); else alert(msg); }
function getCsrf(){
    if(csrf) return csrf;
    csrf = $('#edu-chat-form input[name="csrf_token"]').val() || $('input[name="csrf_token"]').first().val() || '';
    return csrf;
}
function roman(b){ return ['', 'I','II','III','IV'][Number(b)] || String(b); }
function gradeLabel(v){
    var grade=String(v == null ? '' : v).trim().replace(/[°º]+$/u,'').trim();
    return grade ? esc(grade)+'°' : '';
}
function aulaLabel(x){ return gradeLabel(x.grado)+' '+esc(x.seccion); }
function fmtDate(v){
    if(!v) return '—';
    var m=String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if(!m) return esc(v);
    var h=Number(m[4]), suffix=h>=12?'p. m.':'a. m.'; h=h%12||12;
    return m[3]+'/'+m[2]+'/'+m[1]+' '+String(h).padStart(2,'0')+':'+m[5]+' '+suffix;
}
function stateBadge(state){
    var map={'Cerrado':'success','Listo':'primary','Incompleto':'warning','Reabierto':'info','Reapertura pendiente':'danger'};
    return '<span class="badge badge-'+(map[state]||'secondary')+'">'+esc(state)+'</span>';
}
function unique(items,key){
    return Array.from(new Set(items.map(function(x){return String(x[key] == null ? '' : x[key]);}).filter(Boolean)))
        .sort(function(a,b){return a.localeCompare(b,'es',{numeric:true});});
}
function options(values,label){
    return '<option value="">'+esc(label)+'</option>'+values.map(function(v){return '<option value="'+esc(v)+'">'+esc(v)+'</option>';}).join('');
}
function adminPost(action,data){
    data=data||{}; data.action=action; data.csrf_token=getCsrf();
    return $.ajax({url:'admin_grade_closure_api.php',method:'POST',data:data,dataType:'json'});
}
function adminGet(action,data){ data=data||{}; data.action=action; return $.getJSON('admin_grade_closure_api.php',data); }
function gradeGet(action,data){ data=data||{}; data.action=action; return $.getJSON('academic_year_grade_control_api.php',data); }

// El panel viejo queda fuera de uso: toda la gestión individual vive en el modal.
$('<style id="aym-style">'+
  '#ay-admin-close-panel{display:none!important}'+
  '#info-modal.aym-manager .modal-dialog{max-width:1280px;width:calc(100% - 32px)}'+
  '#info-modal.aym-manager .modal-body{padding:1rem}'+
  '#admin-close-modal.aym-pending-fixed{overflow:hidden!important;z-index:1070}'+
  '#admin-close-modal.aym-pending-fixed .modal-dialog{position:fixed;top:50%;left:50%;margin:0!important;width:calc(100% - 24px);max-width:560px;transform:translate(-50%,-50%)!important}'+
  '#admin-close-modal.aym-pending-fixed .modal-content{max-height:calc(100vh - 32px);overflow:hidden}'+
  '#admin-close-modal.aym-pending-fixed .modal-body{overflow-y:auto;overscroll-behavior:contain}'+
  '.aym-toolbar{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:10px;padding:12px}'+
  '.aym-toolbar label{font-size:.7rem;font-weight:700;color:#5a5c69;text-transform:uppercase;margin-bottom:4px}'+
  '.aym-actions{display:flex;gap:6px;flex-wrap:wrap}'+
  '.aym-table td,.aym-table th{vertical-align:middle}'+
  '.aym-row-closed{background:#f8fff9}.aym-row-incomplete{background:#fffdf7}'+
  '.aym-note{font-size:.72rem;color:#858796}.aym-mobile-list{display:none}'+
  '@media(max-width:767.98px){#info-modal.aym-manager .modal-dialog{width:calc(100% - 16px);margin:.5rem auto}.aym-toolbar .form-group{margin-bottom:.55rem}.aym-actions .btn{flex:1 1 100%}.aym-table-wrap{display:none}.aym-mobile-list{display:block}.aym-card{border:1px solid #e3e6f0;border-radius:10px;padding:12px;margin-bottom:10px;background:#fff}.aym-card.closed{border-left:4px solid #1cc88a}.aym-card.ready{border-left:4px solid #4e73df}.aym-card.incomplete{border-left:4px solid #f6c23e}.aym-card-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.aym-card-actions .btn{flex:1 1 auto}}'+
  '</style>').appendTo('head');

function getPeriod(){ return gradeData && gradeData.periods ? (gradeData.periods[currentBimester] || gradeData.periods[String(currentBimester)]) : null; }
function findItem(id){ return currentItems.find(function(x){return Number(x.teacher_course_id)===Number(id);}); }
function filteredItems(){
    var lv=$('#aym-level').val()||'', teacher=$('#aym-teacher').val()||'', course=$('#aym-course').val()||'', grade=$('#aym-grade').val()||'', section=$('#aym-section').val()||'', state=$('#aym-state').val()||'';
    return currentItems.filter(function(x){
        return (!lv||String(x.level)===lv)&&(!teacher||String(x.teacher_name)===teacher)&&(!course||String(x.course_name)===course)&&(!grade||String(x.grado)===grade)&&(!section||String(x.seccion)===section)&&(!state||String(x.state)===state);
    });
}
function closureText(x){
    var m=closureMeta[String(x.teacher_course_id)]||{};
    if(!x.closed) return '<span class="text-muted">—</span>';
    return '<strong>'+esc(m.mode||'Cerrado')+'</strong><div class="aym-note">'+esc(m.closed_by_name||x.closed_by_name||'')+(m.closed_at?' · '+fmtDate(m.closed_at):'')+'</div>';
}
function actionButtons(x){
    if(yearStatus!=='Activo') return '<button class="btn btn-sm btn-outline-info aym-view" data-id="'+x.teacher_course_id+'"><i class="fas fa-eye mr-1"></i>Ver</button>';
    if(x.closed) return '<button class="btn btn-sm btn-outline-info aym-view-closure" data-id="'+x.teacher_course_id+'"><i class="fas fa-eye mr-1"></i>Ver cierre</button>';
    if(x.ready) return '<button class="btn btn-sm btn-outline-success aym-close-one" data-id="'+x.teacher_course_id+'"><i class="fas fa-lock mr-1"></i>Cerrar</button>';
    return '<button class="btn btn-sm btn-outline-secondary aym-view mr-1" data-id="'+x.teacher_course_id+'"><i class="fas fa-list mr-1"></i>Pendientes</button><button class="btn btn-sm btn-outline-danger aym-close-exceptional" data-id="'+x.teacher_course_id+'"><i class="fas fa-exclamation-triangle mr-1"></i>Excepcional</button>';
}
function renderRows(){
    var items=filteredItems(), rows='', cards='';
    $('#aym-count').text(items.length);
    items.forEach(function(x){
        var cls=x.closed?'aym-row-closed':(!x.ready?'aym-row-incomplete':'');
        rows+='<tr class="'+cls+'"><td><input type="checkbox" class="aym-check" value="'+x.teacher_course_id+'" '+(x.closed?'disabled':'')+'></td><td><strong>'+esc(x.teacher_name)+'</strong></td><td>'+esc(x.course_name)+'</td><td>'+esc(x.level)+'</td><td>'+aulaLabel(x)+'</td><td>'+Number(x.grade_progress||0).toFixed(1)+'%</td><td>'+stateBadge(x.state)+'</td><td>'+closureText(x)+'</td><td class="text-right">'+actionButtons(x)+'</td></tr>';
        var c=x.closed?'closed':(x.ready?'ready':'incomplete');
        cards+='<div class="aym-card '+c+'"><div class="d-flex justify-content-between align-items-start"><div><strong>'+esc(x.course_name)+'</strong><div class="small text-muted">'+esc(x.teacher_name)+'</div></div><input type="checkbox" class="aym-check" value="'+x.teacher_course_id+'" '+(x.closed?'disabled':'')+'></div><div class="small mt-2"><strong>'+esc(x.level)+' · '+aulaLabel(x)+'</strong> · '+Number(x.grade_progress||0).toFixed(1)+'%</div><div class="mt-1">'+stateBadge(x.state)+'</div><div class="mt-1">'+closureText(x)+'</div><div class="aym-card-actions">'+actionButtons(x)+'</div></div>';
    });
    if(!rows) rows='<tr><td colspan="9" class="text-center text-muted py-4">No hay asignaciones con los filtros seleccionados.</td></tr>';
    if(!cards) cards='<div class="alert alert-light border">No hay asignaciones con los filtros seleccionados.</div>';
    $('#aym-tbody').html(rows); $('#aym-mobile').html(cards); $('#aym-select-all').prop('checked',false);
}
function renderManager(){
    var p=getPeriod()||{}, s=p.summary||{}, locked=!!s.locked;
    currentItems=p.items||[];
    var summary='<div class="d-flex justify-content-between align-items-center flex-wrap mb-3"><div><div class="small text-muted">Año académico '+esc((gradeData.year||{}).year||'')+'</div><strong>'+roman(currentBimester)+' Bimestre</strong></div><div class="mt-2 mt-md-0"><span class="badge badge-'+(locked?'info':'warning')+'">'+(locked?'🔒 Bloqueo institucional activo':'🔓 Bimestre abierto')+'</span></div></div>'+
      '<div class="row mb-3"><div class="col-6 col-md-3"><div class="ay-stat"><small class="text-muted">Cerrados</small><br><strong>'+Number(s.closed||0)+'</strong></div></div><div class="col-6 col-md-3"><div class="ay-stat"><small class="text-muted">Listos</small><br><strong>'+Number(s.ready||0)+'</strong></div></div><div class="col-6 col-md-3 mt-2 mt-md-0"><div class="ay-stat"><small class="text-muted">Incompletos</small><br><strong>'+Number(s.incomplete||0)+'</strong></div></div><div class="col-6 col-md-3 mt-2 mt-md-0"><div class="ay-stat"><small class="text-muted">Avance</small><br><strong>'+Number(s.progress||0).toFixed(1)+'%</strong></div></div></div>';
    var toolbar='<div class="aym-toolbar mb-3"><div class="form-row">'+
      '<div class="form-group col-lg-2 col-6"><label>Nivel</label><select class="form-control form-control-sm" id="aym-level">'+options(unique(currentItems,'level'),'Todos')+'</select></div>'+
      '<div class="form-group col-lg-2 col-6"><label>Docente</label><select class="form-control form-control-sm" id="aym-teacher">'+options(unique(currentItems,'teacher_name'),'Todos')+'</select></div>'+
      '<div class="form-group col-lg-2 col-6"><label>Curso</label><select class="form-control form-control-sm" id="aym-course">'+options(unique(currentItems,'course_name'),'Todos')+'</select></div>'+
      '<div class="form-group col-lg-2 col-6"><label>Grado</label><select class="form-control form-control-sm" id="aym-grade">'+options(unique(currentItems,'grado'),'Todos')+'</select></div>'+
      '<div class="form-group col-lg-2 col-6"><label>Sección</label><select class="form-control form-control-sm" id="aym-section">'+options(unique(currentItems,'seccion'),'Todas')+'</select></div>'+
      '<div class="form-group col-lg-2 col-6"><label>Estado</label><select class="form-control form-control-sm" id="aym-state">'+options(['Cerrado','Listo','Incompleto','Reabierto','Reapertura pendiente'],'Todos')+'</select></div></div>'+
      '<div class="d-flex justify-content-between align-items-center flex-wrap"><div class="small text-muted mb-2 mb-md-0"><span id="aym-count">0</span> asignación(es) visibles.</div><div class="aym-actions"><button class="btn btn-sm btn-light border" id="aym-clear"><i class="fas fa-eraser mr-1"></i>Limpiar</button>'+(yearStatus==='Activo'&&!locked?'<button class="btn btn-sm btn-outline-success" id="aym-close-selected"><i class="fas fa-check-square mr-1"></i>Cerrar seleccionados</button><button class="btn btn-sm btn-success" id="aym-close-ready"><i class="fas fa-lock mr-1"></i>Cerrar todos los listos</button>':'')+'</div></div></div>';
    var table='<div class="aym-table-wrap table-responsive"><table class="table table-sm table-hover aym-table"><thead class="thead-light"><tr><th style="width:34px"><input type="checkbox" id="aym-select-all"></th><th>Docente</th><th>Curso</th><th>Nivel</th><th>Aula</th><th>Avance</th><th>Estado</th><th>Cierre</th><th class="text-right">Acción</th></tr></thead><tbody id="aym-tbody"></tbody></table></div><div class="aym-mobile-list" id="aym-mobile"></div>';
    $('#info-body').html(summary+toolbar+table);
    renderRows();
}
function loadManager(bim){
    currentBimester=Number(bim);
    $('#info-modal').addClass('aym-manager');
    $('#info-modal .modal-dialog').removeClass('modal-sm modal-lg mid-large').addClass('modal-xl');
    $('#info-title').text('Control de cierre · '+roman(currentBimester)+' Bimestre');
    $('#info-body').html('<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando asignaciones...</div>');
    $('#info-modal').modal('show');
    $.when(
      gradeGet('progress',{academic_year_id:yearId}),
      adminGet('closure_info',{academic_year_id:yearId,bimester:currentBimester})
    ).done(function(a,b){
        var gr=a[0]||{}, cm=b[0]||{};
        if(Number(gr.status)!==1){ $('#info-body').html('<div class="alert alert-warning">'+esc(gr.message||'No se pudo cargar el control de cierres.')+'</div>'); return; }
        gradeData=gr; yearStatus=String((gr.year||{}).status||''); closureMeta=Number(cm.status)===1?(cm.items||{}):{};
        renderManager();
    }).fail(function(xhr){ $('#info-body').html('<div class="alert alert-danger">'+esc((xhr.responseJSON||{}).message||'No se pudo cargar el control de cierres.')+'</div>'); });
}
function refreshManager(){
    $.when(gradeGet('progress',{academic_year_id:yearId}),adminGet('closure_info',{academic_year_id:yearId,bimester:currentBimester})).done(function(a,b){
        var gr=a[0]||{},cm=b[0]||{}; if(Number(gr.status)!==1)return;
        gradeData=gr;yearStatus=String((gr.year||{}).status||'');closureMeta=Number(cm.status)===1?(cm.items||{}):{};renderManager();
        $('#ay-refresh-grade-control').trigger('click');
    });
}
function showDetails(x,closure){
    var modal=$('#admin-close-modal');
    modal.attr('data-aym-mode','view').toggleClass('aym-pending-fixed',!closure);
    $('#admin-close-title').text(closure?'Información del cierre':'Detalle de pendientes');
    var m=closureMeta[String(x.teacher_course_id)]||{};
    var html='<strong>'+esc(x.course_name)+' · '+aulaLabel(x)+'</strong><br><span class="small text-muted">'+esc(x.teacher_name)+' · '+roman(currentBimester)+' Bimestre</span>';
    if(closure) html+='<hr class="my-2"><div class="small"><strong>Tipo:</strong> '+esc(m.mode||'Cerrado')+'<br><strong>Realizado por:</strong> '+esc(m.closed_by_name||x.closed_by_name||'—')+'<br><strong>Fecha:</strong> '+fmtDate(m.closed_at||x.closed_at)+'<br><strong>Versión:</strong> #'+Number(m.closure_version||1)+'<br><strong>Motivo:</strong> '+esc(m.reason||'Sin observación adicional')+'</div>';
    $('#admin-close-summary').attr('class','alert alert-light border').html(html);
    var issues=x.issues||[];
    $('#admin-close-issues').html(!closure&&issues.length?'<div class="mb-3"><strong>Pendientes detectados</strong><ul class="small mt-2 mb-0">'+issues.map(function(i){return '<li>'+esc(i)+'</li>';}).join('')+'</ul></div>':'');
    $('#admin-close-reason').closest('.form-group').hide(); $('#admin-close-confirm-wrap').hide(); $('#admin-close-confirm').hide();
    modal.modal('show');
}
function openClose(x,force){
    $('#admin-close-modal').removeClass('aym-pending-fixed').attr('data-aym-mode','close'); $('#admin-close-modal').data('aym-item',x).data('aym-force',force?1:0);
    $('#admin-close-title').text(force?'Cierre administrativo excepcional':'Cierre administrativo');
    $('#admin-close-summary').attr('class','alert '+(force?'alert-warning':'alert-info')).html('<strong>'+esc(x.course_name)+' · '+aulaLabel(x)+'</strong><br>'+esc(x.teacher_name)+' · '+roman(currentBimester)+' Bimestre · '+Number(x.grade_progress||0).toFixed(1)+'% completo');
    var issues=x.issues||[];
    $('#admin-close-issues').html(force&&issues.length?'<div class="small mb-3"><strong>Pendientes:</strong><ul class="mb-0 mt-1">'+issues.map(function(i){return '<li>'+esc(i)+'</li>';}).join('')+'</ul></div>':'');
    $('#admin-close-reason').val('').closest('.form-group').show(); $('#admin-close-confirm-check').prop('checked',false); $('#admin-close-confirm-wrap').toggle(!!force); $('#admin-close-confirm').show().prop('disabled',false).attr('class','btn '+(force?'btn-danger':'btn-success')).html('<i class="fas fa-lock mr-1"></i>'+(force?'Cerrar excepcionalmente':'Cerrar asignación'));
    $('#admin-close-modal').modal('show');
}
function submitClose(){
    var modal=$('#admin-close-modal'), x=modal.data('aym-item'), force=Number(modal.data('aym-force')||0), reason=$.trim($('#admin-close-reason').val());
    if(!x)return;
    if(force&&reason.length<5){toast('Indique un motivo de al menos 5 caracteres.','warning');return;}
    if(force&&!$('#admin-close-confirm-check').is(':checked')){toast('Confirme que autoriza el cierre excepcional.','warning');return;}
    var btn=$('#admin-close-confirm').prop('disabled',true);
    adminPost('close_assignment',{academic_year_id:yearId,teacher_course_id:x.teacher_course_id,bimester:currentBimester,force:force,reason:reason}).done(function(r){
        if(Number(r.status)===1){modal.modal('hide');toast(r.message,'success');refreshManager();return;}
        if(Number(r.status)===2){x.issues=(r.validation&&r.validation.issues)||x.issues;openClose(x,true);toast(r.message,'warning');return;}
        toast(r.message||'No se pudo realizar el cierre.','danger');
    }).fail(function(xhr){toast((xhr.responseJSON||{}).message||'No se pudo realizar el cierre.','danger');}).always(function(){btn.prop('disabled',false);});
}
function selectedIds(){ return Array.from(new Set($('#info-modal .aym-check:checked').map(function(){return Number(this.value);}).get())); }
function bulk(ids,label){
    if(!ids.length){toast('No hay asignaciones para cerrar.','warning');return;}
    if(!confirm('¿Cerrar '+ids.length+' asignación(es) '+label+'? Las incompletas serán omitidas.'))return;
    adminPost('close_bulk',{academic_year_id:yearId,bimester:currentBimester,teacher_course_ids:JSON.stringify(ids)}).done(function(r){
        if(Number(r.status)===1){toast(r.message,r.skipped_count?'warning':'success');refreshManager();}else toast(r.message||'No se pudo completar el cierre masivo.','danger');
    }).fail(function(xhr){toast((xhr.responseJSON||{}).message||'No se pudo completar el cierre masivo.','danger');});
}

// Captura: evita que el handler antiguo despliegue el panel debajo de las tarjetas.
document.addEventListener('click',function(e){
    var manage=e.target.closest&&e.target.closest('.ay-manage-bim');
    if(manage){e.preventDefault();e.stopImmediatePropagation();loadManager(Number(manage.getAttribute('data-bim'))||1);return;}
    var confirmBtn=e.target.closest&&e.target.closest('#admin-close-confirm');
    if(confirmBtn&&$('#admin-close-modal').attr('data-aym-mode')==='close'){e.preventDefault();e.stopImmediatePropagation();submitClose();}
},true);

$(document).on('change','#aym-level,#aym-teacher,#aym-course,#aym-grade,#aym-section,#aym-state',renderRows);
$(document).on('click','#aym-clear',function(){$('#aym-level,#aym-teacher,#aym-course,#aym-grade,#aym-section,#aym-state').val('');renderRows();});
$(document).on('change','#aym-select-all',function(){var checked=this.checked;$('#info-modal .aym-check:not(:disabled)').prop('checked',checked);});
$(document).on('click','.aym-view',function(){var x=findItem($(this).data('id'));if(x)showDetails(x,false);});
$(document).on('click','.aym-view-closure',function(){var x=findItem($(this).data('id'));if(x)showDetails(x,true);});
$(document).on('click','.aym-close-one',function(){var x=findItem($(this).data('id'));if(x)openClose(x,false);});
$(document).on('click','.aym-close-exceptional',function(){var x=findItem($(this).data('id'));if(x)openClose(x,true);});
$(document).on('click','#aym-close-selected',function(){bulk(selectedIds(),'seleccionadas');});
$(document).on('click','#aym-close-ready',function(){var ids=filteredItems().filter(function(x){return x.ready&&!x.closed;}).map(function(x){return Number(x.teacher_course_id);});bulk(ids,'que están listas');});

$('#admin-close-modal').on('hidden.bs.modal',function(){
    var managerStillOpen=$('#info-modal').hasClass('show');
    $(this).removeClass('aym-pending-fixed').removeAttr('data-aym-mode').removeData('aym-item').removeData('aym-force');
    $('#admin-close-reason').closest('.form-group').show();$('#admin-close-confirm').show();$('#admin-close-confirm-wrap').hide();
    if(managerStillOpen) $('body').addClass('modal-open');
});
$('#info-modal').on('hidden.bs.modal',function(){
    if($(this).hasClass('aym-manager')){$(this).removeClass('aym-manager');$(this).find('.modal-dialog').removeClass('modal-xl').addClass('modal-lg');}
});

})(window.jQuery);