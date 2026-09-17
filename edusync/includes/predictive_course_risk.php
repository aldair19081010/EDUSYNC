<?php
/**
 * EduSync - Alerta Temprana Inteligente v6 por estudiante + curso.
 *
 * Pregunta del modelo:
 *   Con la información disponible al cierre del bimestre N, ¿qué probabilidad
 *   existe de que ESTE MISMO CURSO presente rendimiento crítico en N+1?
 *
 * Rendimiento crítico operativo para el modelo:
 * - nota literal C, o
 * - nota numérica < 10.5;
 * - para resumir un curso, las letras se normalizan SOLO para cálculo de
 *   tendencia: C=10, B=12, A=15.5, AD=19. La letra C sigue siendo crítica
 *   directamente y una ausencia de nota nunca se convierte en cero.
 *
 * La asistencia es una señal asociada al riesgo, no una prueba de causalidad.
 * El docente/teacher_id NO es predictor.
 */
require_once __DIR__.'/predictive_risk.php';

function edu_course_risk_model_path(): string {
    $custom=trim((string)getenv('EDUSYNC_COURSE_RISK_MODEL_PATH'));
    return $custom!==''?$custom:dirname(__DIR__).'/storage/ai_models/course_risk_model.json';
}

function edu_course_risk_model_load(): array {
    $path=edu_course_risk_model_path();
    if(!is_file($path)||!is_readable($path))return['available'=>false,'reason'=>'model_missing','path'=>$path];
    $model=json_decode((string)file_get_contents($path),true);
    if(!is_array($model))return['available'=>false,'reason'=>'model_invalid','path'=>$path];
    $schema=(int)($model['schema_version']??0);$variant=(string)($model['model_variant']??'');if(!(($schema===6&&$variant==='student_course_next_bimester_v6')||($schema===7&&$variant==='student_course_longitudinal_v7')))return['available'=>false,'reason'=>'model_outdated','path'=>$path];
    $features=(array)($model['features']??[]);$coefs=(array)($model['coefficients']??[]);$mean=(array)($model['scaler']['mean']??[]);$scale=(array)($model['scaler']['scale']??[]);
    if(($model['model_type']??'')!=='logistic_regression'||!$features||!isset($model['intercept']))return['available'=>false,'reason'=>'model_schema','path'=>$path];
    foreach($features as $f)if(!array_key_exists($f,$coefs)||!array_key_exists($f,$mean)||!array_key_exists($f,$scale))return['available'=>false,'reason'=>'model_schema','path'=>$path];
    $cal=(array)($model['calibration']??[]);
    if(($cal['method']??'')!=='platt_grouped_oof'||!is_numeric($cal['coefficient']??null)||!is_numeric($cal['intercept']??null)||(float)$cal['coefficient']<=0)return['available'=>false,'reason'=>'model_schema','path'=>$path];
    $model['available']=true;$model['path']=$path;return$model;
}

/** Normalización únicamente para tendencia/promedios internos del modelo v6. */
function edu_course_risk_grade_score($grade): ?float {
    $v=strtoupper(trim((string)$grade));if($v==='')return null;
    $numeric=str_replace(',','.',$v);
    if(is_numeric($numeric))return max(0.0,min(20.0,(float)$numeric));
    $map=['C'=>10.0,'B'=>13.0,'A'=>17.0,'AD'=>20.0];
    return$map[$v]??null;
}
function edu_course_risk_grade_low($grade): bool {
    $v=strtoupper(trim((string)$grade));if($v==='')return false;
    if($v==='C')return true;$numeric=str_replace(',','.',$v);
    return is_numeric($numeric)&&(float)$numeric<edu_predictive_critical_threshold();
}
function edu_course_risk_norm(string $value): string {
    $value=trim($value);if(function_exists('iconv')){$x=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);if($x!==false)$value=$x;}
    $value=mb_strtolower($value,'UTF-8');$value=preg_replace('/[^a-z0-9]+/u',' ',(string)$value);return trim((string)preg_replace('/\s+/',' ',$value));
}
function edu_course_risk_section_sql(string $alias,string $placeholder='?'): string {
    return "LOWER(TRIM(COALESCE(NULLIF($alias.seccion,''),'U')))=LOWER(TRIM(COALESCE(NULLIF($placeholder,''),'U')))";
}

/** Fecha académica de una evaluación. created_at NO se usa como fecha de examen. */
function edu_course_risk_evaluation_date_column(mysqli $conn): ?string {
    foreach(['evaluation_date','date'] as $column)if(edu_predictive_column_exists($conn,'evaluations',$column))return$column;
    return null;
}

/**
 * Cursos que realmente aparecen para el estudiante en un periodo histórico.
 * Sirve para exportación y también evita inventar asignaciones antiguas.
 */
function edu_course_risk_contexts_from_grades(mysqli $conn,int $studentId,int $schoolId,int $yearId,int $bimester): array {
    $where=['eg.student_id=?','tc.school_id=?','CAST(e.bimestre AS UNSIGNED)=?'];$types='iii';$params=[$studentId,$schoolId,$bimester];
    if(edu_predictive_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if(edu_predictive_column_exists($conn,'evaluations','academic_year_id')){$where[]='e.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if(edu_predictive_column_exists($conn,'evaluations','status'))$where[]="COALESCE(e.status,'Activa')<>'Anulada'";
    $sql="SELECT tc.course_id,COALESCE(NULLIF(TRIM(ac.name),''),'Curso') course_name,ac.level,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,GROUP_CONCAT(DISTINCT tc.id ORDER BY tc.id) teacher_course_ids FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN academic_courses ac ON ac.id=tc.course_id WHERE ".implode(' AND ',$where)." GROUP BY tc.course_id,ac.name,ac.level,tc.grado,tc.seccion ORDER BY ac.name";
    $stmt=$conn->prepare($sql);if(!$stmt)return[];edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];
    while($r=$res->fetch_assoc()){$ids=array_values(array_filter(array_map('intval',explode(',',(string)$r['teacher_course_ids']))));if(!$ids)continue;$r['course_id']=(int)$r['course_id'];$r['teacher_course_ids']=$ids;$rows[]=$r;}$stmt->close();return$rows;
}

/** Cursos asignados actualmente al aula del estudiante, incluso si aún falta alguna nota. */
function edu_course_risk_live_contexts(mysqli $conn,array $student,int $schoolId,int $yearId): array {
    $level=trim((string)($student['nivel']??''));$grade=(int)preg_replace('/\D+/','',(string)($student['grado']??''));$section=trim((string)($student['seccion']??'U'));if($level===''||$grade<=0)return[];
    $where=['tc.school_id=?','LOWER(TRIM(ac.level))=LOWER(TRIM(?))','CAST(tc.grado AS UNSIGNED)=?'];$types='isi';$params=[$schoolId,$level,$grade];
    if(edu_predictive_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    $where[]=edu_course_risk_section_sql('tc');$types.='s';$params[]=$section;
    $sql="SELECT tc.course_id,COALESCE(NULLIF(TRIM(ac.name),''),'Curso') course_name,ac.level,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,GROUP_CONCAT(DISTINCT tc.id ORDER BY tc.id) teacher_course_ids FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id WHERE ".implode(' AND ',$where)." GROUP BY tc.course_id,ac.name,ac.level,tc.grado,tc.seccion ORDER BY ac.name";
    $stmt=$conn->prepare($sql);if(!$stmt)return[];edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc()){$r['course_id']=(int)$r['course_id'];$r['teacher_course_ids']=array_values(array_filter(array_map('intval',explode(',',(string)$r['teacher_course_ids']))));if($r['teacher_course_ids'])$rows[]=$r;}$stmt->close();return$rows;
}

function edu_course_risk_context_for_course(mysqli $conn,int $studentId,int $schoolId,int $yearId,int $bimester,int $courseId): ?array {
    foreach(edu_course_risk_contexts_from_grades($conn,$studentId,$schoolId,$yearId,$bimester) as $ctx)if((int)$ctx['course_id']===$courseId)return$ctx;
    return null;
}

/** Filas esperadas de evaluación/competencia para un conjunto de asignaciones. */
function edu_course_risk_expected_cells(mysqli $conn,array $teacherCourseIds,int $yearId,int $bimester,int $studentId): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$teacherCourseIds))));if(!$ids)return[];$idSql=implode(',',$ids);
    $dateColumn=edu_course_risk_evaluation_date_column($conn);$dateExpr=$dateColumn!==null?"DATE(e.`$dateColumn`)":"NULL";$dateSelect=$dateExpr." evaluation_date";$dateOrder=$dateColumn!==null?$dateExpr.",e.id":"e.id";
    $statusWhere=edu_predictive_column_exists($conn,'evaluations','status')?" AND COALESCE(e.status,'Activa')<>'Anulada'":'';
    $yearWhere=edu_predictive_column_exists($conn,'evaluations','academic_year_id')?' AND e.academic_year_id=?':'';$types=$yearWhere!==''?'iii':'ii';$params=$yearWhere!==''?[$studentId,$bimester,$yearId]:[$studentId,$bimester];
    $sql="SELECT e.id evaluation_id,e.title,$dateSelect,ec.competencia_id,COALESCE(gcc.percentage,100) competencia_percentage,eg.grade FROM evaluations e INNER JOIN evaluation_competencias ec ON ec.evaluation_id=e.id LEFT JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id LEFT JOIN evaluation_grades eg ON eg.evaluation_id=e.id AND eg.competencia_id=ec.competencia_id AND eg.student_id=? WHERE e.teacher_course_id IN ($idSql) AND CAST(e.bimestre AS UNSIGNED)=?$yearWhere$statusWhere ORDER BY $dateOrder,ec.competencia_id";
    $stmt=$conn->prepare($sql);if(!$stmt)return[];edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;
}

function edu_course_risk_attendance_status_on_dates(mysqli $conn,int $studentId,array $dates): array {
    $dates=array_values(array_unique(array_filter($dates,static fn($d)=>is_string($d)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$d))));if(!$dates||!edu_predictive_table_exists($conn,'asistencia'))return[];
    $quoted=array_map(static fn($d)=>"'".$conn->real_escape_string($d)."'",$dates);$cancel=edu_predictive_column_exists($conn,'asistencia','is_cancelled')?' AND COALESCE(is_cancelled,0)=0':'';
    $sql="SELECT fecha,CASE WHEN SUM(LOWER(TRIM(estado))='tarde')>0 THEN 'Tarde' WHEN SUM(LOWER(TRIM(estado)) IN ('presente','normal','temprano'))>0 THEN 'Presente' WHEN SUM(LOWER(TRIM(estado))='permiso')>0 THEN 'Permiso' WHEN SUM(LOWER(TRIM(estado)) IN ('ausente justificada','ausencia justificada'))>0 THEN 'Ausente Justificada' ELSE 'Ausente' END estado FROM asistencia WHERE student_id=? AND tipo='Entrada' AND fecha IN (".implode(',',$quoted).") $cancel GROUP BY fecha";
    $stmt=$conn->prepare($sql);if(!$stmt)return[];$stmt->bind_param('i',$studentId);$stmt->execute();$res=$stmt->get_result();$out=[];while($r=$res->fetch_assoc())$out[(string)$r['fecha']]=(string)$r['estado'];$stmt->close();return$out;
}

/** Contexto del aula para el mismo curso/evaluaciones; teacher_id no se devuelve como predictor. */
function edu_course_risk_class_metrics(mysqli $conn,array $teacherCourseIds,int $yearId,int $bimester): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$teacherCourseIds))));$out=['class_course_mean_current'=>null,'class_low_grade_rate_current'=>null,'class_students_critical_rate'=>null,'class_graded_cells'=>0,'class_students_with_data'=>0];if(!$ids)return$out;$idSql=implode(',',$ids);
    $statusWhere=edu_predictive_column_exists($conn,'evaluations','status')?" AND COALESCE(e.status,'Activa')<>'Anulada'":'';$yearWhere=edu_predictive_column_exists($conn,'evaluations','academic_year_id')?' AND e.academic_year_id='.(int)$yearId:'';
    $sql="SELECT eg.student_id,eg.grade,eg.competencia_id,COALESCE(gcc.percentage,100) competencia_percentage FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id LEFT JOIN general_course_competencies gcc ON gcc.id=eg.competencia_id WHERE e.teacher_course_id IN ($idSql) AND CAST(e.bimestre AS UNSIGNED)=? $yearWhere $statusWhere AND eg.grade IS NOT NULL AND TRIM(eg.grade)<>''";
    $stmt=$conn->prepare($sql);if(!$stmt)return$out;$stmt->bind_param('i',$bimester);$stmt->execute();$res=$stmt->get_result();$low=0;$cellScores=[];$byStudent=[];$weights=[];
    while($r=$res->fetch_assoc()){$score=edu_course_risk_grade_score($r['grade']);if($score===null)continue;$sid=(int)$r['student_id'];$cid=(int)$r['competencia_id'];$cellScores[]=$score;if(edu_course_risk_grade_low($r['grade']))$low++;$byStudent[$sid][$cid][]=$score;$weights[$cid]=max(0.0,(float)($r['competencia_percentage']??100)/100.0);}$stmt->close();
    $studentMeans=[];foreach($byStudent as $sid=>$comps){$sum=0.0;$used=0;foreach($comps as $cid=>$vals){if(!$vals)continue;$sum+=(array_sum($vals)/count($vals))*(float)($weights[$cid]??1.0);$used++;}if($used)$studentMeans[$sid]=$sum;}
    if($cellScores){$out['class_low_grade_rate_current']=$low/count($cellScores);$out['class_graded_cells']=count($cellScores);}
    if($studentMeans){$out['class_course_mean_current']=array_sum($studentMeans)/count($studentMeans);$critical=0;foreach($studentMeans as $m)if($m<edu_predictive_critical_threshold())$critical++;$out['class_students_with_data']=count($studentMeans);$out['class_students_critical_rate']=$critical/count($studentMeans);}
    return$out;
}

function edu_course_risk_std

function edu_course_risk_std(array $values): ?float {
    $v=array_values(array_filter($values,static fn($x)=>$x!==null&&is_numeric($x)));$n=count($v);if(!$n)return null;$m=array_sum($v)/$n;$ss=0.0;foreach($v as $x)$ss+=((float)$x-$m)**2;return sqrt($ss/$n);
}
function edu_course_risk_period_metrics(mysqli $conn,int $studentId,int $schoolId,int $yearId,int $bimester,array $context,?string $cutoffDate=null,int $attendanceWindow=30): array {
    $cells=edu_course_risk_expected_cells($conn,(array)($context['teacher_course_ids']??[]),$yearId,$bimester,$studentId);
    $scores=[];$low=0;$missing=0;$evalIds=[];$missingEvalDates=[];$grades=[];$byComp=[];$weights=[];$byEval=[];$evalOrder=[];
    foreach($cells as $cell){
        $eid=(int)$cell['evaluation_id'];$cid=(int)$cell['competencia_id'];$evalIds[$eid]=true;$weights[$cid]=max(0.0,(float)($cell['competencia_percentage']??100)/100.0);
        if(!isset($evalOrder[$eid]))$evalOrder[$eid]=['date'=>(string)($cell['evaluation_date']??''),'id'=>$eid];
        $grade=trim((string)($cell['grade']??''));if($grade===''){$missing++;if(!empty($cell['evaluation_date']))$missingEvalDates[]=(string)$cell['evaluation_date'];continue;}
        $score=edu_course_risk_grade_score($grade);if($score===null)continue;$scores[]=$score;$grades[]=$grade;$byComp[$cid][]=$score;$byEval[$eid][]=$score;if(edu_course_risk_grade_low($grade))$low++;
    }
    $expected=count($cells);$graded=count($scores);$mean=null;
    if($byComp){$sum=0.0;$used=0;foreach($byComp as $cid=>$vals){if(!$vals)continue;$sum+=(array_sum($vals)/count($vals))*(float)($weights[$cid]??1.0);$used++;}if($used)$mean=$sum;}
    $evalMeans=[];foreach($byEval as $eid=>$vals)if($vals)$evalMeans[]=['id'=>(int)$eid,'date'=>$evalOrder[$eid]['date']??'','mean'=>array_sum($vals)/count($vals)];
    usort($evalMeans,static function($a,$b){$ad=(string)$a['date'];$bd=(string)$b['date'];if($ad!==''&&$bd!==''&&$ad!==$bd)return strcmp($ad,$bd);return(int)$a['id']<=>(int)$b['id'];});
    $evalValues=array_map(static fn($x)=>(float)$x['mean'],$evalMeans);$lastEval=$evalValues?end($evalValues):null;$recent=$evalValues?array_slice($evalValues,-3):[];
    $evalLow=0;$evalTrailing=0;foreach($evalValues as $v)if($v<edu_predictive_critical_threshold())$evalLow++;for($i=count($evalValues)-1;$i>=0;$i--){if($evalValues[$i]<edu_predictive_critical_threshold())$evalTrailing++;else break;}
    $compMeans=[];$criticalComp=0;$criticalWeight=0.0;$usedWeight=0.0;foreach($byComp as $cid=>$vals){if(!$vals)continue;$cm=array_sum($vals)/count($vals);$compMeans[]=$cm;$w=(float)($weights[$cid]??1.0);$usedWeight+=$w;if($cm<edu_predictive_critical_threshold()){$criticalComp++;$criticalWeight+=$w;}}
    $attendance=$cutoffDate?edu_predictive_attendance_features($conn,$studentId,$cutoffDate,$attendanceWindow):['attendance_rate_30d'=>null,'late_30d'=>null,'absent_30d'=>null,'attendance_records_30d'=>0];
    $dateStatuses=edu_course_risk_attendance_status_on_dates($conn,$studentId,$missingEvalDates);$overlap=0;foreach($missingEvalDates as $d)if(($dateStatuses[$d]??'')==='Ausente')$overlap++;
    $class=edu_course_risk_class_metrics($conn,(array)($context['teacher_course_ids']??[]),$yearId,$bimester);
    return array_merge([
        'course_id'=>(int)($context['course_id']??0),'course_name'=>(string)($context['course_name']??'Curso'),'bimester'=>$bimester,
        'course_mean_current'=>$mean,'distance_to_critical'=>$mean===null?null:$mean-edu_predictive_critical_threshold(),
        'graded_cells'=>$graded,'expected_cells'=>$expected,'missing_grade_cells'=>$missing,'low_grade_cells'=>$low,
        'low_grade_rate_current'=>$graded>0?$low/$graded:null,'missing_grade_rate_current'=>$expected>0?$missing/$expected:null,
        'evaluations_count_current'=>count($evalIds),'grades'=>$grades,
        'evaluation_mean_last'=>$lastEval,'evaluation_mean_recent3'=>$recent?array_sum($recent)/count($recent):null,
        'evaluation_trend_current'=>edu_course_risk_slope($evalValues),'evaluation_std_current'=>edu_course_risk_std($evalValues),
        'low_evaluation_rate_current'=>$evalValues?$evalLow/count($evalValues):null,'consecutive_low_evaluations_current'=>(float)$evalTrailing,
        'competencies_graded_current'=>(float)count($compMeans),'critical_competency_count_current'=>(float)$criticalComp,
        'critical_competency_rate_current'=>$compMeans?$criticalComp/count($compMeans):null,
        'critical_competency_weight_rate_current'=>$usedWeight>0?$criticalWeight/$usedWeight:null,
        'min_competency_mean_current'=>$compMeans?min($compMeans):null,'competency_std_current'=>edu_course_risk_std($compMeans),
        'missing_eval_absence_overlap'=>$missingEvalDates?$overlap:null,'evaluation_date_available'=>edu_course_risk_evaluation_date_column($conn)!==null,
        'attendance_rate_30d'=>$attendance['attendance_rate_30d'],'late_30d'=>$attendance['late_30d'],'absent_30d'=>$attendance['absent_30d'],'attendance_records_30d'=>$attendance['attendance_records_30d'],
    ],$class);
}

function edu_course_risk_same_year_history

function edu_course_risk_same_year_history(mysqli $conn,int $studentId,int $schoolId,int $yearId,int $sourceBimester,int $courseId,int $attendanceWindow=30): array {
    $periods=[];for($b=1;$b<=$sourceBimester;$b++){$closure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$b);if(empty($closure['closed']))continue;$ctx=edu_course_risk_context_for_course($conn,$studentId,$schoolId,$yearId,$b,$courseId);if(!$ctx)continue;$m=edu_course_risk_period_metrics($conn,$studentId,$schoolId,$yearId,$b,$ctx,$closure['date']??null,$attendanceWindow);if($m['course_mean_current']!==null)$periods[]=$m;}return$periods;
}
function edu_course_risk_slope(array $values): ?float {$values=array_values(array_filter($values,static fn($v)=>$v!==null&&is_numeric($v)));$n=count($values);if($n<2)return null;$mx=($n-1)/2;$my=array_sum($values)/$n;$num=0;$den=0;foreach($values as $i=>$v){$dx=$i-$mx;$num+=$dx*((float)$v-$my);$den+=$dx*$dx;}return$den>0?$num/$den:null;}

function edu_course_risk_prior_year_features(mysqli $conn,int $studentId,int $schoolId,int $currentYearId,string $courseName,int $sourceBimester,int $maxYears=3): array {
    $empty=['prior_years_periods_available'=>0.0,'prior_years_mean'=>null,'prior_years_last_mean'=>null,'prior_years_slope'=>null,'prior_years_critical_rate'=>null,'prior_years_last_critical'=>null,'prior_years_same_bimester_mean'=>null,'prior_years_persistence_after_critical_rate'=>null];
    $stmt=$conn->prepare('SELECT start_date FROM academic_year WHERE id=? AND school_id=? LIMIT 1');if(!$stmt)return$empty;$stmt->bind_param('ii',$currentYearId,$schoolId);$stmt->execute();$cur=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$cur||empty($cur['start_date']))return$empty;
    $ids=[$studentId];$stmt=$conn->prepare('SELECT id_no,name FROM student WHERE id=? AND school_id=? LIMIT 1');if($stmt){$stmt->bind_param('ii',$studentId,$schoolId);$stmt->execute();$s=$stmt->get_result()->fetch_assoc();$stmt->close();if($s){$dni=trim((string)($s['id_no']??''));$name=trim((string)($s['name']??''));if($dni!==''){$q=$conn->prepare('SELECT id FROM student WHERE school_id=? AND id_no=?');if($q){$q->bind_param('is',$schoolId,$dni);$q->execute();$rr=$q->get_result();while($x=$rr->fetch_assoc())$ids[]=(int)$x['id'];$q->close();}}elseif($name!==''){$q=$conn->prepare("SELECT id FROM student WHERE school_id=? AND name=? AND (id_no IS NULL OR TRIM(id_no)='')");if($q){$q->bind_param('is',$schoolId,$name);$q->execute();$rr=$q->get_result();while($x=$rr->fetch_assoc())$ids[]=(int)$x['id'];$q->close();}}}}
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));$stmt=$conn->prepare('SELECT id,year,start_date FROM academic_year WHERE school_id=? AND start_date<? ORDER BY start_date DESC,id DESC LIMIT ?');if(!$stmt)return$empty;$stmt->bind_param('isi',$schoolId,$cur['start_date'],$maxYears);$stmt->execute();$res=$stmt->get_result();$years=[];while($y=$res->fetch_assoc())$years[]=$y;$stmt->close();$years=array_reverse($years);if(!$years)return$empty;
    $needle=edu_course_risk_norm($courseName);$entries=[];$same=null;
    foreach($years as $y){$yearId=(int)$y['id'];for($b=1;$b<=4;$b++){foreach($ids as $sid){$ctx=null;foreach(edu_course_risk_contexts_from_grades($conn,$sid,$schoolId,$yearId,$b) as $candidate){if(edu_course_risk_norm((string)$candidate['course_name'])===$needle){$ctx=$candidate;break;}}if(!$ctx)continue;$closure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$b);if(empty($closure['closed']))continue;$m=edu_course_risk_period_metrics($conn,$sid,$schoolId,$yearId,$b,$ctx,$closure['date']??null,30);if($m['course_mean_current']===null)continue;$entries[]=['year'=>$yearId,'bimester'=>$b,'mean'=>(float)$m['course_mean_current']];if($b===$sourceBimester)$same=(float)$m['course_mean_current'];break;}}}
    if(!$entries)return$empty;$means=array_map(static fn($x)=>(float)$x['mean'],$entries);$critical=0;$critTransitions=0;$critStayed=0;foreach($entries as $e)if($e['mean']<edu_predictive_critical_threshold())$critical++;for($i=0;$i<count($entries)-1;$i++){if($entries[$i]['mean']>=edu_predictive_critical_threshold())continue;$critTransitions++;if($entries[$i+1]['mean']<edu_predictive_critical_threshold())$critStayed++;}
    $last=end($entries);
    return['prior_years_periods_available'=>(float)count($entries),'prior_years_mean'=>array_sum($means)/count($means),'prior_years_last_mean'=>(float)$last['mean'],'prior_years_slope'=>edu_course_risk_slope($means),'prior_years_critical_rate'=>$critical/count($entries),'prior_years_last_critical'=>$last['mean']<edu_predictive_critical_threshold()?1.0:0.0,'prior_years_same_bimester_mean'=>$same,'prior_years_persistence_after_critical_rate'=>$critTransitions?$critStayed/$critTransitions:null];
}

function edu_course_risk_feature_vector(mysqli $conn,int $studentId,int $schoolId,int $yearId,int $bimester,array $context,string $cutoffDate,int $attendanceWindow=30): array {
    $current=edu_course_risk_period_metrics($conn,$studentId,$schoolId,$yearId,$bimester,$context,$cutoffDate,$attendanceWindow);$history=edu_course_risk_same_year_history($conn,$studentId,$schoolId,$yearId,$bimester,(int)$context['course_id'],$attendanceWindow);$means=array_map(static fn($p)=>$p['course_mean_current'],$history);$previous=count($history)>=2?$history[count($history)-2]:null;$currentMean=$current['course_mean_current'];$previousMean=$previous['course_mean_current']??null;$attendancePrevious=$previous['attendance_rate_30d']??null;
    $criticalPeriods=0;$trailing=0;foreach($means as $m)if($m!==null&&(float)$m<edu_predictive_critical_threshold())$criticalPeriods++;for($i=count($means)-1;$i>=0;$i--){if($means[$i]!==null&&(float)$means[$i]<edu_predictive_critical_threshold())$trailing++;else break;}
    $prior=edu_course_risk_prior_year_features($conn,$studentId,$schoolId,$yearId,(string)($context['course_name']??'Curso'),$bimester,3);
    return array_merge($current,[
        'course_trend'=>($currentMean!==null&&$previousMean!==null)?$currentMean-$previousMean:0.0,
        'previous_course_available'=>$previousMean!==null?1.0:0.0,'previous_course_mean'=>$previousMean,
        'previous_course_critical'=>$previousMean!==null&&$previousMean<edu_predictive_critical_threshold()?1.0:0.0,
        'current_course_critical'=>$currentMean!==null&&$currentMean<edu_predictive_critical_threshold()?1.0:0.0,
        'consecutive_critical_periods'=>(float)$trailing,'critical_period_rate_same_year'=>$means?$criticalPeriods/count($means):null,
        'same_year_mean'=>$means?array_sum($means)/count($means):null,'same_year_min_mean'=>$means?min($means):null,
        'same_year_course_slope'=>edu_course_risk_slope($means),'same_year_periods_available'=>(float)count($history),
        'attendance_trend_same_year'=>($current['attendance_rate_30d']!==null&&$attendancePrevious!==null)?(float)$current['attendance_rate_30d']-(float)$attendancePrevious:null,
        'student_vs_class_mean'=>($currentMean!==null&&$current['class_course_mean_current']!==null)?$currentMean-(float)$current['class_course_mean_current']:null,
        '_history'=>$history,
    ],$prior);
}

function edu_course_risk_target_next(mysqli $conn,int $studentId,int $schoolId,int $yearId,int $sourceBimester,int $courseId): ?int {
    if($sourceBimester<1||$sourceBimester>=4)return null;$target=$sourceBimester+1;$closure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$target);if(empty($closure['closed']))return null;$ctx=edu_course_risk_context_for_course($conn,$studentId,$schoolId,$yearId,$target,$courseId);if(!$ctx)return null;$m=edu_course_risk_period_metrics($conn,$studentId,$schoolId,$yearId,$target,$ctx,$closure['date']??null,30);if($m['course_mean_current']===null)return null;return(float)$m['course_mean_current']<edu_predictive_critical_threshold()?1:0;
}

function edu_course_risk_score(array $features,array $model): array {
    $z=(float)$model['intercept'];$contrib=[];$fill=(array)($model['imputer']['fill']??[]);
    foreach((array)$model['features'] as $f){$raw=$features[$f]??null;$missing=$raw===null||$raw===''||!is_numeric($raw);$mean=(float)($model['scaler']['mean'][$f]??0);$x=$missing?(float)($fill[$f]??$mean):(float)$raw;$scale=(float)($model['scaler']['scale'][$f]??1);if(abs($scale)<1e-12)$scale=1;$std=($x-$mean)/$scale;$coef=(float)($model['coefficients'][$f]??0);$c=$coef*$std;$z+=$c;$contrib[$f]=['feature'=>$f,'value'=>$x,'missing'=>$missing,'contribution'=>$c];}
    $cal=(array)$model['calibration'];$p=edu_predictive_sigmoid((float)$cal['intercept']+(float)$cal['coefficient']*$z);$medium=(float)($model['risk_thresholds']['medium']??.40);$high=(float)($model['risk_thresholds']['high']??.70);$level=$p>=$high?'Alto':($p>=$medium?'Medio':'Bajo');
    uasort($contrib,static fn($a,$b)=>abs($b['contribution'])<=>abs($a['contribution']));return['probability'=>$p,'level'=>$level,'contributions'=>array_values($contrib)];
}

function edu_course_risk_reason_labels(array $f): array {
    $reasons=[];$mean=$f['course_mean_current']??null;$threshold=edu_predictive_critical_threshold();
    if($mean!==null){if((float)$mean<$threshold)$reasons[]='El promedio actual del curso está en zona crítica ('.number_format((float)$mean,1).').';elseif((float)$mean<=$threshold+1.5)$reasons[]='El promedio actual está muy cerca del límite crítico ('.number_format((float)$mean,1).' frente a '.$threshold.').';}
    if((float)($f['consecutive_critical_periods']??0)>=2)$reasons[]='El curso acumula '.(int)$f['consecutive_critical_periods'].' bimestres consecutivos en zona crítica.';
    if((float)($f['previous_course_available']??0)>=.5){$trend=(float)($f['course_trend']??0);if($trend<=-1)$reasons[]='El rendimiento del curso bajó '.number_format(abs($trend),1).' punto(s) respecto al bimestre anterior.';elseif($trend>0&&$mean!==null&&(float)$mean<$threshold)$reasons[]='Existe una mejora de '.number_format($trend,1).' punto(s), pero el promedio aún permanece en zona crítica.';elseif(abs($trend)<.25&&$mean!==null&&(float)$mean<=$threshold+1.5)$reasons[]='El rendimiento se mantiene sin mejora y cerca del límite crítico.';}
    if(($f['evaluation_trend_current']??null)!==null&&(float)$f['evaluation_trend_current']<-.5)$reasons[]='Las evaluaciones del bimestre muestran una tendencia descendente.';
    if((float)($f['consecutive_low_evaluations_current']??0)>=2)$reasons[]='Las últimas '.(int)$f['consecutive_low_evaluations_current'].' evaluaciones registradas se mantienen en nivel bajo.';
    if(($f['critical_competency_rate_current']??null)!==null&&(float)$f['critical_competency_rate_current']>=.5)$reasons[]='La mitad o más de las competencias evaluadas del curso están en zona crítica.';
    if(($f['prior_years_critical_rate']??null)!==null&&(float)$f['prior_years_critical_rate']>=.5&&($f['prior_years_periods_available']??0)>=2)$reasons[]='El historial de años anteriores muestra dificultades frecuentes en este mismo curso.';
    if(($f['same_year_course_slope']??null)!==null&&(float)$f['same_year_course_slope']<-.5)$reasons[]='La tendencia de este curso durante el año es descendente.';
    if(($f['low_grade_rate_current']??null)!==null&&(float)$f['low_grade_rate_current']>=.4)$reasons[]='Una proporción importante de sus calificaciones del curso está en nivel bajo.';
    if(($f['missing_grade_rate_current']??null)!==null&&(float)$f['missing_grade_rate_current']>=.25)$reasons[]='Hay evaluaciones/competencias sin calificación registrada; no se consideran como cero.';
    if(($f['attendance_rate_30d']??null)!==null&&(float)$f['attendance_rate_30d']<85)$reasons[]='La asistencia reciente es baja ('.number_format((float)$f['attendance_rate_30d'],1).'%).';
    if(($f['absent_30d']??null)!==null&&(float)$f['absent_30d']>=3)$reasons[]='Registra '.(int)$f['absent_30d'].' ausencia(s) en los 30 días previos al cierre.';
    if(($f['late_30d']??null)!==null&&(float)$f['late_30d']>=3)$reasons[]='Registra '.(int)$f['late_30d'].' tardanza(s) en los 30 días previos al cierre.';
    if(($f['class_students_critical_rate']??null)!==null&&(float)$f['class_students_critical_rate']>=.35)$reasons[]='El bajo rendimiento también aparece en una parte importante del aula en este curso.';
    return array_slice(array_values(array_unique($reasons)),0,7);
}

function edu_course_risk_history_across_years(mysqli $conn,int $studentId,int $schoolId,string $courseName,int $maxYears=4): array {
    $years=[];$stmt=$conn->prepare('SELECT id,year,start_date,end_date FROM academic_year WHERE school_id=? ORDER BY start_date DESC,id DESC LIMIT ?');if(!$stmt)return[];$stmt->bind_param('ii',$schoolId,$maxYears);$stmt->execute();$res=$stmt->get_result();while($y=$res->fetch_assoc())$years[]=$y;$stmt->close();$needle=edu_course_risk_norm($courseName);$out=[];
    foreach(array_reverse($years) as $year){$yearId=(int)$year['id'];$periods=[];for($b=1;$b<=4;$b++){foreach(edu_course_risk_contexts_from_grades($conn,$studentId,$schoolId,$yearId,$b) as $ctx){if(edu_course_risk_norm((string)$ctx['course_name'])!==$needle)continue;$closure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$b);$m=edu_course_risk_period_metrics($conn,$studentId,$schoolId,$yearId,$b,$ctx,$closure['date']??null,30);if($m['course_mean_current']!==null)$periods[]=['bimester'=>$b,'label'=>edu_predictive_bimester_label($b),'mean'=>$m['course_mean_current'],'low_rate'=>$m['low_grade_rate_current']];break;}}if($periods)$out[]=['academic_year_id'=>$yearId,'year'=>(string)($year['year']??$yearId),'periods'=>$periods];}
    return$out;
}

function edu_course_risk_general_priority(array $courses): array {
    $high=0;$medium=0;$low=0;$max=null;$maxCourse=null;foreach($courses as $c){$level=(string)($c['level']??'Bajo');if($level==='Alto')$high++;elseif($level==='Medio')$medium++;else$low++;if($max===null||(float)$c['probability']>$max){$max=(float)$c['probability'];$maxCourse=(string)$c['course_name'];}}
    $level=$high>0?'Alto':($medium>0?'Medio':'Bajo');return['level'=>$level,'high_courses'=>$high,'medium_courses'=>$medium,'low_courses'=>$low,'attention_courses'=>$high+$medium,'max_course_probability'=>$max,'max_course_name'=>$maxCourse];
}

function edu_course_risk_student_prediction(mysqli $conn,array $actor,int $studentId,?int $requestedBimester=null,bool $includeHistory=false): array {
    $schoolId=(int)($actor['school_id']??0);if($schoolId<=0||$studentId<=0)return['available'=>false,'reason'=>'student'];
    $stmt=$conn->prepare("SELECT id,name,nivel,grado,COALESCE(NULLIF(TRIM(seccion),''),'U') seccion FROM student WHERE id=? AND school_id=? LIMIT 1");if(!$stmt)return['available'=>false,'reason'=>'student'];$stmt->bind_param('ii',$studentId,$schoolId);$stmt->execute();$student=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$student)return['available'=>false,'reason'=>'student'];
    $year=edu_predictive_academic_year($conn,$schoolId,null);if(!$year)return['available'=>false,'reason'=>'year'];$yearId=(int)$year['id'];$b=$requestedBimester??edu_predictive_latest_closed_bimester($conn,$schoolId,$yearId);if(!$b||$b>=4)return['available'=>false,'reason'=>'period'];$closure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$b);if(empty($closure['closed'])||empty($closure['date']))return['available'=>false,'reason'=>'period'];
    $model=edu_course_risk_model_load();if(empty($model['available']))return['available'=>false,'reason'=>'model','model'=>$model,'student'=>$student];
    $contexts=edu_course_risk_live_contexts($conn,$student,$schoolId,$yearId);if(!$contexts)$contexts=edu_course_risk_contexts_from_grades($conn,$studentId,$schoolId,$yearId,$b);$courses=[];
    foreach($contexts as $ctx){$f=edu_course_risk_feature_vector($conn,$studentId,$schoolId,$yearId,$b,$ctx,(string)$closure['date'],30);if((int)($f['expected_cells']??0)===0)continue;$score=edu_course_risk_score($f,$model);$course=['course_id'=>(int)$ctx['course_id'],'course_name'=>(string)$ctx['course_name'],'probability'=>$score['probability'],'level'=>$score['level'],'features'=>$f,'reasons'=>edu_course_risk_reason_labels($f),'history_same_year'=>$f['_history']??[]];if($includeHistory)$course['history_across_years']=edu_course_risk_history_across_years($conn,$studentId,$schoolId,(string)$ctx['course_name']);$courses[]=$course;}
    usort($courses,static fn($a,$b)=>$b['probability']<=>$a['probability']);if(!$courses)return['available'=>false,'reason'=>'no_courses','student'=>$student];
    return['available'=>true,'student'=>$student,'academic_year_id'=>$yearId,'academic_year_label'=>$year['year']??null,'source_bimester'=>$b,'source_bimester_label'=>edu_predictive_bimester_label($b),'target_bimester'=>$b+1,'target_bimester_label'=>edu_predictive_bimester_label($b+1),'base_closed_at'=>$closure['date'],'courses'=>$courses,'general'=>edu_course_risk_general_priority($courses),'model'=>$model];
}
