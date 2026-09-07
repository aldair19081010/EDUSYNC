(function($){
    'use strict';

    function showStandardPreview() {
        if (!$('#grades-report-table table').length) {
            alert_toast('No hay reporte para imprimir.', 'warning');
            return;
        }

        var content = $('#grades-report-table').clone();
        content.find('.dataTables_length,.dataTables_filter,.dataTables_info,.dataTables_paginate,script').remove();
        content.find('table').removeClass('dataTable').removeAttr('style').css('width','100%');
        var filters = [
            $('#academic_year_id option:selected').text(),
            $('#level').val(),
            $('#grado').val(),
            $('#seccion').val(),
            $('#course_id option:selected').text(),
            $('#bimestre option:selected').text()
        ].filter(Boolean).join(' · ');

        var html = '<!doctype html><html><head><meta charset="utf-8"><title>Reporte de notas</title><style>body{font-family:Arial,sans-serif;color:#25324b;padding:22px;font-size:12px}h2{margin:0 0 5px;color:#344767}.meta{color:#667085;margin-bottom:18px}table{width:100%;border-collapse:collapse;margin-bottom:16px}th,td{border:1px solid #cfd6e4;padding:6px;text-align:left}th{background:#eef2f8}.badge{border:1px solid #aaa;padding:2px 5px;border-radius:4px}@page{size:landscape;margin:10mm}</style></head><body><h2>Reporte de notas</h2><div class="meta">'+$('<div>').text(filters).html()+'</div>'+content.html()+'</body></html>';

        var frame = document.getElementById('grades-print-frame');
        frame.src = 'about:blank';
        frame.srcdoc = html;
        $('#grades-print-modal').modal('show');
    }

    function showExcelStyleDetailPreview() {
        var required = $('#level').val() && $('#grado').val() && $('#seccion').val() && $('#course_id').val() && $('#bimestre').val();
        if (!required) {
            showStandardPreview();
            return;
        }

        var frame = document.getElementById('grades-print-frame');
        frame.removeAttribute('srcdoc');
        var query = $('#filter-form').serialize();
        query += '&export_format=numeric&_preview=' + Date.now();
        frame.src = 'grades_report_preview.php?' + query;
        $('#grades-print-modal').modal('show');
    }

    $(function(){
        // Sustituimos únicamente el comportamiento de Vista de impresión.
        // La tabla Detalle visible en pantalla no se modifica.
        $('#print-report').off('click').on('click.gradesPreview', function(e){
            e.preventDefault();
            if (($('#report_view').val() || 'detail') === 'detail') {
                showExcelStyleDetailPreview();
            } else {
                showStandardPreview();
            }
        });
    });
})(jQuery);
