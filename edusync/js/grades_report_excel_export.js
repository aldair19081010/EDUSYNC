(function ($) {
    'use strict';

    if (!$) return;

    function serializedFilters() {
        return $('#filter-form').serialize();
    }

    function requiredPrintFiltersReady() {
        var fields = [
            ['#academic_year_id', 'Año Académico'],
            ['#level', 'Nivel'],
            ['#grado', 'Grado'],
            ['#seccion', 'Sección'],
            ['#bimestre', 'Bimestre']
        ];
        for (var i = 0; i < fields.length; i++) {
            if (!$(fields[i][0]).val()) {
                if (typeof alert_toast === 'function') {
                    alert_toast('Seleccione ' + fields[i][1] + ' para la vista de impresión.', 'warning');
                }
                return false;
            }
        }
        return true;
    }

    function installGradesReportOutput() {
        var $exportButton = $('#confirm-export');
        if ($exportButton.length) {
            // Conserva el mismo modal Numérico/Letras y cambia únicamente el destino de descarga.
            $exportButton.off('click').on('click', function () {
                var selectedFormat = $('.format-option.border-primary').data('format');
                if (!selectedFormat) {
                    if (typeof alert_toast === 'function') alert_toast('Por favor, seleccione un formato.', 'warning');
                    return;
                }

                $('#export-format-modal').modal('hide');
                var formData = serializedFilters();
                formData += '&export_format=' + encodeURIComponent(selectedFormat);
                formData += '&download_token=' + encodeURIComponent('download_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9));

                if (typeof start_load === 'function') start_load();
                var iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.setAttribute('aria-hidden', 'true');
                iframe.src = 'export_grades_excel_router.php?' + formData;
                document.body.appendChild(iframe);

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
                        ? 'Se exportarán todos los cursos del aula en un solo archivo Excel. Incluye RESUMEN y una hoja por curso, alineada con el formato individual.'
                        : 'Seleccione el formato en el que desea exportar las calificaciones del curso seleccionado:';
                    $(this).find('.modal-body > p').first().text(text);
                });
        }

        var $printButton = $('#print-report');
        if ($printButton.length) {
            // La impresión ya no depende de la página visible de DataTables.
            // Curso = Todos muestra consolidado + todos los cursos; curso específico muestra ese curso completo.
            $printButton.off('click').on('click', function (e) {
                e.preventDefault();
                if (!requiredPrintFiltersReady()) return;

                var frame = document.getElementById('grades-print-frame');
                if (!frame) return;
                frame.removeAttribute('srcdoc');
                frame.src = 'grades_report_print.php?' + serializedFilters() + '&print_format=auto&_=' + Date.now();
                $('#grades-print-modal').modal('show');
            });
        }
    }

    installGradesReportOutput();
    $(installGradesReportOutput);
})(window.jQuery);
