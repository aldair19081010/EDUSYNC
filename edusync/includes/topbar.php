<?php
include_once 'db_connect.php';

if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime, $full = false) {
        try {
            $now = new DateTime();
            $ago = new DateTime($datetime);
            $diff = $now->diff($ago);
            $weeks = floor($diff->days / 7);
            $days_remaining = $diff->days % 7;
            $string = [
                'y' => 'año', 'm' => 'mes', 'w' => 'semana', 'd' => 'día',
                'h' => 'hora', 'i' => 'minuto', 's' => 'segundo',
            ];
            $plural = [
                'y' => 'años', 'm' => 'meses', 'w' => 'semanas', 'd' => 'días',
                'h' => 'horas', 'i' => 'minutos', 's' => 'segundos',
            ];
            foreach ($string as $k => &$v) {
                if ($k === 'w') {
                    if ($weeks) $v = $weeks . ' ' . ($weeks > 1 ? $plural[$k] : $v); else unset($string[$k]);
                } elseif ($k === 'd') {
                    if ($days_remaining) $v = $days_remaining . ' ' . ($days_remaining > 1 ? $plural[$k] : $v); else unset($string[$k]);
                } elseif ($diff->$k) {
                    $v = $diff->$k . ' ' . ($diff->$k > 1 ? $plural[$k] : $v);
                } else {
                    unset($string[$k]);
                }
            }
            if (!$full) $string = array_slice($string, 0, 1);
            return $string ? 'hace ' . implode(', ', $string) : 'justo ahora';
        } catch (Throwable $e) {
            return 'recientemente';
        }
    }
}

if (!function_exists('topbar_initials')) {
    function topbar_initials($name) {
        $name = trim((string)$name);
        if ($name === '') return 'US';
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) return 'US';
        $first = $parts[0];
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';
        $take = static function ($text) {
            if ($text === '') return '';
            return function_exists('mb_substr') ? mb_substr($text, 0, 1, 'UTF-8') : substr($text, 0, 1);
        };
        $value = $take($first) . $take($last);
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }
}

$user_id = (int)($_SESSION['login_id'] ?? 0);
$user_type = (int)($_SESSION['login_type'] ?? 0);
$teacher_id = (int)($_SESSION['login_teacher_id'] ?? 0);
$is_director = !empty($_SESSION['login_is_director']);
$is_student = !empty($_SESSION['student_logged_in']) || $user_type === 4;
$school_id_topbar = (int)($_SESSION['login_school_id'] ?? $_SESSION['student_school_id'] ?? 0);

if ($is_student) {
    $userName = $_SESSION['student_name'] ?? $_SESSION['login_name'] ?? 'Estudiante';
    $avatarFile = $_SESSION['student_avatar'] ?? '';
    $roleLabel = 'Estudiante';
} else {
    $userName = $_SESSION['login_name'] ?? $_SESSION['user_name'] ?? 'Usuario';
    $avatarFile = $_SESSION['login_avatar'] ?? '';
    if ($user_type === 1) $roleLabel = $is_director ? 'Director' : 'Administrador';
    elseif ($user_type === 2) $roleLabel = 'Docente';
    elseif ($user_type === 3) $roleLabel = 'Auxiliar';
    else $roleLabel = 'Usuario';
}

$avatarPath = !empty($avatarFile) ? 'assets/uploads/' . ltrim($avatarFile, '/') : '';
$hasAvatar = $avatarPath !== '' && file_exists($avatarPath);
$userInitials = topbar_initials($userName);

$schoolName = 'EduSync';
$schoolLogoPath = '';
$hasSchoolLogo = false;
$activeAcademicYear = null;
if ($school_id_topbar > 0) {
    $schoolStmt = $conn->prepare('SELECT name, logo_path FROM schools WHERE id = ? LIMIT 1');
    if ($schoolStmt) {
        $schoolStmt->bind_param('i', $school_id_topbar);
        $schoolStmt->execute();
        $schoolRow = $schoolStmt->get_result()->fetch_assoc();
        $schoolStmt->close();
        if (!empty($schoolRow['name'])) $schoolName = $schoolRow['name'];

        if (!empty($schoolRow['logo_path'])) {
            $storedSchoolLogo = ltrim(str_replace('\\', '/', trim((string)$schoolRow['logo_path'])), '/');
            if (strpos($storedSchoolLogo, 'assets/uploads/') === 0) {
                $candidateSchoolLogo = $storedSchoolLogo;
            } else {
                $candidateSchoolLogo = 'assets/uploads/' . basename($storedSchoolLogo);
            }
            if (is_file($candidateSchoolLogo)) {
                $schoolLogoPath = $candidateSchoolLogo;
                $hasSchoolLogo = true;
            }
        }
    }

    $yearTable = $conn->query("SHOW TABLES LIKE 'academic_year'");
    if ($yearTable && $yearTable->num_rows > 0) {
        $yearStmt = $conn->prepare('SELECT year FROM academic_year WHERE school_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
        if ($yearStmt) {
            $yearStmt->bind_param('i', $school_id_topbar);
            $yearStmt->execute();
            $yearRow = $yearStmt->get_result()->fetch_assoc();
            $yearStmt->close();
            if (!empty($yearRow['year'])) $activeAcademicYear = $yearRow['year'];
        }
    }
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$topbar_csrf = $_SESSION['csrf_token'];

$unified_notifications_ready = false;
$unified_check = $conn->query("SHOW TABLES LIKE 'notification_user_state'");
if ($unified_check && $unified_check->num_rows) $unified_notifications_ready = true;

$notifications = [];
if (!$unified_notifications_ready && in_array($user_type, [1, 2, 3], true)) {
    $eval_query = "SELECT 'evaluation' AS type, e.id AS notification_id, e.title, e.created_at, ac.name AS course_name, tc.level, tc.grado, tc.seccion, t.name AS teacher_name, NULL AS student_name, NULL AS student_id, NULL AS bimestre, NULL AS count_low_grades, NULL AS failed_courses, NULL AS teacher_id FROM evaluations e JOIN teacher_courses tc ON e.teacher_course_id = tc.id JOIN academic_courses ac ON tc.course_id = ac.id JOIN teacher t ON tc.teacher_id = t.id LEFT JOIN notification_read nr ON nr.evaluation_id = e.id AND nr.user_id = {$user_id} WHERE nr.id IS NULL AND tc.school_id = {$school_id_topbar}";
    $low_grades_query = "SELECT 'low_grade' AS type, lgn.id AS notification_id, CONCAT('Notas Bajas - Bimestre ', lgn.bimestre) AS title, lgn.created_at, NULL AS course_name, s.nivel AS level, s.grado, s.seccion, NULL AS teacher_name, s.name AS student_name, lgn.student_id, lgn.bimestre, lgn.count_low_grades, lgn.failed_courses, lgn.teacher_id FROM low_grade_notifications lgn JOIN student s ON lgn.student_id = s.id LEFT JOIN low_grade_notification_read lgnr ON lgn.id = lgnr.notification_id AND lgnr.user_id = {$user_id} WHERE lgnr.id IS NULL AND s.school_id = {$school_id_topbar}";
    if ($user_type === 2 && $teacher_id > 0) $low_grades_query .= " AND lgn.teacher_id = {$teacher_id}";
    elseif ($user_type === 1) $low_grades_query .= ' AND lgn.teacher_id IS NULL';
    else $low_grades_query .= ' AND 1 = 0';

    $notification_query = ($user_type === 2 && $teacher_id > 0)
        ? "SELECT * FROM ({$low_grades_query}) AS notifications ORDER BY created_at DESC LIMIT 50"
        : "SELECT * FROM ({$eval_query} UNION ALL {$low_grades_query}) AS notifications ORDER BY created_at DESC LIMIT 50";
    $notification_result = $conn->query($notification_query);
    if ($notification_result) {
        while ($row = $notification_result->fetch_assoc()) $notifications[] = $row;
    }
}

$can_review_attendance = ($user_type === 1);
if ($user_id > 0 && !$can_review_attendance && !$is_student) {
    $roleStmt = $conn->prepare('SELECT type FROM users WHERE id = ? LIMIT 1');
    if ($roleStmt) {
        $roleStmt->bind_param('i', $user_id);
        $roleStmt->execute();
        $roleData = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();
        $can_review_attendance = $roleData && (int)$roleData['type'] === 1;
    }
}

if ($can_review_attendance && !$unified_notifications_ready) {
    $requestTable = $conn->query("SHOW TABLES LIKE 'attendance_change_requests'");
    if ($requestTable && $requestTable->num_rows > 0) {
        $requestStmt = $conn->prepare("SELECT r.id, r.request_type, r.created_at, u.name requester FROM attendance_change_requests r INNER JOIN users u ON u.id = r.requested_by WHERE r.school_id = ? AND r.status = 'Pendiente' ORDER BY r.created_at DESC LIMIT 50");
        if ($requestStmt) {
            $requestStmt->bind_param('i', $school_id_topbar);
            $requestStmt->execute();
            $requestResult = $requestStmt->get_result();
            while ($request = $requestResult->fetch_assoc()) {
                $notifications[] = [
                    'type' => 'attendance_request', 'notification_id' => $request['id'],
                    'title' => $request['request_type'], 'created_at' => $request['created_at'],
                    'course_name' => null, 'level' => null, 'grado' => null, 'seccion' => null,
                    'teacher_name' => $request['requester'], 'student_name' => null, 'student_id' => null,
                    'bimestre' => null, 'count_low_grades' => null, 'failed_courses' => null, 'teacher_id' => null
                ];
            }
            $requestStmt->close();
        }
    }
}

usort($notifications, static function ($a, $b) {
    return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});
$notifications = array_slice($notifications, 0, 50);
$notifications_count = count($notifications);
$notificationBadge = $notifications_count > 99 ? '99+' : (string)$notifications_count;
?>

<link rel="stylesheet" href="css/topbar-modern.css?v=<?php echo @filemtime(__DIR__ . '/../css/topbar-modern.css') ?: time(); ?>">

<nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 sticky-top edusync-topbar">
    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-2" aria-label="Abrir menú lateral">
        <i class="fa fa-bars"></i>
    </button>

    <div class="edusync-topbar-context d-none d-md-flex" title="<?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="edusync-topbar-school-icon">
            <?php if ($hasSchoolLogo): ?>
                <img src="<?php echo htmlspecialchars($schoolLogoPath, ENT_QUOTES, 'UTF-8'); ?>"
                     alt="Logo de <?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?>"
                     style="width:100%;height:100%;object-fit:contain;border-radius:.7rem;background:#fff;padding:.12rem;">
            <?php else: ?>
                <i class="fas fa-school"></i>
            <?php endif; ?>
        </span>
        <span class="edusync-topbar-school-copy">
            <span class="edusync-topbar-school-name"><?php echo htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="edusync-topbar-school-meta">
                <span class="edusync-year-pill"><i class="far fa-calendar-alt"></i><?php echo $activeAcademicYear ? 'Año académico ' . htmlspecialchars($activeAcademicYear, ENT_QUOTES, 'UTF-8') : 'Sin año académico activo'; ?></span>
            </span>
        </span>
    </div>

    <ul class="navbar-nav ml-auto align-items-center">
        <?php if (in_array($user_type, [1, 2, 3], true)): ?>
        <li class="nav-item dropdown no-arrow mx-1">
            <a class="nav-link dropdown-toggle edusync-alert-toggle" href="#" id="alertsDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Notificaciones">
                <i class="fas fa-bell fa-fw"></i>
                <?php if ($notifications_count > 0): ?>
                    <span class="badge badge-danger badge-counter"><?php echo $notificationBadge; ?></span>
                <?php endif; ?>
            </a>
            <div class="dropdown-list dropdown-menu dropdown-menu-right shadow animated--grow-in edusync-notification-menu" aria-labelledby="alertsDropdown">
                <h6 class="dropdown-header d-flex align-items-center justify-content-between mb-0">
                    <span><i class="far fa-bell mr-1"></i>Notificaciones</span>
                    <?php if ($unified_notifications_ready || $notifications_count > 0): ?>
                    <button type="button" class="btn btn-light btn-sm py-1 px-2 font-weight-bold mark-all-read-topbar<?php echo $notifications_count > 0 ? '' : ' d-none'; ?>" title="Marcar todas como leídas">
                        <i class="fas fa-check-double mr-1"></i>Marcar leídas
                    </button>
                    <?php endif; ?>
                </h6>

                <div id="topbar-notification-list" class="edusync-notification-list">
                    <?php if ($notifications_count > 0): ?>
                        <?php foreach ($notifications as $notification):
                            $type = $notification['type'] ?? '';
                            $time_ago = time_elapsed_string($notification['created_at'] ?? 'now');
                            if ($type === 'evaluation') {
                                $icon_bg = 'bg-primary'; $icon_class = 'fa-file-alt'; $kind = 'Evaluación'; $kindClass = '';
                            } elseif ($type === 'attendance_request') {
                                $icon_bg = 'bg-warning'; $icon_class = 'fa-user-check'; $kind = 'Asistencia'; $kindClass = ' kind-attendance';
                            } else {
                                $icon_bg = 'bg-danger'; $icon_class = 'fa-exclamation-triangle'; $kind = 'Alerta académica'; $kindClass = ' kind-alert';
                            }
                            $link = '#';
                            $onclick = '';
                            if ($type === 'evaluation') {
                                $onclick = "onclick=\"uni_modal('Ver Evaluación', 'manage_evaluation_grades.php?evaluation_id=" . (int)$notification['notification_id'] . "&from=notifications', 'large'); return false;\"";
                            } elseif ($type === 'attendance_request') {
                                $onclick = "onclick=\"uni_modal('Autorizar cambio de asistencia', 'review_attendance_request.php?id=" . (int)$notification['notification_id'] . "', 'modal-lg'); return false;\"";
                            } else {
                                $courses = json_decode($notification['failed_courses'] ?? '', true);
                                $course_name_url = (is_array($courses) && isset($courses[0]['name'])) ? urlencode($courses[0]['name']) : '';
                                $link = 'index.php?page=student_low_grades&student_id=' . (int)$notification['student_id'] . '&bimestre=' . urlencode((string)$notification['bimestre']) . '&course_name=' . $course_name_url . '&notification_id=' . (int)$notification['notification_id'] . '&from=notifications';
                            }
                        ?>
                        <a class="dropdown-item d-flex align-items-center system-notification-item<?php echo $type === 'attendance_request' ? ' attendance-request-notification' : ''; ?>" href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($type === 'attendance_request'): ?>data-request-id="<?php echo (int)$notification['notification_id']; ?>"<?php endif; ?> <?php echo $onclick; ?>>
                            <div class="mr-3"><div class="icon-circle <?php echo $icon_bg; ?>"><i class="fas <?php echo $icon_class; ?> text-white"></i></div></div>
                            <div class="edusync-notification-copy">
                                <div class="edusync-notification-meta">
                                    <span class="edusync-notification-kind<?php echo $kindClass; ?>"><?php echo $kind; ?></span>
                                    <span class="small text-gray-500"><?php echo htmlspecialchars($time_ago, ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <span class="font-weight-bold edusync-notification-title">
                                    <?php if ($type === 'evaluation'): ?>
                                        <?php echo htmlspecialchars($notification['teacher_name'] ?? 'Docente', ENT_QUOTES, 'UTF-8'); ?>: nueva evaluación en <?php echo htmlspecialchars($notification['course_name'] ?? 'curso', ENT_QUOTES, 'UTF-8'); ?>
                                    <?php elseif ($type === 'attendance_request'): ?>
                                        <?php echo htmlspecialchars($notification['teacher_name'] ?? 'Usuario', ENT_QUOTES, 'UTF-8'); ?> solicita: <?php echo htmlspecialchars($notification['title'] ?? 'cambio de asistencia', ENT_QUOTES, 'UTF-8'); ?>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($notification['student_name'] ?? 'Estudiante', ENT_QUOTES, 'UTF-8'); ?>: notas bajas detectadas
                                    <?php endif; ?>
                                </span>
                                <div class="edusync-notification-detail">
                                    <?php if ($type === 'attendance_request'): ?>
                                        Requiere autorización
                                    <?php else: ?>
                                        <?php echo htmlspecialchars(trim(($notification['level'] ?? '') . ' - ' . ($notification['grado'] ?? '') . ' ' . ($notification['seccion'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="dropdown-item d-flex align-items-center topbar-empty-notifications py-3">
                            <div class="mr-3"><div class="icon-circle bg-gray-200"><i class="fas fa-check text-gray-400"></i></div></div>
                            <div><span class="font-weight-bold text-gray-700">No hay notificaciones nuevas</span><div class="small text-gray-500">Todo está al día.</div></div>
                        </div>
                    <?php endif; ?>
                </div>

                <a class="dropdown-item text-center small font-weight-bold text-primary py-2" href="index.php?page=notifications">Ver todas las notificaciones</a>
            </div>
        </li>
        <?php endif; ?>

        <div class="topbar-divider d-none d-sm-block"></div>

        <li class="nav-item dropdown no-arrow">
            <a class="nav-link dropdown-toggle edusync-user-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <span class="edusync-user-summary d-none d-lg-flex mr-2">
                    <span class="edusync-user-name"><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="edusync-user-role"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <?php if ($hasAvatar): ?>
                    <img class="edusync-avatar" src="<?php echo htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar">
                <?php else: ?>
                    <span class="edusync-avatar-initials" aria-label="Iniciales del usuario"><?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </a>

            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in edusync-profile-menu" aria-labelledby="userDropdown">
                <div class="edusync-profile-card">
                    <?php if ($hasAvatar): ?>
                        <img class="edusync-avatar" src="<?php echo htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar">
                    <?php else: ?>
                        <span class="edusync-avatar-initials"><?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <div class="edusync-profile-card-copy">
                        <div class="edusync-profile-card-name"><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="edusync-profile-card-role"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>

                <a class="dropdown-item" href="#" id="manage_my_account" data-action="manage_my_account">
                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>Mi Perfil
                </a>
                <?php if ($user_type === 1): ?>
                <a class="dropdown-item" href="#" id="manage_school" data-action="manage_school">
                    <i class="fas fa-school fa-sm fa-fw mr-2 text-gray-400"></i>Perfil del Colegio
                </a>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-danger" href="#" id="logout_btn">
                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-danger"></i>Cerrar Sesión
                </a>
            </div>
        </li>
    </ul>
</nav>

<script>
(function initTopbar(){
    if (typeof window.jQuery === 'undefined') return setTimeout(initTopbar, 50);
    var $ = window.jQuery;

    function badgeValue(total) {
        total = Number(total) || 0;
        return total > 99 ? '99+' : String(total);
    }

    function setNotificationBadge(total) {
        total = Number(total) || 0;
        var badge = $('#alertsDropdown .badge-counter');
        if (total > 0) {
            if (!badge.length) badge = $('<span class="badge badge-danger badge-counter"></span>').appendTo('#alertsDropdown');
            badge.text(badgeValue(total));
        } else {
            badge.remove();
        }
    }

    function emptyNotificationState(message) {
        return $('<div class="dropdown-item d-flex align-items-center topbar-empty-notifications py-3"></div>')
            .append('<div class="mr-3"><div class="icon-circle bg-gray-200"><i class="fas fa-check text-gray-400"></i></div></div>')
            .append($('<div></div>').append($('<span class="font-weight-bold text-gray-700"></span>').text(message || 'No hay notificaciones nuevas')).append('<div class="small text-gray-500">Todo está al día.</div>'));
    }

    function notificationKind(category, priority) {
        if (category === 'Asistencia') return {label:'Asistencia', cls:' kind-attendance', bg:'bg-warning', icon:'fa-user-check'};
        if (category === 'Alerta estudiantil' || priority === 'Alta') return {label:'Alerta académica', cls:' kind-alert', bg:'bg-danger', icon:'fa-exclamation-triangle'};
        return {label:category || 'Notificación', cls:'', bg:'bg-primary', icon:'fa-bell'};
    }

    $(function(){
        $(document).off('click.markAllRead').on('click.markAllRead', '.mark-all-read-topbar', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var button = $(this).addClass('disabled').attr('aria-disabled', 'true');

            <?php if ($unified_notifications_ready): ?>
            $.post('notifications_api.php?action=mark_all', {csrf_token: <?php echo json_encode($topbar_csrf); ?>, tab: 'pending'}, null, 'json')
                .done(function(resp){
                    if (!resp || Number(resp.status) !== 1) {
                        if (typeof alert_toast === 'function') alert_toast((resp && resp.message) || 'No se pudieron actualizar las notificaciones.', 'error');
                        return;
                    }
                    setNotificationBadge(0);
                    button.addClass('d-none');
                    if (typeof alert_toast === 'function') alert_toast(resp.message || 'Notificaciones marcadas como leídas.', 'success');
                    if (typeof refreshUnifiedNotifications === 'function') refreshUnifiedNotifications();
                    $(document).trigger('notifications:all-read');
                })
                .fail(function(xhr){
                    var msg = (xhr.responseJSON || {}).message || 'No se pudieron actualizar las notificaciones.';
                    if (typeof alert_toast === 'function') alert_toast(msg, 'error');
                })
                .always(function(){ button.removeClass('disabled').removeAttr('aria-disabled'); });
            <?php else: ?>
            if (typeof start_load === 'function') start_load();
            $.ajax({url:'ajax.php?action=mark_all_notifications_read', method:'POST', dataType:'json', cache:false})
                .done(function(resp){
                    if (resp && Number(resp.status) === 1) {
                        if (typeof alert_toast === 'function') alert_toast('Notificaciones marcadas como leídas', 'success');
                        setTimeout(function(){ location.reload(); }, 600);
                    } else if (typeof alert_toast === 'function') {
                        alert_toast((resp && resp.message) || 'No se pudieron marcar las notificaciones.', 'error');
                    }
                })
                .fail(function(){ if (typeof alert_toast === 'function') alert_toast('Error de comunicación con el servidor', 'error'); })
                .always(function(){ if (typeof end_load === 'function') end_load(); button.removeClass('disabled').removeAttr('aria-disabled'); });
            <?php endif; ?>
        });

        // Control propio del botón móvil para evitar doble ejecución con SB Admin.
        $('#sidebarToggleTop').off('click.edusyncTopbar').on('click.edusyncTopbar', function(e){
            e.preventDefault();
            e.stopImmediatePropagation();
            $('body').toggleClass('sidebar-toggled');
            $('.sidebar').toggleClass('toggled');
            if ($('.sidebar').hasClass('toggled') && $('.sidebar .collapse').length && typeof $.fn.collapse === 'function') $('.sidebar .collapse').collapse('hide');
            return false;
        });

        function waitForUniModal(callback) {
            if (typeof window.uni_modal === 'function') callback();
            else setTimeout(function(){ waitForUniModal(callback); }, 50);
        }

        $('#manage_my_account').off('click.topbarProfile').on('click.topbarProfile', function(e){
            e.preventDefault();
            <?php if ($is_student): ?>
            waitForUniModal(function(){ window.uni_modal('Mi Perfil', 'pages/student_profile.php', 'mid-large'); });
            <?php else: ?>
            waitForUniModal(function(){ window.uni_modal('Mi Perfil', 'manage_user.php?id=<?php echo $user_id; ?>', 'mid-large'); });
            <?php endif; ?>
        });

        $('#manage_school').off('click.topbarProfile').on('click.topbarProfile', function(e){
            e.preventDefault();
            waitForUniModal(function(){ window.uni_modal('Perfil del Colegio', 'pages/manage_school_profile.php', 'large'); });
        });

        $('#logout_btn').off('click.topbarLogout').on('click.topbarLogout', function(e){
            e.preventDefault();
            var modal = $('#logoutModal');
            if (modal.length) modal.modal('show');
            else window.location.href = 'logout.php';
        });

        <?php if ($can_review_attendance && !$unified_notifications_ready): ?>
        var attendanceNotificationsLoading = false;
        var baseNotificationCount = $('#topbar-notification-list .system-notification-item').not('.attendance-request-notification').length;

        function buildAttendanceNotification(row) {
            var item = $('<a class="dropdown-item d-flex align-items-center system-notification-item attendance-request-notification" href="#"></a>').attr('data-request-id', row.id);
            item.on('click', function(e){
                e.preventDefault();
                waitForUniModal(function(){ window.uni_modal('Autorizar cambio de asistencia', 'review_attendance_request.php?id=' + row.id, 'modal-lg'); });
            });
            item.append('<div class="mr-3"><div class="icon-circle bg-warning"><i class="fas fa-user-check text-white"></i></div></div>');
            var copy = $('<div class="edusync-notification-copy"></div>');
            var meta = $('<div class="edusync-notification-meta"></div>');
            meta.append('<span class="edusync-notification-kind kind-attendance">Asistencia</span>');
            meta.append($('<span class="small text-gray-500"></span>').text(row.created_at || 'Ahora'));
            copy.append(meta);
            copy.append($('<span class="font-weight-bold edusync-notification-title"></span>').text((row.requester || 'Usuario') + ' solicita: ' + (row.request_type || 'cambio de asistencia')));
            copy.append('<div class="edusync-notification-detail">Requiere autorización</div>');
            return item.append(copy);
        }

        function refreshAttendanceNotifications() {
            if (attendanceNotificationsLoading) return;
            attendanceNotificationsLoading = true;
            $.getJSON('attendance_api.php', {action:'requests'}).done(function(resp){
                if (!resp || Number(resp.status) !== 1) return;
                var list = $('#topbar-notification-list');
                list.find('.attendance-request-notification').remove();
                if ((resp.rows || []).length) list.find('.topbar-empty-notifications').remove();
                (resp.rows || []).forEach(function(row){ list.prepend(buildAttendanceNotification(row)); });
                var total = baseNotificationCount + (resp.rows || []).length;
                if (!list.find('.system-notification-item').length) {
                    list.find('.topbar-empty-notifications').remove();
                    list.append(emptyNotificationState());
                }
                setNotificationBadge(total);
            }).always(function(){ attendanceNotificationsLoading = false; });
        }

        refreshAttendanceNotifications();
        window.setInterval(refreshAttendanceNotifications, 5000);
        <?php endif; ?>

        $(document).off('attendance:request-reviewed.topbar').on('attendance:request-reviewed.topbar', function(e, requestId){
            $('.attendance-request-notification[data-request-id="' + requestId + '"]').remove();
            <?php if ($can_review_attendance && !$unified_notifications_ready): ?>refreshAttendanceNotifications();<?php endif; ?>
        });

        <?php if ($unified_notifications_ready): ?>
        var unifiedNotificationsLoading = false;
        window.refreshUnifiedNotifications = function(){
            if (unifiedNotificationsLoading) return;
            unifiedNotificationsLoading = true;
            $.getJSON('notifications_api.php', {action:'list', tab:'pending', start:0, length:10, draw:1}).done(function(resp){
                if (!resp || !Array.isArray(resp.data)) return;
                var list = $('#topbar-notification-list').empty();
                resp.data.forEach(function(row){
                    var kind = notificationKind(row.category, row.priority);
                    var item = $('<a class="dropdown-item d-flex align-items-center system-notification-item" href="#"></a>');
                    item.append('<div class="mr-3"><div class="icon-circle ' + kind.bg + '"><i class="fas ' + kind.icon + ' text-white"></i></div></div>');
                    var copy = $('<div class="edusync-notification-copy"></div>');
                    var meta = $('<div class="edusync-notification-meta"></div>');
                    meta.append($('<span class="edusync-notification-kind' + kind.cls + '"></span>').text(kind.label));
                    meta.append($('<span class="small text-gray-500"></span>').text(row.created_at || 'Ahora'));
                    copy.append(meta);
                    copy.append($('<span class="font-weight-bold edusync-notification-title"></span>').text(row.title || 'Notificación'));
                    copy.append($('<div class="edusync-notification-detail"></div>').text(row.message || row.category || ''));
                    item.append(copy).on('click', function(e){
                        e.preventDefault();
                        if (row.open_mode === 'modal') waitForUniModal(function(){ window.uni_modal('Detalle de notificación', row.open_url, 'modal-lg'); });
                        else window.location.href = row.open_url || 'index.php?page=notifications';
                    });
                    list.append(item);
                });
                if (!resp.data.length) list.append(emptyNotificationState('No hay notificaciones pendientes'));
                var unread = Number((resp.summary || {}).unread || 0);
                setNotificationBadge(unread);
                $('.mark-all-read-topbar').toggleClass('d-none', unread <= 0);
            }).always(function(){ unifiedNotificationsLoading = false; });
        };

        window.refreshUnifiedNotifications();
        window.setInterval(window.refreshUnifiedNotifications, 7000);
        $(document).on('attendance:request-reviewed.unified', window.refreshUnifiedNotifications);
        <?php endif; ?>
    });
})();
</script>
