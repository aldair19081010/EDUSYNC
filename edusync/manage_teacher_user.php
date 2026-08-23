<?php
include 'db_connect.php';
include_once 'includes/session_check.php'; require_login_modal();

// Obtener el school_id del administrador
$school_id = $_SESSION['login_school_id'] ?? 0;

// Obtener información del docente
$teacher_id = intval($_GET['id'] ?? 0);
$teacher_name = urldecode($_GET['name'] ?? '');
$teacher_email = urldecode($_GET['email'] ?? '');

// Verificar si ya existe un usuario para este docente
$check_stmt = $conn->prepare('SELECT * FROM users WHERE teacher_id = ? AND school_id = ? AND type = 2 LIMIT 1');
$check_stmt->bind_param('ii', $teacher_id, $school_id);
$check_stmt->execute();
$check = $check_stmt->get_result();
$user = $check->num_rows > 0 ? $check->fetch_assoc() : null;
$check_stmt->close();
?>

<form action="" id="manage-teacher-user">
    <input type="hidden" name="id" value="<?php echo isset($user['id']) ? $user['id'] : '' ?>">
    <input type="hidden" name="teacher_id" value="<?php echo $teacher_id ?>">
    <input type="hidden" name="school_id" value="<?php echo $school_id ?>">
    <input type="hidden" name="name" value="<?php echo $teacher_name ?>">
    
    <div id="msg"></div>

    <div class="form-group">
        <label class="font-weight-bold">Docente</label>
        <input type="text" class="form-control" value="<?php echo $teacher_name ?>" readonly>
    </div>

    <div class="form-group">
        <label for="username" class="font-weight-bold">Nombre de Usuario <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="username" name="username" value="<?php echo isset($user['username']) ? $user['username'] : '' ?>" required>
    </div>

    <div class="form-group">
        <label for="password" class="font-weight-bold">Contraseña <?php echo isset($user['id']) ? '' : '<span class="text-danger">*</span>' ?></label>
        <input type="password" class="form-control" id="password" name="password" <?php echo !isset($user['id']) ? 'required' : '' ?> placeholder="<?php echo isset($user['id']) ? 'Dejar en blanco para no cambiar' : '' ?>">
    </div>

    <div class="form-group">
        <div class="custom-control custom-switch">
            <input type="checkbox" class="custom-control-input" id="is_director" name="is_director" value="1" <?php echo (isset($user['is_director']) && $user['is_director'] == 1) ? 'checked' : '' ?>>
            <label class="custom-control-label" for="is_director">
                <strong>Es Director Institucional</strong>
                <br><small class="text-muted">Si está activo, este docente podrá ver notificaciones globales y el Reporte de Notas de todo el colegio como si fuera Administrador.</small>
            </label>
        </div>
    </div>

    <div class="d-flex justify-content-end">
        <button type="button" class="btn btn-light mr-2" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Guardar</button>
    </div>
</form>

<script>
    $(document).ready(function() {
        $('#manage-teacher-user').submit(function(e) {
            e.preventDefault();
            start_load();
            $('#msg').html('');
            
            $.ajax({
                url: 'ajax.php?action=save_teacher_user',
                method: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(resp) {
                    if (resp.status == 1 || resp == 1) {
						alert_toast('Usuario creado/actualizado exitosamente', 'success');
						$(document).trigger('teacher:user-saved');
                        try { window.parent.$('#uni_modal').modal('hide'); } catch(_) {}
                        end_load();
                    } else if (resp.status == 2 || resp == 2) {
                        $('#msg').html('<div class="alert alert-danger"><i class="fa fa-exclamation-circle mr-2"></i>El nombre de usuario ya existe</div>');
                        end_load();
                    } else {
                        $('#msg').html('<div class="alert alert-danger"><i class="fa fa-exclamation-circle mr-2"></i>Error al guardar los datos</div>');
                        end_load();
                    }
                },
                error: function(err) {
                    console.error('Error en la solicitud AJAX:', err);
                    $('#msg').html('<div class="alert alert-danger"><i class="fa fa-exclamation-circle mr-2"></i>Error en el servidor. Intente nuevamente más tarde.</div>');
                    end_load();
                }
            });
        });
    });
</script>
