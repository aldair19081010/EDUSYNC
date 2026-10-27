(function($){
'use strict';
if(!$ || !window.EduSyncTeacherAlerts) return;
var cfg=window.EduSyncTeacherAlerts,endpoint='teacher_notifications_admin_api.php';
if(!$('#teacher-followup-root').length) return;

var years=Array.isArray(cfg.years)?cfg.years:[];
var yearId=Number(cfg.yearId||0),state=null,table=null,requestNumber=0,periodNumber=0;
var gradeRequest=null,gradesLoaded=false,historyKey='',historyData=null;
var gradeMatrix=null,matrixRequest=null,matrixLoading=false,detailTeacherId=0;

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
function bimester(){return Number($('#taca-bim').val()||1);}
function periodLabel(){return bimester()+'° bimestre';}
function api(data,post){
  var args=$.extend({},data,{academic_year_id:yearId,bimester:bimester()});
  if(post){args.csrf_token=cfg.csrf;return $.ajax({url:endpoint,type:'POST',data:args,dataType:'json'});}
  return $.getJSON(endpoint,args);
}
function complianceState(t){
  var total=Number(t.total||0),closed=Number(t.closed||0),pending=Math.max(0,total-closed);
  if(total>0&&pending===0)return 'Cumplió';
  if(gradesLoaded&&Number(t.incomplete_courses)>0)return 'Notas incompletas';
  if(closed>0)return 'En proceso';
  return 'Sin cierres';
}
function badge(t){
  var x=complianceState(t),colors={'Cumplió':'success','Notas incompletas':'danger','En proceso':'info','Sin cierres':'warning'};
  return '<span class="badge badge-'+(colors[x]||'secondary')+'">'+esc(x)+'</span>';
}
function stats(){
  if(!state)return;
  var teachers=state.teachers||[],s=state.totals||{},total=Number(s.assignments||0),closed=Number(s.closed||0),
      pct=total?Math.min(100,Math.round(100*closed/total)):0;
  var cards=[
    {label:'Docentes',value:teachers.length,icon:'users',color:'primary'},
    {label:'Cumplieron',value:teachers.filter(function(t){return Number(t.total)>0&&Number(t.pending)===0;}).length,icon:'check-circle',color:'success'},
    {label:'En proceso',value:teachers.filter(function(t){return Number(t.closed)>0&&Number(t.pending)>0;}).length,icon:'clock',color:'info'},
    {label:'Sin cierres',value:teachers.filter(function(t){return Number(t.total)>0&&Number(t.closed)===0;}).length,icon:'exclamation-circle',color:'warning'}
  ];
  $('#taca-stats').html(cards.map(function(x){
    return '<div class="col-6 col-lg-3 mb-2"><div class="tf-stat tf-stat-'+x.color+'">'+
      '<i class="fas fa-'+x.icon+' tf-stat-icon text-'+x.color+'"></i>'+
      '<div><strong class="tf-stat-value">'+Number(x.value).toLocaleString('es-PE')+'</strong>'+
      '<div class="tf-stat-caption">'+esc(x.label)+'</div></div></div></div>';
  }).join(''));
  $('#taca-compliance-value').text(pct+'%');
  $('#taca-compliance-caption').text(closed+' de '+total+' asignaciones cerradas formalmente');
  $('#taca-compliance-bar').css('width',pct+'%').attr('aria-valuenow',pct);
}
function rowCells(t){
  var total=Number(t.total),closed=Number(t.closed),pending=Number(t.pending);
  var pct=total>0?Math.max(0,Math.min(100,Math.round(100*closed/total))):0;
  var details='<button type="button" class="btn btn-sm btn-outline-primary taca-detail" data-teacher="'+Number(t.teacher_id)+'">'+
    '<i class="fas fa-list mr-1"></i>Detalle</button>';
  var notify=canEdit()&&alertsReady()&&state&&state.period&&
    Number(state.period.is_locked)!==1&&Number(state.period.global_locked||0)!==1&&pending>0&&Number(t.recipient_user_id)>0?
    '<button type="button" class="btn btn-sm btn-outline-secondary taca-send" data-user="'+Number(t.recipient_user_id)+'">'+
      '<i class="fas fa-paper-plane mr-1"></i>Avisar</button>':'';
  return [
    '<strong class="tf-person">'+esc(t.teacher_name)+'</strong>',
    '<div class="text-nowrap"><strong>'+closed+'</strong> / '+total+'</div>'+
      '<div class="progress tf-mini-progress"><div class="progress-bar bg-success" style="width:'+pct+'%"></div></div>',
    '<span class="'+(pending>0?'font-weight-bold text-warning':'text-success')+'">'+pending+'</span>',
    !canEdit()?'<span class="text-muted" title="No se recalculan calificaciones de años históricos">—</span>':
      !gradesLoaded?'<span class="small text-muted">Calculando…</span>':
      Number(t.missing_cells)>0?'<strong class="text-danger">'+num(t.missing_cells)+'</strong>':'<span class="text-success">0</span>',
    badge(t),
    '<div class="tf-row-actions">'+details+notify+'</div>'
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
            if(type!=='display')return complianceState(t);
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
          if(f==='progress')return Number(t.closed)>0&&Number(t.pending)>0;
          if(f==='not_started')return Number(t.closed)===0&&Number(t.total)>0;
          if(f==='done')return Number(t.total)>0&&Number(t.pending)===0;
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
  if(detailTeacherId)renderTeacherDetail();
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
function periodAssignments(){
  return Array.isArray(gradeMatrix)?gradeMatrix:[];
}
function teacherAssignments(t){
  return periodAssignments().filter(function(x){return Number(x.teacher_id)===Number(t.teacher_id);});
}
function teacherForDetail(){
  return state&&Array.isArray(state.teachers)?state.teachers.find(function(t){return Number(t.teacher_id)===detailTeacherId;}):null;
}
function closeDetail(){
  detailTeacherId=0;
  var detail=$('#taca-teacher-detail');
  if(detail.hasClass('show'))detail.modal('hide');
  $('#taca-detail-body').empty();
}
function renderTeacherDetail(){
  if(!detailTeacherId)return;
  var t=teacherForDetail();if(!t){closeDetail();return;}
  var total=Number(t.total||0),closed=Number(t.closed||0),pending=Number(t.pending||0),
    pct=total?Math.round(100*closed/total):0;
  var subtitle=(currentYear()?currentYear().year:'')+' · '+periodLabel()+' · '+closed+'/'+total+' asignaciones cerradas';
  $('#taca-detail-heading').text(t.teacher_name||'Docente');
  $('#taca-detail-subtitle').text(subtitle);
  var html='<div class="d-flex align-items-center justify-content-between flex-wrap mb-2">'+
    '<div><strong>'+pct+'% de cierres</strong><div class="small text-muted">'+pending+' asignación(es) sin cerrar</div></div>'+badge(t)+'</div>'+
    '<div class="progress tf-compliance-bar mb-3"><div class="progress-bar bg-success" style="width:'+pct+'%"></div></div>';
  var items=teacherAssignments(t);
  if(matrixLoading && gradeMatrix===null){
    html+='<div class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando asignaciones del docente...</div>';
  }else if(gradeMatrix===null){
    html+='<div class="alert alert-light border small">No se pudo recuperar el detalle académico por curso; el resumen de cierres sigue disponible.</div>';
    if((t.pending_courses||[]).length)html+='<strong class="small">Cursos sin cierre</strong><ul class="small mt-2">'+
      t.pending_courses.map(function(name){return '<li>'+esc(name)+'</li>';}).join('')+'</ul>';
  }else if(!items.length){
    html+='<div class="alert alert-light border small">No se encontraron asignaciones académicas para este docente en el periodo.</div>';
  }else{
    html+='<div class="table-responsive"><table class="table table-sm table-hover mb-0 tf-detail-table"><thead class="thead-light">'+
      '<tr><th>Curso y aula</th><th>Notas</th><th>Estado del cierre</th><th>Fecha</th></tr></thead><tbody>';
    items.forEach(function(x){
      html+='<tr><td><strong>'+esc(x.course_name)+'</strong><div class="small text-muted">'+esc(x.level)+' · '+
        esc(gradeLabel(x.grado))+' '+esc(x.seccion)+'</div></td>'+
        '<td>'+Number(x.grade_progress||0).toFixed(1)+'%</td>'+
        '<td>'+badgeForAssignment(x.state)+'</td><td class="small">'+(x.closed?dateOf(x.closed_at):'—')+'</td></tr>';
    });
    html+='</tbody></table></div><p class="small text-muted mt-2 mb-0">«Listo» significa que las notas están completas, pero la asignación aún no ha sido cerrada.</p>';
  }
  $('#taca-detail-body').html(html);
}
function badgeForAssignment(x){
  var colors={'Cerrado':'success','Listo':'primary','Incompleto':'warning',
    'Reabierto':'info','Reapertura pendiente':'danger'};
  return '<span class="badge badge-'+(colors[x]||'secondary')+'">'+esc(x||'Pendiente')+'</span>';
}
// Normaliza grados recibidos con o sin símbolo ordinal (p. ej. 3, 3° o 3°°).
function gradeLabel(v){
  var value=String(v==null?'':v).trim().replace(/[°º]+\s*$/g,'').trim();
  return value?value+'°':'';
}
function dateOf(v){
  if(!v)return '—';
  var d=String(v).match(/^(\d{4})-(\d\d)-(\d\d)(?:[ T](\d\d):(\d\d))?/);
  return d?d[3]+'/'+d[2]+'/'+d[1]+(d[4]?' '+d[4]+':'+d[5]:''):esc(v);
}
function loadGradeMatrix(token,yearKey,periodKey){
  if(matrixRequest&&matrixRequest.readyState!==4)matrixRequest.abort();
  gradeMatrix=null;matrixLoading=true;
  matrixRequest=$.getJSON('academic_year_grade_control_api.php',
    {action:'progress',academic_year_id:yearKey})
    .done(function(result){
      if(token!==requestNumber||yearKey!==yearId||periodKey!==bimester())return;
      if(Number(result.status)===1){
        var p=result.periods||{},record=p[periodKey]||p[String(periodKey)]||{};
        gradeMatrix=Array.isArray(record.items)?record.items:null;
      }
    }).always(function(){
      if(token!==requestNumber||yearKey!==yearId||periodKey!==bimester())return;
      matrixLoading=false;
      if(detailTeacherId)renderTeacherDetail();
    });
}
function reportRows(){
  if(!state||!Array.isArray(state.teachers))return [];
  return state.teachers.map(function(t){
    var total=Number(t.total||0),closed=Number(t.closed||0),pending=Math.max(0,total-closed);
    return {id:Number(t.teacher_id),name:String(t.teacher_name||''),total:total,closed:closed,
      pending:pending,pct:total?Math.round(100*closed/total):0,state:complianceState(t),
      missing:!canEdit()?'No calculado':gradesLoaded?Number(t.missing_cells||0):'Pendiente cálculo',
      courses:teacherAssignments(t)};
  });
}
function safeCsv(v){
  var text=String(v==null?'':v);
  if(/^[ \t\r\n]*[=+@-]/.test(text))text="'"+text;
  return '"'+text.replace(/"/g,'""')+'"';
}
function exportComplianceCsv(){
  var rows=reportRows();if(!rows.length)return;
  var yr=(currentYear()||{}).year||'',cells=[
    ['EduSync · Cumplimiento docente'],
    ['Año académico',yr,'Periodo',periodLabel()],
    ['Alcance','Docentes activos del año y bimestre seleccionados'],
    ['Criterio','Cierres formales de asignaciones; la fecha de avisos no implica un plazo oficial de cumplimiento'],[],
    ['Docente','ID docente','Asignaciones','Cerradas','Pendientes','Cumplimiento','Estado','Notas faltantes']
  ];
  rows.forEach(function(r){cells.push([r.name,r.id,r.total,r.closed,r.pending,r.pct+'%',r.state,r.missing]);});
  if(gradeMatrix!==null){
    cells.push([],['Detalle de asignaciones'],['Docente','Curso','Nivel','Grado','Sección','Avance notas','Estado cierre','Fecha cierre','Responsable']);
    rows.forEach(function(r){
      r.courses.forEach(function(x){cells.push([r.name,x.course_name,x.level,gradeLabel(x.grado),x.seccion,
        Number(x.grade_progress||0).toFixed(1)+'%',x.state,x.closed_at||'',x.closed_by_name||'']);});
    });
  }else cells.push([],['Detalle por curso','No disponible en esta consulta']);
  var content='\ufeff'+cells.map(function(row){return row.map(safeCsv).join(';');}).join('\r\n');
  var blob=new Blob([content],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),
    link=document.createElement('a');
  link.href=url;link.download='EduSync_cumplimiento_'+String(yr)+'_bim'+bimester()+'.csv';
  document.body.appendChild(link);link.click();link.remove();
  setTimeout(function(){URL.revokeObjectURL(url);},1000);
}
function printCompliance(){
  var rows=reportRows();if(!rows.length)return;
  var frame=document.getElementById('taca-report-preview');
  if(!frame){toast('No se encontró la vista previa del reporte. Recarga la página.','warning');return;}
  var year=(currentYear()||{}).year||'';
  var tableRows=rows.map(function(r){return '<tr><td>'+esc(r.name)+'</td><td>'+r.total+'</td><td>'+r.closed+'</td>'+
    '<td>'+r.pending+'</td><td>'+r.pct+'%</td><td>'+esc(r.state)+'</td><td>'+esc(r.missing)+'</td></tr>';}).join('');
  var detail='';
  if(gradeMatrix!==null){
    detail='<h2>Detalle de asignaciones</h2><table><thead><tr><th>Docente</th><th>Curso</th><th>Aula</th><th>Avance de notas</th><th>Estado</th><th>Fecha de cierre</th></tr></thead><tbody>';
    rows.forEach(function(r){r.courses.forEach(function(x){detail+='<tr><td>'+esc(r.name)+'</td><td>'+esc(x.course_name)+'</td>'+
      '<td>'+esc(x.level)+' · '+esc(gradeLabel(x.grado))+' '+esc(x.seccion)+'</td><td>'+Number(x.grade_progress||0).toFixed(1)+'%</td>'+
      '<td>'+esc(x.state)+'</td><td>'+dateOf(x.closed_at)+'</td></tr>';});});
    detail+='</tbody></table>';
  }else detail='<p>El detalle por asignación no estuvo disponible en esta consulta.</p>';
  var html='<!doctype html><html lang="es"><head><meta charset="utf-8"><title>EduSync · Cumplimiento docente</title>'+
    '<style>body{font:12px Arial,sans-serif;color:#2d3952;padding:24px}h1{font-size:22px;margin-bottom:5px}h2{font-size:16px;margin-top:24px}'+
    'p{color:#657187}table{width:100%;border-collapse:collapse;margin:12px 0}th,td{padding:7px;text-align:left;border:1px solid #cbd5e1}th{background:#edf2ff}'+
    '@page{size:A4 landscape;margin:13mm}</style></head><body>'+
    '<h1>EduSync · Reporte de cumplimiento docente</h1>'+
    '<p>Año académico '+esc(year)+' · '+esc(periodLabel())+' · Consulta '+esc(new Date().toLocaleString('es-PE'))+'</p>'+
    '<p>Docentes activos. Cumplimiento = asignaciones formalmente cerradas / asignaciones asignadas. No se califica atraso sin fecha oficial.</p>'+
    '<h2>Resumen por docente</h2><table><thead><tr><th>Docente</th><th>Asignadas</th><th>Cerradas</th><th>Pendientes</th><th>Cumplimiento</th><th>Estado</th><th>Notas faltantes</th></tr></thead><tbody>'+
    tableRows+'</tbody></table>'+detail+'</body></html>';
  // El reporte queda dentro del modal. El botón inferior imprime únicamente
  // el iframe, sin crear pestañas ni navegar fuera del sistema.
  $('#taca-report-print').prop('disabled',true);
  $('#taca-report-subtitle').text('Año '+year+' · '+periodLabel()+' · '+rows.length+' docente(s)');
  frame.onload=function(){
    $('#taca-report-print').prop('disabled',false);
  };
  frame.srcdoc=html;
  $('#taca-report-modal').modal('show');
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
  gradeMatrix=null;matrixLoading=false;closeDetail();
  if(matrixRequest&&matrixRequest.readyState!==4)matrixRequest.abort();
  $('#taca-export-csv,#taca-print-report').prop('disabled',true);
  $('#taca-data').addClass('d-none');
  $('#taca-error').empty();
  if(!yearId)return;
  $('#taca-loading').removeClass('d-none');
  api({action:'status'},false).done(function(response){
    if(token!==requestNumber||yearId!==yearKey||bimester()!==periodKey)return;
    if(Number(response.status)!==1){
      $('#taca-error').html('<div class="alert alert-warning">'+esc(response.message||'No se pudo cargar el seguimiento.')+'</div>');
      return;
    }
    state=response;
    $('#taca-export-csv,#taca-print-report').prop('disabled',!Array.isArray(response.teachers)||response.teachers.length===0);
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
    loadGradeMatrix(token,yearKey,periodKey);
  }).fail(function(xhr){
    if(token!==requestNumber)return;
    var r=xhr.responseJSON||{},http=xhr.status||0;
    var msg=r.message||('No se pudo consultar Seguimiento Docente (HTTP '+http+').');
    if(r.error_ref){
      msg+=' [Etapa: '+String(r.stage||'no identificada')+'; referencia: '+String(r.error_ref)+']';
    }
    if(r.migration_required){
      msg+=' Tabla pendiente: '+(r.missing_table||((r.missing_tables||[]).join(', '))||'consultar migraciones')+'.';
    }else if(http===404){
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
  state=null;closeDetail();gradeMatrix=null;
  $('#taca-export-csv,#taca-print-report').prop('disabled',true);
  $('#taca-data').addClass('d-none');
  $('#taca-loading').removeClass('d-none');
  if(!yearId){$('#taca-loading').addClass('d-none');return;}
  $.getJSON('ajax.php?action=get_academic_periods',{academic_year_id:yearId})
    .done(function(response){
      if(pToken!==periodNumber)return;
      var periods=Array.isArray(response.periods)?response.periods:[];
      var today=new Date(),now=today.getFullYear()+'-'+
        String(today.getMonth()+1).padStart(2,'0')+'-'+String(today.getDate()).padStart(2,'0');
      var valid=periods.filter(function(p){return Number(p.period_number)>=1&&Number(p.period_number)<=4;});
      var active=valid.find(function(p){
        return p.start_date&&p.end_date&&now>=p.start_date&&now<=p.end_date;
      });
      if(!active){
        var past=valid.filter(function(p){return p.start_date&&p.start_date<=now;});
        active=past[past.length-1]||valid[0];
      }
      if(active)$('#taca-bim').val(String(active.period_number));
    })
    .always(function(){if(pToken===periodNumber)load();});
}
$('#taca-year').on('change',function(){
  yearId=Number(this.value||0);
  $('#taca-filter').val('all');
  if(table)table.search('');
  selectYearAndLoad();
});
$('#taca-bim').on('change',function(){
  ++periodNumber;
  $('#taca-filter').val('all');
  if(table)table.search('');
  load();
});
$('#taca-filter').on('change',function(){
  if(table)table.draw();
  else if(state)drawTable();
});
$('#taca-reload').on('click',load);
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
$('#taca-teachers-table').on('click','.taca-detail',function(){
  if(!state)return;
  detailTeacherId=Number($(this).attr('data-teacher')||0);
  renderTeacherDetail();
  $('#taca-teacher-detail').modal('show');
});
$('#taca-teacher-detail').on('hidden.bs.modal',function(){
  detailTeacherId=0;
  $('#taca-detail-body').empty();
});
$('#taca-export-csv').on('click',exportComplianceCsv);
$('#taca-print-report').on('click',printCompliance);
$('#taca-report-print').on('click',function(){
  var frame=document.getElementById('taca-report-preview');
  var doc=frame&&frame.contentWindow;
  if(!doc||!frame.srcdoc){toast('La vista previa todavía no está disponible.','warning');return;}
  try{
    doc.focus();
    doc.print();
  }catch(err){
    toast('No se pudo abrir la impresión del reporte desde la vista previa. Comprueba los permisos del navegador.','warning');
  }
});
$('#taca-report-modal').on('hidden.bs.modal',function(){
  var frame=document.getElementById('taca-report-preview');
  if(frame){frame.onload=null;frame.removeAttribute('srcdoc');}
  $('#taca-report-print').prop('disabled',true);
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
