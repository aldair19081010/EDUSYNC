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
                if (typeof alert_toast === 'function') alert_toast('Seleccione ' + fields[i][1] + '.', 'warning');
                return false;
            }
        }
        return true;
    }

    function installGradesReportOutput() {
        var $exportButton = $('#confirm-export');
        if ($exportButton.length) {
            $exportButton.off('click');
            $(document)
                .off('click.edusyncGradesExport', '#confirm-export')
                .on('click.edusyncGradesExport', '#confirm-export', function (e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();

                    var selectedFormat = $('.format-option.border-primary').data('format');
                    if (!selectedFormat) {
                        if (typeof alert_toast === 'function') alert_toast('Por favor, seleccione un formato.', 'warning');
                        return false;
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
                    return false;
                });

            $('#export-format-modal')
                .off('show.bs.modal.edusyncGradesExcel')
                .on('show.bs.modal.edusyncGradesExcel', function () {
                    var allCourses = !$('#course_id').val();
                    var text = allCourses
                        ? 'Se exportarán todos los cursos del aula en un solo archivo Excel. Incluye RESUMEN y una hoja por curso; todas las hojas comienzan con APELLIDOS Y NOMBRES en la columna A.'
                        : 'Seleccione el formato en el que desea exportar las calificaciones del curso seleccionado:';
                    $(this).find('.modal-body > p').first().text(text);
                });
        }

        var $printButton = $('#print-report');
        if ($printButton.length) {
            // Eliminar el manejador antiguo y capturar siempre la impresión nueva.
            $printButton.off('click');
            $(document)
                .off('click.edusyncGradesPrint', '#print-report')
                .on('click.edusyncGradesPrint', '#print-report', function (e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    if (!requiredPrintFiltersReady()) return false;

                    var frame = document.getElementById('grades-print-frame');
                    if (!frame) return false;
                    frame.removeAttribute('srcdoc');
                    frame.src = 'grades_report_print.php?' + serializedFilters() + '&print_format=auto&_=' + Date.now();
                    $('#grades-print-modal').modal('show');
                    return false;
                });
        }
    }

    installGradesReportOutput();
    $(installGradesReportOutput);
})(window.jQuery);
