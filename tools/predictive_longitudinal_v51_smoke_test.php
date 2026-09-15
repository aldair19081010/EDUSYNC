<?php
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Solo CLI.\n");exit(1);}
require_once __DIR__.'/../edusync/includes/predictive_longitudinal_v51.php';
function v51_assert(bool $condition,string $message): void{if(!$condition){fwrite(STDERR,"FALLO: $message\n");exit(1);}}

$periods=[
 ['academic_year_id'=>1,'bimester'=>4,'grade_mean'=>9.0,'critical_courses'=>3,'attendance_rate'=>70.0],
 ['academic_year_id'=>2,'bimester'=>1,'grade_mean'=>15.0,'critical_courses'=>0,'attendance_rate'=>96.0],
 ['academic_year_id'=>2,'bimester'=>2,'grade_mean'=>13.0,'critical_courses'=>1,'attendance_rate'=>91.0],
 ['academic_year_id'=>2,'bimester'=>3,'grade_mean'=>11.0,'critical_courses'=>3,'attendance_rate'=>84.0],
];
$f=edu_predictive_v51_derive_same_year($periods,2);
v51_assert((int)$f['year_periods_available']===3,'Debe usar I, II y III del año actual.');
v51_assert(abs((float)$f['grade_trend_same_year']-(-2.0))<0.0001,'La tendencia reciente II->III es incorrecta.');
v51_assert((float)$f['year_grade_trend']<0,'No detectó la trayectoria descendente I->II->III.');
v51_assert(abs((float)$f['critical_courses_year_mean']-(4.0/3.0))<0.0001,'La media de cursos críticos del año es incorrecta.');
v51_assert(abs((float)$f['attendance_trend_same_year']-(-7.0))<0.0001,'La tendencia de asistencia II->III es incorrecta.');

$first=edu_predictive_v51_derive_same_year([
 ['academic_year_id'=>1,'bimester'=>4,'grade_mean'=>8.0,'critical_courses'=>4,'attendance_rate'=>60.0],
 ['academic_year_id'=>2,'bimester'=>1,'grade_mean'=>14.0,'critical_courses'=>0,'attendance_rate'=>95.0],
],2);
v51_assert((int)$first['year_periods_available']===1,'I bimestre debe tener un solo periodo del año actual.');
v51_assert(abs((float)$first['grade_trend_same_year'])<0.0001,'I bimestre no debe heredar la tendencia de IV del año anterior.');
v51_assert($first['year_grade_trend']===null,'No debe inventar tendencia anual con un solo bimestre.');

echo "OK: v5.1 usa I-II-III del mismo año para predecir IV.\n";
echo "OK: I bimestre no incorpora IV del año anterior al porcentaje de riesgo.\n";
echo "OK: la historia multianual queda separada del modelo v5.1.\n";
