<?php
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");

$school = (int)($_SESSION['login_school_id'] ?? 0);
$user = (int)($_SESSION['login_id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

function arr_datetime($value) {
    if (!$value) return '—';
    try {
        $dt = new DateTime($value, new DateTimeZone('America/Lima'));
        return $dt->format('d/m/Y h:i a');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

$role = $conn->prepare('SELECT type,is_director FROM users WHERE id=? AND school_id=? LIMIT 1');
$role->bind_param('ii', $user, $school);
$role->execute();
$roleData = $role->get_result()->fetch_assoc();
$role->close();
$allowed = $roleData && (int)$roleData['type'] === 1;
if (!$allowed) {
    echo '<div class="alert alert-danger">No tiene permiso para autorizar solicitudes.</div>';
    return;
}

$stmt = $conn->prepare("SELECT r.*,u.name AS requester,rv.name AS reviewer_name,st.name AS student_name,st.id_no,a.fecha,a.hora AS attendance_time,a.estado AS attendance_status,a.notes AS attendance_notes
    FROM attendance_change_requests r
    INNER JOIN users u ON u.id=r.requested_by
    LEFT JOIN users rv ON rv.id=r.reviewed_by AND rv.school_id=r.school_id
    LEFT JOIN asistencia a ON a.id=r.attendance_id
    LEFT JOIN student st ON st.id=a.student_id
    WHERE r.id=? AND r.school_id=? LIMIT 1");
$stmt->bind_param('ii', $id, $school);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    echo '<div class="alert alert-warning">La solicitud no existe o pertenece a otra institución.</div>';
    return;
}

$payload = json_decode($request['request_payload'], true) ?: [];
$wanted = $payload['requested'] ?? [];
$status = (string)$request['status'];
$statusClass = $status === 'Pendiente' ? 'warning' : ($status === 'Aprobada' ? 'success' : 'danger');
?>


<?php
require_once __DIR__ . '/includes/notification_detail_ui.php';
edn_header('attendance','Solicitud de asistencia: '.$request['request_type'],
    (string)($request['requester']??'Usuario').' · Revisión administrativa',
    $request['created_at'],$status);
?>
<div id="arr-message"></div>


<div class="row mb-3">
  <div class="col-md-6 mb-2"><div class="card shadow-sm h-100"><div class="card-body py-3">
    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Solicitado por</div>
    <div class="font-weight-bold text-gray-800"><?= edn_escape($request['requester']) ?></div>
    <small class="text-muted"><?= edn_escape(arr_datetime($request['created_at'])) ?></small>
  </div></div></div>
  <div class="col-md-6 mb-2"><div class="card shadow-sm h-100"><div class="card-body py-3">
    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Motivo</div>
    <div class="text-gray-800"><?= edn_escape($request['reason'] ?: 'Sin motivo adicional') ?></div>
  </div></div></div>
</div>

<?php if ($request['request_type'] === 'Editar nómina'): $changes = $payload['changes'] ?? []; ?>
    <div class="mb-2 font-weight-bold"><?= htmlspecialchars(($payload['date'] ?? '') . ' · ' . ($payload['nivel'] ?? '') . ' ' . ($payload['grado'] ?? '') . ' ' . ($payload['seccion'] ?? '')) ?></div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
            <thead class="thead-light"><tr><th>Estudiante</th><th>Estado anterior</th><th>Hora anterior</th><th>Estado solicitado</th><th>Hora solicitada</th><th>Observación</th></tr></thead>
            <tbody>
            <?php foreach ($changes as $change): $previous = $change['previous'] ?? []; $requested = $change['requested'] ?? []; ?>
                <tr>
                    <td><strong><?= htmlspecialchars($change['student_name'] ?? 'Estudiante') ?></strong><div class="small text-muted"><?= htmlspecialchars($change['student_code'] ?? '') ?></div></td>
                    <td><?= htmlspecialchars($previous['status'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($previous['time'] ?? '—') ?></td>
                    <td><strong><?= htmlspecialchars($requested['status'] ?? '—') ?></strong></td>
                    <td><strong><?= htmlspecialchars($requested['time'] ?? '—') ?></strong></td>
                    <td><?= htmlspecialchars($requested['notes'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($status === 'Pendiente'): ?><div class="alert alert-info mt-3 mb-0">Al aprobar se aplicarán juntos los <?= count($changes) ?> cambios de esta solicitud.</div><?php endif; ?>
<?php elseif ($request['request_type'] === 'Editar registro'): ?>
    <div class="mb-2 font-weight-bold"><?= htmlspecialchars($request['student_name'] ?: 'Estudiante') ?> · <?= htmlspecialchars($request['fecha'] ?: ($payload['date'] ?? '')) ?></div>
    <div class="row align-items-stretch">
        <div class="col-md-5 mb-2"><div class="card shadow-sm h-100"><div class="card-body py-3"><div class="text-xs font-weight-bold text-info text-uppercase mb-2">Registro actual</div><div>Estado: <strong><?= edn_escape($request['attendance_status']) ?></strong></div><div>Hora: <strong><?= edn_escape($request['attendance_time']) ?></strong></div><div>Observación: <?= edn_escape($request['attendance_notes'] ?: '—') ?></div></div></div></div>
        <div class="col-md-2 d-flex align-items-center justify-content-center text-primary mb-2"><i class="fas fa-arrow-right"></i></div>
        <div class="col-md-5 mb-2"><div class="card shadow-sm border-left-primary h-100"><div class="card-body py-3"><div class="text-xs font-weight-bold text-primary text-uppercase mb-2">Cambio solicitado</div><div>Estado: <strong><?= edn_escape($wanted['status'] ?? '') ?></strong></div><div>Hora: <strong><?= edn_escape($wanted['time'] ?? '') ?></strong></div><div>Observación: <?= edn_escape($wanted['notes'] ?? '—') ?></div></div></div></div>
    </div>
<?php elseif ($request['request_type'] === 'Reabrir día'): ?>
    <div class="card shadow-sm mb-3"><div class="card-body"><h6 class="font-weight-bold text-info">Día que se solicita reabrir</h6><div><?= edn_escape(($payload['date'] ?? '') . ' · ' . ($payload['nivel'] ?? '') . ' ' . ($payload['grado'] ?? '') . ' ' . ($payload['seccion'] ?? '')) ?></div></div></div>
<?php else: ?>
    <div class="card shadow-sm mb-3"><div class="card-body"><h6 class="font-weight-bold text-info">Detalle</h6><div class="font-weight-bold"><?= edn_escape($request['student_name'] ?: 'Registro de asistencia') ?></div><small class="text-muted"><?= edn_escape($request['request_type']) ?></small></div></div>
<?php endif; ?>

<?php if ($status === 'Pendiente'): ?>
    <div class="card shadow-sm mt-3 mb-3">
      <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Resolver solicitud de asistencia</h6></div>
      <div class="card-body">
    <div class="form-group mt-3">
        <label>Observación de la revisión</label>
        <textarea id="arr-notes" class="form-control" maxlength="255" placeholder="Opcional al aprobar; recomendable al rechazar"></textarea>
    </div>
    <div class="d-flex justify-content-end">
        <button type="button" class="btn btn-outline-danger mr-2 arr-review" data-decision="Rechazada"><i class="fas fa-times mr-1"></i>Rechazar</button>
        <button type="button" class="btn btn-success arr-review" data-decision="Aprobada"><i class="fas fa-check mr-1"></i>Aprobar y aplicar</button>
    </div></div></div>
<?php else: ?>
    <div class="card shadow-sm mt-3 mb-3 border-left-<?= $status === 'Aprobada' ? 'success' : 'danger' ?>"><div class="card-body">
        <div class="font-weight-bold mb-2"><i class="fas <?= $status === 'Aprobada' ? 'fa-check-circle text-success' : 'fa-times-circle text-danger' ?> mr-1"></i>Esta solicitud ya fue atendida</div>
        <div class="arr-resolution-grid">
            <div><div class="arr-label">Resultado</div><div class="arr-value"><?= htmlspecialchars($status) ?></div></div>
            <div><div class="arr-label">Revisada por</div><div class="arr-value"><?= htmlspecialchars($request['reviewer_name'] ?: 'Administrador') ?></div></div>
            <div><div class="arr-label">Fecha de resolución</div><div class="arr-value"><?= htmlspecialchars(arr_datetime($request['reviewed_at'])) ?></div></div>
            <div class="wide"><div class="arr-label">Observación</div><div class="arr-value"><?= htmlspecialchars($request['review_notes'] ?: 'Sin observación adicional') ?></div></div>
        </div>
    </div></div>
<?php endif; ?>


<?php edn_footer(); ?>
<script>
(function($){
    function refreshResolution(message){
        if(message){
            $('#arr-message').html('<div class="alert alert-info">' + $('<div>').text(message).html() + ' Actualizando el estado…</div>');
        }
        $.get('review_attendance_request.php?id=<?= json_encode($id) ?>')
            .done(function(html){
                var body = $('#uni_modal_body');
                if(!body.length) body = $('#uni_modal .modal-body');
                if(body.length) body.html(html);
                $(document).trigger('attendance:request-reviewed',[<?= json_encode($id) ?>]);
            });
    }

    $('.arr-review').click(function(){
        var button = $(this), decision = button.data('decision');
        if(decision === 'Rechazada' && !$('#arr-notes').val().trim()){
            return $('#arr-message').html('<div class="alert alert-warning">Indique el motivo del rechazo.</div>');
        }
        $('.arr-review').prop('disabled', true);
        $.post('attendance_api.php?action=review_request', {
            csrf_token: <?= json_encode($csrf) ?>,
            id: <?= json_encode($id) ?>,
            decision: decision,
            notes: $('#arr-notes').val()
        }, null, 'json').done(function(r){
            if(!r.status){
                if(/ya fue revisada|no existe/i.test(String(r.message || ''))){
                    return refreshResolution(r.message || 'La solicitud ya fue atendida por otro administrador.');
                }
                $('.arr-review').prop('disabled', false);
                return $('#arr-message').html('<div class="alert alert-danger">' + $('<div>').text(r.message).html() + '</div>');
            }
            if(typeof alert_toast === 'function') alert_toast(r.message, 'success');
            $(document).trigger('attendance:request-reviewed',[<?= json_encode($id) ?>]);
            setTimeout(function(){ refreshResolution(); }, 350);
        }).fail(function(x){
            var message = (x.responseJSON || {}).message || 'No se pudo revisar la solicitud.';
            if(/ya fue revisada|no existe/i.test(String(message))){
                return refreshResolution(message);
            }
            $('.arr-review').prop('disabled', false);
            $('#arr-message').html('<div class="alert alert-danger">' + $('<div>').text(message).html() + '</div>');
        });
    });
})(jQuery);
</script>
