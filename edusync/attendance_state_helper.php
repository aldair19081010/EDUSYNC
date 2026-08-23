<?php
// Calcula el estado de asistencia (Temprano/Normal/Tarde) usando reglas configurables.
// $hora_hhmm: cadena "HH:MM" (se ignorarán segundos si llegan)
// $fecha: "Y-m-d"
// $tipo: Entrada | Salida (por ahora no diferencia, pero se deja por compatibilidad)
// $conn: conexión mysqli
function calcular_estado_asistencia($hora_hhmm, $fecha, $tipo, $conn, $school_id = null) {
    // Normalizar hora a HH:MM
    $hora_hhmm = substr($hora_hhmm, 0, 5);

    // Defaults
    $default_early = '07:00';
    $default_late  = '07:30';
    $use_day_rules = '0';

    $school_id = is_null($school_id) ? intval($_SESSION['login_school_id'] ?? 0) : intval($school_id);
    $has_settings_school = false;
    $has_rules_school = false;
    $col_settings = $conn->query("SHOW COLUMNS FROM attendance_settings LIKE 'school_id'");
    if ($col_settings && $col_settings->num_rows > 0) {
        $has_settings_school = true;
    }
    $col_rules = $conn->query("SHOW COLUMNS FROM attendance_rules LIKE 'school_id'");
    if ($col_rules && $col_rules->num_rows > 0) {
        $has_rules_school = true;
    }

    // Cargar ajustes generales
    $cfg_sql = "SELECT setting_key, setting_value FROM attendance_settings WHERE setting_key IN ('use_day_rules','default_early_time','default_late_time')";
    if ($has_settings_school) {
        $cfg_sql .= " AND school_id = $school_id";
    }
    $cfg = $conn->query($cfg_sql);
    if ($cfg) {
        while ($row = $cfg->fetch_assoc()) {
            if ($row['setting_key'] === 'use_day_rules') $use_day_rules = $row['setting_value'];
            if ($row['setting_key'] === 'default_early_time') $default_early = substr($row['setting_value'], 0, 5);
            if ($row['setting_key'] === 'default_late_time')  $default_late  = substr($row['setting_value'], 0, 5);
        }
    }

    $early = $default_early;
    $late  = $default_late;

    // Reglas por día si están habilitadas
    if ($use_day_rules === '1') {
        $dayOfWeek = date('l', strtotime($fecha)); // Monday, Tuesday, ...
        $rq_sql = "SELECT is_active, early_time, late_time FROM attendance_rules WHERE day_of_week = '" . $conn->real_escape_string($dayOfWeek) . "'";
        if ($has_rules_school) {
            $rq_sql .= " AND school_id = $school_id";
        }
        $rq_sql .= " LIMIT 1";
        $rq = $conn->query($rq_sql);
        if ($rq && $rq->num_rows > 0) {
            $rule = $rq->fetch_assoc();
            if (intval($rule['is_active']) === 1) {
                $early = substr($rule['early_time'], 0, 5) ?: $early;
                $late  = substr($rule['late_time'], 0, 5)  ?: $late;
            } else {
                // Día inactivo: devolver Normal para no bloquear registros manuales
                return 'Normal';
            }
        }
    }

    // Salidas no se califican como Tarde/Temprano, usar estado neutro
    if (strtolower($tipo) === 'salida') {
        return 'Normal';
    }

    // Convertir a minutos para comparar
    $toMinutes = function($hhmm) {
        $parts = explode(':', $hhmm);
        return intval($parts[0]) * 60 + intval($parts[1]);
    };

    $h = $toMinutes($hora_hhmm);
    $e = $toMinutes($early);
    $l = $toMinutes($late);

    if ($h <= $e) return 'Temprano';
    if ($h <= $l) return 'Normal';
    return 'Tarde';
}
?>
