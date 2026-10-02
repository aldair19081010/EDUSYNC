<?php
include_once __DIR__ . '/../db_connect.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$ready = false;
$q = $conn->query("SHOW TABLES LIKE 'student_announcements'");
$ready = $q && $q->num_rows > 0;
?>
<style>
/* Solo patrones propios de Comunicados que Bootstrap/ed-* no cubren. */
.an-audience-option{cursor:pointer;height:100%;min-height:78px;transition:border-color .15s,background .15s}
.an-audience-option.is-active{border-color:#9cbcf9!important;background:#eef4ff!important}
.an-audience-option.is-active i,.an-audience-option.is-active strong{color:#4285f4!important}
.an-audience-option i{font-size:1.05rem;color:#7a8798}
.an-audience-option strong{font-size:.73rem;color:#526274}
.an-preview-phone{max-width:340px;margin:0 auto;background:#edf0f5;border-radius:22px;padding:10px}
.an-preview-screen{min-height:290px;background:#f8f9fc;border-radius:15px;padding:16px}
.an-preview-push{background:#fff;border:1px solid #edf0f5;border-radius:.6rem;padding:.8rem;box-shadow:0 .125rem .5rem rgba(31,45,61,.06)}
.an-preview-logo{width:26px;height:26px;border-radius:7px;background:#4285f4;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700}
.an-preview-body{font-size:.78rem;color:#6e7891;line-height:1.4}
.an-message-preview{white-space:pre-line}
@media(max-width:767.98px){.an-audience-option{min-height:68px}.an-history-actions{white-space:normal!important}}
</style>

<div class="container-fluid py-3">
    <div class="ed-page-header">
        <div class="ed-page-heading">
            <div class="ed-page-icon"><i class="fas fa-bullhorn"></i></div>
            <div>
                <h1 class="ed-page-title">Comunicados institucionales</h1>
                <p class="ed-page-subtitle">Crea y envía comunicaciones oficiales a estudiantes desde EduSync.</p>
            </div>
        </div>
        <div class="ed-page-actions">
            <a href="index.php?page=collections" class="btn btn-light border btn-sm">
                <i class="fas fa-hand-holding-usd mr-1"></i>Cobranza
            </a>
            <button id="an-new" class="btn btn-primary btn-sm ed-page-action" <?php echo $ready ? '' : 'disabled'; ?>>
                <i class="fas fa-plus mr-1"></i>Nuevo comunicado
            </button>
        </div>
    </div>

    <?php if (!$ready): ?>
        <div class="alert alert-warning">
            <i class="fas fa-database mr-2"></i>
            Ejecuta manualmente <strong>sql/student_announcements.sql</strong>.
        </div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="ed-stat-card">
                <div class="ed-stat-label">Este mes</div>
                <strong class="ed-stat-value" id="an-stat-month">—</strong>
                <div class="ed-stat-meta">Comunicados enviados</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="ed-stat-card">
                <div class="ed-stat-label">Alcance</div>
                <strong class="ed-stat-value" id="an-stat-recipients">—</strong>
                <div class="ed-stat-meta">Estudiantes destinatarios este mes</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-md-0">
            <div class="ed-stat-card">
                <div class="ed-stat-label">Push enviados</div>
                <strong class="ed-stat-value text-success" id="an-stat-sent">—</strong>
                <div class="ed-stat-meta">Entregas registradas este mes</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="ed-stat-card">
                <div class="ed-stat-label">Incidencias</div>
                <strong class="ed-stat-value text-danger" id="an-stat-failed">—</strong>
                <div class="ed-stat-meta">Push fallidos este mes</div>
            </div>
        </div>
    </div>

    <div class="ed-content-card">
        <div class="ed-content-card-header d-flex flex-wrap align-items-center justify-content-between">
            <div>
                <strong><i class="fas fa-history text-primary mr-2"></i>Historial de comunicados</strong>
                <div class="small text-muted mt-1">Consulta contenido, alcance, entrega y lectura.</div>
            </div>
            <div class="ed-toolbar mt-2 mt-md-0">
                <div class="input-group input-group-sm" style="max-width:270px">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                    <input id="an-search" class="form-control" placeholder="Buscar comunicado...">
                </div>
                <button id="an-refresh" class="btn btn-light border btn-sm ed-icon-btn" title="Actualizar">
                    <i class="fas fa-sync-alt"></i>
                </button>
                <a href="index.php?page=notifications" class="btn btn-light border btn-sm ed-icon-btn" title="Centro de notificaciones">
                    <i class="fas fa-bell"></i>
                </a>
            </div>
        </div>
        <div class="ed-content-card-body p-0">
            <div class="table-responsive ed-table-responsive">
                <table class="table table-hover table-sm ed-table mb-0">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Comunicado</th>
                            <th>Destinatarios</th>
                            <th>Entrega</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="an-history">
                        <tr class="ed-empty-row"><td colspan="5"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="an-compose-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="fas fa-bullhorn text-primary mr-2"></i>Nuevo comunicado</h5>
                    <div class="small text-muted mt-1">Redacta el mensaje y define exactamente quién debe recibirlo.</div>
                </div>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="an-compose-feedback"></div>
                <div class="row">
                    <div class="col-lg-7">
                        <div class="ed-modal-section mb-3">
                            <div class="ed-modal-section-title mb-2">1. Contenido</div>
                            <div class="form-group">
                                <label class="small font-weight-bold">Título</label>
                                <input id="an-title" class="form-control" maxlength="180" placeholder="Ej.: Reunión de padres">
                                <div class="small text-muted text-right mt-1"><span id="an-title-count">0</span>/180</div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="small font-weight-bold">Mensaje</label>
                                <textarea id="an-content" class="form-control" rows="7" maxlength="5000" placeholder="Escribe el comunicado completo..."></textarea>
                                <div class="small text-muted text-right mt-1"><span id="an-content-count">0</span>/5000</div>
                            </div>
                        </div>

                        <div class="ed-modal-section">
                            <div class="ed-modal-section-title mb-2">2. Destinatarios</div>
                            <div class="row no-gutters mx-n1 mb-3">
                                <div class="col-6 col-md px-1 mb-2"><div class="an-audience-option is-active border rounded p-2 text-center" data-type="all"><i class="fas fa-school d-block mb-1"></i><strong>Todo el colegio</strong></div></div>
                                <div class="col-6 col-md px-1 mb-2"><div class="an-audience-option border rounded p-2 text-center" data-type="level"><i class="fas fa-layer-group d-block mb-1"></i><strong>Nivel</strong></div></div>
                                <div class="col-6 col-md px-1 mb-2"><div class="an-audience-option border rounded p-2 text-center" data-type="grade"><i class="fas fa-graduation-cap d-block mb-1"></i><strong>Grado</strong></div></div>
                                <div class="col-6 col-md px-1 mb-2"><div class="an-audience-option border rounded p-2 text-center" data-type="section"><i class="fas fa-users d-block mb-1"></i><strong>Sección</strong></div></div>
                                <div class="col-6 col-md px-1 mb-2"><div class="an-audience-option border rounded p-2 text-center" data-type="student"><i class="fas fa-user d-block mb-1"></i><strong>Estudiante</strong></div></div>
                            </div>
                            <input type="hidden" id="an-audience" value="all">

                            <div class="row">
                                <div id="an-level-wrap" class="col-md-4 d-none">
                                    <div class="form-group"><label class="small font-weight-bold">Nivel</label><select id="an-level" class="form-control form-control-sm"><option value="">Selecciona</option></select></div>
                                </div>
                                <div id="an-grade-wrap" class="col-md-4 d-none">
                                    <div class="form-group"><label class="small font-weight-bold">Grado</label><select id="an-grade" class="form-control form-control-sm"><option value="">Selecciona</option></select></div>
                                </div>
                                <div id="an-section-wrap" class="col-md-4 d-none">
                                    <div class="form-group"><label class="small font-weight-bold">Sección</label><select id="an-section" class="form-control form-control-sm"><option value="">Selecciona</option></select></div>
                                </div>
                                <div id="an-student-wrap" class="col-12 d-none">
                                    <div class="form-group mb-0"><label class="small font-weight-bold">Estudiante</label><select id="an-student" class="form-control form-control-sm"><option value="">Selecciona</option></select></div>
                                </div>
                            </div>

                            <div class="ed-filter-card mb-0">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <strong id="an-recipient-label">Todo el colegio</strong>
                                        <div class="small text-muted" id="an-recipient-help">Se enviará a todos los estudiantes activos.</div>
                                    </div>
                                    <div class="text-right">
                                        <strong id="an-recipient-count">0</strong>
                                        <div class="small text-muted">estudiantes</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5 mt-3 mt-lg-0">
                        <div class="ed-modal-section">
                            <div class="ed-modal-section-title mb-2">Vista previa</div>
                            <div class="an-preview-phone">
                                <div class="an-preview-screen">
                                    <div class="small text-muted d-flex justify-content-between mb-4">
                                        <span>EduSync</span><span><i class="fas fa-wifi mr-1"></i><i class="fas fa-battery-three-quarters"></i></span>
                                    </div>
                                    <div class="an-preview-push">
                                        <div class="d-flex align-items-center mb-2">
                                            <span class="an-preview-logo mr-2">E</span>
                                            <span class="small font-weight-bold text-muted">EduSync · ahora</span>
                                        </div>
                                        <div class="font-weight-bold text-gray-800" id="an-preview-title">Comunicado institucional</div>
                                        <div class="an-preview-body mt-1" id="an-preview-body">El mensaje del comunicado aparecerá aquí.</div>
                                    </div>
                                    <div class="small text-muted text-center mt-3">
                                        Al tocarla, el estudiante abrirá el comunicado completo.
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-light border small mt-3 mb-0">
                                <i class="fas fa-info-circle text-primary mr-1"></i>
                                El comunicado se envía inmediatamente y queda guardado en el Centro de Notificaciones del estudiante.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border btn-sm" data-dismiss="modal">Cancelar</button>
                <button id="an-send" type="button" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane mr-1"></i>Revisar y enviar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="an-detail-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="fas fa-bullhorn text-primary mr-2"></i>Detalle del comunicado</h5>
                    <div class="small text-muted mt-1">Contenido, alcance y estado de entrega por estudiante.</div>
                </div>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="an-detail-loading" class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</div>
                <div id="an-detail-content" class="d-none">
                    <div class="ed-filter-card">
                        <div class="row">
                            <div class="col-md-8 mb-2 mb-md-0">
                                <div class="small text-muted">Comunicado</div>
                                <strong id="an-detail-title">—</strong>
                                <div id="an-detail-message" class="small text-muted mt-2 an-message-preview"></div>
                            </div>
                            <div class="col-md-4">
                                <div class="small text-muted">Destinatarios</div>
                                <strong id="an-detail-audience">—</strong>
                                <div class="small text-muted mt-1" id="an-detail-meta"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Destinatarios</div><strong class="ed-stat-value" id="an-detail-total">0</strong></div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Entregados</div><strong class="ed-stat-value text-success" id="an-detail-sent">0</strong></div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Sin dispositivo</div><strong class="ed-stat-value text-muted" id="an-detail-no-device">0</strong></div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Leídos</div><strong class="ed-stat-value text-info" id="an-detail-read">0</strong></div></div>
                    </div>

                    <div class="ed-toolbar justify-content-end mb-2">
                        <div class="input-group input-group-sm" style="max-width:260px">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                            <input id="an-detail-search" class="form-control" placeholder="Buscar estudiante...">
                        </div>
                    </div>
                    <div class="table-responsive ed-table-responsive">
                        <table class="table table-hover table-sm ed-table mb-0">
                            <thead><tr><th>Estudiante</th><th>Aula</th><th>Entrega</th><th>Lectura</th></tr></thead>
                            <tbody id="an-detail-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light border btn-sm" data-dismiss="modal">Cerrar</button></div>
        </div>
    </div>
</div>

<script>
(function($){
    const api='announcements_api.php';
    const csrf=<?php echo json_encode($csrf); ?>;
    const ready=<?php echo $ready ? 'true' : 'false'; ?>;

    let options={levels:[],grades:[],sections:[],students:[]};
    let history=[];
    let detailRows=[];

    function esc(v){return $('<div>').text(v==null?'':v).html();}
    function toast(message,type){
        if(window.EduSyncFeedback&&EduSyncFeedback.toast) EduSyncFeedback.toast(message,type||'info');
        else if(typeof alert_toast==='function') alert_toast(message,type||'info');
    }
    function inline(message,type){
        if(window.EduSyncFeedback&&EduSyncFeedback.inline) EduSyncFeedback.inline('#an-compose-feedback',message,type||'warning');
        else $('#an-compose-feedback').html('<div class="alert alert-'+(type||'warning')+'">'+esc(message)+'</div>');
    }
    function clearInline(){$('#an-compose-feedback').empty();}

    function dateText(v){
        const m=String(v||'').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m)return v||'—';
        let h=Number(m[4]),suffix=h>=12?'p. m.':'a. m.'; h=h%12||12;
        return m[3]+'/'+m[2]+'/'+m[1]+' · '+h+':'+m[5]+' '+suffix;
    }

    function fill(selector,rows,key,label){
        let html='<option value="">Selecciona</option>';
        (rows||[]).forEach(row=>{
            const value=typeof row==='string'?row:row[key];
            const text=typeof row==='string'?row:label(row);
            html+='<option value="'+esc(value)+'">'+esc(text)+'</option>';
        });
        $(selector).html(html);
    }

    function setupStudentSelect(){
        if(!$.fn.select2)return;
        const el=$('#an-student');
        if(el.hasClass('select2-hidden-accessible'))el.select2('destroy');
        el.select2({width:'100%',dropdownParent:$('#an-compose-modal'),placeholder:'Buscar estudiante',allowClear:true});
    }

    function loadOptions(){
        return $.getJSON(api,{action:'options'}).done(r=>{
            if(!r||Number(r.status)!==1){toast((r&&r.message)||'No se pudieron cargar los destinatarios.','danger');return;}
            options=r;
            fill('#an-level',r.levels||[],'',x=>x);
            fill('#an-student',r.students||[],'id',s=>s.name+' · '+s.level+' '+s.grade+' '+s.section+(s.dni?' · '+s.dni:''));
            setupStudentSelect();
            updateAudienceSummary();
        });
    }

    function refreshGrades(){
        const level=$('#an-level').val();
        fill('#an-grade',(options.grades||[]).filter(r=>r.level===level),'grade',r=>r.grade);
    }
    function refreshSections(){
        const level=$('#an-level').val(),grade=$('#an-grade').val();
        fill('#an-section',(options.sections||[]).filter(r=>r.level===level&&String(r.grade)===String(grade)),'section',r=>r.section);
    }

    function recipientRows(){
        const type=$('#an-audience').val()||'all',level=$('#an-level').val(),grade=$('#an-grade').val(),section=$('#an-section').val(),studentId=Number($('#an-student').val()||0);
        const rows=options.students||[];
        if(type==='all')return rows;
        if(type==='level')return rows.filter(s=>s.level===level);
        if(type==='grade')return rows.filter(s=>s.level===level&&String(s.grade)===String(grade));
        if(type==='section')return rows.filter(s=>s.level===level&&String(s.grade)===String(grade)&&String(s.section)===String(section));
        if(type==='student')return rows.filter(s=>Number(s.id)===studentId);
        return [];
    }

    function audienceLabel(){
        const type=$('#an-audience').val()||'all',level=$('#an-level').val(),grade=$('#an-grade').val(),section=$('#an-section').val();
        if(type==='all')return 'Todo el colegio';
        if(type==='level')return level||'Nivel sin seleccionar';
        if(type==='grade')return (level&&grade)?level+' · '+grade:'Grado sin seleccionar';
        if(type==='section')return (level&&grade&&section)?level+' · '+grade+' '+section:'Sección sin seleccionar';
        const row=recipientRows()[0]; return row?row.name:'Estudiante sin seleccionar';
    }

    function updateAudienceUI(){
        const type=$('#an-audience').val()||'all';
        $('.an-audience-option').removeClass('is-active').filter('[data-type="'+type+'"]').addClass('is-active');
        $('#an-level-wrap').toggleClass('d-none',!['level','grade','section'].includes(type));
        $('#an-grade-wrap').toggleClass('d-none',!['grade','section'].includes(type));
        $('#an-section-wrap').toggleClass('d-none',type!=='section');
        $('#an-student-wrap').toggleClass('d-none',type!=='student');
        updateAudienceSummary();
    }

    function updateAudienceSummary(){
        const type=$('#an-audience').val()||'all';
        $('#an-recipient-label').text(audienceLabel());
        $('#an-recipient-count').text(recipientRows().length);
        $('#an-recipient-help').text({
            all:'Se enviará a todos los estudiantes activos.',
            level:'Se enviará al nivel seleccionado.',
            grade:'Se enviará al grado seleccionado.',
            section:'Se enviará únicamente a esta sección.',
            student:'Se enviará únicamente a este estudiante.'
        }[type]||'');
    }

    function updatePreview(){
        const title=$.trim($('#an-title').val());
        const body=$.trim($('#an-content').val()).replace(/\s+/g,' ');
        $('#an-title-count').text($('#an-title').val().length);
        $('#an-content-count').text($('#an-content').val().length);
        $('#an-preview-title').text(title||'Comunicado institucional');
        $('#an-preview-body').text(body?body.substring(0,180)+(body.length>180?'...':''):'El mensaje del comunicado aparecerá aquí.');
    }

    function validate(){
        const title=$.trim($('#an-title').val()),content=$.trim($('#an-content').val()),type=$('#an-audience').val()||'all';
        if(title.length<3)return 'Escribe un título de al menos 3 caracteres.';
        if(content.length<3)return 'Escribe el contenido del comunicado.';
        if(type==='level'&&!$('#an-level').val())return 'Selecciona el nivel.';
        if(type==='grade'&&(!$('#an-level').val()||!$('#an-grade').val()))return 'Selecciona nivel y grado.';
        if(type==='section'&&(!$('#an-level').val()||!$('#an-grade').val()||!$('#an-section').val()))return 'Selecciona nivel, grado y sección.';
        if(type==='student'&&!$('#an-student').val())return 'Selecciona el estudiante.';
        if(recipientRows().length<=0)return 'No hay estudiantes activos para la selección.';
        return '';
    }

    function resetCompose(){
        clearInline();
        $('#an-title,#an-content').val('');
        $('#an-audience').val('all');
        $('#an-level,#an-grade,#an-section').val('');
        $('#an-student').val('').trigger('change');
        refreshGrades();refreshSections();updateAudienceUI();updatePreview();
    }

    function loadSummary(){
        if(!ready)return;
        $.getJSON(api,{action:'summary'}).done(r=>{
            const s=(r&&r.summary)||{};
            $('#an-stat-month').text(Number(s.month_total||0));
            $('#an-stat-recipients').text(Number(s.month_recipients||0));
            $('#an-stat-sent').text(Number(s.month_push_sent||0));
            $('#an-stat-failed').text(Number(s.month_push_failed||0));
        });
    }

    function loadHistory(){
        if(!ready)return;
        $('#an-history').html('<tr class="ed-empty-row"><td colspan="5"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</td></tr>');
        $.getJSON(api,{action:'list'}).done(r=>{history=(r&&r.items)||[];renderHistory();})
            .fail(()=>$('#an-history').html('<tr class="ed-empty-row"><td colspan="5" class="text-danger">No se pudo cargar el historial.</td></tr>'));
    }

    function renderHistory(){
        const q=$.trim($('#an-search').val()).toLowerCase();
        const rows=history.filter(x=>!q||[x.title,x.content,x.audience,x.created_by_name].join(' ').toLowerCase().includes(q));
        if(!rows.length){
            $('#an-history').html('<tr class="ed-empty-row"><td colspan="5">'+(q?'No hay coincidencias.':'Aún no hay comunicados.')+'</td></tr>');
            return;
        }
        let html='';
        rows.forEach(x=>{
            const noDevice=Math.max(0,Number(x.recipient_count||0)-Math.min(Number(x.recipient_count||0),Number(x.push_sent_count||0)));
            html+='<tr>'+
                '<td><strong>'+esc(dateText(x.created_at))+'</strong><div class="small text-muted">'+esc(x.created_by_name)+'</div></td>'+
                '<td><strong>'+esc(x.title)+'</strong><div class="small text-muted text-truncate" style="max-width:440px">'+esc(x.content)+'</div></td>'+
                '<td><span class="badge badge-light border ed-badge">'+esc(x.audience)+'</span><div class="small text-muted mt-1">'+Number(x.recipient_count||0)+' estudiante(s)</div></td>'+
                '<td><span class="badge badge-success ed-badge">'+Number(x.push_sent_count||0)+' enviados</span>'+
                    (Number(x.push_failed_count||0)?'<div class="small text-danger mt-1">'+Number(x.push_failed_count)+' fallidos</div>':'')+'</td>'+
                '<td class="text-right an-history-actions"><button class="btn btn-outline-primary btn-sm an-detail" data-id="'+Number(x.id)+'"><i class="fas fa-eye mr-1"></i>Detalle</button></td>'+
            '</tr>';
        });
        $('#an-history').html(html);
    }

    function stateBadge(state){
        if(state==='sent')return '<span class="badge badge-success ed-badge"><i class="fas fa-check mr-1"></i>Entregado</span>';
        if(state==='failed')return '<span class="badge badge-danger ed-badge"><i class="fas fa-times mr-1"></i>Fallido</span>';
        if(state==='no_device')return '<span class="badge badge-secondary ed-badge"><i class="fas fa-mobile-alt mr-1"></i>Sin dispositivo</span>';
        return '<span class="badge badge-warning ed-badge"><i class="fas fa-clock mr-1"></i>Pendiente</span>';
    }

    function renderDetail(){
        const q=$.trim($('#an-detail-search').val()).toLowerCase();
        const rows=detailRows.filter(r=>!q||[r.student_name,r.dni,r.level,r.grade,r.section].join(' ').toLowerCase().includes(q));
        if(!rows.length){
            $('#an-detail-body').html('<tr class="ed-empty-row"><td colspan="4">No hay estudiantes para mostrar.</td></tr>');
            return;
        }
        let html='';
        rows.forEach(r=>{
            html+='<tr><td><strong>'+esc(r.student_name)+'</strong><div class="small text-muted">'+esc(r.dni||'')+'</div></td>'+
                '<td>'+esc(r.level+' · '+r.grade+' '+r.section)+'</td><td>'+stateBadge(r.state)+'</td>'+
                '<td>'+(r.is_read?'<span class="text-success small"><i class="fas fa-check-double mr-1"></i>Leído</span>':'<span class="text-muted small">No leído</span>')+'</td></tr>';
        });
        $('#an-detail-body').html(html);
    }

    function openDetail(id){
        $('#an-detail-content').addClass('d-none');
        $('#an-detail-loading').removeClass('d-none').html('<i class="fas fa-spinner fa-spin mr-1"></i>Cargando...');
        $('#an-detail-search').val('');
        $('#an-detail-modal').modal('show');
        $.getJSON(api,{action:'detail',id:id}).done(r=>{
            if(!r||Number(r.status)!==1){
                $('#an-detail-loading').html('<span class="text-danger">'+esc((r&&r.message)||'No se pudo cargar el detalle.')+'</span>');return;
            }
            const a=r.announcement||{},counts=r.counts||{};
            detailRows=r.delivery||[];
            $('#an-detail-title').text(a.title||'—');
            $('#an-detail-message').text(a.content||'');
            $('#an-detail-audience').text(a.audience||'—');
            $('#an-detail-meta').text((a.created_by_name||'Administración')+' · '+dateText(a.created_at));
            $('#an-detail-total').text(Number(a.recipient_count||0));
            $('#an-detail-sent').text(Number(counts.sent||0));
            $('#an-detail-no-device').text(Number(counts.no_device||0));
            $('#an-detail-read').text(Number(counts.read||0));
            renderDetail();
            $('#an-detail-loading').addClass('d-none');
            $('#an-detail-content').removeClass('d-none');
        }).fail(x=>$('#an-detail-loading').html('<span class="text-danger">'+esc((x.responseJSON||{}).message||'No se pudo cargar el detalle.')+'</span>'));
    }

    async function send(){
        const error=validate();
        if(error){inline(error,'warning');return;}

        const count=recipientRows().length;
        const message='<div class="text-left"><p class="mb-2">El comunicado se enviará inmediatamente y quedará disponible en la app EduSync.</p>'+
            '<div class="border rounded p-2 bg-light"><div><strong>'+esc($.trim($('#an-title').val()))+'</strong></div>'+
            '<div class="small text-muted mt-1">'+esc(audienceLabel())+' · '+count+' estudiante(s)</div></div></div>';

        let accepted=true;
        if(window.EduSyncFeedback&&EduSyncFeedback.confirm){
            accepted=await EduSyncFeedback.confirm(message,{type:'info',title:'Enviar comunicado',confirmText:'Enviar ahora',cancelText:'Cancelar',html:true});
        }else accepted=window.confirm('¿Enviar este comunicado ahora?');
        if(!accepted)return;

        const btn=document.getElementById('an-send');
        if(window.EduSyncFeedback&&EduSyncFeedback.setButtonLoading)EduSyncFeedback.setButtonLoading(btn,true,'Enviando...');
        else $(btn).prop('disabled',true);

        const data={
            action:'send',csrf_token:csrf,
            title:$.trim($('#an-title').val()),content:$.trim($('#an-content').val()),
            audience_type:$('#an-audience').val()||'all',
            level:$('#an-level').val()||'',grade:$('#an-grade').val()||'',section:$('#an-section').val()||'',
            student_id:$('#an-student').val()||''
        };
        $.post(api,data,null,'json').done(r=>{
            if(!r||Number(r.status)!==1){inline((r&&r.message)||'No se pudo enviar el comunicado.','danger');return;}
            $('#an-compose-modal').modal('hide');
            const missing=Math.max(0,Number(r.recipients||0)-Number(r.students_with_devices||0));
            let msg='Comunicado enviado a '+Number(r.recipients||0)+' estudiante(s). '+Number(r.push_sent||0)+' push enviados.';
            if(missing)msg+=' '+missing+' sin dispositivo registrado.';
            if(Number(r.push_failed||0))msg+=' '+Number(r.push_failed)+' push fallidos.';
            toast(msg,(missing||Number(r.push_failed||0))?'warning':'success');
            loadSummary();loadHistory();
        }).fail(x=>inline((x.responseJSON||{}).message||'No se pudo enviar el comunicado.','danger'))
          .always(()=>{
              if(window.EduSyncFeedback&&EduSyncFeedback.setButtonLoading)EduSyncFeedback.setButtonLoading(btn,false);
              else $(btn).prop('disabled',false);
          });
    }

    $('.an-audience-option').on('click',function(){$('#an-audience').val($(this).data('type'));updateAudienceUI();});
    $('#an-level').on('change',function(){refreshGrades();refreshSections();updateAudienceSummary();});
    $('#an-grade').on('change',function(){refreshSections();updateAudienceSummary();});
    $('#an-section,#an-student').on('change',updateAudienceSummary);
    $('#an-title,#an-content').on('input',updatePreview);
    $('#an-search').on('input',renderHistory);
    $('#an-detail-search').on('input',renderDetail);
    $('#an-new').on('click',function(){resetCompose();$('#an-compose-modal').modal('show');});
    $('#an-send').on('click',send);
    $('#an-refresh').on('click',function(){loadSummary();loadHistory();});
    $(document).on('click','.an-detail',function(){openDetail(Number($(this).data('id')||0));});

    if(ready){
        loadOptions().always(resetCompose);
        loadSummary();
        loadHistory();
    }
})(jQuery);
</script>
