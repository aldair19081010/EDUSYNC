(function($){
    'use strict';
    if (!$ || !$('#gr-list-view').length || !$('#gr-book-view').length) return;

    var api = 'grade_closure_api.php';
    var contextsApi = 'gradebook_api.php';
    var csrf = window.EDUSYNC_CSRF || '';
    var selectedClosureContext = null;
    var actionContext = null;

    var style = '<style id="gc-style">' +
        '.gc-panel{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.5rem;padding:.9rem 1rem;margin-bottom:1rem}' +
        '.gc-panel.gc-ready{border-left-color:#1cc88a}.gc-panel.gc-closed{border-left-color:#36b9cc}.gc-panel.gc-incomplete{border-left-color:#f6c23e}' +
        '.gc-state{font-size:.75rem;font-weight:800;text-transform:uppercase}.gc-meta{font-size:.78rem;color:#6c757d}.gc-progress{height:7px}' +
        '.gc-check{display:flex;align-items:flex-start;padding:.38rem 0;border-bottom:1px solid #f0f1f5}.gc-check:last-child{border-bottom:0}.gc-check i{width:20px;margin-top:.15rem}' +
        '.gc-details{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.4rem;padding:.65rem .8rem;margin-top:.7rem;max-height:260px;overflow:auto}' +
        '.gc-actions{gap:.4rem}.gc-summary-pill{display:inline-block;border:1px solid #e3e6f0;border-radius:1rem;padding:.15rem .55rem;margin:.1rem .15rem .1rem 0;background:#fff;font-size:.75rem}' +
        '.gc-general-status{min-height:90px}' +
        '@media(max-width:767px){.gc-panel .d-flex.gc-main{display:block!important}.gc-actions{margin-top:.7rem;display:flex!important;flex-wrap:wrap}.gc-actions .btn{flex:1 1 auto}}' +
        '</style>';
    if (!$('#gc-style').length) $('head').append(style);

    $('#gc-panel').remove();

    function esc(v){ return $('<div>').text(v == null ? '' : v).html(); }
    function normalize(v){ return String(v == null ? '' : v).replace(/[°º]+/g,'').trim().toLowerCase(); }
    function gradeLabel(v){
        var text=String(v==null?'':v).replace(/[°º]+/g,'').trim();
        return text ? text+'°' : '';
    }
    function fmtDate(v){
        if(!v) return '—';
        var m=String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m) return esc(v);
        var h=Number(m[4]),suffix=h>=12?'p. m.':'a. m.';
        h=h%12||12;
        return m[3]+'/'+m[2]+'/'+m[1]+' '+String(h).padStart(2,'0')+':'+m[5]+' '+suffix;
    }
    function validContext(ctx){ return ctx && Number(ctx.assignment)>0 && /^[1-4]$/.test(String(ctx.bimester||'')); }

    function generalContext(){return selectedClosureContext;}
    function viewSelection(view){
        var prefix=view==='book'?'gb':'gr',bimester=String($('#'+prefix+'-bimester').val()||'');
        return {
            view:view,
            year:String($('#'+prefix+'-year').val()||''),
            course:String($('#'+prefix+'-course').val()||''),
            level:String($('#'+prefix+'-level').val()||''),
            grado:String($('#'+prefix+'-grade').val()||''),
            seccion:String($('#'+prefix+'-section').val()||''),
            bimester:bimester,
            assignment:view==='book'?String($('#gb-assignment').val()||''):''
        };
    }
    function contextText(sel){
        return [sel.course,sel.level,gradeLabel(sel.grado)+' '+(sel.seccion||''),sel.bimester+'° Bimestre'].join(' · ').replace(/\s+/g,' ').trim();
    }
    function resolveFromFilters(sel,callback){
        if(!sel.year||!sel.course||!sel.level||!sel.grado||!sel.seccion||!/^[1-4]$/.test(sel.bimester)){
            callback(null,'Selecciona año, curso, nivel, grado, sección y bimestre en los filtros para revisar el cierre.');
            return;
        }
        if(sel.view==='book'){
            callback(sel.assignment?{assignment:sel.assignment,bimester:sel.bimester}:null,sel.assignment?'':'No se encontró la asignación del curso seleccionado.');
            return;
        }
        $.getJSON(contextsApi,{action:'contexts',academic_year_id:sel.year}).done(function(r){
            if(!r||Number(r.status)!==1){callback(null,(r&&r.message)||'No se pudieron consultar los cursos asignados.');return;}
            var matches=(r.assignments||[]).filter(function(a){
                return normalize(a.course_name)===normalize(sel.course)&&normalize(a.level)===normalize(sel.level)&&
                    normalize(a.grado)===normalize(sel.grado)&&normalize(a.seccion||'U')===normalize(sel.seccion);
            });
            if(matches.length!==1){callback(null,'La combinación de curso y aula no corresponde a una asignación única. Revisa los filtros.');return;}
            callback({assignment:String(matches[0].id),bimester:sel.bimester},'');
        }).fail(function(){callback(null,'No se pudo comprobar la asignación para el cierre.');});
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
            return '<div id="gc-general-detail-area" class="gc-details" style="display:none"><div class="gc-check text-success"><i class="fas fa-check-circle"></i><div><strong>Verificación completa.</strong><br><small>Todas las condiciones necesarias para el cierre se cumplen.</small></div></div></div>';
        }
        var html='<div id="gc-general-detail-area" class="gc-details" style="display:none">';
        issues.forEach(function(issue){
            html+='<div class="gc-check text-danger"><i class="fas fa-times-circle"></i><div>'+esc(issue.message||'Hay información pendiente.')+'</div></div>';
        });
        return html+'</div>';
    }
    function statusMarkup(data){
        var state=data.state||'Incompleto';
        var validation=data.validation||{};
        var s=validation.summary||{};
        var closed=!!data.closed;
        var icon=closed?'fa-lock':(data.ready?'fa-check-circle':'fa-exclamation-triangle');
        var color=closed?'text-info':(data.ready?'text-success':'text-warning');
        var title=closed?'Bimestre cerrado':(data.ready?'Listo para cerrar':'Cierre incompleto');
        var sub=closed
            ? 'El curso, aula y bimestre están cerrados. El bloqueo se aplica tanto a Lista de evaluaciones como a Libro de notas.'
            : (data.ready
                ? 'Todo está correcto. Este cierre bloqueará las dos formas de registrar notas.'
                : 'Corrige los pendientes antes de cerrar. La validación considera todas las evaluaciones y notas del curso y aula seleccionados.');
        var actions='<button class="btn btn-outline-primary btn-sm gc-verify"><i class="fas fa-clipboard-check mr-1"></i>Verificar</button>';
        if(closed){
            if(data.pending_reopen){
                actions+='<button class="btn btn-warning btn-sm" disabled><i class="fas fa-hourglass-half mr-1"></i>Reapertura pendiente</button>';
            } else {
                actions+='<button class="btn btn-outline-warning btn-sm gc-request-reopen"><i class="fas fa-unlock-alt mr-1"></i>Solicitar reapertura</button>';
            }
        } else if(data.ready){
            actions+='<button class="btn btn-success btn-sm gc-close"><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button>';
        } else {
            actions+='<button class="btn btn-secondary btn-sm" disabled><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button>';
        }
        var meta='';
        if(closed&&data.closure){
            meta='<div class="gc-meta mt-1">Cerrado por <strong>'+esc(data.closure.closed_by_name||'Docente')+'</strong> · '+fmtDate(data.closure.closed_at)+' · Cierre #'+Number(data.closure.closure_version||1)+'</div>';
        } else if(data.pending_reopen){
            meta='<div class="gc-meta mt-1">Solicitud de reapertura enviada '+fmtDate(data.pending_reopen.created_at)+'</div>';
        }
        var progress=Number(s.progress||0);
        return '<div class="d-flex gc-main justify-content-between align-items-start">'+
            '<div class="pr-md-3 flex-grow-1">'+
                '<div class="'+color+' gc-state"><i class="fas '+icon+' mr-1"></i>'+esc(state)+'</div>'+
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
    function requestStatus(ctx,onSuccess,onError){
        if(!validContext(ctx)){
            if(onError)onError('Selecciona curso, nivel, grado, sección y bimestre.',false);
            return;
        }
        $.getJSON(api,{action:'status',teacher_course_id:ctx.assignment,bimestre:ctx.bimester})
            .done(function(r){
                if(!r||Number(r.status)!==1){
                    if(onError)onError((r&&r.message)||'No se pudo verificar el cierre.',!!(r&&r.migration_required));
                    return;
                }
                onSuccess(r);
            })
            .fail(function(x){
                var r=x.responseJSON||{};
                if(onError)onError(r.message||'No se pudo verificar el estado del bimestre.',!!r.migration_required);
            });
    }


    function ensureGeneralModal(){
        if($('#gc-general-modal').length)return;
        $('body').append(
            '<div class="modal fade" id="gc-general-modal" tabindex="-1" role="dialog">'+
                '<div class="modal-dialog modal-lg modal-dialog-centered" role="document"><div class="modal-content">'+
                    '<div class="modal-header"><div><h5 class="modal-title"><i class="fas fa-lock text-success mr-2"></i>Cierre bimestral de notas</h5><small class="text-muted">Se aplica a la Lista de evaluaciones y al Libro de notas.</small></div><button class="close" data-dismiss="modal"><span>&times;</span></button></div>'+
                    '<div class="modal-body">'+
                        '<div class="small text-muted mb-3"><i class="fas fa-book mr-1"></i><span id="gc-general-context"></span></div>'+
                        '<div id="gc-general-status" class="gc-panel mb-0"><div class="text-muted small">Consultando estado del bimestre...</div></div>'+
                    '</div>'+
                    '<div class="modal-footer"><button class="btn btn-light border" data-dismiss="modal">Volver</button></div>'+
                '</div></div>'+
            '</div>'
        );
    }
    function generalError(message,migration){
        $('#gc-general-status').removeClass('gc-ready gc-closed gc-incomplete').addClass('gc-incomplete').html(
            '<div class="alert '+(migration?'alert-warning':'alert-danger')+' mb-0"><i class="fas '+(migration?'fa-database':'fa-exclamation-circle')+' mr-1"></i>'+esc(message)+'</div>');
    }
    function refreshGeneral(){
        if(!$('#gc-general-modal').is(':visible'))return;
        var ctx=generalContext(),target=$('#gc-general-status');
        if(!validContext(ctx)){generalError('Selecciona un curso y bimestre válidos.',false);return;}
        target.html('<div class="text-muted small"><i class="fas fa-spinner fa-spin mr-1"></i>Verificando cierre bimestral...</div>');
        requestStatus(ctx,function(r){
            if(!$('#gc-general-modal').is(':visible'))return;
            setPanelClass(target,r);
            target.html(statusMarkup(r));
        },generalError);
    }

    function ensureCloseModal(){
        if($('#gc-close-modal').length)return;
        $('body').append(
            '<div class="modal fade" id="gc-close-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">'+
                '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-lock text-success mr-2"></i>Cerrar notas del bimestre</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div>'+
                '<div class="modal-body"><p>Al cerrar, las evaluaciones y calificaciones del <strong>curso, aula y bimestre seleccionados</strong> quedarán en solo lectura en ambos modos de registro.</p><div class="alert alert-light border small">La reapertura requerirá autorización de Administración.</div><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="gc-confirm-check"><label class="custom-control-label" for="gc-confirm-check">He revisado las notas y deseo realizar el cierre bimestral.</label></div></div>'+
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
                '<div class="modal-body"><label class="small font-weight-bold">Motivo de la reapertura</label><textarea class="form-control" id="gc-reopen-reason" rows="4" maxlength="500" placeholder="Ej. Se detectó una calificación registrada incorrectamente."></textarea><small class="text-muted">Si Administración aprueba, volverán a habilitarse ambos modos de registro.</small></div>'+
                '<div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Cancelar</button><button class="btn btn-warning" id="gc-send-reopen"><i class="fas fa-paper-plane mr-1"></i>Enviar solicitud</button></div>'+
            '</div></div></div>'
        );
    }
    function afterClosureChange(){
        if($('#gb-assignment').val()&&$('#gb-bimester').val())$('#gb-bimester').trigger('change');
        refreshGeneral();
        $(document).trigger('grade:closure-changed');
        $(document).trigger('evaluation:gradesSaved',[{status:1,source:'grade_closure'}]);
        if(typeof window.refreshUnifiedNotifications==='function')window.refreshUnifiedNotifications();
    }


    $(document).on('click','.gc-open-closure',function(){
        if($('.gb-cell.gb-dirty').length){
            if(typeof alert_toast==='function')alert_toast('Guarda o descarta las notas modificadas antes de revisar el cierre.','warning');
            return;
        }
        var sel=viewSelection($(this).data('gc-view')==='book'?'book':'list');
        var button=$(this);
        button.prop('disabled',true);
        resolveFromFilters(sel,function(ctx,error){
            button.prop('disabled',false);
            if(!ctx||!validContext(ctx)){
                if(typeof alert_toast==='function')alert_toast(error||'Selecciona un curso y bimestre válidos.','warning');
                return;
            }
            selectedClosureContext=ctx;
            ensureGeneralModal();
            $('#gc-general-context').text(contextText(sel));
            $('#gc-general-modal').one('shown.bs.modal',refreshGeneral).modal('show');
        });
    });
    $(document).on('click','.gc-verify',function(){
        $('#gc-general-detail-area').stop(true,true).slideToggle(150);
    });
    $(document).on('click','.gc-close',function(){
        var ctx=generalContext();
        if(!validContext(ctx))return;
        if($('.gb-cell.gb-dirty').length){
            if(typeof alert_toast==='function')alert_toast('Guarda o descarta las notas modificadas antes de cerrar.','warning');
            return;
        }
        actionContext=ctx;
        ensureCloseModal();
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
                    refreshGeneral();
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
        actionContext=generalContext();
        if(!validContext(actionContext))return;
        ensureReopenModal();
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

    $(document).on('evaluation:saved.gradeClosure evaluation:gradesSaved.gradeClosure',function(e,payload){
        if(payload&&payload.source==='grade_closure')return;
        setTimeout(refreshGeneral,300);
    });
    $(document).on('grade:reopen-reviewed.gradeClosure',afterClosureChange);
})(window.jQuery);
