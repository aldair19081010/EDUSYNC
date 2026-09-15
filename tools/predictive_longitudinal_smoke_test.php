<?php
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Solo CLI.\n");exit(1);}
require_once __DIR__.'/../edusync/includes/predictive_longitudinal.php';

function longitudinal_assert(bool $condition,string $message): void {
    if(!$condition){fwrite(STDERR,"FALLO: $message\n");exit(1);}
}

// Caso 1: primer bimestre del nuevo año con historia del año anterior.
$firstBimester=[
    ['academic_year_id'=>1,'bimester'=>3,'grade_mean'=>15.0,'critical_courses'=>0,'attendance_rate'=>96.0,'late'=>0,'absent'=>0],
    ['academic_year_id'=>1,'bimester'=>4,'grade_mean'=>13.0,'critical_courses'=>1,'attendance_rate'=>92.0,'late'=>1,'absent'=>1],
    ['academic_year_id'=>2,'bimester'=>1,'grade_mean'=>11.0,'critical_courses'=>2,'attendance_rate'=>86.0,'late'=>3,'absent'=>2],
];
$f=edu_predictive_longitudinal_derive($firstBimester);
longitudinal_assert((float)$f['previous_period_available']===1.0,'I bimestre no reconoció el IV bimestre anterior como historial utilizable.');
longitudinal_assert((float)$f['previous_academic_year_history']===1.0,'No se detectó historia de un año académico anterior.');
longitudinal_assert(abs((float)$f['grade_trend_recent']-(-2.0))<0.0001,'La tendencia reciente entre IV anterior e I actual es incorrecta.');
longitudinal_assert((int)$f['history_periods_available']===3,'El conteo de periodos históricos es incorrecto.');
longitudinal_assert((int)$f['historical_years_available']===2,'El conteo de años históricos es incorrecto.');
longitudinal_assert((float)$f['long_term_grade_trend']<0,'No se detectó la tendencia académica descendente de largo plazo.');

// Caso 2: estudiante nuevo, solo existe I bimestre.
$newStudent=[
    ['academic_year_id'=>2,'bimester'=>1,'grade_mean'=>14.0,'critical_courses'=>0,'attendance_rate'=>95.0,'late'=>0,'absent'=>0],
];
$n=edu_predictive_longitudinal_derive($newStudent);
longitudinal_assert((float)$n['previous_period_available']===0.0,'Un estudiante nuevo recibió un periodo previo inexistente.');
longitudinal_assert((int)$n['history_periods_available']===1,'El estudiante nuevo debe tener un solo periodo disponible.');
longitudinal_assert($n['historical_mean_prior']===null,'Se inventó promedio histórico para un estudiante nuevo.');
longitudinal_assert($n['long_term_grade_trend']===null,'Se inventó tendencia larga con un solo periodo.');

// Caso 3: tres bimestres cerrados del año actual para predecir IV.
$threeClosed=[
    ['academic_year_id'=>2,'bimester'=>1,'grade_mean'=>15.0,'critical_courses'=>0,'attendance_rate'=>96.0,'late'=>0,'absent'=>0],
    ['academic_year_id'=>2,'bimester'=>2,'grade_mean'=>13.0,'critical_courses'=>1,'attendance_rate'=>91.0,'late'=>2,'absent'=>1],
    ['academic_year_id'=>2,'bimester'=>3,'grade_mean'=>11.0,'critical_courses'=>3,'attendance_rate'=>84.0,'late'=>4,'absent'=>3],
];
$t=edu_predictive_longitudinal_derive($threeClosed);
longitudinal_assert(abs((float)$t['grade_trend_recent']-(-2.0))<0.0001,'La tendencia reciente II->III es incorrecta.');
longitudinal_assert((float)$t['long_term_grade_trend']<0,'No se detectó caída I->II->III.');
longitudinal_assert(abs((float)$t['critical_courses_recent_3_mean']-(4.0/3.0))<0.0001,'El resumen de cursos críticos de I-II-III es incorrecto.');
longitudinal_assert(abs((float)$t['attendance_trend_recent']-(-7.0))<0.0001,'La tendencia reciente de asistencia es incorrecta.');

echo "OK: v5 distingue estudiante nuevo de estudiante con historia previa.\n";
echo "OK: I bimestre puede usar IV y periodos de años académicos anteriores.\n";
echo "OK: con I, II y III cerrados se resume la trayectoria completa disponible para predecir IV.\n";
echo "OK: no se inventa tendencia cuando solo existe un periodo.\n";
