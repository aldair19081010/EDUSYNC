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
$student_audit_check = $conn->query("SHOW TABLES LIKE 'student_access_audit_log'");
$grade_policy_check = $conn->query("SHOW TABLES LIKE 'school_grade_access_policy'");
$access_migration_ready = (
    $student_hash_check && $student_hash_check->num_rows > 0
    && $student_changed_check && $student_changed_check->num_rows > 0
    && $student_audit_check && $student_audit_check->num_rows > 0
    && $grade_policy_check && $grade_policy_check->num_rows > 0
);

$student_hash_select = ($student_hash_check && $student_hash_check->num_rows > 0)
    ? 'portal_password_hash'
    : "NULL AS portal_password_hash";
$student_changed_select = ($student_changed_check && $student_changed_check->num_rows > 0)
    ? 'password_changed_at'
    : "NULL AS password_changed_at";

$stmt_students = $conn->prepare(
    "SELECT id, id_no, name, nivel, grado, seccion, status,
            {$student_hash_select}, {$student_changed_select}
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



<div class="container-fluid px-0 users-access-shell">
    <div class="user-hero ed-page-header">
        <div class="ed-page-heading">
            <div class="ed-page-icon"><i class="fas fa-users-cog"></i></div>
            <div>
                <h1 class="ed-page-title">Usuarios y Accesos</h1>
                <p class="ed-page-subtitle">Gestiona cuentas del personal, accesos de estudiantes y políticas institucionales de seguridad.</p>
            </div>
        </div>
        <div class="ed-page-actions">
            <button class="btn btn-primary btn-sm" id="new_user" <?php echo $migration_ready ? '' : 'disabled'; ?>>
                <i class="fas fa-user-plus mr-1"></i>Nuevo usuario
            </button>
        </div>
    </div>

    <?php if (!$migration_ready): ?>
        <div class="alert alert-warning shadow-sm">
            <strong><i class="fas fa-database mr-2"></i>Actualización de base de datos pendiente.</strong>
            Para activar el nuevo módulo ejecuta <code>sql/users_module_upgrade.sql</code> en esta base de datos. La lista actual se muestra solo en modo consulta.
        </div>
    <?php endif; ?>

    <div class="users-access-tabs" role="tablist" aria-label="Secciones de usuarios y accesos">
        <button type="button" class="access-tab active" data-access-panel="personal">
            <i class="fas fa-user-shield mr-1"></i>Personal
        </button>
        <button type="button" class="access-tab" data-access-panel="students">
            <i class="fas fa-user-graduate mr-1"></i>Estudiantes
            <span class="badge badge-light ml-1" id="studentAccessTabCount"><?php echo (int)$total_student_access; ?></span>
        </button>
        <button type="button" class="access-tab" data-access-panel="policy">
            <i class="fas fa-lock mr-1"></i>Política de notas
        </button>
    </div>

    <div id="access-personal-panel" class="access-panel active">
    <div class="row users-kpi-row mb-3">
        <div class="col-xl-2 col-md-4 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Total usuarios</div><div class="number ed-stat-value" id="userStatTotal"><?php echo $total_users; ?></div><div class="meta ed-stat-meta">Cuentas registradas</div></div></div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Administradores</div><div class="number ed-stat-value" id="userStatAdmins"><?php echo $total_admin; ?></div><div class="meta ed-stat-meta">Gestión del sistema</div></div></div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Directores</div><div class="number ed-stat-value text-primary" id="userStatDirectors"><?php echo $total_directors; ?></div><div class="meta ed-stat-meta">Dirección institucional</div></div></div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Docentes</div><div class="number ed-stat-value" id="userStatTeachers"><?php echo $total_teachers; ?></div><div class="meta ed-stat-meta">Cuentas docentes</div></div></div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Auxiliares</div><div class="number ed-stat-value" id="userStatAux"><?php echo $total_aux; ?></div><div class="meta ed-stat-meta">Personal auxiliar</div></div></div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-2">
            <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Inactivos</div><div class="number ed-stat-value text-muted" id="userStatInactive"><?php echo $total_inactive; ?></div><div class="meta ed-stat-meta">Sin acceso</div></div></div>
        </div>
    </div>

    <div class="card users-table-card ed-content-card mb-4">
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
                <div class="users-toolbar-note"><i class="fas fa-shield-alt mr-1"></i>Solo administradores pueden modificar cuentas.</div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
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
                    ?>
                        <tr id="user-row-<?php echo (int)$row['id']; ?>"
                            data-user-id="<?php echo (int)$row['id']; ?>"
                            data-role="<?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?>"
                            data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"
                            data-type="<?php echo (int)$row['type']; ?>"
                            data-director="<?php echo (int)$row['is_director']; ?>"
                            data-teacher="<?php echo (int)($row['teacher_id'] ?? 0); ?>"
                            data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-username="<?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?>">
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
                            <td class="text-center ed-actions-cell">
                                <div class="ed-row-actions">
                                    <div class="dropdown">
                                    <button class="btn btn-sm ed-action-more" type="button" data-toggle="dropdown" data-boundary="window" aria-haspopup="true" aria-expanded="false" <?php echo $migration_ready ? '' : 'disabled'; ?>><i class="fas fa-ellipsis-v"></i></button>
                                    <div class="dropdown-menu dropdown-menu-right ed-action-menu">
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

        <div class="row users-kpi-row mb-3">
            <div class="col-xl-3 col-md-6 mb-2">
                <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Estudiantes</div><div class="number ed-stat-value" id="studentStatTotal"><?php echo (int)$total_student_access; ?></div><div class="meta ed-stat-meta">Registrados en el colegio</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 mb-2">
                <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Contraseña propia</div><div class="number ed-stat-value text-success" id="studentStatCustom"><?php echo (int)$total_student_custom_password; ?></div><div class="meta ed-stat-meta">Acceso personalizado</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 mb-2">
                <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">Acceso con DNI</div><div class="number ed-stat-value text-warning" id="studentStatDni"><?php echo (int)$total_student_default_password; ?></div><div class="meta ed-stat-meta">Usan DNI como clave actual</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 mb-2">
                <div class="card user-stat ed-stat-card"><div class="card-body"><div class="label ed-stat-label">No activos</div><div class="number ed-stat-value text-muted" id="studentStatInactive"><?php echo (int)$total_student_inactive; ?></div><div class="meta ed-stat-meta">Retirados, egresados u otros</div></div></div>
            </div>
        </div>

        <div class="card users-table-card ed-content-card mb-4">
            <div class="card-header bg-white py-3">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center">
                    <div>
                        <h6 class="mb-1 font-weight-bold text-gray-800"><i class="fas fa-user-graduate text-primary mr-2"></i>Accesos de estudiantes</h6>
                        <div class="small text-muted">El usuario del estudiante es su DNI/código. Desde aquí puedes restablecer su contraseña sin modificar su matrícula.</div>
                    </div>
                    <div class="small text-muted mt-2 mt-lg-0"><i class="fas fa-shield-alt mr-1"></i>Los restablecimientos quedan auditados.</div>
                </div>
                <div class="users-toolbar mt-3">
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
            <div class="card-body">
                <div class="table-responsive">
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
                                <td class="student-access-changed"><span class="small"><?php echo htmlspecialchars($changed_display, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="text-center">
                                    <span class="badge <?php echo $student_status === 'Activo' ? 'badge-success' : 'badge-secondary'; ?> px-2 py-2"><?php echo htmlspecialchars($student_status !== '' ? $student_status : 'Activo', ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="text-center ed-actions-cell">
                                    <div class="ed-row-actions">
                                        <div class="dropdown">
                                        <button class="btn btn-sm ed-action-more" type="button" data-toggle="dropdown" data-boundary="window" aria-haspopup="true" aria-expanded="false" <?php echo $access_migration_ready ? '' : 'disabled'; ?>><i class="fas fa-ellipsis-v"></i></button>
                                        <div class="dropdown-menu dropdown-menu-right ed-action-menu">
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

        <div class="card policy-card ed-content-card mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-1 font-weight-bold text-gray-800"><i class="fas fa-lock text-warning mr-2"></i>Política institucional de acceso a notas</h6>
                <div class="small text-muted">Cada colegio decide si la deuda restringe la visualización de calificaciones y bajo qué condiciones.</div>
            </div>
            <form id="gradeAccessPolicyForm">
                <div class="card-body">
                    <div class="custom-control custom-switch mb-4">
                        <input type="checkbox" class="custom-control-input" id="block_grades_by_debt" name="block_grades_by_debt" value="1" <?php echo (int)$grade_policy['block_grades_by_debt'] === 1 ? 'checked' : ''; ?> <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                        <label class="custom-control-label" for="block_grades_by_debt">
                            <strong>Bloquear visualización de notas por deuda</strong>
                            <div class="policy-help">Al desactivarlo, las familias podrán consultar notas aunque tengan obligaciones pendientes.</div>
                        </label>
                    </div>

                    <div id="gradePolicyControls">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Deudas que cuentan para el bloqueo</label>
                                <select class="form-control" id="debt_scope" name="debt_scope" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                                    <option value="overdue" <?php echo ($grade_policy['debt_scope'] ?? 'overdue') === 'overdue' ? 'selected' : ''; ?>>Solo conceptos vencidos</option>
                                    <option value="pending" <?php echo ($grade_policy['debt_scope'] ?? '') === 'pending' ? 'selected' : ''; ?>>Todo concepto con saldo pendiente</option>
                                </select>
                                <small class="form-text text-muted">Recomendado: solo vencidos, para que una deuda futura no restrinja notas.</small>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Cantidad mínima de conceptos</label>
                                <input type="number" min="1" max="20" class="form-control" id="minimum_debt_concepts" name="minimum_debt_concepts" value="<?php echo max(1, (int)$grade_policy['minimum_debt_concepts']); ?>" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                                <small class="form-text text-muted">Ejemplo: 2 significa que con 0 o 1 concepto no se bloquean las notas.</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Mensaje para la familia</label>
                            <textarea class="form-control" rows="3" maxlength="500" id="block_message" name="block_message" <?php echo $access_migration_ready ? '' : 'disabled'; ?>><?php echo htmlspecialchars((string)$grade_policy['block_message'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small class="form-text text-muted"><span id="policyMessageCount">0</span>/500 caracteres</small>
                        </div>
                    </div>

                    <div class="policy-preview">
                        <div class="font-weight-bold text-warning mb-1"><i class="fas fa-eye mr-1"></i>Así funcionará</div>
                        <div id="gradePolicyPreview" class="small text-gray-800"></div>
                    </div>
                </div>
                <div class="card-footer bg-white text-right">
                    <button type="submit" class="btn btn-primary" id="saveGradePolicy" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                        <i class="fas fa-save mr-1"></i>Guardar política
                    </button>
                </div>
            </form>
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
          <div class="form-group mt-3 mb-0" id="directorWrap" style="display:none"><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="is_director" name="is_director" value="1"><label class="custom-control-label" for="is_director"><strong>Director institucional</strong><br><small class="text-muted">La cuenta se identificará como Director. Si el rol es Docente, conservará también su vinculación académica.</small></label></div></div>
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
            return {
                '&':'&amp;',
                '<':'&lt;',
                '>':'&gt;',
                '"':'&quot;',
                "'":'&#039;'
            }[ch];
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

    function userInitial(name){
        var value=String(name||'').trim();
        return value ? value.charAt(0).toUpperCase() : 'U';
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

        var nameHtml=
            '<div class="user-name-cell">'
            +'<span class="user-avatar-placeholder">'+escapeHtml(userInitial(name))+'</span>'
            +'<div><strong>'+escapeHtml(name)+'</strong>'
            +(isSelf?'<div><span class="badge badge-light border">Tu cuenta</span></div>':'')
            +'</div></div>';

        var userHtml='<span class="text-dark"><i class="far fa-user mr-1 text-muted"></i>'+escapeHtml(username)+'</span>';
        var roleHtml='<span class="badge '+userRoleBadgeClass(user)+' px-2 py-2">'+escapeHtml(role)+'</span>';

        var linkHtml='<span class="text-muted">—</span>';
        if(type===2){
            if(teacherName){
                linkHtml='<div><i class="fas fa-chalkboard-teacher mr-1 text-info"></i>'+escapeHtml(teacherName)+'</div>'
                    +(teacherStatus!=='Activo'?'<span class="badge badge-warning mt-1">Docente inactivo</span>':'');
            }else{
                linkHtml='<span class="text-danger"><i class="fas fa-unlink mr-1"></i>Sin docente vinculado</span>';
            }
        }

        var statusHtml=status==='Activo'
            ? '<span class="badge badge-success px-2 py-2">Activo</span>'
            : '<span class="badge badge-secondary px-2 py-2">Inactivo</span>';

        var commonData=
            ' data-id="'+id+'"'
            +' data-name="'+escapeAttr(name)+'"'
            +' data-username="'+escapeAttr(username)+'"'
            +' data-type="'+type+'"'
            +' data-director="'+isDirector+'"'
            +' data-teacher="'+teacherId+'"'
            +' data-status="'+escapeAttr(status)+'"';

        var actions=
            '<div class="ed-row-actions"><div class="dropdown">'
            +'<button class="btn btn-sm ed-action-more" type="button" data-toggle="dropdown" data-boundary="window" aria-haspopup="true" aria-expanded="false"><i class="fas fa-ellipsis-v"></i></button>'
            +'<div class="dropdown-menu dropdown-menu-right ed-action-menu">'
            +'<a class="dropdown-item edit-user" href="#"'+commonData+'><i class="fas fa-edit text-primary"></i>Editar</a>'
            +'<a class="dropdown-item reset-password" href="#" data-id="'+id+'" data-name="'+escapeAttr(name)+'"><i class="fas fa-key text-warning"></i>Restablecer contraseña</a>'
            +'<a class="dropdown-item toggle-status'+(isSelf?' disabled text-muted':'')+'" href="#" data-id="'+id+'" data-name="'+escapeAttr(name)+'" data-status="'+escapeAttr(status)+'"><i class="fas '+(status==='Activo'?'fa-user-slash text-danger':'fa-user-check text-success')+'"></i>'+(status==='Activo'?'Desactivar usuario':'Activar usuario')+'</a>'
            +'<a class="dropdown-item user-history" href="#" data-id="'+id+'" data-name="'+escapeAttr(name)+'"><i class="fas fa-history text-info"></i>Ver historial</a>'
            +(status==='Inactivo'&&!isSelf
                ?'<div class="dropdown-divider"></div><a class="dropdown-item ed-action-danger delete-permanent" href="#" data-id="'+id+'" data-name="'+escapeAttr(name)+'"><i class="fas fa-trash-alt"></i>Eliminar definitivamente</a>'
                :'')
            +'</div></div></div>';

        return [nameHtml,userHtml,roleHtml,linkHtml,statusHtml,actions];
    }

    function applyUserRowData($row,user){
        if(!$row||!$row.length) return;
        var role=userRoleLabel(user);
        var status=String(user.status||'Activo');

        $row
            .attr('id','user-row-'+Number(user.id||0))
            .attr('data-user-id',Number(user.id||0))
            .attr('data-role',role)
            .attr('data-status',status)
            .attr('data-type',Number(user.type||0))
            .attr('data-director',Number(user.is_director||0))
            .attr('data-teacher',Number(user.teacher_id||0))
            .attr('data-name',String(user.name||''))
            .attr('data-username',String(user.username||''));

        $row.data({
            userId:Number(user.id||0),
            role:role,
            status:status,
            type:Number(user.type||0),
            director:Number(user.is_director||0),
            teacher:Number(user.teacher_id||0),
            name:String(user.name||''),
            username:String(user.username||'')
        });
    }

    function pulseRow($row){
        if(!$row||!$row.length) return;
        $row.removeClass('user-live-pulse');
        void $row[0].offsetWidth;
        $row.addClass('user-live-pulse');
        window.setTimeout(function(){
            $row.removeClass('user-live-pulse');
        },700);
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
        var $row=$('#user-row-'+id);

        if($row.length){
            var rowApi=table.row($row);
            rowApi.data(cells);
            applyUserRowData($(rowApi.node()),user);
            rowApi.invalidate('dom').draw(false);
            $row=$('#user-row-'+id);
        }else{
            var added=table.row.add(cells);
            added.draw(false);
            $row=$(added.node());
            applyUserRowData($row,user);
            added.invalidate('dom').draw(false);
            $row=$('#user-row-'+id);
        }

        refreshPersonalStats();
        pulseRow($row);
    }

    function removeUserRow(id){
        if(!table) return;
        var $row=$('#user-row-'+Number(id||0));
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
            var access=String($row.attr('data-password')||'DNI');
            var status=String($row.attr('data-status')||'Activo');
            if(access==='Personalizada') custom++;
            else dni++;
            if(status!=='Activo') inactive++;
        });

        $('#studentStatTotal,#studentAccessTabCount').text(total);
        $('#studentStatCustom').text(custom);
        $('#studentStatDni').text(dni);
        $('#studentStatInactive').text(inactive);
    }

    function updateStudentAccessRow(studentId,mode,changedDisplay){
        if(!studentTable) return;

        var $row=$('#student-access-row-'+Number(studentId||0));
        if(!$row.length) return;

        var personalized=mode==='temporary';
        var accessLabel=personalized?'Personalizada':'DNI';
        var accessHtml=personalized
            ? '<span class="student-access-badge custom"><i class="fas fa-key"></i>Personalizada</span>'
            : '<span class="student-access-badge default"><i class="fas fa-id-card"></i>DNI</span>';

        $row
            .attr('data-password',accessLabel)
            .data('password',accessLabel);
        $row.find('.student-access-mode').html(accessHtml);
        $row.find('.student-access-changed').html('<span class="small">'+escapeHtml(changedDisplay||'Ahora')+'</span>');

        studentTable.row($row).invalidate('dom').draw(false);
        refreshStudentStats();
        pulseRow($('#student-access-row-'+Number(studentId||0)));
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
            setTimeout(function(){ table.columns.adjust(); },30);
        }
        if(name==='students' && studentTable){
            setTimeout(function(){ studentTable.columns.adjust(); },30);
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
            ? 'conceptos con saldo pendiente'
            : 'conceptos vencidos';
        var minimum=parseInt($('#minimum_debt_concepts').val()||'2',10);
        if(!minimum || minimum<1) minimum=1;
        var message=$('#block_message').val()||'';

        $('#gradePolicyControls').toggleClass('text-muted',!enabled);
        $('#policyMessageCount').text(message.length);

        if(!enabled){
            $('#gradePolicyPreview').html(
                '<strong>Sin bloqueo:</strong> las notas permanecerán visibles aunque el estudiante tenga deudas.'
            );
            return;
        }

        $('#gradePolicyPreview').html(
            'Las notas se restringirán cuando el estudiante acumule <strong>'
            +minimum+'</strong> o más '+escapeHtml(scope)+'.'
            +(message ? '<div class="mt-2 text-muted"><strong>Mensaje:</strong> '+escapeHtml(message)+'</div>' : '')
        );
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

        $('#block_grades_by_debt,#debt_scope,#minimum_debt_concepts,#block_message')
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

            var btn=$('#saveGradePolicy')
                .prop('disabled',true)
                .html('<i class="fas fa-spinner fa-spin mr-1"></i>Guardando...');

            api(
                {
                    action:'save_grade_access_policy',
                    block_grades_by_debt:enabled?1:0,
                    minimum_debt_concepts:minimum,
                    debt_scope:$('#debt_scope').val(),
                    block_message:$('#block_message').val()
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
