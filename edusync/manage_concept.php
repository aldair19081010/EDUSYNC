
<?php
include_once 'includes/session_check.php';
require_login_modal();
include 'db_connect.php';

// Comprobar permisos
if(!isset($_SESSION['login_id']) || (isset($_SESSION['login_type']) && $_SESSION['login_type'] != 1)){
    echo '<div class="alert alert-danger m-3">No tiene permisos para acceder a esta sección.</div>';
    exit;
}

$school_id = $_SESSION['login_school_id'] ?? 1;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Si es edición, obtener datos del concepto
if ($id) {
    $qry = $conn->query("SELECT * FROM courses WHERE id = $id");
    if ($qry && $qry->num_rows > 0) {
        $data = $qry->fetch_assoc();
        $course = $data['course'] ?? '';
        $level = $data['level'] ?? '';
        $grades = $data['grades'] ?? '';
        $academic_year_id = $data['academic_year_id'] ?? '';
        $description = $data['description'] ?? '';
        $total_amount = $data['total_amount'] ?? '';
    } else {
        echo '<div class="alert alert-danger m-3">Concepto no encontrado.</div>';
        exit;
    }
}

// Obtener años académicos disponibles
$academic_years = $conn->query("SELECT * FROM academic_year WHERE school_id = $school_id ORDER BY year DESC");

// Definir grados disponibles por nivel
$grades_by_level = [
    'Inicial' => ['3°', '4°', '5°'],
    'Primaria' => ['1°', '2°', '3°', '4°', '5°', '6°'],
    'Secundaria' => ['1°', '2°', '3°', '4°', '5°']
];
?>

<div class="p-4">
    <div id="msg" class="form-group mb-3"></div>
    
    <form id="manage-concept">
        <input type="hidden" name="id" value="<?php echo $id ?? ''; ?>">
        
        <!-- Concepto -->
        <div class="form-group">
            <label for="course" class="font-weight-bold">Concepto <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="course" id="course" 
                   value="<?php echo isset($course) ? htmlspecialchars($course) : ''; ?>" 
                   placeholder="Ej: Pensión Mensual" required>
            <small class="form-text text-muted">Nombre del concepto de pago</small>
        </div>
        
        <!-- Nivel y Grados -->
        <div class="form-group">
            <label for="level" class="font-weight-bold">Nivel <span class="text-danger">*</span></label>
            <select class="form-control" name="level" id="level" required>
                <option value="">Seleccione un nivel</option>
                <option value="Inicial" <?php echo (isset($level) && $level == 'Inicial') ? 'selected' : ''; ?>>Inicial</option>
                <option value="Primaria" <?php echo (isset($level) && $level == 'Primaria') ? 'selected' : ''; ?>>Primaria</option>
                <option value="Secundaria" <?php echo (isset($level) && $level == 'Secundaria') ? 'selected' : ''; ?>>Secundaria</option>
            </select>
        </div>
        
        <!-- Grados -->
        <div class="form-group">
            <label class="font-weight-bold">Grados <span class="text-danger">*</span></label>
            <div id="grades-container" class="border rounded p-3 bg-light" style="min-height: 80px;">
                <small class="text-muted">Seleccione primero un nivel para ver los grados disponibles</small>
            </div>
            <input type="hidden" name="grades" id="grades-hidden" value="<?php echo isset($grades) ? htmlspecialchars($grades) : ''; ?>">
            <small class="form-text text-muted d-block mt-2">Seleccione al menos un grado</small>
        </div>
        
        <!-- Año Académico -->
        <div class="form-group">
            <label for="academic_year" class="font-weight-bold">Año Académico <span class="text-danger">*</span></label>
            <select class="form-control" name="academic_year_id" id="academic_year" required>
                <option value="">Seleccione un año académico</option>
                <?php 
                if($academic_years && $academic_years->num_rows > 0):
                    while ($year = $academic_years->fetch_assoc()): 
                ?>
                    <option value="<?php echo $year['id']; ?>" 
                        <?php echo (isset($academic_year_id) && $academic_year_id == $year['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($year['year'] . ' - ' . ($year['description'] ?? '')); ?>
                    </option>
                <?php 
                    endwhile;
                endif; 
                ?>
            </select>
        </div>
        
        <!-- Descripción -->
        <div class="form-group">
            <label for="description" class="font-weight-bold">Descripción</label>
            <textarea name="description" id="description" rows="3" class="form-control" 
                      placeholder="Descripción del concepto de pago"><?php echo isset($description) ? htmlspecialchars($description) : ''; ?></textarea>
        </div>
        
        <!-- Monto -->
        <div class="form-group">
            <label for="total_amount" class="font-weight-bold">Monto (S/.) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0" class="form-control text-right" 
                   name="total_amount" id="total_amount"
                   value="<?php echo isset($total_amount) ? number_format($total_amount, 2, '.', '') : '0.00'; ?>" 
                   placeholder="0.00" required>
        </div>
        
        <hr class="my-4">
        
        <!-- Botones -->
        <div class="form-group text-right">
            <button type="button" class="btn btn-secondary mr-2" data-dismiss="modal">
                <i class="fa fa-times mr-2"></i>Cancelar
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save mr-2"></i><?php echo $id ? 'Actualizar' : 'Guardar'; ?>
            </button>
        </div>
    </form>
</div>

<script>
// Definir grados disponibles por nivel
const gradesByLevel = {
    'Inicial': ['3°', '4°', '5°'],
    'Primaria': ['1°', '2°', '3°', '4°', '5°', '6°'],
    'Secundaria': ['1°', '2°', '3°', '4°', '5°']
};

// Grados seleccionados iniciales (para edición)
let selectedGrades = [];
<?php if (isset($grades) && !empty($grades)): ?>
    selectedGrades = <?php echo json_encode(explode(',', $grades)); ?>;
<?php endif; ?>

function updateGradesContainer() {
    const level = $('#level').val();
    const container = $('#grades-container');
    
    if (!level) {
        container.html('<small class="text-muted">Seleccione primero un nivel para ver los grados disponibles</small>');
        return;
    }

    const grades = gradesByLevel[level] || [];
    let html = '<div class="row">';
    
    grades.forEach(grade => {
        const isChecked = selectedGrades.includes(grade) ? 'checked' : '';
        html += `
            <div class="col-md-4 col-6 mb-2">
                <div class="form-check">
                    <input class="form-check-input grade-checkbox" type="checkbox" 
                           value="${grade}" id="grade_${grade}" ${isChecked}>
                    <label class="form-check-label" for="grade_${grade}">
                        ${grade}
                    </label>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.html(html);
    updateHiddenGrades();
}

function updateHiddenGrades() {
    const checkedGrades = [];
    $('.grade-checkbox:checked').each(function() {
        checkedGrades.push($(this).val());
    });
    selectedGrades = checkedGrades;
    $('#grades-hidden').val(checkedGrades.join(','));
}

$(document).ready(function() {
    // Esperar a que el DOM esté completamente cargado
    setTimeout(function() {
        // Si hay un nivel seleccionado (edición), mostrar grados
        const level = $('#level').val();
        if (level) {
            updateGradesContainer();
        }
    }, 100);

    // Actualizar grados cuando cambie el nivel
    $('#level').change(function() {
        selectedGrades = [];
        updateGradesContainer();
    });

    // Manejar cambios en checkboxes de grados
    $(document).on('change', '.grade-checkbox', function() {
        updateHiddenGrades();
    });
});

$('#manage-concept').on('reset', function() {
    $('#msg').html('');
    selectedGrades = [];
    updateGradesContainer();
});

$('#manage-concept').submit(function(e) {
    e.preventDefault();
    
    // Validar que se hayan seleccionado grados
    if (selectedGrades.length === 0) {
        $('#msg').html('<div class="alert alert-warning alert-dismissible fade show" role="alert"><i class="fa fa-exclamation-triangle mr-2"></i>Por favor, seleccione al menos un grado.<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>');
        return;
    }
    
    start_load();
    $('#msg').html('');
    
    $.ajax({
        url: 'ajax.php?action=save_course',
        data: new FormData($(this)[0]),
        cache: false,
        contentType: false,
        processData: false,
        method: 'POST',
        dataType: 'json',
        success: function(resp) {
            end_load();
            if(resp && resp.status == 1) {
                alert_toast(resp.msg || "Concepto guardado exitosamente", 'success');
                setTimeout(function() {
                    $('#uni_modal').modal('hide');
                    location.reload();
                }, 1000);
            } else if(resp && resp.status == 2) {
                $('#msg').html('<div class="alert alert-warning alert-dismissible fade show" role="alert"><i class="fa fa-info-circle mr-2"></i>' + resp.msg + '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>');
            } else {
                alert_toast(resp.msg || "Error al guardar el concepto", 'danger');
            }
        },
        error: function(err) {
            end_load();
            console.error("Error en la solicitud AJAX:", err);
            alert_toast("Error en el servidor. Intente nuevamente más tarde", 'danger');
        }
    });
});
</script>
