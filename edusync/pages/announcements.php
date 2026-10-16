<?php
include_once __DIR__ . '/../db_connect.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$ready = false;
$q = $conn->query("SHOW TABLES LIKE 'student_announcements'");
$ready = $q && $q->num_rows > 0;
// No confundir tablas de Comunicados con dependencias del Centro de Notificaciones.
$teacherRequiredTables=[
    'teacher_announcements','teacher_announcement_recipients',
    'notification_events','notification_user_state'
];
$teacherMissingTables=[];
foreach($teacherRequiredTables as $teacherTable){
    $safeTable=$conn->real_escape_string($teacherTable);
    $exists=$conn->query("SHOW TABLES LIKE '$safeTable'");
    if(!$exists || $exists->num_rows===0)$teacherMissingTables[]=$teacherTable;
}
$teacherReady=!$teacherMissingTables;
?>
<style>
/* Un mismo patrón visual para estudiantes y docentes: EduSync standard-v2. */
.an-audience-filter{margin-bottom:1rem}
.an-mode-bar{gap:.5rem;min-height:36px}
.an-mode-label{font-size:.77rem;font-weight:600;color:var(--edu-muted,#6e7891)}
.an-mode-switch{display:inline-flex;align-items:stretch;flex-wrap:nowrap;gap:.2rem;max-width:100%}
.an-mode-tab{
    appearance:none!important;background:transparent!important;border:0!important;
    border-bottom:2px solid transparent!important;border-radius:0!important;
    color:var(--edu-muted,#6e7891)!important;
    display:inline-flex;align-items:center;justify-content:center;gap:.35rem;
    cursor:pointer;min-height:34px;padding:.38rem .85rem!important;
    font-size:.81rem;font-weight:600;line-height:1.25;
    box-shadow:none!important;transition:color .15s,border-color .15s
}
.an-mode-tab:hover{color:var(--edu-primary,#4e73df)!important}
.an-mode-tab.is-active{
    background:transparent!important;color:var(--edu-primary,#4e73df)!important;
    border-bottom-color:var(--edu-primary,#4e73df)!important;font-weight:700
}
.an-mode-tab:focus-visible{
    outline:2px solid var(--edu-primary,#4e73df)!important;
    outline-offset:-2px;border-radius:.2rem!important
}
.an-mode-tab i{font-size:.82rem;opacity:.82}
.an-audience-option{
    cursor:pointer;min-height:80px;width:100%;display:flex;flex-direction:column;
    align-items:center;justify-content:center;gap:.18rem;
    background:#fff;color:#526274;transition:background .15s,border-color .15s;
    border:1px solid #d8deea!important;border-radius:.45rem!important
}
.an-audience-option:hover{background:var(--edu-surface-soft,#f8f9fc)}
.an-audience-option.is-active{
    background:var(--edu-primary-soft,#eef4ff)!important;
    border-color:var(--edu-primary,#4e73df)!important;
    box-shadow:inset 0 0 0 1px var(--edu-primary,#4e73df)
}
.an-audience-option:focus-visible{outline:2px solid var(--edu-primary,#4e73df);outline-offset:2px}
.an-audience-option i{font-size:1rem;color:#7a8798}
.an-audience-option strong{font-size:.78rem;line-height:1.2;color:#526274}
.an-audience-option .an-option-hint{font-size:.69rem;color:#858796;line-height:1.2}
.an-audience-option.is-active i,.an-audience-option.is-active strong{color:var(--edu-primary,#4e73df)!important}
.an-compose-recipient-kind{font-size:.75rem;font-weight:600;padding:.4rem .6rem}
#an-teacher-target .ed-filter-card{margin-bottom:0!important}
#an-teacher-select-wrap label{display:block}
#an-compose-modal .select2-container{width:100%!important;max-width:100%}
#an-compose-modal .select2-container--default .select2-selection--multiple{
    min-height:38px;border-color:#d8deea!important;border-radius:.45rem!important;
    box-shadow:none!important;padding:.1rem .25rem
}
#an-compose-modal .select2-container--default .select2-selection--multiple .select2-selection__choice{
    background:var(--edu-primary-soft,#eef4ff);color:#36589b;
    border-color:#b8cafa;border-radius:.3rem;font-size:.76rem;margin-top:4px
}
#an-compose-modal .select2-container--default.select2-container--focus .select2-selection--multiple{
    border-color:var(--edu-primary,#4e73df)!important;
    box-shadow:0 0 0 .14rem rgba(66,133,244,.13)!important
}
.an-preview-phone{max-width:340px;margin:0 auto;background:#edf0f5;border-radius:18px;padding:9px}
.an-preview-screen{min-height:260px;background:#f8f9fc;border-radius:12px;padding:15px}
.an-preview-push{background:#fff;border:1px solid #edf0f5;border-radius:.6rem;padding:.8rem;box-shadow:0 .125rem .5rem rgba(31,45,61,.06)}
.an-preview-logo{width:26px;height:26px;border-radius:7px;background:var(--edu-primary,#4e73df);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700}
.an-preview-body{font-size:.78rem;color:#6e7891;line-height:1.4;overflow-wrap:anywhere}
.an-message-preview{white-space:pre-line;overflow-wrap:anywhere}
#an-history td,#an-detail-body td{vertical-align:middle}
#an-history .badge,#an-detail-body .badge{font-size:.72rem;font-weight:600}
#an-history td:nth-child(2){max-width:430px;overflow-wrap:anywhere}
#an-teacher-recipient-help{line-height:1.4}
@media(max-width:767.98px){
    .an-audience-option{min-height:70px}
    .an-history-actions{white-space:normal!important}
    .an-mode-label{flex-basis:100%}
    .an-mode-switch{width:100%}
    .an-mode-switch .an-mode-tab{flex:1 1 0;min-width:0}
    #an-compose-modal .modal-body{padding:1rem}
    #an-teacher-target .ed-filter-card{padding:.7rem!important}
}
</style>

<div class="container-fluid py-3">
    <div class="ed-page-header">
        <div class="ed-page-heading">
            <div class="ed-page-icon"><i class="fas fa-bullhorn"></i></div>
            <div>
                <h1 class="ed-page-title">Comunicados institucionales</h1>
                <p class="ed-page-subtitle">Crea comunicados institucionales para estudiantes y docentes desde un solo lugar.</p>
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

    <div class="ed-filter-card an-audience-filter">
        <div class="an-mode-bar d-flex flex-wrap align-items-center" aria-label="Filtro por destinatario">
            <span class="an-mode-label mr-2">Ver comunicados de</span>
            <div class="an-mode-switch" role="tablist" aria-label="Destinatarios de comunicados">
                <button class="an-mode an-mode-tab is-active" data-kind="students" type="button" role="tab" aria-selected="true" aria-controls="an-history"><i class="fas fa-user-graduate" aria-hidden="true"></i>Estudiantes</button>
                <button class="an-mode an-mode-tab" data-kind="teachers" type="button" role="tab" aria-selected="false" aria-controls="an-history"><i class="fas fa-chalkboard-teacher" aria-hidden="true"></i>Docentes</button>
            </div>
            <span class="sr-only" id="an-current-mode-help" aria-live="polite">Historial de comunicados a estudiantes</span>
        </div>
    </div>
    <div id="an-teacher-migration" class="alert alert-warning d-none" role="status" aria-live="polite">
        <div class="d-flex flex-wrap align-items-center justify-content-between">
            <span id="an-teacher-migration-message">
                Revisando las tablas de Comunicados docentes...
            </span>
            <button type="button" id="an-teacher-recheck" class="btn btn-sm btn-outline-warning ml-2 mt-1">
                <i class="fas fa-sync-alt mr-1"></i>Volver a comprobar
            </button>
        </div>
    </div>
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
                <div class="ed-stat-meta" id="an-recipients-description">Estudiantes destinatarios este mes</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-md-0">
            <div class="ed-stat-card">
                <div class="ed-stat-label" id="an-stat-sent-label">Push enviados</div>
                <strong class="ed-stat-value text-success" id="an-stat-sent">—</strong>
                <div class="ed-stat-meta" id="an-stat-sent-meta">Entregas registradas este mes</div>
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
            <div class="modal-body ed-modal-form">
                <div id="an-compose-feedback"></div>
                <div class="row">
                    <div class="col-lg-7">
                        <div class="ed-modal-section mb-3">
                            <div class="ed-modal-section-title mb-2">1. Contenido</div>
                            <div class="form-group">
                                <label class="small font-weight-bold">Título</label>
                                <input id="an-title" class="form-control" maxlength="180" placeholder="Ej.: Reunión institucional">
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
                            <div class="mb-3">
                                <span class="badge badge-light border an-compose-recipient-kind" aria-live="polite">
                                    <i id="an-compose-kind-icon" class="fas fa-user-graduate mr-1 text-primary"></i>
                                    <span id="an-compose-kind-label">Estudiantes</span>
                                </span>
                            </div>
                            <div id="an-student-target">
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
                            </div><!-- /an-student-target -->
                            <div id="an-teacher-target" class="d-none">
                                <div class="row no-gutters mx-n1 mb-2" role="group" aria-label="Elegir destinatarios docentes">
                                    <div class="col-6 px-1 mb-2">
                                        <button type="button" class="an-audience-option an-teacher-option is-active p-2 text-center" data-scope="all" aria-pressed="true">
                                            <i class="fas fa-users"></i>
                                            <strong>Todos los docentes</strong>
                                            <span class="an-option-hint">Personal activo</span>
                                        </button>
                                    </div>
                                    <div class="col-6 px-1 mb-2">
                                        <button type="button" class="an-audience-option an-teacher-option p-2 text-center" data-scope="selected" aria-pressed="false">
                                            <i class="fas fa-user-check"></i>
                                            <strong>Seleccionar docentes</strong>
                                            <span class="an-option-hint">Envío individual</span>
                                        </button>
                                    </div>
                                </div>
                                <input type="hidden" id="an-teacher-scope" value="all">
                                <div id="an-teacher-select-wrap" class="form-group d-none">
                                    <label class="small font-weight-bold" for="an-teacher-select">Buscar docentes destinatarios</label>
                                    <select id="an-teacher-select" class="form-control" multiple="multiple" style="width:100%" aria-describedby="an-teacher-select-help"></select>
                                    <small id="an-teacher-select-help" class="text-muted d-block mt-1">
                                        Puedes elegir varios docentes. Solo aparecerán los activos de tu colegio.
                                    </small>
                                </div>
                                <div class="ed-filter-card mb-0">
                                    <div class="d-flex align-items-center justify-content-between" style="gap:.6rem">
                                        <div class="d-flex align-items-center" style="gap:.6rem;min-width:0">
                                            <i class="fas fa-paper-plane text-primary" aria-hidden="true"></i>
                                            <div>
                                                <strong id="an-teacher-recipient-label">Todos los docentes</strong>
                                                <div class="small text-muted" id="an-teacher-recipient-help">
                                                    Disponible en la bandeja web y en la app docente.
                                                </div>
                                            </div>
                                        </div>
                                        <span class="badge badge-primary ed-badge px-2 py-1" id="an-teacher-recipient-count" aria-label="Cantidad de docentes">0</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5 mt-3 mt-lg-0">
                        <div class="ed-modal-section">
                            <div class="ed-modal-section-title mb-2">Vista previa <small class="text-muted font-weight-normal ml-2" id="an-preview-audience">Estudiantes</small></div>
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
                                        <i class="fas fa-info-circle mr-1"></i>El destinatario puede abrir el comunicado completo.
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-light border small mt-3 mb-0">
                                <i class="fas fa-info-circle text-primary mr-1"></i>
                                El comunicado queda guardado en la bandeja del destinatario. Las alertas push se procesan por Firebase.
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
                    <div class="small text-muted mt-1">Contenido, destinatarios y estado de entrega.</div>
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
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label" id="an-detail-sent-heading">Entregados</div><strong class="ed-stat-value text-success" id="an-detail-sent">0</strong></div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Sin dispositivo</div><strong class="ed-stat-value text-muted" id="an-detail-no-device">0</strong></div></div>
                        <div class="col-6 col-md-3 mb-2"><div class="ed-stat-card shadow-none"><div class="ed-stat-label">Leídos</div><strong class="ed-stat-value text-info" id="an-detail-read">0</strong></div></div>
                    </div>

                    <div class="ed-toolbar justify-content-end mb-2">
                        <div class="input-group input-group-sm" style="max-width:260px">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                            <input id="an-detail-search" class="form-control" placeholder="Buscar destinatario...">
                        </div>
                    </div>
                    <div class="table-responsive ed-table-responsive">
                        <table class="table table-hover table-sm ed-table mb-0">
                            <thead><tr><th id="an-detail-name-heading">Estudiante</th><th id="an-detail-context-heading">Aula</th><th>Entrega</th><th>Lectura</th></tr></thead>
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
    const teacherApi='teacher_announcements_api.php';
    let teacherReady=<?php echo $teacherReady ? 'true' : 'false'; ?>;
    let teacherMissing=<?php echo json_encode($teacherMissingTables,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS); ?>;
    const csrf=<?php echo json_encode($csrf); ?>;
    const ready=<?php echo $ready ? 'true' : 'false'; ?>;

    let options={levels:[],grades:[],sections:[],students:[]};
    let history=[];
    let detailRows=[];
    let mode='students',teacherOptions=[],teacherLoaded=false;
    let teacherRequestKey='';
    function newRequestKey(){
        let parts=[];
        if(window.crypto && window.crypto.getRandomValues){
            const bytes=new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            parts=Array.from(bytes,x=>x.toString(16).padStart(2,'0'));
            return parts.join('');
        }
        return Array.from({length:32},()=>Math.floor(Math.random()*16).toString(16)).join('');
    }
    function teacherSelectedRows(){
        if($('#an-teacher-scope').val()==='all')return teacherOptions;
        const ids=new Set(($('#an-teacher-select').val()||[]).map(String));
        return teacherOptions.filter(t=>ids.has(String(t.id)));
    }
    function checkTeacherSchema(){
        $('#an-teacher-recheck').prop('disabled',true);
        return $.getJSON(teacherApi,{action:'health',ts:Date.now()})
          .done(r=>{
            if(!r||Number(r.status)!==1){
                $('#an-teacher-migration-message').text('No se pudo comprobar la base de datos.');
                return;
            }
            teacherReady=r.ready===true;
            teacherMissing=r.missing_tables||[];
            $('#an-teacher-migration-message').text(r.message||
                (teacherReady?'Tablas de comunicados disponibles.':'Faltan tablas del módulo.'));
            $('#an-teacher-migration').toggleClass('d-none',mode!=='teachers'||teacherReady);
            $('#an-new').prop('disabled',mode==='teachers'?!teacherReady:!ready);
            if(teacherReady&&mode==='teachers'){
                loadTeachers();
                loadSummary();
                loadHistory();
            }
          }).fail(xhr=>{
            teacherReady=false;
            const result=xhr.responseJSON||{};
            $('#an-teacher-migration-message').text(
                result.message||'No se pudo verificar la base de datos. Revisa la sesión y el servidor.'
            );
            $('#an-teacher-migration').toggleClass('d-none',mode!=='teachers');
            $('#an-new').prop('disabled',mode==='teachers'||!ready);
          }).always(()=>$('#an-teacher-recheck').prop('disabled',false));
    }
    function loadTeachers(){
        if(!teacherReady)return;
        if(teacherLoaded)return $.Deferred().resolve().promise();
        return $.getJSON(teacherApi,{action:'options'}).done(r=>{
            if(Number(r.status)!==1){toast(r.message||'No se encontraron docentes.','danger');return;}
            teacherOptions=r.teachers||[];
            teacherLoaded=true;
            const $sel=$('#an-teacher-select');
            $sel.empty();
            teacherOptions.forEach(t=>$sel.append(new Option(t.name,String(t.id))));
            if($.fn.select2){
                if($sel.hasClass('select2-hidden-accessible'))$sel.select2('destroy');
                $sel.select2({width:'100%',dropdownParent:$('#an-compose-modal'),
                    placeholder:'Buscar docentes por nombre',closeOnSelect:false});
            }
            updateAudienceSummary();
        }).fail(x=>toast((x.responseJSON||{}).message||'No se pudieron cargar los docentes.','danger'));
    }
    function setMode(next){
        if(next!=='students'&&next!=='teachers')return;
        mode=next;
        const isTeacher=mode==='teachers';
        $('.an-mode').removeClass('is-active').attr('aria-selected','false')
            .filter('[data-kind="'+mode+'"]').addClass('is-active')
            .attr('aria-selected','true');
        $('#an-compose-kind-label,#an-preview-audience').text(isTeacher?'Docentes':'Estudiantes');
        $('#an-compose-kind-icon').toggleClass('fa-chalkboard-teacher',isTeacher)
            .toggleClass('fa-user-graduate',!isTeacher);
        $('#an-stat-sent-label').text(isTeacher?'Push aceptados':'Push enviados');
        $('#an-stat-sent-meta').text(isTeacher?'Aceptaciones FCM este mes':'Entregas registradas este mes');
        $('#an-student-target').toggleClass('d-none',isTeacher);
        $('#an-teacher-target').toggleClass('d-none',!isTeacher);
        $('#an-teacher-migration').toggleClass('d-none',!isTeacher||teacherReady);
        $('#an-current-mode-help').text('Historial de comunicados a '+(isTeacher?'docentes':'estudiantes'));
        $('#an-recipients-description').text((isTeacher?'Docentes':'Estudiantes')+' destinatarios este mes');
        $('#an-new').prop('disabled',isTeacher?!teacherReady:!ready);
        if(isTeacher)checkTeacherSchema();
        updateAudienceSummary();updatePreview();
        loadSummary();loadHistory();
    }
    function refreshTeacherTarget(){
        const selected=$('#an-teacher-scope').val()==='selected';
        $('#an-teacher-select-wrap').toggleClass('d-none',!selected);
        $('.an-teacher-option').removeClass('is-active').attr('aria-pressed','false')
            .filter('[data-scope="'+(selected?'selected':'all')+'"]')
            .addClass('is-active').attr('aria-pressed','true');
        $('#an-teacher-recipient-label').text(selected?'Docentes seleccionados':'Todos los docentes');
        $('#an-teacher-recipient-help').text(
            selected?'Solo los docentes elegidos recibirán este comunicado.':
                'Disponible en la bandeja web y en la app docente.');
        const count=teacherSelectedRows().length;
        $('#an-teacher-recipient-count').text(count).attr('aria-label',count+' docentes destinatarios');
        updateAudienceSummary();
    }

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
        if(mode==='teachers')return teacherSelectedRows();
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
        if(mode==='teachers')return $('#an-teacher-scope').val()==='selected'?'Docentes seleccionados':'Todos los docentes';
        const type=$('#an-audience').val()||'all',level=$('#an-level').val(),grade=$('#an-grade').val(),section=$('#an-section').val();
        if(type==='all')return 'Todo el colegio';
        if(type==='level')return level||'Nivel sin seleccionar';
        if(type==='grade')return (level&&grade)?level+' · '+grade:'Grado sin seleccionar';
        if(type==='section')return (level&&grade&&section)?level+' · '+grade+' '+section:'Sección sin seleccionar';
        const row=recipientRows()[0]; return row?row.name:'Estudiante sin seleccionar';
    }

    function updateAudienceUI(){
        const type=$('#an-audience').val()||'all';
        $('.an-audience-option[data-type]').removeClass('is-active').attr('aria-pressed','false')
            .filter('[data-type="'+type+'"]').addClass('is-active').attr('aria-pressed','true');
        $('#an-level-wrap').toggleClass('d-none',!['level','grade','section'].includes(type));
        $('#an-grade-wrap').toggleClass('d-none',!['grade','section'].includes(type));
        $('#an-section-wrap').toggleClass('d-none',type!=='section');
        $('#an-student-wrap').toggleClass('d-none',type!=='student');
        updateAudienceSummary();
    }

    function updateAudienceSummary(){
        if(mode==='teachers'){
            $('#an-teacher-recipient-count').text(teacherSelectedRows().length);
            return;
        }
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
        if(mode==='teachers'&&!teacherReady)return 'Ejecuta sql/teacher_announcements_v1.sql antes de enviar.';
        const title=$.trim($('#an-title').val()),content=$.trim($('#an-content').val()),type=$('#an-audience').val()||'all';
        if(title.length<3)return 'Escribe un título de al menos 3 caracteres.';
        if(content.length<3)return 'Escribe el contenido del comunicado.';
        if(mode==='teachers'){
            if(recipientRows().length<1)return 'Selecciona por lo menos un docente activo.';
            return '';
        }
        if(type==='level'&&!$('#an-level').val())return 'Selecciona el nivel.';
        if(type==='grade'&&(!$('#an-level').val()||!$('#an-grade').val()))return 'Selecciona nivel y grado.';
        if(type==='section'&&(!$('#an-level').val()||!$('#an-grade').val()||!$('#an-section').val()))return 'Selecciona nivel, grado y sección.';
        if(type==='student'&&!$('#an-student').val())return 'Selecciona el estudiante.';
        if(recipientRows().length<=0)return 'No hay estudiantes activos para la selección.';
        return '';
    }

    function resetCompose(){
        clearInline();
        teacherRequestKey=newRequestKey();
        $('#an-teacher-scope').val('all');
        $('#an-teacher-select').val(null).trigger('change');
        refreshTeacherTarget();
        $('#an-title,#an-content').val('');
        $('#an-audience').val('all');
        $('#an-level,#an-grade,#an-section').val('');
        $('#an-student').val('').trigger('change');
        refreshGrades();refreshSections();updateAudienceUI();updatePreview();
    }

    function loadSummary(){
        if(mode==='students'&&!ready || mode==='teachers'&&!teacherReady){
            $('#an-stat-month,#an-stat-recipients,#an-stat-sent,#an-stat-failed').text('—');
            return;
        }
        const current=mode,source=current==='teachers'?teacherApi:api;
        $.getJSON(source,{action:'summary'}).done(r=>{
            if(current!==mode)return;
            const s=(r&&r.summary)||{};
            $('#an-stat-month').text(Number(s.month_total||0));
            $('#an-stat-recipients').text(Number(s.month_recipients||0));
            $('#an-stat-sent').text(Number(s.month_push_sent||0));
            $('#an-stat-failed').text(Number(s.month_push_failed||0));
        });
    }

    function loadHistory(){
        if(mode==='students'&&!ready || mode==='teachers'&&!teacherReady){
            history=[];
            $('#an-history').html('<tr class="ed-empty-row"><td colspan="5">Activa la base de comunicados de este perfil para ver el historial.</td></tr>');
            return;
        }
        const current=mode,source=current==='teachers'?teacherApi:api;
        $('#an-history').html('<tr class="ed-empty-row"><td colspan="5"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</td></tr>');
        $.getJSON(source,{action:'list'}).done(r=>{if(current!==mode)return;history=(r&&r.items)||[];renderHistory();})
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
            const isTeacher=mode==='teachers';
            const noDevice=Math.max(0,Number(x.recipient_count||0)-Math.min(Number(x.recipient_count||0),Number(x.push_sent_count||0)));
            html+='<tr>'+
                '<td><strong>'+esc(dateText(x.created_at))+'</strong><div class="small text-muted">'+esc(x.created_by_name)+'</div></td>'+
                '<td><strong>'+esc(x.title)+'</strong><div class="small text-muted text-truncate" style="max-width:440px">'+esc(x.content)+'</div></td>'+
                '<td><span class="badge badge-light border ed-badge">'+esc(x.audience)+'</span><div class="small text-muted mt-1">'+Number(x.recipient_count||0)+' '+(isTeacher?'docente(s)':'estudiante(s)')+'</div></td>'+
                '<td><span class="badge badge-success ed-badge">'+Number(x.push_sent_count||0)+' enviados</span>'+
                    (Number(x.push_failed_count||0)?'<div class="small text-danger mt-1">'+Number(x.push_failed_count)+' fallidos</div>':'')+'</td>'+
                '<td class="text-right an-history-actions"><button class="btn btn-outline-primary btn-sm an-detail" data-id="'+Number(x.id)+'"><i class="fas fa-eye mr-1"></i>Detalle</button></td>'+
            '</tr>';
        });
        $('#an-history').html(html);
    }

    function stateBadge(state){
        if(state==='sent')return '<span class="badge badge-success ed-badge"><i class="fas fa-check mr-1"></i>'+(mode==='teachers'?'Aceptado FCM':'Entregado')+'</span>';
        if(state==='failed')return '<span class="badge badge-danger ed-badge"><i class="fas fa-times mr-1"></i>Fallido</span>';
        if(state==='no_device')return '<span class="badge badge-secondary ed-badge"><i class="fas fa-mobile-alt mr-1"></i>Sin dispositivo</span>';
        return '<span class="badge badge-warning ed-badge"><i class="fas fa-clock mr-1"></i>Pendiente</span>';
    }

    function renderDetail(){
        const isTeacher=mode==='teachers';
        const q=$.trim($('#an-detail-search').val()).toLowerCase();
        const rows=detailRows.filter(r=>!q||[r.student_name,r.teacher_name,r.dni,r.level,r.grade,r.section].join(' ').toLowerCase().includes(q));
        if(!rows.length){
            $('#an-detail-body').html('<tr class="ed-empty-row"><td colspan="4">No hay destinatarios para mostrar.</td></tr>');
            return;
        }
        let html='';
        rows.forEach(r=>{
            html+='<tr><td><strong>'+esc(isTeacher?r.teacher_name:r.student_name)+'</strong><div class="small text-muted">'+esc(isTeacher?'Docente':r.dni||'')+'</div></td>'+
                '<td>'+(isTeacher?'<span class="text-primary small"><i class="fas fa-inbox mr-1"></i>Registrado</span>':esc(r.level+' · '+r.grade+' '+r.section))+'</td><td>'+stateBadge(r.state)+'</td>'+
                '<td>'+(r.is_read?'<span class="text-success small"><i class="fas fa-check-double mr-1"></i>Leído</span>':'<span class="text-muted small">No leído</span>')+'</td></tr>';
        });
        $('#an-detail-body').html(html);
    }

    function openDetail(id){
        const current=mode,source=current==='teachers'?teacherApi:api;
        $('#an-detail-name-heading').text(current==='teachers'?'Docente':'Estudiante');
        $('#an-detail-context-heading').text(current==='teachers'?'Bandeja':'Aula');
        $('#an-detail-sent-heading').text(current==='teachers'?'Aceptados FCM':'Entregados');
        $('#an-detail-content').addClass('d-none');
        $('#an-detail-loading').removeClass('d-none').html('<i class="fas fa-spinner fa-spin mr-1"></i>Cargando...');
        $('#an-detail-search').val('');
        $('#an-detail-modal').modal('show');
        $.getJSON(source,{action:'detail',id:id}).done(r=>{
            if(current!==mode)return;
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
        const current=mode;
        const error=validate();
        if(error){inline(error,'warning');return;}

        const count=recipientRows().length;
        const message='<div class="text-left"><p class="mb-2">El comunicado se enviará inmediatamente y quedará disponible en la app EduSync.</p>'+
            '<div class="border rounded p-2 bg-light"><div><strong>'+esc($.trim($('#an-title').val()))+'</strong></div>'+
            '<div class="small text-muted mt-1">'+esc(audienceLabel())+' · '+count+' '+(current==='teachers'?'docente(s)':'estudiante(s)')+'</div></div>'+
            '<div class="small text-muted mt-2">'+(current==='teachers'?'Quedará registrado en la bandeja docente; el envío push se procesará por Firebase.':'Se notificará mediante la app EduSync.')+'</div></div>';

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
        const source=current==='teachers'?teacherApi:api;
        if(current==='teachers'){
            data.request_key=teacherRequestKey;
            data.audience_type=$('#an-teacher-scope').val()||'all';
            data['teacher_ids[]']=($('#an-teacher-select').val()||[]);
        }
        $.post(source,data,null,'json').done(r=>{
            if(!r||Number(r.status)!==1){inline((r&&r.message)||'No se pudo enviar el comunicado.','danger');return;}
            $('#an-compose-modal').modal('hide');
            const missing=current==='teachers'?0:Math.max(0,Number(r.recipients||0)-Number(r.students_with_devices||0));
            let msg=current==='teachers'
              ? 'Comunicado registrado para '+Number(r.recipients||0)+' docente(s). Avisos en bandeja; Firebase procesará las entregas.'
              : 'Comunicado enviado a '+Number(r.recipients||0)+' estudiante(s). '+Number(r.push_sent||0)+' push enviados.';
            if(missing)msg+=' '+missing+' sin dispositivo registrado.';
            if(Number(r.push_failed||0))msg+=' '+Number(r.push_failed)+' push fallidos.';
            toast(msg,(missing||Number(r.push_failed||0))?'warning':'success');
            teacherRequestKey=newRequestKey();
            loadSummary();loadHistory();
        }).fail(x=>inline((x.responseJSON||{}).message||'No se pudo enviar el comunicado.','danger'))
          .always(()=>{
              if(window.EduSyncFeedback&&EduSyncFeedback.setButtonLoading)EduSyncFeedback.setButtonLoading(btn,false);
              else $(btn).prop('disabled',false);
          });
    }

    $('.an-mode').on('click',function(){setMode(String($(this).data('kind')));});
    $('.an-teacher-option').on('click',function(){
        $('#an-teacher-scope').val(String($(this).data('scope')));
        refreshTeacherTarget();
    });
    $('#an-teacher-recheck').on('click',checkTeacherSchema);
    $('#an-teacher-select').on('change',refreshTeacherTarget);
    $('.an-audience-option[data-type]').on('click',function(){
        $('#an-audience').val($(this).data('type'));updateAudienceUI();
    }).attr('role','button').attr('tabindex','0').on('keydown',function(e){
        if(e.key==='Enter'||e.key===' '){e.preventDefault();$(this).trigger('click');}
    });
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
