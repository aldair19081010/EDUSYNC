<?php
// Configuración de sesión y base de datos
ini_set('session.save_path', __DIR__ . '/tmp'); // Asegúrate que esta carpeta exista o usa la por defecto
if(!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
// Unificar nombre y path de cookie de sesión
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Si ya está logueado, redirigir (admin/docente o estudiante)
if (isset($_SESSION['login_id']) || (isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'] === true) || (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 4)) {
    header("Location: index.php?page=home");
    exit();
}

// Intentar conectar a BD
$db_connected = false;
if (file_exists('./db_connect.php')) {
    include('./db_connect.php');
    $db_connected = true;
    
    // Obtener configuraciones
    ob_start();
    $system = $conn->query("SELECT * FROM system_settings LIMIT 1");
    if ($system && $system->num_rows > 0) {
        $system = $system->fetch_array();
        foreach ($system as $k => $v) {
            $_SESSION['system'][$k] = $v;
        }

    }
    ob_end_flush();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>EduSync - Login</title>

    <!-- Favicon -->
    <link rel="icon" href="assets/uploads/logo.jpg" type="image/jpeg">
    <link rel="shortcut icon" href="assets/uploads/logo.jpg" type="image/jpeg">

    <!-- Estilos de SB Admin 2 -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <style>
        /* Color principal del sistema #4285f4 (Google Blue) */
        .bg-gradient-primary {
            background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%);
        }
        
        .bg-login-image {
            background-image: url('assets/uploads/background.jpg');
            background-position: center;
            background-size: cover;
        }
        
        .btn-primary {
            background-color: #4285f4;
            border-color: #4285f4;
        }
        
        .btn-primary:hover {
            background-color: #2a75f3;
            border-color: #2a75f3;
        }
        
        .text-primary {
            color: #4285f4 !important;
        }
        
        /* Botones de radio - color principal */
        .btn-outline-primary {
            color: #4285f4;
            border-color: #4285f4;
        }
        
        .btn-outline-primary:hover {
            color: #fff;
            background-color: #4285f4;
            border-color: #4285f4;
        }
        
        .btn-outline-primary.active,
        .btn-outline-primary:active,
        .btn-outline-primary.focus,
        .btn-outline-primary:focus {
            color: #fff;
            background-color: #4285f4 !important;
            border-color: #4285f4 !important;
        }
        
        /* Estilo del logo */
        .login-logo {
            max-width: 150px;
            max-height: 150px;
            object-fit: contain;
            margin-bottom: 1rem;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* Card con sombra mejorada */
        .card {
            border-radius: 15px;
        }
        
        /* Enlaces */
        a {
            color: #4285f4;
        }
        
        a:hover {
            color: #2a75f3;
        }
    </style>
</head>

<body class="bg-gradient-primary">

    <div class="container">

        <!-- Outer Row -->
        <div class="row justify-content-center h-100 align-items-center" style="min-height: 100vh;">

            <div class="col-xl-5 col-lg-6 col-md-8">

                <div class="card o-hidden border-0 shadow-lg my-5">
                    <div class="card-body p-0">
                        <!-- Nested Row within Card Body -->
                        <div class="row">
                            <div class="col-lg-12">
                                <div class="p-5">
                                    <div class="text-center">
                                        <!-- Logo del sistema -->
                                        <div class="mb-4">
                                            <?php if (file_exists('assets/uploads/logo.jpg')): ?>
                                                <img src="assets/uploads/logo.jpg" alt="Logo EduSync" class="login-logo">
                                            <?php else: ?>
                                                <i class="fas fa-school fa-3x text-primary mb-2"></i>
                                            <?php endif; ?>
                                        </div>
                                        <h1 class="h4 text-gray-900 mb-1">Bienvenido a EduSync</h1>
                                        <p class="text-muted small mb-4">Sistema de Gestión Educativa</p>
                                    </div>

                                    <?php if (!$db_connected): ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle"></i> 
                                            Falta `db_connect.php`
                                        </div>
                                    <?php endif; ?>

                                    <form class="user" id="login-form">
                                        <div class="form-group">
                                            <div class="btn-group btn-group-toggle w-100 mb-3" data-toggle="buttons">
                                                <label class="btn btn-outline-primary active">
                                                    <input type="radio" name="user_type" id="type_admin" value="admin" checked> 
                                                    <i class="fas fa-user-tie mr-1"></i>Admin/Docente
                                                </label>
                                                <label class="btn btn-outline-primary">
                                                    <input type="radio" name="user_type" id="type_student" value="student"> 
                                                    <i class="fas fa-user-graduate mr-1"></i>Estudiante
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="small mb-1 ml-3" for="school_id">Colegio/Sede</label>
                                            <select class="form-control" name="school_id" id="school_id" required style="border-radius: 10rem; height: 50px; font-size: 0.8rem;">
                                                <option value="">Seleccione un colegio...</option>
                                                <?php
                                                if ($db_connected) {
                                                    $schools = $conn->query("SELECT id, name FROM schools ORDER BY name ASC");
                                                    while ($row = $schools->fetch_assoc()):
                                                ?>
                                                    <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['name']) ?></option>
                                                <?php 
                                                    endwhile; 
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group" id="admin-fields">
                                            <input type="text" class="form-control form-control-user"
                                                id="username" name="username" placeholder="Usuario" autocomplete="username" required>
                                        </div>
                                        
                                        <div class="form-group" id="student-fields" style="display:none;">
                                            <input type="text" class="form-control form-control-user"
                                                id="student_dni" name="student_dni" placeholder="DNI / Código de Estudiante" autocomplete="username">
                                        </div>
                                        
                                        <div class="form-group">
                                            <input type="password" class="form-control form-control-user"
                                                id="password" name="password" placeholder="Contraseña" autocomplete="current-password" required>
                                        </div>

                                        <button type="submit" class="btn btn-primary btn-user btn-block">
                                            Ingresar
                                        </button>
                                    </form>
                                    
                                
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>

    <script>
        // Cambiar entre Admin y Estudiante
        $('input[name="user_type"]').change(function() {
            if ($(this).val() === 'student') {
                $('#admin-fields').hide();
                $('#student-fields').show();
                $('#username').removeAttr('required');
                $('#student_dni').attr('required', 'required');
                $('#student_dni').focus();
            } else {
                $('#admin-fields').show();
                $('#student-fields').hide();
                $('#student_dni').removeAttr('required');
                $('#username').attr('required', 'required');
                $('#username').focus();
            }
        });

        $('#login-form').submit(function(e) {
            e.preventDefault();
            console.log('Formulario enviado');
            var btn = $(this).find('button[type="submit"]');
            btn.attr('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Ingresando...');
            
            if ($(this).find('.alert-danger').length > 0)
                $(this).find('.alert-danger').remove();

            var userType = $('input[name="user_type"]:checked').val();
            var formData = $(this).serialize();
            console.log('Tipo de usuario:', userType);
            console.log('Datos del formulario:', formData);

            // Si es estudiante, usar endpoint diferente
            var endpoint = userType === 'student' ? 'api/login.php' : 'ajax.php?action=login';
            console.log('Endpoint:', endpoint);

            $.ajax({
                url: endpoint,
                method: 'POST',
                data: formData,
                dataType: 'json',
                error: err => {
                    console.error('Error en AJAX:', err);
                    console.log('Response text:', err.responseText);
                    btn.removeAttr('disabled').html('Ingresar');
                    $('#login-form').prepend('<div class="alert alert-danger" role="alert">Error de conexión con el servidor.</div>');
                },
                success: function(resp) {
                    console.log('Respuesta del servidor:', resp);
                    if (resp.status == 1 || resp.status === 'ok') {
                        if (resp.session_id) console.log('Session ID (server):', resp.session_id);
                        if (resp.login_type) console.log('login_type (server):', resp.login_type);
                        // Admin/Docente o Estudiante - Todos van a home
                        location.href = 'index.php?page=home';
                    } else {
                        $('#login-form').prepend('<div class="alert alert-danger" role="alert">' + (resp.message || 'Error al iniciar sesión') + '</div>');
                        btn.removeAttr('disabled').html('Ingresar');
                    }
                }
            });
        });
    </script>
</body>
</html>
