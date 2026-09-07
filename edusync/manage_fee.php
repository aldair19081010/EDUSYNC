<?php include 'db_connect.php' ?>
<?php
include_once 'includes/session_check.php';
require_login_modal();

// Obtener school_id de la sesión
$school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];
// Fallback: intentar obtener school_id desde el usuario logueado
if ($school_id === 0 && isset($_SESSION['login_id'])) {
    $u = intval($_SESSION['login_id']);
    $uq = $conn->query("SELECT school_id FROM users WHERE id = $u LIMIT 1");
    if ($uq && $uq->num_rows > 0) {
        $school_id = intval($uq->fetch_assoc()['school_id']);
        if ($school_id > 0) $_SESSION['login_school_id'] = $school_id;
    }
}
// Fallback adicional: si no hay colegio en sesión y sólo existe uno en DB, usar ese
if ($school_id === 0) {
    $sc_q = $conn->query("SELECT id FROM schools LIMIT 2");
    if ($sc_q && $sc_q->num_rows == 1) {
        $school_id = intval($sc_q->fetch_assoc()['id']);
        $_SESSION['login_school_id'] = $school_id;
    }
}

if (isset($_GET['id'])) {
	$edit_id = intval($_GET['id']);
	$qry = $conn->query("SELECT ef.* FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id WHERE ef.id=$edit_id AND s.school_id=$school_id LIMIT 1");
	if (!$qry || !$qry->num_rows) exit('<div class="alert alert-danger">La deuda no existe o pertenece a otra institución.</div>');
	foreach ($qry->fetch_array() as $k => $v) {
		$$k = $v;
	}
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

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
		color: #4e73df;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		margin-bottom: 15px;
		padding-bottom: 10px;
		border-bottom: 2px solid #e3e6f0;
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

	input[type="hidden"] {
		display: none;
	}

</style>

<div class="container-fluid">
	<form id="manage-fees">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
		<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
		
		<div id="msg" class="form-group"></div>
		
		<!-- Sección: Estudiante -->
		<div class="form-section">
			<div class="form-section-title"><i class="fa fa-user"></i> Estudiante</div>
			
			<div class="form-group">
				<label for="student_search">Estudiante <span class="text-danger">*</span></label>
				<select id="student_search" name="student_id" class="form-control" style="width: 100%" required>
					<option value="">Seleccione un estudiante</option>
					<?php
					$students = $conn->query("SELECT id, name, id_no, nivel, grado FROM student WHERE school_id = $school_id ORDER BY name ASC");
					while ($row = $students->fetch_assoc()):
					?>
					<option value="<?php echo $row['id'] ?>" data-name="<?php echo ucwords($row['name']) ?> | <?php echo $row['id_no'] ?>" data-nivel="<?php echo $row['nivel'] ?>" data-grado="<?php echo $row['grado'] ?>"><?php echo ucwords($row['name']) ?> | <?php echo $row['id_no'] ?></option>
					<?php endwhile; ?>
				</select>
				<input type="hidden" id="student_nivel">
				<input type="hidden" id="student_grado">
				<small class="form-text text-muted">Seleccione el estudiante al que desea asignar la deuda</small>
			</div>
		</div>

		<div class="form-section">
			<div class="form-section-title"><i class="fa fa-calendar-alt"></i> Vencimiento</div>
			<div class="form-group mb-0"><label for="due_date">Fecha de vencimiento</label><input type="date" id="due_date" name="due_date" class="form-control" value="<?php echo htmlspecialchars($due_date ?? ''); ?>"><small class="form-text text-muted">El año se obtiene automáticamente del concepto activo seleccionado.</small></div>
		</div>
		
		<!-- Sección: Concepto de Pago -->
		<div class="form-section">
			<div class="form-section-title"><i class="fa fa-money-bill"></i> Concepto de Pago</div>
			
			<div class="form-group">
				<label for="course_search">Concepto de Pago <span class="text-danger">*</span></label>
				<select id="course_search" name="course_id" class="form-control" style="width: 100%" disabled required>
					<option value="">Seleccione un concepto</option>
				</select>
				<small class="form-text text-muted">Se mostrarán los conceptos disponibles para el nivel del estudiante</small>
			</div>
		</div>
		
		<!-- Sección: Tarifa -->
		<div class="form-section">
			<div class="form-section-title"><i class="fa fa-tag"></i> Monto</div>
			
			<div class="form-group">
				<label for="total_fee">Tarifa <span class="text-danger">*</span></label>
			<input type="text" id="total_fee" class="form-control text-right" name="total_fee" 
				       value="<?php echo isset($total_fee) ? number_format($total_fee) : '' ?>" 
				       required readonly style="background-color: #f8f9fa; cursor: not-allowed;">
				<small class="form-text text-muted">Se calcula automáticamente según el concepto seleccionado</small>
			</div>
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
	var allConcepts = [];

	$(document).ready(function() {
		setTimeout(function() {
			console.log('🔧 Inicializando Select2...');
			
			// Inicializar Select2 para estudiante
			$('#student_search').select2({
				placeholder: 'Seleccione un estudiante',
				allowClear: true,
				width: '100%',
				dropdownParent: $('#uni_modal')
			});

			// Inicializar Select2 para concepto
			$('#course_search').select2({
				placeholder: 'Seleccione un concepto',
				allowClear: true,
				width: '100%',
				dropdownParent: $('#uni_modal')
			});
			
			console.log('✅ Select2 inicializado');
			
			// Eventos
			$('#student_search').on('change', function() {
				var studentId = $(this).val();
				
				if (!studentId) {
					$('#student_nivel').val('');
					$('#student_grado').val('');
					$('#course_search').prop('disabled', true).empty().append('<option value="">Seleccione un concepto</option>');
					$('#course_search').select2('destroy').select2({
						placeholder: 'Seleccione un concepto',
						allowClear: true,
						width: '100%',
						dropdownParent: $('#uni_modal')
					});
					$('#total_fee').val('');
					return;
				}
				
				var selectedOption = $(this).find('option:selected');
				var nivel = selectedOption.data('nivel');
				var grado = selectedOption.data('grado');
				
				console.log('✅ Estudiante seleccionado:', {studentId, nivel, grado});
				
				$('#student_nivel').val(nivel);
				$('#student_grado').val(grado);
				$('#course_search').prop('disabled', false);
				$('#course_search').empty().append('<option value="">Cargando conceptos...</option>');
				$('#course_search').select2('destroy').select2({
					placeholder: 'Seleccione un concepto',
					allowClear: true,
					width: '100%',
					dropdownParent: $('#uni_modal')
				});
				$('#total_fee').val('');
				
				loadStudentConcepts(studentId, nivel, grado);
			});
			
			$('#course_search').on('change', function() {
				var courseId = $(this).val();
				
				if (!courseId) {
					$('#total_fee').val('');
					return;
				}
				
				var foundConcept = allConcepts.find(function(c) {
					return c.id == courseId;
				});
				
				if (foundConcept) {
					var tarifaVal = parseFloat(foundConcept.total_amount).toFixed(2);
					$('#total_fee').val(tarifaVal);
					console.log('✅ TARIFA RELLENADA:', tarifaVal);
				}
			});
		}, 300);
	});

	function loadStudentConcepts(studentId, nivel, grado) {
		console.log('📥 Cargando conceptos para:', {studentId, nivel, grado});
		
		// Obtener el ID de edición si existe
		var editId = $('input[name="id"]').val();
		
		$.ajax({
			url: 'ajax.php?action=get_student_concepts',
			method: 'POST',
			data: {
				student_id: studentId,
				nivel: nivel,
				grado: grado,
				edit_id: editId // Enviar ID para modo edición
			},
			dataType: 'json',
			success: function(resp) {
				if (resp.status == 1) {
					allConcepts = resp.concepts || [];
					console.log('✓ Conceptos recibidos:', allConcepts);
					
					// Limpiar opciones anteriores
					$('#course_search').empty().append('<option value="">Seleccione un concepto</option>');
					
					// Agregar nuevas opciones
					allConcepts.forEach(function(concept) {
						$('#course_search').append(
							$('<option></option>')
								.val(concept.id)
								.attr('data-amount', concept.total_amount)
								.text(concept.display_text)
						);
					});
					
					// Reinicializar Select2
					$('#course_search').select2('destroy').select2({
						placeholder: 'Seleccione un concepto',
						allowClear: true,
						width: '100%',
						dropdownParent: $('#uni_modal')
					});
					
					console.log('✅ Conceptos cargados en select:', allConcepts.length);
				} else {
					console.warn('Error en respuesta:', resp.message);
					alert_toast(resp.message || 'No hay conceptos disponibles', 'info');
				}
			},
			error: function(xhr, status, error) {
				console.error('Error AJAX:', error);
				alert_toast('Error al cargar conceptos', 'danger');
			}
		});
	}

	// Submit del formulario
	$('#manage-fees').submit(function(e){
		e.preventDefault();
		
		// Validaciones básicas
		if (!$('#student_search').val()) {
			alert_toast('Por favor seleccione un estudiante', 'warning');
			return;
		}
		
		if (!$('#course_search').val()) {
			alert_toast('Por favor seleccione un concepto de pago', 'warning');
			return;
		}
		
		start_load();
		$.ajax({
			url: 'fees_api.php?action=save',
			method: 'POST',
			data: $(this).serialize(),
			dataType: 'json',
			error: function(err) {
				console.log(err);
				end_load();
				alert_toast('Error en el servidor', 'danger');
			},
			success: function(resp) {
				if (resp.status == 1) {
					end_load();
					alert_toast(resp.message || resp.msg, 'success');
					setTimeout(function() {
						$('#uni_modal').modal('hide');
						if (window.reload_table) window.reload_table();
						else if (typeof table !== 'undefined') table.ajax.reload();
						else location.reload();
					}, 500);
				} else if (resp.status == 2) {
					alert_toast(resp.message || 'Registro duplicado', 'warning');
					end_load();
				} else {
					alert_toast(resp.message || 'Error al guardar', 'danger');
					end_load();
				}
			}
		});
	});

	// Precargar datos en modo edición
	$(document).ready(function() {
		var studentIdVal = '<?php echo isset($student_id) ? $student_id : '' ?>';
		var courseIdVal = '<?php echo isset($course_id) ? $course_id : '' ?>';
		
		if (studentIdVal) {
			console.log('📝 Modo edición - Cargando datos:', {studentIdVal, courseIdVal});
			
			// Seleccionar el estudiante primero
			setTimeout(function() {
				$('#student_search').val(studentIdVal).trigger('change');
				
				// Esperar a que se carguen los conceptos antes de seleccionar el concepto
				if (courseIdVal) {
					var checkConceptsLoaded = setInterval(function() {
						if (allConcepts.length > 0) {
							clearInterval(checkConceptsLoaded);
							console.log('✅ Conceptos cargados, seleccionando concepto:', courseIdVal);
							
							$('#course_search').val(courseIdVal).trigger('change');
							
							// Asegurar que la tarifa se muestre
							setTimeout(function() {
								var foundConcept = allConcepts.find(function(c) {
									return c.id == courseIdVal;
								});
								
								if (foundConcept) {
									var tarifaVal = parseFloat(foundConcept.total_amount).toFixed(2);
									$('#total_fee').val(tarifaVal);
									console.log('✅ Tarifa cargada en edición:', tarifaVal);
								}
							}, 200);
						}
					}, 100);
					
					// Timeout de seguridad (5 segundos)
					setTimeout(function() {
						clearInterval(checkConceptsLoaded);
					}, 5000);
				}
			}, 400);
		}
	});
</script>
