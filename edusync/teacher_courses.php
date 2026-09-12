<?php 
include __DIR__ . '/db_connect.php';
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
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

if (($_GET['partial'] ?? '') === 'course_data') {
	header('Content-Type: application/json; charset=utf-8');
	$year_id = intval($_GET['year'] ?? 0);
	if (!$school_id || !$year_id) { echo json_encode(['status'=>0,'message'=>'Sesión o año inválido.']); exit; }
	$year_check = $conn->query("SELECT id FROM academic_year WHERE id=$year_id AND school_id=".(int)$school_id." LIMIT 1");
	if (!$year_check || !$year_check->num_rows) { echo json_encode(['status'=>0,'message'=>'El año no pertenece a la institución.']); exit; }
	$list = $conn->query("SELECT tc.id,tc.grado,tc.seccion,t.name teacher_name,ac.name course_name,ac.level FROM teacher_courses tc INNER JOIN teacher t ON t.id=tc.teacher_id LEFT JOIN academic_courses ac ON ac.id=tc.course_id WHERE tc.school_id=".(int)$school_id." AND tc.academic_year_id=$year_id ORDER BY t.name,ac.name");
	ob_start(); $row_number=1;
	while ($list && ($item=$list->fetch_assoc())): ?>
	<tr><td class="text-center"><input type="checkbox" class="assignment-row-check" value="<?php echo (int)$item['id']; ?>"></td><td class="text-center"><?php echo $row_number++; ?></td><td><?php echo htmlspecialchars(ucwords($item['teacher_name']),ENT_QUOTES,'UTF-8'); ?></td><td><?php echo htmlspecialchars(ucwords($item['course_name']),ENT_QUOTES,'UTF-8'); ?></td><td><?php echo htmlspecialchars($item['level'],ENT_QUOTES,'UTF-8'); ?></td><td><?php echo htmlspecialchars($item['grado'],ENT_QUOTES,'UTF-8'); ?></td><td><?php echo htmlspecialchars($item['seccion'] ?: 'U',ENT_QUOTES,'UTF-8'); ?></td><td class="text-center"><button class="btn btn-primary btn-sm edit_tc" type="button" data-id="<?php echo (int)$item['id']; ?>"><i class="fa fa-edit"></i></button> <button class="btn btn-danger btn-sm delete_tc" type="button" data-id="<?php echo (int)$item['id']; ?>"><i class="fa fa-trash-alt"></i></button></td></tr>
	<?php endwhile; $rows_html=ob_get_clean();
	$stats=['assignments'=>0,'teachers'=>0,'without_courses'=>0,'unassigned_courses'=>0];
	$q=$conn->query("SELECT COUNT(*) assignments,COUNT(DISTINCT teacher_id) teachers FROM teacher_courses WHERE school_id=".(int)$school_id." AND academic_year_id=$year_id"); if($q)$stats=array_merge($stats,$q->fetch_assoc());
	$q=$conn->query("SELECT COUNT(*) total FROM teacher t WHERE t.school_id=".(int)$school_id." AND t.status='Activo' AND NOT EXISTS(SELECT 1 FROM teacher_courses tc WHERE tc.teacher_id=t.id AND tc.school_id=t.school_id AND tc.academic_year_id=$year_id)"); if($q)$stats['without_courses']=(int)$q->fetch_assoc()['total'];
	$pending=$conn->query("SELECT ac.id,ac.name,ac.level FROM academic_courses ac WHERE ac.school_id=".(int)$school_id." AND NOT EXISTS(SELECT 1 FROM teacher_courses tc WHERE tc.course_id=ac.id AND tc.school_id=ac.school_id AND tc.academic_year_id=$year_id) ORDER BY ac.level,ac.name");
	ob_start(); $pending_count=0; while($pending && ($course=$pending->fetch_assoc())): $pending_count++; ?><tr><td><?php echo htmlspecialchars($course['name'],ENT_QUOTES,'UTF-8'); ?></td><td><?php echo htmlspecialchars($course['level'],ENT_QUOTES,'UTF-8'); ?></td><td class="text-center"><button type="button" class="btn btn-primary btn-sm assign_unassigned_course" data-course-id="<?php echo (int)$course['id']; ?>"><i class="fa fa-user-plus mr-1"></i>Asignar</button></td></tr><?php endwhile; if(!$pending_count): ?><tr><td colspan="3" class="text-center text-success py-4"><i class="fa fa-check-circle mr-1"></i>Todos los cursos tienen al menos un docente.</td></tr><?php endif; $pending_html=ob_get_clean();
	$stats['unassigned_courses']=$pending_count;
	echo json_encode(['status'=>1,'rows'=>$rows_html,'pending_rows'=>$pending_html,'summary'=>$stats]); exit;
}
?>
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
		display: grid;
		grid-template-columns: repeat(4, minmax(0, 1fr));
		gap: 14px;
		align-items: end;
		padding: 16px;
		margin-bottom: 12px;
		background: #fff;
		border: 1px solid #e3e6f0;
		border-radius: 10px;
		box-shadow: 0 2px 10px rgba(0,0,0,.04);
	}

	.filter-group {
		min-width: 0;
	}

	.filter-group label {
		display: block;
		font-weight: 600;
		margin-bottom: 6px;
		color: #233b52;
		font-size: 0.9rem;
	}

	#academic_year_filter,
	#teacher_filter,
	#course_filter {
		height: 40px;
		border-radius: 6px;
		border: 1px solid #d1d3e2;
		font-size: 0.95rem;
		font-weight: 500;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
	}

	#level_filter,
	#grado_filter,
	#seccion_filter {
		height: 40px;
		border-radius: 6px;
		border: 1px solid #d1d3e2;
		font-size: 0.95rem;
		font-weight: 500;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
	}

	.select2-container--default .select2-selection--single {
		height: 40px !important;
		border-radius: 6px !important;
		border: 1px solid #d1d3e2 !important;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
	}

	.select2-container--default .select2-selection--single .select2-selection__rendered {
		line-height: 38px !important;
		font-size: 0.95rem !important;
	}

	.select2-container--default .select2-selection--single .select2-selection__arrow {
		height: 38px !important;
	}

	.select2-dropdown {
		border-radius: 6px !important;
		box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
	}

	.btn-group-actions {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		padding: 10px 4px 18px;
		grid-column: 1 / -1;
		border-top: 1px solid #edf0f5;
		margin-top: 2px;
		padding-top: 14px;
	}
	.primary-assignment-actions, .secondary-assignment-actions { display:flex; gap:8px; flex-wrap:wrap; }
	.advanced-filter-group { display:none; }
	.filter-controls.show-advanced .advanced-filter-group { display:block; }
	.filter-controls.show-advanced { grid-template-columns:repeat(4,minmax(0,1fr)); }
	#toggle_assignment_filters.active { color:#2a75f3; border-color:#2a75f3; background:#eef4ff; }

	.btn-group-actions .btn {
		padding: 8px 14px;
		font-size: 0.9rem;
		box-shadow: 0 1px 3px rgba(0,0,0,0.1);
	}

	.btn-group-actions .btn i {
		margin-right: 6px;
	}
	#new_teacher_course { margin-right:auto; }

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

	.assignment-summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
	.assignment-summary-card { background:#fff; border:1px solid #e3e6f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; box-shadow:0 2px 10px rgba(0,0,0,.05); }
	.assignment-summary-icon { width:38px; height:38px; flex:0 0 38px; border-radius:9px; display:flex; align-items:center; justify-content:center; margin-right:11px; }
	.assignment-summary-value { font-size:1.35rem; line-height:1; font-weight:800; color:#233b52; }
	.assignment-summary-label { margin-top:5px; color:#6c757d; font-size:.8rem; }
	.summary-assignments .assignment-summary-icon { background:#e8f0fe; color:#2a75f3; }
	.summary-assignments .assignment-summary-icon i:before { content:"\f0ae"; }
	.summary-teachers .assignment-summary-icon { background:#d4edda; color:#218838; }
	.summary-pending .assignment-summary-icon { background:#fff3cd; color:#856404; }
	.summary-courses .assignment-summary-icon { background:#d9edf7; color:#31708f; }
	.assignment-toolbar { display:none; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
	.assignment-toolbar.is-visible { display:flex; }
	.assignment-group-row td { background:#eef4ff !important; color:#274c77; font-weight:700; border-top:2px solid #cbdcf5 !important; }
	@media (max-width:1100px) { .filter-controls, .filter-controls.show-advanced { grid-template-columns:repeat(2,minmax(0,1fr)); } }
	@media (max-width:991px) { .assignment-summary-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
	@media (max-width:575px) { .assignment-summary-grid { grid-template-columns:1fr; } .assignment-toolbar { align-items:stretch; flex-direction:column; } }

	@media (max-width: 768px) {
		.filter-controls {
			grid-template-columns: 1fr;
		}

		.filter-group {
			width: 100%;
		}

		.btn-group-actions {
			width: 100%;
			align-items: stretch;
			flex-direction: column;
		}

		.btn-group-actions .btn {
			font-size: 0.8rem;
			padding: 6px 12px;
			flex: 1;
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
						$selected_year_is_active = false;
						
						$years_query = $conn->query("SELECT * FROM academic_year WHERE school_id = $school_id ORDER BY is_active DESC, start_date DESC");
						while ($year = $years_query->fetch_assoc()):
							if ((int)$year['id'] === (int)$selected_year) $selected_year_is_active = (int)$year['is_active'] === 1;
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
					<label for="assignment_view"><i class="fa fa-object-group"></i> Vista:</label>
					<select id="assignment_view" class="form-control">
						<option value="detail">Detalle</option>
						<option value="teacher">Agrupar por docente</option>
						<option value="course">Agrupar por curso</option>
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
					<button class="btn btn-info" id="copy_from_previous_year" title="Copiar asignaciones desde un año específico">
						<i class="fa fa-copy"></i> Copiar asignaciones
					</button>
					<button class="btn btn-warning" id="clean_teacher_courses" title="Limpiar todas las asignaciones">
						<i class="fa fa-broom"></i> Limpiar
					</button>
					<button class="btn btn-light border" id="reset_assignment_filters" title="Restablecer filtros"><i class="fa fa-undo"></i> Restablecer</button>
				</div>
			</div>
		</div>
	</div>
	<?php if (empty($selected_year_is_active)): ?><div class="alert alert-secondary py-2"><i class="fa fa-lock mr-2"></i>Este año académico está cerrado. Puedes consultar sus asignaciones, pero no modificarlas.</div><?php endif; ?>

	<?php
	$summary = ['assignments' => 0, 'teachers' => 0, 'without_courses' => 0, 'courses' => 0, 'unassigned_courses' => 0];
	$summary_query = $conn->query("SELECT COUNT(*) assignments, COUNT(DISTINCT teacher_id) teachers, COUNT(DISTINCT course_id) courses FROM teacher_courses WHERE school_id = " . (int)$school_id . " AND academic_year_id = " . (int)$current_year);
	if ($summary_query) $summary = array_merge($summary, $summary_query->fetch_assoc());
	$without_query = $conn->query("SELECT COUNT(*) total FROM teacher t WHERE t.school_id = " . (int)$school_id . " AND t.status = 'Activo' AND NOT EXISTS (SELECT 1 FROM teacher_courses tc WHERE tc.teacher_id=t.id AND tc.school_id=t.school_id AND tc.academic_year_id=" . (int)$current_year . ")");
	if ($without_query) $summary['without_courses'] = (int)$without_query->fetch_assoc()['total'];
	$unassigned_query = $conn->query("SELECT COUNT(*) total FROM academic_courses ac WHERE ac.school_id = " . (int)$school_id . " AND NOT EXISTS (SELECT 1 FROM teacher_courses tc WHERE tc.course_id=ac.id AND tc.school_id=ac.school_id AND tc.academic_year_id=" . (int)$current_year . ")");
	if ($unassigned_query) $summary['unassigned_courses'] = (int)$unassigned_query->fetch_assoc()['total'];
	?>
	<div class="assignment-summary-grid">
		<div class="assignment-summary-card summary-assignments"><div class="assignment-summary-icon"><i class="fa fa-list-check"></i></div><div><div class="assignment-summary-value" id="summary_assignments"><?php echo (int)$summary['assignments']; ?></div><div class="assignment-summary-label">Asignaciones del año</div></div></div>
		<div class="assignment-summary-card summary-teachers"><div class="assignment-summary-icon"><i class="fa fa-chalkboard-teacher"></i></div><div><div class="assignment-summary-value" id="summary_teachers"><?php echo (int)$summary['teachers']; ?></div><div class="assignment-summary-label">Docentes con cursos</div></div></div>
		<div class="assignment-summary-card summary-pending"><div class="assignment-summary-icon"><i class="fa fa-user-clock"></i></div><div><div class="assignment-summary-value" id="summary_without_courses"><?php echo (int)$summary['without_courses']; ?></div><div class="assignment-summary-label">Docentes activos sin cursos</div></div></div>
		<div class="assignment-summary-card summary-courses" id="open_unassigned_courses" role="button" title="Ver cursos sin docente"><div class="assignment-summary-icon"><i class="fa fa-book-open"></i></div><div><div class="assignment-summary-value" id="summary_unassigned_courses"><?php echo (int)$summary['unassigned_courses']; ?></div><div class="assignment-summary-label">Cursos sin docente</div></div></div>
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
					<div id="assignment_bulk_toolbar" class="assignment-toolbar alert alert-light border">
						<div><strong id="assignment_selected_count">0</strong> asignaciones seleccionadas</div>
						<div><button type="button" id="export_selected_assignments" class="btn btn-outline-success btn-sm mr-2"><i class="fa fa-file-csv mr-1"></i>Exportar seleccionadas</button><button type="button" id="open_bulk_assignment_actions" class="btn btn-primary btn-sm"><i class="fa fa-tasks mr-1"></i>Acciones masivas</button></div>
					</div>
					<div class="table-responsive">
						<table id="teacher_courses_table" class="table table-striped table-bordered table-hover">
							<thead>
								<tr>
									<th class="text-center" style="width:36px;"><input type="checkbox" id="assignment_check_all"></th>
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
									<td class="text-center"><input type="checkbox" class="assignment-row-check" value="<?php echo (int)$row['id']; ?>"></td>
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
							elseif (false): // DataTables muestra su propio estado vacío sin usar colspan
								$year_info_query = $conn->query("SELECT year, description FROM academic_year WHERE id = $current_year AND school_id = $school_id");
								$year_info = $year_info_query && $year_info_query->num_rows > 0 ? $year_info_query->fetch_assoc() : null;
								$year_display = $year_info ? $year_info['year'] . ' - ' . $year_info['description'] : 'el año seleccionado';
							?>
								<tr>
									<td colspan="8" class="text-center py-5">
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

<div class="modal fade" id="bulkAssignmentActionsModal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog" role="document"><div class="modal-content">
		<div class="modal-header"><h5 class="modal-title"><i class="fa fa-tasks text-primary mr-2"></i>Acciones masivas</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
		<div class="modal-body"><div class="alert alert-info py-2"><strong id="bulk_assignment_count">0</strong> asignaciones seleccionadas</div>
			<div class="form-group"><label>Acción</label><select id="bulk_assignment_action" class="form-control"><option value="unassign">Desasignar</option><option value="transfer">Transferir a otro docente</option><option value="classroom">Cambiar grado y sección</option></select></div>
			<div id="bulk_transfer_fields" class="d-none"><div class="form-group"><label>Docente destino</label><select id="bulk_target_teacher" class="form-control"><option value="">Seleccione...</option><?php
			$bulk_teachers=$conn->query("SELECT id,name FROM teacher WHERE school_id=".(int)$school_id." AND status='Activo' ORDER BY name"); while($bulk_teachers && ($bulk_teacher=$bulk_teachers->fetch_assoc())): ?><option value="<?php echo (int)$bulk_teacher['id']; ?>"><?php echo htmlspecialchars($bulk_teacher['name'],ENT_QUOTES,'UTF-8'); ?></option><?php endwhile; ?></select></div></div>
			<div id="bulk_classroom_fields" class="d-none"><div class="row"><div class="col-6"><div class="form-group"><label>Nuevo grado</label><select id="bulk_new_grade" class="form-control"><option value="">Seleccione...</option><?php foreach(['1°','2°','3°','4°','5°','6°'] as $bulk_grade): ?><option><?php echo $bulk_grade; ?></option><?php endforeach; ?></select></div></div><div class="col-6"><div class="form-group"><label>Nueva sección</label><select id="bulk_new_section" class="form-control"><option value="">Seleccione...</option><?php foreach(['U','A','B','C','D','E','F'] as $bulk_section): ?><option><?php echo $bulk_section; ?></option><?php endforeach; ?></select></div></div></div></div>
			<div id="bulk_assignment_error" class="alert alert-danger d-none"></div>
		</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button><button type="button" id="apply_bulk_assignment_action" class="btn btn-primary">Aplicar</button></div>
	</div></div>
</div>

<div class="modal fade" id="replaceTeacherCoursesModal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog" role="document"><div class="modal-content">
		<div class="modal-header"><h5 class="modal-title"><i class="fa fa-people-arrows text-primary mr-2"></i>Reemplazar docente</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
		<div class="modal-body">
			<div class="alert alert-warning py-2">Se transferirán todas las asignaciones del docente de origen en el año seleccionado. Las duplicadas serán consolidadas.</div>
			<div class="form-group"><label>Docente de origen</label><select id="replace_from_teacher" class="form-control"><option value="">Seleccione...</option><?php
			$assigned_teachers = $conn->query("SELECT DISTINCT t.id,t.name,t.status FROM teacher_courses tc INNER JOIN teacher t ON t.id=tc.teacher_id WHERE tc.school_id=".(int)$school_id." AND tc.academic_year_id=".(int)$current_year." ORDER BY t.name");
			while ($assigned_teachers && ($assigned_teacher=$assigned_teachers->fetch_assoc())): ?><option value="<?php echo (int)$assigned_teacher['id']; ?>"><?php echo htmlspecialchars($assigned_teacher['name'] . ($assigned_teacher['status']==='Activo'?'':' (Inactivo)'), ENT_QUOTES, 'UTF-8'); ?></option><?php endwhile; ?></select></div>
			<div class="form-group"><label>Docente reemplazante</label><select id="replace_to_teacher" class="form-control"><option value="">Seleccione...</option><?php
			$active_teachers = $conn->query("SELECT id,name FROM teacher WHERE school_id=".(int)$school_id." AND status='Activo' ORDER BY name");
			while ($active_teachers && ($active_teacher=$active_teachers->fetch_assoc())): ?><option value="<?php echo (int)$active_teacher['id']; ?>"><?php echo htmlspecialchars($active_teacher['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endwhile; ?></select></div>
			<div id="replace_teacher_courses_error" class="alert alert-danger d-none"></div>
		</div>
		<div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button><button type="button" id="confirm_replace_teacher" class="btn btn-primary"><i class="fa fa-exchange-alt mr-1"></i>Transferir asignaciones</button></div>
	</div></div>
</div>

<div class="modal fade" id="unassignedCoursesModal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg" role="document"><div class="modal-content">
		<div class="modal-header"><h5 class="modal-title"><i class="fa fa-book-open text-info mr-2"></i>Cursos sin docente</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
		<div class="modal-body"><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Curso</th><th>Nivel</th><th class="text-center">Acción</th></tr></thead><tbody id="unassigned_courses_body">
		<?php
		$unassigned_courses = $conn->query("SELECT ac.id, ac.name, ac.level FROM academic_courses ac WHERE ac.school_id=" . (int)$school_id . " AND NOT EXISTS (SELECT 1 FROM teacher_courses tc WHERE tc.course_id=ac.id AND tc.school_id=ac.school_id AND tc.academic_year_id=" . (int)$current_year . ") ORDER BY ac.level, ac.name");
		if ($unassigned_courses && $unassigned_courses->num_rows): while ($unassigned = $unassigned_courses->fetch_assoc()): ?>
		<tr><td><?php echo htmlspecialchars($unassigned['name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($unassigned['level'], ENT_QUOTES, 'UTF-8'); ?></td><td class="text-center"><button type="button" class="btn btn-primary btn-sm assign_unassigned_course" data-course-id="<?php echo (int)$unassigned['id']; ?>"><i class="fa fa-user-plus mr-1"></i>Asignar</button></td></tr>
		<?php endwhile; else: ?><tr><td colspan="3" class="text-center text-success py-4"><i class="fa fa-check-circle mr-1"></i>Todos los cursos tienen al menos un docente.</td></tr><?php endif; ?>
		</tbody></table></div><small class="text-muted d-block mt-3">Un curso se considera pendiente cuando no tiene ninguna asignación en el año seleccionado.</small></div>
	</div></div>
</div>

<div class="modal fade" id="copyTeacherCoursesModal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog" role="document"><div class="modal-content">
		<div class="modal-header"><h5 class="modal-title"><i class="fa fa-copy text-info mr-2"></i>Copiar asignaciones</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
		<div class="modal-body">
			<div class="alert alert-info py-2">Las asignaciones se copiarán al año seleccionado actualmente: <strong id="copy_target_year_label"></strong>.</div>
			<div class="form-group"><label for="copy_source_year"><strong>Año de origen</strong></label>
				<select id="copy_source_year" class="form-control">
					<option value="">Seleccione el año que desea copiar...</option>
					<?php
					$copy_years = $conn->query("SELECT id, year, description FROM academic_year WHERE school_id = " . (int)$school_id . " AND id != " . (int)$current_year . " ORDER BY start_date DESC, year DESC");
					while ($copy_years && ($copy_year = $copy_years->fetch_assoc())):
					?>
					<option value="<?php echo (int)$copy_year['id']; ?>"><?php echo htmlspecialchars($copy_year['year'] . (!empty($copy_year['description']) ? ' - ' . $copy_year['description'] : ''), ENT_QUOTES, 'UTF-8'); ?></option>
					<?php endwhile; ?>
				</select>
			</div>
			<small class="text-muted">Las asignaciones que ya existan en el destino serán omitidas.</small>
			<div id="copy_teacher_courses_error" class="alert alert-danger mt-3 d-none"></div>
		</div>
		<div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button><button type="button" id="confirm_copy_teacher_courses" class="btn btn-info"><i class="fa fa-copy mr-1"></i>Copiar</button></div>
	</div></div>
</div>

<script>
$(document).ready(function() {
    var groupingMode = 'detail';
	var selectedYearIsActive = <?php echo !empty($selected_year_is_active) ? 'true' : 'false'; ?>;
    var advancedFilters = $('#level_filter, #grado_filter, #seccion_filter').closest('.filter-group');
    advancedFilters.addClass('advanced-filter-group');
    if (!$('#toggle_assignment_filters').length) {
        $('<button type="button" class="btn btn-light border" id="toggle_assignment_filters"><i class="fa fa-sliders-h"></i> Más filtros</button>').insertBefore('#reset_assignment_filters');
    }
	if (!$('#replace_teacher_courses').length) {
		$('<button type="button" class="btn btn-outline-primary" id="replace_teacher_courses"><i class="fa fa-exchange-alt"></i> Reemplazar docente</button>').insertAfter('#copy_from_previous_year');
	}
	if (!$('#export_teacher_courses_view').length) {
		$('<button type="button" class="btn btn-outline-success" id="export_teacher_courses_view"><i class="fa fa-file-csv"></i> Exportar vista</button>').insertAfter('#replace_teacher_courses');
	}
	if (!selectedYearIsActive) {
		$('#new_teacher_course, #copy_from_previous_year, #replace_teacher_courses, #clean_teacher_courses, #bulk_unassign_courses, .edit_tc, .delete_tc, .assignment-row-check, #assignment_check_all, .assign_unassigned_course').prop('disabled', true);
	}

    $('#toggle_assignment_filters').on('click', function() {
        var panel = $('.filter-controls');
        var expanded = !panel.hasClass('show-advanced');
        panel.toggleClass('show-advanced', expanded);
        $(this).toggleClass('active', expanded).html('<i class="fa fa-sliders-h"></i> ' + (expanded ? 'Menos filtros' : 'Más filtros'));
    });
    // Inicializar Select2 para docente y curso
    if ($.fn.select2) {
        $('#teacher_filter').select2({ placeholder: 'Seleccionar docente...', allowClear: true, width: '100%', language: 'es' });
        $('#course_filter').select2({ placeholder: 'Seleccionar curso...', allowClear: true, width: '100%', language: 'es' });
    }

    // Inicializar DataTables
    var table = $('#teacher_courses_table').DataTable({
        "language": {
            "emptyTable": "No hay asignaciones para el año seleccionado.",
            "zeroRecords": "No se encontraron asignaciones con estos filtros.",
            "search": "Buscar:",
            "lengthMenu": "Mostrar _MENU_ asignaciones",
            "info": "Mostrando _START_ a _END_ de _TOTAL_ asignaciones",
            "infoEmpty": "No hay asignaciones para mostrar",
            "paginate": { "previous": "Anterior", "next": "Siguiente" }
        },
        "pageLength": 10,
        "lengthMenu": [[10, 25, 50, 100], [10, 25, 50, 100]],
        "order": [[2, 'asc'], [3, 'asc']],
        "columnDefs": [{ "orderable": false, "targets": [0, 7] }],
        "drawCallback": function() {
            var api = this.api();
            $('#teacher_courses_table tbody .assignment-group-row').remove();
            if (groupingMode !== 'detail') {
                var groupColumn = groupingMode === 'teacher' ? 2 : 3;
                var lastGroup = null;
                api.rows({ page: 'current', search: 'applied' }).every(function() {
                    var rowNode = this.node();
                    var groupName = $('<div>').html(api.cell(this.index(), groupColumn).data() || 'Sin especificar').text();
                    if (groupName !== lastGroup) {
                        $(rowNode).before('<tr class="assignment-group-row"><td colspan="8"><i class="fa ' + (groupingMode === 'teacher' ? 'fa-user' : 'fa-book') + ' mr-2"></i>' + $('<div>').text(groupName).html() + '</td></tr>');
                        lastGroup = groupName;
                    }
                });
            }
            if (typeof window.updateAssignmentSelection === 'function') window.updateAssignmentSelection();
        }
    });

	window.refreshTeacherCourses = function() {
		return $.getJSON('teacher_courses.php', { partial:'course_data', year:$('#academic_year_filter').val() }).done(function(resp) {
			if (!resp || resp.status != 1) { alert_toast((resp && resp.message) || 'No se pudo actualizar el módulo.', 'danger'); return; }
			var currentPage = table.page();
			table.clear();
			var newRows = $(resp.rows || '').filter('tr');
			if (newRows.length) table.rows.add(newRows);
			table.draw(false);
			if (currentPage < table.page.info().pages) table.page(currentPage).draw(false);
			$('#summary_assignments').text(resp.summary.assignments || 0);
			$('#summary_teachers').text(resp.summary.teachers || 0);
			$('#summary_without_courses').text(resp.summary.without_courses || 0);
			$('#summary_unassigned_courses').text(resp.summary.unassigned_courses || 0);
			$('#unassigned_courses_body').html(resp.pending_rows || '');
			$('#assignment_check_all').prop('checked', false);
			if (typeof window.updateAssignmentSelection === 'function') window.updateAssignmentSelection();
		});
	};
	$(document).off('teacher:courses-changed.teacherCourses').on('teacher:courses-changed.teacherCourses', function(){ window.refreshTeacherCourses().always(end_load); });

    function applyAllFilters() {
        var teacherFilter = $('#teacher_filter').val();
        var courseFilter = $('#course_filter').val();
        var levelFilter = $('#level_filter').val();
        var gradoFilter = $('#grado_filter').val();
        var seccionFilter = $('#seccion_filter').val();
        
        // Aplicar filtros de búsqueda en cada columna
        table.column(2).search(teacherFilter || '');
        table.column(3).search(courseFilter || '');
        table.column(4).search(levelFilter || '');
        table.column(5).search(gradoFilter || '');
        table.column(6).search(seccionFilter || '').draw();
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

    $('#assignment_view').on('change', function() {
        groupingMode = this.value;
        if (groupingMode === 'teacher') table.order([[2, 'asc'], [3, 'asc']]);
        else if (groupingMode === 'course') table.order([[3, 'asc'], [2, 'asc']]);
        else table.order([[2, 'asc'], [3, 'asc']]);
        table.draw();
    });

    $('#reset_assignment_filters').on('click', function() {
        $('#teacher_filter, #course_filter').val(null).trigger('change.select2');
        $('#level_filter, #grado_filter, #seccion_filter').val('');
        table.search('').columns().search('').draw();
		$('.filter-controls').removeClass('show-advanced');
		$('#toggle_assignment_filters').removeClass('active').html('<i class="fa fa-sliders-h"></i> Más filtros');
    });

    function selectedAssignmentIds() {
        return $('.assignment-row-check:checked').map(function(){ return parseInt(this.value, 10); }).get();
    }

    window.getSelectedTeacherCourseIds = selectedAssignmentIds;
	window.getFilteredTeacherCourseIds = function() {
		return table.rows({search:'applied'}).nodes().to$().find('.assignment-row-check').map(function(){ return parseInt(this.value,10); }).get();
	};
    window.updateAssignmentSelection = function() {
        var count = selectedAssignmentIds().length;
        $('#assignment_selected_count').text(count);
        $('#assignment_bulk_toolbar').toggleClass('is-visible', count > 0);
        var visible = $('.assignment-row-check:visible');
        $('#assignment_check_all').prop('checked', visible.length > 0 && visible.filter(':checked').length === visible.length);
    };

    $(document).off('change.teacherCourses', '.assignment-row-check').on('change.teacherCourses', '.assignment-row-check', updateAssignmentSelection);
    $(document).off('change.teacherCourses', '#assignment_check_all').on('change.teacherCourses', '#assignment_check_all', function(){
        $('.assignment-row-check:visible').prop('checked', this.checked);
        updateAssignmentSelection();
    });
    $(document).off('click.teacherCourses', '#bulk_unassign_courses').on('click.teacherCourses', '#bulk_unassign_courses', function(){
        var count = selectedAssignmentIds().length;
        if (count) _conf('¿Deseas desasignar las ' + count + ' asignaciones seleccionadas?', 'bulk_unassign_teacher_courses', []);
    });

	$(document).off('click.teacherCourses', '#export_selected_assignments').on('click.teacherCourses', '#export_selected_assignments', function(){
		var ids=selectedAssignmentIds(); if(ids.length) window.location.assign('export_teacher_courses.php?year='+encodeURIComponent($('#academic_year_filter').val())+'&ids='+encodeURIComponent(ids.join(',')));
	});
	$(document).off('click.teacherCourses', '#export_teacher_courses_view').on('click.teacherCourses', '#export_teacher_courses_view', function(){
		var ids=window.getFilteredTeacherCourseIds();
		if(!ids.length){ alert_toast('No hay asignaciones para exportar con estos filtros.','warning'); return; }
		window.location.assign('export_teacher_courses.php?year='+encodeURIComponent($('#academic_year_filter').val())+'&ids='+encodeURIComponent(ids.join(',')));
	});
	$(document).off('click.teacherCourses', '#open_bulk_assignment_actions').on('click.teacherCourses', '#open_bulk_assignment_actions', function(){
		var ids=selectedAssignmentIds(); if(!ids.length)return;
		$('#bulk_assignment_count').text(ids.length); $('#bulk_assignment_action').val('unassign').trigger('change');
		$('#bulk_target_teacher,#bulk_new_grade,#bulk_new_section').val(''); $('#bulk_assignment_error').addClass('d-none').empty(); $('#bulkAssignmentActionsModal').modal('show');
	});
	$(document).off('change.teacherCourses', '#bulk_assignment_action').on('change.teacherCourses', '#bulk_assignment_action', function(){
		$('#bulk_transfer_fields').toggleClass('d-none',this.value!=='transfer'); $('#bulk_classroom_fields').toggleClass('d-none',this.value!=='classroom');
	});
	$(document).off('click.teacherCourses', '#apply_bulk_assignment_action').on('click.teacherCourses', '#apply_bulk_assignment_action', function(){
		var ids=selectedAssignmentIds(), action=$('#bulk_assignment_action').val(), target=$('#bulk_target_teacher').val(), grade=$('#bulk_new_grade').val(), section=$('#bulk_new_section').val();
		if(!ids.length){ $('#bulkAssignmentActionsModal').modal('hide'); return; }
		if(action==='transfer'&&!target){ $('#bulk_assignment_error').removeClass('d-none').text('Selecciona el docente destino.'); return; }
		if(action==='classroom'&&(!grade||!section)){ $('#bulk_assignment_error').removeClass('d-none').text('Selecciona el nuevo grado y sección.'); return; }
		if(action==='unassign'&&!window.confirm('¿Deseas desasignar las '+ids.length+' asignaciones seleccionadas?')) return;
		var button=$(this).prop('disabled',true); start_load();
		$.ajax({url:'ajax.php?action=bulk_update_teacher_courses',method:'POST',dataType:'json',data:{ids:ids.join(','),academic_year_id:$('#academic_year_filter').val(),bulk_action:action,target_teacher_id:target,new_grade:grade,new_section:section},
			success:function(resp){if(resp.status==1){$('#bulkAssignmentActionsModal').modal('hide');alert_toast(resp.message,'success');window.refreshTeacherCourses().always(end_load);}else{$('#bulk_assignment_error').removeClass('d-none').text(resp.message||'No se pudo completar la acción.');end_load();}},
			error:function(xhr){console.error(xhr.responseText);$('#bulk_assignment_error').removeClass('d-none').text('Error del servidor al procesar la acción.');end_load();},complete:function(){button.prop('disabled',false);}});
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
    $('#copy_target_year_label').text(yearText);
    $('#copy_source_year').val('');
    $('#copy_teacher_courses_error').addClass('d-none').empty();
    $('#copyTeacherCoursesModal').modal('show');
});

$(document).off('click.teacherCourses', '#open_unassigned_courses').on('click.teacherCourses', '#open_unassigned_courses', function() {
	$('#unassignedCoursesModal').modal('show');
});

$(document).off('click.teacherCourses', '.assign_unassigned_course').on('click.teacherCourses', '.assign_unassigned_course', function() {
	var courseId = $(this).attr('data-course-id');
	$('#unassignedCoursesModal').modal('hide');
	setTimeout(function(){
		uni_modal('Asignar docente al curso', 'manage_teacher_course.php?academic_year_id=' + encodeURIComponent($('#academic_year_filter').val()) + '&course_id=' + encodeURIComponent(courseId), 'mid-large');
	}, 200);
});

$(document).off('click.teacherCourses', '#replace_teacher_courses').on('click.teacherCourses', '#replace_teacher_courses', function() {
	$('#replace_from_teacher, #replace_to_teacher').val('');
	$('#replace_teacher_courses_error').addClass('d-none').empty();
	$('#replaceTeacherCoursesModal').modal('show');
});

$(document).off('click.teacherCourses', '#confirm_replace_teacher').on('click.teacherCourses', '#confirm_replace_teacher', function() {
	var fromId = parseInt($('#replace_from_teacher').val(), 10) || 0;
	var toId = parseInt($('#replace_to_teacher').val(), 10) || 0;
	if (!fromId || !toId || fromId === toId) {
		$('#replace_teacher_courses_error').removeClass('d-none').text('Selecciona dos docentes diferentes.'); return;
	}
	var button = $(this).prop('disabled', true); start_load();
	$.ajax({ url:'ajax.php?action=replace_teacher_courses', method:'POST', dataType:'json', data:{from_teacher_id:fromId,to_teacher_id:toId,academic_year_id:$('#academic_year_filter').val()},
		success:function(resp){ if(resp.status==1){ $('#replaceTeacherCoursesModal').modal('hide'); alert_toast(resp.message,'success'); window.refreshTeacherCourses().always(end_load); } else { $('#replace_teacher_courses_error').removeClass('d-none').text(resp.message||'No se pudo realizar el reemplazo.'); end_load(); } },
		error:function(xhr){ $('#replace_teacher_courses_error').removeClass('d-none').text('Error del servidor al reemplazar al docente.'); console.error(xhr.responseText); end_load(); },
		complete:function(){ button.prop('disabled',false); }
	});
});

$('#copy_from_previous_year').html('<i class="fa fa-copy"></i> Copiar asignaciones').attr('title', 'Copiar asignaciones desde un año específico');

$(document).off('click.teacherCourses', '#confirm_copy_teacher_courses').on('click.teacherCourses', '#confirm_copy_teacher_courses', function() {
    var sourceYear = parseInt($('#copy_source_year').val(), 10) || 0;
    var targetYear = parseInt($('#academic_year_filter').val(), 10) || 0;
    if (!sourceYear) {
        $('#copy_teacher_courses_error').removeClass('d-none').text('Selecciona el año de origen.');
        return;
    }
    copy_from_previous_year(targetYear, sourceYear);
});

$(document).on('click', '.edit_tc', function() {
    uni_modal("Editar Asignación", "manage_teacher_course.php?id=" + $(this).attr('data-id'), "mid-large");
});

$(document).on('submit', '#manage-teacher-course[data-inline-page="1"]', function(e) {
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
				window.refreshTeacherCourses().always(end_load);
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

$(document).off('click.teacherCourses', '.delete_tc').on('click.teacherCourses', '.delete_tc', function() {
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
				window.refreshTeacherCourses().always(end_load);
			} else {
				alert_toast(resp.message || "Error al eliminar.", 'danger');
				end_load();
			}
		}
	});
}

function bulk_unassign_teacher_courses() {
	var ids = typeof window.getSelectedTeacherCourseIds === 'function' ? window.getSelectedTeacherCourseIds() : [];
	if (!ids.length) return;
	start_load();
	$.ajax({
		url: 'ajax.php?action=bulk_delete_teacher_courses',
		method: 'POST',
		data: { ids: ids.join(','), academic_year_id: $('#academic_year_filter').val() },
		dataType: 'json',
		success: function(resp) {
			if (resp.status == 1) {
				alert_toast(resp.message || 'Asignaciones desasignadas.', 'success');
				window.refreshTeacherCourses().always(end_load);
			} else {
				alert_toast(resp.message || 'No se pudieron desasignar.', 'danger');
				end_load();
			}
		},
		error: function(xhr) {
			console.error(xhr.responseText);
			alert_toast('Error del servidor al procesar la acción masiva.', 'danger');
			end_load();
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
				window.refreshTeacherCourses().always(end_load);
			} else {
				alert_toast(resp.message || "Error.", 'danger');
				end_load();
			}
		}
	});
}

function copy_from_previous_year(currentYearId, sourceYearId) {
    start_load();
	$('#confirm_copy_teacher_courses').prop('disabled', true);
    $.ajax({
        url: 'ajax.php?action=copy_teacher_courses_from_previous_year',
        method: 'POST',
        data: { current_year_id: currentYearId, source_year_id: sourceYearId, confirm: true },
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                var message = "Se copiaron " + (resp.copied || 0) + " asignaciones.";
				$('#copyTeacherCoursesModal').modal('hide');
                alert_toast(message, 'success');
				window.refreshTeacherCourses().always(end_load);
            } else {
				$('#copy_teacher_courses_error').removeClass('d-none').text(resp.message || 'No se pudieron copiar las asignaciones.');
                end_load();
            }
		},
		error: function(xhr) {
			$('#copy_teacher_courses_error').removeClass('d-none').text((xhr.responseJSON && xhr.responseJSON.message) || 'Error del servidor al copiar las asignaciones.');
			end_load();
		},
		complete: function() { $('#confirm_copy_teacher_courses').prop('disabled', false); }
    });
}
</script>
