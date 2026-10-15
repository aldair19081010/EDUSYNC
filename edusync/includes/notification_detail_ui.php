<?php
/**
 * Presentación de avisos con componentes Bootstrap 4 / SB Admin 2.
 * El modal principal sigue a cargo de uni_modal; estas vistas no crean
 * modales anidados ni cabeceras independientes del sistema.
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
        } catch (Throwable $e) {
            return edn_escape($value);
        }
    }
}
if (!function_exists('edn_header')) {
    function edn_header(string $kind,string $title,string $subtitle='', $date=null,
                        string $status='',string $extra=''): void {
        $kinds=[
            'evaluation'=>['primary','fa-clipboard-list','Académica'],
            'grade_changes'=>['warning','fa-pen','Auditoría académica'],
            'low_grade'=>['danger','fa-exclamation-triangle','Seguimiento académico'],
            'closure'=>['primary','fa-lock','Cierre bimestral'],
            'reopen'=>['warning','fa-unlock','Gestión académica'],
            'attendance'=>['info','fa-user-check','Asistencia'],
            'success'=>['success','fa-check-circle','Resultado'],
            'rejected'=>['danger','fa-times-circle','Resultado'],
            'general'=>['primary','fa-bell','Comunicaciones'],
        ];
        [$tone,$icon,$label]=$kinds[$kind]??$kinds['general'];
        // SB Admin 2: card, border-left, text-* y badge. Un solo título
        // dentro de la tarjeta; el título del modal es gestionado por la app.
        echo '<div class="edn-view">';
        echo '<div class="card shadow-sm border-left-'.edn_escape($tone).' mb-3">';
        echo '<div class="card-body py-3 px-3">';
        echo '<div class="d-flex align-items-start">';
        echo '<span class="edn-icon mr-3 text-'.edn_escape($tone).'"><i class="fas '.edn_escape($icon).'"></i></span>';
        echo '<div class="flex-grow-1 min-width-0">';
        echo '<div class="small font-weight-bold text-'.edn_escape($tone).' text-uppercase mb-1">'.edn_escape($label).'</div>';
        echo '<h5 class="font-weight-bold text-gray-800 mb-1 edn-title">'.edn_escape($title).'</h5>';
        if ($subtitle!=='') {
            echo '<div class="small text-muted edn-wrap">'.edn_escape($subtitle).'</div>';
        }
        echo '<div class="d-flex flex-wrap align-items-center mt-2 edn-meta">';
        if ($status!=='') echo '<span class="badge badge-'.edn_escape($tone).' mr-2 mb-1">'.edn_escape($status).'</span>';
        if ($extra!=='') echo '<span class="badge badge-light border mr-2 mb-1">'.edn_escape($extra).'</span>';
        if ($date) echo '<small class="text-muted mb-1"><i class="far fa-clock mr-1"></i>'.edn_datetime($date).'</small>';
        echo '</div></div></div></div></div>';
    }
}
if (!function_exists('edn_footer')) {
    function edn_footer(): void {
        echo '<div class="edn-footer border-top pt-3 mt-3 text-right">';
        echo '<button type="button" class="btn btn-light btn-sm border" data-dismiss="modal">Cerrar</button>';
        echo '</div></div>';
    }
}
