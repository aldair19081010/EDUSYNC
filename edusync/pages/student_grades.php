<?php
if ((!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) &&
    (!isset($_SESSION['login_type']) || (int)$_SESSION['login_type'] !== 4)) {
    header('Location: login.php');
    exit();
}

if (empty($_SESSION['student_id'])) {
    header('Location: login.php');
    exit();
}

$student_name = (string)($_SESSION['student_name'] ?? $_SESSION['login_name'] ?? 'Estudiante');
?>

<section id="student-grades-app" class="sg-page" data-endpoint="api/my_grades_v2.php" aria-busy="true">
    <div class="sg-hero">
        <div class="sg-identity">
            <div class="sg-avatar" aria-hidden="true"><i class="fas fa-user-graduate"></i></div>
            <div class="sg-identity-copy">
                <div class="sg-eyebrow">Portal del estudiante</div>
                <h1>Mis Notas</h1>
                <div class="sg-student-name"><?php echo htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="sg-student-meta" id="sg-student-meta">Cargando información académica...</div>
            </div>
        </div>
        <div class="sg-year-control">
            <label for="sg-year-filter">Año académico</label>
            <select id="sg-year-filter" class="form-control" aria-label="Seleccionar año académico">
                <option value="">Cargando...</option>
            </select>
        </div>
    </div>

    <div id="sg-debt-alert" class="alert alert-danger sg-debt-alert d-none" role="alert">
        <div><i class="fas fa-lock mr-2"></i><strong>Calificaciones temporalmente restringidas</strong></div>
        <div class="small mt-1" id="sg-debt-message"></div>
    </div>

    <div id="sg-summary" class="sg-summary-grid" aria-live="polite">
        <div class="sg-summary-card is-loading"></div>
        <div class="sg-summary-card is-loading"></div>
        <div class="sg-summary-card is-loading"></div>
        <div class="sg-summary-card is-loading"></div>
    </div>

    <div class="card sg-panel shadow-sm mb-4">
        <div class="sg-panel-toolbar">
            <div>
                <h2>Calificaciones</h2>
                <p id="sg-panel-caption">Resultados por bimestre y progreso durante el año.</p>
            </div>
            <div class="sg-view-switch" role="group" aria-label="Vista de calificaciones">
                <button type="button" class="sg-view-btn active" data-view="bimester" aria-pressed="true">
                    <i class="fas fa-calendar-alt"></i><span>Bimestre</span>
                </button>
                <button type="button" class="sg-view-btn" data-view="annual" aria-pressed="false">
                    <i class="fas fa-chart-line"></i><span>Progreso anual</span>
                </button>
            </div>
        </div>

        <div id="sg-bimester-nav" class="sg-bimester-nav" aria-label="Seleccionar bimestre"></div>

        <div id="sg-content" class="sg-content">
            <div class="sg-loading-state">
                <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
                <span>Cargando calificaciones...</span>
            </div>
        </div>
    </div>
</section>

<div class="modal fade sg-detail-modal" id="sg-detail-modal" tabindex="-1" role="dialog" aria-labelledby="sg-detail-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="sg-modal-eyebrow" id="sg-detail-meta"></div>
                    <h5 class="modal-title" id="sg-detail-title">Detalle del curso</h5>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="sg-detail-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
