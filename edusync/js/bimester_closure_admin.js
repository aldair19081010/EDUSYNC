/* EduSync · Control de cierre bimestral unificado (solo Administración).
 * Todas las acciones usan las API existentes y su auditoría/validaciones.
 */
(function($){
'use strict';
if(!$ || !document.getElementById('bc-root'))return;
var cfg=window.EduSyncBimesterClose||{},years=cfg.years||[];
var data={matrix:null,items:[],teachers:[],teacherStatus:null,meta:{},institution:null,errors:[]};
var ticket=0, action=null,selected=new Set(),tables={teacher:null,course:null};
var $id=function(id){return $('#'+id);};
var esc=function(v){return $('<div>').text(v==null?'':String(v)).html();};
var num=function(v){return Number(v||0);};
var yearId=function(){return Number($id('bc-year').val()||0);};
var bimester=function(){return Number($id('bc-bim').val()||1);};
var year=function(){return years.find(function(x){return Number(x.id)===yearId();})||null;};
var editable=function(){var y=year();return !!y&&y.status==='Activo'&&Number(y.active)===1;};
var period=function(){var p=data.matrix&&data.matrix.periods||{};return p[bimester()]||p[String(bimester())]||{summary:{},items:[]};};
var summary=function(){return period().summary||{};};
var gradeLabel=function(v){var s=String(v||'');return /^[1-9]$/.test(s)?s+'°':s;};
var aula=function(x){return esc(x.level||'')+' · '+esc(gradeLabel(x.grado))+' '+esc(x.seccion||'');};
var canEdit=function(){return editable() && !Number(summary().locked) && !((data.institution||{}).locked);};
var date=function(v){if(!v)return '—';var p=String(v).split(' ')[0].split('-');return p.length===3?p[2]+'/'+p[1]+'/'+p[0]:'—';};
var amount=function(n){return Number(n||0).toLocaleString('es-PE');};
function toast(msg,kind){if(typeof window.alert_toast==='function')window.alert_toast(msg,kind||'success');else window.alert(msg);}
function message(xhr,fallback){var r=xhr&&xhr.responseJSON||{};return String(r.message||fallback||'Error de conexión.')+(r.error_ref?' [Ref. '+r.error_ref+']':'');}
function get(url,args){return $.getJSON(url,args);}
function post(url,args){return $.ajax({url:url,method:'POST',dataType:'json',data:$.extend({},args,{csrf_token:cfg.csrf})});}
function scope(action,args){return $.extend({action:action,academic_year_id:yearId(),bimester:bimester()},args||{});}
function gradeGet(action,args){return get('academic_year_grade_control_api.php',scope(action,args));}
function adminGet(action,args){return get('admin_grade_closure_api.php',scope(action,args));}
function adminPost(action,args){return post('admin_grade_closure_api.php',scope(action,args));}
function followGet(action,args){return get('teacher_notifications_admin_api.php',scope(action,args));}
function followPost(action,args){return post('teacher_notifications_admin_api.php',scope(action,args));}
function instGet(action,args){return get('institutional_bimester_close_api.php',scope(action,args));}
function instPost(action,args){return post('institutional_bimester_close_api.php',scope(action,args));}
function safeCall(p,label){return new Promise(function(resolve){p.done(function(r){resolve({ok:Number(r&&r.status)===1,value:r,error:r&&r.message});}).fail(function(x){resolve({ok:false,error:message(x,'No se pudo consultar '+label)});});});}
function linkTab(which){
  var target=$('#bc-tabs a[data-tab="'+which+'"]');
  if(target.length)target.tab('show');
}
function showError(text){$id('bc-error').html('<div class="alert alert-warning">'+esc(text)+'</div>');}
function statuses(x){var n=x&&x.state||'Incompleto',tones={'Cerrado':'success','Listo':'primary','Incompleto':'warning','Reabierto':'info','Reapertura pendiente':'danger'};return '<span class="badge badge-'+(tones[n]||'secondary')+'">'+esc(n)+'</span>';}
function progressLine(value){var n=Math.max(0,Math.min(100,Number(value||0)));return '<div class="progress bc-progress"><div class="progress-bar" style="width:'+n.toFixed(1)+'%"></div></div>';}
function stat(label,value,icon,tone){return '<div class="col-xl-3 col-sm-6 mb-3"><div class="bc-stat"><span class="bc-stat-icon '+(tone||'')+'"><i class="fas fa-'+icon+'"></i></span><div><small>'+esc(label)+'</small><strong>'+esc(value)+'</strong></div></div></div>';}
function reportTeachers(){
  if(data.teacherStatus && Array.isArray(data.teacherStatus.teachers))return data.teacherStatus.teachers;
  // Degradación de solo lectura si faltan las tablas opcionales de avisos.
  var grouped={};data.items.forEach(function(x){var name=x.teacher_name||'Docente sin nombre';
    if(!grouped[name])grouped[name]={teacher_name:name,total:0,closed:0,pending:0,pending_courses:[],recipient_user_id:0};
    var t=grouped[name];t.total++;if(x.closed)t.closed++;else {t.pending++;t.pending_courses.push(x.course_name+' · '+x.level+' · '+x.grado+' '+x.seccion);}
  });return Object.values(grouped);
}
function rebindTable(name,selector,sort){
  if(tables[name]&&$.fn.DataTable){tables[name].destroy();tables[name]=null;}
  if(!$.fn.DataTable)return;
  tables[name]=$(selector).DataTable({
    pageLength:10,lengthMenu:[[10,25,50],[10,25,50]],order:[sort||[0,'asc']],
    autoWidth:false,language:{search:'Buscar:',lengthMenu:'Mostrar _MENU_',info:'_START_–_END_ de _TOTAL_',infoEmpty:'Sin registros',zeroRecords:'No hay registros con esos filtros',paginate:{previous:'Anterior',next:'Siguiente'}}
  });
}
function renderOverview(){
  var s=summary(),total=num(s.total),closed=num(s.closed),ready=num(s.ready),incomplete=num(s.incomplete),
      done=total?Math.round(closed/total*100):0,remaining=Math.max(0,total-closed);
  $id('bc-kpis').html(stat('Asignaciones',amount(total),'layer-group')+stat('Cerradas',amount(closed),'check-circle','success')+
    stat('Listas para cerrar',amount(ready),'clipboard-check','info')+stat('Incompletas',amount(incomplete),'exclamation-triangle','warning'));
  var locked=!!num(s.locked) || !!(data.institution&&data.institution.locked);
  $id('bc-period-state').html('<span class="badge badge-'+(locked?'info':editable()?'warning':'secondary')+'">'+(locked?'Bimestre bloqueado':editable()?'Bimestre abierto':'Consulta histórica')+'</span>');
  $id('bc-overview-body').html('<div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted">Asignaciones cerradas</span><strong>'+done+'%</strong></div>'+
    progressLine(done)+'<p class="small text-muted mt-2 mb-3">'+closed+' de '+total+' asignaciones cerradas. El progreso de cierres es diferente al avance de calificaciones.</p>'+
    '<div class="row text-center"><div class="col-4"><strong class="text-success">'+closed+'</strong><div class="small text-muted">Cerradas</div></div><div class="col-4"><strong class="text-primary">'+ready+'</strong><div class="small text-muted">Listas</div></div><div class="col-4"><strong class="text-warning">'+remaining+'</strong><div class="small text-muted">Pendientes</div></div></div>');
  var next='';
  if(ready>0&&!locked)next+='<div class="bc-next-row"><i class="fas fa-check-circle text-primary"></i><div><strong>'+ready+' cursos listos, todavía sin cerrar</strong><div class="small text-muted">Revisa y cierra las asignaciones completas desde Control por cursos.</div><button class="btn btn-sm btn-outline-primary mt-2 bc-go-courses" type="button">Ver cursos</button></div></div>';
  if(incomplete>0)next+='<div class="bc-next-row"><i class="fas fa-exclamation-circle text-warning"></i><div><strong>'+incomplete+' cursos necesitan revisión</strong><div class="small text-muted">Consulta los pendientes y avisa a los docentes.</div><button class="btn btn-sm btn-outline-primary mt-2 bc-go-teachers" type="button">Seguimiento docente</button></div></div>';
  if(!remaining&&!num(s.pending_reopen)&&total>0&&!locked)next+='<div class="bc-next-row"><i class="fas fa-lock text-success"></i><div><strong>Todo listo para el cierre institucional</strong><div class="small text-muted">Verifica la información antes de bloquear el bimestre.</div><button class="btn btn-sm btn-success mt-2 bc-go-institution" type="button">Continuar al cierre</button></div></div>';
  if(num(s.pending_reopen))next+='<div class="bc-next-row"><i class="fas fa-unlock-alt text-danger"></i><div><strong>'+num(s.pending_reopen)+' solicitudes de reapertura pendientes</strong><div class="small text-muted">Deben resolverse antes del cierre normal.</div></div></div>';
  if(!next)next='<div class="alert alert-light border mb-0">No hay acciones urgentes para este periodo.</div>';
  $id('bc-next-actions').html(next);
}
function teacherRows(){
  return reportTeachers().map(function(t){
    var total=num(t.total),closed=num(t.closed),remaining=Math.max(0,total-closed),pct=total?Math.round(closed/total*100):0;
    var state=!remaining?'Cumplió':closed?'En proceso':'Pendiente';
    return {source:t,total:total,closed:closed,remaining:remaining,pct:pct,state:state};
  });
}
function renderTeachers(){
  var body='',items=teacherRows();
  items.forEach(function(r){
    var t=r.source,rec=num(t.recipient_user_id),canNotify=canEdit()&&r.remaining>0&&rec>0&&data.teacherStatus&&data.teacherStatus.alerts_ready!==false;
    body+='<tr><td><strong>'+esc(t.teacher_name)+'</strong><div class="small text-muted">'+esc((t.pending_courses||[]).slice(0,2).join(' · '))+'</div></td>'+
      '<td data-order="'+r.closed+'"><strong>'+r.closed+'</strong> / '+r.total+'</td><td data-order="'+r.remaining+'">'+r.remaining+'</td>'+
      '<td data-order="'+r.pct+'"><strong>'+r.pct+'%</strong>'+progressLine(r.pct)+'</td>'+
      '<td><span class="badge badge-'+(!r.remaining?'success':'warning')+'">'+esc(r.state)+'</span></td>'+
      '<td><div class="bc-actions-cell"><button class="btn btn-sm btn-outline-primary bc-teacher-courses" data-teacher="'+esc(t.teacher_name)+'">Ver cursos</button>'+
      (canNotify?'<button class="btn btn-sm btn-outline-secondary bc-send-reminder" data-user="'+rec+'">Avisar</button>':'')+'</div></td></tr>';
  });
  if(!body)body='<tr><td colspan="6" class="text-center text-muted py-4">No existen docentes asignados a este bimestre.</td></tr>';
  if(tables.teacher){tables.teacher.destroy();tables.teacher=null;}
  $id('bc-teacher-table').find('tbody').html(body);
  if(items.length)rebindTable('teacher','#bc-teacher-table',[0,'asc']);
  $id('bc-avisos-settings,#bc-avisos-history').prop('disabled',!data.teacherStatus||data.teacherStatus.alerts_ready===false);
}
function fillOptions(id,values){var unique=Array.from(new Set(values.filter(Boolean).map(String))).sort(function(a,b){return a.localeCompare(b,'es');});var before=$id(id).val();$id(id).html('<option value="">Todos</option>'+unique.map(function(v){return '<option value="'+esc(v)+'">'+esc(v)+'</option>';}).join(''));if(unique.includes(before))$id(id).val(before);}
function filterCourseRows(){
  return data.items.filter(function(x){
    return (!$id('bc-level').val()||x.level===$id('bc-level').val())
      &&(!$id('bc-teacher').val()||x.teacher_name===$id('bc-teacher').val())
      &&(!$id('bc-state').val()||x.state===$id('bc-state').val());
  });
}
function renderCourses(){
  fillOptions('bc-level',data.items.map(function(x){return x.level;}));
  fillOptions('bc-teacher',data.items.map(function(x){return x.teacher_name;}));
  var items=filterCourseRows(),rows='';
  items.forEach(function(x){
    var key=String(x.teacher_course_id),meta=data.meta[key]||{},closed=!!x.closed;
    var action=closed?'<button class="btn btn-sm btn-outline-info bc-view-course" data-id="'+key+'">Ver cierre</button>':
      x.ready&&canEdit()?'<button class="btn btn-sm btn-outline-success bc-close-one" data-id="'+key+'">Cerrar</button>':
      '<button class="btn btn-sm btn-outline-secondary bc-view-course" data-id="'+key+'">'+(x.ready?'Ver detalle':'Pendientes')+'</button>';
    rows+='<tr><td><input type="checkbox" class="bc-check" value="'+key+'" '+(selected.has(Number(key))?'checked':'')+' '+(!x.ready||closed||!canEdit()?'disabled':'')+' aria-label="Seleccionar '+esc(x.course_name)+'"></td>'+
      '<td><strong>'+esc(x.course_name)+'</strong><div class="small text-muted">'+aula(x)+'</div></td><td>'+esc(x.teacher_name)+'</td>'+
      '<td data-order="'+num(x.grade_progress)+'"><strong>'+num(x.grade_progress).toFixed(1)+'%</strong>'+progressLine(x.grade_progress)+'</td>'+
      '<td>'+statuses(x)+'</td><td class="small">'+(closed?(esc(meta.mode||'Cerrado')+'<div class="text-muted">'+date(meta.closed_at||x.closed_at)+'</div>'):'—')+'</td>'+
      '<td>'+action+'</td></tr>';
  });
  if(tables.course){tables.course.destroy();tables.course=null;}
  $id('bc-course-table').find('tbody').html(rows||'<tr><td colspan="7" class="text-center text-muted py-4">No hay asignaciones con los filtros actuales.</td></tr>');
  if(items.length)rebindTable('course','#bc-course-table',[1,'asc']);
  $id('bc-check-all').prop('checked',false).prop('disabled',!canEdit());
  $id('bc-close-ready').prop('disabled',!canEdit()||!data.items.some(function(x){return x.ready&&!x.closed;}));
  updateSelection();
}
function updateSelection(){
  var allowed=new Set(data.items.filter(function(x){return x.ready&&!x.closed;}).map(function(x){return Number(x.teacher_course_id);}));
  selected.forEach(function(id){if(!allowed.has(id))selected.delete(id);});
  $id('bc-close-selected').prop('disabled',!canEdit()||!selected.size).text('Cerrar seleccionados'+(selected.size?' ('+selected.size+')':''));
}
function renderReport(){
  var tr=teacherRows(),rows='';
  tr.forEach(function(r){rows+='<tr><td>'+esc(r.source.teacher_name)+'</td><td>'+r.total+'</td><td>'+r.closed+'</td><td>'+r.remaining+'</td><td>'+r.pct+'%</td><td>'+esc(r.state)+'</td></tr>';});
  $id('bc-report-table tbody').html(rows||'<tr><td colspan="6" class="text-center text-muted">No hay datos para reportar.</td></tr>');
  $id('bc-report-summary').text((year()?year().year:'')+' · '+bimester()+'° bimestre · '+tr.length+' docentes · '+num(summary().closed)+' cierres registrados');
  $id('bc-report-csv,#bc-report-print').prop('disabled',!tr.length);
}
function renderInstitution(){
  var s=summary(),total=num(s.total),closed=num(s.closed),pending=Math.max(0,total-closed),reopens=num(s.pending_reopen);
  var inst=data.institution,locked=!!(inst&&inst.locked)||!!num(s.locked),active=editable(),current=inst&&inst.current;
  var allReady=total>0&&pending===0&&reopens===0&&active&&!locked;
  var row=function(ok,title,detail){return '<div class="bc-check-row"><i class="fas fa-'+(ok?'check-circle text-success':'exclamation-circle text-warning')+'"></i><div><strong>'+title+'</strong><div class="small text-muted">'+detail+'</div></div></div>';};
  var html='<div class="d-flex align-items-center justify-content-between mb-3"><div><h5 class="mb-1">Verificación previa al cierre</h5><div class="text-muted small">El cierre institucional bloquea la edición de las notas del periodo.</div></div>'+
    '<span class="badge badge-'+(locked?'info':'warning')+'">'+(locked?'Bloqueado':'Abierto')+'</span></div>'+
    '<div class="bc-state-block mb-3">'+row(total>0,'Asignaciones: '+total,total?'El bimestre tiene cursos asignados.':'No hay cursos para cerrar.')+
    row(pending===0&&total>0,'Cierres individuales: '+closed+' / '+total,pending?pending+' sin cierre individual.':'Todos los cierres individuales registrados.')+
    row(reopens===0,'Solicitudes de reapertura: '+reopens,reopens?'Resuelve las solicitudes antes del cierre normal.':'Sin solicitudes pendientes.')+'</div>';
  if(locked){
    html+='<div class="alert alert-info"><strong>Bimestre bloqueado.</strong> '+(current&&current.status==='Cerrado'?'Existe un cierre institucional formal registrado.':'El bloqueo puede proceder de una versión anterior; consulta su historial.')+'</div>'+
      '<button type="button" class="btn btn-sm btn-outline-info mr-2 bc-view-inst-history">Ver historial</button>'+
      (active?'<button type="button" class="btn btn-sm btn-outline-warning bc-inst-reopen">Reabrir institucionalmente</button>':'');
  }else{
    html+='<button type="button" class="btn btn-primary bc-inst-normal" '+(!allReady?'disabled':'')+'><i class="fas fa-lock mr-1"></i>Confirmar cierre institucional</button>'+
      (!allReady?'<div class="small text-muted mt-2">Para cerrar normalmente, todas las asignaciones deben estar cerradas y no puede haber reaperturas pendientes.</div>':'');
    if(active&&total>0)html+='<details class="mt-4"><summary class="text-muted small">Acciones extraordinarias de Administración</summary><div class="alert alert-warning mt-2 small mb-2">El cierre excepcional bloquea las notas aun cuando existan pendientes. Requiere un motivo y confirmación expresa.</div><button type="button" class="btn btn-sm btn-outline-danger bc-inst-exceptional">Cierre excepcional</button></details>';
  }
  if(current){html+='<hr><div class="small text-muted"><strong>Último cierre:</strong> '+esc(current.closure_type||'Normal')+' · '+date(current.closed_at)+' · '+esc(current.closed_by_name||'')+'</div>';}
  if(!inst)html+='<div class="alert alert-warning mt-3 mb-0 small">El servicio de cierre institucional no está disponible en este entorno; las acciones están deshabilitadas.</div>';
  $id('bc-institution-body').html(html);
  if(!inst)$id('bc-institution-body button').prop('disabled',true);
}
function renderAll(){renderOverview();renderTeachers();renderCourses();renderReport();renderInstitution();}
function reload(){
  var seq=++ticket,id=yearId();
  selected.clear();
  $id('bc-error').empty();$id('bc-loading').removeClass('d-none');$id('bc-data').addClass('d-none');
  if(!id){$id('bc-loading').addClass('d-none');showError('No hay año académico para consultar. Configura un año en Años Académicos.');return;}
  Promise.all([
    safeCall(gradeGet('progress'),'el control de notas'),
    safeCall(followGet('status'),'el seguimiento docente'),
    safeCall(adminGet('closure_info'),'el historial de cierres'),
    safeCall(instGet('status'),'el cierre institucional')
  ]).then(function(all){
    if(seq!==ticket)return;
    var gr=all[0],tc=all[1],mt=all[2],ic=all[3];
    $id('bc-loading').addClass('d-none');
    if(!gr.ok){showError(gr.error||'No se pudo consultar el avance.');return;}
    data.matrix=gr.value;data.items=(period().items||[]);data.teacherStatus=tc.ok?tc.value:null;
    data.teachers=reportTeachers();data.meta=mt.ok?(mt.value.items||{}):{};data.institution=ic.ok?ic.value:null;
    data.errors=all.filter(function(x,i){return i>0&&!x.ok;}).map(function(x){return x.error;});
    if(data.errors.length)showError('Algunas funciones no están disponibles: '+data.errors.join(' · '));
    $id('bc-data').removeClass('d-none');renderAll();
  }).catch(function(err){if(seq!==ticket)return;$id('bc-loading').addClass('d-none');showError(err.message||'No se pudo cargar la información.');});
}
function findCourse(id){return data.items.find(function(x){return Number(x.teacher_course_id)===Number(id);})||null;}
function modal(title,html,onSubmit,kind){
  action=onSubmit;$id('bc-action-title').text(title);$id('bc-action-body').html(html);
  $id('bc-action-confirm').removeClass('btn-success btn-danger btn-warning btn-primary').addClass('btn-'+(kind||'primary')).prop('disabled',false).text('Confirmar');
  $id('bc-action-modal').modal('show');
}
function issues(x){var i=x.issues||[];return i.length?'<ul class="bc-issue-list">'+i.map(function(z){return '<li>'+esc(z)+'</li>';}).join('')+'</ul>':'<div class="text-success small"><i class="fas fa-check-circle mr-1"></i>Sin observaciones.</div>';}
function closeOne(x,exceptional){
  if(!canEdit())return;
  var html='<p><strong>'+esc(x.course_name)+'</strong> · '+aula(x)+'<br><small class="text-muted">'+esc(x.teacher_name)+'</small></p>'+
    '<p class="small">El curso quedará en modo lectura después del cierre.</p>';
  if(exceptional)html+='<div class="alert alert-warning small">La asignación tiene pendientes y será cerrada de forma excepcional.</div>'+issues(x)+
    '<div class="form-group mt-2"><label for="bc-reason">Motivo obligatorio (mínimo 5 caracteres)</label><textarea id="bc-reason" class="form-control" maxlength="500" rows="3" required></textarea></div>'+
    '<label class="small"><input type="checkbox" id="bc-confirm-special"> Confirmo el cierre con pendientes.</label>';
  modal(exceptional?'Cierre administrativo excepcional':'Cerrar asignación',html,function(){
    var reason=$id('bc-reason').val()||'';
    if(exceptional&&(reason.trim().length<5||!$id('bc-confirm-special').is(':checked'))){toast('Indica el motivo y confirma el cierre excepcional.','warning');return;}
    return adminPost('close_assignment',{teacher_course_id:x.teacher_course_id,force:exceptional?1:0,reason:reason}).then(function(r){
      if(Number(r.status)===1){$id('bc-action-modal').modal('hide');toast(r.message||'Curso cerrado.');reload();}
      else if(Number(r.status)===2)toast('El curso tiene pendientes. Puedes revisarlos o usar un cierre excepcional autorizado.','warning');
      else toast(r.message||'No se pudo registrar el cierre.','danger');
    },function(xhr){toast(message(xhr,'No se pudo cerrar el curso.'),'danger');});
  },exceptional?'danger':'success');
}
function viewCourse(x){
  var meta=data.meta[String(x.teacher_course_id)]||{};
  var html='<h6>'+esc(x.course_name)+' · '+aula(x)+'</h6><p class="small text-muted">'+esc(x.teacher_name)+' · '+bimester()+'° bimestre · '+num(x.grade_progress).toFixed(1)+'% de notas registradas</p>';
  if(x.closed){
    html+='<div class="alert alert-success"><strong>Cierre registrado</strong><br>Responsable: '+esc(meta.closed_by_name||x.closed_by_name||'—')+
      '<br>Fecha: '+date(meta.closed_at||x.closed_at)+'<br>Tipo: '+esc(meta.mode||'Cerrado')+
      '<br>Versión: '+num(meta.closure_version||1)+(meta.reason?'<br>Motivo: '+esc(meta.reason):'')+'</div>';
  }else{
    html+='<h6>Pendientes detectados</h6>'+issues(x);
    if(canEdit()&&!x.ready)html+='<hr><div class="small text-muted">El cierre excepcional requiere autorización administrativa y quedará auditado.</div><button type="button" class="btn btn-sm btn-outline-danger mt-2" id="bc-from-detail-exceptional">Evaluar cierre excepcional</button>';
  }
  modal('Detalle de la asignación',html,function(){ $id('bc-action-modal').modal('hide');},'primary');
  $id('bc-action-confirm').text('Cerrar detalle');
  $id('bc-from-detail-exceptional').one('click',function(){$id('bc-action-modal').one('hidden.bs.modal',function(){closeOne(x,true);}).modal('hide');});
}
function bulk(ids){
  if(!canEdit()||!ids.length)return;
  modal('Confirmar cierre de asignaciones','<p>Se procesarán <strong>'+ids.length+'</strong> asignaciones listas, previa validación del servidor.</p><p class="small text-muted">Se procesan lotes de hasta 500. Las incompletas serán omitidas; nunca se cerrarán excepcionalmente en lote.</p><div id="bc-bulk-progress" class="small text-muted" role="status"></div>',function(){
    var batches=[];for(var n=0;n<ids.length;n+=500)batches.push(ids.slice(n,n+500));
    var totals={closed:0,skipped:0},chain=Promise.resolve();
    batches.forEach(function(batch,i){
      chain=chain.then(function(){
        $id('bc-bulk-progress').text('Procesando lote '+(i+1)+' de '+batches.length+'...');
        return new Promise(function(resolve,reject){
          adminPost('close_bulk',{teacher_course_ids:JSON.stringify(batch)})
            .done(function(r){
              if(Number(r.status)!==1){reject(new Error(r.message||'No se completó el lote.'));return;}
              totals.closed+=num(r.closed_count);totals.skipped+=num(r.skipped_count);resolve();
            }).fail(function(xhr){reject(new Error(message(xhr,'Error en cierre masivo')));});
        });
      });
    });
    return chain.then(function(){
      $id('bc-action-modal').modal('hide');
      toast(totals.closed+' cerradas; '+totals.skipped+' omitidas.',totals.skipped?'warning':'success');
      reload();
    }).catch(function(err){
      toast(err.message+' · Completadas antes del error: '+totals.closed,'danger');
      reload();
    });
  },'success');
}
function institutionAction(mode){
  if(!data.institution||!editable())return;
  var reopen=mode==='reopen',exceptional=mode==='Excepcional',title=reopen?'Reabrir bimestre institucional':exceptional?'Cierre institucional excepcional':'Confirmar cierre institucional';
  var body='<p class="small">'+(reopen?'Este cambio levanta el bloqueo institucional pero no reabre automáticamente los cierres de cada curso.':
    'Se bloquearán las evaluaciones y las calificaciones del bimestre. Se guardarán la fecha, el responsable y la evidencia del cierre.')+'</p>';
  if(reopen||exceptional)body+='<div class="form-group"><label for="bc-reason">Motivo obligatorio</label><textarea class="form-control" id="bc-reason" rows="3" maxlength="500"></textarea></div>';
  if(exceptional)body+='<label class="small"><input type="checkbox" id="bc-confirm-special"> Autorizo el cierre institucional con pendientes.</label>';
  if(!reopen)body+='<div class="form-group mt-3"><label for="bc-word">Escribe CERRAR para confirmar</label><input class="form-control" autocomplete="off" id="bc-word"></div>';
  modal(title,body,function(){
    var reason=String($id('bc-reason').val()||'').trim(),word=String($id('bc-word').val()||'').trim();
    if((reopen||exceptional)&&reason.length<5){toast('Indica un motivo de al menos 5 caracteres.','warning');return;}
    if(exceptional&&!$id('bc-confirm-special').is(':checked')){toast('Confirma expresamente el cierre excepcional.','warning');return;}
    if(!reopen&&word.toUpperCase()!=='CERRAR'){toast('Escribe CERRAR para confirmar.','warning');return;}
    var payload=reopen?{reason:reason}:{mode:mode,reason:reason,confirmation:word,exception_confirmed:exceptional?1:0};
    return instPost(reopen?'reopen':'close',payload).then(function(r){
      if(Number(r.status)===1){$id('bc-action-modal').modal('hide');toast(r.message);reload();}
      else toast(r.message||'No se pudo actualizar el cierre.','warning');
    },function(xhr){toast(message(xhr,'No se pudo completar la operación'),'danger');});
  },reopen?'warning':exceptional?'danger':'primary');
}
function viewHistory(){
  var inst=data.institution;if(!inst)return;
  var records=inst.history||[],html=records.length?'<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Versión</th><th>Estado</th><th>Tipo</th><th>Responsable</th><th>Fecha</th></tr></thead><tbody>':'<div class="text-muted">Sin cierres institucionales históricos.</div>';
  records.forEach(function(r){html+='<tr><td>#'+esc(r.version)+'</td><td>'+esc(r.status)+'</td><td>'+esc(r.closure_type)+'</td><td>'+esc(r.closed_by_name)+'</td><td>'+date(r.closed_at)+'</td></tr>';});
  if(records.length)html+='</tbody></table></div>';
  modal('Historial institucional',html,function(){$id('bc-action-modal').modal('hide');});
  $id('bc-action-confirm').text('Cerrar historial');
}
function reportCsv(){
  var rows=[['REPORTE DE CUMPLIMIENTO DOCENTE'],['Año',year()?year().year:'','Bimestre',bimester()],[],
    ['Docente','Asignaciones','Cerradas','Pendientes','Cumplimiento','Estado','Cursos pendientes']];
  teacherRows().forEach(function(r){rows.push([r.source.teacher_name,r.total,r.closed,r.remaining,r.pct+'%',r.state,(r.source.pending_courses||[]).join(' | ')]);});
  rows.push([],['DETALLE DE ASIGNACIONES'],['Docente','Curso','Nivel','Grado','Seccion','Avance notas','Estado','Fecha cierre','Responsable','Tipo cierre']);
  data.items.forEach(function(x){var m=data.meta[String(x.teacher_course_id)]||{};rows.push([x.teacher_name,x.course_name,x.level,x.grado,x.seccion,num(x.grade_progress).toFixed(1)+'%',x.state,m.closed_at||x.closed_at||'',m.closed_by_name||x.closed_by_name||'',m.mode||'']);});
  var q=function(v){var t=String(v==null?'':v);if(/^[\t\r\n ]*[=+@-]/.test(t))t="'"+t;return '"'+t.replace(/"/g,'""')+'"';};
  var csv='\ufeff'+rows.map(function(row){return row.map(q).join(';');}).join('\r\n');
  var blob=new Blob([csv],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');
  a.href=url;a.download='edusync_cumplimiento_'+(year()?year().year:'')+'_bim'+bimester()+'.csv';document.body.appendChild(a);a.click();a.remove();
  setTimeout(function(){URL.revokeObjectURL(url);},5000);
}
function reportPrint(){
  var w=window.open('','_blank');
  if(!w){toast('Permite abrir ventanas emergentes para imprimir el reporte.','warning');return;}
  var rows=teacherRows().map(function(r){return '<tr><td>'+esc(r.source.teacher_name)+'</td><td>'+r.total+'</td><td>'+r.closed+'</td><td>'+r.remaining+'</td><td>'+r.pct+'%</td><td>'+esc(r.state)+'</td></tr>';}).join('');
  var detail=data.items.map(function(x){var m=data.meta[String(x.teacher_course_id)]||{};return '<tr><td>'+esc(x.teacher_name)+'</td><td>'+esc(x.course_name)+'</td><td>'+aula(x)+'</td><td>'+esc(x.state)+'</td><td>'+date(m.closed_at||x.closed_at)+'</td></tr>';}).join('');
  var html='<!doctype html><html lang="es"><head><meta charset="utf-8"><title>EduSync · Cumplimiento docente</title><style>body{font:12px Arial,sans-serif;color:#283348;padding:25px}h1{font-size:21px;margin-bottom:4px}h2{font-size:15px;margin-top:25px}.small{color:#6b7280}table{width:100%;border-collapse:collapse;margin:12px 0}td,th{border:1px solid #ced5e0;padding:6px;text-align:left}th{background:#edf2fb}@page{size:A4 landscape;margin:12mm}</style></head><body>'+
    '<h1>EduSync · Reporte de cumplimiento docente</h1><p class="small">Año '+esc(year()?year().year:'')+' · '+bimester()+'° Bimestre · Emitido '+esc(new Date().toLocaleString('es-PE'))+'</p>'+
    '<h2>Resumen por docente</h2><table><thead><tr><th>Docente</th><th>Asignaciones</th><th>Cerradas</th><th>Pendientes</th><th>Cumplimiento</th><th>Estado</th></tr></thead><tbody>'+rows+'</tbody></table>'+
    '<h2>Detalle por curso</h2><table><thead><tr><th>Docente</th><th>Curso</th><th>Aula</th><th>Estado</th><th>Fecha de cierre</th></tr></thead><tbody>'+detail+'</tbody></table>'+
    '<p class="small">El avance de notas no equivale al cierre efectivo. La fecha de avisos no representa un plazo oficial de cumplimiento.</p></body></html>';
  w.document.open();w.document.write(html);w.document.close();w.focus();w.print();
}
$(function(){
  if(cfg.yearId)$id('bc-year').val(String(cfg.yearId));
  $id('bc-bim').val(String(cfg.bimester||1));
  $id('bc-year,#bc-bim').on('change',reload);
  $id('bc-reload').on('click',reload);
  $id('bc-level,#bc-teacher,#bc-state').on('change',renderCourses);
  $id('bc-clear-filters').on('click',function(){$id('bc-level,#bc-teacher,#bc-state').val('');selected.clear();renderCourses();});
  $id('bc-course-table').on('change','.bc-check',function(){var id=Number(this.value);if(this.checked)selected.add(id);else selected.delete(id);updateSelection();});
  $id('bc-check-all').on('change',function(){var enabled=this.checked;$('#bc-course-table tbody .bc-check:visible:not(:disabled)').each(function(){this.checked=enabled;if(enabled)selected.add(Number(this.value));else selected.delete(Number(this.value));});updateSelection();});
  $id('bc-course-table').on('click','.bc-view-course',function(){var x=findCourse($(this).data('id'));if(x)viewCourse(x);});
  $id('bc-course-table').on('click','.bc-close-one',function(){var x=findCourse($(this).data('id'));if(x)closeOne(x,false);});
  $id('bc-close-selected').on('click',function(){bulk(Array.from(selected).filter(function(id){var x=findCourse(id);return x&&x.ready&&!x.closed;}));});
  $id('bc-close-ready').on('click',function(){bulk(data.items.filter(function(x){return x.ready&&!x.closed;}).map(function(x){return Number(x.teacher_course_id);}).slice(0,500));});
  $id('bc-action-form').on('submit',function(e){e.preventDefault();if(!action)return;var button=$id('bc-action-confirm').prop('disabled',true),request;
    try{request=action();}catch(err){toast(err.message,'danger');button.prop('disabled',false);return;}
    if(request&&typeof request.always==='function')request.always(function(){button.prop('disabled',false);});
    else if(request&&typeof request.finally==='function')request.finally(function(){button.prop('disabled',false);});
    else button.prop('disabled',false);
  });
  $id('bc-action-modal').on('hidden.bs.modal',function(){action=null;});
  $id('bc-root').on('click','.bc-go-courses',function(){linkTab('courses');});
  $id('bc-root').on('click','.bc-go-teachers',function(){linkTab('teachers');});
  $id('bc-root').on('click','.bc-go-institution',function(){linkTab('institution');});
  $id('bc-teacher-table').on('click','.bc-teacher-courses',function(){var name=$(this).data('teacher');$id('bc-teacher').val(name);$id('bc-state').val('');renderCourses();linkTab('courses');});
  $id('bc-teacher-table').on('click','.bc-send-reminder',function(){var id=Number($(this).data('user')),t=reportTeachers().find(function(t){return Number(t.recipient_user_id)===id;});if(!t||!canEdit())return;
    modal('Avisar a docente','<p><strong>'+esc(t.teacher_name)+'</strong> · '+num(t.pending)+' curso(s) pendientes</p><label for="bc-reminder-note">Observación opcional</label><textarea id="bc-reminder-note" class="form-control" rows="3" maxlength="250"></textarea><p class="small text-muted mt-2">Máximo un aviso manual por docente cada 24 horas. Registrar un aviso no significa que haya sido leído.</p>',function(){
      return followPost('send_reminder',{recipient_user_id:id,note:$id('bc-reminder-note').val()}).then(function(r){if(Number(r.status)===1){$id('bc-action-modal').modal('hide');toast(r.message);reload();}else toast(r.message||'No se pudo enviar el aviso.','warning');},function(xhr){toast(message(xhr,'Error al registrar el aviso'),'danger');});
    });
  });
  $id('bc-avisos-settings').on('click',function(){var state=data.teacherStatus;if(!state)return;var s=state.settings||{};
    $id('bc-av-date').val(s.deadline_date||'');$id('bc-av-days').val(String(s.notice_days_before||3));
    $id('bc-av-closure').prop('checked',num(s.closure_enabled)===1);$id('bc-av-missing').prop('checked',num(s.incomplete_enabled)===1);$id('bc-av-edits').prop('checked',num(s.admin_edits_enabled)===1);
    $id('bc-avisos-form :input').prop('disabled',!editable()||state.alerts_ready===false);
    $id('bc-avisos-modal').modal('show');
  });
  $id('bc-avisos-form').on('submit',function(e){e.preventDefault();if(!editable())return;var button=$(this).find('[type=submit]').prop('disabled',true);
    followPost('save_settings',{deadline_date:$id('bc-av-date').val(),notice_days_before:$id('bc-av-days').val(),
      closure_enabled:$id('bc-av-closure').is(':checked')?'1':'0',incomplete_enabled:$id('bc-av-missing').is(':checked')?'1':'0',admin_edits_enabled:$id('bc-av-edits').is(':checked')?'1':'0'
    }).done(function(r){if(Number(r.status)===1){$id('bc-avisos-modal').modal('hide');toast(r.message);reload();}else toast(r.message||'No se pudo guardar la configuración','danger');})
      .fail(function(xhr){toast(message(xhr,'Error al guardar configuración'),'danger');}).always(function(){button.prop('disabled',false);});
  });
  $id('bc-avisos-history').on('click',function(){
    $id('bc-history-body').html('<div class="text-center text-muted">Consultando historial...</div>');$id('bc-history-modal').modal('show');
    followGet('history').done(function(r){var rows=r.history||[];if(Number(r.status)!==1){$id('bc-history-body').text(r.message||'No disponible');return;}
      if(!rows.length){$id('bc-history-body').html('<div class="text-muted">No existen avisos para este bimestre.</div>');return;}
      var html='<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Fecha</th><th>Aviso</th><th>Entrega</th></tr></thead><tbody>';
      rows.forEach(function(x){html+='<tr><td>'+date(x.created_at)+'</td><td><strong>'+esc(x.title)+'</strong><div class="small text-muted">'+esc(x.message)+'</div></td><td class="small">'+esc(x.delivery_status||'No confirmada')+'</td></tr>';});
      $id('bc-history-body').html(html+'</tbody></table></div>');
    }).fail(function(xhr){$id('bc-history-body').text(message(xhr,'No se pudo consultar el historial.'));});
  });
  $id('bc-root').on('click','.bc-inst-normal',function(){institutionAction('Normal');});
  $id('bc-root').on('click','.bc-inst-exceptional',function(){institutionAction('Excepcional');});
  $id('bc-root').on('click','.bc-inst-reopen',function(){institutionAction('reopen');});
  $id('bc-root').on('click','.bc-view-inst-history',viewHistory);
  $id('bc-report-csv').on('click',reportCsv);
  $id('bc-report-print').on('click',reportPrint);
  var initial=String(cfg.defaultTab||'overview');if(['overview','teachers','courses','reports','institution'].includes(initial))linkTab(initial);
  reload();
});
})(window.jQuery);
