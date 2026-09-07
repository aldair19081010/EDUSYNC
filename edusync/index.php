<?php
// Unificar ruta de sesiones (coincide con login.php)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Verificar si el usuario está logueado (admin/docente o estudiante)
if (!isset($_SESSION['login_id']) && !isset($_SESSION['login_type']) && !isset($_SESSION['student_logged_in'])) {
    header("Location: login.php");
    exit();
}

// Obtener la página solicitada
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

// Por seguridad, lista blanca de páginas permitidas
$allowed_pages = [
    'home', 
    // Admin pages
    'students', 'bulk_student_update',
    'teachers', 'teacher_courses',
    'academic_management', 'academic_year',
    'competencias',
    'concepts', 'fees', 'payments', 'discounts',
    'asistencia', 'attendance_rules_page',
    'grades',
    'payments_report', 'grades_report', 'attendance_report', 'debt_reports', 'fichas_reportes',
    'users',
    // Facturación Electrónica
    'comprobantes', 'config_facturacion', 'facturacion_deudas',
    // Notifications
    'notifications',
    'student_low_grades',
    // Teacher pages
    'my_courses',
    // Student pages
    'my_grades', 'my_schedule', 'my_payments', 'my_debts', 'my_attendance', 'student_payments', 'student_debts', 'student_grades', 'student_attendances'
];

// Validar que la página esté permitida
if (!in_array($page, $allowed_pages)) {
    $page = 'home';
}

// Restringir módulos administrativos aunque el usuario cambie manualmente la URL.
$is_admin_user = false;
$current_user_type = (int)($_SESSION['login_type'] ?? 0);
$is_director_user = false;
if (!empty($_SESSION['login_id'])) {
    include_once 'db_connect.php';
    $role_query = $conn->prepare('SELECT u.type, u.is_director, t.status AS teacher_status FROM users u LEFT JOIN teacher t ON t.id = u.teacher_id WHERE u.id = ? LIMIT 1');
    if ($role_query) {
        $login_id = (int)$_SESSION['login_id'];
        $role_query->bind_param('i', $login_id);
        $role_query->execute();
        $role = $role_query->get_result()->fetch_assoc();
        if ($role) {
            $current_user_type = (int)$role['type'];
            $is_director_user = (int)$role['is_director'] === 1;
            $is_admin_user = $current_user_type === 1;
            if ($current_user_type === 2 && ($role['teacher_status'] ?? 'Activo') === 'Inactivo') {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                session_destroy();
                header('Location: login.php?inactive=1');
                exit();
            }
        }
        $role_query->close();
    }
}

$common_pages = ['home', 'notifications'];
$role_pages = [
    1 => $allowed_pages,
    2 => ['my_courses', 'grades', 'grades_report', 'competencias'],
    3 => ['asistencia', 'attendance_rules_page', 'attendance_report'],
    4 => ['my_grades', 'my_schedule', 'my_payments', 'my_debts', 'my_attendance', 'student_payments', 'student_debts', 'student_grades', 'student_attendances']
];
$allowed_for_role = array_merge($common_pages, $role_pages[$current_user_type] ?? []);
if (!in_array($page, $allowed_for_role, true)) {
    $_SESSION['access_denied_message'] = 'No tienes permisos para acceder al módulo solicitado.';
    header('Location: index.php?page=home');
    exit();
}

// Determinar si usar 'pages' o archivo directo para backward compatibility
if (in_array($page, ['students', 'teachers', 'teacher_courses', 'academic_management', 'academic_year', 'concepts', 'fees', 'payments', 'discounts', 'asistencia', 'comprobantes', 'config_facturacion', 'facturacion_deudas'])) {
    $page_file = "{$page}.php";
} else {
    $page_file = "pages/{$page}.php";
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Sistema de Gestión Escolar EduSync">
    <meta name="author" content="EduSync">

    <title>EduSync - Sistema de Gestión Escolar</title>

    <!-- Favicon -->
    <link rel="icon" href="assets/uploads/logo.jpg" type="image/jpeg">
    <link rel="shortcut icon" href="assets/uploads/logo.jpg" type="image/jpeg">

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.css" rel="stylesheet">
    <link href="css/custom.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-theme@0.1.0-beta.10/dist/select2-bootstrap.min.css" rel="stylesheet" />
    <!-- Load jQuery early so inline page scripts can safely use it -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <!-- DataTables debe estar disponible antes de ejecutar scripts inline de cada módulo -->
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
    <?php if ($page === 'payments'): ?>
    <!-- Generación local de la imagen del recibo para compartirla desde el dispositivo -->
    <script src="js/vendor/html2canvas.min.js"></script>
    <?php endif; ?>
    
    <!-- Logout Modal (movido al head para disponibilidad temprana) -->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">¿Listo para salir?</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">Selecciona "Cerrar Sesión" a continuación si estás listo para finalizar tu sesión actual.</div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancelar</button>
                    <a class="btn btn-primary" href="logout.php">Cerrar Sesión</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Shim temprano: asegurar window.uni_modal antes de handlers del topbar -->
    <script>
        (function(){
                if (typeof window.uni_modal !== 'function') {
                        window.uni_modal = function(title, url, size) {
                                try {
                                        var $ = window.jQuery || window.$;
                                        if (!$) { console.warn('uni_modal shim: jQuery no disponible aún'); return; }
                                        var modal = $('#uni_modal');
                                        if (!modal.length) {
                                                var dlgSize = size || 'modal-lg';
                                                var html = ''+
                                                '<div class="modal fade" id="uni_modal" tabindex="-1" role="dialog" aria-labelledby="uni_modal_label" aria-hidden="true">'+
                                                    '<div class="modal-dialog '+ dlgSize +'" role="document">'+
                                                        '<div class="modal-content">'+
                                                            '<div class="modal-header">'+
                                                                '<h5 class="modal-title" id="uni_modal_label"></h5>'+
                                                                '<button type="button" class="close" data-dismiss="modal" aria-label="Close">'+
                                                                    '<span aria-hidden="true">&times;</span>'+
                                                                '</button>'+
                                                            '</div>'+
                                                            '<div class="modal-body" id="uni_modal_body"></div>'+
                                                        '</div>'+
                                                    '</div>'+
                                                '</div>';
                                                $('body').append(html);
                                                modal = $('#uni_modal');
                                        } else {
                                                var modalDialog = modal.find('.modal-dialog');
                                                modalDialog.removeClass('modal-sm modal-lg modal-xl mid-large');
                                                modalDialog.addClass(size || 'modal-lg');
                                        }
                                        // Mostrar modal INMEDIATAMENTE (vacío)
                                        $('#uni_modal_label').html(title || '');
                                        $('#uni_modal_body').html('');
                                        modal.modal('show');
                                        
                                        // Cargar contenido en segundo plano
                                        $.ajax({
                                            url: url,
                                            type: 'GET',
                                            cache: false,
                                            success: function(response) {
                                                $('#uni_modal_body').html(response);
                                            },
                                            error: function(xhr) {
                                                $('#uni_modal_body').html('<div class="alert alert-danger">Error al cargar el contenido: ' + xhr.status + ' ' + xhr.statusText + '</div>');
                                            }
                                        });
                                } catch (e) {
                                        console.error('uni_modal shim error:', e);
                                }
                        };
                }
        })();
        </script>
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <?php include 'includes/navbar.php'; ?>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <?php include 'includes/topbar.php'; ?>

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <?php if (!empty($_SESSION['access_denied_message'])): ?>
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <i class="fas fa-lock mr-2"></i><?php echo htmlspecialchars($_SESSION['access_denied_message'], ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
                        </div>
                        <?php unset($_SESSION['access_denied_message']); ?>
                    <?php endif; ?>

                    <?php
                    // Cargar el contenido de la página
                    if (file_exists($page_file)) {
                        include $page_file;
                    } else {
                        // Página por defecto si no existe el archivo
                        echo '<h1 class="h3 mb-4 text-gray-800">Página en Construcción</h1>';
                        echo '<p>La página solicitada está en desarrollo.</p>';
                    }
                    ?>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <?php include 'includes/footer.php'; ?>

        </div>
        <!-- End of Content Wrapper -->

    </div>
    <!-- End of Page Wrapper -->

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- Bootstrap CDN como respaldo -->
    <script>
    if (typeof $.fn.dropdown === 'undefined' && typeof window.bootstrap === 'undefined') {
        console.log('⚠️ Bootstrap local no disponible, cargando desde CDN...');
        document.write('<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"><\/script>');
    }
    </script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages. filemtime evita servir una versión antigua desde caché. -->
    <?php $sb_admin_js_version = @filemtime(__DIR__ . '/js/sb-admin-2.min.js') ?: time(); ?>
    <script src="js/sb-admin-2.min.js?v=<?php echo $sb_admin_js_version; ?>"></script>

    <?php if ($page === 'grades_report'): ?>
    <!-- Mejora exclusiva de la vista previa del reporte de notas -->
    <?php $grades_report_preview_version = @filemtime(__DIR__ . '/js/grades_report_preview.js') ?: time(); ?>
    <script src="js/grades_report_preview.js?v=<?php echo $grades_report_preview_version; ?>"></script>
    <?php endif; ?>

    <!-- Sidebar toggle guard: rebinds the click handlers in case another script detaches them -->
    <script>
    (function($){
        function bindSidebarToggle(){
            $('#sidebarToggle, #sidebarToggleTop').off('click.sidebarfix').on('click.sidebarfix', function(e){
                e.preventDefault();
                $('body').toggleClass('sidebar-toggled');
                $('.sidebar').toggleClass('toggled');
                if ($('.sidebar').hasClass('toggled')) {
                    $('.sidebar .collapse').collapse('hide');
                }
            });
        }
        $(bindSidebarToggle);
        $(document).on('edusync:rebindSidebar', bindSidebarToggle);
    })(window.jQuery);
    </script>

    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!-- INICIALIZACIÓN DE DROPDOWNS DEL TOPBAR -->
    <script>
    console.log('🔵 SCRIPT DE INICIALIZACIÓN DE INDEX.PHP EJECUTANDO');
    
    // Log sin jQuery
    document.addEventListener('click', function(e) {
        var target = e.target;
        if (target.closest && target.closest('.dropdown-menu')) {
            console.log('🔴 CLICK DETECTADO EN DROPDOWN:', target.innerText || target.textContent);
        }
    }, true); // true = captura durante la fase de captura
    
    (function($){
        console.log('jQuery disponible en script final');
        
        function initDropdowns() {
            if (typeof $.fn.dropdown !== 'undefined') {
                console.log('Bootstrap dropdown disponible');
                $('.topbar [data-toggle="dropdown"]').each(function() {
                    try {
                        $(this).dropdown();
                    } catch(e) {
                        console.error('Error inicializando dropdown:', e.message);
                    }
                });
            } else {
                console.log('Esperando Bootstrap...');
                setTimeout(initDropdowns, 100);
            }
        }
        
        initDropdowns();
    })(window.jQuery);
    </script>

</body>

</html>