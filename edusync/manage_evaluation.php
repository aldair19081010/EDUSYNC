
<?php
// Mostrar todos los errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db_connect.php';
include 'session_config.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$is_teacher = (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 2);
$is_admin = (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 1);
$from_notification = $is_admin && isset($_GET['id']) && (isset($_GET['from']) && $_GET['from'] == 'notifications');
$teacher_id = $_SESSION['login_teacher_id'] ?? null;
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$title = $description = $type = '';
$selected_course_id = '';
$selected_grado = '';
$selected_seccion = '';
$selected_teacher_course_id = '';
$bimestre = (!$id && isset($_GET['bimestre']) && in_array((string)$_GET['bimestre'], ['1','2','3','4'], true)) ? (string)$_GET['bimestre'] : '';

// Depuración removida (ya solucionado)
// Obtener el año académico activo para asignar a la evaluación
$school_id = $_SESSION['login_school_id'] ?? 1;
$academic_year_id = 0;
$academic_year_name = '';
$academic_year_query = $conn->query("SELECT id, year, description FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
if($academic_year_query && $academic_year_query->num_rows > 0) {
    $academic_year = $academic_year_query->fetch_assoc();
    $academic_year_id = $academic_year['id'];
    $academic_year_name = $academic_year['year'] . ($academic_year['description'] ? ' - ' . $academic_year['description'] : '');
} else {
    // Si no hay año académico activo, mostrar advertencia
    $academic_year_name = 'Sin año académico activo';
}

if (!$id && $is_teacher && $teacher_id && !empty($_GET['teacher_course_id'])) {
	$requested_teacher_course_id = intval($_GET['teacher_course_id']);
	$tc_stmt = $conn->prepare("SELECT tc.id,tc.course_id,tc.grado,tc.seccion,tc.academic_year_id,ac.name course_name,ac.level FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=tc.academic_year_id WHERE tc.id=? AND tc.teacher_id=? AND tc.school_id=? AND ay.is_active=1 AND ac.is_active=1 AND COALESCE(ac.course_status,'Activo')='Activo' LIMIT 1");
	if ($tc_stmt) {
		$tc_stmt->bind_param('iii', $requested_teacher_course_id, $teacher_id, $school_id);
		$tc_stmt->execute();
		$tc_row = $tc_stmt->get_result()->fetch_assoc();
		$tc_stmt->close();
		if ($tc_row) {
			$selected_teacher_course_id = (int)$tc_row['id'];
			$selected_course_id = (int)$tc_row['course_id'];
			$selected_course_name = $tc_row['course_name'];
			$selected_level = $tc_row['level'];
			$selected_grado = $tc_row['grado'];
			$selected_seccion = $tc_row['seccion'];
			$academic_year_id = (int)$tc_row['academic_year_id'];
		}
	}
}

if ($id) {
	$q = $conn->query("SELECT e.* FROM evaluations e INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id WHERE e.id=$id AND e.teacher_id=".intval($teacher_id)." AND tc.teacher_id=".intval($teacher_id)." AND tc.school_id=".intval($school_id)." LIMIT 1");
	if ($q && $q->num_rows) {
		$row = $q->fetch_assoc();
		$title = $row['title'];
		$description = $row['description'];
		$type = $row['type'];
		$selected_teacher_course_id = $row['teacher_course_id'];
		$bimestre = $row['bimestre'] ?? '';
		
		// Al editar, usar el año académico de la evaluación existente
		if (isset($row['academic_year_id']) && $row['academic_year_id']) {
			$academic_year_id = $row['academic_year_id'];
			// Obtener el año y descripción del año académico de la evaluación
			$ay_query = $conn->query("SELECT year, description FROM academic_year WHERE id = $academic_year_id");
			if ($ay_query && $ay_query->num_rows > 0) {
				$ay_row = $ay_query->fetch_assoc();
				$academic_year_name = $ay_row['year'] . ($ay_row['description'] ? ' - ' . $ay_row['description'] : '');
			}
		}
		
		// Obtener detalles del curso relacionado
		if ($selected_teacher_course_id) {
			$tc_query = $conn->query("SELECT tc.*, ac.id as course_id, ac.name as course_name, ac.level 
                                   FROM teacher_courses tc 
                                   INNER JOIN academic_courses ac ON tc.course_id = ac.id 
                                   WHERE tc.id = $selected_teacher_course_id");
			if ($tc_query && $tc_query->num_rows) {
				$tc_row = $tc_query->fetch_assoc();
				$selected_course_id = $tc_row['course_id'];
				$selected_course_name = $tc_row['course_name'];
				$selected_level = $tc_row['level'];
				$selected_grado = $tc_row['grado'];
				$selected_seccion = $tc_row['seccion'];
			}
		}
	} else {
		echo "<div class='alert alert-danger mb-0'>La evaluación no existe o no te pertenece.</div>";
		exit;
	}
}
// Cargar cursos, grados y secciones asignados al docente
$cursos = [];
$curso_grados = [];
$curso_grado_secciones = [];
$niveles_por_curso = [];

// Agrupar cursos por nombre
$cursos_por_nombre = [];
$niveles_por_nombre = [];
$grados_por_nombre_nivel = [];
$secciones_por_nombre_nivel_grado = [];	

if ($is_teacher && $teacher_id) {
	// Filtrar cursos por año académico activo
	$year_filter = $academic_year_id ? "AND tc.academic_year_id = $academic_year_id" : "";
	
	$q = $conn->query("SELECT ac.id, ac.name, ac.level, tc.grado, tc.seccion, tc.id as teacher_course_id, tc.academic_year_id
		FROM teacher_courses tc
		INNER JOIN academic_courses ac ON ac.id = tc.course_id
		WHERE tc.teacher_id = $teacher_id AND tc.school_id = $school_id $year_filter
		ORDER BY ac.name, ac.level, tc.grado, tc.seccion");
	while ($row = $q->fetch_assoc()) {
		$curso_id = $row['id'];
		$curso_nombre = $row['name'];
		$nivel = $row['level'];
		$grado = $row['grado'];
		$seccion = $row['seccion'] ?? 'U';
		// Agrupar IDs por nombre
		if (!isset($cursos_por_nombre[$curso_nombre])) $cursos_por_nombre[$curso_nombre] = [];
		if (!in_array($curso_id, $cursos_por_nombre[$curso_nombre])) $cursos_por_nombre[$curso_nombre][] = $curso_id;
		// Niveles por nombre
		if (!isset($niveles_por_nombre[$curso_nombre])) $niveles_por_nombre[$curso_nombre] = [];
		if (!in_array($nivel, $niveles_por_nombre[$curso_nombre])) $niveles_por_nombre[$curso_nombre][] = $nivel;
		// Grados por nombre y nivel
		if (!isset($grados_por_nombre_nivel[$curso_nombre])) $grados_por_nombre_nivel[$curso_nombre] = [];
		if (!isset($grados_por_nombre_nivel[$curso_nombre][$nivel])) $grados_por_nombre_nivel[$curso_nombre][$nivel] = [];
		if (!in_array($grado, $grados_por_nombre_nivel[$curso_nombre][$nivel])) $grados_por_nombre_nivel[$curso_nombre][$nivel][] = $grado;
		// Secciones por nombre, nivel y grado
		if (!isset($secciones_por_nombre_nivel_grado[$curso_nombre])) $secciones_por_nombre_nivel_grado[$curso_nombre] = [];
		if (!isset($secciones_por_nombre_nivel_grado[$curso_nombre][$nivel])) $secciones_por_nombre_nivel_grado[$curso_nombre][$nivel] = [];
		if (!isset($secciones_por_nombre_nivel_grado[$curso_nombre][$nivel][$grado])) $secciones_por_nombre_nivel_grado[$curso_nombre][$nivel][$grado] = [];
		if (!in_array($seccion, $secciones_por_nombre_nivel_grado[$curso_nombre][$nivel][$grado])) $secciones_por_nombre_nivel_grado[$curso_nombre][$nivel][$grado][] = $seccion;
	}
}

// Consultar competencias por curso del docente
$competencias = [];
if ($is_teacher && $teacher_id) {
	// Obtener competencias generales por curso del docente
	$year_filter_comp = $academic_year_id > 0 ? "AND gcc.academic_year_id = $academic_year_id" : "";
	$q_comp = $conn->query("
		SELECT gcc.*, ac.name as course_name, a.name as area_name, a.color as area_color
		FROM general_course_competencies gcc
		JOIN academic_courses ac ON ac.id = gcc.course_id
		LEFT JOIN areas a ON a.id = ac.area_id
		WHERE gcc.teacher_id = $teacher_id AND gcc.is_active = 1 $year_filter_comp
		ORDER BY a.name, ac.name, gcc.name ASC
	");
	while ($row = $q_comp->fetch_assoc()) {
		$competencias[] = $row;
	}
}
// Si se está editando, cargar competencias ya asociadas
$selected_competencias = [];
$has_existing_grades = false;
if (!$id && isset($_GET['competencia_id']) && intval($_GET['competencia_id']) > 0) {
	$requested_competencia_id = intval($_GET['competencia_id']);
	foreach ($competencias as $comp) {
		if ((int)$comp['id'] === $requested_competencia_id && (int)$comp['course_id'] === (int)$selected_course_id) {
			$selected_competencias[] = $requested_competencia_id;
			break;
		}
	}
}
if ($id) {
	$q_sel = $conn->query("SELECT competencia_id FROM evaluation_competencias WHERE evaluation_id = $id");
	while ($row = $q_sel->fetch_assoc()) {
		$selected_competencias[] = $row['competencia_id'];
	}
	$grades_check = $conn->query("SELECT id FROM evaluation_grades WHERE evaluation_id = $id LIMIT 1");
	if ($grades_check && $grades_check->num_rows > 0) {
		$has_existing_grades = true;
	}
}
?>
<div class="container-fluid">
	<form id="manage-evaluation" method="post" action="#" onsubmit="return false;">
		<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
		<div class="card shadow mb-3">
			<div class="card-header py-3">
				<h6 class="m-0 font-weight-bold text-primary">
					<i class="fa fa-clipboard-list"></i>
					<?php 
					if ($from_notification) {
						echo 'Visualizar Evaluación';
					} else {
						echo $id ? 'Editar Evaluación' : 'Nueva Evaluación';
					}
					?>
				</h6>
			</div>
			<div class="card-body">
				<input type="hidden" name="id" value="<?php echo $id ?>">
				
				<div class="row">
					<div class="col-md-8 mb-3">
						<label for="title" class="small font-weight-bold"><i class="fa fa-pen-fancy"></i> Título de la Evaluación</label>
						<?php if ($from_notification): ?>
						<input type="text" name="title" id="title" class="form-control form-control-sm" value="<?php echo htmlspecialchars($title) ?>" readonly>
						<?php else: ?>
						<input type="text" name="title" id="title" class="form-control form-control-sm" value="<?php echo htmlspecialchars($title) ?>" placeholder="Ej: Examen de Mitad de Curso" required>
						<?php endif; ?>
					</div>
					<div class="col-md-4 mb-3">
						<label for="type" class="small font-weight-bold"><i class="fa fa-tags"></i> Tipo</label>
						<?php if ($from_notification): ?>
						<input type="text" class="form-control form-control-sm" value="<?php echo htmlspecialchars($type) ?>" readonly>
						<input type="hidden" name="type" value="<?php echo htmlspecialchars($type) ?>">
						<?php else: ?>
						<select name="type" id="type" class="form-control form-control-sm select2" required>
							<option value="">Seleccione un tipo</option>
							<option value="Examen" <?php echo ($type == 'Examen') ? 'selected' : '' ?>>Examen</option>
							<option value="Examen Parcial" <?php echo ($type == 'Examen Parcial') ? 'selected' : '' ?>>Examen Parcial</option>
							<option value="Examen Final" <?php echo ($type == 'Examen Final') ? 'selected' : '' ?>>Examen Final</option>
							<option value="Quiz" <?php echo ($type == 'Quiz') ? 'selected' : '' ?>>Quiz</option>
							<option value="Tarea" <?php echo ($type == 'Tarea') ? 'selected' : '' ?>>Tarea</option>
							<option value="Proyecto" <?php echo ($type == 'Proyecto') ? 'selected' : '' ?>>Proyecto</option>
							<option value="Exposición" <?php echo ($type == 'Exposición') ? 'selected' : '' ?>>Exposición</option>
							<option value="Trabajo en clase" <?php echo ($type == 'Trabajo en clase') ? 'selected' : '' ?>>Trabajo en clase</option>
							<option value="Participación" <?php echo ($type == 'Participación') ? 'selected' : '' ?>>Participación</option>
							<option value="Práctica de laboratorio" <?php echo ($type == 'Práctica de laboratorio') ? 'selected' : '' ?>>Práctica de laboratorio</option>
							<option value="Informe" <?php echo ($type == 'Informe') ? 'selected' : '' ?>>Informe</option>
							<option value="Ensayo" <?php echo ($type == 'Ensayo') ? 'selected' : '' ?>>Ensayo</option>
							<option value="Debate" <?php echo ($type == 'Debate') ? 'selected' : '' ?>>Debate</option>
							<option value="Otro" <?php echo ($type == 'Otro') ? 'selected' : '' ?>>Otro</option>
						</select>
						<?php endif; ?>
					</div>
				</div>

				<div class="form-group mb-3">
					<label for="description" class="small font-weight-bold"><i class="fa fa-align-left"></i> Tema o Descripción</label>
					<?php if ($from_notification): ?>
					<div class="form-control form-control-sm" style="min-height: 80px; background-color: #f8f9fc;"><?php echo nl2br(htmlspecialchars($description)) ?></div>
					<input type="hidden" name="description" value="<?php echo htmlspecialchars($description) ?>">
					<?php else: ?>
					<textarea name="description" id="description" class="form-control form-control-sm" rows="3" placeholder="Describe el contenido de esta evaluación" required><?php echo htmlspecialchars($description) ?></textarea>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="card shadow mb-3">
			<div class="card-header py-3">
				<h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-book"></i> Información del Curso</h6>
			</div>
			<div class="card-body">
				<?php if ($from_notification): ?>
				<div class="row">
					<div class="col-md-6 mb-3">
						<label class="small font-weight-bold"><i class="fa fa-chalkboard"></i> Curso</label>
						<div class="form-control form-control-sm" style="background-color: #f8f9fc;"><?php echo htmlspecialchars($selected_course_name ?? ''); ?></div>
					</div>
					<div class="col-md-6 mb-3">
						<label class="small font-weight-bold"><i class="fa fa-layer-group"></i> Nivel</label>
						<div class="form-control form-control-sm" style="background-color: #f8f9fc;"><?php echo htmlspecialchars($selected_level ?? ''); ?></div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-6 mb-3">
						<label class="small font-weight-bold"><i class="fa fa-sort-numeric-up"></i> Grado</label>
						<div class="form-control form-control-sm" style="background-color: #f8f9fc;"><?php echo htmlspecialchars($selected_grado ?? ''); ?></div>
					</div>
					<div class="col-md-6 mb-3">
						<label class="small font-weight-bold"><i class="fa fa-users"></i> Sección</label>
						<div class="form-control form-control-sm" style="background-color: #f8f9fc;"><?php echo htmlspecialchars($selected_seccion != 'U' ? $selected_seccion : 'Única'); ?></div>
					</div>
				</div>
				<?php elseif ($is_teacher && $teacher_id && !empty($cursos_por_nombre)): ?>
				<div class="row">
					<div class="col-md-6 mb-3">
						<label for="course_name" class="small font-weight-bold"><i class="fa fa-chalkboard"></i> Curso a Evaluar</label>
						<select name="course_name" id="course_name" class="form-control form-control-sm select2" required>
							<option value="">Seleccione un curso</option>
							<?php foreach (array_keys($cursos_por_nombre) as $cname): ?>
							<option value="<?php echo htmlspecialchars($cname); ?>" <?php echo ($selected_course_id && isset($cursos[$selected_course_id]) && $cursos[$selected_course_id] == $cname) ? 'selected' : '' ?>>
								<?php echo htmlspecialchars($cname); ?>
							</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-6 mb-3">
						<label for="level" class="small font-weight-bold"><i class="fa fa-layer-group"></i> Nivel</label>
						<select name="level" id="level" class="form-control form-control-sm select2" required>
							<option value="">Seleccione un nivel</option>
						</select>
					</div>
				</div>
				<div class="row">
					<div class="col-md-6 mb-3">
						<label for="grado" class="small font-weight-bold"><i class="fa fa-sort-numeric-up"></i> Grado</label>
						<select name="grado" id="grado" class="form-control form-control-sm select2" required>
							<option value="">Seleccione un grado</option>
						</select>
					</div>
					<div class="col-md-6 mb-3">
						<label for="seccion" class="small font-weight-bold"><i class="fa fa-users"></i> Sección</label>
						<select name="seccion" id="seccion" class="form-control form-control-sm select2" required>
							<option value="">Seleccione una sección</option>
						</select>
					</div>
				</div>
		<?php endif; ?>
			</div>
		</div>

		<div class="card shadow mb-3">
			<div class="card-header py-3">
				<h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-cog"></i> Configuración de Evaluación</h6>
			</div>
			<div class="card-body">
				<?php if ($academic_year_id > 0): ?>
				<div class="alert alert-info mb-3">
					<i class="fa fa-info-circle"></i>
					<strong>Año Académico:</strong> 
					<?php echo htmlspecialchars($academic_year_name); ?>
					<?php if ($id): ?>
						<small class="text-muted">(Esta evaluación pertenece a este año académico)</small>
					<?php else: ?>
						<small class="text-muted">(Nuevas evaluaciones se crearán en el año académico activo)</small>
					<?php endif; ?>
				</div>
				<?php else: ?>
				<div class="alert alert-warning mb-3">
					<i class="fa fa-exclamation-triangle"></i>
					<strong>Advertencia:</strong> No hay un año académico activo configurado.
					<br><small>Contacte al administrador para configurar un año académico antes de crear evaluaciones.</small>
				</div>
				<?php endif; ?>
				
				<div class="row">
					<div class="col-md-6 mb-3">
						<label class="small font-weight-bold"><i class="fa fa-calendar-alt"></i> Bimestre</label>
						<?php if ($from_notification): ?>
						<div class="form-control form-control-sm" style="background-color: #f8f9fc;">
							<?php 
							$bimestre_text = '';
							switch ($bimestre) {
								case '1': $bimestre_text = '1° Bimestre'; break;
								case '2': $bimestre_text = '2° Bimestre'; break;
								case '3': $bimestre_text = '3° Bimestre'; break;
								case '4': $bimestre_text = '4° Bimestre'; break;
							}
							echo $bimestre_text;
							?>
						</div>
						<input type="hidden" name="bimestre" value="<?php echo $bimestre; ?>">
						<?php else: ?>
						<select name="bimestre" id="bimestre" class="form-control form-control-sm select2" required>
							<option value="">Seleccione un bimestre</option>
							<option value="1" <?php echo ($bimestre == '1') ? 'selected' : '' ?>>1° Bimestre</option>
							<option value="2" <?php echo ($bimestre == '2') ? 'selected' : '' ?>>2° Bimestre</option>
							<option value="3" <?php echo ($bimestre == '3') ? 'selected' : '' ?>>3° Bimestre</option>
							<option value="4" <?php echo ($bimestre == '4') ? 'selected' : '' ?>>4° Bimestre</option>
						</select>
						<?php endif; ?>
					</div>
					<div class="col-md-6 mb-3">
						<label class="small font-weight-bold"><i class="fa fa-award"></i> Competencia a Evaluar</label>
						<?php if ($from_notification): ?>
						<div class="form-control form-control-sm" style="background-color: #f8f9fc; min-height: 38px;">
							<?php 
							$comp_names = [];
							foreach ($competencias as $comp) {
								if (in_array($comp['id'], $selected_competencias)) {
									$comp_names[] = $comp['name'] . " (" . $comp['percentage'] . "%) - " . $comp['course_name'] . " (" . $comp['area_name'] . ")";
								}
							}
							echo !empty($comp_names) ? htmlspecialchars(implode(', ', $comp_names)) : 'No hay competencias seleccionadas';
							?>
						</div>
						<?php else: ?>
						<select name="competencias[]" id="competencias" class="form-control form-control-sm select2" required>
							<option value="">Seleccione curso y nivel para cargar competencias</option>
						</select>
						<?php endif; ?>
					</div>
					<?php if ($id && $has_existing_grades): ?>
					<div class="col-md-6 mb-3">
						<label class="small font-weight-bold"><i class="fa fa-history"></i> Acción para el historial</label>
						<select name="competency_change_mode" id="competency_change_mode" class="form-control form-control-sm select2">
							<option value="preserve">Mantener la competencia histórica actual</option>
							<option value="replace">Cambiar a la competencia seleccionada</option>
						</select>
						<small class="text-muted">Si ya hay notas registradas, esta opción define si se conserva la competencia anterior o se cambia a la nueva selección.</small>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		
		<input type="hidden" name="teacher_id" value="<?php echo $teacher_id ?>">
		<input type="hidden" name="teacher_course_id" id="teacher_course_id_hidden" value="<?php echo htmlspecialchars($selected_teacher_course_id); ?>">
		<input type="hidden" name="academic_year_id" value="<?php echo $academic_year_id ?>">
		
		<div class="row mt-4">
			<div class="col-12">
				<button type="submit" class="btn btn-primary btn-sm" <?php echo ($academic_year_id <= 0) ? 'disabled' : ''; ?>>
					<i class="fa fa-save"></i> Guardar Evaluación
				</button>
				<button type="button" class="btn btn-secondary btn-sm ms-2" onclick="try { window.parent.$('#uni_modal').modal('hide'); } catch(_) { $('#uni_modal').modal('hide'); }">
					<i class="fa fa-times"></i> Cancelar
				</button>
			</div>
			</div>
	</form>
</div>

<script>
// --- Bloqueo por bimestre (solo para docentes) ---
(function(){
	var isTeacher = <?php echo isset($is_teacher) && $is_teacher ? 'true' : 'false'; ?>;
	var isAdmin = <?php echo isset($is_admin) && $is_admin ? 'true' : 'false'; ?>;
	var academicYearId = <?php echo (int)$academic_year_id; ?>;
	var initialBim = <?php echo json_encode($bimestre ?: ''); ?>;
	var isEdit = <?php echo ($id ? 'true' : 'false'); ?>;

	function ensureLockBanner(){
		if($('#bimester-lock-banner').length === 0){
			var banner = '<div id="bimester-lock-banner" class="alert alert-warning" style="display:none;margin-bottom:15px;">' +
				'<i class="fa fa-lock"></i> Este bimestre está bloqueado por el administrador. No puedes crear ni editar evaluaciones en este bimestre.' +
				'</div>';
			$('.card').first().find('.card-body').prepend(banner);
		}
	}

	function setLockedUI(locked){
		ensureLockBanner();
		if(locked){
			$('#bimester-lock-banner').slideDown(180);
			var $form = $('#manage-evaluation');
			if (isEdit) {
				$form.find('input, select, textarea, button[type="submit"]').prop('disabled', true);
			} else {
				$form.find('input, select, textarea, button[type="submit"]').prop('disabled', false);
				$('#bimestre').prop('disabled', false);
			}
			try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', true); } catch(e) {}
		} else {
			$('#bimester-lock-banner').slideUp(120);
			var $form = $('#manage-evaluation');
			$form.find('input, select, textarea, button[type="submit"]').prop('disabled', false);
			<?php if ($academic_year_id <= 0): ?>
			$form.find('button[type="submit"]').prop('disabled', true);
			try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', true); } catch(e) {}
			<?php endif; ?>
			<?php if ($academic_year_id > 0): ?>
			try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false); } catch(e) {}
			<?php endif; ?>
		}
	}

	function checkBimesterLock(){
		if(!isTeacher || isAdmin) return;
		if(!academicYearId) return;
		var bimVal = $('#bimestre').val() || initialBim || '';
		if(!bimVal){ setLockedUI(false); return; }
		$.getJSON('ajax.php', { action: 'get_bimester_locks', academic_year_id: academicYearId })
			.done(function(resp){
				if(resp && resp.status==1 && resp.locks){
					var locked = !!resp.locks[parseInt(bimVal,10)];
					setLockedUI(locked);
				} else {
					setLockedUI(false);
				}
			})
			.fail(function(){ setLockedUI(false); });
	}

	$(document).ready(function(){
		checkBimesterLock();
		$(document).on('change', '#bimestre', checkBimesterLock);
	});
})();

// Inicializar Select2
$('.select2').select2({ 
    width: '100%',
    placeholder: 'Seleccione una opción',
    allowClear: true
});

// Variable global para la competencia seleccionada al editar
var selectedCompetenciaId = '<?php echo !empty($selected_competencias) ? $selected_competencias[0] : ''; ?>';

<?php if ($is_teacher && $teacher_id && !empty($cursos_por_nombre)): ?>
// Datos para la cascada de selección
var nivelesPorNombre = <?php echo json_encode($niveles_por_nombre); ?>;
var gradosPorNombreNivel = <?php echo json_encode($grados_por_nombre_nivel); ?>;
var seccionesPorNombreNivelGrado = <?php echo json_encode($secciones_por_nombre_nivel_grado); ?>;

// Mapa para buscar el teacher_course_id según selección
var teacherCourseMap = {};
<?php
$teacher_course_map = [];
if ($is_teacher && $teacher_id) {
	$year_filter = $academic_year_id ? "AND tc.academic_year_id = $academic_year_id" : "";
	
	$q = $conn->query("SELECT tc.id as tcid, ac.name, ac.level, tc.grado, tc.seccion
		FROM teacher_courses tc
		INNER JOIN academic_courses ac ON ac.id = tc.course_id
		WHERE tc.teacher_id = $teacher_id $year_filter");
	while ($row = $q->fetch_assoc()) {
		$teacher_course_map[$row['name']][$row['level']][$row['grado']][$row['seccion'] ?? 'U'] = $row['tcid'];
	}
}
?>
teacherCourseMap = <?php echo json_encode($teacher_course_map); ?>;

function updateNiveles() {
    var cname = $('#course_name').val();
    var $nivel = $('#level');
    
    $nivel.prop('disabled', true);
    
    setTimeout(function() {
        $nivel.html('<option value="">Seleccione un nivel</option>');
        if (cname && nivelesPorNombre[cname]) {
            nivelesPorNombre[cname].forEach(function(n) {
                $nivel.append('<option value="'+n+'">'+n+'</option>');
            });
        }
        $nivel.val('').prop('disabled', false).trigger('change.select2');
    }, 100);
}

function updateGrados() {
    var cname = $('#course_name').val();
    var nivel = $('#level').val();
    var $grado = $('#grado');
    
    $grado.prop('disabled', true);
    
    setTimeout(function() {
        $grado.html('<option value="">Seleccione un grado</option>');
        if (cname && nivel && gradosPorNombreNivel[cname] && gradosPorNombreNivel[cname][nivel]) {
            gradosPorNombreNivel[cname][nivel].forEach(function(g) {
                $grado.append('<option value="'+g+'">'+g+'</option>');
            });
        }
        $grado.val('').prop('disabled', false).trigger('change.select2');
    }, 100);
}

function updateSecciones() {
    var cname = $('#course_name').val();
    var nivel = $('#level').val();
    var grado = $('#grado').val();
    var $seccion = $('#seccion');
    
    $seccion.prop('disabled', true);
    
    setTimeout(function() {
        $seccion.html('<option value="">Seleccione una sección</option>');
        if (cname && nivel && grado && seccionesPorNombreNivelGrado[cname] && 
            seccionesPorNombreNivelGrado[cname][nivel] && 
            seccionesPorNombreNivelGrado[cname][nivel][grado]) {
            seccionesPorNombreNivelGrado[cname][nivel][grado].forEach(function(s) {
                $seccion.append('<option value="'+s+'">'+s+'</option>');
            });
        }
        $seccion.val('').prop('disabled', false).trigger('change.select2');
    }, 100);
}

function updateTeacherCourseIdHidden() {
    var cname = $('#course_name').val();
    var nivel = $('#level').val();
    var grado = $('#grado').val();
    var seccion = $('#seccion').val();
    var tcid = '';
    
    if (cname && nivel && grado && seccion && teacherCourseMap[cname] && 
        teacherCourseMap[cname][nivel] && 
        teacherCourseMap[cname][nivel][grado] && 
        teacherCourseMap[cname][nivel][grado][seccion]) {
        tcid = teacherCourseMap[cname][nivel][grado][seccion];
    }
    
    $('#teacher_course_id_hidden').val(tcid);
}

function updateCompetencias() {
    var cname = $('#course_name').val();
    var nivel = $('#level').val();
    var $competencias = $('#competencias');
    
    $competencias.prop('disabled', true);
    
    if (cname && nivel) {
        $competencias.html('<option value="">Cargando competencias...</option>');
        
        $.ajax({
            url: 'ajax.php?action=get_course_competencies_by_name_level',
            method: 'POST',
            data: { 
                course_name: cname,
                level: nivel,
                academic_year_id: <?php echo (int)$academic_year_id; ?>,
                selected_competency_id: selectedCompetenciaId || 0
            },
            dataType: 'json',
            success: function(resp) {
                $competencias.html('<option value="">Seleccione una competencia</option>');
                
                var hasUnofficial = false;
                if (resp.status == 1 && resp.competencies && resp.competencies.length > 0) {
                    $.each(resp.competencies, function(index, competencia) {
                        if (parseFloat(competencia.percentage) == 0 && competencia.name.toLowerCase().indexOf('no oficial') !== -1) {
                            hasUnofficial = true;
                        }
                        var selected = '';
                        if (typeof selectedCompetenciaId !== 'undefined' && selectedCompetenciaId == competencia.id) {
                            selected = ' selected';
                        }
                        $competencias.append(
                            '<option value="' + competencia.id + '"' + selected + '>' + 
                            competencia.name + ' (' + competencia.percentage + '%) - ' + 
                            competencia.course_name + ' (' + competencia.area_name + ')' +
                            '</option>'
                        );
                    });
                } else {
                    $competencias.append('<option value="">No hay competencias configuradas, pero puede usar una no oficial.</option>');
                }
                
                if (!hasUnofficial) {
                    var selUnofficial = (typeof selectedCompetenciaId !== 'undefined' && selectedCompetenciaId === 'unofficial') ? ' selected' : '';
                    $competencias.append('<option value="unofficial"'+selUnofficial+'>Evaluación No Oficial (No Promedia)</option>');
                }
                
                $competencias.prop('disabled', false).trigger('change.select2');
            },
            error: function() {
                $competencias.html('<option value="">Error al cargar competencias</option>');
                $competencias.prop('disabled', false).trigger('change.select2');
            }
        });
    } else {
        $competencias.html('<option value="">Seleccione curso y nivel para cargar competencias</option>');
        $competencias.prop('disabled', false).trigger('change.select2');
    }
}

$('#course_name').on('change', function() {
    updateNiveles();
    $('#grado').html('<option value="">Seleccione un grado</option>').val('').prop('disabled', true);
    $('#seccion').html('<option value="">Seleccione una sección</option>').val('').prop('disabled', true);
    $('#competencias').html('<option value="">Seleccione una competencia</option>').val('').prop('disabled', true);
});

$('#level').on('change', function() {
    updateGrados();
    $('#seccion').html('<option value="">Seleccione una sección</option>').val('').prop('disabled', true);
});

$('#grado').on('change', function() {
    updateSecciones();
});

$('#course_name, #level, #grado, #seccion').on('change', function() {
    updateTeacherCourseIdHidden();
    
    if ($(this).is('#course_name') || $(this).is('#level')) {
        var cname = $('#course_name').val();
        var nivel = $('#level').val();
        if (cname && nivel) {
            updateCompetencias();
        }
    }
});

$(document).ready(function() {
    var selectedCourseName = '<?php echo isset($selected_course_name) ? $selected_course_name : ''; ?>';
    var selectedLevel = '<?php echo isset($selected_level) ? $selected_level : ''; ?>';
    var selectedGrado = '<?php echo $selected_grado; ?>';
    var selectedSeccion = '<?php echo $selected_seccion; ?>';
    
    if (selectedCourseName) {
        $('#course_name').val(selectedCourseName).trigger('change');
        setTimeout(function() {
            if (selectedLevel) {
                $('#level').val(selectedLevel).trigger('change');
                setTimeout(function() {
                    if (selectedGrado) {
                        $('#grado').val(selectedGrado).trigger('change');
                        setTimeout(function() {
                            if (selectedSeccion) {
                                $('#seccion').val(selectedSeccion).trigger('change');
                            }
                            updateTeacherCourseIdHidden();
                            setTimeout(function() {
                                updateCompetencias();
                                // Después de cargar las competencias, seleccionar la que estaba guardada
                                if (selectedCompetenciaId) {
                                    $('#competencias').val(selectedCompetenciaId).trigger('change.select2');
                                }
                            }, 100);
                        }, 300);
                    } else {
                        setTimeout(function() {
                            updateCompetencias();
                            if (selectedCompetenciaId) {
                                $('#competencias').val(selectedCompetenciaId).trigger('change.select2');
                            }
                        }, 100);
                    }
                }, 300);
            }
        }, 300);
    }
});
<?php endif; ?>

$(document).ready(function(){
  try {
    var $parent = window.parent && window.parent.$ ? window.parent.$ : $;
    if ($parent && $parent('#uni_modal').length) {
      if (<?php echo ($academic_year_id <= 0) ? 'true' : 'false'; ?>) {
        $parent('#uni_modal #submit').prop('disabled', true).text('Guardar');
      }
    }
  } catch(e) { }
});

var evalSubmitting = false;
$('#manage-evaluation').submit(function(e) {
  e.preventDefault();
	if (evalSubmitting) { return false; }
	evalSubmitting = true;
  try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', true).text('Guardar'); } catch(_) {}
  
  var teacherCourseId = $('#teacher_course_id_hidden').val();
  var title = $('#title').val().trim();
  var type = $('#type').val();
  var description = $('#description').val().trim();
  var academicYearId = <?php echo $academic_year_id; ?>;
  
  if (!academicYearId) {
      alert_toast('No hay un año académico activo. Contacte al administrador.', 'error');
      try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
      evalSubmitting = false;
      return;
  }
  
  if (!title) {
      alert_toast('El título de la evaluación es obligatorio', 'warning');
      $('#title').focus();
      try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
      evalSubmitting = false;
      return;
  }
  
  if (!type) {
      alert_toast('Debe seleccionar un tipo de evaluación', 'warning');
      $('#type').focus();
      try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
      evalSubmitting = false;
      return;
  }
  
  if (!description) {
      alert_toast('La descripción o tema es obligatorio', 'warning');
      $('#description').focus();
      try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
      evalSubmitting = false;
      return;
  }
  
  if (!teacherCourseId) {
      alert_toast('Debe seleccionar curso, nivel, grado y sección válidos', 'warning');
      $('#course_name').focus();
      try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
      evalSubmitting = false;
      return;
  }
  
	var compSel = $('#competencias').val();
	if (!compSel || (Array.isArray(compSel) && compSel.length !== 1)) {
		alert_toast('Seleccione exactamente una competencia', 'warning');
		$('#competencias').focus();
		try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
		evalSubmitting = false;
		return;
	}
  
  start_load();
  $.ajax({
      url: 'ajax.php?action=save_evaluation',
      method: 'POST',
      data: $(this).serialize(),
      dataType: 'json',
      success: function(resp) {
          if (resp.status == 1) {
              alert_toast(resp.message, 'success');
              try {
                  if (typeof $ !== 'undefined' && $.fn && $(document).trigger) {
                      $(document).trigger('evaluation:saved', [resp]);
                  }
                  if (window.parent && typeof window.parent.loadEvaluations === 'function') {
                      window.parent.loadEvaluations();
                  } else if (typeof window.loadEvaluations === 'function') {
                      window.loadEvaluations();
                  }
              } catch (e) { }
			  setTimeout(function() {
                  try {
                      if (window.parent && window.parent.$) {
                          window.parent.$('#uni_modal').modal('hide');
                      } else {
                          $('#uni_modal').modal('hide');
                      }
                  } catch (e) { }
				  evalSubmitting = false; end_load();
              }, 500);
          } else {
              alert_toast(resp.message || 'Error al guardar la evaluación.', 'danger');
              try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
			  evalSubmitting = false; end_load();
          }
      },
      error: function() {
          alert_toast('Error en el servidor.', 'danger');
          try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
		  evalSubmitting = false; end_load();
      }
  });
});
</script>
