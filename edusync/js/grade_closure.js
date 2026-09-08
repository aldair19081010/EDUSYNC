(function($){
    'use strict';
    if (!$ || !$('#gr-list-view').length || !$('#gr-book-view').length) return;

    var api = 'grade_closure_api.php';
    var contextsApi = 'gradebook_api.php';
    var csrf = window.EDUSYNC_CSRF || '';
    var bookCurrentKey = '';
    var bookLoading = false;
    var bookLastRefresh = 0;
    var generalAssignments = [];
    var actionContext = null;
    var actionScope = 'book';

    var style = '<style id="gc-style">' +
        '.gc-panel{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.5rem;padding:.9rem 1rem;margin-bottom:1rem}' +
        '.gc-panel.gc-ready{border-left-color:#1cc88a}.gc-panel.gc-closed{border-left-color:#36b9cc}.gc-panel.gc-incomplete{border-left-color:#f6c23e}' +
        '.gc-state{font-size:.75rem;font-weight:800;text-transform:uppercase}.gc-meta{font-size:.78rem;color:#6c757d}.gc-progress{height:7px}' +
        '.gc-check{display:flex;align-items:flex-start;padding:.38rem 0;border-bottom:1px solid #f0f1f5}.gc-check:last-child{border-bottom:0}.gc-check i{width:20px;margin-top:.15rem}' +
        '.gc-details{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.4rem;padding:.65rem .8rem;margin-top:.7rem;max-height:250px;overflow:auto}' +
        '.gc-actions{gap:.4rem}.gc-summary-pill{display:inline-block;border:1px solid #e3e6f0;border-radius:1rem;padding:.15rem .55rem;margin:.1rem .15rem .1rem 0;background:#fff;font-size:.75rem}' +
        '.gc-general-note{border-left:4px solid #4e73df;background:#f8f9fc}.gc-general-status{min-height:90px}.gc-general-select label{font-size:.72rem;text-transform:uppercase;font-weight:700;color:#5a5c69}' +
        '@media(max-width:767px){.gc-panel .d-flex.gc-main{display:block!important}.gc-actions{margin-top:.7rem;display:flex!important;flex-wrap:wrap}.gc-actions .btn{flex:1 1 auto}}' +
        '</style>';
    if (!$('#gc-style').length) $('head').append(style);

    if (!$('#gr-general-close').length) {
        $('<button type="button" class="btn btn-outline-success btn-sm mr-1" id="gr-general-close"><i class="fas fa-lock mr-1"></i>Cierre bimestral</button>').insertBefore('#gr-new');
    }

    var bookPanel = $('<div id="gc-panel" class="gc-panel"><div class="text-muted small"><i class="fas fa-lock mr-1"></i>Selecciona un curso y un bimestre para verificar el cierre general.</div></div>');
    $('#gr-book-view > .gr-filter').first().after(bookPanel);

    function esc(v){ return $('<div>').text(v == null ? '' : v).html(); }
    function normalize(v){ return String(v == null ? '' : v).replace(/[°º]+/g,'').trim().toLowerCase(); }
    function fmtDate(v){
        if(!v) return '—';
        var m=String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m) return esc(v);
        var h=Number(m[4]),suffix=h>=12?'p. m.':'a. m.';h=h%12||12;
        return m[3]+'/'+m[2]+'/'+m[1]+' '+String(h).padStart(2,'0')+':'+m[5]+' '+suffix;
    }
    function validContext(ctx){ return ctx && Number(ctx.assignment)>0 && /^[1-4]$/.test(String(ctx.bimester||'')); }
    function bookContext(){ return {assignment:String($('#gb-assignment').val()||''),bimester:String($('#gb-bimester').val()||'')}; }
    function generalContext(){ return {assignment:String($('#gc-general-assignment').val()||''),bimester:String($('#gc-general-bimester').val()||'')}; }
    function getScopeContext(scope){ return scope==='general'?generalContext():bookContext(); }
    function bookContextKey(){ var c=bookContext();return c.assignment+':'+c.bimester; }
    function summaryHtml(s){
        s=s||{};
        return '<span class="gc-summary-pill"><i class="fas fa-tasks mr-1"></i>'+Number(s.competencies||0)+' competencias</span>'+
            '<span class="gc-summary-pill"><i class="fas fa-clipboard-list mr-1"></i>'+Number(s.evaluations||0)+' evaluaciones</span>'+
            '<span class="gc-summary-pill"><i class="fas fa-users mr-1"></i>'+Number(s.students||0)+' estudiantes</span>'+
            '<span class="gc-summary-pill"><i class="fas fa-percentage mr-1"></i>'+Number(s.percentage_total||0).toFixed(2)+'%</span>';
    }
    function detailsHtml(validation,scope){
        var issues=(validation&&validation.issues)||[];
        var id=scope==='general'?'gc-general-detail-area':'gc-detail-area';
        if(!issues.length)return '<div id="'+id+'" class="gc-details" style="display:none"><div class="gc-check text-success"><i class="fas fa-check-circle"></i><div><strong>Verificación completa.</strong><br><small>Todas las condiciones necesarias para el cierre se cumplen.</small></div></div></div>';
        var html='<div id="'+id+'" class="gc-details" style="display:none">';
        issues.forEach(function(issue){html+='<div class="gc-check text-danger"><i class="fas fa-times-circle"></i><div>'+esc(issue.message||'Hay información pendiente.')+'</div></div>';});
        return html+'</div>';
    }
    function statusMarkup(data,scope){
        var state=data.state||'Incompleto',validation=data.validation||{},s=validation.summary||{},closed=!!data.closed;
        var icon=closed?'fa-lock':(data.ready?'fa-check-circle':'fa-exclamation-triangle');
        var color=closed?'text-info':(data.ready?'text-success':'text-warning');
        var title=closed?'Bimestre cerrado':(data.ready?'Listo para cerrar':'Cierre incompleto');
        var sub=closed?'El cierre protege las notas en las dos formas de registro.':(data.ready?'Todo está correcto. El cierre se aplicará a Lista de evaluaciones y Libro de notas.':'Corrige los pendientes antes de cerrar. El mismo control aplica a ambos modos de registro.');
        var actions='<button class="btn btn-outline-primary btn-sm gc-verify" data-scope="'+scope+'"><i class="fas fa-clipboard-check mr-1"></i>Verificar</button>';
        if(closed){
            if(data.pending_reopen)actions+='<button class="btn btn-warning btn-sm" disabled><i class="fas fa-hourglass-half mr-1"></i>Reapertura pendiente</button>';
            else actions+='<button class="btn btn-outline-warning btn-sm gc-request-reopen" data-scope="'+scope+'"><i class="fas fa-unlock-alt mr-1"></i>Solicitar reapertura</button>';
        } else if(data.ready) {
            actions+='<button class="btn btn-success btn-sm gc-close" data-scope="'+scope+'"><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button>';
        } else {
            actions+='<button class="btn btn-secondary btn-sm" disabled><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button>';
        }
        var meta='';
        if(closed&&data.closure)meta='<div class="gc-meta mt-1">Cerrado por <strong>'+esc(data.closure.closed_by_name||'Docente')+'</strong> · '+fmtDate(data.closure.closed_at)+' · Cierre #'+Number(data.closure.closure_version||1)+'</div>';
        else if(data.pending_reopen)meta='<div class="gc-meta mt-1">Solicitud de reapertura enviada '+fmtDate(data.pending_reopen.created_at)+'</div>';
        var progress=Number(s.progress||0);
        return '<div class="d-flex gc-main justify-content-between align-items-start"><div class="pr-md-3 flex-grow-1"><div class="'+color+' gc-state"><i class="fas '+icon+' mr-1"></i>'+esc(state)+'</div><div class="font-weight-bold text-gray-800">'+title+'</div><div class="small text-muted">'+sub+'</div>'+meta+'<div class="mt-2">'+summaryHtml(s)+'</div><div class="d-flex justify-content-between small mt-2"><span>Completitud de calificaciones</span><strong>'+progress.toFixed(1)+'%</strong></div><div class="progress gc-progress"><div class="progress-bar '+(progress>=100?'bg-success':'bg-warning')+'" style="width:'+Math.min(100,progress)+'%"></div></div></div><div class="gc-actions d-flex">'+actions+'</div></div>'+detailsHtml(validation,scope);
    }
    function applyBookReadOnly(closed){
        if(closed){
            $('#gb-readonly-banner').show().find('span').text('Este curso y bimestre tiene un cierre general. No puede modificarse ni desde Lista de evaluaciones ni desde Libro de notas.');
            $('#gb-save,#gb-discard').prop('disabled',true);
            $('.gb-cell,.gb-add-eval,.gb-edit-eval,.gb-delete-eval,.gb-duplicate-eval').prop('disabled',true);
        } else if(!($('#gb-readonly-banner').is(':visible')&&/bloqueado|histórico/i.test($('#gb-readonly-banner').text()))){
            $('#gb-save,#gb-discard').prop('disabled',false);
        }
    }
    function setPanelClass(target,data){
        target.removeClass('gc-ready gc-closed gc-incomplete').addClass(data.closed?'gc-closed':(data.ready?'gc-ready':'gc-incomplete'));
    }
    function requestStatus(ctx,onSuccess,onError){
        if(!validContext(ctx)){if(onError)onError('Selecciona un curso/aula y un bimestre.',false);return;}
        $.getJSON(api,{action:'status',teacher_course_id:ctx.assignment,bimestre:ctx.bimester})
            .done(function(r){if(!r||Number(r.status)!==1){if(onError)onError((r&&r.message)||'No se pudo verificar el cierre.',!!(r&&r.migration_required));return;}onSuccess(r);})
            .fail(function(x){var r=x.responseJSON||{};if(onError)onError(r.message||'No se pudo verificar el estado del bimestre.',!!r.migration_required);});
    }
    function renderBook(data){setPanelClass(bookPanel,data);bookPanel.html(statusMarkup(data,'book'));applyBookReadOnly(!!data.closed);}
    function bookError(message,migration){bookPanel.removeClass('gc-ready gc-closed').addClass('gc-incomplete').html('<div class="alert '+(migration?'alert-warning':'alert-danger')+' mb-0"><i class="fas '+(migration?'fa-database':'fa-exclamation-circle')+' mr-1"></i>'+esc(message)+'</div>');}
    function refreshBook(force){
        var ctx=bookContext(),key=bookContextKey();
        if(!validContext(ctx)){bookCurrentKey=key;bookPanel.removeClass('gc-ready gc-closed gc-incomplete').html('<div class="text-muted small"><i class="fas fa-lock mr-1"></i>Selecciona un curso y un bimestre para verificar el cierre general.</div>');return;}
        if(bookLoading&&!force)return;
        bookLoading=true;bookCurrentKey=key;
        requestStatus(ctx,function(r){if(bookContextKey()!==key)return;bookLastRefresh=Date.now();renderBook(r);},function(message,migration){if(bookContextKey()===key)bookError(message,migration);});
        $(document).one('ajaxStop.gradeClosureBook',function(){bookLoading=false;});
        setTimeout(function(){bookLoading=false;},1500);
    }

    function ensureGeneralModal(){
        if($('#gc-general-modal').length)return;
        var years='';
        $('#gr-year option').each(function(){years+='<option value="'+esc($(this).val())+'">'+esc($(this).text())+'</option>';});
        $('body').append('<div class="modal fade" id="gc-general-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-xl" role="document"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title"><i class="fas fa-lock text-success mr-2"></i>Cierre general de notas</h5><small class="text-muted">Un solo cierre para las dos formas de registrar notas.</small></div><button class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body"><div class="alert gc-general-note small"><strong>Importante:</strong> este cierre no pertenece al Libro de notas ni a la Lista de evaluaciones por separado. Cierra el <strong>curso + grado + sección + bimestre</strong> y bloquea ambos modos al mismo tiempo.</div><div class="row gc-general-select"><div class="col-md-3 mb-2"><label>Año académico</label><select class="form-control form-control-sm" id="gc-general-year">'+years+'</select></div><div class="col-md-6 mb-2"><label>Curso y aula</label><select class="form-control form-control-sm" id="gc-general-assignment"><option value="">Selecciona un curso y aula</option></select></div><div class="col-md-3 mb-2"><label>Bimestre</label><select class="form-control form-control-sm" id="gc-general-bimester"><option value="">Selecciona</option><option value="1">1° Bimestre</option><option value="2">2° Bimestre</option><option value="3">3° Bimestre</option><option value="4">4° Bimestre</option></select></div></div><div id="gc-general-status" class="gc-panel gc-general-status mb-0"><div class="text-muted small"><i class="fas fa-info-circle mr-1"></i>Selecciona el curso/aula y bimestre que deseas verificar.</div></div></div><div class="modal-footer"><button class="btn btn-light border" data-dismiss="modal">Cerrar</button></div></div></div></div>');
    }
    function assignmentLabel(a){return (a.course_name||'Curso')+' · '+(a.level||'')+' · '+String(a.grado||'').replace(/[°º]+/g,'')+'° '+(a.seccion||'U');}
    function currentSuggestedSelection(){
        var bookVisible=$('#gr-book-view').is(':visible');
        if(bookVisible&&$('#gb-assignment').val())return {year:$('#gb-year').val(),assignment:$('#gb-assignment').val(),bimester:$('#gb-bimester').val()};
        return {year:$('#gr-year').val(),assignment:'',bimester:$('#gr-bimester').val(),course:$('#gr-course').val(),level:$('#gr-level').val(),grado:$('#gr-grade').val(),seccion:$('#gr-section').val()};
    }
    function loadGeneralAssignments(suggestion){
        var year=$('#gc-general-year').val(),select=$('#gc-general-assignment').prop('disabled',true).html('<option value="">Cargando asignaciones...</option>');
        generalAssignments=[];
        $.getJSON(contextsApi,{action:'contexts',academic_year_id:year}).done(function(r){
            generalAssignments=r&&Number(r.status)===1?(r.assignments||[]):[];
            var html='<option value="">Selecciona un curso y aula</option>';
            generalAssignments.forEach(function(a){html+='<option value="'+Number(a.id)+'">'+esc(assignmentLabel(a))+'</option>';});
            select.html(html).prop('disabled',false);
            var wanted='';
            if(suggestion&&suggestion.assignment&&generalAssignments.some(function(a){return String(a.id)===String(suggestion.assignment);}))wanted=String(suggestion.assignment);
            if(!wanted&&suggestion&&suggestion.course){
                var matches=generalAssignments.filter(function(a){
                    return normalize(a.course_name)===normalize(suggestion.course)&&(!suggestion.level||normalize(a.level)===normalize(suggestion.level))&&(!suggestion.grado||normalize(a.grado)===normalize(suggestion.grado))&&(!suggestion.seccion||normalize(a.seccion||'U')===normalize(suggestion.seccion||'U'));
                });
                if(matches.length===1)wanted=String(matches[0].id);
            }
            if(wanted)select.val(wanted);
            if(suggestion&&suggestion.bimester)$('#gc-general-bimester').val(String(suggestion.bimester));
            refreshGeneral();
        }).fail(function(){select.html('<option value="">No se pudieron cargar las asignaciones</option>').prop('disabled',false);generalError('No se pudieron cargar los cursos del docente.',false);});
    }
    function generalError(message,migration){
        var target=$('#gc-general-status');target.removeClass('gc-ready gc-closed').addClass('gc-incomplete').html('<div class="alert '+(migration?'alert-warning':'alert-danger')+' mb-0"><i class="fas '+(migration?'fa-database':'fa-exclamation-circle')+' mr-1"></i>'+esc(message)+'</div>');
    }
    function refreshGeneral(){
        if(!$('#gc-general-modal').is(':visible'))return;
        var ctx=generalContext(),target=$('#gc-general-status');
        if(!validContext(ctx)){target.removeClass('gc-ready gc-closed gc-incomplete').html('<div class="text-muted small"><i class="fas fa-info-circle mr-1"></i>Selecciona el curso/aula y bimestre que deseas verificar.</div>');return;}
        target.html('<div class="text-muted small"><i class="fas fa-spinner fa-spin mr-1"></i>Verificando cierre general...</div>');
        requestStatus(ctx,function(r){setPanelClass(target,r);target.html(statusMarkup(r,'general'));},generalError);
    }

    function ensureCloseModal(){
        if($('#gc-close-modal').length)return;
        $('body').append('<div class="modal fade" id="gc-close-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-lock text-success mr-2"></i>Cerrar notas del bimestre</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body"><p>Al cerrar, las notas y evaluaciones del curso/aula y bimestre seleccionados quedarán en <strong>solo lectura en los dos modos de registro</strong>.</p><div class="alert alert-light border small">La reapertura requerirá autorización de Administración.</div><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="gc-confirm-check"><label class="custom-control-label" for="gc-confirm-check">He revisado las notas y deseo realizar el cierre general.</label></div></div><div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Cancelar</button><button class="btn btn-success" id="gc-confirm-close" disabled><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button></div></div></div></div>');
        $(document).on('change','#gc-confirm-check',function(){$('#gc-confirm-close').prop('disabled',!this.checked);});
        $(document).on('hidden.bs.modal','#gc-close-modal',function(){$('#gc-confirm-check').prop('checked',false);$('#gc-confirm-close').prop('disabled',true);});
    }
    function ensureReopenModal(){
        if($('#gc-reopen-modal').length)return;
        $('body').append('<div class="modal fade" id="gc-reopen-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-unlock-alt text-warning mr-2"></i>Solicitar reapertura</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body"><label class="small font-weight-bold">Motivo de la reapertura</label><textarea class="form-control" id="gc-reopen-reason" rows="4" maxlength="500" placeholder="Ej. Se detectó una calificación registrada incorrectamente."></textarea><small class="text-muted">Si Administración aprueba, volverán a habilitarse ambos modos de registro.</small></div><div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Cancelar</button><button class="btn btn-warning" id="gc-send-reopen"><i class="fas fa-paper-plane mr-1"></i>Enviar solicitud</button></div></div></div></div>');
    }
    function afterClosureChange(){
        if($('#gb-assignment').val()&&$('#gb-bimester').val())$('#gb-bimester').trigger('change');
        refreshBook(true);refreshGeneral();
        $(document).trigger('grade:closure-changed');
        $(document).trigger('evaluation:gradesSaved',[{status:1,source:'grade_closure'}]);
        if(typeof window.refreshUnifiedNotifications==='function')window.refreshUnifiedNotifications();
    }

    $(document).on('click','#gr-general-close',function(){
        if($('.gb-cell.gb-dirty').length){if(typeof alert_toast==='function')alert_toast('Guarda o descarta las notas modificadas antes de cerrar el bimestre.','warning');return;}
        ensureGeneralModal();
        var suggestion=currentSuggestedSelection();
        if(suggestion.year)$('#gc-general-year').val(String(suggestion.year));
        $('#gc-general-bimester').val(suggestion.bimester?String(suggestion.bimester):'');
        $('#gc-general-modal').modal('show');
        loadGeneralAssignments(suggestion);
    });
    $(document).on('change','#gc-general-year',function(){loadGeneralAssignments({bimester:$('#gc-general-bimester').val()});});
    $(document).on('change','#gc-general-assignment,#gc-general-bimester',refreshGeneral);
    $(document).on('click','.gc-verify',function(){var scope=$(this).data('scope')||'book';$('#'+(scope==='general'?'gc-general-detail-area':'gc-detail-area')).stop(true,true).slideToggle(150);});
    $(document).on('click','.gc-close',function(){
        var scope=$(this).data('scope')||'book',ctx=getScopeContext(scope);
        if(!validContext(ctx))return;
        if($('.gb-cell.gb-dirty').length){if(typeof alert_toast==='function')alert_toast('Guarda o descarta las notas modificadas antes de cerrar.','warning');return;}
        actionContext=ctx;actionScope=scope;ensureCloseModal();$('#gc-close-modal').modal('show');
    });
    $(document).on('click','#gc-confirm-close',function(){
        if(!actionContext||!validContext(actionContext))return;
        if(!csrf){if(typeof alert_toast==='function')alert_toast('No se encontró el token de seguridad. Recarga la página.','warning');return;}
        var btn=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Cerrando...');
        $.post(api+'?action=close',{csrf_token:csrf,teacher_course_id:actionContext.assignment,bimestre:actionContext.bimester},null,'json').done(function(r){
            if(!r||Number(r.status)!==1){if(typeof alert_toast==='function')alert_toast((r&&r.message)||'No se pudo cerrar el bimestre.','danger');if(actionScope==='general')refreshGeneral();else refreshBook(true);return;}
            $('#gc-close-modal').modal('hide');if(typeof alert_toast==='function')alert_toast(r.message,'success');afterClosureChange();
        }).fail(function(x){if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo cerrar el bimestre.','danger');}).always(function(){btn.prop('disabled',false).html('<i class="fas fa-lock mr-1"></i>Cerrar bimestre');});
    });
    $(document).on('click','.gc-request-reopen',function(){var scope=$(this).data('scope')||'book';actionContext=getScopeContext(scope);actionScope=scope;if(!validContext(actionContext))return;ensureReopenModal();$('#gc-reopen-reason').val('');$('#gc-reopen-modal').modal('show');setTimeout(function(){$('#gc-reopen-reason').focus();},250);});
    $(document).on('click','#gc-send-reopen',function(){
        var reason=$.trim($('#gc-reopen-reason').val());if(reason.length<5){if(typeof alert_toast==='function')alert_toast('Explica el motivo de la reapertura.','warning');return;}if(!csrf){if(typeof alert_toast==='function')alert_toast('No se encontró el token de seguridad. Recarga la página.','warning');return;}if(!actionContext||!validContext(actionContext))return;
        var btn=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Enviando...');
        $.post(api+'?action=request_reopen',{csrf_token:csrf,teacher_course_id:actionContext.assignment,bimestre:actionContext.bimester,reason:reason},null,'json').done(function(r){if(!r||Number(r.status)!==1){if(typeof alert_toast==='function')alert_toast((r&&r.message)||'No se pudo enviar la solicitud.','danger');return;}$('#gc-reopen-modal').modal('hide');if(typeof alert_toast==='function')alert_toast(r.message,'success');afterClosureChange();}).fail(function(x){if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo enviar la solicitud.','danger');}).always(function(){btn.prop('disabled',false).html('<i class="fas fa-paper-plane mr-1"></i>Enviar solicitud');});
    });

    $('#gb-year,#gb-course,#gb-level,#gb-grade,#gb-section,#gb-bimester').on('change.gradeClosure',function(){setTimeout(function(){refreshBook(true);},120);});
    $('#gb-load').on('click.gradeClosure',function(){setTimeout(function(){refreshBook(true);},150);});
    $(document).on('evaluation:saved.gradeClosure evaluation:gradesSaved.gradeClosure',function(e,payload){if(payload&&payload.source==='grade_closure')return;setTimeout(function(){refreshBook(true);refreshGeneral();},300);});
    $(document).on('grade:reopen-reviewed.gradeClosure',afterClosureChange);
    window.setInterval(function(){var key=bookContextKey();if(key!==bookCurrentKey){refreshBook(true);return;}if(validContext(bookContext())&&Date.now()-bookLastRefresh>15000&&!bookLoading)refreshBook(false);},1000);
})(window.jQuery);
