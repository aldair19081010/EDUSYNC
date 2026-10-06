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

$student_hash_check = $conn->query("SHOW COLUMNS FROM student LIKE 'portal_password_hash'");
$student_changed_check = $conn->query("SHOW COLUMNS FROM student LIKE 'password_changed_at'");
$student_last_login_check = $conn->query("SHOW COLUMNS FROM student LIKE 'portal_last_login_at'");
$student_audit_check = $conn->query("SHOW TABLES LIKE 'student_access_audit_log'");
$grade_policy_check = $conn->query("SHOW TABLES LIKE 'school_grade_access_policy'");
$grade_policy_concepts_check = $conn->query("SHOW TABLES LIKE 'school_grade_access_policy_concepts'");
$grade_exception_check = $conn->query("SHOW TABLES LIKE 'student_grade_access_exception'");
$grade_policy_audit_check = $conn->query("SHOW TABLES LIKE 'grade_access_policy_audit_log'");
$grade_grace_check = $conn->query("SHOW COLUMNS FROM school_grade_access_policy LIKE 'grace_days'");
$access_migration_ready = (
    $student_hash_check && $student_hash_check->num_rows > 0
    && $student_changed_check && $student_changed_check->num_rows > 0
    && $student_last_login_check && $student_last_login_check->num_rows > 0
    && $student_audit_check && $student_audit_check->num_rows > 0
    && $grade_policy_check && $grade_policy_check->num_rows > 0
    && $grade_policy_concepts_check && $grade_policy_concepts_check->num_rows > 0
    && $grade_exception_check && $grade_exception_check->num_rows > 0
    && $grade_policy_audit_check && $grade_policy_audit_check->num_rows > 0
    && $grade_grace_check && $grade_grace_check->num_rows > 0
);

$student_hash_select = ($student_hash_check && $student_hash_check->num_rows > 0)
    ? 'portal_password_hash'
    : "NULL AS portal_password_hash";
$student_changed_select = ($student_changed_check && $student_changed_check->num_rows > 0)
    ? 'password_changed_at'
    : "NULL AS password_changed_at";
$student_last_login_select = ($student_last_login_check && $student_last_login_check->num_rows > 0)
    ? 'portal_last_login_at'
    : "NULL AS portal_last_login_at";

$stmt_students = $conn->prepare(
    "SELECT id, id_no, name, nivel, grado, seccion, status,
            {$student_hash_select}, {$student_changed_select}, {$student_last_login_select}
     FROM student
     WHERE school_id = ?
     ORDER BY nivel ASC, grado ASC, seccion ASC, name ASC"
);
$students_access = [];
if ($stmt_students) {
    $stmt_students->bind_param('i', $school_id);
    $stmt_students->execute();
    $res_students = $stmt_students->get_result();
    while ($row = $res_students->fetch_assoc()) $students_access[] = $row;
    $stmt_students->close();
}

$student_levels = [];
$student_grades = [];
$student_sections = [];
$total_student_access = count($students_access);
$total_student_custom_password = 0;
$total_student_default_password = 0;
$total_student_inactive = 0;

foreach ($students_access as $student_access_row) {
    $level = trim((string)($student_access_row['nivel'] ?? ''));
    $grade = trim((string)($student_access_row['grado'] ?? ''));
    $section = trim((string)($student_access_row['seccion'] ?? ''));

    if ($level !== '') $student_levels[$level] = true;
    if ($grade !== '') $student_grades[$grade] = true;
    if ($section !== '') $student_sections[$section] = true;

    if (trim((string)($student_access_row['portal_password_hash'] ?? '')) !== '') {
        $total_student_custom_password++;
    } else {
        $total_student_default_password++;
    }

    if (($student_access_row['status'] ?? 'Activo') !== 'Activo') {
        $total_student_inactive++;
    }
}

$student_levels = array_keys($student_levels);
$student_grades = array_keys($student_grades);
$student_sections = array_keys($student_sections);
natcasesort($student_levels);
natcasesort($student_grades);
natcasesort($student_sections);

$grade_policy = [
    'block_grades_by_debt' => 1,
    'minimum_debt_concepts' => 2,
    'debt_scope' => 'overdue',
    'block_message' => 'Las calificaciones están temporalmente restringidas por obligaciones de pago vencidas. Comunícate con la institución para regularizar tu situación.'
];

if ($grade_policy_check && $grade_policy_check->num_rows > 0) {
    $stmt_policy = $conn->prepare(
        'SELECT block_grades_by_debt, minimum_debt_concepts, debt_scope, block_message, updated_at
         FROM school_grade_access_policy
         WHERE school_id = ?
         LIMIT 1'
    );
    if ($stmt_policy) {
        $stmt_policy->bind_param('i', $school_id);
        $stmt_policy->execute();
        $policy_row = $stmt_policy->get_result()->fetch_assoc();
        $stmt_policy->close();
        if ($policy_row) {
            $grade_policy = array_merge($grade_policy, $policy_row);
        }
    }
}

$status_select = $migration_ready ? 'u.status' : "'Activo' AS status";
$user_last_login_check = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
$user_last_login_select = ($user_last_login_check && $user_last_login_check->num_rows > 0)
    ? 'u.last_login_at'
    : "NULL AS last_login_at";
$sql_users = "SELECT u.id, u.name, u.username, u.type, u.is_director, u.teacher_id, {$status_select}, {$user_last_login_select},
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
    if ((int)$u['is_director'] === 1) {
        $total_directors++;
    } elseif ((int)$u['type'] === 1) {
        $total_admin++;
    } elseif ((int)$u['type'] === 2) {
        $total_teachers++;
    } elseif ((int)$u['type'] === 3) {
        $total_aux++;
    }
    if (($u['status'] ?? 'Activo') !== 'Activo') $total_inactive++;
}

function user_role_label($row) {
    if ((int)$row['is_director'] === 1) return 'Director';
    if ((int)$row['type'] === 1) return 'Administrador';
    if ((int)$row['type'] === 2) return 'Docente';
    if ((int)$row['type'] === 3) return 'Auxiliar';
    return 'Otro';
}
function user_role_badge($row) {
    if ((int)$row['is_director'] === 1) return 'badge-primary';
    if ((int)$row['type'] === 1) return 'badge-danger';
    if ((int)$row['type'] === 2) return 'badge-info';
    if ((int)$row['type'] === 3) return 'badge-success';
    return 'badge-secondary';
}
?>

<style>
.user-hero{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.6rem;padding:1.1rem 1.3rem;margin-bottom:1rem}.user-hero h1{font-size:1.35rem;font-weight:700;color:#344767;margin:0}.user-hero p{font-size:.84rem;color:#7b8499;margin:.2rem 0 0}.user-stat{border:1px solid #e3e6f0;border-radius:.55rem;box-shadow:0 2px 7px rgba(31,45,61,.04);height:100%}.user-stat .card-body{padding:1rem}.user-stat .label{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:#858796;font-weight:700}.user-stat .number{font-size:1.55rem;color:#344767;font-weight:700;line-height:1.2}.user-stat .meta{font-size:.72rem;color:#858796}.users-toolbar{display:grid;gap:.65rem;align-items:end;width:100%}.users-toolbar--personal{grid-template-columns:minmax(180px,220px) minmax(180px,220px) minmax(0,1fr)}.users-toolbar--students{grid-template-columns:repeat(5,minmax(130px,1fr))}.users-toolbar .form-group{margin-bottom:0;min-width:0;width:100%}.users-toolbar .form-control{width:100%}.users-toolbar-note{align-self:end;justify-self:end;padding-bottom:.3rem;font-size:.78rem;color:#858796}.users-table-card{border:1px solid #e3e6f0;border-radius:.6rem;box-shadow:0 2px 8px rgba(31,45,61,.05)}#usersTable td,#usersTable th{vertical-align:middle}.user-name-cell{display:flex;align-items:center;gap:.65rem}.user-avatar-placeholder{width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#f1f4f9;color:#4e73df;font-weight:700;flex:0 0 36px}.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px}.status-active{background:#1cc88a}.status-inactive{background:#e74a3b}.dropdown-menu .dropdown-item i{width:20px}.password-box{font-family:monospace;font-size:1rem}.history-item{border-left:3px solid #4e73df;padding:.7rem .85rem;margin-bottom:.75rem;background:#f8f9fc;border-radius:.25rem}.history-item .history-title{font-weight:700;color:#344767}.history-item .history-meta{font-size:.75rem;color:#858796}.teacher-warning{font-size:.78rem}.user-modal-section{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.45rem;padding:.75rem;margin-bottom:1rem}
.access-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;padding:.35rem;background:#f1f4f9;border:1px solid #e3e6f0;border-radius:.65rem}.access-tab{border:0;background:transparent;color:#6c7587;font-weight:700;font-size:.82rem;padding:.65rem .9rem;border-radius:.5rem;transition:.18s ease}.access-tab:hover{background:#fff;color:#4e73df}.access-tab.active{background:#fff;color:#4e73df;box-shadow:0 2px 8px rgba(31,45,61,.08)}.access-panel{display:none}.access-panel.active{display:block}
.student-access-meta{font-size:.78rem;color:#7b8499}.student-access-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .55rem;border-radius:999px;font-size:.72rem;font-weight:700}.student-access-badge.custom{background:#eafaf4;color:#14855f}.student-access-badge.default{background:#fff8e1;color:#8b6b00}.policy-card{border:1px solid #e3e6f0;border-radius:.65rem;box-shadow:0 2px 8px rgba(31,45,61,.05)}.policy-preview{border-left:4px solid #f6c23e;background:#fffaf0;border-radius:.4rem;padding:.85rem 1rem}.policy-status{font-size:.76rem;font-weight:700}.policy-help{font-size:.78rem;color:#7b8499;line-height:1.45}#studentAccessTable td,#studentAccessTable th{vertical-align:middle}
@media(max-width:1199.98px){.users-toolbar--students{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:991.98px){.users-toolbar--personal{grid-template-columns:repeat(2,minmax(0,1fr))}.users-toolbar-note{grid-column:1/-1;justify-self:start;padding-bottom:0}}@media(max-width:767.98px){.users-toolbar--students{grid-template-columns:repeat(2,minmax(0,1fr))}.user-hero{padding:1rem}.user-stat{margin-bottom:.5rem}.access-tabs{display:grid;grid-template-columns:1fr}.access-tab{width:100%}}@media(max-width:575.98px){.users-toolbar--personal,.users-toolbar--students{grid-template-columns:1fr}}
</style>

<div class="container-fluid px-0">
    <div class="user-hero d-sm-flex align-items-center justify-content-between ed-page-header">
        <div class="ed-page-heading" style="display:block!important">
            <h1 class="ed-page-title"><i class="fas fa-users-cog mr-2 text-primary"></i>Usuarios y Accesos</h1>
            <p class="ed-page-subtitle">Gestiona cuentas del personal, accesos de estudiantes y políticas institucionales de seguridad.</p>
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

    <div class="access-tabs" role="tablist" aria-label="Secciones de usuarios y accesos">
        <button type="button" class="access-tab active" data-access-panel="personal">
            <i class="fas fa-user-shield mr-1"></i>Personal
        </button>
        <button type="button" class="access-tab" data-access-panel="students">
            <i class="fas fa-user-graduate mr-1"></i>Estudiantes
            <span class="badge ed-badge badge-light ml-1" id="studentAccessTabCount"><?php echo (int)$total_student_access; ?></span>
        </button>
        <button type="button" class="access-tab" data-access-panel="policy">
            <i class="fas fa-lock mr-1"></i>Política de notas
        </button>
    </div>

    <div id="access-personal-panel" class="access-panel active">
    <div class="row mb-3">
        <div class="col-xl col-md-4 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Total usuarios</div><div class="number ed-stat-value" id="userStatTotal"><?php echo $total_users; ?></div><div class="meta ed-stat-meta">Cuentas registradas</div></div></div>
        </div>
        <div class="col-xl col-md-4 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Administración</div><div class="number ed-stat-value" id="userStatAdmins"><?php echo $total_admin; ?></div><div class="meta ed-stat-meta"><span id="userStatDirectors"><?php echo $total_directors; ?></span> director(es)</div></div></div>
        </div>
        <div class="col-xl col-md-4 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Docentes</div><div class="number ed-stat-value" id="userStatTeachers"><?php echo $total_teachers; ?></div><div class="meta ed-stat-meta">Cuentas vinculadas</div></div></div>
        </div>
        <div class="col-xl col-md-6 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Auxiliares</div><div class="number ed-stat-value" id="userStatAux"><?php echo $total_aux; ?></div><div class="meta ed-stat-meta">Personal auxiliar</div></div></div>
        </div>
        <div class="col-xl col-md-6 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Inactivos</div><div class="number ed-stat-value" id="userStatInactive"><?php echo $total_inactive; ?></div><div class="meta ed-stat-meta">Sin acceso al sistema</div></div></div>
        </div>
    </div>

    <div class="card users-table-card ed-content-card mb-4">
        <div class="card-header bg-white py-3 ed-content-card-header ed-filter-card">
            <div class="users-toolbar users-toolbar--personal">
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
                <div class="users-toolbar-note"><i class="fas fa-shield-alt mr-1"></i>Solo administradores pueden modificar cuentas.</div>
            </div>
        </div>
        <div class="card-body ed-content-card-body">
            <div class="table-responsive ed-table-responsive">
                <table class="table table-hover table-bordered ed-table" id="usersTable" width="100%">
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
                        $user_last_login = trim((string)($row['last_login_at'] ?? ''));
                        $user_last_login_display = 'Nunca';
                        if ($user_last_login !== '') {
                            try {
                                $user_last_login_display = (new DateTimeImmutable($user_last_login, new DateTimeZone('America/Lima')))->format('d/m/Y H:i');
                            } catch (Exception $e) {
                                $user_last_login_display = $user_last_login;
                            }
                        }
                    ?>
                        <tr id="user-row-<?php echo (int)$row['id']; ?>"
                            data-user-id="<?php echo (int)$row['id']; ?>"
                            data-role="<?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?>"
                            data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"
                            data-type="<?php echo (int)$row['type']; ?>"
                            data-director="<?php echo (int)$row['is_director']; ?>"
                            data-teacher="<?php echo (int)($row['teacher_id'] ?? 0); ?>">
                            <td>
                                <div class="user-name-cell">
                                    <span class="user-avatar-placeholder"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim($row['name']),0,1)), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <div><strong><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if ($is_self): ?><div><span class="badge ed-badge badge-light border">Tu cuenta</span></div><?php endif; ?></div>
                                </div>
                            </td>
                            <td>
                                <span class="text-dark"><i class="far fa-user mr-1 text-muted"></i><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <div class="small text-muted mt-1"><i class="far fa-clock mr-1"></i>Último acceso: <?php echo htmlspecialchars($user_last_login_display, ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td class="text-center"><span class="badge ed-badge <?php echo user_role_badge($row); ?> px-2 py-2"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td>
                                <?php if ((int)$row['type'] === 2): ?>
                                    <?php if ($teacher_name !== ''): ?>
                                        <div><i class="fas fa-chalkboard-teacher mr-1 text-info"></i><?php echo htmlspecialchars($teacher_name, ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php if (($row['teacher_status'] ?? 'Activo') !== 'Activo'): ?><span class="badge ed-badge badge-warning mt-1">Docente inactivo</span><?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-danger"><i class="fas fa-unlink mr-1"></i>Sin docente vinculado</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($status === 'Activo'): ?>
                                    <span class="badge ed-badge badge-success px-2 py-2"><span class="status-dot status-active"></span>Activo</span>
                                <?php else: ?>
                                    <span class="badge ed-badge badge-secondary px-2 py-2"><span class="status-dot status-inactive"></span>Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="dropdown">
                                    <button class="btn btn-light btn-sm border dropdown-toggle ed-icon-btn" type="button" data-toggle="dropdown" <?php echo $migration_ready ? '' : 'disabled'; ?>><i class="fas fa-ellipsis-v"></i></button>
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

    <div id="access-students-panel" class="access-panel">
        <?php if (!$access_migration_ready): ?>
            <div class="alert alert-warning shadow-sm">
                <strong><i class="fas fa-database mr-2"></i>Actualización requerida.</strong>
                Ejecuta <code>sql/access_control_upgrade.sql</code> para administrar contraseñas estudiantiles e historial.
            </div>
        <?php endif; ?>

        <div class="row mb-3">
            <div class="col-xl-3 col-md-6 mb-2">
                <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Estudiantes</div><div class="number ed-stat-value" id="studentStatTotal"><?php echo (int)$total_student_access; ?></div><div class="meta ed-stat-meta">Registrados en el colegio</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 mb-2">
                <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Contraseña propia</div><div class="number text-success ed-stat-value" id="studentStatCustom"><?php echo (int)$total_student_custom_password; ?></div><div class="meta ed-stat-meta">Acceso personalizado</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 mb-2">
                <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Acceso con DNI</div><div class="number text-warning ed-stat-value" id="studentStatDni"><?php echo (int)$total_student_default_password; ?></div><div class="meta ed-stat-meta">Usan DNI como clave actual</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 mb-2">
                <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">No activos</div><div class="number text-muted ed-stat-value" id="studentStatInactive"><?php echo (int)$total_student_inactive; ?></div><div class="meta ed-stat-meta">Retirados, egresados u otros</div></div></div>
            </div>
        </div>

        <div class="card users-table-card ed-content-card mb-4">
            <div class="card-header bg-white py-3 ed-content-card-header ed-filter-card">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center">
                    <div>
                        <h6 class="mb-1 font-weight-bold text-gray-800"><i class="fas fa-user-graduate text-primary mr-2"></i>Accesos de estudiantes</h6>
                        <div class="small text-muted">El usuario del estudiante es su DNI/código. Desde aquí puedes restablecer su contraseña sin modificar su matrícula.</div>
                    </div>
                    <div class="small text-muted mt-2 mt-lg-0"><i class="fas fa-shield-alt mr-1"></i>Los restablecimientos quedan auditados.</div>
                </div>
                <div class="users-toolbar users-toolbar--students mt-3">
                    <div class="form-group">
                        <label class="small font-weight-bold mb-1">Nivel</label>
                        <select class="form-control form-control-sm" id="studentLevelFilter">
                            <option value="">Todos</option>
                            <?php foreach ($student_levels as $value): ?>
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold mb-1">Grado</label>
                        <select class="form-control form-control-sm" id="studentGradeFilter">
                            <option value="">Todos</option>
                            <?php foreach ($student_grades as $value): ?>
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold mb-1">Sección</label>
                        <select class="form-control form-control-sm" id="studentSectionFilter">
                            <option value="">Todas</option>
                            <?php foreach ($student_sections as $value): ?>
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold mb-1">Estado</label>
                        <select class="form-control form-control-sm" id="studentStatusFilter">
                            <option value="">Todos</option>
                            <option value="Activo">Activo</option>
                            <option value="Retirado">Retirado</option>
                            <option value="Egresado">Egresado</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="small font-weight-bold mb-1">Tipo de acceso</label>
                        <select class="form-control form-control-sm" id="studentPasswordFilter">
                            <option value="">Todos</option>
                            <option value="Personalizada">Contraseña propia</option>
                            <option value="DNI">Acceso con DNI</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-body ed-content-card-body">
                <div class="table-responsive ed-table-responsive">
                    <table class="table table-hover table-bordered ed-table" id="studentAccessTable" width="100%">
                        <thead class="thead-light">
                            <tr>
                                <th>Estudiante</th>
                                <th>DNI / código</th>
                                <th>Ubicación académica</th>
                                <th class="text-center">Acceso</th>
                                <th>Último cambio</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center" style="width:90px">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students_access as $student):
                            $has_custom_password = trim((string)($student['portal_password_hash'] ?? '')) !== '';
                            $password_mode = $has_custom_password ? 'Personalizada' : 'DNI';
                            $changed_at = trim((string)($student['password_changed_at'] ?? ''));
                            $changed_display = 'Nunca';
                            if ($changed_at !== '') {
                                try {
                                    $changed_display = (new DateTimeImmutable($changed_at, new DateTimeZone('America/Lima')))->format('d/m/Y H:i');
                                } catch (Exception $e) {
                                    $changed_display = $changed_at;
                                }
                            }
                            $student_last_login = trim((string)($student['portal_last_login_at'] ?? ''));
                            $student_last_login_display = 'Nunca';
                            if ($student_last_login !== '') {
                                try {
                                    $student_last_login_display = (new DateTimeImmutable($student_last_login, new DateTimeZone('America/Lima')))->format('d/m/Y H:i');
                                } catch (Exception $e) {
                                    $student_last_login_display = $student_last_login;
                                }
                            }
                            $student_status = trim((string)($student['status'] ?? 'Activo'));
                        ?>
                            <tr id="student-access-row-<?php echo (int)$student['id']; ?>"
                                data-student-id="<?php echo (int)$student['id']; ?>"
                                data-level="<?php echo htmlspecialchars((string)$student['nivel'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-grade="<?php echo htmlspecialchars((string)$student['grado'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-section="<?php echo htmlspecialchars((string)$student['seccion'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-status="<?php echo htmlspecialchars($student_status, ENT_QUOTES, 'UTF-8'); ?>"
                                data-password="<?php echo $password_mode; ?>">
                                <td>
                                    <div class="user-name-cell">
                                        <span class="user-avatar-placeholder"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim((string)$student['name']), 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <div>
                                            <strong><?php echo htmlspecialchars((string)$student['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <div class="student-access-meta"><?php echo htmlspecialchars((string)$student['nivel'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="font-weight-bold"><?php echo htmlspecialchars((string)$student['id_no'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td>
                                    <?php echo htmlspecialchars(trim((string)$student['grado']) !== '' ? (string)$student['grado'] : '—', ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (trim((string)$student['seccion']) !== ''): ?>
                                        <span class="text-muted">· <?php echo htmlspecialchars((string)$student['seccion'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center student-access-mode">
                                    <?php if ($has_custom_password): ?>
                                        <span class="student-access-badge custom"><i class="fas fa-key"></i>Personalizada</span>
                                    <?php else: ?>
                                        <span class="student-access-badge default"><i class="fas fa-id-card"></i>DNI</span>
                                    <?php endif; ?>
                                </td>
                                <td class="student-access-changed">
                                    <span class="small d-block">Clave: <?php echo htmlspecialchars($changed_display, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="small text-muted d-block">Último acceso: <?php echo htmlspecialchars($student_last_login_display, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge ed-badge <?php echo $student_status === 'Activo' ? 'badge-success' : 'badge-secondary'; ?> px-2 py-2"><?php echo htmlspecialchars($student_status !== '' ? $student_status : 'Activo', ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="dropdown">
                                        <button class="btn btn-light btn-sm border dropdown-toggle ed-icon-btn" type="button" data-toggle="dropdown" <?php echo $access_migration_ready ? '' : 'disabled'; ?>><i class="fas fa-ellipsis-v"></i></button>
                                        <div class="dropdown-menu dropdown-menu-right shadow">
                                            <a class="dropdown-item student-reset-password" href="#"
                                               data-id="<?php echo (int)$student['id']; ?>"
                                               data-name="<?php echo htmlspecialchars((string)$student['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                               data-dni="<?php echo htmlspecialchars((string)$student['id_no'], ENT_QUOTES, 'UTF-8'); ?>"
                                               data-grade="<?php echo htmlspecialchars((string)$student['grado'], ENT_QUOTES, 'UTF-8'); ?>"
                                               data-section="<?php echo htmlspecialchars((string)$student['seccion'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-key text-warning"></i>Restablecer contraseña
                                            </a>
                                            <a class="dropdown-item student-access-history" href="#"
                                               data-id="<?php echo (int)$student['id']; ?>"
                                               data-name="<?php echo htmlspecialchars((string)$student['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-history text-info"></i>Ver historial de acceso
                                            </a>
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

    <div id="access-policy-panel" class="access-panel">
        <?php if (!$access_migration_ready): ?>
            <div class="alert alert-warning shadow-sm">
                <strong><i class="fas fa-database mr-2"></i>Actualización requerida.</strong>
                Ejecuta <code>sql/access_control_upgrade.sql</code> antes de guardar esta política.
            </div>
        <?php endif; ?>

        <form id="gradeAccessPolicyForm">
            <?php include __DIR__ . '/../includes/grade_access_policy_admin_panel.php'; ?>
        </form>
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
          <div class="form-group mt-3 mb-0" id="directorWrap" style="display:none"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="is_director" name="is_director" value="1"><label class="custom-control-label" for="is_director"><strong>Este usuario es Director</strong><br><small class="text-muted">Se identificará como Director. Si es docente, conserva su vinculación académica.</small></label></div></div>
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


<!-- Modal Reset estudiante -->
<div class="modal fade" id="studentResetPasswordModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><i class="fas fa-user-lock text-warning mr-2"></i>Restablecer acceso del estudiante</h5>
      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="student_reset_id">
      <input type="hidden" id="student_reset_dni">
      <div class="mb-3">
        <div class="font-weight-bold" id="student_reset_name"></div>
        <div class="small text-muted" id="student_reset_meta"></div>
      </div>

      <div class="custom-control custom-radio mb-3">
        <input type="radio" class="custom-control-input student-reset-mode" id="student_reset_mode_dni" name="student_reset_mode" value="dni" checked>
        <label class="custom-control-label" for="student_reset_mode_dni">
          <strong>Usar DNI como contraseña</strong>
          <div class="small text-muted">Se elimina la contraseña personalizada y el estudiante vuelve a ingresar con su DNI/código.</div>
        </label>
      </div>

      <div class="custom-control custom-radio">
        <input type="radio" class="custom-control-input student-reset-mode" id="student_reset_mode_temp" name="student_reset_mode" value="temporary">
        <label class="custom-control-label" for="student_reset_mode_temp">
          <strong>Asignar contraseña temporal</strong>
          <div class="small text-muted">Crea una clave distinta al DNI para entregarla al estudiante o apoderado.</div>
        </label>
      </div>

      <div id="studentTemporaryPasswordWrap" class="mt-3" style="display:none">
        <label class="font-weight-bold">Contraseña temporal</label>
        <div class="input-group">
          <input type="text" class="form-control password-box" id="student_temporary_password">
          <div class="input-group-append">
            <button type="button" class="btn btn-outline-info" id="generateStudentPassword" title="Generar"><i class="fas fa-random"></i></button>
            <button type="button" class="btn btn-outline-secondary" id="copyStudentPassword" title="Copiar"><i class="far fa-copy"></i></button>
          </div>
        </div>
        <small class="form-text text-muted">Mínimo 8 caracteres, con al menos una letra y un número.</small>
      </div>

      <div class="alert alert-light border mt-3 mb-0 small">
        <i class="fas fa-info-circle text-primary mr-1"></i>
        Esta acción no modifica la matrícula, notas ni datos académicos del estudiante.
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
      <button type="button" class="btn btn-warning" id="confirmStudentResetPassword"><i class="fas fa-key mr-1"></i>Restablecer acceso</button>
    </div>
  </div></div>
</div>

<!-- Modal historial estudiante -->
<div class="modal fade" id="studentAccessHistoryModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title"><i class="fas fa-history text-info mr-2"></i>Historial de acceso · <span id="studentHistoryName"></span></h5>
      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
    </div>
    <div class="modal-body" id="studentHistoryBody">
      <div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button></div>
  </div></div>
</div>

<script>
(function($){
    var csrfToken = <?php echo json_encode($csrf_token); ?>;
    var migrationReady = <?php echo $migration_ready ? 'true' : 'false'; ?>;
    var accessMigrationReady = <?php echo $access_migration_ready ? 'true' : 'false'; ?>;
    var loginId = <?php echo (int)$login_id; ?>;
    var table = null;
    var studentTable = null;

    function notify(message, type){
        if (typeof window.alert_toast === 'function') window.alert_toast(message, type || 'info');
        else alert(message);
    }

    function escapeHtml(value){
        return $('<div>').text(value == null ? '' : value).html();
    }

    function generateStrongPassword(){
        var upper='ABCDEFGHJKLMNPQRSTUVWXYZ';
        var lower='abcdefghijkmnopqrstuvwxyz';
        var nums='23456789';
        var sym='!@#$%&*?';
        var all=upper+lower+nums+sym;
        var out=upper[Math.floor(Math.random()*upper.length)]
            +lower[Math.floor(Math.random()*lower.length)]
            +nums[Math.floor(Math.random()*nums.length)]
            +sym[Math.floor(Math.random()*sym.length)];
        while(out.length<12) out += all[Math.floor(Math.random()*all.length)];
        return out.split('').sort(function(){return Math.random()-.5;}).join('');
    }

    function copyText(value){
        if(!value){
            notify('No hay contraseña para copiar.','warning');
            return;
        }
        if(navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(value).then(function(){
                notify('Contraseña copiada.','success');
            });
        } else {
            var tmp=$('<input>').val(value).appendTo('body').select();
            document.execCommand('copy');
            tmp.remove();
            notify('Contraseña copiada.','success');
        }
    }

    function escapeAttr(value){
        return String(value == null ? '' : value).replace(/[&<>"']/g,function(ch){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
        });
    }

    function userRoleLabel(user){
        if(Number(user.is_director||0)===1) return 'Director';
        if(Number(user.type||0)===1) return 'Administrador';
        if(Number(user.type||0)===2) return 'Docente';
        if(Number(user.type||0)===3) return 'Auxiliar';
        return 'Otro';
    }

    function userRoleBadgeClass(user){
        if(Number(user.is_director||0)===1) return 'badge-primary';
        if(Number(user.type||0)===1) return 'badge-danger';
        if(Number(user.type||0)===2) return 'badge-info';
        if(Number(user.type||0)===3) return 'badge-success';
        return 'badge-secondary';
    }

    function findUserRow(id){
        if(!table) return $();
        return $(table.rows().nodes()).filter('[data-user-id="'+Number(id||0)+'"]').first();
    }

    function findStudentRow(id){
        if(!studentTable) return $();
        return $(studentTable.rows().nodes()).filter('[data-student-id="'+Number(id||0)+'"]').first();
    }

    function buildUserCells(user){
        var id=Number(user.id||0);
        var name=String(user.name||'');
        var username=String(user.username||'');
        var type=Number(user.type||0);
        var isDirector=Number(user.is_director||0);
        var teacherId=Number(user.teacher_id||0);
        var teacherName=String(user.teacher_name||'').trim();
        var teacherStatus=String(user.teacher_status||'Activo');
        var status=String(user.status||'Activo');
        var isSelf=Boolean(user.is_self)||id===loginId;
        var role=userRoleLabel(user);
        var initial=name.trim()?name.trim().charAt(0).toUpperCase():'U';

        var nameHtml='<div class="user-name-cell">'
            +'<span class="user-avatar-placeholder">'+escapeHtml(initial)+'</span>'
            +'<div><strong>'+escapeHtml(name)+'</strong>'
            +(isSelf?'<div><span class="badge ed-badge badge-light border">Tu cuenta</span></div>':'')
            +'</div></div>';

        var lastLogin=String(user.last_login_at||'').trim();
        var usernameHtml='<span class="text-dark"><i class="far fa-user mr-1 text-muted"></i>'+escapeHtml(username)+'</span>'
            +'<div class="small text-muted mt-1"><i class="far fa-clock mr-1"></i>Último acceso: '+escapeHtml(lastLogin||'Nunca')+'</div>';
        var roleHtml='<span class="badge ed-badge '+userRoleBadgeClass(user)+' px-2 py-2">'+escapeHtml(role)+'</span>';

        var linkHtml='<span class="text-muted">—</span>';
        if(type===2){
            if(teacherName){
                linkHtml='<div><i class="fas fa-chalkboard-teacher mr-1 text-info"></i>'+escapeHtml(teacherName)+'</div>'
                    +(teacherStatus!=='Activo'?'<span class="badge ed-badge badge-warning mt-1">Docente inactivo</span>':'');
            }else{
                linkHtml='<span class="text-danger"><i class="fas fa-unlink mr-1"></i>Sin docente vinculado</span>';
            }
        }

        var statusHtml=status==='Activo'
            ? '<span class="badge ed-badge badge-success px-2 py-2"><span class="status-dot status-active"></span>Activo</span>'
            : '<span class="badge ed-badge badge-secondary px-2 py-2"><span class="status-dot status-inactive"></span>Inactivo</span>';

        var editData=' data-id="'+id+'" data-name="'+escapeAttr(name)+'" data-username="'+escapeAttr(username)+'" data-type="'+type+'" data-director="'+isDirector+'" data-teacher="'+teacherId+'" data-status="'+escapeAttr(status)+'"';

        var actionsHtml='<div class="dropdown">'
            +'<button class="btn btn-light btn-sm border dropdown-toggle ed-icon-btn" type="button" data-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>'
            +'<div class="dropdown-menu dropdown-menu-right shadow">'
            +'<a class="dropdown-item edit-user" href="#"'+editData+'><i class="fas fa-edit text-primary"></i>Editar</a>'
            +'<a class="dropdown-item reset-password" href="#" data-id="'+id+'" data-name="'+escapeAttr(name)+'"><i class="fas fa-key text-warning"></i>Restablecer contraseña</a>'
            +'<a class="dropdown-item toggle-status'+(isSelf?' disabled text-muted':'')+'" href="#" data-id="'+id+'" data-name="'+escapeAttr(name)+'" data-status="'+escapeAttr(status)+'"><i class="fas '+(status==='Activo'?'fa-user-slash text-danger':'fa-user-check text-success')+'"></i>'+(status==='Activo'?'Desactivar usuario':'Activar usuario')+'</a>'
            +'<a class="dropdown-item user-history" href="#" data-id="'+id+'" data-name="'+escapeAttr(name)+'"><i class="fas fa-history text-info"></i>Ver historial</a>'
            +(status==='Inactivo'&&!isSelf?'<div class="dropdown-divider"></div><a class="dropdown-item text-danger delete-permanent" href="#" data-id="'+id+'" data-name="'+escapeAttr(name)+'"><i class="fas fa-trash-alt text-danger"></i>Eliminar definitivamente</a>':'')
            +'</div></div>';

        return [nameHtml,usernameHtml,roleHtml,linkHtml,statusHtml,actionsHtml];
    }

    function applyUserRowData($row,user){
        if(!$row.length) return;
        $row.attr({
            id:'user-row-'+Number(user.id||0),
            'data-user-id':Number(user.id||0),
            'data-role':userRoleLabel(user),
            'data-status':String(user.status||'Activo'),
            'data-type':Number(user.type||0),
            'data-director':Number(user.is_director||0),
            'data-teacher':Number(user.teacher_id||0)
        });
        $row.data({
            userId:Number(user.id||0),
            role:userRoleLabel(user),
            status:String(user.status||'Activo'),
            type:Number(user.type||0),
            director:Number(user.is_director||0),
            teacher:Number(user.teacher_id||0)
        });
    }

    function refreshPersonalStats(){
        if(!table) return;
        var total=0,admins=0,directors=0,teachers=0,aux=0,inactive=0;

        $(table.rows().nodes()).each(function(){
            var $row=$(this);
            total++;
            var role=String($row.attr('data-role')||'');
            var status=String($row.attr('data-status')||'Activo');

            if(role==='Director') directors++;
            else if(role==='Administrador') admins++;
            else if(role==='Docente') teachers++;
            else if(role==='Auxiliar') aux++;

            if(status!=='Activo') inactive++;
        });

        $('#userStatTotal').text(total);
        $('#userStatAdmins').text(admins);
        $('#userStatDirectors').text(directors);
        $('#userStatTeachers').text(teachers);
        $('#userStatAux').text(aux);
        $('#userStatInactive').text(inactive);
    }

    function upsertUserRow(user){
        if(!table||!user) return;

        var id=Number(user.id||0);
        var cells=buildUserCells(user);
        var $row=findUserRow(id);

        if($row.length){
            table.row($row).data(cells).draw(false);
            $row=findUserRow(id);
            applyUserRowData($row,user);
        }else{
            var rowApi=table.row.add(cells);
            rowApi.draw(false);
            $row=$(rowApi.node());
            applyUserRowData($row,user);
        }

        refreshPersonalStats();
    }

    function removeUserRow(id){
        var $row=findUserRow(id);
        if(!$row.length) return;
        table.row($row).remove().draw(false);
        refreshPersonalStats();
    }

    function refreshStudentStats(){
        if(!studentTable) return;
        var total=0,custom=0,dni=0,inactive=0;

        $(studentTable.rows().nodes()).each(function(){
            var $row=$(this);
            total++;
            if(String($row.attr('data-password')||'DNI')==='Personalizada') custom++;
            else dni++;
            if(String($row.attr('data-status')||'Activo')!=='Activo') inactive++;
        });

        $('#studentStatTotal,#studentAccessTabCount').text(total);
        $('#studentStatCustom').text(custom);
        $('#studentStatDni').text(dni);
        $('#studentStatInactive').text(inactive);
    }

    function updateStudentAccessRow(studentId,mode,changedDisplay){
        var $row=findStudentRow(studentId);
        if(!$row.length) return;

        var personalized=mode==='temporary';
        var accessLabel=personalized?'Personalizada':'DNI';
        var accessHtml=personalized
            ? '<span class="student-access-badge custom"><i class="fas fa-key"></i>Personalizada</span>'
            : '<span class="student-access-badge default"><i class="fas fa-id-card"></i>DNI</span>';

        $row.attr('data-password',accessLabel).data('password',accessLabel);
        $row.find('.student-access-mode').html(accessHtml);
        var changedCell=$row.find('.student-access-changed');
        var firstLine=changedCell.find('span').first();
        if(firstLine.length) firstLine.text('Clave: '+(changedDisplay||'Ahora'));
        else changedCell.prepend('<span class="small d-block">Clave: '+escapeHtml(changedDisplay||'Ahora')+'</span>');
        studentTable.row($row).invalidate('dom').draw(false);
        refreshStudentStats();
    }

    function api(data, success, fail){
        data.csrf_token = csrfToken;
        $.ajax({
            url:'users_api.php',
            method:'POST',
            data:data,
            dataType:'json'
        }).done(function(resp){
            if(resp && resp.status==1){
                success(resp);
            } else {
                var message=(resp&&resp.message)||'No se pudo completar la operación.';
                notify(message,'danger');
                if(typeof fail==='function') fail(resp);
            }
        }).fail(function(xhr){
            var msg='Error de conexión.';
            try{
                var r=JSON.parse(xhr.responseText);
                if(r.message) msg=r.message;
            }catch(e){}
            notify(msg,'danger');
            if(typeof fail==='function') fail();
        });
    }

    function showAccessPanel(name){
        $('.access-panel').removeClass('active');
        $('#access-'+name+'-panel').addClass('active');
        $('.access-tab').removeClass('active').filter('[data-access-panel="'+name+'"]').addClass('active');
        $('#new_user').toggle(name==='personal');

        if(name==='personal' && table){
            table.columns.adjust();
        }
        if(name==='students' && studentTable){
            studentTable.columns.adjust();
        }
    }

    function syncRoleFields(){
        var type=$('#user_type').val();
        var canBeDirector=(type==='1'||type==='2');
        $('#directorWrap').toggle(canBeDirector);
        $('#teacherWrap').toggle(type==='2');
        $('#teacher_id').prop('required',type==='2');
        if(!canBeDirector) $('#is_director').prop('checked',false);
        if(type!=='2') $('#teacher_id').val('');
    }

    function syncPasswordMatch(){
        var p=$('#user_password').val();
        var r=$('#user_password_repeat').val();
        if(!p&&!r){
            $('#passwordMatch').text('').removeClass('text-success text-danger');
            return;
        }
        var ok=p===r;
        $('#passwordMatch')
            .text(ok?'Las contraseñas coinciden.':'Las contraseñas no coinciden.')
            .toggleClass('text-success',ok)
            .toggleClass('text-danger',!ok);
    }

    function updateStudentResetMode(){
        var mode=$('input[name="student_reset_mode"]:checked').val()||'dni';
        var temporary=mode==='temporary';
        $('#studentTemporaryPasswordWrap').toggle(temporary);
        if(temporary && !$('#student_temporary_password').val()){
            $('#student_temporary_password').val(generateStrongPassword());
        }
    }

    function updateGradePolicyPreview(){
        var enabled=$('#block_grades_by_debt').is(':checked');
        var scope=$('#debt_scope').val()==='pending'
            ? 'deudas pendientes'
            : 'deudas vencidas';
        var minimum=parseInt($('#minimum_debt_concepts').val()||'2',10);
        var grace=parseInt($('#gradeGraceDays').val()||'0',10);
        var limited=$('#limitGradeDebtConcepts').is(':checked');
        var selected=$('#gradeConceptIds').val()||[];
        var message=$('#block_message').val()||'';

        if(!minimum || minimum<1) minimum=1;
        if(!grace || grace<0) grace=0;

        $('#gradePolicyControls').toggleClass('text-muted',!enabled);
        $('#policyMessageCount').text(message.length);

        if(!enabled){
            $('#gradePolicyPreview').html(
                '<strong>Sin bloqueo:</strong> las notas permanecerán visibles aunque el estudiante tenga deudas.'
            );
            return;
        }

        var html='El estudiante será bloqueado cuando acumule <strong>'+minimum+' '+(minimum===1?'deuda':'deudas')+'</strong> '
            +escapeHtml(scope)+'.';

        if($('#debt_scope').val()==='overdue'){
            html+=' '+(grace>0
                ? 'Cada deuda empezará a contar <strong>'+grace+' día'+(grace===1?'':'s')+'</strong> después de su vencimiento.'
                : 'La deuda contará desde que quede vencida.');
        }

        html+=' <strong>'+(limited?'Solo los conceptos seleccionados':'Todas las deudas')+'</strong> se tomarán en cuenta.';
        if(limited && !selected.length){
            html+='<div class="mt-2 text-warning"><i class="fas fa-exclamation-triangle mr-1"></i>Selecciona al menos un concepto.</div>';
        }

        if(message){
            html+='<div class="mt-2 text-muted"><strong>Mensaje:</strong> '+escapeHtml(message)+'</div>';
        }

        $('#gradePolicyPreview').html(html);
    }

    $(function(){
        $('.access-tab').on('click',function(){
            showAccessPanel($(this).data('access-panel'));
        });

        if($.fn.DataTable){
            table=$('#usersTable').DataTable({
                pageLength:25,
                order:[[0,'asc']],
                language:{
                    search:'Buscar:',
                    lengthMenu:'Mostrar _MENU_',
                    info:'Mostrando _START_ a _END_ de _TOTAL_',
                    infoEmpty:'Sin usuarios',
                    zeroRecords:'No se encontraron usuarios',
                    paginate:{previous:'Anterior',next:'Siguiente'}
                }
            });

            studentTable=$('#studentAccessTable').DataTable({
                pageLength:25,
                order:[[0,'asc']],
                language:{
                    search:'Buscar estudiante o DNI:',
                    lengthMenu:'Mostrar _MENU_',
                    info:'Mostrando _START_ a _END_ de _TOTAL_ estudiantes',
                    infoEmpty:'Sin estudiantes',
                    zeroRecords:'No se encontraron estudiantes con esos criterios',
                    paginate:{previous:'Anterior',next:'Siguiente'}
                }
            });

            $('#usersTable_wrapper,#studentAccessTable_wrapper').addClass('ed-datatable');
            $('#usersTable_wrapper .pagination,#studentAccessTable_wrapper .pagination').addClass('ed-pagination');

            $.fn.dataTable.ext.search.push(function(settings,data,index){
                if(settings.nTable.id==='usersTable'){
                    var row=table.row(index).node();
                    if(!row) return true;
                    var role=$('#roleFilter').val();
                    var status=$('#statusFilter').val();
                    return (!role||$(row).data('role')===role)
                        && (!status||$(row).data('status')===status);
                }

                if(settings.nTable.id==='studentAccessTable'){
                    var studentRow=studentTable.row(index).node();
                    if(!studentRow) return true;
                    var level=$('#studentLevelFilter').val();
                    var grade=$('#studentGradeFilter').val();
                    var section=$('#studentSectionFilter').val();
                    var studentStatus=$('#studentStatusFilter').val();
                    var password=$('#studentPasswordFilter').val();

                    return (!level||$(studentRow).data('level')===level)
                        && (!grade||$(studentRow).data('grade')===grade)
                        && (!section||$(studentRow).data('section')===section)
                        && (!studentStatus||$(studentRow).data('status')===studentStatus)
                        && (!password||$(studentRow).data('password')===password);
                }

                return true;
            });

            $('#roleFilter,#statusFilter').on('change',function(){
                table.draw();
            });

            $('#studentLevelFilter,#studentGradeFilter,#studentSectionFilter,#studentStatusFilter,#studentPasswordFilter').on('change',function(){
                studentTable.draw();
            });

            refreshPersonalStats();
            refreshStudentStats();
        }

        $('#new_user').on('click',function(){
            if(!migrationReady)return;
            $('#userForm')[0].reset();
            $('#user_id').val('');
            $('#userModalTitle').text('Nuevo usuario');
            $('#user_status').val('Activo');
            $('#user_password').prop('required',true);
            $('#passwordRequired').show();
            $('#passwordHint').text('Mínimo 8 caracteres.');
            syncRoleFields();
            syncPasswordMatch();
            $('#userModal').modal('show');
        });

        $(document).on('click','.edit-user',function(e){
            e.preventDefault();
            var b=$(this);
            $('#userForm')[0].reset();
            $('#user_id').val(b.data('id'));
            $('#user_name').val(b.data('name'));
            $('#user_username').val(b.data('username'));
            $('#user_type').val(String(b.data('type')));
            $('#user_status').val(b.data('status'));
            $('#is_director').prop('checked',String(b.data('director'))==='1');
            $('#teacher_id').val(String(b.data('teacher')||''));
            $('#user_password').prop('required',false).val('');
            $('#user_password_repeat').val('');
            $('#passwordRequired').hide();
            $('#passwordHint').text('Déjala en blanco para mantener la contraseña actual.');
            $('#userModalTitle').text('Editar usuario');
            syncRoleFields();
            if(String(b.data('type'))==='2') $('#teacher_id').val(String(b.data('teacher')||''));
            syncPasswordMatch();
            $('#userModal').modal('show');
        });

        $('#user_type').on('change',syncRoleFields);
        $('#user_password,#user_password_repeat').on('input',syncPasswordMatch);

        $('.toggle-pass').on('click',function(){
            var input=$($(this).data('target'));
            var icon=$(this).find('i');
            input.attr('type',input.attr('type')==='password'?'text':'password');
            icon.toggleClass('fa-eye fa-eye-slash');
        });

        $('#generatePassword').on('click',function(){
            var p=generateStrongPassword();
            $('#user_password,#user_password_repeat').val(p);
            syncPasswordMatch();
        });

        $('#copyPassword').on('click',function(){
            copyText($('#user_password').val());
        });

        $('#userForm').on('submit',function(e){
            e.preventDefault();
            var id=$('#user_id').val();
            var pass=$('#user_password').val();
            var repeat=$('#user_password_repeat').val();

            if(!id && pass.length<8){
                notify('La contraseña inicial debe tener al menos 8 caracteres.','warning');
                return;
            }
            if(pass && pass.length<8){
                notify('La contraseña debe tener al menos 8 caracteres.','warning');
                return;
            }
            if(pass!==repeat){
                notify('Las contraseñas no coinciden.','warning');
                return;
            }

            var btn=$('#saveUserBtn')
                .prop('disabled',true)
                .html('<i class="fas fa-spinner fa-spin mr-1"></i>Guardando...');
            var data=$(this).serialize();

            $.ajax({
                url:'users_api.php',
                method:'POST',
                data:data,
                dataType:'json'
            }).done(function(resp){
                btn.prop('disabled',false).html('<i class="fas fa-save mr-1"></i>Guardar usuario');
                if(resp&&resp.status==1){
                    if(resp.user) upsertUserRow(resp.user);
                    notify(resp.message,'success');
                    $('#userModal').modal('hide');
                }else{
                    notify((resp&&resp.message)||'No se pudo guardar.','danger');
                }
            }).fail(function(){
                notify('Error de conexión.','danger');
                btn.prop('disabled',false).html('<i class="fas fa-save mr-1"></i>Guardar usuario');
            });
        });

        $(document).on('click','.toggle-status:not(.disabled)',function(e){
            e.preventDefault();
            var b=$(this);
            var current=b.data('status');
            var next=current==='Activo'?'desactivar':'activar';
            if(!confirm('¿Deseas '+next+' al usuario "'+b.data('name')+'"?'))return;
            api(
                {action:'toggle_status',id:b.data('id')},
                function(resp){
                    if(resp.user) upsertUserRow(resp.user);
                    notify(resp.message,'success');
                }
            );
        });

        $(document).on('click','.reset-password',function(e){
            e.preventDefault();
            $('#reset_user_id').val($(this).data('id'));
            $('#reset_user_name').text($(this).data('name'));
            $('#reset_password').val(generateStrongPassword());
            $('#resetPasswordModal').modal('show');
        });

        $('#generateResetPassword').on('click',function(){
            $('#reset_password').val(generateStrongPassword());
        });

        $('#copyResetPassword').on('click',function(){
            copyText($('#reset_password').val());
        });

        $('#confirmResetPassword').on('click',function(){
            var p=$('#reset_password').val();
            if(p.length<8){
                notify('La contraseña debe tener al menos 8 caracteres.','warning');
                return;
            }
            var btn=$(this).prop('disabled',true);
            api(
                {
                    action:'reset_password',
                    id:$('#reset_user_id').val(),
                    new_password:p
                },
                function(resp){
                    btn.prop('disabled',false);
                    notify(resp.message,'success');
                    $('#resetPasswordModal').modal('hide');
                },
                function(){btn.prop('disabled',false);}
            );
        });

        $(document).on('click','.user-history',function(e){
            e.preventDefault();
            var id=$(this).data('id');
            $('#historyUserName').text($(this).data('name'));
            $('#historyBody').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>');
            $('#historyModal').modal('show');

            api({action:'history',id:id},function(resp){
                if(!resp.items||!resp.items.length){
                    $('#historyBody').html('<div class="text-center text-muted py-4"><i class="fas fa-history fa-2x mb-2 d-block"></i>Aún no hay movimientos registrados para esta cuenta.</div>');
                    return;
                }

                var labels={
                    CREATED:'Usuario creado',
                    UPDATED:'Datos actualizados',
                    ACTIVATED:'Usuario activado',
                    DEACTIVATED:'Usuario desactivado',
                    PASSWORD_RESET:'Contraseña restablecida',
                    DELETED:'Usuario eliminado',
                    GRADE_ACCESS_POLICY_UPDATED:'Política de notas actualizada'
                };

                var html='';
                resp.items.forEach(function(item){
                    var detail='';
                    if(item.action==='UPDATED'&&item.details&&item.details.password_changed){
                        detail=' · Contraseña modificada';
                    }
                    html+='<div class="history-item"><div class="history-title">'
                        +escapeHtml(labels[item.action]||item.action)+detail
                        +'</div><div class="history-meta"><i class="far fa-clock mr-1"></i>'
                        +escapeHtml(item.created_at)
                        +' · <i class="far fa-user mr-1"></i>'
                        +escapeHtml(item.actor_name||'Sistema')
                        +(item.ip_address?' · IP '+escapeHtml(item.ip_address):'')
                        +'</div></div>';
                });
                $('#historyBody').html(html);
            });
        });

        $(document).on('click','.student-reset-password',function(e){
            e.preventDefault();
            if(!accessMigrationReady) return;

            var b=$(this);
            $('#student_reset_id').val(b.data('id'));
            $('#student_reset_dni').val(b.data('dni'));
            $('#student_reset_name').text(b.data('name'));
            $('#student_reset_meta').text(
                'DNI/código: '+b.data('dni')
                +(b.data('grade')?' · '+b.data('grade'):'')
                +(b.data('section')?' '+b.data('section'):'')
            );
            $('#student_reset_mode_dni').prop('checked',true);
            $('#student_temporary_password').val('');
            updateStudentResetMode();
            $('#studentResetPasswordModal').modal('show');
        });

        $('.student-reset-mode').on('change',updateStudentResetMode);

        $('#generateStudentPassword').on('click',function(){
            $('#student_temporary_password').val(generateStrongPassword());
        });

        $('#copyStudentPassword').on('click',function(){
            copyText($('#student_temporary_password').val());
        });

        $('#confirmStudentResetPassword').on('click',function(){
            var mode=$('input[name="student_reset_mode"]:checked').val()||'dni';
            var password=$('#student_temporary_password').val();
            var studentName=$('#student_reset_name').text();

            if(mode==='temporary'){
                if(password.length<8 || !/[A-Za-z]/.test(password) || !/\d/.test(password)){
                    notify('La contraseña temporal debe tener al menos 8 caracteres, una letra y un número.','warning');
                    return;
                }
                if(password===$('#student_reset_dni').val()){
                    notify('La contraseña temporal no puede ser igual al DNI.','warning');
                    return;
                }
            }

            var question=mode==='dni'
                ? '¿Restablecer el acceso de "'+studentName+'" para que vuelva a ingresar con su DNI?'
                : '¿Asignar la contraseña temporal mostrada a "'+studentName+'"?';
            if(!confirm(question)) return;

            var btn=$(this).prop('disabled',true);
            api(
                {
                    action:'reset_student_password',
                    student_id:$('#student_reset_id').val(),
                    mode:mode,
                    new_password:mode==='temporary'?password:''
                },
                function(resp){
                    btn.prop('disabled',false);
                    updateStudentAccessRow(
                        $('#student_reset_id').val(),
                        resp.mode||mode,
                        resp.password_changed_at_display||resp.password_changed_at||'Ahora'
                    );
                    notify(resp.message,'success');
                    $('#studentResetPasswordModal').modal('hide');
                },
                function(){btn.prop('disabled',false);}
            );
        });

        $(document).on('click','.student-access-history',function(e){
            e.preventDefault();
            var id=$(this).data('id');
            $('#studentHistoryName').text($(this).data('name'));
            $('#studentHistoryBody').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>');
            $('#studentAccessHistoryModal').modal('show');

            api({action:'student_history',student_id:id},function(resp){
                if(!resp.items||!resp.items.length){
                    $('#studentHistoryBody').html('<div class="text-center text-muted py-4"><i class="fas fa-history fa-2x mb-2 d-block"></i>Aún no hay restablecimientos registrados para este estudiante.</div>');
                    return;
                }

                var labels={
                    PASSWORD_RESET_DNI:'Acceso restablecido al DNI',
                    PASSWORD_RESET_TEMPORARY:'Contraseña temporal asignada'
                };
                var html='';

                resp.items.forEach(function(item){
                    html+='<div class="history-item"><div class="history-title">'
                        +escapeHtml(labels[item.action]||item.action)
                        +'</div><div class="history-meta"><i class="far fa-clock mr-1"></i>'
                        +escapeHtml(item.created_at)
                        +' · <i class="far fa-user mr-1"></i>'
                        +escapeHtml(item.actor_name||'Sistema')
                        +(item.ip_address?' · IP '+escapeHtml(item.ip_address):'')
                        +'</div></div>';
                });

                $('#studentHistoryBody').html(html);
            });
        });

        $('#block_grades_by_debt,#debt_scope,#minimum_debt_concepts,#gradeGraceDays,#limitGradeDebtConcepts,#gradeConceptIds,#block_message')
            .on('change input',updateGradePolicyPreview);
        updateGradePolicyPreview();

        $('#gradeAccessPolicyForm').on('submit',function(e){
            e.preventDefault();
            if(!accessMigrationReady) return;

            var enabled=$('#block_grades_by_debt').is(':checked');
            var minimum=parseInt($('#minimum_debt_concepts').val()||'0',10);
            if(minimum<1||minimum>20){
                notify('La cantidad mínima debe estar entre 1 y 20.','warning');
                return;
            }

            if($('#limitGradeDebtConcepts').is(':checked') && !($('#gradeConceptIds').val()||[]).length){
                notify('Selecciona al menos un concepto o desactiva "Usar solo conceptos específicos".','warning');
                return;
            }

            var btn=$('#saveGradePolicy')
                .prop('disabled',true)
                .html('<i class="fas fa-spinner fa-spin mr-1"></i>Guardando...');

            api(
                {
                    action:'save_grade_access_policy',
                    block_grades_by_debt:enabled?1:0,
                    minimum_debt_concepts:minimum,
                    debt_scope:$('#debt_scope').val(),
                    block_message:$('#block_message').val(),
                    grace_days:$('#gradeGraceDays').val()||0,
                    temporary_access_until:$('#gradeTemporaryUntil').val()||'',
                    grace_message:$('#gradeGraceMessage').val()||'',
                    temporary_message:$('#gradeTemporaryMessage').val()||'',
                    exception_message:$('#gradeExceptionMessage').val()||'',
                    concept_ids:$('#gradeConceptIds').val()||[]
                },
                function(resp){
                    btn.prop('disabled',false).html('<i class="fas fa-save mr-1"></i>Guardar política');
                    notify(resp.message,'success');
                    updateGradePolicyPreview();
                },
                function(){
                    btn.prop('disabled',false).html('<i class="fas fa-save mr-1"></i>Guardar política');
                }
            );
        });

        $(document).on('click','.delete-permanent',function(e){
            e.preventDefault();
            var b=$(this);
            if(!confirm(
                'ELIMINACIÓN DEFINITIVA\n\n'
                +'Se eliminará la cuenta de "'+b.data('name')+'". '
                +'Esta acción no se puede deshacer.\n\n¿Deseas continuar?'
            )) return;

            api(
                {action:'delete_permanent',id:b.data('id')},
                function(resp){
                    removeUserRow(resp.id||b.data('id'));
                    notify(resp.message,'success');
                }
            );
        });
    });
})(jQuery);
</script>
