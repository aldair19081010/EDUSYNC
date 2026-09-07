<?php
include_once __DIR__.'/session_config.php';
include_once __DIR__.'/includes/session_check.php';
require_login_modal();
include __DIR__.'/db_connect.php';

$schoolId=(int)($_SESSION['login_school_id']??0);$teacherId=(int)($_SESSION['login_teacher_id']??0);$loginType=(int)($_SESSION['login_type']??0);$assignmentId=(int)($_GET['teacher_course_id']??0);
if($loginType!==2||$schoolId<=0||$teacherId<=0||$assignmentId<=0){http_response_code(403);echo '<div class="alert alert-danger mb-0">No tiene permisos para consultar esta aula.</div>';exit;}
$context=$conn->prepare("SELECT tc.id,tc.academic_year_id,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,ac.name course_name,ac.level,ay.year FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=tc.academic_year_id WHERE tc.id=? AND tc.teacher_id=? AND tc.school_id=? LIMIT 1");$context->bind_param('iii',$assignmentId,$teacherId,$schoolId);$context->execute();$course=$context->get_result()->fetch_assoc();$context->close();
if(!$course){http_response_code(404);echo '<div class="alert alert-warning mb-0">La asignación no existe o no te pertenece.</div>';exit;}
$stmt=$conn->prepare("SELECT st.id,st.id_no,st.name,st.status,
(SELECT COUNT(*) FROM evaluations e WHERE e.teacher_course_id=? AND e.academic_year_id=?) evaluation_count,
(SELECT COUNT(DISTINCT eg.evaluation_id) FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id WHERE e.teacher_course_id=? AND e.academic_year_id=? AND eg.student_id=st.id AND eg.grade<>'') graded_count,
(SELECT ROUND(AVG(CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(5,2)) END),2) FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id WHERE e.teacher_course_id=? AND e.academic_year_id=? AND eg.student_id=st.id) numeric_average,
(SELECT COUNT(*) FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id WHERE e.teacher_course_id=? AND e.academic_year_id=? AND eg.student_id=st.id AND eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(eg.grade AS DECIMAL(5,2))<=10) low_count
FROM student st WHERE st.school_id=? AND st.status='Activo' AND st.nivel=? AND st.grado=? AND COALESCE(NULLIF(st.seccion,''),'U')=? ORDER BY st.name");
$yearId=(int)$course['academic_year_id'];$stmt->bind_param('iiiiiiiiisss',$assignmentId,$yearId,$assignmentId,$yearId,$assignmentId,$yearId,$assignmentId,$yearId,$schoolId,$course['level'],$course['grado'],$course['seccion']);$stmt->execute();$result=$stmt->get_result();$students=[];while($row=$result->fetch_assoc())$students[]=$row;$stmt->close();
function mcs_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function mcs_grade($v){return rtrim(trim((string)$v),"°º ").'°';}
?>
<style>.mcs-head{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.4rem}.mcs-table td,.mcs-table th{vertical-align:middle}.mcs-progress{height:5px;min-width:80px}</style>
<div class="mcs-head p-3 mb-3"><div class="d-flex justify-content-between flex-wrap"><div><h5 class="mb-1 text-gray-800"><?php echo mcs_h($course['course_name']); ?></h5><span class="text-muted"><?php echo mcs_h($course['level'].' · '.mcs_grade($course['grado']).' '.$course['seccion']); ?></span></div><span class="badge badge-primary align-self-center mt-2 mt-sm-0"><?php echo mcs_h($course['year']); ?></span></div></div>
<div class="d-flex justify-content-between align-items-center mb-2"><strong><i class="fas fa-users text-primary mr-1"></i>Estudiantes activos</strong><span class="badge badge-light border"><?php echo count($students); ?> estudiantes</span></div>
<div class="table-responsive"><table class="table table-sm table-hover mcs-table mb-0"><thead class="thead-light"><tr><th>#</th><th>DNI</th><th>Estudiante</th><th>Notas registradas</th><th>Promedio numérico</th><th>Alertas</th></tr></thead><tbody>
<?php if(!$students): ?><tr><td colspan="6" class="text-center text-muted py-4">No hay estudiantes activos en esta aula.</td></tr><?php else:foreach($students as$i=>$student):$total=(int)$student['evaluation_count'];$graded=(int)$student['graded_count'];$progress=$total>0?min(100,round($graded/$total*100)):0; ?>
<tr><td><?php echo$i+1; ?></td><td><?php echo mcs_h($student['id_no']); ?></td><td class="font-weight-bold text-gray-800"><?php echo mcs_h($student['name']); ?></td><td><div class="d-flex justify-content-between small"><span><?php echo$graded; ?> de <?php echo$total; ?></span><span><?php echo$progress; ?>%</span></div><div class="progress mcs-progress"><div class="progress-bar" style="width:<?php echo$progress; ?>%"></div></div></td><td><?php echo$student['numeric_average']!==null?'<strong>'.number_format((float)$student['numeric_average'],2).'</strong>':'<span class="text-muted">Sin promedio</span>'; ?></td><td><?php echo(int)$student['low_count']>0?'<span class="badge badge-warning">'.(int)$student['low_count'].' nota(s) baja(s)</span>':'<span class="text-muted">Sin alertas</span>'; ?></td></tr>
<?php endforeach;endif; ?></tbody></table></div>
<div class="alert alert-light border mt-3 mb-0 small"><i class="fas fa-info-circle text-primary mr-1"></i>La lista considera a todos los estudiantes con estado <strong>Activo</strong> que pertenecen al mismo nivel, grado y sección.</div>
