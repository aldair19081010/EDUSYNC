<?php
require_once __DIR__.'/predictive_longitudinal.php';

/**
 * Candidato v5.1: resume únicamente la trayectoria del AÑO ACADÉMICO ACTUAL.
 * La historia multianual puede conservarse como contexto, pero no entra al
 * porcentaje de riesgo hasta contar con suficiente evidencia temporal.
 */
function edu_predictive_v51_derive_same_year(array $periods,int $yearId): array {
    $same=array_values(array_filter($periods,static fn($p)=>(int)($p['academic_year_id']??0)===$yearId));
    if(!$same)return[];
    usort($same,static fn($a,$b)=>(int)($a['bimester']??0)<=>(int)($b['bimester']??0));
    $count=count($same);$current=$same[$count-1];$previous=$count>1?$same[$count-2]:null;
    $means=array_map(static fn($p)=>$p['grade_mean']??null,$same);
    $critical=array_map(static fn($p)=>$p['critical_courses']??null,$same);
    $currentMean=isset($current['grade_mean'])&&is_numeric($current['grade_mean'])?(float)$current['grade_mean']:null;
    $previousMean=$previous!==null&&isset($previous['grade_mean'])&&is_numeric($previous['grade_mean'])?(float)$previous['grade_mean']:null;
    $currentAttendance=isset($current['attendance_rate'])&&is_numeric($current['attendance_rate'])?(float)$current['attendance_rate']:null;
    $previousAttendance=$previous!==null&&isset($previous['attendance_rate'])&&is_numeric($previous['attendance_rate'])?(float)$previous['attendance_rate']:null;

    return[
        'grade_trend_same_year'=>($currentMean!==null&&$previousMean!==null)?$currentMean-$previousMean:0.0,
        'year_periods_available'=>(float)$count,
        'year_grade_trend'=>edu_predictive_longitudinal_slope($means),
        'critical_courses_year_mean'=>edu_predictive_longitudinal_mean($critical),
        'attendance_trend_same_year'=>($currentAttendance!==null&&$previousAttendance!==null)?$currentAttendance-$previousAttendance:null,
        '_same_year_previous_grade_mean'=>$previousMean,
        '_same_year_periods'=>$same,
    ];
}

function edu_predictive_v51_feature_vector(mysqli $conn,int $studentId,int $schoolId,int $yearId,int $bimester,?string $cutoffDate=null,int $attendanceWindowDays=30): array {
    $base=edu_predictive_feature_vector($conn,$studentId,$schoolId,$yearId,$bimester,$cutoffDate,$attendanceWindowDays);
    $allPeriods=edu_predictive_longitudinal_periods($conn,$studentId,$schoolId,$yearId,$bimester,$attendanceWindowDays,$cutoffDate);
    $sameYear=edu_predictive_v51_derive_same_year($allPeriods,$yearId);
    return array_merge($base,$sameYear,[
        '_all_longitudinal_periods'=>$allPeriods,
        '_previous_academic_year_context_available'=>(float)(count(array_filter($allPeriods,static fn($p)=>(int)($p['academic_year_id']??0)!==$yearId))>0?1:0),
    ]);
}
