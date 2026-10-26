<?php
/** Panel único de cierre bimestral para Administración. Reutiliza las API vigentes. */
if (empty($_SESSION['login_id']) || (int)($_SESSION['login_type'] ?? 0) !== 1
    || (int)($_SESSION['login_school_id'] ?? 0) <= 0
    || !isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">Acceso exclusivo de Administración.</div>';
    return;
}
$bcSchool = (int)$_SESSION['login_school_id'];
$bcYears = [];
$stmt = $conn->prepare('SELECT id,year,status,is_active FROM academic_year WHERE school_id=? ORDER BY is_active DESC,year DESC,id DESC');
if ($stmt) {
    $stmt->bind_param('i', $bcSchool);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $bcYears[] = ['id'=>(int)$row['id'],'year'=>(string)$row['year'],
            'status'=>(string)($row['status'] ?? ''),'active'=>(int)$row['is_active']];
    }
    $stmt->close();
}
$bcYearId = $bcYears ? (int)$bcYears[0]['id'] : 0;
foreach ($bcYears as $y) if ((int)($_GET['year_id'] ?? 0) === (int)$y['id']) $bcYearId = (int)$y['id'];
$bcBim = 1;
if ($bcYearId) {
    $today = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d');
    $stmt = $conn->prepare('SELECT period_number,start_date,end_date FROM academic_periods WHERE school_id=? AND academic_year_id=? ORDER BY period_number');
    if ($stmt) {
        $stmt->bind_param('ii', $bcSchool, $bcYearId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($p = $result->fetch_assoc()) {
            $num = (int)$p['period_number'];
            if ($num < 1 || $num > 4) continue;
            if (!empty($p['start_date']) && $p['start_date'] <= $today) $bcBim = $num;
            if (!empty($p['start_date']) && !empty($p['end_date']) && $p['start_date'] <= $today && $p['end_date'] >= $today) {
                $bcBim = $num;
                break;
            }
        }
        $stmt->close();
    }
}
if ((int)($_GET['bimester'] ?? 0) >= 1 && (int)($_GET['bimester'] ?? 0) <= 4) $bcBim = (int)$_GET['bimester'];
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$bcCsrf = (string)$_SESSION['csrf_token'];
$bcCss = @filemtime(__DIR__.'/../css/bimester_closure_admin.css') ?: 1;
$bcJs = @filemtime(__DIR__.'/../js/bimester_closure_admin.js') ?: 1;
?>
<link rel="stylesheet" href="css/bimester_closure_admin.css?v=<?php echo (int)$bcCss; ?>">
<div class="container-fluid bc-root" id="bc-root">
  <div class="bc-heading d-flex justify-content-between align-items-center flex-wrap mb-3">
    <div class="d-flex align-items-center">
      <span class="bc-heading-icon"><i class="fas fa-clipboard-check"></i></span>
      <div><h1 class="h4 mb-1 text-gray-800">Cierre bimestral</h1>
        <p class="small text-muted mb-0">Supervisión de docentes, cierres por curso y consolidación institucional.</p></div>
    </div>
    <button class="btn btn-sm btn-outline-secondary bc-reload mt-2 mt-md-0" type="button" id="bc-reload"><i class="fas fa-sync-alt mr-1"></i>Actualizar</button>
  </div>
  <div class="card shadow-sm mb-3 bc-filters"><div class="card-body py-3">
    <div class="row align-items-end">
      <div class="col-md-5 col-lg-4 mb-2 mb-md-0"><label for="bc-year">Año académico</label>
        <select id="bc-year" class="form-control form-control-sm">
          <?php foreach ($bcYears as $year): ?>
          <option value="<?php echo (int)$year['id']; ?>" <?php echo (int)$year['id'] === $bcYearId?'selected':''; ?>><?php echo htmlspecialchars($year['year'], ENT_QUOTES, 'UTF-8'); ?><?php echo $year['active']?' · Activo':''; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 col-lg-3 mb-2 mb-md-0"><label for="bc-bim">Bimestre</label>
        <select id="bc-bim" class="form-control form-control-sm">
          <?php foreach (['I','II','III','IV'] as $i=>$label): ?>
            <option value="<?php echo $i+1; ?>" <?php echo $bcBim===$i+1?'selected':''; ?>><?php echo $label; ?> Bimestre</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 col-lg-5"><div class="bc-period-state text-md-right" id="bc-period-state">Consultando estado...</div></div>
    </div>
  </div></div>
  <div id="bc-error" aria-live="polite"></div>
  <div id="bc-loading" class="text-muted text-center py-4"><i class="fas fa-circle-notch fa-spin mr-2"></i>Preparando información de cierres...</div>
  <div id="bc-data" class="d-none">
    <ul class="nav nav-pills bc-tabs mb-3" role="tablist" id="bc-tabs">
      <li class="nav-item"><a class="nav-link active" data-toggle="pill" href="#bc-overview" data-tab="overview" role="tab">Resumen</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="pill" href="#bc-teachers" data-tab="teachers" role="tab">Seguimiento docente</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="pill" href="#bc-courses" data-tab="courses" role="tab">Control por cursos</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="pill" href="#bc-reports" data-tab="reports" role="tab">Reportes</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="pill" href="#bc-institution" data-tab="institution" role="tab">Cierre institucional</a></li>
    </ul>
    <div class="tab-content">
      <section class="tab-pane fade show active" id="bc-overview" role="tabpanel">
        <div class="row" id="bc-kpis"></div>
        <div class="row">
          <div class="col-xl-7 mb-3"><div class="card shadow-sm h-100"><div class="card-header bg-white"><strong>Estado del bimestre</strong></div><div class="card-body" id="bc-overview-body"></div></div></div>
          <div class="col-xl-5 mb-3"><div class="card shadow-sm h-100"><div class="card-header bg-white"><strong>Acciones recomendadas</strong></div><div class="card-body" id="bc-next-actions"></div></div></div>
        </div>
      </section>
      <section class="tab-pane fade" id="bc-teachers" role="tabpanel">
        <div class="card shadow-sm mb-3">
          <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
            <strong>Seguimiento de cumplimiento docente</strong>
            <div class="bc-inline-actions">
              <button class="btn btn-sm btn-outline-secondary" id="bc-avisos-history" type="button"><i class="fas fa-history mr-1"></i>Historial de avisos</button>
              <button class="btn btn-sm btn-outline-primary" id="bc-avisos-settings" type="button"><i class="fas fa-bell mr-1"></i>Configurar avisos</button>
            </div>
          </div>
          <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover table-sm ed-table w-100 mb-0" id="bc-teacher-table"><thead class="thead-light"><tr><th>Docente</th><th>Cerrados</th><th>Pendientes</th><th>Cumplimiento</th><th>Estado</th><th>Acciones</th></tr></thead><tbody></tbody></table>
          </div></div>
          <div class="card-footer bg-white small text-muted">Un curso con todas las notas completas sigue pendiente hasta que se registre su cierre. Los avisos no prueban que hayan sido leídos.</div>
        </div>
      </section>
      <section class="tab-pane fade" id="bc-courses" role="tabpanel">
        <div class="card shadow-sm mb-3">
          <div class="card-body pb-1"><div class="row align-items-end">
            <div class="col-md-3 col-lg-2 mb-2"><label for="bc-level">Nivel</label><select class="form-control form-control-sm" id="bc-level"><option value="">Todos</option></select></div>
            <div class="col-md-4 col-lg-3 mb-2"><label for="bc-teacher">Docente</label><select class="form-control form-control-sm" id="bc-teacher"><option value="">Todos</option></select></div>
            <div class="col-md-3 col-lg-3 mb-2"><label for="bc-state">Estado</label><select class="form-control form-control-sm" id="bc-state">
              <option value="">Todos</option><option value="Cerrado">Cerrado</option><option value="Listo">Listo</option><option value="Incompleto">Incompleto</option><option value="Reabierto">Reabierto</option><option value="Reapertura pendiente">Reapertura pendiente</option>
            </select></div>
            <div class="col-md-2 col-lg-4 mb-2 text-md-right"><button type="button" class="btn btn-sm btn-light border" id="bc-clear-filters">Limpiar filtros</button></div>
          </div></div>
          <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap">
            <strong>Asignaciones por curso y aula</strong>
            <div class="bc-inline-actions">
              <button class="btn btn-sm btn-outline-success" id="bc-close-selected" type="button" disabled>Cerrar seleccionados</button>
              <button class="btn btn-sm btn-success" id="bc-close-ready" type="button" disabled>Cerrar todos los listos</button>
            </div>
          </div>
          <div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover table-sm ed-table w-100 mb-0" id="bc-course-table"><thead class="thead-light"><tr><th><input type="checkbox" id="bc-check-all" aria-label="Seleccionar visibles"></th><th>Curso y aula</th><th>Docente</th><th>Notas</th><th>Estado</th><th>Cierre</th><th>Acción</th></tr></thead><tbody></tbody></table>
          </div></div>
        </div>
      </section>
      <section class="tab-pane fade" id="bc-reports" role="tabpanel">
        <div class="card shadow-sm"><div class="card-header bg-white"><strong>Reportes de cumplimiento bimestral</strong></div><div class="card-body">
          <p class="text-muted small">Los reportes se elaboran con las asignaciones y cierres del periodo seleccionado. Se distingue el avance de notas del cierre efectivo.</p>
          <div class="bc-inline-actions mb-3">
            <button type="button" class="btn btn-sm btn-outline-success" id="bc-report-csv"><i class="fas fa-file-excel mr-1"></i>Excel (CSV)</button>
            <button type="button" class="btn btn-sm btn-outline-danger" id="bc-report-print"><i class="fas fa-file-pdf mr-1"></i>Imprimir / Guardar como PDF</button>
          </div>
          <div id="bc-report-summary" class="small text-muted"></div>
          <div class="table-responsive mt-3"><table class="table table-sm table-bordered bc-report-table" id="bc-report-table">
            <thead class="thead-light"><tr><th>Docente</th><th>Asignaciones</th><th>Cerradas</th><th>Pendientes</th><th>Cumplimiento</th><th>Estado</th></tr></thead><tbody></tbody>
          </table></div>
          <div class="alert alert-light border small mb-0">La fecha configurada para enviar avisos no constituye una fecha oficial de cumplimiento. El reporte no califica atrasos sin un plazo institucional definido.</div>
        </div></div>
      </section>
      <section class="tab-pane fade" id="bc-institution" role="tabpanel">
        <div class="card shadow-sm"><div class="card-header bg-white"><strong>Verificación y cierre institucional</strong></div><div class="card-body" id="bc-institution-body"></div></div>
      </section>
    </div>
  </div>
</div>
<div class="modal fade" id="bc-action-modal" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg" role="document"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="bc-action-title">Confirmación</h5><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button></div>
  <form id="bc-action-form"><div class="modal-body" id="bc-action-body"></div><div class="modal-footer"><button type="button" class="btn btn-light border" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="bc-action-confirm">Confirmar</button></div></form>
</div></div></div>
<div class="modal fade" id="bc-avisos-modal" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Configurar avisos docentes</h5><button class="close" type="button" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button></div>
  <form id="bc-avisos-form"><div class="modal-body">
    <p class="small text-muted">Estos valores programan avisos. No cierran ni bloquean notas.</p>
    <div class="form-group"><label for="bc-av-date">Fecha de referencia de avisos</label><input type="date" class="form-control" id="bc-av-date"><small class="text-muted">Vacío: se usa el fin del bimestre.</small></div>
    <div class="form-group"><label for="bc-av-days">Días de anticipación</label><select class="form-control" id="bc-av-days"><?php for($d=1;$d<=14;$d++): ?><option value="<?php echo $d; ?>"><?php echo $d; ?></option><?php endfor; ?></select></div>
    <div class="custom-control custom-checkbox mb-2"><input class="custom-control-input" type="checkbox" id="bc-av-closure"><label for="bc-av-closure" class="custom-control-label">Recordar cierres próximos y pendientes</label></div>
    <div class="custom-control custom-checkbox mb-2"><input class="custom-control-input" type="checkbox" id="bc-av-missing"><label for="bc-av-missing" class="custom-control-label">Avisar por calificaciones incompletas</label></div>
    <div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" id="bc-av-edits"><label for="bc-av-edits" class="custom-control-label">Notificar modificaciones administrativas</label></div>
  </div><div class="modal-footer"><button type="button" data-dismiss="modal" class="btn btn-light border">Cancelar</button><button class="btn btn-primary" type="submit">Guardar configuración</button></div></form>
</div></div></div>
<div class="modal fade" id="bc-history-modal" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg" role="document"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Historial de avisos docentes</h5><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button></div>
  <div class="modal-body" id="bc-history-body"></div><div class="modal-footer"><button type="button" class="btn btn-light border" data-dismiss="modal">Cerrar</button></div>
</div></div></div>
<script>window.EduSyncBimesterClose=<?php echo json_encode([
  'yearId'=>$bcYearId,'bimester'=>$bcBim,'years'=>$bcYears,'csrf'=>$bcCsrf,
  'defaultTab'=>(string)($_GET['tab'] ?? 'overview')
], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script>
<script src="js/bimester_closure_admin.js?v=<?php echo (int)$bcJs; ?>"></script>
