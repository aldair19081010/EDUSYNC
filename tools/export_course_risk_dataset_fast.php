<?php
/**
 * Exportador optimizado v6 por curso.
 *
 * Evita el patrón N+1 del exportador inicial: carga definiciones, notas y
 * asistencia una vez por bimestre cerrado y calcula las variables en memoria.
 */
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Solo CLI.\n");exit(1);}
require_once __DIR__.'/../edusync/db_connect.php';
require_once __DIR__.'/../edusync/includes/predictive_course_risk.php';

$options=getopt('',['school:','year::','output::']);
$schoolId=(int)($options['school']??0);
$yearFilter=isset($options['year'])&&$options['year']!==false&&$options['year']!==''?(int)$options['year']:null;
$output=(string)($options['output']??(__DIR__.'/../storage/course_risk_dataset.csv'));
if($schoolId<=0){fwrite(STDERR,"Falta --school=<ID_REAL>.\n");exit(1);}
if(!isset($conn)||!($conn instanceof mysqli)){fwrite(STDERR,"Sin conexión MySQL.\n");exit(1);}
@set_time_limit(0);

function crf_progress(string $text): void {echo '['.date('H:i:s').'] '.$text."\n";if(function_exists('ob_flush'))@ob_flush();flush();}
function crf_val($v){return$v===null||$v===''?'':round((float)$v,6);}
function crf_years(mysqli $conn,int $schoolId,?int $yearId): array {
    $sql='SELECT id,year,start_date,end_date,is_active FROM academic_year WHERE school_id=?'.($yearId?' AND id=?':'').' ORDER BY start_date,id';
    $stmt=$conn->prepare($sql);if(!$stmt)return[];
    if($yearId)$stmt->bind_param('ii',$schoolId,$yearId);else$stmt->bind_param('i',$schoolId);
    $stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;
}
function crf_grade_score($grade): ?float {return edu_course_risk_grade_score($grade);}
function crf_grade_low($grade): bool {return edu_course_risk_grade_low($grade);}
function crf_slope(array $values): ?float {return edu_course_risk_slope($values);}
function crf_ctx_key(int $courseId,string $level,string $grade,string $section): string {
    return $courseId.'|'.edu_course_risk_norm($level).'|'.preg_replace('/\D+/','',$grade).'|'.edu_course_risk_norm($section?:'U');
}

function crf_load_attendance(mysqli $conn,int $schoolId,array $studentIds,string $cutoffDate,int $windowDays=30): array {
    $out=[];$studentIds=array_values(array_unique(array_filter(array_map('intval',$studentIds))));
    if(!$studentIds||!edu_predictive_table_exists($conn,'asistencia'))return$out;
    $end=date('Y-m-d',strtotime($cutoffDate));$start=date('Y-m-d',strtotime($end.' -'.(max(7,min(120,$windowDays))-1).' days'));
    $cancel=edu_predictive_column_exists($conn,'asistencia','is_cancelled')?' AND COALESCE(a.is_cancelled,0)=0':'';
    foreach(array_chunk($studentIds,800) as $chunk){
        $ids=implode(',',array_map('intval',$chunk));
        $sql="SELECT a.student_id,a.fecha,CASE WHEN SUM(LOWER(TRIM(a.estado))='tarde')>0 THEN 'Tarde' WHEN SUM(LOWER(TRIM(a.estado)) IN ('presente','normal','temprano'))>0 THEN 'Presente' WHEN SUM(LOWER(TRIM(a.estado))='permiso')>0 THEN 'Permiso' WHEN SUM(LOWER(TRIM(a.estado)) IN ('ausente justificada','ausencia justificada'))>0 THEN 'Ausente Justificada' ELSE 'Ausente' END estado FROM asistencia a INNER JOIN student s ON s.id=a.student_id AND s.school_id=? WHERE a.student_id IN ($ids) AND a.tipo='Entrada' AND a.fecha BETWEEN ? AND ? $cancel GROUP BY a.student_id,a.fecha";
        $stmt=$conn->prepare($sql);if(!$stmt)continue;$stmt->bind_param('iss',$schoolId,$start,$end);$stmt->execute();$res=$stmt->get_result();
        while($r=$res->fetch_assoc()){$sid=(int)$r['student_id'];$out[$sid][(string)$r['fecha']]=(string)$r['estado'];}$stmt->close();
    }
    return$out;
}
function crf_attendance_metrics(array $days): array {
    $present=0;$late=0;$absent=0;$records=count($days);
    foreach($days as $state){if($state==='Presente')$present++;elseif($state==='Tarde')$late++;elseif($state==='Ausente')$absent++;}
    return['attendance_rate_30d'=>$records?(($present+$late)/$records)*100.0:null,'late_30d'=>$records?(float)$late:null,'absent_30d'=>$records?(float)$absent:null,'attendance_records_30d'=>$records];
}

function crf_load_period(mysqli $conn,int $schoolId,int $yearId,int $bimester,?string $cutoffDate): array {
    $hasTcYear=edu_predictive_column_exists($conn,'teacher_courses','academic_year_id');
    $hasEYear=edu_predictive_column_exists($conn,'evaluations','academic_year_id');
    $hasStatus=edu_predictive_column_exists($conn,'evaluations','status');
    $dateCol=edu_course_risk_evaluation_date_column($conn);
    $dateSelect=$dateCol!==null?"DATE(e.`$dateCol`) evaluation_date":"NULL evaluation_date";
    $where=['tc.school_id=?','CAST(e.bimestre AS UNSIGNED)=?'];$types='ii';$params=[$schoolId,$bimester];
    if($hasTcYear){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($hasEYear){$where[]='e.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($hasStatus)$where[]="COALESCE(e.status,'Activa')<>'Anulada'";
    $baseWhere=implode(' AND ',$where);

    $sql="SELECT e.id evaluation_id,tc.course_id,COALESCE(NULLIF(TRIM(ac.name),''),'Curso') course_name,ac.level,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,ec.competencia_id,$dateSelect FROM evaluations e INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN evaluation_competencias ec ON ec.evaluation_id=e.id WHERE $baseWhere ORDER BY e.id,ec.competencia_id";
    $stmt=$conn->prepare($sql);if(!$stmt)throw new RuntimeException('No pude cargar definiciones del bimestre: '.$conn->error);edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();
    $defs=[];$evalCtx=[];
    while($r=$res->fetch_assoc()){$key=crf_ctx_key((int)$r['course_id'],(string)$r['level'],(string)$r['grado'],(string)$r['seccion']);$r['course_id']=(int)$r['course_id'];$r['competencia_id']=(int)$r['competencia_id'];$r['evaluation_id']=(int)$r['evaluation_id'];$defs[$key][]=$r;$evalCtx[$r['evaluation_id']]=$key;}
    $stmt->close();
    if(!$evalCtx)return['metrics'=>[],'student_meta'=>[],'date_available'=>$dateCol!==null];

    $evalIds=array_keys($evalCtx);$studentMeta=[];$gradeMap=[];$studentContexts=[];
    foreach(array_chunk($evalIds,700) as $chunk){
        $idSql=implode(',',array_map('intval',$chunk));
        $gsql="SELECT eg.student_id,eg.evaluation_id,eg.competencia_id,eg.grade,s.name,s.id_no,s.nivel,s.grado,s.seccion FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id AND s.school_id=? WHERE eg.evaluation_id IN ($idSql)";
        $gst=$conn->prepare($gsql);if(!$gst)continue;$gst->bind_param('i',$schoolId);$gst->execute();$gr=$gst->get_result();
        while($r=$gr->fetch_assoc()){$sid=(int)$r['student_id'];$eid=(int)$r['evaluation_id'];$cid=(int)$r['competencia_id'];$gradeMap[$sid][$eid][$cid]=$r['grade'];$studentMeta[$sid]=['id'=>$sid,'name'=>(string)$r['name'],'id_no'=>(string)($r['id_no']??''),'nivel'=>(string)$r['nivel'],'grado'=>(string)$r['grado'],'seccion'=>(string)$r['seccion']];if(isset($evalCtx[$eid]))$studentContexts[$sid][$evalCtx[$eid]]=true;}
        $gst->close();
    }

    $attendance=$cutoffDate?crf_load_attendance($conn,$schoolId,array_keys($studentContexts),$cutoffDate,30):[];
    $raw=[];$classAgg=[];
    foreach($studentContexts as $sid=>$contexts){
        $att=crf_attendance_metrics($attendance[$sid]??[]);
        foreach(array_keys($contexts) as $key){
            if(empty($defs[$key]))continue;$scores=[];$grades=[];$low=0;$missing=0;$evalIdsSeen=[];$missingDates=[];$courseId=0;$courseName='Curso';
            foreach($defs[$key] as $d){$courseId=(int)$d['course_id'];$courseName=(string)$d['course_name'];$eid=(int)$d['evaluation_id'];$cid=(int)$d['competencia_id'];$evalIdsSeen[$eid]=true;$grade=trim((string)($gradeMap[$sid][$eid][$cid]??''));if($grade===''){$missing++;if(!empty($d['evaluation_date']))$missingDates[]=(string)$d['evaluation_date'];continue;}$score=crf_grade_score($grade);if($score===null)continue;$scores[]=$score;$grades[]=$grade;if(crf_grade_low($grade))$low++;}
            if(!$scores)continue;$expected=count($defs[$key]);$graded=count($scores);$mean=array_sum($scores)/$graded;$overlap=null;
            if($missingDates){$overlap=0;foreach($missingDates as $d)if(($attendance[$sid][$d]??'')==='Ausente')$overlap++;}
            $m=array_merge(['student_id'=>(int)$sid,'course_id'=>$courseId,'course_name'=>$courseName,'course_mean_current'=>$mean,'distance_to_critical'=>$mean-edu_predictive_critical_threshold(),'graded_cells'=>$graded,'expected_cells'=>$expected,'missing_grade_cells'=>$missing,'low_grade_cells'=>$low,'low_grade_rate_current'=>$graded?$low/$graded:null,'missing_grade_rate_current'=>$expected?$missing/$expected:null,'evaluations_count_current'=>count($evalIdsSeen),'grades'=>$grades,'missing_eval_absence_overlap'=>$overlap,'evaluation_date_available'=>$dateCol!==null],$att);
            $raw[$sid.'|'.$courseId]=$m;
            if(!isset($classAgg[$key]))$classAgg[$key]=['scores'=>[],'low'=>0,'students'=>[]];
            foreach($scores as $score)$classAgg[$key]['scores'][]=$score;$classAgg[$key]['low']+=$low;$classAgg[$key]['students'][$sid]=$mean;
        }
    }
    $class=[];
    foreach($classAgg as $key=>$a){$scores=$a['scores'];$means=$a['students'];$critical=0;foreach($means as $m)if($m<edu_predictive_critical_threshold())$critical++;$class[$key]=['class_course_mean_current'=>$scores?array_sum($scores)/count($scores):null,'class_low_grade_rate_current'=>$scores?$a['low']/count($scores):null,'class_students_critical_rate'=>$means?$critical/count($means):null,'class_graded_cells'=>count($scores),'class_students_with_data'=>count($means)];}
    foreach($raw as $rk=>&$m){$sid=(int)$m['student_id'];$meta=$studentMeta[$sid]??[];$key=crf_ctx_key((int)$m['course_id'],(string)($meta['nivel']??''),(string)($meta['grado']??''),(string)($meta['seccion']??'U'));$c=$class[$key]??['class_course_mean_current'=>null,'class_low_grade_rate_current'=>null,'class_students_critical_rate'=>null,'class_graded_cells'=>0,'class_students_with_data'=>0];$m=array_merge($m,$c);$m['student_vs_class_mean']=$m['class_course_mean_current']===null?null:$m['course_mean_current']-(float)$m['class_course_mean_current'];}
    unset($m);
    return['metrics'=>$raw,'student_meta'=>$studentMeta,'date_available'=>$dateCol!==null];
}

$features=['course_mean_current','distance_to_critical','course_trend','previous_course_available','same_year_course_slope','same_year_periods_available','low_grade_rate_current','missing_grade_rate_current','evaluations_count_current','attendance_rate_30d','attendance_trend_same_year','late_30d','absent_30d','class_course_mean_current','class_low_grade_rate_current','class_students_critical_rate','student_vs_class_mean'];
$headers=array_merge(['school_id','academic_year_id','academic_year','student_id','nivel','grado','seccion','course_id','course_name','bimester','target_bimester','cutoff_date'],$features,['graded_cells','expected_cells','missing_eval_absence_overlap','evaluation_date_available','target_course_critical']);
$dir=dirname($output);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)){fwrite(STDERR,"No pude crear $dir.\n");exit(1);}$fh=fopen($output,'wb');if(!$fh){fwrite(STDERR,"No pude crear $output.\n");exit(1);}fputcsv($fh,$headers);

$rows=0;$positive=0;$negative=0;$pairs=[];$courses=[];$missingAttendance=0;$missingGrades=0;$dateOverlapAvailable=0;$skippedTarget=0;$uniqueStudents=[];
$years=crf_years($conn,$schoolId,$yearFilter);if(!$years){fclose($fh);fwrite(STDERR,"No encontré años académicos para el colegio.\n");exit(1);}
crf_progress('Iniciando exportación v6 optimizada. Años encontrados: '.count($years));
foreach($years as $year){
    $yearId=(int)$year['id'];$yearLabel=(string)($year['year']??$yearId);crf_progress("Año $yearLabel (ID $yearId): revisando cierres...");
    $closures=[];$periodData=[];
    for($b=1;$b<=4;$b++){$closures[$b]=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$b);if(!empty($closures[$b]['closed'])){crf_progress("  Cargando ".edu_predictive_bimester_label($b)." bimestre cerrado...");$periodData[$b]=crf_load_period($conn,$schoolId,$yearId,$b,$closures[$b]['date']??null);crf_progress('    Cursos-alumno con datos: '.count($periodData[$b]['metrics']));}}
    for($b=1;$b<=3;$b++){
        if(empty($closures[$b]['closed'])||empty($closures[$b]['date'])||empty($closures[$b+1]['closed'])||empty($periodData[$b]['metrics'])||empty($periodData[$b+1]['metrics']))continue;
        $before=$rows;crf_progress('  Generando par '.edu_predictive_bimester_label($b).'→'.edu_predictive_bimester_label($b+1).'...');
        foreach($periodData[$b]['metrics'] as $key=>$current){
            if(!isset($periodData[$b+1]['metrics'][$key])){$skippedTarget++;continue;}$target=$periodData[$b+1]['metrics'][$key];
            $sid=(int)$current['student_id'];$courseId=(int)$current['course_id'];$history=[];
            for($hb=1;$hb<=$b;$hb++){if(empty($closures[$hb]['closed'])||empty($periodData[$hb]['metrics'][$key]))continue;$history[]=$periodData[$hb]['metrics'][$key];}
            $previous=count($history)>=2?$history[count($history)-2]:null;$means=array_map(static function($p){return$p['course_mean_current'];},$history);$prevMean=$previous['course_mean_current']??null;$prevAtt=$previous['attendance_rate_30d']??null;
            $f=$current;$f['course_trend']=$prevMean!==null?$current['course_mean_current']-$prevMean:0.0;$f['previous_course_available']=$prevMean!==null?1.0:0.0;$f['same_year_course_slope']=crf_slope($means);$f['same_year_periods_available']=(float)count($history);$f['attendance_trend_same_year']=($current['attendance_rate_30d']!==null&&$prevAtt!==null)?$current['attendance_rate_30d']-$prevAtt:null;
            $meta=$periodData[$b]['student_meta'][$sid]??[];$targetCritical=(float)$target['course_mean_current']<edu_predictive_critical_threshold()?1:0;
            $line=[$schoolId,$yearId,$yearLabel,$sid,(string)($meta['nivel']??''),(string)($meta['grado']??''),(string)($meta['seccion']??''),$courseId,(string)$current['course_name'],$b,$b+1,(string)$closures[$b]['date']];foreach($features as $feature)$line[]=crf_val($f[$feature]??null);$line[]=(int)$current['graded_cells'];$line[]=(int)$current['expected_cells'];$line[]=crf_val($current['missing_eval_absence_overlap']??null);$line[]=!empty($current['evaluation_date_available'])?1:0;$line[]=$targetCritical;fputcsv($fh,$line);
            $rows++;$uniqueStudents[$sid]=true;if($targetCritical)$positive++;else$negative++;$pair=$b.'->'.($b+1);$pairs[$pair]=($pairs[$pair]??0)+1;$name=(string)$current['course_name'];$courses[$name]=($courses[$name]??0)+1;if((int)($current['attendance_records_30d']??0)===0)$missingAttendance++;if((int)($current['missing_grade_cells']??0)>0)$missingGrades++;if(!empty($current['evaluation_date_available']))$dateOverlapAvailable++;
        }
        crf_progress('    Filas agregadas: '.($rows-$before).' | acumuladas: '.$rows);
    }
    unset($periodData);
}
fclose($fh);arsort($courses);
echo "\nDataset por curso creado: $output\n";echo "Esquema: v6 estudiante + curso + bimestre\n";echo "Filas curso-periodo: $rows | positivas: $positive | negativas: $negative".($rows?' | prevalencia: '.number_format($positive/$rows*100,1).'%':'')."\n";echo 'Estudiantes distintos: '.count($uniqueStudents)."\n";echo 'Pares: '.json_encode($pairs,JSON_UNESCAPED_UNICODE)."\n";echo 'Cursos con más filas: '.json_encode(array_slice($courses,0,10,true),JSON_UNESCAPED_UNICODE)."\n";echo "Filas con alguna calificación faltante: $missingGrades\n";echo "Filas sin asistencia en ventana de 30 días: $missingAttendance\n";echo "Filas donde existe fecha académica de evaluación para cruce con asistencia: $dateOverlapAvailable\n";echo "Filas omitidas porque el mismo curso no tiene resultado observable en N+1: $skippedTarget\n";echo "IMPORTANTE: una nota faltante no se convirtió en cero. Teacher ID no se exporta como predictor.\n";if($rows<300)echo "ADVERTENCIA: dataset por curso pequeño; interpretar validación con cautela.\n";
