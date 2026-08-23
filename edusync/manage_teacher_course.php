
<?php
include_once 'includes/session_check.php';
require_login_modal();
include 'db_connect.php';

// Obtener el school_id del administrador
$school_id = $_SESSION['login_school_id'] ?? 0;

// Obtener el año académico activo o el especificado
$academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;
if ($academic_year_id == 0) {
    $active_year_query = $conn->query("SELECT id FROM academic_year WHERE is_active = 1 AND school_id = $school_id LIMIT 1");
    if ($active_year_query && $active_year_query->num_rows > 0) {
        $active_year = $active_year_query->fetch_assoc();
        $academic_year_id = $active_year['id'];
    }
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : '';
$course_id = $grado = $seccion = '';
$teacher_name = '';
$existing_assignments = [];
$opened_from_teachers = ($_GET['source'] ?? '') === 'teachers';

if ($id) {
    $q = $conn->query("SELECT * FROM teacher_courses WHERE id = $id");
    if ($q && $q->num_rows) {
        $row = $q->fetch_assoc();
        $teacher_id = $row['teacher_id'];
        $course_id = $row['course_id'];
        $grado = $row['grado'];
        $seccion = $row['seccion'] ?? 'U';
        $academic_year_id = $row['academic_year_id'] ?? $academic_year_id;
    }
}

if (!empty($teacher_id)) {
	$teacher_stmt = $conn->prepare("SELECT name FROM teacher WHERE id = ? AND school_id = ? AND status = 'Activo' LIMIT 1");
	$teacher_stmt->bind_param('ii', $teacher_id, $school_id);
	$teacher_stmt->execute();
	$teacher_row = $teacher_stmt->get_result()->fetch_assoc();
	$teacher_stmt->close();
	if ($teacher_row) $teacher_name = $teacher_row['name'];

	if ($teacher_row && empty($id) && $academic_year_id > 0) {
		$assignments_stmt = $conn->prepare('SELECT tc.id, tc.course_id, tc.grado, tc.seccion, tc.level, COALESCE(ac.name, "Curso no disponible") AS course_name FROM teacher_courses tc LEFT JOIN academic_courses ac ON ac.id = tc.course_id WHERE tc.teacher_id = ? AND tc.school_id = ? AND tc.academic_year_id = ? ORDER BY tc.level, tc.grado, tc.seccion, ac.name');
		$assignments_stmt->bind_param('iii', $teacher_id, $school_id, $academic_year_id);
		$assignments_stmt->execute();
		$assignments_result = $assignments_stmt->get_result();
		while ($assignment = $assignments_result->fetch_assoc()) $existing_assignments[] = $assignment;
		$assignments_stmt->close();
	}
}
?>

<style>
	.form-group label {
		font-weight: 600;
		color: #233b52;
		margin-bottom: 8px;
		font-size: 0.95rem;
	}

	.form-control {
		border-radius: 6px;
		border: 1px solid #d1d3e2;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
		transition: border-color 0.2s, box-shadow 0.2s;
		font-size: 0.95rem;
	}

	.form-control:focus {
		border-color: #4285f4;
		box-shadow: 0 0 0 0.2rem rgba(66,133,244,0.25);
	}

	#msg {
		margin-bottom: 15px;
	}

	.form-section {
		margin-bottom: 25px;
	}

	.form-section-title {
		font-size: 0.9rem;
		font-weight: 700;
		color: #4285f4;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		margin-bottom: 15px;
		padding-bottom: 10px;
		border-bottom: 2px solid #e3e6f0;
	}

	.row-cols-2 {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 15px;
	}

	@media (max-width: 768px) {
		.row-cols-2 {
			grid-template-columns: 1fr;
		}
	}

	.btn-group-form {
		display: flex;
		gap: 10px;
		margin-top: 25px;
		justify-content: flex-end;
	}

	.btn-group-form .btn {
		padding: 8px 20px;
		border-radius: 6px;
		font-weight: 500;
		transition: all 0.2s;
	}

	.btn-group-form .btn:hover {
		transform: translateY(-2px);
		box-shadow: 0 4px 8px rgba(0,0,0,0.15);
	}
</style>

<div class="container-fluid">
	<form id="manage-teacher-course">
		<input type="hidden" name="id" value="<?php echo $id ?>">
		<input type="hidden" name="school_id" value="<?php echo $school_id ?>">
		<input type="hidden" name="academic_year_id" value="<?php echo $academic_year_id ?>">
		
		<div id="msg" class="form-group"></div>
		
		<!-- Sección: Información General -->
		<div class="form-section">
			<div class="form-section-title"><i class="fa fa-info-circle"></i> Información General</div>
			
			<div class="form-group">
				<label for="academic_year_display">Año Académico</label>
				<?php
				$year_info = null;
				if ($academic_year_id > 0) {
					$year_query = $conn->query("SELECT year, description FROM academic_year WHERE id = $academic_year_id");
					if ($year_query && $year_query->num_rows > 0) {
						$year_info = $year_query->fetch_assoc();
					}
				}
				$year_display = $year_info ? ($year_info['year']) : 'No configurado';
				?>
				<input type="text" class="form-control" value="<?php echo htmlspecialchars($year_display) ?>" readonly>
				<?php if (!$year_info): ?>
					<small class="text-danger d-block mt-2">No hay año académico activo. Debe agregar uno para poder asignar cursos.</small>
				<?php endif; ?>
			</div>
		</div>

		<!-- Sección: Datos del Docente y Curso -->
		<div class="form-section">
			<div class="form-section-title"><i class="fa fa-book"></i> Asignación</div>
			
			<div class="row-cols-2">
				<div class="form-group">
					<label for="teacher_id">Docente <span class="text-danger">*</span></label>
					<input type="text" id="teacher_id" list="teacher_list" class="form-control" value="<?php echo htmlspecialchars($teacher_name, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Seleccione o escriba un docente" <?php echo $opened_from_teachers ? 'readonly' : ''; ?> required>
					<input type="hidden" name="teacher_id" id="teacher_id_hidden" value="<?php echo (int)$teacher_id; ?>">
					<?php if ($opened_from_teachers): ?><small class="text-muted">El docente está bloqueado porque la asignación se abrió desde su ficha.</small><?php endif; ?>
					<datalist id="teacher_list">
						<?php
						$teachers = $conn->query("SELECT id, name FROM teacher WHERE school_id = $school_id AND status = 'Activo' ORDER BY name ASC");
						while ($row = $teachers->fetch_assoc()):
						?>
						<option value="<?php echo ucwords($row['name']) ?>" data-id="<?php echo $row['id'] ?>">
						<?php endwhile; ?>
					</datalist>
				</div>

				<div class="form-group">
					<label for="course_val">Curso <span class="text-danger">*</span></label>
					<select <?php echo !empty($id) ? 'name="course_id"' : ''; ?> id="course_val" class="form-control select2" data-placeholder="Seleccione un curso" <?php echo !empty($id) ? 'required' : ''; ?>>
						<option value="">Seleccione un curso</option>
						<?php
						$courses = $conn->query("SELECT id, name, level FROM academic_courses WHERE school_id = $school_id ORDER BY name ASC");
						while ($row = $courses->fetch_assoc()):
						?>
						<option value="<?php echo $row['id'] ?>" <?php echo ($course_id == $row['id']) ? 'selected' : '' ?>><?php echo ucwords($row['name']) ?> (<?php echo $row['level'] ?>)</option>
						<?php endwhile; ?>
					</select>
				</div>
			</div>
		</div>

		<!-- Sección: Detalles -->
		<div class="form-section">
			<div class="form-section-title"><i class="fa fa-cog"></i> Detalles de la Asignación</div>
			
			<div class="row-cols-2">
				<div class="form-group">
					<label for="grado_val">Grado <span class="text-danger">*</span></label>
					<select <?php echo !empty($id) ? 'name="grado"' : ''; ?> id="grado_val" class="form-control select2" data-placeholder="Seleccione un grado" <?php echo !empty($id) ? 'required' : ''; ?>>
						<option value="">Seleccione un grado</option>
						<?php
						$all_grades = ['1°','2°','3°','4°','5°','6°'];
						foreach ($all_grades as $g):
						?>
						<option value="<?php echo $g; ?>" <?php echo ($grado == $g) ? 'selected' : '' ?>><?php echo $g; ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="form-group">
					<label for="seccion_val">Sección <span class="text-danger">*</span></label>
					<select <?php echo !empty($id) ? 'name="seccion"' : ''; ?> id="seccion_val" class="form-control select2" data-placeholder="Seleccione una sección" <?php echo !empty($id) ? 'required' : ''; ?>>
						<option value="">Seleccione una sección</option>
						<option value="U" <?php echo ($seccion == 'U') ? 'selected' : '' ?>>U (Única)</option>
						<option value="A" <?php echo ($seccion == 'A') ? 'selected' : '' ?>>A</option>
						<option value="B" <?php echo ($seccion == 'B') ? 'selected' : '' ?>>B</option>
						<option value="C" <?php echo ($seccion == 'C') ? 'selected' : '' ?>>C</option>
						<option value="D" <?php echo ($seccion == 'D') ? 'selected' : '' ?>>D</option>
						<option value="E" <?php echo ($seccion == 'E') ? 'selected' : '' ?>>E</option>
						<option value="F" <?php echo ($seccion == 'F') ? 'selected' : '' ?>>F</option>
					</select>
				</div>
			</div>

			<?php if(empty($id)): ?>
			<div class="text-right mt-3 mb-3">
				<button type="button" class="btn btn-info btn-sm" id="btn_add_to_list">
					<i class="fa fa-plus"></i> Añadir a la lista
				</button>
			</div>
			
			<div class="table-responsive">
				<table class="table table-bordered table-striped" id="assignment_table">
					<thead class="bg-primary text-white">
						<tr>
							<th>Curso</th>
							<th>Grado</th>
							<th>Sección</th>
							<th class="text-center">Acción</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($existing_assignments as $assignment): ?>
						<tr class="existing-assignment-row" data-course-id="<?php echo (int)$assignment['course_id']; ?>" data-grado="<?php echo htmlspecialchars($assignment['grado'], ENT_QUOTES, 'UTF-8'); ?>" data-seccion="<?php echo htmlspecialchars($assignment['seccion'] ?: 'U', ENT_QUOTES, 'UTF-8'); ?>">
							<td><?php echo htmlspecialchars($assignment['course_name'] . ' (' . $assignment['level'] . ')', ENT_QUOTES, 'UTF-8'); ?></td>
							<td><?php echo htmlspecialchars($assignment['grado'], ENT_QUOTES, 'UTF-8'); ?></td>
							<td><?php echo htmlspecialchars($assignment['seccion'] ?: 'U', ENT_QUOTES, 'UTF-8'); ?></td>
							<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-unassign-existing" data-id="<?php echo (int)$assignment['id']; ?>" title="Desasignar curso"><i class="fa fa-unlink mr-1"></i>Desasignar</button></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>

		<!-- Botones de Acción -->
		<div class="btn-group-form">
			<button type="button" class="btn btn-secondary" data-dismiss="modal">
				<i class="fa fa-times"></i> Cancelar
			</button>
			<button type="submit" class="btn btn-primary">
				<i class="fa fa-save"></i> Guardar
			</button>
		</div>
	</form>
</div>

<script>
	$(document).ready(function(){
		// Bootstrap 4 modal focus fix for Select2
		$.fn.modal.Constructor.prototype._enforceFocus = function() {};

		// Inicializar select2 localmente para evitar bugs de desplazamiento y foco en el modal
		$('.select2').each(function() {
			$(this).select2({
				width: '100%',
				dropdownParent: $(this).closest('.form-group')
			});
		});

		// Manejar cambios en docente para extraer el ID
		$('#teacher_id').on('change', function() {
			var selectedName = $(this).val();
			var selectedOption = $('option[value="' + selectedName + '"]', '#teacher_list')[0];
			var teacherId = $(selectedOption).data('id') || '';
			$('#teacher_id_hidden').val(teacherId);
		});

		// Agregar a la lista
		$('#btn_add_to_list').click(function(){
			var courseId = $('#course_val').val();
			var courseText = $('#course_val option:selected').text();
			var gradoId = $('#grado_val').val();
			var seccionId = $('#seccion_val').val();

			if (!courseId || !gradoId || !seccionId) {
				$('#msg').html('<div class="alert alert-warning"><i class="fa fa-exclamation-circle"></i> Por favor seleccione un curso, un grado y una sección válidos antes de añadir a la lista.</div>');
				return;
			}
			
			// Validar únicos en la tabla actual visualmente
			var exist = false;
			$('#assignment_table tbody tr').each(function(){
				var rowCourse = $(this).attr('data-course-id') || $(this).find('input[name="course_id[]"]').val();
				var rowGrade = $(this).attr('data-grado') || $(this).find('input[name="grado[]"]').val();
				var rowSection = $(this).attr('data-seccion') || $(this).find('input[name="seccion[]"]').val();
				if(rowCourse == courseId && rowGrade == gradoId && rowSection == seccionId) {
					exist = true;
				}
			});
			if (exist) {
				$('#msg').html('<div class="alert alert-warning"><i class="fa fa-exclamation-circle"></i> Esa combinación de curso, grado y sección ya está en la lista.</div>');
				return;
			}

			var tr = $('<tr class="new-assignment-row"></tr>');
			tr.attr({'data-course-id': courseId, 'data-grado': gradoId, 'data-seccion': seccionId});
			tr.append('<td><input type="hidden" name="course_id[]" value="'+courseId+'">'+courseText+'</td>');
			tr.append('<td><input type="hidden" name="grado[]" value="'+gradoId+'">'+gradoId+'</td>');
			tr.append('<td><input type="hidden" name="seccion[]" value="'+seccionId+'">'+seccionId+'</td>');
			tr.append('<td class="text-center"><button type="button" class="btn btn-sm btn-danger btn-remove-row"><i class="fa fa-trash"></i></button></td>');
			$('#assignment_table tbody').append(tr);
			
			// Limpiar selects si se desea un flujo rápido
			$('#grado_val').val('').trigger('change');
			$('#seccion_val').val('').trigger('change');
			$('#msg').html('');
		});

		// Eliminar de la lista
		$(document).on('click', '.btn-remove-row', function(){
			$(this).closest('tr').remove();
		});

		$(document).off('click.teacherUnassign', '.btn-unassign-existing').on('click.teacherUnassign', '.btn-unassign-existing', function(){
			var button = $(this);
			var row = button.closest('tr');
			if (!window.confirm('¿Deseas desasignar este curso del docente para el año académico seleccionado?')) return;
			button.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
			$.ajax({
				url: 'ajax.php?action=delete_teacher_course',
				method: 'POST',
				data: { id: button.attr('data-id') },
				dataType: 'json',
				success: function(resp){
					if (resp.status == 1) {
						row.fadeOut(180, function(){ $(this).remove(); });
						alert_toast('Curso desasignado correctamente.', 'success');
						<?php if ($opened_from_teachers): ?>$(document).trigger('teacher:saved');<?php endif; ?>
					} else {
						button.prop('disabled', false).html('<i class="fa fa-unlink mr-1"></i>Desasignar');
						$('#msg').html('<div class="alert alert-danger">' + (resp.message || 'No se pudo desasignar el curso.') + '</div>');
					}
				},
				error: function(){
					button.prop('disabled', false).html('<i class="fa fa-unlink mr-1"></i>Desasignar');
					$('#msg').html('<div class="alert alert-danger">Error del servidor al desasignar el curso.</div>');
				}
			});
		});

		// Validar en submit
		$('#manage-teacher-course').on('submit', function() {
			var teacherId = $('#teacher_id_hidden').val();
			if (!teacherId) $('#teacher_id_hidden').val($('#teacher_id').val());
		});
	});

	$('#manage-teacher-course').submit(function(e){
		e.preventDefault();
		start_load();
		$('#msg').html('');

		// Validaciones básicas
		if (!$('#teacher_id_hidden').val()) {
			$('#msg').html('<div class="alert alert-warning"><i class="fa fa-exclamation-circle"></i> Por favor seleccione un docente</div>');
			end_load();
			return;
		}

		<?php if(empty($id)): ?>
		// Validar que haya al menos 1 fila en la tabla cuando se está creando
		if ($('#assignment_table tbody tr.new-assignment-row').length === 0) {
			$('#msg').html('<div class="alert alert-warning"><i class="fa fa-exclamation-circle"></i> Los cursos marcados como “Ya asignado” no necesitan guardarse nuevamente. Añada una asignación nueva o cierre el formulario.</div>');
			end_load();
			return;
		}
		<?php else: ?>
		// Validar cuando se edita
		if (!$('#course_val').val() || !$('#grado_val').val() || !$('#seccion_val').val()) {
			$('#msg').html('<div class="alert alert-warning"><i class="fa fa-exclamation-circle"></i> Todos los campos son obligatorios.</div>');
			end_load();
			return;
		}
		<?php endif; ?>

		$.ajax({
			url: 'ajax.php?action=assign_teacher_course',
			method: 'POST',
			data: $(this).serialize(),
			dataType: 'json',
			success: function(resp){
				if(resp.status == 1){
					alert_toast("Asignación guardada exitosamente", 'success');
					setTimeout(function(){
						$('#uni_modal').modal('hide');
						<?php if ($opened_from_teachers): ?>
						$(document).trigger('teacher:saved');
						<?php else: ?>
						location.reload();
						<?php endif; ?>
					}, 1500);
				} else if(resp.status == 2){
					$('#msg').html('<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> El docente ya está asignado a este curso en este grado y sección.</div>');
					end_load();
				} else {
					$('#msg').html('<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> ' + (resp.message || "Ocurrió un error") + '</div>');
					end_load();
				}
			},
			error: function(xhr, status, error){
				console.error("Error en la solicitud AJAX:", error);
				$('#msg').html('<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> Ocurrió un error en el servidor. Verifique la consola para más detalles.</div>');
				end_load();
			}
		});
	});
</script>
