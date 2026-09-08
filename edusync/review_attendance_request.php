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
<style>
.arr-box{border:1px solid #e4e7ec;border-radius:8px;padding:12px;background:#f8fafc}
.arr-label{font-size:.75rem;color:#667085;text-transform:uppercase;font-weight:700}
.arr-value{color:#344054;font-weight:600}
.arr-arrow{display:flex;align-items:center;justify-content:center;color:#4e73df;font-size:1.2rem}
.arr-resolution{border-radius:9px;padding:14px;border:1px solid #d9e2ec}
.arr-resolution.approved{background:#f0fff5;border-color:#b7e4c7}
.arr-resolution.rejected{background:#fff5f5;border-color:#f3b8b8}
.arr-resolution-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem 1rem}
.arr-resolution-grid .wide{grid-column:1/-1}
@media(max-width:767px){.arr-arrow{transform:rotate(90deg);padding:8px}.arr-resolution-grid{grid-template-columns:1fr}}
</style>

<div id="arr-message"></div>
<div class="mb-3">
    <span class="badge badge-secondary"><?= htmlspecialchars($request['request_type']) ?></span>
    <span class="badge badge-<?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
</div>

<div class="row mb-3">
    <div class="col-md-6 mb-2 mb-md-0">
        <div class="arr-box h-100">
            <div class="arr-label">Solicitado por</div>
            <div class="arr-value"><?= htmlspecialchars($request['requester']) ?></div>
            <div class="small text-muted mt-2"><?= htmlspecialchars(arr_datetime($request['created_at'])) ?></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="arr-box h-100">
            <div class="arr-label">Motivo</div>
            <div class="arr-value"><?= htmlspecialchars($request['reason'] ?: 'Sin motivo adicional') ?></div>
        </div>
    </div>
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
        <div class="col-md-5"><div class="arr-box h-100"><div class="arr-label mb-2">Registro actual</div><div>Estado: <strong><?= htmlspecialchars($request['attendance_status']) ?></strong></div><div>Hora: <strong><?= htmlspecialchars($request['attendance_time']) ?></strong></div><div>Observación: <?= htmlspecialchars($request['attendance_notes'] ?: '—') ?></div></div></div>
        <div class="col-md-2 arr-arrow"><i class="fas fa-arrow-right"></i></div>
        <div class="col-md-5"><div class="arr-box h-100 border-primary"><div class="arr-label mb-2">Cambio solicitado</div><div>Estado: <strong><?= htmlspecialchars($wanted['status'] ?? '') ?></strong></div><div>Hora: <strong><?= htmlspecialchars($wanted['time'] ?? '') ?></strong></div><div>Observación: <?= htmlspecialchars($wanted['notes'] ?? '—') ?></div></div></div>
    </div>
<?php elseif ($request['request_type'] === 'Reabrir día'): ?>
    <div class="arr-box"><div class="arr-label mb-2">Día que se solicita reabrir</div><div class="arr-value"><?= htmlspecialchars(($payload['date'] ?? '') . ' · ' . ($payload['nivel'] ?? '') . ' ' . ($payload['grado'] ?? '') . ' ' . ($payload['seccion'] ?? '')) ?></div></div>
<?php else: ?>
    <div class="arr-box"><div class="arr-label mb-2">Detalle</div><div class="arr-value"><?= htmlspecialchars($request['student_name'] ?: 'Registro de asistencia') ?></div><div class="small text-muted mt-2"><?= htmlspecialchars($request['request_type']) ?></div></div>
<?php endif; ?>

<?php if ($status === 'Pendiente'): ?>
    <div class="form-group mt-3">
        <label>Observación de la revisión</label>
        <textarea id="arr-notes" class="form-control" maxlength="255" placeholder="Opcional al aprobar; recomendable al rechazar"></textarea>
    </div>
    <div class="d-flex justify-content-end">
        <button type="button" class="btn btn-outline-danger mr-2 arr-review" data-decision="Rechazada"><i class="fas fa-times mr-1"></i>Rechazar</button>
        <button type="button" class="btn btn-success arr-review" data-decision="Aprobada"><i class="fas fa-check mr-1"></i>Aprobar y aplicar</button>
    </div>
<?php else: ?>
    <div class="arr-resolution mt-3 <?= $status === 'Aprobada' ? 'approved' : 'rejected' ?>">
        <div class="font-weight-bold mb-2"><i class="fas <?= $status === 'Aprobada' ? 'fa-check-circle text-success' : 'fa-times-circle text-danger' ?> mr-1"></i>Esta solicitud ya fue atendida</div>
        <div class="arr-resolution-grid">
            <div><div class="arr-label">Resultado</div><div class="arr-value"><?= htmlspecialchars($status) ?></div></div>
            <div><div class="arr-label">Revisada por</div><div class="arr-value"><?= htmlspecialchars($request['reviewer_name'] ?: 'Administrador') ?></div></div>
            <div><div class="arr-label">Fecha de resolución</div><div class="arr-value"><?= htmlspecialchars(arr_datetime($request['reviewed_at'])) ?></div></div>
            <div class="wide"><div class="arr-label">Observación</div><div class="arr-value"><?= htmlspecialchars($request['review_notes'] ?: 'Sin observación adicional') ?></div></div>
        </div>
    </div>
<?php endif; ?>

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
