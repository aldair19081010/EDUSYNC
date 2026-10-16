<?php
/**
 * Prueba estática sin base de datos ni mensajes FCM.
 * php tools/test_teacher_academic_alerts_v1.php
 */
require_once __DIR__.'/../edusync/includes/teacher_action_notifications.php';

function tna_assert(bool $ok,string $description):void {
    if (!$ok) { fwrite(STDERR,"ERROR: $description\n");exit(1); }
    echo "OK: $description\n";
}
$source=file_get_contents(__DIR__.'/../edusync/includes/teacher_action_notifications.php');
$cron=file_get_contents(__DIR__.'/../edusync/cron/web_push_dispatch.php');
$inbox=file_get_contents(__DIR__.'/../edusync/notifications_api.php');

tna_assert(strpos($source,'edusync_teacher_alerts_v1')!==false,
    'Existe candado para generación concurrente.');
tna_assert(strpos($source,'recipient_user_id=? AND source_type=? AND source_id=?')!==false,
    'La deduplicación separa destinatarios, tipo y periodo.');
tna_assert(strpos($source,'gpc.status=')!==false &&
    strpos($source,'ap.is_locked=0')!==false,
    'No se recuerda cerrar un bimestre ya cerrado o bloqueado.');
tna_assert(strpos($source,"h.previous_grade IS NOT NULL")!==false &&
    strpos($source,"editor.type=1")!==false,
    'Solo se identifican cambios de nota existente por administración.');
tna_assert(strpos($source,'GROUP BY h.school_id,u.id,e.teacher_course_id,')!==false,
    'Se agrupan los cambios por docente y curso.');
tna_assert(strpos($cron,'tna_run($conn)')!==false,
    'El despacho FCM ejecuta el generador de avisos.');
tna_assert(strpos($inbox,"'teacher_admin_grade_change'")!==false,
    'La bandeja abre detalles de la notificación contextual.');
echo "Controles estáticos completados. Faltan pruebas de base de datos y FCM.\n";
