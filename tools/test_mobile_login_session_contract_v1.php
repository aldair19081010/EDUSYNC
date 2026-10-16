<?php
/**
 * Check the Flutter session contract after 30-day Web remember-me.
 * Run: php tools/test_mobile_login_session_contract_v1.php
 * Does not connect to the database or authenticate any account.
 */
$root=dirname(__DIR__).'/edusync/';
$personal=file_get_contents($root.'auth_login.php');
$student=file_get_contents($root.'api/login.php');
$remember=file_get_contents($root.'includes/remember_session.php');

function assert_contract(bool $ok,string $message):void {
    if(!$ok){fwrite(STDERR,"FAIL: ".$message.PHP_EOL);exit(1);}
    echo "OK: ".$message.PHP_EOL;
}
foreach(['Personal'=>$personal,'Estudiantes'=>$student] as $name=>$code){
    assert_contract(strpos($code,'$mobile_session_id = session_id();')!==false,
        $name.': captura el ID de sesión PHP antes de liberar la sesión.');
    assert_contract(strpos($code,"'session_id' => \$mobile_session_id")!==false,
        $name.': devuelve session_id que la aplicación necesita.');
    assert_contract(strpos($code,'session_write_close();')!==false,
        $name.': mantiene el cierre de la sesión PHP.');
    assert_contract(strpos($code,'edrs_process_login(')!==false &&
        strpos($code,"'remember_saved' => \$remember_saved")!==false,
        $name.': conserva sin cambios la opción de recordar 30 días en web.');
}
assert_contract(strpos($remember,'edrs_cookie_parts()')!==false,
    'Se mantiene la restauración segura del navegador independiente de Flutter.');
echo "Comprobación de contrato terminada; pruebas reales requieren el servidor y un dispositivo Flutter.".PHP_EOL;
