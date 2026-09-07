<?php
include 'db_connect.php';

$school_id = intval($_SESSION['login_school_id'] ?? 0);
$login_id = intval($_SESSION['login_id'] ?? 0);
if (!$school_id || !$login_id) {
    echo '<div class="alert alert-danger">Sesión no válida.</div>';
    return;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$status_check = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$audit_check = $conn->query("SHOW TABLES LIKE 'user_audit_log'");
$migration_ready = ($status_check && $status_check->num_rows > 0 && $audit_check && $audit_check->num_rows > 0);

$status_select = $migration_ready ? 'u.status' : "'Activo' AS status";
$sql_users = "SELECT u.id, u.name, u.username, u.type, u.is_director, u.teacher_id, {$status_select},
                     t.name AS teacher_name, t.status AS teacher_status
              FROM users u
              LEFT JOIN teacher t ON t.id = u.teacher_id AND t.school_id = u.school_id
              WHERE u.school_id = ?
              ORDER BY u.name ASC";
$stmt_users = $conn->prepare($sql_users);
$stmt_users->bind_param('i', $school_id);
$stmt_users->execute();
$res_users = $stmt_users->get_result();
$users = [];
while ($row = $res_users->fetch_assoc()) $users[] = $row;
$stmt_users->close();

$stmt_teachers = $conn->prepare("SELECT id, name, status FROM teacher WHERE school_id = ? ORDER BY name ASC");
$stmt_teachers->bind_param('i', $school_id);
$stmt_teachers->execute();
$res_teachers = $stmt_teachers->get_result();
$teachers = [];
while ($row = $res_teachers->fetch_assoc()) $teachers[] = $row;
$stmt_teachers->close();

$total_users = count($users);
$total_admin = 0;
$total_directors = 0;
$total_teachers = 0;
$total_aux = 0;
$total_inactive = 0;
foreach ($users as $u) {
    if ((int)$u['type'] === 1) {
        $total_admin++;
        if ((int)$u['is_director'] === 1) $total_directors++;
    } elseif ((int)$u['type'] === 2) {
        $total_teachers++;
    } elseif ((int)$u['type'] === 3) {
        $total_aux++;
    }
    if (($u['status'] ?? 'Activo') !== 'Activo') $total_inactive++;
}

function user_role_label($row) {
    if ((int)$row['type'] === 1 && (int)$row['is_director'] === 1) return 'Director';
    if ((int)$row['type'] === 1) return 'Administrador';
    if ((int)$row['type'] === 2) return 'Docente';
    if ((int)$row['type'] === 3) return 'Auxiliar';
    return 'Otro';
}
function user_role_badge($row) {
    if ((int)$row['type'] === 1 && (int)$row['is_director'] === 1) return 'badge-primary';
    if ((int)$row['type'] === 1) return 'badge-danger';
    if ((int)$row['type'] === 2) return 'badge-info';
    if ((int)$row['type'] === 3) return 'badge-success';
    return 'badge-secondary';
}
?>

<style>
.user-hero{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.6rem;padding:1.1rem 1.3rem;margin-bottom:1rem}.user-hero h1{font-size:1.35rem;font-weight:700;color:#344767;margin:0}.user-hero p{font-size:.84rem;color:#7b8499;margin:.2rem 0 0}.user-stat{border:1px solid #e3e6f0;border-radius:.55rem;box-shadow:0 2px 7px rgba(31,45,61,.04);height:100%}.user-stat .card-body{padding:1rem}.user-stat .label{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:#858796;font-weight:700}.user-stat .number{font-size:1.55rem;color:#344767;font-weight:700;line-height:1.2}.user-stat .meta{font-size:.72rem;color:#858796}.users-toolbar{display:flex;gap:.65rem;flex-wrap:wrap;align-items:end}.users-toolbar .form-group{margin-bottom:0;min-width:180px}.users-table-card{border:1px solid #e3e6f0;border-radius:.6rem;box-shadow:0 2px 8px rgba(31,45,61,.05)}#usersTable td,#usersTable th{vertical-align:middle}.user-name-cell{display:flex;align-items:center;gap:.65rem}.user-avatar-placeholder{width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#f1f4f9;color:#4e73df;font-weight:700;flex:0 0 36px}.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px}.status-active{background:#1cc88a}.status-inactive{background:#e74a3b}.dropdown-menu .dropdown-item i{width:20px}.password-box{font-family:monospace;font-size:1rem}.history-item{border-left:3px solid #4e73df;padding:.7rem .85rem;margin-bottom:.75rem;background:#f8f9fc;border-radius:.25rem}.history-item .history-title{font-weight:700;color:#344767}.history-item .history-meta{font-size:.75rem;color:#858796}.teacher-warning{font-size:.78rem}.user-modal-section{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.45rem;padding:.75rem;margin-bottom:1rem}@media(max-width:767px){.users-toolbar .form-group{min-width:100%;width:100%}.user-hero{padding:1rem}.user-stat{margin-bottom:.5rem}}
</style>

<div class="container-fluid px-0">
    <div class="user-hero d-sm-flex align-items-center justify-content-between">
        <div>
            <h1><i class="fas fa-users-cog mr-2 text-primary"></i>Gestión de usuarios</h1>
            <p>Administra accesos, roles, cuentas docentes, estados y seguridad del personal.</p>
        </div>
        <button class="btn btn-primary btn-sm mt-2 mt-sm-0" id="new_user" <?php echo $migration_ready ? '' : 'disabled'; ?>>
            <i class="fas fa-user-plus mr-1"></i> Nuevo usuario
        </button>
    </div>

    <?php if (!$migration_ready): ?>
        <div class="alert alert-warning shadow-sm">
            <strong><i class="fas fa-database mr-2"></i>Actualización de base de datos pendiente.</strong>
            Para activar el nuevo módulo ejecuta <code>sql/users_module_upgrade.sql</code> en esta base de datos. La lista actual se muestra solo en modo consulta.
        </div>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-xl col-md-4 col-sm-6 mb-2">
            <div class="card user-stat"><div class="card-body"><div class="label">Total usuarios</div><div class="number"><?php echo $total_users; ?></div><div class="meta">Cuentas registradas</div></div></div>
        </div>
        <div class="col-xl col-md-4 col-sm-6 mb-2">
            <div class="card user-stat"><div class="card-body"><div class="label">Administración</div><div class="number"><?php echo $total_admin; ?></div><div class="meta"><?php echo $total_directors; ?> director(es)</div></div></div>
        </div>
        <div class="col-xl col-md-4 col-sm-6 mb-2">
            <div class="card user-stat"><div class="card-body"><div class="label">Docentes</div><div class="number"><?php echo $total_teachers; ?></div><div class="meta">Cuentas vinculadas</div></div></div>
        </div>
        <div class="col-xl col-md-6 col-sm-6 mb-2">
            <div class="card user-stat"><div class="card-body"><div class="label">Auxiliares</div><div class="number"><?php echo $total_aux; ?></div><div class="meta">Personal auxiliar</div></div></div>
        </div>
        <div class="col-xl col-md-6 col-sm-6 mb-2">
            <div class="card user-stat"><div class="card-body"><div class="label">Inactivos</div><div class="number"><?php echo $total_inactive; ?></div><div class="meta">Sin acceso al sistema</div></div></div>
        </div>
    </div>

    <div class="card users-table-card mb-4">
        <div class="card-header bg-white py-3">
            <div class="users-toolbar">
                <div class="form-group">
                    <label class="small font-weight-bold mb-1">Filtrar por rol</label>
                    <select class="form-control form-control-sm" id="roleFilter">
                        <option value="">Todos los roles</option>
                        <option value="Administrador">Administrador</option>
                        <option value="Director">Director</option>
                        <option value="Docente">Docente</option>
                        <option value="Auxiliar">Auxiliar</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="small font-weight-bold mb-1">Filtrar por estado</label>
                    <select class="form-control form-control-sm" id="statusFilter">
                        <option value="">Todos los estados</option>
                        <option value="Activo">Activo</option>
                        <option value="Inactivo">Inactivo</option>
                    </select>
                </div>
                <div class="ml-sm-auto small text-muted pt-2"><i class="fas fa-shield-alt mr-1"></i>Solo administradores pueden modificar cuentas.</div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-bordered" id="usersTable" width="100%">
                    <thead class="thead-light">
                        <tr>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th class="text-center">Rol</th>
                            <th>Vinculación</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center" style="width:90px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $row):
                        $role_label = user_role_label($row);
                        $status = $row['status'] ?? 'Activo';
                        $teacher_name = trim((string)($row['teacher_name'] ?? ''));
                        $is_self = ((int)$row['id'] === $login_id);
                    ?>
                        <tr data-role="<?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                            <td>
                                <div class="user-name-cell">
                                    <span class="user-avatar-placeholder"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim($row['name']),0,1)), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <div><strong><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if ($is_self): ?><div><span class="badge badge-light border">Tu cuenta</span></div><?php endif; ?></div>
                                </div>
                            </td>
                            <td><span class="text-dark"><i class="far fa-user mr-1 text-muted"></i><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="text-center"><span class="badge <?php echo user_role_badge($row); ?> px-2 py-2"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td>
                                <?php if ((int)$row['type'] === 2): ?>
                                    <?php if ($teacher_name !== ''): ?>
                                        <div><i class="fas fa-chalkboard-teacher mr-1 text-info"></i><?php echo htmlspecialchars($teacher_name, ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php if (($row['teacher_status'] ?? 'Activo') !== 'Activo'): ?><span class="badge badge-warning mt-1">Docente inactivo</span><?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-danger"><i class="fas fa-unlink mr-1"></i>Sin docente vinculado</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($status === 'Activo'): ?>
                                    <span class="badge badge-success px-2 py-2"><span class="status-dot status-active"></span>Activo</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary px-2 py-2"><span class="status-dot status-inactive"></span>Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="dropdown">
                                    <button class="btn btn-light btn-sm border dropdown-toggle" type="button" data-toggle="dropdown" <?php echo $migration_ready ? '' : 'disabled'; ?>><i class="fas fa-ellipsis-v"></i></button>
                                    <div class="dropdown-menu dropdown-menu-right shadow">
                                        <a class="dropdown-item edit-user" href="#"
                                           data-id="<?php echo (int)$row['id']; ?>"
                                           data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                           data-username="<?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                           data-type="<?php echo (int)$row['type']; ?>"
                                           data-director="<?php echo (int)$row['is_director']; ?>"
                                           data-teacher="<?php echo (int)($row['teacher_id'] ?? 0); ?>"
                                           data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-edit text-primary"></i>Editar</a>
                                        <a class="dropdown-item reset-password" href="#" data-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-key text-warning"></i>Restablecer contraseña</a>
                                        <a class="dropdown-item toggle-status <?php echo $is_self ? 'disabled text-muted' : ''; ?>" href="#" data-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas <?php echo $status === 'Activo' ? 'fa-user-slash text-danger' : 'fa-user-check text-success'; ?>"></i><?php echo $status === 'Activo' ? 'Desactivar usuario' : 'Activar usuario'; ?></a>
                                        <a class="dropdown-item user-history" href="#" data-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-history text-info"></i>Ver historial</a>
                                        <?php if ($status === 'Inactivo' && !$is_self): ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger delete-permanent" href="#" data-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-trash-alt text-danger"></i>Eliminar definitivamente</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Crear/Editar -->
<div class="modal fade" id="userModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-user-cog mr-2"></i><span id="userModalTitle">Nuevo usuario</span></h5><button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button></div>
    <form id="userForm">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="user_id" value="">
        <div id="userFormMsg"></div>
        <div class="row">
          <div class="col-md-6 form-group"><label class="font-weight-bold">Nombre completo <span class="text-danger">*</span></label><input type="text" class="form-control" name="name" id="user_name" required></div>
          <div class="col-md-6 form-group"><label class="font-weight-bold">Usuario / correo <span class="text-danger">*</span></label><input type="text" class="form-control" name="username" id="user_username" required autocomplete="off"></div>
        </div>
        <div class="user-modal-section">
          <div class="row">
            <div class="col-md-6 form-group mb-md-0"><label class="font-weight-bold">Rol <span class="text-danger">*</span></label><select class="form-control" name="type" id="user_type" required><option value="">Seleccionar...</option><option value="1">Administrador</option><option value="2">Docente</option><option value="3">Auxiliar</option></select></div>
            <div class="col-md-6 form-group mb-0"><label class="font-weight-bold">Estado</label><select class="form-control" name="status" id="user_status"><option value="Activo">Activo</option><option value="Inactivo">Inactivo</option></select></div>
          </div>
          <div class="form-group mt-3 mb-0" id="directorWrap" style="display:none"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="is_director" name="is_director" value="1"><label class="custom-control-label" for="is_director"><strong>Este usuario es Director</strong><br><small class="text-muted">Mantiene permisos administrativos y se identifica como Director.</small></label></div></div>
          <div class="form-group mt-3 mb-0" id="teacherWrap" style="display:none"><label class="font-weight-bold">Docente vinculado <span class="text-danger">*</span></label><select class="form-control" name="teacher_id" id="teacher_id"><option value="">Seleccionar docente...</option><?php foreach ($teachers as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-status="<?php echo htmlspecialchars($t['status'] ?? 'Activo', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?><?php echo (($t['status'] ?? 'Activo') !== 'Activo') ? ' (Inactivo)' : ''; ?></option><?php endforeach; ?></select><div class="teacher-warning text-muted mt-1"><i class="fas fa-link mr-1"></i>Cada docente puede tener una sola cuenta.</div></div>
        </div>
        <div class="row">
          <div class="col-md-6 form-group"><label class="font-weight-bold">Contraseña <span class="text-danger" id="passwordRequired">*</span></label><div class="input-group"><input type="password" class="form-control password-box" name="password" id="user_password" autocomplete="new-password"><div class="input-group-append"><button class="btn btn-outline-secondary toggle-pass" type="button" data-target="#user_password"><i class="fas fa-eye"></i></button></div></div><small class="form-text text-muted" id="passwordHint">Mínimo 8 caracteres.</small></div>
          <div class="col-md-6 form-group"><label class="font-weight-bold">Repetir contraseña</label><input type="password" class="form-control password-box" id="user_password_repeat" autocomplete="new-password"><small id="passwordMatch" class="form-text"></small></div>
        </div>
        <div class="d-flex flex-wrap align-items-center"><button type="button" class="btn btn-outline-info btn-sm mr-2" id="generatePassword"><i class="fas fa-random mr-1"></i>Generar contraseña</button><button type="button" class="btn btn-outline-secondary btn-sm" id="copyPassword"><i class="far fa-copy mr-1"></i>Copiar</button><span class="small text-muted ml-2">Al editar, deja la contraseña vacía para conservar la actual.</span></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="saveUserBtn"><i class="fas fa-save mr-1"></i>Guardar usuario</button></div>
    </form>
  </div></div>
</div>

<!-- Modal Reset -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-key text-warning mr-2"></i>Restablecer contraseña</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body"><input type="hidden" id="reset_user_id"><p class="mb-2">Usuario: <strong id="reset_user_name"></strong></p><label class="font-weight-bold">Nueva contraseña</label><div class="input-group"><input type="text" class="form-control password-box" id="reset_password"><div class="input-group-append"><button type="button" class="btn btn-outline-info" id="generateResetPassword"><i class="fas fa-random"></i></button><button type="button" class="btn btn-outline-secondary" id="copyResetPassword"><i class="far fa-copy"></i></button></div></div><small class="text-muted">Mínimo 8 caracteres. Entrégala al usuario por un medio seguro.</small></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button><button type="button" class="btn btn-warning" id="confirmResetPassword"><i class="fas fa-key mr-1"></i>Restablecer</button></div>
  </div></div>
</div>

<!-- Modal Historial -->
<div class="modal fade" id="historyModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-history text-info mr-2"></i>Historial de <span id="historyUserName"></span></h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body" id="historyBody"><div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Cargando...</div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button></div>
  </div></div>
</div>

<script>
(function($){
    var csrfToken = <?php echo json_encode($csrf_token); ?>;
    var migrationReady = <?php echo $migration_ready ? 'true' : 'false'; ?>;
    var table = null;

    function notify(message, type){
        if (typeof window.alert_toast === 'function') window.alert_toast(message, type || 'info');
        else alert(message);
    }
    function escapeHtml(value){ return $('<div>').text(value == null ? '' : value).html(); }
    function generateStrongPassword(){
        var upper='ABCDEFGHJKLMNPQRSTUVWXYZ', lower='abcdefghijkmnopqrstuvwxyz', nums='23456789', sym='!@#$%&*?';
        var all=upper+lower+nums+sym, out=upper[Math.floor(Math.random()*upper.length)]+lower[Math.floor(Math.random()*lower.length)]+nums[Math.floor(Math.random()*nums.length)]+sym[Math.floor(Math.random()*sym.length)];
        while(out.length<12) out += all[Math.floor(Math.random()*all.length)];
        return out.split('').sort(function(){return Math.random()-.5;}).join('');
    }
    function copyText(value){
        if(!value){ notify('No hay contraseña para copiar.','warning'); return; }
        if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(value).then(function(){notify('Contraseña copiada.','success');}); }
        else { var tmp=$('<input>').val(value).appendTo('body').select(); document.execCommand('copy'); tmp.remove(); notify('Contraseña copiada.','success'); }
    }
    function refresh(){ window.location.reload(); }
    function api(data, success){
        data.csrf_token = csrfToken;
        $.ajax({url:'users_api.php',method:'POST',data:data,dataType:'json'}).done(function(resp){
            if(resp && resp.status==1) success(resp); else notify((resp&&resp.message)||'No se pudo completar la operación.','danger');
        }).fail(function(xhr){ var msg='Error de conexión.'; try{var r=JSON.parse(xhr.responseText); if(r.message)msg=r.message;}catch(e){} notify(msg,'danger'); });
    }
    function syncRoleFields(){
        var type=$('#user_type').val();
        $('#directorWrap').toggle(type==='1');
        $('#teacherWrap').toggle(type==='2');
        $('#teacher_id').prop('required',type==='2');
        if(type!=='1') $('#is_director').prop('checked',false);
        if(type!=='2') $('#teacher_id').val('');
    }
    function syncPasswordMatch(){
        var p=$('#user_password').val(), r=$('#user_password_repeat').val();
        if(!p&&!r){$('#passwordMatch').text('').removeClass('text-success text-danger');return;}
        var ok=p===r; $('#passwordMatch').text(ok?'Las contraseñas coinciden.':'Las contraseñas no coinciden.').toggleClass('text-success',ok).toggleClass('text-danger',!ok);
    }

    $(function(){
        if($.fn.DataTable){
            table=$('#usersTable').DataTable({pageLength:25,order:[[0,'asc']],language:{search:'Buscar:',lengthMenu:'Mostrar _MENU_',info:'Mostrando _START_ a _END_ de _TOTAL_',infoEmpty:'Sin usuarios',zeroRecords:'No se encontraron usuarios',paginate:{previous:'Anterior',next:'Siguiente'}}});
            $.fn.dataTable.ext.search.push(function(settings,data,index){
                if(settings.nTable.id!=='usersTable') return true;
                var row=table.row(index).node(); if(!row) return true;
                var role=$('#roleFilter').val(), status=$('#statusFilter').val();
                return (!role||$(row).data('role')===role) && (!status||$(row).data('status')===status);
            });
            $('#roleFilter,#statusFilter').on('change',function(){table.draw();});
        }

        $('#new_user').on('click',function(){
            if(!migrationReady)return;
            $('#userForm')[0].reset(); $('#user_id').val(''); $('#userModalTitle').text('Nuevo usuario'); $('#user_status').val('Activo'); $('#user_password').prop('required',true); $('#passwordRequired').show(); $('#passwordHint').text('Mínimo 8 caracteres.'); syncRoleFields(); syncPasswordMatch(); $('#userModal').modal('show');
        });
        $(document).on('click','.edit-user',function(e){
            e.preventDefault(); var b=$(this);
            $('#userForm')[0].reset(); $('#user_id').val(b.data('id')); $('#user_name').val(b.data('name')); $('#user_username').val(b.data('username')); $('#user_type').val(String(b.data('type'))); $('#user_status').val(b.data('status')); $('#is_director').prop('checked',String(b.data('director'))==='1'); $('#teacher_id').val(String(b.data('teacher')||'')); $('#user_password').prop('required',false).val(''); $('#user_password_repeat').val(''); $('#passwordRequired').hide(); $('#passwordHint').text('Déjala en blanco para mantener la contraseña actual.'); $('#userModalTitle').text('Editar usuario'); syncRoleFields(); if(String(b.data('type'))==='2') $('#teacher_id').val(String(b.data('teacher')||'')); syncPasswordMatch(); $('#userModal').modal('show');
        });
        $('#user_type').on('change',syncRoleFields);
        $('#user_password,#user_password_repeat').on('input',syncPasswordMatch);
        $('.toggle-pass').on('click',function(){var input=$($(this).data('target')),icon=$(this).find('i'); input.attr('type',input.attr('type')==='password'?'text':'password'); icon.toggleClass('fa-eye fa-eye-slash');});
        $('#generatePassword').on('click',function(){var p=generateStrongPassword();$('#user_password,#user_password_repeat').val(p);syncPasswordMatch();});
        $('#copyPassword').on('click',function(){copyText($('#user_password').val());});

        $('#userForm').on('submit',function(e){
            e.preventDefault(); var id=$('#user_id').val(), pass=$('#user_password').val(), repeat=$('#user_password_repeat').val();
            if(!id && pass.length<8){notify('La contraseña inicial debe tener al menos 8 caracteres.','warning');return;}
            if(pass && pass.length<8){notify('La contraseña debe tener al menos 8 caracteres.','warning');return;}
            if(pass!==repeat){notify('Las contraseñas no coinciden.','warning');return;}
            var btn=$('#saveUserBtn').prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Guardando...');
            var data=$(this).serialize();
            $.ajax({url:'users_api.php',method:'POST',data:data,dataType:'json'}).done(function(resp){if(resp&&resp.status==1){notify(resp.message,'success');setTimeout(refresh,350);}else{notify((resp&&resp.message)||'No se pudo guardar.','danger');btn.prop('disabled',false).html('<i class="fas fa-save mr-1"></i>Guardar usuario');}}).fail(function(){notify('Error de conexión.','danger');btn.prop('disabled',false).html('<i class="fas fa-save mr-1"></i>Guardar usuario');});
        });

        $(document).on('click','.toggle-status:not(.disabled)',function(e){
            e.preventDefault(); var b=$(this), current=b.data('status'), next=current==='Activo'?'desactivar':'activar';
            if(!confirm('¿Deseas '+next+' al usuario "'+b.data('name')+'"?'))return;
            api({action:'toggle_status',id:b.data('id')},function(resp){notify(resp.message,'success');setTimeout(refresh,350);});
        });

        $(document).on('click','.reset-password',function(e){e.preventDefault();$('#reset_user_id').val($(this).data('id'));$('#reset_user_name').text($(this).data('name'));$('#reset_password').val(generateStrongPassword());$('#resetPasswordModal').modal('show');});
        $('#generateResetPassword').on('click',function(){$('#reset_password').val(generateStrongPassword());});
        $('#copyResetPassword').on('click',function(){copyText($('#reset_password').val());});
        $('#confirmResetPassword').on('click',function(){var p=$('#reset_password').val();if(p.length<8){notify('La contraseña debe tener al menos 8 caracteres.','warning');return;}var btn=$(this).prop('disabled',true);api({action:'reset_password',id:$('#reset_user_id').val(),new_password:p},function(resp){btn.prop('disabled',false);notify(resp.message,'success');$('#resetPasswordModal').modal('hide');});setTimeout(function(){btn.prop('disabled',false);},1500);});

        $(document).on('click','.user-history',function(e){
            e.preventDefault(); var id=$(this).data('id'); $('#historyUserName').text($(this).data('name')); $('#historyBody').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>'); $('#historyModal').modal('show');
            api({action:'history',id:id},function(resp){
                if(!resp.items||!resp.items.length){$('#historyBody').html('<div class="text-center text-muted py-4"><i class="fas fa-history fa-2x mb-2 d-block"></i>Aún no hay movimientos registrados para esta cuenta.</div>');return;}
                var labels={CREATED:'Usuario creado',UPDATED:'Datos actualizados',ACTIVATED:'Usuario activado',DEACTIVATED:'Usuario desactivado',PASSWORD_RESET:'Contraseña restablecida',DELETED:'Usuario eliminado'};
                var html=''; resp.items.forEach(function(item){var detail='';if(item.action==='UPDATED'&&item.details&&item.details.password_changed) detail=' · Contraseña modificada';html+='<div class="history-item"><div class="history-title">'+escapeHtml(labels[item.action]||item.action)+detail+'</div><div class="history-meta"><i class="far fa-clock mr-1"></i>'+escapeHtml(item.created_at)+' · <i class="far fa-user mr-1"></i>'+escapeHtml(item.actor_name||'Sistema')+(item.ip_address?' · IP '+escapeHtml(item.ip_address):'')+'</div></div>';}); $('#historyBody').html(html);
            });
        });

        $(document).on('click','.delete-permanent',function(e){
            e.preventDefault(); var b=$(this); if(!confirm('ELIMINACIÓN DEFINITIVA\n\nSe eliminará la cuenta de "'+b.data('name')+'". Esta acción no se puede deshacer.\n\n¿Deseas continuar?'))return;
            api({action:'delete_permanent',id:b.data('id')},function(resp){notify(resp.message,'success');setTimeout(refresh,350);});
        });
    });
})(jQuery);
</script>
