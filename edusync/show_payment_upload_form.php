<?php include 'db_connect.php' ?>
<?php include_once 'includes/session_check.php'; require_login_modal(); ?>
<style>
.file-input-wrapper input[type="file"] {
    display: none;
}
</style>

<div class="container-fluid">
    <form id="upload-payment-form" enctype="multipart/form-data">
        
        <!-- Mensaje informativo -->
        <div class="alert alert-info" role="alert">
            <i class="fas fa-info-circle mr-2"></i>
            Suba un archivo Excel con la información de estudiantes. Asegúrese de usar el formato correcto. 
            Puede <a href="download_payment_format.php" target="_blank" class="alert-link">descargar el formato aquí</a>.
        </div>

        <!-- Seleccionar archivo -->
        <div class="form-group">
            <label><strong>Seleccionar archivo Excel:</strong></label>
            <div class="input-group">
                <input type="text" class="form-control" id="file-name-display" placeholder="Seleccionar archivo..." readonly>
                <div class="input-group-append">
                    <button type="button" class="btn btn-primary" onclick="$('#payment_excel_file').click()">
                        <i class="fas fa-folder-open"></i> Explorar
                    </button>
                </div>
            </div>
            <input type="file" id="payment_excel_file" name="payment_excel_file" accept=".xls,.xlsx" required style="display:none;">
            <small class="form-text text-muted">Formatos permitidos: .xls, .xlsx</small>
        </div>

        <!-- Botones de acción -->
        <div class="text-right">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="submit" class="btn btn-success" id="btn-upload" disabled>
                <i class="fas fa-upload"></i> Subir Excel
            </button>
        </div>
    </form>
</div>

<script>
$(document).ready(function() {
    // Manejar selección de archivo
    $('#payment_excel_file').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        
        if (fileName) {
            $('#file-name-display').val(fileName);
            $('#btn-upload').prop('disabled', false);
        } else {
            $('#file-name-display').val('');
            $('#btn-upload').prop('disabled', true);
        }
    });
    
    // Manejar clic en el área de upload
    $('#upload-area').on('click', function(e) {
        if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'LABEL') {
            $('#payment_excel_file').click();
        }
    });
    
    // Manejar envío del formulario
    $('#upload-payment-form').submit(function(e) {
        e.preventDefault();
        
        // Validar que hay archivo
        var fileInput = $('#payment_excel_file')[0];
        if (!fileInput.files || fileInput.files.length === 0) {
            alert_toast('Por favor seleccione un archivo Excel', 'warning');
            return;
        }
        
        // Validar extensión
        var fileName = fileInput.files[0].name;
        var ext = fileName.split('.').pop().toLowerCase();
        if (ext !== 'xls' && ext !== 'xlsx') {
            alert_toast('Por favor seleccione un archivo Excel válido (.xls o .xlsx)', 'warning');
            return;
        }
        
        // Deshabilitar botón y mostrar carga
        $('#btn-upload').prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-2"></i> Procesando...');
        start_load();
        
        var formData = new FormData(this);
        
        $.ajax({
            url: 'ajax.php?action=process_payment_excel',
            method: 'POST',
            data: formData,
            cache: false,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(resp) {
                end_load();
                
                if (resp.status === 1) {
                    // Mostrar resumen del proceso
                    var message = resp.message;
                    
                    // Construir detalles adicionales si existen
                    if (resp.details) {
                        var details = '\n\nAsignados: ' + (resp.details.assigned || 0);
                        details += '\nOmitidos: ' + (resp.details.skipped || 0);
                        details += '\nErrores: ' + (resp.details.errors || 0);
                        
                        if (resp.details.error_list && resp.details.error_list.length > 0) {
                            details += '\n\nPrimeros errores:\n';
                            resp.details.error_list.forEach(function(err) {
                                details += '• ' + err + '\n';
                            });
                        }
                        
                        message += details;
                    }
                    
                    alert_toast(message, 'success');
                    
                    setTimeout(function() {
                        $('#uni_modal').modal('hide');
                        if (window.reload_table) window.reload_table();
                        else location.reload();
                    }, 2000);
                } else {
                    alert_toast(resp.message || 'Error al procesar el archivo.', 'danger');
                    
                    // Restaurar botón
                    $('#btn-upload').prop('disabled', false).html('<i class="fa fa-upload mr-2"></i> Subir y Procesar Excel');
                }
            },
            error: function(xhr, status, error) {
                end_load();
                console.error('Error:', error);
                console.error('Response:', xhr.responseText);
                
                alert_toast('Error en el servidor. Verifique el formato del archivo e intente nuevamente.', 'danger');
                
                // Restaurar botón
                $('#btn-upload').prop('disabled', false).html('<i class="fa fa-upload mr-2"></i> Subir y Procesar Excel');
            }
        });
    });
    
    // Drag and drop support
    $('#upload-area').on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('active');
    });
    
    $('#upload-area').on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('active');
    });
    
    $('#upload-area').on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('active');
        
        var files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            $('#payment_excel_file')[0].files = files;
            $('#payment_excel_file').trigger('change');
        }
    });
});
</script>
