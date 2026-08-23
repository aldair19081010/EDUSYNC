<?php
include 'db_connect.php';
include_once 'includes/session_check.php'; require_login_modal();
if (isset($_GET['id'])) {
    $qry = $conn->query("SELECT * FROM users WHERE id = {$_GET['id']}");
    foreach ($qry->fetch_array() as $k => $v) {
        $$k = $v;
    }
    if (isset($avatar) && !empty($avatar)) {
        $_SESSION['login_avatar'] = $avatar;
    }
}
?>

<div class="modal-body">
    <div id="msg"></div>

    <form id="manage-user" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
        
        <!-- Nombre -->
        <div class="form-group">
            <label class="font-weight-bold text-gray-700">
                <i class="fas fa-user mr-1 text-primary"></i>Nombre Completo 
                <span class="text-danger">*</span>
            </label>
            <input type="text" class="form-control" name="name" 
                   value="<?php echo isset($name) ? htmlspecialchars($name) : '' ?>" required>
        </div>

        <!-- Usuario -->
        <div class="form-group">
            <label class="font-weight-bold text-gray-700">
                <i class="fas fa-user-circle mr-1 text-info"></i>Usuario 
                <span class="text-danger">*</span>
            </label>
            <input type="text" class="form-control" name="username" 
                   value="<?php echo isset($username) ? htmlspecialchars($username) : '' ?>" 
                   required autocomplete="off" placeholder="Nombre de usuario">
        </div>

        <!-- Contraseña -->
        <div class="form-group">
            <label class="font-weight-bold text-gray-700">
                <i class="fas fa-lock mr-1 text-warning"></i>Contraseña 
                <span class="text-danger">*</span>
            </label>
            <div class="input-group">
                <input type="password" class="form-control" name="password" id="password" 
                       autocomplete="off" placeholder="Ingrese contraseña"
                       <?php echo isset($id) ? '' : 'required'; ?>>
                <div class="input-group-append">
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <?php if (isset($id)): ?>
                <small class="form-text text-muted">
                    <i class="fas fa-info-circle mr-1"></i>Dejar en blanco para mantener la contraseña actual
                </small>
            <?php endif; ?>
        </div>

        <!-- Repetir Contraseña -->
        <div class="form-group">
            <label class="font-weight-bold text-gray-700">
                <i class="fas fa-lock mr-1 text-warning"></i>Repetir Contraseña 
                <span class="text-danger">*</span>
            </label>
            <div class="input-group">
                <input type="password" class="form-control" name="repeat_password" id="repeat_password" 
                       autocomplete="off" placeholder="Confirme contraseña"
                       <?php echo isset($id) ? '' : 'required'; ?>>
                <div class="input-group-append">
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword2">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <div id="password-match-indicator"></div>
        </div>

        <!-- Avatar -->
        <div class="form-group">
            <label class="font-weight-bold text-gray-700">
                <i class="fas fa-image mr-1 text-success"></i>Foto de Perfil
            </label>
            <div class="custom-file">
                <input type="file" class="custom-file-input" id="avatarFile" name="avatar" 
                       accept="image/*" onchange="displayImg(this)">
                <label class="custom-file-label" for="avatarFile" id="avatarLabel">Seleccionar imagen...</label>
            </div>
            <small class="form-text text-muted">
                <i class="fas fa-info-circle mr-1"></i>Formatos permitidos: JPG, PNG, GIF (Opcional)
            </small>

            <?php
            $avatar_path = '';
            if (isset($avatar) && !empty($avatar)) {
                $avatar_path = "assets/uploads/" . $avatar;
            }
            ?>

            <!-- Preview del avatar -->
            <div class="text-center mt-3" id="avatarPreviewContainer" <?php echo empty($avatar_path) ? 'style="display:none;"' : '' ?>>
                <img id="user_avatar_preview" 
                     src="<?php echo $avatar_path ?>" 
                     alt="Avatar" 
                     class="rounded-circle"
                     style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #4e73df; padding: 2px;">
                <div class="mt-2">
                    <?php if (!empty($avatar_path)): ?>
                        <span class="badge badge-success">
                            <i class="fas fa-check-circle"></i> Avatar actual
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </form>
</div>

<!-- Modal Footer -->
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-dismiss="modal">
        <i class="fas fa-times mr-1"></i>Cancelar
    </button>
    <button type="button" class="btn btn-primary" id="btnSaveUser">
        <i class="fas fa-save mr-1"></i>Guardar
    </button>
</div>

<script>
    $(document).ready(function() {
        // Toggle mostrar/ocultar contraseña
        $('#togglePassword').click(function(e) {
            e.preventDefault();
            var passwordField = $('#password');
            var icon = $(this).find('i');
            
            if (passwordField.attr('type') === 'password') {
                passwordField.attr('type', 'text');
                icon.removeClass('fa-eye').addClass('fa-eye-slash');
            } else {
                passwordField.attr('type', 'password');
                icon.removeClass('fa-eye-slash').addClass('fa-eye');
            }
        });

        $('#togglePassword2').click(function(e) {
            e.preventDefault();
            var passwordField = $('#repeat_password');
            var icon = $(this).find('i');
            
            if (passwordField.attr('type') === 'password') {
                passwordField.attr('type', 'text');
                icon.removeClass('fa-eye').addClass('fa-eye-slash');
            } else {
                passwordField.attr('type', 'password');
                icon.removeClass('fa-eye-slash').addClass('fa-eye');
            }
        });

        // Validación en tiempo real de contraseñas
        $('#password, #repeat_password').on('keyup', function() {
            var pass = $('#password').val();
            var repeat = $('#repeat_password').val();
            var indicator = $('#password-match-indicator');
            
            if (pass.trim() !== '' && repeat.trim() !== '') {
                if (pass === repeat) {
                    $('#repeat_password').css('border-color', '#28a745');
                    indicator.html('<div class="text-success mt-2"><i class="fas fa-check-circle"></i> Las contraseñas coinciden</div>');
                } else {
                    $('#repeat_password').css('border-color', '#dc3545');
                    indicator.html('<div class="text-danger mt-2"><i class="fas fa-times-circle"></i> Las contraseñas no coinciden</div>');
                }
            } else {
                $('#repeat_password').css('border-color', '');
                indicator.html('');
            }
        });

        // Guardar usuario
        $('#btnSaveUser').click(function() {
            var pass = $('#password').val();
            var repeat = $('#repeat_password').val();
            
            if (pass !== repeat && pass.trim() !== '') {
                $('#msg').html('<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle mr-2"></i> Las contraseñas no coinciden.<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>');
                $('#repeat_password').focus();
                return false;
            }

            var btn = $(this);
            var originalText = btn.html();
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Guardando...');

            $.ajax({
                url: 'ajax.php?action=save_user',
                method: 'POST',
                data: new FormData($('#manage-user')[0]),
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(resp) {
                    btn.prop('disabled', false).html(originalText);
                    
                    if (resp == 1 || (typeof resp === 'object' && resp.status == 1)) {
                        alert('✓ Perfil actualizado correctamente');
                        setTimeout(function() {
                            $('.modal').modal('hide');
                            location.reload();
                        }, 500);
                    } else if (resp == 2 || (typeof resp === 'object' && resp.status == 2)) {
                        $('#msg').html('<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-triangle mr-2"></i> El nombre de usuario ya existe.<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>');
                    } else {
                        $('#msg').html('<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle mr-2"></i> Ocurrió un error al guardar los datos.<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>');
                    }
                },
                error: function(err) {
                    btn.prop('disabled', false).html(originalText);
                    console.log(err);
                    $('#msg').html('<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle mr-2"></i> Error en la solicitud.<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>');
                }
            });
        });
    });

    function displayImg(input) {
        if (input.files && input.files[0]) {
            var fileName = input.files[0].name;
            $('#avatarLabel').text(fileName);
            
            var reader = new FileReader();
            reader.onload = function(e) {
                $('#user_avatar_preview').attr('src', e.target.result);
                $('#avatarPreviewContainer').fadeIn(300);
                
                if ($('#avatarPreviewContainer .badge').length === 0) {
                    $('#avatarPreviewContainer').append(
                        '<div class="mt-2"><span class="badge badge-info"><i class="fas fa-cloud-upload"></i> Nuevo avatar</span></div>'
                    );
                }
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
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
    
    .input-group-append .btn:focus {
        box-shadow: none;
        border-color: #d1d3e2;
    }
</style>
