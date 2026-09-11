<?php
ini_set('session.save_path', __DIR__ . '/tmp');
if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) session_start();

if ((int)($_SESSION['login_type'] ?? 0) !== 5 || empty($_SESSION['login_id']) || empty($_SESSION['login_school_id'])) {
    header('Location: login.php');
    exit;
}

require __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/guardian_portal_context.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $ctx = guardian_portal_context($conn);
} catch (Throwable $e) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?guardian_error=1');
    exit;
}

$allowedViews = ['home','grades','attendance','payments','debts','communications'];
$view = trim((string)($_GET['view'] ?? 'home'));
if (!in_array($view, $allowedViews, true)) $view = 'home';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'switch_student') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
        $_SESSION['guardian_portal_message'] = ['type' => 'danger', 'text' => 'La sesión de seguridad venció. Recarga la página.'];
    } else {
        $studentId = (int)($_POST['student_id'] ?? 0);
        if (guardian_portal_set_active_student($conn, $studentId)) {
            $_SESSION['guardian_portal_message'] = ['type' => 'success', 'text' => 'Estudiante seleccionado correctamente.'];
        } else {
            $_SESSION['guardian_portal_message'] = ['type' => 'danger', 'text' => 'Ese estudiante no está vinculado a tu cuenta.'];
        }
    }
    $returnView = trim((string)($_POST['return_view'] ?? 'home'));
    if (!in_array($returnView, $allowedViews, true)) $returnView = 'home';
    header('Location: guardian_portal.php?view=' . urlencode($returnView));
    exit;
}

$ctx = guardian_portal_context($conn);
$guardian = $ctx['guardian'];
$children = $ctx['children'];
$active = $ctx['active_student'];
$guardianName = guardian_portal_guardian_name($guardian);
$message = $_SESSION['guardian_portal_message'] ?? null;
unset($_SESSION['guardian_portal_message']);

function gp_money($value): string {
    return 'S/ ' . number_format((float)$value, 2, '.', ',');
}

function gp_dashboard_metrics(mysqli $conn, ?array $student): array
{
    if (!$student) return ['grades' => 0, 'attendance' => 0, 'late' => 0, 'payments' => 0.0, 'debt' => 0.0];
    $studentId = (int)$student['student_id'];
    $metrics = ['grades' => 0, 'attendance' => 0, 'late' => 0, 'payments' => 0.0, 'debt' => 0.0];

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM evaluation_grades WHERE student_id=? AND grade IS NOT NULL AND TRIM(grade)<>''");
    if ($stmt) {
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $metrics['grades'] = (int)($row['c'] ?? 0);
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) total,
        SUM(CASE WHEN LOWER(TRIM(estado))='tarde' THEN 1 ELSE 0 END) late
        FROM asistencia WHERE student_id=? AND fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    if ($stmt) {
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $metrics['attendance'] = (int)($row['total'] ?? 0);
        $metrics['late'] = (int)($row['late'] ?? 0);
        $stmt->close();
    }

    $stmt = $conn->prepare('SELECT COALESCE(SUM(p.amount),0) total FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id WHERE ef.student_id=?');
    if ($stmt) {
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $metrics['payments'] = (float)($row['total'] ?? 0);
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM(GREATEST((CASE WHEN ef.discounted_amount IS NULL THEN ef.total_fee ELSE ef.discounted_amount END) - COALESCE(pp.paid,0),0)),0) debt
        FROM student_ef_list ef
        LEFT JOIN (SELECT ef_id,SUM(amount) paid FROM payments GROUP BY ef_id) pp ON pp.ef_id=ef.id
        WHERE ef.student_id=?");
    if ($stmt) {
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $metrics['debt'] = (float)($row['debt'] ?? 0);
        $stmt->close();
    }

    return $metrics;
}

function gp_include_student_page(string $path, mysqli $conn, array $active): void
{
    $hadStudentLoggedIn = array_key_exists('student_logged_in', $_SESSION);
    $previousStudentLoggedIn = $_SESSION['student_logged_in'] ?? null;
    $_SESSION['student_logged_in'] = true;
    $_SESSION['student_id'] = (int)$active['student_id'];
    $_SESSION['student_name'] = (string)$active['name'];
    $_SESSION['student_dni'] = (string)$active['id_no'];
    $_SESSION['student_school_id'] = (int)($_SESSION['login_school_id'] ?? 0);

    include $path;

    if ($hadStudentLoggedIn) {
        $_SESSION['student_logged_in'] = $previousStudentLoggedIn;
    } else {
        unset($_SESSION['student_logged_in']);
    }
}

$metrics = $view === 'home' ? gp_dashboard_metrics($conn, $active) : [];
$permissionMap = [
    'grades' => 'can_view_grades',
    'attendance' => 'can_view_attendance',
    'payments' => 'can_view_payments',
    'debts' => 'can_view_payments',
    'communications' => 'can_receive_communications',
];
$permissionGranted = true;
if (isset($permissionMap[$view])) {
    $permissionGranted = $active && !empty($active[$permissionMap[$view]]);
}

function gp_nav_class(string $current, string $target, bool $enabled = true): string {
    $classes = 'list-group-item list-group-item-action border-0';
    if ($current === $target) $classes .= ' active';
    if (!$enabled) $classes .= ' disabled text-muted';
    return $classes;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>EduSync - Portal del Apoderado</title>
    <link rel="icon" href="assets/uploads/logo.jpg" type="image/jpeg">
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:300,400,600,700,800,900" rel="stylesheet">
    <link href="css/sb-admin-2.css" rel="stylesheet">
    <link href="css/custom.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
</head>
<body id="page-top" class="bg-light">

<nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
    <a class="navbar-brand d-flex align-items-center" href="guardian_portal.php">
        <img src="assets/uploads/logo.jpg" alt="EduSync" class="rounded mr-2" style="width:38px;height:38px;object-fit:cover">
        <span class="font-weight-bold text-primary">EduSync</span>
        <span class="badge badge-light border ml-2 d-none d-sm-inline">Portal del Apoderado</span>
    </a>
    <ul class="navbar-nav ml-auto align-items-center">
        <li class="nav-item d-none d-md-block mr-3 text-right">
            <span class="d-block small font-weight-bold text-gray-800"><?php echo htmlspecialchars($guardianName, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="d-block text-xs text-gray-600">DNI: <?php echo htmlspecialchars((string)$guardian['dni'], ENT_QUOTES, 'UTF-8'); ?></span>
        </li>
        <li class="nav-item">
            <a class="btn btn-outline-secondary btn-sm" href="logout.php"><i class="fas fa-sign-out-alt mr-1"></i><span class="d-none d-sm-inline">Cerrar sesión</span></a>
        </li>
    </ul>
</nav>

<div class="container-fluid pb-4">
    <?php if ($message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message['type'], ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show shadow-sm" role="alert">
            <?php echo htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>

    <?php if (!$children): ?>
        <div class="card shadow mb-4">
            <div class="card-body text-center py-5">
                <i class="fas fa-user-friends fa-3x text-primary mb-3"></i>
                <h4 class="font-weight-bold text-gray-800">Tu cuenta aún no tiene estudiantes vinculados</h4>
                <p class="text-muted mb-0">Comunícate con la institución para revisar la ficha del alumno y el vínculo del apoderado.</p>
            </div>
        </div>
    <?php else: ?>
    <div class="row">
        <div class="col-xl-2 col-lg-3 mb-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-primary text-white">
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-user-friends mr-2"></i>Familia</h6>
                </div>
                <div class="list-group list-group-flush">
                    <a href="guardian_portal.php?view=home" class="<?php echo gp_nav_class($view, 'home'); ?>"><i class="fas fa-home fa-fw mr-2"></i>Inicio</a>
                </div>
                <div class="card-header py-2 bg-light"><span class="text-xs font-weight-bold text-primary text-uppercase">Académico</span></div>
                <div class="list-group list-group-flush">
                    <a href="guardian_portal.php?view=grades" class="<?php echo gp_nav_class($view, 'grades', !empty($active['can_view_grades'])); ?>"><i class="fas fa-graduation-cap fa-fw mr-2"></i>Notas</a>
                    <a href="guardian_portal.php?view=attendance" class="<?php echo gp_nav_class($view, 'attendance', !empty($active['can_view_attendance'])); ?>"><i class="fas fa-calendar-check fa-fw mr-2"></i>Asistencia</a>
                </div>
                <div class="card-header py-2 bg-light"><span class="text-xs font-weight-bold text-primary text-uppercase">Finanzas</span></div>
                <div class="list-group list-group-flush">
                    <a href="guardian_portal.php?view=payments" class="<?php echo gp_nav_class($view, 'payments', !empty($active['can_view_payments'])); ?>"><i class="fas fa-credit-card fa-fw mr-2"></i>Pagos</a>
                    <a href="guardian_portal.php?view=debts" class="<?php echo gp_nav_class($view, 'debts', !empty($active['can_view_payments'])); ?>"><i class="fas fa-file-invoice-dollar fa-fw mr-2"></i>Deudas</a>
                </div>
                <div class="card-header py-2 bg-light"><span class="text-xs font-weight-bold text-primary text-uppercase">Institución</span></div>
                <div class="list-group list-group-flush">
                    <a href="guardian_portal.php?view=communications" class="<?php echo gp_nav_class($view, 'communications', !empty($active['can_receive_communications'])); ?>"><i class="fas fa-bullhorn fa-fw mr-2"></i>Comunicaciones</a>
                </div>
            </div>
        </div>

        <div class="col-xl-10 col-lg-9">
            <div class="card shadow mb-4">
                <div class="card-body py-3">
                    <div class="row align-items-center">
                        <div class="col-md mb-3 mb-md-0">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Estudiante seleccionado</div>
                            <div class="h5 mb-1 font-weight-bold text-gray-800">
                                <?php echo htmlspecialchars((string)$active['name'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($active['is_primary'])): ?><span class="badge badge-primary ml-1">Vínculo principal</span><?php endif; ?>
                            </div>
                            <div class="small text-muted"><?php echo htmlspecialchars(trim((string)$active['nivel'] . ' · ' . (string)$active['grado'] . ' ' . (string)$active['seccion']), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <?php if (count($children) > 1): ?>
                        <div class="col-md-5 col-xl-4">
                            <form method="post" action="guardian_portal.php?view=<?php echo urlencode($view); ?>">
                                <input type="hidden" name="action" value="switch_student">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="return_view" value="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
                                <label class="small font-weight-bold mb-1" for="guardian-student-selector">Cambiar estudiante</label>
                                <select class="form-control form-control-sm" id="guardian-student-selector" name="student_id" onchange="this.form.submit()">
                                    <?php foreach ($children as $child): ?>
                                        <option value="<?php echo (int)$child['student_id']; ?>" <?php echo (int)$child['student_id']===(int)$active['student_id']?'selected':''; ?>>
                                            <?php echo htmlspecialchars((string)$child['name'] . ' · ' . (string)$child['nivel'] . ' ' . (string)$child['grado'] . ' ' . (string)$child['seccion'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!$permissionGranted): ?>
                <div class="alert alert-danger shadow-sm"><i class="fas fa-lock mr-2"></i>No tienes permiso para consultar este módulo del estudiante seleccionado.</div>
            <?php elseif ($view === 'home'): ?>
                <div class="card shadow mb-4 border-left-primary">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Portal familiar</div>
                                <div class="h4 mb-1 font-weight-bold text-gray-800">Hola, <?php echo htmlspecialchars((string)$guardian['nombres'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-muted">Este es el resumen de <?php echo htmlspecialchars((string)$active['name'], ENT_QUOTES, 'UTF-8'); ?>.</div>
                            </div>
                            <div class="col-auto"><i class="fas fa-home fa-2x text-gray-300"></i></div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <?php if (!empty($active['can_view_grades'])): ?>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <a class="text-decoration-none" href="guardian_portal.php?view=grades">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2"><div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Notas</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo (int)$metrics['grades']; ?></div><div class="small text-muted mt-1">Registros disponibles</div></div><div class="col-auto"><i class="fas fa-graduation-cap fa-2x text-gray-300"></i></div></div></div>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($active['can_view_attendance'])): ?>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <a class="text-decoration-none" href="guardian_portal.php?view=attendance">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2"><div class="text-xs font-weight-bold text-success text-uppercase mb-1">Asistencia · 30 días</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo (int)$metrics['attendance']; ?></div><div class="small text-muted mt-1"><?php echo (int)$metrics['late']; ?> tardanza(s)</div></div><div class="col-auto"><i class="fas fa-calendar-check fa-2x text-gray-300"></i></div></div></div>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($active['can_view_payments'])): ?>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <a class="text-decoration-none" href="guardian_portal.php?view=payments">
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2"><div class="text-xs font-weight-bold text-info text-uppercase mb-1">Pagado</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo gp_money($metrics['payments']); ?></div><div class="small text-muted mt-1">Histórico registrado</div></div><div class="col-auto"><i class="fas fa-credit-card fa-2x text-gray-300"></i></div></div></div>
                            </div>
                        </a>
                    </div>
                    <div class="col-xl-3 col-md-6 mb-4">
                        <a class="text-decoration-none" href="guardian_portal.php?view=debts">
                            <div class="card border-left-warning shadow h-100 py-2">
                                <div class="card-body"><div class="row no-gutters align-items-center"><div class="col mr-2"><div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Deuda pendiente</div><div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo gp_money($metrics['debt']); ?></div><div class="small text-muted mt-1">Saldo pendiente</div></div><div class="col-auto"><i class="fas fa-file-invoice-dollar fa-2x text-gray-300"></i></div></div></div>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-shield-alt mr-2"></i>Permisos para este estudiante</h6></div>
                    <div class="card-body">
                        <span class="badge badge-<?php echo !empty($active['can_view_grades'])?'success':'secondary'; ?> p-2 mr-2 mb-2">Notas</span>
                        <span class="badge badge-<?php echo !empty($active['can_view_attendance'])?'success':'secondary'; ?> p-2 mr-2 mb-2">Asistencia</span>
                        <span class="badge badge-<?php echo !empty($active['can_view_payments'])?'success':'secondary'; ?> p-2 mr-2 mb-2">Pagos y deudas</span>
                        <span class="badge badge-<?php echo !empty($active['can_receive_communications'])?'success':'secondary'; ?> p-2 mr-2 mb-2">Comunicaciones</span>
                    </div>
                </div>
            <?php elseif ($view === 'grades'): ?>
                <?php gp_include_student_page(__DIR__ . '/pages/student_grades.php', $conn, $active); ?>
            <?php elseif ($view === 'attendance'): ?>
                <?php gp_include_student_page(__DIR__ . '/pages/student_attendances.php', $conn, $active); ?>
            <?php elseif ($view === 'payments'): ?>
                <?php gp_include_student_page(__DIR__ . '/pages/student_payments.php', $conn, $active); ?>
            <?php elseif ($view === 'debts'): ?>
                <?php gp_include_student_page(__DIR__ . '/pages/student_debts.php', $conn, $active); ?>
            <?php elseif ($view === 'communications'): ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-bullhorn mr-2"></i>Comunicaciones</h6></div>
                    <div class="card-body text-center py-5">
                        <i class="fas fa-bullhorn fa-3x text-primary mb-3"></i>
                        <h5 class="font-weight-bold text-gray-800">Centro de Comunicaciones</h5>
                        <p class="text-muted mb-0">Aquí llegarán los comunicados del colegio para el estudiante seleccionado, con lectura y confirmación. Se implementará en la siguiente etapa.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/jquery-easing/jquery.easing.min.js"></script>
</body>
</html>
