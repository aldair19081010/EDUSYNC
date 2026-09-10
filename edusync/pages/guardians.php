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
.guardian-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.6rem;padding:1rem 1.2rem;margin:1rem 0}.guardian-head h1{font-size:1.35rem;font-weight:700;color:#344767;margin:0}.guardian-head p{font-size:.82rem;color:#7b8499;margin:.2rem 0 0}.guardian-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1rem}.guardian-stat{background:#fff;border:1px solid #e4e9f2;border-radius:.55rem;padding:.85rem 1rem}.guardian-stat small{display:block;color:#7b8499;font-weight:700;font-size:.72rem}.guardian-stat strong{display:block;color:#253858;font-size:1.2rem;margin-top:.25rem}.guardian-panel{background:#fff;border:1px solid #e4e9f2;border-radius:.6rem;padding:1rem}.guardian-table td{vertical-align:middle}.guardian-chip{display:inline-block;padding:.18rem .45rem;border-radius:1rem;background:#f1f4fb;color:#56627a;font-size:.7rem;margin:.08rem}.guardian-links-table td{vertical-align:middle}.guardian-permissions{display:flex;flex-wrap:wrap;gap:.3rem}.guardian-permissions .badge{font-weight:600}.guardian-form-note{font-size:.73rem;color:#858796}.guardian-student-search .select2-container{width:100%!important}.guardian-history-details{white-space:pre-wrap;font-size:.75rem;color:#6c757d;max-width:420px}.guardian-empty{padding:2rem;text-align:center;color:#858796}@media(max-width:768px){.guardian-head{align-items:flex-start;flex-direction:column}.guardian-stats{grid-template-columns:1fr}.guardian-panel{padding:.75rem}}
</style>

<div class="guardian-head">
    <div>
        <h1><i class="fas fa-user-friends text-primary mr-2"></i>Apoderados</h1>
        <p>Administra responsables familiares, sus accesos y los estudiantes vinculados.</p>
    </div>
    <?php if ($tablesReady): ?>
    <button class="btn btn-primary btn-sm" type="button" id="btn-new-guardian"><i class="fas fa-plus mr-1"></i> Nuevo apoderado</button>
    <?php endif; ?>
</div>

<?php if (!$tablesReady): ?>
<div class="alert alert-warning">
    <i class="fas fa-database mr-2"></i>
    Falta actualizar la base de datos. Ejecuta manualmente <b>sql/guardians_module_upgrade.sql</b> y recarga esta página.
</div>
<?php else: ?>
<div class="guardian-stats">
    <div class="guardian-stat"><small>APODERADOS REGISTRADOS</small><strong id="guardian-stat-total">—</strong></div>
    <div class="guardian-stat"><small>APODERADOS ACTIVOS</small><strong id="guardian-stat-active">—</strong></div>
    <div class="guardian-stat"><small>VÍNCULOS CON ESTUDIANTES</small><strong id="guardian-stat-links">—</strong></div>
</div>

<div class="guardian-panel mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h6 class="font-weight-bold text-primary mb-1">Directorio de apoderados</h6>
            <small class="text-muted">El DNI/documento funciona como usuario. La clave se guarda cifrada y solo admite números.</small>
        </div>
        <div class="mt-2 mt-md-0">
            <select id="guardian-status-filter" class="form-control form-control-sm">
                <option value="">Todos los estados</option>
                <option value="Activo">Activos</option>
                <option value="Inactivo">Inactivos</option>
            </select>
        </div>
    </div>
    <div id="guardian-error" class="alert alert-danger d-none"></div>
    <div class="table-responsive">
        <table id="guardians-table" class="table table-hover table-sm guardian-table" style="width:100%">
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

<div class="modal fade" id="guardian-form-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <form id="guardian-form">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-friends text-primary mr-2"></i><span id="guardian-form-title">Nuevo apoderado</span></h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" id="guardian-id" value="0">
        <div class="row">
          <div class="col-md-4"><div class="form-group"><label class="font-weight-bold small">DNI / Documento *</label><input class="form-control" name="dni" id="guardian-dni" inputmode="numeric" maxlength="12" required><small class="guardian-form-note">Entre 8 y 12 dígitos. Será el usuario de acceso.</small></div></div>
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
          <div class="col-md-6"><div class="form-group"><label class="font-weight-bold small" id="guardian-pin-label">Clave numérica inicial *</label><input class="form-control" type="password" name="pin" id="guardian-pin" inputmode="numeric" maxlength="12" autocomplete="new-password"><small class="guardian-form-note" id="guardian-pin-note">6 a 12 dígitos. No se mostrará nuevamente.</small></div></div>
        </div>
        <div class="alert alert-info py-2 mb-0"><i class="fas fa-info-circle mr-1"></i> La cuenta se crea como rol <b>Apoderado</b>. El portal familiar se habilitará en la siguiente etapa; por ahora esta pantalla prepara y administra los accesos.</div>
      </div>
      <div class="modal-footer"><button class="btn btn-light border" type="button" data-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit" id="guardian-save-btn"><i class="fas fa-save mr-1"></i> Guardar</button></div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="guardian-links-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document"><div class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title"><i class="fas fa-link text-primary mr-2"></i>Estudiantes vinculados</h5><small class="text-muted" id="guardian-links-name"></small></div><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body">
      <input type="hidden" id="guardian-links-id" value="0">
      <div class="card border-left-primary mb-3"><div class="card-body py-3">
        <form id="guardian-link-form">
          <div class="row align-items-end">
            <div class="col-lg-5 guardian-student-search"><div class="form-group mb-lg-0"><label class="small font-weight-bold">Estudiante</label><select id="guardian-student-id" class="form-control" style="width:100%"></select></div></div>
            <div class="col-lg-2"><div class="form-group mb-lg-0"><label class="small font-weight-bold">Parentesco</label><select id="guardian-parentesco" class="form-control"><option>Madre</option><option>Padre</option><option>Tutor</option><option>Apoderado</option><option>Abuelo/a</option><option>Hermano/a</option><option>Otro</option></select></div></div>
            <div class="col-lg-2"><div class="form-group mb-lg-0"><div class="custom-control custom-checkbox mt-2"><input type="checkbox" class="custom-control-input" id="guardian-is-primary"><label class="custom-control-label small font-weight-bold" for="guardian-is-primary">Apoderado principal</label></div></div></div>
            <div class="col-lg-3 text-lg-right"><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-link mr-1"></i> Vincular</button></div>
          </div>
          <div class="mt-3 pt-2 border-top">
            <span class="small font-weight-bold mr-2">Permisos para el futuro portal:</span>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="guardian-can-grades" checked><label class="custom-control-label small" for="guardian-can-grades">Notas</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="guardian-can-attendance" checked><label class="custom-control-label small" for="guardian-can-attendance">Asistencia</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="guardian-can-payments" checked><label class="custom-control-label small" for="guardian-can-payments">Pagos</label></div>
            <div class="custom-control custom-checkbox custom-control-inline"><input type="checkbox" class="custom-control-input" id="guardian-can-communications" checked><label class="custom-control-label small" for="guardian-can-communications">Comunicaciones</label></div>
          </div>
        </form>
      </div></div>
      <div class="table-responsive"><table class="table table-sm table-hover guardian-links-table"><thead class="thead-light"><tr><th>Estudiante</th><th>Aula</th><th>Parentesco</th><th>Principal</th><th>Permisos</th><th></th></tr></thead><tbody id="guardian-links-body"></tbody></table></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-dismiss="modal">Cerrar</button></div>
  </div></div>
</div>

<div class="modal fade" id="guardian-pin-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document"><div class="modal-content"><form id="guardian-pin-form">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-key text-primary mr-2"></i>Restablecer clave</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body"><input type="hidden" id="guardian-pin-id"><p class="small text-muted" id="guardian-pin-name"></p><div class="form-group"><label class="small font-weight-bold">Nueva clave numérica</label><input type="password" id="guardian-new-pin" class="form-control" inputmode="numeric" maxlength="12" required><small class="guardian-form-note">Entre 6 y 12 dígitos.</small></div></div>
    <div class="modal-footer"><button class="btn btn-light border" type="button" data-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar nueva clave</button></div>
  </form></div></div>
</div>

<div class="modal fade" id="guardian-history-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title"><i class="fas fa-history text-primary mr-2"></i>Historial del apoderado</h5><small class="text-muted" id="guardian-history-name"></small></div><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body"><div class="table-responsive"><table class="table table-sm table-hover"><thead class="thead-light"><tr><th>Fecha</th><th>Acción</th><th>Usuario</th><th>Detalle</th></tr></thead><tbody id="guardian-history-body"></tbody></table></div></div>
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
