<?php
/**
 * Exporta un dataset histórico sin fuga de información futura.
 *
 * Uso:
 *   php tools/export_risk_dataset.php --school=1 --output=storage/risk_dataset.csv
 *   php tools/export_risk_dataset.php --school=1 --year=3 --output=storage/risk_dataset.csv
 *   php tools/export_risk_dataset.php --school=1 --allow-estimated-cutoffs=1
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../edusync/db_connect.php';
require_once __DIR__ . '/../edusync/includes/predictive_risk.php';

$options = getopt('', ['school:','year::','output::','allow-estimated-cutoffs::','attendance-window::']);
$schoolId = (int)($options['school'] ?? 0);
$yearOption = isset($options['year']) && $options['year'] !== false && $options['year'] !== '' ? (int)$options['year'] : null;
$output = (string)($options['output'] ?? (__DIR__ . '/../storage/risk_dataset.csv'));
$allowEstimated = in_array(strtolower((string)($options['allow-estimated-cutoffs'] ?? '0')), ['1','true','yes','on'], true);
$attendanceWindow = max(7, min(120, (int)($options['attendance-window'] ?? 30)));

if ($schoolId <= 0) {
    fwrite(STDERR, "Falta --school=<ID del colegio>.\n");
    exit(1);
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "No se pudo abrir la conexión MySQL de EduSync.\n");
    exit(1);
}

function risk_export_years(mysqli $conn, int $schoolId, ?int $yearId): array {
    if (!edu_predictive_table_exists($conn,'academic_year')) return [];
    if ($yearId !== null && $yearId > 0) {
        $stmt=$conn->prepare('SELECT id,school_id,start_date,end_date,is_active FROM academic_year WHERE school_id=? AND id=? LIMIT 1');
        if(!$stmt)return[];$stmt->bind_param('ii',$schoolId,$yearId);
    } else {
        $stmt=$conn->prepare('SELECT id,school_id,start_date,end_date,is_active FROM academic_year WHERE school_id=? ORDER BY start_date ASC,id ASC');
        if(!$stmt)return[];$stmt->bind_param('i',$schoolId);
    }
    $stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;
}

function risk_export_eval_date_column(mysqli $conn): ?string {
    foreach(['date_created','created_at','evaluation_date','date'] as $column) if(edu_predictive_column_exists($conn,'evaluations',$column)) return $column;
    return null;
}

function risk_export_cutoff(mysqli $conn, int $schoolId, array $year, int $bimester, bool $allowEstimated): ?array {
    $yearId=(int)$year['id'];
    if (edu_predictive_table_exists($conn,'grade_period_closures') && edu_predictive_column_exists($conn,'grade_period_closures','closed_at')) {
        $where=['school_id=?','academic_year_id=?','bimester=?','closed_at IS NOT NULL'];$types='iii';$params=[$schoolId,$yearId,$bimester];
        if(edu_predictive_column_exists($conn,'grade_period_closures','status'))$where[]="LOWER(TRIM(status))='cerrado'";
        $sql='SELECT MAX(DATE(closed_at)) cutoff_date FROM grade_period_closures WHERE '.implode(' AND ',$where);
        $stmt=$conn->prepare($sql);
        if($stmt){edu_predictive_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if(!empty($r['cutoff_date']))return['date'=>$r['cutoff_date'],'source'=>'grade_period_closures'];}
    }

    $dateColumn=risk_export_eval_date_column($conn);
    if($dateColumn!==null){
        $where=['tc.school_id=?','CAST(e.bimestre AS UNSIGNED)=?'];$types='ii';$params=[$schoolId,$bimester];
        if(edu_predictive_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
        $sql="SELECT MAX(DATE(e.`$dateColumn`)) cutoff_date FROM evaluations e INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id WHERE ".implode(' AND ',$where);
        $stmt=$conn->prepare($sql);
        if($stmt){edu_predictive_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if(!empty($r['cutoff_date']))return['date'=>$r['cutoff_date'],'source'=>'evaluations.'.$dateColumn];}
    }

    if(!$allowEstimated)return null;
    $start=strtotime((string)($year['start_date']??''));$end=strtotime((string)($year['end_date']??''));
    if(!$start||!$end||$end<=$start)return null;
    $span=$end-$start;$cutoff=$start+(int)round(($span/4.0)*$bimester);
    return['date'=>date('Y-m-d',$cutoff),'source'=>'estimated_quarter'];
}

function risk_export_students(mysqli $conn,int $schoolId,int $yearId,int $bimester): array {
    foreach(['student','evaluation_grades','evaluations','teacher_courses'] as $table)if(!edu_predictive_table_exists($conn,$table))return[];
    $where=['s.school_id=?','CAST(e.bimestre AS UNSIGNED)=?'];$types='ii';$params=[$schoolId,$bimester];
    if(edu_predictive_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    $sql='SELECT DISTINCT s.id,s.name,s.nivel,s.grado,s.seccion FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id WHERE '.implode(' AND ',$where).' ORDER BY s.id';
    $stmt=$conn->prepare($sql);if(!$stmt)return[];edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;
}

$years=risk_export_years($conn,$schoolId,$yearOption);
if(!$years){fwrite(STDERR,"No se encontraron años académicos para ese colegio.\n");exit(1);}

$dir=dirname($output);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)){fwrite(STDERR,"No pude crear el directorio de salida.\n");exit(1);}
$fh=fopen($output,'wb');if(!$fh){fwrite(STDERR,"No pude crear $output.\n");exit(1);}
$headers=['school_id','academic_year_id','student_id','nivel','grado','seccion','bimester','cutoff_date','cutoff_source','grade_mean_current','grade_mean_previous','grade_trend','critical_records_current','critical_courses_current','attendance_rate_30d','late_30d','absent_30d','target_next_bimester_risk'];
fputcsv($fh,$headers);

$written=0;$skippedCutoff=0;$skippedTarget=0;
foreach($years as $year){
    $yearId=(int)$year['id'];
    foreach([1,2,3] as $bimester){
        $cutoff=risk_export_cutoff($conn,$schoolId,$year,$bimester,$allowEstimated);
        if(!$cutoff){$skippedCutoff++;continue;}
        $students=risk_export_students($conn,$schoolId,$yearId,$bimester);
        foreach($students as $student){
            $studentId=(int)$student['id'];
            $target=edu_predictive_target_next_bimester($conn,$studentId,$schoolId,$yearId,$bimester);
            if($target===null){$skippedTarget++;continue;}
            $f=edu_predictive_feature_vector($conn,$studentId,$schoolId,$yearId,$bimester,(string)$cutoff['date'],$attendanceWindow);
            if((int)$f['_grade_records_current']===0)continue;
            fputcsv($fh,[
                $schoolId,$yearId,$studentId,(string)$student['nivel'],(string)$student['grado'],(string)$student['seccion'],$bimester,(string)$cutoff['date'],(string)$cutoff['source'],
                round((float)$f['grade_mean_current'],6),round((float)$f['grade_mean_previous'],6),round((float)$f['grade_trend'],6),(int)$f['critical_records_current'],(int)$f['critical_courses_current'],round((float)$f['attendance_rate_30d'],6),(int)$f['late_30d'],(int)$f['absent_30d'],$target
            ]);
            $written++;
        }
    }
}
fclose($fh);

echo "Dataset creado: $output\n";
echo "Filas: $written\n";
echo "Ventana de asistencia: $attendanceWindow días\n";
echo "Bimestres omitidos por falta de cutoff confiable: $skippedCutoff\n";
echo "Filas omitidas por falta de datos en el bimestre siguiente: $skippedTarget\n";
if($allowEstimated)echo "ADVERTENCIA: se permitieron cutoffs estimados. Para la tesis final conviene usar cierres/fechas reales.\n";
if($written<40)echo "ADVERTENCIA: el conjunto es pequeño; no entrenes ni reportes métricas concluyentes hasta reunir más observaciones.\n";
