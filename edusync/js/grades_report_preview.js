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

    function reinitSelect($el) {
        if (!$.fn.select2) return;
        if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
        $el.select2({width:'100%'});
    }

    function fillSelect(selector, placeholder, rows, valueKey, labelKey, previous, disabled) {
        var $el=$(selector);
        if ($.fn.select2 && $el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
        $el.empty().append($('<option>',{value:'',text:placeholder}));
        (rows||[]).forEach(function(row){
            var value=typeof row==='object'?row[valueKey]:row;
            var label=typeof row==='object'?row[labelKey]:row;
            $el.append($('<option>',{value:value,text:label}));
        });
        $el.prop('disabled',!!disabled);
        if (previous!==undefined && previous!==null && previous!=='' && $el.find('option').filter(function(){return String(this.value)===String(previous);}).length) {
            $el.val(String(previous));
        }
        reinitSelect($el);
    }

    function directorRequest(action,data,onSuccess) {
        data=data||{};data.action=action;
        $.ajax({url:'grades_report_director_filters_api.php',method:'POST',data:data,dataType:'json'})
            .done(function(resp){
                if(resp&&Number(resp.status)===1){onSuccess(resp);return;}
                if(typeof alert_toast==='function')alert_toast((resp&&resp.message)||'No se pudo cargar el catálogo institucional.','warning');
            })
            .fail(function(xhr){
                var msg=(xhr.responseJSON||{}).message||'No se pudo cargar el catálogo institucional.';
                if(typeof alert_toast==='function')alert_toast(msg,'danger');
            });
    }

    function installDirectorFilters(){
        if(!window.EDUSYNC_GRADE_REPORT_DIRECTOR)return;

        window.loadLevelsByAcademicYear=function(academicYearId){
            var previous=$('#level').val();
            if(!academicYearId){fillSelect('#level','Seleccionar',[],null,null,'',true);return;}
            directorRequest('levels',{academic_year_id:academicYearId},function(resp){
                fillSelect('#level','Seleccionar',resp.levels||[],null,null,previous,false);
            });
        };

        window.updateGrados=function(){
            var level=$('#level').val(),year=$('#academic_year_id').val(),previous=$('#grado').val();
            if(!level){
                fillSelect('#grado','Seleccionar',[],null,null,'',true);
                fillSelect('#seccion','Seleccionar',[],null,null,'',true);
                fillSelect('#course_id','Todos',[],null,null,'',true);
                return;
            }
            directorRequest('grades',{academic_year_id:year,level:level},function(resp){
                fillSelect('#grado','Seleccionar',resp.grados||[],null,null,previous,false);
                if(!$('#grado').val()){
                    fillSelect('#seccion','Seleccionar',[],null,null,'',true);
                    fillSelect('#course_id','Todos',[],null,null,'',true);
                }
            });
        };

        window.updateSecciones=function(){
            var level=$('#level').val(),grade=$('#grado').val(),year=$('#academic_year_id').val(),previous=$('#seccion').val();
            if(!level||!grade){
                fillSelect('#seccion','Seleccionar',[],null,null,'',true);
                fillSelect('#course_id','Todos',[],null,null,'',true);
                return;
            }
            directorRequest('sections',{academic_year_id:year,level:level,grado:grade},function(resp){
                fillSelect('#seccion','Seleccionar',resp.secciones||[],null,null,previous,false);
                if(!$('#seccion').val())fillSelect('#course_id','Todos',[],null,null,'',true);
            });
        };

        window.updateCursos=function(){
            var level=$('#level').val(),grade=$('#grado').val(),section=$('#seccion').val(),year=$('#academic_year_id').val(),previous=$('#course_id').val();
            if(!level||!grade||!section){fillSelect('#course_id','Todos',[],null,null,'',true);return;}
            directorRequest('courses',{academic_year_id:year,level:level,grado:grade,seccion:section},function(resp){
                fillSelect('#course_id','Todos',resp.courses||[],'id','name',previous,false);
                if(typeof window.updateStudents==='function')window.updateStudents();
                if(typeof window.updateEvaluations==='function')window.updateEvaluations();
            });
        };
    }

    installDirectorFilters();

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
