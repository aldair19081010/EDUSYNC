<?php
// Capa de acceso del Reporte de notas.
// Mantiene el reporte original intacto y, solo para usuarios Director heredados
// (type=2 + is_director=1), les da alcance institucional de lectura equivalente
// al Administrador dentro de este módulo.
if (session_status() == PHP_SESSION_NONE) {
    $session_save_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($session_save_path)) {
        @mkdir($session_save_path, 0755, true);
    }
    ini_set('session.save_path', $session_save_path);
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$director_report_original_type = (int)($_SESSION['login_type'] ?? 0);
$director_report_full_access = ((int)($_SESSION['login_is_director'] ?? 0) === 1);
$director_report_legacy_account = $director_report_full_access && $director_report_original_type === 2;

// El PHP inicial del reporte debe cargar todos los cursos/niveles como lo hace Admin.
if ($director_report_legacy_account) {
    $_SESSION['login_type'] = 1;
}

require __DIR__ . '/grades_report_core.php';

// No dejar el rol elevado persistido en la sesión.
if ($director_report_legacy_account) {
    $_SESSION['login_type'] = $director_report_original_type;
}
?>
<script src="js/grades_report_excel_export.js?v=20260910"></script>
<?php if ($director_report_legacy_account): ?>
<script>
(function($){
    if (!$ || !$.ajax || $.ajax.__edusyncDirectorReportWrapped) return;

    var originalAjax = $.ajax;
    var reportActions = {
        get_grados_by_nivel: true,
        get_secciones_by_grado_nivel: true,
        get_courses_by_aula: true,
        get_levels_by_academic_year: true,
        get_students_by_grado_seccion: true,
        get_evaluations_by_filters: true
    };

    function rewriteReportUrl(url) {
        if (typeof url !== 'string') return url;

        if (url === 'grades_report_table.php') {
            return 'director_grades_report_proxy.php?route=table';
        }
        if (url === 'grades_report_views.php') {
            return 'director_grades_report_proxy.php?route=views';
        }

        if (url.indexOf('ajax.php?action=') === 0) {
            var action = url.substring('ajax.php?action='.length).split('&')[0];
            if (reportActions[action]) {
                return 'director_grades_report_proxy.php?route=ajax&action=' + encodeURIComponent(action);
            }
        }
        return url;
    }

    var wrappedAjax = function(url, options) {
        if (typeof url === 'object') {
            var settings = $.extend({}, url);
            settings.url = rewriteReportUrl(settings.url || '');
            return originalAjax.call($, settings);
        }

        var settings = options ? $.extend({}, options) : {};
        settings.url = rewriteReportUrl(url || settings.url || '');
        return originalAjax.call($, settings);
    };

    wrappedAjax.__edusyncDirectorReportWrapped = true;
    $.ajax = wrappedAjax;
})(window.jQuery);
</script>
<?php endif; ?>
