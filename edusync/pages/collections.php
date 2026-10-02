<?php
include_once __DIR__ . '/../db_connect.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$ready = true;
foreach (['student_collection_campaigns','student_collection_recipients'] as $table) {
    $safe = $conn->real_escape_string($table);
    $q = $conn->query("SHOW TABLES LIKE '$safe'");
    if (!$q || !$q->num_rows) $ready = false;
}
?>
<style>
/* Solo patrones propios de Cobranza que Bootstrap/ed-* no cubren. */
.cn-audience-option{cursor:pointer;height:100%;min-height:78px;transition:border-color .15s,background .15s}
.cn-audience-option.is-active{border-color:#9cbcf9!important;background:#eef4ff!important}
.cn-audience-option.is-active i,.cn-audience-option.is-active strong{color:#4285f4!important}
.cn-audience-option i{font-size:1.05rem;color:#7a8798}
.cn-audience-option strong{font-size:.73rem;color:#526274}
.cn-debt-option{cursor:pointer;transition:border-color .15s,background .15s}
.cn-debt-option.is-active{border-color:#9cbcf9!important;background:#f7faff!important}
.cn-preview-phone{max-width:340px;margin:0 auto;background:#edf0f5;border-radius:22px;padding:10px}
.cn-preview-screen{min-height:245px;background:#f8f9fc;border-radius:15px;padding:16px}
.cn-preview-push{background:#fff;border:1px solid #edf0f5;border-radius:.6rem;padding:.8rem;box-shadow:0 .125rem .5rem rgba(31,45,61,.06)}
.cn-preview-logo{width:26px;height:26px;border-radius:7px;background:#4285f4;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700}
.cn-preview-body{font-size:.78rem;color:#6e7891;line-height:1.4}
.cn-detail-message{white-space:pre-line}
.cn-money{font-variant-numeric:tabular-nums}
@media(max-width:767.98px){
    .cn-audience-option{min-height:68px}
    .cn-history-actions{white-space:normal!important}
}
</style>

<div class="container-fluid py-3">
    <div class="ed-page-header">
        <div class="ed-page-heading">
            <div class="ed-page-icon"><i class="fas fa-hand-holding-usd"></i></div>
            <div>
                <h1 class="ed-page-title">Cobranza</h1>
                <p class="ed-page-subtitle">Envía recordatorios manuales basados en las deudas reales registradas en EduSync.</p>
            </div>
        </div>
        <div class="ed-page-actions">
            <a href="index.php?page=announcements" class="btn btn-light border btn-sm">
                <i class="fas fa-bullhorn mr-1"></i>Comunicados
            </a>
            <button id="cn-new" class="btn btn-primary btn-sm ed-page-action" <?php echo $ready ? '' : 'disabled'; ?>>
                <i class="fas fa-paper-plane mr-1"></i>Nueva notificación de cobro
            </button>
        </div>
    </div>

    <?php if (!$ready): ?>
        <div class="alert alert-warning">
            <i class="fas fa-database mr-2"></i>
            Ejecuta manualmente <strong>sql/student_collection_campaigns.sql</strong>.
        </div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="ed-stat-card">
                <div class="ed-stat-label">Estudiantes con deuda</div>
                <strong class="ed-stat-value" id="cn-stat-students">—</strong>
                <div class="ed-stat-meta">Con saldo exigible pendiente</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="ed-stat-card">
                <div class="ed-stat-label">Saldo pendiente</div>
                <strong class="ed-stat-value text-danger cn-money" id="cn-stat-balance">—</strong>
                <div class="ed-stat-meta">Deudas activas de estudiantes activos</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-md-0">
            <div class="ed-stat-card">
                <div class="ed-stat-label">Saldo vencido</div>
                <strong class="ed-stat-value text-danger cn-money" id="cn-stat-overdue">—</strong>
                <div class="ed-stat-meta">Incluye deudas sin fecha de vencimiento</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="ed-stat-card">
                <div class="ed-stat-label">Envíos este mes</div>
                <strong class="ed-stat-value" id="cn-stat-campaigns">—</strong>
                <div class="ed-stat-meta">Campañas manuales realizadas</div>
            </div>
        </div>
    </div>

    <div class="ed-content-card">
        <div class="ed-content-card-header d-flex flex-wrap align-items-center justify-content-between">
            <div>
                <strong><i class="fas fa-history text-primary mr-2"></i>Historial de cobranza</strong>
                <div class="small text-muted mt-1">Consulta a quién se notificó, el saldo comunicado y el estado de entrega.</div>
            </div>
            <div class="ed-toolbar mt-2 mt-md-0">
                <div class="input-group input-group-sm" style="max-width:270px">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                    <input id="cn-search" class="form-control" placeholder="Buscar envío...">
                </div>
                <button id="cn-refresh" class="btn btn-light border btn-sm ed-icon-btn" title="Actualizar">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        <div class="ed-content-card-body p-0">
            <div class="table-responsive ed-table-responsive">
                <table class="table table-hover table-sm ed-table mb-0">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Destinatarios</th>
                            <th>Deudas incluidas</th>
                            <th class="text-right">Saldo comunicado</th>
                            <th>Entrega</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="cn-history">
                        <tr class="ed-empty-row"><td colspan="6"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cn-compose-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="fas fa-hand-holding-usd text-primary mr-2"></i>Nueva notificación de cobro</h5>
                    <div class="small text-muted mt-1">Selecciona los destinatarios y las obligaciones que deseas recordar.</div>
                </div>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="cn-compose-feedback"></div>
                <div class="row">
                    <div class="col-lg-7">
                        <div class="ed-modal-section mb-3">
                            <div class="ed-modal-section-title mb-2">1. Destinatarios</div>
                            <div class="row no-gutters mx-n1 mb-3">
                                <div class="col-6 col-md px-1 mb-2">
                                    <div class="cn-audience-option is-active border rounded p-2 text-center" data-type="all">
                                        <i class="fas fa-users d-block mb-1"></i><strong>Todos con deuda</strong>
                                    </div>
                                </div>
                                <div class="col-6 col-md px-1 mb-2">
                                    <div class="cn-audience-option border rounded p-2 text-center" data-type="level">
                                        <i class="fas fa-layer-group d-block mb-1"></i><strong>Nivel</strong>
                                    </div>
                                </div>
                                <div class="col-6 col-md px-1 mb-2">
                                    <div class="cn-audience-option border rounded p-2 text-center" data-type="grade">
                                        <i class="fas fa-graduation-cap d-block mb-1"></i><strong>Grado</strong>
                                    </div>
                                </div>
                                <div class="col-6 col-md px-1 mb-2">
                                    <div class="cn-audience-option border rounded p-2 text-center" data-type="section">
                                        <i class="fas fa-chalkboard d-block mb-1"></i><strong>Sección</strong>
                                    </div>
                                </div>
                                <div class="col-6 col-md px-1 mb-2">
                                    <div class="cn-audience-option border rounded p-2 text-center" data-type="student">
                                        <i class="fas fa-user d-block mb-1"></i><strong>Estudiante</strong>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" id="cn-audience" value="all">

                            <div class="row">
                                <div id="cn-level-wrap" class="col-md-4 d-none">
                                    <div class="form-group">
                                        <label class="small font-weight-bold">Nivel</label>
                                        <select id="cn-level" class="form-control form-control-sm"><option value="">Selecciona</option></select>
                                    </div>
                                </div>
                                <div id="cn-grade-wrap" class="col-md-4 d-none">
                                    <div class="form-group">
                                        <label class="small font-weight-bold">Grado</label>
                                        <select id="cn-grade" class="form-control form-control-sm"><option value="">Selecciona</option></select>
                                    </div>
                                </div>
                                <div id="cn-section-wrap" class="col-md-4 d-none">
                                    <div class="form-group">
                                        <label class="small font-weight-bold">Sección</label>
                                        <select id="cn-section" class="form-control form-control-sm"><option value="">Selecciona</option></select>
                                    </div>
                                </div>
                                <div id="cn-student-wrap" class="col-12 d-none">
                                    <div class="form-group mb-0">
                                        <label class="small font-weight-bold">Estudiante</label>
                                        <select id="cn-student" class="form-control form-control-sm"><option value="">Selecciona</option></select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ed-modal-section mb-3">
                            <div class="ed-modal-section-title mb-2">2. Deudas que se incluirán</div>
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <div class="cn-debt-option is-active border rounded p-3" data-state="overdue">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input cn-state" id="cn-overdue" checked>
                                            <label class="custom-control-label font-weight-bold" for="cn-overdue">Vencidas</label>
                                        </div>
                                        <div class="small text-muted mt-2">Fecha vencida o sin fecha registrada.</div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <div class="cn-debt-option is-active border rounded p-3" data-state="partial">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input cn-state" id="cn-partial" checked>
                                            <label class="custom-control-label font-weight-bold" for="cn-partial">Parciales</label>
                                        </div>
                                        <div class="small text-muted mt-2">Tienen un pago confirmado, pero aún mantienen saldo.</div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <div class="cn-debt-option border rounded p-3" data-state="upcoming">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input cn-state" id="cn-upcoming">
                                            <label class="custom-control-label font-weight-bold" for="cn-upcoming">Por vencer</label>
                                        </div>
                                        <div class="small text-muted mt-2">Tienen fecha de vencimiento igual o posterior a hoy.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="small text-muted">
                                Si una deuda coincide con más de un criterio, EduSync la cuenta una sola vez.
                            </div>
                        </div>

                        <div class="ed-modal-section">
                            <div class="ed-modal-section-title mb-2">3. Resultado de la selección</div>
                            <div id="cn-preview-loading" class="text-muted small py-3">
                                <i class="fas fa-spinner fa-spin mr-1"></i>Calculando destinatarios...
                            </div>
                            <div id="cn-preview-summary" class="d-none">
                                <div class="row mb-3">
                                    <div class="col-6 col-md-3 mb-2">
                                        <div class="ed-stat-card shadow-none">
                                            <div class="ed-stat-label">Estudiantes</div>
                                            <strong class="ed-stat-value" id="cn-prev-students">0</strong>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-2">
                                        <div class="ed-stat-card shadow-none">
                                            <div class="ed-stat-label">Deudas</div>
                                            <strong class="ed-stat-value" id="cn-prev-debts">0</strong>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-2">
                                        <div class="ed-stat-card shadow-none">
                                            <div class="ed-stat-label">Saldo</div>
                                            <strong class="ed-stat-value text-danger cn-money" id="cn-prev-balance">S/ 0.00</strong>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-2">
                                        <div class="ed-stat-card shadow-none">
                                            <div class="ed-stat-label">Con dispositivo</div>
                                            <strong class="ed-stat-value text-success" id="cn-prev-devices">0</strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive" style="max-height:230px">
                                    <table class="table table-hover table-sm ed-table mb-0">
                                        <thead><tr><th>Estudiante</th><th>Deudas</th><th class="text-right">Saldo</th></tr></thead>
                                        <tbody id="cn-preview-students"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5 mt-3 mt-lg-0">
                        <div class="ed-modal-section">
                            <div class="ed-modal-section-title mb-2">Vista previa</div>
                            <div class="cn-preview-phone">
                                <div class="cn-preview-screen">
                                    <div class="small text-muted d-flex justify-content-between mb-4">
                                        <span>EduSync</span><span><i class="fas fa-wifi mr-1"></i><i class="fas fa-battery-three-quarters"></i></span>
                                    </div>
                                    <div class="cn-preview-push">
                                        <div class="d-flex align-items-center mb-2">
                                            <span class="cn-preview-logo mr-2">E</span>
                                            <span class="small font-weight-bold text-muted">EduSync · ahora</span>
                                        </div>
                                        <div class="font-weight-bold text-gray-800" id="cn-phone-title">Pago pendiente</div>
                                        <div class="cn-preview-body mt-1" id="cn-phone-body">
                                            Selecciona los filtros para generar una vista previa personalizada.
                                        </div>
                                    </div>
                                    <div class="small text-muted text-center mt-3">
                                        Al tocarla abrirá directamente <strong>Mis Deudas</strong>.
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-light border small mt-3 mb-0">
                                <i class="fas fa-shield-alt text-primary mr-1"></i>
                                El envío no altera deudas ni pagos. Solo comunica el saldo vigente al momento del envío.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border btn-sm" data-dismiss="modal">Cancelar</button>
                <button id="cn-send" type="button" class="btn btn-primary btn-sm">
                    <i class="fas fa-paper-plane mr-1"></i>Revisar y enviar
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cn-detail-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="fas fa-chart-pie text-primary mr-2"></i>Detalle de cobranza</h5>
                    <div class="small text-muted mt-1">Estado de entrega y lectura por estudiante.</div>
                </div>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="cn-detail-loading" class="text-center text-muted py-5">
                    <i class="fas fa-spinner fa-spin mr-1"></i>Cargando detalle...
                </div>
                <div id="cn-detail-content" class="d-none">
                    <div class="ed-filter-card mb-3">
                        <div class="row">
                            <div class="col-md-3 mb-2 mb-md-0"><div class="small text-muted">Destinatarios</div><strong id="cn-detail-audience">—</strong></div>
                            <div class="col-md-3 mb-2 mb-md-0"><div class="small text-muted">Deudas incluidas</div><strong id="cn-detail-states">—</strong></div>
                            <div class="col-md-3 mb-2 mb-md-0"><div class="small text-muted">Saldo comunicado</div><strong class="text-danger cn-money" id="cn-detail-balance">—</strong></div>
                            <div class="col-md-3"><div class="small text-muted">Enviado por</div><strong id="cn-detail-author">—</strong></div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Destinatarios</div><strong class="ed-stat-value" id="cn-detail-total">0</strong></div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Entregados</div><strong class="ed-stat-value text-success" id="cn-detail-sent">0</strong></div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Sin dispositivo</div><strong class="ed-stat-value text-muted" id="cn-detail-no-device">0</strong></div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Leídos</div><strong class="ed-stat-value text-info" id="cn-detail-read">0</strong></div></div>
                    </div>

                    <div class="ed-toolbar justify-content-end mb-2">
                        <div class="input-group input-group-sm" style="max-width:260px">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                            <input id="cn-detail-search" class="form-control" placeholder="Buscar estudiante...">
                        </div>
                    </div>
                    <div class="table-responsive ed-table-responsive">
                        <table class="table table-hover table-sm ed-table mb-0">
                            <thead><tr><th>Estudiante</th><th>Aula</th><th>Deudas</th><th class="text-right">Saldo</th><th>Entrega</th><th>Lectura</th></tr></thead>
                            <tbody id="cn-detail-body"></tbody>
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
    const api='collection_notifications_api.php';
    const csrf=<?php echo json_encode($csrf); ?>;
    const ready=<?php echo $ready ? 'true' : 'false'; ?>;

    let options={levels:[],grades:[],sections:[],students:[]};
    let preview=null;
    let history=[];
    let detailRows=[];
    let previewTimer=null;

    function money(v){ return 'S/ '+Number(v||0).toFixed(2); }
    function esc(v){ return $('<div>').text(v==null?'':v).html(); }
    function toast(message,type){
        if(window.EduSyncFeedback&&EduSyncFeedback.toast) EduSyncFeedback.toast(message,type||'info');
        else if(typeof alert_toast==='function') alert_toast(message,type||'info');
    }
    function inline(message,type){
        if(window.EduSyncFeedback&&EduSyncFeedback.inline) EduSyncFeedback.inline('#cn-compose-feedback',message,type||'warning');
        else $('#cn-compose-feedback').html('<div class="alert alert-'+(type||'warning')+'">'+esc(message)+'</div>');
    }
    function clearInline(){ $('#cn-compose-feedback').empty(); }

    function dateText(v){
        const m=String(v||'').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m)return v||'—';
        let h=Number(m[4]),suffix=h>=12?'p. m.':'a. m.';
        h=h%12||12;
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
        const el=$('#cn-student');
        if(el.hasClass('select2-hidden-accessible'))el.select2('destroy');
        el.select2({
            width:'100%',
            dropdownParent:$('#cn-compose-modal'),
            placeholder:'Buscar estudiante',
            allowClear:true
        });
    }

    function loadOptions(){
        return $.getJSON(api,{action:'options'}).done(r=>{
            if(!r||Number(r.status)!==1){ toast((r&&r.message)||'No se pudieron cargar los destinatarios.','danger'); return; }
            options=r;
            fill('#cn-level',r.levels||[],'',x=>x);
            fill('#cn-student',r.students||[],'id',s=>s.name+' · '+s.level+' '+s.grade+' '+s.section+(s.dni?' · '+s.dni:''));
            setupStudentSelect();
        });
    }

    function refreshGrades(){
        const level=$('#cn-level').val();
        fill('#cn-grade',(options.grades||[]).filter(r=>r.level===level),'grade',r=>r.grade);
    }
    function refreshSections(){
        const level=$('#cn-level').val(),grade=$('#cn-grade').val();
        fill('#cn-section',(options.sections||[]).filter(r=>r.level===level&&String(r.grade)===String(grade)),'section',r=>r.section);
    }

    function payload(){
        return {
            audience_type:$('#cn-audience').val()||'all',
            level:$('#cn-level').val()||'',
            grade:$('#cn-grade').val()||'',
            section:$('#cn-section').val()||'',
            student_id:$('#cn-student').val()||'',
            include_overdue:$('#cn-overdue').prop('checked')?1:0,
            include_partial:$('#cn-partial').prop('checked')?1:0,
            include_upcoming:$('#cn-upcoming').prop('checked')?1:0
        };
    }

    function validate(){
        const p=payload();
        if(p.audience_type==='level'&&!p.level)return 'Selecciona el nivel.';
        if(p.audience_type==='grade'&&(!p.level||!p.grade))return 'Selecciona nivel y grado.';
        if(p.audience_type==='section'&&(!p.level||!p.grade||!p.section))return 'Selecciona nivel, grado y sección.';
        if(p.audience_type==='student'&&!p.student_id)return 'Selecciona el estudiante.';
        if(!p.include_overdue&&!p.include_partial&&!p.include_upcoming)return 'Selecciona al menos un tipo de deuda.';
        return '';
    }

    function updateAudience(){
        const type=$('#cn-audience').val()||'all';
        $('.cn-audience-option').removeClass('is-active').filter('[data-type="'+type+'"]').addClass('is-active');
        $('#cn-level-wrap').toggleClass('d-none',!['level','grade','section'].includes(type));
        $('#cn-grade-wrap').toggleClass('d-none',!['grade','section'].includes(type));
        $('#cn-section-wrap').toggleClass('d-none',type!=='section');
        $('#cn-student-wrap').toggleClass('d-none',type!=='student');
        schedulePreview();
    }

    function syncStateCards(){
        $('.cn-debt-option').each(function(){
            const checkbox=$(this).find('.cn-state');
            $(this).toggleClass('is-active',checkbox.prop('checked'));
        });
    }

    function schedulePreview(){
        window.clearTimeout(previewTimer);
        previewTimer=window.setTimeout(loadPreview,220);
    }

    function previewMessage(){
        const first=preview&&preview.students&&preview.students[0];
        if(!first){
            $('#cn-phone-title').text('Pago pendiente');
            $('#cn-phone-body').text('Selecciona filtros con estudiantes que mantengan saldo pendiente.');
            return;
        }
        if(Number(first.debt_count||0)<=1){
            const concept=(first.concepts&&first.concepts[0])||'obligación pendiente';
            $('#cn-phone-title').text('Pago pendiente');
            $('#cn-phone-body').text('Tienes un saldo pendiente de '+money(first.balance)+' por '+concept+'. Ingresa a Mis Deudas para revisar el detalle.');
        }else{
            $('#cn-phone-title').text('Pagos pendientes');
            $('#cn-phone-body').text('Tienes '+Number(first.debt_count)+' obligaciones pendientes por un total de '+money(first.balance)+'. Ingresa a Mis Deudas para revisar el detalle.');
        }
    }

    function loadPreview(){
        clearInline();
        const error=validate();
        if(error){
            preview=null;
            $('#cn-preview-loading').removeClass('d-none').text(error);
            $('#cn-preview-summary').addClass('d-none');
            previewMessage();
            return;
        }

        $('#cn-preview-loading').removeClass('d-none').html('<i class="fas fa-spinner fa-spin mr-1"></i>Calculando destinatarios...');
        $('#cn-preview-summary').addClass('d-none');

        $.getJSON(api,$.extend({action:'preview'},payload())).done(r=>{
            if(!r||Number(r.status)!==1){
                preview=null;
                $('#cn-preview-loading').text((r&&r.message)||'No se pudo calcular la selección.');
                previewMessage();
                return;
            }
            preview=r.preview||{};
            $('#cn-prev-students').text(Number(preview.recipient_count||0));
            $('#cn-prev-debts').text(Number(preview.debt_count||0));
            $('#cn-prev-balance').text(money(preview.total_balance));
            $('#cn-prev-devices').text(Number(preview.students_with_devices||0));

            let html='';
            (preview.students||[]).slice(0,20).forEach(s=>{
                html+='<tr><td><strong>'+esc(s.student_name)+'</strong><div class="small text-muted">'+esc(s.level+' · '+s.grade+' '+s.section)+'</div></td>'+
                    '<td>'+Number(s.debt_count||0)+'</td><td class="text-right font-weight-bold text-danger">'+money(s.balance)+'</td></tr>';
            });
            if((preview.students||[]).length>20){
                html+='<tr><td colspan="3" class="text-center text-muted small">Y '+((preview.students||[]).length-20)+' estudiante(s) más.</td></tr>';
            }
            if(!html)html='<tr class="ed-empty-row"><td colspan="3">No hay estudiantes con saldo para esta selección.</td></tr>';
            $('#cn-preview-students').html(html);
            $('#cn-preview-loading').addClass('d-none');
            $('#cn-preview-summary').removeClass('d-none');
            previewMessage();
        }).fail(x=>{
            preview=null;
            $('#cn-preview-loading').text((x.responseJSON||{}).message||'No se pudo calcular la selección.');
            previewMessage();
        });
    }

    function loadSummary(){
        if(!ready)return;
        $.getJSON(api,{action:'summary'}).done(r=>{
            const s=(r&&r.summary)||{};
            $('#cn-stat-students').text(Number(s.students_with_debt||0));
            $('#cn-stat-balance').text(money(s.balance));
            $('#cn-stat-overdue').text(money(s.overdue_balance));
            $('#cn-stat-campaigns').text(Number(s.campaigns_month||0));
        });
    }

    function loadHistory(){
        if(!ready)return;
        $('#cn-history').html('<tr class="ed-empty-row"><td colspan="6"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</td></tr>');
        $.getJSON(api,{action:'history'}).done(r=>{
            history=(r&&r.items)||[];
            renderHistory();
        }).fail(()=>$('#cn-history').html('<tr class="ed-empty-row"><td colspan="6" class="text-danger">No se pudo cargar el historial.</td></tr>'));
    }

    function renderHistory(){
        const q=$.trim($('#cn-search').val()).toLowerCase();
        const rows=history.filter(x=>!q||[x.audience,(x.states||[]).join(' '),x.created_by_name].join(' ').toLowerCase().includes(q));
        if(!rows.length){
            $('#cn-history').html('<tr class="ed-empty-row"><td colspan="6">'+(q?'No hay coincidencias.':'Aún no hay envíos de cobranza.')+'</td></tr>');
            return;
        }
        let html='';
        rows.forEach(x=>{
            const noDevice=Math.max(0,Number(x.recipient_count||0)-Number(x.students_with_devices||0));
            html+='<tr>'+
                '<td><strong>'+esc(dateText(x.created_at))+'</strong><div class="small text-muted">'+esc(x.created_by_name)+'</div></td>'+
                '<td><strong>'+esc(x.audience)+'</strong><div class="small text-muted">'+Number(x.recipient_count||0)+' estudiante(s)</div></td>'+
                '<td>'+((x.states||[]).map(s=>'<span class="badge badge-light border mr-1 ed-badge">'+esc(s)+'</span>').join(''))+'<div class="small text-muted mt-1">'+Number(x.debt_count||0)+' obligación(es)</div></td>'+
                '<td class="text-right font-weight-bold text-danger cn-money">'+money(x.total_balance)+'</td>'+
                '<td><span class="badge badge-success ed-badge">'+Number(x.push_sent_count||0)+' enviados</span>'+
                    (noDevice?'<div class="small text-muted mt-1">'+noDevice+' sin dispositivo</div>':'')+
                    (Number(x.push_failed_count||0)?'<div class="small text-danger">'+Number(x.push_failed_count)+' fallidos</div>':'')+'</td>'+
                '<td class="text-right cn-history-actions"><button class="btn btn-outline-primary btn-sm cn-detail" data-id="'+Number(x.id)+'"><i class="fas fa-eye mr-1"></i>Detalle</button></td>'+
            '</tr>';
        });
        $('#cn-history').html(html);
    }

    function stateBadge(state){
        if(state==='sent')return '<span class="badge badge-success ed-badge"><i class="fas fa-check mr-1"></i>Entregado</span>';
        if(state==='failed')return '<span class="badge badge-danger ed-badge"><i class="fas fa-times mr-1"></i>Fallido</span>';
        if(state==='no_device')return '<span class="badge badge-secondary ed-badge"><i class="fas fa-mobile-alt mr-1"></i>Sin dispositivo</span>';
        return '<span class="badge badge-warning ed-badge"><i class="fas fa-clock mr-1"></i>Pendiente</span>';
    }

    function renderDetailRows(){
        const q=$.trim($('#cn-detail-search').val()).toLowerCase();
        const rows=detailRows.filter(r=>!q||[r.student_name,r.dni,r.level,r.grade,r.section].join(' ').toLowerCase().includes(q));
        if(!rows.length){
            $('#cn-detail-body').html('<tr class="ed-empty-row"><td colspan="6">No hay estudiantes para mostrar.</td></tr>');
            return;
        }
        let html='';
        rows.forEach(r=>{
            html+='<tr>'+
                '<td><strong>'+esc(r.student_name)+'</strong><div class="small text-muted">'+esc(r.dni||'')+'</div></td>'+
                '<td>'+esc(r.level+' · '+r.grade+' '+r.section)+'</td>'+
                '<td><strong>'+Number(r.debt_count||0)+'</strong><div class="small text-muted">'+esc((r.concepts||[]).slice(0,2).join(', '))+((r.concepts||[]).length>2?'…':'')+'</div></td>'+
                '<td class="text-right font-weight-bold text-danger cn-money">'+money(r.balance)+'</td>'+
                '<td>'+stateBadge(r.state)+'</td>'+
                '<td>'+(r.is_read?'<span class="text-success small"><i class="fas fa-check-double mr-1"></i>Leído</span>':'<span class="text-muted small">No leído</span>')+'</td>'+
            '</tr>';
        });
        $('#cn-detail-body').html(html);
    }

    function openDetail(id){
        $('#cn-detail-content').addClass('d-none');
        $('#cn-detail-loading').removeClass('d-none').html('<i class="fas fa-spinner fa-spin mr-1"></i>Cargando detalle...');
        $('#cn-detail-search').val('');
        $('#cn-detail-modal').modal('show');

        $.getJSON(api,{action:'detail',id:id}).done(r=>{
            if(!r||Number(r.status)!==1){
                $('#cn-detail-loading').html('<span class="text-danger">'+esc((r&&r.message)||'No se pudo cargar el detalle.')+'</span>');
                return;
            }
            const c=r.campaign||{},counts=r.counts||{};
            detailRows=r.recipients||[];
            $('#cn-detail-audience').text(c.audience||'—');
            $('#cn-detail-states').text((c.states||[]).join(', ')||'—');
            $('#cn-detail-balance').text(money(c.total_balance));
            $('#cn-detail-author').text((c.created_by_name||'Administración')+' · '+dateText(c.created_at));
            $('#cn-detail-total').text(Number(c.recipient_count||0));
            $('#cn-detail-sent').text(Number(counts.sent||0));
            $('#cn-detail-no-device').text(Number(counts.no_device||0));
            $('#cn-detail-read').text(Number(counts.read||0));
            renderDetailRows();
            $('#cn-detail-loading').addClass('d-none');
            $('#cn-detail-content').removeClass('d-none');
        }).fail(x=>$('#cn-detail-loading').html('<span class="text-danger">'+esc((x.responseJSON||{}).message||'No se pudo cargar el detalle.')+'</span>'));
    }

    function resetCompose(){
        clearInline();
        $('#cn-audience').val('all');
        $('#cn-level,#cn-grade,#cn-section').val('');
        $('#cn-student').val('').trigger('change');
        $('#cn-overdue,#cn-partial').prop('checked',true);
        $('#cn-upcoming').prop('checked',false);
        refreshGrades();
        refreshSections();
        syncStateCards();
        updateAudience();
    }

    async function confirmSend(){
        const error=validate();
        if(error){ inline(error,'warning'); return; }
        if(!preview||Number(preview.recipient_count||0)<=0){ inline('No hay estudiantes con saldo pendiente para esta selección.','warning'); return; }

        const noDevice=Number(preview.students_without_devices||0);
        const message='<div class="text-left">'+
            '<p class="mb-2">Se enviará una notificación de cobranza basada en el saldo actual.</p>'+
            '<div class="border rounded p-2 bg-light">'+
                '<div><strong>'+Number(preview.recipient_count||0)+'</strong> estudiante(s)</div>'+
                '<div><strong>'+Number(preview.debt_count||0)+'</strong> obligación(es)</div>'+
                '<div><strong>'+money(preview.total_balance)+'</strong> de saldo comunicado</div>'+
                (noDevice?'<div class="text-muted">'+noDevice+' estudiante(s) sin dispositivo registrado.</div>':'')+
            '</div></div>';

        let accepted=true;
        if(window.EduSyncFeedback&&EduSyncFeedback.confirm){
            accepted=await EduSyncFeedback.confirm(message,{
                type:'info',
                title:'Enviar notificación de cobranza',
                confirmText:'Enviar ahora',
                cancelText:'Cancelar',
                html:true
            });
        }else{
            accepted=window.confirm('¿Enviar la notificación de cobranza ahora?');
        }
        if(!accepted)return;

        const btn=document.getElementById('cn-send');
        if(window.EduSyncFeedback&&EduSyncFeedback.setButtonLoading) EduSyncFeedback.setButtonLoading(btn,true,'Enviando...');
        else $(btn).prop('disabled',true);

        $.post(api,$.extend({action:'send',csrf_token:csrf},payload()),null,'json').done(r=>{
            if(!r||Number(r.status)!==1){ inline((r&&r.message)||'No se pudo enviar la cobranza.','danger'); return; }
            $('#cn-compose-modal').modal('hide');
            const missing=Number(r.students_without_devices||0);
            let msg='Cobranza enviada a '+Number(r.recipients||0)+' estudiante(s). '+Number(r.push_sent||0)+' push enviados.';
            if(missing)msg+=' '+missing+' sin dispositivo registrado.';
            if(Number(r.push_failed||0))msg+=' '+Number(r.push_failed)+' push fallidos.';
            toast(msg,(missing||Number(r.push_failed||0))?'warning':'success');
            loadSummary();
            loadHistory();
        }).fail(x=>inline((x.responseJSON||{}).message||'No se pudo enviar la cobranza.','danger'))
          .always(()=>{
              if(window.EduSyncFeedback&&EduSyncFeedback.setButtonLoading) EduSyncFeedback.setButtonLoading(btn,false);
              else $(btn).prop('disabled',false);
          });
    }

    $('.cn-audience-option').on('click',function(){
        $('#cn-audience').val($(this).data('type'));
        updateAudience();
    });
    $('.cn-debt-option').on('click',function(e){
        if($(e.target).is('input,label'))return;
        const cb=$(this).find('.cn-state');
        cb.prop('checked',!cb.prop('checked')).trigger('change');
    });
    $('.cn-state').on('change',function(){syncStateCards();schedulePreview();});
    $('#cn-level').on('change',function(){refreshGrades();refreshSections();schedulePreview();});
    $('#cn-grade').on('change',function(){refreshSections();schedulePreview();});
    $('#cn-section,#cn-student').on('change',schedulePreview);
    $('#cn-search').on('input',renderHistory);
    $('#cn-detail-search').on('input',renderDetailRows);

    $('#cn-new').on('click',function(){
        resetCompose();
        $('#cn-compose-modal').modal('show');
    });
    $('#cn-send').on('click',confirmSend);
    $('#cn-refresh').on('click',function(){loadSummary();loadHistory();});
    $(document).on('click','.cn-detail',function(){openDetail(Number($(this).data('id')||0));});

    if(ready){
        loadOptions().always(()=>{
            resetCompose();
        });
        loadSummary();
        loadHistory();
    }
})(jQuery);
</script>
