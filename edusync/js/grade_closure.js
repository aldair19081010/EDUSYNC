(function($){
    'use strict';
    if (!$ || !$('#gr-book-view').length) return;

    var api = 'grade_closure_api.php';
    var csrf = window.EDUSYNC_CSRF || '';
    var actionContext = null;
    var currentStatus = null;
    var requestSeq = 0;

    var style = '<style id="gc-style">' +
        '.gc-panel{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.5rem;padding:.9rem 1rem;margin-bottom:1rem}' +
        '.gc-panel.gc-ready{border-left-color:#1cc88a}.gc-panel.gc-closed{border-left-color:#36b9cc}.gc-panel.gc-incomplete{border-left-color:#f6c23e}' +
        '.gc-state{font-size:.75rem;font-weight:800;text-transform:uppercase}.gc-meta{font-size:.78rem;color:#6c757d}.gc-progress{height:7px}' +
        '.gc-check{display:flex;align-items:flex-start;padding:.38rem 0;border-bottom:1px solid #f0f1f5}.gc-check:last-child{border-bottom:0}.gc-check i{width:20px;margin-top:.15rem}' +
        '.gc-details{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.4rem;padding:.65rem .8rem;margin-top:.7rem;max-height:260px;overflow:auto}' +
        '.gc-actions{gap:.4rem}.gc-summary-pill{display:inline-block;border:1px solid #e3e6f0;border-radius:1rem;padding:.15rem .55rem;margin:.1rem .15rem .1rem 0;background:#fff;font-size:.75rem}' +
        '@media(max-width:767px){.gc-panel .d-flex.gc-main{display:block!important}.gc-actions{margin-top:.7rem;display:flex!important;flex-wrap:wrap}.gc-actions .btn{flex:1 1 auto}}' +
        '</style>';
    if (!$('#gc-style').length) $('head').append(style);

    // El cierre deja de ser una acción general: pertenece al curso y bimestre abiertos en el Libro de notas.
    $('#gr-general-close,#gc-general-modal').remove();
    if (!$('#gc-book-panel').length) {
        $('<div id="gc-book-panel" class="gc-panel" style="display:none"></div>').insertBefore('#gb-readonly-banner');
    }

    function esc(v){ return $('<div>').text(v == null ? '' : v).html(); }
    function fmtDate(v){
        if(!v) return '—';
        var m=String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m) return esc(v);
        var h=Number(m[4]),suffix=h>=12?'p. m.':'a. m.';
        h=h%12||12;
        return m[3]+'/'+m[2]+'/'+m[1]+' '+String(h).padStart(2,'0')+':'+m[5]+' '+suffix;
    }
    function gradeLabel(v){
        var text=String(v==null?'':v).replace(/[°º]+/g,'').trim();
        return text ? text+'°' : '';
    }
    function bookContext(){
        return {
            assignment:String($('#gb-assignment').val()||''),
            bimester:String($('#gb-bimester').val()||'')
        };
    }
    function validContext(ctx){
        return ctx && Number(ctx.assignment)>0 && /^[1-4]$/.test(String(ctx.bimester||''));
    }
    function contextLabel(){
        var parts=[];
        var course=$('#gb-course').val();
        var level=$('#gb-level').val();
        var grade=$('#gb-grade').val();
        var section=$('#gb-section').val();
        var bimester=$('#gb-bimester').val();
        if(course) parts.push(course);
        if(level) parts.push(level);
        if(grade) parts.push(gradeLabel(grade)+(section?' '+section:''));
        if(bimester) parts.push(bimester+'° Bimestre');
        return parts.join(' · ');
    }
    function summaryHtml(s){
        s=s||{};
        return '<span class="gc-summary-pill"><i class="fas fa-tasks mr-1"></i>'+Number(s.competencies||0)+' competencias</span>'+
            '<span class="gc-summary-pill"><i class="fas fa-clipboard-list mr-1"></i>'+Number(s.evaluations||0)+' evaluaciones</span>'+
            '<span class="gc-summary-pill"><i class="fas fa-users mr-1"></i>'+Number(s.students||0)+' estudiantes</span>'+
            '<span class="gc-summary-pill"><i class="fas fa-percentage mr-1"></i>'+Number(s.percentage_total||0).toFixed(2)+'%</span>';
    }
    function detailsHtml(validation){
        var issues=(validation&&validation.issues)||[];
        if(!issues.length){
            return '<div id="gc-book-detail-area" class="gc-details" style="display:none"><div class="gc-check text-success"><i class="fas fa-check-circle"></i><div><strong>Verificación completa.</strong><br><small>Todas las condiciones necesarias para el cierre se cumplen.</small></div></div></div>';
        }
        var html='<div id="gc-book-detail-area" class="gc-details" style="display:none">';
        issues.forEach(function(issue){
            html+='<div class="gc-check text-danger"><i class="fas fa-times-circle"></i><div>'+esc(issue.message||'Hay información pendiente.')+'</div></div>';
        });
        return html+'</div>';
    }
    function statusMarkup(data){
        var validation=data.validation||{};
        var s=validation.summary||{};
        var closed=!!data.closed;
        var ready=!!data.ready;
        var expected=Number(s.expected_grade_cells||0);
        var completed=Number(s.completed_grade_cells||0);
        var pending=Math.max(0,expected-completed);
        var progress=Number(s.progress||0);

        var state=closed?'CERRADO':(ready?'LISTO PARA CERRAR':'EN EDICIÓN');
        var icon=closed?'fa-lock':(ready?'fa-check-circle':'fa-pencil-alt');
        var color=closed?'text-info':(ready?'text-success':'text-warning');
        var title=closed?'Bimestre cerrado':(ready?'Notas completas para el cierre':'Bimestre en edición');
        var sub;
        if(closed){
            sub='Las evaluaciones y calificaciones están en modo solo lectura.';
        }else if(ready){
            sub='Todas las condiciones están completas. Revisa el resumen antes de cerrar.';
        }else if(pending>0){
            sub='Faltan '+pending+' calificación'+(pending===1?'':'es')+' para completar el bimestre.';
        }else{
            sub='Hay condiciones pendientes antes de poder cerrar el bimestre.';
        }

        var actions='';
        if(closed){
            actions+='<button class="btn btn-outline-primary btn-sm gc-verify"><i class="fas fa-info-circle mr-1"></i>Ver detalle</button>';
            if(data.pending_reopen){
                actions+='<button class="btn btn-warning btn-sm" disabled><i class="fas fa-hourglass-half mr-1"></i>Reapertura pendiente</button>';
            }else{
                actions+='<button class="btn btn-outline-warning btn-sm gc-request-reopen"><i class="fas fa-unlock-alt mr-1"></i>Solicitar reapertura</button>';
            }
        }else if(ready){
            actions+='<button class="btn btn-outline-primary btn-sm gc-verify"><i class="fas fa-clipboard-check mr-1"></i>Ver detalle</button>';
            actions+='<button class="btn btn-success btn-sm gc-close"><i class="fas fa-lock mr-1"></i>Revisar y cerrar</button>';
        }else{
            if(pending>0){
                actions+='<button class="btn btn-outline-primary btn-sm gc-show-pending"><i class="fas fa-user-clock mr-1"></i>Ver pendientes</button>';
            }
            actions+='<button class="btn btn-light border btn-sm gc-verify"><i class="fas fa-clipboard-check mr-1"></i>Ver detalle</button>';
        }

        var meta='';
        if(closed&&data.closure){
            meta='<div class="gc-meta mt-1">Cerrado por <strong>'+esc(data.closure.closed_by_name||'Docente')+'</strong> · '+fmtDate(data.closure.closed_at)+' · Cierre #'+Number(data.closure.closure_version||1)+'</div>';
        }else if(data.pending_reopen){
            meta='<div class="gc-meta mt-1">Solicitud de reapertura enviada '+fmtDate(data.pending_reopen.created_at)+'</div>';
        }

        return '<div class="d-flex gc-main justify-content-between align-items-start">'+
            '<div class="pr-md-3 flex-grow-1">'+
                '<div class="'+color+' gc-state"><i class="fas '+icon+' mr-1"></i>'+state+'</div>'+
                '<div class="font-weight-bold text-gray-800">'+title+'</div>'+
                '<div class="small text-muted">'+sub+'</div>'+meta+
                '<div class="mt-2">'+summaryHtml(s)+'</div>'+
                '<div class="d-flex justify-content-between small mt-2"><span>Completitud de calificaciones</span><strong>'+progress.toFixed(1)+'%</strong></div>'+
                '<div class="progress gc-progress"><div class="progress-bar '+(progress>=100?'bg-success':'bg-warning')+'" style="width:'+Math.min(100,progress)+'%"></div></div>'+
            '</div>'+
            '<div class="gc-actions d-flex">'+actions+'</div>'+
        '</div>'+detailsHtml(validation);
    }
    function setPanelClass(target,data){
        target.removeClass('gc-ready gc-closed gc-incomplete').addClass(data.closed?'gc-closed':(data.ready?'gc-ready':'gc-incomplete'));
    }
    function panelError(message,migration){
        var target=$('#gc-book-panel');
        target.show().removeClass('gc-ready gc-closed').addClass('gc-incomplete').html(
            '<div class="alert '+(migration?'alert-warning':'alert-danger')+' mb-0 small"><i class="fas '+(migration?'fa-database':'fa-exclamation-circle')+' mr-1"></i>'+esc(message)+'</div>'
        );
    }
    function refreshBookStatus(){
        var panel=$('#gc-book-panel');
        var ctx=bookContext();
        currentStatus=null;
        if(!validContext(ctx)){
            panel.hide().removeClass('gc-ready gc-closed gc-incomplete').empty();
            return;
        }

        var seq=++requestSeq;
        panel.show().removeClass('gc-ready gc-closed gc-incomplete').html(
            '<div class="text-muted small"><i class="fas fa-spinner fa-spin mr-1"></i>Verificando estado del '+esc($('#gb-bimester option:selected').text()||'bimestre')+'...</div>'
        );

        $.getJSON(api,{action:'status',teacher_course_id:ctx.assignment,bimestre:ctx.bimester})
            .done(function(r){
                if(seq!==requestSeq)return;
                if(!r||Number(r.status)!==1){
                    panelError((r&&r.message)||'No se pudo verificar el cierre.',!!(r&&r.migration_required));
                    return;
                }
                currentStatus=r;
                setPanelClass(panel,r);
                panel.html(statusMarkup(r));
            })
            .fail(function(x){
                if(seq!==requestSeq)return;
                var r=x.responseJSON||{};
                panelError(r.message||'No se pudo verificar el estado del bimestre.',!!r.migration_required);
            });
    }

    function closeChecklistHtml(data){
        var s=(data&&data.validation&&data.validation.summary)||{};
        var expected=Number(s.expected_grade_cells||0);
        var completed=Number(s.completed_grade_cells||0);
        return '<div class="gc-details mt-0 mb-3" style="display:block;max-height:none">'+
            '<div class="gc-check text-success"><i class="fas fa-check-circle"></i><div><strong>'+Number(s.competencies||0)+' competencias</strong><br><small>Configuración válida para el cierre.</small></div></div>'+
            '<div class="gc-check text-success"><i class="fas fa-check-circle"></i><div><strong>'+Number(s.evaluations||0)+' evaluaciones activas</strong><br><small>Todas las competencias requeridas están evaluadas.</small></div></div>'+
            '<div class="gc-check text-success"><i class="fas fa-check-circle"></i><div><strong>'+completed+' de '+expected+' calificaciones registradas</strong><br><small>No existen notas pendientes.</small></div></div>'+
            '<div class="gc-check text-success"><i class="fas fa-check-circle"></i><div><strong>Porcentajes: '+Number(s.percentage_total||0).toFixed(2)+'%</strong><br><small>La ponderación de competencias está completa.</small></div></div>'+
        '</div>';
    }
    function ensureCloseModal(){
        if($('#gc-close-modal').length)return;
        $('body').append(
            '<div class="modal fade" id="gc-close-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">'+
                '<div class="modal-header"><div><h5 class="modal-title"><i class="fas fa-lock text-success mr-2"></i>Revisar y cerrar bimestre</h5><small class="text-muted" id="gc-close-context"></small></div><button class="close" data-dismiss="modal"><span>&times;</span></button></div>'+
                '<div class="modal-body"><div id="gc-close-checks"></div><div class="alert alert-light border small">Al cerrar, las evaluaciones y calificaciones de este curso y bimestre quedarán en solo lectura. La reapertura requerirá autorización de Administración.</div><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="gc-confirm-check"><label class="custom-control-label" for="gc-confirm-check">He revisado las notas y deseo cerrar este bimestre.</label></div></div>'+
                '<div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Cancelar</button><button class="btn btn-success" id="gc-confirm-close" disabled><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button></div>'+
            '</div></div></div>'
        );
        $(document).on('change','#gc-confirm-check',function(){$('#gc-confirm-close').prop('disabled',!this.checked);});
        $(document).on('hidden.bs.modal','#gc-close-modal',function(){$('#gc-confirm-check').prop('checked',false);$('#gc-confirm-close').prop('disabled',true);});
    }
    function ensureReopenModal(){
        if($('#gc-reopen-modal').length)return;
        $('body').append(
            '<div class="modal fade" id="gc-reopen-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">'+
                '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-unlock-alt text-warning mr-2"></i>Solicitar reapertura</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div>'+
                '<div class="modal-body"><div class="small text-muted mb-3" id="gc-reopen-context"></div><label class="small font-weight-bold">Motivo de la reapertura</label><textarea class="form-control" id="gc-reopen-reason" rows="4" maxlength="500" placeholder="Ej. Se detectó una calificación registrada incorrectamente."></textarea><small class="text-muted">Si Administración aprueba, volverán a habilitarse ambos modos de registro.</small></div>'+
                '<div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Cancelar</button><button class="btn btn-warning" id="gc-send-reopen"><i class="fas fa-paper-plane mr-1"></i>Enviar solicitud</button></div>'+
            '</div></div></div>'
        );
    }
    function afterClosureChange(){
        if($('#gb-assignment').val()&&$('#gb-bimester').val())$('#gb-bimester').trigger('change');
        setTimeout(refreshBookStatus,250);
        $(document).trigger('grade:closure-changed');
        $(document).trigger('evaluation:gradesSaved',[{status:1,source:'grade_closure'}]);
        if(typeof window.refreshUnifiedNotifications==='function')window.refreshUnifiedNotifications();
    }

    $(document).on('click','.gc-verify',function(){
        $('#gc-book-detail-area').stop(true,true).slideToggle(150);
    });
    $(document).on('click','.gc-show-pending',function(){
        if($('#gb-student-filter').length){
            $('#gb-student-filter').val('pending').trigger('change');
            var target=$('#gb-container');
            if(target.length)$('html,body').animate({scrollTop:Math.max(0,target.offset().top-120)},180);
        }
    });
    $(document).on('click','.gc-close',function(){
        var ctx=bookContext();
        if(!validContext(ctx)||!currentStatus||!currentStatus.ready||currentStatus.closed)return;
        if($('.gb-cell.gb-dirty').length){
            if(typeof alert_toast==='function')alert_toast('Guarda o descarta las notas modificadas antes de cerrar.','warning');
            return;
        }
        actionContext=ctx;
        ensureCloseModal();
        $('#gc-close-context').text(contextLabel());
        $('#gc-close-checks').html(closeChecklistHtml(currentStatus));
        $('#gc-close-modal').modal('show');
    });
    $(document).on('click','#gc-confirm-close',function(){
        if(!actionContext||!validContext(actionContext))return;
        if(!csrf){
            if(typeof alert_toast==='function')alert_toast('No se encontró el token de seguridad. Recarga la página.','warning');
            return;
        }
        var btn=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Cerrando...');
        $.post(api+'?action=close',{csrf_token:csrf,teacher_course_id:actionContext.assignment,bimestre:actionContext.bimester},null,'json')
            .done(function(r){
                if(!r||Number(r.status)!==1){
                    if(typeof alert_toast==='function')alert_toast((r&&r.message)||'No se pudo cerrar el bimestre.','danger');
                    refreshBookStatus();
                    return;
                }
                $('#gc-close-modal').modal('hide');
                if(typeof alert_toast==='function')alert_toast(r.message,'success');
                afterClosureChange();
            })
            .fail(function(x){
                if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo cerrar el bimestre.','danger');
            })
            .always(function(){btn.prop('disabled',false).html('<i class="fas fa-lock mr-1"></i>Cerrar bimestre');});
    });
    $(document).on('click','.gc-request-reopen',function(){
        actionContext=bookContext();
        if(!validContext(actionContext))return;
        ensureReopenModal();
        $('#gc-reopen-context').text(contextLabel());
        $('#gc-reopen-reason').val('');
        $('#gc-reopen-modal').modal('show');
        setTimeout(function(){$('#gc-reopen-reason').focus();},250);
    });
    $(document).on('click','#gc-send-reopen',function(){
        var reason=$.trim($('#gc-reopen-reason').val());
        if(reason.length<5){
            if(typeof alert_toast==='function')alert_toast('Explica el motivo de la reapertura.','warning');
            return;
        }
        if(!csrf){
            if(typeof alert_toast==='function')alert_toast('No se encontró el token de seguridad. Recarga la página.','warning');
            return;
        }
        if(!actionContext||!validContext(actionContext))return;
        var btn=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Enviando...');
        $.post(api+'?action=request_reopen',{csrf_token:csrf,teacher_course_id:actionContext.assignment,bimestre:actionContext.bimester,reason:reason},null,'json')
            .done(function(r){
                if(!r||Number(r.status)!==1){
                    if(typeof alert_toast==='function')alert_toast((r&&r.message)||'No se pudo enviar la solicitud.','danger');
                    return;
                }
                $('#gc-reopen-modal').modal('hide');
                if(typeof alert_toast==='function')alert_toast(r.message,'success');
                afterClosureChange();
            })
            .fail(function(x){
                if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo enviar la solicitud.','danger');
            })
            .always(function(){btn.prop('disabled',false).html('<i class="fas fa-paper-plane mr-1"></i>Enviar solicitud');});
    });

    $(document).on('change.gradeClosure','#gb-year,#gb-course,#gb-level,#gb-grade,#gb-section,#gb-bimester',function(){
        setTimeout(refreshBookStatus,120);
    });
    $(document).on('click.gradeClosure','#gr-show-book',function(){setTimeout(refreshBookStatus,120);});
    $(document).on('evaluation:saved.gradeClosure evaluation:gradesSaved.gradeClosure',function(e,payload){
        if(payload&&payload.source==='grade_closure')return;
        setTimeout(refreshBookStatus,300);
    });
    $(document).on('grade:reopen-reviewed.gradeClosure',afterClosureChange);

    setTimeout(refreshBookStatus,180);
})(window.jQuery);
