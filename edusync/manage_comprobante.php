<?php
include 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$school_id = $_SESSION['login_school_id'] ?? 0;

$students = $conn->query("SELECT id, id_no, name as nombre, address, email FROM student WHERE school_id = $school_id ORDER BY name ASC");
?>
<div class="container-fluid">
    <form id="emitir-comprobante-form">
        <!-- TIPO DE COMPROBANTE -->
        <div class="row custom-section">
            <div class="col-md-6 form-group">
                <label class="font-weight-bold">Tipo de Comprobante</label>
                <select name="tipo_comprobante" id="tipo_comprobante" class="form-control" required>
                    <option value="03" selected>BOLETA DE VENTA ELECTRÓNICA</option>
                    <option value="01">FACTURA ELECTRÓNICA</option>
                </select>
            </div>
            <div class="col-md-6 form-group">
                <label class="font-weight-bold">Moneda</label>
                <select name="moneda" class="form-control" readonly>
                    <option value="PEN" selected>SOLES (S/)</option>
                </select>
            </div>
        </div>
        
        <hr>

        <!-- DATOS DEL CLIENTE -->
        <h6 class="font-weight-bold text-primary mb-3">Datos del Cliente</h6>
        
        <div class="form-group mb-3">
            <label>Autocompletar con Estudiante (Opcional)</label>
            <select id="auto_student" class="form-control select2" style="width: 100%;">
                <option value="">-- Escribir datos manualmente --</option>
                <?php while($s = $students->fetch_assoc()): ?>
                    <option value="<?php echo $s['id'] ?>" 
                            data-doc="<?php echo $s['id_no'] ?>" 
                            data-nom="<?php echo htmlspecialchars($s['nombre']) ?>"
                            data-dir="<?php echo htmlspecialchars($s['address'] ?? '') ?>"
                            data-email="<?php echo htmlspecialchars($s['email'] ?? '') ?>">
                        <?php echo $s['id_no'] . ' - ' . htmlspecialchars($s['nombre']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <input type="hidden" name="student_id" id="student_id_hidden" value="">
        </div>

        <div class="row">
            <div class="col-md-4 form-group">
                <label class="font-weight-bold">Tipo Doc.</label>
                <select name="cliente_tipo_doc" id="cliente_tipo_doc" class="form-control" required>
                    <option value="1">DNI</option>
                    <option value="6">RUC</option>
                    <option value="4">CARNET EXT.</option>
                </select>
            </div>
            <div class="col-md-8 form-group">
                <label class="font-weight-bold">Número de Documento *</label>
                <div class="input-group">
                    <input type="text" name="cliente_num_doc" id="cliente_num_doc" class="form-control" required maxlength="15">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-outline-secondary" id="btn-api-search" title="Buscar en RENIEC/SUNAT">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label class="font-weight-bold">Razón Social / Nombres y Apellidos *</label>
            <input type="text" name="cliente_razon_social" id="cliente_razon_social" class="form-control" required>
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label>Dirección (Opcional)</label>
                <input type="text" name="cliente_direccion" id="cliente_direccion" class="form-control">
            </div>
            <div class="col-md-6 form-group">
                <label>Correo Electrónico (Opcional)</label>
                <input type="email" name="cliente_email" id="cliente_email" class="form-control">
            </div>
        </div>

        <hr>

        <!-- DETALLES (ITEMS) -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="font-weight-bold text-primary mb-0">Detalle de Comprobante</h6>
            <button type="button" class="btn btn-sm btn-success" id="btn-add-item"><i class="fa fa-plus"></i> Añadir Concepto</button>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm" id="items-table">
                <thead class="bg-light">
                    <tr>
                        <th width="40%">Descripción</th>
                        <th width="15%">Cantidad</th>
                        <th width="20%">Precio Unit. (S/)</th>
                        <th width="15%">Total (S/)</th>
                        <th width="10%"></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Item template -->
                    <tr>
                        <td><input type="text" name="item_desc[]" class="form-control form-control-sm i-desc" placeholder="Ej: Pensión Marzo" required></td>
                        <td><input type="number" name="item_qty[]" class="form-control form-control-sm text-center i-qty" value="1" min="1" required></td>
                        <td><input type="number" name="item_price[]" class="form-control form-control-sm text-right i-price" value="0.00" step="0.01" min="0.01" required></td>
                        <td class="text-right font-weight-bold align-middle i-total">0.00</td>
                        <td class="text-center align-middle">
                            <button type="button" class="btn btn-sm btn-danger btn-remove-item"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" class="text-right">IMPORTE TOTAL (S/):</th>
                        <th class="text-right font-weight-bold" id="gran_total" style="font-size: 1.2rem;">0.00</th>
                        <th><input type="hidden" name="total_amount" id="total_amount_input" value="0"></th>
                    </tr>
                </tfoot>
            </table>
            <small class="text-muted"><i class="fa fa-info-circle"></i> Los servicios educativos de colegios están inafectos al IGV por defecto.</small>
        </div>

        <div class="mt-4 text-right">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary" id="btn-save"><i class="fa fa-paper-plane"></i> Generar y Enviar a SUNAT</button>
        </div>
    </form>
</div>

<script>
$(document).ready(function(){
    // Iniciar Select2 en el modal
    $('#auto_student').select2({
        dropdownParent: $('#uni_modal .modal-content'),
        width: '100%'
    });

    // Auto completado al elegir estudiante
    $('#auto_student').change(function(){
        let opt = $(this).find('option:selected');
        if(opt.val() != '') {
            $('#student_id_hidden').val(opt.val());
            $('#cliente_num_doc').val(opt.attr('data-doc'));
            $('#cliente_razon_social').val(opt.attr('data-nom'));
            $('#cliente_direccion').val(opt.attr('data-dir'));
            $('#cliente_email').val(opt.attr('data-email'));
            
            // Si el DNI tiene 11 digitos, asume RUC
            if (opt.attr('data-doc') && opt.attr('data-doc').length == 11) {
                $('#cliente_tipo_doc').val('6');
                $('#tipo_comprobante').val('01'); // Auto-Factura
            } else {
                $('#cliente_tipo_doc').val('1'); // DNI
            }
        } else {
            $('#student_id_hidden').val('');
            $('#cliente_num_doc, #cliente_razon_social, #cliente_direccion, #cliente_email').val('');
        }
    });

    // Auto-select Tipo Doc segun Comprobante
    $('#tipo_comprobante').change(function() {
        if ($(this).val() == '01') { // Factura
            $('#cliente_tipo_doc').val('6'); // RUC
        } else {
            if ($('#cliente_tipo_doc').val() == '6') {
                $('#cliente_tipo_doc').val('1'); // DNI
            }
        }
    });

    // Validacion de longitud
    $('#cliente_tipo_doc').change(function() {
        if ($(this).val() == '6') {
            $('#cliente_num_doc').attr('maxlength', '11');
            $('#tipo_comprobante').val('01');
        } else if ($(this).val() == '1') {
            $('#cliente_num_doc').attr('maxlength', '8');
            $('#tipo_comprobante').val('03');
        }
    });

    // API Busqueda DNI/RUC
    $('#btn-api-search').click(function() {
        var ndoc = $('#cliente_num_doc').val();
        var tdoc = $('#cliente_tipo_doc').val();
        
        if (ndoc.length < 8) return alert_toast('Ingrese documento válido', 'warning');
        
        start_load();
        // Lógica simulada o reemplazar por API REAL (ej. apisnet)
        // Como Edusync no parece tener un backend activo para consultar RENIEC en el archivo provisto, solo simularemos para RUC.
        setTimeout(function() {
            end_load();
            alert_toast("Búsqueda API no configurada en el Demo.", "info");
        }, 500);
    });

    // LÓGICA DE TABLA DE ÍTEMS
    function setCalculations() {
        let gt = 0;
        $('#items-table tbody tr').each(function(){
            let q = parseFloat($(this).find('.i-qty').val()) || 0;
            let p = parseFloat($(this).find('.i-price').val()) || 0;
            let t = q * p;
            $(this).find('.i-total').text(t.toFixed(2));
            gt += t;
        });
        $('#gran_total').text(gt.toFixed(2));
        $('#total_amount_input').val(gt.toFixed(2));
    }

    $(document).on('input', '.i-qty, .i-price', function() {
        setCalculations();
    });

    $('#btn-add-item').click(function() {
        let tr = $('#items-table tbody tr:first').clone();
        tr.find('input').val('');
        tr.find('.i-qty').val('1');
        tr.find('.i-price').val('0.00');
        tr.find('.i-total').text('0.00');
        $('#items-table tbody').append(tr);
    });

    $(document).on('click', '.btn-remove-item', function() {
        if ($('#items-table tbody tr').length > 1) {
            $(this).closest('tr').remove();
            setCalculations();
        } else {
            alert_toast('Debe haber al menos un ítem', 'warning');
        }
    });

    // ENVÍO DEL FORMULARIO
    $('#emitir-comprobante-form').submit(function(e){
        e.preventDefault();
        
        // Validar tipo vs doc
        if ($('#tipo_comprobante').val() == '01' && $('#cliente_tipo_doc').val() != '6') {
            alert_toast("Para Factura, el documento debe ser RUC (6).", "error");
            return false;
        }

        if ($('#total_amount_input').val() <= 0) {
            alert_toast("El total debe ser mayor a 0", "error");
            return false;
        }

        start_load();
        $('#btn-save').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Enviando a SUNAT...');
        
        $.ajax({
            url: 'ajax.php?action=emitir_comprobante_libre',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alert_toast('¡Comprobante Emitido y Aceptado!', 'success');
                    setTimeout(function(){
                        $('.modal').modal('hide');
                        if (typeof buscarComprobantes === 'function') buscarComprobantes();
                    }, 1500);
                } else {
                    alert_toast(resp.message || 'Error desconocido al facturar', 'error');
                    $('#btn-save').prop('disabled', false).html('<i class="fa fa-paper-plane"></i> Generar y Enviar a SUNAT');
                }
            },
            error: function(err) {
                console.log(err);
                alert_toast('Error de conexión con el backend', 'error');
                end_load();
                $('#btn-save').prop('disabled', false).html('<i class="fa fa-paper-plane"></i> Generar y Enviar a SUNAT');
            }
        });
    });
});
</script>
