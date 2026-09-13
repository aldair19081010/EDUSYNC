<?php
ini_set('display_errors',0);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/chatbot_engine.php';
require_once __DIR__ . '/includes/predictive_interventions.php';

function risk_api_reply(array $payload,int $status=200): void {
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $actor=edu_chat_resolve_actor($conn);
    if(!$actor)risk_api_reply(['ok'=>false,'message'=>'Tu sesión ha expirado.'],401);
    if((int)($actor['type']??0)!==1)risk_api_reply(['ok'=>false,'message'=>'Este módulo está disponible únicamente para administración.'],403);

    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $action=trim((string)($_REQUEST['action']??'dashboard'));
    $entities=[];
    foreach(['level','grade','section','bimestre'] as $key){
        $value=trim((string)($_REQUEST[$key]??''));
        if($value!=='')$entities[$key]=$value;
    }

    if($method==='GET'){
        if($action==='dashboard')risk_api_reply(edu_risk_dashboard_data($conn,$actor,$entities));
        if($action==='student'){
            $studentId=(int)($_GET['student_id']??0);
            $student=edu_risk_student_by_id($conn,$actor,$studentId);
            if(!$student)risk_api_reply(['ok'=>false,'message'=>'No encontré al estudiante solicitado.'],404);
            $prediction=edu_predictive_student_prediction($conn,$actor,$studentId,!empty($entities['bimestre'])?(int)$entities['bimestre']:null);
            $counterfactual=edu_risk_counterfactual_for_student($conn,$actor,$studentId,!empty($entities['bimestre'])?(int)$entities['bimestre']:null);
            $interventions=edu_risk_list_interventions($conn,$actor,['student_id'=>$studentId],100);
            risk_api_reply(['ok'=>true,'student'=>$student,'prediction'=>$prediction,'counterfactual'=>$counterfactual,'interventions'=>$interventions,'types'=>edu_risk_intervention_types(),'statuses'=>edu_risk_statuses(),'outcomes'=>edu_risk_outcomes(),'migration_ready'=>edu_risk_interventions_ready($conn)]);
        }
        if($action==='interventions')risk_api_reply(['ok'=>true,'rows'=>edu_risk_list_interventions($conn,$actor,$entities,200),'statuses'=>edu_risk_statuses(),'outcomes'=>edu_risk_outcomes()]);
        risk_api_reply(['ok'=>false,'message'=>'Acción no válida.'],404);
    }

    if($method!=='POST')risk_api_reply(['ok'=>false,'message'=>'Método no permitido.'],405);
    $token=(string)($_POST['csrf_token']??'');$sessionToken=(string)($_SESSION['csrf_token']??'');
    if($token===''||$sessionToken===''||!hash_equals($sessionToken,$token))risk_api_reply(['ok'=>false,'message'=>'La sesión de seguridad venció. Recarga la página.'],403);

    if($action==='create_intervention'){
        $result=edu_risk_create_intervention($conn,$actor,$_POST);
        risk_api_reply($result,$result['ok']?200:422);
    }
    if($action==='update_intervention'){
        $id=(int)($_POST['id']??0);
        if($id<=0)risk_api_reply(['ok'=>false,'message'=>'Intervención no válida.'],422);
        $result=edu_risk_update_intervention($conn,$actor,$id,$_POST);
        risk_api_reply($result,$result['ok']?200:422);
    }
    risk_api_reply(['ok'=>false,'message'=>'Acción no válida.'],404);
} catch(Throwable $e){
    error_log('[risk_dashboard_api] '.$e->getMessage().' line '.$e->getLine());
    risk_api_reply(['ok'=>false,'message'=>'No pude procesar la solicitud del panel de riesgo.'],500);
}
