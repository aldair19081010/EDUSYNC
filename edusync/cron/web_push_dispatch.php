<?php
/**
 * Reintentos y respaldo FCM Web Push de EduSync (CLI, p. ej. cada minuto).
 * El MISMO despachador opera en eventos inmediatos y en este proceso.
 * Mantener el cron permite recuperarse de caídas de red, errores o carreras.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ini_set('display_errors','0');
date_default_timezone_set('America/Lima');
require_once __DIR__.'/../db_connect.php';
require_once __DIR__.'/../includes/web_push_dispatcher.php';
$conn->query("SET time_zone = '-05:00'");
$result=edwp_dispatch_pending($conn);
if (!empty($result['error'])) {
    fwrite(STDERR,"Web Push: ".$result['error']."\n");
    exit(1);
}
if (!empty($result['skipped'])) {
    echo "Ya hay otro despachador de notificaciones en curso.\n";
    exit(0);
}
echo "Web Push: ".(int)$result['sent']." enviados, ".(int)$result['failed']." fallidos.\n";
