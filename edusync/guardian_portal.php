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

    $stmt = $conn->prepare('SELECT COUNT(*) c FROM evaluation_grades WHERE student_id=? AND grade IS NOT NULL AND TRIM(grade)<>\'\'');
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
    <style>
        body{background:#f6f8fc}.gp-shell{min-height:100vh;display:flex}.gp-sidebar{width:250px;background:linear-gradient(180deg,#4285f4 0%,#2a65cc 100%);color:#fff;padding:1.2rem 1rem;position:fixed;left:0;top:0;bottom:0;overflow-y:auto;z-index:1030}.gp-brand{display:flex;align-items:center;gap:.7rem;padding:.25rem .5rem 1.2rem;border-bottom:1px solid rgba(255,255,255,.18);margin-bottom:1rem}.gp-brand img{width:42px;height:42px;border-radius:10px;object-fit:cover;background:#fff}.gp-brand strong{display:block;font-size:1.05rem}.gp-brand small{opacity:.8}.gp-nav-title{font-size:.67rem;text-transform:uppercase;letter-spacing:.08em;opacity:.65;font-weight:800;margin:1rem .65rem .35rem}.gp-link{display:flex;align-items:center;gap:.7rem;color:rgba(255,255,255,.86);padding:.7rem .75rem;border-radius:.55rem;text-decoration:none!important;font-size:.88rem;font-weight:700;margin:.12rem 0}.gp-link:hover,.gp-link.active{color:#fff;background:rgba(255,255,255,.16)}.gp-link.disabled{opacity:.55;pointer-events:none}.gp-main{margin-left:250px;width:calc(100% - 250px);min-height:100vh}.gp-topbar{background:#fff;border-bottom:1px solid #e3e6f0;min-height:72px;padding:.8rem 1.3rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;position:sticky;top:0;z-index:1020}.gp-user strong{display:block;color:#27364f}.gp-user small{color:#7b8499}.gp-content{padding:1.25rem}.gp-selector{background:#fff;border:1px solid #dfe5ef;border-radius:.65rem;padding:.8rem 1rem;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}.gp-selector-info{min-width:0}.gp-selector-info strong{display:block;color:#2f3c55}.gp-selector-info small{color:#7b8499}.gp-selector form{display:flex;align-items:center;gap:.5rem;min-width:360px}.gp-selector select{min-width:280px}.gp-hero{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4285f4;border-radius:.7rem;padding:1.15rem 1.25rem;margin-bottom:1rem}.gp-hero h1{font-size:1.35rem;font-weight:800;color:#2f3c55;margin:0}.gp-hero p{margin:.25rem 0 0;color:#7b8499}.gp-card{display:block;background:#fff;border:1px solid #e3e6f0;border-radius:.7rem;padding:1rem;height:100%;text-decoration:none!important;transition:.15s}.gp-card:hover{transform:translateY(-2px);box-shadow:0 .3rem 1rem rgba(38,55,84,.08);border-color:#b9c7ea}.gp-card-top{display:flex;align-items:center;justify-content:space-between}.gp-card-label{font-size:.73rem;text-transform:uppercase;color:#7b8499;font-weight:800}.gp-icon{width:42px;height:42px;border-radius:.6rem;display:flex;align-items:center;justify-content:center;background:#edf2ff;color:#4285f4}.gp-value{font-size:1.35rem;font-weight:800;color:#263754;margin:.7rem 0 .15rem}.gp-sub{font-size:.76rem;color:#858796}.gp-no-child{background:#fff;border:1px dashed #cfd7e6;border-radius:.75rem;padding:3rem 1rem;text-align:center;color:#6e7891}.gp-mobile-toggle{display:none}.gp-badge-main{background:#edf2ff;color:#3159aa;border-radius:1rem;padding:.2rem .55rem;font-size:.68rem;font-weight:800}.gp-denied{background:#fff;border:1px solid #f0d5d5;border-left:4px solid #e74a3b;border-radius:.65rem;padding:1rem;color:#7b3940}@media(max-width:900px){.gp-sidebar{transform:translateX(-100%);transition:.2s}.gp-sidebar.open{transform:translateX(0)}.gp-main{margin-left:0;width:100%}.gp-mobile-toggle{display:inline-flex}.gp-selector{align-items:flex-start;flex-direction:column}.gp-selector form{min-width:0;width:100%}.gp-selector select{min-width:0;flex:1}.gp-content{padding:.8rem}.gp-topbar{padding:.7rem .8rem}}
    </style>
</head>
<body>
<div class="gp-shell">
    <aside class="gp-sidebar" id="gp-sidebar">
        <div class="gp-brand">
            <img src="assets/uploads/logo.jpg" alt="EduSync">
            <div><strong>EduSync</strong><small>Portal del Apoderado</small></div>
        </div>
        <div class="gp-nav-title">Familia</div>
        <a class="gp-link <?php echo $view==='home'?'active':''; ?>" href="guardian_portal.php?view=home"><i class="fas fa-home fa-fw"></i> Inicio</a>
        <div class="gp-nav-title">Académico</div>
        <a class="gp-link <?php echo $view==='grades'?'active':''; ?><?php echo $active && empty($active['can_view_grades'])?' disabled':''; ?>" href="guardian_portal.php?view=grades"><i class="fas fa-graduation-cap fa-fw"></i> Notas</a>
        <a class="gp-link <?php echo $view==='attendance'?'active':''; ?><?php echo $active && empty($active['can_view_attendance'])?' disabled':''; ?>" href="guardian_portal.php?view=attendance"><i class="fas fa-calendar-check fa-fw"></i> Asistencia</a>
        <div class="gp-nav-title">Finanzas</div>
        <a class="gp-link <?php echo $view==='payments'?'active':''; ?><?php echo $active && empty($active['can_view_payments'])?' disabled':''; ?>" href="guardian_portal.php?view=payments"><i class="fas fa-credit-card fa-fw"></i> Pagos</a>
        <a class="gp-link <?php echo $view==='debts'?'active':''; ?><?php echo $active && empty($active['can_view_payments'])?' disabled':''; ?>" href="guardian_portal.php?view=debts"><i class="fas fa-file-invoice-dollar fa-fw"></i> Deudas</a>
        <div class="gp-nav-title">Comunicaciones</div>
        <a class="gp-link <?php echo $view==='communications'?'active':''; ?><?php echo $active && empty($active['can_receive_communications'])?' disabled':''; ?>" href="guardian_portal.php?view=communications"><i class="fas fa-bullhorn fa-fw"></i> Comunicaciones</a>
        <div class="gp-nav-title">Cuenta</div>
        <a class="gp-link" href="logout.php"><i class="fas fa-sign-out-alt fa-fw"></i> Cerrar sesión</a>
    </aside>

    <main class="gp-main">
        <header class="gp-topbar">
            <div class="d-flex align-items-center">
                <button class="btn btn-light border gp-mobile-toggle mr-2" id="gp-mobile-toggle" type="button"><i class="fas fa-bars"></i></button>
                <div class="gp-user"><strong><?php echo htmlspecialchars($guardianName, ENT_QUOTES, 'UTF-8'); ?></strong><small>Apoderado · <?php echo htmlspecialchars((string)$guardian['dni'], ENT_QUOTES, 'UTF-8'); ?></small></div>
            </div>
            <a href="logout.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-sign-out-alt mr-1"></i> Salir</a>
        </header>

        <div class="gp-content">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo htmlspecialchars($message['type'], ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
                    <?php echo htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
            <?php endif; ?>

            <?php if ($children): ?>
                <section class="gp-selector">
                    <div class="gp-selector-info">
                        <small>Estudiante seleccionado</small>
                        <strong><?php echo htmlspecialchars((string)$active['name'], ENT_QUOTES, 'UTF-8'); ?> <?php echo !empty($active['is_primary']) ? '<span class="gp-badge-main">VÍNCULO PRINCIPAL</span>' : ''; ?></strong>
                        <small><?php echo htmlspecialchars(trim((string)$active['nivel'] . ' · ' . (string)$active['grado'] . ' ' . (string)$active['seccion']), ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                    <?php if (count($children) > 1): ?>
                    <form method="post" action="guardian_portal.php?view=<?php echo urlencode($view); ?>">
                        <input type="hidden" name="action" value="switch_student">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_view" value="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
                        <select class="form-control form-control-sm" name="student_id" onchange="this.form.submit()">
                            <?php foreach ($children as $child): ?>
                                <option value="<?php echo (int)$child['student_id']; ?>" <?php echo (int)$child['student_id']===(int)$active['student_id']?'selected':''; ?>>
                                    <?php echo htmlspecialchars((string)$child['name'] . ' · ' . (string)$child['nivel'] . ' ' . (string)$child['grado'] . ' ' . (string)$child['seccion'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (!$children): ?>
                <div class="gp-no-child"><i class="fas fa-user-friends fa-3x text-primary mb-3"></i><h4>Tu cuenta aún no tiene estudiantes vinculados</h4><p class="mb-0">Comunícate con la institución para revisar la ficha del alumno y el vínculo del apoderado.</p></div>
            <?php elseif (!$permissionGranted): ?>
                <div class="gp-denied"><i class="fas fa-lock mr-2"></i>No tienes permiso para consultar este módulo del estudiante seleccionado.</div>
            <?php elseif ($view === 'home'): ?>
                <section class="gp-hero">
                    <h1>Hola, <?php echo htmlspecialchars((string)$guardian['nombres'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>Resumen de <?php echo htmlspecialchars((string)$active['name'], ENT_QUOTES, 'UTF-8'); ?>. Cambia de estudiante arriba si tienes más de un hijo vinculado.</p>
                </section>
                <div class="row">
                    <?php if (!empty($active['can_view_grades'])): ?>
                    <div class="col-xl-3 col-md-6 mb-3"><a class="gp-card" href="guardian_portal.php?view=grades"><div class="gp-card-top"><span class="gp-card-label">Notas</span><span class="gp-icon"><i class="fas fa-graduation-cap"></i></span></div><div class="gp-value"><?php echo (int)$metrics['grades']; ?></div><div class="gp-sub">Registros de calificación disponibles</div></a></div>
                    <?php endif; ?>
                    <?php if (!empty($active['can_view_attendance'])): ?>
                    <div class="col-xl-3 col-md-6 mb-3"><a class="gp-card" href="guardian_portal.php?view=attendance"><div class="gp-card-top"><span class="gp-card-label">Asistencia · 30 días</span><span class="gp-icon"><i class="fas fa-calendar-check"></i></span></div><div class="gp-value"><?php echo (int)$metrics['attendance']; ?></div><div class="gp-sub"><?php echo (int)$metrics['late']; ?> registro(s) de tardanza</div></a></div>
                    <?php endif; ?>
                    <?php if (!empty($active['can_view_payments'])): ?>
                    <div class="col-xl-3 col-md-6 mb-3"><a class="gp-card" href="guardian_portal.php?view=payments"><div class="gp-card-top"><span class="gp-card-label">Pagado</span><span class="gp-icon"><i class="fas fa-credit-card"></i></span></div><div class="gp-value"><?php echo gp_money($metrics['payments']); ?></div><div class="gp-sub">Histórico registrado</div></a></div>
                    <div class="col-xl-3 col-md-6 mb-3"><a class="gp-card" href="guardian_portal.php?view=debts"><div class="gp-card-top"><span class="gp-card-label">Deuda pendiente</span><span class="gp-icon"><i class="fas fa-file-invoice-dollar"></i></span></div><div class="gp-value"><?php echo gp_money($metrics['debt']); ?></div><div class="gp-sub">Saldo pendiente estimado</div></a></div>
                    <?php endif; ?>
                </div>
                <div class="card shadow-sm border-0 mt-2"><div class="card-body"><h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-shield-alt mr-2"></i>Permisos para este estudiante</h6><div class="d-flex flex-wrap" style="gap:.5rem"><span class="badge badge-<?php echo !empty($active['can_view_grades'])?'success':'secondary'; ?> p-2">Notas</span><span class="badge badge-<?php echo !empty($active['can_view_attendance'])?'success':'secondary'; ?> p-2">Asistencia</span><span class="badge badge-<?php echo !empty($active['can_view_payments'])?'success':'secondary'; ?> p-2">Pagos y deudas</span><span class="badge badge-<?php echo !empty($active['can_receive_communications'])?'success':'secondary'; ?> p-2">Comunicaciones</span></div></div></div>
            <?php elseif ($view === 'grades'): ?>
                <?php gp_include_student_page(__DIR__ . '/pages/student_grades.php', $conn, $active); ?>
            <?php elseif ($view === 'attendance'): ?>
                <?php gp_include_student_page(__DIR__ . '/pages/student_attendances.php', $conn, $active); ?>
            <?php elseif ($view === 'payments'): ?>
                <?php gp_include_student_page(__DIR__ . '/pages/student_payments.php', $conn, $active); ?>
            <?php elseif ($view === 'debts'): ?>
                <?php gp_include_student_page(__DIR__ . '/pages/student_debts.php', $conn, $active); ?>
            <?php elseif ($view === 'communications'): ?>
                <section class="gp-hero"><h1>Comunicaciones</h1><p>Este espacio ya está reservado para la siguiente etapa del módulo.</p></section>
                <div class="card shadow-sm border-0"><div class="card-body text-center py-5"><i class="fas fa-bullhorn fa-3x text-primary mb-3"></i><h5 class="font-weight-bold">Centro de Comunicaciones</h5><p class="text-muted mb-0">Aquí llegarán los comunicados del colegio para el estudiante seleccionado, con lectura y confirmación. Lo implementaremos en la siguiente etapa sin mezclarlo con el desarrollo actual.</p></div></div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/jquery-easing/jquery.easing.min.js"></script>
<script>
(function(){
    var btn=document.getElementById('gp-mobile-toggle');
    var sidebar=document.getElementById('gp-sidebar');
    if(btn&&sidebar){btn.addEventListener('click',function(){sidebar.classList.toggle('open');});}
})();
</script>
</body>
</html>
