<?php
include_once __DIR__ . '/../db_connect.php';
$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$ready = false;
$q = $conn->query("SHOW TABLES LIKE 'student_announcements'");
$ready = $q && $q->num_rows > 0;
?>
<style>
.ca-head{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.65rem;padding:1rem 1.2rem}
.ca-card{background:#fff;border:1px solid #e3e6f0;border-radius:.65rem;box-shadow:0 .12rem .4rem rgba(58,59,69,.05)}
.ca-card .card-header{background:#fff;border-bottom:1px solid #edf0f5;font-weight:800;color:#263754}
.ca-help{font-size:.78rem;color:#6e7891}
.ca-counter{font-size:.72rem;color:#858796}
.ca-history-title{font-weight:800;color:#263754}
.ca-history-message{font-size:.78rem;color:#6e7891;max-width:580px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ca-stat{font-size:.74rem}
.ca-recipient-box{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.5rem;padding:.75rem}
@media(max-width:767px){.ca-actions .btn{width:100%}}
</style>

<div class="container-fluid py-3">
  <div class="ca-head d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-1 text-gray-800"><i class="fas fa-bullhorn text-primary mr-2"></i>Comunicados institucionales</h1>
      <div class="small text-muted">Envía avisos oficiales a estudiantes desde EduSync.</div>
    </div>
    <a href="index.php?page=notifications" class="btn btn-light border btn-sm mt-2 mt-md-0"><i class="fas fa-bell mr-1"></i>Centro de notificaciones</a>
  </div>

  <?php if (!$ready): ?>
    <div class="alert alert-warning">
      <i class="fas fa-database mr-2"></i>
      Ejecuta manualmente <strong>sql/student_announcements.sql</strong> antes de usar este módulo.
    </div>
  <?php endif; ?>

  <div id="ca-alert" class="alert d-none"></div>

  <div class="row">
    <div class="col-xl-5 mb-3">
      <div class="card ca-card">
        <div class="card-header"><i class="fas fa-paper-plane text-primary mr-2"></i>Nuevo comunicado</div>
        <div class="card-body">
          <form id="ca-form">
            <div class="form-group">
              <label class="small font-weight-bold">Título</label>
              <input id="ca-title" name="title" class="form-control" maxlength="180" placeholder="Ej.: Reunión de padres" required>
              <div class="text-right ca-counter"><span id="ca-title-count">0</span>/180</div>
            </div>

            <div class="form-group">
              <label class="small font-weight-bold">Comunicado</label>
              <textarea id="ca-content" name="content" class="form-control" rows="7" maxlength="5000" placeholder="Escribe el comunicado completo..." required></textarea>
              <div class="text-right ca-counter"><span id="ca-content-count">0</span>/5000</div>
            </div>

            <div class="ca-recipient-box mb-3">
              <div class="font-weight-bold small mb-2"><i class="fas fa-users mr-1"></i>Destinatarios</div>
              <div class="form-group mb-2">
                <select id="ca-audience" name="audience_type" class="form-control form-control-sm">
                  <option value="all">Todo el colegio</option>
                  <option value="level">Un nivel</option>
                  <option value="grade">Un grado</option>
                  <option value="section">Una sección</option>
                  <option value="student">Un estudiante</option>
                </select>
              </div>

              <div id="ca-level-wrap" class="form-group mb-2 d-none">
                <label class="small font-weight-bold">Nivel</label>
                <select id="ca-level" name="level" class="form-control form-control-sm"><option value="">Selecciona</option></select>
              </div>

              <div id="ca-grade-wrap" class="form-group mb-2 d-none">
                <label class="small font-weight-bold">Grado</label>
                <select id="ca-grade" name="grade" class="form-control form-control-sm"><option value="">Selecciona</option></select>
              </div>

              <div id="ca-section-wrap" class="form-group mb-2 d-none">
                <label class="small font-weight-bold">Sección</label>
                <select id="ca-section" name="section" class="form-control form-control-sm"><option value="">Selecciona</option></select>
              </div>

              <div id="ca-student-wrap" class="form-group mb-0 d-none">
                <label class="small font-weight-bold">Estudiante</label>
                <select id="ca-student" name="student_id" class="form-control form-control-sm"><option value="">Selecciona</option></select>
              </div>

              <div id="ca-audience-preview" class="ca-help mt-2">Se enviará a todos los estudiantes activos del colegio.</div>
            </div>

            <div class="ca-help mb-3">
              <i class="fas fa-info-circle mr-1"></i>
              El comunicado se envía inmediatamente, queda en el Centro de Notificaciones de cada estudiante y puede abrirse completo desde la app.
            </div>

            <div class="ca-actions">
              <button id="ca-send" type="submit" class="btn btn-primary btn-block" <?php echo $ready ? '' : 'disabled'; ?>>
                <i class="fas fa-paper-plane mr-1"></i>Enviar comunicado
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-xl-7 mb-3">
      <div class="card ca-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="fas fa-history text-primary mr-2"></i>Historial de comunicados</span>
          <button id="ca-refresh" class="btn btn-light border btn-sm"><i class="fas fa-sync-alt"></i></button>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="thead-light">
                <tr>
                  <th>Comunicado</th>
                  <th>Destinatarios</th>
                  <th>Entrega</th>
                  <th>Fecha</th>
                </tr>
              </thead>
              <tbody id="ca-history">
                <tr><td colspan="4" class="text-center text-muted py-4">Cargando...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function($){
  const api='announcements_api.php';
  const csrf=<?php echo json_encode($csrf); ?>;
  const ready=<?php echo $ready ? 'true' : 'false'; ?>;
  let options={levels:[],grades:[],sections:[],students:[]};

  function esc(v){return $('<div>').text(v==null?'':v).html();}
  function alertBox(message,type){
    $('#ca-alert').removeClass('d-none alert-success alert-danger alert-warning alert-info')
      .addClass('alert-'+(type||'info')).html(message);
  }
  function hideAlert(){$('#ca-alert').addClass('d-none');}

  function fillSelect(selector,items,valueKey,labelFn){
    let html='<option value="">Selecciona</option>';
    (items||[]).forEach(item=>{
      const value=typeof item==='string'?item:item[valueKey];
      const label=typeof item==='string'?item:labelFn(item);
      html+='<option value="'+esc(value)+'">'+esc(label)+'</option>';
    });
    $(selector).html(html);
  }

  function loadOptions(){
    if(!ready)return;
    $.getJSON(api,{action:'options'}).done(r=>{
      if(!r||Number(r.status)!==1){alertBox((r&&r.message)||'No se pudieron cargar los destinatarios.','danger');return;}
      options=r;
      fillSelect('#ca-level',options.levels||[],'',x=>x);
      fillSelect('#ca-student',options.students||[],'id',s=>s.name+' · '+s.level+' '+s.grade+' '+s.section+(s.dni?' · '+s.dni:''));
      updateAudience();
    }).fail(x=>alertBox((x.responseJSON||{}).message||'No se pudieron cargar los destinatarios.','danger'));
  }

  function refreshGrades(){
    const level=$('#ca-level').val();
    const rows=(options.grades||[]).filter(r=>r.level===level);
    fillSelect('#ca-grade',rows,'grade',r=>r.grade);
  }

  function refreshSections(){
    const level=$('#ca-level').val(),grade=$('#ca-grade').val();
    const rows=(options.sections||[]).filter(r=>r.level===level&&String(r.grade)===String(grade));
    fillSelect('#ca-section',rows,'section',r=>r.section);
  }

  function updateAudience(){
    const type=$('#ca-audience').val();
    $('#ca-level-wrap').toggleClass('d-none',!['level','grade','section'].includes(type));
    $('#ca-grade-wrap').toggleClass('d-none',!['grade','section'].includes(type));
    $('#ca-section-wrap').toggleClass('d-none',type!=='section');
    $('#ca-student-wrap').toggleClass('d-none',type!=='student');

    let text='Se enviará a todos los estudiantes activos del colegio.';
    if(type==='level')text='Se enviará a todos los estudiantes activos del nivel seleccionado.';
    if(type==='grade')text='Se enviará a todos los estudiantes activos del nivel y grado seleccionados.';
    if(type==='section')text='Se enviará solo a los estudiantes activos de la sección seleccionada.';
    if(type==='student')text='Se enviará únicamente al estudiante seleccionado.';
    $('#ca-audience-preview').text(text);
  }

  function dateText(v){
    const m=String(v||'').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if(!m)return esc(v||'—');
    return m[3]+'/'+m[2]+'/'+m[1]+' '+m[4]+':'+m[5];
  }

  function loadHistory(){
    if(!ready)return;
    $('#ca-history').html('<tr><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</td></tr>');
    $.getJSON(api,{action:'list'}).done(r=>{
      if(!r||Number(r.status)!==1){$('#ca-history').html('<tr><td colspan="4" class="text-center text-danger py-4">'+esc((r&&r.message)||'No se pudo cargar.')+'</td></tr>');return;}
      const items=r.items||[];
      if(!items.length){$('#ca-history').html('<tr><td colspan="4" class="text-center text-muted py-4">Aún no hay comunicados.</td></tr>');return;}
      let html='';
      items.forEach(item=>{
        html+='<tr>'+
          '<td><div class="ca-history-title">'+esc(item.title)+'</div><div class="ca-history-message">'+esc(item.content)+'</div><div class="small text-muted mt-1">'+esc(item.created_by_name)+'</div></td>'+
          '<td><span class="badge badge-light border">'+esc(item.audience)+'</span><div class="small text-muted mt-1">'+Number(item.recipient_count||0)+' estudiante(s)</div></td>'+
          '<td><div class="ca-stat text-success"><i class="fas fa-check mr-1"></i>'+Number(item.push_sent_count||0)+' push enviados</div><div class="ca-stat text-muted">'+Number(item.push_failed_count||0)+' fallidos</div></td>'+
          '<td class="small text-muted">'+dateText(item.created_at)+'</td>'+
        '</tr>';
      });
      $('#ca-history').html(html);
    }).fail(()=>$('#ca-history').html('<tr><td colspan="4" class="text-center text-danger py-4">No se pudo cargar el historial.</td></tr>'));
  }

  $('#ca-title').on('input',function(){$('#ca-title-count').text(this.value.length);});
  $('#ca-content').on('input',function(){$('#ca-content-count').text(this.value.length);});
  $('#ca-audience').on('change',updateAudience);
  $('#ca-level').on('change',function(){refreshGrades();refreshSections();});
  $('#ca-grade').on('change',refreshSections);
  $('#ca-refresh').on('click',loadHistory);

  $('#ca-form').on('submit',function(e){
    e.preventDefault();
    hideAlert();
    const title=$.trim($('#ca-title').val()),content=$.trim($('#ca-content').val());
    if(title.length<3||content.length<3){alertBox('Completa el título y el comunicado.','warning');return;}

    const type=$('#ca-audience').val();
    const label={
      all:'todo el colegio',
      level:'el nivel seleccionado',
      grade:'el grado seleccionado',
      section:'la sección seleccionada',
      student:'el estudiante seleccionado'
    }[type]||'los destinatarios seleccionados';

    if(!window.confirm('¿Enviar este comunicado ahora a '+label+'?'))return;

    const button=$('#ca-send').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Enviando...');
    const data=$(this).serializeArray();
    data.push({name:'action',value:'send'},{name:'csrf_token',value:csrf});

    $.post(api,data,null,'json').done(r=>{
      if(!r||Number(r.status)!==1){alertBox((r&&r.message)||'No se pudo enviar el comunicado.','danger');return;}
      alertBox(
        '<strong>Comunicado enviado.</strong> '+Number(r.recipients||0)+' estudiante(s) destinatarios · '+Number(r.push_sent||0)+' push enviados.'+
        (Number(r.push_failed||0)>0?' · '+Number(r.push_failed)+' fallidos.':''),
        Number(r.push_failed||0)>0?'warning':'success'
      );
      $('#ca-form')[0].reset();
      $('#ca-title-count,#ca-content-count').text('0');
      refreshGrades();refreshSections();updateAudience();
      loadHistory();
    }).fail(x=>alertBox((x.responseJSON||{}).message||'No se pudo enviar el comunicado.','danger'))
      .always(()=>button.prop('disabled',false).html('<i class="fas fa-paper-plane mr-1"></i>Enviar comunicado'));
  });

  updateAudience();
  if(ready){loadOptions();loadHistory();}
})(jQuery);
</script>
