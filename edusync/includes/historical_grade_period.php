<?php
/**
 * Normalización compartida de bimestres históricos para consultas SQL.
 *
 * La ficha del Estudiante admite numerales, romanos y ordinales antiguos.
 * Los módulos Docente y Dirección deben leer esas mismas marcaciones,
 * sin modificar el texto original almacenado en evaluations.bimestre.
 */
if (!function_exists('edh_historical_bimester_sql')) {
    function edh_historical_bimester_sql(string $column): string {
        // Solo columnas literales definidas por nuestras consultas, nunca
        // expresiones ni nombres enviados por el cliente.
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new InvalidArgumentException('Columna académica inválida.');
        }
        $normalized = "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(
            TRIM(COALESCE($column,'')),' ',''),'BIMESTRE',''),'BIM',''),'B',''))";
        return "(CASE
            WHEN $normalized IN ('IV','CUARTO') THEN 4
            WHEN $normalized IN ('III','TERCERO') THEN 3
            WHEN $normalized IN ('II','SEGUNDO') THEN 2
            WHEN $normalized IN ('I','PRIMERO','PRIMER') THEN 1
            WHEN CAST($normalized AS UNSIGNED) BETWEEN 1 AND 4
                THEN CAST($normalized AS UNSIGNED)
            ELSE 1
        END)";
    }
}
