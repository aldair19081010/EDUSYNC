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
$oldPage=file_get_contents($root.'/edusync/academic_year.php');
$page=file_get_contents($root.'/edusync/pages/teacher_followup.php');
$index=file_get_contents($root.'/edusync/index.php');
$sidebar=file_get_contents($root.'/edusync/includes/navbar_view.php');
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
assertion(strpos($api,'function taca_missing_by_course(')!==false &&
    strpos($api,'GROUP BY tc.id')!==false &&
    strpos($api,'function taca_teachers(')!==false,
    'Las notas incompletas se agrupan por asignación en una sola consulta.');
assertion(strpos($api,"if($action==='missing_counts')")!==false &&
    strpos($api,"if($action==='history')")!==false,
    'Carga separada de notas e historial bajo demanda.');
assertion(strpos($api,'session_write_close()')!==false,
    'La consulta de notas no bloquea la sesión PHP del administrador.');
assertion(strpos($api,"AND event_id IN ($in)")!==false,
    'Las entregas FCM se limitan a los eventos seleccionados.');
assertion(strpos($page,'teacher-followup-root')!==false &&
    strpos($page,'taca-reminder-modal')!==false,
    'Interfaz y confirmación de recordatorio en página independiente.');
assertion(strpos($oldPage,'ay-teacher-alert-control')===false &&
    strpos($oldPage,'taca-reminder-modal')===false,
    'El cierre de notas ya no incluye el panel ni el modal de avisos.');
assertion(strpos($index,"'teacher_followup'")!==false &&
    strpos($sidebar,"'page' => 'teacher_followup'")!==false,
    'Nuevo módulo registrado en whitelist y menú del administrador.');
assertion(strpos($page,'taca-settings-modal')!==false &&
    strpos($page,'taca-history-modal')!==false &&
    strpos($js,"$('#taca-settings-open')")!==false &&
    strpos($js,"$('#taca-history-open')")!==false,
    'Configuración e historial accesibles en diálogos separados.');
assertion(strpos($js,"$('#taca-year')")!==false &&
    strpos($js,'function canEdit()')!==false,
    'Cambio de año y protección de años históricos en UI.');
assertion(strpos($page,'id="taca-teachers-table"')!==false &&
    strpos($js,"$('#taca-teachers-table').DataTable(")!==false,
    'Listado normalizado con DataTables, paginación, búsqueda y ordenación.');
assertion(strpos($js,"api({action:'history'},false)")!==false &&
    strpos($js,"api({action:'missing_counts'},false)")!==false,
    'Notas y FCM cargan después de los cierres, sin bloquear el render inicial.');
assertion(strpos($page,'id="taca-search"')===false,
    'Se utiliza el buscador propio de DataTables, sin buscadores duplicados.');
assertion(strpos($notificationApi,"'teacher_manual_reminder'")!==false,
    'La bandeja usa el ID autorizado del evento manual.');
assertion(strpos($js,'function esc(value)')!==false,
    'El renderizado HTML del panel escapa las cadenas mostradas.');
assertion(strpos($sql,'UNIQUE KEY uniq_teacher_alert_period')!==false,
    'Configuración única por institución/año/bimestre.');
echo "Verificación estática completa. Se requiere validar consultas y FCM en XAMPP.\n";
