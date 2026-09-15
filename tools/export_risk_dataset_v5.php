<?php
/**
 * Exporta dataset longitudinal candidato v5.
 *
 * Mantiene las columnas v4 para comparación y agrega variables de trayectoria
 * construidas solo con periodos cerrados disponibles hasta el bimestre base.
 */
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Este script solo puede ejecutarse por CLI.\n");exit(1);}

require_once __DIR__.'/../edusync/db_connect.php';
require_once __DIR__.'/../edusync/includes/predictive_longitudinal.php';

$options=getopt('',['school:','year::','output::','allow-estimated-cutoffs::','attendance-window::']);
$schoolId=(int)($options['school']??0);
$yearOption=isset($options['year'])&&$options['year']!==false&&$options['year']!==''?(int)$options['year']:null;
$output=(string)($options['output']??(__DIR__.'/../storage/risk_dataset_v5.csv'));
$allowEstimated=in_array(strtolower((string)($options['allow-estimated-cutoffs']??'0')),['1','true','yes','on'],true);
$attendanceWindow=max(7,min(120,(int)($options['attendance-window']??30)));

if($schoolId<=0){fwrite(STDERR,"Falta --school=<ID del colegio>.\n");exit(1);}
if(!isset($conn)||!($conn instanceof mysqli)){fwrite(STDERR,"No se pudo abrir la conexión MySQL de EduSync.\n");exit(1);}

function risk_v5_source_years(mysqli $conn,int $schoolId,?int $yearId): array {
    $years=edu_predictive_longitudinal_years($conn,$schoolId);
    if($yearId===null||$yearId<=0)return$years;
    return array_values(array_filter($years,static fn($year)=>(int)$year['id']===$yearId));
}
function risk_v5_students(mysqli $conn,int $schoolId,int $yearId,int $bimester): array {
    foreach(['student','evaluation_grades','evaluations','teacher_courses'] as $table)if(!edu_predictive_table_exists($conn,$table))return[];
    $where=['s.school_id=?','CAST(e.bimestre AS UNSIGNED)=?'];$types='ii';$params=[$schoolId,$bimester];
    if(edu_predictive_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    $sql='SELECT DISTINCT s.id,s.name,s.nivel,s.grado,s.seccion FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id WHERE '.implode(' AND ',$where).' ORDER BY s.id';
    $stmt=$conn->prepare($sql);if(!$stmt)return[];edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;
}
function risk_v5_nullable($value,int $precision=6){if($value===null||$value==='')return'';return round((float)$value,$precision);}
function risk_v5_estimated_cutoff(array $year,int $bimester): ?string {
    $start=strtotime((string)($year['start_date']??''));$end=strtotime((string)($year['end_date']??''));if(!$start||!$end||$end<=$start)return null;
    return date('Y-m-d',$start+(int)round((($end-$start)/4.0)*$bimester));
}

$sourceYears=risk_v5_source_years($conn,$schoolId,$yearOption);
if(!$sourceYears){fwrite(STDERR,"No se encontraron años académicos fuente para ese colegio.\n");exit(1);}
$dir=dirname($output);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)){fwrite(STDERR,"No pude crear el directorio de salida.\n");exit(1);}
$fh=fopen($output,'wb');if(!$fh){fwrite(STDERR,"No pude crear $output.\n");exit(1);}

$headers=[
    'school_id','academic_year_id','student_id','nivel','grado','seccion','bimester','target_bimester','cutoff_date','cutoff_source',
    // columnas v4 conservadas como baseline
    'grade_mean_current','grade_mean_previous_observed','grade_trend','previous_bimester_available','grade_records_current',
    'critical_records_current','critical_courses_current','attendance_rate_30d','late_30d','absent_30d','attendance_records_30d',
    // variables longitudinales v5
    'grade_trend_recent','previous_period_available','history_periods_available','historical_years_available','previous_academic_year_history',
    'historical_mean_prior','long_term_grade_trend','grade_volatility_recent','critical_courses_recent_3_mean','critical_period_rate_prior',
    'attendance_historical_mean','attendance_trend_recent','late_mean_recent_3','absent_mean_recent_3',
    'target_next_bimester_risk'
];
fputcsv($fh,$headers);

$written=0;$positive=0;$negative=0;$withPrior=0;$withPriorYear=0;$currentOnly=0;$missingAttendance=0;$pairs=[];$skippedBase=0;$skippedTarget=0;$skippedCutoff=0;$skippedTargetData=0;
foreach($sourceYears as $year){
    $yearId=(int)$year['id'];
    foreach([1,2,3] as $bimester){
        $baseClosure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$bimester);if(empty($baseClosure['closed'])){$skippedBase++;continue;}
        $targetClosure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$bimester+1);if(empty($targetClosure['closed'])){$skippedTarget++;continue;}
        $cutoff=$baseClosure['date']??null;$cutoffSource='closure:'.(string)($baseClosure['source']??'unknown');
        if(!$cutoff&&$allowEstimated){$cutoff=risk_v5_estimated_cutoff($year,$bimester);if($cutoff)$cutoffSource='estimated_quarter_after_confirmed_closure';}
        if(!$cutoff){$skippedCutoff++;continue;}

        foreach(risk_v5_students($conn,$schoolId,$yearId,$bimester) as $student){
            $studentId=(int)$student['id'];$target=edu_predictive_target_next_bimester($conn,$studentId,$schoolId,$yearId,$bimester);
            if($target===null){$skippedTargetData++;continue;}
            $f=edu_predictive_longitudinal_feature_vector($conn,$studentId,$schoolId,$yearId,$bimester,(string)$cutoff,$attendanceWindow);
            if((int)($f['_grade_records_current']??0)===0)continue;
            if((int)($f['_attendance_records']??0)===0)$missingAttendance++;
            if((float)($f['previous_period_available']??0)>=0.5)$withPrior++;else$currentOnly++;
            if((float)($f['previous_academic_year_history']??0)>=0.5)$withPriorYear++;
            if($target===1)$positive++;else$negative++;
            $pair=$bimester.'->'.($bimester+1);$pairs[$pair]=($pairs[$pair]??0)+1;

            fputcsv($fh,[
                $schoolId,$yearId,$studentId,(string)$student['nivel'],(string)$student['grado'],(string)$student['seccion'],$bimester,$bimester+1,(string)$cutoff,$cutoffSource,
                risk_v5_nullable($f['grade_mean_current']),risk_v5_nullable($f['_grade_mean_previous_observed']??null),risk_v5_nullable($f['grade_trend']),risk_v5_nullable($f['previous_bimester_available']),
                (int)$f['_grade_records_current'],(int)$f['critical_records_current'],(int)$f['critical_courses_current'],risk_v5_nullable($f['attendance_rate_30d']),risk_v5_nullable($f['late_30d']),risk_v5_nullable($f['absent_30d']),(int)$f['_attendance_records'],
                risk_v5_nullable($f['grade_trend_recent']),risk_v5_nullable($f['previous_period_available']),risk_v5_nullable($f['history_periods_available']),risk_v5_nullable($f['historical_years_available']),risk_v5_nullable($f['previous_academic_year_history']),
                risk_v5_nullable($f['historical_mean_prior']),risk_v5_nullable($f['long_term_grade_trend']),risk_v5_nullable($f['grade_volatility_recent']),risk_v5_nullable($f['critical_courses_recent_3_mean']),risk_v5_nullable($f['critical_period_rate_prior']),
                risk_v5_nullable($f['attendance_historical_mean']),risk_v5_nullable($f['attendance_trend_recent']),risk_v5_nullable($f['late_mean_recent_3']),risk_v5_nullable($f['absent_mean_recent_3']),$target
            ]);
            $written++;
        }
    }
}
fclose($fh);

echo "Dataset longitudinal creado: $output\n";
echo "Esquema candidato: v5 longitudinal (v4 permanece activo)\n";
echo "Filas: $written | positivos: $positive | negativos: $negative\n";
echo "Pares cerrados: ".json_encode($pairs,JSON_UNESCAPED_UNICODE)."\n";
echo "Filas con periodo previo utilizable: $withPrior\n";
echo "Filas con historia de un año académico anterior: $withPriorYear\n";
echo "Filas con solo el periodo actual disponible: $currentOnly\n";
echo "Filas sin asistencia en la ventana actual: $missingAttendance\n";
echo "Ventana de asistencia por periodo: $attendanceWindow días previos a cada cierre\n";
echo "Criterio crítico: letra C o nota < ".edu_predictive_critical_threshold()."\n";
echo "Bases abiertas omitidas: $skippedBase | objetivos abiertos omitidos: $skippedTarget | sin cutoff seguro: $skippedCutoff | sin datos objetivo: $skippedTargetData\n";
if($allowEstimated)echo "ADVERTENCIA: se permitieron cutoffs estimados; no usar esta opción para la comparación final de tesis.\n";
if($written<100)echo "ADVERTENCIA: conjunto longitudinal pequeño; interpretar con cautela.\n";
