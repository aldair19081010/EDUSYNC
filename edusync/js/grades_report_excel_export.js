(function ($) {
    'use strict';

    if (!$) return;

    function installGradesExcelExport() {
        var $button = $('#confirm-export');
        if (!$button.length) return;

        // Sustituye únicamente el envío del Excel; conserva el mismo modal y sus dos formatos.
        $button.off('click').on('click', function () {
            var selectedFormat = $('.format-option.border-primary').data('format');
            if (!selectedFormat) {
                if (typeof alert_toast === 'function') alert_toast('Por favor, seleccione un formato.', 'warning');
                return;
            }

            $('#export-format-modal').modal('hide');

            var formData = $('#filter-form').serialize();
            formData += '&export_format=' + encodeURIComponent(selectedFormat);
            formData += '&download_token=' + encodeURIComponent('download_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9));

            if (typeof start_load === 'function') start_load();

            var iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.setAttribute('aria-hidden', 'true');
            iframe.src = 'export_grades_excel_router.php?' + formData;
            document.body.appendChild(iframe);

            // La descarga puede tardar más cuando Curso = Todos. No retirar el iframe prematuramente.
            window.setTimeout(function () {
                if (typeof end_load === 'function') end_load();
            }, 1500);
            window.setTimeout(function () {
                if (document.body.contains(iframe)) document.body.removeChild(iframe);
            }, 120000);
        });

        $('#export-format-modal')
            .off('show.bs.modal.edusyncGradesExcel')
            .on('show.bs.modal.edusyncGradesExcel', function () {
                var allCourses = !$('#course_id').val();
                var text = allCourses
                    ? 'Se exportarán todos los cursos del aula en un solo archivo Excel. El archivo incluirá una hoja RESUMEN y una hoja por cada curso.'
                    : 'Seleccione el formato en el que desea exportar las calificaciones del curso seleccionado:';
                $(this).find('.modal-body > p').first().text(text);
            });
    }

    installGradesExcelExport();
    $(installGradesExcelExport);
})(window.jQuery);
