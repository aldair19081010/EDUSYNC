<?php
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$student_name = (string)($_SESSION['student_name'] ?? 'Estudiante');
$student_dni = (string)($_SESSION['student_dni'] ?? '');
?>

<div id="student-payments-app" class="student-payments-page" data-endpoint="api/my_payments.php">
    <div class="mb-3 d-flex align-items-center student-profile-bar">
        <div class="avatar-circle mr-3">
            <i class="fas fa-user-graduate"></i>
        </div>
        <div>
            <div class="text-xs text-uppercase text-muted">Estudiante</div>
            <div class="h5 mb-0 font-weight-bold text-primary"><?php echo htmlspecialchars($student_name); ?></div>
            <?php if ($student_dni !== ''): ?>
                <div class="text-muted small">DNI: <?php echo htmlspecialchars($student_dni); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-sm-flex align-items-end justify-content-between mb-3 student-payments-heading">
        <div>
            <h1 class="h4 mb-1 text-gray-800">
                <i class="fas fa-money-bill-wave mr-2 text-primary"></i>Mis Pagos
            </h1>
            <div class="text-muted small">Consulta tus pagos confirmados y comprobantes vigentes.</div>
        </div>
        <div class="student-payments-year mt-3 mt-sm-0">
            <label for="student-payments-year" class="small font-weight-bold text-muted mb-1">Año académico</label>
            <select id="student-payments-year" class="form-control form-control-sm">
                <option value="">Cargando...</option>
            </select>
        </div>
    </div>

    <div id="student-payments-alert" class="alert d-none" role="alert"></div>

    <div class="row mb-2" id="student-payments-stats">
        <div class="col-12 text-center py-4 text-muted">
            <i class="fas fa-spinner fa-spin mr-2"></i>Cargando resumen de pagos...
        </div>
    </div>

    <div class="card shadow-sm mb-4 student-payments-card">
        <div class="card-header py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h6 class="m-0 font-weight-bold text-gray-800">
                    <i class="fas fa-receipt mr-2 text-primary"></i>Historial de pagos
                </h6>
                <div id="student-payments-caption" class="small text-muted mt-1">Cargando información...</div>
            </div>
            <div class="small text-muted mt-2 mt-md-0">
                Solo se muestran comprobantes vigentes
            </div>
        </div>
        <div class="card-body p-0">
            <div id="student-payments-list" class="student-payments-list">
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-spinner fa-spin mr-2"></i>Cargando pagos...
                </div>
            </div>
        </div>
    </div>
</div>
