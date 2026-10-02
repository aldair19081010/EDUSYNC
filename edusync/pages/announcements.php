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
.ca-page{--ca-primary:#3157d5;--ca-primary-soft:#eef2ff;--ca-text:#263754;--ca-muted:#74809a;--ca-border:#e5e9f2;--ca-bg:#f5f7fb}
.ca-hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#2f55d4 0%,#4d6fe5 62%,#6f88ef 100%);border-radius:18px;padding:26px 28px;color:#fff;box-shadow:0 10px 28px rgba(49,87,213,.18)}
.ca-hero:after{content:'';position:absolute;right:-55px;top:-70px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.09)}
.ca-hero:before{content:'';position:absolute;right:120px;bottom:-90px;width:165px;height:165px;border-radius:50%;background:rgba(255,255,255,.06)}
.ca-hero h1{font-size:1.65rem;font-weight:800;margin:0 0 5px}
.ca-hero p{margin:0;color:rgba(255,255,255,.82);max-width:720px}
.ca-hero .btn{position:relative;z-index:2;border:0;border-radius:11px;font-weight:800;padding:.68rem 1rem;box-shadow:0 6px 18px rgba(19,37,96,.16)}
.ca-metric{background:#fff;border:1px solid var(--ca-border);border-radius:15px;padding:17px 18px;height:100%;box-shadow:0 4px 14px rgba(30,45,85,.04)}
.ca-metric-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.ca-metric-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1rem}
.ca-metric-label{font-size:.72rem;color:var(--ca-muted);text-transform:uppercase;font-weight:800;letter-spacing:.35px}
.ca-metric-value{font-size:1.55rem;line-height:1;font-weight:800;color:var(--ca-text)}
.ca-metric-sub{font-size:.74rem;color:#9aa3b6;margin-top:7px}
.ca-panel{background:#fff;border:1px solid var(--ca-border);border-radius:16px;box-shadow:0 4px 16px rgba(30,45,85,.04)}
.ca-panel-head{padding:18px 20px;border-bottom:1px solid #edf0f5;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
.ca-panel-title{font-weight:800;color:var(--ca-text);font-size:1rem}
.ca-panel-sub{font-size:.76rem;color:var(--ca-muted);margin-top:2px}
.ca-search{position:relative;min-width:260px}
.ca-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#98a1b3}
.ca-search input{padding-left:36px;border-radius:10px;border-color:var(--ca-border);height:38px}
.ca-history-list{padding:8px 18px 18px}
.ca-history-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;padding:16px 4px;border-bottom:1px solid #edf0f5;align-items:center}
.ca-history-item:last-child{border-bottom:0}
.ca-history-main{min-width:0}
.ca-history-title{font-weight:800;color:var(--ca-text);font-size:.96rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ca-history-message{color:#6f7a91;font-size:.79rem;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:760px}
.ca-history-meta{display:flex;flex-wrap:wrap;gap:7px 12px;margin-top:10px;color:#8992a6;font-size:.72rem}
.ca-history-meta span{display:inline-flex;align-items:center;gap:5px}
.ca-pill{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:5px 9px;font-size:.7rem;font-weight:700;border:1px solid var(--ca-border);background:#fafbfe;color:#5f6b82}
.ca-pill-success{background:#edf9f2;border-color:#d8f0e1;color:#2b8a57}
.ca-pill-warning{background:#fff8e9;border-color:#f6e8bd;color:#b78319}
.ca-pill-danger{background:#fff0f1;border-color:#f6d9dd;color:#bf4d5c}
.ca-history-actions{text-align:right;min-width:125px}
.ca-history-actions .btn{border-radius:9px;font-weight:700}
.ca-empty{padding:56px 20px;text-align:center;color:#8b95a8}
.ca-empty-icon{width:64px;height:64px;border-radius:18px;background:#f1f4fa;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;color:#8590a5;font-size:1.35rem}
.ca-alert{border-radius:12px}
.ca-modal .modal-content{border:0;border-radius:18px;overflow:hidden;box-shadow:0 18px 60px rgba(30,45,85,.2)}
.ca-modal .modal-header{border-bottom:1px solid #edf0f5;padding:18px 22px}
.ca-modal .modal-title{font-weight:800;color:var(--ca-text)}
.ca-modal .modal-body{background:#fbfcff;padding:20px 22px}
.ca-modal .modal-footer{border-top:1px solid #edf0f5;padding:14px 22px}
.ca-section-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.4px;font-weight:800;color:#8a94a8;margin-bottom:8px}
.ca-field-card{background:#fff;border:1px solid var(--ca-border);border-radius:14px;padding:16px}
.ca-field-card .form-control{border-radius:10px;border-color:#dfe4ee}
.ca-field-card textarea{resize:vertical;min-height:145px}
.ca-audience-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
.ca-audience-option{border:1px solid #dde3ed;background:#fff;border-radius:12px;padding:12px 8px;text-align:center;cursor:pointer;transition:.15s;color:#68738a;min-height:83px;display:flex;flex-direction:column;align-items:center;justify-content:center}
.ca-audience-option:hover{border-color:#b9c5e8;background:#fafbff}
.ca-audience-option.active{background:var(--ca-primary-soft);border-color:#9eb0ed;color:var(--ca-primary);box-shadow:inset 0 0 0 1px #9eb0ed}
.ca-audience-option i{font-size:1.1rem;margin-bottom:7px}
.ca-audience-option strong{font-size:.7rem}
.ca-recipient-summary{background:#f6f8fd;border:1px solid #e2e7f2;border-radius:11px;padding:11px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.ca-recipient-summary strong{color:var(--ca-text);font-size:.82rem}
.ca-recipient-summary span{font-size:.74rem;color:var(--ca-muted)}
.ca-preview-wrap{position:sticky;top:14px}
.ca-preview-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.4px;font-weight:800;color:#8a94a8;margin-bottom:8px}
.ca-phone{background:#e9edf5;border-radius:25px;padding:12px;max-width:340px;margin:0 auto;box-shadow:0 10px 26px rgba(31,45,77,.1)}
.ca-phone-screen{background:#f7f8fb;border-radius:18px;min-height:385px;padding:17px}
.ca-phone-top{font-size:.69rem;color:#69758c;font-weight:700;display:flex;justify-content:space-between;margin-bottom:24px}
.ca-push-card{background:#fff;border-radius:15px;padding:13px 14px;box-shadow:0 5px 18px rgba(30,45,85,.08);border:1px solid #edf0f5}
.ca-push-app{display:flex;align-items:center;gap:8px;color:#68738a;font-size:.69rem;font-weight:700}
.ca-push-logo{width:26px;height:26px;border-radius:8px;background:#3157d5;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900}
.ca-push-title{font-size:.86rem;font-weight:800;color:#28364f;margin-top:9px}
.ca-push-body{font-size:.76rem;color:#69758c;line-height:1.4;margin-top:4px;white-space:pre-line}
.ca-preview-note{text-align:center;color:#8a94a8;font-size:.69rem;margin-top:13px}
.ca-confirm-box{background:#fff;border:1px solid var(--ca-border);border-radius:13px;padding:14px}
.ca-confirm-row{display:flex;justify-content:space-between;gap:15px;padding:7px 0;border-bottom:1px solid #f0f2f6}
.ca-confirm-row:last-child{border-bottom:0}
.ca-confirm-row span{color:#7a859a;font-size:.76rem}
.ca-confirm-row strong{color:var(--ca-text);font-size:.78rem;text-align:right}
.ca-detail-head{background:linear-gradient(135deg,#f7f9ff,#eef3ff);border:1px solid #dfe7fb;border-radius:14px;padding:16px}
.ca-detail-title{font-weight:800;color:var(--ca-text);font-size:1.08rem}
.ca-detail-message{white-space:pre-line;color:#59667d;font-size:.82rem;line-height:1.55;margin-top:9px}
.ca-delivery-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-top:14px}
.ca-delivery-stat{background:#fff;border:1px solid var(--ca-border);border-radius:11px;padding:10px;text-align:center}
.ca-delivery-stat strong{display:block;font-size:1.05rem;color:var(--ca-text)}
.ca-delivery-stat span{font-size:.66rem;color:#8791a4;text-transform:uppercase;font-weight:800}
.ca-delivery-table td,.ca-delivery-table th{vertical-align:middle;font-size:.75rem}
.ca-state{font-size:.67rem;font-weight:800;border-radius:999px;padding:4px 8px;display:inline-flex;align-items:center;gap:5px}
.ca-state-sent{background:#eaf8f0;color:#278552}
.ca-state-failed{background:#fff0f1;color:#bc4859}
.ca-state-no_device{background:#f3f4f7;color:#707b8d}
.ca-state-pending{background:#fff7e7;color:#a97918}
.ca-read{font-size:.67rem;color:#7c879b}
@media(max-width:991px){.ca-audience-grid{grid-template-columns:repeat(3,1fr)}.ca-preview-wrap{position:static;margin-top:16px}.ca-delivery-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:767px){.ca-hero{padding:22px 20px}.ca-hero .btn{width:100%;margin-top:15px}.ca-history-item{grid-template-columns:1fr}.ca-history-actions{text-align:left}.ca-search{min-width:100%;width:100%}.ca-audience-grid{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="container-fluid py-3 ca-page">
  <div class="ca-hero mb-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between position-relative" style="z-index:2">
      <div>
        <h1><i class="fas fa-bullhorn mr-2"></i>Comunicados institucionales</h1>
        <p>Crea, dirige y supervisa comunicaciones oficiales enviadas a los estudiantes desde EduSync.</p>
      </div>
      <button id="ca-open-compose" class="btn btn-light text-primary" <?php echo $ready ? '' : 'disabled'; ?>>
        <i class="fas fa-plus mr-1"></i>Nuevo comunicado
      </button>
    </div>
  </div>

  <?php if (!$ready): ?>
    <div class="alert alert-warning ca-alert">
      <i class="fas fa-database mr-2"></i>
      Ejecuta manualmente <strong>sql/student_announcements.sql</strong> antes de usar este módulo.
    </div>
  <?php endif; ?>

  <div id="ca-alert" class="alert ca-alert d-none"></div>

  <div class="row mb-3">
    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
      <div class="ca-metric">
        <div class="ca-metric-top">
          <div><div class="ca-metric-label">Este mes</div><div class="ca-metric-value" id="ca-stat-month">0</div></div>
          <div class="ca-metric-icon" style="background:#eef2ff;color:#3157d5"><i class="fas fa-paper-plane"></i></div>
        </div>
        <div class="ca-metric-sub">Comunicados enviados</div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
      <div class="ca-metric">
        <div class="ca-metric-top">
          <div><div class="ca-metric-label">Alcance</div><div class="ca-metric-value" id="ca-stat-recipients">0</div></div>
          <div class="ca-metric-icon" style="background:#eef8f2;color:#2c8a59"><i class="fas fa-users"></i></div>
        </div>
        <div class="ca-metric-sub">Estudiantes destinatarios este mes</div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3 mb-md-0">
      <div class="ca-metric">
        <div class="ca-metric-top">
          <div><div class="ca-metric-label">Push enviados</div><div class="ca-metric-value" id="ca-stat-sent">0</div></div>
          <div class="ca-metric-icon" style="background:#edf9f6;color:#238b78"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="ca-metric-sub">Entregas registradas este mes</div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="ca-metric">
        <div class="ca-metric-top">
          <div><div class="ca-metric-label">Incidencias</div><div class="ca-metric-value" id="ca-stat-failed">0</div></div>
          <div class="ca-metric-icon" style="background:#fff3f3;color:#c75261"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
        <div class="ca-metric-sub">Push fallidos este mes</div>
      </div>
    </div>
  </div>

  <div class="ca-panel">
    <div class="ca-panel-head">
      <div>
        <div class="ca-panel-title"><i class="fas fa-history text-primary mr-2"></i>Historial de comunicados</div>
        <div class="ca-panel-sub">Consulta alcance, entrega y lectura de cada comunicación.</div>
      </div>
      <div class="d-flex flex-wrap align-items-center" style="gap:8px">
        <div class="ca-search">
          <i class="fas fa-search"></i>
          <input id="ca-search" class="form-control form-control-sm" placeholder="Buscar comunicado...">
        </div>
        <button id="ca-refresh" class="btn btn-light border btn-sm" title="Actualizar"><i class="fas fa-sync-alt"></i></button>
        <a href="index.php?page=notifications" class="btn btn-light border btn-sm" title="Centro de notificaciones"><i class="fas fa-bell"></i></a>
      </div>
    </div>
    <div id="ca-history" class="ca-history-list">
      <div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</div>
    </div>
  </div>
</div>

<div class="modal fade ca-modal" id="ca-compose-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1"><i class="fas fa-paper-plane text-primary mr-2"></i>Nuevo comunicado</h5>
          <div class="small text-muted">Redacta el mensaje y define exactamente quién debe recibirlo.</div>
        </div>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="ca-compose-error" class="alert alert-warning d-none"></div>
        <div class="row">
          <div class="col-lg-7">
            <div class="ca-section-label">1. Contenido</div>
            <div class="ca-field-card mb-3">
              <div class="form-group">
                <label class="small font-weight-bold">Título</label>
                <input id="ca-title" class="form-control" maxlength="180" placeholder="Ej.: Reunión de padres">
                <div class="text-right small text-muted mt-1"><span id="ca-title-count">0</span>/180</div>
              </div>
              <div class="form-group mb-0">
                <label class="small font-weight-bold">Mensaje</label>
                <textarea id="ca-content" class="form-control" maxlength="5000" placeholder="Escribe el comunicado completo..."></textarea>
                <div class="text-right small text-muted mt-1"><span id="ca-content-count">0</span>/5000</div>
              </div>
            </div>

            <div class="ca-section-label">2. Destinatarios</div>
            <div class="ca-field-card">
              <div class="ca-audience-grid mb-3">
                <div class="ca-audience-option active" data-type="all"><i class="fas fa-school"></i><strong>Todo el colegio</strong></div>
                <div class="ca-audience-option" data-type="level"><i class="fas fa-layer-group"></i><strong>Nivel</strong></div>
                <div class="ca-audience-option" data-type="grade"><i class="fas fa-graduation-cap"></i><strong>Grado</strong></div>
                <div class="ca-audience-option" data-type="section"><i class="fas fa-users"></i><strong>Sección</strong></div>
                <div class="ca-audience-option" data-type="student"><i class="fas fa-user"></i><strong>Estudiante</strong></div>
              </div>
              <input type="hidden" id="ca-audience" value="all">

              <div class="row">
                <div id="ca-level-wrap" class="col-md-4 d-none">
                  <div class="form-group">
                    <label class="small font-weight-bold">Nivel</label>
                    <select id="ca-level" class="form-control"><option value="">Selecciona</option></select>
                  </div>
                </div>
                <div id="ca-grade-wrap" class="col-md-4 d-none">
                  <div class="form-group">
                    <label class="small font-weight-bold">Grado</label>
                    <select id="ca-grade" class="form-control"><option value="">Selecciona</option></select>
                  </div>
                </div>
                <div id="ca-section-wrap" class="col-md-4 d-none">
                  <div class="form-group">
                    <label class="small font-weight-bold">Sección</label>
                    <select id="ca-section" class="form-control"><option value="">Selecciona</option></select>
                  </div>
                </div>
                <div id="ca-student-wrap" class="col-12 d-none">
                  <div class="form-group">
                    <label class="small font-weight-bold">Estudiante</label>
                    <select id="ca-student" class="form-control"><option value="">Selecciona</option></select>
                  </div>
                </div>
              </div>

              <div class="ca-recipient-summary">
                <div>
                  <strong id="ca-recipient-label">Todo el colegio</strong>
                  <div><span id="ca-recipient-help">Se enviará a todos los estudiantes activos.</span></div>
                </div>
                <div class="text-right">
                  <strong id="ca-recipient-count">0</strong>
                  <div><span>estudiantes</span></div>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-5">
            <div class="ca-preview-wrap">
              <div class="ca-preview-label">Vista previa en el celular</div>
              <div class="ca-phone">
                <div class="ca-phone-screen">
                  <div class="ca-phone-top"><span>5:20</span><span><i class="fas fa-wifi mr-1"></i><i class="fas fa-battery-three-quarters"></i></span></div>
                  <div class="ca-push-card">
                    <div class="ca-push-app"><div class="ca-push-logo">E</div><span>EduSync · ahora</span></div>
                    <div id="ca-preview-title" class="ca-push-title">Comunicado institucional</div>
                    <div id="ca-preview-body" class="ca-push-body">El mensaje del comunicado aparecerá aquí.</div>
                  </div>
                  <div class="ca-preview-note">Al tocar la notificación, el estudiante verá el comunicado completo.</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-dismiss="modal">Cancelar</button>
        <button id="ca-review-send" type="button" class="btn btn-primary"><i class="fas fa-arrow-right mr-1"></i>Revisar y enviar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade ca-modal" id="ca-confirm-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-check-circle text-primary mr-2"></i>Confirmar envío</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="ca-confirm-error" class="alert alert-danger d-none"></div>
        <div class="mb-3 text-muted small">El comunicado se enviará inmediatamente y quedará disponible en la app EduSync.</div>
        <div class="ca-confirm-box">
          <div class="ca-confirm-row"><span>Título</span><strong id="ca-confirm-title">—</strong></div>
          <div class="ca-confirm-row"><span>Destinatarios</span><strong id="ca-confirm-audience">—</strong></div>
          <div class="ca-confirm-row"><span>Estudiantes</span><strong id="ca-confirm-count">0</strong></div>
        </div>
      </div>
      <div class="modal-footer">
        <button id="ca-back-compose" type="button" class="btn btn-light border">Volver</button>
        <button id="ca-confirm-send" type="button" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i>Enviar comunicado</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade ca-modal" id="ca-detail-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1"><i class="fas fa-bullhorn text-primary mr-2"></i>Detalle del comunicado</h5>
          <div class="small text-muted">Contenido, alcance y estado de entrega por estudiante.</div>
        </div>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="ca-detail-loading" class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando detalle...</div>
        <div id="ca-detail-content" class="d-none">
          <div class="ca-detail-head">
            <div class="d-flex flex-wrap justify-content-between align-items-start" style="gap:10px">
              <div>
                <div id="ca-detail-title" class="ca-detail-title"></div>
                <div id="ca-detail-meta" class="small text-muted mt-1"></div>
              </div>
              <span id="ca-detail-audience" class="ca-pill"></span>
            </div>
            <div id="ca-detail-message" class="ca-detail-message"></div>
          </div>

          <div class="ca-delivery-stats">
            <div class="ca-delivery-stat"><strong id="ca-detail-total">0</strong><span>Destinatarios</span></div>
            <div class="ca-delivery-stat"><strong id="ca-detail-sent">0</strong><span>Entregados</span></div>
            <div class="ca-delivery-stat"><strong id="ca-detail-no-device">0</strong><span>Sin dispositivo</span></div>
            <div class="ca-delivery-stat"><strong id="ca-detail-read">0</strong><span>Leídos</span></div>
          </div>

          <div id="ca-detail-warning" class="alert alert-warning mt-3 d-none"></div>

          <div class="d-flex flex-wrap align-items-center justify-content-between mt-4 mb-2" style="gap:10px">
            <div class="font-weight-bold text-gray-800">Entrega por estudiante</div>
            <div class="ca-search" style="min-width:230px">
              <i class="fas fa-search"></i>
              <input id="ca-detail-search" class="form-control form-control-sm" placeholder="Buscar estudiante...">
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-hover ca-delivery-table">
              <thead class="thead-light"><tr><th>Estudiante</th><th>Aula</th><th>Estado</th><th>Lectura</th></tr></thead>
              <tbody id="ca-delivery-body"></tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light border" data-dismiss="modal">Cerrar</button></div>
    </div>
  </div>
</div>

<script>
(function($){
  const api='announcements_api.php';
  const csrf=<?php echo json_encode($csrf); ?>;
  const ready=<?php echo $ready ? 'true' : 'false'; ?>;

  let options={levels:[],grades:[],sections:[],students:[]};
  let historyItems=[];
  let pendingSend=null;
  let currentDelivery=[];

  function esc(v){return $('<div>').text(v==null?'':v).html();}
  function showAlert(message,type){
    $('#ca-alert').removeClass('d-none alert-success alert-danger alert-warning alert-info')
      .addClass('alert-'+(type||'info')).html(message);
    $('html,body').animate({scrollTop:Math.max(0,$('#ca-alert').offset().top-90)},180);
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

  function initStudentSelect(){
    if(!$.fn.select2)return;
    const el=$('#ca-student');
    if(el.hasClass('select2-hidden-accessible'))el.select2('destroy');
    el.select2({
      width:'100%',
      dropdownParent:$('#ca-compose-modal'),
      placeholder:'Buscar estudiante',
      allowClear:true
    });
  }

  function loadOptions(){
    if(!ready)return $.Deferred().resolve().promise();
    return $.getJSON(api,{action:'options'}).done(r=>{
      if(!r||Number(r.status)!==1){showAlert((r&&r.message)||'No se pudieron cargar los destinatarios.','danger');return;}
      options=r;
      fillSelect('#ca-level',options.levels||[],'',x=>x);
      fillSelect('#ca-student',options.students||[],'id',s=>s.name+' · '+s.level+' '+s.grade+' '+s.section+(s.dni?' · '+s.dni:''));
      initStudentSelect();
      refreshGrades();
      refreshSections();
      updateAudienceSummary();
    }).fail(x=>showAlert((x.responseJSON||{}).message||'No se pudieron cargar los destinatarios.','danger'));
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

  function selectedAudience(){
    return $('#ca-audience').val()||'all';
  }

  function recipientRows(){
    const type=selectedAudience();
    const level=$('#ca-level').val(),grade=$('#ca-grade').val(),section=$('#ca-section').val();
    const studentId=Number($('#ca-student').val()||0);
    const students=options.students||[];
    if(type==='all')return students;
    if(type==='level')return students.filter(s=>s.level===level);
    if(type==='grade')return students.filter(s=>s.level===level&&String(s.grade)===String(grade));
    if(type==='section')return students.filter(s=>s.level===level&&String(s.grade)===String(grade)&&String(s.section)===String(section));
    if(type==='student')return students.filter(s=>Number(s.id)===studentId);
    return [];
  }

  function audienceLabel(){
    const type=selectedAudience();
    const level=$('#ca-level').val(),grade=$('#ca-grade').val(),section=$('#ca-section').val();
    if(type==='all')return 'Todo el colegio';
    if(type==='level')return level||'Nivel sin seleccionar';
    if(type==='grade')return (level&&grade)?level+' · '+grade:'Grado sin seleccionar';
    if(type==='section')return (level&&grade&&section)?level+' · '+grade+' '+section:'Sección sin seleccionar';
    if(type==='student'){
      const row=recipientRows()[0];
      return row?row.name:'Estudiante sin seleccionar';
    }
    return 'Destinatarios';
  }

  function updateAudienceUI(){
    const type=selectedAudience();
    $('.ca-audience-option').removeClass('active').filter('[data-type="'+type+'"]').addClass('active');
    $('#ca-level-wrap').toggleClass('d-none',!['level','grade','section'].includes(type));
    $('#ca-grade-wrap').toggleClass('d-none',!['grade','section'].includes(type));
    $('#ca-section-wrap').toggleClass('d-none',type!=='section');
    $('#ca-student-wrap').toggleClass('d-none',type!=='student');
    updateAudienceSummary();
  }

  function updateAudienceSummary(){
    const count=recipientRows().length;
    $('#ca-recipient-label').text(audienceLabel());
    $('#ca-recipient-count').text(count);
    const type=selectedAudience();
    const help={
      all:'Se enviará a todos los estudiantes activos.',
      level:'Se enviará al nivel seleccionado.',
      grade:'Se enviará al grado seleccionado.',
      section:'Se enviará únicamente a esta sección.',
      student:'Se enviará únicamente a este estudiante.'
    }[type]||'';
    $('#ca-recipient-help').text(help);
  }

  function updatePreview(){
    const title=$.trim($('#ca-title').val());
    const content=$.trim($('#ca-content').val()).replace(/\s+/g,' ');
    $('#ca-preview-title').text(title||'Comunicado institucional');
    $('#ca-preview-body').text(content?content.substring(0,180)+(content.length>180?'...':''):'El mensaje del comunicado aparecerá aquí.');
    $('#ca-title-count').text($('#ca-title').val().length);
    $('#ca-content-count').text($('#ca-content').val().length);
  }

  function resetCompose(){
    $('#ca-title,#ca-content').val('');
    $('#ca-audience').val('all');
    $('#ca-level,#ca-grade,#ca-section').val('');
    $('#ca-student').val('').trigger('change.select2');
    refreshGrades();
    refreshSections();
    updateAudienceUI();
    updatePreview();
  }

  function validateCompose(){
    const title=$.trim($('#ca-title').val());
    const content=$.trim($('#ca-content').val());
    const type=selectedAudience();
    if(title.length<3)return 'Escribe un título de al menos 3 caracteres.';
    if(content.length<3)return 'Escribe el contenido del comunicado.';
    if(type==='level'&&!$('#ca-level').val())return 'Selecciona el nivel.';
    if(type==='grade'&&(!$('#ca-level').val()||!$('#ca-grade').val()))return 'Selecciona nivel y grado.';
    if(type==='section'&&(!$('#ca-level').val()||!$('#ca-grade').val()||!$('#ca-section').val()))return 'Selecciona nivel, grado y sección.';
    if(type==='student'&&!$('#ca-student').val())return 'Selecciona un estudiante.';
    if(recipientRows().length<=0)return 'No hay estudiantes activos para los destinatarios seleccionados.';
    return '';
  }

  function buildPayload(){
    return {
      action:'send',
      csrf_token:csrf,
      title:$.trim($('#ca-title').val()),
      content:$.trim($('#ca-content').val()),
      audience_type:selectedAudience(),
      level:$('#ca-level').val()||'',
      grade:$('#ca-grade').val()||'',
      section:$('#ca-section').val()||'',
      student_id:$('#ca-student').val()||''
    };
  }

  function dateText(v){
    const m=String(v||'').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if(!m)return esc(v||'—');
    let h=Number(m[4]),suffix=h>=12?'p. m.':'a. m.';
    h=h%12||12;
    return m[3]+'/'+m[2]+'/'+m[1]+' · '+h+':'+m[5]+' '+suffix;
  }

  function loadSummary(){
    if(!ready)return;
    $.getJSON(api,{action:'summary'}).done(r=>{
      const s=(r&&r.summary)||{};
      $('#ca-stat-month').text(Number(s.month_total||0));
      $('#ca-stat-recipients').text(Number(s.month_recipients||0));
      $('#ca-stat-sent').text(Number(s.month_push_sent||0));
      $('#ca-stat-failed').text(Number(s.month_push_failed||0));
    });
  }

  function renderHistory(){
    const q=$.trim($('#ca-search').val()).toLowerCase();
    const items=historyItems.filter(item=>{
      if(!q)return true;
      return [item.title,item.content,item.audience,item.created_by_name].join(' ').toLowerCase().includes(q);
    });

    if(!items.length){
      $('#ca-history').html(
        '<div class="ca-empty"><div class="ca-empty-icon"><i class="fas fa-bullhorn"></i></div>'+
        '<div class="font-weight-bold mb-1">'+(q?'No hay coincidencias':'Aún no hay comunicados')+'</div>'+
        '<div class="small">'+(q?'Prueba con otra búsqueda.':'Crea el primer comunicado institucional desde el botón superior.')+'</div></div>'
      );
      return;
    }

    let html='';
    items.forEach(item=>{
      const failed=Number(item.push_failed_count||0);
      const sent=Number(item.push_sent_count||0);
      html+='<div class="ca-history-item" data-search="'+esc([item.title,item.content,item.audience,item.created_by_name].join(' ').toLowerCase())+'">'+
        '<div class="ca-history-main">'+
          '<div class="d-flex flex-wrap align-items-center" style="gap:8px">'+
            '<div class="ca-history-title">'+esc(item.title)+'</div>'+
            '<span class="ca-pill"><i class="fas fa-users"></i>'+esc(item.audience)+'</span>'+
          '</div>'+
          '<div class="ca-history-message">'+esc(item.content)+'</div>'+
          '<div class="ca-history-meta">'+
            '<span><i class="fas fa-user-shield"></i>'+esc(item.created_by_name)+'</span>'+
            '<span><i class="far fa-clock"></i>'+dateText(item.created_at)+'</span>'+
            '<span><i class="fas fa-user-friends"></i>'+Number(item.recipient_count||0)+' destinatarios</span>'+
            '<span class="ca-pill ca-pill-success"><i class="fas fa-check"></i>'+sent+' enviados</span>'+
            (failed>0?'<span class="ca-pill ca-pill-danger"><i class="fas fa-times"></i>'+failed+' fallidos</span>':'')+
          '</div>'+
        '</div>'+
        '<div class="ca-history-actions"><button class="btn btn-outline-primary btn-sm ca-detail-btn" data-id="'+Number(item.id)+'"><i class="fas fa-chart-pie mr-1"></i>Ver detalle</button></div>'+
      '</div>';
    });
    $('#ca-history').html(html);
  }

  function loadHistory(){
    if(!ready)return;
    $('#ca-history').html('<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</div>');
    $.getJSON(api,{action:'list'}).done(r=>{
      if(!r||Number(r.status)!==1){
        $('#ca-history').html('<div class="ca-empty text-danger">No se pudo cargar el historial.</div>');
        return;
      }
      historyItems=r.items||[];
      renderHistory();
    }).fail(()=>$('#ca-history').html('<div class="ca-empty text-danger">No se pudo cargar el historial.</div>'));
  }

  function stateBadge(state){
    const map={
      sent:['ca-state-sent','fa-check-circle','Entregado'],
      failed:['ca-state-failed','fa-times-circle','Fallido'],
      no_device:['ca-state-no_device','fa-mobile-alt','Sin dispositivo'],
      pending:['ca-state-pending','fa-clock','Pendiente']
    };
    const d=map[state]||map.pending;
    return '<span class="ca-state '+d[0]+'"><i class="fas '+d[1]+'"></i>'+d[2]+'</span>';
  }

  function renderDelivery(){
    const q=$.trim($('#ca-detail-search').val()).toLowerCase();
    const rows=currentDelivery.filter(r=>{
      if(!q)return true;
      return [r.student_name,r.dni,r.level,r.grade,r.section].join(' ').toLowerCase().includes(q);
    });
    if(!rows.length){
      $('#ca-delivery-body').html('<tr><td colspan="4" class="text-center text-muted py-4">No hay estudiantes para mostrar.</td></tr>');
      return;
    }
    let html='';
    rows.forEach(r=>{
      html+='<tr>'+
        '<td><strong>'+esc(r.student_name)+'</strong><div class="small text-muted">'+esc(r.dni||'')+'</div></td>'+
        '<td>'+esc((r.level||'')+' · '+(r.grade||'')+' '+(r.section||''))+'</td>'+
        '<td>'+stateBadge(r.state)+'</td>'+
        '<td>'+(r.is_read?'<span class="ca-read text-success"><i class="fas fa-check-double mr-1"></i>Leído</span>':'<span class="ca-read">No leído</span>')+'</td>'+
      '</tr>';
    });
    $('#ca-delivery-body').html(html);
  }

  function openDetail(id){
    $('#ca-detail-content').addClass('d-none');
    $('#ca-detail-loading').removeClass('d-none').html('<i class="fas fa-spinner fa-spin mr-1"></i>Cargando detalle...');
    $('#ca-detail-search').val('');
    $('#ca-detail-modal').modal('show');

    $.getJSON(api,{action:'detail',id:id}).done(r=>{
      if(!r||Number(r.status)!==1){
        $('#ca-detail-loading').html('<span class="text-danger">'+esc((r&&r.message)||'No se pudo cargar el detalle.')+'</span>');
        return;
      }
      const a=r.announcement||{},counts=r.counts||{};
      currentDelivery=r.delivery||[];
      $('#ca-detail-title').text(a.title||'Comunicado');
      $('#ca-detail-meta').text((a.created_by_name||'Administración')+' · '+$('<div>').html(dateText(a.created_at)).text());
      $('#ca-detail-audience').html('<i class="fas fa-users"></i>'+esc(a.audience||'Destinatarios'));
      $('#ca-detail-message').text(a.content||'');
      $('#ca-detail-total').text(Number(a.recipient_count||0));
      $('#ca-detail-sent').text(Number(counts.sent||0));
      $('#ca-detail-no-device').text(Number(counts.no_device||0));
      $('#ca-detail-read').text(Number(counts.read||0));
      $('#ca-detail-warning').toggleClass('d-none',r.event_aware_delivery!==false)
        .text(r.event_aware_delivery===false?'El detalle de entrega por dispositivo es limitado hasta ejecutar la migración de reintentos de notificaciones.':'');
      renderDelivery();
      $('#ca-detail-loading').addClass('d-none');
      $('#ca-detail-content').removeClass('d-none');
    }).fail(x=>{
      $('#ca-detail-loading').html('<span class="text-danger">'+esc((x.responseJSON||{}).message||'No se pudo cargar el detalle.')+'</span>');
    });
  }

  $('.ca-audience-option').on('click',function(){
    $('#ca-audience').val($(this).data('type'));
    updateAudienceUI();
  });

  $('#ca-level').on('change',function(){refreshGrades();refreshSections();updateAudienceSummary();});
  $('#ca-grade').on('change',function(){refreshSections();updateAudienceSummary();});
  $('#ca-section,#ca-student').on('change',updateAudienceSummary);
  $('#ca-title,#ca-content').on('input',updatePreview);
  $('#ca-search').on('input',renderHistory);
  $('#ca-detail-search').on('input',renderDelivery);

  $('#ca-open-compose').on('click',function(){
    hideAlert();
    $('#ca-compose-error,#ca-confirm-error').addClass('d-none').text('');
    resetCompose();
    $('#ca-compose-modal').modal('show');
  });

  $('#ca-review-send').on('click',function(){
    const error=validateCompose();
    if(error){
      $('#ca-compose-error').removeClass('d-none').text(error);
      return;
    }
    $('#ca-compose-error').addClass('d-none').text('');
    pendingSend=buildPayload();
    $('#ca-confirm-title').text(pendingSend.title);
    $('#ca-confirm-audience').text(audienceLabel());
    $('#ca-confirm-count').text(recipientRows().length);
    $('#ca-compose-modal').one('hidden.bs.modal',function(){
      $('#ca-confirm-modal').modal('show');
    }).modal('hide');
  });

  $('#ca-back-compose').on('click',function(){
    $('#ca-confirm-error').addClass('d-none').text('');
    $('#ca-confirm-modal').one('hidden.bs.modal',function(){
      $('#ca-compose-modal').modal('show');
    }).modal('hide');
  });

  $('#ca-confirm-send').on('click',function(){
    if(!pendingSend)return;
    const button=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Enviando...');
    $('#ca-confirm-error').addClass('d-none').text('');
    $.post(api,pendingSend,null,'json').done(r=>{
      if(!r||Number(r.status)!==1){
        $('#ca-confirm-error').removeClass('d-none').text((r&&r.message)||'No se pudo enviar el comunicado.');
        return;
      }
      $('#ca-confirm-modal').modal('hide');
      const recipients=Number(r.recipients||0);
      const withDevices=Number(r.students_with_devices||0);
      const withoutDevice=Math.max(0,recipients-withDevices);
      const sent=Number(r.push_sent||0),failed=Number(r.push_failed||0);
      let message='<strong><i class="fas fa-check-circle mr-1"></i>Comunicado enviado.</strong> '+recipients+' estudiante(s) destinatarios · '+sent+' push enviados.';
      if(withoutDevice>0)message+=' <span class="ml-1">'+withoutDevice+' sin dispositivo registrado.</span>';
      if(failed>0)message+=' <span class="ml-1">'+failed+' push fallidos.</span>';
      showAlert(message,(failed>0||withoutDevice>0)?'warning':'success');
      pendingSend=null;
      resetCompose();
      loadSummary();
      loadHistory();
    }).fail(x=>{
      $('#ca-confirm-error').removeClass('d-none').text((x.responseJSON||{}).message||'No se pudo enviar el comunicado.');
    }).always(()=>button.prop('disabled',false).html('<i class="fas fa-paper-plane mr-1"></i>Enviar comunicado'));
  });

  $('#ca-refresh').on('click',function(){loadSummary();loadHistory();});
  $(document).on('click','.ca-detail-btn',function(){openDetail(Number($(this).data('id')||0));});

  if(ready){
    loadOptions();
    loadSummary();
    loadHistory();
  }
  updateAudienceUI();
  updatePreview();
})(jQuery);
</script>
