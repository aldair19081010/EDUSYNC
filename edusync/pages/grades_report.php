<?php
include 'db_connect.php';

// Configurar sesión igual que en index.php
if (session_status() == PHP_SESSION_NONE) {
    $session_save_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp';
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
$is_admin = ($login_type == 1);
$is_teacher = ($login_type == 2);
$is_director = $_SESSION['login_is_director'] ?? 0;

if ($is_director == 1) {
    $is_admin = true;
    $is_teacher = false;
}
$teacher_id = $_SESSION['login_teacher_id'] ?? null;

if (!$school_id || !$login_type) {
    die("Acceso no autorizado o configuración de sesión incompleta. Por favor, inicie sesión nuevamente.");
}

function normalize_level_for_key($level_name) {
    if (empty($level_name)) return '';
    return strtolower(str_replace(' ', '', trim($level_name)));
}

// --- Cargar opciones de filtros ---
$courses = [];
$levels = [];
$grades = [];
$sections = [];
$students = [];
$academic_years = [];

// Cargar años académicos disponibles
$qay = $conn->prepare("SELECT id, year, description, is_active FROM academic_year WHERE school_id = ? ORDER BY is_active DESC, year DESC");
$qay->bind_param("i", $school_id);
$qay->execute();
$ray = $qay->get_result();
while($row = $ray->fetch_assoc()) {
    $academic_years[] = $row;
}
$qay->close();

// --- Obtener valores seleccionados ANTES de cargar los datos ---
$selected_academic_year = $_POST['academic_year_id'] ?? '';
$selected_course = $_POST['course_id'] ?? '';
$selected_level = $_POST['level'] ?? '';
$selected_grado = $_POST['grado'] ?? '';
$selected_seccion = $_POST['seccion'] ?? '';
$selected_bimestre = $_POST['bimestre'] ?? '';
$selected_evaluation = $_POST['evaluation_id'] ?? '';
$selected_student = $_POST['student_id'] ?? '';

// Si no se selecciona año académico, usar el activo por defecto
if (empty($selected_academic_year)) {
    foreach ($academic_years as $ay) {
        if ($ay['is_active'] == 1) {
            $selected_academic_year = $ay['id'];
            break;
        }
    }
    if (empty($selected_academic_year) && !empty($academic_years)) {
        $selected_academic_year = $academic_years[0]['id'];
    }
}

if ($is_admin) {
    $q = $conn->prepare("SELECT DISTINCT ac.id, ac.name, ac.level FROM academic_courses ac 
                         INNER JOIN teacher_courses tc ON ac.id = tc.course_id
                         WHERE ac.school_id = ? AND tc.academic_year_id = ? ORDER BY ac.name");
    $q->bind_param("ii", $school_id, $selected_academic_year);
    $q->execute();
    $res = $q->get_result();
    $levels_set = [];
    while ($row = $res->fetch_assoc()) {
        $courses[$row['id']] = ['name' => $row['name'], 'level' => $row['level']];
        if (!in_array($row['level'], $levels_set) && $row['level']) $levels_set[] = $row['level'];
    }
    $levels = $levels_set;
    $q->close();
    
    $grades = [];
    $sections = [];
    $qgs = $conn->prepare("SELECT DISTINCT tc.grado, tc.seccion 
                          FROM teacher_courses tc 
                          INNER JOIN academic_courses ac ON ac.id = tc.course_id 
                          WHERE tc.school_id = ? AND tc.academic_year_id = ? AND tc.grado != '' AND tc.seccion != ''
                          ORDER BY tc.grado, tc.seccion");
    $qgs->bind_param("ii", $school_id, $selected_academic_year);
    $qgs->execute();
    $rgs = $qgs->get_result();
    $grades_set = [];
    $sections_set = [];
    while($row = $rgs->fetch_assoc()) {
        if (!in_array($row['grado'], $grades_set)) $grades_set[] = $row['grado'];
        if (!in_array($row['seccion'], $sections_set)) $sections_set[] = $row['seccion'];
    }
    $grades = $grades_set;
    $sections = $sections_set;
    $qgs->close();
} elseif ($is_teacher && $teacher_id) {
    $q = $conn->prepare("SELECT ac.id as course_id, ac.name, ac.level, tc.grado, tc.seccion 
                         FROM teacher_courses tc 
                         INNER JOIN academic_courses ac ON ac.id = tc.course_id 
                         WHERE tc.teacher_id = ? AND tc.school_id = ? AND tc.academic_year_id = ? 
                         ORDER BY ac.name, tc.grado, tc.seccion");
    $q->bind_param("iii", $teacher_id, $school_id, $selected_academic_year);
    $q->execute();
    $res = $q->get_result();
    $levels_set = [];
    $grades_set = [];
    $sections_set = [];
    while ($row = $res->fetch_assoc()) {
        $courses[$row['course_id']] = ['name' => $row['name'], 'level' => $row['level']];
        if (!in_array($row['level'], $levels_set) && $row['level']) $levels_set[] = $row['level'];
        if (!in_array($row['grado'], $grades_set) && $row['grado']) $grades_set[] = $row['grado'];
        if (!in_array($row['seccion'], $sections_set) && $row['seccion']) $sections_set[] = $row['seccion'];
    }
    $levels = $levels_set;
    $grades = $grades_set;
    $sections = $sections_set;
    $q->close();
}

// --- Cargar alumnos si hay filtros ---
if ($selected_level && $selected_grado && $selected_seccion && $selected_academic_year) {
    $students = [];
    $level_key = normalize_level_for_key($selected_level);
    
    $sql1 = "
        SELECT DISTINCT s.id, s.name, s.id_no 
        FROM student s
        WHERE LOWER(REPLACE(TRIM(s.nivel), ' ', '')) = ? 
        AND s.school_id = ? 
        AND (s.status = 'Activo' OR s.status IS NULL)
        AND s.grado = ?
        AND s.seccion = ?
        ORDER BY s.name ASC
    ";
    $stmt1 = $conn->prepare($sql1);
    $stmt1->bind_param("siss", $level_key, $school_id, $selected_grado, $selected_seccion);
    $stmt1->execute();
    $res1 = $stmt1->get_result();
    while($row = $res1->fetch_assoc()) {
        $students[$row['id']] = $row;
    }
    $stmt1->close();
    
    $sql2 = "
        SELECT DISTINCT s.id, s.name, s.id_no 
        FROM student s
        INNER JOIN evaluation_grades eg ON s.id = eg.student_id
        INNER JOIN evaluations e ON eg.evaluation_id = e.id
        INNER JOIN teacher_courses tc ON e.teacher_course_id = tc.id
        WHERE LOWER(REPLACE(TRIM(s.nivel), ' ', '')) = ? 
        AND s.school_id = ? 
        AND (s.status = 'Activo' OR s.status IS NULL)
        AND tc.academic_year_id = ?
        AND tc.grado = ?
        AND tc.seccion = ?
        ORDER BY s.name ASC
    ";
    $stmt2 = $conn->prepare($sql2);
    $academic_year_id = (int) $selected_academic_year;
    $stmt2->bind_param("sisss", $level_key, $school_id, $academic_year_id, $selected_grado, $selected_seccion);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while($row = $res2->fetch_assoc()) {
        $students[$row['id']] = $row;
    }
    $stmt2->close();
    
    usort($students, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    $students = array_values($students);
}
?>

<div class="container-fluid py-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="fa fa-chart-bar mr-2"></i> Reporte de Notas</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-filter mr-2"></i> Filtros de búsqueda</h6>
        </div>
        <div class="card-body">
            <form id="filter-form">
                <div class="row mb-3">
                    <div class="col-md-2 mb-2">
                        <label class="small font-weight-bold mb-1">Año Académico</label>
                        <select name="academic_year_id" id="academic_year_id" class="form-control form-control-sm select2">
                            <?php if(empty($selected_academic_year)): ?>
                                <option value="">Seleccionar Año</option>
                            <?php endif; ?>
                            <?php foreach($academic_years as $ay): ?>
                                <option value="<?php echo $ay['id']; ?>" <?php echo ($selected_academic_year == $ay['id']) ? 'selected' : ''; ?>>
                                    <?php echo $ay['year']; ?>
                                    <?php if($ay['is_active'] == 1): ?><span class="text-success"> (Activo)</span><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="small font-weight-bold mb-1">Nivel</label>
                        <select name="level" id="level" class="form-control form-control-sm select2">
                            <option value="">Seleccionar</option>
                            <?php foreach($levels as $l): ?>
                                <option value="<?php echo $l; ?>" <?php echo ($selected_level == $l) ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="small font-weight-bold mb-1">Grado</label>
                        <select name="grado" id="grado" class="form-control form-control-sm select2" <?php echo empty($selected_level) ? 'disabled' : ''; ?>>
                            <option value="">Seleccionar</option>
                            <?php if(!empty($selected_level)): ?>
                                <?php foreach($grades as $g): ?>
                                    <option value="<?php echo $g; ?>" <?php echo ($selected_grado == $g) ? 'selected' : ''; ?>><?php echo $g; ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="small font-weight-bold mb-1">Sección</label>
                        <select name="seccion" id="seccion" class="form-control form-control-sm select2" <?php echo empty($selected_grado) ? 'disabled' : ''; ?>>
                            <option value="">Seleccionar</option>
                            <?php if(!empty($selected_grado)): ?>
                                <?php foreach($sections as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php echo ($selected_seccion == $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="small font-weight-bold mb-1">Curso</label>
                        <select name="course_id" id="course_id" class="form-control form-control-sm select2" <?php echo empty($selected_seccion) ? 'disabled' : ''; ?>>
                            <option value="">Todos</option>
                            <?php if(!empty($selected_level) && !empty($selected_grado) && !empty($selected_seccion)): ?>
                                <?php foreach($courses as $cid => $c): ?>
                                    <?php if($c['level'] == $selected_level): ?>
                                        <option value="<?php echo $cid; ?>" <?php echo ($selected_course == $cid) ? 'selected' : ''; ?>><?php echo $c['name']; ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="small font-weight-bold mb-1">Bimestre</label>
                        <select name="bimestre" id="bimestre" class="form-control form-control-sm select2">
                            <option value="">Seleccionar</option>
                            <option value="1" <?php echo ($selected_bimestre == '1') ? 'selected' : ''; ?>>1° Bimestre</option>
                            <option value="2" <?php echo ($selected_bimestre == '2') ? 'selected' : ''; ?>>2° Bimestre</option>
                            <option value="3" <?php echo ($selected_bimestre == '3') ? 'selected' : ''; ?>>3° Bimestre</option>
                            <option value="4" <?php echo ($selected_bimestre == '4') ? 'selected' : ''; ?>>4° Bimestre</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-2 mb-2">
                        <label class="small font-weight-bold mb-1">Evaluación</label>
                        <select name="evaluation_id" id="evaluation_id" class="form-control form-control-sm select2">
                            <option value="">Todas</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="small font-weight-bold mb-1">Alumno</label>
                        <select name="student_id" id="student_id" class="form-control form-control-sm select2">
                            <option value="">Todos</option>
                            <?php foreach($students as $stu): ?>
                                <option value="<?php echo $stu['id']; ?>" <?php echo ($selected_student == $stu['id']) ? 'selected' : ''; ?>><?php echo ucwords($stu['name']) . " ({$stu['id_no']})"; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="no-students-msg"></div>
                    </div>
                    <div class="col-md-2 mb-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm btn-block">
                            <i class="fa fa-search"></i> Buscar
                        </button>
                    </div>
                    <div class="col-md-2 mb-2 d-flex align-items-end">
                        <div class="dropdown w-100">
                            <button type="button" class="btn btn-info btn-sm btn-block dropdown-toggle" id="export-dropdown" data-toggle="dropdown">
                                <i class="fa fa-download"></i> Descargar
                            </button>
                            <div class="dropdown-menu" aria-labelledby="export-dropdown">
                                <a class="dropdown-item" href="#" id="print-report">
                                    <i class="fa fa-print text-secondary"></i> Imprimir PDF
                                </a>
                                <a class="dropdown-item" href="#" id="export-excel-report">
                                    <i class="fa fa-file-excel text-success"></i> Exportar Excel
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-2 d-flex align-items-end">
                        <button type="button" class="btn btn-success btn-sm btn-block" id="show-avg-report">
                            <i class="fa fa-chart-bar"></i> Ver Promedio
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-table mr-2"></i> Resultados</h6>
        </div>
        <div class="card-body">
            <div id="grades-report-table"></div>
        </div>
    </div>
</div>

<!-- Modal de formato de exportación -->
<div class="modal fade" id="export-format-modal" tabindex="-1" role="dialog" aria-labelledby="exportFormatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exportFormatModalLabel">
                    <i class="fa fa-file-excel mr-2"></i> Formato de Exportación Excel
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Seleccione el formato en el que desea exportar las calificaciones:</p>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="format-option card" data-format="numeric" style="cursor: pointer; border: 2px solid #e3e6f0;">
                            <div class="card-body text-center">
                                <div style="font-size: 2rem; color: #4e73df; margin-bottom: 10px;">
                                    <i class="fa fa-calculator"></i>
                                </div>
                                <h6>Formato Numérico</h6>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="format-option card" data-format="letters" style="cursor: pointer; border: 2px solid #e3e6f0;">
                            <div class="card-body text-center">
                                <div style="font-size: 2rem; color: #28a745; margin-bottom: 10px;">
                                    A
                                </div>
                                <h6>Formato por Letras</h6>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">
                    <i class="fa fa-times mr-2"></i> Cancelar
                </button>
                <button type="button" id="confirm-export" class="btn btn-success btn-sm" disabled>
                    <i class="fa fa-download mr-2"></i> Exportar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2').select2({ width: '100%' });
    }
});

function updateGrados() {
    var nivel = $('#level').val();
    var academic_year_id = $('#academic_year_id').val();
    
    if (!nivel) {
        $('#grado').html('<option value="">Seleccionar</option>').prop('disabled', true);
        $('#grado').select2('destroy').select2({ width: '100%' });
        $('#seccion').html('<option value="">Seleccionar</option>').prop('disabled', true);
        $('#seccion').select2('destroy').select2({ width: '100%' });
        $('#course_id').html('<option value="">Todos</option>').prop('disabled', true);
        $('#course_id').select2('destroy').select2({ width: '100%' });
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=get_grados_by_nivel',
        method: 'POST',
        data: { level: nivel, academic_year_id: academic_year_id },
        dataType: 'json',
        success: function(resp) {
            var html = '<option value="">Seleccionar</option>';
            if (resp && Array.isArray(resp.grados) && resp.grados.length > 0) {
                resp.grados.forEach(function(grado) {
                    html += '<option value="'+grado+'">'+grado+'</option>';
                });
                $('#grado').html(html).prop('disabled', false);
            } else {
                $('#grado').html(html).prop('disabled', true);
            }
            $('#grado').select2('destroy').select2({ width: '100%' });
            $('#seccion').html('<option value="">Seleccionar</option>').prop('disabled', true);
            $('#seccion').select2('destroy').select2({ width: '100%' });
            $('#course_id').html('<option value="">Todos</option>').prop('disabled', true);
            $('#course_id').select2('destroy').select2({ width: '100%' });
        }
    });
}

function updateSecciones() {
    var nivel = $('#level').val();
    var grado = $('#grado').val();
    var academic_year_id = $('#academic_year_id').val();
    
    if (!nivel || !grado) {
        $('#seccion').html('<option value="">Seleccionar</option>').prop('disabled', true);
        $('#seccion').select2('destroy').select2({ width: '100%' });
        $('#course_id').html('<option value="">Todos</option>').prop('disabled', true);
        $('#course_id').select2('destroy').select2({ width: '100%' });
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=get_secciones_by_grado_nivel',
        method: 'POST',
        data: { level: nivel, grado: grado, academic_year_id: academic_year_id },
        dataType: 'json',
        success: function(resp) {
            var html = '<option value="">Seleccionar</option>';
            if (resp && Array.isArray(resp.secciones) && resp.secciones.length > 0) {
                resp.secciones.forEach(function(seccion) {
                    html += '<option value="'+seccion+'">'+seccion+'</option>';
                });
                $('#seccion').html(html).prop('disabled', false);
            } else {
                $('#seccion').html(html).prop('disabled', true);
            }
            $('#seccion').select2('destroy').select2({ width: '100%' });
            $('#course_id').html('<option value="">Todos</option>').prop('disabled', true);
            $('#course_id').select2('destroy').select2({ width: '100%' });
        }
    });
}

function updateCursos() {
    var nivel = $('#level').val();
    var grado = $('#grado').val();
    var seccion = $('#seccion').val();
    var academic_year_id = $('#academic_year_id').val();
    
    if (!nivel || !grado || !seccion) {
        $('#course_id').html('<option value="">Todos</option>').prop('disabled', true);
        $('#course_id').select2('destroy').select2({ width: '100%' });
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=get_courses_by_aula',
        method: 'POST',
        data: { level: nivel, grado: grado, seccion: seccion, academic_year_id: academic_year_id },
        dataType: 'json',
        success: function(resp) {
            var html = '<option value="">Todos</option>';
            if (resp && Array.isArray(resp.courses) && resp.courses.length > 0) {
                resp.courses.forEach(function(course) {
                    html += '<option value="'+course.id+'">'+course.name+'</option>';
                });
                $('#course_id').html(html).prop('disabled', false);
            } else {
                $('#course_id').html(html).prop('disabled', false);
            }
            $('#course_id').select2('destroy').select2({ width: '100%' });
            updateStudents();
            updateEvaluations();
        }
    });
}

function updateStudents() {
    var grado = $('#grado').val();
    var seccion = $('#seccion').val();
    var nivel = $('#level').val();
    var academic_year_id = $('#academic_year_id').val();
    
    if (!grado || !seccion || !nivel) {
        $('#student_id').html('<option value="">Todos</option>');
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=get_students_by_grado_seccion',
        method: 'POST',
        data: { grado: grado, seccion: seccion, level: nivel, academic_year_id: academic_year_id },
        dataType: 'json',
        success: function(resp) {
            var html = '<option value="">Todos</option>';
            if (resp && Array.isArray(resp.students) && resp.students.length > 0) {
                resp.students.forEach(function(stu) {
                    html += '<option value="'+stu.id+'">'+stu.name+' ('+stu.id_no+')</option>';
                });
                $('#student_id').html(html).trigger('change');
            } else {
                $('#student_id').html(html).trigger('change');
            }
        }
    });
}

function updateEvaluations() {
    var course_id = $('#course_id').val();
    var grado = $('#grado').val();
    var seccion = $('#seccion').val();
    var bimestre = $('#bimestre').val();
    var nivel = $('#level').val();
    var academic_year_id = $('#academic_year_id').val();
    
    if (!nivel || !grado || !seccion) {
        $('#evaluation_id').html('<option value="">Todas</option>');
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=get_evaluations_by_filters',
        method: 'POST',
        data: { course_id: course_id, grado: grado, seccion: seccion, bimestre: bimestre, level: nivel, academic_year_id: academic_year_id },
        dataType: 'json',
        success: function(resp) {
            var html = '<option value="">Todas</option>';
            if (resp && Array.isArray(resp.evaluations) && resp.evaluations.length > 0) {
                resp.evaluations.forEach(function(ev) {
                    html += '<option value="'+ev.id+'">'+ev.title+'</option>';
                });
            }
            $('#evaluation_id').html(html).trigger('change');
        }
    });
}

$('#academic_year_id').on('change', function() {
    $('#level').val('').trigger('change');
    $('#grado').html('<option value="">Seleccionar</option>').prop('disabled', true).trigger('change');
    $('#seccion').html('<option value="">Seleccionar</option>').prop('disabled', true).trigger('change');
    $('#course_id').html('<option value="">Todos</option>').prop('disabled', true).trigger('change');
    $('#student_id').html('<option value="">Todos</option>').trigger('change');
    
    if ($(this).val()) {
        loadLevelsByAcademicYear($(this).val());
    }
});

$('#level').on('change', function() {
    updateGrados();
});

$('#grado').on('change', function() {
    updateSecciones();
});

$('#seccion').on('change', function() {
    updateCursos();
});

$('#course_id, #bimestre').on('change', function() {
    updateEvaluations();
});

$('#filter-form').submit(function(e) {
    e.preventDefault();
    
    var academic_year_id = $('#academic_year_id').val();
    if (!academic_year_id) {
        alert_toast('Por favor, seleccione un Año Académico.', 'warning');
        return;
    }
    
    start_load();
    
    if ($.fn.dataTable.isDataTable('#gradesReportData')) {
        $('#gradesReportData').DataTable().destroy();
    }
    
    $.ajax({
        url: 'grades_report_table.php',
        method: 'POST',
        data: $(this).serialize() + '&academic_year_id=' + $('#academic_year_id').val(),
        success: function(resp) {
            $('#grades-report-table').empty().html(resp);
            end_load();
        },
        error: function() {
            alert_toast("Error al cargar el reporte.", 'danger');
            end_load();
        }
    });
});

$('#print-report').on('click', function(e) {
    e.preventDefault();
    
    var bimestre = $('#bimestre').val();
    if (!bimestre) {
        alert_toast('Debe seleccionar un bimestre para imprimir.', 'warning');
        return;
    }
    
    var tables = $('#grades-report-table table');
    if (!tables.length) {
        alert_toast('No hay reporte para imprimir.', 'warning');
        return;
    }
    
    var allTablesHtml = '';
    tables.each(function() {
        var dataTable = $.fn.dataTable.isDataTable(this) ? $(this).DataTable() : null;
        var fullTable;
        if (dataTable) {
            var originalPageLength = dataTable.page.len();
            dataTable.page.len(-1).draw();
            fullTable = $(dataTable.table().node()).clone();
            fullTable.removeClass('dataTable no-footer').removeAttr('style');
            dataTable.page.len(originalPageLength).draw();
        } else {
            fullTable = $(this).clone();
        }
        allTablesHtml += '<div style="margin-bottom:30px;">' + fullTable.prop('outerHTML') + '</div>';
    });
    
    var win = window.open('', '', 'width=900,height=700');
    win.document.write('<html><head><title>Reporte de Notas</title>');
    win.document.write('<style>body{font-family:sans-serif;padding:20px;}table{width:100%;border-collapse:collapse;}th,td{border:1px solid #ccc;padding:8px;}th{background:#e3e6f0;font-weight:bold;}</style>');
    win.document.write('</head><body>');
    win.document.write('<h2 style="text-align:center; color:#333;">Reporte de Notas</h2>');
    win.document.write(allTablesHtml);
    win.document.write('</body></html>');
    win.document.close();
    win.focus();
    setTimeout(function(){ win.print(); }, 500);
});

$('#export-excel-report').on('click', function(e) {
    e.preventDefault();
    
    var bimestre = $('#bimestre').val();
    if (!bimestre) {
        alert_toast('Debe seleccionar un bimestre para exportar.', 'warning');
        return;
    }
    
    var academic_year_id = $('#academic_year_id').val();
    if (!academic_year_id) {
        alert_toast('Por favor, seleccione un Año Académico.', 'warning');
        return;
    }
    
    if ($('#grades-report-table table').length === 0) {
        alert_toast('No hay datos para exportar.', 'warning');
        return;
    }
    
    $('#export-format-modal').modal('show');
});

$(document).on('click', '.format-option', function() {
    $('.format-option').removeClass('border-primary bg-light');
    $(this).addClass('border-primary bg-light');
    $('#confirm-export').prop('disabled', false);
});

$('#confirm-export').on('click', function() {
    var selectedFormat = $('.format-option.border-primary').data('format');
    
    if (!selectedFormat) {
        alert_toast('Por favor, seleccione un formato.', 'warning');
        return;
    }
    
    $('#export-format-modal').modal('hide');
    
    var formData = $('#filter-form').serialize();
    formData += '&export_format=' + selectedFormat;
    var downloadToken = 'download_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    formData += '&download_token=' + downloadToken;
    
    start_load();
    
    var iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = 'export_grades_excel.php?' + formData;
    document.body.appendChild(iframe);
    
    setTimeout(function() {
        end_load();
        if (document.body.contains(iframe)) {
            document.body.removeChild(iframe);
        }
    }, 5000);
});

$('#export-format-modal').on('hidden.bs.modal', function() {
    $('.format-option').removeClass('border-primary bg-light');
    $('#confirm-export').prop('disabled', true);
});

$('#show-avg-report').on('click', function() {
    var studentId = $('#student_id').val();
    var bimestre = $('#bimestre').val();
    
    if (!studentId) {
        alert_toast('Seleccione un alumno.', 'warning');
        return;
    }
    
    if (!bimestre) {
        alert_toast('Seleccione un bimestre.', 'warning');
        return;
    }
    
    start_load();
    
    var course_id = $('#course_id').val();
    var grado = $('#grado').val();
    var seccion = $('#seccion').val();
    var evaluation_id = $('#evaluation_id').val();
    var nivel = $('#level').val();
    
    $.ajax({
        url: 'grades_report_table.php',
        method: 'POST',
        data: {
            course_id: course_id,
            grado: grado,
            seccion: seccion,
            bimestre: bimestre,
            student_id: studentId,
            evaluation_id: evaluation_id,
            level: nivel,
            academic_year_id: $('#academic_year_id').val(),
            show_avg: 1
        },
        success: function(resp) {
            var win = window.open('', '', 'width=700,height=600');
            if (!win) {
                alert_toast('Por favor, permita ventanas emergentes.', 'warning');
                end_load();
                return;
            }
            
            win.document.write('<html><head><title>Promedio Final</title>');
            win.document.write('<style>body{font-family:sans-serif;padding:20px;}table{width:100%;border-collapse:collapse;margin-top:20px;}th,td{border:1px solid #ccc;padding:8px;}th{background:#e3e6f0;}</style>');
            win.document.write('</head><body>');
            win.document.write('<h3 style="text-align:center;">Promedio Final del Alumno</h3>');
            win.document.write(resp);
            win.document.write('</body></html>');
            win.document.close();
            win.focus();
            end_load();
        },
        error: function() {
            alert_toast('Error al calcular el promedio.', 'danger');
            end_load();
        }
    });
});

function loadLevelsByAcademicYear(academic_year_id) {
    if (!academic_year_id) {
        $('#level').html('<option value="">Seleccionar</option>').prop('disabled', true);
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=get_levels_by_academic_year',
        method: 'POST',
        data: { academic_year_id: academic_year_id },
        dataType: 'json',
        success: function(resp) {
            var html = '<option value="">Seleccionar</option>';
            if (resp && Array.isArray(resp.levels) && resp.levels.length > 0) {
                resp.levels.forEach(function(level) {
                    html += '<option value="'+level+'">'+level+'</option>';
                });
                $('#level').html(html).prop('disabled', false);
            } else {
                $('#level').html(html).prop('disabled', true);
            }
        }
    });
}

$(document).ready(function() {
    var academic_year_id = $('#academic_year_id').val();
    if (academic_year_id) {
        var hasServerLevels = $('#level option').length > 1;
        if (!hasServerLevels) {
            loadLevelsByAcademicYear(academic_year_id);
        } else {
            var selectedLevel = $('#level').val();
            if (selectedLevel) {
                updateGrados();
                
                // Si ya hay grado y sección seleccionados, continuar la cadena
                setTimeout(function() {
                    var selectedGrado = $('#grado').val();
                    if (selectedGrado) {
                        updateSecciones();
                        
                        // Si ya hay sección, continuar
                        setTimeout(function() {
                            var selectedSeccion = $('#seccion').val();
                            if (selectedSeccion) {
                                updateCursos();
                                updateStudents();
                            }
                        }, 500);
                    }
                }, 500);
            }
        }
    }
});
</script>
