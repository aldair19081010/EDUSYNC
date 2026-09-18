<?php
require_once __DIR__ . '/predictive_risk.php';

/**
 * Ingeniería de variables longitudinales candidata v5.
 *
 * No reemplaza todavía el modelo v4 activo. Construye una trayectoria usando
 * únicamente periodos cerrados disponibles antes o hasta el bimestre base.
 * Puede cruzar años académicos cuando el mismo student_id conserva historial.
 */

function edu_predictive_longitudinal_years(mysqli $conn,int $schoolId): array {
    static $cache=[];
    $key=spl_object_id($conn).':'.$schoolId;
    if(isset($cache[$key]))return$cache[$key];
    if(!edu_predictive_table_exists($conn,'academic_year'))return$cache[$key]=[];
    $stmt=$conn->prepare('SELECT id,school_id,start_date,end_date,is_active FROM academic_year WHERE school_id=? ORDER BY start_date ASC,id ASC');
    if(!$stmt)return$cache[$key]=[];
    $stmt->bind_param('i',$schoolId);$stmt->execute();$res=$stmt->get_result();$rows=[];
    while($row=$res->fetch_assoc())$rows[]=$row;
    $stmt->close();return$cache[$key]=$rows;
}

function edu_predictive_longitudinal_mean(array $values): ?float {
    $clean=[];foreach($values as $value)if($value!==null&&$value!==''&&is_numeric($value))$clean[]=(float)$value;
    return$clean?array_sum($clean)/count($clean):null;
}

function edu_predictive_longitudinal_slope(array $values): ?float {
    $clean=[];foreach($values as $value)if($value!==null&&$value!==''&&is_numeric($value))$clean[]=(float)$value;
    $n=count($clean);if($n<2)return null;
    $meanX=($n-1)/2.0;$meanY=array_sum($clean)/$n;$num=0.0;$den=0.0;
    foreach($clean as $i=>$value){$dx=$i-$meanX;$num+=$dx*($value-$meanY);$den+=$dx*$dx;}
    return$den>0?$num/$den:null;
}

function edu_predictive_longitudinal_std(array $values): ?float {
    $clean=[];foreach($values as $value)if($value!==null&&$value!==''&&is_numeric($value))$clean[]=(float)$value;
    $n=count($clean);if($n<2)return null;$mean=array_sum($clean)/$n;$sum=0.0;
    foreach($clean as $value){$d=$value-$mean;$sum+=$d*$d;}
    return sqrt($sum/$n);
}

function edu_predictive_longitudinal_last_numeric(array $periods,string $field): ?float {
    for($i=count($periods)-1;$i>=0;$i--){$value=$periods[$i][$field]??null;if($value!==null&&$value!==''&&is_numeric($value))return(float)$value;}
    return null;
}

function edu_predictive_longitudinal_recent_numeric(array $periods,string $field,int $limit): array {
    $values=[];
    for($i=count($periods)-1;$i>=0&&count($values)<$limit;$i--){$value=$periods[$i][$field]??null;if($value!==null&&$value!==''&&is_numeric($value))array_unshift($values,(float)$value);}
    return$values;
}

function edu_predictive_longitudinal_derive(array $periods): array {
    $periods=array_values($periods);$count=count($periods);
    if($count===0)return[];
    $current=$periods[$count-1];$prior=array_slice($periods,0,-1);$previous=$prior?$prior[count($prior)-1]:null;
    $recent4=array_slice($periods,max(0,$count-4));$recent3=array_slice($periods,max(0,$count-3));

    $priorMeans=array_map(static fn($p)=>$p['grade_mean']??null,$prior);
    $recentGradeMeans=array_values(array_filter(array_map(static fn($p)=>$p['grade_mean']??null,$recent4),static fn($v)=>$v!==null&&$v!==''&&is_numeric($v)));
    $currentMean=isset($current['grade_mean'])&&is_numeric($current['grade_mean'])?(float)$current['grade_mean']:null;
    $previousMean=$previous!==null&&isset($previous['grade_mean'])&&is_numeric($previous['grade_mean'])?(float)$previous['grade_mean']:null;

    $criticalRecent=[];foreach($recent3 as $p)if(isset($p['critical_courses'])&&is_numeric($p['critical_courses']))$criticalRecent[]=(float)$p['critical_courses'];
    $criticalPriorPeriods=0;foreach($prior as $p)if((float)($p['critical_courses']??0)>0)$criticalPriorPeriods++;

    $priorAttendance=array_slice($prior,max(0,count($prior)-3));
    $attendanceHistorical=edu_predictive_longitudinal_mean(array_map(static fn($p)=>$p['attendance_rate']??null,$priorAttendance));
    $previousAttendance=edu_predictive_longitudinal_last_numeric($prior,'attendance_rate');
    $currentAttendance=isset($current['attendance_rate'])&&is_numeric($current['attendance_rate'])?(float)$current['attendance_rate']:null;

    $yearIds=[];foreach($periods as $p)$yearIds[(int)($p['academic_year_id']??0)]=true;
    $currentYear=(int)($current['academic_year_id']??0);$previousYearHistory=false;
    foreach($prior as $p)if((int)($p['academic_year_id']??0)!==$currentYear){$previousYearHistory=true;break;}

    return[
        'grade_trend_recent'=>($currentMean!==null&&$previousMean!==null)?$currentMean-$previousMean:0.0,
        'previous_period_available'=>$previousMean!==null?1.0:0.0,
        'history_periods_available'=>(float)$count,
        'historical_years_available'=>(float)count(array_filter(array_keys($yearIds),static fn($id)=>(int)$id>0)),
        'previous_academic_year_history'=>$previousYearHistory?1.0:0.0,
        'historical_mean_prior'=>edu_predictive_longitudinal_mean($priorMeans),
        'long_term_grade_trend'=>edu_predictive_longitudinal_slope($recentGradeMeans),
        'grade_volatility_recent'=>edu_predictive_longitudinal_std($recentGradeMeans),
        'critical_courses_recent_3_mean'=>edu_predictive_longitudinal_mean($criticalRecent),
        'critical_period_rate_prior'=>count($prior)>0?$criticalPriorPeriods/count($prior):null,
        'attendance_historical_mean'=>$attendanceHistorical,
        'attendance_trend_recent'=>($currentAttendance!==null&&$previousAttendance!==null)?$currentAttendance-$previousAttendance:null,
        'late_mean_recent_3'=>edu_predictive_longitudinal_mean(array_map(static fn($p)=>$p['late']??null,$recent3)),
        'absent_mean_recent_3'=>edu_predictive_longitudinal_mean(array_map(static fn($p)=>$p['absent']??null,$recent3)),
        '_previous_period_grade_mean'=>$previousMean,
        '_previous_period'=>$previous,
        '_periods'=>$periods,
    ];
}

function edu_predictive_longitudinal_periods(mysqli $conn,int $studentId,int $schoolId,int $sourceYearId,int $sourceBimester,int $attendanceWindowDays=30,?string $sourceCutoffDate=null): array {
    $years=edu_predictive_longitudinal_years($conn,$schoolId);if(!$years)return[];
    $sourceIndex=null;foreach($years as $i=>$year)if((int)$year['id']===$sourceYearId){$sourceIndex=$i;break;}
    if($sourceIndex===null)return[];

    $periods=[];
    foreach($years as $yearIndex=>$year){
        if($yearIndex>$sourceIndex)break;
        $yearId=(int)$year['id'];$maxBimester=$yearIndex===$sourceIndex?$sourceBimester:4;
        foreach(range(1,$maxBimester) as $bimester){
            $closure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$bimester);
            if(empty($closure['closed']))continue;
            $grades=edu_predictive_grade_features($conn,$studentId,$schoolId,$yearId,$bimester);
            if((int)($grades['grade_records']??0)===0||$grades['grade_mean']===null)continue;
            $cutoff=($yearId===$sourceYearId&&$bimester===$sourceBimester&&$sourceCutoffDate)?$sourceCutoffDate:($closure['date']??null);
            $attendance=$cutoff?edu_predictive_attendance_features($conn,$studentId,(string)$cutoff,$attendanceWindowDays):['attendance_rate_30d'=>null,'late_30d'=>null,'absent_30d'=>null,'attendance_records_30d'=>0];
            $periods[]=[
                'academic_year_id'=>$yearId,'academic_year_start'=>$year['start_date']??null,'bimester'=>$bimester,'cutoff_date'=>$cutoff,
                'grade_mean'=>(float)$grades['grade_mean'],'critical_records'=>(float)$grades['critical_records'],'critical_courses'=>(float)$grades['critical_courses'],
                'attendance_rate'=>$attendance['attendance_rate_30d']===null?null:(float)$attendance['attendance_rate_30d'],
                'late'=>$attendance['late_30d']===null?null:(float)$attendance['late_30d'],
                'absent'=>$attendance['absent_30d']===null?null:(float)$attendance['absent_30d'],
                'attendance_records'=>(int)$attendance['attendance_records_30d'],
            ];
        }
    }
    return$periods;
}

function edu_predictive_longitudinal_feature_vector(mysqli $conn,int $studentId,int $schoolId,int $yearId,int $bimester,?string $cutoffDate=null,int $attendanceWindowDays=30): array {
    $base=edu_predictive_feature_vector($conn,$studentId,$schoolId,$yearId,$bimester,$cutoffDate,$attendanceWindowDays);
    $periods=edu_predictive_longitudinal_periods($conn,$studentId,$schoolId,$yearId,$bimester,$attendanceWindowDays,$cutoffDate);
    $derived=edu_predictive_longitudinal_derive($periods);
    return array_merge($base,$derived,[
        '_longitudinal_period_count'=>count($periods),
        '_longitudinal_periods'=>$periods,
    ]);
}
