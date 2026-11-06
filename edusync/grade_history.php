<?php
include_once __DIR__.'/session_config.php';
include_once __DIR__.'/includes/session_check.php';
require_login_modal();
include __DIR__.'/db_connect.php';
$schoolId=(int)($_SESSION['login_school_id']??0);$teacherId=(int)($_SESSION['login_teacher_id']??0);$type=(int)($_SESSION['login_type']??0);$evaluationId=(int)($_GET['evaluation_id']??0);$studentId=(int)($_GET['student_id']??0);
if($type!==2||$teacherId<=0||$schoolId<=0){echo '<div class="alert alert-danger mb-0">No tienes permisos para consultar este historial.</div>';exit;}
$stmt=$conn->prepare("SELECT e.title,s.name student_name,COALESCE(ac.evaluation_mode,'weighted') evaluation_mode,
 (SELECT gcc.name FROM evaluation_competencias ec INNER JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id WHERE ec.evaluation_id=e.id ORDER BY ec.id LIMIT 1) legacy_competency_name,
 (SELECT acomp.name FROM evaluation_area_competencies eac INNER JOIN area_competencies acomp ON acomp.id=eac.area_competency_id WHERE eac.evaluation_id=e.id LIMIT 1) area_competency_name
 FROM evaluations e INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN academic_courses ac ON ac.id=tc.course_id
 INNER JOIN student s ON s.id=? AND s.school_id=tc.school_id
 WHERE e.id=? AND e.teacher_id=? AND tc.teacher_id=? AND tc.school_id=? LIMIT 1");
$stmt->bind_param('iiiii',$studentId,$evaluationId,$teacherId,$teacherId,$schoolId);$stmt->execute();$context=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$context){echo '<div class="alert alert-danger mb-0">La nota no existe o no te pertenece.</div>';exit;}
$isCompetencyModel=($context['evaluation_mode']??'weighted')==='competency';
$historyTable=$isCompetencyModel?'evaluation_area_grade_history':'evaluation_grade_history';
$table=$conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($historyTable)."'");
if(!$table||!$table->num_rows){
 $migration=$isCompetencyModel?'sql/evaluation_dual_model_upgrade.sql':'sql/gradebook_history_upgrade.sql';
 echo '<div class="alert alert-warning mb-0">Ejecuta <strong>'.htmlspecialchars($migration).'</strong> para habilitar este historial.</div>';exit;
}
$context['competency_name']=$isCompetencyModel?($context['area_competency_name']??'') : ($context['legacy_competency_name']??'');
$history=[];
if($isCompetencyModel){
 $stmt=$conn->prepare('SELECT h.previous_grade,h.new_grade,h.source,h.created_at,u.name changed_by_name FROM evaluation_area_grade_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.school_id=? AND h.evaluation_id=? AND h.student_id=? ORDER BY h.created_at DESC,h.id DESC LIMIT 50');
}else{
 $stmt=$conn->prepare('SELECT h.previous_grade,h.new_grade,h.source,h.created_at,u.name changed_by_name FROM evaluation_grade_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.school_id=? AND h.evaluation_id=? AND h.student_id=? ORDER BY h.created_at DESC,h.id DESC LIMIT 50');
}
$stmt->bind_param('iii',$schoolId,$evaluationId,$studentId);$stmt->execute();$result=$stmt->get_result();while($row=$result->fetch_assoc())$history[]=$row;$stmt->close();
?>
<div class="mb-3"><strong><?php echo htmlspecialchars($context['student_name']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($context['title'].' · '.($context['competency_name']?:'Sin competencia')); ?></small></div>
<?php if(!$history): ?><div class="text-center text-muted py-4"><i class="fas fa-history fa-2x d-block mb-2"></i>Esta nota todavía no tiene modificaciones registradas.</div><?php else: ?><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead class="thead-light"><tr><th>Fecha</th><th>Valor anterior</th><th>Nuevo valor</th><th>Modificado por</th><th>Origen</th></tr></thead><tbody><?php foreach($history as$row): ?><tr><td><?php echo date('d/m/Y H:i',strtotime($row['created_at'])); ?></td><td class="text-center font-weight-bold"><?php echo $row['previous_grade']===''?'Vacío':htmlspecialchars($row['previous_grade']); ?></td><td class="text-center font-weight-bold text-primary"><?php echo $row['new_grade']===''?'Vacío':htmlspecialchars($row['new_grade']); ?></td><td><?php echo htmlspecialchars($row['changed_by_name']?:'Usuario del sistema'); ?></td><td><?php echo htmlspecialchars($row['source']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
