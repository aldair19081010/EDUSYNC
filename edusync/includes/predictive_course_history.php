<?php
require_once __DIR__.'/predictive_course_risk.php';

/** Vincula registros históricos del mismo alumno por DNI dentro del mismo colegio. */
function edu_course_risk_linked_student_ids(mysqli $conn,int $schoolId,int $studentId): array {
    $ids=[$studentId];$stmt=$conn->prepare('SELECT id_no,name FROM student WHERE id=? AND school_id=? LIMIT 1');if(!$stmt)return$ids;$stmt->bind_param('ii',$studentId,$schoolId);$stmt->execute();$s=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$s)return$ids;$dni=trim((string)($s['id_no']??''));$name=trim((string)($s['name']??''));
    if($dni!==''){$stmt=$conn->prepare('SELECT id FROM student WHERE school_id=? AND id_no=?');if($stmt){$stmt->bind_param('is',$schoolId,$dni);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc())$ids[]=(int)$r['id'];$stmt->close();}}
    elseif($name!==''){$stmt=$conn->prepare("SELECT id FROM student WHERE school_id=? AND name=? AND (id_no IS NULL OR TRIM(id_no)='')");if($stmt){$stmt->bind_param('is',$schoolId,$name);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc())$ids[]=(int)$r['id'];$stmt->close();}}
    return array_values(array_unique(array_filter(array_map('intval',$ids))));
}
function edu_course_risk_history_linked(mysqli $conn,int $studentId,int $schoolId,string $courseName,int $maxYears=5): array {
    $linked=edu_course_risk_linked_student_ids($conn,$schoolId,$studentId);$stmt=$conn->prepare('SELECT id,year,start_date,end_date FROM academic_year WHERE school_id=? ORDER BY start_date DESC,id DESC LIMIT ?');if(!$stmt)return[];$stmt->bind_param('ii',$schoolId,$maxYears);$stmt->execute();$res=$stmt->get_result();$years=[];while($r=$res->fetch_assoc())$years[]=$r;$stmt->close();$needle=edu_course_risk_norm($courseName);$out=[];
    foreach(array_reverse($years) as $year){$yearId=(int)$year['id'];$periods=[];for($b=1;$b<=4;$b++){$found=null;foreach($linked as $sid){foreach(edu_course_risk_contexts_from_grades($conn,$sid,$schoolId,$yearId,$b) as $ctx){if(edu_course_risk_norm((string)$ctx['course_name'])!==$needle)continue;$closure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$b);$m=edu_course_risk_period_metrics($conn,$sid,$schoolId,$yearId,$b,$ctx,$closure['date']??null,30);if($m['course_mean_current']!==null){$found=['bimester'=>$b,'label'=>edu_predictive_bimester_label($b),'mean'=>$m['course_mean_current'],'low_rate'=>$m['low_grade_rate_current'],'evaluations'=>$m['evaluations_count_current'],'class_mean'=>$m['class_course_mean_current']];break 2;}}}if($found)$periods[]=$found;}if($periods)$out[]=['academic_year_id'=>$yearId,'year'=>(string)($year['year']??$yearId),'periods'=>$periods];}
    return$out;
}
function edu_course_risk_history_direction(array $history): string {
    $vals=[];foreach($history as $year)foreach((array)($year['periods']??[]) as $p)if(isset($p['mean'])&&is_numeric($p['mean']))$vals[]=(float)$p['mean'];if(count($vals)<2)return'Sin historial suficiente para definir una tendencia.';$s=edu_course_risk_slope(array_slice($vals,-8));if($s===null)return'Sin historial suficiente para definir una tendencia.';if($s<=-.5)return'Tendencia histórica descendente en el mismo curso.';if($s>=.5)return'Tendencia histórica de recuperación/mejora en el mismo curso.';$last=end($vals);if($last<=edu_predictive_critical_threshold()+1.5)return'Rendimiento históricamente estable, pero cercano al nivel crítico.';return'Rendimiento históricamente estable.';
}
