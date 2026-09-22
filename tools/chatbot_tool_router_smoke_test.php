<?php
require_once __DIR__ . '/../edusync/includes/chatbot_engine.php';
require_once __DIR__ . '/../edusync/includes/chatbot_semantic_schema.php';
require_once __DIR__ . '/../edusync/includes/chatbot_router.php';
require_once __DIR__ . '/../edusync/includes/chatbot_advanced_assistant.php';

$actor=['type'=>1,'role'=>'Administrador','school_id'=>1];

$cases=[
    ['¿Cuánto en efectivo ha recibido de pagos hoy?','get_collections_by_method','Efectivo','today'],
    ['¿Cuánto efectivo entró hoy?','get_collections_by_method','Efectivo','today'],
    ['¿Cuánto recibimos por Yape ayer?','get_collections_by_method','Yape','yesterday'],
    ['¿Cuánto ingresó por transferencia esta semana?','get_collections_by_method','Transferencia','week'],
    ['Total depositado el mes pasado','get_collections_by_method','Transferencia','last_month'],
];

$failed=0;
foreach($cases as [$text,$tool,$method,$period]){
    $route=edu_chat_ai_forced_route($actor,$text);
    $ok=is_array($route)
        && ($route['name']??'')===$tool
        && (($route['arguments']['payment_method']??'')===$method)
        && (($route['arguments']['period']??'')===$period);
    echo ($ok?'OK':'FAIL').": $text\n";
    if(!$ok){
        $failed++;
        echo '  '.json_encode($route,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    }
}

$state=[
    'topic'=>'finanzas',
    'period'=>'today',
    'root_query'=>'¿Cuánto cobramos hoy?',
    'updated_at'=>time()
];
$follow=edu_chat_contextualize_message('¿Y por Yape?',$state);
$route=edu_chat_ai_forced_route($actor,$follow);
$ok=is_array($route)
    && ($route['name']??'')==='get_collections_by_method'
    && (($route['arguments']['payment_method']??'')==='Yape')
    && (($route['arguments']['period']??'')==='today');
echo ($ok?'OK':'FAIL').": seguimiento ¿Y por Yape? conserva hoy\n";
if(!$ok){$failed++;echo '  contextualizado='.$follow."\n  route=".json_encode($route,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}

if($failed>0){
    fwrite(STDERR,"Fallaron $failed pruebas del router semántico.\n");
    exit(1);
}
echo "OK: router semántico de pagos por método listo.\n";
