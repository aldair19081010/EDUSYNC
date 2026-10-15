(function($){
'use strict';
if(!$ || !window.EduSyncTeacherAlerts) return;
var cfg=window.EduSyncTeacherAlerts, endpoint='teacher_notifications_admin_api.php';
var $root=$('#ay-teacher-alert-control'), state=null, search='';
if(!$root.length || !cfg.yearId) return;

function esc(s){return $('<span>').text(s==null?'':String(s)).html();}
function api(data,post){
  var args=$.extend({},data,{academic_year_id:cfg.yearId,bimester:Number($('#taca-bim').val()||1)});
  if(post){args.csrf_token=cfg.csrf;return $.ajax({url:endpoint,type:'POST',data:args,dataType:'json'});}
  return $.getJSON(endpoint,args);
}
function alertMessage(message,kind){
  if(typeof window.alert_toast==='function') window.alert_toast(message,kind||'success');
  else window.alert(message);
}
function number(value){return Number(value||0).toLocaleString('es-PE');}
function stat(label,value,icon,color){
  return '<div class="col-6 col-xl-3 mb-2"><div class="taca-stat taca-stat-'+color+'">'+
    '<div class="taca-stat-icon text-'+color+'"><i class="fas fa-'+icon+'"></i></div>'+
    '<div><div class="taca-stat-count">'+number(value)+'</div><div class="small text-muted">'+esc(label)+'</div></div></div></div>';
}
function statusBadge(x){
  if(!Number(x.pending))return '<span class="badge badge-success">Al día</span>';
  if(Number(x.incomplete_courses))return '<span class="badge badge-danger">Notas pendientes</span>';
  return '<span class="badge badge-warning">Cierres pendientes</span>';
}
function teacherRows(teachers){
  var q=search.toLowerCase(), filtered=teachers.filter(function(x){
    return !q || String(x.teacher_name||'').toLowerCase().includes(q) ||
      (x.pending_courses||[]).join(' ').toLowerCase().includes(q);
  }),active=cfg.yearStatus==='Activo' && state &&
     Number(state.period.is_locked)!==1;
  $('#taca-teacher-count').text(filtered.length+' docente(s)');
  if(!filtered.length){
    $('#taca-teacher-list').html('<div class="alert alert-light border mb-0">No se encontraron docentes para este filtro.</div>');
    return;
  }
  var rows=filtered.map(function(x){
    var pct=Number(x.total)?Math.round(100*Number(x.closed)/Number(x.total)):0;
    var note=Number(x.missing_cells) ?
      '<span class="small text-danger">'+number(x.missing_cells)+' calificaciones sin completar</span>' :
      '<span class="small text-muted">'+(Number(x.pending)?'Sin calificaciones faltantes detectadas':'Sin pendientes de cierre')+'</span>';
    var list=(x.pending_courses||[]).slice(0,3).map(function(c){
      return '<div class="small text-muted text-truncate" title="'+esc(c)+'">'+esc(c)+'</div>';
    }).join('');
    if((x.pending_courses||[]).length>3)list+='<div class="small text-muted">Y '+(x.pending_courses.length-3)+' cursos más</div>';
    var button=active&&Number(x.pending)>0 ?
      '<button type="button" class="btn btn-sm btn-outline-primary taca-send" data-user="'+Number(x.recipient_user_id)+'"><i class="fas fa-paper-plane mr-1"></i>Recordar</button>' :
      '<button type="button" class="btn btn-sm btn-light border" disabled title="Sin cursos pendientes o periodo bloqueado">Sin envío</button>';
    return '<div class="taca-teacher-row py-3 px-2"><div class="row align-items-center">'+
      '<div class="col-12 col-lg-4 mb-2 mb-lg-0"><strong>'+esc(x.teacher_name)+'</strong>'+
      '<div class="mt-1">'+statusBadge(x)+'</div></div>'+
      '<div class="col-6 col-md-3 col-lg-2"><div class="small text-muted">Cerrados</div><strong>'+number(x.closed)+' / '+number(x.total)+'</strong>'+
      '<div class="progress mt-1 taca-progress"><div class="progress-bar bg-success" style="width:'+pct+'%"></div></div></div>'+
      '<div class="col-6 col-md-3 col-lg-2"><div class="small text-muted">Sin cerrar</div><strong>'+number(x.pending)+'</strong><div>'+note+'</div></div>'+
      '<div class="col-12 col-md-4 col-lg-3 my-2 my-md-0">'+list+'</div>'+
      '<div class="col-12 col-md-2 col-lg-1 text-lg-right">'+button+'</div>'+
      '</div></div>';
  }).join('');
  $('#taca-teacher-list').html(rows);
}
function history(items){
  if(!items.length){$('#taca-history').html('<div class="small text-muted py-3">Todavía no se han generado avisos para este bimestre.</div>');return;}
  var out='<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="thead-light"><tr><th>Fecha</th><th>Docente</th><th>Aviso</th><th>Entrega FCM</th></tr></thead><tbody>';
  items.forEach(function(x){
    var delivered=Number(x.sent),failed=Number(x.failed),pending=Number(x.pending);
    var label=delivered>0?'<span class="badge badge-success">Aceptado por FCM</span>':
      failed>0?'<span class="badge badge-danger">Fallido</span>':
      pending>0?'<span class="badge badge-warning">Reintento</span>':
      '<span class="badge badge-secondary">En bandeja / sin envío confirmado</span>';
    out+='<tr><td class="small text-muted">'+esc(x.created_at)+'</td>'+
      '<td>'+esc(x.recipient_name)+'</td><td><strong>'+esc(x.title)+'</strong>'+
      '<div class="small text-muted">'+esc(x.message)+'</div></td>'+
      '<td>'+label+'</td></tr>';
  });
  $('#taca-history').html(out+'</tbody></table></div>');
}
function fillSettings(r){
  var p=r.period||{},s=r.settings||{};
  var due=r.effective_deadline||'';
  $('#taca-date').val(s.deadline_date||'');
  $('#taca-days').val(s.notice_days_before||3);
  $('#taca-enabled-closure').prop('checked',Number(s.closure_enabled)===1);
  $('#taca-enabled-incomplete').prop('checked',Number(s.incomplete_enabled)===1);
  $('#taca-enabled-admin').prop('checked',Number(s.admin_edits_enabled)===1);
  $('#taca-effective').text('Fecha académica: '+(p.end_date||'no configurada')+
    ' · Fecha límite aplicada: '+(due||'sin configurar'));
  $('#taca-settings-form :input').prop('disabled',cfg.yearStatus!=='Activo');
  $('#taca-save').prop('disabled',cfg.yearStatus!=='Activo');
}
function load(){
  $('#taca-error').empty();
  $('#taca-loading').removeClass('d-none');
  $('#taca-data').addClass('d-none');
  return api({action:'status'},false).done(function(r){
    if(Number(r.status)!==1){$('#taca-error').html('<div class="alert alert-warning">'+esc(r.message||'No se pudieron obtener los avisos.')+'</div>');return;}
    state=r;var t=r.totals||{};
    $('#taca-stats').html(
      stat('Asignaciones cerradas',t.closed,'check-circle','success')+
      stat('Cierres pendientes',t.pending,'clock','warning')+
      stat('Cursos con notas faltantes',t.incomplete,'exclamation-circle','danger')+
      stat('Docentes',t.teachers,'users','primary')
    );
    fillSettings(r); teacherRows(r.teachers||[]); history(r.history||[]);
    $('#taca-data').removeClass('d-none');
  }).fail(function(xhr){
    var r=xhr.responseJSON||{};
    var extra=r.migration_required?' Debe ejecutarse manualmente la migración SQL del panel docente.':'';
    $('#taca-error').html('<div class="alert alert-warning">'+esc((r.message||'No se pudo cargar el seguimiento.')+extra)+'</div>');
  }).always(function(){$('#taca-loading').addClass('d-none');});
}
$('#taca-bim').on('change',function(){search='';$('#taca-search').val('');load();});
$('#taca-search').on('input',function(){search=$.trim(this.value);if(state)teacherRows(state.teachers||[]);});
$('#taca-reload').on('click',load);
$('#taca-settings-form').on('submit',function(e){
  e.preventDefault();if(cfg.yearStatus!=='Activo')return;
  var btn=$('#taca-save').prop('disabled',true);
  api({action:'save_settings',deadline_date:$('#taca-date').val(),
    notice_days_before:$('#taca-days').val(),
    closure_enabled:$('#taca-enabled-closure').is(':checked')?'1':'0',
    incomplete_enabled:$('#taca-enabled-incomplete').is(':checked')?'1':'0',
    admin_edits_enabled:$('#taca-enabled-admin').is(':checked')?'1':'0'},true)
  .done(function(r){if(Number(r.status)===1){alertMessage(r.message);load();}else alertMessage(r.message||'No se pudo guardar.','danger');})
  .fail(function(x){alertMessage((x.responseJSON||{}).message||'Error al guardar.','danger');})
  .always(function(){btn.prop('disabled',false);});
});
$(document).on('click','.taca-send',function(){
  if(!state)return;
  var id=Number($(this).data('user'));
  var t=(state.teachers||[]).find(function(t){return Number(t.recipient_user_id)===id;});
  if(!t||!Number(t.pending))return;
  $('#taca-reminder-recipient').val(id);
  $('#taca-reminder-target').text(t.teacher_name+' · '+t.pending+' curso(s) pendientes');
  $('#taca-reminder-note').val('');
  $('#taca-reminder-modal').modal('show');
});
$('#taca-reminder-form').on('submit',function(e){
  e.preventDefault();var id=Number($('#taca-reminder-recipient').val());if(!id)return;
  var btn=$('#taca-reminder-confirm').prop('disabled',true);
  api({action:'send_reminder',recipient_user_id:id,note:$('#taca-reminder-note').val()},true)
    .done(function(r){
      if(Number(r.status)===1){$('#taca-reminder-modal').modal('hide');alertMessage(r.message);load();}
      else alertMessage(r.message||'No se pudo registrar el recordatorio.','warning');
    }).fail(function(x){alertMessage((x.responseJSON||{}).message||'No se pudo enviar el aviso.','danger');})
    .always(function(){btn.prop('disabled',false);});
});
$('a[href="#ay-cierre-notas"]').on('shown.bs.tab',function(){load();});
$('#ay-refresh-grade-control').on('click',function(){
  if($('#ay-cierre-notas').hasClass('show'))load();
});
window.EduSyncTeacherAlertsRefresh=load;
})(window.jQuery);
