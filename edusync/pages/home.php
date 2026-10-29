<div class="hd"><div id="hd-error" class="alert alert-danger d-none"></div><div id="hd-loading" class="hd-loading"><div class="spinner-border text-primary mb-2"></div><div>Preparando tu panel...</div></div><main id="hd-content" class="d-none">
 <section class="hd-box hd-hero mb-3"><div class="hd-school"><div id="hd-logo"></div><div><h1 id="hd-name" class="hd-title"></h1><div id="hd-welcome" class="hd-muted"></div></div></div><div class="hd-year"><small class="text-muted">Año académico</small><strong id="hd-year"></strong><small id="hd-updated" class="text-muted"></small></div></section>
 <div id="hd-metrics" class="row"></div>
 <section id="hd-tools" class="hd-box"><div class="hd-head"><h5 id="hd-tools-title"><i class="fas fa-bolt text-primary mr-2"></i>Acciones rápidas</h5></div><div id="hd-actions" class="p-3 hd-actions"></div></section>
 <div id="hd-overview" class="row"><div id="hd-pending-section" class="col-xl-6 mb-3"><section class="hd-box h-100"><div class="hd-head"><h5><i class="fas fa-tasks text-primary mr-2"></i>Pendientes y alertas</h5><a class="small" href="index.php?page=notifications">Notificaciones</a></div><div id="hd-pending" class="hd-list"></div></section></div><div id="hd-activity-section" class="col-xl-6 mb-3"><section class="hd-box h-100"><div class="hd-head"><h5><i class="fas fa-history text-primary mr-2"></i>Actividad reciente</h5></div><div id="hd-activity" class="hd-list"></div></section></div></div>
 <section id="hd-analysis" class="hd-box mb-3 d-none"><div class="hd-head"><h5><i class="fas fa-chart-pie text-primary mr-2"></i>Análisis institucional</h5><small class="text-muted">Solo administración y dirección</small></div><ul class="nav hd-tabs px-3 pt-2"><li><a class="nav-link active" data-toggle="tab" href="#hda-m">Matrícula</a></li><li><a class="nav-link" data-toggle="tab" href="#hda-d">Docentes</a></li><li><a class="nav-link" data-toggle="tab" href="#hda-f">Finanzas</a></li><li><a class="nav-link" data-toggle="tab" href="#hda-a">Asistencia</a></li><li><a class="nav-link" data-toggle="tab" href="#hda-c">Académico</a></li></ul><div class="tab-content hd-analysis"><div id="hda-m" class="tab-pane fade show active"></div><div id="hda-d" class="tab-pane fade"></div><div id="hda-f" class="tab-pane fade"></div><div id="hda-a" class="tab-pane fade"></div><div id="hda-c" class="tab-pane fade"></div></div></section>
 
</main></div>
<script>
(function($){
 const e=v=>$('<div>').text(v==null?'':v).html(), money=v=>'S/ '+Number(v||0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2}), tone=t=>['primary','success','warning','danger'].includes(t)?t:'primary';
 const empty=t=>'<div class="hd-empty"><i class="fas fa-check-circle text-success mr-1"></i>'+e(t)+'</div>';
 function bars(rows,labelKey='label',valueKey='value',moneyValues=false,limit=20){rows=(rows||[]).slice(0,limit);let max=Math.max(...rows.map(r=>Number(r[valueKey]||0)),1),h='<div class="hd-chart">';rows.forEach(r=>h+='<div class="hd-bar"><span>'+e(r[labelKey]||'—')+'</span><div class="hd-track"><div class="hd-fill" style="width:'+Math.max(1,Number(r[valueKey]||0)/max*100)+'%"></div></div><strong class="text-right">'+(moneyValues?money(r[valueKey]):e(r[valueKey]))+'</strong></div>');return h+'</div>'}
 function table(headers,rows){let h='<div class="table-responsive hd-table-wrap"><table class="table table-sm table-hover mb-0"><thead class="thead-light"><tr>';headers.forEach(x=>h+='<th'+(x.right?' class="text-right"':'')+'>'+e(x.label)+'</th>');h+='</tr></thead><tbody>';rows.forEach(r=>{h+='<tr>';headers.forEach(x=>h+='<td'+(x.right?' class="text-right font-weight-bold"':'')+'>'+e(r[x.key]??'—')+'</td>');h+='</tr>'});return h+'</tbody></table></div>'}
 function renderAnalysis(a,charts){if(!a||!a.enrollment)return;$('#hd-analysis').removeClass('d-none');let lv=a.enrollment.levels||[],cl=a.enrollment.classrooms||[];$('#hda-m').html('<div class="row"><div class="col-lg-5 mb-3"><div class="hd-chart-title">Estudiantes activos por nivel</div>'+bars(lv)+'</div><div class="col-lg-7 mb-3"><div class="hd-chart-title">Detalle por grado y sección</div>'+table([{label:'Nivel',key:'nivel'},{label:'Grado',key:'grado'},{label:'Sección',key:'seccion'},{label:'Estudiantes',key:'students',right:true}],cl)+'</div></div>');
 let loads=a.teachers.loads||[];$('#hda-d').html('<div class="row"><div class="col-lg-5 mb-3"><div class="row mb-2">'+(a.teachers.summary||[]).map(v=>'<div class="col-6"><div class="hd-mini"><small>'+e(v.label)+'</small><strong>'+e(v.value)+'</strong></div></div>').join('')+'</div><div class="hd-chart-title">Carga académica por docente</div>'+bars(loads,'name','assignments',false,12)+'</div><div class="col-lg-7 mb-3"><div class="hd-chart-title">Detalle de asignaciones</div>'+table([{label:'Docente',key:'name'},{label:'Asignaciones',key:'assignments',right:true}],loads)+'</div></div>');
 let f=a.finance||{},methods=f.methods||[],financeTrend=(charts&&Array.isArray(charts.payments))?charts.payments:[];$('#hda-f').html('<div class="row mb-3"><div class="col-md-4 mb-2"><div class="hd-mini"><small>Recaudado este mes</small><strong>'+money(f.month)+'</strong></div></div><div class="col-md-4 mb-2"><div class="hd-mini"><small>Saldo pendiente</small><strong>'+money(f.pending)+'</strong></div></div><div class="col-md-4 mb-2"><div class="hd-mini"><small>Saldo vencido</small><strong>'+money(f.overdue)+'</strong></div></div></div><div class="hd-chart-title">Recaudación del mes por medio de pago</div>'+bars(methods,'label','value',true)+(financeTrend.length?'<div class="mt-3"><div class="hd-chart-title">Recaudación · últimos seis meses</div>'+bars(financeTrend,'label','value',true,6)+'</div>':''));
 let at=a.attendance||[],totalMax=Math.max(...at.map(v=>Number(v.present||0)+Number(v.late||0)+Number(v.absent||0)),1),ah='<div class="hd-legend mb-3"><span><i class="hd-dot bg-success"></i>Presentes</span><span><i class="hd-dot bg-warning"></i>Tardanzas</span><span><i class="hd-dot bg-danger"></i>Ausencias</span></div>';at.forEach(v=>{let p=Number(v.present||0),l=Number(v.late||0),ab=Number(v.absent||0);ah+='<div class="hd-bar"><span>'+e(v.label)+'</span><div class="hd-stack"><span class="bg-success" style="width:'+p/totalMax*100+'%"></span><span class="bg-warning" style="width:'+l/totalMax*100+'%"></span><span class="bg-danger" style="width:'+ab/totalMax*100+'%"></span></div><strong class="text-right">'+(p+l+ab)+'</strong></div>'});$('#hda-a').html('<div class="row"><div class="col-lg-7"><div class="hd-chart-title">Tendencia de los últimos siete días</div><div class="hd-chart">'+ah+'</div></div><div class="col-lg-5">'+table([{label:'Fecha',key:'label'},{label:'P',key:'present',right:true},{label:'T',key:'late',right:true},{label:'A',key:'absent',right:true}],at)+'</div></div>');
 let ac=a.academic||{};$('#hda-c').html('<div class="row"><div class="col-md-4 mb-2"><div class="hd-mini"><small>Asignaciones académicas</small><strong>'+e(ac.courses)+'</strong></div></div><div class="col-md-4 mb-2"><div class="hd-mini"><small>Evaluaciones del año activo</small><strong>'+e(ac.evaluations)+'</strong></div></div><div class="col-md-4 mb-2"><div class="hd-mini"><small>Alertas académicas</small><strong>'+e(ac.alerts)+'</strong></div></div></div>')}

 const roleConfig={
  Administrador:{css:'hd-admin',title:'Panel de control',icon:'fa-chart-line',subtitle:'Resumen institucional',tools:'Acciones rápidas'},
  Director:{css:'hd-director',title:'Panel de dirección',icon:'fa-user-tie',subtitle:'Gestión académica y herramientas asignadas',tools:'Herramientas de dirección'},
  Docente:{css:'hd-docente',title:'Mi espacio académico',icon:'fa-chalkboard-teacher',subtitle:'Cursos, evaluaciones y seguimiento',tools:'Mis herramientas'},
  Auxiliar:{css:'hd-auxiliar',title:'Control de asistencia',icon:'fa-clipboard-check',subtitle:'Resumen de asistencia del día',tools:'Accesos de asistencia'},
  Estudiante:{css:'hd-estudiante',title:'Mi información',icon:'fa-user-graduate',subtitle:'Notas, asistencia y pagos',tools:'Mis accesos'}
 };
 const description={
  Director:{'Mis cursos':'Cursos y secciones a cargo','Registrar notas':'Registra calificaciones','Competencias':'Competencias asignadas','Reporte de notas':'Consulta y exporta notas'},
  Docente:{'Mis cursos':'Cursos y secciones asignados','Registrar notas':'Evaluaciones y calificaciones','Competencias':'Competencias de tus cursos','Reporte de notas':'Resultados académicos'},
  Auxiliar:{'Tomar asistencia':'Registra la asistencia del día','Reglas':'Consulta horarios y reglas','Reporte':'Presentes, tardanzas y ausencias','Notificaciones':'Solicitudes y resultados'},
  Estudiante:{'Mis notas':'Calificaciones por curso','Mi asistencia':'Consulta tu asistencia','Mis pagos':'Pagos realizados','Mis deudas':'Saldos y vencimientos'}
 };
 function render(d){
  const role=d.user&&d.user.role||'',cfg=roleConfig[role]||roleConfig.Estudiante;
  const admin=role==='Administrador',director=role==='Director',aux=role==='Auxiliar',student=role==='Estudiante';
  const metrics=Array.isArray(d.metrics)?d.metrics:[],pendings=Array.isArray(d.pending)?d.pending:[],activity=Array.isArray(d.activity)?d.activity:[],links=Array.isArray(d.actions)?d.actions:[];
  $('.hd').removeClass('hd-admin hd-director hd-docente hd-auxiliar hd-estudiante').addClass('hd-role '+cfg.css);
  $('#hd-logo').html('<span class="hd-dashboard-icon" aria-hidden="true"><i class="fas '+e(cfg.icon)+'"></i></span>');
  $('#hd-name').text(cfg.title);
  $('#hd-welcome').text('Hola, '+(d.user&&d.user.name||'Usuario')+' · '+cfg.subtitle);
  $('#hd-year').text('Última actualización');
  $('#hd-updated').text(d.updated_at||'—');
  $('#hd-year').closest('.hd-year').addClass('hd-updated-box');
  let cards='',headers={0:'Comunidad educativa',2:'Finanzas',6:'Asistencia'};
  metrics.forEach((m,i)=>{
    if(admin&&Object.prototype.hasOwnProperty.call(headers,i))cards+='<div class="col-12 hd-metric-section">'+e(headers[i])+'</div>';
    const cols=admin?((i<2||i>=6)?'col-xl-6 col-md-6 col-6':'col-xl-3 col-md-6 col-6')
      :aux?'col-xl col-lg-4 col-md-6 col-6'
      :student?'col-xl-3 col-lg-6 col-md-6 col-6'
      :'col-xl-4 col-lg-4 col-md-6 col-6';
    const numeric=Number(String(m.value==null?'':m.value).replace(/[^0-9.-]/g,'')||0);
    const zeroDebt=student&&i<2&&numeric===0;
    cards+='<div class="'+cols+' mb-3"><a class="hd-card'+(zeroDebt?' hd-neutral':'')+'" href="'+e(m.url)+'"><div class="hd-card-head"><span class="hd-label">'+e(m.label)+'</span><span class="hd-icon hd-'+(zeroDebt?'primary':tone(m.tone))+'"><i class="fas '+e(m.icon)+'"></i></span></div><div class="hd-value">'+e(m.value)+'</div></a></div>';
  });
  $('#hd-metrics').html(cards).toggleClass('d-none',director||!metrics.length);
  let p='';pendings.forEach(v=>p+='<a class="hd-item text-decoration-none" href="'+e(v.u)+'"><span class="hd-list-icon hd-'+tone(v.tone)+'"><i class="fas fa-exclamation"></i></span><span class="hd-main"><span class="hd-item-title d-block">'+e(v.t)+'</span><span class="hd-muted">'+e(v.d)+'</span></span><span class="hd-count text-'+tone(v.tone)+'">'+e(v.c)+'</span></a>');
  $('#hd-pending').html(p||empty('No hay pendientes importantes.'));
  let act='';activity.forEach(v=>act+='<div class="hd-item"><span class="hd-list-icon hd-primary"><i class="fas '+(v.kind==='payment'?'fa-receipt':'fa-user-check')+'"></i></span><span class="hd-main"><span class="hd-item-title d-block">'+e(v.title)+'</span><span class="hd-muted">'+e(v.detail)+' · '+e(v.event_date)+'</span></span></div>');
  $('#hd-activity').html(act||empty('Aún no hay actividad reciente.'));
  // En sesiones no administrativas las alertas ya están enlazadas desde sus métricas.
  $('#hd-overview,#hd-pending-section,#hd-activity-section').toggleClass('d-none',!admin);
  let actions='',desc=description[role]||{};
  links.forEach((v,i)=>{
    if(admin)actions+='<a class="hd-action" href="'+e(v.url)+'"><i class="fas '+e(v.icon)+' mr-1 text-primary"></i>'+e(v.label)+'</a>';
    else actions+='<a class="hd-action'+(aux&&i===0?' hd-action-primary':'')+'" href="'+e(v.url)+'"><span class="hd-tool-icon"><i class="fas '+e(v.icon)+'"></i></span><span class="hd-tool-text"><strong>'+e(v.label)+'</strong><small>'+e(desc[v.label]||'Abrir herramienta')+'</small></span><i class="fas fa-chevron-right hd-tool-arrow" aria-hidden="true"></i></a>';
  });
  $('#hd-actions').html(actions||'<div class="hd-empty hd-no-tools">No hay herramientas asignadas a esta sesión.</div>');
  $('#hd-tools').toggleClass('hd-profile-tools',!admin);
  $('#hd-tools-title').html('<i class="fas '+(admin?'fa-bolt':'fa-th-large')+' text-primary mr-2"></i>'+e(cfg.tools));
  // Orden compartido: encabezado, indicadores, herramientas.
  // Admin mantiene además pendientes/actividad antes de las acciones.
  if(admin)$('#hd-tools').insertBefore('#hd-analysis');
  $('#hd-analysis').addClass('d-none');
  if(admin)renderAnalysis(d.analysis,d.charts);
  $('#hd-loading').addClass('d-none');
  $('#hd-content').removeClass('d-none');
 }
 $.ajax({url:'home_dashboard_api.php',data:{action:'dashboard',_t:Date.now()},dataType:'json',cache:false}).done(r=>{if(r&&Number(r.status)===1)render(r);else{$('#hd-loading').addClass('d-none');$('#hd-error').removeClass('d-none').text((r&&r.message)||'No se pudo cargar el panel.')}}).fail(x=>{let m='No se pudo cargar el panel de inicio.';try{m=JSON.parse(x.responseText).message||m}catch(z){}$('#hd-loading').addClass('d-none');$('#hd-error').removeClass('d-none').text(m)})
})(jQuery);
</script>
