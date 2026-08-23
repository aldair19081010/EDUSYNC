<?php
include 'db_connect.php';
// Iniciar sesión solo si no hay una activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Comprobar que el usuario está autenticado
if (!isset($_SESSION['login_id'])) {
    echo "<div class='alert alert-danger'>Acceso denegado</div>";
    exit;
}

// Obtener parámetros
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$bimestre = isset($_GET['bimestre']) ? $_GET['bimestre'] : '';
$course_name = isset($_GET['course_name']) ? $_GET['course_name'] : '';
$notification_id = isset($_GET['notification_id']) ? intval($_GET['notification_id']) : 0;
$from_notification = isset($_GET['from']) && $_GET['from'] == 'notifications';

// Validar parámetros
if (!$student_id || !$bimestre) {
    echo "<div class='alert alert-danger'>Parámetros incorrectos</div>";
    exit;
}

// Obtener información del estudiante
$student_query = $conn->query("SELECT s.*, COALESCE(s.nivel, tc.level) as nivel, COALESCE(s.grado, tc.grado) as grado, 
    COALESCE(s.seccion, tc.seccion) as seccion 
    FROM student s 
    LEFT JOIN teacher_courses tc ON LOWER(tc.level) = LOWER(s.nivel) AND tc.grado = s.grado AND tc.seccion = s.seccion
    WHERE s.id = $student_id LIMIT 1");

if ($student_query->num_rows === 0) {
    echo "<div class='alert alert-danger'>Estudiante no encontrado</div>";
    exit;
}

$student = $student_query->fetch_assoc();

// Marcar la notificación como leída si viene desde notificaciones
if ($from_notification) {
    $user_id = $_SESSION['login_id'];
    $user_type = isset($_SESSION['login_type']) ? $_SESSION['login_type'] : 0;
    $teacher_id = isset($_SESSION['login_teacher_id']) ? $_SESSION['login_teacher_id'] : 0;
    
    // Construir la condición de filtro por tipo de usuario
    $user_condition = '';
    if ($user_type == 2 && $teacher_id > 0) { // Profesor
        $user_condition = " AND teacher_id = $teacher_id";
    } elseif ($user_type == 1) { // Admin
        $user_condition = " AND teacher_id IS NULL"; // Solo marca como leídas las notificaciones de admin
    }
    
    // Si tenemos un course_name, lo usamos para marcar solo la notificación específica
    if (!empty($course_name)) {
        $course_name_esc = $conn->real_escape_string($course_name);
        $conn->query("UPDATE low_grade_notifications 
                     SET is_read = 1 
                     WHERE student_id = $student_id 
                     AND bimestre = '$bimestre'
                     AND failed_courses LIKE '%\"name\":\"$course_name_esc\"%'" . $user_condition);
    } 
    // Si tenemos un notification_id específico
    else if ($notification_id > 0) {
        $conn->query("UPDATE low_grade_notifications 
                     SET is_read = 1 
                     WHERE id = $notification_id" . $user_condition);
    }
    // Caso antiguo (marcar todas las notificaciones de ese estudiante/bimestre)
    else {
        $conn->query("UPDATE low_grade_notifications 
                     SET is_read = 1 
                     WHERE student_id = $student_id 
                     AND bimestre = '$bimestre'" . $user_condition);
    }
}

// Función de ayuda para determinar el color y texto según la nota
function getGradeStyle($grade) {
    if ($grade >= 16) {
        return [
            'color_class' => 'success',
            'color_bar' => '#28a745',
            'text' => 'Excelente',
            'icon' => 'trophy'
        ];
    } elseif ($grade >= 11) {
        return [
            'color_class' => 'primary',
            'color_bar' => '#4e73df',
            'text' => 'Aprobado',
            'icon' => 'check-circle'
        ];
    } elseif ($grade >= 6) {
        return [
            'color_class' => 'warning',
            'color_bar' => '#ffc107',
            'text' => 'Necesita Mejorar',
            'icon' => 'exclamation-circle'
        ];
    } else {
        return [
            'color_class' => 'danger',
            'color_bar' => '#dc3545',
            'text' => 'En Riesgo',
            'icon' => 'times-circle'
        ];
    }
}
?>

<div class="container-fluid py-4">
    <?php if ($from_notification): ?>
    <div class="alert alert-info mb-3 d-flex align-items-center">
        <i class="fas fa-lock fa-lg mr-3"></i>
        <div>
            <strong>Modo de Visualización:</strong> Estás viendo el reporte de notas en modo de solo lectura.
            <?php if (!empty($course_name)): ?>
            <div class="mt-1 small">
                <i class="fas fa-filter mr-1"></i> <strong>Filtrando por:</strong> Curso "<?php echo htmlspecialchars($course_name); ?>"
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Card Principal -->
    <div class="card shadow mb-4">
        <div class="card-header bg-gradient-primary">
            <h5 class="mb-0 text-white">
                <i class="fas fa-chart-line mr-2"></i> Reporte de Notas Bajas - Bimestre <?php echo htmlspecialchars($bimestre); ?>
                <?php if (!empty($course_name)): ?>
                <span class="ml-2 small">
                    <i class="fas fa-book mr-1"></i> <?php echo htmlspecialchars($course_name); ?>
                </span>
                <?php endif; ?>
            </h5>
        </div>
        
        <div class="card-body">
            <!-- Información del Estudiante -->
            <div class="student-info mb-4 p-3 bg-light rounded border-left border-primary">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-1 font-weight-bold"><?php echo htmlspecialchars($student['name']); ?></h5>
                        <div class="row small">
                            <div class="col-md-3">
                                <small class="text-muted">ID Estudiante:</small>
                                <p class="mb-0 font-weight-600"><?php echo htmlspecialchars($student['id_no']); ?></p>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Nivel:</small>
                                <p class="mb-0 font-weight-600"><?php echo htmlspecialchars($student['nivel']); ?></p>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Grado:</small>
                                <p class="mb-0 font-weight-600"><?php echo htmlspecialchars($student['grado']); ?></p>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Sección:</small>
                                <p class="mb-0 font-weight-600"><?php echo htmlspecialchars($student['seccion']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            // Obtener resumen de notas bajas por curso
            $course_filter = "";
            if (!empty($course_name)) {
                $course_filter = " AND ac.name = '" . $conn->real_escape_string($course_name) . "'";
            } else if ($notification_id > 0) {
                // Si tenemos un ID de notificación, buscamos el curso asociado
                $notification_query = $conn->query("SELECT failed_courses FROM low_grade_notifications WHERE id = $notification_id");
                if ($notification_query->num_rows > 0) {
                    $notification_data = $notification_query->fetch_assoc();
                    $courses = json_decode($notification_data['failed_courses'], true);
                    if (is_array($courses) && count($courses) > 0 && isset($courses[0]['name'])) {
                        $course_name = $conn->real_escape_string($courses[0]['name']);
                        $course_filter = " AND ac.name = '$course_name'";
                    }
                }
            }
            
            $course_summary_query = $conn->query("
                SELECT 
                    ac.name as course_name,
                    COUNT(*) as low_grade_count,
                    MIN(eg.grade) as lowest_grade,
                    AVG(eg.grade) as average_grade
                FROM 
                    evaluation_grades eg
                    JOIN evaluations e ON eg.evaluation_id = e.id
                    JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                    JOIN academic_courses ac ON tc.course_id = ac.id
                WHERE 
                    eg.student_id = $student_id 
                    AND e.bimestre = '$bimestre'
                    AND eg.grade <= 10
                    $course_filter
                GROUP BY 
                    ac.name
                ORDER BY 
                    low_grade_count DESC, ac.name
            ");
            
            if ($course_summary_query->num_rows > 0):
            ?>
            <!-- Resumen de Cursos con Notas Bajas -->
            <div class="alert alert-danger mb-4" style="border-left: 5px solid #dc3545;">
                <h6 class="mb-3 font-weight-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Resumen de Cursos con Notas Bajas:</h6>
                <div class="row">
                <?php while ($course_summary = $course_summary_query->fetch_assoc()): ?>
                    <div class="col-md-4 mb-2">
                        <div class="d-flex justify-content-between px-3 py-2 border-left border-danger rounded bg-light">
                            <div><strong><?php echo htmlspecialchars($course_summary['course_name']); ?></strong></div>
                            <div>
                                <span class="badge badge-danger"><?php echo $course_summary['low_grade_count']; ?> nota(s) baja(s)</span>
                                <span class="badge badge-secondary ml-1">Mín: <?php echo number_format($course_summary['lowest_grade'], 1); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
                </div>
            </div>
            <?php 
            $course_summary_query->data_seek(0);
            endif; 
            ?>

            <!-- Detalle de Evaluaciones -->
            <?php if (!empty($course_name)): ?>
            <h6 class="mb-3 font-weight-bold">Todas las Evaluaciones del Curso:</h6>
            <?php else: ?>
            <h6 class="mb-3 font-weight-bold">Detalle de Evaluaciones con Notas Bajas:</h6>
            <?php endif; ?>
            
            <?php
            // Determinar si mostrar todas las evaluaciones o solo las bajas
            $grade_filter = "";
            if (empty($course_name)) {
                $grade_filter = " AND eg.grade <= 10 ";
            }
            
            // Obtener todas las evaluaciones del estudiante en ese bimestre
            $grades_query = $conn->query("
                SELECT 
                    e.id as eval_id,
                    e.title as eval_title,
                    e.type as eval_type,
                    e.created_at,
                    eg.grade,
                    c.name as competencia_name,
                    ac.name as course_name,
                    tc.level,
                    tc.grado,
                    tc.seccion
                FROM 
                    evaluation_grades eg
                    JOIN evaluations e ON eg.evaluation_id = e.id
                    JOIN general_course_competencies c ON eg.competencia_id = c.id
                    JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                    JOIN academic_courses ac ON tc.course_id = ac.id
                WHERE 
                    eg.student_id = $student_id 
                    AND e.bimestre = '$bimestre'
                    $grade_filter
                    $course_filter
                ORDER BY 
                    ac.name, e.created_at DESC"
            );
            
            if ($grades_query->num_rows > 0):
                $current_course = '';
                
                while ($grade = $grades_query->fetch_assoc()):
                    if ($current_course != $grade['course_name']):
                        if ($current_course != ''):
                            echo '</tbody></table></div></div>';
                        endif;
                        
                        $current_course = $grade['course_name'];
            ?>
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <strong><?php echo htmlspecialchars($grade['course_name']); ?></strong>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Evaluación</th>
                                    <th>Tipo</th>
                                    <th>Competencia</th>
                                    <th width="100">Nota</th>
                                    <th width="120">Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
            <?php 
                    endif;
            ?>
                <tr class="<?php echo $grade['grade'] <= 10 ? 'table-danger' : ''; ?>">
                    <td><?php echo htmlspecialchars($grade['eval_title']); ?></td>
                    <td><?php echo htmlspecialchars($grade['eval_type']); ?></td>
                    <td><?php echo htmlspecialchars($grade['competencia_name']); ?></td>
                    <td class="text-center font-weight-bold">
                        <?php if ($grade['grade'] <= 10): ?>
                        <span class="badge badge-danger p-2"><?php echo number_format($grade['grade'], 1); ?></span>
                        <?php else: ?>
                        <span class="badge badge-success p-2"><?php echo number_format($grade['grade'], 1); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('d/m/Y', strtotime($grade['created_at'])); ?></td>
                </tr>
            <?php
                endwhile;
                echo '</tbody></table></div></div>';
                
            else:
            ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle mr-2"></i> No se encontraron notas bajas para este estudiante en el bimestre <?php echo htmlspecialchars($bimestre); ?>.
            </div>
            <?php endif; ?>

            <!-- Promedios por Curso -->
            <?php
            $avg_query = $conn->query("
                SELECT 
                    ac.id as course_id,
                    ac.name as course_name,
                    AVG(eg.grade) as simple_average,
                    MIN(eg.grade) as min_grade,
                    MAX(eg.grade) as max_grade,
                    COUNT(*) as total_evaluations,
                    SUM(CASE WHEN eg.grade <= 10 THEN 1 ELSE 0 END) as low_grades_count
                FROM 
                    evaluation_grades eg
                    JOIN evaluations e ON eg.evaluation_id = e.id
                    JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                    JOIN academic_courses ac ON tc.course_id = ac.id
                WHERE 
                    eg.student_id = $student_id 
                    AND e.bimestre = '$bimestre'
                    $course_filter
                GROUP BY 
                    ac.id, ac.name
                ORDER BY 
                    ac.name"
            );

            if ($avg_query->num_rows > 0) {
                while ($course_row = $avg_query->fetch_assoc()) {
                    $simple_avg = $course_row['simple_average'];
                    $final_average = $simple_avg;
                    $grade_style = getGradeStyle($final_average);
                    
                    echo '<h6 class="mb-3 mt-4 font-weight-bold">Promedio - '.htmlspecialchars($course_row['course_name']).' (Bimestre '.$bimestre.'):</h6>';
            ?>
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="mb-0">Promedio General:</h6>
                                        <h4 class="text-<?php echo $grade_style['color_class']; ?> mb-0"><?php echo number_format($final_average, 2); ?></h4>
                                    </div>
                                    <div class="progress mb-3" style="height: 20px;">
                                        <?php 
                                        $percent = min(($final_average / 20) * 100, 100);
                                        ?>
                                        <div class="progress-bar bg-<?php echo $grade_style['color_class']; ?>" role="progressbar" 
                                             style="width: <?php echo $percent; ?>%" 
                                             aria-valuenow="<?php echo $final_average; ?>" aria-valuemin="0" aria-valuemax="20">
                                            <?php echo number_format($final_average, 2); ?>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between small">
                                        <span>Estado: <strong class="text-<?php echo $grade_style['color_class']; ?>"><?php echo $grade_style['text']; ?></strong></span>
                                        <span>Mín: <strong><?php echo number_format($course_row['min_grade'], 1); ?></strong></span>
                                        <span>Máx: <strong><?php echo number_format($course_row['max_grade'], 1); ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body py-2">
                                            <div class="small text-muted mb-1">Resumen de Evaluaciones</div>
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Total evaluaciones:</span>
                                                <strong><?php echo $course_row['total_evaluations']; ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Desaprobadas:</span>
                                                <strong class="text-danger"><?php echo $course_row['low_grades_count']; ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span>Porcentaje:</span>
                                                <strong class="<?php echo ($course_row['total_evaluations'] > 0 && ($course_row['low_grades_count'] / $course_row['total_evaluations']) * 100 > 30) ? 'text-danger' : 'text-success'; ?>">
                                                    <?php echo $course_row['total_evaluations'] > 0 ? round(($course_row['low_grades_count'] / $course_row['total_evaluations']) * 100, 1) : 0; ?>%
                                                </strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
            <?php
                    
                    if (!empty($course_name)) {
                        break;
                    }
                }
                
                if (empty($course_name) && $avg_query->num_rows > 1) {
                    $avg_query->data_seek(0);
            ?>
                    <h6 class="mb-3 mt-4 font-weight-bold">Resumen de Promedios por Curso:</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="thead-light">
                                <tr>
                                    <th>Curso</th>
                                    <th width="120">Promedio</th>
                                    <th width="120">Estado</th>
                                    <th width="120">Evaluaciones</th>
                                    <th width="120">Notas Bajas</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                            while ($avg = $avg_query->fetch_assoc()): 
                                $avg_grade = round($avg['simple_average'], 1);
                                $status_class = $avg_grade < 11 ? 'text-danger' : 'text-success';
                                $status_text = $avg_grade < 11 ? 'En riesgo' : 'Aprobado';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($avg['course_name']); ?></td>
                                <td class="text-center font-weight-bold <?php echo $status_class; ?>">
                                    <?php echo number_format($avg_grade, 1); ?>
                                </td>
                                <td class="text-center <?php echo $status_class; ?>">
                                    <strong><?php echo $status_text; ?></strong>
                                </td>
                                <td class="text-center">
                                    <?php echo $avg['total_evaluations']; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $avg['low_grades_count'] >= 3 ? 'badge-danger' : 'badge-warning'; ?> p-2">
                                        <?php echo $avg['low_grades_count']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
            <?php
                }
            } else {
            ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle mr-2"></i> No hay promedios disponibles para este estudiante en el bimestre <?php echo htmlspecialchars($bimestre); ?>.
                </div>
            <?php
            }
            ?>
        </div>
    </div>

    <!-- Botones de Acción -->
    <div class="text-center mt-4">
        <?php if (!empty($course_name) && $from_notification): ?>
        <a href="index.php?page=student_low_grades&student_id=<?php echo $student_id; ?>&bimestre=<?php echo $bimestre; ?>&from=notifications" class="btn btn-info mr-2">
            <i class="fas fa-list mr-1"></i> Ver todas las notas
        </a>
        <?php endif; ?>
    </div>
</div>

<style>
.student-info {
    border-left: 5px solid #4e73df;
}

.table-danger {
    background-color: #ffebee;
}

.progress-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    transition: width 0.6s ease;
}

.badge-danger.p-2 {
    background-color: rgba(220, 53, 69, 0.9);
    color: white;
}

.badge-success.p-2 {
    background-color: rgba(40, 167, 69, 0.9);
    color: white;
}

.competencia-block {
    padding: 15px;
    background-color: #f8f9fa;
    border-radius: 4px;
}

.text-sm {
    font-size: 0.875rem;
}
</style>
