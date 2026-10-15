<?php
/**
 * Fechas de notificaciones académicas en Perú.
 *
 * evaluations.created_at puede ser DATETIME en instalaciones antiguas
 * (almacena la hora del servidor sin conversión), o TIMESTAMP
 * (MySQL ya convierte según SET time_zone = '-05:00').
 *
 * Las instalaciones DATETIME de producción guardan el valor en UTC.
 * No modificar jamás filas históricas para ajustar su presentación.
 */
if (!function_exists('edun_evaluation_created_at_is_datetime')) {
    function edun_evaluation_created_at_is_datetime(mysqli $db): bool {
        $result = $db->query("SHOW COLUMNS FROM `evaluations` LIKE 'created_at'");
        if (!$result) return false;
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) && stripos((string)($row['Type'] ?? ''), 'datetime') === 0;
    }
}

if (!function_exists('edun_evaluation_created_at_lima_sql')) {
    function edun_evaluation_created_at_lima_sql(mysqli $db, string $alias = 'e'): string {
        // El identificador sólo puede provenir de código, no de la petición web.
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $alias)) {
            throw new InvalidArgumentException('Alias SQL no permitido.');
        }
        $column = "`$alias`.`created_at`";
        // DATETIME histórico: UTC de Hostinger -> Lima.
        // TIMESTAMP: la conexión ya tiene SET time_zone='-05:00'.
        return edun_evaluation_created_at_is_datetime($db)
            ? "DATE_SUB($column, INTERVAL 5 HOUR)"
            : $column;
    }
}
