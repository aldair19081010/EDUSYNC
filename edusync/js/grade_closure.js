(function($){
    'use strict';
    if (!$ || !$('#gr-book-view').length) return;

    var api='grade_closure_api.php';
    var csrf=window.EDUSYNC_CSRF||'';
    var currentStatus=null;
    var actionContext=null;
    var requestSeq=0;

    var style='<style id="gc-style">'+
        '.gc-period-btn{border-radius:1rem;font-weight:700;padding:.28rem .7rem}'+
        '.gc-period-btn i{font-size:.75rem}.gc-menu-note{font-size:.72rem;color:#858796;max-width:250px;white-space:normal;line-height:1.3}'+
        '.gc-detail-row{display:flex;align-items:flex-start;padding:.45rem 0;border-bottom:1px solid #f0f1f5}.gc-detail-row:last-child{border-bottom:0}.gc-detail-row i{width:22px;margin-top:.15rem}'+
        '.gc-detail-box{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.4rem;padding:.65rem .8rem}'+
        '@media(max-width:575px){.gc-period-btn{width:100%}}'+
        '</style>';
    if(!$('#gc-style').length)$('head').append(style);

    $('#gr-general-close,#gc-general-modal,#gc-book-panel').remove();
    if(!$('#gb-period-action').length){
        $('<div id="gb-period-action" class="gb-period-action ml-auto"></div>').insertBefore('#gb-readonly-banner');
    }

    function esc(v){return $('<div>').text(v==null?'':v).html();}
    function fmtDate(v){
        if(!v)return '—';
        var m=String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m)return esc(v);
        var h=Number(m[4]),suffix=h>=12?'p. m.':'a. m.';h=h%12||12;
        return m[3]+'/'+m[2]+'/'+m[1]+' '+String(h).padStart(2,'0')+':'+m[5]+' '+suffix;
    }
    function gradeLabel(v){
        var t=String(v==null?'':v).replace(/[°º]+/g,'').trim();
        return t?t+'°':'';
    }
    function bookContext(){
        return {assignment:String($('#gb-assignment').val()||''),bimester:String($('#gb-bimester').val()||'')};
    }
    function validContext(ctx){return ctx&&Number(ctx.assignment)>0&&/^[1-4]$/.test(String(ctx.bimester||''));}
    function contextLabel(){
        var p=[],course=$('#gb-course').val(),level=$('#gb-level').val(),grade=$('#gb-grade').val(),section=$('#gb-section').val(),bim=$('#gb-bimester').val();
        if(course)p.push(course);
        if(level)p.push(level);
        if(grade)p.push(gradeLabel(grade)+(section?' '+section:''));
        if(bim)p.push(bim+'° Bimestre');
        return p.join(' · ');
    }
    function pendingCount(data){
        var s=(data&&data.validation&&data.validation.summary)||{};
        return Math.max(0,Number(s.expected_grade_cells||0)-Number(s.completed_grade_cells||0));
    }
    function stateData(data){
        if(data&&data.closed)return {label:'Cerrado',icon:'fa-lock',btn:'btn-outline-info'};
        if(data&&data.ready)return {label:'Listo para cerrar',icon:'fa-check-circle',btn:'btn-outline-success'};
        return {label:'En edición',icon:'fa-pencil-alt',btn:'btn-outline-warning'};
    }
    function renderPeriodAction(data){
        var target=$('#gb-period-action');
        if(!data){target.empty();return;}
        var st=stateData(data),pending=pendingCount(data),menu='';
        if(data.closed){
            menu+='<button class="dropdown-item gc-detail"><i class="fas fa-info-circle text-primary mr-2"></i>Ver detalle del cierre</button>';
            menu+='<div class="dropdown-divider"></div>';
            if(data.pending_reopen){
                menu+='<span class="dropdown-item-text small text-warning"><i class="fas fa-hourglass-half mr-2"></i>Reapertura pendiente</span>';
            }else{
                menu+='<button class="dropdown-item gc-request-reopen"><i class="fas fa-unlock-alt text-warning mr-2"></i>Solicitar reapertura</button>';
            }
        }else if(data.ready){
            menu+='<button class="dropdown-item gc-close"><i class="fas fa-lock text-success mr-2"></i>Revisar y cerrar</button>';
            menu+='<button class="dropdown-item gc-detail"><i class="fas fa-clipboard-check text-primary mr-2"></i>Ver verificación</button>';
        }else{
            if(pending>0)menu+='<button class="dropdown-item gc-show-pending"><i class="fas fa-user-clock text-warning mr-2"></i>Ver pendientes ('+pending+')</button>';
            menu+='<button class="dropdown-item gc-detail"><i class="fas fa-clipboard-check text-primary mr-2"></i>Ver qué falta</button>';
        }

        target.html(
            '<div class="dropdown">'+
                '<button class="btn btn-sm '+st.btn+' gc-period-btn dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'+
                    '<i class="fas '+st.icon+' mr-1"></i>'+st.label+
                '</button>'+
                '<div class="dropdown-menu dropdown-menu-right shadow">'+
                    '<h6 class="dropdown-header">'+esc($('#gb-bimester option:selected').text()||'Bimestre')+'</h6>'+
                    '<div class="px-3 pb-2 gc-menu-note">'+esc(contextLabel())+'</div>'+
                    menu+
                '</div>'+
            '</div>'
        );
    }
    function refreshBookStatus(){
        var ctx=bookContext(),target=$('#gb-period-action');
        currentStatus=null;
        if(!validContext(ctx)){target.empty();return;}
        var seq=++requestSeq;
        target.html('<button class="btn btn-light border btn-sm gc-period-btn" disabled><i class="fas fa-spinner fa-spin mr-1"></i>Verificando</button>');
        $.getJSON(api,{action:'status',teacher_course_id:ctx.assignment,bimestre:ctx.bimester})
            .done(function(r){
                if(seq!==requestSeq)return;
                if(!r||Number(r.status)!==1){
                    target.html('<button class="btn btn-outline-danger btn-sm gc-period-btn gc-detail"><i class="fas fa-exclamation-circle mr-1"></i>Revisar cierre</button>');
                    currentStatus=r||null;
                    return;
                }
                currentStatus=r;
                renderPeriodAction(r);
            })
            .fail(function(){
                if(seq!==requestSeq)return;
                target.html('<button class="btn btn-outline-danger btn-sm gc-period-btn gc-detail"><i class="fas fa-exclamation-circle mr-1"></i>Revisar cierre</button>');
            });
    }
    function detailRows(data){
        var validation=(data&&data.validation)||{},issues=validation.issues||[],s=validation.summary||{},pending=pendingCount(data);
        var html='<div class="gc-detail-box">';
        html+='<div class="gc-detail-row '+(Number(s.percentage_total||0)===100?'text-success':'text-danger')+'"><i class="fas '+(Number(s.percentage_total||0)===100?'fa-check-circle':'fa-times-circle')+'"></i><div><strong>Porcentajes de competencias: '+Number(s.percentage_total||0).toFixed(2)+'%</strong></div></div>';
        html+='<div class="gc-detail-row '+(Number(s.evaluations||0)>0?'text-success':'text-danger')+'"><i class="fas '+(Number(s.evaluations||0)>0?'fa-check-circle':'fa-times-circle')+'"></i><div><strong>'+Number(s.evaluations||0)+' evaluaciones</strong></div></div>';
        html+='<div class="gc-detail-row '+(pending===0?'text-success':'text-danger')+'"><i class="fas '+(pending===0?'fa-check-circle':'fa-times-circle')+'"></i><div><strong>'+pending+' calificaciones pendientes</strong><br><small>'+Number(s.completed_grade_cells||0)+' de '+Number(s.expected_grade_cells||0)+' registradas</small></div></div>';
        if(issues.length){
            html+='<div class="pt-2">';
            issues.forEach(function(issue){html+='<div class="small text-danger mb-1"><i class="fas fa-angle-right mr-1"></i>'+esc(issue.message||'Hay información pendiente.')+'</div>';});
            html+='</div>';
        }
        html+='</div>';
        return html;
    }
    function ensureDetailModal(){
        if($('#gc-detail-modal').length)return;
        $('body').append(
            '<div class="modal fade" id="gc-detail-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">'+
                '<div class="modal-header"><div><h5 class="modal-title"><i class="fas fa-clipboard-check text-primary mr-2"></i>Estado del bimestre</h5><small class="text-muted" id="gc-detail-context"></small></div><button class="close" data-dismiss="modal"><span>&times;</span></button></div>'+
                '<div class="modal-body" id="gc-detail-body"></div>'+
                '<div class="modal-footer"><button class="btn btn-light border" data-dismiss="modal">Cerrar</button></div>'+
            '</div></div></div>'
        );
    }
    function ensureCloseModal(){
        if($('#gc-close-modal').length)return;
        $('body').append(
            '<div class="modal fade" id="gc-close-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">'+
                '<div class="modal-header"><div><h5 class="modal-title"><i class="fas fa-lock text-success mr-2"></i>Revisar y cerrar bimestre</h5><small class="text-muted" id="gc-close-context"></small></div><button class="close" data-dismiss="modal"><span>&times;</span></button></div>'+
                '<div class="modal-body"><div id="gc-close-checks" class="mb-3"></div><div class="alert alert-light border small">Al cerrar, las evaluaciones y calificaciones de este curso quedarán en solo lectura. La reapertura requerirá autorización de Administración.</div><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="gc-confirm-check"><label class="custom-control-label" for="gc-confirm-check">He revisado las notas y deseo cerrar este bimestre.</label></div></div>'+
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
                '<div class="modal-body"><div class="small text-muted mb-3" id="gc-reopen-context"></div><label class="small font-weight-bold">Motivo de la reapertura</label><textarea class="form-control" id="gc-reopen-reason" rows="4" maxlength="500" placeholder="Ej. Se detectó una calificación registrada incorrectamente."></textarea><small class="text-muted">Si Administración aprueba, volverán a habilitarse las notas.</small></div>'+
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

    $(document).on('click','.gc-show-pending',function(){
        $('#gb-student-filter').val('pending').trigger('change');
        var target=$('#gb-container');if(target.length)$('html,body').animate({scrollTop:Math.max(0,target.offset().top-120)},180);
    });
    $(document).on('click','.gc-detail',function(){
        if(!currentStatus){
            if(typeof alert_toast==='function')alert_toast('No se pudo obtener el estado del bimestre.','warning');
            return;
        }
        ensureDetailModal();
        $('#gc-detail-context').text(contextLabel());
        $('#gc-detail-body').html(detailRows(currentStatus));
        $('#gc-detail-modal').modal('show');
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
        $('#gc-close-checks').html(detailRows(currentStatus));
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
                    refreshBookStatus();return;
                }
                $('#gc-close-modal').modal('hide');
                if(typeof alert_toast==='function')alert_toast(r.message,'success');
                afterClosureChange();
            })
            .fail(function(x){if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo cerrar el bimestre.','danger');})
            .always(function(){btn.prop('disabled',false).html('<i class="fas fa-lock mr-1"></i>Cerrar bimestre');});
    });
    $(document).on('click','.gc-request-reopen',function(){
        actionContext=bookContext();if(!validContext(actionContext))return;
        ensureReopenModal();
        $('#gc-reopen-context').text(contextLabel());
        $('#gc-reopen-reason').val('');
        $('#gc-reopen-modal').modal('show');
        setTimeout(function(){$('#gc-reopen-reason').focus();},250);
    });
    $(document).on('click','#gc-send-reopen',function(){
        var reason=$.trim($('#gc-reopen-reason').val());
        if(reason.length<5){if(typeof alert_toast==='function')alert_toast('Explica el motivo de la reapertura.','warning');return;}
        if(!csrf){if(typeof alert_toast==='function')alert_toast('No se encontró el token de seguridad. Recarga la página.','warning');return;}
        if(!actionContext||!validContext(actionContext))return;
        var btn=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Enviando...');
        $.post(api+'?action=request_reopen',{csrf_token:csrf,teacher_course_id:actionContext.assignment,bimestre:actionContext.bimester,reason:reason},null,'json')
            .done(function(r){
                if(!r||Number(r.status)!==1){if(typeof alert_toast==='function')alert_toast((r&&r.message)||'No se pudo enviar la solicitud.','danger');return;}
                $('#gc-reopen-modal').modal('hide');
                if(typeof alert_toast==='function')alert_toast(r.message,'success');
                afterClosureChange();
            })
            .fail(function(x){if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo enviar la solicitud.','danger');})
            .always(function(){btn.prop('disabled',false).html('<i class="fas fa-paper-plane mr-1"></i>Enviar solicitud');});
    });

    $(document).on('change.gradeClosure','#gb-year,#gb-course,#gb-level,#gb-grade,#gb-section,#gb-bimester',function(){setTimeout(refreshBookStatus,120);});
    $(document).on('click.gradeClosure','#gr-show-book',function(){setTimeout(refreshBookStatus,120);});
    $(document).on('evaluation:saved.gradeClosure evaluation:gradesSaved.gradeClosure',function(e,payload){
        if(payload&&payload.source==='grade_closure')return;
        setTimeout(refreshBookStatus,300);
    });
    $(document).on('grade:reopen-reviewed.gradeClosure',afterClosureChange);

    setTimeout(refreshBookStatus,180);
})(window.jQuery);
