

<?php
$from = isset($_GET['from']) ? $_GET['from'] : '';
include_once 'includes/session_check.php';
require_login_modal();

require_once('admin_class.php');
$crud = new Action();
$meta = [];
if (isset($_GET['id'])) {
    $meta = $crud->get_discount($_GET['id']);
}

$students = [];
$students_json = $crud->get_all_students();
if ($students_json) {
    $students_arr = json_decode($students_json, true);
    if (isset($students_arr['status']) && $students_arr['status'] == 1 && isset($students_arr['students'])) {
        $students = $students_arr['students'];
    }
}
?>

<div class="container-fluid py-2">
    <form id="manage-discount" autocomplete="off">
        <input type="hidden" name="id" value="<?php echo isset($meta['id']) ? $meta['id'] : ''; ?>">
        <input type="hidden" name="ef_id" value="<?php echo isset($meta['id']) ? $meta['id'] : ''; ?>">

        <?php if (!empty($meta)): ?>
        <div class="card border-left-primary shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Concepto en edición</div>
                <div class="mb-1"><strong><?php echo $meta['concepto_concatenado'] ?? ($meta['course'] ?? ''); ?></strong></div>
                <div class="small text-muted"><i class="fa fa-user mr-1"></i><?php echo ucwords($meta['student_name']); ?></div>
                <div class="small text-muted"><i class="fa fa-id-card mr-1"></i><?php echo $meta['id_no']; ?> · <i class="fa fa-graduation-cap mr-1"></i><?php echo $meta['nivel']; ?> - <?php echo $meta['grado']; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label class="font-weight-bold" for="student_id">Alumno</label>
            <?php if (empty($students)): ?>
                <?php
                    // Diagnóstico: mostrar valores de sesión y user.school_id para ayudar debugging
                    $sess_login_id = $_SESSION['login_id'] ?? null;
                    $sess_school = $_SESSION['login_school_id'] ?? null;
                    $user_school_db = null;
                    if ($sess_login_id) {
                        $q = $conn->query("SELECT school_id FROM users WHERE id = " . intval($sess_login_id) . " LIMIT 1");
                        if ($q && $q->num_rows > 0) $user_school_db = $q->fetch_assoc()['school_id'];
                    }
                ?>
                <div class="alert alert-warning">
                    No hay alumnos disponibles. Verifique que está logueado y que su sesión tenga un colegio activo.<br>
                    <small class="text-muted">Debug: login_id=<?php echo htmlspecialchars($sess_login_id ?? 'null'); ?>, session_school_id=<?php echo htmlspecialchars($sess_school ?? 'null'); ?>, users.school_id=<?php echo htmlspecialchars($user_school_db ?? 'null'); ?></small>
                </div>
                <select name="student_id" id="discount_student_id" class="form-control select2" disabled>
                    <option value="">No hay alumnos disponibles</option>
                </select>
            <?php else: ?>
                <select name="student_id" id="discount_student_id" class="form-control select2" required <?php echo isset($meta['student_id']) ? 'disabled' : ''; ?>>
                    <option value="">Seleccione un alumno</option>
                    <?php foreach ($students as $row): ?>
                        <option value="<?php echo $row['id']; ?>" <?php echo isset($meta['student_id']) && $meta['student_id'] == $row['id'] ? 'selected' : ''; ?>>
                            <?php echo $row['id_no'] . ' - ' . $row['name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="font-weight-bold" for="ef_id">Concepto de pago</label>
            <select name="ef_id" id="discount_ef_id" class="form-control select2" required <?php echo isset($meta['id']) ? 'disabled' : ''; ?>>
                <option value="">Seleccione un concepto</option>
                <?php if(isset($meta['id'])): ?>
                    <option value="<?php echo $meta['id']; ?>" selected data-total="<?php echo isset($meta['total_fee']) ? $meta['total_fee'] : ''; ?>">
                        <?php echo $meta['concepto_concatenado'] ?? ($meta['course'] ?? ''); ?>
                    </option>
                <?php endif; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="font-weight-bold" for="discount_amount">Monto con descuento</label>
            <input type="number" name="discount_amount" id="discount_amount" class="form-control text-right" step="0.01" min="0" value="<?php echo isset($meta['discounted_amount']) ? $meta['discounted_amount'] : ''; ?>" required>
            <small class="form-text text-muted">Monto final que pagará el alumno después del descuento/beca.</small>
        </div>

        <div class="form-group" id="original_fee_display" style="display: none;">
            <label class="font-weight-bold">Monto original</label>
            <div class="form-control-plaintext" id="original_fee_amount"></div>
        </div>

        <div class="form-group">
            <label class="font-weight-bold" for="reason">Motivo/Notas</label>
            <textarea name="reason" id="reason" cols="30" rows="2" class="form-control" placeholder="Opcional: tipo de beca, motivo, etc."><?php echo isset($meta['reason']) ? $meta['reason'] : ''; ?></textarea>
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

<script>
$(document).ready(function(){
    $('.select2').select2({
        placeholder: 'Seleccione una opción',
        width: '100%',
        dropdownParent: $('#uni_modal')
    });

    var isEdit = <?php echo isset($meta['id']) ? 'true' : 'false'; ?>;

    function loadConceptsForStudent(student_id) {
        $('#discount_ef_id').html('<option value="">Cargando...</option>');
        if(student_id) {
            $.ajax({
                url: 'ajax.php?action=get_pending_concepts',
                method: 'POST',
                data: {student_id: student_id},
                success: function(resp) {
                    try {
                        if(typeof resp === 'string') resp = JSON.parse(resp);
                        var options = '<option value="">Seleccione un concepto</option>';
                        (resp.data || []).forEach(function(item) {
                            options += `<option value="${item.ef_id}" data-total="${item.total_fee}">${item.concepto_concatenado}</option>`;
                        });
                        $('#discount_ef_id').html(options).prop('disabled', false).trigger('change.select2');
                    } catch(e) {
                        $('#discount_ef_id').html('<option value="">Error al cargar conceptos</option>');
                    }
                },
                error: function() {
                    $('#discount_ef_id').html('<option value="">Error al cargar conceptos</option>');
                }
            });
        } else {
            $('#discount_ef_id').html('<option value="">Seleccione un concepto</option>');
        }
    }

    $('#discount_student_id').on('change', function(){
        if (isEdit) return; // no recargar en edición
        var student_id = $(this).val();
        loadConceptsForStudent(student_id);
        $('#discount_ef_id').val('').trigger('change');
    });

    $('#discount_ef_id').on('change', function(){
        var total = $('#discount_ef_id option:selected').attr('data-total');
        if(total) {
            var totalNum = parseFloat(total) || 0;
            $('#original_fee_amount').text('S/ ' + totalNum.toFixed(2));
            $('#original_fee_display').show();
            $('#discount_amount').attr('max', totalNum);
        } else {
            $('#original_fee_display').hide();
            $('#discount_amount').removeAttr('max');
        }
    });

    // Si está en modo edición, mostrar monto original
    <?php if(isset($meta['id']) && isset($meta['total_fee'])): ?>
        $('#original_fee_amount').text('S/ <?php echo number_format($meta['total_fee'], 2); ?>');
        $('#original_fee_display').show();
        $('#discount_amount').attr('max', '<?php echo $meta['total_fee']; ?>');
    <?php endif; ?>
});

$('#manage-discount').submit(function(e){
    e.preventDefault();
    start_load();
    $.ajax({
        url: 'ajax.php?action=save_discount',
        data: new FormData($(this)[0]),
        cache: false,
        contentType: false,
        processData: false,
        method: 'POST',
        success: function(resp){
            if(resp == 1){
                alert_toast('Descuento guardado correctamente', 'success');
                <?php if($from == 'payment'): ?>
                    setTimeout(function(){ 
                        $('.modal').modal('hide'); 
                        if ($('#manage-payment select[name="student_id"]').length > 0) {
                            $('#manage-payment select[name="student_id"]').trigger('change');
                        }
                        end_load();
                    }, 750);
                <?php else: ?>
                    setTimeout(function(){ location.replace('index.php?page=discounts'); }, 750);
                <?php endif; ?>
            } else if(resp == 2) {
                alert_toast('El alumno ya tiene descuento para este concepto', 'error');
                end_load();
            } else {
                alert_toast('Ocurrió un error', 'error');
                end_load();
            }
        },
        error: function(){
            alert_toast('Error en el servidor', 'error');
            end_load();
        }
    });
});
</script>
