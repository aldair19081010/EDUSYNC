<?php
if ((!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) &&
    (!isset($_SESSION['login_type']) || (int)$_SESSION['login_type'] !== 4)) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

$student_name = (string)($_SESSION['student_name'] ?? 'Estudiante');
$student_dni = (string)($_SESSION['student_dni'] ?? '');
?>

<div id="student-attendance-app" class="student-attendance-page" data-endpoint="api/my_attendance.php">
    <div class="student-profile-bar mb-3">
        <div class="avatar-circle mr-3">
            <i class="fas fa-user-graduate"></i>
        </div>
        <div>
            <div class="text-xs text-uppercase text-muted">Estudiante</div>
            <div class="h5 mb-0 font-weight-bold text-primary"><?php echo htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($student_dni !== ''): ?>
                <div class="text-muted small">DNI: <?php echo htmlspecialchars($student_dni, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-sm-flex align-items-end justify-content-between mb-3 student-attendance-heading">
        <div>
            <h1 class="h4 mb-1 text-gray-800">
                <i class="fas fa-calendar-check mr-2 text-primary"></i>Mis Asistencias
            </h1>
            <div class="text-muted small">Consulta tu asistencia diaria, horarios de entrada y salida, tardanzas y ausencias registradas.</div>
        </div>
        <div class="student-attendance-year mt-3 mt-sm-0">
            <label for="attendance-year-filter" class="small font-weight-bold text-muted mb-1">Año académico</label>
            <select id="attendance-year-filter" class="form-control form-control-sm">
                <option value="">Cargando...</option>
            </select>
        </div>
    </div>

    <div id="attendance-error" class="alert alert-danger d-none" role="alert"></div>

    <div class="row mb-2" id="attendance-stats">
        <div class="col-12 text-center py-4 text-muted">
            <i class="fas fa-spinner fa-spin mr-2"></i>Cargando resumen de asistencias...
        </div>
    </div>

    <div class="card shadow-sm mb-4 student-attendance-card">
        <div class="card-header py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h6 class="m-0 font-weight-bold text-gray-800">
                    <i class="fas fa-list-ul mr-2 text-primary"></i>Registro de asistencia
                </h6>
                <div id="attendance-caption" class="small text-muted mt-1">Cargando información...</div>
            </div>
            <div class="student-attendance-legend small text-muted mt-2 mt-md-0">
                <span class="badge badge-success mr-1">Presente</span>
                <span class="badge badge-warning mr-1">Tarde</span>
                <span class="badge badge-danger mr-1">Ausente</span>
                <span class="badge badge-info">Justificada / Permiso</span>
            </div>
        </div>
        <div class="card-body p-0 p-md-3">
            <div id="attendance-months">
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-spinner fa-spin mr-2"></i>Cargando asistencias...
                </div>
            </div>
        </div>
    </div>
</div>
