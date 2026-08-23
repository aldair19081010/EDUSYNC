<?php
include_once 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();

$teacher_id = intval($_GET['id'] ?? 0);
$school_id = intval($_SESSION['login_school_id'] ?? 0);
if ($teacher_id <= 0 || $school_id <= 0) { echo '<div class="alert alert-danger">Datos del docente inválidos.</div>'; exit; }

$stmt = $conn->prepare('SELECT * FROM teacher WHERE id = ? AND school_id = ? LIMIT 1');
$stmt->bind_param('ii', $teacher_id, $school_id); $stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$teacher) { echo '<div class="alert alert-warning">Docente no encontrado.</div>'; exit; }

$school = ['name' => 'Institución Educativa', 'logo_path' => ''];
$stmt = $conn->prepare('SELECT name, logo_path FROM schools WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $school_id); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc(); $stmt->close();
if ($row) $school = $row;

$stmt = $conn->prepare('SELECT username, is_director FROM users WHERE teacher_id = ? AND school_id = ? AND type = 2 LIMIT 1');
$stmt->bind_param('ii', $teacher_id, $school_id); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

$stmt = $conn->prepare('SELECT id, year, description FROM academic_year WHERE school_id = ? AND is_active = 1 LIMIT 1');
$stmt->bind_param('i', $school_id); $stmt->execute();
$active_year = $stmt->get_result()->fetch_assoc(); $stmt->close();

$courses = [];
if ($active_year) {
    $stmt = $conn->prepare('SELECT tc.grado, tc.seccion, tc.level, COALESCE(ac.name, "Curso no disponible") AS course_name FROM teacher_courses tc LEFT JOIN academic_courses ac ON ac.id = tc.course_id WHERE tc.teacher_id = ? AND tc.school_id = ? AND tc.academic_year_id = ? ORDER BY tc.level, tc.grado, tc.seccion, ac.name');
    $stmt->bind_param('iii', $teacher_id, $school_id, $active_year['id']); $stmt->execute();
    $result = $stmt->get_result(); while ($row = $result->fetch_assoc()) $courses[] = $row; $stmt->close();
}

$history = []; $history_table_exists = false;
$check = $conn->query("SHOW TABLES LIKE 'teacher_employment_history'");
if ($check && $check->num_rows > 0) {
    $history_table_exists = true;
    $stmt = $conn->prepare('SELECT h.*, ay.year AS academic_year, COALESCE(u.name, "Sistema") AS created_by_name FROM teacher_employment_history h LEFT JOIN academic_year ay ON ay.id = h.academic_year_id AND ay.school_id = h.school_id LEFT JOIN users u ON u.id = h.created_by WHERE h.teacher_id = ? AND h.school_id = ? ORDER BY h.start_date DESC, h.id DESC');
    $stmt->bind_param('ii', $teacher_id, $school_id); $stmt->execute();
    $result = $stmt->get_result(); while ($row = $result->fetch_assoc()) $history[] = $row; $stmt->close();
}
$audit = [];
$audit_check = $conn->query("SHOW TABLES LIKE 'teacher_audit_log'");
if ($audit_check && $audit_check->num_rows > 0) {
    $stmt = $conn->prepare('SELECT a.action, a.details, a.ip_address, a.created_at, COALESCE(u.name, "Sistema") AS user_name FROM teacher_audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.teacher_id = ? AND a.school_id = ? ORDER BY a.created_at DESC, a.id DESC LIMIT 30');
    $stmt->bind_param('ii', $teacher_id, $school_id); $stmt->execute();
    $result = $stmt->get_result(); while ($row = $result->fetch_assoc()) $audit[] = $row; $stmt->close();
}

function tv($value, $fallback = 'N/A') { return htmlspecialchars(trim((string)$value) !== '' ? $value : $fallback, ENT_QUOTES, 'UTF-8'); }
function td_date($date, $empty = 'Actualidad') { return $date ? date('d/m/Y', strtotime($date)) : $empty; }
function audit_label($action) { $labels=['teacher_created'=>'Docente creado','teacher_updated'=>'Información actualizada','teacher_deactivated'=>'Docente desactivado','teacher_rehired'=>'Docente recontratado','user_created'=>'Usuario creado','user_updated'=>'Usuario actualizado','courses_assigned'=>'Cursos asignados','course_assignment_updated'=>'Asignación actualizada','course_unassigned'=>'Curso desasignado']; return $labels[$action] ?? $action; }
$access_active = $user && ($teacher['status'] ?? 'Activo') === 'Activo';
$year_label = $active_year ? $active_year['year'] . (!empty($active_year['description']) ? ' - '.$active_year['description'] : '') : 'Sin año activo';
$teacher_age = null;
if (!empty($teacher['birth_date'])) {
    $teacher_age = (new DateTime($teacher['birth_date']))->diff(new DateTime('today'))->y;
}
?>
<style>
.teacher-profile{padding:10px 5px}.teacher-print-header{display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #e9ecef;padding-bottom:10px;margin-bottom:12px}.teacher-print-left,.teacher-header{display:flex;align-items:center}.teacher-school-logo,.teacher-avatar{display:flex;align-items:center;justify-content:center;background:#f1f3f5;color:#6c757d}.teacher-school-logo{width:64px;height:64px;border-radius:6px;margin-right:12px}.teacher-school-logo img{max-width:100%;max-height:100%}.teacher-print-header h4{margin:0;color:#2c4964;font-weight:700}.teacher-header{margin-bottom:12px}.teacher-avatar{width:70px;height:70px;border-radius:50%;font-size:28px;margin-right:12px}.teacher-name{font-size:1.1rem;font-weight:700;color:#2c4964}.teacher-badge{padding:4px 8px;border-radius:12px;font-size:12px;font-weight:600;margin-right:3px}.info-grid,.summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.info-item{background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;padding:10px}.info-item small{color:#6c757d;font-weight:600;display:block;margin-bottom:4px}.summary-grid{margin-top:12px}.summary-panel,.history-panel{border:1px solid #e9ecef;border-radius:6px;padding:10px;background:#fff}.summary-title{font-weight:700;color:#2c4964;border-bottom:1px solid #e9ecef;padding-bottom:7px;margin-bottom:8px}.summary-title i{margin-right:6px}.summary-number{font-size:1.35rem;font-weight:700;color:#2c4964}.course-list{margin:5px 0 0;padding-left:18px;max-height:150px;overflow:auto}.history-panel{margin-top:12px}.history-table{width:100%;font-size:12px}.history-table th{color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;padding:6px;white-space:nowrap}.history-table td{border-bottom:1px solid #f1f3f5;padding:6px;vertical-align:middle}.teacher-footer{margin-top:14px;border-top:1px dashed #dee2e6;padding-top:8px;color:#6c757d;font-size:12px;display:flex;justify-content:space-between}@media(max-width:576px){.info-grid,.summary-grid{grid-template-columns:1fr}}@media print{body *{visibility:hidden}#teacher-printable,#teacher-printable *{visibility:visible}#teacher-printable{position:absolute;left:0;top:0;width:100%}#btn_print_teacher{display:none!important}.summary-panel,.history-panel{break-inside:avoid}}
</style>
<div class="teacher-profile" id="teacher-printable">
 <div class="teacher-print-header"><div class="teacher-print-left"><div class="teacher-school-logo"><?php if (!empty($school['logo_path']) && file_exists($school['logo_path'])): ?><img src="<?php echo tv($school['logo_path']); ?>" alt="Logo"><?php else: ?><i class="fa fa-school" style="font-size:28px"></i><?php endif; ?></div><div><h4><?php echo tv($school['name']); ?></h4><small>Ficha del Docente · Fecha: <?php echo date('d/m/Y H:i'); ?></small></div></div><button type="button" id="btn_print_teacher" class="btn btn-sm btn-light" style="border:1px solid #e9ecef"><i class="fa fa-print mr-1"></i>Imprimir</button></div>
 <div class="teacher-header"><div class="teacher-avatar"><i class="fa fa-chalkboard-teacher"></i></div><div><div class="teacher-name"><?php echo tv($teacher['name']); ?></div><span class="badge teacher-badge <?php echo $teacher['status']==='Activo'?'badge-success':'badge-secondary'; ?>">Estado: <?php echo tv($teacher['status'],'Activo'); ?></span><span class="badge teacher-badge badge-info">Año: <?php echo tv($year_label); ?></span><span class="badge teacher-badge <?php echo !$user?'badge-warning':($access_active?'badge-success':'badge-secondary'); ?>"><?php echo !$user?'Sin usuario':($access_active?'Acceso activo':'Acceso inactivo'); ?></span></div></div>
 <div class="info-grid">
  <div class="info-item"><small>DNI</small><div><?php echo tv($teacher['id_no']); ?></div></div><div class="info-item"><small>Fecha de nacimiento</small><div><?php echo !empty($teacher['birth_date']) ? td_date($teacher['birth_date'], 'N/A') . ($teacher_age !== null ? ' · ' . $teacher_age . ' años' : '') : 'N/A'; ?></div></div>
  <div class="info-item"><small>Especialidad</small><div><?php echo tv($teacher['specialty']); ?></div></div>
  <div class="info-item"><small>Correo</small><div><?php echo tv($teacher['email']); ?></div></div><div class="info-item"><small>Teléfono</small><div><?php echo tv($teacher['contact']); ?></div></div>
  <div class="info-item"><small>Dirección</small><div><?php echo tv($teacher['address']); ?></div></div><div class="info-item"><small>Cuenta de acceso</small><div><?php echo $user ? tv($user['username']).((int)$user['is_director']===1?' · Director':' · Docente') : 'No tiene usuario creado'; ?></div></div>
 </div>
 <div class="summary-grid">
  <div class="summary-panel"><div class="summary-title"><i class="fa fa-book text-primary"></i>Cursos del año activo</div><div class="summary-number"><?php echo count($courses); ?> <small class="text-muted">asignación(es)</small></div><?php if ($courses): ?><ul class="course-list"><?php foreach($courses as $course): ?><li><?php echo tv($course['course_name']); ?> · <?php echo tv($course['level']); ?> · <?php echo tv($course['grado']); ?><?php echo !empty($course['seccion'])?' “'.tv($course['seccion']).'”':''; ?></li><?php endforeach; ?></ul><?php else: ?><div class="text-muted small mt-2">No tiene cursos asignados en <?php echo tv($year_label); ?>.</div><?php endif; ?></div>
  <div class="summary-panel"><div class="summary-title"><i class="fa fa-briefcase text-success"></i>Resumen laboral</div><div class="summary-number"><?php echo count($history); ?> <small class="text-muted">periodo(s)</small></div><div class="small text-muted mt-2">Ingreso vigente: <?php echo !empty($history)&&$history[0]['status']==='Activo'?td_date($history[0]['start_date']):'Sin periodo activo'; ?></div></div>
 </div>
 <div class="history-panel"><div class="summary-title"><i class="fa fa-history text-secondary"></i>Historial laboral</div>
 <?php if(!$history_table_exists): ?><div class="alert alert-warning mb-0">Falta instalar <code>sql/teacher_employment_history.sql</code>.</div><?php elseif(!$history): ?><div class="text-muted small">Todavía no existen periodos laborales registrados para este docente.</div><?php else: ?><div class="table-responsive"><table class="history-table"><thead><tr><th>Registro</th><th>Año</th><th>Ingreso</th><th>Salida</th><th>Estado</th><th>Motivo</th><th>Observaciones</th><th>Usuario</th></tr></thead><tbody><?php foreach($history as $period): ?><tr><td><?php echo td_date($period['created_at'],'-'); ?></td><td><?php echo tv($period['academic_year']); ?></td><td><?php echo td_date($period['start_date'],'-'); ?></td><td><?php echo td_date($period['end_date']); ?></td><td><span class="badge <?php echo $period['status']==='Activo'?'badge-success':'badge-secondary'; ?>"><?php echo tv($period['status']); ?></span></td><td><?php echo tv($period['departure_reason'] ?? '', '-'); ?></td><td><?php echo tv($period['notes'] ?? '', '-'); ?></td><td><?php echo tv($period['created_by_name']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
 </div>
 <?php if($audit): ?><div class="history-panel"><div class="summary-title"><i class="fa fa-shield-alt text-primary"></i>Auditoría reciente</div><div class="table-responsive"><table class="history-table"><thead><tr><th>Fecha</th><th>Acción</th><th>Usuario</th><th>IP</th></tr></thead><tbody><?php foreach($audit as $entry): ?><tr><td><?php echo date('d/m/Y H:i',strtotime($entry['created_at'])); ?></td><td><?php echo tv(audit_label($entry['action'])); ?></td><td><?php echo tv($entry['user_name']); ?></td><td><?php echo tv($entry['ip_address'],'-'); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
 <div class="teacher-footer"><span>Generado por el sistema</span><span><?php echo tv($teacher['name']); ?> · DNI: <?php echo tv($teacher['id_no']); ?></span></div>
</div>
<script>(function(){try{$('#uni_modal').find('.modal-footer,.card-footer').hide()}catch(e){}var b=document.getElementById('btn_print_teacher');if(b)b.addEventListener('click',function(){window.print()})})();</script>
