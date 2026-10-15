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
<div class="edn-grid">
  <div class="edn-card"><span class="edn-card-label">Curso y aula</span>
    <strong class="edn-card-value"><?= edn_escape($row['course_name']) ?></strong>
    <span class="edn-card-help"><?= edn_escape($row['level'].' · '.$row['grado'].' '.$row['seccion']) ?> · <?= edn_escape($row['year']) ?></span></div>
  <div class="edn-card"><span class="edn-card-label">Docente</span>
    <strong class="edn-card-value"><?= edn_escape($row['teacher_name']?:'Docente') ?></strong>
    <span class="edn-card-help">Cierre realizado por <?= edn_escape($row['closed_by_name']?:'Docente') ?></span></div>
  <div class="edn-card"><span class="edn-card-label">Fecha de cierre</span>
    <strong class="edn-card-value"><?= gcd_date($row['closed_at']) ?></strong>
    <span class="edn-card-help">Versión de cierre <?= (int)$row['closure_version'] ?></span></div>
  <div class="edn-card edn-card--tint"><span class="edn-card-label">Estado actual</span>
    <strong class="edn-card-value"><?= edn_escape($row['status']) ?></strong>
    <?php if($row['reopened_at']): ?>
    <span class="edn-card-help">Reabierto el <?= gcd_date($row['reopened_at']) ?> por <?= edn_escape($row['reopened_by_name']?:'Administración') ?></span>
    <?php endif; ?></div>
</div>
<section class="edn-section">
 <h6 class="edn-section-title"><i class="fas fa-chart-bar"></i>Resumen del bimestre</h6>
 <div class="edn-mini-kpi">
   <span class="edn-mini-kpi-item"><strong><?= (int)($summary['competencies']??0) ?></strong> competencias</span>
   <span class="edn-mini-kpi-item"><strong><?= (int)($summary['evaluations']??0) ?></strong> evaluaciones</span>
   <span class="edn-mini-kpi-item"><strong><?= (int)($summary['students']??0) ?></strong> estudiantes</span>
   <span class="edn-mini-kpi-item"><strong><?= number_format((float)($summary['percentage_total']??0),2) ?>%</strong> ponderación</span>
   <span class="edn-mini-kpi-item"><strong><?= number_format((float)($summary['progress']??0),1) ?>%</strong> completo</span>
 </div>
</section>
<?php if(trim((string)($row['reopen_reason']??''))!==''): ?>
<section class="edn-section"><h6 class="edn-section-title"><i class="fas fa-comment-alt"></i>Motivo de la última reapertura</h6>
 <div><?= nl2br(edn_escape($row['reopen_reason'])) ?></div></section>
<?php endif; ?>
<?php edn_footer(); ?>
