<?php
require_once __DIR__ . '/../edusync/includes/chatbot_engine.php';
require_once __DIR__ . '/../edusync/includes/chatbot_semantic_schema.php';
require_once __DIR__ . '/../edusync/includes/chatbot_router.php';
require_once __DIR__ . '/../edusync/includes/chatbot_advanced_assistant.php';

echo 'Router version: '.(defined('EDUSYNC_CHAT_ROUTER_VERSION')?EDUSYNC_CHAT_ROUTER_VERSION:'unknown')."\n";
echo 'Normalizacion: '.edu_chat_normalize('Muéstrame cómo registrar notas')."\n";

$actors=[
    'admin'=>['type'=>1,'role'=>'Administrador','school_id'=>1],
    'teacher'=>['type'=>2,'role'=>'Docente','school_id'=>1],
    'aux'=>['type'=>3,'role'=>'Auxiliar','school_id'=>1]
];

$cases=[
    ['admin','¿Cuánto efectivo entró hoy?','get_collections_by_method',['payment_method'=>'Efectivo','period'=>'today']],
    ['admin','¿Cuánto recibimos por Yape ayer?','get_collections_by_method',['payment_method'=>'Yape','period'=>'yesterday']],
    ['admin','¿Cuánto ingresó por transferencia esta semana?','get_collections_by_method',['payment_method'=>'Transferencia','period'=>'week']],
    ['admin','¿Cuántos estudiantes hay en secundaria?','get_student_count',['level'=>'Secundaria']],
    ['admin','Muéstrame los estudiantes de 4to de secundaria','get_student_roster',['level'=>'Secundaria','grade'=>'4']],
    ['admin','Muéstrame los estudiantes de tercero de secundaria en ambas secciones','get_student_roster',['level'=>'Secundaria','grade'=>'3','limit'=>50]],
    ['admin','Muéstrame tercero de secundaria en ambas secciones','get_student_roster',['level'=>'Secundaria','grade'=>'3','limit'=>50]],
    ['admin','¿Cómo se distribuyen los estudiantes por sección?','get_student_distribution',[]],
    ['admin','¿Cuántos docentes hay?','get_teacher_count',[]],
    ['admin','¿Cómo está la deuda del colegio?','get_debt_summary',[]],
    ['admin','Desglosa la deuda por grado','get_debt_distribution',[]],
    ['admin','¿Cómo está la asistencia hoy?','get_attendance_summary',['period'=>'today']],
    ['admin','Muéstrame quiénes faltaron hoy','get_attendance_roster',['period'=>'today']],
    ['admin','¿Cuántos estudiantes están en riesgo?','get_academic_risk',[]],
    ['admin','Muéstrame los estudiantes en riesgo','get_academic_risk_roster',[]],
    ['teacher','¿Cuáles son mis cursos?','get_my_courses',[]],
    ['teacher','¿Cuántos estudiantes tengo?','get_my_student_count',[]],
    ['aux','¿Cómo está la asistencia hoy?','get_attendance_summary',['period'=>'today']],
    ['admin','¿Cómo registro notas?','get_system_help',[]],
];

$failed=0;
foreach($cases as [$who,$text,$tool,$expected]){
    $route=edu_chat_ai_forced_route($actors[$who],$text);
    $ok=is_array($route)&&($route['name']??'')===$tool;
    foreach($expected as $k=>$v)$ok=$ok&&(($route['arguments'][$k]??null)===$v);
    echo ($ok?'OK':'FAIL').": [$who] $text => ".($route['name']??'null')."\n";
    if(!$ok){$failed++;echo '  '.json_encode($route,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}
}

$gradeOk=edu_chat_grade_label('3°')==='3°' && edu_chat_grade_label('3')==='3°' && edu_chat_grade_label('3°°')==='3°';
echo ($gradeOk?'OK':'FAIL').": formato de grado evita doble símbolo °\n";
if(!$gradeOk)$failed++;

$bimesterOk=edu_chat_router_explicit_grade('tercer bimestre')===null;
echo ($bimesterOk?'OK':'FAIL').": tercer bimestre no se confunde con tercer grado\n";
if(!$bimesterOk)$failed++;

$sectionState=['topic'=>'estudiantes','level'=>'Secundaria','grade'=>'3','section'=>'A','root_query'=>'Muéstrame tercero A','updated_at'=>time()];
$sectionFollow=edu_chat_contextualize_message('Ahora ambas secciones',$sectionState);
$sectionRoute=edu_chat_ai_forced_route($actors['admin'],$sectionFollow);
$sectionOk=is_array($sectionRoute)
    &&($sectionRoute['name']??'')==='get_student_roster'
    &&(($sectionRoute['arguments']['grade']??'')==='3')
    &&!isset($sectionRoute['arguments']['section'])
    &&(($sectionRoute['arguments']['limit']??0)===50)
    &&strpos($sectionFollow,'sección=A')===false;
echo ($sectionOk?'OK':'FAIL').": ambas secciones elimina filtro A heredado\n";
if(!$sectionOk){$failed++;echo '  contextualizado='.$sectionFollow."\n  route=".json_encode($sectionRoute,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}

$state=['topic'=>'finanzas','period'=>'today','root_query'=>'¿Cuánto cobramos hoy?','updated_at'=>time()];
$follow=edu_chat_contextualize_message('¿Y por Yape?',$state);
$route=edu_chat_ai_forced_route($actors['admin'],$follow);
$ok=is_array($route)&&($route['name']??'')==='get_collections_by_method'
    &&(($route['arguments']['payment_method']??'')==='Yape')
    &&(($route['arguments']['period']??'')==='today');
echo ($ok?'OK':'FAIL').": seguimiento financiero conserva contexto\n";
if(!$ok){$failed++;echo '  contextualizado='.$follow."\n  route=".json_encode($route,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}

if($failed>0){fwrite(STDERR,"Fallaron $failed pruebas del router semántico general.\n");exit(1);}
echo "OK: router semántico general de EduSync listo.\n";
