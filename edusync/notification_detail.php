<?php
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone='-05:00'");

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
    $stmt=$conn->prepare("SELECT lgn.*,DATE_SUB(lgn.created_at, INTERVAL 5 HOUR) created_at_peru,s.name student_name,s.id_no,s.nivel,s.grado,s.seccion FROM low_grade_notifications lgn INNER JOIN student s ON s.id=lgn.student_id WHERE lgn.id=? AND s.school_id=? AND (?=1 OR lgn.teacher_id=?) LIMIT 1");$approverFlag=$isApprover?1:0;$stmt->bind_param('iiii',$id,$school,$approverFlag,$teacherId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row){echo '<div class="alert alert-warning">La alerta no existe o no corresponde a su colegio y rol.</div>';return;}
    $title='Alerta de notas bajas';$category='Alerta estudiantil';$priority='Alta';$createdAt=$row['created_at_peru'];$status='Requiere seguimiento';$courses=json_decode($row['failed_courses'],true)?:[];
    ob_start(); ?>
    <div class="row mb-3">
      <div class="col-md-6 mb-2"><div class="card shadow-sm h-100"><div class="card-body py-3">
        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Estudiante</div>
        <div class="font-weight-bold text-gray-800"><?=nd_e($row['student_name'])?></div>
        <small class="text-muted"><?=nd_e($row['id_no'])?></small>
      </div></div></div>
      <div class="col-md-6 mb-2"><div class="card shadow-sm h-100"><div class="card-body py-3">
        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Aula y periodo</div>
        <div class="font-weight-bold text-gray-800"><?=nd_e($row['nivel'].' · '.$row['grado'].' '.$row['seccion'])?></div>
        <small class="text-muted"><?=nd_e($row['bimestre'])?>° bimestre</small>
      </div></div></div>
    </div>
    <div class="alert alert-danger mb-3">
      <strong class="h5 font-weight-bold mr-1"><?=(int)$row['count_low_grades']?></strong>
      calificaciones desaprobatorias detectadas. Este caso requiere seguimiento.
    </div>
    <?php if($courses): ?>
      <div class="card shadow-sm mb-3"><div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-danger"><i class="fas fa-book-open mr-2"></i>Cursos involucrados</h6>
      </div><div class="list-group list-group-flush">
        <?php foreach($courses as $course):
          $courseName=is_array($course)?($course['name']??$course['course']??'Curso'):$course;
          $failedCount=is_array($course)?(int)($course['count']??0):0; ?>
          <div class="list-group-item d-flex justify-content-between align-items-center">
            <strong class="text-gray-800"><?=nd_e($courseName)?></strong>
            <?php if($failedCount>0):?><span class="badge badge-danger ml-2"><?=$failedCount?> bajas</span><?php endif;?>
          </div>
        <?php endforeach; ?>
      </div></div>
    <?php endif; ?>
    <?php $content=ob_get_clean();
} elseif ($type === 'grade_changes') {
    if (!$isApprover) {
        echo '<div class="alert alert-warning">Solo Administración puede consultar este historial.</div>';
        return;
    }
    require_once __DIR__ . '/includes/grade_change_notification_detail.php';
    $detail=edun_grade_change_detail($conn,$school,$id);
    if (!$detail) {
        echo '<div class="alert alert-warning">No se encontró el detalle de este cambio para su colegio.</div>';
        return;
    }
    $title=$detail['title'];$category=$detail['category'];
    $priority=$detail['priority'];$message=$detail['message'];
    $createdAt=$detail['created_at'];$content=$detail['content'];
} elseif ($type !== 'evaluation' && $type !== 'attendance_request') {
    $roleName=$isApprover?'Administrador':((int)$roleData['type']===3?'Auxiliar':((int)$roleData['type']===2?'Docente':'Usuario'));
    $stmt=$conn->prepare("SELECT * FROM notification_events WHERE id=? AND school_id=? AND (recipient_user_id=? OR (recipient_user_id IS NULL AND recipient_role=?)) LIMIT 1");$stmt->bind_param('iiis',$id,$school,$user,$roleName);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row){echo '<div class="alert alert-warning">La notificación no existe o no está dirigida a su usuario y rol.</div>';return;}
    $title=$row['title'];$category=$row['notification_type'];$priority=$row['priority'];$message=$row['message'];$createdAt=$row['created_at'];
    if ($row['source_type']==='teacher_announcement') {
        // Ya se verificó que el evento pertenece al usuario y colegio.
        // El contenido completo no se replica en notification_events.message (500 chars).
        $stmt=$conn->prepare("SELECT a.content,COALESCE(u.name,'Administración') author
            FROM teacher_announcements a
            INNER JOIN teacher_announcement_recipients ar ON ar.announcement_id=a.id
                AND ar.school_id=a.school_id AND ar.notification_event_id=?
                AND ar.recipient_user_id=?
            LEFT JOIN users u ON u.id=a.created_by AND u.school_id=a.school_id
            WHERE a.id=? AND a.school_id=? LIMIT 1");
        if(!$stmt){echo '<div class="alert alert-warning">El contenido de este comunicado no está disponible.</div>';return;}
        $announcementId=(int)$row['source_id'];
        $stmt->bind_param('iiii',$id,$user,$announcementId,$school);
        $stmt->execute();$full=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$full){echo '<div class="alert alert-warning">No se encontró el comunicado para este usuario.</div>';return;}
        $content='<div class="card shadow-sm mb-3"><div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-bullhorn mr-2"></i>Comunicado institucional</h6></div><div class="card-body"><div class="small text-muted mb-3">Emitido por '.nd_e($full['author']).'</div><div style="white-space:pre-wrap;overflow-wrap:anywhere">'.nd_e($full['content']).'</div></div></div>';
    } else {
        $content='<div class="card shadow-sm mb-3"><div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Detalle del aviso</h6></div><div class="card-body">'.nl2br(nd_e($message?:'Sin información adicional.')).'</div></div>';
    }
} else { echo '<div class="alert alert-warning">Utilice la vista especializada de esta notificación.</div>'; return; }
?>
<?php
require_once __DIR__ . '/includes/notification_detail_ui.php';
$kind='general';
if ($type==='grade_changes') $kind='grade_changes';
elseif ($type==='low_grade') $kind='low_grade';
elseif (strpos($type,'attendance')===0 || $category==='Asistencia') $kind='attendance';
elseif ($type==='grade_period_closed') $kind='closure';
elseif (strpos($type,'grade_reopen')===0) $kind='reopen';
elseif ($status==='Aprobada') $kind='success';
elseif ($status==='Rechazada') $kind='rejected';
$subtitle = '';
if ($type==='grade_changes') $subtitle='Registro de modificaciones de calificaciones realizado por un docente.';
elseif ($type==='low_grade') $subtitle='Caso detectado por el seguimiento académico de EduSync.';
elseif ($type==='attendance_result') $subtitle='Resultado de la solicitud de cambio de asistencia.';
else $subtitle=(string)$message;
$badge=$status!==''?$status:$category;
edn_header($kind,$title,$subtitle,$createdAt,$badge,$priority==='Alta'?'Prioridad alta':'');
?>
<div class="edn-detail-body"><?=$content?></div>
<?php edn_footer(); ?>
