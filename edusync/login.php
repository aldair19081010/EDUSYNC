<?php
ini_set('session.save_path', __DIR__ . '/tmp');
if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) session_start();

if ((int)($_SESSION['login_type'] ?? 0) === 5 && !empty($_SESSION['login_id'])) {
    header('Location: guardian_portal.php');
    exit();
}
if (isset($_SESSION['login_id']) || !empty($_SESSION['student_logged_in']) || (int)($_SESSION['login_type'] ?? 0) === 4) {
    header('Location: index.php?page=home');
    exit();
}

$db_connected = false;
if (file_exists(__DIR__ . '/db_connect.php')) {
    include __DIR__ . '/db_connect.php';
    $db_connected = true;
    $system = $conn->query('SELECT * FROM system_settings LIMIT 1');
    if ($system && $system->num_rows > 0) {
        $settings = $system->fetch_array();
        foreach ($settings as $k => $v) $_SESSION['system'][$k] = $v;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>EduSync - Login</title>
    <link rel="icon" href="assets/uploads/logo.jpg" type="image/jpeg">
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:300,400,600,700,800,900" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
        .bg-gradient-primary{background:linear-gradient(135deg,#4285f4 0%,#2a75f3 100%)}
        .btn-primary{background-color:#4285f4;border-color:#4285f4}.btn-primary:hover{background-color:#2a75f3;border-color:#2a75f3}
        .text-primary{color:#4285f4!important}.btn-outline-primary{color:#4285f4;border-color:#4285f4}.btn-outline-primary:hover,.btn-outline-primary.active,.btn-outline-primary:active,.btn-outline-primary.focus,.btn-outline-primary:focus{color:#fff;background-color:#4285f4!important;border-color:#4285f4!important}
        .login-logo{max-width:145px;max-height:145px;object-fit:contain;margin-bottom:1rem;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        .card{border-radius:15px}.login-role-group .btn{font-size:.76rem;padding:.65rem .35rem}.login-hint{font-size:.72rem;color:#858796;margin:.4rem 0 0 1rem}
        @media(max-width:480px){.login-role-group{display:grid!important;grid-template-columns:1fr}.login-role-group .btn{border-radius:.35rem!important;margin-bottom:.35rem}}
    </style>
</head>
<body class="bg-gradient-primary">
<div class="container">
    <div class="row justify-content-center h-100 align-items-center" style="min-height:100vh">
        <div class="col-xl-5 col-lg-6 col-md-8">
            <div class="card o-hidden border-0 shadow-lg my-5"><div class="card-body p-0"><div class="p-5">
                <div class="text-center">
                    <div class="mb-4">
                        <?php if (file_exists(__DIR__ . '/assets/uploads/logo.jpg')): ?>
                            <img src="assets/uploads/logo.jpg" alt="Logo EduSync" class="login-logo">
                        <?php else: ?>
                            <i class="fas fa-school fa-3x text-primary mb-2"></i>
                        <?php endif; ?>
                    </div>
                    <h1 class="h4 text-gray-900 mb-1">Bienvenido a EduSync</h1>
                    <p class="text-muted small mb-4">Sistema de Gestión Educativa</p>
                </div>

                <?php if (!$db_connected): ?>
                    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-1"></i> No se pudo cargar la conexión a la base de datos.</div>
                <?php endif; ?>
                <?php if (isset($_GET['guardian_error'])): ?>
                    <div class="alert alert-warning"><i class="fas fa-user-shield mr-1"></i> No se pudo validar el perfil del apoderado. Comunícate con la institución.</div>
                <?php endif; ?>

                <form class="user" id="login-form">
                    <div class="form-group">
                        <div class="btn-group btn-group-toggle w-100 mb-2 login-role-group" data-toggle="buttons">
                            <label class="btn btn-outline-primary active">
                                <input type="radio" name="user_type" value="admin" checked><i class="fas fa-user-tie mr-1"></i>Personal
                            </label>
                            <label class="btn btn-outline-primary">
                                <input type="radio" name="user_type" value="student"><i class="fas fa-user-graduate mr-1"></i>Estudiante
                            </label>
                            <label class="btn btn-outline-primary">
                                <input type="radio" name="user_type" value="guardian"><i class="fas fa-user-friends mr-1"></i>Apoderado
                            </label>
                        </div>
                        <div class="login-hint" id="role-hint">Administradores, docentes y auxiliares.</div>
                    </div>

                    <div class="form-group">
                        <label class="small mb-1 ml-3" for="school_id">Colegio/Sede</label>
                        <select class="form-control" name="school_id" id="school_id" required style="border-radius:10rem;height:50px;font-size:.8rem">
                            <option value="">Seleccione un colegio...</option>
                            <?php if ($db_connected): $schools = $conn->query('SELECT id,name FROM schools ORDER BY name ASC'); ?>
                                <?php while ($schools && ($row = $schools->fetch_assoc())): ?>
                                    <option value="<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group" id="admin-fields">
                        <input type="text" class="form-control form-control-user" id="username" name="username" placeholder="Usuario" autocomplete="username" required>
                    </div>
                    <div class="form-group" id="student-fields" style="display:none">
                        <input type="text" class="form-control form-control-user" id="student_dni" name="student_dni" placeholder="DNI / Código de Estudiante" autocomplete="username" inputmode="numeric">
                    </div>
                    <div class="form-group">
                        <input type="password" class="form-control form-control-user" id="password" name="password" placeholder="Contraseña" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-user btn-block">Ingresar</button>
                </form>
            </div></div></div>
        </div>
    </div>
</div>
<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/jquery-easing/jquery.easing.min.js"></script>
<script src="js/sb-admin-2.min.js"></script>
<script>
(function($){
    function configureRole(){
        var type=$('input[name="user_type"]:checked').val();
        $('#login-form .alert-danger').remove();
        if(type==='student'){
            $('#admin-fields').hide();
            $('#student-fields').show();
            $('#username').prop('required',false);
            $('#student_dni').prop('required',true).focus();
            $('#role-hint').text('Acceso del estudiante con su DNI/código y clave actual.');
        }else{
            $('#admin-fields').show();
            $('#student-fields').hide();
            $('#student_dni').prop('required',false);
            $('#username').prop('required',true);
            if(type==='guardian'){
                $('#username').attr('placeholder','DNI del apoderado').attr('inputmode','numeric').focus();
                $('#role-hint').text('Apoderados con acceso habilitado por la institución.');
            }else{
                $('#username').attr('placeholder','Usuario').removeAttr('inputmode').focus();
                $('#role-hint').text('Administradores, docentes y auxiliares.');
            }
        }
    }

    $('input[name="user_type"]').on('change',configureRole);

    $('#login-form').on('submit',function(e){
        e.preventDefault();
        var $form=$(this),$btn=$form.find('button[type="submit"]');
        $form.find('.alert-danger').remove();
        $btn.prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Ingresando...');
        var type=$('input[name="user_type"]:checked').val();
        var endpoint=type==='student'?'api/login.php':'auth_login.php';
        $.ajax({url:endpoint,method:'POST',data:$form.serialize(),dataType:'json'})
            .done(function(resp){
                if(resp.status==1||resp.status==='ok'){
                    if(resp.redirect){location.href=resp.redirect;return;}
                    if(Number(resp.login_type||0)===5){location.href='guardian_portal.php';return;}
                    location.href='index.php?page=home';
                    return;
                }
                $form.prepend('<div class="alert alert-danger" role="alert">'+$('<div>').text(resp.message||'Error al iniciar sesión').html()+'</div>');
                $btn.prop('disabled',false).html('Ingresar');
            })
            .fail(function(xhr){
                var resp=xhr.responseJSON||{};
                $form.prepend('<div class="alert alert-danger" role="alert">'+$('<div>').text(resp.message||'Error de conexión con el servidor.').html()+'</div>');
                $btn.prop('disabled',false).html('Ingresar');
            });
    });
})(window.jQuery);
</script>
</body>
</html>
