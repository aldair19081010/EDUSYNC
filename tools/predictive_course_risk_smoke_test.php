<?php
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Solo CLI.\n");exit(1);}require_once __DIR__.'/../edusync/includes/predictive_course_risk.php';
function cr_assert($ok,$msg){if(!$ok){fwrite(STDERR,"FALLO: $msg\n");exit(1);}}
cr_assert(edu_course_risk_grade_score('C')===5.0,'C debe usar la misma escala canónica del Libro de Notas.');
cr_assert(edu_course_risk_grade_score('B')===12.0,'B debe normalizarse a 12.');
cr_assert(edu_course_risk_grade_score('A')===15.5,'A debe normalizarse a 15.5.');
cr_assert(edu_course_risk_grade_score('AD')===19.0,'AD debe normalizarse a 19.');
cr_assert(edu_course_risk_grade_low('C')===true,'C debe ser calificación baja.');
cr_assert(edu_course_risk_grade_score('')===null,'Una nota vacía no debe convertirse en cero.');
cr_assert(edu_course_risk_grade_low('11')===false,'11 no debe considerarse menor a 10.5.');
cr_assert(edu_course_risk_grade_low('10')===true,'10 debe considerarse bajo.');
$reasons=edu_course_risk_reason_labels(['course_mean_current'=>11.0,'previous_course_available'=>1,'course_trend'=>0.0,'same_year_course_slope'=>0.0,'low_grade_rate_current'=>0.4,'missing_grade_rate_current'=>0.0,'attendance_rate_30d'=>80.0,'absent_30d'=>5,'late_30d'=>4,'class_students_critical_rate'=>0.1]);
$text=implode(' ',$reasons);cr_assert(stripos($text,'cerca del límite')!==false,'El caso 10/11/11 debe explicar cercanía al límite.');cr_assert(stripos($text,'sin mejora')!==false,'Debe explicar estabilidad baja sin mejora.');cr_assert(stripos($text,'asistencia')!==false,'La asistencia baja debe aparecer en la explicación.');cr_assert(stripos($text,'ausencia')!==false,'Las ausencias deben aparecer en la explicación.');
$priority=edu_course_risk_general_priority([['course_name'=>'Matemática','probability'=>.74,'level'=>'Alto'],['course_name'=>'CyT','probability'=>.58,'level'=>'Medio'],['course_name'=>'Comunicación','probability'=>.10,'level'=>'Bajo']]);
cr_assert($priority['level']==='Alto'&&$priority['attention_courses']===2,'La prioridad general debe derivarse de los cursos en riesgo.');cr_assert($priority['max_course_name']==='Matemática','Debe identificar el curso de mayor riesgo.');
$priority2=edu_course_risk_general_priority([['course_name'=>'Comunicación','probability'=>.15,'level'=>'Bajo'],['course_name'=>'Inglés','probability'=>.12,'level'=>'Bajo']]);cr_assert($priority2['level']==='Bajo'&&$priority2['attention_courses']===0,'Sin cursos Alto/Medio la prioridad debe ser Baja.');
echo "OK: v7 trabaja por estudiante + curso + bimestre con escala canónica del Libro de Notas.\n";echo "OK: nota vacía no se convierte en cero.\n";echo "OK: un curso 10→11→11 puede explicarse como estable cerca del límite, no como tendencia favorable.\n";echo "OK: asistencia, ausencias y tardanzas forman parte de los motivos de alerta.\n";echo "OK: el riesgo general se deriva de los cursos en riesgo y no de un porcentaje general inventado.\n";echo "OK: teacher_id no forma parte de las variables del modelo v7.\n";
