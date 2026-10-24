<?php
/**
 * Seguimiento docente · administración.
 * Vista independiente de Comunicados, Cobranza, Notificaciones y Cierre de notas.
 * Se sirve desde index.php (lista blanca y controles de rol).
 */
if (empty($_SESSION['login_id']) || (int)($_SESSION['login_type']??0)!==1
    || empty($_SESSION['login_school_id']) || !isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">Esta sección está disponible solo para Administración.</div>';
    return;
}
$tfSchool=(int)$_SESSION['login_school_id'];
$tfYears=[];
$tfQuery=$conn->prepare("SELECT id,year,status,is_active FROM academic_year WHERE school_id=? ORDER BY is_active DESC,year DESC,id DESC");
if($tfQuery){
    $tfQuery->bind_param('i',$tfSchool);
    $tfQuery->execute();
    $tfResult=$tfQuery->get_result();
    while($tfRow=$tfResult->fetch_assoc()){
        $tfYears[]=[
            'id'=>(int)$tfRow['id'],
            'year'=>(string)$tfRow['year'],
            'status'=>(string)$tfRow['status'],
            'active'=>(int)$tfRow['is_active']
        ];
    }
    $tfQuery->close();
}
$tfYearId=$tfYears?(int)$tfYears[0]['id']:0;
$tfBimester=1;
$tfPeriodOptions=[];
if($tfYearId>0){
    $tfPeriodQuery=$conn->prepare('SELECT period_number,name,start_date,end_date FROM academic_periods WHERE school_id=? AND academic_year_id=? ORDER BY period_number');
    if($tfPeriodQuery){
        $tfPeriodQuery->bind_param('ii',$tfSchool,$tfYearId);
        $tfPeriodQuery->execute();$tfPeriods=$tfPeriodQuery->get_result();
        $tfToday=(new DateTimeImmutable('now',new DateTimeZone('America/Lima')))->format('Y-m-d');$tfBest=null;$tfCurrent=null;
        while($tfP=$tfPeriods->fetch_assoc()){
            $tfN=(int)$tfP['period_number'];
            if($tfN<1||$tfN>4)continue;
            $tfPeriodOptions[]=['number'=>$tfN,'name'=>(string)$tfP['name']];
            if(!$tfBest)$tfBest=$tfN;
            if($tfP['start_date']&&$tfP['start_date']<=$tfToday)$tfBest=$tfN;
            if($tfP['start_date']&&$tfP['end_date']&&$tfToday>=$tfP['start_date']&&$tfToday<=$tfP['end_date']){
                $tfCurrent=$tfN;
            }
        }
        $tfBimester=$tfCurrent?:($tfBest?:1);
        $tfPeriodQuery->close();
    }
}
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$tfCssVersion=@filemtime(__DIR__.'/../css/teacher_notifications_admin.css')?:1;
$tfJsVersion=@filemtime(__DIR__.'/../js/teacher_notifications_admin.js')?:1;
?>
<link rel="stylesheet" href="css/teacher_notifications_admin.css?v=<?php echo (int)$tfCssVersion; ?>">
<div id="teacher-followup-root" class="container-fluid px-0">
  <div class="d-sm-flex justify-content-between align-items-center mb-3 tf-page-header">
    <div>
      <h1 class="h4 mb-1 text-gray-800"><i class="fas fa-clipboard-check text-primary mr-2"></i>Seguimiento docente</h1>
      <p class="text-muted small mb-0">Control de cierres de notas y avisos a docentes por bimestre.</p>
    </div>
    <div class="d-flex flex-wrap mt-2 mt-sm-0 tf-top-actions">
      <button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-1" id="taca-history-open">
        <i class="fas fa-history mr-1"></i>Historial
      </button>
      <button type="button" class="btn btn-sm btn-outline-primary mr-2 mb-1" id="taca-settings-open">
        <i class="fas fa-sliders-h mr-1"></i>Configuración
      </button>
      <button type="button" class="btn btn-sm btn-primary mb-1" id="taca-reload">
        <i class="fas fa-sync-alt mr-1"></i>Actualizar
      </button>
    </div>
  </div>

  <div class="card shadow-sm border-0 mb-3 tf-filter-card">
    <div class="card-body py-3">
      <div class="form-row align-items-end">
        <div class="form-group col-md-5 col-lg-4 mb-2">
          <label class="small font-weight-bold text-gray-700 mb-1" for="taca-year">Año académico</label>
          <select id="taca-year" class="form-control form-control-sm" <?php echo !$tfYears?'disabled':''; ?>>
            <?php foreach($tfYears as $tfYear): ?>
              <option value="<?php echo (int)$tfYear['id']; ?>" <?php echo $tfYear['id']===$tfYearId?'selected':''; ?>>
                <?php echo htmlspecialchars($tfYear['year'],ENT_QUOTES,'UTF-8'); ?>
                <?php echo $tfYear['active']===1?'· Activo':''; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group col-md-3 col-lg-3 mb-2">
          <label class="small font-weight-bold text-gray-700 mb-1" for="taca-bim">Bimestre</label>
          <select id="taca-bim" class="form-control form-control-sm" <?php echo !$tfPeriodOptions?'disabled':''; ?>>
            <?php if(!$tfPeriodOptions): ?>
              <option value="">Sin bimestres configurados</option>
            <?php else: foreach($tfPeriodOptions as $tfOption): ?>
              <option value="<?php echo (int)$tfOption['number']; ?>"<?php echo $tfBimester===$tfOption['number']?' selected':''; ?>>
                <?php echo htmlspecialchars($tfOption['name']?:($tfOption['number'].'° bimestre'),ENT_QUOTES,'UTF-8'); ?>
              </option>
            <?php endforeach; endif; ?>
          </select>
        </div>
        <div class="form-group col-md-4 col-lg-5 mb-2">
          <label class="small font-weight-bold text-gray-700 mb-1" for="taca-filter">Estado</label>
          <select id="taca-filter" class="form-control form-control-sm">
            <option value="pending" selected>Pendientes</option>
            <option value="all">Todos</option>
            <option value="incomplete">Notas incompletas</option>
            <option value="done">Al día</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div id="taca-error" aria-live="polite"></div>
  <div id="taca-loading" class="text-center text-muted py-4 d-none" role="status">
    <i class="fas fa-spinner fa-spin mr-2"></i>Consultando seguimiento docente...
  </div>
  <div id="taca-data" class="d-none">
    <div class="row mb-2" id="taca-stats"></div>
    <div class="card shadow-sm border-0 mb-3 tf-table-card">
      <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap py-3 tf-table-header">
        <div>
          <strong class="text-gray-800">Estado de docentes</strong>
        </div>
        <div class="d-flex align-items-center flex-wrap">
          <small class="text-muted mr-2" id="taca-grades-status" aria-live="polite"></small>
          <span id="taca-teacher-count" class="badge badge-light border mt-2 mt-sm-0"></span>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive ed-table-responsive" id="taca-table-wrap">
          <table class="table table-hover table-sm ed-table w-100 mb-0" id="taca-teachers-table">
            <thead class="thead-light">
              <tr><th>Docente</th><th>Cierres</th><th>Pendientes</th><th>Notas faltantes</th><th>Estado</th><th class="text-center">Acción</th></tr>
            </thead><tbody></tbody>
          </table>
        </div>
      </div>
    </div>
    <p class="small text-muted mb-3"><i class="fas fa-info-circle mr-1"></i>Los recordatorios se registran en la bandeja del docente. Máximo un envío manual cada 24 horas.</p>
  </div>
  <?php if(!$tfYears): ?>
    <div class="alert alert-light border">No hay años académicos configurados para esta institución.</div>
  <?php endif; ?>
</div>

<div class="modal fade" id="taca-settings-modal" tabindex="-1" role="dialog" aria-labelledby="taca-settings-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content">
      <form id="taca-settings-form">
        <div class="modal-header">
          <h5 id="taca-settings-title" class="modal-title"><i class="fas fa-sliders-h text-primary mr-2"></i>Configurar avisos docentes</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div id="taca-settings-context" class="small text-muted mb-3"></div>
          <div class="form-row">
            <div class="form-group col-md-7">
              <label for="taca-date" class="small font-weight-bold">Fecha límite de avisos</label>
              <input type="date" class="form-control form-control-sm" id="taca-date">
              <small class="text-muted">Si está vacía, se usa el fin del bimestre.</small>
            </div>
            <div class="form-group col-md-5">
              <label for="taca-days" class="small font-weight-bold">Avisar con anticipación</label>
              <select id="taca-days" class="form-control form-control-sm">
                <option value="1">1 día</option><option value="2">2 días</option>
                <option value="3">3 días</option><option value="5">5 días</option>
                <option value="7">7 días</option><option value="10">10 días</option>
                <option value="14">14 días</option>
              </select>
            </div>
          </div>
          <div class="border-top pt-3">
            <div class="custom-control custom-switch mb-3">
              <input type="checkbox" class="custom-control-input" id="taca-enabled-closure" checked>
              <label class="custom-control-label small" for="taca-enabled-closure">Recordatorios por cierres próximos y pendientes</label>
            </div>
            <div class="custom-control custom-switch mb-3">
              <input type="checkbox" class="custom-control-input" id="taca-enabled-incomplete" checked>
              <label class="custom-control-label small" for="taca-enabled-incomplete">Avisos por calificaciones incompletas</label>
            </div>
            <div class="custom-control custom-switch">
              <input type="checkbox" class="custom-control-input" id="taca-enabled-admin" checked>
              <label class="custom-control-label small" for="taca-enabled-admin">Avisar al docente cuando administración modifica sus notas</label>
            </div>
          </div>
          <div id="taca-effective" class="alert alert-light border small mt-3 mb-0"></div>
          <small class="text-muted d-block mt-2">Estas fechas solo controlan avisos; no cierran ni bloquean calificaciones.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border" data-dismiss="modal">Cerrar</button>
          <button type="submit" class="btn btn-primary" id="taca-save"><i class="fas fa-save mr-1"></i>Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="taca-history-modal" tabindex="-1" role="dialog" aria-labelledby="taca-history-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 id="taca-history-title" class="modal-title"><i class="fas fa-history text-primary mr-2"></i>Historial de avisos</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="small text-muted mb-2">Últimos 30 avisos del bimestre seleccionado. Una entrega aceptada por FCM no equivale a lectura.</div>
        <div id="taca-history"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="taca-reminder-modal" tabindex="-1" role="dialog" aria-labelledby="taca-reminder-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <form id="taca-reminder-form">
        <div class="modal-header">
          <h5 class="modal-title" id="taca-reminder-title"><i class="fas fa-paper-plane text-primary mr-2"></i>Recordatorio de cierre</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="taca-reminder-recipient">
          <div class="alert alert-light border small" id="taca-reminder-target"></div>
          <div class="form-group mb-2">
            <label for="taca-reminder-note" class="small font-weight-bold">Observación (opcional)</label>
            <textarea id="taca-reminder-note" class="form-control" rows="3" maxlength="250" placeholder="Recuerda cerrar los cursos pendientes..."></textarea>
          </div>
          <small class="text-muted">Se registrará un aviso en la bandeja. El sistema impide repetirlo durante 24 horas por docente.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="taca-reminder-confirm"><i class="fas fa-paper-plane mr-1"></i>Enviar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
window.EduSyncTeacherAlerts={
  yearId:<?php echo (int)$tfYearId; ?>,
  years:<?php echo json_encode($tfYears,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS); ?>,
  csrf:<?php echo json_encode($_SESSION['csrf_token'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS); ?>
};
</script>
<script src="js/teacher_notifications_admin.js?v=<?php echo (int)$tfJsVersion; ?>" onerror="document.getElementById('taca-error').textContent='No se pudo cargar el archivo js/teacher_notifications_admin.js. Comprueba que esté subido a producción y recarga la página.'"></script>
