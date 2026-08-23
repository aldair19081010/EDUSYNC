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
</style>
