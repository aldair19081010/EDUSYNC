<?php
require_once __DIR__ . '/../edusync/db_connect.php';
require_once __DIR__ . '/../edusync/includes/chatbot_engine.php';
require_once __DIR__ . '/../edusync/includes/chatbot_semantic_schema.php';
require_once __DIR__ . '/../edusync/includes/chatbot_advanced_assistant.php';
require_once __DIR__ . '/../edusync/includes/chatbot_tools.php';
require_once __DIR__ . '/../edusync/includes/chatbot_router.php';

$schoolId=1;
foreach($argv as $arg){
    if(strpos($arg,'--school=')===0)$schoolId=max(1,(int)substr($arg,9));
}
$actor=['type'=>1,'role'=>'Administrador','school_id'=>$schoolId];

echo "Universal query engine - school_id=$schoolId\n";
echo 'Router version: '.(defined('EDUSYNC_CHAT_ROUTER_VERSION')?EDUSYNC_CHAT_ROUTER_VERSION:'unknown')."\n";

$failed=0;

$defs=edu_chat_ai_tool_definitions($actor);
$names=[];
foreach($defs as $tool)$names[]=(string)($tool['name']??'');
$hasUniversal=in_array('query_edusync_data',$names,true);
echo ($hasUniversal?'OK':'FAIL').": tool universal disponible\n";
if(!$hasUniversal)$failed++;

$cross=[
    '¿Cuántos estudiantes deben más de 500 soles y tienen 2 tardanzas este mes?',
    'Muéstrame alumnos con notas críticas y deuda pendiente',
    '¿Qué cursos dicta cada docente?',
    '¿Cuántas boletas se emitieron este mes?'
];
foreach($cross as $question){
    $route=edu_chat_ai_forced_route($actor,$question);
    $ok=$route===null;
    echo ($ok?'OK':'FAIL').": router deja consulta universal -> $question\n";
    if(!$ok){$failed++;echo '  '.json_encode($route,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}
}

$plans=[
    'school'=>['domain'=>'school','operation'=>'list'],
    'config_methods'=>['domain'=>'config','operation'=>'list','filters'=>['resource'=>'payment_methods']],
    'students_3_sec'=>['domain'=>'students','operation'=>'count','metric'=>'count','filters'=>['level'=>'Secundaria','grade'=>'3']],
    'students_cross'=>['domain'=>'students','operation'=>'list','metric'=>'debt','filters'=>['level'=>'Secundaria','debt_min'=>0.01,'late_min'=>1,'period'=>'month'],'sort_by'=>'debt','sort_dir'=>'desc','limit'=>10],
    'teachers'=>['domain'=>'teachers','operation'=>'count'],
    'assignments'=>['domain'=>'assignments','operation'=>'group','group_by'=>'course','limit'=>20],
    'attendance'=>['domain'=>'attendance','operation'=>'group','group_by'=>'status','filters'=>['period'=>'today']],
    'payments'=>['domain'=>'payments','operation'=>'sum','metric'=>'amount','filters'=>['period'=>'today']],
    'debts'=>['domain'=>'debts','operation'=>'sum','metric'=>'debt'],
    'grades'=>['domain'=>'grades','operation'=>'count','filters'=>['bimestre'=>'2']],
    'evaluations'=>['domain'=>'evaluations','operation'=>'count'],
    'billing'=>['domain'=>'billing','operation'=>'count','filters'=>['period'=>'month']],
    'years'=>['domain'=>'academic_years','operation'=>'list']
];

foreach($plans as $label=>$plan){
    try{
        $result=edu_chat_universal_query($conn,$actor,$plan);
        $message=trim((string)($result['message']??''));
        $ok=$message!=='';
        echo ($ok?'OK':'FAIL').": $label";
        if($message!==''){
            $one=preg_replace('/\s+/u',' ',$message);
            if(function_exists('mb_substr'))$one=mb_substr($one,0,180,'UTF-8');else$one=substr($one,0,180);
            echo " => $one";
        }
        echo "\n";
        if(!$ok)$failed++;
    }catch(Throwable $e){
        $failed++;
        echo "FAIL: $label => ".$e->getMessage()."\n";
    }
}

if(isset($conn)&&$conn instanceof mysqli)$conn->close();

if($failed>0){
    fwrite(STDERR,"Fallaron $failed pruebas del motor universal.\n");
    exit(1);
}
echo "OK: motor universal de consultas EduSync operativo.\n";
