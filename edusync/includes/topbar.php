<?php
// Mantener la lógica de conexión y funciones auxiliares del usuario
include_once 'db_connect.php';

// Función time_elapsed_string (Lógica del usuario)
if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime, $full = false) {
        $now = new DateTime;
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        $weeks = floor($diff->days / 7);
        $days_remaining = $diff->days % 7;
        
        $string = array(
            'y' => 'año', 'm' => 'mes', 'w' => 'semana', 'd' => 'día',
            'h' => 'hora', 'i' => 'minuto', 's' => 'segundo',
        );
        $plural = array(
            'y' => 'años', 'm' => 'meses', 'w' => 'semanas', 'd' => 'días',
            'h' => 'horas', 'i' => 'minutos', 's' => 'segundos',
        );
        
        foreach ($string as $k => &$v) {
            if ($k === 'w') {
                if ($weeks) $v = $weeks . ' ' . ($weeks > 1 ? $plural[$k] : $v); else unset($string[$k]);
            } else if ($k === 'd') {
                if ($days_remaining) $v = $days_remaining . ' ' . ($days_remaining > 1 ? $plural[$k] : $v); else unset($string[$k]);
            } else if ($diff->$k) {
                $v = $diff->$k . ' ' . ($diff->$k > 1 ? $plural[$k] : $v);
            } else {
                unset($string[$k]);
            }
        }
        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? 'hace ' . implode(', ', $string) : 'justo ahora';
    }
}

// --- MIGRACIÓN DE ESQUEMA (solo se ejecuta 1 vez por sesión) ---
if (!isset($_SESSION['_schema_checked'])) {
    // Asegurar tablas necesarias
    $conn->query("CREATE TABLE IF NOT EXISTS `low_grade_notifications` (`id` int(30) NOT NULL AUTO_INCREMENT PRIMARY KEY, `student_id` int(11) NOT NULL, `bimestre` varchar(2) NOT NULL, `count_low_grades` int(11) NOT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, `is_read` tinyint(1) NOT NULL DEFAULT 0, `teacher_id` int(11) NULL, `failed_courses` TEXT NULL, KEY `student_id` (`student_id`), KEY `bimestre` (`bimestre`), KEY `teacher_id` (`teacher_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->query("CREATE TABLE IF NOT EXISTS `low_grade_notification_read` (`id` int(11) NOT NULL AUTO_INCREMENT, `user_id` int(11) NOT NULL, `notification_id` int(11) NOT NULL, `read_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`), KEY `user_id` (`user_id`), KEY `notification_id` (`notification_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->query("CREATE TABLE IF NOT EXISTS `notification_read` (`id` int(11) NOT NULL AUTO_INCREMENT, `user_id` int(11) NOT NULL, `evaluation_id` int(11) NOT NULL, `read_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`), KEY `user_id` (`user_id`), KEY `evaluation_id` (`evaluation_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Asegurar claves únicas
    $check_lgn_index = $conn->query("SHOW INDEX FROM `low_grade_notification_read` WHERE Key_name IN ('user_notification', 'user_notification_unique')");
    if (!$check_lgn_index || $check_lgn_index->num_rows == 0) {
        $conn->query("ALTER TABLE `low_grade_notification_read` ADD UNIQUE KEY `user_notification_unique` (`user_id`, `notification_id`)");
    }

    $check_nr_index = $conn->query("SHOW INDEX FROM `notification_read` WHERE Key_name = 'user_evaluation'");
    if (!$check_nr_index || $check_nr_index->num_rows == 0) {
        $conn->query("ALTER TABLE `notification_read` ADD UNIQUE KEY `user_evaluation` (`evaluation_id`, `user_id`)");
    }

    // Verificar columnas y correcciones de esquema
    $result = $conn->query("SHOW COLUMNS FROM `low_grade_notification_read` LIKE 'low_grade_notification_id'");
    if ($result && $result->num_rows > 0) $conn->query("ALTER TABLE `low_grade_notification_read` CHANGE `low_grade_notification_id` `notification_id` INT(11) NOT NULL");
    if ($conn->query("SHOW COLUMNS FROM `low_grade_notifications` LIKE 'teacher_id'")->num_rows == 0) $conn->query("ALTER TABLE `low_grade_notifications` ADD COLUMN `teacher_id` int(11) NULL AFTER `is_read`");
    if ($conn->query("SHOW COLUMNS FROM `low_grade_notifications` LIKE 'failed_courses'")->num_rows == 0) $conn->query("ALTER TABLE `low_grade_notifications` ADD COLUMN `failed_courses` TEXT NULL");

    $_SESSION['_schema_checked'] = true;
}


// Consultas de Notificaciones
$user_id = isset($_SESSION['login_id']) ? $_SESSION['login_id'] : 0;
$user_type = isset($_SESSION['login_type']) ? $_SESSION['login_type'] : 0;
$teacher_id = isset($_SESSION['login_teacher_id']) ? $_SESSION['login_teacher_id'] : 0;
$is_director = isset($_SESSION['login_is_director']) ? $_SESSION['login_is_director'] : 0;

// Si es director, lo tratamos como Admin (1) solo para efectos de las notificaciones
if ($is_director == 1 && $user_type == 2) {
    $user_type = 1;
}

$eval_query = "SELECT 'evaluation' as type, e.id as notification_id, e.title, e.created_at, ac.name as course_name, tc.level, tc.grado, tc.seccion, t.name as teacher_name, NULL as student_name, NULL as student_id, NULL as bimestre, NULL as count_low_grades, NULL as failed_courses, NULL as teacher_id FROM evaluations e JOIN teacher_courses tc ON e.teacher_course_id = tc.id JOIN academic_courses ac ON tc.course_id = ac.id JOIN teacher t ON tc.teacher_id = t.id LEFT JOIN notification_read nr ON nr.evaluation_id = e.id AND nr.user_id = $user_id WHERE nr.id IS NULL";

$low_grades_query = "SELECT 'low_grade' as type, lgn.id as notification_id, CONCAT('Notas Bajas - Bimestre ', lgn.bimestre) as title, lgn.created_at, NULL as course_name, s.nivel as level, s.grado, s.seccion, NULL as teacher_name, s.name as student_name, lgn.student_id, lgn.bimestre, lgn.count_low_grades, lgn.failed_courses, lgn.teacher_id FROM low_grade_notifications lgn JOIN student s ON lgn.student_id = s.id LEFT JOIN low_grade_notification_read lgnr ON lgn.id = lgnr.notification_id AND lgnr.user_id = {$user_id} WHERE lgnr.id IS NULL";

if ($user_type == 2 && $teacher_id > 0) $low_grades_query .= " AND lgn.teacher_id = $teacher_id";
elseif ($user_type == 1) $low_grades_query .= " AND lgn.teacher_id IS NULL";
else $low_grades_query .= " AND 1 = 0";

if ($user_type == 2 && $teacher_id > 0) {
    $notification_query = "SELECT * FROM ($low_grades_query) as notifications ORDER BY created_at DESC LIMIT 50";
} else {
    $notification_query = "SELECT * FROM ($eval_query UNION ALL $low_grades_query) as notifications ORDER BY created_at DESC LIMIT 50";
}

$notification_result = $conn->query($notification_query);
$notifications_count = ($notification_result) ? $notification_result->num_rows : 0;
$notifications = [];
if ($notification_result && $notification_result->num_rows > 0) {
    while ($row = $notification_result->fetch_assoc()) $notifications[] = $row;
}
?>

<!-- Navbar Oficial de SB Admin 2 -->
<nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 sticky-top shadow">

    <!-- Sidebar Toggle (Topbar) -->
    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
        <i class="fa fa-bars"></i>
    </button>

    <!-- Topbar Navbar -->
    <ul class="navbar-nav ml-auto">

        <?php if ($user_type == 1 || $user_type == 2): ?>
        <!-- Nav Item - Alerts -->
        <li class="nav-item dropdown no-arrow mx-1">
            <a class="nav-link dropdown-toggle" href="#" id="alertsDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-bell fa-fw"></i>
                <!-- Counter - Alerts -->
                <?php if($notifications_count > 0): ?>
                    <span class="badge badge-danger badge-counter"><?php echo $notifications_count; ?>+</span>
                <?php endif; ?>
            </a>
            <!-- Dropdown - Alerts -->
            <div class="dropdown-list dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="alertsDropdown">
                <h6 class="dropdown-header">
                    Notificaciones
                </h6>
                
                <div style="max-height: 300px; overflow-y: auto;">
                <?php if($notifications_count > 0): 
                      foreach($notifications as $notification):
                        $time_ago = time_elapsed_string($notification['created_at']);
                        // Determinar icono y color basado en tipo
                        $icon_bg = ($notification['type'] == 'evaluation') ? 'bg-primary' : 'bg-danger';
                        $icon_class = ($notification['type'] == 'evaluation') ? 'fa-file-alt' : 'fa-exclamation-triangle';
                        
                        $link = "#";
                        $onclick = '';
                        if ($notification['type'] == 'evaluation') {
                            $onclick = "onclick=\"uni_modal('Ver Evaluación', 'manage_evaluation_grades.php?evaluation_id=".$notification['notification_id']."&from=notifications', 'large'); return false;\"";
                        } else {
                            $courses = json_decode($notification['failed_courses'], true);
                            $course_name_url = (is_array($courses) && isset($courses[0]['name'])) ? urlencode($courses[0]['name']) : '';
                            $link = "index.php?page=student_low_grades&student_id=".$notification['student_id']."&bimestre=".$notification['bimestre']."&course_name=".$course_name_url."&notification_id=".$notification['notification_id']."&from=notifications";
                        }
                ?>
                <a class="dropdown-item d-flex align-items-center" href="<?php echo $link; ?>" <?php echo $onclick; ?>>
                    <div class="mr-3">
                        <div class="icon-circle <?php echo $icon_bg; ?>">
                            <i class="fas <?php echo $icon_class; ?> text-white"></i>
                        </div>
                    </div>
                    <div>
                        <div class="small text-gray-500"><?php echo htmlspecialchars($time_ago); ?></div>
                        <span class="font-weight-bold">
                            <?php if ($notification['type'] == 'evaluation'): ?>
                                <?php echo htmlspecialchars($notification['teacher_name']); ?>: Nueva evaluación en <?php echo htmlspecialchars($notification['course_name']); ?>
                            <?php else: ?>
                                <?php echo htmlspecialchars($notification['student_name']); ?>: Notas bajas detectadas
                            <?php endif; ?>
                        </span>
                        <div class="small text-gray-600">
                             <?php echo htmlspecialchars($notification['level']); ?> - <?php echo htmlspecialchars($notification['grado']); ?> <?php echo isset($notification['seccion']) ? $notification['seccion'] : ''; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; else: ?>
                    <a class="dropdown-item d-flex align-items-center" href="#">
                        <div class="mr-3">
                            <div class="icon-circle bg-gray-200">
                                <i class="fas fa-info text-gray-400"></i>
                            </div>
                        </div>
                        <div>
                            <span class="font-weight-bold">No hay notificaciones nuevas</span>
                        </div>
                    </a>
                <?php endif; ?>
                </div>

                <a class="dropdown-item text-center small text-gray-500" href="index.php?page=notifications">Ver todas las alertas</a>
                <?php if($notifications_count > 0): ?>
                    <a class="dropdown-item text-center small text-gray-500 mark-all-read-topbar" href="#">Marcar todo como leído</a>
                <?php endif; ?>
            </div>
        </li>
        <?php endif; ?>

        <div class="topbar-divider d-none d-sm-block"></div>

        <!-- Nav Item - User Information -->
        <li class="nav-item dropdown no-arrow">
            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <?php 
                  // Lógica de nombre y avatar para admin/docente o estudiante
                  if (isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in']) {
                      // Usuario es estudiante
                      $userName = $_SESSION['student_name'] ?? 'Estudiante';
                      $avatarFile = $_SESSION['student_avatar'] ?? '';
                  } else {
                      // Usuario es admin/docente
                      $userName = $_SESSION['login_name'] ?? $_SESSION['user_name'] ?? 'Usuario';
                      $avatarFile = $_SESSION['login_avatar'] ?? '';
                  }
                  $avatarPath = !empty($avatarFile) ? 'assets/uploads/' . $avatarFile : '';
                  $avatarUrl = (!empty($avatarFile) && file_exists($avatarPath)) ? $avatarPath : 'https://ui-avatars.com/api/?name=' . urlencode($userName) . '&background=4285f4&color=fff&size=60';
                ?>
                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?php echo htmlspecialchars($userName); ?></span>
                <img class="img-profile rounded-circle" src="<?php echo $avatarUrl; ?>">
            </a>
            <!-- Dropdown - User Information -->
            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
                
                <?php if (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 1): ?>
                <a class="dropdown-item" href="#" id="manage_school" data-action="manage_school">
                    <i class="fas fa-school fa-sm fa-fw mr-2 text-gray-400"></i>
                    Perfil del Colegio
                </a>
                <?php endif; ?>

                <a class="dropdown-item" href="#" id="manage_my_account" data-action="manage_my_account">
                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                    Perfil
                </a>
                
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="#" id="logout_btn">
                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                    Cerrar Sesión
                </a>
            </div>
        </li>

    </ul>

</nav>
<!-- End of Topbar -->

<script>
    // Scripts JS específicos para Topbar (esperar a que jQuery esté disponible)
    (function initTopbar(){
      if (typeof window.jQuery === 'undefined') { return setTimeout(initTopbar, 50); }
      var $ = window.jQuery;
      $(function(){
        // Manejador para marcar notificaciones como leídas - usar namespace
        $(document).off('click.markAllRead').on('click.markAllRead', '.mark-all-read-topbar', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Marcando todas como leídas...');
            
            // Start load (assuming start_load is global)
            if(typeof start_load === 'function') start_load();
            
            $.ajax({
                url: 'ajax.php?action=mark_all_notifications_read',
                method: 'POST',
                dataType: 'json',
                cache: false,
                success: function(resp){
                    console.log('Respuesta del servidor:', resp);
                    if(typeof end_load === 'function') end_load();
                    if(resp && resp.status == 1){
                        // Mostrar información de debug si existe
                        if(resp.debug) {
                            console.log('DEBUG INFO:', resp.debug);
                            console.log('- User ID:', resp.debug.user_id);
                            console.log('- User Type:', resp.debug.user_type);
                            console.log('- Evaluaciones sin leer:', resp.debug.eval_unread_before);
                            console.log('- Evaluaciones marcadas:', resp.debug.eval_marked);
                            console.log('- Notas bajas sin leer:', resp.debug.low_grade_unread_before);
                            console.log('- Notas bajas marcadas:', resp.debug.low_grade_marked);
                            console.log('- Total marcadas:', resp.debug.total_marked);
                        }
                        
                        // Usar Swal si está disponible, sino alert_toast, sino alert simple
                        if(typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Éxito!',
                                text: 'Todas las notificaciones han sido marcadas como leídas',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(function() {
                                location.reload(true);
                            });
                        } else if(typeof alert_toast === 'function') {
                            alert_toast("Todas las notificaciones han sido marcadas como leídas", "success");
                            setTimeout(function(){ location.reload(true); }, 1000);
                        } else {
                            alert("Todas las notificaciones han sido marcadas como leídas");
                            location.reload(true);
                        }
                    } else {
                        var msg = (resp && resp.message) ? resp.message : "Error al marcar como leído";
                        console.error('Error:', msg);
                        if(resp && resp.debug) {
                            console.error('DEBUG INFO:', resp.debug);
                        }
                        if(typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: msg
                            });
                        } else if(typeof alert_toast === 'function') {
                            alert_toast(msg, "error");
                        } else {
                            alert(msg);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error AJAX:', status, error, xhr.responseText);
                    if(typeof end_load === 'function') end_load();
                    var msg = "Error de comunicación con el servidor";
                    if(typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: msg
                        });
                    } else if(typeof alert_toast === 'function') {
                        alert_toast(msg, "error");
                    } else {
                        alert(msg);
                    }
                }
            });
        });

        // Ensure sidebar toggle responds to touch events on mobile
        // We attach a touchstart handler because some mobile browsers do not convert touches to click when elements are overlaid.
        $('#sidebarToggle, #sidebarToggleTop').off('touchstart.mobileToggle').on('touchstart.mobileToggle click.mobileToggle', function(e){
            e.stopPropagation();
            e.preventDefault();
            try{
                $('body').toggleClass('sidebar-toggled');
                $('.sidebar').toggleClass('toggled');
                if ($('.sidebar .collapse').length) $('.sidebar .collapse').collapse('hide');
            } catch(_) {}
            return false;
        });

        // Modales de perfil - usar namespace de eventos para proteger
        function waitForUniModal(callback) {
            console.log('waitForUniModal: checking for uni_modal function');
            if (typeof uni_modal === 'function') {
                console.log('✓ uni_modal disponible');
                callback();
            } else {
                console.log('⏳ uni_modal no disponible, esperando...');
                setTimeout(function() { waitForUniModal(callback); }, 50);
            }
        }
        
        // Usar .off() con namespace específico y .on() con namespace para proteger
        $('#manage_my_account').off('click.topbarProfile').on('click.topbarProfile', function(e){ 
            if (e.which !== 1) return; // Solo click izquierdo
            e.preventDefault();
            console.log('Abriendo modal de perfil');
            
            <?php if (isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in']): ?>
                // Es un estudiante
                waitForUniModal(function() {
                    console.log('Ejecutando uni_modal para perfil de estudiante');
                    uni_modal("Mi Perfil","pages/student_profile.php","mid-large");
                });
            <?php else: ?>
                // Es admin/docente
                waitForUniModal(function() {
                    console.log('Ejecutando uni_modal para perfil');
                    uni_modal("Mi Perfil","manage_user.php?id=<?php echo $_SESSION['login_id'] ?? 0 ?>","mid-large");
                });
            <?php endif; ?>
        });
        
        $('#manage_school').off('click.topbarProfile').on('click.topbarProfile', function(e){ 
            if (e.which !== 1) return; // Solo click izquierdo
            e.preventDefault();
            console.log('Abriendo modal de colegio');
            waitForUniModal(function() {
                uni_modal("Perfil del Colegio","pages/manage_school_profile.php","large");
            });
        });
        
        // Handler para Cerrar Sesión - abre el modal de logout
        $('#logout_btn').off('click.topbarLogout').on('click.topbarLogout', function(e){
            if (e.which !== 1) return; // Solo click izquierdo
            e.preventDefault();
            console.log('Abriendo modal de cierre de sesión');
            var $ = window.jQuery;
            var modal = $('#logoutModal');
            if (modal.length) {
                console.log('✓ logoutModal encontrado, mostrando modal');
                modal.modal('show');
            } else {
                console.error('✗ logoutModal no encontrado en el DOM');
            }
        });
        
        // Prevenir que los dropdowns del topbar añadan "#" a la URL
        $('.topbar a[href="#"][data-toggle="dropdown"]').on('click.topbarDropdown', function(e) {
            // NO HACER e.preventDefault() - dejar que Bootstrap maneje el click
        });
        
        // ✅ Bootstrap ya se inicializa automáticamente desde index.php
        
      });
    })();
</script>
