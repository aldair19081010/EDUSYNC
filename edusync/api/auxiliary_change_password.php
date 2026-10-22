<?php
declare(strict_types=1);
// Cambio voluntario de contraseña del Auxiliar, igual política que Docentes.
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function apw_reply(int $http,string $status,string $message): void {
    http_response_code($http);
    echo json_encode(['status'=>$status,'message'=>$message],JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    apw_reply(405,'error','Método no permitido.');
}
$sid=trim((string)($_POST['sid']??''));
if (!preg_match('/^[a-zA-Z0-9,-]{16,128}$/',$sid)
    || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))!=='xmlhttprequest') {
    apw_reply(401,'error','Sesión no válida.');
}
require_once __DIR__.'/../session_config.php';
require_once __DIR__.'/../db_connect.php';
$school=(int)($_SESSION['login_school_id']??0);
$user=(int)($_SESSION['login_id']??0);
if (!$school||!$user||(int)($_SESSION['login_type']??0)!==3
    || !hash_equals(session_id(),$sid)) {
    apw_reply(403,'error','No autorizado para cambiar esta contraseña.');
}
$current=(string)($_POST['current_password']??'');
$new=(string)($_POST['new_password']??'');
$confirm=(string)($_POST['confirm_password']??'');
if ($current===''||$new===''||$confirm==='') apw_reply(422,'error','Completa todos los campos.');
if (!hash_equals($new,$confirm)) apw_reply(422,'error','Las contraseñas nuevas no coinciden.');
$len=function_exists('mb_strlen')?mb_strlen($new,'UTF-8'):strlen($new);
if ($len<8||$len>64||!preg_match('/\p{L}/u',$new)||!preg_match('/\d/u',$new))
    apw_reply(422,'error','Usa de 8 a 64 caracteres, incluyendo letras y números.');
if (hash_equals($current,$new)) apw_reply(422,'error','La contraseña nueva debe ser diferente.');
try {
    $q=$conn->query("SHOW COLUMNS FROM users LIKE 'status'");
    $active=($q&&$q->num_rows)?" AND status='Activo'":'';
    $q=$conn->prepare("SELECT password FROM users
        WHERE id=? AND school_id=? AND type=3 {$active} LIMIT 1");
    $q->bind_param('ii',$user,$school);
    $q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
    if(!$row)apw_reply(403,'error','Cuenta Auxiliar inactiva.');
    $oldHash=(string)$row['password'];
    $verified=$oldHash!==''&&(
        password_verify($current,$oldHash)||
        (preg_match('/^[a-f0-9]{32}$/i',$oldHash)&&
            hash_equals(strtolower($oldHash),md5($current)))
    );
    if(!$verified)apw_reply(422,'error','La contraseña actual es incorrecta.');
    $newHash=password_hash($new,PASSWORD_DEFAULT);
    if(!$newHash)throw new RuntimeException('hash');
    $q=$conn->prepare("UPDATE users SET password=?
        WHERE id=? AND school_id=? AND type=3 AND password=?");
    $q->bind_param('siis',$newHash,$user,$school,$oldHash);
    if(!$q->execute())throw new RuntimeException('update');
    $affected=$q->affected_rows;$q->close();
    if($affected!==1)apw_reply(409,'error','La contraseña cambió en otra sesión. Inicia sesión nuevamente.');
    apw_reply(200,'ok','Contraseña actualizada correctamente.');
} catch (Throwable $e) {
    error_log('[auxiliary password] '.$e->getMessage());
    apw_reply(500,'error','No se pudo actualizar la contraseña.');
}
