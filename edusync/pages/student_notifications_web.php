<?php
// Portal de estudiantes: utiliza los mismos eventos que la app, no la bandeja administrativa.
$isStudentWeb = !empty($_SESSION['student_logged_in']) && !empty($_SESSION['student_id']) && !empty($_SESSION['student_school_id']);
?>
<link rel="stylesheet" href="css/student_portal_consistency.css?v=<?php echo (int)(@filemtime(__DIR__ . '/../css/student_portal_consistency.css') ?: 1); ?>">
<div class="swn" id="student-web-notifications" data-open-id="<?php echo max(0,(int)($_GET['notification_id']??0)); ?>">
  <div class="swn-head">
    <div>
      <h1 class="swn-title"><i class="far fa-bell text-primary mr-2"></i>Mis notificaciones</h1>
      <p class="swn-muted">Comunicados, avisos y actividad de tu cuenta escolar.</p>
    </div>
    <div class="d-flex align-items-center flex-wrap" style="gap:.45rem">
      <span class="badge badge-light border py-2 px-3" id="swn-unread-count">0 sin leer</span>
      <button type="button" class="btn btn-sm btn-outline-primary" id="swn-refresh"><i class="fas fa-sync-alt mr-1"></i>Actualizar</button>
      <button type="button" class="btn btn-sm btn-light border d-none" id="swn-mark-all"><i class="fas fa-check-double mr-1"></i>Marcar leídas</button>
    </div>
  </div>
  <?php if (!$isStudentWeb): ?>
    <div class="alert alert-warning">Para consultar tus notificaciones, inicia sesión como estudiante.</div>
  <?php else: ?>
    <div id="swn-error" class="alert alert-warning d-none" role="alert"></div>
    <div class="swn-tabs" role="tablist" aria-label="Filtrar notificaciones">
      <button type="button" class="swn-tab active" data-filter="all" aria-selected="true">Todas</button>
      <button type="button" class="swn-tab" data-filter="announcements" aria-selected="false">Comunicados</button>
      <button type="button" class="swn-tab" data-filter="alerts" aria-selected="false">Notas y deudas</button>
      <button type="button" class="swn-tab" data-filter="activity" aria-selected="false">Asistencia y pagos</button>
    </div>
    <div class="swn-list" id="swn-items" aria-live="polite"><div class="swn-empty"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando tus notificaciones...</div></div>
    <div class="text-center mt-3"><button type="button" id="swn-load-more" class="btn btn-sm btn-outline-secondary d-none">Cargar anteriores</button></div>
    <div class="modal fade" id="swn-announcement-modal" tabindex="-1" role="dialog" aria-labelledby="swn-announcement-title">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title" id="swn-announcement-title">Comunicado</h5><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">&times;</button></div>
          <div class="modal-body">
            <div id="swn-announcement-sender" class="text-muted small mb-2"></div>
            <p id="swn-announcement-body" style="white-space:pre-wrap;overflow-wrap:anywhere;"></p>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-light border" data-dismiss="modal">Cerrar</button></div>
        </div>
      </div>
    </div>
    <script src="js/student_web_notifications.js?v=<?php echo @filemtime(__DIR__.'/../js/student_web_notifications.js')?:time(); ?>"></script>
  <?php endif; ?>
</div>
