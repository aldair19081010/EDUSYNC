<?php 
include('db_connect.php'); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;

// Manejar solicitudes AJAX
if (isset($_GET['action']) && $_GET['action'] == 'get_teachers') {
    header('Content-Type: application/json');
    $year_id = isset($_GET['year']) ? intval($_GET['year']) : 0;
    
    $teachers = array();
    if ($year_id > 0) {
        $query = "SELECT DISTINCT t.id, t.name, 
            (SELECT COUNT(*) FROM teacher_courses WHERE teacher_id = t.id AND academic_year_id = $year_id) as course_count
			FROM teacher t 
            INNER JOIN teacher_courses tc ON t.id = tc.teacher_id
			WHERE t.school_id = $school_id AND t.status = 'Activo' AND tc.academic_year_id = $year_id
            ORDER BY t.name ASC";
            
        $teachers_list = $conn->query($query);
        
        if ($teachers_list && $teachers_list->num_rows > 0) {
            while ($t = $teachers_list->fetch_assoc()) {
                $teachers[] = array(
                    'id' => $t['id'],
                    'name' => ucwords($t['name']),
                    'course_count' => $t['course_count']
                );
            }
        }
    }
    
    echo json_encode($teachers);
    exit;
}
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
	.page-title-wrapper {
		margin-bottom: 16px;
	}

	.page-title {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 6px 10px;
	}

	.page-title .title-left {
		display: flex;
		align-items: center;
	}

	.page-title .title-icon {
		width: 44px;
		height: 44px;
		border-radius: 10px;
		background: linear-gradient(135deg, rgba(66,133,244,0.12), rgba(42,117,243,0.08));
		display: flex;
		align-items: center;
		justify-content: center;
		margin-right: 10px;
		color: #2a75f3;
		font-size: 1.1rem;
	}

	.page-title .page-title-text {
		font-size: 1.05rem;
		font-weight: 700;
		color: #233b52;
	}

	.page-title .page-title-sub {
		font-size: 0.85rem;
		color: #6c757d;
		margin-top: 2px;
		font-weight: 500;
	}

	@media (max-width: 575px) {
		.page-title .page-title-sub { display: none; }
		.page-title .title-icon { width: 40px; height: 40px; }
	}

	.filter-controls {
		display: flex;
		gap: 15px;
		flex-wrap: wrap;
		align-items: flex-end;
		margin-bottom: 20px;
	}

	.filter-group {
		flex: 0 1 calc(20% - 12px);
		min-width: 150px;
	}

	.filter-group label {
		display: block;
		font-weight: 600;
		margin-bottom: 8px;
		color: #233b52;
		font-size: 0.9rem;
	}

	#academic_year_filter,
	#teacher_filter,
	#course_filter {
		height: 50px;
		border-radius: 6px;
		border: 1px solid #d1d3e2;
		font-size: 0.95rem;
		font-weight: 500;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
	}

	#level_filter,
	#grado_filter,
	#seccion_filter {
		height: 50px;
		border-radius: 6px;
		border: 1px solid #d1d3e2;
		font-size: 0.95rem;
		font-weight: 500;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
	}

	.select2-container--default .select2-selection--single {
		height: 50px !important;
		border-radius: 6px !important;
		border: 1px solid #d1d3e2 !important;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
	}

	.select2-container--default .select2-selection--single .select2-selection__rendered {
		line-height: 50px !important;
		font-size: 0.95rem !important;
	}

	.select2-container--default .select2-selection--single .select2-selection__arrow {
		height: 50px !important;
	}

	.select2-dropdown {
		border-radius: 6px !important;
		box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
	}

	.btn-group-actions {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}

	.btn-group-actions .btn {
		padding: 8px 14px;
		font-size: 0.9rem;
		box-shadow: 0 1px 3px rgba(0,0,0,0.1);
	}

	.btn-group-actions .btn i {
		margin-right: 6px;
	}

	.table-hover tbody tr:hover {
		background-color: rgba(66,133,244,0.05) !important;
	}

	td { vertical-align: middle !important; }
	td p { margin: unset; }

	.empty-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 40px 20px;
		text-align: center;
	}

	.empty-state i {
		font-size: 3rem;
		color: #ccc;
		margin-bottom: 15px;
	}

	.empty-state h5 {
		color: #6c757d;
	}

	.empty-state p {
		color: #6c757d;
		margin: 0;
	}

	@media (max-width: 768px) {
		.filter-controls {
			flex-direction: column;
		}

		.filter-group {
			width: 100%;
			flex: 1 1 auto;
		}

		.btn-group-actions {
			width: 100%;
			justify-content: stretch;
		}

		.btn-group-actions .btn {
			font-size: 0.8rem;
			padding: 6px 12px;
			flex: 1;
		}
	}

	@media (max-width: 1200px) {
		.filter-group {
			flex: 0 1 calc(33.33% - 10px);
		}
	}

	@media (max-width: 992px) {
		.filter-group {
			flex: 0 1 calc(50% - 8px);
		}

		.btn-group-actions {
			width: 100%;
			justify-content: center;
		}
	}
</style>

<div class="container-fluid">
	<!-- Título de la página -->
	<div class="page-title-wrapper mb-4">
		<div class="page-title">
			<div class="title-left">
				<div class="title-icon"><i class="fa fa-user-graduate"></i></div>
				<div>
					<div class="page-title-text">Asignaciones de Docentes</div>
					<div class="page-title-sub">Gestión de docentes por curso y año académico</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Controles de filtro -->
	<div class="row">
		<div class="col-md-12">
			<div class="filter-controls">
				<div class="filter-group">
					<label for="academic_year_filter"><i class="fa fa-calendar-alt"></i> Año académico:</label>
					<select id="academic_year_filter" class="form-control">
						<?php
						$active_year_query = $conn->query("SELECT * FROM academic_year WHERE is_active = 1 AND school_id = $school_id LIMIT 1");
						$active_year_id = 0;
						if ($active_year_query && $active_year_query->num_rows > 0) {
							$active_year = $active_year_query->fetch_assoc();
							$active_year_id = $active_year['id'];
						}
						
						$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $active_year_id;
						$current_year = $selected_year;
						
						$years_query = $conn->query("SELECT * FROM academic_year WHERE school_id = $school_id ORDER BY is_active DESC, start_date DESC");
						while ($year = $years_query->fetch_assoc()):
							$year_text = $year['year'] . ' - ' . $year['description'];
							if ($year['is_active']) {
								$year_text .= ' (Activo)';
							}
							$selected = ($year['id'] == $selected_year) ? 'selected' : '';
						?>
							<option value="<?php echo $year['id'] ?>" <?php echo $selected ?>><?php echo $year_text ?></option>
						<?php endwhile; ?>
					</select>
				</div>

				<div class="filter-group">
					<label for="teacher_filter"><i class="fa fa-user"></i> Docente:</label>
					<select id="teacher_filter" class="form-control">
						<option value="">Todos</option>
						<?php
						$teacher_query = "SELECT DISTINCT t.id, t.name, 
							(SELECT COUNT(*) FROM teacher_courses WHERE teacher_id = t.id AND academic_year_id = $current_year) as course_count
							FROM teacher t
							INNER JOIN teacher_courses tc ON t.id = tc.teacher_id
							WHERE t.school_id = $school_id AND t.status = 'Activo' AND tc.academic_year_id = $current_year
							ORDER BY t.name ASC";
							
						$teachers_list = $conn->query($teacher_query);
						
						if ($teachers_list && $teachers_list->num_rows > 0):
							while ($t = $teachers_list->fetch_assoc()):
						?>
							<option value="<?php echo $t['name'] ?>"><?php echo ucwords($t['name']) ?></option>
						<?php 
							endwhile;
						endif; ?>
					</select>
				</div>

				<div class="filter-group">
					<label for="course_filter"><i class="fa fa-book"></i> Curso:</label>
					<select id="course_filter" class="form-control">
						<option value="">Todos</option>
						<?php
						$course_query = "SELECT DISTINCT ac.id, ac.name 
							FROM teacher_courses tc
							LEFT JOIN academic_courses ac ON ac.id = tc.course_id
							WHERE tc.academic_year_id = $current_year AND ac.school_id = $school_id
							ORDER BY ac.name ASC";
							
						$courses_list = $conn->query($course_query);
						
						if ($courses_list && $courses_list->num_rows > 0):
							while ($c = $courses_list->fetch_assoc()):
						?>
							<option value="<?php echo $c['name'] ?>"><?php echo ucwords($c['name']) ?></option>
						<?php 
							endwhile;
						endif; ?>
					</select>
				</div>

				<div class="filter-group">
					<label for="level_filter"><i class="fa fa-layer-group"></i> Nivel:</label>
					<select id="level_filter" class="form-control">
						<option value="">Todos</option>
						<?php
						$level_query = "SELECT DISTINCT ac.level 
							FROM teacher_courses tc
							LEFT JOIN academic_courses ac ON ac.id = tc.course_id
							WHERE tc.academic_year_id = $current_year AND ac.school_id = $school_id
							ORDER BY ac.level ASC";
							
						$levels_list = $conn->query($level_query);
						
						if ($levels_list && $levels_list->num_rows > 0):
							while ($l = $levels_list->fetch_assoc()):
						?>
							<option value="<?php echo $l['level'] ?>"><?php echo ucwords($l['level']) ?></option>
						<?php 
							endwhile;
						endif; ?>
					</select>
				</div>

				<div class="filter-group">
					<label for="grado_filter"><i class="fa fa-graduation-cap"></i> Grado:</label>
					<select id="grado_filter" class="form-control">
						<option value="">Todos</option>
						<?php
						$grado_query = "SELECT DISTINCT tc.grado 
							FROM teacher_courses tc
							WHERE tc.academic_year_id = $current_year
							ORDER BY tc.grado ASC";
							
						$grados_list = $conn->query($grado_query);
						
						if ($grados_list && $grados_list->num_rows > 0):
							while ($g = $grados_list->fetch_assoc()):
						?>
							<option value="<?php echo $g['grado'] ?>"><?php echo ucwords($g['grado']) ?></option>
						<?php 
							endwhile;
						endif; ?>
					</select>
				</div>

				<div class="filter-group">
					<label for="seccion_filter"><i class="fa fa-columns"></i> Sección:</label>
					<select id="seccion_filter" class="form-control">
						<option value="">Todos</option>
						<?php
						$seccion_query = "SELECT DISTINCT tc.seccion 
							FROM teacher_courses tc
							WHERE tc.academic_year_id = $current_year AND tc.seccion IS NOT NULL
							ORDER BY tc.seccion ASC";
							
						$secciones_list = $conn->query($seccion_query);
						
						if ($secciones_list && $secciones_list->num_rows > 0):
							while ($s = $secciones_list->fetch_assoc()):
						?>
							<option value="<?php echo $s['seccion'] ?>"><?php echo ucwords($s['seccion']) ?></option>
						<?php 
							endwhile;
						endif; ?>
					</select>
				</div>

				<div class="btn-group-actions">
					<button class="btn btn-primary" id="new_teacher_course" title="Asignar docente a curso">
						<i class="fa fa-plus"></i> Asignar Docente
					</button>
					<button class="btn btn-info" id="copy_from_previous_year" title="Copiar asignaciones del año anterior">
						<i class="fa fa-copy"></i> Copiar Año Anterior
					</button>
					<button class="btn btn-warning" id="clean_teacher_courses" title="Limpiar todas las asignaciones">
						<i class="fa fa-broom"></i> Limpiar
					</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Panel de la tabla -->
	<div class="row">
		<div class="col-md-12">
			<div class="card border-left-primary shadow h-100 py-2">
				<div class="card-header bg-white py-3">
					<span class="m-0 font-weight-bold text-primary">Lista de Asignaciones</span>
				</div>
				<div class="card-body">
					<div id="msg"></div>
					<div class="table-responsive">
						<table id="teacher_courses_table" class="table table-striped table-bordered table-hover">
							<thead>
								<tr>
									<th class="text-center" style="width: 40px;">#</th>
									<th>Docente</th>
									<th>Curso</th>
									<th>Nivel</th>
									<th>Grado</th>
									<th>Sección</th>
									<th class="text-center" style="width: 100px;">Acción</th>
								</tr>
							</thead>
							<tbody>
							<?php
							$i = 1;
							
							$courses_query = $conn->query("SELECT tc.id, tc.teacher_id, tc.course_id, tc.grado, tc.seccion, t.name as teacher_name, ac.name as course_name, ac.level 
								FROM teacher_courses tc
								INNER JOIN teacher t ON t.id = tc.teacher_id
								LEFT JOIN academic_courses ac ON ac.id = tc.course_id
								WHERE t.school_id = $school_id AND tc.academic_year_id = $current_year
								ORDER BY t.name ASC, ac.name ASC");
							
							if ($courses_query && $courses_query->num_rows > 0):
								while ($row = $courses_query->fetch_assoc()):
							?>
								<tr>
									<td class="text-center"><?php echo $i++ ?></td>
									<td><?php echo ucwords($row['teacher_name']) ?></td>
									<td><?php echo ucwords($row['course_name']) ?></td>
									<td><?php echo $row['level'] ?></td>
									<td><?php echo $row['grado'] ?></td>
									<td><?php echo $row['seccion'] ?? 'U' ?></td>
									<td class="text-center">
										<button class="btn btn-primary btn-sm edit_tc" type="button" data-id="<?php echo $row['id'] ?>"><i class="fa fa-edit"></i></button>
										<button class="btn btn-danger btn-sm delete_tc" type="button" data-id="<?php echo $row['id'] ?>"><i class="fa fa-trash-alt"></i></button>
									</td>
								</tr>
							<?php 
								endwhile;
							else:
								$year_info_query = $conn->query("SELECT year, description FROM academic_year WHERE id = $current_year AND school_id = $school_id");
								$year_info = $year_info_query && $year_info_query->num_rows > 0 ? $year_info_query->fetch_assoc() : null;
								$year_display = $year_info ? $year_info['year'] . ' - ' . $year_info['description'] : 'el año seleccionado';
							?>
								<tr>
									<td colspan="7" class="text-center py-5">
										<div class="empty-state">
											<i class="fa fa-calendar-times"></i>
											<h5 class="text-muted">No hay asignaciones para <?php echo $year_display ?></h5>
											<p class="text-muted">Puedes crear nuevas asignaciones usando el botón de arriba.</p>
										</div>
									</td>
								</tr>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    // Inicializar Select2 para docente y curso
    $('#teacher_filter').select2({
        placeholder: 'Seleccionar docente...',
        allowClear: true,
        width: '100%',
        language: 'es'
    });

    $('#course_filter').select2({
        placeholder: 'Seleccionar curso...',
        allowClear: true,
        width: '100%',
        language: 'es'
    });

    // Inicializar DataTables
    var table = $('#teacher_courses_table').DataTable({
        "language": {
            "url": "https://cdn.datatables.net/plug-ins/1.10.21/i18n/Spanish.json"
        },
        "pageLength": 10,
        "lengthMenu": [[10, 25, 50, 100], [10, 25, 50, 100]],
        "order": [[1, 'asc']]
    });

    function applyAllFilters() {
        var teacherFilter = $('#teacher_filter').val();
        var courseFilter = $('#course_filter').val();
        var levelFilter = $('#level_filter').val();
        var gradoFilter = $('#grado_filter').val();
        var seccionFilter = $('#seccion_filter').val();
        
        // Aplicar filtros de búsqueda en cada columna
        table.column(1).search(teacherFilter || '').draw();
        table.column(2).search(courseFilter || '').draw();
        table.column(3).search(levelFilter || '').draw();
        table.column(4).search(gradoFilter || '').draw();
        table.column(5).search(seccionFilter || '').draw();
    }

    $('#academic_year_filter').on('change', function() {
        var selectedYear = $(this).val();
        var url = new URL(window.location.href);
        url.searchParams.set('year', selectedYear);
        window.location.href = url.toString();
    });

    $('#teacher_filter').on('change', function() {
        applyAllFilters();
    });

    $('#course_filter').on('change', function() {
        applyAllFilters();
    });

    $('#level_filter').on('change', function() {
        applyAllFilters();
    });

    $('#grado_filter').on('change', function() {
        applyAllFilters();
    });

    $('#seccion_filter').on('change', function() {
        applyAllFilters();
    });
});

$('#new_teacher_course').click(function() {
	var selectedYear = $('#academic_year_filter').val();
	uni_modal("Asignar Docente a Curso", "manage_teacher_course.php?academic_year_id=" + selectedYear, "mid-large");
});

$('#clean_teacher_courses').click(function() {
    var selectedYear = $('#academic_year_filter').val();
    var yearText = $('#academic_year_filter option:selected').text();
    _conf("¿Estás seguro de limpiar TODAS las asignaciones del año " + yearText + "?", "clean_all_teacher_courses", [selectedYear]);
});

$('#copy_from_previous_year').click(function() {
    var currentYear = $('#academic_year_filter').val();
    var yearText = $('#academic_year_filter option:selected').text();
    _conf("¿Deseas copiar las asignaciones del año anterior al año " + yearText + "?", "copy_from_previous_year", [currentYear]);
});

$(document).on('click', '.edit_tc', function() {
    uni_modal("Editar Asignación", "manage_teacher_course.php?id=" + $(this).attr('data-id'), "mid-large");
});

$(document).on('submit', '#manage-teacher-course', function(e) {
    e.preventDefault();
    start_load();
    $('#msg').html('');
    
    var selectedYear = $('#academic_year_filter').val();
    var formData = $(this).serialize() + '&academic_year_id=' + selectedYear;
    
    $.ajax({
        url: 'ajax.php?action=assign_teacher_course',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                alert_toast("Asignación guardada exitosamente", 'success');
                setTimeout(function() { location.reload(); }, 1500);
            } else if (resp.status == 2) {
                $('#msg').html('<div class="alert alert-danger">El docente ya está asignado a este curso.</div>');
                end_load();
            } else {
                $('#msg').html('<div class="alert alert-danger">' + (resp.message || "Ocurrió un error") + '</div>');
                end_load();
            }
        }
    });
});

$('.delete_tc').click(function() {
	_conf("¿Deseas eliminar esta asignación?", "delete_teacher_course", [$(this).attr('data-id')]);
});

function delete_teacher_course(id) {
	start_load();
	$.ajax({
		url: 'ajax.php?action=delete_teacher_course',
		method: 'POST',
		data: { id: id },
		dataType: 'json',
		success: function(resp) {
			if (resp.status == 1) {
				alert_toast("Asignación eliminada exitosamente.", 'success');
				setTimeout(function() { location.reload(); }, 500);
			} else {
				alert_toast(resp.message || "Error al eliminar.", 'danger');
				end_load();
			}
		}
	});
}

function clean_all_teacher_courses(academicYearId) {
	start_load();
	$.ajax({
		url: 'ajax.php?action=clean_all_teacher_courses',
		method: 'POST',
		data: { academic_year_id: academicYearId, confirm: true },
		dataType: 'json',
		success: function(resp) {
			if (resp.status == 1) {
				alert_toast("Asignaciones eliminadas.", 'success');
				setTimeout(function() { location.reload(); }, 1500);
			} else {
				alert_toast(resp.message || "Error.", 'danger');
				end_load();
			}
		}
	});
}

function copy_from_previous_year(currentYearId) {
    start_load();
    $.ajax({
        url: 'ajax.php?action=copy_teacher_courses_from_previous_year',
        method: 'POST',
        data: { current_year_id: currentYearId, confirm: true },
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                var message = "Se copiaron " + (resp.copied || 0) + " asignaciones.";
                alert_toast(message, 'success');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                alert_toast(resp.message || "Error.", 'danger');
                end_load();
            }
        }
    });
}
</script>
