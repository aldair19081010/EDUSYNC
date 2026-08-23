<?php
include 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();

// Obtener el school_id del administrador
$school_id = $_SESSION['login_school_id'] ?? 0;

if(isset($_GET['id'])){
    // Verificar que el curso pertenezca al colegio del administrador
    $qry = $conn->query("SELECT * FROM academic_courses WHERE id = ".$_GET['id']." AND school_id = $school_id");
    foreach($qry->fetch_array() as $k => $val){
        $$k = $val;
    }
} else {
    // Si es un nuevo curso, verificar si se pasaron parámetros para pre-llenar
    if(isset($_GET['area_id'])) {
        $area_id = $_GET['area_id'];
    }
    if(isset($_GET['level'])) {
        $level = $_GET['level'];
    }
}
?>
<style>
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
    
    .alert {
        border-radius: 0.35rem;
        border-left: 4px solid #4e73df;
        background-color: #eef2ff;
        color: #2e3e50;
        padding: 12px;
        margin-top: 20px;
    }
    
    .alert-info {
        border-left-color: #4e73df;
        background-color: #eef2ff;
        color: #2e3e50;
    }
    
    .alert i {
        margin-right: 8px;
        color: #4e73df;
    }
    
    .level-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        margin-top: 4px;
    }
    
    .level-badge-inicial {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .level-badge-primaria {
        background-color: #d1ecf1;
        color: #0c5460;
    }
    
    .level-badge-secundaria {
        background-color: #d4edda;
        color: #155724;
    }
</style>

<div class="container-fluid">
    <form id="manage-academic-course">
        <input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
        <input type="hidden" name="school_id" value="<?php echo $school_id ?>">
        
        <div class="form-group">
            <label for="course_name">Nombre del Curso / Capacidad <span class="text-danger">*</span></label>
            <input type="text" id="course_name" name="name" class="form-control" 
                   value="<?php echo isset($name) ? htmlspecialchars($name) : '' ?>" 
                   placeholder="Ej: Matemática Básica o Capacidad de Razonamiento Lógico" required>
            <small class="text-muted">Nombre descriptivo del curso o capacidad a enseñar</small>
        </div>
        
        <div class="form-group">
            <label for="course_area">Área Académica <span class="text-danger">*</span></label>
            <select id="course_area" name="area_id" class="form-control" required>
                <option value="">-- Selecciona un área --</option>
                <?php
                $areas = $conn->query("SELECT * FROM areas WHERE school_id = $school_id AND is_active = 1 ORDER BY name ASC");
                while($area = $areas->fetch_assoc()):
                ?>
                <option value="<?php echo $area['id'] ?>" 
                        data-color="<?php echo $area['color'] ?>"
                        <?php echo (isset($area_id) && $area_id == $area['id']) ? 'selected' : '' ?>>
                    <?php echo htmlspecialchars($area['name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
            <small class="text-muted">Selecciona el área a la que pertenece este curso</small>
        </div>
        
        <div class="form-group">
            <label for="course_level">Nivel <span class="text-danger">*</span></label>
            <select id="course_level" name="level" class="form-control" required>
                <option value="">-- Selecciona un nivel --</option>
                <option value="Inicial" <?php echo (isset($level) && $level == 'Inicial') ? 'selected' : '' ?>>
                    <i class="fa fa-baby"></i> Inicial
                </option>
                <option value="Primaria" <?php echo (isset($level) && $level == 'Primaria') ? 'selected' : '' ?>>
                    <i class="fa fa-child"></i> Primaria
                </option>
                <option value="Secundaria" <?php echo (isset($level) && $level == 'Secundaria') ? 'selected' : '' ?>>
                    <i class="fa fa-graduation-cap"></i> Secundaria
                </option>
            </select>
            <small class="text-muted">Selecciona el nivel educativo del curso</small>
        </div>
        
        <div class="form-group">
            <label for="course_description">Descripción</label>
            <textarea id="course_description" name="description" class="form-control" rows="3" 
                      placeholder="Describe el curso, objetivos, contenidos...<?php echo isset($description) ? htmlspecialchars($description) : '' ?>"><?php echo isset($description) ? htmlspecialchars($description) : '' ?></textarea>
            <small class="text-muted">Descripción detallada del curso o capacidad (opcional)</small>
        </div>
        
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>Nota:</strong> Aquí puedes crear tanto <strong>cursos académicos</strong> como <strong>capacidades</strong>. 
            Los cursos representan materias tradicionales, mientras que las capacidades son habilidades específicas que se evalúan.
        </div>
        
        <div class="form-group mt-4">
            <button type="submit" class="btn btn-primary" id="btn_save_course">
                <i class="fa fa-save"></i> <?php echo isset($id) ? 'Actualizar Curso' : 'Crear Curso' ?>
            </button>
            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                <i class="fa fa-times"></i> Cancelar
            </button>
        </div>
    </form>
</div>

<script>
$(document).ready(function() {
    // Asegurar que los botones del footer estén visibles para este modal
    $('#uni_modal .modal-footer').show();
    
    // Actualizar vista previa del área seleccionada
    $('#course_area').on('change', function() {
        var selectedOption = $(this).find('option:selected');
        var color = selectedOption.data('color');
        if (color) {
            $(this).css('border-left', '4px solid ' + color);
        }
    }).trigger('change');
});

$('#manage-academic-course').submit(function(e){
    e.preventDefault();
    
    // Validación básica
    var name = $('input[name="name"]').val();
    var area_id = $('select[name="area_id"]').val();
    var level = $('select[name="level"]').val();
    
    if (!name || !area_id || !level) {
        alert_toast("Por favor completa los campos obligatorios", 'warning');
        return false;
    }
    
    start_load();
    $.ajax({
        url: 'ajax.php?action=save_academic_course',
        method: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(resp){
            end_load();
            if(resp.status == 1){
                alert_toast("Curso / Capacidad guardado exitosamente", 'success');
                setTimeout(function(){
                    // Actualizar todas las vistas dinámicamente
                    if (typeof window.updateMainTablesSafely === 'function') {
                        window.updateMainTablesSafely();
                    }
                    // Cerrar el modal
                    $('#uni_modal').modal('hide');
                }, 1000);
            } else {
                alert_toast(resp.message || resp.msg || "Ocurrió un error", 'danger');
            }
        },
        error: function(xhr, status, error){
            end_load();
            console.error("Error en la solicitud AJAX:", error);
            alert_toast("Error en el servidor. Intente nuevamente.", 'danger');
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
                console.log('Tooltips inicializados correctamente en manage_academic_course');
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
