<?php

require_once __DIR__ . '/predictive_risk.php';

function edu_predictive_is_query(string $message): bool {
    $n = function_exists('edu_chat_normalize') ? edu_chat_normalize($message) : strtolower(trim($message));
    $needles = [
        'riesgo predictivo','riesgo futuro','predecir riesgo','predice riesgo','prediccion de riesgo',
        'probabilidad de riesgo','proximo bimestre','siguiente bimestre','alerta temprana',
        'mayor riesgo futuro','mayor probabilidad de riesgo'
    ];
    foreach ($needles as $needle) if (strpos($n,$needle)!==false) return true;
    return false;
}

function edu_predictive_requested_name(string $message, array $history = []): string {
    $n = function_exists('edu_chat_normalize') ? edu_chat_normalize($message) : strtolower(trim($message));
    $patterns = [
        '/\b(?:riesgo predictivo|riesgo futuro|probabilidad de riesgo|predecir riesgo|predice riesgo)\s+(?:de|del|para)\s+(.+)$/',
        '/\b(?:por que|porque)\s+(?:esta|estaria)\s+en riesgo\s+(.+)$/',
    ];
    foreach($patterns as $pattern){
        if(!preg_match($pattern,$n,$m))continue;
        $name=trim((string)$m[1]);
        $name=preg_replace('/\s+(?:de\s+)?(?:inicial|primaria|secundaria)\b.*$/','',$name);
        $name=preg_replace('/\s+(?:de\s+)?[1-6]\s*(?:ro|do|to|er|°)(?:\s+[a-z])?\b.*$/','',$name);
        $name=preg_replace('/\s+seccion\s+[a-z0-9]+\b.*$/','',$name);
        $name=trim((string)$name," .,:;?¿!¡\t\n\r\0\x0B");
        if($name!==''&&!in_array($name,['alto','medio','bajo','el primero','la primera'],true))return $name;
    }

    if(strpos($n,'el primero')!==false||strpos($n,'la primera')!==false){
        for($i=count($history)-1;$i>=0;$i--){
            if(($history[$i]['role']??'')!=='assistant')continue;
            $text=(string)($history[$i]['text']??'');
            if(preg_match('/(?:^|\n)1\.\s+(.+?)\s+—\s+riesgo\s+/u',$text,$m))return trim((string)$m[1]);
        }
    }
    return '';
}

function edu_predictive_model_report_result(): array {
    $model=edu_predictive_model_load();
    if(empty($model['available']))return edu_chat_result(edu_predictive_model_unavailable_message($model));
    $training=(array)($model['training']??[]);$metrics=(array)($model['metrics']['holdout']??[]);$bench=(array)($model['metrics']['benchmark_random_forest']??[]);
    $lines=[];
    $lines[]='Modelo de producción: regresión logística explicable.';
    $lines[]='Objetivo: estimar riesgo de presentar al menos un curso crítico en el bimestre siguiente.';
    if(isset($training['rows']))$lines[]='Entrenamiento: '.(int)$training['rows'].' observaciones de '.(int)($training['students']??0).' estudiantes.';
    if(isset($metrics['roc_auc'])&&$metrics['roc_auc']!==null)$lines[]='ROC-AUC holdout: '.number_format((float)$metrics['roc_auc'],3).'.';
    if(isset($metrics['f1']))$lines[]='F1 holdout: '.number_format((float)$metrics['f1'],3).'.';
    if(isset($metrics['recall']))$lines[]='Recall holdout: '.number_format((float)$metrics['recall'],3).'.';
    if(isset($bench['roc_auc'])&&$bench['roc_auc']!==null)$lines[]='Benchmark Random Forest ROC-AUC: '.number_format((float)$bench['roc_auc'],3).'.';
    $lines[]='La evaluación separa estudiantes entre entrenamiento y prueba para reducir fuga de información.';
    $lines[]='La alerta apoya la decisión humana; no determina automáticamente acciones sobre el estudiante.';
    $result=edu_chat_result(implode("\n",$lines));$result['tools_used']=['predictive_risk_model_report'];return$result;
}

function edu_predictive_try(mysqli $conn,array $actor,string $message,array $state=[],array $history=[]): ?array {
    $n=function_exists('edu_chat_normalize')?edu_chat_normalize($message):strtolower(trim($message));
    if((int)($actor['type']??0)!==1)return null;

    if((strpos($n,'modelo predictivo')!==false||strpos($n,'precision del modelo')!==false||strpos($n,'metricas del modelo')!==false||strpos($n,'rendimiento del modelo')!==false)
       && (strpos($n,'precision')!==false||strpos($n,'metrica')!==false||strpos($n,'rendimiento')!==false||strpos($n,'modelo predictivo')!==false)){
        return edu_predictive_model_report_result();
    }

    if(!edu_predictive_is_query($message))return null;
    $entities=function_exists('edu_chat_adv_entities')?edu_chat_adv_entities($n,$state):[];
    $name=edu_predictive_requested_name($message,$history);
    $limit=30;
    if(preg_match('/\b(?:top|primeros|primeras|dame|muestrame|lista)\s+(?:los\s+|las\s+)?([1-9]|[1-4][0-9]|50)\b/',$n,$m))$limit=(int)$m[1];
    return edu_predictive_chat_result($conn,$actor,$entities,$name,$limit);
}
