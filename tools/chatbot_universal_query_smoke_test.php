<?php
require_once __DIR__ . '/../edusync/db_connect.php';
require_once __DIR__ . '/../edusync/includes/chatbot_engine.php';
require_once __DIR__ . '/../edusync/includes/chatbot_semantic_schema.php';
require_once __DIR__ . '/../edusync/includes/chatbot_analytics.php';
require_once __DIR__ . '/../edusync/includes/chatbot_totals.php';
require_once __DIR__ . '/../edusync/includes/chatbot_finance_filters.php';
require_once __DIR__ . '/../edusync/includes/chatbot_student_insights.php';
require_once __DIR__ . '/../edusync/includes/chatbot_router.php';
require_once __DIR__ . '/../edusync/includes/chatbot_advanced_assistant.php';
require_once __DIR__ . '/../edusync/includes/chatbot_universal_query.php';
require_once __DIR__ . '/../edusync/includes/chatbot_tools.php';

echo 'Motor universal: '.(defined('EDUSYNC_CHAT_UNIVERSAL_VERSION')?EDUSYNC_CHAT_UNIVERSAL_VERSION:'unknown')."\n";

$failed=0;
function uq_ok($ok,$label,$detail=''){
    global $failed;
    echo ($ok?'OK':'FAIL').": $label\n";
    if(!$ok){$failed++;if($detail!=='')echo "  $detail\n";}
}

$admin=['type'=>1,'role'=>'Administrador','school_id'=>1];
$teacher=['type'=>2,'role'=>'Docente','school_id'=>1,'teacher_id'=>30];
$aux=['type'=>3,'role'=>'Auxiliar','school_id'=>1];

$plan=edu_chat_universal_plan([
    'subject'=>'students','operation'=>'list','level'=>'Secundaria','grade'=>'3',
    'debt_min'=>500,'late_min'=>2,'period'=>'month','sort_by'=>'debt','limit'=>40
]);
uq_ok($plan['subject']==='students'&&$plan['debt_min']===500.0&&$plan['late_min']===2&&$plan['limit']===40,'plan estructurado conserva filtros cruzados');

$adminTools=edu_chat_ai_tool_definitions($admin);
$names=array_map(static function($t){return $t['name']??'';},$adminTools);
uq_ok(in_array('query_edusync_data',$names,true),'administrador recibe query_edusync_data');

$studentAllowed=edu_chat_universal_allowed_subjects($admin);
uq_ok(in_array('billing',$studentAllowed,true)&&in_array('competencies',$studentAllowed,true)&&in_array('bimester_locks',$studentAllowed,true)&&in_array('concepts',$studentAllowed,true)&&in_array('fees',$studentAllowed,true)&&in_array('notifications',$studentAllowed,true)&&in_array('settings',$studentAllowed,true)&&in_array('sunat',$studentAllowed,true),'cobertura administrativa ampliada');

$route=edu_chat_ai_forced_route($admin,'Muéstrame estudiantes con deuda mayor a 500 soles y al menos 2 tardanzas este mes');
uq_ok($route===null,'consulta cruzada no es robada por router específico',json_encode($route,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

$route=edu_chat_ai_forced_route($admin,'¿Cuánto cuesta la mensualidad de tercero de secundaria?');
uq_ok($route===null,'consulta de concepto de pago llega al motor universal',json_encode($route,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

$route=edu_chat_ai_forced_route($admin,'¿Cuál es el modo SUNAT configurado?');
uq_ok($route===null,'consulta de configuración SUNAT llega al motor universal',json_encode($route,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

$denied=edu_chat_universal_query($teacher,['subject'=>'students','operation'=>'list','debt_min'=>1]);
uq_ok(stripos((string)($denied['message']??''),'no está autorizada')!==false,'docente no puede cruzar alumnos con finanzas',(string)($denied['message']??''));

$deniedAux=edu_chat_universal_query($aux,['subject'=>'students','operation'=>'list','grade_max'=>10]);
uq_ok(stripos((string)($deniedAux['message']??''),'no está autorizada')!==false,'auxiliar no puede cruzar alumnos con notas',(string)($deniedAux['message']??''));

$cases=[
    ['estudiantes 3ro secundaria', ['subject'=>'students','operation'=>'list','level'=>'Secundaria','grade'=>'3','limit'=>50]],
    ['años académicos', ['subject'=>'academic_years','operation'=>'list']],
    ['áreas', ['subject'=>'areas','operation'=>'list','limit'=>50]],
    ['competencias', ['subject'=>'competencies','operation'=>'list','limit'=>20]],
    ['cursos', ['subject'=>'courses','operation'=>'list','limit'=>20]],
    ['conceptos de pago', ['subject'=>'concepts','operation'=>'list','level'=>'Secundaria','grade'=>'3','limit'=>20]],
    ['cuotas asignadas', ['subject'=>'fees','operation'=>'summary','level'=>'Secundaria','grade'=>'3']],
    ['evaluaciones', ['subject'=>'evaluations','operation'=>'count']],
    ['notas', ['subject'=>'grades','operation'=>'summary']],
    ['pagos', ['subject'=>'payments','operation'=>'summary','period'=>'month']],
    ['deuda', ['subject'=>'debts','operation'=>'summary']],
    ['asistencia', ['subject'=>'attendance','operation'=>'summary','period'=>'today']],
    ['riesgo', ['subject'=>'risk','operation'=>'summary']],
    ['institución', ['subject'=>'school','operation'=>'summary']],
    ['bimestres bloqueados', ['subject'=>'bimester_locks','operation'=>'list']],
    ['usuarios', ['subject'=>'users','operation'=>'count']],
    ['configuración asistencia', ['subject'=>'attendance_config','operation'=>'summary']],
    ['configuración institucional', ['subject'=>'settings','operation'=>'summary']],
    ['notificaciones', ['subject'=>'notifications','operation'=>'summary']]
];

if(edu_chat_table_exists($conn,'comprobantes_electronicos'))$cases[]=['facturación',['subject'=>'billing','operation'=>'summary','period'=>'month']];
if(edu_chat_table_exists($conn,'sunat_log')&&edu_chat_table_exists($conn,'comprobantes_electronicos'))$cases[]=['trazabilidad SUNAT',['subject'=>'sunat','operation'=>'summary','period'=>'month']];

foreach($cases as $case){
    [$label,$args]=$case;
    try{
        $r=edu_chat_universal_query($conn,$admin,$args);
        $msg=trim((string)($r['message']??''));
        $bad=$msg===''||stripos($msg,'No pude preparar')!==false||stripos($msg,'no está autorizado')!==false;
        uq_ok(!$bad,"consulta DB: $label",$msg);
    }catch(Throwable $e){
        uq_ok(false,"consulta DB: $label",$e->getMessage());
    }
}

if($failed>0){
    fwrite(STDERR,"Fallaron $failed pruebas del motor universal.\n");
    exit(1);
}
echo "OK: motor universal de EduSync listo para piloto.\n";
