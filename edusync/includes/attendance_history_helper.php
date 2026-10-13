<?php
// Funciones compartidas de historial; sin sesión, consultas ni salida HTTP.

function attendance_normalize_status($status): string {
    $value = trim((string)$status);
    $lower = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    if ($lower === 'normal' || $lower === 'temprano' || $lower === 'presente') return 'Presente';
    if ($lower === 'tarde') return 'Tarde';
    if ($lower === 'ausente justificada' || $lower === 'ausencia justificada') return 'Ausente Justificada';
    if ($lower === 'ausente' || $lower === 'ausencia') return 'Ausente';
    if ($lower === 'permiso') return 'Permiso';
    return $value !== '' ? $value : 'Sin estado';
}

function attendance_clean_time($time): ?string {
    $value = trim((string)$time);
    if ($value === '' || $value === '00:00:00' || $value === '00:00') return null;
    return substr($value, 0, 8);
}

function attendance_pick_entry(array $rows): ?array {
    if (!$rows) return null;
    usort($rows, static function ($a, $b) {
        $ta = attendance_clean_time($a['hora'] ?? null) ?? '99:99:99';
        $tb = attendance_clean_time($b['hora'] ?? null) ?? '99:99:99';
        return strcmp($ta, $tb);
    });
    return $rows[0];
}

function attendance_pick_exit(array $rows): ?array {
    if (!$rows) return null;
    usort($rows, static function ($a, $b) {
        $ta = attendance_clean_time($a['hora'] ?? null) ?? '00:00:00';
        $tb = attendance_clean_time($b['hora'] ?? null) ?? '00:00:00';
        return strcmp($tb, $ta);
    });
    return $rows[0];
}

/**
 * Mismo criterio de días para familias y docentes: primera entrada,
 * última salida y estado de la entrada (o de otra marcación disponible).
 */
function attendance_group_days(array $raw): array {
    $grouped = [];
    foreach ($raw as $mark) {
        $year = (string)$mark['anio_academico'];
        $date = substr((string)$mark['fecha'], 0, 10);
        $key = $year . '|' . $date;
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'fecha' => $date,
                'anio_academico' => $year,
                'anio_descripcion' => (string)$mark['anio_descripcion'],
                'entradas' => [],
                'salidas' => [],
                'otros' => []
            ];
        }

        $type = strtolower(trim((string)$mark['tipo']));
        if ($type === 'entrada') $grouped[$key]['entradas'][] = $mark;
        elseif ($type === 'salida') $grouped[$key]['salidas'][] = $mark;
        else $grouped[$key]['otros'][] = $mark;
    }

    $days = [];
    foreach ($grouped as $day) {
        $entry = attendance_pick_entry($day['entradas']);
        $exit = attendance_pick_exit($day['salidas']);
        $fallback = $entry ?: ($day['otros'][0] ?? ($exit ?: null));
        $status = attendance_normalize_status($fallback['estado'] ?? 'Sin estado');

        $days[] = [
            'fecha' => $day['fecha'],
            'anio_academico' => $day['anio_academico'],
            'anio_descripcion' => $day['anio_descripcion'],
            'entrada' => $entry ? attendance_clean_time($entry['hora'] ?? null) : null,
            'salida' => $exit ? attendance_clean_time($exit['hora'] ?? null) : null,
            'estado' => $status,
            'marcaciones' => count($day['entradas']) + count($day['salidas']) + count($day['otros'])
        ];
    }

    usort($days, static function ($a, $b) {
        return strcmp((string)$b['fecha'], (string)$a['fecha']);
    });

    return $days;
}

/** Identifica el aula autorizada aunque el grado use 4, 4° o 4º. */
function attendance_normalize_classroom(array $assignment): array {
    $level = trim((string)($assignment['level'] ?? ''));
    $level = function_exists('mb_strtolower')
        ? mb_strtolower($level, 'UTF-8') : strtolower($level);
    $grade = trim(str_replace(['°', 'º'], '', trim((string)($assignment['grado'] ?? ''))));
    $section = strtoupper(trim((string)($assignment['seccion'] ?? '')));
    return [$level, $grade, $section !== '' ? $section : 'U'];
}

/** Los parámetros son institución, nivel, grado y sección de la asignación. */
function attendance_teacher_roster_sql(): string {
    return "SELECT st.id,st.id_no,st.name
            FROM student st
            WHERE st.school_id=?
              AND LOWER(TRIM(COALESCE(st.status,'')))='activo'
              AND LOWER(TRIM(COALESCE(st.nivel,'')))=LOWER(TRIM(?))
              AND TRIM(REPLACE(REPLACE(TRIM(COALESCE(st.grado,'')),'°',''),'º',''))=?
              AND UPPER(COALESCE(NULLIF(TRIM(st.seccion),''),'U'))=?
            ORDER BY st.name";
}
