<?php
/**
 * Política de alertas de seguimiento académico.
 * Una alerta es el episodio (estudiante, bimestre, destinatario).
 * Actualizar cantidad o cursos NO constituye una alerta nueva.
 * No consulta ni altera MySQL para permitir pruebas deterministas.
 */
function edun_academic_alert_plan(array $existing, array $wanted): array {
    $create=[];$refresh=[];$unchanged=[];$resolved=[];
    foreach ($wanted as $key=>$current) {
        if (!array_key_exists($key,$existing)) {
            $create[$key]=$current;
            continue;
        }
        $previous=$existing[$key];
        if ((int)($previous['count_low_grades']??-1)!==(int)$current['count']
            || (string)($previous['failed_courses']??'')!==(string)$current['json']) {
            $refresh[$key]=['existing'=>$previous,'current'=>$current];
        } else {
            $unchanged[$key]=$previous;
        }
    }
    foreach ($existing as $key=>$previous) {
        if (!array_key_exists($key,$wanted)) $resolved[$key]=$previous;
    }
    return ['create'=>$create,'refresh'=>$refresh,
        'unchanged'=>$unchanged,'resolved'=>$resolved];
}
