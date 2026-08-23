<?php
include('db_connect.php');
$login_type = $_SESSION['login_type'] ?? null;
$is_teacher = ($login_type == 2);
$teacher_id = $_SESSION['login_teacher_id'] ?? null;

// Si no es docente, solo mostramos el aviso y permitimos seguir navegando por el resto del sistema
if (!$is_teacher || !$teacher_id) {
    echo "<div class='py-5'><div class='alert alert-warning text-center'>Solo los docentes pueden gestionar notas. Usa el menú para ir a otra sección.</div></div>";
    return;
}

$school_id = $_SESSION['login_school_id'] ?? 0;
$academic_year_id = 0;
$academic_year_query = $conn->query("SELECT id FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
if($academic_year_query && $academic_year_query->num_rows > 0) {
    $academic_year_id = $academic_year_query->fetch_assoc()['id'];
}

$stats = ['total_evaluations' => 0, 'recent_evaluations' => 0, 'courses_count' => 0];

$total_q = $conn->query("SELECT COUNT(*) as total FROM evaluations WHERE teacher_id = $teacher_id AND academic_year_id = $academic_year_id");
if($total_q && $total_q->num_rows > 0) {
    $stats['total_evaluations'] = $total_q->fetch_assoc()['total'];
}

$recent_q = $conn->query("SELECT COUNT(*) as total FROM evaluations WHERE teacher_id = $teacher_id AND academic_year_id = $academic_year_id AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
if($recent_q && $recent_q->num_rows > 0) {
    $stats['recent_evaluations'] = $recent_q->fetch_assoc()['total'];
}

$courses_q = $conn->query("SELECT COUNT(*) as total FROM (SELECT DISTINCT ac.name, ac.level, tc.grado, tc.seccion FROM teacher_courses tc INNER JOIN academic_courses ac ON tc.course_id = ac.id WHERE tc.teacher_id = $teacher_id AND tc.academic_year_id = $academic_year_id) as unique_courses");
if($courses_q && $courses_q->num_rows > 0) {
    $stats['courses_count'] = $courses_q->fetch_assoc()['total'];
}
?>

<div class="container-fluid py-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="fa fa-clipboard-list mr-2"></i> Gestión de Evaluaciones</h1>
        <button class="btn btn-primary btn-sm" id="new_evaluation">
            <i class="fa fa-plus"></i> Nueva Evaluación
        </button>
    </div>

    <div class="row">
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Evaluaciones</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_evaluations']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-book fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Últimos 7 días</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['recent_evaluations']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Cursos Asignados</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['courses_count']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-graduation-cap fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-filter mr-2"></i> Filtros de búsqueda</h6>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="small mb-1">Año Académico</label>
                    <select id="academic_year_filter" class="form-control form-control-sm">
                        <?php 
                        $ay_query = $conn->query("SELECT * FROM academic_year WHERE school_id = {$_SESSION['login_school_id']} ORDER BY is_active DESC, year DESC");
                        while($ay_row = $ay_query->fetch_assoc()): 
                            $ay_selected = $ay_row['is_active'] ? 'selected' : '';
                        ?>
                        <option value="<?php echo $ay_row['id']; ?>" <?php echo $ay_selected; ?>>
                            <?php echo $ay_row['year']; ?> <?php echo $ay_row['is_active'] ? '(Activo)' : ''; ?>
                        </option>
                        <?php endwhile; ?>
                        <option value="0">Todos los años</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="small mb-1">Nivel</label>
                    <select id="level_filter" class="form-control form-control-sm">
                        <option value="">Todos los niveles</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="small mb-1">Grado</label>
                    <select id="grado_filter" class="form-control form-control-sm">
                        <option value="">Todos los grados</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="small mb-1">Sección</label>
                    <select id="seccion_filter" class="form-control form-control-sm">
                        <option value="">Todas las secciones</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="small mb-1">Curso</label>
                    <select id="course_filter" class="form-control form-control-sm">
                        <option value="">Todos los cursos</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="small mb-1">Bimestre</label>
                    <select id="bimestre_filter" class="form-control form-control-sm">
                        <option value="">Todos los bimestres</option>
                        <option value="1">1° Bimestre</option>
                        <option value="2">2° Bimestre</option>
                        <option value="3">3° Bimestre</option>
                        <option value="4">4° Bimestre</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-3 col-sm-6 mb-2">
                    <label class="small mb-1">Tipo de Evaluación</label>
                    <select id="type_filter" class="form-control form-control-sm">
                        <option value="">Todos los tipos</option>
                        <option value="Examen">Examen</option>
                        <option value="Examen Parcial">Examen Parcial</option>
                        <option value="Examen Final">Examen Final</option>
                        <option value="Exposición">Exposición</option>
                        <option value="Trabajo en Clase">Trabajo en Clase</option>
                        <option value="Quiz">Quiz</option>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6 mb-2">
                    <label class="small mb-1">Fecha</label>
                    <input type="date" id="date_filter" class="form-control form-control-sm" placeholder="Filtrar por fecha">
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-list mr-2"></i> Mis Evaluaciones</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="grades_table">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="15%">Evaluación</th>
                            <th width="10%">Tipo</th>
                            <th width="8%">Curso</th>
                            <th width="8%">Nivel</th>
                            <th width="4%">Grado</th>
                            <th width="4%">Sección</th>
                            <th width="8%">Bimestre</th>
                            <th width="8%">Año Académico</th>
                            <th width="12%">Fecha</th>
                            <th width="18%">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $i = 1;
                    $active_year_id = 0;
                    $ay_active_q = $conn->query("SELECT id FROM academic_year WHERE school_id = {$_SESSION['login_school_id']} AND is_active = 1 LIMIT 1");
                    if($ay_active_q && $ay_active_q->num_rows > 0) {
                        $active_year_id = $ay_active_q->fetch_assoc()['id'];
                    }
                    
                    if($active_year_id > 0) {
                        $update_query = "UPDATE evaluations SET academic_year_id = $active_year_id WHERE teacher_id = $teacher_id AND (academic_year_id IS NULL OR academic_year_id = 0)";
                        $conn->query($update_query);
                    }

                    $q = $conn->query("SELECT e.*, ac.name as course_name, ac.level, tc.grado, tc.seccion, ay.year as academic_year, ay.is_active, (SELECT COUNT(*) FROM evaluation_grades WHERE evaluation_id = e.id) as grades_count FROM evaluations e INNER JOIN teacher_courses tc ON tc.id = e.teacher_course_id INNER JOIN academic_courses ac ON ac.id = tc.course_id INNER JOIN academic_year ay ON ay.id = e.academic_year_id WHERE e.teacher_id = $teacher_id AND e.academic_year_id = $active_year_id AND ay.school_id = {$_SESSION['login_school_id']} ORDER BY e.created_at DESC");
                    while ($row = $q->fetch_assoc()):
                        $has_grades = $row['grades_count'] > 0;
                        $academic_year_display = $row['academic_year'] ?? 'N/A';
                        $is_active_year = $row['is_active'] ?? false;
                    ?>
                    <tr>
                        <td><?php echo $i++ ?></td>
                        <td><strong><?php echo htmlspecialchars($row['title']) ?></strong><br><small class="text-muted"><?php echo substr(htmlspecialchars($row['description']), 0, 30) . (strlen($row['description']) > 30 ? '...' : ''); ?></small></td>
                        <td><span class="badge badge-secondary"><?php echo htmlspecialchars($row['type']) ?></span></td>
                        <td><?php echo htmlspecialchars($row['course_name']) ?></td>
                        <td><?php echo htmlspecialchars($row['level']) ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($row['grado']) ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($row['seccion'] ?? 'U') ?></td>
                        <td><?php if($row['bimestre']): ?><span class="badge badge-light"><?php echo $row['bimestre'] ?>° Bimestre</span><?php else: ?><span class="badge badge-secondary">No asignado</span><?php endif; ?></td>
                        <td><?php if($academic_year_display != 'N/A'): ?><span class="badge <?php echo $is_active_year ? 'badge-primary' : 'badge-secondary'; ?>"><?php echo $academic_year_display ?></span><?php else: ?><span class="badge badge-warning">Sin asignar</span><?php endif; ?></td>
                        <td class="text-center"><?php if(isset($row['created_at'])): $fecha = new DateTime($row['created_at']); echo $fecha->format('d/m/Y H:i'); else: echo 'N/A'; endif; ?></td>
                        <td class="text-center">
                            <button class="btn btn-primary btn-sm edit_evaluation me-2" data-id="<?php echo $row['id'] ?>" data-toggle="tooltip" title="Editar"><i class="fa fa-edit"></i></button>
                            <button class="btn btn-info btn-sm enter_grades me-2" data-id="<?php echo $row['id'] ?>" data-toggle="tooltip" title="Gestionar notas"><i class="fa fa-pen"></i><?php echo $has_grades ? ' <span class="badge badge-light">'.$row['grades_count'].'</span>' : '' ?></button>
                            <button class="btn btn-danger btn-sm delete_evaluation" data-id="<?php echo $row['id'] ?>" data-toggle="tooltip" title="Eliminar"><i class="fa fa-trash-alt"></i></button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch (error) {
        return dateString;
    }
}

$(document).ready(function() {
    if ($('#grades_table tbody tr').length > 0 && $('#grades_table tbody tr td div.alert').length === 0) {
        initializeDataTable();
    }
    
    $('[data-toggle="tooltip"]').tooltip();
    loadFilterOptions();
    
    $('#academic_year_filter').change(function() {
        $('#level_filter, #grado_filter, #seccion_filter, #course_filter, #bimestre_filter, #type_filter, #date_filter').val('');
        loadFilterOptions();
        loadEvaluations();
    });
    
    $('#level_filter, #grado_filter, #seccion_filter, #course_filter, #bimestre_filter, #type_filter, #date_filter').change(function() {
        loadEvaluations();
    });

    $('#new_evaluation').click(function() {
        uni_modal("Nueva Evaluación", "manage_evaluation.php", "mid-large");
    });

    $(document).on('evaluation:saved evaluation:gradesSaved', function(){ loadEvaluations(); });
    $(document).on('click', '.edit_evaluation', function() { uni_modal("Editar Evaluación", "manage_evaluation.php?id=" + $(this).attr('data-id'), "mid-large"); });
    $(document).on('click', '.delete_evaluation', function() { _conf("¿Deseas eliminar esta evaluación?", "delete_evaluation", [$(this).attr('data-id')]); });
    $(document).on('click', '.enter_grades', function() { uni_modal("Ingresar Notas", "manage_evaluation_grades.php?evaluation_id=" + $(this).attr('data-id'), "large"); });
});

function loadFilterOptions() {
    const academicYearId = $('#academic_year_filter').val();
    const teacherId = <?php echo json_encode($teacher_id); ?>;
    $.ajax({
        url: 'grades_filters_data.php',
        method: 'GET',
        data: { teacher_id: teacherId, academic_year_id: academicYearId },
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) updateFilterSelects(resp);
        }
    });
}

function updateFilterSelects(data) {
    function formatGrado(grado) {
        if (!grado) return '';
        const cleaned = grado.toString().replace(/[°º]+/g, '').trim();
        return /^\d+$/.test(cleaned) ? cleaned + '°' : cleaned;
    }
    
    const levelSelect = $('#level_filter');
    levelSelect.find('option:not(:first)').remove();
    data.levels.forEach(function(level) { if (level) levelSelect.append(`<option value="${level}">${level}</option>`); });
    
    const gradoSelect = $('#grado_filter');
    gradoSelect.find('option:not(:first)').remove();
    data.grados.forEach(function(grado) { if (grado) { const formattedGrado = formatGrado(grado); gradoSelect.append(`<option value="${grado}">${formattedGrado}</option>`); }});
    
    const seccionSelect = $('#seccion_filter');
    seccionSelect.find('option:not(:first)').remove();
    data.secciones.forEach(function(seccion) { if (seccion) seccionSelect.append(`<option value="${seccion}">${seccion}</option>`); });
    
    const courseSelect = $('#course_filter');
    courseSelect.find('option:not(:first)').remove();
    if (data.courses) data.courses.forEach(function(course) { if (course) courseSelect.append(`<option value="${course}">${course}</option>`); });
}

function initializeDataTable() {
    if ($('#grades_table tbody tr').length === 0 || $('#grades_table tbody tr td div.alert').length > 0) return;
    try {
        if ($.fn.DataTable.isDataTable('#grades_table')) $('#grades_table').DataTable().destroy();
        $('#grades_table').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json' },
            pageLength: 10,
            responsive: true,
            order: [[0, 'desc']],
            columnDefs: [{ targets: [10], orderable: false }]
        });
    } catch (error) {}
}

function loadEvaluations() {
    const academicYearId = $('#academic_year_filter').val();
    const teacherId = <?php echo json_encode($teacher_id); ?>;
    
    $('#grades_table tbody').html('<tr><td colspan="11" class="text-center py-4"><i class="fa fa-spinner fa-spin mr-2"></i> Cargando...</td></tr>');
    
    $.ajax({
        url: 'ajax.php?action=get_teacher_evaluations',
        method: 'POST',
        data: {
            teacher_id: teacherId,
            academic_year_id: academicYearId,
            level: $('#level_filter').val(),
            grado: $('#grado_filter').val(),
            seccion: $('#seccion_filter').val(),
            course: $('#course_filter').val(),
            bimestre: $('#bimestre_filter').val(),
            type: $('#type_filter').val(),
            date: $('#date_filter').val()
        },
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) renderEvaluationsTable(resp.data);
            else $('#grades_table tbody').html(`<tr><td colspan="11" class="text-center py-4"><div class="alert alert-danger"><i class="fa fa-exclamation-triangle mr-2"></i> Error al cargar evaluaciones</div></td></tr>`);
        },
        error: function() {
            $('#grades_table tbody').html(`<tr><td colspan="11" class="text-center py-4"><div class="alert alert-danger"><i class="fa fa-exclamation-circle mr-2"></i> Error de conexión</div></td></tr>`);
        }
    });
}

function renderEvaluationsTable(evaluations) {
    try {
        if ($.fn.DataTable.isDataTable('#grades_table')) {
            $('#grades_table').DataTable().destroy();
            $('#grades_table').html(`<thead><tr><th width="5%">#</th><th width="15%">Evaluación</th><th width="10%">Tipo</th><th width="8%">Curso</th><th width="8%">Nivel</th><th width="4%">Grado</th><th width="4%">Sección</th><th width="8%">Bimestre</th><th width="8%">Año Académico</th><th width="12%">Fecha</th><th width="18%">Acción</th></tr></thead><tbody></tbody>`);
        }
    } catch (error) {}
    
    if (evaluations.length === 0) {
        $('#grades_table tbody').html(`<tr><td colspan="11" class="text-center py-4"><div class="alert alert-info"><i class="fa fa-info-circle mr-2"></i> No se encontraron evaluaciones</div></td></tr>`);
        return;
    }
    
    let html = '';
    evaluations.forEach((eval, index) => {
        const hasGrades = eval.grades_count > 0;
        const academicYearDisplay = eval.academic_year || 'N/A';
        const yearBadgeClass = eval.is_active ? 'badge-primary' : 'badge-secondary';
        html += `<tr>
            <td>${index + 1}</td>
            <td><strong>${eval.title}</strong><br><small class="text-muted">${eval.description.substr(0, 30)}${eval.description.length > 30 ? '...' : ''}</small></td>
            <td><span class="badge badge-secondary">${eval.type}</span></td>
            <td>${eval.course_name}</td>
            <td>${eval.level}</td>
            <td class="text-center">${eval.grado}</td>
            <td class="text-center">${eval.seccion || 'U'}</td>
            <td>${eval.bimestre ? `<span class="badge badge-light">${eval.bimestre}° Bimestre</span>` : `<span class="badge badge-secondary">No asignado</span>`}</td>
            <td>${academicYearDisplay !== 'N/A' ? `<span class="badge ${yearBadgeClass}">${academicYearDisplay}</span>` : `<span class="badge badge-warning">Sin asignar</span>`}</td>
            <td class="text-center">${formatDate(eval.created_at)}</td>
            <td class="text-center">
                <button class="btn btn-primary btn-sm edit_evaluation me-2" data-id="${eval.id}" data-toggle="tooltip" title="Editar"><i class="fa fa-edit"></i></button>
                <button class="btn btn-info btn-sm enter_grades me-2" data-id="${eval.id}" data-toggle="tooltip" title="Gestionar notas"><i class="fa fa-pen"></i>${hasGrades ? ` <span class="badge badge-light">${eval.grades_count}</span>` : ''}</button>
                <button class="btn btn-danger btn-sm delete_evaluation" data-id="${eval.id}" data-toggle="tooltip" title="Eliminar"><i class="fa fa-trash-alt"></i></button>
            </td>
        </tr>`;
    });
    
    $('#grades_table tbody').html(html);
    $('[data-toggle="tooltip"]').tooltip();
    
    setTimeout(function() {
        if (!$.fn.DataTable.isDataTable('#grades_table')) {
            $('#grades_table').DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json' },
                pageLength: 10,
                responsive: true,
                order: [[0, 'desc']],
                columnDefs: [{ targets: [10], orderable: false }]
            });
        }
    }, 100);
}

function delete_evaluation(id) {
    start_load();
    $('.modal.show').modal('hide');
    $('.modal-backdrop').remove();
    $('body').removeClass('modal-open');
    $.ajax({
        url: 'ajax.php?action=delete_evaluation',
        method: 'POST',
        data: { id: id },
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                alert_toast("Evaluación eliminada exitosamente.", 'success');
                loadEvaluations();
            } else {
                alert_toast(resp.message || "Error al eliminar la evaluación.", 'danger');
            }
            end_load();
        },
        error: function() {
            alert_toast("Error en el servidor.", 'danger');
            end_load();
        }
    });
}
</script>
