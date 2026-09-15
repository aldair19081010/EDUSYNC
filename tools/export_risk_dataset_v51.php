<?php
/**
 * Exporta dataset candidato v5.1.
 *
 * Mantiene el objetivo temporal N -> N+1 y añade solo trayectoria del mismo
 * año académico. La historia de años anteriores se reporta como contexto,
 * pero no se usa como predictor en v5.1.
 */
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Este script solo puede ejecutarse por CLI.\n");exit(1);}

require_once __DIR__.'/../edusync/db_connect.php';
require_once __DIR__.'/../edusync/includes/predictive_longitudinal_v51.php';

$options=getopt('',['school:','year::','output::','allow-estimated-cutoffs::','attendance-window::']);
$schoolId=(int)($options['school']??0);
$yearOption=isset($options['year'])&&$options['year']!==false&&$options['year']!==''?(int)$options['year']:null;
$output=(string)($options['output']??(__DIR__.'/../storage/risk_dataset_v51.csv'));
$allowEstimated=in_array(strtolower((string)($options['allow-estimated-cutoffs']??'0')),['1','true','yes','on'],true);
$attendanceWindow=max(7,min(120,(int)($options['attendance-window']??30)));

if($schoolId<=0){fwrite(STDERR,"Falta --school=<ID del colegio>.\n");exit(1);}
if(!isset($conn)||!($conn instanceof mysqli)){fwrite(STDERR,"No se pudo abrir la conexión MySQL de EduSync.\n");exit(1);}

function risk_v51_years(mysqli $conn,int $schoolId,?int $yearId): array {
    $years=edu_predictive_longitudinal_years($conn,$schoolId);
    if($yearId===null||$yearId<=0)return$years;
    return array_values(array_filter($years,static fn($year)=>(int)$year['id']===$yearId));
}
function risk_v51_students(mysqli $conn,int $schoolId,int $yearId,int $bimester): array {
    foreach(['student','evaluation_grades','evaluations','teacher_courses'] as $table)if(!edu_predictive_table_exists($conn,$table))return[];
    $where=['s.school_id=?','CAST(e.bimestre AS UNSIGNED)=?'];$types='ii';$params=[$schoolId,$bimester];
    if(edu_predictive_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    $sql='SELECT DISTINCT s.id,s.name,s.nivel,s.grado,s.seccion FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id WHERE '.implode(' AND ',$where).' ORDER BY s.id';
    $stmt=$conn->prepare($sql);if(!$stmt)return[];edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;
}
function risk_v51_nullable($value,int $precision=6){if($value===null||$value==='')return'';return round((float)$value,$precision);}
function risk_v51_estimated_cutoff(array $year,int $bimester): ?string {
    $start=strtotime((string)($year['start_date']??''));$end=strtotime((string)($year['end_date']??''));if(!$start||!$end||$end<=$start)return null;
    return date('Y-m-d',$start+(int)round((($end-$start)/4.0)*$bimester));
}

$years=risk_v51_years($conn,$schoolId,$yearOption);
if(!$years){fwrite(STDERR,"No se encontraron años académicos para ese colegio.\n");exit(1);}
$dir=dirname($output);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)){fwrite(STDERR,"No pude crear el directorio de salida.\n");exit(1);}
$fh=fopen($output,'wb');if(!$fh){fwrite(STDERR,"No pude crear $output.\n");exit(1);}

$headers=[
    'school_id','academic_year_id','student_id','nivel','grado','seccion','bimester','target_bimester','cutoff_date','cutoff_source',
    // baseline v4
    'grade_mean_current','grade_trend','previous_bimester_available','grade_records_current','critical_records_current','critical_courses_current',
    'attendance_rate_30d','late_30d','absent_30d','attendance_records_30d',
    // candidato v5.1: solo trayectoria del año actual
    'grade_trend_same_year','year_periods_available','year_grade_trend','critical_courses_year_mean','attendance_trend_same_year',
    // contexto no predictor
    'previous_academic_year_context_available',
    'target_next_bimester_risk'
];
fputcsv($fh,$headers);

$written=0;$positive=0;$negative=0;$contextPreviousYear=0;$onlyOnePeriod=0;$twoPeriods=0;$threePeriods=0;$missingAttendance=0;$pairs=[];
$skippedBase=0;$skippedTarget=0;$skippedCutoff=0;$skippedTargetData=0;
foreach($years as $year){
    $yearId=(int)$year['id'];
    foreach([1,2,3] as $bimester){
        $baseClosure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$bimester);if(empty($baseClosure['closed'])){$skippedBase++;continue;}
        $targetClosure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$bimester+1);if(empty($targetClosure['closed'])){$skippedTarget++;continue;}
        $cutoff=$baseClosure['date']??null;$cutoffSource='closure:'.(string)($baseClosure['source']??'unknown');
        if(!$cutoff&&$allowEstimated){$cutoff=risk_v51_estimated_cutoff($year,$bimester);if($cutoff)$cutoffSource='estimated_quarter_after_confirmed_closure';}
        if(!$cutoff){$skippedCutoff++;continue;}

        foreach(risk_v51_students($conn,$schoolId,$yearId,$bimester) as $student){
            $studentId=(int)$student['id'];$target=edu_predictive_target_next_bimester($conn,$studentId,$schoolId,$yearId,$bimester);
            if($target===null){$skippedTargetData++;continue;}
            $f=edu_predictive_v51_feature_vector($conn,$studentId,$schoolId,$yearId,$bimester,(string)$cutoff,$attendanceWindow);
            if((int)($f['_grade_records_current']??0)===0)continue;
            $periods=(int)round((float)($f['year_periods_available']??1));
            if($periods<=1)$onlyOnePeriod++;elseif($periods===2)$twoPeriods++;else$threePeriods++;
            if((float)($f['_previous_academic_year_context_available']??0)>=0.5)$contextPreviousYear++;
            if((int)($f['_attendance_records']??0)===0)$missingAttendance++;
            if($target===1)$positive++;else$negative++;
            $pair=$bimester.'->'.($bimester+1);$pairs[$pair]=($pairs[$pair]??0)+1;

            fputcsv($fh,[
                $schoolId,$yearId,$studentId,(string)$student['nivel'],(string)$student['grado'],(string)$student['seccion'],$bimester,$bimester+1,(string)$cutoff,$cutoffSource,
                risk_v51_nullable($f['grade_mean_current']),risk_v51_nullable($f['grade_trend']),risk_v51_nullable($f['previous_bimester_available']),(int)$f['_grade_records_current'],(int)$f['critical_records_current'],(int)$f['critical_courses_current'],
                risk_v51_nullable($f['attendance_rate_30d']),risk_v51_nullable($f['late_30d']),risk_v51_nullable($f['absent_30d']),(int)$f['_attendance_records'],
                risk_v51_nullable($f['grade_trend_same_year']),risk_v51_nullable($f['year_periods_available']),risk_v51_nullable($f['year_grade_trend']),risk_v51_nullable($f['critical_courses_year_mean']),risk_v51_nullable($f['attendance_trend_same_year']),
                risk_v51_nullable($f['_previous_academic_year_context_available']??0),$target
            ]);
            $written++;
        }
    }
}
fclose($fh);

echo "Dataset v5.1 creado: $output\n";
echo "Esquema: v5.1 trayectoria del año actual | estado: candidate_not_active\n";
echo "Filas: $written | positivos: $positive | negativos: $negative\n";
echo "Pares cerrados: ".json_encode($pairs,JSON_UNESCAPED_UNICODE)."\n";
echo "Filas con 1 periodo del año disponible: $onlyOnePeriod\n";
echo "Filas con 2 periodos del año disponibles: $twoPeriods\n";
echo "Filas con 3 periodos del año disponibles: $threePeriods\n";
echo "Filas que además tienen contexto de años anteriores: $contextPreviousYear (NO entra como predictor v5.1)\n";
echo "Filas sin asistencia en la ventana actual: $missingAttendance\n";
echo "Ventana de asistencia: $attendanceWindow días previos al cierre\n";
echo "Criterio crítico: letra C o nota < ".edu_predictive_critical_threshold()."\n";
echo "Bases abiertas omitidas: $skippedBase | objetivos abiertos omitidos: $skippedTarget | sin cutoff seguro: $skippedCutoff | sin datos objetivo: $skippedTargetData\n";
if($allowEstimated)echo "ADVERTENCIA: se permitieron cutoffs estimados; no usar para la comparación final.\n";
