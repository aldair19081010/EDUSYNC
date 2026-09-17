<?php
/**
 * Dashboard v6 rápido.
 * Calcula todas las filas estudiante+curso de un bimestre en bloques, evitando
 * el patrón N+1 del detalle individual. El detalle de un alumno sigue usando
 * risk_dashboard_course.php porque allí el volumen es pequeño.
 */
require_once __DIR__.'/risk_dashboard_course.php';

function crf_norm(string $v): string {
    $v=trim($v);
    if(function_exists('iconv')){$x=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);if($x!==false)$v=$x;}
    $v=mb_strtolower($v,'UTF-8');
    return trim((string)preg_replace('/\s+/',' ',preg_replace('/[^a-z0-9]+/u',' ',$v)));
}
function crf_ctx_key(int $courseId,string $level,string $grade,string $section): string {
    return $courseId.'|'.crf_norm($level).'|'.preg_replace('/\D+/','',$grade).'|'.crf_norm($section?:'U');
}
function crf_grade_score($grade): ?float {
    $v=strtoupper(trim((string)$grade));if($v==='')return null;
    $n=str_replace(',','.',$v);if(is_numeric($n))return max(0.0,min(20.0,(float)$n));
    $map=['C'=>10.0,'B'=>13.0,'A'=>17.0,'AD'=>20.0];return $map[$v]??null;
}
function crf_grade_low($grade): bool {
    $v=strtoupper(trim((string)$grade));if($v==='')return false;if($v==='C')return true;
    $n=str_replace(',','.',$v);return is_numeric($n)&&(float)$n<edu_predictive_critical_threshold();
}
function crf_slope(array $values): ?float {
    $v=array_values(array_filter($values,static fn($x)=>$x!==null&&is_numeric($x)));$n=count($v);if($n<2)return null;
    $mx=($n-1)/2;$my=array_sum($v)/$n;$num=0.0;$den=0.0;
    foreach($v as $i=>$y){$dx=$i-$mx;$num+=$dx*((float)$y-$my);$den+=$dx*$dx;}
    return $den>0?$num/$den:null;
}
function crf_weighted_mean(array $byCompetency,array $weights): ?float {
    $sum=0.0;$used=0;
    foreach($byCompetency as $cid=>$scores){if(!$scores)continue;$sum+=(array_sum($scores)/count($scores))*(float)($weights[$cid]??1.0);$used++;}
    return $used?$sum:null;
}
function crf_eval_date_col(mysqli $conn): ?string {
    static $cache=[];$key=spl_object_id($conn);if(array_key_exists($key,$cache))return $cache[$key];
    foreach(['evaluation_date','date'] as $col)if(edu_predictive_column_exists($conn,'evaluations',$col))return $cache[$key]=$col;
    return $cache[$key]=null;
}
function crf_load_attendance(mysqli $conn,int $schoolId,array $studentIds,string $cutoffDate,int $windowDays=30): array {
    $out=[];$studentIds=array_values(array_unique(array_filter(array_map('intval',$studentIds))));if(!$studentIds||!edu_predictive_table_exists($conn,'asistencia'))return$out;
    $end=date('Y-m-d',strtotime($cutoffDate));$start=date('Y-m-d',strtotime($end.' -'.(max(7,min(120,$windowDays))-1).' days'));
    $cancel=edu_predictive_column_exists($conn,'asistencia','is_cancelled')?' AND COALESCE(a.is_cancelled,0)=0':'';
    foreach(array_chunk($studentIds,800) as $chunk){
        $ids=implode(',',array_map('intval',$chunk));
        $sql="SELECT a.student_id,a.fecha,CASE WHEN SUM(LOWER(TRIM(a.estado))='tarde')>0 THEN 'Tarde' WHEN SUM(LOWER(TRIM(a.estado)) IN ('presente','normal','temprano'))>0 THEN 'Presente' WHEN SUM(LOWER(TRIM(a.estado))='permiso')>0 THEN 'Permiso' WHEN SUM(LOWER(TRIM(a.estado)) IN ('ausente justificada','ausencia justificada'))>0 THEN 'Ausente Justificada' ELSE 'Ausente' END estado FROM asistencia a INNER JOIN student s ON s.id=a.student_id AND s.school_id=? WHERE a.student_id IN ($ids) AND a.tipo='Entrada' AND a.fecha BETWEEN ? AND ? $cancel GROUP BY a.student_id,a.fecha";
        $stmt=$conn->prepare($sql);if(!$stmt)continue;$stmt->bind_param('iss',$schoolId,$start,$end);$stmt->execute();$res=$stmt->get_result();
        while($r=$res->fetch_assoc())$out[(int)$r['student_id']][(string)$r['fecha']]=(string)$r['estado'];$stmt->close();
    }
    return$out;
}
function crf_att(array $days): array {
    $present=0;$late=0;$absent=0;$records=count($days);
    foreach($days as $state){if($state==='Presente')$present++;elseif($state==='Tarde')$late++;elseif($state==='Ausente')$absent++;}
    return ['attendance_rate_30d'=>$records?(($present+$late)/$records)*100.0:null,'late_30d'=>$records?(float)$late:null,'absent_30d'=>$records?(float)$absent:null,'attendance_records_30d'=>$records];
}

/** Carga todo un bimestre en pocas consultas, alineado con el exportador ponderado. */
function crf_load_period(mysqli $conn,int $schoolId,int $yearId,int $bimester,?string $cutoffDate): array {
    $hasTcYear=edu_predictive_column_exists($conn,'teacher_courses','academic_year_id');
    $hasEYear=edu_predictive_column_exists($conn,'evaluations','academic_year_id');
    $hasStatus=edu_predictive_column_exists($conn,'evaluations','status');
    $dateCol=crf_eval_date_col($conn);$dateSelect=$dateCol!==null?"DATE(e.`$dateCol`) evaluation_date":"NULL evaluation_date";
    $where=['tc.school_id=?','CAST(e.bimestre AS UNSIGNED)=?'];$types='ii';$params=[$schoolId,$bimester];
    if($hasTcYear){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($hasEYear){$where[]='e.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($hasStatus)$where[]="COALESCE(e.status,'Activa')<>'Anulada'";
    $sql="SELECT e.id evaluation_id,tc.course_id,COALESCE(NULLIF(TRIM(ac.name),''),'Curso') course_name,ac.level,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,ec.competencia_id,COALESCE(gcc.percentage,100) competencia_percentage,$dateSelect FROM evaluations e INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN evaluation_competencias ec ON ec.evaluation_id=e.id LEFT JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id WHERE ".implode(' AND ',$where)." ORDER BY e.id,ec.competencia_id";
    $stmt=$conn->prepare($sql);if(!$stmt)throw new RuntimeException('No pude cargar definiciones del bimestre: '.$conn->error);edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$defs=[];$evalCtx=[];
    while($r=$res->fetch_assoc()){
        $key=crf_ctx_key((int)$r['course_id'],(string)$r['level'],(string)$r['grado'],(string)$r['seccion']);
        $r['course_id']=(int)$r['course_id'];$r['competencia_id']=(int)$r['competencia_id'];$r['evaluation_id']=(int)$r['evaluation_id'];$r['weight']=max(0.0,(float)$r['competencia_percentage']/100.0);
        $defs[$key][]=$r;$evalCtx[$r['evaluation_id']]=$key;
    }
    $stmt->close();if(!$evalCtx)return['metrics'=>[],'student_meta'=>[]];

    $studentMeta=[];$gradeMap=[];$studentContexts=[];
    foreach(array_chunk(array_keys($evalCtx),700) as $chunk){
        $idSql=implode(',',array_map('intval',$chunk));
        $gsql="SELECT eg.student_id,eg.evaluation_id,eg.competencia_id,eg.grade,s.name,s.id_no,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'U') seccion,s.status FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id AND s.school_id=? WHERE eg.evaluation_id IN ($idSql)";
        $gst=$conn->prepare($gsql);if(!$gst)continue;$gst->bind_param('i',$schoolId);$gst->execute();$gr=$gst->get_result();
        while($r=$gr->fetch_assoc()){
            $sid=(int)$r['student_id'];$eid=(int)$r['evaluation_id'];$cid=(int)$r['competencia_id'];
            $gradeMap[$sid][$eid][$cid]=$r['grade'];
            $studentMeta[$sid]=['id'=>$sid,'name'=>(string)$r['name'],'id_no'=>(string)($r['id_no']??''),'nivel'=>(string)$r['nivel'],'grado'=>(string)$r['grado'],'seccion'=>(string)$r['seccion'],'status'=>(string)($r['status']??'Activo')];
            if(isset($evalCtx[$eid]))$studentContexts[$sid][$evalCtx[$eid]]=true;
        }
        $gst->close();
    }
    $attendance=$cutoffDate?crf_load_attendance($conn,$schoolId,array_keys($studentContexts),$cutoffDate,30):[];
    $raw=[];$classAgg=[];
    foreach($studentContexts as $sid=>$contexts){
        $att=crf_att($attendance[$sid]??[]);
        foreach(array_keys($contexts) as $key){
            if(empty($defs[$key]))continue;$allScores=[];$byComp=[];$weights=[];$low=0;$missing=0;$evalSeen=[];$grades=[];$courseId=0;$courseName='Curso';
            foreach($defs[$key] as $d){
                $courseId=(int)$d['course_id'];$courseName=(string)$d['course_name'];$eid=(int)$d['evaluation_id'];$cid=(int)$d['competencia_id'];$weights[$cid]=(float)$d['weight'];$evalSeen[$eid]=true;
                $grade=trim((string)($gradeMap[$sid][$eid][$cid]??''));if($grade===''){$missing++;continue;}
                $score=crf_grade_score($grade);if($score===null)continue;$byComp[$cid][]=$score;$allScores[]=$score;$grades[]=$grade;if(crf_grade_low($grade))$low++;
            }
            if(!$allScores)continue;$mean=crf_weighted_mean($byComp,$weights);if($mean===null)continue;$expected=count($defs[$key]);$graded=count($allScores);
            $m=array_merge(['student_id'=>(int)$sid,'course_id'=>$courseId,'course_name'=>$courseName,'course_mean_current'=>$mean,'distance_to_critical'=>$mean-edu_predictive_critical_threshold(),'graded_cells'=>$graded,'expected_cells'=>$expected,'missing_grade_cells'=>$missing,'low_grade_cells'=>$low,'low_grade_rate_current'=>$graded?$low/$graded:null,'missing_grade_rate_current'=>$expected?$missing/$expected:null,'evaluations_count_current'=>count($evalSeen),'grades'=>$grades,'missing_eval_absence_overlap'=>null,'evaluation_date_available'=>$dateCol!==null],$att);
            $raw[$sid.'|'.$courseId]=$m;
            if(!isset($classAgg[$key]))$classAgg[$key]=['cell_scores'=>[],'low'=>0,'student_means'=>[]];
            foreach($allScores as $score)$classAgg[$key]['cell_scores'][]=$score;$classAgg[$key]['low']+=$low;$classAgg[$key]['student_means'][$sid]=$mean;
        }
    }
    $class=[];
    foreach($classAgg as $key=>$a){
        $means=$a['student_means'];$critical=0;foreach($means as $m)if($m<edu_predictive_critical_threshold())$critical++;
        $class[$key]=['class_course_mean_current'=>$means?array_sum($means)/count($means):null,'class_low_grade_rate_current'=>$a['cell_scores']?$a['low']/count($a['cell_scores']):null,'class_students_critical_rate'=>$means?$critical/count($means):null,'class_graded_cells'=>count($a['cell_scores']),'class_students_with_data'=>count($means)];
    }
    foreach($raw as &$m){
        $sid=(int)$m['student_id'];$meta=$studentMeta[$sid]??[];$key=crf_ctx_key((int)$m['course_id'],(string)($meta['nivel']??''),(string)($meta['grado']??''),(string)($meta['seccion']??'U'));
        $c=$class[$key]??['class_course_mean_current'=>null,'class_low_grade_rate_current'=>null,'class_students_critical_rate'=>null,'class_graded_cells'=>0,'class_students_with_data'=>0];
        $m=array_merge($m,$c);$m['student_vs_class_mean']=$m['class_course_mean_current']===null?null:$m['course_mean_current']-(float)$m['class_course_mean_current'];
    }
    unset($m);return['metrics'=>$raw,'student_meta'=>$studentMeta];
}

function crf_match_student(array $s,array $filters): bool {
    $status=mb_strtolower(trim((string)($s['status']??'Activo')),'UTF-8');if(!in_array($status,['activo','active'],true))return false;
    if(!empty($filters['level'])&&strcasecmp(trim((string)$s['nivel']),trim((string)$filters['level']))!==0)return false;
    if(!empty($filters['grade'])&&(int)preg_replace('/\D+/','',(string)$s['grado'])!==(int)$filters['grade'])return false;
    if(!empty($filters['section'])&&strcasecmp(trim((string)$s['seccion']),trim((string)$filters['section']))!==0)return false;
    $search=trim((string)($filters['search']??''));if($search!==''&&mb_stripos((string)$s['name'],$search,0,'UTF-8')===false)return false;
    return true;
}

function edu_course_dashboard_data_fast(mysqli $conn,array $actor,array $filters=[]): array {
    if((int)($actor['type']??0)!==1)return['ok'=>false,'message'=>'Este módulo está disponible únicamente para administración.'];
    $schoolId=(int)($actor['school_id']??0);$model=edu_course_risk_model_load();
    if(empty($model['available']))return['ok'=>true,'model'=>['available'=>false,'reason'=>$model['reason']??'model_missing'],'period'=>[],'filters'=>edu_course_dashboard_filter_options($conn,$actor),'summary'=>['evaluated'=>0,'high'=>0,'medium'=>0,'low'=>0,'attention_courses'=>0,'high_courses'=>0,'matched'=>0],'students'=>[],'course_patterns'=>[],'interventions'=>[],'migration_ready'=>false];
    $year=edu_predictive_academic_year($conn,$schoolId,null);if(!$year)return['ok'=>false,'message'=>'No hay año académico activo.'];$yearId=(int)$year['id'];
    $b=!empty($filters['bimestre'])?(int)$filters['bimestre']:edu_predictive_latest_closed_bimester($conn,$schoolId,$yearId);if(!$b||$b<1||$b>3)return['ok'=>false,'message'=>'No hay un bimestre cerrado disponible.'];
    $closure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$b);if(empty($closure['closed'])||empty($closure['date']))return['ok'=>false,'message'=>'El bimestre seleccionado no tiene un cierre seguro.'];

    $periods=[];
    for($p=1;$p<=$b;$p++){
        $cl=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$p);if(empty($cl['closed'])||empty($cl['date']))continue;
        $periods[$p]=crf_load_period($conn,$schoolId,$yearId,$p,(string)$cl['date']);
    }
    if(empty($periods[$b]))return['ok'=>false,'message'=>'No hay calificaciones suficientes en el bimestre base.'];
    $current=$periods[$b]['metrics'];$meta=$periods[$b]['student_meta'];$coursesByStudent=[];$patterns=[];$courseIdFilter=(int)($filters['course_id']??0);

    foreach($current as $key=>$m){
        $sid=(int)$m['student_id'];$student=$meta[$sid]??null;if(!$student||!crf_match_student($student,$filters))continue;if($courseIdFilter>0&&(int)$m['course_id']!==$courseIdFilter)continue;
        $means=[];$prev=null;$attPrev=null;$available=0;
        for($p=1;$p<=$b;$p++){
            if(empty($periods[$p]['metrics'][$key]))continue;$pm=$periods[$p]['metrics'][$key];$means[]=$pm['course_mean_current'];$available++;
            if($p<$b){$prev=$pm['course_mean_current'];$attPrev=$pm['attendance_rate_30d'];}
        }
        $f=$m;
        $f['course_trend']=($prev!==null)?(float)$m['course_mean_current']-(float)$prev:0.0;
        $f['previous_course_available']=$prev!==null?1.0:0.0;
        $f['same_year_course_slope']=crf_slope($means);
        $f['same_year_periods_available']=(float)$available;
        $f['attendance_trend_same_year']=($m['attendance_rate_30d']!==null&&$attPrev!==null)?(float)$m['attendance_rate_30d']-(float)$attPrev:null;
        $score=edu_course_risk_score($f,$model);
        $course=['course_id'=>(int)$m['course_id'],'course_name'=>(string)$m['course_name'],'probability'=>$score['probability'],'level'=>$score['level'],'features'=>$f,'reasons'=>edu_course_risk_reason_labels($f),'history_same_year'=>[]];
        $coursesByStudent[$sid][]=$course;
        $pkey=crf_norm((string)$course['course_name']).'|'.crf_norm((string)$student['nivel']).'|'.(int)preg_replace('/\D+/','',(string)$student['grado']).'|'.crf_norm((string)$student['seccion']);
        if(!isset($patterns[$pkey])&&$f['class_students_critical_rate']!==null)$patterns[$pkey]=['course_name'=>$course['course_name'],'nivel'=>$student['nivel'],'grado'=>$student['grado'],'seccion'=>$student['seccion'],'class_mean'=>$f['class_course_mean_current'],'critical_student_rate'=>$f['class_students_critical_rate'],'low_grade_rate'=>$f['class_low_grade_rate_current'],'evaluations_count'=>$f['evaluations_count_current'],'students_with_data'=>$f['class_students_with_data']??0];
    }

    $items=[];$summary=['Alto'=>0,'Medio'=>0,'Bajo'=>0,'attention_courses'=>0,'high_courses'=>0];$riskFilter=trim((string)($filters['risk_level']??''));if(!in_array($riskFilter,['Alto','Medio','Bajo'],true))$riskFilter='';
    foreach($coursesByStudent as $sid=>$courses){
        usort($courses,static fn($a,$b)=>$b['probability']<=>$a['probability']);$general=edu_course_risk_general_priority($courses);$summary[$general['level']]++;$summary['attention_courses']+=(int)$general['attention_courses'];$summary['high_courses']+=(int)$general['high_courses'];if($riskFilter!==''&&$general['level']!==$riskFilter)continue;
        $attendance=null;$absent=null;$late=null;foreach($courses as $c){$f=$c['features'];if($attendance===null&&$f['attendance_rate_30d']!==null)$attendance=(float)$f['attendance_rate_30d'];if($absent===null&&$f['absent_30d']!==null)$absent=(float)$f['absent_30d'];if($late===null&&$f['late_30d']!==null)$late=(float)$f['late_30d'];}
        $student=$meta[$sid];$items[]=['student'=>$student,'source_bimester'=>$b,'source_label'=>edu_predictive_bimester_label($b),'target_bimester'=>$b+1,'target_label'=>edu_predictive_bimester_label($b+1),'base_closed_at'=>$closure['date'],'general'=>$general,'courses'=>array_slice($courses,0,4),'course_count'=>count($courses),'attendance'=>['rate'=>$attendance,'absent'=>$absent,'late'=>$late]];
    }
    usort($items,static function($a,$b){$rank=['Alto'=>3,'Medio'=>2,'Bajo'=>1];$r=($rank[$b['general']['level']]??0)<=>($rank[$a['general']['level']]??0);if($r!==0)return$r;$r=($b['general']['attention_courses']??0)<=>($a['general']['attention_courses']??0);return$r!==0?$r:(($b['general']['max_course_probability']??0)<=>($a['general']['max_course_probability']??0));});
    $patterns=array_values($patterns);usort($patterns,static fn($a,$b)=>($b['critical_student_rate']??0)<=>($a['critical_student_rate']??0));$patterns=array_slice($patterns,0,12);
    $academic=[];foreach(['level','grade','section'] as $k)if(!empty($filters[$k]))$academic[$k]=$filters[$k];$interventionFilters=$academic;if(!empty($filters['intervention_status']))$interventionFilters['status']=$filters['intervention_status'];$interventions=edu_risk_list_interventions($conn,$actor,$interventionFilters,100);$search=trim((string)($filters['search']??''));if($search!=='')$interventions=array_values(array_filter($interventions,static fn($r)=>stripos((string)($r['student_name']??''),$search)!==false));
    $migrationReady=edu_risk_interventions_ready($conn)&&edu_predictive_column_exists($conn,'student_risk_interventions','course_id')&&edu_predictive_column_exists($conn,'student_risk_interventions','course_name');
    return ['ok'=>true,'model'=>['available'=>true,'reason'=>null,'created_at'=>$model['created_at']??null,'schema_version'=>$model['schema_version']??null,'variant'=>$model['model_variant']??null,'thresholds'=>$model['risk_thresholds']??[]],'period'=>['base'=>edu_predictive_bimester_label($b),'target'=>edu_predictive_bimester_label($b+1),'cutoff'=>$closure['date']],'filters'=>edu_course_dashboard_filter_options($conn,$actor),'summary'=>['evaluated'=>count($coursesByStudent),'high'=>$summary['Alto'],'medium'=>$summary['Medio'],'low'=>$summary['Bajo'],'attention_courses'=>$summary['attention_courses'],'high_courses'=>$summary['high_courses'],'matched'=>count($items)],'students'=>array_slice($items,0,120),'course_patterns'=>$patterns,'interventions'=>$interventions,'migration_ready'=>$migrationReady,'engine'=>'bulk_v6'];
}
