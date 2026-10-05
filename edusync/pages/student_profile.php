<?php
// Iniciar sesión si no está iniciada (necesario para modales)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', dirname(__DIR__) . '/tmp');
    session_name('EDUSYNCSESSID');
    session_start();
}

// Verificar que sea estudiante
if ((!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) && 
    (!isset($_SESSION['login_type']) || $_SESSION['login_type'] != 4)) {
    echo '<div class="alert alert-danger">No autorizado</div>';
    exit();
}

include_once __DIR__ . '/../db_connect.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$studentProfileCsrf = $_SESSION['csrf_token'];

// Obtener datos del estudiante
$student_id = $_SESSION['student_id'] ?? null;
$student = null;

if ($student_id) {
    // Buscar por ID de estudiante en la tabla student
    $query = $conn->query("SELECT s.*, sc.name as school_name FROM student s 
                          LEFT JOIN schools sc ON s.school_id = sc.id 
                          WHERE s.id = $student_id LIMIT 1");
    if ($query && $query->num_rows > 0) {
        $student = $query->fetch_assoc();
    }
}

if (!$student) {
    echo '<div class="alert alert-danger">Datos del estudiante no encontrados</div>';
    exit();
}

$hasCustomPassword = !empty($student['portal_password_hash']);
$passwordChangedAt = trim((string)($student['password_changed_at'] ?? ''));
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-8">
            <div class="form-group">
                <label for="student_name" class="control-label">Nombre Completo</label>
                <input type="text" id="student_name" class="form-control" value="<?php echo htmlspecialchars($student['name'] ?? ''); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="student_email" class="control-label">Email</label>
                <input type="email" id="student_email" class="form-control" value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="student_phone" class="control-label">Teléfono</label>
                <input type="text" id="student_phone" class="form-control" value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="student_dni" class="control-label">DNI / Cédula</label>
                <input type="text" id="student_dni" class="form-control" value="<?php echo htmlspecialchars($student['id_no'] ?? $_SESSION['student_dni'] ?? ''); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="student_school" class="control-label">Colegio/Sede</label>
                <input type="text" id="student_school" class="form-control" value="<?php echo htmlspecialchars($student['school_name'] ?? ''); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="student_grado" class="control-label">Grado</label>
                <input type="text" id="student_grado" class="form-control" value="<?php echo htmlspecialchars($student['grado'] ?? ''); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="student_seccion" class="control-label">Sección</label>
                <input type="text" id="student_seccion" class="form-control" value="<?php echo htmlspecialchars($student['seccion'] ?? ''); ?>" readonly>
            </div>

            <div class="form-group">
                <label for="student_nivel" class="control-label">Nivel</label>
                <input type="text" id="student_nivel" class="form-control" value="<?php echo htmlspecialchars($student['nivel'] ?? ''); ?>" readonly>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-user-circle"></i> Foto de Perfil
                </div>
                <div class="card-body text-center">
                    <?php 
                    $avatar_path = 'assets/uploads/' . ($student['avatar'] ?? '');
                    $has_avatar = !empty($student['avatar']) && file_exists($avatar_path);
                    ?>
                    <img src="<?php echo $has_avatar ? $avatar_path : 'https://ui-avatars.com/api/?name=' . urlencode($student['name'] ?? 'Student') . '&background=4285f4&color=fff&size=200'; ?>" 
                         class="img-fluid rounded-circle" style="max-width: 150px;" alt="Perfil">
                    <hr>
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i> 
                        Contacta al administrador para cambiar tu foto de perfil
                    </small>
                </div>
            </div>

            <div class="card mt-3 student-security-card">
                <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
                    <span><i class="fas fa-shield-alt mr-1"></i> Seguridad</span>
                    <span id="student-password-status" class="badge badge-light text-primary">
                        <?php echo $hasCustomPassword ? 'Personalizada' : 'Inicial'; ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        <?php if ($hasCustomPassword): ?>
                            Tu cuenta ya utiliza una contraseña personalizada.
                        <?php else: ?>
                            Actualmente tu contraseña inicial es tu DNI o código de estudiante.
                        <?php endif; ?>
                    </p>

                    <form id="student-change-password-form" autocomplete="off">
                        <div id="student-password-message"></div>

                        <div class="form-group">
                            <label class="small font-weight-bold" for="student-current-password">Contraseña actual</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="student-current-password"
                                       name="current_password" autocomplete="current-password" required>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary student-password-toggle" type="button"
                                            data-target="student-current-password" aria-label="Mostrar contraseña">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="small font-weight-bold" for="student-new-password">Nueva contraseña</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="student-new-password"
                                       name="new_password" autocomplete="new-password" minlength="8" maxlength="64" required>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary student-password-toggle" type="button"
                                            data-target="student-new-password" aria-label="Mostrar contraseña">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="form-text text-muted">Mínimo 8 caracteres, con al menos una letra y un número. No puede ser igual a tu DNI.</small>
                        </div>

                        <div class="form-group">
                            <label class="small font-weight-bold" for="student-confirm-password">Confirmar nueva contraseña</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="student-confirm-password"
                                       name="confirm_password" autocomplete="new-password" minlength="8" maxlength="64" required>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary student-password-toggle" type="button"
                                            data-target="student-confirm-password" aria-label="Mostrar contraseña">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block" id="student-change-password-btn">
                            <i class="fas fa-key mr-1"></i> Cambiar contraseña
                        </button>
                    </form>

                    <div id="student-password-changed-at" class="small text-muted mt-2 <?php echo $passwordChangedAt === '' ? 'd-none' : ''; ?>">
                        <?php if ($passwordChangedAt !== ''): ?>
                            Último cambio: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($passwordChangedAt))); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .form-group {
        margin-bottom: 1rem;
    }
    
    .form-control {
        border-radius: 0.5rem;
        border: 1px solid #e3e6f0;
    }
    
    .card {
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .card-header {
        background-color: #4285f4 !important;
        border-radius: 10px 10px 0 0;
    }

    .student-security-card .form-control {
        border-radius: .5rem 0 0 .5rem;
    }

    .student-security-card .input-group-append .btn {
        border-radius: 0 .5rem .5rem 0;
    }

    .student-security-card .badge {
        font-size: .7rem;
        padding: .35rem .55rem;
    }
</style>

<script>
(function($) {
    if (!$) return;

    $('.student-password-toggle').off('click.studentPassword').on('click.studentPassword', function() {
        var target = document.getElementById($(this).data('target'));
        if (!target) return;
        var show = target.type === 'password';
        target.type = show ? 'text' : 'password';
        $(this).find('i')
            .toggleClass('fa-eye', !show)
            .toggleClass('fa-eye-slash', show);
        $(this).attr('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });

    $('#student-change-password-form').off('submit.studentPassword').on('submit.studentPassword', function(e) {
        e.preventDefault();

        var current = $('#student-current-password').val() || '';
        var next = $('#student-new-password').val() || '';
        var confirm = $('#student-confirm-password').val() || '';
        var message = $('#student-password-message').empty();

        function fail(text) {
            message.html('<div class="alert alert-danger py-2 small">' + $('<div>').text(text).html() + '</div>');
        }

        if (!current || !next || !confirm) {
            fail('Completa los tres campos.');
            return;
        }
        if (next.length < 8 || next.length > 64) {
            fail('La nueva contraseña debe tener entre 8 y 64 caracteres.');
            return;
        }
        if (!/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/.test(next) || !/\d/.test(next)) {
            fail('La nueva contraseña debe incluir al menos una letra y un número.');
            return;
        }
        if (next !== confirm) {
            fail('La nueva contraseña y su confirmación no coinciden.');
            return;
        }

        var btn = $('#student-change-password-btn');
        var original = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Actualizando...');

        $.ajax({
            url: 'api/student_change_password.php',
            method: 'POST',
            dataType: 'json',
            data: {
                current_password: current,
                new_password: next,
                confirm_password: confirm,
                csrf_token: <?php echo json_encode($studentProfileCsrf); ?>
            }
        }).done(function(resp) {
            if (!resp || resp.status !== 'ok') {
                fail((resp && resp.message) || 'No se pudo actualizar la contraseña.');
                return;
            }

            message.html('<div class="alert alert-success py-2 small"><i class="fas fa-check-circle mr-1"></i>' +
                $('<div>').text(resp.message || 'Contraseña actualizada correctamente.').html() + '</div>');
            $('#student-current-password, #student-new-password, #student-confirm-password').val('').attr('type', 'password');
            $('.student-password-toggle i').removeClass('fa-eye-slash').addClass('fa-eye');
            $('#student-password-status').text('Personalizada');

            var changedAt = $('#student-password-changed-at');
            changedAt.removeClass('d-none').text('Contraseña actualizada en esta sesión.');
        }).fail(function(xhr) {
            var response = xhr.responseJSON || {};
            fail(response.message || 'No se pudo conectar con el servidor.');
        }).always(function() {
            btn.prop('disabled', false).html(original);
        });
    });
})(window.jQuery);
</script>
