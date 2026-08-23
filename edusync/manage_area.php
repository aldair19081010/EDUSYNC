<?php
include 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();

// Obtener el school_id del administrador
$school_id = $_SESSION['login_school_id'] ?? 0;

if(isset($_GET['id'])){
    // Verificar que el área pertenezca al colegio del administrador
    $qry = $conn->query("SELECT * FROM areas WHERE id = ".$_GET['id']." AND school_id = $school_id");
    foreach($qry->fetch_array() as $k => $val){
        $$k = $val;
    }
}
?>
<style>
    .color-input-group {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .color-preview {
        width: 50px;
        height: 50px;
        border-radius: 0.35rem;
        border: 2px solid #e3e6f0;
        cursor: pointer;
    }
    
    .form-group label {
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
    }
    
    .form-control:focus {
        border-color: #4e73df;
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
    }
    
    .form-info {
        background: #f8f9fc;
        border-left: 4px solid #4e73df;
        padding: 12px;
        border-radius: 0.35rem;
        margin-top: 20px;
    }
    
    .form-info-row {
        display: flex;
        justify-content: space-between;
        gap: 20px;
    }
    
    .form-info-item {
        flex: 1;
    }
    
    .form-info-label {
        font-size: 0.85rem;
        color: #858796;
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 4px;
    }
    
    .form-info-value {
        font-size: 0.9rem;
        color: #495057;
    }
</style>

<div class="container-fluid">
    <form id="manage-area">
        <input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
        <input type="hidden" name="school_id" value="<?php echo $school_id ?>">
        
        <div class="form-group">
            <label for="area_name">Nombre del Área <span class="text-danger">*</span></label>
            <input type="text" id="area_name" name="name" class="form-control" value="<?php echo isset($name) ? htmlspecialchars($name) : '' ?>" placeholder="Ej: Matemática, Lenguaje, Ciencias..." required>
            <small class="text-muted">Nombre descriptivo del área académica</small>
        </div>
        
        <div class="form-group">
            <label for="area_description">Descripción</label>
            <textarea id="area_description" name="description" class="form-control" rows="3" placeholder="Describe los objetivos y contenidos de esta área..."><?php echo isset($description) ? htmlspecialchars($description) : '' ?></textarea>
            <small class="text-muted">Descripción detallada del área (opcional)</small>
        </div>
        
        <div class="form-group">
            <label>Color de Identificación <span class="text-danger">*</span></label>
            <div class="color-input-group">
                <div class="input-group" style="flex: 1; max-width: 300px;">
                    <input type="color" id="area_color" name="color" class="form-control form-control-color" value="<?php echo isset($color) ? $color : '#4e73df' ?>" style="cursor: pointer; height: 45px; padding: 3px;" title="Selecciona un color">
                    <input type="text" id="area_color_text" name="color_text" class="form-control" value="<?php echo isset($color) ? $color : '#4e73df' ?>" readonly>
                </div>
                <div class="color-preview" id="color_preview" style="background-color: <?php echo isset($color) ? $color : '#4e73df' ?>;" title="Vista previa del color"></div>
            </div>
            <small class="text-muted">Selecciona un color para identificar visualmente esta área</small>
        </div>
        
        <div class="form-group">
            <label for="area_status">Estado <span class="text-danger">*</span></label>
            <select id="area_status" name="is_active" class="form-control" required>
                <option value="">-- Selecciona un estado --</option>
                <option value="1" <?php echo (isset($is_active) && $is_active == 1) ? 'selected' : '' ?>>Activo</option>
                <option value="0" <?php echo (isset($is_active) && $is_active == 0) ? 'selected' : '' ?>>Inactivo</option>
            </select>
        </div>
        
        <?php if(isset($id)): ?>
        <div class="form-info">
            <div class="form-info-label mb-2">Información Adicional</div>
            <div class="form-info-row">
                <div class="form-info-item">
                    <div class="form-info-label">Creado:</div>
                    <div class="form-info-value"><?php echo isset($created_at) ? date('d/m/Y H:i', strtotime($created_at)) : 'N/A' ?></div>
                </div>
                <div class="form-info-item">
                    <div class="form-info-label">Última actualización:</div>
                    <div class="form-info-value"><?php echo isset($updated_at) ? date('d/m/Y H:i', strtotime($updated_at)) : 'N/A' ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="form-group mt-4">
            <button type="submit" class="btn btn-primary" id="btn_save_area">
                <i class="fa fa-save"></i> <?php echo isset($id) ? 'Actualizar Área' : 'Crear Área' ?>
            </button>
            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                <i class="fa fa-times"></i> Cancelar
            </button>
        </div>
    </form>
</div>

<script>
$(document).ready(function() {
    // Sincronizar el input de color con el texto y la vista previa
    $('#area_color').on('input', function() {
        var colorValue = $(this).val();
        $('#area_color_text').val(colorValue);
        $('#color_preview').css('background-color', colorValue);
    });
    
    // Sincronizar el texto con el input de color
    $('#area_color_text').on('input', function() {
        var colorValue = $(this).val();
        if(/^#[0-9A-F]{6}$/i.test(colorValue)) {
            $('#area_color').val(colorValue);
            $('#color_preview').css('background-color', colorValue);
        }
    });
    
    // Sincronizar la vista previa con el color inicial
    $('#color_preview').css('background-color', $('#area_color').val());
});

// Asegurar que los botones del footer estén visibles para este modal
$('#uni_modal .modal-footer').show();

$('#manage-area').submit(function(e){
    e.preventDefault();
    start_load();
    $.ajax({
        url: 'ajax.php?action=save_area',
        method: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(resp){
            end_load();
            if(resp.status == 1){
                alert_toast("Área guardada exitosamente", "success");
                setTimeout(function(){
                    // Actualizar todas las vistas dinámicamente
                    if (typeof window.updateMainTablesSafely === 'function') {
                        window.updateMainTablesSafely();
                    }
                    // Cerrar el modal
                    $('#uni_modal').modal('hide');
                }, 1000);
            } else {
                alert_toast("Error al guardar el área: " + (resp.msg || resp.message || "Error desconocido"), "error");
            }
        },
        error: function(){
            end_load();
            alert_toast("Error de conexión", "error");
        }
    });
});
</script>

<!-- Script para inicializar tooltips después de que cargue Bootstrap -->
<script>
$(function() {
    // Inicializar tooltips con reintentos
    var tooltipAttempts = 0;
    var tooltipInterval = setInterval(function() {
        try {
            if (typeof $.fn.tooltip === 'function') {
                $('[data-toggle="tooltip"]').tooltip({
                    delay: { show: 100, hide: 100 }
                });
                clearInterval(tooltipInterval);
                console.log('Tooltips inicializados correctamente en manage_area');
            } else if (tooltipAttempts++ < 10) {
                console.log('Esperando que Bootstrap cargue...');
            } else {
                console.error('No se pudo cargar Bootstrap Tooltip');
                clearInterval(tooltipInterval);
            }
        } catch(e) {
            console.log('Error al inicializar tooltips:', e);
            if (tooltipAttempts++ >= 10) {
                clearInterval(tooltipInterval);
            }
        }
    }, 100);
});
</script>
