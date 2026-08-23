<?php include 'db_connect.php' ?>
<?php
include_once 'includes/session_check.php';
require_login_modal();
$school_id = intval($_SESSION['login_school_id'] ?? 0);
if (isset($_GET['id'])) {
	$payment_id = intval($_GET['id']);
	$qry = $conn->query("SELECT p.* FROM payments p JOIN student_ef_list ef ON ef.id = p.ef_id JOIN student s ON s.id = ef.student_id WHERE p.id = $payment_id" . ($school_id ? " AND s.school_id = $school_id" : " AND 1 = 0"));
	foreach ($qry->fetch_array() as $k => $v) {
		$$k = $v;
	}
} else {
	// Obtener el último número de boleta y sumarle 1
	$last_receipt = $conn->query("SELECT MAX(CAST(p.receipt_no AS UNSIGNED)) as last_no FROM payments p JOIN student_ef_list ef ON ef.id = p.ef_id JOIN student s ON s.id = ef.student_id WHERE 1=1" . ($school_id ? " AND s.school_id = $school_id" : " AND 1 = 0"));
	$next_receipt = 1;
	if ($last_receipt && $last_receipt->num_rows > 0) {
		$row = $last_receipt->fetch_assoc();
		if (!empty($row['last_no'])) {
			$next_receipt = $row['last_no'] + 1;
		}
	}
	$receipt_no = $next_receipt;
}
?>

<div class="container-fluid">
	<form id="manage-payment">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
		<input type="hidden" name="payment_splits" id="payment_splits" value="">
		<input type="hidden" name="selected_concepts" id="selected_concepts" value="">
		<input type="hidden" name="payment_method_id" id="payment_method_id" value="">
		
		<div id="msg" class="mb-3"></div>
		
		<div class="form-group">
			<label class="font-weight-bold">Alumno <span class="text-danger">*</span></label>
			<select name="student_id" id="student_id" class="form-control select2" required>
				<option value="">Seleccione un alumno</option>
				<?php
				$students = $conn->query("SELECT id, name, id_no, status FROM student WHERE (status = 'Activo' OR status = 'Retirado' OR status = 'Egresado' OR status IS NULL)" . ($school_id ? " AND school_id = $school_id" : " AND 1 = 0") . " ORDER BY name ASC");
				while ($stu = $students->fetch_assoc()): 
					$status_label = '';
					if($stu['status'] == 'Retirado') {
						$status_label = ' [Retirado]';
					} elseif($stu['status'] == 'Egresado') {
						$status_label = ' [Egresado]';
					}
				?>
					<option value="<?php echo $stu['id']; ?>" <?php echo (isset($student_id) && $student_id == $stu['id']) ? 'selected' : '' ?>>
						<?php echo $stu['id_no'] . ' - ' . ucwords($stu['name']) . $status_label; ?>
					</option>
				<?php endwhile; ?>
			</select>
		</div>
		
		<div class="form-group">
			<label class="font-weight-bold">Conceptos de Pago Pendientes <span class="text-danger">*</span></label>
			<select name="ef_id[]" id="ef_id" class="form-control select2" multiple required>
				<option value="">Seleccione uno o más conceptos</option>
			</select>
			<small class="form-text text-muted">Puedes seleccionar varios conceptos para realizar un multipago.</small>
		</div>

		<div class="form-group" id="concepts_breakdown_wrapper" style="display:none;">
			<label class="font-weight-bold">Desglose por concepto</label>
			<div id="concepts_breakdown" class="border rounded p-2"></div>
		</div>
		
		<div class="form-group">
			<label class="font-weight-bold">Saldo Total Seleccionado</label>
			<input type="text" class="form-control text-right" id="balance" value="<?php echo isset($balance) ? $balance : '' ?>" readonly style="background-color: #f8f9fa;">
		</div>
		
		<div class="form-group">
			<label class="font-weight-bold">Monto a Pagar <span class="text-danger">*</span></label>
			<input type="number" step="any" class="form-control text-right" name="amount" required value="<?php echo isset($amount) ? $amount : '' ?>">
		</div>
		
		<div class="form-group">
			<label class="font-weight-bold">Medios de Pago <span class="text-danger">*</span></label>
			<div id="payment_methods_container"></div>
			<button type="button" class="btn btn-sm btn-primary mt-2" id="add_payment_method">
				<i class="fa fa-plus"></i> Agregar Medio de Pago
			</button>
		</div>
		
		<div class="form-group">
			<label class="font-weight-bold">N° Boleta <span class="text-danger">*</span></label>
			<input type="text" class="form-control" name="receipt_no" required value="<?php echo isset($receipt_no) ? $receipt_no : '' ?>">
		</div>
		
		<div class="form-group">
			<label class="font-weight-bold">Observaciones</label>
			<textarea name="remarks" cols="30" rows="4" class="form-control"><?php echo isset($remarks) ? $remarks : '' ?></textarea>
		</div>

		<div class="text-right">
			<button type="button" class="btn btn-secondary" data-dismiss="modal">
				<i class="fa fa-times"></i> Cancelar
			</button>
			<button type="submit" class="btn btn-primary">
				<i class="fa fa-save"></i> Guardar
			</button>
		</div>
	</form>
</div>

<!-- Template para filas de método de pago -->
<script type="text/html" id="payment_method_template">
	<div class="payment-method-row mb-2">
		<div class="row">
			<div class="col-md-6">
				<select class="form-control payment-method-select" required>
					<option value="">Seleccione método</option>
					<?php
					$methods = $conn->query("SELECT id, name FROM payment_methods ORDER BY name ASC");
					while($row = $methods->fetch_assoc()):
					?>
						<option value="<?php echo $row['id'] ?>"><?php echo $row['name'] ?></option>
					<?php endwhile; ?>
				</select>
			</div>
			<div class="col-md-4">
				<input type="number" step="0.01" class="form-control payment-amount-input" placeholder="Monto" required>
			</div>
			<div class="col-md-2">
				<button type="button" class="btn btn-sm btn-danger remove-payment-method btn-block">
					<i class="fa fa-trash"></i>
				</button>
			</div>
		</div>
	</div>
</script>

<script>
	$('.select2').select2({
		placeholder: 'Por favor selecciona aquí',
		width: '100%',
		dropdownParent: $('#uni_modal')
	});

	$(document).ready(function() {
		// Función para agregar una fila de método de pago
		function addPaymentMethodRow(methodId = null, amount = null) {
			var template = $('#payment_method_template').html();
			var $row = $(template);
			
			$('#payment_methods_container').append($row);
			
			if (methodId) $row.find('.payment-method-select').val(methodId);
			if (amount) $row.find('.payment-amount-input').val(amount);
		}

		// Agregar fila inicial
		if ($('#payment_methods_container').children().length === 0) {
			addPaymentMethodRow();
		}

		// Botón Agregar
		$('#add_payment_method').click(function() {
			addPaymentMethodRow();
		});

		// Botón Eliminar
		$(document).on('click', '.remove-payment-method', function() {
			if ($('#payment_methods_container').children().length > 1) {
				$(this).closest('.payment-method-row').remove();
			} else {
				alert_toast("Debe haber al menos un medio de pago.", 'warning');
			}
		});

		// Validar y enviar
		$('#manage-payment').submit(function(e) {
			e.preventDefault();
			
			var totalSplits = 0;
			var splits = [];
			var firstMethodId = null;

			$('.payment-method-row').each(function() {
				var methodId = $(this).find('.payment-method-select').val();
				var amount = parseFloat($(this).find('.payment-amount-input').val()) || 0;
				
				if (methodId && amount > 0) {
					splits.push({ method_id: methodId, amount: amount });
					totalSplits += amount;
					if (!firstMethodId) firstMethodId = methodId;
				}
			});

			var mainAmount = parseFloat($('[name="amount"]').val().replace(/,/g, '')) || 0;
			
			// Construir conceptos seleccionados y validar suma
			var concepts = [];
			var conceptsSum = 0;
			$('#concepts_breakdown .row').each(function(){
				var efId = $(this).attr('data-ef-id');
				var amt = parseFloat($(this).find('.concept-amount').val()) || 0;
				if (efId && amt > 0) {
					concepts.push({ ef_id: parseInt(efId, 10), amount: amt });
					conceptsSum += amt;
				}
			});
			if (concepts.length === 0) {
				alert_toast('Selecciona al menos un concepto y asigna monto.', 'warning');
				return;
			}
			if (Math.abs(conceptsSum - mainAmount) > 0.01) {
				alert_toast('La suma de los conceptos (' + conceptsSum.toFixed(2) + ') debe ser igual al Monto Total (' + mainAmount.toFixed(2) + ').', 'warning');
				return;
			}

			if (Math.abs(totalSplits - mainAmount) > 0.01) {
				alert_toast("La suma de los medios de pago (" + totalSplits.toFixed(2) + ") debe ser igual al Monto Total (" + mainAmount.toFixed(2) + ").", 'warning');
				return;
			}

			if (splits.length === 0) {
				alert_toast("Debe agregar al menos un medio de pago válido.", 'warning');
				return;
			}

			$('#payment_splits').val(JSON.stringify(splits));
			$('#payment_method_id').val(firstMethodId);
			$('#selected_concepts').val(JSON.stringify(concepts));

			start_load();
			$('#msg').html('');
			$.ajax({
				url: 'ajax.php?action=save_payment',
				method: 'POST',
				data: $(this).serialize(),
				success: function(resp) {
					try {
						if (typeof resp === 'string') {
							resp = JSON.parse(resp);
						}
						if (resp.status == 1) {
							alert_toast("Datos guardados con éxito.", 'success');
							setTimeout(function() {
								var payments = resp.payments || (resp.pid ? [{ef_id: resp.ef_id, pid: resp.pid}] : []);
								(payments || []).forEach(function(p){
									var nw = window.open('receipt.php?ef_id=' + p.ef_id + '&pid=' + p.pid, "_blank", "width=900,height=600");
									setTimeout(function() { try { nw.print(); } catch(_){} }, 500);
								});
								setTimeout(function(){ location.reload(); }, 1200);
							}, 500);
						} else {
							alert_toast(resp.message, 'danger');
							end_load();
						}
					} catch (err) {
						console.error("Error al procesar la respuesta del servidor:", err);
						alert_toast("Error inesperado. Intente nuevamente más tarde.", 'danger');
						end_load();
					}
				},
				error: function(err) {
					console.error("Error en la solicitud AJAX:", err);
					alert_toast("Error en el servidor. Intente nuevamente más tarde.", 'danger');
					end_load();
				}
			});
		});
	});

	$('#student_id').change(function() {
		var student_id = $(this).val();
		$('#ef_id').prop('disabled', true).html('<option value="">Cargando...</option>');
		
		if(student_id) {
			$.ajax({
				url: 'ajax.php?action=get_pending_concepts',
				method: 'POST',
				data: {student_id: student_id},
				success: function(resp) {
					try {
						if(typeof resp === 'string') resp = JSON.parse(resp);
						var options = '';
						(resp.data || []).forEach(function(item) {
							var balanceFormatted = 'S/ ' + parseFloat(item.balance).toLocaleString('en-US', {
								style: 'decimal',
								minimumFractionDigits: 2,
								maximumFractionDigits: 2
							});
							options += `<option value="${item.ef_id}" data-balance="${item.balance}" data-total="${item.total_fee}" title="Saldo: ${balanceFormatted}">${item.concepto_concatenado}</option>`;
						});
						$('#ef_id').html(options).prop('disabled', false);
						
						// Reinicializar Select2 en el campo de conceptos (múltiple)
						$('#ef_id').select2('destroy').select2({
							placeholder: 'Seleccione uno o más conceptos',
							width: '100%',
							dropdownParent: $('#uni_modal')
						});
					} catch(e) {
						$('#ef_id').html('<option value="">Error al cargar conceptos</option>');
					}
				},
				error: function() {
					$('#ef_id').html('<option value="">Error al cargar conceptos</option>');
				}
			});
		} else {
			$('#ef_id').html('<option value="">Seleccione uno o más conceptos</option>').prop('disabled', true);
		}
	});

	function rebuildConceptsBreakdown() {
		var selected = $('#ef_id').val() || [];
		var totalBalance = 0;
		var html = '';
		selected.forEach(function(efId){
			var opt = $('#ef_id option[value="'+efId+'"]').get(0);
			var balance = parseFloat($(opt).attr('data-balance')) || 0;
			var total = parseFloat($(opt).attr('data-total')) || 0;
			var concepto = $(opt).text();
			totalBalance += balance;
			html += `<div class="row align-items-center py-1" data-ef-id="${efId}">
						<div class="col-md-5">
							<div class="small text-muted">${concepto}</div>
						</div>
						<div class="col-md-4 text-right">
							<button type="button" class="btn btn-xs btn-outline-warning btn-apply-discount mr-2" data-id="${efId}" title="Aplicar Beca/Descuento"><i class="fa fa-percent"></i> Desc.</button>
							<span class="badge badge-info" title="Total original/final: S/ ${total.toFixed(2)}">Saldo: S/ ${balance.toFixed(2)}</span>
						</div>
						<div class="col-md-3">
							<input type="number" step="0.01" class="form-control form-control-sm concept-amount" placeholder="Monto" value="${balance.toFixed(2)}">
						</div>
					</div>`;
		});
		if (selected.length > 0) {
			$('#concepts_breakdown').html(html);
			$('#concepts_breakdown_wrapper').show();
		} else {
			$('#concepts_breakdown').html('');
			$('#concepts_breakdown_wrapper').hide();
		}
		$('#balance').val(totalBalance ? totalBalance.toLocaleString('en-US', {style: 'decimal', maximumFractionDigits: 2, minimumFractionDigits: 2}) : '');
		// Set main amount to total by default
		$('[name="amount"]').val(totalBalance ? totalBalance.toFixed(2) : '');
		if ($('.payment-method-row').length === 1) {
			$('.payment-amount-input').val($('[name="amount"]').val());
		}
	}

	$('#ef_id').on('change', rebuildConceptsBreakdown);

	$(document).on('click', '.btn-apply-discount', function() {
		var efId = $(this).attr('data-id');
		var opt = $('#ef_id option[value="'+efId+'"]').get(0);
		var total = parseFloat($(opt).attr('data-total')) || 0;
		
		var descAmount = prompt("Ingrese el monto FINAL a pagar para este concepto (con descuento):\nTotal original: S/ " + total.toFixed(2));
		if (descAmount !== null) {
			var num = parseFloat(descAmount);
			if (isNaN(num) || num < 0) {
				alert_toast("Monto inválido", "warning");
				return;
			}
			
			start_load();
			$.ajax({
				url: 'ajax.php?action=save_discount',
				method: 'POST',
				data: { ef_id: efId, discount_amount: num },
				success: function(resp){
					if(resp == 1){
						alert_toast('Descuento aplicado correctamente', 'success');
						var currentSelection = $('#ef_id').val();
						$.ajax({
							url: 'ajax.php?action=get_pending_concepts',
							method: 'POST',
							data: {student_id: $('#student_id').val()},
							success: function(resp2) {
								try {
									if(typeof resp2 === 'string') resp2 = JSON.parse(resp2);
									var options = '';
									(resp2.data || []).forEach(function(item) {
										var balanceFormatted = 'S/ ' + parseFloat(item.balance).toLocaleString('en-US', {
											style: 'decimal',
											minimumFractionDigits: 2,
											maximumFractionDigits: 2
										});
										options += `<option value="${item.ef_id}" data-balance="${item.balance}" data-total="${item.total_fee}" title="Saldo: ${balanceFormatted}">${item.concepto_concatenado}</option>`;
									});
									$('#ef_id').html(options);
									$('#ef_id').val(currentSelection);
									$('#ef_id').trigger('change');
								} catch(e) {}
								end_load();
							},
							error: function() {
								end_load();
							}
						});
					} else {
						alert_toast('Error al aplicar descuento', 'error');
						end_load();
					}
				},
				error: function(){
					alert_toast('Error en el servidor', 'error');
					end_load();
				}
			});
		}
	});
</script>
