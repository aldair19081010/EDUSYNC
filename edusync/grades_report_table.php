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

    if ($login_type == 2 && $teacher_id) {
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

    <style>
    .individual-report{color:#344767;text-align:left}
    .individual-report .ir-heading{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:1px solid #e3e6f0;padding-bottom:16px;margin-bottom:16px}
    .individual-report h3{font-size:1.15rem;font-weight:700;margin:4px 0}
    .individual-report .ir-meta{color:#7b8499;font-size:.85rem}
    .individual-report .ir-result{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:8px;padding:10px 18px}
    .individual-report .ir-result strong{display:block;font-size:1.6rem;color:#4e73df}
    .individual-report .ir-card{border:1px solid #e3e6f0;border-radius:8px;margin-bottom:16px;overflow:hidden}
    .individual-report .ir-card-header{background:#f8f9fc;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
    .individual-report h4{font-size:.95rem;font-weight:700;margin:0}
    .individual-report .table{margin:0;color:#344767}
    .individual-report th,.individual-report td{padding:.65rem .85rem;vertical-align:middle}
    .individual-report thead{background:#f8f9fc}
    .individual-report .ir-number{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
    </style>
    <section class="individual-report">
      <div class="ir-heading">
        <div><div class="ir-meta">Ficha individual · <?php echo htmlspecialchars((string)$bimestre_filter); ?>° bimestre</div>
          <h3><?php echo $student_name_avg; ?></h3>
          <div><?php echo $course_name_for_avg_display; ?></div>
          <div class="ir-meta"><?php echo htmlspecialchars(trim(($level_filter ?? '').' · '.($grado_filter ?? '').' '.($seccion_filter ?? ''))); ?></div>
        </div>
        <div class="ir-result"><small><?php echo $has_competencias ? 'Promedio ponderado' : 'Promedio'; ?></small><strong><?php echo $has_competencias ? number_format($final_average,2) : ($promedio_final !== null ? number_format($promedio_final,2) : '—'); ?></strong></div>
      </div>
      <?php if ($has_competencias): ?>
        <?php foreach ($competencias_data as $comp_data): ?>
          <div class="ir-card">
            <div class="ir-card-header"><h4><?php echo htmlspecialchars($comp_data['name']); ?></h4><span class="badge badge-light border"><?php echo number_format((float)$comp_data['percentage'],2); ?>% del promedio</span></div>
            <div class="table-responsive"><table class="table table-sm table-hover">
              <thead><tr><th>Evaluación</th><th class="ir-number">Nota</th></tr></thead><tbody>
                <?php foreach ($comp_data['evaluations'] as $eval): ?><tr><td><?php echo htmlspecialchars($eval['title']); ?></td><td class="ir-number"><?php echo format_grade($eval['grade']); ?></td></tr><?php endforeach; ?>
                <?php if (empty($comp_data['evaluations'])): ?><tr><td colspan="2" class="text-muted">Sin notas registradas en este bimestre.</td></tr><?php else: ?>
                <tr class="bg-light"><td><strong>Promedio de la competencia</strong></td><td class="ir-number"><strong><?php echo number_format($comp_data['avg_grade'],2); ?></strong></td></tr><?php endif; ?>
              </tbody>
            </table></div>
          </div>
        <?php endforeach; ?>
        <div class="ir-card">
          <div class="ir-card-header"><h4><i class="fas fa-calculator text-primary mr-2"></i>Cálculo del promedio</h4></div>
          <div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>Competencia</th><th class="ir-number">Promedio</th><th class="ir-number">Peso</th><th class="ir-number">Aporte</th></tr></thead><tbody>
            <?php foreach ($competencias_data as $comp_data): if (empty($comp_data['evaluations'])) continue; ?>
              <tr><td><?php echo htmlspecialchars($comp_data['name']); ?></td><td class="ir-number"><?php echo number_format($comp_data['avg_grade'],2); ?></td><td class="ir-number"><?php echo number_format((float)$comp_data['percentage'],2); ?>%</td><td class="ir-number"><?php echo number_format($comp_data['avg_grade']*$comp_data['percentage']/100,2); ?></td></tr>
            <?php endforeach; ?>
            </tbody><tfoot><tr class="bg-light"><th colspan="3">Promedio final del bimestre</th><th class="ir-number text-primary"><?php echo number_format($final_average,2); ?></th></tr></tfoot>
          </table></div>
        </div>
        <p class="small text-muted">Cada aporte corresponde al promedio de la competencia multiplicado por su porcentaje. Las competencias con 0% no aportan al promedio.</p>
        <?php if ($total_percentage < 100): ?><div class="alert alert-warning small">Los porcentajes considerados suman <?php echo htmlspecialchars((string)$total_percentage); ?>%. Revise la configuración de competencias.</div><?php endif; ?>
      <?php else: ?>
        <div class="alert alert-light border">No se encontraron competencias con notas para los filtros seleccionados.<?php if ($promedio_final !== null): ?> Se muestra el promedio simple disponible, como en el reporte anterior.<?php endif; ?></div>
      <?php endif; ?>
    </section>

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

    if ($login_type == 2 && $teacher_id) {
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
