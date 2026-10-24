(function($){
'use strict';
if(!$ || !window.EduSyncTeacherAlerts) return;
var cfg=window.EduSyncTeacherAlerts,endpoint='teacher_notifications_admin_api.php';
if(!$('#teacher-followup-root').length) return;

var years=Array.isArray(cfg.years)?cfg.years:[];
var yearId=Number(cfg.yearId||0),state=null,table=null,requestNumber=0,periodNumber=0;
var gradeRequest=null,gradesLoaded=false,historyKey='',historyData=null;

function esc(value){return $('<span>').text(value==null?'':String(value)).html();}
function num(value){return Number(value||0).toLocaleString('es-PE');}
function toast(msg,type){
  if(typeof window.alert_toast==='function') window.alert_toast(msg,type||'success');
  else window.alert(msg);
}
function explainApiFailure(xhr,fallback){
  var r=xhr.responseJSON||{},code=Number(xhr.status||0);
  var message=r.message||fallback+' (HTTP '+code+').';
  if(r.error_ref)message+=' [Etapa: '+String(r.stage||'desconocida')+
    '; referencia: '+String(r.error_ref)+']';
  if(Number(r.db_errno)>0)message+=' [MySQL: '+Number(r.db_errno)+']';
  if(r.db_hint)message+=' '+String(r.db_hint);
  if(r.missing_tables&&r.missing_tables.length)message+=' Tablas: '+r.missing_tables.join(', ')+'.';
  return message;
}
function currentYear(){return years.find(function(y){return Number(y.id)===yearId;})||null;}
function canEdit(){var y=currentYear();return !!y&&y.status==='Activo'&&Number(y.active)===1;}
function alertsReady(){return !!state && state.alerts_ready!==false;}
function bimester(){return Number($('#taca-bim').val()||0);}
function periodLabel(){return bimester()+'° bimestre';}
function showPeriodSetup(){
  $('#taca-settings-open, #taca-history-open').prop('disabled',true);
  var link='index.php?page=academic_year&manage=1&id='+encodeURIComponent(String(yearId));
  $('#taca-error').html('<div class="alert alert-warning" role="alert">'+
    '<strong>Este año académico todavía no tiene bimestres configurados.</strong> '+
    'En Años académicos, abre Gestionar > Periodos y guarda los bimestres de esta institución. '+
    '<a class="alert-link" href="'+link+'">Configurar periodos</a>.</div>');
}
function api(data,post){
  var args=$.extend({},data,{academic_year_id:yearId,bimester:bimester()});
  if(post){args.csrf_token=cfg.csrf;return $.ajax({url:endpoint,type:'POST',data:args,dataType:'json'});}
  return $.getJSON(endpoint,args);
}
function badge(t){
  if(Number(t.pending)===0)return '<span class="badge badge-success">Al día</span>';
  if(canEdit()&&gradesLoaded&&Number(t.incomplete_courses)>0)
    return '<span class="badge badge-danger">Incompleto</span>';
  return '<span class="badge badge-warning">Pendiente</span>';
}
function stats(){
  if(!state)return;
  var s=state.totals||{},missing=(!canEdit()?'—':gradesLoaded?num(s.incomplete):'…');
  var data=[
    {label:'Cerrados',value:num(s.closed),icon:'check-circle',color:'success'},
    {label:'Pendientes',value:num(s.pending),icon:'clock',color:'warning'},
    {label:'Incompletos',value:missing,icon:'exclamation-circle',color:'danger'}
  ];
  $('#taca-stats').html(data.map(function(x){
    return '<div class="col-4 mb-2"><div class="tf-stat tf-stat-'+x.color+'">'+
      '<i class="fas fa-'+x.icon+' tf-stat-icon text-'+x.color+'"></i>'+
      '<div><strong class="tf-stat-value">'+x.value+'</strong>'+
      '<div class="tf-stat-caption">'+esc(x.label)+'</div></div></div></div>';
  }).join(''));
}
function rowCells(t){
  var total=Number(t.total),closed=Number(t.closed),pending=Number(t.pending);
  var complete=total>0?Math.max(0,Math.min(100,Math.round(100*closed/total))):0;
  var courseText=(t.pending_courses||[]).join(' | ');
  return [
    '<strong class="tf-person">'+esc(t.teacher_name)+'</strong>'+
      (courseText?'<div class="small text-muted text-truncate tf-course" title="'+esc(courseText)+'">'+esc(courseText)+'</div>':''),
    '<div class="text-nowrap"><strong>'+closed+'</strong> / '+total+'</div>'+
      '<div class="progress tf-mini-progress"><div class="progress-bar bg-success" style="width:'+complete+'%"></div></div>',
    '<span class="'+(pending>0?'font-weight-bold text-warning':'text-success')+'">'+pending+'</span>',
    !canEdit()?'<span class="text-muted">—</span>':
      !gradesLoaded?'<span class="small text-muted">Calculando…</span>':
      Number(t.missing_cells)>0?'<span class="font-weight-bold text-danger">'+num(t.missing_cells)+'</span>':
      '<span class="text-success">0</span>',
    badge(t),
    canEdit()&&alertsReady()&&state&&state.period&&
      Number(state.period.is_locked)!==1&&Number(state.period.global_locked||0)!==1&&pending>0&&Number(t.recipient_user_id)>0?
      '<button type="button" class="btn btn-sm btn-outline-primary taca-send" data-user="'+Number(t.recipient_user_id)+'"><i class="fas fa-paper-plane mr-1"></i>Avisar</button>':
      Number(t.recipient_user_id)===0?
      '<span class="badge badge-light border text-muted" title="El docente aún no tiene una cuenta de usuario docente activa">Sin cuenta</span>':
      '<button type="button" class="btn btn-sm btn-light border" disabled>—</button>'
  ];
}
function drawTable(){
  var items=state&&state.teachers?state.teachers:[];
  if($.fn.DataTable){
    if(!table){
      table=$('#taca-teachers-table').DataTable({
        data:[],
        columns:[
          {data:null,render:function(v,type,t){
            if(type==='filter')return String(t.teacher_name||'')+' '+(t.pending_courses||[]).join(' ');
            if(type==='sort')return String(t.teacher_name||'');
            return rowCells(t)[0];
          }},
          {data:'closed',render:function(v,type,t){return type==='display'?rowCells(t)[1]:Number(v);}},
          {data:'pending',render:function(v,type,t){return type==='display'?rowCells(t)[2]:Number(v);}},
          {data:'missing_cells',render:function(v,type,t){
            return type==='display'?rowCells(t)[3]:Number(v||0);
          }},
          {data:null,render:function(v,type,t){
            if(type!=='display')return Number(t.pending)===0?'Al día':gradesLoaded&&Number(t.incomplete_courses)>0?'Incompleto':'Pendiente';
            return rowCells(t)[4];
          }},
          {data:null,orderable:false,searchable:false,render:function(v,type,t){
            return type==='display'?rowCells(t)[5]:'';
          }}
        ],
        pageLength:10,lengthMenu:[[10,25,50],[10,25,50]],
        order:[[0,'asc']],
        autoWidth:false,
        columnDefs:[{targets:[1,2,3,4,5],className:'text-center align-middle'}],
        language:{
          search:'Buscar:',
          lengthMenu:'Mostrar _MENU_',
          info:'Mostrando _START_ a _END_ de _TOTAL_ docentes',
          infoEmpty:'Sin docentes',
          infoFiltered:'(filtrados de _MAX_)',
          emptyTable:'No hay docentes asignados en este periodo',
          zeroRecords:'No se encontraron docentes con ese filtro',
          paginate:{previous:'Anterior',next:'Siguiente'}
        }
      });
      $('#taca-teachers-table_wrapper').addClass('ed-datatable');
      $('#taca-teachers-table_wrapper .pagination').addClass('ed-pagination');
      if($.fn.dataTable.ext && $.fn.dataTable.ext.search){
        $.fn.dataTable.ext.search.push(function(settings,display,index){
          if(!settings.nTable||settings.nTable.id!=='taca-teachers-table')return true;
          var t=table&&table.row(index).data();
          if(!t)return true;
          var f=$('#taca-filter').val();
          if(f==='pending')return Number(t.pending)>0;
          if(f==='done')return Number(t.pending)===0;
          if(f==='incomplete')return gradesLoaded&&Number(t.incomplete_courses)>0;
          return true;
        });
      }
    }
    table.clear();
    if(items.length)table.rows.add(items);
    table.draw();
    table.columns.adjust();
  }else{
    // Respaldo por si DataTables aún no se carga.
    $('#taca-teachers-table tbody').html(items.map(function(t){
      return '<tr>'+rowCells(t).map(function(v){return '<td>'+v+'</td>';}).join('')+'</tr>';
    }).join(''));
  }
  $('#taca-teacher-count').text(items.length+' docente(s)');
}
function redrawRows(){
  if(!state)return;
  stats();
  if(table)table.rows().invalidate().draw(false);
  else drawTable();
}
function fillSettings(){
  if(!state)return;
  var s=state.settings||{},p=state.period||{},editable=canEdit()&&alertsReady();
  $('#taca-date').val(s.deadline_date||'');
  $('#taca-days').val(s.notice_days_before||3);
  $('#taca-enabled-closure').prop('checked',Number(s.closure_enabled)===1);
  $('#taca-enabled-incomplete').prop('checked',Number(s.incomplete_enabled)===1);
  $('#taca-enabled-admin').prop('checked',Number(s.admin_edits_enabled)===1);
  $('#taca-settings-context').text((currentYear()?currentYear().year:'')+' · '+periodLabel()+(editable?'':' · Solo lectura'));
  $('#taca-effective').text('Fin académico: '+(p.end_date||'sin definir')+
    ' · Límite para avisos: '+(state.effective_deadline||'sin definir'));
  $('#taca-settings-form').find('input,select,textarea').prop('disabled',!editable);
  $('#taca-save').prop('disabled',!editable).toggleClass('d-none',!editable);
}
function renderHistory(items){
  if(!items||!items.length){
    $('#taca-history').html('<div class="text-center text-muted py-4">No hay avisos de '+esc(periodLabel())+'.</div>');
    return;
  }
  var html='<div class="table-responsive"><table class="table table-sm table-bordered table-hover mb-0">'+
    '<thead class="thead-light"><tr><th>Fecha</th><th>Docente</th><th>Aviso</th><th>Entrega FCM</th></tr></thead><tbody>';
  items.forEach(function(t){
    var mark=Number(t.sent)>0?'<span class="badge badge-success">Aceptado FCM</span>':
      Number(t.failed)>0?'<span class="badge badge-danger">Fallido</span>':
      Number(t.pending)>0?'<span class="badge badge-warning">Reintento</span>':
      '<span class="badge badge-secondary">En bandeja</span>';
    html+='<tr><td class="text-nowrap small">'+esc(t.created_at)+'</td><td>'+esc(t.recipient_name)+
      '</td><td><strong>'+esc(t.title)+'</strong><div class="small text-muted">'+esc(t.message)+
      '</div></td><td>'+mark+'</td></tr>';
  });
  $('#taca-history').html(html+'</tbody></table></div>');
}
function loadMissing(token,yearKey,periodKey){
  if(!canEdit()) {
    gradesLoaded=false;
    $('#taca-grades-status').text('Año histórico: no se recalculan notas con el padrón actual');
    return;
  }
  $('#taca-grades-status').html('<i class="fas fa-spinner fa-spin mr-1"></i>Calculando notas faltantes…');
  gradeRequest=api({action:'missing_counts'},false).done(function(r){
    if(token!==requestNumber||yearId!==yearKey||bimester()!==periodKey||!state)return;
    if(Number(r.status)!==1)return;
    var map=r.missing_by_course||{},missingTotal=0;
    (state.teachers||[]).forEach(function(t){
      var count=0,subjects=0;
      (t.pending_course_ids||[]).forEach(function(course){
        var cells=Number(map[String(course)]||0);
        if(cells>0){subjects++;count+=cells;}
      });
      t.missing_cells=count;
      t.incomplete_courses=subjects;
      missingTotal+=subjects;
    });
    state.totals.incomplete=missingTotal;
    gradesLoaded=true;
    $('#taca-grades-status').text('Notas verificadas');
    redrawRows();
  }).fail(function(xhr,status){
    if(status==='abort'||token!==requestNumber)return;
    $('#taca-grades-status').text('No se pudieron calcular las notas pendientes');
  });
}
function load(){
  var token=++requestNumber,yearKey=yearId,periodKey=bimester();
  if(gradeRequest&&gradeRequest.readyState!==4)gradeRequest.abort();
  state=null;gradesLoaded=false;historyKey='';historyData=null;
  $('#taca-data').addClass('d-none');
  $('#taca-error').empty();
  if(!yearId)return;
  if(!bimester()){
    showPeriodSetup();
    $('#taca-loading').addClass('d-none');
    return;
  }
  $('#taca-loading').removeClass('d-none');
  api({action:'status'},false).done(function(response){
    if(token!==requestNumber||yearId!==yearKey||bimester()!==periodKey)return;
    if(Number(response.status)!==1){
      $('#taca-error').html('<div class="alert alert-warning">'+esc(response.message||'No se pudo cargar el seguimiento.')+'</div>');
      return;
    }
    state=response;
    if(!alertsReady()){
      $('#taca-error').html('<div class="alert alert-warning" role="alert">'+
        '<strong>Seguimiento en modo consulta.</strong> Puedes revisar los cierres, pero los avisos y su configuración están desactivados porque faltan tablas de notificaciones. '+
        'Ejecuta la migración <code>sql/teacher_alert_admin_control_v1.sql</code> y verifica las tablas indicadas en el servidor.</div>');
    }
    $('#taca-settings-open, #taca-history-open').prop('disabled',!alertsReady());
    $('#taca-data').removeClass('d-none');
    $('#taca-grades-status').text(canEdit()?'Revisando notas…':'Año histórico · cierres únicamente');
    stats();drawTable();fillSettings();
    loadMissing(token,yearKey,periodKey);
  }).fail(function(xhr){
    if(token!==requestNumber)return;
    var r=xhr.responseJSON||{},http=xhr.status||0;
    if(r.error_code==='TACA_PERIOD_NOT_CONFIGURED'){
      showPeriodSetup();
      return;
    }
    var msg=r.message||('No se pudo consultar Seguimiento Docente (HTTP '+http+').');
    if(r.error_ref){
      msg+=' [Etapa: '+String(r.stage||'no identificada')+'; referencia: '+String(r.error_ref)+']';
    }
    if(r.migration_required){
      msg+=' Tabla pendiente: '+(r.missing_table||((r.missing_tables||[]).join(', '))||'consultar migraciones')+'.';
    }else if(http===404 && !xhr.responseJSON){
      msg+=' Verifica que teacher_notifications_admin_api.php esté publicado en producción.';
    }else if(http===401||http===403){
      msg+=' Comprueba tu sesión de administrador y vuelve a iniciar sesión si es necesario.';
    }
    $('#taca-error').html('<div class="alert alert-warning" role="alert">'+esc(msg)+'</div>');
  }).always(function(){if(token===requestNumber)$('#taca-loading').addClass('d-none');});
}
function selectYearAndLoad(){
  var pToken=++periodNumber;
  ++requestNumber;
  if(gradeRequest&&gradeRequest.readyState!==4)gradeRequest.abort();
  state=null;
  $('#taca-data').addClass('d-none');
  $('#taca-error').empty();
  $('#taca-loading').removeClass('d-none');
  var selector=$('#taca-bim').empty()
    .append($('<option>',{value:'',text:'Cargando bimestres...'})).prop('disabled',true);
  if(!yearId){$('#taca-loading').addClass('d-none');return;}
  $.getJSON('ajax.php?action=get_academic_periods',{academic_year_id:yearId})
    .done(function(response){
      if(pToken!==periodNumber)return;
      if(Number(response.status)!==1){
        $('#taca-loading').addClass('d-none');
        $('#taca-error').html('<div class="alert alert-warning">'+
          esc(response.msg||response.message||'No se pudieron consultar los periodos.')+'</div>');
        return;
      }
      var periods=Array.isArray(response.periods)?response.periods:[];
      var today=new Date(),now=today.getFullYear()+'-'+
        String(today.getMonth()+1).padStart(2,'0')+'-'+String(today.getDate()).padStart(2,'0');
      var valid=periods.filter(function(p){return Number(p.period_number)>=1&&Number(p.period_number)<=4;})
        .sort(function(a,b){return Number(a.period_number)-Number(b.period_number);});
      var active=valid.find(function(p){
        return p.start_date&&p.end_date&&now>=p.start_date&&now<=p.end_date;
      });
      if(!active){
        var past=valid.filter(function(p){return p.start_date&&p.start_date<=now;});
        active=past[past.length-1]||valid[0];
      }
      selector.empty();
      if(valid.length){
        valid.forEach(function(p){
          selector.append($('<option>',{
            value:String(p.period_number),
            text:String(p.name||p.period_number+'° bimestre')
          }));
        });
        selector.prop('disabled',false).val(String(active.period_number));
      }else{
        selector.append($('<option>',{value:'',text:'Sin bimestres configurados'}));
      }
      load();
    })
    .fail(function(xhr,status){
      if(status==='abort'||pToken!==periodNumber)return;
      $('#taca-loading').addClass('d-none');
      $('#taca-error').html('<div class="alert alert-warning">'+
        esc((xhr.responseJSON||{}).message||'No se pudieron consultar los bimestres del año seleccionado.')+
        '</div>');
    });
}
$('#taca-year').on('change',function(){
  yearId=Number(this.value||0);
  $('#taca-filter').val('pending');
  if(table)table.search('');
  selectYearAndLoad();
});
$('#taca-bim').on('change',function(){
  ++periodNumber;
  $('#taca-filter').val('pending');
  if(table)table.search('');
  load();
});
$('#taca-filter').on('change',function(){
  if(table)table.draw();
  else if(state)drawTable();
});
$('#taca-reload').on('click',selectYearAndLoad);
$('#taca-settings-open').on('click',function(){
  if(!state){toast('Selecciona un periodo con información.','warning');return;}
  $('#taca-settings-error').remove();
  fillSettings();$('#taca-settings-modal').modal('show');
});
$('#taca-history-open').on('click',function(){
  if(!state){toast('Selecciona un periodo con información.','warning');return;}
  var token=requestNumber,key=yearId+':'+bimester();
  $('#taca-history-modal').modal('show');
  if(historyKey===key && historyData!==null){renderHistory(historyData);return;}
  $('#taca-history').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando historial…</div>');
  api({action:'history'},false).done(function(result){
    if(token!==requestNumber)return;
    if(Number(result.status)!==1){
      $('#taca-history').html('<div class="alert alert-warning">'+esc(result.message||'Historial no disponible.')+'</div>');
      return;
    }
    historyKey=key;historyData=result.history||[];
    renderHistory(historyData);
  }).fail(function(xhr){
    if(token!==requestNumber)return;
    $('#taca-history').html('<div class="alert alert-warning">'+
      esc((xhr.responseJSON||{}).message||'No se pudo consultar el historial.')+'</div>');
  });
});
$('#taca-settings-form').on('submit',function(e){
  e.preventDefault();
  $('#taca-settings-error').remove();
  if(!canEdit()||!alertsReady())return;
  var btn=$('#taca-save').prop('disabled',true);
  api({action:'save_settings',deadline_date:$('#taca-date').val(),
    notice_days_before:$('#taca-days').val(),
    closure_enabled:$('#taca-enabled-closure').is(':checked')?'1':'0',
    incomplete_enabled:$('#taca-enabled-incomplete').is(':checked')?'1':'0',
    admin_edits_enabled:$('#taca-enabled-admin').is(':checked')?'1':'0'},true)
  .done(function(result){
    if(Number(result.status)===1){
      $('#taca-settings-modal').modal('hide');
      toast((result.message||'Configuración guardada.')+
        (result.audit_warning?' No se pudo registrar la auditoría auxiliar.':''),result.audit_warning?'warning':'success');
      load();
    }else toast(result.message||'No se pudo guardar.','danger');
  }).fail(function(xhr){
    var message=explainApiFailure(xhr,'No se pudo guardar la configuración');
    $('<div>',{id:'taca-settings-error',class:'alert alert-danger',role:'alert'})
      .text(message).insertBefore('#taca-settings-context');
    toast('No se pudo guardar. Revisa el detalle en la ventana.','danger');
  }).always(function(){btn.prop('disabled',false);});
});
$('#taca-teachers-table').on('click','.taca-send',function(){
  if(!state)return;
  var id=Number($(this).data('user'));
  var t=(state.teachers||[]).find(function(t){return Number(t.recipient_user_id)===id;});
  if(!t||Number(t.pending)===0)return;
  $('#taca-reminder-recipient').val(id);
  $('#taca-reminder-target').text(t.teacher_name+' · '+t.pending+' curso(s) sin cerrar');
  $('#taca-reminder-note').val('');
  $('#taca-reminder-modal').modal('show');
});
$('#taca-reminder-form').on('submit',function(e){
  e.preventDefault();
  var recipient=Number($('#taca-reminder-recipient').val());
  if(!recipient||!canEdit())return;
  var btn=$('#taca-reminder-confirm').prop('disabled',true);
  api({action:'send_reminder',recipient_user_id:recipient,note:$('#taca-reminder-note').val()},true)
  .done(function(r){
    if(Number(r.status)===1){
      $('#taca-reminder-modal').modal('hide');toast(r.message||'Recordatorio registrado.');load();
    }else toast(r.message||'No se pudo enviar el recordatorio.','warning');
  }).fail(function(xhr){
    toast(explainApiFailure(xhr,'No se pudo registrar el aviso'),'danger');
  }).always(function(){btn.prop('disabled',false);});
});
if(yearId)load();
})(window.jQuery);
