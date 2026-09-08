(function($){
    'use strict';
    if (!$ || !$('#gr-book-view').length) return;

    var api = 'grade_closure_api.php';
    var currentKey = '';
    var currentData = null;
    var csrf = window.EDUSYNC_CSRF || '';
    var loading = false;
    var lastRefresh = 0;

    var style = '<style id="gc-style">' +
        '.gc-panel{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.5rem;padding:.9rem 1rem;margin-bottom:1rem}' +
        '.gc-panel.gc-ready{border-left-color:#1cc88a}.gc-panel.gc-closed{border-left-color:#36b9cc}.gc-panel.gc-incomplete{border-left-color:#f6c23e}' +
        '.gc-state{font-size:.75rem;font-weight:800;text-transform:uppercase}.gc-meta{font-size:.78rem;color:#6c757d}.gc-progress{height:7px}' +
        '.gc-check{display:flex;align-items:flex-start;padding:.38rem 0;border-bottom:1px solid #f0f1f5}.gc-check:last-child{border-bottom:0}.gc-check i{width:20px;margin-top:.15rem}' +
        '.gc-details{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.4rem;padding:.65rem .8rem;margin-top:.7rem;max-height:250px;overflow:auto}' +
        '.gc-actions{gap:.4rem}.gc-summary-pill{display:inline-block;border:1px solid #e3e6f0;border-radius:1rem;padding:.15rem .55rem;margin:.1rem .15rem .1rem 0;background:#fff;font-size:.75rem}' +
        '@media(max-width:767px){.gc-panel .d-flex.gc-main{display:block!important}.gc-actions{margin-top:.7rem;display:flex!important;flex-wrap:wrap}.gc-actions .btn{flex:1 1 auto}}' +
        '</style>';
    if (!$('#gc-style').length) $('head').append(style);

    var panel = $('<div id="gc-panel" class="gc-panel"><div class="text-muted small"><i class="fas fa-lock mr-1"></i>Selecciona un curso y un bimestre para verificar el cierre.</div></div>');
    $('#gr-book-view > .gr-filter').first().after(panel);

    function esc(v){ return $('<div>').text(v == null ? '' : v).html(); }
    function fmtDate(v){
        if(!v) return '—';
        var m=String(v).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if(!m) return esc(v);
        var h=Number(m[4]),suffix=h>=12?'p. m.':'a. m.';h=h%12||12;
        return m[3]+'/'+m[2]+'/'+m[1]+' '+String(h).padStart(2,'0')+':'+m[5]+' '+suffix;
    }
    function context(){ return {assignment:String($('#gb-assignment').val()||''),bimester:String($('#gb-bimester').val()||'')}; }
    function contextKey(){ var c=context();return c.assignment+':'+c.bimester; }
    function validContext(){ var c=context();return Number(c.assignment)>0&&/^[1-4]$/.test(c.bimester); }
    function summaryHtml(s){
        s=s||{};
        return '<span class="gc-summary-pill"><i class="fas fa-tasks mr-1"></i>'+Number(s.competencies||0)+' competencias</span>'+
            '<span class="gc-summary-pill"><i class="fas fa-clipboard-list mr-1"></i>'+Number(s.evaluations||0)+' evaluaciones</span>'+
            '<span class="gc-summary-pill"><i class="fas fa-users mr-1"></i>'+Number(s.students||0)+' estudiantes</span>'+
            '<span class="gc-summary-pill"><i class="fas fa-percentage mr-1"></i>'+Number(s.percentage_total||0).toFixed(2)+'%</span>';
    }
    function detailsHtml(validation){
        var issues=(validation&&validation.issues)||[];
        if(!issues.length)return '<div class="gc-details"><div class="gc-check text-success"><i class="fas fa-check-circle"></i><div><strong>Verificación completa.</strong><br><small>Todas las condiciones necesarias para el cierre se cumplen.</small></div></div></div>';
        var html='<div class="gc-details">';issues.forEach(function(issue){html+='<div class="gc-check text-danger"><i class="fas fa-times-circle"></i><div>'+esc(issue.message||'Hay información pendiente.')+'</div></div>';});return html+'</div>';
    }
    function applyReadOnlyHint(closed){
        if(closed){$('#gb-readonly-banner').show().find('span').text('Las notas de este curso y bimestre fueron cerradas por el docente.');$('#gb-save,#gb-discard').prop('disabled',true);$('.gb-cell,.gb-add-eval,.gb-edit-eval,.gb-delete-eval,.gb-duplicate-eval').prop('disabled',true);}
        else if(!($('#gb-readonly-banner').is(':visible')&&/bloqueado|histórico|cerrado/i.test($('#gb-readonly-banner').text())))$('#gb-save,#gb-discard').prop('disabled',false);
    }
    function render(data){
        currentData=data;var state=data.state||'Incompleto',validation=data.validation||{},s=validation.summary||{},closed=!!data.closed;
        panel.removeClass('gc-ready gc-closed gc-incomplete').addClass(closed?'gc-closed':(data.ready?'gc-ready':'gc-incomplete'));
        var icon=closed?'fa-lock':(data.ready?'fa-check-circle':'fa-exclamation-triangle'),color=closed?'text-info':(data.ready?'text-success':'text-warning'),title=closed?'Bimestre cerrado':(data.ready?'Listo para cerrar':'Cierre incompleto'),sub=closed?'Las notas están protegidas y son de solo lectura.':(data.ready?'Todas las validaciones están correctas.':'Corrige los pendientes antes de cerrar el bimestre.');
        var actions='<button class="btn btn-outline-primary btn-sm gc-verify"><i class="fas fa-clipboard-check mr-1"></i>Verificar</button>';
        if(closed){if(data.pending_reopen)actions+='<button class="btn btn-warning btn-sm" disabled><i class="fas fa-hourglass-half mr-1"></i>Reapertura pendiente</button>';else actions+='<button class="btn btn-outline-warning btn-sm gc-request-reopen"><i class="fas fa-unlock-alt mr-1"></i>Solicitar reapertura</button>';}
        else if(data.ready)actions+='<button class="btn btn-success btn-sm gc-close"><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button>';else actions+='<button class="btn btn-secondary btn-sm" disabled><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button>';
        var meta='';if(closed&&data.closure)meta='<div class="gc-meta mt-1">Cerrado por <strong>'+esc(data.closure.closed_by_name||'Docente')+'</strong> · '+fmtDate(data.closure.closed_at)+' · Cierre #'+Number(data.closure.closure_version||1)+'</div>';else if(data.pending_reopen)meta='<div class="gc-meta mt-1">Solicitud de reapertura enviada '+fmtDate(data.pending_reopen.created_at)+'</div>';
        var progress=Number(s.progress||0);
        panel.html('<div class="d-flex gc-main justify-content-between align-items-start"><div class="pr-md-3 flex-grow-1"><div class="'+color+' gc-state"><i class="fas '+icon+' mr-1"></i>'+esc(state)+'</div><div class="font-weight-bold text-gray-800">'+title+'</div><div class="small text-muted">'+sub+'</div>'+meta+'<div class="mt-2">'+summaryHtml(s)+'</div><div class="d-flex justify-content-between small mt-2"><span>Completitud de calificaciones</span><strong>'+progress.toFixed(1)+'%</strong></div><div class="progress gc-progress"><div class="progress-bar '+(progress>=100?'bg-success':'bg-warning')+'" style="width:'+Math.min(100,progress)+'%"></div></div></div><div class="gc-actions d-flex">'+actions+'</div></div><div id="gc-detail-area" style="display:none">'+detailsHtml(validation)+'</div>');applyReadOnlyHint(closed);
    }
    function renderError(message,migration){panel.removeClass('gc-ready gc-closed').addClass('gc-incomplete').html('<div class="alert '+(migration?'alert-warning':'alert-danger')+' mb-0"><i class="fas '+(migration?'fa-database':'fa-exclamation-circle')+' mr-1"></i>'+esc(message)+'</div>');}
    function refresh(force){
        if(!validContext()){currentKey=contextKey();currentData=null;panel.removeClass('gc-ready gc-closed gc-incomplete').html('<div class="text-muted small"><i class="fas fa-lock mr-1"></i>Selecciona un curso y un bimestre para verificar el cierre.</div>');return;}
        var key=contextKey();if(loading&&!force)return;loading=true;currentKey=key;
        $.getJSON(api,{action:'status',teacher_course_id:context().assignment,bimestre:context().bimester}).done(function(r){if(contextKey()!==key)return;if(!r||Number(r.status)!==1){renderError((r&&r.message)||'No se pudo verificar el cierre.',!!(r&&r.migration_required));return;}lastRefresh=Date.now();render(r);}).fail(function(x){if(contextKey()!==key)return;var r=x.responseJSON||{};renderError(r.message||'No se pudo verificar el estado del bimestre.',!!r.migration_required);}).always(function(){loading=false;});
    }
    function reloadBook(){if($('#gb-assignment').val()&&$('#gb-bimester').val())$('#gb-bimester').trigger('change');$(document).trigger('grade:closure-changed');if(typeof window.refreshUnifiedNotifications==='function')window.refreshUnifiedNotifications();setTimeout(function(){refresh(true);},350);}
    function ensureCloseModal(){
        if($('#gc-close-modal').length)return;
        $('body').append('<div class="modal fade" id="gc-close-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-lock text-success mr-2"></i>Cerrar notas del bimestre</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body"><p>Al cerrar, las notas y evaluaciones de este curso y bimestre quedarán en <strong>solo lectura</strong>.</p><div class="alert alert-light border small">La reapertura requerirá autorización de Administración.</div><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="gc-confirm-check"><label class="custom-control-label" for="gc-confirm-check">He revisado las notas y deseo cerrar el bimestre.</label></div></div><div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Cancelar</button><button class="btn btn-success" id="gc-confirm-close" disabled><i class="fas fa-lock mr-1"></i>Cerrar bimestre</button></div></div></div></div>');
        $(document).on('change','#gc-confirm-check',function(){$('#gc-confirm-close').prop('disabled',!this.checked);});$(document).on('hidden.bs.modal','#gc-close-modal',function(){$('#gc-confirm-check').prop('checked',false);$('#gc-confirm-close').prop('disabled',true);});
    }
    function ensureReopenModal(){
        if($('#gc-reopen-modal').length)return;
        $('body').append('<div class="modal fade" id="gc-reopen-modal" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-unlock-alt text-warning mr-2"></i>Solicitar reapertura</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body"><label class="small font-weight-bold">Motivo de la reapertura</label><textarea class="form-control" id="gc-reopen-reason" rows="4" maxlength="500" placeholder="Ej. Se detectó una calificación registrada incorrectamente."></textarea><small class="text-muted">Administración deberá aprobar la solicitud antes de que puedas editar nuevamente.</small></div><div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Cancelar</button><button class="btn btn-warning" id="gc-send-reopen"><i class="fas fa-paper-plane mr-1"></i>Enviar solicitud</button></div></div></div></div>');
    }
    $(document).on('click','.gc-verify',function(){$('#gc-detail-area').stop(true,true).slideToggle(150);});
    $(document).on('click','.gc-close',function(){ensureCloseModal();$('#gc-close-modal').modal('show');});
    $(document).on('click','#gc-confirm-close',function(){
        if(!csrf){if(typeof alert_toast==='function')alert_toast('No se encontró el token de seguridad. Recarga la página.','warning');return;}
        var btn=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Cerrando...');$.post(api+'?action=close',{csrf_token:csrf,teacher_course_id:context().assignment,bimestre:context().bimester},null,'json').done(function(r){if(!r||Number(r.status)!==1){if(typeof alert_toast==='function')alert_toast((r&&r.message)||'No se pudo cerrar el bimestre.','danger');if(r&&r.validation)refresh(true);return;}$('#gc-close-modal').modal('hide');if(typeof alert_toast==='function')alert_toast(r.message,'success');reloadBook();}).fail(function(x){if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo cerrar el bimestre.','danger');}).always(function(){btn.prop('disabled',false).html('<i class="fas fa-lock mr-1"></i>Cerrar bimestre');});
    });
    $(document).on('click','.gc-request-reopen',function(){ensureReopenModal();$('#gc-reopen-reason').val('');$('#gc-reopen-modal').modal('show');setTimeout(function(){$('#gc-reopen-reason').focus();},250);});
    $(document).on('click','#gc-send-reopen',function(){
        var reason=$.trim($('#gc-reopen-reason').val());if(reason.length<5){if(typeof alert_toast==='function')alert_toast('Explica el motivo de la reapertura.','warning');return;}if(!csrf){if(typeof alert_toast==='function')alert_toast('No se encontró el token de seguridad. Recarga la página.','warning');return;}
        var btn=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Enviando...');$.post(api+'?action=request_reopen',{csrf_token:csrf,teacher_course_id:context().assignment,bimestre:context().bimester,reason:reason},null,'json').done(function(r){if(!r||Number(r.status)!==1){if(typeof alert_toast==='function')alert_toast((r&&r.message)||'No se pudo enviar la solicitud.','danger');return;}$('#gc-reopen-modal').modal('hide');if(typeof alert_toast==='function')alert_toast(r.message,'success');refresh(true);if(typeof window.refreshUnifiedNotifications==='function')window.refreshUnifiedNotifications();}).fail(function(x){if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo enviar la solicitud.','danger');}).always(function(){btn.prop('disabled',false).html('<i class="fas fa-paper-plane mr-1"></i>Enviar solicitud');});
    });
    $('#gb-year,#gb-course,#gb-level,#gb-grade,#gb-section,#gb-bimester').on('change.gradeClosure',function(){setTimeout(function(){refresh(true);},120);});$('#gb-load').on('click.gradeClosure',function(){setTimeout(function(){refresh(true);},150);});$(document).on('evaluation:saved.gradeClosure evaluation:gradesSaved.gradeClosure',function(){setTimeout(function(){refresh(true);},300);});$(document).on('grade:reopen-reviewed.gradeClosure',function(){refresh(true);reloadBook();});
    window.setInterval(function(){var key=contextKey();if(key!==currentKey){refresh(true);return;}if(validContext()&&Date.now()-lastRefresh>15000&&!loading)refresh(false);},1000);
})(window.jQuery);
