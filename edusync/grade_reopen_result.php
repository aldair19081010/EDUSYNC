<?php
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
$schoolId=(int)($_SESSION['login_school_id']??0);$userId=(int)($_SESSION['login_id']??0);$teacherId=(int)($_SESSION['login_teacher_id']??0);$type=(int)($_SESSION['login_type']??0);$id=(int)($_GET['id']??0);
$stmt=$conn->prepare("SELECT grr.*,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,ac.name course_name,ac.level,ay.year,t.name teacher_name,rv.name reviewed_by_name FROM grade_reopen_requests grr INNER JOIN teacher_courses tc ON tc.id=grr.teacher_course_id AND tc.school_id=grr.school_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=grr.academic_year_id LEFT JOIN teacher t ON t.id=grr.teacher_id LEFT JOIN users rv ON rv.id=grr.reviewed_by AND rv.school_id=grr.school_id WHERE grr.id=? AND grr.school_id=? LIMIT 1");
$stmt->bind_param('ii',$id,$schoolId);$stmt->execute();$request=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$request){echo '<div class="alert alert-warning">No se encontró la solicitud.</div>';return;}
$allowed=$type===1||($type===2&&$teacherId>0&&(int)$request['teacher_id']===$teacherId&&(int)$request['requested_by']===$userId);
if(!$allowed){echo '<div class="alert alert-danger">No tienes permiso para ver esta resolución.</div>';return;}
function gr_result_date($value){if(!$value)return '—';$ts=strtotime($value);return $ts?date('d/m/Y h:i a',$ts):htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
$status=$request['status'];$badge=$status==='Aprobada'?'success':($status==='Rechazada'?'danger':'warning');
?>
<div class="mb-3"><span class="badge badge-<?php echo $badge; ?> px-2 py-1"><?php echo htmlspecialchars($status); ?></span></div>
<div class="card border-left-<?php echo $badge; ?> shadow-sm mb-3"><div class="card-body"><h6 class="font-weight-bold mb-2"><?php echo htmlspecialchars($request['course_name'].' · '.$request['grado'].' '.$request['seccion']); ?></h6><div class="small text-muted"><?php echo htmlspecialchars($request['level'].' · '.$request['year'].' · '.$request['bimester'].'° Bimestre'); ?></div></div></div>
<div class="row"><div class="col-md-6 mb-3"><div class="border rounded p-3 h-100"><small class="text-muted font-weight-bold text-uppercase">Motivo solicitado</small><div class="mt-1"><?php echo nl2br(htmlspecialchars($request['reason'],ENT_QUOTES,'UTF-8')); ?></div></div></div><div class="col-md-6 mb-3"><div class="border rounded p-3 h-100"><small class="text-muted font-weight-bold text-uppercase">Resolución</small><div class="mt-1">Revisada por <strong><?php echo htmlspecialchars($request['reviewed_by_name']?:'Administración'); ?></strong><br><?php echo gr_result_date($request['reviewed_at']); ?></div></div></div></div>
<?php if(trim((string)$request['review_notes'])!==''): ?><div class="alert alert-light border mb-0"><strong>Observación:</strong><br><?php echo nl2br(htmlspecialchars($request['review_notes'],ENT_QUOTES,'UTF-8')); ?></div><?php endif; ?>
<?php if($status==='Aprobada'): ?><div class="alert alert-success mt-3 mb-0"><i class="fas fa-unlock mr-1"></i>El bimestre fue reabierto y ya puede volver a editarse hasta que el docente lo cierre nuevamente.</div><?php elseif($status==='Rechazada'): ?><div class="alert alert-danger mt-3 mb-0"><i class="fas fa-lock mr-1"></i>El bimestre continúa cerrado.</div><?php endif; ?>
