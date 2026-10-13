(function($){
    'use strict';
    if (!$ || !$('#gr-list-view').length || !$('#gr-book-view').length) return;

    var api = 'grade_closure_api.php';
    var contextsApi = 'gradebook_api.php';
    var csrf = window.EDUSYNC_CSRF || '';
    var selectedClosureContext = null;
    var actionContext = null;

    var style = '<style id="gc-style">' +
        '.gc-modal .modal-dialog{max-width:720px}.gc-modal .modal-content{border:1px solid #e3e6f0;border-radius:.65rem;overflow:hidden}' +
        '.gc-modal .modal-header{padding:1rem 1.25rem;border-bottom:1px solid #e9edf4}.gc-modal .modal-title{font-weight:700;color:#2e3a59;font-size:1.05rem}.gc-modal .modal-body{padding:1.05rem 1.25rem}' +
        '.gc-context{font-size:.82rem;color:#526078;background:#f8f9fc;border:1px solid #e9edf4;border-radius:.4rem;padding:.55rem .75rem;line-height:1.5;margin-bottom:1rem}' +
        '.gc-state-line{display:flex;align-items:center;flex-wrap:wrap;gap:.4rem .7rem}.gc-state-chip{display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;border-radius:1rem;padding:.24rem .55rem}' +
        '.gc-state-chip.gc-ready{color:#13795b;background:#e6f6ef}.gc-state-chip.gc-closed{color:#0b7285;background:#e7f5f8}.gc-state-chip.gc-incomplete{color:#91620c;background:#fff5da}' +
        '.gc-status-title{font-size:1rem;font-weight:700;color:#273750}.gc-status-sub{font-size:.81rem;line-height:1.5;color:#6e7891;margin:.35rem 0 .65rem}' +
        '.gc-stats{display:flex;flex-wrap:wrap;align-items:baseline;gap:.35rem 1.05rem;padding:.55rem 0;border-top:1px solid #edf0f5;border-bottom:1px solid #edf0f5;margin:.75rem 0}' +
        '.gc-stat{display:inline-flex;align-items:baseline;gap:.3rem;font-size:.76rem;color:#6b7280}.gc-stat strong{color:#314262;font-size:.88rem;font-weight:700}' +
        '.gc-progress{height:6px;background:#edf1f7;border-radius:1rem}.gc-progress-label{font-size:.76rem;color:#66748b}.gc-progress .progress-bar{border-radius:1rem}' +
        '.gc-check-heading{font-size:.79rem;font-weight:700;color:#40506d;margin:.85rem 0 .25rem}' +
        '.gc-checks{max-height:190px;overflow:auto}.gc-check{display:flex;align-items:flex-start;padding:.35rem 0;gap:.35rem;font-size:.8rem;line-height:1.4;color:#5f6d83}.gc-check i{width:18px;flex:0 0 18px;margin-top:.15rem}' +
        '.gc-closed-info{font-size:.78rem;color:#53617a;padding:.35rem 0}.gc-admin-note{display:flex;gap:.55rem;padding:.6rem 0 0;border-top:1px solid #edf0f5;margin-top:.7rem;font-size:.78rem;line-height:1.5;color:#68768b}' +
        '.gc-admin-note i{margin-top:.2rem;color:#4e73df}.gc-modal .modal-footer{border-top:1px solid #e9edf4;padding:.7rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:.6rem}' +
        '.gc-confirm-area{font-size:.78rem;line-height:1.45;flex:1 1 240px;padding:.25rem 0}.gc-confirm-area label{cursor:pointer;margin-bottom:0}.gc-footer-actions{display:flex;align-items:center;justify-content:flex-end;gap:.4rem;flex-wrap:wrap;margin-left:auto}' +
        '.gc-footer-actions .btn{white-space:nowrap}.gc-reopen-context{font-size:.8rem;color:#65748b;border-bottom:1px solid #e9edf4;padding-bottom:.65rem;margin-bottom:.8rem}' +
        '@media(max-width:575.98px){.gc-modal .modal-dialog{margin:.45rem}.gc-modal .modal-header,.gc-modal .modal-body{padding:.85rem}.gc-modal .modal-footer{padding:.7rem .85rem;display:block}.gc-footer-actions{width:100%;margin-top:.7rem}.gc-footer-actions .btn{flex:1}.gc-confirm-area{padding-top:.2rem}.gc-stats{gap:.25rem .65rem}}' +
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
        var stats=[['Estudiantes',s.students||0],['Evaluaciones',s.evaluations||0],['Competencias',s.competencies||0],['Ponderación',Number(s.percentage_total||0).toFixed(2)+'%']];
        return '<div class="gc-stats">'+stats.map(function(stat){return '<span class="gc-stat"><strong>'+esc(stat[1])+'</strong>'+esc(stat[0])+'</span>';}).join('')+'</div>';
    }
    function checksHtml(data){
        var validation=data.validation||{},issues=validation.issues||[],summary=validation.summary||{},ready=!!data.ready,closed=!!data.closed;
        if(closed)return '';
        var pending=Math.max(0,Number(summary.expected_grade_cells||0)-Number(summary.completed_grade_cells||0));
        var positive=ready?[
            'Competencias configuradas correctamente',
            'Evaluaciones y notas completas',
            'Ponderación de competencias al 100%'
        ]:[];
        var rows=ready?positive.map(function(x){return '<div class="gc-check"><i class="fas fa-check-circle text-success"></i><span>'+esc(x)+'</span></div>';}).join(''):
            issues.map(function(x){return '<div class="gc-check"><i class="fas fa-exclamation-circle text-warning"></i><span>'+esc(x.message||'Información pendiente')+'</span></div>';}).join('');
        if(!ready&&!issues.length)rows='<div class="gc-check"><i class="fas fa-info-circle text-primary"></i><span>Revisa la configuración del curso y las notas pendientes.</span></div>';
        return '<div class="gc-check-heading">'+(ready?'Verificación completada':'Por resolver'+(pending?' · '+pending+' calificaciones pendientes':''))+'</div><div class="gc-checks">'+rows+'</div>';
    }
    function statusMarkup(data){
        var closed=!!data.closed,ready=!!data.ready,validation=data.validation||{},summary=validation.summary||{},state=closed?'Cerrado':(ready?'Listo para cerrar':'Pendiente de completar');
        var mode=closed?'closed':(ready?'ready':'incomplete');
        var icon=closed?'fa-lock':(ready?'fa-check-circle':'fa-clock');
        var subtitle=closed?'El registro de notas está en solo lectura en ambas vistas.':(ready?'El curso y el bimestre cumplen las condiciones para el cierre.':'Completa las condiciones pendientes antes de cerrar las notas.');
        var detail=closed&&data.closure?'<div class="gc-closed-info"><i class="fas fa-user-check mr-1"></i>Cerrado por <strong>'+esc(data.closure.closed_by_name||'Docente')+'</strong> · '+fmtDate(data.closure.closed_at)+'</div>':'';
        var pending=data.pending_reopen?'<div class="gc-closed-info text-warning"><i class="fas fa-hourglass-half mr-1"></i>Solicitud de reapertura pendiente desde '+fmtDate(data.pending_reopen.created_at)+'</div>':'';
        var progress=Number(summary.progress||0);
        var progressHtml=closed?'':'<div class="d-flex justify-content-between align-items-center gc-progress-label"><span>Calificaciones registradas</span><strong>'+Math.max(0,Math.min(100,progress)).toFixed(1)+'%</strong></div><div class="progress gc-progress mt-1"><div class="progress-bar '+(ready?'bg-success':'bg-primary')+'" style="width:'+Math.max(0,Math.min(100,progress))+'%"></div></div>';
        var notice=data.admin_notification_enabled
            ? 'Al cerrar, Administración recibirá una notificación con el docente, curso, aula y bimestre. El cierre también quedará en el control académico.'
            : 'El cierre quedará registrado en el control académico, con el docente y la fecha. Para recibir alertas también en el Centro de notificaciones debe estar habilitado ese módulo.';
        return '<div class="gc-state-line"><span class="gc-state-chip gc-'+mode+'"><i class="fas '+icon+'"></i>'+esc(state)+'</span></div>'+
            '<div class="gc-status-sub">'+subtitle+'</div>'+
            detail+pending+summaryHtml(summary)+progressHtml+checksHtml(data)+
            '<div class="gc-admin-note"><i class="fas fa-bell"></i><span>'+esc(notice)+'</span></div>';
    }
    function setDialogActions(data){
        var closed=!!data.closed,ready=!!data.ready;
        $('#gc-confirm-area,#gc-confirm-close').toggleClass('d-none',closed||!ready);
        $('#gc-confirm-check').prop('checked',false);
        $('#gc-confirm-close').prop('disabled',true);
        $('#gc-request-reopen').toggleClass('d-none',!closed||!!data.pending_reopen);
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
            '<div class="modal fade gc-modal" id="gc-general-modal" tabindex="-1" role="dialog" aria-labelledby="gc-modal-title">'+
                '<div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">'+
                    '<div class="modal-header"><div><h5 class="modal-title" id="gc-modal-title"><i class="fas fa-clipboard-check text-primary mr-2"></i>Cierre bimestral</h5><small class="text-muted">Revisión y confirmación del registro de notas</small></div><button class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button></div>'+
                    '<div class="modal-body">'+
                        '<div class="gc-context"><i class="fas fa-book-open text-primary mr-2"></i><strong id="gc-general-context"></strong></div>'+
                        '<div id="gc-general-status" role="status" aria-live="polite"><div class="text-muted small"><i class="fas fa-spinner fa-spin mr-1"></i>Verificando el bimestre...</div></div>'+
                    '</div>'+
                    '<div class="modal-footer">'+
                        '<div id="gc-confirm-area" class="gc-confirm-area d-none"><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="gc-confirm-check"><label class="custom-control-label" for="gc-confirm-check">He revisado las notas y confirmo el cierre de este bimestre.</label></div></div>'+
                        '<div class="gc-footer-actions"><button class="btn btn-light border btn-sm" data-dismiss="modal">Volver</button><button class="btn btn-outline-warning btn-sm d-none" id="gc-request-reopen"><i class="fas fa-unlock-alt mr-1"></i>Solicitar reapertura</button><button class="btn btn-success btn-sm d-none" id="gc-confirm-close" disabled><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button></div>'+
                    '</div>'+
                '</div></div>'+
            '</div>'
        );
        $(document).on('change','#gc-confirm-check',function(){$('#gc-confirm-close').prop('disabled',!this.checked)});
        $(document).on('hidden.bs.modal','#gc-general-modal',function(){
            $('#gc-confirm-check').prop('checked',false);$('#gc-confirm-close').prop('disabled',true);
        });
    }
    function generalError(message,migration){
        $('#gc-confirm-area,#gc-confirm-close,#gc-request-reopen').addClass('d-none');
        $('#gc-general-status').html('<div class="alert '+(migration?'alert-warning':'alert-danger')+' mb-0"><i class="fas fa-exclamation-circle mr-1"></i>'+esc(message)+'</div>');
    }
    function refreshGeneral(){
        if(!$('#gc-general-modal').is(':visible'))return;
        var ctx=generalContext(),target=$('#gc-general-status');
        if(!validContext(ctx)){generalError('Selecciona un curso y bimestre válidos.',false);return;}
        $('#gc-confirm-area,#gc-confirm-close,#gc-request-reopen').addClass('d-none');
        target.html('<div class="text-muted small py-2"><i class="fas fa-spinner fa-spin mr-1"></i>Verificando el bimestre...</div>');
        requestStatus(ctx,function(r){
            if(!$('#gc-general-modal').is(':visible')||ctx!==selectedClosureContext)return;
            target.html(statusMarkup(r));setDialogActions(r);
        },generalError);
    }
    function ensureReopenModal(){
        if($('#gc-reopen-modal').length)return;
        $('body').append(
            '<div class="modal fade" id="gc-reopen-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">'+
                '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-unlock-alt text-warning mr-2"></i>Solicitar reapertura</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div>'+
                '<div class="modal-body"><div class="gc-reopen-context" id="gc-reopen-context"></div><label class="small font-weight-bold" for="gc-reopen-reason">Motivo de la solicitud</label><textarea class="form-control" id="gc-reopen-reason" rows="3" maxlength="500" placeholder="Indica qué calificación necesitas corregir y por qué."></textarea><small class="text-muted d-block mt-2">Administración revisará la solicitud antes de habilitar nuevamente las notas.</small></div>'+
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
    $(document).on('click','#gc-request-reopen',function(){
        $(document).trigger('gc:reopen-click');
    });
    $(document).on('click','#gc-confirm-close',function(){
        actionContext=generalContext();
        if(!$('#gc-confirm-check').prop('checked')||!actionContext||!validContext(actionContext))return;
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
                $('#gc-confirm-check').prop('checked',false);
                if(typeof alert_toast==='function')alert_toast(r.message,'success');
                afterClosureChange();
            })
            .fail(function(x){
                if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo cerrar el bimestre.','danger');
            })
            .always(function(){btn.html('<i class="fas fa-lock mr-1"></i>Cerrar bimestre').prop('disabled',!$('#gc-confirm-check').prop('checked')||btn.hasClass('d-none'));});
    });
    $(document).on('gc:reopen-click',function(){
        actionContext=generalContext();
        if(!validContext(actionContext))return;
        ensureReopenModal();
        $('#gc-reopen-context').text($('#gc-general-context').text());
        $('#gc-reopen-reason').val('');
        $('#gc-general-modal').one('hidden.bs.modal',function(){
            $('#gc-reopen-modal').modal('show');
            setTimeout(function(){$('#gc-reopen-reason').focus();},250);
        }).modal('hide');
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
