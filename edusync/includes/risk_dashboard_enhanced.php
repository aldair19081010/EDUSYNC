<?php
require_once __DIR__.'/predictive_interventions.php';

/** Datos auxiliares del panel de Alerta Temprana Inteligente. */
function edu_risk_dashboard_filter_options(mysqli $conn,array $actor): array {
    $schoolId=(int)($actor['school_id']??0);$levels=[];$grades=[];$sections=[];
    if($schoolId<=0||!edu_predictive_table_exists($conn,'student'))return['levels'=>[],'grades'=>[],'sections'=>[]];
    $stmt=$conn->prepare("SELECT DISTINCT TRIM(nivel) nivel,CAST(grado AS UNSIGNED) grado,UPPER(TRIM(COALESCE(seccion,''))) seccion FROM student WHERE school_id=? AND LOWER(TRIM(COALESCE(status,'Activo'))) IN ('activo','active') ORDER BY FIELD(TRIM(nivel),'Inicial','Primaria','Secundaria'),CAST(grado AS UNSIGNED),seccion");
    if(!$stmt)return['levels'=>[],'grades'=>[],'sections'=>[]];
    $stmt->bind_param('i',$schoolId);$stmt->execute();$res=$stmt->get_result();
    while($row=$res->fetch_assoc()){
        $level=trim((string)($row['nivel']??''));$grade=(int)($row['grado']??0);$section=trim((string)($row['seccion']??''));
        if($level!==''&&!in_array($level,$levels,true))$levels[]=$level;
        if($level!==''&&$grade>0){if(!isset($grades[$level]))$grades[$level]=[];if(!in_array($grade,$grades[$level],true))$grades[$level][]=$grade;}
        if($level!==''&&$grade>0&&$section!==''){$key=$level.'|'.$grade;if(!isset($sections[$key]))$sections[$key]=[];if(!in_array($section,$sections[$key],true))$sections[$key][]=$section;}
    }
    $stmt->close();foreach($grades as &$items)sort($items,SORT_NUMERIC);unset($items);foreach($sections as &$items)sort($items,SORT_NATURAL);unset($items);
    return['levels'=>$levels,'grades'=>$grades,'sections'=>$sections];
}

function edu_risk_dashboard_data_enhanced(mysqli $conn,array $actor,array $filters=[]): array {
    if((int)($actor['type']??0)!==1)return['ok'=>false,'message'=>'Este panel está disponible únicamente para administración.'];
    $model=edu_predictive_model_load();$academicFilters=[];foreach(['level','grade','section'] as $key)if(!empty($filters[$key]))$academicFilters[$key]=$filters[$key];
    $nameSearch=trim((string)($filters['search']??''));$riskLevel=trim((string)($filters['risk_level']??''));if(!in_array($riskLevel,['Alto','Medio','Bajo'],true))$riskLevel='';
    $requestedBimester=!empty($filters['bimestre'])?(int)$filters['bimestre']:null;
    $students=edu_predictive_students($conn,$actor,$academicFilters,$nameSearch,200);$openMap=edu_risk_open_counts_by_student($conn,$actor);
    $summary=['Alto'=>0,'Medio'=>0,'Bajo'=>0];$predictions=[];$factorCounts=[];$evaluatedBeforeRiskFilter=0;$missingAttendance=0;
    if(!empty($model['available'])){
        foreach($students as $student){
            $prediction=edu_predictive_student_prediction($conn,$actor,(int)$student['id'],$requestedBimester);if(empty($prediction['available']))continue;
            $evaluatedBeforeRiskFilter++;$summary[$prediction['level']]++;if(empty($prediction['data_quality']['attendance_available']))$missingAttendance++;
            if($riskLevel!==''&&$prediction['level']!==$riskLevel)continue;
            foreach(array_slice((array)($prediction['explanation']['raises']??[]),0,3) as $factor){$label=(string)($factor['label']??$factor['feature']??'Factor');$factorCounts[$label]=($factorCounts[$label]??0)+1;}
            $predictions[]=[
                'student'=>$student,'probability'=>(float)$prediction['probability'],'level'=>$prediction['level'],
                'bimester'=>$prediction['bimester'],'bimester_label'=>$prediction['bimester_label']??edu_predictive_bimester_label((int)$prediction['bimester']),
                'target_bimester'=>$prediction['target_bimester'],'target_bimester_label'=>$prediction['target_bimester_label']??edu_predictive_bimester_label((int)$prediction['target_bimester']),
                'base_closed_at'=>$prediction['base_closed_at']??null,'factors'=>array_slice((array)($prediction['explanation']['raises']??[]),0,3),
                'attendance_available'=>!empty($prediction['data_quality']['attendance_available']),'attendance_records'=>(int)($prediction['data_quality']['attendance_records']??0),
                'open_interventions'=>$openMap[(int)$student['id']]??0,'suggested'=>edu_risk_suggest_intervention($prediction),
                'details'=>[
                    'grade_mean_current'=>$prediction['features']['grade_mean_current']??null,
                    'grade_trend'=>$prediction['features']['grade_trend']??null,
                    'critical_records_current'=>$prediction['features']['critical_records_current']??null,
                    'critical_courses_current'=>$prediction['features']['critical_courses_current']??null,
                    'attendance_rate_30d'=>$prediction['features']['attendance_rate_30d']??null,
                    'late_30d'=>$prediction['features']['late_30d']??null,
                    'absent_30d'=>$prediction['features']['absent_30d']??null,
                ],
            ];
        }
    }
    usort($predictions,static fn($a,$b)=>$b['probability']<=>$a['probability']);$totalMatched=count($predictions);$predictions=array_slice($predictions,0,100);
    arsort($factorCounts);$factors=[];foreach(array_slice($factorCounts,0,8,true) as $label=>$count)$factors[]=['label'=>$label,'count'=>$count];

    $interventionFilters=$academicFilters;if(!empty($filters['intervention_status']))$interventionFilters['status']=$filters['intervention_status'];
    $interventions=edu_risk_list_interventions($conn,$actor,$interventionFilters,100);
    if($nameSearch!=='')$interventions=array_values(array_filter($interventions,static fn($row)=>stripos((string)($row['student_name']??''),$nameSearch)!==false));
    $intSummary=['Pendiente'=>0,'En proceso'=>0,'Completada'=>0,'Cancelada'=>0,'Seguimientos vencidos'=>0];
    $improved=0;$measured=0;$deltaSum=0.0;$today=date('Y-m-d');foreach($interventions as $row){$status=(string)$row['status'];if(isset($intSummary[$status]))$intSummary[$status]++;if(in_array($status,['Pendiente','En proceso'],true)&&!empty($row['followup_date'])&&$row['followup_date']<$today)$intSummary['Seguimientos vencidos']++;if($status==='Completada'&&$row['risk_probability']!==null&&$row['post_risk_probability']!==null){$delta=(float)$row['risk_probability']-(float)$row['post_risk_probability'];$deltaSum+=$delta;$measured++;if($delta>0)$improved++;}}

    $first=$predictions[0]??null;$thresholds=(array)($model['risk_thresholds']??[]);
    return[
        'ok'=>true,
        'model'=>['available'=>!empty($model['available']),'reason'=>$model['reason']??null,'created_at'=>$model['created_at']??null,'schema_version'=>$model['schema_version']??null,'model_variant'=>$model['model_variant']??'v4','metrics'=>(array)($model['metrics']['holdout']??[]),'training'=>$model['training']??[],'thresholds'=>['medium'=>(float)($thresholds['medium']??0.40),'high'=>(float)($thresholds['high']??0.70)]],
        'period'=>['base'=>$first['bimester_label']??null,'target'=>$first['target_bimester_label']??null,'cutoff'=>$first['base_closed_at']??null],
        'filters'=>edu_risk_dashboard_filter_options($conn,$actor),
        'active_filters'=>['search'=>$nameSearch,'level'=>$filters['level']??'','grade'=>$filters['grade']??'','section'=>$filters['section']??'','risk_level'=>$riskLevel,'bimestre'=>$requestedBimester,'intervention_status'=>$filters['intervention_status']??''],
        'summary'=>['evaluated'=>$evaluatedBeforeRiskFilter,'matched'=>$totalMatched,'high'=>$summary['Alto'],'medium'=>$summary['Medio'],'low'=>$summary['Bajo'],'students_considered'=>count($students),'attendance_missing'=>$missingAttendance],
        'predictions'=>$predictions,'factors'=>$factors,'interventions'=>$interventions,'intervention_summary'=>$intSummary,
        'effectiveness'=>['measured'=>$measured,'improved'=>$improved,'average_probability_reduction'=>$measured>0?$deltaSum/$measured:null],
        'migration_ready'=>edu_risk_interventions_ready($conn),
    ];
}
