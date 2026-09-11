<?php
include_once __DIR__ . '/../db_connect.php';
if (($_SESSION['login_type'] ?? 0) != 1) {
    echo '<div class="alert alert-danger mt-3">No tienes permisos para administrar apoderados.</div>';
    return;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$tablesReady = true;
foreach (['guardians', 'guardian_students', 'guardian_audit_log'] as $table) {
    $safe = $conn->real_escape_string($table);
    $check = $conn->query("SHOW TABLES LIKE '{$safe}'");
    if (!$check || $check->num_rows === 0) {
        $tablesReady = false;
        break;
    }
}
?>
<style>
.guardian-history-details{white-space:pre-wrap;font-size:.75rem;color:#6c757d;max-width:420px}.guardian-student-search .select2-container{width:100%!important}
</style>

<div class="d-sm-flex align-items-center justify-content-between mb-4 mt-3">
    <div>
        <h1 class="h3 mb-1 text-gray-800"><i class="fas fa-user-friends text-primary mr-2"></i>Apoderados</h1>
        <p class="mb-0 text-muted small">Administra responsables familiares, sus accesos y los estudiantes vinculados.</p>
    </div>
    <?php if ($tablesReady): ?>
    <button class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm mt-3 mt-sm-0" type="button" id="btn-new-guardian">
        <i class="fas fa-plus fa-sm text-white-50 mr-1"></i> Nuevo apoderado
    </button>
    <?php endif; ?>
</div>

<?php if (!$tablesReady): ?>
<div class="alert alert-warning shadow-sm">
    <i class="fas fa-database mr-2"></i>
    Falta actualizar la base de datos. Ejecuta manualmente <b>sql/guardians_module_upgrade.sql</b> y recarga esta página.
</div>
<?php else: ?>

<div class="row">
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2"><div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Apoderados registrados</div><div class="h5 mb-0 font-weight-bold text-gray-800" id="guardian-stat-total">—</div></div><div class="col-auto"><i class="fas fa-user-friends fa-2x text-gray-300"></i></div></div></div>
        </div>
    </div>
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2"><div class="text-xs font-weight-bold text-success text-uppercase mb-1">Apoderados activos</div><div class="h5 mb-0 font-weight-bold text-gray-800" id="guardian-stat-active">—</div></div><div class="col-auto"><i class="fas fa-user-check fa-2x text-gray-300"></i></div></div></div>
        </div>
    </div>
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2"><div class="text-xs font-weight-bold text-info text-uppercase mb-1">Vínculos con estudiantes</div><div class="h5 mb-0 font-weight-bold text-gray-800" id="guardian-stat-links">—</div></div><div class="col-auto"><i class="fas fa-link fa-2x text-gray-300"></i></div></div></div>
        </div>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
        <div>
            <h6 class="m-0 font-weight-bold text-primary">Directorio de apoderados</h6>
            <small class="text-muted">El DNI/documento funciona como usuario cuando el acceso está habilitado.</small>
        </div>
        <div class="mt-2 mt-md-0">
            <select id="guardian-status-filter" class="form-control form-control-sm">
                <option value="">Todos los estados</option>
                <option value="Activo">Activos</option>
                <option value="Inactivo">Inactivos</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div id="guardian-error" class="alert alert-danger d-none"></div>
        <div class="table-responsive">
            <table id="guardians-table" class="table table-bordered table-hover table-sm" width="100%" cellspacing="0">
                <thead class="thead-light">
                    <tr>
                        <th>DNI / Documento</th>
                        <th>Apoderado</th>
                        <th>Contacto</th>
                        <th>Estudiantes</th>
                        <th>Estado</th>
                        <th style="width:190px">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="guardian-form-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <form id="guardian-form">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fas fa-user-friends mr-2"></i><span id="guardian-form-title">Nuevo apoderado</span></h5>
        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="guardian-id" value="0">
        <div class="row">
          <div class="col-md-4"><div class="form-group"><label class="font-weight-bold small">DNI / Documento *</label><input class="form-control" name="dni" id="guardian-dni" inputmode="numeric" maxlength="12" required><small class="form-text text-muted">Entre 8 y 12 dígitos. Será el usuario de acceso.</small></div></div>
          <div class="col-md-4"><div class="form-group"><label class="font-weight-bold small">Nombres *</label><input class="form-control" name="nombres" id="guardian-nombres" required></div></div>
          <div class="col-md-4"><div class="form-group"><label class="font-weight-bold small">Apellido paterno *</label><input class="form-control" name="apellido_paterno" id="guardian-apellido-paterno" required></div></div>
        </div>
        <div class="row">
          <div class="col-md-4"><div class="form-group"><label class="font-weight-bold small">Apellido materno</label><input class="form-control" name="apellido_materno" id="guardian-apellido-materno"></div></div>
          <div class="col-md-4"><div class="form-group"><label class="font-weight-bold small">Teléfono</label><input class="form-control" name="telefono" id="guardian-telefono"></div></div>
          <div class="col-md-4"><div class="form-group"><label class="font-weight-bold small">Correo</label><input class="form-control" type="email" name="email" id="guardian-email"></div></div>
        </div>
        <div class="row">
          <div class="col-md-6"><div class="form-group"><label class="font-weight-bold small">Estado</label><select class="form-control" name="status" id="guardian-status"><option value="Activo">Activo</option><option value="Inactivo">Inactivo</option></select></div></div>
          <div class="col-md-6"><div class="form-group"><label class="font-weight-bold small" id="guardian-pin-label">Clave numérica inicial *</label><input class="form-control" type="password" name="pin" id="guardian-pin" inputmode="numeric" maxlength="12" autocomplete="new-password"><small class="form-text text-muted" id="guardian-pin-note">6 a 12 dígitos. No se mostrará nuevamente.</small></div></div>
        </div>
        <div class="alert alert-info mb-0"><i class="fas fa-info-circle mr-1"></i> La cuenta utiliza el rol <b>Apoderado</b>. El acceso puede crearse ahora o posteriormente desde la llave del directorio.</div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit" id="guardian-save-btn"><i class="fas fa-save mr-1"></i> Guardar</button></div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="guardian-links-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document"><div class="modal-content">
    <div class="modal-header bg-primary text-white">
        <div><h5 class="modal-title"><i class="fas fa-link mr-2"></i>Estudiantes vinculados</h5><small id="guardian-links-name" class="text-white-50"></small></div>
        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="guardian-links-id" value="0">
      <div class="card border-left-primary shadow-sm mb-3"><div class="card-body">
        <form id="guardian-link-form">
          <div class="row align-items-end">
            <div class="col-lg-5 guardian-student-search"><div class="form-group mb-lg-0"><label class="small font-weight-bold">Estudiante</label><select id="guardian-student-id" class="form-control" style="width:100%"></select></div></div>
            <div class="col-lg-2"><div class="form-group mb-lg-0"><label class="small font-weight-bold">Parentesco</label><select id="guardian-parentesco" class="form-control"><option>Madre</option><option>Padre</option><option>Tutor</option><option>Apoderado</option><option>Abuelo/a</option><option>Hermano/a</option><option>Otro</option></select></div></div>
            <div class="col-lg-2"><div class="form-group mb-lg-0"><div class="custom-control custom-checkbox mt-2"><input type="checkbox" class="custom-control-input" id="guardian-is-primary"><label class="custom-control-label small font-weight-bold" for="guardian-is-primary">Apoderado principal</label></div></div></div>
            <div class="col-lg-3 text-lg-right"><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-link mr-1"></i> Vincular</button></div>
          </div>
          <hr>
          <span class="small font-weight-bold mr-2">Permisos:</span>
          <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="guardian-can-grades" checked><label class="custom-control-label small" for="guardian-can-grades">Notas</label></div>
          <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="guardian-can-attendance" checked><label class="custom-control-label small" for="guardian-can-attendance">Asistencia</label></div>
          <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="guardian-can-payments" checked><label class="custom-control-label small" for="guardian-can-payments">Pagos</label></div>
          <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="guardian-can-communications" checked><label class="custom-control-label small" for="guardian-can-communications">Comunicaciones</label></div>
        </form>
      </div></div>
      <div class="table-responsive"><table class="table table-bordered table-hover table-sm"><thead class="thead-light"><tr><th>Estudiante</th><th>Aula</th><th>Parentesco</th><th>Principal</th><th>Permisos</th><th></th></tr></thead><tbody id="guardian-links-body"></tbody></table></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-dismiss="modal">Cerrar</button></div>
  </div></div>
</div>

<div class="modal fade" id="guardian-pin-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document"><div class="modal-content"><form id="guardian-pin-form">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-key mr-2"></i><span id="guardian-pin-title">Restablecer clave</span></h5><button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body"><input type="hidden" id="guardian-pin-id"><p class="small text-muted" id="guardian-pin-name"></p><div class="form-group"><label class="small font-weight-bold">Clave numérica</label><input type="password" id="guardian-new-pin" class="form-control" inputmode="numeric" maxlength="12" required><small class="form-text text-muted">Entre 6 y 12 dígitos.</small></div></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar clave</button></div>
  </form></div></div>
</div>

<div class="modal fade" id="guardian-history-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><div><h5 class="modal-title"><i class="fas fa-history mr-2"></i>Historial del apoderado</h5><small id="guardian-history-name" class="text-white-50"></small></div><button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body"><div class="table-responsive"><table class="table table-bordered table-hover table-sm"><thead class="thead-light"><tr><th>Fecha</th><th>Acción</th><th>Usuario</th><th>Detalle</th></tr></thead><tbody id="guardian-history-body"></tbody></table></div></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-dismiss="modal">Cerrar</button></div>
  </div></div>
</div>

<script>
window.GUARDIANS_CONFIG = {
  api: 'guardians_api.php',
  csrf: <?php echo json_encode($_SESSION['csrf_token'], JSON_UNESCAPED_SLASHES); ?>
};
</script>
<?php $guardianJsVersion = @filemtime(__DIR__ . '/../js/guardians.js') ?: time(); ?>
<script src="js/guardians.js?v=<?php echo $guardianJsVersion; ?>"></script>
<?php endif; ?>
