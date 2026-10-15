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

<?php
require_once __DIR__ . '/includes/notification_detail_ui.php';
edn_header('closure','Cierre de bimestre: '.$row['course_name'],
    $row['level'].' · '.$row['grado'].' '.$row['seccion'],
    $row['closed_at'],$row['status'],(int)$row['bimester'].'° bimestre');
?>
<div class="row mb-3">
  <div class="col-md-6 mb-2">
    <div class="card shadow-sm h-100"><div class="card-body py-3">
      <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Curso y aula</div>
      <div class="font-weight-bold text-gray-800"><?= edn_escape($row['course_name']) ?></div>
      <small class="text-muted"><?= edn_escape($row['level'].' · '.$row['grado'].' '.$row['seccion']) ?> · <?= edn_escape($row['year']) ?></small>
    </div></div>
  </div>
  <div class="col-md-6 mb-2">
    <div class="card shadow-sm h-100"><div class="card-body py-3">
      <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Docente responsable</div>
      <div class="font-weight-bold text-gray-800"><?= edn_escape($row['teacher_name']?:'Docente') ?></div>
      <small class="text-muted">Cerrado por <?= edn_escape($row['closed_by_name']?:'Docente') ?></small>
    </div></div>
  </div>
</div>
<div class="card shadow-sm mb-3">
  <div class="card-header py-3 d-flex justify-content-between align-items-center">
    <h6 class="m-0 font-weight-bold text-primary">Resumen del cierre</h6>
    <span class="badge badge-<?= $row['status']==='Cerrado'?'primary':'success' ?>"><?= edn_escape($row['status']) ?></span>
  </div>
  <div class="card-body">
    <div class="small text-muted mb-3">Cerrado: <strong><?= gcd_date($row['closed_at']) ?></strong> · Versión <?= (int)$row['closure_version'] ?></div>
    <div class="row text-center">
      <div class="col-4 mb-3"><div class="h5 mb-0 text-gray-800 font-weight-bold"><?= (int)($summary['competencies']??0) ?></div><small class="text-muted">Competencias</small></div>
      <div class="col-4 mb-3"><div class="h5 mb-0 text-gray-800 font-weight-bold"><?= (int)($summary['evaluations']??0) ?></div><small class="text-muted">Evaluaciones</small></div>
      <div class="col-4 mb-3"><div class="h5 mb-0 text-gray-800 font-weight-bold"><?= (int)($summary['students']??0) ?></div><small class="text-muted">Estudiantes</small></div>
    </div>
    <div class="progress mb-2" style="height:7px">
      <div class="progress-bar bg-primary" role="progressbar" style="width:<?= min(100,max(0,(float)($summary['progress']??0))) ?>%" aria-valuenow="<?= min(100,max(0,(float)($summary['progress']??0))) ?>" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
    <div class="d-flex justify-content-between small text-muted">
      <span><?= number_format((float)($summary['progress']??0),1) ?>% de avance</span>
      <span><?= number_format((float)($summary['percentage_total']??0),2) ?>% ponderación</span>
    </div>
    <?php if($row['reopened_at']): ?>
      <div class="alert alert-info mt-3 mb-0"><i class="fas fa-unlock mr-1"></i>Reabierto el <?= gcd_date($row['reopened_at']) ?> por <?= edn_escape($row['reopened_by_name']?:'Administración') ?></div>
    <?php endif; ?>
  </div>
</div>
<?php if(trim((string)($row['reopen_reason']??''))!==''): ?>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <h6 class="font-weight-bold text-gray-800">Motivo de la última reapertura</h6>
    <div class="small"><?= nl2br(edn_escape($row['reopen_reason'])) ?></div>
  </div></div>
<?php endif; ?>
<?php edn_footer(); ?>
