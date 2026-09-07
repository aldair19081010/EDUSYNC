<?php
include_once 'includes/session_check.php';
include 'db_connect.php';
if(empty($_SESSION['login_id'])||(int)($_SESSION['login_type']??0)!==1)die('No autorizado');
$school=(int)($_SESSION['login_school_id']??0);$a=(int)($_GET['year_a']??0);$b=(int)($_GET['year_b']??0);
function ey_count($db,$table,$column,$year){$t=$db->real_escape_string($table);$c=$db->real_escape_string($column);$exists=$db->query("SHOW TABLES LIKE '$t'");if(!$exists||!$exists->num_rows)return 0;$col=$db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");if(!$col||!$col->num_rows)return 0;$q=$db->query("SELECT COUNT(*) total FROM `$t` WHERE `$c`=".(int)$year);return $q?(int)$q->fetch_assoc()['total']:0;}
$s=$conn->prepare('SELECT id,year FROM academic_year WHERE school_id=? AND id IN (?,?) ORDER BY id');$s->bind_param('iii',$school,$a,$b);$s->execute();$years=[];$r=$s->get_result();while($x=$r->fetch_assoc())$years[(int)$x['id']]=$x['year'];$s->close();if(count($years)!==2)die('Años inválidos');
$indicators=['Áreas'=>['areas','academic_year_id'],'Cursos académicos'=>['academic_courses','academic_year_id'],'Asignaciones docentes'=>['teacher_courses','academic_year_id'],'Competencias'=>['general_course_competencies','academic_year_id'],'Evaluaciones'=>['evaluations','academic_year_id'],'Estudiantes'=>['student','academic_year_id'],'Periodos'=>['academic_periods','academic_year_id']];
header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="comparacion_anios_'.$years[$a].'_'.$years[$b].'.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');fputcsv($out,['Indicador',$years[$a],$years[$b]],';');foreach($indicators as $label=>$cfg)fputcsv($out,[$label,ey_count($conn,$cfg[0],$cfg[1],$a),ey_count($conn,$cfg[0],$cfg[1],$b)],';');fclose($out);exit;
