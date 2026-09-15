<?php
ini_set('display_errors',0);error_reporting(E_ALL);date_default_timezone_set('America/Lima');header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');
require_once __DIR__.'/session_config.php';require_once __DIR__.'/db_connect.php';require_once __DIR__.'/includes/chatbot_engine.php';require_once __DIR__.'/includes/risk_dashboard_course.php';require_once __DIR__.'/includes/predictive_course_interventions.php';
function risk_api_reply(array $p,int $status=200): void{http_response_code($status);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function risk_api_grade_base($v): string{$g=trim((string)$v);return trim((string)preg_replace('/(?:\s*°)+\s*$/u','',$g));}
function risk_api_normalize_student(array $s): array{if(array_key_exists('grado',$s))$s['grado']=risk_api_grade_base($s['grado']);return$s;}
function risk_api_normalize_course_dashboard(array $d): array{foreach((array)($d['students']??[]) as $i=>$row)if(isset($row['student']))$d['students'][$i]['student']=risk_api_normalize_student($row['student']);foreach((array)($d['interventions']??[]) as $i=>$row)if(isset($row['grado']))$d['interventions'][$i]['grado']=risk_api_grade_base($row['grado']);return$d;}
try{
 $actor=edu_chat_resolve_actor($conn);if(!$actor)risk_api_reply(['ok'=>false,'message'=>'Tu sesión ha expirado.'],401);if((int)($actor['type']??0)!==1)risk_api_reply(['ok'=>false,'message'=>'Este módulo está disponible únicamente para administración.'],403);
 $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));$action=trim((string)($_REQUEST['action']??'dashboard'));$filters=[];foreach(['level','grade','section','bimestre','search','risk_level','intervention_status','course_id'] as $k){$v=trim((string)($_REQUEST[$k]??''));if($v!=='')$filters[$k]=$v;}
 if($method==='GET'){
  if($action==='dashboard')risk_api_reply(risk_api_normalize_course_dashboard(edu_course_dashboard_data($conn,$actor,$filters)));
  if($action==='student'){$studentId=(int)($_GET['student_id']??0);$detail=edu_course_dashboard_student_detail($conn,$actor,$studentId,!empty($filters['bimestre'])?(int)$filters['bimestre']:null);if(empty($detail['ok']))risk_api_reply($detail,422);$detail['prediction']['student']=risk_api_normalize_student($detail['prediction']['student']);$detail['interventions']=edu_risk_list_interventions($conn,$actor,['student_id'=>$studentId],100);$detail['intervention_types']=edu_risk_intervention_types();$detail['course_interventions_ready']=edu_course_interventions_ready($conn);risk_api_reply($detail);}
  if($action==='interventions'){$f=[];foreach(['level','grade','section'] as $k)if(!empty($filters[$k]))$f[$k]=$filters[$k];if(!empty($filters['intervention_status']))$f['status']=$filters['intervention_status'];risk_api_reply(['ok'=>true,'rows'=>edu_risk_list_interventions($conn,$actor,$f,200)]);}
  risk_api_reply(['ok'=>false,'message'=>'Acción no válida.'],404);
 }
 if($method!=='POST')risk_api_reply(['ok'=>false,'message'=>'Método no permitido.'],405);$token=(string)($_POST['csrf_token']??'');$session=(string)($_SESSION['csrf_token']??'');if($token===''||$session===''||!hash_equals($session,$token))risk_api_reply(['ok'=>false,'message'=>'La sesión de seguridad venció. Recarga la página.'],403);
 if($action==='create_intervention'){$r=edu_course_create_intervention($conn,$actor,$_POST);risk_api_reply($r,!empty($r['ok'])?200:422);}
 if($action==='update_intervention'){$id=(int)($_POST['id']??0);if($id<=0)risk_api_reply(['ok'=>false,'message'=>'Intervención no válida.'],422);$r=edu_course_update_intervention($conn,$actor,$id,$_POST);risk_api_reply($r,!empty($r['ok'])?200:422);}
 risk_api_reply(['ok'=>false,'message'=>'Acción no válida.'],404);
}catch(Throwable $e){error_log('[risk_dashboard_api_v6] '.$e->getMessage().' line '.$e->getLine());risk_api_reply(['ok'=>false,'message'=>'No pude procesar la Alerta Temprana Inteligente. Revisa el modelo v6 y los datos del periodo.'],500);}
