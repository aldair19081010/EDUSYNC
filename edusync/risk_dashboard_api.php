<?php
ini_set('display_errors',0);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
ob_start();

$risk_api_replied=false;

function risk_api_reply(array $payload,int $status=200): void {
    global $risk_api_replied;
    $risk_api_replied=true;
    http_response_code($status);
    if(ob_get_level()>0)ob_clean();
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    if($json===false){
        http_response_code(500);
        $json='{"ok":false,"message":"No pude serializar la respuesta del módulo de alerta temprana."}';
    }
    echo $json;
    exit;
}

register_shutdown_function(function(){
    global $risk_api_replied;
    if($risk_api_replied)return;
    $last=error_get_last();
    $fatalTypes=[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR];
    if($last && in_array((int)$last['type'],$fatalTypes,true)){
        error_log('[risk_dashboard_api_v6_fatal] '.($last['message']??'Error fatal').' line '.($last['line']??0).' file '.($last['file']??''));
        if(!headers_sent()){
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        if(ob_get_level()>0)ob_clean();
        echo json_encode([
            'ok'=>false,
            'message'=>'La Alerta Temprana Inteligente encontró un error interno. Revisa el log de PHP/XAMPP.'
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

function risk_api_grade_base($v): string {
    $g=trim((string)$v);
    return trim((string)preg_replace('/(?:\s*°)+\s*$/u','',$g));
}
function risk_api_normalize_student(array $s): array {
    if(array_key_exists('grado',$s))$s['grado']=risk_api_grade_base($s['grado']);
    return $s;
}
function risk_api_normalize_course_dashboard(array $d): array {
    foreach((array)($d['students']??[]) as $i=>$row){
        if(isset($row['student']))$d['students'][$i]['student']=risk_api_normalize_student($row['student']);
    }
    foreach((array)($d['interventions']??[]) as $i=>$row){
        if(isset($row['grado']))$d['interventions'][$i]['grado']=risk_api_grade_base($row['grado']);
    }
    return $d;
}

/** Resolver mínimo exclusivo del dashboard. */
function risk_api_resolve_admin(mysqli $conn): ?array {
    $userId=(int)($_SESSION['login_id']??0);
    if($userId<=0)return null;
    $stmt=$conn->prepare('SELECT id,name,type,school_id FROM users WHERE id=? LIMIT 1');
    if(!$stmt)return null;
    $stmt->bind_param('i',$userId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row)return null;
    return ['kind'=>'user','role'=>(int)$row['type']===1?'Administrador':'Usuario','type'=>(int)$row['type'],'id'=>(int)$row['id'],'student_id'=>0,'teacher_id'=>0,'school_id'=>(int)$row['school_id'],'name'=>(string)$row['name'],'dni'=>'','nivel'=>'','grado'=>'','seccion'=>''];
}

try{
    require_once __DIR__.'/session_config.php';
    require_once __DIR__.'/db_connect.php';
    require_once __DIR__.'/includes/risk_dashboard_course_fast.php';
    require_once __DIR__.'/includes/predictive_course_interventions.php';

    $actor=risk_api_resolve_admin($conn);
    if(!$actor)risk_api_reply(['ok'=>false,'message'=>'Tu sesión ha expirado.'],401);
    if((int)($actor['type']??0)!==1)risk_api_reply(['ok'=>false,'message'=>'Este módulo está disponible únicamente para administración.'],403);

    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $action=trim((string)($_REQUEST['action']??'dashboard'));
    $filters=[];
    foreach(['level','grade','section','bimestre','search','risk_level','intervention_status','course_id'] as $key){$value=trim((string)($_REQUEST[$key]??''));if($value!=='')$filters[$key]=$value;}

    if($method==='GET'){
        if($action==='dashboard')risk_api_reply(risk_api_normalize_course_dashboard(edu_course_dashboard_data_fast($conn,$actor,$filters)));
        if($action==='student'){
            $studentId=(int)($_GET['student_id']??0);
            $detail=edu_course_dashboard_student_detail($conn,$actor,$studentId,!empty($filters['bimestre'])?(int)$filters['bimestre']:null);
            if(empty($detail['ok']))risk_api_reply($detail,422);
            $detail['prediction']['student']=risk_api_normalize_student($detail['prediction']['student']);
            $detail['interventions']=edu_risk_list_interventions($conn,$actor,['student_id'=>$studentId],100);
            $detail['intervention_types']=edu_risk_intervention_types();
            $detail['course_interventions_ready']=edu_course_interventions_ready($conn);
            risk_api_reply($detail);
        }
        if($action==='interventions'){
            $f=[];foreach(['level','grade','section'] as $key)if(!empty($filters[$key]))$f[$key]=$filters[$key];if(!empty($filters['intervention_status']))$f['status']=$filters['intervention_status'];
            risk_api_reply(['ok'=>true,'rows'=>edu_risk_list_interventions($conn,$actor,$f,200)]);
        }
        risk_api_reply(['ok'=>false,'message'=>'Acción no válida.'],404);
    }

    if($method!=='POST')risk_api_reply(['ok'=>false,'message'=>'Método no permitido.'],405);
    $token=(string)($_POST['csrf_token']??'');$session=(string)($_SESSION['csrf_token']??'');
    if($token===''||$session===''||!hash_equals($session,$token))risk_api_reply(['ok'=>false,'message'=>'La sesión de seguridad venció. Recarga la página.'],403);
    if($action==='create_intervention'){$result=edu_course_create_intervention($conn,$actor,$_POST);risk_api_reply($result,!empty($result['ok'])?200:422);}
    if($action==='update_intervention'){$id=(int)($_POST['id']??0);if($id<=0)risk_api_reply(['ok'=>false,'message'=>'Intervención no válida.'],422);$result=edu_course_update_intervention($conn,$actor,$id,$_POST);risk_api_reply($result,!empty($result['ok'])?200:422);}
    risk_api_reply(['ok'=>false,'message'=>'Acción no válida.'],404);
}catch(Throwable $e){
    error_log('[risk_dashboard_api_v6] '.$e->getMessage().' line '.$e->getLine().' file '.$e->getFile());
    risk_api_reply(['ok'=>false,'message'=>'No pude procesar la Alerta Temprana Inteligente. Revisa el log de PHP/XAMPP para ver el error interno.'],500);
}
