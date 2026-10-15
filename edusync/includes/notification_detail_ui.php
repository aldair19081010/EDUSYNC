<?php
/**
 * Cabecera y pie unificados para las vistas de detalle de notificaciones.
 * No modifica permisos, consultas ni acciones de los módulos existentes.
 */
if (!function_exists('edn_escape')) {
    function edn_escape($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('edn_datetime')) {
    function edn_datetime($value): string {
        if (!$value) return '—';
        try {
            $d = new DateTime((string)$value, new DateTimeZone('America/Lima'));
            return $d->format('d/m/Y · h:i a');
        } catch (Throwable $e) { return edn_escape($value); }
    }
}
if (!function_exists('edn_header')) {
    function edn_header(string $kind,string $title,string $subtitle='', $date=null,
                        string $status='',string $extra=''): void {
        $themes=[
            'evaluation'=>['blue','fa-file-alt','Académica'],
            'grade_changes'=>['warning','fa-pen','Auditoría académica'],
            'low_grade'=>['danger','fa-exclamation-triangle','Seguimiento académico'],
            'closure'=>['blue','fa-lock','Cierre bimestral'],
            'reopen'=>['warning','fa-unlock-alt','Gestión académica'],
            'attendance'=>['teal','fa-user-check','Asistencia'],
            'success'=>['success','fa-check-circle','Resultado'],
            'rejected'=>['danger','fa-times-circle','Resultado'],
            'general'=>['blue','fa-bell','Comunicaciones'],
        ];
        [$theme,$icon,$label]=$themes[$kind]??$themes['general'];
        $themeClass=$theme==='blue'?'':(' edn-theme--'.$theme);
        echo '<div class="edn-view'.$themeClass.'">';
        echo '<div class="edn-hero"><div class="edn-hero-icon"><i class="fas '.edn_escape($icon).'"></i></div><div class="edn-hero-main">';
        echo '<div class="edn-eyebrow">'.edn_escape($label).'</div>';
        echo '<h4 class="edn-title">'.edn_escape($title).'</h4>';
        if ($subtitle!=='') echo '<div class="edn-subtitle">'.edn_escape($subtitle).'</div>';
        echo '<div class="edn-tags">';
        if ($status!=='') echo '<span class="edn-tag edn-tag--accent">'.edn_escape($status).'</span>';
        if ($extra!=='') echo '<span class="edn-tag">'.edn_escape($extra).'</span>';
        if ($date) echo '<span class="edn-meta-date"><i class="far fa-clock"></i> '.edn_datetime($date).'</span>';
        echo '</div></div></div>';
    }
}
if (!function_exists('edn_footer')) {
    function edn_footer(): void {
        echo '<div class="edn-footer"><button type="button" class="btn btn-light border" data-dismiss="modal">Cerrar</button></div></div>';
    }
}
