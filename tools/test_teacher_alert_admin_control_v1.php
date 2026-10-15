<?php
/**
 * Contratos estáticos SIN base de datos ni envío de notificaciones.
 * php tools/test_teacher_alert_admin_control_v1.php
 */
function assertion(bool $test,string $name):void {
    if(!$test){fwrite(STDERR,"FALLO: $name\n");exit(1);}
    echo "OK: $name\n";
}
$root=dirname(__DIR__);
$api=file_get_contents($root.'/edusync/teacher_notifications_admin_api.php');
$scan=file_get_contents($root.'/edusync/includes/teacher_action_notifications.php');
$page=file_get_contents($root.'/edusync/academic_year.php');
$js=file_get_contents($root.'/edusync/js/teacher_notifications_admin.js');
$notificationApi=file_get_contents($root.'/edusync/notifications_api.php');
$sql=file_get_contents($root.'/sql/teacher_alert_admin_control_v1.sql');
assertion(strpos($api,'(int)$account[\'type\']!==1')!==false,
    'Solo un administrador autenticado puede usar el endpoint.');
assertion(strpos($api,'hash_equals($session,$incoming)')!==false,
    'Las acciones POST requieren CSRF.');
assertion(strpos($api,'taca_year($conn,$school,$yearId)')!==false
    && strpos($api,'taca_period($conn,$school,$yearId,$bim)')!==false,
    'Año y bimestre se verifican contra la institución.');
assertion(strpos($api,"GET_LOCK(?,5)")!==false
    && strpos($api,'INTERVAL 24 HOUR')!==false,
    'Los recordatorios manuales están serializados y limitados a 24 horas.');
assertion(strpos($api,'edwp_queue_staff_event(')!==false,
    'El aviso usa la cola FCM compartida del personal.');
assertion(strpos($scan,"COALESCE(tns.deadline_date,ap.end_date)")!==false
    && strpos($scan,"COALESCE(tns.incomplete_enabled,1)")!==false
    && strpos($scan,"COALESCE(tns.admin_edits_enabled,1)")!==false,
    'El generador respeta plazos y preferencias de cada bimestre.');
assertion(strpos($api,"taca_missing_cells")===false && strpos($api,"tna_missing_cells")!==false,
    'El panel reutiliza el cálculo existente de notas sin completar.');
assertion(strpos($page,'ay-teacher-alert-control')!==false &&
    strpos($page,'taca-reminder-modal')!==false,
    'Interfaz y confirmación de recordatorio disponibles en cierre de notas.');
assertion(strpos($notificationApi,"'teacher_manual_reminder'")!==false,
    'La bandeja usa el ID autorizado del evento manual.');
assertion(strpos($js,'escape')===false && strpos($js,"function esc(s)")!==false,
    'El renderizado HTML del panel escapa las cadenas mostradas.');
assertion(strpos($sql,'UNIQUE KEY uniq_teacher_alert_period')!==false,
    'Configuración única por institución/año/bimestre.');
echo "Verificación estática completa. Se requiere validar consultas y FCM en XAMPP.\n";
