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

/**
 * Convierte sólo los registros heredados que evidencian el desfase UTC -> Lima.
 *
 * Antes de fijar la zona horaria, payment_date (DATETIME) podía guardarse con
 * la hora UTC como si fuera hora local. created_at es TIMESTAMP y, al leerlo
 * con la sesión MySQL en -05:00, devuelve la hora local real de creación.
 *
 * El margen de 15 minutos contempla el tiempo transcurrido entre abrir el
 * formulario y guardar. Los registros que no cumplen el patrón se conservan.
 * No se actualiza ni se modifica ningún dato almacenado.
 */
if (!function_exists('edusync_payment_datetime_for_display')) {
    function edusync_payment_datetime_for_display($paymentDate, $createdAt = null): string
    {
        $paymentDate = trim((string)$paymentDate);
        if ($paymentDate === '') return '';

        $paymentTimestamp = strtotime($paymentDate);
        if ($paymentTimestamp === false) return $paymentDate;

        $createdAt = trim((string)$createdAt);
        if ($createdAt !== '') {
            $createdTimestamp = strtotime($createdAt);
            if ($createdTimestamp !== false) {
                $difference = $paymentTimestamp - $createdTimestamp;
                // Entre 04:45 y 05:15: patrón característico de UTC mostrado como Lima.
                if ($difference >= 17100 && $difference <= 18900) {
                    $paymentTimestamp -= 18000;
                }
            }
        }

        return date('Y-m-d H:i:s', $paymentTimestamp);
    }
}

if (!function_exists('edusync_payment_datetime_sql')) {
    function edusync_payment_datetime_sql(string $alias = 'po'): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $alias)) {
            throw new InvalidArgumentException('Alias SQL no permitido.');
        }

        $createdAt = "`$alias`.`created_at`";
        $paymentDate = "`$alias`.`payment_date`";

        return "(CASE WHEN $createdAt IS NOT NULL
            AND TIMESTAMPDIFF(SECOND, $createdAt, $paymentDate) BETWEEN 17100 AND 18900
            THEN DATE_SUB($paymentDate, INTERVAL 5 HOUR)
            ELSE $paymentDate END)";
    }
}
