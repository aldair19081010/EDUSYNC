<?php include 'db_connect.php'; ?>

<div class="container-fluid">

    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-users-cog mr-2"></i>Gestión de Usuarios
        </h1>
        <button class="btn btn-primary btn-icon-split" id="new_user">
            <span class="icon text-white-50">
                <i class="fas fa-user-plus"></i>
            </span>
            <span class="text">Nuevo Usuario</span>
        </button>
    </div>

    <!-- Users Table Card -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list mr-2"></i>Lista de Usuarios del Sistema
            </h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="usersTable" width="100%" cellspacing="0">
                    <thead class="thead-light">
                        <tr>
                            <th class="text-center" style="width: 60px;">#</th>
                            <th>Nombre</th>
                            <th>Usuario/Correo</th>
                            <th class="text-center" style="width: 150px;">Tipo de Usuario</th>
                            <th class="text-center" style="width: 150px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $type = array("", "Admin", "Teachers", "Auxiliar");
                        $school_id = intval($_SESSION['login_school_id'] ?? 0);
                        $users = $conn->query("SELECT * FROM users" . ($school_id ? " WHERE school_id = $school_id" : "") . " order by name asc");
                        $i = 1;
                        while ($row = $users->fetch_assoc()) :
                            // Determinar el badge según el tipo
                            $badge_class = '';
                            switch($row['type']) {
                                case 1:
                                    $badge_class = 'badge-danger';
                                    break;
                                case 2:
                                    $badge_class = 'badge-info';
                                    break;
                                case 3:
                                    $badge_class = 'badge-success';
                                    break;
                                default:
                                    $badge_class = 'badge-secondary';
                            }
                        ?>
                            <tr>
                                <td class="text-center font-weight-bold">
                                    <?php echo $i++ ?>
                                </td>
                                <td>
                                    <i class="fas fa-user mr-2 text-gray-600"></i>
                                    <?php echo ucwords($row['name']) ?>
                                </td>
                                <td>
                                    <i class="fas fa-envelope mr-2 text-gray-600"></i>
                                    <?php echo $row['username'] ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $badge_class ?> px-3 py-2">
                                        <?php echo $type[$row['type']] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-info edit_user" 
                                                data-id="<?php echo $row['id'] ?>" 
                                                data-name="<?php echo htmlspecialchars($row['name']) ?>" 
                                                data-username="<?php echo htmlspecialchars($row['username']) ?>" 
                                                data-type="<?php echo $row['type'] ?>" 
                                                title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger delete_user" 
                                                data-id="<?php echo $row['id'] ?>" 
                                                data-name="<?php echo htmlspecialchars($row['name']) ?>" 
                                                title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Modal para Nuevo/Editar Usuario -->
<div class="modal fade" id="userModal" tabindex="-1" role="dialog" aria-labelledby="userModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="userModalLabel">
                    <i class="fas fa-user-plus mr-2"></i><span id="modal-title-text">Nuevo Usuario</span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="userForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="user_id">
                    
                    <div class="form-group">
                        <label for="name" class="font-weight-bold">Nombre Completo <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                            </div>
                            <input type="text" class="form-control" name="name" id="name" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="username" class="font-weight-bold">Usuario/Correo <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-user-circle"></i></span>
                            </div>
                            <input type="text" class="form-control" name="username" id="username" required placeholder="Ingrese usuario o correo">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password" class="font-weight-bold">Contraseña <span class="text-danger" id="password-required">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            </div>
                            <input type="password" class="form-control" name="password" id="password">
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <small class="form-text text-muted" id="password-hint">Dejar en blanco para mantener la contraseña actual</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="type" class="font-weight-bold">Tipo de Usuario <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-user-tag"></i></span>
                            </div>
                            <select class="form-control" name="type" id="type" required>
                                <option value="">Seleccionar tipo...</option>
                                <option value="1">Admin</option>
                                <option value="2">Teachers</option>
                                <option value="3">Auxiliar</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-1"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveUserBtn">
                        <i class="fas fa-save mr-1"></i>Guardar Usuario
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Inicializar DataTable con configuración en español
        $('#usersTable').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/Spanish.json"
            },
            "order": [[1, "asc"]],
            "pageLength": 25,
            "responsive": true
        });

        // Botón nuevo usuario
        $('#new_user').click(function() {
            $('#userForm')[0].reset();
            $('#user_id').val('');
            $('#modal-title-text').html('<i class="fas fa-user-plus mr-2"></i>Nuevo Usuario');
            $('#password').prop('required', true);
            $('#password-required').show();
            $('#password-hint').hide();
            $('#userModal').modal('show');
        });

        // Botón editar usuario
        $(document).on('click', '.edit_user', function() {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var username = $(this).data('username');
            var type = $(this).data('type');
            
            $('#user_id').val(id);
            $('#name').val(name);
            $('#username').val(username);
            $('#type').val(type);
            $('#password').val('');
            $('#password').prop('required', false);
            $('#password-required').hide();
            $('#password-hint').show();
            $('#modal-title-text').html('<i class="fas fa-user-edit mr-2"></i>Editar Usuario');
            $('#userModal').modal('show');
        });

        // Toggle mostrar/ocultar contraseña
        $('#togglePassword').click(function() {
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

        // Enviar formulario
        $('#userForm').submit(function(e) {
            e.preventDefault();
            
            var formData = $(this).serialize();
            
            // Deshabilitar botón de guardar
            $('#saveUserBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Guardando...');
            
            $.ajax({
                url: 'ajax.php?action=save_user',
                method: 'POST',
                data: formData,
                dataType: 'json',
                success: function(resp) {
                    $('#saveUserBtn').prop('disabled', false).html('<i class="fas fa-save mr-1"></i>Guardar Usuario');
                    
                    if (resp.status == 1) {
                        $('#userModal').modal('hide');
                        alert('Usuario guardado exitosamente');
                        setTimeout(function() {
                            location.reload();
                        }, 500);
                    } else if (resp.status == 2) {
                        alert('El nombre de usuario ya existe. Por favor, elige otro.');
                    } else {
                        alert('Error al guardar el usuario: ' + (resp.message || 'Error desconocido'));
                    }
                },
                error: function(xhr, status, error) {
                    $('#saveUserBtn').prop('disabled', false).html('<i class="fas fa-save mr-1"></i>Guardar Usuario');
                    alert('Error de conexión: ' + error);
                }
            });
        });

        // Botón eliminar usuario
        $(document).on('click', '.delete_user', function() {
            var userId = $(this).data('id');
            var userName = $(this).data('name');
            
            if (confirm('¿Estás seguro de eliminar al usuario "' + userName + '"?\n\nEsta acción no se puede deshacer.')) {
                deleteUser(userId);
            }
        });
    });

    function deleteUser(id) {
        $.ajax({
            url: 'ajax.php?action=delete_user',
            method: 'POST',
            data: { id: id },
            success: function(resp) {
                if (resp == 1) {
                    alert('Usuario eliminado exitosamente');
                    setTimeout(function() {
                        location.reload();
                    }, 500);
                } else {
                    alert('Error al eliminar el usuario: ' + resp);
                }
            },
            error: function(xhr, status, error) {
                alert('Error de conexión: ' + error);
            }
        });
    }
</script>

<style>
    /* Estilos adicionales para mejorar la apariencia */
    #usersTable tbody tr:hover {
        background-color: #f8f9fc;
    }
    
    .btn-group .btn {
        margin: 0 2px;
    }
    
    .badge {
        font-size: 0.85rem;
        font-weight: 600;
    }
    
    .table td {
        vertical-align: middle;
    }
</style>
