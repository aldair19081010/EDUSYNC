<?php
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
$schoolId=(int)($_SESSION['login_school_id']??0);$userId=(int)($_SESSION['login_id']??0);$id=(int)($_GET['id']??0);
$role=$conn->prepare('SELECT type FROM users WHERE id=? AND school_id=? LIMIT 1');$role->bind_param('ii',$userId,$schoolId);$role->execute();$roleData=$role->get_result()->fetch_assoc();$role->close();
if(!$roleData||(int)$roleData['type']!==1){echo '<div class="alert alert-danger">Solo Administración puede ver este detalle.</div>';return;}
$stmt=$conn->prepare("SELECT gpc.*,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,ac.name course_name,ac.level,ay.year,t.name teacher_name,u.name closed_by_name,ru.name reopened_by_name FROM grade_period_closures gpc INNER JOIN teacher_courses tc ON tc.id=gpc.teacher_course_id AND tc.school_id=gpc.school_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=gpc.academic_year_id LEFT JOIN teacher t ON t.id=gpc.teacher_id LEFT JOIN users u ON u.id=gpc.closed_by AND u.school_id=gpc.school_id LEFT JOIN users ru ON ru.id=gpc.reopened_by AND ru.school_id=gpc.school_id WHERE gpc.id=? AND gpc.school_id=? LIMIT 1");
$stmt->bind_param('ii',$id,$schoolId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$row){echo '<div class="alert alert-warning">No se encontró el cierre.</div>';return;}
$snapshot=json_decode((string)$row['closure_snapshot'],true)?:[];$summary=$snapshot['summary']??[];
function gcd_date($value){if(!$value)return '—';$ts=strtotime($value);return $ts?date('d/m/Y h:i a',$ts):htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
?>
<style>.gcd-box{border:1px solid #e3e6f0;border-radius:.5rem;padding:.8rem 1rem;background:#f8f9fc;height:100%}.gcd-label{font-size:.7rem;text-transform:uppercase;font-weight:800;color:#6c757d}.gcd-value{font-weight:700;color:#344054}.gcd-pill{display:inline-block;border:1px solid #e3e6f0;border-radius:1rem;padding:.2rem .6rem;margin:.15rem;background:#fff;font-size:.78rem}</style>
<div class="mb-3"><span class="badge badge-<?php echo $row['status']==='Cerrado'?'info':'success'; ?> px-2 py-1"><?php echo htmlspecialchars($row['status']); ?></span><span class="badge badge-light border ml-1"><?php echo (int)$row['bimester']; ?>° Bimestre</span></div>
<div class="gcd-box mb-3"><div class="gcd-label">Curso y aula</div><div class="gcd-value"><?php echo htmlspecialchars($row['course_name'].' · '.$row['level'].' · '.$row['grado'].' '.$row['seccion']); ?></div><div class="small text-muted">Año académico <?php echo htmlspecialchars($row['year']); ?> · Docente: <?php echo htmlspecialchars($row['teacher_name']?:'—'); ?></div></div>
<div class="row mb-3"><div class="col-md-6 mb-2"><div class="gcd-box"><div class="gcd-label">Cierre</div><div class="gcd-value"><?php echo gcd_date($row['closed_at']); ?></div><small>Por <?php echo htmlspecialchars($row['closed_by_name']?:'Docente'); ?> · versión <?php echo (int)$row['closure_version']; ?></small></div></div><div class="col-md-6 mb-2"><div class="gcd-box"><div class="gcd-label">Estado actual</div><div class="gcd-value"><?php echo htmlspecialchars($row['status']); ?></div><?php if($row['reopened_at']): ?><small>Reabierto <?php echo gcd_date($row['reopened_at']); ?> por <?php echo htmlspecialchars($row['reopened_by_name']?:'Administración'); ?></small><?php endif; ?></div></div></div>
<div class="mb-3"><span class="gcd-pill"><?php echo (int)($summary['competencies']??0); ?> competencias</span><span class="gcd-pill"><?php echo (int)($summary['evaluations']??0); ?> evaluaciones</span><span class="gcd-pill"><?php echo (int)($summary['students']??0); ?> estudiantes</span><span class="gcd-pill"><?php echo number_format((float)($summary['percentage_total']??0),2); ?>% ponderación</span><span class="gcd-pill"><?php echo number_format((float)($summary['progress']??0),1); ?>% completo</span></div>
<?php if($row['reopen_reason']): ?><div class="alert alert-light border mb-0"><strong>Motivo de la última reapertura:</strong><br><?php echo nl2br(htmlspecialchars($row['reopen_reason'],ENT_QUOTES,'UTF-8')); ?></div><?php endif; ?>
