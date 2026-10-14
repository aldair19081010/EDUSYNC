<?php
// Tests puros: no requiere MySQL, migración ni claves Firebase.
declare(strict_types=1);
require_once __DIR__.'/../edusync/includes/remember_session.php';
function check_remember(bool $condition, string $description): void {
    if (!$condition) {
        fwrite(STDERR, 'FALLO: '.$description.PHP_EOL);
        exit(1);
    }
}
$selector = str_repeat('a', 24);
$verifier = str_repeat('b', 64);
$_COOKIE[EDRS_COOKIE]=$selector.'.'.$verifier;
check_remember(edrs_cookie_parts()===[$selector,$verifier],
    'Cookie debe contener selector y verificador hexadecimales');
$_COOKIE[EDRS_COOKIE] = $selector.'.'.str_repeat('z',64);
check_remember(edrs_cookie_parts()===null,'Rechazar cookie inválida');
$_COOKIE[EDRS_COOKIE]=$selector.'.'.$verifier.'.extra';
check_remember(edrs_cookie_parts()===null,'Rechazar sufijos');
$_COOKIE[EDRS_COOKIE]='';
check_remember(edrs_cookie_parts()===null,'Ignorar cookie vacía');
check_remember(edrs_fingerprint('passhash')===hash('sha256','passhash'),
    'No guardar el hash real de contraseña');
check_remember(edrs_fingerprint('passhash')!==edrs_fingerprint('otherhash'),
    'Cambiar contraseña invalida el dispositivo');
$_SESSION=[];
check_remember(!edrs_has_session(),'Sesión anónima');
$_SESSION=['login_id'=>10,'login_school_id'=>3];
check_remember(edrs_has_session(),'Sesión de personal');
$_SESSION=['student_logged_in'=>true,'student_id'=>24,'student_school_id'=>3];
check_remember(edrs_has_session(),'Sesión de estudiante');
$_SESSION=[];
$_SERVER['HTTP_HOST']='localhost:8080';
$_SERVER['HTTPS']='off';$_SERVER['SERVER_PORT']=80;
check_remember(edrs_cookie_allowed(),'Permitir localhost para pruebas XAMPP');
$_SERVER['HTTP_HOST']='example.edu';
check_remember(!edrs_cookie_allowed(),'No crear cookie persistente en HTTP público');
$_SERVER['HTTPS']='on';
check_remember(edrs_cookie_allowed(),'Permitir producción HTTPS');
$cookie=edrs_cookie_options(time()+EDRS_TTL);
check_remember($cookie['secure']===true && $cookie['httponly']===true
    && $cookie['samesite']==='Lax' && $cookie['path']==='/',
    'Cookie protegida Secure, HttpOnly y SameSite');
check_remember(EDRS_TTL===2592000,'Duración absoluta de 30 días');
echo 'OK: formatos de cookie, duración, aislamiento, contraseña y HTTPS.'.PHP_EOL;
