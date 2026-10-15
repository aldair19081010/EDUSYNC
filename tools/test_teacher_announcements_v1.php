<?php
/**
 * Verificación estática de integración, sin tocar la BD ni enviar push.
 * php tools/test_teacher_announcements_v1.php
 */
$root=dirname(__DIR__).'/edusync/';
$api=file_get_contents($root.'teacher_announcements_api.php');
$ui=file_get_contents($root.'pages/announcements.php');
$students=file_get_contents($root.'announcements_api.php');
$inbox=file_get_contents($root.'notifications_api.php');
$detail=file_get_contents($root.'notification_detail.php');
$sql=file_get_contents(dirname(__DIR__).'/sql/teacher_announcements_v1.sql');

function expected(bool $ok,string $label):void {
    if(!$ok){fwrite(STDERR,"FALLO: ".$label.PHP_EOL);exit(1);}
    echo "OK: ".$label.PHP_EOL;
}
expected(strpos($api,'teacher_announcement_recipients')!==false &&
    strpos($api,'notification_events')!==false,
    'Comunicado registrado por cada usuario en bandeja.');
expected(strpos($api,'$role!==2')!==false &&
    strpos($api,'ne.recipient_user_id=?')!==false &&
    strpos($api,'ne.school_id=?')!==false,
    'Detalle completo restringido al docente y colegio autorizados.');
expected(strpos($api,'$role!==1')===false &&
    strpos($api,'if(!$isAdmin)')!==false &&
    strpos($api,'hash_equals(')!==false,
    'Administrador con CSRF obligatorio para enviar.');
expected(strpos($api,'ta_users($conn,$school,$ids)')!==false &&
    strpos($api,'count($targets)!==count($ids)')!==false,
    'Destinatarios activos comprobados por servidor.');
expected(strpos($sql,'UNIQUE KEY uniq_teacher_announcement_request')!==false &&
    strpos($sql,'UNIQUE KEY uniq_teacher_announcement_user')!==false &&
    strpos($api,'begin_transaction()')!==false,
    'Envío transaccional sin duplicados.');
expected(strpos($ui,'id="an-teacher-select"')!==false &&
    strpos($ui,'teacher_announcements_api.php')!==false &&
    strpos($ui,'select2(')!==false,
    'Comunicados conserva Select2 y permite elegir docentes.');
expected(strpos($inbox,"'teacher_announcement'")!==false &&
    strpos($detail,'$row[\'source_type\']===\'teacher_announcement\'')!==false,
    'La bandeja web abre el texto completo autorizado.');
expected(strpos($students,'push_send_announcement_notification(')!==false &&
    strpos($ui,"const api='announcements_api.php'")!==false,
    'Se conserva la ruta original de estudiantes.');
echo "Contratos estáticos verificados. Faltan pruebas XAMPP y FCM real.".PHP_EOL;
