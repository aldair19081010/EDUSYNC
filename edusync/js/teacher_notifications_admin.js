(function($){
'use strict';
if(!$ || !window.EduSyncTeacherAlerts) return;

var cfg=window.EduSyncTeacherAlerts;
var endpoint='teacher_notifications_admin_api.php';
var $root=$('#teacher-followup-root');
if(!$root.length) return;
var state=null, requestNumber=0, periodRequestNumber=0;
var years=Array.isArray(cfg.years)?cfg.years:[];
var yearId=Number(cfg.yearId||0);

function esc(value){return $('<span>').text(value==null?'':String(value)).html();}
function num(value){return Number(value||0).toLocaleString('es-PE');}
function notify(msg,type){
  if(typeof window.alert_toast==='function')window.alert_toast(msg,type||'success');
  else window.alert(msg);
}
function year(){
  return years.find(function(y){return Number(y.id)===yearId;})||null;
}
function isEditable(){
  var y=year();
  return !!y && y.status==='Activo' && Number(y.active)===1;
}
function term(){
  var bim=Number($('#taca-bim').val()||1);
  return bim+'° bimestre';
}
function api(data,post){
  var values=$.extend({},data,{academic_year_id:yearId,bimester:Number($('#taca-bim').val()||1)});
  if(post){
    values.csrf_token=cfg.csrf;
    return $.ajax({url:endpoint,type:'POST',data:values,dataType:'json'});
  }
  return $.getJSON(endpoint,values);
}
function stat(label,value,tone,icon){
  return '<div class="col-4 mb-2"><div class="tf-stat tf-stat-'+tone+'">'+
    '<i class="fas fa-'+icon+' tf-stat-icon text-'+tone+'"></i>'+
    '<div><strong class="tf-stat-value">'+num(value)+'</strong>'+
    '<div class="tf-stat-caption">'+esc(label)+'</div></div></div></div>';
}
function statusBadge(t){
  if(!Number(t.pending))return '<span class="badge badge-success">Al día</span>';
  if(isEditable() && Number(t.incomplete_courses)>0)return '<span class="badge badge-danger">Notas incompletas</span>';
  return '<span class="badge badge-warning">Por cerrar</span>';
}
function teacherRows(){
  if(!state)return;
  var search=$.trim($('#taca-search').val()||'').toLocaleLowerCase('es-PE');
  var filter=String($('#taca-filter').val()||'pending');
  var teachers=(state.teachers||[]).filter(function(t){
    var pending=Number(t.pending),incomplete=Number(t.incomplete_courses);
    if(filter==='pending'&&pending===0)return false;
    if(filter==='incomplete'&&incomplete===0)return false;
    if(filter==='done'&&pending!==0)return false;
    return !search || String(t.teacher_name||'').toLocaleLowerCase('es-PE').includes(search) ||
      (t.pending_courses||[]).join(' ').toLocaleLowerCase('es-PE').includes(search);
  });
  $('#taca-teacher-count').text(teachers.length+' de '+(state.teachers||[]).length+' docentes');
  if(!teachers.length){
    $('#taca-teacher-list').html('<div class="text-center text-muted small py-4">No hay docentes con este filtro.</div>');
    return;
  }
  var canNotify=isEditable() && state.period &&
    Number(state.period.is_locked)!==1 && Number(state.period.global_locked||0)!==1;
  var html=teachers.map(function(t){
    var total=Number(t.total),closed=Number(t.closed),pending=Number(t.pending),cells=Number(t.missing_cells);
    var subtitle=pending===0?'Todos sus cursos están cerrados':
      pending+' '+(pending===1?'curso por cerrar':'cursos por cerrar')+
      (isEditable()&&cells>0?' · '+num(cells)+' notas sin completar':'');
    var button=canNotify && pending>0 ?
      '<button type="button" class="btn btn-sm btn-outline-primary taca-send" data-user="'+Number(t.recipient_user_id)+'"><i class="fas fa-paper-plane mr-1"></i>Avisar</button>' :
      '<button type="button" class="btn btn-sm btn-light border" disabled>Sin envío</button>';
    return '<div class="tf-teacher-row">'+
      '<div class="tf-teacher-identity"><strong>'+esc(t.teacher_name)+'</strong><div class="small text-muted">'+esc(subtitle)+'</div></div>'+
      '<div class="tf-teacher-progress"><span class="small text-muted">'+closed+'/'+total+' cerrados</span><div class="progress"><div class="progress-bar bg-success" style="width:'+Math.min(100,total>0?Math.round(100*closed/total):0)+'%"></div></div></div>'+
      '<div class="tf-teacher-state">'+statusBadge(t)+'</div>'+
      '<div class="tf-teacher-action">'+button+'</div>'+
    '</div>';
  }).join('');
  $('#taca-teacher-list').html(html);
}
function historyRows(){
  if(!state)return;
  var items=state.history||[];
  if(!items.length){
    $('#taca-history').html('<div class="text-center text-muted py-4">No hay avisos registrados para '+esc(term())+'.</div>');
    return;
  }
  var out='<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="thead-light"><tr>'+
    '<th>Fecha</th><th>Destinatario</th><th>Aviso</th><th>Estado FCM</th></tr></thead><tbody>';
  items.forEach(function(it){
    var status=Number(it.sent)>0?
      '<span class="badge badge-success">Aceptado por FCM</span>':
      Number(it.failed)>0?'<span class="badge badge-danger">Fallido</span>':
      Number(it.pending)>0?'<span class="badge badge-warning">Reintento</span>':
      '<span class="badge badge-secondary">En bandeja</span>';
    out+='<tr><td class="small text-muted text-nowrap">'+esc(it.created_at)+'</td>'+
      '<td>'+esc(it.recipient_name)+'</td>'+
      '<td><strong>'+esc(it.title)+'</strong><div class="small text-muted">'+esc(it.message)+'</div></td>'+
      '<td>'+status+'</td></tr>';
  });
  $('#taca-history').html(out+'</tbody></table></div>');
}
function fillSettings(){
  if(!state)return;
  var s=state.settings||{},p=state.period||{},editable=isEditable();
  $('#taca-date').val(s.deadline_date||'');
  $('#taca-days').val(s.notice_days_before||3);
  $('#taca-enabled-closure').prop('checked',Number(s.closure_enabled)===1);
  $('#taca-enabled-incomplete').prop('checked',Number(s.incomplete_enabled)===1);
  $('#taca-enabled-admin').prop('checked',Number(s.admin_edits_enabled)===1);
  $('#taca-settings-context').text((year()?year().year:'')+' · '+term()+(editable?'':' · Solo lectura'));
  $('#taca-effective').text('Fecha académica: '+(p.end_date||'sin definir')+
    ' · Fecha de aviso aplicada: '+(state.effective_deadline||'sin definir'));
  $('#taca-settings-form').find('input,select,textarea').prop('disabled',!editable);
  $('#taca-save').prop('disabled',!editable).toggleClass('d-none',!editable);
}
function render(){
  if(!state)return;
  var totals=state.totals||{};
  $('#taca-stats').html(
    stat('Cerrados',totals.closed,'success','check-circle')+
    stat('Pendientes',totals.pending,'warning','clock')+
    stat('Incompletos',totals.incomplete,'danger','exclamation-circle')
  );
  teacherRows();
  fillSettings();
  historyRows();
  $('#taca-data').removeClass('d-none');
}
function load(){
  var id=++requestNumber;
  state=null;
  $('#taca-data').addClass('d-none');
  $('#taca-error').empty();
  if(!yearId)return $.Deferred().resolve().promise();
  $('#taca-loading').removeClass('d-none');
  return api({action:'status'},false).done(function(response){
    if(id!==requestNumber)return;
    if(Number(response.status)!==1){
      $('#taca-error').html('<div class="alert alert-warning">'+esc(response.message||'No se pudo consultar el seguimiento.')+'</div>');
      return;
    }
    state=response;
    render();
  }).fail(function(xhr){
    if(id!==requestNumber)return;
    var r=xhr.responseJSON||{};
    var extra=r.migration_required?' Ejecuta la migración SQL de Seguimiento docente.':'';
    $('#taca-error').html('<div class="alert alert-warning">'+esc((r.message||'No se pudo cargar el seguimiento.')+extra)+'</div>');
  }).always(function(){
    if(id===requestNumber)$('#taca-loading').addClass('d-none');
  });
}
function refreshPeriodAndLoad(){
  var rid=++periodRequestNumber;
  ++requestNumber;
  state=null;
  $('#taca-data').addClass('d-none');
  $('#taca-error').empty();
  if(!yearId){$('#taca-loading').addClass('d-none');return;}
  $('#taca-loading').removeClass('d-none');
  var selectedBim=String($('#taca-bim').val()||'1');
  $.getJSON('ajax.php?action=get_academic_periods',{academic_year_id:yearId})
  .done(function(result){
    if(rid!==periodRequestNumber)return;
    var periods=Array.isArray(result.periods)?result.periods:[];
    var today=new Date();
    var localDate=today.getFullYear()+'-'+String(today.getMonth()+1).padStart(2,'0')+'-'+String(today.getDate()).padStart(2,'0');
    var available=periods.filter(function(p){return Number(p.period_number)>=1&&Number(p.period_number)<=4;});
    var current=available.find(function(p){return p.start_date&&p.end_date&&localDate>=p.start_date&&localDate<=p.end_date;});
    if(!current){
      var previous=available.filter(function(p){return p.start_date&&p.start_date<=localDate;});
      current=previous[previous.length-1]||null;
    }
    if(!current){
      current=available.find(function(p){return String(p.period_number)===selectedBim;})||available[0];
    }
    if(current)$('#taca-bim').val(String(current.period_number));
  })
  .always(function(){if(rid===periodRequestNumber)load();});
}
$('#taca-year').on('change',function(){
  yearId=Number($(this).val()||0);
  $('#taca-search').val('');
  $('#taca-filter').val('pending');
  refreshPeriodAndLoad();
});
$('#taca-bim').on('change',function(){
  ++periodRequestNumber;
  $('#taca-search').val('');
  $('#taca-filter').val('pending');
  load();
});
$('#taca-search').on('input',teacherRows);
$('#taca-filter').on('change',teacherRows);
$('#taca-reload').on('click',load);
$('#taca-settings-open').on('click',function(){
  if(!state){notify('Selecciona un periodo con información.','warning');return;}
  fillSettings();
  $('#taca-settings-modal').modal('show');
});
$('#taca-history-open').on('click',function(){
  if(!state){notify('Selecciona un periodo con información.','warning');return;}
  historyRows();
  $('#taca-history-modal').modal('show');
});
$('#taca-settings-form').on('submit',function(e){
  e.preventDefault();
  if(!isEditable())return;
  var btn=$('#taca-save').prop('disabled',true);
  api({action:'save_settings',
      deadline_date:$('#taca-date').val(),
      notice_days_before:$('#taca-days').val(),
      closure_enabled:$('#taca-enabled-closure').is(':checked')?'1':'0',
      incomplete_enabled:$('#taca-enabled-incomplete').is(':checked')?'1':'0',
      admin_edits_enabled:$('#taca-enabled-admin').is(':checked')?'1':'0'
    },true).done(function(response){
      if(Number(response.status)===1){
        $('#taca-settings-modal').modal('hide');
        notify(response.message||'Configuración guardada.');
        load();
      }else notify(response.message||'No se pudo guardar.','danger');
    }).fail(function(xhr){
      notify((xhr.responseJSON||{}).message||'No se pudo guardar.','danger');
    }).always(function(){btn.prop('disabled',false);});
});
$('#taca-teacher-list').on('click','.taca-send',function(){
  if(!state)return;
  var id=Number($(this).data('user'));
  var teacher=(state.teachers||[]).find(function(t){return Number(t.recipient_user_id)===id;});
  if(!teacher||Number(teacher.pending)<1)return;
  $('#taca-reminder-recipient').val(id);
  $('#taca-reminder-target').text(teacher.teacher_name+' · '+teacher.pending+' curso(s) pendientes');
  $('#taca-reminder-note').val('');
  $('#taca-reminder-modal').modal('show');
});
$('#taca-reminder-form').on('submit',function(e){
  e.preventDefault();
  var recipient=Number($('#taca-reminder-recipient').val());
  if(!recipient||!isEditable())return;
  var btn=$('#taca-reminder-confirm').prop('disabled',true);
  api({action:'send_reminder',recipient_user_id:recipient,note:$('#taca-reminder-note').val()},true)
  .done(function(response){
    if(Number(response.status)===1){
      $('#taca-reminder-modal').modal('hide');
      notify(response.message||'Recordatorio registrado.');
      load();
    }else notify(response.message||'No se pudo registrar el aviso.','warning');
  }).fail(function(xhr){
    notify((xhr.responseJSON||{}).message||'No se pudo registrar el aviso.','danger');
  }).always(function(){btn.prop('disabled',false);});
});
if(yearId)refreshPeriodAndLoad();
})(window.jQuery);
