<?php
/**
 * Verificación estática de integración: NO conecta con BD ni envía Firebase.
 * Para entrega real usar también el escenario descrito en la documentación.
 */
declare(strict_types=1);
$root=dirname(__DIR__);
function wu_code(string $file): string {
    global $root;
    $source=file_get_contents($root.'/'.$file);
    if ($source===false) throw new RuntimeException('Falta '.$file);
    return $source;
}
function wu_assert(bool $ok,string $why): void {
    if (!$ok) {fwrite(STDERR,"ERROR: ".$why.PHP_EOL);exit(1);}
}
$dispatch=wu_code('edusync/includes/web_push_dispatcher.php');
$cron=wu_code('edusync/cron/web_push_dispatch.php');
$android=wu_code('edusync/includes/push_notifications.php');
$collection=wu_code('edusync/collection_notifications_api.php');
$academic=wu_code('edusync/admin_class.php');
$closures=wu_code('edusync/grade_closure_api.php');
$admin=wu_code('edusync/admin_grade_closure_api.php');
$attendance=wu_code('edusync/attendance_api.php');

wu_assert(str_contains($dispatch,'function edwp_dispatch_pending(')
    && str_contains($dispatch,'function edwp_dispatch_now('),
    'Solo debe haber un motor compartido de despacho.');
wu_assert(str_contains($cron,'edwp_dispatch_pending($conn)')
    && !str_contains($cron,'edwp_send_message('),
    'Cron debe reutilizar despacho común sin segundo algoritmo.');
wu_assert(str_contains($dispatch,"GET_LOCK('edusync_web_push_fcm_v1',0)")
    && str_contains($dispatch,"RELEASE_LOCK('edusync_web_push_fcm_v1')")
    && str_contains($dispatch,'web_push_delivery')
    && str_contains($dispatch,'$pending'),
    'Evitar carreras y reenvíos sobre el mismo dispositivo.');
wu_assert(str_contains($android,'push_schedule_web_delivery($db,$result[\'event_id\'])')
    && str_contains($android,'register_shutdown_function')
    && str_contains($android,'@@in_transaction'),
    'Solo enviar eventos estudiantiles después de la operación.');
wu_assert(str_contains($collection,'$GLOBALS[\'edwp_defer_immediate\']')
    && str_contains($collection,'edwp_dispatch_now($conn, \'student\', $webEventIds, 20)')
    && str_contains($collection,"'web_push_sent'")
    && str_contains($collection,"'push_sent'"),
    'Las cobranzas usan lote Web Push y métricas separadas de Android.');
wu_assert(str_contains($dispatch,"notification_type='collection_notice'")
    && str_contains($dispatch,'Consulta Mis Deudas en EduSync.')
    && !str_contains($dispatch,'e.body,e.screen'),
    'No mostrar importes de cobranza en pantalla bloqueada.');
wu_assert(str_contains($academic,'edwp_dispatch_now($this->db,\'low_grade\'')
    && str_contains($academic,'edwp_dispatch_now($this->db,\'evaluation\''),
    'Activar solo nuevos episodios académicos y nuevas evaluaciones.');
wu_assert(str_contains($closures,'edwp_dispatch_now($conn,\'staff\'')
    && str_contains($closures,'edwp_dispatch_now($conn,\'reopen_req\''),
    'El cierre, la resolución y solicitud de reapertura necesitan Push.');
wu_assert(str_contains($admin,'edwp_dispatch_now($conn,\'staff\''),
    'Administración debe activar avisos docentes después del COMMIT.');
wu_assert(str_contains($attendance,"'att_request'")
    && str_contains($attendance,"'att_result'")
    && str_contains($attendance,"'att_admin'"),
    'Enviar solicitudes de asistencia a perfiles correspondientes.');
wu_assert(str_contains($dispatch,"s.account_kind='student'")
    && str_contains($dispatch,"s.account_kind='staff'")
    && str_contains($dispatch,"s.account_id=e.student_id")
    && str_contains($dispatch,'e.recipient_user_id=s.account_id'),
    'Mantener segmentación de destinatarios y colegio.');
echo "OK: un solo despachador, flujo automático, reintentos, privacidad y roles.\n";
