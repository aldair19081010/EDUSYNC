<?php
// Verificaciones sin conexión a MySQL ni envíos FCM.
$root=dirname(__DIR__).'/edusync/';
$sender=file_get_contents($root.'includes/web_push_fcm_sender.php');
$staff=file_get_contents($root.'includes/web_push_staff_events.php');
$cron=file_get_contents($root.'cron/web_push_dispatch.php');
$web=file_get_contents($root.'api/web_push.php');
function verify_contract(bool $ok,string $label):void {
    if(!$ok){fwrite(STDERR,"FALLO: ".$label.PHP_EOL);exit(1);}
    echo "OK: ".$label.PHP_EOL;
}
verify_contract(strpos($sender,'nativeApp')!==false &&
  strpos($sender,"'channel_id'=>'staff_alerts'")!==false,
  'Envio nativo Android separado de la ruta web.');
verify_contract(strpos($staff,'s.browser_id')!==false &&
  strpos($staff,'strncmp(')!==false,
  'Despacho inmediato: selecciona navegador o app.');
verify_contract(strpos($cron,'x.browser_id')!==false &&
  strpos($cron,"'student'")!==false,
  'Despacho del cron conserva estudiantes y token por dispositivo.');
verify_contract(strpos($web,'app_status')!==false &&
  strpos($web,"account_kind='staff' AND role_type=2")!==false &&
  strpos($web,'AND account_id=? AND browser_id=?')!==false,
  'Diagnostico autorizado por identidad docente y dispositivo.');
verify_contract(strpos($web,'sender_project_id')!==false,
  'Se comprueba compatibilidad del proyecto Firebase.');
echo "Verificacion estatica terminada; falta prueba FCM en un Android real.".PHP_EOL;
