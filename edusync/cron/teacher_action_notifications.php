<?php
/**
 * Diagnóstico o ejecución manual de las alertas docentes.
 * php teacher_action_notifications.php --dry-run
 * php teacher_action_notifications.php --send
 *
 * La ejecución automática se integra con web_push_dispatch.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ini_set('display_errors','0');
date_default_timezone_set('America/Lima');
$send=in_array('--send',$argv,true);
require_once __DIR__.'/../db_connect.php';
require_once __DIR__.'/../includes/teacher_action_notifications.php';
$conn->query("SET time_zone = '-05:00'");
try {
    $result=tna_run($conn,!$send);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,'Error de alertas docentes: '.$e->getMessage().PHP_EOL);
    exit(1);
}
