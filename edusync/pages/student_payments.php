<?php
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$student_name = (string)($_SESSION['student_name'] ?? 'Estudiante');
$student_dni = (string)($_SESSION['student_dni'] ?? '');
?>

<div class="student-payments-page" data-endpoint="api/my_payments.php">
    <div class="student-payments-profile mb-3">
        <div class="student-payments-avatar"><i class="fas fa-user-graduate"></i></div>
        <div>
            <div class="text-xs text-uppercase text-muted">Estudiante</div>
            <div class="h5 mb-0 font-weight-bold text-primary"><?php echo htmlspecialchars($student_name); ?></div>
            <?php if ($student_dni !== ''): ?>
                <div class="text-muted small">DNI: <?php echo htmlspecialchars($student_dni); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="student-payments-heading mb-3">
        <div>
            <h1 class="h4 mb-1 text-gray-800"><i class="fas fa-money-bill-wave text-primary mr-2"></i>Mis Pagos</h1>
            <div class="text-muted small">Historial de recibos, medios de pago y montos confirmados.</div>
        </div>
    </div>

    <div id="student-payments-alert" class="alert d-none" role="alert"></div>

    <div class="row mb-3" id="student-payments-stats">
        <div class="col-12 text-center py-4 text-muted">
            <i class="fas fa-spinner fa-spin mr-2"></i>Cargando resumen de pagos...
        </div>
    </div>

    <div class="card shadow-sm student-payments-card mb-4">
        <div class="card-header student-payments-card-header">
            <div>
                <div class="font-weight-bold"><i class="fas fa-receipt mr-2"></i>Historial de pagos</div>
                <div class="small student-payments-card-subtitle">Cada recibo se muestra como una operación. Los pagos antiguos mantienen compatibilidad individual.</div>
            </div>
            <div class="student-payments-filters">
                <select id="student-payments-year" class="form-control form-control-sm" aria-label="Filtrar por año académico">
                    <option value="">Todos los años</option>
                </select>
                <select id="student-payments-status" class="form-control form-control-sm" aria-label="Filtrar por estado">
                    <option value="">Todos los estados</option>
                    <option value="Confirmado">Confirmados</option>
                    <option value="Anulado">Anulados</option>
                    <option value="Corregido">Corregidos</option>
                </select>
            </div>
        </div>
        <div class="card-body p-0">
            <div id="student-payments-list" class="student-payments-list">
                <div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando pagos...</div>
            </div>
        </div>
    </div>
</div>
