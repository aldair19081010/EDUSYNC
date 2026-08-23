<?php
include '../db_connect.php';
include_once __DIR__ . '/../includes/session_check.php';
require_login_modal();
if (!isset($_SESSION['login_type']) || $_SESSION['login_type'] != 1) {
    echo '<div class="alert alert-danger">Solo los administradores pueden editar el perfil del colegio.</div>';
    exit;
}

// Obtener el perfil del colegio
$school_id = $_SESSION['login_school_id'] ?? 0;
$school = $conn->query("SELECT * FROM schools WHERE id = $school_id")->fetch_assoc();

if (!$school) {
    $school = $conn->query("SELECT * FROM schools ORDER BY id ASC LIMIT 1")->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $conn->real_escape_string($_POST['name'] ?? '');
    $contact_number = $conn->real_escape_string($_POST['contact_number'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $address = $conn->real_escape_string($_POST['address'] ?? '');
    $logo_path = $school['logo_path'];
    $remove_logo = isset($_POST['remove_logo']) && $_POST['remove_logo'] == '1';

    // Si se marcó la opción de eliminar el logo
    if ($remove_logo) {
        if (!empty($logo_path) && file_exists($logo_path)) {
            $logo_path = '';
        }
    }
    // Si se está subiendo uno nuevo
    else if (!empty($_FILES['logo']['name'])) {
        $target_dir = "../assets/uploads/";
        $relative_dir = "assets/uploads/"; // Ruta relativa para guardar en BD
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $file_name = 'school_logo_' . time() . '.' . $ext;
        $target_file = $target_dir . $file_name;
        $relative_path = $relative_dir . $file_name; // Para guardar en BD
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_file)) {
            if (!empty($school['logo_path']) && file_exists($school['logo_path']) && $school['logo_path'] != $target_file) {
                @unlink($school['logo_path']);
            }
            $logo_path = $relative_path; // Guardar la ruta relativa en BD
        }
    }

    $sql = "UPDATE schools SET name='$name', contact_number='$contact_number', email='$email', address='$address', logo_path='$logo_path' WHERE id=" . $school['id'];
    if ($conn->query($sql)) {
        echo json_encode(['status' => 'success', 'message' => 'Perfil del colegio actualizado correctamente']);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error al actualizar: ' . $conn->error]);
        exit;
    }
}
?>

<div class="modal-body">

    <!-- School Profile Card -->
    <div class="row">
        <div class="col-12">
            <form id="manage-school-form" enctype="multipart/form-data">
                        
                        <!-- Nombre del colegio -->
                        <div class="form-group">
                            <label class="font-weight-bold text-gray-700">
                                <i class="fas fa-school mr-1 text-primary"></i>Nombre del Colegio 
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" name="name" 
                                   value="<?php echo htmlspecialchars($school['name'] ?? ''); ?>" 
                                   required placeholder="Ingrese el nombre del colegio">
                        </div>

                        <!-- Teléfono -->
                        <div class="form-group">
                            <label class="font-weight-bold text-gray-700">
                                <i class="fas fa-phone mr-1 text-success"></i>Teléfono de Contacto
                            </label>
                            <input type="text" class="form-control" name="contact_number" 
                                   value="<?php echo htmlspecialchars($school['contact_number'] ?? ''); ?>"
                                   placeholder="Ingrese el teléfono">
                        </div>

                        <!-- Email -->
                        <div class="form-group">
                            <label class="font-weight-bold text-gray-700">
                                <i class="fas fa-envelope mr-1 text-info"></i>Correo Electrónico
                            </label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?php echo htmlspecialchars($school['email'] ?? ''); ?>"
                                   placeholder="correo@colegio.edu">
                        </div>

                        <!-- Dirección -->
                        <div class="form-group">
                            <label class="font-weight-bold text-gray-700">
                                <i class="fas fa-map-marker-alt mr-1 text-danger"></i>Dirección
                            </label>
                            <textarea class="form-control" name="address" rows="2" 
                                      placeholder="Ingrese la dirección del colegio"><?php echo htmlspecialchars($school['address'] ?? ''); ?></textarea>
                        </div>

                        <!-- Logo -->
                        <div class="form-group">
                            <label class="font-weight-bold text-gray-700">
                                <i class="fas fa-image mr-1 text-warning"></i>Logo del Colegio
                            </label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" id="logoFile" name="logo" 
                                       accept="image/*" onchange="displayImg(this)">
                                <label class="custom-file-label" for="logoFile" id="logoLabel">Seleccionar imagen...</label>
                            </div>
                            <small class="form-text text-muted">
                                <i class="fas fa-info-circle mr-1"></i>Formatos permitidos: JPG, PNG, GIF
                            </small>

                            <?php
                            $logo_path = '';
                            if (isset($school['logo_path']) && !empty($school['logo_path'])) {
                                // Si la ruta es relativa (comienza con assets/), convertirla a ruta accesible desde el modal
                                if (strpos($school['logo_path'], 'assets/') === 0) {
                                    $logo_path = $school['logo_path']; // Ya es relativa correcta
                                } else {
                                    $logo_path = $school['logo_path'];
                                }
                                // Verificar si el archivo existe
                                if (!file_exists($logo_path) && !file_exists('../' . $logo_path)) {
                                    $logo_path = ''; // Si no existe, dejar vacío
                                }
                            }
                            ?>

                            <!-- Preview del logo -->
                            <div class="text-center mt-3" id="logoPreviewContainer" <?php echo empty($logo_path) ? 'style="display:none;"' : '' ?>>
                                <div class="border rounded p-3 d-inline-block" style="background: #f8f9fc;">
                                    <img id="school_logo_preview" 
                                         src="<?php echo $logo_path ?>" 
                                         alt="Logo" 
                                         class="img-fluid rounded"
                                         style="max-height: 150px; max-width: 300px;">
                                    <div class="mt-2">
                                        <?php if (!empty($logo_path)) : ?>
                                            <span class="badge badge-success">
                                                <i class="fas fa-check-circle"></i> Logo actual
                                            </span>
                                        <?php endif; ?>
                                        <button type="button" id="remove_logo" class="btn btn-sm btn-outline-danger ml-2">
                                            <i class="fas fa-trash"></i> Quitar logo
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="remove_logo" id="remove_logo_input" value="0">
                        </div>

<hr class="my-3">

                        <!-- Botones de acción -->
                        <div class="text-right">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                                <i class="fas fa-times mr-1"></i>Cancelar
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save mr-1"></i>Guardar Cambios
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
function displayImg(input) {
    if (input.files && input.files[0]) {
        // Actualizar el label del custom-file-input
        var fileName = input.files[0].name;
        $('#logoLabel').text(fileName);
        
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#school_logo_preview').attr('src', e.target.result);
            $('#logoPreviewContainer').fadeIn(300);
            
            // Actualizar el badge
            $('#logoPreviewContainer .badge').removeClass('badge-success').addClass('badge-info')
                .html('<i class="fas fa-cloud-upload"></i> Nuevo logo');
            
            // Asegurar que el botón de quitar esté visible
            if ($('#remove_logo').length === 0) {
                $('#logoPreviewContainer .mt-2').append(
                    '<button type="button" id="remove_logo" class="btn btn-sm btn-outline-danger ml-2">' +
                    '<i class="fas fa-trash"></i> Quitar logo</button>'
                );
            }
            
            // Resetear el valor de remove_logo
            $('#remove_logo_input').val('0');
        }
        reader.readAsDataURL(input.files[0]);
    }
}

$(document).ready(function() {
    // Prevenir propagación de eventos en botones dentro del formulario
    $(document).on('click', 'button[type="button"]', function(e) {
        e.stopPropagation();
    });
    
    // Manejar click en el botón para quitar logo
    $(document).on('click', '#remove_logo', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Ocultar el contenedor de preview
        $('#logoPreviewContainer').fadeOut(300);
        
        // Marcar para eliminar
        $('#remove_logo_input').val('1');
        
        // Limpiar el input file
        $('#logoFile').val('');
        $('#logoLabel').text('Seleccionar imagen...');
        
        return false;
    });
    
    // Enviar formulario
    $('#manage-school-form').submit(function(e) {
        e.preventDefault();
        
        var formData = new FormData($(this)[0]);
        var submitBtn = $(this).find('button[type="submit"]');
        var originalText = submitBtn.html();
        
        // Deshabilitar botón y mostrar spinner
        submitBtn.prop('disabled', true)
                 .html('<i class="fas fa-spinner fa-spin mr-2"></i>Guardando...');
        
        $.ajax({
            url: 'pages/manage_school_profile.php',
            data: formData,
            cache: false,
            contentType: false,
            processData: false,
            method: 'POST',
            dataType: 'json',
            success: function(resp) {
                submitBtn.prop('disabled', false).html(originalText);
                
                if (resp.status === 'success') {
                    alert('✓ ' + resp.message);
                    setTimeout(function() {
                        $('.modal').modal('hide');
                        location.reload();
                    }, 1000);
                } else {
                    alert('✗ ' + resp.message);
                }
            },
            error: function(xhr, status, error) {
                submitBtn.prop('disabled', false).html(originalText);
                console.error(xhr, status, error);
                alert('Error en el servidor. Por favor, intente nuevamente.');
            }
        });
    });
});
</script>

<style>
    .form-control:focus {
        border-color: #4e73df;
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
    }
    
    .custom-file-input:focus ~ .custom-file-label {
        border-color: #4e73df;
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
    }
    
    #logoPreviewContainer .border {
        border: 2px dashed #d1d3e2 !important;
    }
    
    #logoPreviewContainer:hover .border {
        border-color: #4e73df !important;
    }
</style>
