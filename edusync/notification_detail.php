<?php
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';

function nd_e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function nd_date($value) {
    if (!$value) return '—';
    $time = strtotime($value);
    return $time ? date('d/m/Y H:i', $time) : nd_e($value);
}

$school = (int)($_SESSION['login_school_id'] ?? 0);
$user = (int)($_SESSION['login_id'] ?? 0);
$type = trim($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$role = $conn->prepare('SELECT type,is_director,teacher_id FROM users WHERE id=? AND school_id=? LIMIT 1');
if (!$role) { echo '<div class="alert alert-danger">No se pudo validar el acceso.</div>'; return; }
$role->bind_param('ii', $user, $school); $role->execute(); $roleData = $role->get_result()->fetch_assoc(); $role->close();
if (!$roleData || !$id) { echo '<div class="alert alert-danger">La notificación no está disponible para este usuario.</div>'; return; }
$isApprover = (int)$roleData['type'] === 1;
$teacherId = (int)($roleData['teacher_id'] ?? 0);
$title = 'Detalle de notificación'; $category = 'Sistema'; $priority = 'Normal'; $status = '';
$message = ''; $createdAt = null; $content = '';

if ($type === 'attendance_result') {
    $stmt = $conn->prepare("SELECT r.*,rq.name requester,rv.name reviewer,st.name student_name,st.id_no,a.fecha,a.hora attendance_time,a.estado attendance_status FROM attendance_change_requests r INNER JOIN users rq ON rq.id=r.requested_by AND rq.school_id=r.school_id LEFT JOIN users rv ON rv.id=r.reviewed_by AND rv.school_id=r.school_id LEFT JOIN asistencia a ON a.id=r.attendance_id AND a.school_id=r.school_id LEFT JOIN student st ON st.id=a.student_id AND st.school_id=r.school_id WHERE r.id=? AND r.school_id=? AND (r.requested_by=? OR ?=1) LIMIT 1");
    $approverFlag = $isApprover ? 1 : 0; $stmt->bind_param('iiii', $id, $school, $user, $approverFlag); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) { echo '<div class="alert alert-warning">La resolución no existe o pertenece a otro usuario o colegio.</div>'; return; }
    $title = $row['status'] === 'Aprobada' ? 'Solicitud de asistencia aprobada' : 'Solicitud de asistencia rechazada';
    $category = 'Asistencia'; $priority = $row['status'] === 'Rechazada' ? 'Alta' : 'Normal'; $status = $row['status']; $createdAt = $row['reviewed_at'] ?: $row['created_at'];
    $payload = json_decode($row['request_payload'], true) ?: []; $changes = $payload['changes'] ?? []; $wanted = $payload['requested'] ?? [];
    ob_start(); ?>
    <div class="row mb-3">
      <div class="col-md-6 mb-2"><div class="nd-box"><span>Solicitud</span><strong><?=nd_e($row['request_type'])?></strong></div></div>
      <div class="col-md-6 mb-2"><div class="nd-box"><span>Solicitado por</span><strong><?=nd_e($row['requester'])?></strong></div></div>
      <div class="col-md-6 mb-2"><div class="nd-box"><span>Revisado por</span><strong><?=nd_e($row['reviewer'] ?: 'Administración')?></strong></div></div>
      <div class="col-md-6 mb-2"><div class="nd-box"><span>Fecha de revisión</span><strong><?=nd_date($row['reviewed_at'])?></strong></div></div>
    </div>
    <div class="nd-section"><span>Motivo de la solicitud</span><div><?=nd_e($row['reason'] ?: 'Sin motivo adicional')?></div></div>
    <div class="nd-section"><span>Respuesta de administración</span><div><?=nd_e($row['review_notes'] ?: ($row['status']==='Aprobada'?'Aprobada y aplicada sin observaciones.':'Rechazada sin observaciones adicionales.'))?></div></div>
    <?php if ($changes): ?>
      <h6 class="font-weight-bold mt-3">Cambios incluidos (<?=count($changes)?>)</h6>
      <div class="table-responsive"><table class="table table-sm table-bordered"><thead class="thead-light"><tr><th>Estudiante</th><th>Antes</th><th>Solicitado</th><th>Observación</th></tr></thead><tbody>
      <?php foreach ($changes as $change): $previous=$change['previous']??[]; $requested=$change['requested']??[]; ?><tr><td><strong><?=nd_e($change['student_name']??'Estudiante')?></strong><div class="small text-muted"><?=nd_e($change['student_code']??'')?></div></td><td><?=nd_e(($previous['status']??'—').' · '.($previous['time']??'—'))?></td><td><?=nd_e(($requested['status']??'—').' · '.($requested['time']??'—'))?></td><td><?=nd_e($requested['notes']??'—')?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php elseif ($wanted): ?>
      <div class="row mt-3"><div class="col-md-6 mb-2"><div class="nd-section"><span>Registro anterior</span><div><?=nd_e(($row['attendance_status']?:'—').' · '.($row['attendance_time']?:'—'))?></div></div></div><div class="col-md-6 mb-2"><div class="nd-section border-primary"><span>Cambio solicitado</span><div><strong><?=nd_e(($wanted['status']??'—').' · '.($wanted['time']??'—'))?></strong></div><div><?=nd_e($wanted['notes']??'')?></div></div></div></div>
    <?php else: ?><div class="nd-section mt-3"><span>Contexto</span><div><?=nd_e(trim(($payload['date']??$row['fecha']??'').' '.($payload['nivel']??'').' '.($payload['grado']??'').' '.($payload['seccion']??'')) ?: ($row['student_name'] ?: 'Registro de asistencia'))?></div></div><?php endif;
    $content = ob_get_clean();
} elseif ($type === 'low_grade') {
    $stmt=$conn->prepare("SELECT lgn.*,s.name student_name,s.id_no,s.nivel,s.grado,s.seccion FROM low_grade_notifications lgn INNER JOIN student s ON s.id=lgn.student_id WHERE lgn.id=? AND s.school_id=? AND (?=1 OR lgn.teacher_id=?) LIMIT 1");$approverFlag=$isApprover?1:0;$stmt->bind_param('iiii',$id,$school,$approverFlag,$teacherId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row){echo '<div class="alert alert-warning">La alerta no existe o no corresponde a su colegio y rol.</div>';return;}
    $title='Alerta de notas bajas';$category='Alerta estudiantil';$priority='Alta';$createdAt=$row['created_at'];$status='Requiere seguimiento';$courses=json_decode($row['failed_courses'],true)?:[];
    ob_start();?><div class="row mb-3"><div class="col-md-6 mb-2"><div class="nd-box"><span>Estudiante</span><strong><?=nd_e($row['student_name'])?></strong><small><?=nd_e($row['id_no'])?></small></div></div><div class="col-md-6 mb-2"><div class="nd-box"><span>Aula</span><strong><?=nd_e($row['nivel'].' · '.$row['grado'].' '.$row['seccion'])?></strong><small>Bimestre <?=nd_e($row['bimestre'])?></small></div></div></div><div class="nd-section"><span>Resumen</span><div><strong><?=nd_e($row['count_low_grades'])?></strong> calificaciones bajas detectadas.</div></div><?php if($courses):?><h6 class="font-weight-bold mt-3">Cursos involucrados</h6><ul class="list-group"><?php foreach($courses as $course):?><li class="list-group-item py-2"><?=nd_e(is_array($course)?($course['name']??$course['course']??json_encode($course,JSON_UNESCAPED_UNICODE)):$course)?></li><?php endforeach;?></ul><?php endif;$content=ob_get_clean();
} elseif ($type !== 'evaluation' && $type !== 'attendance_request') {
    $roleName=$isApprover?'Administrador':((int)$roleData['type']===3?'Auxiliar':((int)$roleData['type']===2?'Docente':'Usuario'));
    $stmt=$conn->prepare("SELECT * FROM notification_events WHERE id=? AND school_id=? AND (recipient_user_id=? OR (recipient_user_id IS NULL AND recipient_role=?)) LIMIT 1");$stmt->bind_param('iiis',$id,$school,$user,$roleName);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row){echo '<div class="alert alert-warning">La notificación no existe o no está dirigida a su usuario y rol.</div>';return;}
    $title=$row['title'];$category=$row['notification_type'];$priority=$row['priority'];$message=$row['message'];$createdAt=$row['created_at'];
    $content='<div class="nd-section"><span>Mensaje</span><div>'.nl2br(nd_e($message?:'Sin información adicional.')).'</div></div>';
} else { echo '<div class="alert alert-warning">Utilice la vista especializada de esta notificación.</div>'; return; }
?>
<style>.nd-head{border-bottom:1px solid #e3e6f0;padding-bottom:.8rem;margin-bottom:1rem}.nd-icon{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#edf2ff;color:#4e73df;font-size:1.1rem}.nd-box,.nd-section{border:1px solid #e3e6f0;border-radius:.5rem;padding:.75rem 1rem;background:#f8f9fc;height:100%}.nd-box span,.nd-section>span{display:block;text-transform:uppercase;font-size:.68rem;font-weight:700;color:#6e7891;margin-bottom:.25rem}.nd-box strong{display:block;color:#263754}.nd-box small{color:#6e7891}.nd-section{height:auto;background:#fff;margin-bottom:.65rem}.nd-section div{color:#344054}.nd-meta{font-size:.78rem;color:#6e7891}</style>
<div class="nd-head d-flex align-items-center"><div class="nd-icon mr-3"><i class="fas <?=$category==='Asistencia'?'fa-user-check':($category==='Alerta estudiantil'?'fa-exclamation-triangle':'fa-bell')?>"></i></div><div class="flex-grow-1"><h5 class="mb-1 text-gray-800"><?=nd_e($title)?></h5><div class="nd-meta"><?=nd_e($category)?> · <?=nd_date($createdAt)?></div></div><div class="text-right"><span class="badge <?=$priority==='Alta'?'badge-danger':'badge-primary'?>"><?=nd_e($priority)?></span><?php if($status):?><div class="mt-1"><span class="badge <?=$status==='Aprobada'?'badge-success':($status==='Rechazada'?'badge-danger':'badge-secondary')?>"><?=nd_e($status)?></span></div><?php endif;?></div></div>
<?=$content?>
<div class="d-flex justify-content-end mt-3"><button type="button" class="btn btn-light border" data-dismiss="modal">Cerrar</button></div>
