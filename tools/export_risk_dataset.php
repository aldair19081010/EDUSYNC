<?php
/**
 * Exporta un dataset histórico temporalmente consistente.
 *
 * Cada fila representa:
 *   información disponible al CIERRE del bimestre N
 *                       -> resultado observado al CIERRE del bimestre N+1.
 *
 * Un bimestre con notas parciales no sirve ni como base cerrada ni como target
 * histórico completo. Esto evita etiquetar como resultado final un periodo que
 * todavía está en proceso.
 *
 * Uso:
 *   php tools/export_risk_dataset.php --school=1 --output=storage/risk_dataset.csv
 *   php tools/export_risk_dataset.php --school=1 --year=3 --output=storage/risk_dataset.csv
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR,"Este script solo puede ejecutarse por CLI.\n"); exit(1); }

require_once __DIR__ . '/../edusync/db_connect.php';
require_once __DIR__ . '/../edusync/includes/predictive_risk.php';

$options=getopt('',['school:','year::','output::','allow-estimated-cutoffs::','attendance-window::']);
$schoolId=(int)($options['school']??0);
$yearOption=isset($options['year'])&&$options['year']!==false&&$options['year']!==''?(int)$options['year']:null;
$output=(string)($options['output']??(__DIR__.'/../storage/risk_dataset.csv'));
$allowEstimated=in_array(strtolower((string)($options['allow-estimated-cutoffs']??'0')),['1','true','yes','on'],true);
$attendanceWindow=max(7,min(120,(int)($options['attendance-window']??30)));

if($schoolId<=0){fwrite(STDERR,"Falta --school=<ID del colegio>.\n");exit(1);}
if(!isset($conn)||!($conn instanceof mysqli)){fwrite(STDERR,"No se pudo abrir la conexión MySQL de EduSync.\n");exit(1);}

function risk_export_years(mysqli $conn,int $schoolId,?int $yearId): array {
    if(!edu_predictive_table_exists($conn,'academic_year'))return[];
    if($yearId!==null&&$yearId>0){
        $stmt=$conn->prepare('SELECT id,school_id,start_date,end_date,is_active FROM academic_year WHERE school_id=? AND id=? LIMIT 1');
        if(!$stmt)return[];$stmt->bind_param('ii',$schoolId,$yearId);
    }else{
        $stmt=$conn->prepare('SELECT id,school_id,start_date,end_date,is_active FROM academic_year WHERE school_id=? ORDER BY start_date ASC,id ASC');
        if(!$stmt)return[];$stmt->bind_param('i',$schoolId);
    }
    $stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;
}

function risk_export_students(mysqli $conn,int $schoolId,int $yearId,int $bimester): array {
    foreach(['student','evaluation_grades','evaluations','teacher_courses'] as $table)if(!edu_predictive_table_exists($conn,$table))return[];
    $where=['s.school_id=?','CAST(e.bimestre AS UNSIGNED)=?'];$types='ii';$params=[$schoolId,$bimester];
    if(edu_predictive_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    $sql='SELECT DISTINCT s.id,s.name,s.nivel,s.grado,s.seccion FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id WHERE '.implode(' AND ',$where).' ORDER BY s.id';
    $stmt=$conn->prepare($sql);if(!$stmt)return[];edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;
}

function risk_export_nullable_number($value,int $precision=6){
    if($value===null||$value==='')return'';
    return round((float)$value,$precision);
}

function risk_export_estimated_cutoff(array $year,int $bimester): ?string {
    $start=strtotime((string)($year['start_date']??''));$end=strtotime((string)($year['end_date']??''));
    if(!$start||!$end||$end<=$start)return null;
    $span=$end-$start;return date('Y-m-d',$start+(int)round(($span/4.0)*$bimester));
}

$years=risk_export_years($conn,$schoolId,$yearOption);
if(!$years){fwrite(STDERR,"No se encontraron años académicos para ese colegio.\n");exit(1);}

$dir=dirname($output);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)){fwrite(STDERR,"No pude crear el directorio de salida.\n");exit(1);}
$fh=fopen($output,'wb');if(!$fh){fwrite(STDERR,"No pude crear $output.\n");exit(1);}

$headers=['school_id','academic_year_id','student_id','nivel','grado','seccion','bimester','target_bimester','cutoff_date','cutoff_source','grade_mean_current','grade_mean_previous','grade_trend','grade_records_current','critical_records_current','critical_courses_current','attendance_rate_30d','late_30d','absent_30d','attendance_records_30d','target_next_bimester_risk'];
fputcsv($fh,$headers);

$written=0;$skippedBaseOpen=0;$skippedTargetOpen=0;$skippedCutoff=0;$skippedTargetData=0;$missingAttendance=0;$positive=0;$negative=0;$pairCounts=[];
foreach($years as $year){
    $yearId=(int)$year['id'];
    foreach([1,2,3] as $bimester){
        $baseClosure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$bimester);
        if(empty($baseClosure['closed'])){$skippedBaseOpen++;continue;}

        $targetClosure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$bimester+1);
        if(empty($targetClosure['closed'])){$skippedTargetOpen++;continue;}

        $cutoffDate=$baseClosure['date']??null;
        $cutoffSource='closure:'.(string)($baseClosure['source']??'unknown');
        if(!$cutoffDate&&$allowEstimated){$cutoffDate=risk_export_estimated_cutoff($year,$bimester);if($cutoffDate)$cutoffSource='estimated_quarter_after_confirmed_closure';}
        if(!$cutoffDate){$skippedCutoff++;continue;}

        $students=risk_export_students($conn,$schoolId,$yearId,$bimester);
        foreach($students as $student){
            $studentId=(int)$student['id'];
            $target=edu_predictive_target_next_bimester($conn,$studentId,$schoolId,$yearId,$bimester);
            if($target===null){$skippedTargetData++;continue;}
            $f=edu_predictive_feature_vector($conn,$studentId,$schoolId,$yearId,$bimester,(string)$cutoffDate,$attendanceWindow);
            if((int)$f['_grade_records_current']===0)continue;
            if((int)$f['_attendance_records']===0)$missingAttendance++;
            if($target===1)$positive++;else$negative++;
            $pair=edu_predictive_bimester_label($bimester).'→'.edu_predictive_bimester_label($bimester+1);$pairCounts[$pair]=($pairCounts[$pair]??0)+1;
            fputcsv($fh,[
                $schoolId,$yearId,$studentId,(string)$student['nivel'],(string)$student['grado'],(string)$student['seccion'],$bimester,$bimester+1,(string)$cutoffDate,$cutoffSource,
                round((float)$f['grade_mean_current'],6),round((float)$f['grade_mean_previous'],6),round((float)$f['grade_trend'],6),(int)$f['_grade_records_current'],(int)$f['critical_records_current'],(int)$f['critical_courses_current'],risk_export_nullable_number($f['attendance_rate_30d']),risk_export_nullable_number($f['late_30d']),risk_export_nullable_number($f['absent_30d']),(int)$f['_attendance_records'],$target
            ]);
            $written++;
        }
    }
}
fclose($fh);

echo "Dataset creado: $output\n";
echo "Filas: $written\n";
echo "Objetivo positivo: $positive | negativo: $negative\n";
echo "Pares cerrados utilizados: ".($pairCounts?implode(' | ',array_map(static fn($k,$v)=>$k.': '.$v,array_keys($pairCounts),array_values($pairCounts))):'ninguno')."\n";
echo "Ventana de asistencia: $attendanceWindow días previos al cierre del bimestre base\n";
echo "Filas sin asistencia suficiente: $missingAttendance\n";
echo "Criterio crítico numérico: nota < ".edu_predictive_critical_threshold()." (o letra C)\n";
echo "Bimestres base omitidos por no estar cerrados: $skippedBaseOpen\n";
echo "Pares omitidos porque el bimestre siguiente aún no está cerrado: $skippedTargetOpen\n";
echo "Pares omitidos por falta de fecha de corte segura: $skippedCutoff\n";
echo "Filas omitidas por falta de notas en el bimestre siguiente ya cerrado: $skippedTargetData\n";
if($allowEstimated)echo "ADVERTENCIA: se permitieron fechas de corte estimadas SOLO después de confirmar que el bimestre base estaba cerrado. Para la tesis final es preferible una fecha real.\n";
if($written<40)echo "ADVERTENCIA: el conjunto es pequeño; no entrenes ni reportes métricas concluyentes hasta reunir más observaciones históricas cerradas.\n";
if($written>0&&$missingAttendance/$written>0.5)echo "ADVERTENCIA: más del 50% de las filas no tienen asistencia; el modelo dependerá principalmente de variables académicas.\n";
