<?php
include 'db_connect.php';

// Configurar sesión igual que en index.php
if (session_status() == PHP_SESSION_NONE) {
    $session_save_path = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($session_save_path)) {
        @mkdir($session_save_path, 0755, true);
    }
    ini_set('session.save_path', $session_save_path);
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$school_id = $_SESSION['login_school_id'] ?? null;
$login_type = $_SESSION['login_type'] ?? null;
$teacher_id = $_SESSION['login_teacher_id'] ?? null;

if (!$school_id) {
    die("<div class=\"alert alert-danger\">Error: ID de colegio no configurado.</div>");
}
if (!$login_type) {
    die("<div class=\"alert alert-danger\">Error: Tipo de usuario no definido.</div>");
}

function normalize_level_for_key($level_name) {
    if (empty($level_name)) return null;
    return strtolower(str_replace(' ', '', trim($level_name)));
}

function format_grade($grade) {
    if (empty($grade) || $grade === null) {
        return '-';
    }
    
    $grade = trim($grade);
    
    if (in_array(strtoupper($grade), ['C', 'B', 'A', 'AD'])) {
        return strtoupper($grade);
    }
    
    if (is_numeric($grade)) {
        return number_format((float)$grade, 2);
    }
    
    return htmlspecialchars($grade);
}

function letter_to_numeric_for_calc($grade) {
    if (empty($grade)) return 0;
    
    switch(strtoupper(trim($grade))) {
        case 'AD': return 19;
        case 'A': return 15.5;
        case 'B': return 12;
        case 'C': return 5;
        default: return is_numeric($grade) ? (float)$grade : 0;
    }
}

// Get filter parameters
$course_id_filter = $_POST['course_id'] ?? null;
$level_filter = $_POST['level'] ?? null;
$grado_filter = $_POST['grado'] ?? null;
$seccion_filter = $_POST['seccion'] ?? null;
$student_id_filter = $_POST['student_id'] ?? null;
$evaluation_id_filter = $_POST['evaluation_id'] ?? null;
$bimestre_filter = $_POST['bimestre'] ?? null;
$academic_year_id = $_POST['academic_year_id'] ?? null;
$show_avg = isset($_POST['show_avg']) && $_POST['show_avg'] == '1';

$normalized_level_key = normalize_level_for_key($level_filter);

if ($show_avg && !empty($student_id_filter)) {    
    if (empty($bimestre_filter)) {        
        echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-triangle mr-2"></i>
            <strong>Bimestre requerido</strong>
            <br>
            Para calcular el promedio de un alumno, es necesario seleccionar un bimestre específico. Los promedios se calculan por bimestre considerando los porcentajes de cada competencia.
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>';
        exit;
    }

    $student_name_avg = "Alumno Desconocido";
    $stmt_student_name = $conn->prepare("SELECT name FROM student WHERE id = ? AND school_id = ?");
    if ($stmt_student_name) {
        $stmt_student_name->bind_param("ii", $student_id_filter, $school_id);
        $stmt_student_name->execute();
        $result_student_name = $stmt_student_name->get_result();
        if ($row_student_name = $result_student_name->fetch_assoc()) {
            $student_name_avg = htmlspecialchars($row_student_name['name']);
        }
        $stmt_student_name->close();
    }

    $course_name_for_avg_display = "Todos los aplicables";
    if (!empty($course_id_filter)) {
        $sql_course = "SELECT ac.name FROM academic_courses ac WHERE ac.id = ? AND ac.school_id = ?";
        $params_course = [$course_id_filter, $school_id];
        $types_course = "ii";
        
        $stmt_course_name = $conn->prepare($sql_course);
        if ($stmt_course_name) {
            $stmt_course_name->bind_param($types_course, ...$params_course);
            $stmt_course_name->execute();
            $result_course_name = $stmt_course_name->get_result();
            if ($row_course_name = $result_course_name->fetch_assoc()) {
                $course_name_for_avg_display = htmlspecialchars($row_course_name['name']);
            }
            $stmt_course_name->close();
        }
    }

    $competencias_data = [];
    $final_average = 0;
    $has_competencias = false;
    $total_percentage = 0;
    
    if (!empty($course_id_filter)) {        
        $sql_get_competencias = "SELECT DISTINCT gcc.id, gcc.name, gcc.percentage  
                               FROM general_course_competencies gcc
                               INNER JOIN evaluation_competencias ec ON gcc.id = ec.competencia_id
                               INNER JOIN evaluations e ON ec.evaluation_id = e.id 
                               INNER JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                               INNER JOIN academic_courses ac ON tc.course_id = ac.id
                               WHERE tc.course_id = ?";
                               
        $params_get_comp = [$course_id_filter];
        $types_get_comp = "i";
        
        if (!empty($academic_year_id)) {
            $sql_get_competencias .= " AND tc.academic_year_id = ?";
            $params_get_comp[] = $academic_year_id;
            $types_get_comp .= "i";
        }
        
        if (!empty($bimestre_filter)) {
            $sql_get_competencias .= " AND e.bimestre = ?";
            $params_get_comp[] = $bimestre_filter;
            $types_get_comp .= "s";
        }
        
        if (!empty($grado_filter)) {
            $sql_get_competencias .= " AND tc.grado = ?";
            $params_get_comp[] = $grado_filter;
            $types_get_comp .= "s";
        }
        
        if (!empty($seccion_filter)) {
            $sql_get_competencias .= " AND tc.seccion = ?";
            $params_get_comp[] = $seccion_filter;
            $types_get_comp .= "s";
        }
        
        $stmt_get_comp = $conn->prepare($sql_get_competencias);
        $all_competencias = [];
        
        if ($stmt_get_comp) {
            $stmt_get_comp->bind_param($types_get_comp, ...$params_get_comp);
            $stmt_get_comp->execute();
            $result_get_comp = $stmt_get_comp->get_result();
            
            while ($row_get_comp = $result_get_comp->fetch_assoc()) {
                $all_competencias[$row_get_comp['id']] = [
                    'id' => $row_get_comp['id'],
                    'name' => $row_get_comp['name'],
                    'percentage' => $row_get_comp['percentage']
                ];
                $total_percentage += $row_get_comp['percentage'];
            }
            $stmt_get_comp->close();
        }

        $sql_competencias = "SELECT gcc.id, gcc.name, gcc.percentage, 
                           e.id as evaluation_id, e.title as evaluation_title, e.bimestre,
                           eg.grade
                    FROM general_course_competencies gcc
                    INNER JOIN evaluation_competencias ec ON gcc.id = ec.competencia_id
                    INNER JOIN evaluations e ON ec.evaluation_id = e.id
                    INNER JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                    INNER JOIN academic_courses ac ON tc.course_id = ac.id
                    INNER JOIN evaluation_grades eg ON e.id = eg.evaluation_id
                    INNER JOIN student s ON eg.student_id = s.id
                    WHERE tc.course_id = ? 
                    AND eg.student_id = ?
                    AND s.school_id = ?";                            
        
        $params_comp = [$course_id_filter, $student_id_filter, $school_id];
        $types_comp = "iii";
        
        if (!empty($academic_year_id)) {
            $sql_competencias .= " AND tc.academic_year_id = ?";
            $params_comp[] = $academic_year_id;
            $types_comp .= "i";
        }
        
        if ($normalized_level_key !== null) {
            $sql_competencias .= " AND LOWER(REPLACE(TRIM(s.nivel), ' ', '')) = ?";
            $params_comp[] = $normalized_level_key;
            $types_comp .= "s";
        }
        
        if (!empty($grado_filter)) {
            $sql_competencias .= " AND tc.grado = ?";
            $params_comp[] = $grado_filter;
            $types_comp .= "s";
        }
        
        if (!empty($seccion_filter)) {
            $sql_competencias .= " AND tc.seccion = ?";
            $params_comp[] = $seccion_filter;
            $types_comp .= "s";
        }
        
        if (!empty($bimestre_filter)) {
            $sql_competencias .= " AND e.bimestre = ?";
            $params_comp[] = $bimestre_filter;
            $types_comp .= "s";
        }
        
        $sql_competencias .= " ORDER BY gcc.id, e.title";
        
        $stmt_comp = $conn->prepare($sql_competencias);
        
        if ($stmt_comp) {
            $stmt_comp->bind_param($types_comp, ...$params_comp);
            $stmt_comp->execute();
            $result_comp = $stmt_comp->get_result();
            
            foreach ($all_competencias as $comp_id => $comp_info) {
                $competencias_data[$comp_id] = [
                    'name' => $comp_info['name'],
                    'percentage' => $comp_info['percentage'],
                    'evaluations' => [],
                    'avg_grade' => 0
                ];
            }

            while ($row_comp = $result_comp->fetch_assoc()) {
                $comp_id = $row_comp['id'];
                
                if (isset($competencias_data[$comp_id])) {
                    if (empty($bimestre_filter) || $row_comp['bimestre'] == $bimestre_filter) {
                        $competencias_data[$comp_id]['evaluations'][] = [
                            'id' => $row_comp['evaluation_id'],
                            'title' => $row_comp['evaluation_title'],
                            'grade' => $row_comp['grade'],
                            'bimestre' => $row_comp['bimestre']
                        ];
                    }
                }
            }
            
            $stmt_comp->close();
            $has_competencias = !empty($competencias_data);
        }

        $evaluation_title_for_avg_display = "Todas las aplicables";    
        if (!empty($evaluation_id_filter)) {
            $stmt_eval_name = $conn->prepare("SELECT title FROM evaluations WHERE id = ?");
            if ($stmt_eval_name) {
                $stmt_eval_name->bind_param("i", $evaluation_id_filter);
                $stmt_eval_name->execute();
                $result_eval_name = $stmt_eval_name->get_result();
                if ($row_eval_name = $result_eval_name->fetch_assoc()) {
                    $evaluation_title_for_avg_display = htmlspecialchars($row_eval_name['title']);
                }
                $stmt_eval_name->close();
            }
        }

        if ($has_competencias) {
            $weighted_sum = 0;            
            $total_applied_percentage = 0;
            $bimestres_data = [];         
            
            foreach ($competencias_data as $comp_id => &$comp_data) {
                if (!empty($comp_data['evaluations'])) {
                    foreach ($comp_data['evaluations'] as $eval) {
                        $bim = $eval['bimestre'];
                        if (!isset($bimestres_data[$bim])) {
                            $bimestres_data[$bim] = [];
                        }
                        if (!isset($bimestres_data[$bim][$comp_id])) {
                            $bimestres_data[$bim][$comp_id] = [
                                'name' => $comp_data['name'],
                                'percentage' => $comp_data['percentage'],
                                'evaluations' => [],
                                'avg_grade' => 0
                            ];
                        }
                        $bimestres_data[$bim][$comp_id]['evaluations'][] = $eval;
                    }
                }
            }

            if (isset($bimestres_data[$bimestre_filter])) {
                $bim_weighted_sum = 0;
                $bim_total_percentage = 0;
                
                foreach ($bimestres_data[$bimestre_filter] as $comp_id => &$comp_data) {
                    $total_grades = 0;
                    $valid_evaluations = count($comp_data['evaluations']);
                    
                    foreach ($comp_data['evaluations'] as $eval) {
                        $total_grades += letter_to_numeric_for_calc($eval['grade']);
                    }
                    
                    if ($valid_evaluations > 0) {
                        $comp_data['avg_grade'] = $total_grades / $valid_evaluations;
                        $bim_weighted_sum += ($comp_data['avg_grade'] * $comp_data['percentage'] / 100);
                        $bim_total_percentage += $comp_data['percentage'];
                        
                        if (isset($competencias_data[$comp_id])) {
                            $competencias_data[$comp_id]['avg_grade'] = $comp_data['avg_grade'];
                        }
                    }
                }

                if ($bim_total_percentage > 0) {
                    $final_average = $bim_weighted_sum;
                } else {
                    $final_average = 0;
                }
            } else {
                echo '<div class="alert alert-info"><i class="fa fa-info-circle mr-2"></i>No se encontraron evaluaciones para el bimestre seleccionado.</div>';
                $final_average = 0;
            }
        }
    }

    $sql_grades = "SELECT eg.grade
                FROM evaluation_grades eg
                INNER JOIN evaluations e ON eg.evaluation_id = e.id
                INNER JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                INNER JOIN academic_courses ac ON tc.course_id = ac.id AND ac.school_id = ?
                INNER JOIN student s ON eg.student_id = s.id AND s.school_id = ?
                WHERE eg.student_id = ?";
    
    $params_avg = [$school_id, $school_id, $student_id_filter];
    $types_avg = "iii";

    if (!empty($academic_year_id)) {
        $sql_grades .= " AND tc.academic_year_id = ?";
        $params_avg[] = $academic_year_id;
        $types_avg .= "i";
    }

    if (!empty($course_id_filter)) { 
        $sql_grades .= " AND tc.course_id = ?"; 
        $params_avg[] = $course_id_filter; 
        $types_avg .= "i"; 
    }
    if ($normalized_level_key !== null) { 
        $sql_grades .= " AND LOWER(REPLACE(TRIM(ac.level), ' ', '')) = ?"; 
        $params_avg[] = $normalized_level_key; 
        $types_avg .= "s"; 
        $sql_grades .= " AND LOWER(REPLACE(TRIM(s.nivel), ' ', '')) = ?"; 
        $params_avg[] = $normalized_level_key; 
        $types_avg .= "s"; 
    }
    if (!empty($grado_filter)) { 
        $sql_grades .= " AND tc.grado = ?"; 
        $params_avg[] = $grado_filter; 
        $types_avg .= "s"; 
        $sql_grades .= " AND s.grado = ?"; 
        $params_avg[] = $grado_filter; 
        $types_avg .= "s"; 
    }
    if (!empty($seccion_filter)) { 
        $sql_grades .= " AND tc.seccion = ?"; 
        $params_avg[] = $seccion_filter; 
        $types_avg .= "s"; 
        $sql_grades .= " AND s.seccion = ?"; 
        $params_avg[] = $seccion_filter; 
        $types_avg .= "s"; 
    }
    if (!empty($bimestre_filter)) { 
        $sql_grades .= " AND e.bimestre = ?"; 
        $params_avg[] = $bimestre_filter; 
        $types_avg .= "s"; 
    }
    if (!empty($evaluation_id_filter)) { 
        $sql_grades .= " AND eg.evaluation_id = ?"; 
        $params_avg[] = $evaluation_id_filter; 
        $types_avg .= "i"; 
    }

    $is_director = $_SESSION['login_is_director'] ?? 0;
    if ($login_type == 2 && $teacher_id && !$is_director) {
        $sql_grades .= " AND EXISTS (SELECT 1 FROM teacher_courses tc2 
                                  WHERE tc2.id = e.teacher_course_id 
                                  AND tc2.teacher_id = ?)";
        $params_avg[] = $teacher_id;
        $types_avg .= "i";
    }
    
    $promedio_final = null;
    $stmt_avg = $conn->prepare($sql_grades);
    if ($stmt_avg) {
        $stmt_avg->bind_param($types_avg, ...$params_avg);
        $stmt_avg->execute();
        $result_avg = $stmt_avg->get_result();
        
        $total_numeric_grades = 0;
        $count_grades = 0;
        
        while ($row_grade = $result_avg->fetch_assoc()) {
            $numeric_grade = letter_to_numeric_for_calc($row_grade['grade']);
            if ($numeric_grade > 0) {
                $total_numeric_grades += $numeric_grade;
                $count_grades++;
            }
        }
        
        if ($count_grades > 0) {
            $promedio_final = $total_numeric_grades / $count_grades;
        }
        $stmt_avg->close();
    } else {
        echo "<div class='alert alert-danger'><i class='fa fa-exclamation-triangle mr-2'></i>Error al preparar la consulta de promedio: " . htmlspecialchars($conn->error) . "</div>";
    }
    ?>

    <div style="padding: 25px; border-radius: 10px; margin-top:20px; background-color: #ffffff; box-shadow: 0 5px 20px rgba(0,0,0,0.1); animation: fadeIn 0.5s ease-out;">
        <h3 style="text-align:center; margin-bottom:25px; color:#4e73df; text-shadow: 0 1px 1px rgba(0,0,0,0.05);">
            Promedio Final para: <span style="font-weight: 600;"><?php echo $student_name_avg; ?></span>
            <?php if (!empty($bimestre_filter)): ?>
                <span style="display:inline-block; margin-left:12px; font-size:0.8em; padding:5px 12px; background: linear-gradient(135deg, #4e73df, #3257b3); color:white; border-radius:20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <?php echo $bimestre_filter; ?>° Bimestre
                </span>
            <?php endif; ?>
        </h3>
        
        <div style="margin-bottom:25px; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.07);">
            <table class="table" style="background-color: #fff; margin-bottom: 0;">
                <tr style="background-color: #f8f9fc;">
                    <td style="width:30%; padding: 12px 15px; border-left: 4px solid #4e73df;"><strong>Curso:</strong></td>
                    <td style="padding: 12px 15px;"><?php echo $course_name_for_avg_display; ?></td>
                </tr>
                <tr>
                    <td style="padding: 12px 15px; border-left: 4px solid #4e73df;"><strong>Nivel:</strong></td>
                    <td style="padding: 12px 15px;"><?php echo !empty($level_filter) ? htmlspecialchars($level_filter) : 'Todos'; ?></td>
                </tr>
                <tr style="background-color: #f8f9fc;">
                    <td style="padding: 12px 15px; border-left: 4px solid #4e73df;"><strong>Grado:</strong></td>
                    <td style="padding: 12px 15px;"><?php echo !empty($grado_filter) ? htmlspecialchars($grado_filter) : 'Todos'; ?></td>
                </tr>
                <tr>
                    <td style="padding: 12px 15px; border-left: 4px solid #4e73df;"><strong>Sección:</strong></td>
                    <td style="padding: 12px 15px;"><?php echo !empty($seccion_filter) ? htmlspecialchars($seccion_filter) : 'Todas'; ?></td>
                </tr>
                <tr style="background-color: #f8f9fc;">
                    <td style="padding: 12px 15px; border-left: 4px solid #4e73df;"><strong>Bimestre:</strong></td>
                    <td style="padding: 12px 15px;"><?php echo !empty($bimestre_filter) ? htmlspecialchars($bimestre_filter) . '° Bimestre' : 'Todos'; ?></td>
                </tr>
            </table>
        </div>
        <style>
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
        </style>
        
        <?php if ($has_competencias): ?>
            <div class="competencias-container" style="margin-top:30px;">
                <?php foreach ($competencias_data as $comp_id => $comp_data): ?>
                    <?php 
                    $nota = (float)$comp_data['avg_grade'];
                    $color_class = '';
                    $color_bar = '';
                    
                    if ($nota >= 16) {
                        $color_class = 'success';
                        $color_bar = '#28a745';
                    } elseif ($nota >= 11) {
                        $color_class = 'primary';  
                        $color_bar = '#4e73df';
                    } elseif ($nota >= 6) {
                        $color_class = 'warning';
                        $color_bar = '#ffc107';
                    } else {
                        $color_class = 'danger';
                        $color_bar = '#dc3545';
                    }
                    ?>
                    <div class="competencia-block" style="margin-bottom:25px; border-radius:8px; padding:15px; background-color:#fff; box-shadow: 0 3px 10px rgba(0,0,0,0.08); border-top: 3px solid <?php echo $color_bar; ?>;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom:15px;">
                            <h4 style="color:#333; margin:0; font-weight: 600;">
                                <?php echo htmlspecialchars($comp_data['name']); ?> 
                            </h4>
                            <div style="display: flex; align-items: center;">
                                <span style="font-size:0.9em; color:#555; margin-right: 10px; background-color: #f8f9fc; padding: 4px 10px; border-radius: 4px;"><?php echo $comp_data['percentage']; ?>% del total</span>
                                <span class="badge badge-<?php echo $color_class; ?>" style="font-size: 14px; padding: 5px 10px;"><?php echo number_format((float)$comp_data['avg_grade'], 2); ?></span>
                            </div>
                        </div>
                        
                        <div style="overflow: hidden; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                            <table class="table table-hover" style="margin-bottom: 0; font-size:0.9em;">                            
                                <thead style="background: linear-gradient(90deg, <?php echo $color_bar; ?>20, <?php echo $color_bar; ?>10); border-bottom: 2px solid <?php echo $color_bar; ?>;">                                
                                    <tr>
                                        <th style="width:60%; padding: 12px 15px;">Evaluación</th>
                                        <th style="width:40%; padding: 12px 15px;">Nota</th>
                                    </tr>
                                </thead>
                                <tbody>                                
                                    <?php foreach ($comp_data['evaluations'] as $eval): ?>
                                    <tr>
                                        <td style="padding: 10px 15px;"><?php echo htmlspecialchars($eval['title']); ?></td>
                                        <td style="padding: 10px 15px;">
                                            <span style="display: inline-block; min-width: 40px; text-align: center; 
                                                  padding: 3px 8px; border-radius: 4px; 
                                                  background-color: <?php echo $color_bar; ?>15; 
                                                  color: <?php echo $color_bar; ?>;">
                                                <?php echo format_grade($eval['grade']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr style="background-color: <?php echo $color_bar; ?>10;">
                                        <td style="padding: 12px 15px;"><strong>Promedio de Competencia</strong></td>
                                        <td style="padding: 12px 15px;">
                                            <strong style="display: inline-block; min-width: 40px; text-align: center; 
                                                  padding: 5px 10px; border-radius: 4px; 
                                                  background-color: <?php echo $color_bar; ?>; 
                                                  color: #fff;">
                                                <?php echo format_grade($comp_data['avg_grade']); ?>
                                            </strong>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- Desglose del Cálculo del Promedio -->
                <div class="calculation-breakdown" style="margin-top:30px; padding:20px; border-radius:8px; background-color:#f8f9fc; border-left: 4px solid #4e73df;">
                    <h5 style="color:#4e73df; margin-bottom:15px; font-weight: 600;">
                        <i class="fa fa-calculator mr-2"></i>Desglose del Cálculo del Promedio
                    </h5>
                    <p style="font-size:0.9em; color:#666; margin-bottom:15px; font-style: italic;">
                        El promedio final se calcula multiplicando el promedio de cada competencia por su porcentaje de peso:
                    </p>
                    
                    <div style="background-color: white; padding: 15px; border-radius: 6px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                        <?php 
                        $total_contribution = 0;
                        foreach ($competencias_data as $comp_id => $comp_data): 
                            if (!empty($comp_data['evaluations'])):
                                $contribution = ($comp_data['avg_grade'] * $comp_data['percentage'] / 100);
                                $total_contribution += $contribution;
                        ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #e3e6f0;">
                            <div style="flex: 1;">
                                <span style="font-weight: 500; color:#333;"><?php echo htmlspecialchars($comp_data['name']); ?></span>
                            </div>
                            <div style="flex: 0 0 auto; text-align: right; font-family: 'Courier New', monospace; font-size: 0.9em;">
                                <span style="color:#5a5c69;"><?php echo number_format($comp_data['avg_grade'], 2); ?></span>
                                <span style="color:#858796; margin: 0 5px;">×</span>
                                <span style="color:#5a5c69;"><?php echo $comp_data['percentage']; ?>%</span>
                                <span style="color:#858796; margin: 0 5px;">=</span>
                                <span style="color:#4e73df; font-weight: 600;"><?php echo number_format($contribution, 2); ?></span>
                            </div>
                        </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0 5px 0; margin-top: 10px; border-top: 2px solid #4e73df;">
                            <div style="flex: 1;">
                                <span style="font-weight: 600; color:#4e73df; font-size: 1.05em;">PROMEDIO FINAL:</span>
                            </div>
                            <div style="flex: 0 0 auto; text-align: right;">
                                <span style="font-size: 1.3em; font-weight: 700; color:#4e73df;"><?php echo number_format($final_average, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($total_percentage < 100): ?>
                    <div style="margin-top: 10px; padding: 8px 12px; background-color: #fff3cd; border-left: 3px solid #ffc107; border-radius: 4px;">
                        <small style="color: #856404;">
                            <i class="fa fa-info-circle mr-1"></i>
                            Nota: La suma de porcentajes es <?php echo $total_percentage; ?>% (las competencias configuradas no suman 100%)
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php 
                $final_note = (float)$final_average;
                $final_color = '';
                $final_bg = '';
                $final_text = '';
                $final_icon = '';
                
                if ($final_note >= 18) {
                    $final_color = '#1e7e34';
                    $final_bg = '#d4edda';
                    $final_text = 'Excelente';
                    $final_icon = 'trophy';
                } elseif ($final_note >= 14) {
                    $final_color = '#117a8b';
                    $final_bg = '#d1ecf1';
                    $final_text = 'Muy Bueno';
                    $final_icon = 'thumbs-up';
                } elseif ($final_note >= 11) {
                    $final_color = '#856404';
                    $final_bg = '#fff3cd';
                    $final_text = 'Aprobado';
                    $final_icon = 'check-circle';
                } else {
                    $final_color = '#721c24';
                    $final_bg = '#f8d7da';
                    $final_text = 'Necesita Mejorar';
                    $final_icon = 'exclamation-circle';
                }
                ?>
                <div class="final-average" style="margin-top:35px; background: linear-gradient(135deg, <?php echo $final_bg; ?>, white); padding:20px; border-radius:10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border: 1px solid <?php echo $final_color; ?>40;">
                    <div style="display: flex; justify-content: center; align-items: center; margin-bottom: 15px;">
                        <div style="background-color: <?php echo $final_color; ?>; width: 50px; height: 50px; border-radius: 50%; display: flex; justify-content: center; align-items: center; margin-right: 15px; box-shadow: 0 4px 10px <?php echo $final_color; ?>50;">
                            <i class="fa fa-<?php echo $final_icon; ?>" style="color: white; font-size: 24px;"></i>
                        </div>
                        <h3 style="text-align:center; color:<?php echo $final_color; ?>; margin: 0; font-weight: 600;">
                            Promedio <?php echo $bimestre_filter; ?>° Bimestre: 
                            <strong style="font-size: 1.1em;"><?php echo number_format((float)$final_average, 2); ?></strong>
                            <span style="font-size: 0.8em; display: block; margin-top: 5px; text-transform: uppercase;"><?php echo $final_text; ?></span>
                        </h3>
                    </div>
                    
                    <div style="width: 100%; height: 8px; background-color: #eee; border-radius: 4px; margin: 15px 0; overflow: hidden;">
                        <div style="width: <?php echo min($final_note * 5, 100); ?>%; height: 100%; background: linear-gradient(90deg, <?php echo $final_color; ?>80, <?php echo $final_color; ?>);"></div>
                    </div>
                    
                    <p style="text-align:center; font-size:0.9em; color:#555; margin-top:10px; font-style: italic;">
                        Calculado según el porcentaje de cada competencia del bimestre
                    </p>
                </div>
            </div>
        
        <?php elseif ($promedio_final !== null): ?>
            <table class="table table-bordered table-hover" style="margin-top: 15px; font-size: 0.95em;">
                <thead style="background-color: #f0f0f0;">
                    <tr>
                        <th style="width:40%;">Concepto</th>
                        <th>Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Promedio General (según filtros)</td>
                        <td><strong><?php echo number_format((float)$promedio_final, 2); ?></strong></td>
                    </tr>
                    <tr><td colspan="2" class="text-center" style="color:#666;">No se encontraron competencias configuradas. Se muestra el promedio simple de todas las evaluaciones.</td></tr>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-center" style="margin-top:15px; color: #d9534f;">No se pudo calcular el promedio con los filtros seleccionados o no hay notas registradas para este alumno bajo esos criterios.</p>
        <?php endif; ?>
    </div>

    <?php
} else {
    // --- MAIN REPORT LOGIC ---
    $sql_main = "SELECT s.name as student_name, s.id_no as student_dni, 
                        ac.name as course_name, ac.level as academic_level,
                        tc.grado as evaluation_grado, tc.seccion as evaluation_seccion,
                        e.title as evaluation_title, 
                        e.bimestre, eg.grade as nota
                 FROM evaluation_grades eg
                 INNER JOIN student s ON eg.student_id = s.id AND s.school_id = ? AND (s.status = 'Activo' OR s.status IS NULL)
                 INNER JOIN evaluations e ON eg.evaluation_id = e.id
                 INNER JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                 INNER JOIN academic_courses ac ON tc.course_id = ac.id AND ac.school_id = ? ";
    $where_clauses = [];
    $params = [$school_id, $school_id];
    $types = "ii";

    if (!empty($academic_year_id)) {
        $where_clauses[] = "tc.academic_year_id = ?";
        $params[] = $academic_year_id;
        $types .= "i";
    }

    $is_director = $_SESSION['login_is_director'] ?? 0;
    if ($login_type == 2 && $teacher_id && !$is_director) {
        $where_clauses[] = "tc.teacher_id = ?";
        $params[] = $teacher_id;
        $types .= "i";
    }    

    if (!empty($course_id_filter)) { 
        $where_clauses[] = "tc.course_id = ?"; 
        $params[] = $course_id_filter; 
        $types .= "i"; 
    }
    
    if ($normalized_level_key !== null) { 
        $where_clauses[] = "LOWER(REPLACE(TRIM(ac.level), ' ', '')) = ?"; 
        $params[] = $normalized_level_key; 
        $types .= "s"; 
        
        $where_clauses[] = "LOWER(REPLACE(TRIM(s.nivel), ' ', '')) = ?"; 
        $params[] = $normalized_level_key; 
        $types .= "s";
    }
    
    if (!empty($grado_filter)) { 
        $where_clauses[] = "(tc.grado = ? OR s.grado = ?)"; 
        $params[] = $grado_filter;
        $params[] = $grado_filter;
        $types .= "ss";
    }
    
    if (!empty($seccion_filter)) { 
        $where_clauses[] = "(tc.seccion = ? OR s.seccion = ?)"; 
        $params[] = $seccion_filter;
        $params[] = $seccion_filter;
        $types .= "ss";
    }
    if (!empty($student_id_filter)) { 
        $where_clauses[] = "eg.student_id = ?"; 
        $params[] = $student_id_filter; 
        $types .= "i"; 
    }
    if (!empty($evaluation_id_filter)) { 
        $where_clauses[] = "eg.evaluation_id = ?"; 
        $params[] = $evaluation_id_filter; 
        $types .= "i"; 
    }
    if (!empty($bimestre_filter)) { 
        $where_clauses[] = "e.bimestre = ?"; 
        $params[] = $bimestre_filter; 
        $types .= "s"; 
    }

    if (count($where_clauses) > 0) {
        $sql_main .= " WHERE " . implode(" AND ", $where_clauses);
    }
    $sql_main .= " ORDER BY s.name, ac.name, e.title ";

    $report_data = [];
    $stmt_main = $conn->prepare($sql_main);

    if ($stmt_main) {
        if (!empty($params)) {
             $stmt_main->bind_param($types, ...$params);
        }
        $stmt_main->execute();
        $result_main = $stmt_main->get_result();
        while ($row = $result_main->fetch_assoc()) {
            $report_data[] = $row;
        }
        $stmt_main->close();
    } else {
        echo "<div class='alert alert-danger'><i class='fa fa-exclamation-triangle mr-2'></i>Error al preparar la consulta: " . htmlspecialchars($conn->error) . "</div>";
    }
    ?>

    <?php 
    $has_data = (count($report_data) > 0) ? 'true' : 'false';
    ?>
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover table-sm" id="gradesReportData" 
               style="width:100%;" data-has-records="<?php echo $has_data; ?>">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>Alumno</th>
                    <th>DNI</th>
                    <th>Curso</th>
                    <th>Nivel</th>
                    <th>Grado</th>
                    <th>Sección</th>
                    <th>Evaluación</th>
                    <th>Bimestre</th>
                    <th>Nota</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($report_data) > 0): ?>
                    <?php foreach ($report_data as $index => $row): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['student_dni']); ?></td>
                        <td><?php echo htmlspecialchars($row['course_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['academic_level']); ?></td>
                        <td><?php echo htmlspecialchars($row['evaluation_grado']); ?></td>
                        <td><?php echo htmlspecialchars($row['evaluation_seccion']); ?></td>
                        <td><?php echo htmlspecialchars($row['evaluation_title']); ?></td>
                        <td><?php echo !empty($row['bimestre']) ? htmlspecialchars($row['bimestre']) . '° Bimestre' : 'N/A'; ?></td>
                        <td><?php echo format_grade($row['nota']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="no-data-row">
                        <td colspan="10" class="text-center py-4">
                            <i class="fa fa-info-circle mr-2"></i> No se encontraron registros con los filtros seleccionados.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        $(document).ready(function() {
            var hasData = $('#gradesReportData').attr('data-has-records') === 'true';
            
            if ($.fn.dataTable.isDataTable('#gradesReportData')) {
                $('#gradesReportData').DataTable().destroy();
            }
            
            $('#gradesReportData').removeClass('dataTable no-footer display')
                                 .find('thead th').removeClass('sorting_asc sorting_desc sorting');
            
            if (hasData) {
                try {
                    window.gradesTableInit = (window.gradesTableInit || 0) + 1;
                    
                    setTimeout(function() {
                        if (!$.fn.dataTable.isDataTable('#gradesReportData')) {                            
                            $('#gradesReportData').DataTable({
                                retrieve: true,
                                responsive: true,
                                language: {
                                    url: '//cdn.datatables.net/plug-ins/1.10.25/i18n/Spanish.json'
                                },
                                pageLength: 10,
                                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Todos"]]
                            });
                        }
                    }, 100);
                } catch (e) {
                    console.error("Error inicializando DataTable:", e);
                }
            }
        });
    </script>
    <?php
}
$conn->close();
?>
