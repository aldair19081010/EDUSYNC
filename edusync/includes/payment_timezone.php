<?php
/**
 * Zona horaria oficial del módulo financiero.
 *
 * Los campos DATETIME de pagos y caja representan hora local de Perú.
 * Se fija también la zona horaria de la sesión MySQL para que NOW(),
 * CURDATE() y las columnas TIMESTAMP utilicen el mismo huso horario.
 *
 * Se usa un offset fijo en MySQL para no depender de que estén instaladas
 * las tablas de zonas horarias del servidor.
 */
date_default_timezone_set('America/Lima');

if (isset($conn) && $conn instanceof mysqli) {
    if (!$conn->query("SET SESSION time_zone = '-05:00'")) {
        error_log('[EduSync pagos] No se pudo establecer la zona horaria MySQL: ' . $conn->error);
    }
}
