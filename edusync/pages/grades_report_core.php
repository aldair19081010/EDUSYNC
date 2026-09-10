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
$teacher_id = $_SESSION['login_teacher_id'] ?? null;
require_once dirname(__DIR__) . '/includes/grades_report_access.php';
$reportDirector = grades_report_is_director($conn);
$is_admin = $is_admin || $reportDirector;
$is_teacher = $is_teacher && !$reportDirector;

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
$selected_academic_year = $_POST['academic_year_id'] ?? ($_GET['academic_year_id'] ?? '');
$selected_course = $_POST['course_id'] ?? ($_GET['course_id'] ?? '');
$selected_level = $_POST['level'] ?? ($_GET['level'] ?? '');
$selected_grado = $_POST['grado'] ?? ($_GET['grado'] ?? '');
$selected_seccion = $_POST['seccion'] ?? ($_GET['seccion'] ?? '');
$selected_bimestre = $_POST['bimestre'] ?? ($_GET['bimestre'] ?? '');
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

<style>
.gr-report-shell{padding-top:1rem;width:100%;max-width:none}.gr-report-hero{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.55rem;padding:1.05rem 1.25rem;margin-bottom:14px;width:100%}.gr-report-hero h1{font-size:1.35rem;font-weight:700;color:#344767;margin:0}.gr-report-hero p{color:#7b8499;font-size:.84rem;margin:.15rem 0 0}.gr-filter-card,.gr-results-card{border:1px solid #e4e9f2;border-radius:9px;box-shadow:0 2px 8px rgba(31,45,61,.04);width:100%}.gr-filter-card .card-header{background:#fff;border-bottom:1px solid #eaecf4}.gr-filter-card .card-body{padding:12px 14px 4px}.gr-filter-card label{font-size:.73rem;color:#596579}.gr-tabs{display:flex;gap:.35rem;flex-wrap:wrap;border-bottom:1px solid #e3e6f0;padding-bottom:.65rem;margin-bottom:1rem}.gr-tabs .btn{border-radius:.35rem;font-size:.78rem}.gr-tabs .btn.active{background:#4e73df;color:#fff;border-color:#4e73df}.grades-view-meta{display:flex;gap:.65rem;flex-wrap:wrap}.grades-view-meta span{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.35rem;padding:.4rem .7rem;color:#5a5c69}.student-report-heading{display:grid;grid-template-columns:2fr 1fr 1fr;gap:.65rem;margin-bottom:1rem}.student-report-heading div{border:1px solid #e3e6f0;background:#f8f9fc;border-radius:.4rem;padding:.6rem}.student-report-heading small,.student-report-heading strong{display:block}.report-clean-table thead th{background:#f1f4f9;color:#344767;vertical-align:middle}.gr-results-card .card-body{padding:14px}.gr-empty{padding:2.5rem;text-align:center;color:#858796}.gr-print-frame{width:100%;height:72vh;border:0}#grades-report-table,#grades-report-table>.table-responsive,#grades-report-table .dataTables_wrapper,#grades-report-table table{width:100%!important;max-width:none!important}#grades-report-table table{margin-left:0!important;margin-right:0!important}#grades-report-table .dataTables_wrapper>.row{margin-left:0;margin-right:0}#grades-report-table .dataTables_wrapper>.row>[class*=col-]{padding-left:0;padding-right:0}.table-responsive{overflow-x:auto}@media(max-width:767px){.student-report-heading{grid-template-columns:1fr}.gr-report-shell{padding-left:0;padding-right:0}.gr-results-card .card-body{padding:10px}.gr-tabs .btn{flex:1 0 42%}}
.gr-report-shell .gr-tabs .report-view.active,
.gr-report-shell .gr-tabs .report-view.active:hover,
.gr-report-shell .gr-tabs .report-view.active:focus,
.gr-report-shell .gr-tabs .report-view.active:active{color:#fff!important;background:#4e73df!important;border-color:#4e73df!important}
.gr-report-shell .gr-tabs .report-view.active i{color:inherit!important}
.gr-report-shell .gr-tabs .report-view:focus-visible{outline:2px solid #344767;outline-offset:2px}
</style>
<div class="container-fluid gr-report-shell">
    <div class="gr-report-hero d-sm-flex align-items-center justify-content-between">
        <div><h1><i class="fa fa-chart-bar mr-2 text-primary"></i>Reporte de notas</h1><p>Consulta el detalle, avance del aula, seguimiento individual y evolución por bimestre.</p></div>
        <a href="index.php?page=grades" class="btn btn-outline-primary btn-sm mt-2 mt-sm-0"><i class="fas fa-clipboard-list mr-1"></i>Evaluaciones y notas</a>
    </div>

    <div class="card gr-filter-card mb-3">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-filter mr-2"></i> Filtros de búsqueda</h6>
        </div>
        <div class="card-body">
            <form id="filter-form">
                <input type="hidden" name="report_view" id="report_view" value="detail">
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
                                    <i class="fa fa-print text-secondary"></i> Vista de impresión
                                </a>
                                <a class="dropdown-item" href="#" id="export-excel-report">
                                    <i class="fa fa-file-excel text-success"></i> Excel del detalle oficial
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card gr-results-card mb-4">
        <div class="card-body">
            <div class="gr-tabs" role="tablist">
                <button type="button" class="btn btn-outline-primary btn-sm report-view active" data-view="detail"><i class="fas fa-list mr-1"></i>Detalle</button>
                <button type="button" class="btn btn-outline-primary btn-sm report-view" data-view="consolidated"><i class="fas fa-users mr-1"></i>Consolidado del aula</button>
                <button type="button" class="btn btn-outline-primary btn-sm report-view" data-view="student"><i class="fas fa-user-graduate mr-1"></i>Ficha individual</button>
                <button type="button" class="btn btn-outline-primary btn-sm report-view" data-view="pending"><i class="fas fa-exclamation-circle mr-1"></i>Seguimiento</button>
                <button type="button" class="btn btn-outline-primary btn-sm report-view" data-view="comparison"><i class="fas fa-chart-line mr-1"></i>Comparación bimestral</button>
            </div>
            <div id="grades-report-table" class="gr-empty"><i class="fas fa-filter fa-2x mb-2 d-block"></i>Seleccione los filtros y presione Buscar.</div>
        </div>
    </div>
</div>

<div class="modal fade" id="grades-print-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-print mr-2"></i>Vista de impresión</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
    <div class="modal-body p-0"><iframe id="grades-print-frame" class="gr-print-frame" title="Vista de impresión"></iframe></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cerrar</button><button type="button" class="btn btn-primary btn-sm" id="confirm-print"><i class="fas fa-print mr-1"></i>Imprimir</button></div>
  </div></div>
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

$.ajaxPrefilter(function(options) {
    if (/ajax\.php\?action=get_(grados_by_nivel|secciones_by_grado_nivel|courses_by_aula|students_by_grado_seccion|evaluations_by_filters|levels_by_academic_year)(?:&|$)/.test(options.url)) {
        options.data = (options.data ? options.data + '&' : '') + 'report_scope=grades_report';
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
    var currentView = $('#report_view').val();
    if (!academic_year_id) {
        alert_toast('Por favor, seleccione un Año Académico.', 'warning');
        return;
    }
    if (currentView === 'student' && !$('#student_id').val()) {
        alert_toast('Seleccione un alumno para generar la ficha individual.', 'warning');
        return;
    }
    if (currentView === 'student' && !$('#course_id').val()) {
        alert_toast('Seleccione un curso para calcular el promedio por competencias.', 'warning');
        return;
    }
    if (currentView === 'student' && !$('#bimestre').val()) {
        alert_toast('Seleccione un bimestre para calcular el promedio por competencias.', 'warning');
        return;
    }
    
    start_load();
    
    if ($.fn.dataTable.isDataTable('#gradesReportData')) {
        $('#gradesReportData').DataTable().destroy();
    }
    
    var requestData = $(this).serialize() + '&academic_year_id=' + $('#academic_year_id').val();
    if (currentView === 'student') requestData += '&show_avg=1';
    $.ajax({
        url: (currentView === 'detail' || currentView === 'student') ? 'grades_report_table.php' : 'grades_report_views.php',
        method: 'POST',
        data: requestData,
        timeout: 60000,
        success: function(resp) {
            $('#grades-report-table').removeClass('gr-empty').empty().html(resp);
            try { initializeReportTables(); } catch (error) { console.error('No se pudo paginar la vista:', error); }
        },
        error: function(xhr, status) {
            var message = status === 'timeout' ? 'La consulta tardó demasiado. Reduzca los filtros e inténtelo nuevamente.' : 'Error al cargar el reporte.';
            $('#grades-report-table').html('<div class="alert alert-danger mb-0">'+message+'</div>');
            alert_toast(message, 'danger');
        },
        complete: function() {
            end_load();
        }
    });
});

function initializeReportTables() {
    $('#grades-report-table .js-report-table').each(function() {
        $(this).addClass('table-hover').css('width', '100%');
        if ($.fn.dataTable && !$.fn.dataTable.isDataTable(this)) {
            $(this).DataTable({
                autoWidth: false,
                pageLength: 15,
                lengthMenu: [[15,25,50,100],[15,25,50,100]],
                order: [],
                language: { search:'Buscar:', lengthMenu:'Mostrar _MENU_', info:'Mostrando _START_ a _END_ de _TOTAL_', infoEmpty:'Sin registros', zeroRecords:'No se encontraron resultados', paginate:{previous:'Anterior',next:'Siguiente'} }
            });
        }
    });
}

$(document).on('click', '.report-view', function() {
    $('.report-view').removeClass('active');
    $(this).addClass('active');
    $('#report_view').val($(this).data('view'));
    var studentMode = $(this).data('view') === 'student';
    $('#student_id').closest('.col-md-3').toggleClass('border-left-primary pl-3', studentMode);
    if (studentMode && !$('#student_id').val()) {
        alert_toast('Seleccione un alumno para la ficha individual.', 'info');
        return;
    }
    if (studentMode && (!$('#course_id').val() || !$('#bimestre').val())) {
        alert_toast('Seleccione curso y bimestre para aplicar la ponderación por competencias.', 'info');
        return;
    }
    $('#filter-form').trigger('submit');
});

$('#print-report').on('click', function(e) {
    e.preventDefault();
    
    if (!$('#grades-report-table table').length) {
        alert_toast('No hay reporte para imprimir.', 'warning');
        return;
    }
    var content = $('#grades-report-table').clone();
    content.find('.dataTables_length,.dataTables_filter,.dataTables_info,.dataTables_paginate,script').remove();
    content.find('table').removeClass('dataTable').removeAttr('style').css('width','100%');
    var filters = [$('#academic_year_id option:selected').text(), $('#level').val(), $('#grado').val(), $('#seccion').val(), $('#course_id option:selected').text(), $('#bimestre option:selected').text()].filter(Boolean).join(' · ');
    var html = '<!doctype html><html><head><meta charset="utf-8"><title>Reporte de notas</title><style>body{font-family:Arial,sans-serif;color:#25324b;padding:22px;font-size:12px}h2{margin:0 0 5px;color:#344767}.meta{color:#667085;margin-bottom:18px}table{width:100%;border-collapse:collapse;margin-bottom:16px}th,td{border:1px solid #cfd6e4;padding:6px;text-align:left}th{background:#eef2f8}.badge{border:1px solid #aaa;padding:2px 5px;border-radius:4px}@page{size:landscape;margin:10mm}</style></head><body><h2>Reporte de notas</h2><div class="meta">'+$('<div>').text(filters).html()+'</div>'+content.html()+'</body></html>';
    var frame = document.getElementById('grades-print-frame');
    frame.srcdoc = html;
    $('#grades-print-modal').modal('show');
});

$('#confirm-print').on('click', function(){
    var frame = document.getElementById('grades-print-frame');
    if (frame && frame.contentWindow) { frame.contentWindow.focus(); frame.contentWindow.print(); }
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
