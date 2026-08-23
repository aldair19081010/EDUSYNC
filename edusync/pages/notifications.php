<?php 
include 'db_connect.php'; 
// Iniciar sesión solo si no hay una activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si la función ya existe antes de declararla
if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime, $full = false) {
        $now = new DateTime;
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        
        $weeks = floor($diff->days / 7);
        $days_remaining = $diff->days % 7;
        
        $string = array(
            'y' => 'año',
            'm' => 'mes',
            'w' => 'semana',
            'd' => 'día',
            'h' => 'hora',
            'i' => 'minuto',
            's' => 'segundo',
        );
        
        $plural = array(
            'y' => 'años',
            'm' => 'meses',
            'w' => 'semanas',
            'd' => 'días',
            'h' => 'horas',
            'i' => 'minutos',
            's' => 'segundos',
        );
        
        foreach ($string as $k => &$v) {
            if ($k === 'w') {
                if ($weeks) {
                    $v = $weeks . ' ' . ($weeks > 1 ? $plural[$k] : $v);
                } else {
                    unset($string[$k]);
                }
            } else if ($k === 'd') {
                if ($days_remaining) {
                    $v = $days_remaining . ' ' . ($days_remaining > 1 ? $plural[$k] : $v);
                } else {
                    unset($string[$k]);
                }
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

// Crear tablas si no existen
$conn->query("CREATE TABLE IF NOT EXISTS `low_grade_notifications` (
  `id` int(30) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `student_id` int(11) NOT NULL,
  `bimestre` varchar(2) NOT NULL,
  `count_low_grades` int(11) NOT NULL COMMENT 'Cantidad de notas bajas',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `teacher_id` int(11) NULL COMMENT 'ID del profesor si la notificación es para un profesor',
  `failed_courses` TEXT NULL COMMENT 'Lista de cursos con notas desaprobatorias',
  KEY `student_id` (`student_id`),
  KEY `bimestre` (`bimestre`),
  KEY `teacher_id` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Verificar columnas
$column_check = $conn->query("SHOW COLUMNS FROM `low_grade_notifications` LIKE 'teacher_id'");
if ($column_check->num_rows == 0) {
    $conn->query("ALTER TABLE `low_grade_notifications` ADD COLUMN `teacher_id` int(11) NULL AFTER `is_read`");
}

$column_check = $conn->query("SHOW COLUMNS FROM `low_grade_notifications` LIKE 'failed_courses'");
if ($column_check->num_rows == 0) {
    $conn->query("ALTER TABLE `low_grade_notifications` ADD COLUMN `failed_courses` TEXT NULL");
}

$conn->query("CREATE TABLE IF NOT EXISTS `low_grade_notification_read` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `notification_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `read_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `user_notification` (`user_id`, `notification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$check_column = $conn->query("SHOW COLUMNS FROM `low_grade_notification_read` LIKE 'low_grade_notification_id'");
if ($check_column && $check_column->num_rows > 0) {
    $check_new_column = $conn->query("SHOW COLUMNS FROM `low_grade_notification_read` LIKE 'notification_id'");
    if ($check_new_column && $check_new_column->num_rows == 0) {
        $conn->query("ALTER TABLE `low_grade_notification_read` CHANGE `low_grade_notification_id` `notification_id` INT(11) NOT NULL");
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS `notification_read` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `evaluation_id` int(11) NOT NULL,
    `read_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_evaluation_unique` (`user_id`, `evaluation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Obtener school_id del usuario
$school_id = intval($_SESSION['login_school_id'] ?? 0);
$school_filter_students = $school_id ? " AND s.school_id = $school_id" : " AND 1 = 0";
$school_filter_teacher_courses = $school_id ? " AND tc.school_id = $school_id" : " AND 1 = 0";

// Generar notificaciones si es la primera carga (por escuela)
$has_notifications_sql = "SELECT COUNT(*) as total FROM low_grade_notifications lgn JOIN student s ON s.id = lgn.student_id WHERE 1=1" . $school_filter_students;
$has_notifications = $conn->query($has_notifications_sql)->fetch_assoc()['total'];
if ($has_notifications == 0 && $school_id && isset($_GET['evaluation_id'])) {
    include_once __DIR__ . '/../admin_class.php';
    $crud = new Action();
    $crud->generate_low_grade_notifications(intval($_GET['evaluation_id']));
}

// Obtener datos del usuario
$user_id = isset($_SESSION['login_id']) ? $_SESSION['login_id'] : 0;
$user_type = isset($_SESSION['login_type']) ? $_SESSION['login_type'] : 0;
$teacher_id = isset($_SESSION['login_teacher_id']) ? $_SESSION['login_teacher_id'] : 0;

// Consulta de evaluaciones
$evaluation_query = "
SELECT 
    'evaluation' as type,
    e.id as id,
    e.title as title,
    e.created_at,
    ac.name as course_name,
    tc.level as level,
    tc.grado as grade,
    tc.seccion as section,
    t.name as teacher_name,
    NULL as student_name,
    NULL as student_id,
    NULL as bimestre,
    NULL as count_low_grades,
    NULL as failed_courses,
    CASE WHEN nr.id IS NULL THEN 0 ELSE 1 END as is_read,
    NULL as teacher_id
FROM 
    evaluations e
    JOIN teacher_courses tc ON e.teacher_course_id = tc.id
    JOIN academic_courses ac ON tc.course_id = ac.id
    JOIN teacher t ON tc.teacher_id = t.id
    LEFT JOIN notification_read nr ON nr.evaluation_id = e.id AND nr.user_id = $user_id
WHERE 
    e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . $school_filter_teacher_courses . "
";

// Consulta de notas bajas
$low_grades_query = "
SELECT 
    'low_grade' as type,
    lgn.id as id,
    CONCAT('Notas Bajas - Bimestre ', lgn.bimestre) as title,
    lgn.created_at,
    NULL as course_name,
    s.nivel as level,
    s.grado as grade,
    s.seccion as section,
    NULL as teacher_name,
    s.name as student_name,
    lgn.student_id as student_id,
    lgn.bimestre as bimestre,
    lgn.count_low_grades as count_low_grades,
    lgn.failed_courses,
    CASE WHEN lgnr.id IS NULL THEN 0 ELSE 1 END as is_read,
    lgn.teacher_id
FROM 
    low_grade_notifications lgn
    JOIN student s ON lgn.student_id = s.id
    LEFT JOIN low_grade_notification_read lgnr ON lgnr.notification_id = lgn.id AND lgnr.user_id = {$user_id}
WHERE 
    lgn.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . $school_filter_students . "
";

// Filtrar por tipo de usuario
if ($user_type == 2 && $teacher_id > 0) {
    $low_grades_query .= " AND lgn.teacher_id = $teacher_id";
} elseif ($user_type == 1) {
    $low_grades_query .= " AND lgn.teacher_id IS NULL";
} else {
    $low_grades_query .= " AND 1 = 0"; 
}

// Combinar consultas según tipo de usuario
if ($user_type == 2 && $teacher_id > 0) {
    $notification_query = "
    SELECT * FROM (
        $low_grades_query
    ) as notifications
    ORDER BY 
        is_read ASC, created_at DESC 
    LIMIT 50";
} else {
    $notification_query = "
    SELECT * FROM (
        $evaluation_query
        UNION ALL
        $low_grades_query
    ) as notifications
    ORDER BY 
        is_read ASC, created_at DESC 
    LIMIT 50";
}

$notification_result = $conn->query($notification_query);

// Contar notificaciones no leídas
$unread_count = 0;
if($notification_result && $notification_result->num_rows > 0) {
    while($row = $notification_result->fetch_assoc()) {
        if(isset($row['is_read']) && !$row['is_read']) $unread_count++;
    }
    $notification_result->data_seek(0);
}
?>

<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-bell text-primary mr-2"></i>Centro de Notificaciones
            <?php if($unread_count > 0): ?>
            <span class="badge badge-danger ml-2"><?php echo $unread_count; ?> sin leer</span>
            <?php endif; ?>
        </h1>
        <?php if($unread_count > 0): ?>
        <button class="d-none d-sm-inline-block btn btn-sm btn-outline-primary shadow-sm mark-all-read">
            <i class="fas fa-check-double fa-sm mr-1"></i> Marcar todas como leídas
        </button>
        <?php endif; ?>
    </div>

    <!-- Notificaciones Card -->
    <div class="card shadow mb-4">
        <div class="card-body p-0">
            <?php if($notification_result && $notification_result->num_rows > 0): ?>
            <div class="notification-container">
                <?php
                while($notification = $notification_result->fetch_assoc()): 
                    $time_ago = time_elapsed_string($notification['created_at']);
                    $is_read = isset($notification['is_read']) && $notification['is_read'];
                ?>
                <div class="notification-item <?php echo $is_read ? 'notification-read' : 'notification-unread'; ?>">
                    <div class="row no-gutters align-items-center">
                        <!-- Icon -->
                        <div class="col-auto mr-3 ml-3 mt-3">
                            <?php if ($notification['type'] == 'evaluation'): ?>
                                <div class="icon-circle bg-primary">
                                    <i class="fas fa-file-alt text-white"></i>
                                </div>
                            <?php else: ?>
                                <div class="icon-circle bg-danger">
                                    <i class="fas fa-exclamation-triangle text-white"></i>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Content -->
                        <div class="col">
                            <div class="notification-content">
                                <div class="text-muted small mb-1"><?php echo htmlspecialchars($time_ago); ?></div>
                                
                                <?php if ($notification['type'] == 'evaluation'): ?>
                                    <!-- Notificación de Evaluación -->
                                    <div class="font-weight-bold text-dark mb-2">
                                        <?php echo htmlspecialchars($notification['teacher_name']); ?> 
                                        ha subido una evaluación
                                    </div>
                                    <div class="mb-2">
                                        <span class="text-primary font-weight-bold">
                                            <?php echo htmlspecialchars($notification['title']); ?>
                                        </span>
                                    </div>
                                    <div class="notification-meta">
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($notification['course_name']); ?>
                                        </span>
                                        <span class="badge badge-secondary">
                                            <?php echo htmlspecialchars($notification['level']); ?>
                                            <?php if(!empty($notification['grade'])): ?> - <?php echo htmlspecialchars($notification['grade']); ?><?php endif; ?>
                                            <?php if(!empty($notification['section']) && $notification['section'] != 'U'): ?> - <?php echo htmlspecialchars($notification['section']); ?><?php endif; ?>
                                        </span>
                                    </div>

                                <?php else: ?>
                                    <!-- Notificación de Notas Bajas -->
                                    <div class="font-weight-bold text-dark mb-2">
                                        <?php echo htmlspecialchars($notification['student_name']); ?> tiene notas bajas
                                    </div>
                                    <div class="mb-2">
                                        <?php 
                                        $courses = json_decode($notification['failed_courses'], true);
                                        if(is_array($courses) && count($courses) > 0 && isset($courses[0]['name'])):
                                            $course_name = $courses[0]['name'];
                                            $count = $courses[0]['count'];
                                        ?>
                                            <span class="text-danger font-weight-bold">
                                                <?php echo $count; ?> calificaciones bajas
                                            </span>
                                            <span>en </span>
                                            <span class="font-weight-bold">
                                                <?php echo htmlspecialchars($course_name); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-danger font-weight-bold">Calificaciones bajas detectadas</span>
                                        <?php endif; ?>
                                        <span> - Bimestre <?php echo htmlspecialchars($notification['bimestre']); ?></span>
                                    </div>
                                    <div class="notification-meta">
                                        <span class="badge badge-warning">Requiere atención</span>
                                        <span class="badge badge-secondary">
                                            <?php echo htmlspecialchars($notification['level']); ?>
                                            <?php if(!empty($notification['grade'])): ?> - <?php echo htmlspecialchars($notification['grade']); ?><?php endif; ?>
                                            <?php if(!empty($notification['section']) && $notification['section'] != 'U'): ?> - <?php echo htmlspecialchars($notification['section']); ?><?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="col-auto pr-3 pt-3 pb-3">
                            <div class="btn-group btn-group-sm" role="group">
                                <?php if ($notification['type'] == 'evaluation'): ?>
                                    <button type="button" class="btn btn-outline-primary view-evaluation" 
                                            data-evaluation-id="<?php echo $notification['id']; ?>" 
                                            title="Ver evaluación">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (!$is_read): ?>
                                        <button type="button" class="btn btn-outline-secondary mark-read" 
                                                data-id="<?php echo $notification['id']; ?>" 
                                                data-type="evaluation" title="Marcar como leída">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php
                                    $courses = json_decode($notification['failed_courses'], true);
                                    $course_name_url_param = '';
                                    if(is_array($courses) && count($courses) > 0 && isset($courses[0]['name'])):
                                        $course_name_url_param = urlencode($courses[0]['name']);
                                    endif;
                                    ?>
                                    <a href="index.php?page=student_low_grades&student_id=<?php echo $notification['student_id']; ?>&bimestre=<?php echo $notification['bimestre']; ?>&course_name=<?php echo $course_name_url_param; ?>&notification_id=<?php echo $notification['id']; ?>&from=notifications" 
                                       class="btn btn-outline-danger" title="Ver notas bajas">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (!$is_read): ?>
                                        <?php
                                        $course_name_for_mark = '';
                                        if(is_array($courses) && count($courses) > 0 && isset($courses[0]['name'])) {
                                            $course_name_for_mark = $courses[0]['name'];
                                        }
                                        ?>
                                        <button type="button" class="btn btn-outline-secondary mark-read" 
                                                data-id="<?php echo $notification['id']; ?>" 
                                                data-student-id="<?php echo $notification['student_id']; ?>"
                                                data-bimestre="<?php echo $notification['bimestre']; ?>"
                                                data-course-name="<?php echo htmlspecialchars($course_name_for_mark); ?>"
                                                data-type="low_grade" title="Marcar como leída">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>

            <?php if($unread_count === 0): ?>
            <div class="alert alert-info m-3 text-center">
                <i class="fas fa-check-circle mr-2"></i> ¡Excelente! Has leído todas tus notificaciones.
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="p-5 text-center text-muted">
                <i class="fas fa-inbox fa-3x mb-3 text-gray-300"></i>
                <h5>No hay notificaciones disponibles</h5>
                <p class="small">Cuando haya evaluaciones o notas bajas, aparecerán aquí</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.notification-container { max-height: 800px; overflow-y: auto; }
.notification-item { border-bottom: 1px solid #e3e6f0; padding: 0; transition: all 0.3s ease; position: relative; }
.notification-item:last-child { border-bottom: none; }
.notification-item:hover { background-color: #f8f9fc; }
.notification-unread { background-color: #f8f9fc; border-left: 4px solid #4e73df; }
.notification-read { opacity: 0.85; }
.icon-circle { width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
.notification-content { padding: 15px 0; }
.notification-meta { margin-top: 8px; }
.notification-meta .badge { margin-right: 5px; margin-bottom: 5px; }
.btn-group-sm .btn { padding: 0.25rem 0.5rem; font-size: 0.85rem; }
/* Scrollbar */
.notification-container::-webkit-scrollbar { width: 6px; }
.notification-container::-webkit-scrollbar-track { background: #f1f1f1; }
.notification-container::-webkit-scrollbar-thumb { background: #888; border-radius: 3px; }
.notification-container::-webkit-scrollbar-thumb:hover { background: #555; }
</style>

<script>
$(document).ready(function(){
    $('.mark-all-read').click(function(){
        start_load();
        $.ajax({
            url: 'ajax.php?action=mark_all_notifications_read',
            method: 'POST',
            dataType: 'json',
            success: function(resp) {
                if(resp.status == 1) {
                    alert_toast("Todas las notificaciones han sido marcadas como leídas", "success");
                    setTimeout(function(){
                        location.reload();
                    }, 1500);
                } else {
                    alert_toast(resp.message || "Ocurrió un error. Por favor, intenta de nuevo.", "error");
                    end_load();
                }
            },
            error: function(xhr, status, error) {
                alert_toast("Error de comunicación con el servidor. Por favor, intenta de nuevo.", "error");
                end_load();
            }
        });
    });

    $('.mark-read').click(function(e){
        e.preventDefault();
        var id = $(this).data('id');
        var type = $(this).data('type');
        
        start_load();
        
        var ajax_data = { notification_id: id };
        var ajax_url = '';

        if (type === 'evaluation') {
            ajax_url = 'ajax.php?action=mark_notification_read';
            ajax_data = { evaluation_id: id };
        } else if (type === 'low_grade') {
            ajax_url = 'ajax.php?action=mark_low_grade_notification_read';
            ajax_data.student_id = $(this).data('student-id');
            ajax_data.bimestre = $(this).data('bimestre');
            ajax_data.course_name = $(this).data('course-name');
        }

        $.ajax({
            url: ajax_url,
            method: 'POST',
            data: ajax_data,
            dataType: 'json',
            success: function(resp) {
                if(resp.status == 1) {
                    alert_toast(resp.message || "Notificación marcada como leída", "success");
                    setTimeout(function(){
                        location.reload();
                    }, 1500);
                } else {
                    alert_toast(resp.message || "Ocurrió un error. Por favor, intenta de nuevo.", "error");
                    end_load();
                }
            },
            error: function(xhr, status, error) {
                alert_toast("Error de comunicación con el servidor. Por favor, intenta de nuevo.", "error");
                end_load();
            }
        });
    });

    // Ver evaluación en modal
    $(document).on('click', '.view-evaluation', function(){
        var evaluation_id = $(this).data('evaluation-id');
        uni_modal("Ver Evaluación", "manage_evaluation_grades.php?evaluation_id=" + evaluation_id + "&from=notifications", "large");
    });
});
</script>
