<?php
include 'db_connect.php';
include_once 'includes/session_check.php'; require_login_modal();

// Detectar si es un administrador y si viene de las notificaciones (modo solo lectura)
$is_admin = (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 1);
$is_teacher = (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 2);
$from_notification = isset($_GET['from']) && $_GET['from'] == 'notifications';
$readonly_mode = $from_notification && $is_admin;
$evaluation_id = intval($_GET['evaluation_id'] ?? 0);
if (!$evaluation_id) {
	echo "<div class='alert alert-danger'>Evaluación no encontrada.</div>";
	exit;
}
$eval = $conn->query("SELECT * FROM evaluations WHERE id = $evaluation_id")->fetch_assoc();
if (!$eval) {
	echo "<div class='alert alert-danger'>Evaluación no encontrada.</div>";
	exit;
}

// Obtener información del teacher_course primero
$teacher_course_id = $eval['teacher_course_id'] ?? 0;
$course_name = $level = $grado = $seccion = '';
if ($teacher_course_id) {
	$q = $conn->query("SELECT ac.name as course_name, ac.level, tc.grado, tc.seccion
		FROM teacher_courses tc
		INNER JOIN academic_courses ac ON ac.id = tc.course_id
		WHERE tc.id = $teacher_course_id LIMIT 1");
	if ($q && $row = $q->fetch_assoc()) {
		$course_name = $row['course_name'];
		$level = $row['level'];
		$grado = $row['grado'];
		$seccion = $row['seccion'];
	}
}

// Obtener información del año académico de la evaluación a través de teacher_courses
$academic_year_id = 0;
$academic_year_name = '';

// Obtener el año académico desde teacher_courses ya que evaluations no tiene academic_year_id directo
if ($teacher_course_id) {
	$ay_query = $conn->query("SELECT ay.id, ay.year, ay.description 
							  FROM teacher_courses tc 
							  INNER JOIN academic_year ay ON tc.academic_year_id = ay.id 
							  WHERE tc.id = $teacher_course_id LIMIT 1");
	if ($ay_query && $ay_query->num_rows > 0) {
		$ay_row = $ay_query->fetch_assoc();
		$academic_year_id = $ay_row['id'];
		$academic_year_name = $ay_row['year'] . ($ay_row['description'] ? ' - ' . $ay_row['description'] : '');
	}
}

// Si no se pudo obtener el año académico, usar el activo
if (!$academic_year_id) {
	$school_id = $_SESSION['login_school_id'] ?? 1;
	$ay_query = $conn->query("SELECT id, year, description FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
	if ($ay_query && $ay_query->num_rows > 0) {
		$ay_row = $ay_query->fetch_assoc();
		$academic_year_id = $ay_row['id'];
		$academic_year_name = $ay_row['year'] . ($ay_row['description'] ? ' - ' . $ay_row['description'] : '');
	}
}

$teacher_course_id = $eval['teacher_course_id'] ?? 0;
$school_id = $_SESSION['login_school_id'] ?? 1;

$grado_escaped = $conn->real_escape_string($grado);
$seccion_escaped = $conn->real_escape_string($seccion);

$seccion_condition = "AND s.seccion = '$seccion_escaped'";
if (in_array(strtoupper(trim($seccion)), ['U', 'ÚNICA', 'UNICA'])) {
    $seccion_condition = "AND s.seccion IN ('U', 'Única', 'Unica', 'u', 'única', 'unica', '')";
}

// Combinamos búsquedas para obtener los estudiantes relevantes.
// Cambio: sólo incluimos los alumnos "actuales" del grado/sección cuando la evaluación pertenece al año académico activo.
// Para evaluaciones antiguas, sólo se mostrarán alumnos que ya tengan registros en `evaluation_grades`.
$active_year_q = $conn->query("SELECT id FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
$active_year_id = ($active_year_q && $active_year_q->num_rows) ? intval($active_year_q->fetch_assoc()['id']) : 0;
$parts = [];

// Si la evaluación es del año académico activo, incluir los alumnos actuales del grado/sección
if ($academic_year_id == $active_year_id) {
    $parts[] = "SELECT DISTINCT s.* 
        FROM student s
        WHERE s.school_id = $school_id
        AND (s.status = 'Activo' OR s.status IS NULL)
        AND LOWER(s.nivel) = '".strtolower($level)."'
        AND s.grado = '$grado_escaped'
        $seccion_condition";
}

// Siempre incluir alumnos que ya tienen notas para esta evaluación (históricos)
$parts[] = "SELECT DISTINCT s2.* 
    FROM student s2
    INNER JOIN evaluation_grades eg ON s2.id = eg.student_id
    WHERE eg.evaluation_id = $evaluation_id
    AND s2.school_id = $school_id
    AND (s2.status = 'Activo' OR s2.status IS NULL)";

$students_query = implode(" UNION ", $parts) . " ORDER BY name ASC";

$students = $conn->query($students_query);

if ($students->num_rows === 0) {
    echo "<div class='alert alert-warning'>No hay estudiantes registrados para el grado $grado, sección $seccion.</div>";
}

// Obtener competencias asociadas a la evaluación
$competencias = [];
$q_comp = $conn->query("
	SELECT gcc.*, ac.name as course_name, a.name as area_name, a.color as area_color
	FROM evaluation_competencias ec 
	INNER JOIN general_course_competencies gcc ON gcc.id = ec.competencia_id 
	LEFT JOIN academic_courses ac ON ac.id = gcc.course_id
	LEFT JOIN areas a ON a.id = ac.area_id
	WHERE ec.evaluation_id = $evaluation_id 
	ORDER BY gcc.name ASC
");
while ($row = $q_comp->fetch_assoc()) {
	$competencias[] = $row;
}
?>
<div class="container-fluid">
	<?php if ($readonly_mode): ?>
	<div class="alert alert-warning mb-3">
		<i class="fa fa-lock"></i> <strong>Modo de Visualización:</strong> Estás viendo las calificaciones en modo de solo lectura.
	</div>
	<?php endif; ?>
	
	<div class="card shadow mb-3">
		<div class="card-header py-3">
			<h6 class="m-0 font-weight-bold text-primary"><?php echo htmlspecialchars($eval['title']) ?></h6>
		</div>
		<div class="card-body">
			<p class="text-muted mb-3"><?php echo htmlspecialchars($eval['description']) ?: 'Sin descripción disponible'; ?></p>
			<div class="row small mb-3">
				<div class="col-md-6">
					<strong>Curso:</strong> <?php echo htmlspecialchars($course_name) ?><br>
					<strong>Nivel:</strong> <?php echo htmlspecialchars($level) ?><br>
					<strong>Grado:</strong> <?php echo htmlspecialchars($grado) ?>
				</div>
				<div class="col-md-6">
					<strong>Sección:</strong> <?php echo htmlspecialchars($seccion) ?><br>
					<strong>Bimestre:</strong> <?php echo isset($eval['bimestre']) && $eval['bimestre'] ? $eval['bimestre'].'° Bimestre' : 'No asignado'; ?><br>
					<strong>Año Académico:</strong> <?php echo htmlspecialchars($academic_year_name) ?: 'No asignado'; ?>
				</div>
			</div>
			
			<?php if (!$readonly_mode): ?>
			<div class="form-group">
				<label for="grading_system" class="small font-weight-bold">
					<i class="fa fa-cog"></i> Sistema de Calificación:
				</label>
				<div class="row">
					<div class="col-md-6">
						<select id="grading_system" class="form-control form-control-sm">
							<option value="numeric">Numérico (0-20)</option>
							<option value="letters">Por Letras (C, B, A, AD)</option>
						</select>
					</div>
					<div class="col-md-6">
						<small class="text-muted">
							<strong>Equivalencias:</strong> C=0-10, B=11-13, A=14-17, AD=18-20
						</small>
					</div>
				</div>
				<small class="text-info d-block mt-2">
					<i class="fa fa-info-circle"></i> 
					<span id="system-indicator">Sistema numérico activo</span>
				</small>
			</div>
			<?php endif; ?>
		</div>
	</div>
	
	<?php if (!$academic_year_id): ?>
	<div class="alert alert-warning mb-3">
		<i class="fa fa-exclamation-triangle"></i> 
		<strong>Advertencia:</strong> Esta evaluación no tiene un año académico asignado. Se asignará automáticamente al año académico activo al guardar las calificaciones.
	</div>
	<?php else: ?>
	<div class="alert alert-info mb-3">
		<i class="fa fa-info-circle"></i> 
		<strong>Información:</strong> Esta evaluación pertenece al año académico: <strong><?php echo htmlspecialchars($academic_year_name); ?></strong>
	</div>
	<?php endif; ?>
	
	<form id="manage-evaluation-grades" method="post" action="#" onsubmit="return false;">
		<input type="hidden" name="evaluation_id" value="<?php echo $evaluation_id ?>">
		<input type="hidden" name="grading_system_used" id="grading_system_used" value="numeric">
		
		<?php if (count($competencias) > 0): ?>
			<?php foreach ($competencias as $comp): ?>
				<div class="card shadow mb-3">
					<div class="card-header py-2" style="background-color: #f8f9fc;">
						<div class="d-flex justify-content-between align-items-center">
							<div>
								<strong class="text-primary">Competencia:</strong> <?php echo htmlspecialchars($comp['name']) ?>
								<br>
								<small class="text-muted">
									<i class="fa fa-book"></i> <?php echo htmlspecialchars($comp['course_name']) ?> | 
									<i class="fa fa-tag"></i> <?php echo htmlspecialchars($comp['area_name']) ?>
								</small>
							</div>
							<span class="badge badge-primary"><?php echo $comp['percentage'] ?>%</span>
						</div>
					</div>
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-bordered table-hover mb-0">
								<thead class="thead-light">
									<tr>
										<th width="5%">#</th>
										<th width="45%">Estudiante</th>
										<th width="20%">DNI</th>
										<th width="30%">Nota</th>
									</tr>
								</thead>
								<tbody>
								<?php
								$i = 1;
								$students->data_seek(0);
								while ($stu = $students->fetch_assoc()):
									$grade = '';
									$grade_query = "SELECT grade FROM evaluation_grades WHERE evaluation_id = $evaluation_id AND student_id = {$stu['id']} AND competencia_id = {$comp['id']}";
									$qg = $conn->query($grade_query);
									if ($qg && $qg->num_rows) {
										$grade_row = $qg->fetch_assoc();
										$grade = $grade_row['grade'];
									}
								?>
								<tr>
									<td><?php echo $i++ ?></td>
									<td><?php echo ucwords($stu['name']) ?></td>
									<td><?php echo $stu['id_no'] ?></td>
									<td>
										<?php if ($readonly_mode): ?>
										<div class="form-control form-control-sm text-center font-weight-bold" style="width: 80px; margin: 0 auto; background-color: #f8f9fc;">
											<?php 
											if ($grade !== '' && $grade !== null) {
												if (in_array(strtoupper($grade), ['C', 'B', 'A', 'AD'])) {
													echo htmlspecialchars($grade);
												} else {
													echo htmlspecialchars($grade);
												}
											} else {
												echo '-';
											}
											?>
										</div>
										<?php else: ?>
										<input type="number" step="any" min="0" max="20" name="grades[<?php echo $comp['id'] ?>][<?php echo $stu['id'] ?>]" 
											class="form-control form-control-sm grade-input text-center font-weight-bold" value="<?php echo htmlspecialchars($grade ?? '') ?>" 
											data-original-value="<?php echo htmlspecialchars($grade ?? '') ?>"
											style="width: 80px; margin: 0 auto;">
										<?php endif; ?>
									</td>
								</tr>
								<?php endwhile; ?>
								<?php if ($students->num_rows === 0): ?>
								<tr>
									<td colspan="4" class="text-center text-danger py-3">
										<i class="fa fa-users"></i>
										No hay estudiantes registrados en esta sección y grado
									</td>
								</tr>
								<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		<?php else: ?>
			<div class="alert alert-warning text-center">
				<i class="fa fa-exclamation-triangle"></i> 
				No hay competencias asociadas a esta evaluación.
			</div>
		<?php endif; ?>
		
		<?php if ($readonly_mode): ?>
		<div class="alert alert-info text-center">
			<i class="fa fa-info-circle"></i> Estás viendo las calificaciones en modo solo lectura.
		</div>
		<?php endif; ?>
		
		<?php if (!$readonly_mode): ?>
		<div class="row mt-4">
			<div class="col-12 text-center">
				<button type="submit" class="btn btn-primary btn-sm">
					<i class="fa fa-save"></i> Guardar Calificaciones
				</button>
				<button type="button" class="btn btn-secondary btn-sm ms-2" onclick="try { window.parent.$('#uni_modal').modal('hide'); } catch(_) { $('#uni_modal').modal('hide'); }">
					<i class="fa fa-times"></i> Cancelar
				</button>
			</div>
		</div>
		<?php endif; ?>
	</form>
</div>

<script>
// Funciones para conversión entre sistemas de calificación
function numericToLetter(grade) {
	const num = parseFloat(grade);
	if (isNaN(num) || grade === '') return '';
	if (num >= 18) return 'AD';
	if (num >= 14) return 'A';
	if (num >= 11) return 'B';
	return 'C';
}

function letterToNumeric(letter) {
	switch(letter.toUpperCase()) {
		case 'AD': return 19;
		case 'A': return 15.5;
		case 'B': return 12;
		case 'C': return 5;
		default: return '';
	}
}

function isLetterGrade(value) {
	if (!value || value === '') return false;
	const trimmedValue = value.toString().trim().toUpperCase();
	return ['C', 'B', 'A', 'AD'].includes(trimmedValue);
}

function createNumericInput(name, value, readonly = false) {
	let displayValue = '';
	if (value && value !== '') {
		const trimmedValue = value.toString().trim();
		if (isLetterGrade(trimmedValue)) {
			displayValue = letterToNumeric(trimmedValue);
		} else if (!isNaN(parseFloat(trimmedValue))) {
			displayValue = trimmedValue;
		}
	}
	
	return `<input type="number" step="any" min="0" max="20" name="${name}" 
			class="form-control form-control-sm grade-input text-center font-weight-bold" value="${displayValue}" 
			style="width: 80px; margin: 0 auto;" 
			${readonly ? 'readonly' : ''}>`;
}

function createLetterInput(name, value, readonly = false) {
	let letterValue = '';
	if (value && value !== '') {
		const trimmedValue = value.toString().trim();
		if (isLetterGrade(trimmedValue)) {
			letterValue = trimmedValue.toUpperCase();
		} else if (!isNaN(parseFloat(trimmedValue))) {
			letterValue = numericToLetter(trimmedValue);
		}
	}
	
	const options = ['', 'C', 'B', 'A', 'AD'];
	let selectHtml = `<select name="${name}" class="form-control form-control-sm grade-input text-center font-weight-bold" 
					  style="width: 80px; margin: 0 auto;" 
					  ${readonly ? 'disabled' : ''}>`;
	
	options.forEach(option => {
		const selected = option === letterValue ? 'selected' : '';
		const text = option === '' ? '-' : option;
		selectHtml += `<option value="${option}" ${selected}>${text}</option>`;
	});
	selectHtml += '</select>';
	return selectHtml;
}

function switchGradingSystem() {
	const system = $('#grading_system').val();
	const isReadonly = <?php echo $readonly_mode ? 'true' : 'false'; ?>;

	$('#grading_system_used').val(system);

	const indicator = $('#system-indicator');
	if (system === 'letters') {
		indicator.text('Sistema de letras activo (C, B, A, AD)');
		indicator.removeClass('text-info').addClass('text-success');
	} else {
		indicator.text('Sistema numérico activo (0-20)');
		indicator.removeClass('text-success').addClass('text-info');
	}

	$('.grade-input').each(function() {
		const currentValue = $(this).val();
		const originalValue = $(this).data('original-value') || $(this).attr('data-original-value');
		const name = $(this).attr('name');

		const valueToUse = currentValue || originalValue || '';

		let newInput;

		if (system === 'letters') {
			newInput = createLetterInput(name, valueToUse, isReadonly);
		} else {
			newInput = createNumericInput(name, valueToUse, isReadonly);
		}

		$(this).parent().html(newInput);
	});
}

$('#manage-evaluation-grades').submit(function(e) {
	e.preventDefault();
	<?php if ($readonly_mode): ?>
	alert_toast('No puedes editar las calificaciones en modo solo lectura.', 'warning');
	return false;
	<?php endif; ?>
	try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', true).text('Guardando...'); } catch(_) {}
	
	const system = $('#grading_system_used').val();
	
	let isValid = true;
	let errorMessage = '';
	
	$('.grade-input').each(function(index) {
		const value = $(this).val();
		
		if (value !== '') {
			if (system === 'letters') {
				if (!['C', 'B', 'A', 'AD'].includes(value.toUpperCase())) {
					isValid = false;
					errorMessage = 'Las calificaciones por letras deben ser: C, B, A o AD';
					return false;
				}
			} else {
				const num = parseFloat(value);
				if (isNaN(num) || num < 0 || num > 20) {
					isValid = false;
					errorMessage = 'Las calificaciones numéricas deben estar entre 0 y 20';
					return false;
				}
			}
		}
	});
	
	if (!isValid) {
		alert_toast(errorMessage, 'warning');
		try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
		return false;
	}
	
	start_load();
	
	$.ajax({
		url: 'ajax.php?action=save_evaluation_grades',
		method: 'POST',
		data: $(this).serialize(),
		dataType: 'json',
		success: function(resp) {
			if (resp.status == 1) {
				alert_toast(resp.message, 'success');
				try {
					if (typeof $ !== 'undefined' && $.fn && $(document).trigger) {
						$(document).trigger('evaluation:gradesSaved', [resp, { evaluation_id: <?php echo json_encode($evaluation_id); ?> }]);
					}
					if (window.parent && typeof window.parent.loadEvaluations === 'function') {
						window.parent.loadEvaluations();
					} else if (typeof window.loadEvaluations === 'function') {
						window.loadEvaluations();
					}
				} catch(e) { }

				setTimeout(function(){
					try {
						if (window.parent && window.parent.$) {
							window.parent.$('#uni_modal').modal('hide');
						} else {
							$('#uni_modal').modal('hide');
						}
					} catch(e) { }
					end_load();
				}, 300);
			} else {
				alert_toast(resp.message, 'danger');
				try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
				end_load();
			}
		},
		error: function() {
			alert_toast("Error en el servidor.", 'danger');
			try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
			end_load();
		}
	});
	return false;
});

$('#grading_system').change(function() {
	switchGradingSystem();
});

// Evitar que la rueda del ratón y las teclas de flecha cambien valores en los campos numéricos de notas
function disableGradeInputScrollAndArrow() {
  document.addEventListener('wheel', function(e) {
    var target = e.target;
    if (target && target.tagName === 'INPUT' && target.type === 'number' && target.classList.contains('grade-input')) {
      e.preventDefault();
    }
  }, { passive: false });

  document.addEventListener('keydown', function(e) {
    var target = e.target;
    if (target && target.tagName === 'INPUT' && target.type === 'number' && target.classList.contains('grade-input')) {
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        e.preventDefault();
      }
    }
  });
}

disableGradeInputScrollAndArrow();

$(document).ready(function() {
	let hasLetterGrades = false;
	
	$('.grade-input').each(function() {
		const value = $(this).val();
		const originalValue = $(this).data('original-value') || $(this).attr('data-original-value');
		
		const checkValue = value || originalValue;
		if (checkValue && checkValue.toString().trim() !== '') {
			if (isLetterGrade(checkValue)) {
				hasLetterGrades = true;
			}
		}
	});
	
	if (hasLetterGrades) {
		$('#grading_system').val('letters');
		$('#grading_system_used').val('letters');
		
		$('#system-indicator').text('Sistema de letras activo (C, B, A, AD)');
		$('#system-indicator').removeClass('text-info').addClass('text-success');
		
		switchGradingSystem();
	}
	
  try {
    var $parent = window.parent && window.parent.$ ? window.parent.$ : $;
    if ($parent && $parent('#uni_modal').length) {
      $parent('#uni_modal #submit').off('click.manage_eval_grades').on('click.manage_eval_grades', function(){
        $('#manage-evaluation-grades').submit();
      });
	$parent('#uni_modal #submit').text('Guardar').prop('disabled', false);
      if (<?php echo $readonly_mode ? 'true' : 'false'; ?>) {
        $parent('#uni_modal #submit').prop('disabled', true).text('Guardar');
      }
    }
  } catch(e) { }
});
</script>
<script>
// --- Bloqueo por bimestre en ingreso de notas (solo docentes) ---
(function(){
	var isTeacher = <?php echo isset($is_teacher) && $is_teacher ? 'true' : 'false'; ?>;
	var isAdmin = <?php echo isset($is_admin) && $is_admin ? 'true' : 'false'; ?>;
	var academicYearId = <?php echo (int)$academic_year_id; ?>;
	var bim = <?php echo json_encode($eval['bimestre'] ?? ''); ?>;

	function ensureLockBanner(){
		if($('#grades-bimester-lock-banner').length === 0){
			var banner = '<div id="grades-bimester-lock-banner" class="alert alert-warning" style="display:none;margin-bottom:10px;">' +
				'<i class="fa fa-lock"></i> Este bimestre está bloqueado por el administrador. No puedes registrar ni editar notas en este bimestre.' +
				'</div>';
			$('.container-fluid').prepend(banner);
		}
	}

	function setLockedUI(locked){
		ensureLockBanner();
		if(locked){
			$('#grades-bimester-lock-banner').slideDown(180);
			$('.grade-input').prop('disabled', true);
			try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', true); } catch(e) {}
		} else {
			$('#grades-bimester-lock-banner').slideUp(120);
			$('.grade-input').prop('disabled', false);
			try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false); } catch(e) {}
		}
	}

	function checkBimesterLock(){
		if(!isTeacher || isAdmin) return;
		if(!academicYearId) return;
		if(!bim){ setLockedUI(false); return; }
		$.getJSON('ajax.php', { action: 'get_bimester_locks', academic_year_id: academicYearId })
			.done(function(resp){
				if(resp && resp.status==1 && resp.locks){
					var locked = !!resp.locks[parseInt(bim,10)];
					setLockedUI(locked);
				} else {
					setLockedUI(false);
				}
			})
			.fail(function(){ setLockedUI(false); });
	}

	$(document).ready(function(){
		checkBimesterLock();
	});
})();

(function(){
  var $form = $('#manage-evaluation-grades');
  $form.on('ajaxError grades:enableSubmit', function(){
    try { var $p = window.parent && window.parent.$ ? window.parent.$ : $; $p('#uni_modal #submit').prop('disabled', false).text('Guardar'); } catch(_) {}
  });
})();
</script>
