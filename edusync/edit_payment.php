<?php include 'db_connect.php'; ?>
<?php include_once 'includes/session_check.php'; require_login_modal(); ?>
<?php
// ID del pago a editar
$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$payment_data = [];
$payment_splits = [];
$concept_info = [];

if ($payment_id) {
    $qry = $conn->query("SELECT * FROM payments WHERE id = {$payment_id} LIMIT 1");
    if ($qry && $qry->num_rows > 0) {
        $payment_data = $qry->fetch_assoc();

        // Cargar los splits de pago existentes
        $splits_qry = $conn->query("SELECT payment_method_id, amount FROM payment_split WHERE payment_id = {$payment_id}");
        if ($splits_qry && $splits_qry->num_rows > 0) {
            while ($split = $splits_qry->fetch_assoc()) {
                $payment_splits[] = $split;
            }
        }
        // Datos del concepto asociado
        $ef_id = (int)$payment_data['ef_id'];
        if ($ef_id > 0) {
            $concept_qry = $conn->query("SELECT 
                    ef.id as ef_id,
                    s.name as student_name,
                    s.id_no,
                    s.nivel,
                    s.grado,
                    c.course,
                    c.level as course_level,
                    c.grades,
                    ay.year,
                    CONCAT(c.course, ' - ', c.level, ' (', s.grado, ') - ', COALESCE(ay.year, 'Sin año')) as concepto_concatenado
                FROM student_ef_list ef
                INNER JOIN student s ON s.id = ef.student_id
                INNER JOIN courses c ON c.id = ef.course_id
                LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
                WHERE ef.id = {$ef_id}
                LIMIT 1");
            if ($concept_qry && $concept_qry->num_rows > 0) {
                $concept_info = $concept_qry->fetch_assoc();
            }
        }
    }
}

// Opciones de métodos de pago (se reutilizan en el template)
$method_options = '';
$methods = $conn->query("SELECT id, name FROM payment_methods ORDER BY name ASC");
if ($methods) {
    while ($row = $methods->fetch_assoc()) {
        $method_id = (int)$row['id'];
        $method_name = htmlspecialchars($row['name']);
        $method_options .= "<option value=\"{$method_id}\">{$method_name}</option>";
    }
}
?>

<div class="container-fluid">
    <form id="edit-payment-form">
        <input type="hidden" name="id" value="<?php echo $payment_id; ?>">
        <input type="hidden" name="ef_id" value="<?php echo isset($payment_data['ef_id']) ? $payment_data['ef_id'] : ''; ?>">
        <input type="hidden" id="payment_splits" name="payment_splits" value="">
        <input type="hidden" id="payment_method_id" name="payment_method_id" value="">

        <?php if (!empty($concept_info)): ?>
        <div class="card border-left-primary shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Concepto en edición</div>
                <div class="mb-1"><strong><?php echo $concept_info['concepto_concatenado']; ?></strong></div>
                <div class="small text-muted"><i class="fa fa-user mr-1"></i><?php echo ucwords($concept_info['student_name']); ?></div>
                <div class="small text-muted"><i class="fa fa-id-card mr-1"></i><?php echo $concept_info['id_no']; ?> · <i class="fa fa-graduation-cap mr-1"></i><?php echo $concept_info['nivel']; ?> - <?php echo $concept_info['grado']; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label class="font-weight-bold" for="amount">Monto Total</label>
            <input type="number" step="0.01" min="0" class="form-control text-right" name="amount" id="amount" value="<?php echo isset($payment_data['amount']) ? $payment_data['amount'] : '0.00'; ?>" required>
        </div>

        <div class="form-group">
            <label class="font-weight-bold">Medios de Pago</label>
            <div id="payment_methods_container"></div>
            <button type="button" class="btn btn-sm btn-primary mt-2" id="add_payment_method">
                <i class="fa fa-plus"></i> Agregar Medio de Pago
            </button>
        </div>

        <div class="form-group">
            <label class="font-weight-bold" for="receipt_no">N° Boleta</label>
            <input type="text" class="form-control" name="receipt_no" id="receipt_no" value="<?php echo isset($payment_data['receipt_no']) ? $payment_data['receipt_no'] : ''; ?>" required readonly>
        </div>

        <div class="form-group">
            <label class="font-weight-bold" for="remarks">Observaciones</label>
            <textarea name="remarks" id="remarks" cols="30" rows="3" class="form-control"><?php echo isset($payment_data['remarks']) ? $payment_data['remarks'] : ''; ?></textarea>
        </div>

        <div class="text-right">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                <i class="fa fa-times"></i> Cancelar
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save"></i> Guardar cambios
            </button>
        </div>
    </form>
</div>

<!-- Template para filas de método de pago -->
<script type="text/html" id="payment_method_template">
    <div class="payment-method-row mb-2">
        <div class="row align-items-center">
            <div class="col-md-7">
                <select class="form-control payment-method-select" required>
                    <option value="">Seleccione método</option>
                    <?php echo $method_options; ?>
                </select>
            </div>
            <div class="col-md-4">
                <input type="number" step="0.01" class="form-control payment-amount-input" placeholder="Monto" required>
            </div>
            <div class="col-md-1 text-right">
                <button type="button" class="btn btn-sm btn-danger remove-payment-method" title="Eliminar">
                    <i class="fa fa-trash"></i>
                </button>
            </div>
        </div>
    </div>
</script>

<script>
$(document).ready(function() {
    function addPaymentMethodRow(methodId = null, amount = null) {
        var template = $('#payment_method_template').html();
        var $row = $(template);
        $('#payment_methods_container').append($row);

        if (methodId) $row.find('.payment-method-select').val(methodId);
        if (amount) $row.find('.payment-amount-input').val(amount);
    }

    var existingSplits = <?php echo json_encode($payment_splits); ?>;
    if (existingSplits && existingSplits.length > 0) {
        existingSplits.forEach(function(split) {
            addPaymentMethodRow(split.payment_method_id, split.amount);
        });
    } else {
        var currentMethodId = <?php echo isset($payment_data['payment_method_id']) ? (int)$payment_data['payment_method_id'] : 'null'; ?>;
        var currentAmount = <?php echo isset($payment_data['amount']) ? $payment_data['amount'] : '0'; ?>;
        addPaymentMethodRow(currentMethodId, currentAmount);
    }

    $('#add_payment_method').click(function() {
        addPaymentMethodRow();
    });

    $(document).on('click', '.remove-payment-method', function() {
        if ($('#payment_methods_container').children().length > 1) {
            $(this).closest('.payment-method-row').remove();
        } else {
            alert_toast('Debe haber al menos un medio de pago.', 'warning');
        }
    });

    $('#edit-payment-form').submit(function(e) {
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

        var mainAmount = parseFloat($('#amount').val().replace(/,/g, '')) || 0;

        if (Math.abs(totalSplits - mainAmount) > 0.01) {
            alert_toast('La suma de los medios de pago (' + totalSplits.toFixed(2) + ') debe ser igual al Monto Total (' + mainAmount.toFixed(2) + ').', 'warning');
            return;
        }

        if (splits.length === 0) {
            alert_toast('Debe agregar al menos un medio de pago válido.', 'warning');
            return;
        }

        $('#payment_splits').val(JSON.stringify(splits));
        $('#payment_method_id').val(firstMethodId);

        start_load();

        $.ajax({
            url: 'ajax.php?action=save_payment',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                if (resp.status == 1) {
                    alert_toast('Pago actualizado con éxito.', 'success');
                    setTimeout(function() {
                        location.href = 'index.php?page=payments';
                    }, 1200);
                } else {
                    alert_toast(resp.message || 'Error al actualizar el pago.', 'danger');
                    end_load();
                }
            },
            error: function(xhr) {
                console.error('Error en la solicitud:', xhr.responseText);
                alert_toast('Error en el servidor. Revise la consola para más detalles.', 'danger');
                end_load();
            }
        });
    });
});
</script>
