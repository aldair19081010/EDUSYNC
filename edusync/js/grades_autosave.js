(function($){
'use strict';
if(!$ || !$('#gr-list-view').length || !$('#gr-book-view').length) return;

var api='grade_preferences_api.php';
var csrf=window.EDUSYNC_CSRF||'';
var enabled=false;
var ready=false;
var migrationMissing=false;
var syncing=false;
var modalTimer=null;
var modalSaving=false;
var modalBaseline={};
var modalBoundForm=null;
var modalAllowClose=false;
var lastBookSave='';
var bookMutating=false;

function esc(v){return $('<div>').text(v==null?'':v).html();}
function toast(msg,type){if(typeof window.alert_toast==='function')window.alert_toast(msg,type||'success');else alert(msg);}
function clock(){return new Date().toLocaleTimeString('es-PE',{hour:'2-digit',minute:'2-digit'});}
function localEnabled(){return localStorage.getItem('edusync_gradebook_autosave')==='1';}
function saveLocal(v){localStorage.setItem('edusync_gradebook_autosave',v?'1':'0');}

$('<style id="grades-autosave-style">'+
'.gr-mode-row{background:#fff;border:1px solid #e3e6f0;border-radius:.55rem;padding:.45rem .6rem;gap:.65rem}'+
'.gr-save-pref{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap}.gr-save-pref-title{font-size:.78rem;font-weight:800;color:#5a5c69}'+
'.gr-save-pref-state{font-size:.72rem;font-weight:700}.gr-save-pref .btn{border-radius:1rem}'+
'#grades-autosave-settings-modal{z-index:1085}#grades-autosave-settings-modal+.modal-backdrop{z-index:1080}'+
'.gas-settings-box{border:1px solid #e3e6f0;border-radius:.55rem;padding:1rem;background:#f8f9fc}'+
'.meg-evaluation-hero{border:1px solid #e3e6f0!important;border-left:4px solid #4e73df!important;border-radius:.55rem!important;box-shadow:none!important}'+
'.meg-evaluation-hero .card-header{background:#fff;border-bottom:0;padding:.8rem 1rem .25rem}.meg-evaluation-hero .card-body{padding:.55rem 1rem .85rem}'+
'.meg-context{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-bottom:.45rem}.meg-context-course{font-weight:800;color:#2e3a59;margin-right:.2rem}'+
'.meg-context-chip{display:inline-block;padding:.15rem .45rem;border:1px solid #dfe5ef;background:#f8f9fc;border-radius:1rem;font-size:.72rem;color:#5a5c69}'+
'.meg-evaluation-hero .meg-description{font-size:.78rem;color:#858796;margin-bottom:.6rem}.meg-evaluation-hero .form-group{margin-bottom:.25rem}'+
'.meg-evaluation-hero .meg-counter{margin-top:.65rem!important;margin-bottom:0!important;background:#f8f9fc}'+
'.meg-save-statusbar{position:sticky;top:0;z-index:8;background:#fff;border:1px solid #dfe5ef;border-left:4px solid #1cc88a;border-radius:.5rem;padding:.6rem .75rem;margin-bottom:.75rem;box-shadow:0 .15rem .5rem rgba(58,59,69,.06)}'+
'.meg-save-statusbar.manual{border-left-color:#858796}.meg-save-statusbar.error{border-left-color:#e74a3b}.meg-save-statusbar.pending{border-left-color:#f6c23e}.meg-save-statusbar.saving{border-left-color:#4e73df}'+
'.meg-save-mode{font-size:.74rem;font-weight:800}.meg-autosave-state{font-size:.76rem;font-weight:700}.meg-key-help{font-size:.7rem;color:#858796}'+
'.meg-competency-card{box-shadow:none!important;border:1px solid #e3e6f0!important;border-radius:.5rem!important;overflow:hidden}.meg-competency-card .card-header{border-bottom:1px solid #e3e6f0}'+
'.meg-bottom-actions{position:sticky;bottom:0;z-index:7;background:rgba(255,255,255,.96);border-top:1px solid #e3e6f0;padding:.75rem 0;margin-top:1rem!important}'+
'.gb-save-mode{font-size:.72rem;font-weight:700;white-space:nowrap}.gb-retry-save{display:none}'+
'@media(max-width:767.98px){.gr-mode-row{display:flex!important;flex-direction:column;align-items:stretch!important}.gr-mode-row .gr-view-switch{width:100%;display:flex}.gr-mode-row .gr-view-switch .btn{flex:1}.gr-save-pref{justify-content:space-between;width:100%;padding:.15rem .1rem}.meg-save-statusbar{top:0}.meg-save-statusbar>.d-flex{align-items:flex-start!important}.meg-save-statusbar .meg-state-wrap{width:100%;margin-top:.35rem}.meg-table,.meg-table tbody{display:block;width:100%}.meg-table thead{display:none}.meg-table tbody tr{display:grid;grid-template-columns:32px minmax(0,1fr) 92px;grid-template-areas:"num student note" "num dni note";align-items:center;border-bottom:1px solid #e3e6f0;padding:.55rem .45rem;background:#fff}.meg-table tbody td{border:0!important;padding:.12rem .25rem!important;min-width:0!important}.meg-table tbody td:nth-child(1){grid-area:num;text-align:center;color:#858796}.meg-table tbody td:nth-child(2){grid-area:student;font-weight:700}.meg-table tbody td:nth-child(3){grid-area:dni;font-size:.7rem;color:#858796}.meg-table tbody td:nth-child(3):before{content:"DNI: ";}.meg-table tbody td:nth-child(4){grid-area:note}.meg-table tbody td:nth-child(4) .grade-input,.meg-table tbody td:nth-child(4) .form-control{width:76px!important}.meg-bottom-actions .btn{min-width:125px;margin:.2rem}.meg-context-course{width:100%;font-size:.95rem}}'+
'</style>').appendTo('head');

function ensureSettingsModal(){
    if($('#grades-autosave-settings-modal').length)return;
    $('body').append(
      '<div class="modal fade" id="grades-autosave-settings-modal" tabindex="-1" role="dialog">'+
       '<div class="modal-dialog modal-sm modal-dialog-centered" role="document"><div class="modal-content">'+
        '<div class="modal-header"><div><h6 class="modal-title mb-0"><i class="fas fa-save text-primary mr-2"></i>Preferencias de guardado</h6><small class="text-muted">Una sola configuración para Notas.</small></div><button class="close" data-dismiss="modal"><span>&times;</span></button></div>'+
        '<div class="modal-body"><div class="gas-settings-box">'+
         '<div class="d-flex justify-content-between align-items-center"><div><strong>Guardado automático</strong><div class="small text-muted">Guarda las notas después de una breve pausa.</div></div><div class="custom-control custom-switch ml-3"><input type="checkbox" class="custom-control-input" id="gr-autosave-setting-switch"><label class="custom-control-label" for="gr-autosave-setting-switch"></label></div></div>'+
         '<hr><div class="small"><i class="fas fa-check text-success mr-1"></i>Lista de evaluaciones / Registrar notas<br><i class="fas fa-check text-success mr-1"></i>Libro de notas</div>'+
         '<div class="small text-muted mt-2">El botón <strong>Guardar ahora</strong> seguirá disponible como respaldo.</div>'+
        '</div><div id="gr-autosave-migration-note" class="small text-warning mt-2" style="display:none"><i class="fas fa-exclamation-triangle mr-1"></i>La preferencia se está guardando solo en este dispositivo hasta ejecutar la migración.</div></div>'+
        '<div class="modal-footer"><button class="btn btn-light border btn-sm" data-dismiss="modal">Listo</button></div>'+
       '</div></div></div>'
    );
    $('#grades-autosave-settings-modal').on('hidden.bs.modal',function(){if($('#uni_modal').hasClass('show'))$('body').addClass('modal-open');});
}

function ensureMainPreference(){
    if($('#gr-save-pref').length)return;
    ensureSettingsModal();
    var row=$('.gr-view-switch').parent();
    row.removeClass('justify-content-center').addClass('justify-content-between align-items-center gr-mode-row');
    row.append('<div class="gr-save-pref" id="gr-save-pref"><span class="gr-save-pref-title"><i class="fas fa-save mr-1"></i>Guardado automático</span><span class="gr-save-pref-state text-muted" id="gr-save-pref-state">Cargando…</span><button type="button" class="btn btn-light border btn-sm" id="gr-autosave-configure"><i class="fas fa-cog mr-1"></i>Configurar</button></div>');
}

function renderPreference(triggerGradebook){
    syncing=true;
    saveLocal(enabled);
    $('#gr-autosave-setting-switch,#gb-autosave').prop('checked',enabled);
    var state=$('#gr-save-pref-state');
    if(migrationMissing){state.attr('class','gr-save-pref-state text-warning').html('<i class="fas fa-exclamation-triangle mr-1"></i>'+(enabled?'Activado local':'Desactivado local'));}
    else{state.attr('class','gr-save-pref-state '+(enabled?'text-success':'text-muted')).html(enabled?'<i class="fas fa-check-circle mr-1"></i>Activado':'<i class="far fa-circle mr-1"></i>Desactivado');}
    $('#gr-autosave-migration-note').toggle(!!migrationMissing);
    if(triggerGradebook&&$('#gb-autosave').length)$('#gb-autosave').trigger('change');
    updateBookMode();
    refreshModalState();
    syncing=false;
}

function persistPreference(next){
    var previous=enabled;
    enabled=!!next;
    renderPreference(true);
    if(migrationMissing){saveLocal(enabled);toast('La preferencia se guardó solo en este dispositivo. Ejecuta sql/grades_autosave_upgrade.sql para sincronizarla con tu cuenta.','warning');scheduleModal();return;}
    $.ajax({url:api,method:'POST',dataType:'json',data:{csrf_token:csrf,enabled:enabled?1:0}}).done(function(r){
        if(!r||Number(r.status)!==1){enabled=previous;renderPreference(true);toast((r&&r.message)||'No se pudo guardar la preferencia.','danger');return;}
        enabled=!!r.enabled;renderPreference(true);toast(r.message||'Preferencia actualizada.','success');scheduleModal();
    }).fail(function(x){
        var r=x.responseJSON||{};
        if(r.migration_required){migrationMissing=true;enabled=!!next;renderPreference(true);saveLocal(enabled);toast(r.message||'Falta ejecutar la migración de autoguardado.','warning');scheduleModal();return;}
        enabled=previous;renderPreference(true);toast(r.message||'No se pudo guardar la preferencia.','danger');
    });
}

function loadPreference(){
    ensureMainPreference();
    $.getJSON(api).done(function(r){
        if(Number(r.status)===1){enabled=!!r.enabled;migrationMissing=false;ready=true;renderPreference(true);attachEvaluationModal();return;}
        enabled=localEnabled();ready=true;renderPreference(true);attachEvaluationModal();
    }).fail(function(x){
        var r=x.responseJSON||{};migrationMissing=!!r.migration_required;enabled=localEnabled();ready=true;renderPreference(true);attachEvaluationModal();
    });
}

$(document).on('click','#gr-autosave-configure,.meg-change-save-pref',function(){ensureSettingsModal();$('#gr-autosave-setting-switch').prop('checked',enabled);$('#grades-autosave-settings-modal').modal('show');});
$(document).on('change','#gr-autosave-setting-switch',function(){if(syncing||!ready)return;persistPreference(this.checked);});

function setBookNode(cls,html,retryVisible){
    var node=$('#gb-sync-state'),retry=$('#gb-retry-save');if(!node.length)return;
    var changed=node.attr('class')!==cls||node.html()!==html;
    if(changed){bookMutating=true;node.attr('class',cls).html(html);}
    if(retry.length)retry.toggle(!!retryVisible);
}
function updateBookMode(){
    var nativeSwitch=$('#gb-autosave');
    if(nativeSwitch.length)nativeSwitch.closest('.custom-control').addClass('d-none');
    if(!$('#gb-save-mode').length&&$('#gb-sync-state').length)$('<span class="gb-save-mode" id="gb-save-mode"></span>').insertBefore('#gb-sync-state');
    $('#gb-save-mode').attr('class','gb-save-mode '+(enabled?'text-success':'text-muted')).html(enabled?'<i class="fas fa-bolt mr-1"></i>Automático':'<i class="fas fa-hand-paper mr-1"></i>Manual');
    if($('#gb-save').length)$('#gb-save').html('<i class="fas fa-save mr-1"></i>Guardar ahora');
    if(!$('#gb-retry-save').length&&$('#gb-sync-state').length)$('<button type="button" class="btn btn-outline-danger btn-sm gb-retry-save" id="gb-retry-save"><i class="fas fa-redo mr-1"></i>Reintentar</button>').insertAfter('#gb-sync-state');
    normalizeBookState();
}
function normalizeBookState(){
    var node=$('#gb-sync-state');if(!node.length)return;
    var text=$.trim(node.text());
    var count=parseInt(String($('#gb-change-count').text()||'0'),10)||0;
    var readonly=$('#gb-readonly-banner').is(':visible');
    if(readonly){setBookNode('gb-sync text-muted','<i class="fas fa-lock mr-1"></i>Solo lectura',false);return;}
    if(/sin conexión|error al guardar|no se pudieron guardar/i.test(text)){setBookNode('gb-sync text-danger','<i class="fas fa-exclamation-circle mr-1"></i>No se pudieron guardar '+count+' cambio'+(count===1?'':'s'),true);return;}
    if(/guardando/i.test(text)){setBookNode('gb-sync text-primary','<i class="fas fa-circle-notch fa-spin mr-1"></i>Guardando '+count+' cambio'+(count===1?'':'s')+'…',false);return;}
    if(/todo guardado/i.test(text)){setBookNode('gb-sync text-success',node.html(),false);return;}
    if(/^guardado$/i.test(text)){lastBookSave=clock();setBookNode('gb-sync text-success','<i class="fas fa-check-circle mr-1"></i>Todo guardado · '+lastBookSave,false);return;}
    if(count>0){setBookNode('gb-sync text-warning','<i class="fas fa-circle mr-1"></i>'+count+' cambio'+(count===1?'':'s')+' pendiente'+(count===1?'':'s')+(enabled?' · autoguardado':' · guardado manual'),false);return;}
    setBookNode('gb-sync text-success','<i class="fas fa-check-circle mr-1"></i>Todo guardado'+(lastBookSave?' · '+lastBookSave:''),false);
}
$(document).on('click','#gb-retry-save',function(){$('#gb-save').trigger('click');});
$(document).on('evaluation:gradesSaved.gradesAutosaveBook',function(){if($('#gr-book-view').is(':visible')){lastBookSave=clock();setTimeout(normalizeBookState,0);}});
var bookObserver=new MutationObserver(function(){if(bookMutating){bookMutating=false;return;}updateBookMode();});
var bookNode=document.getElementById('gb-sync-state');if(bookNode)bookObserver.observe(bookNode,{childList:true,characterData:true,subtree:true});

function modalForm(){return $('#manage-evaluation-grades');}
function collectModal(){var values={};modalForm().find('.grade-input').each(function(i){values[$(this).attr('name')||('field_'+i)]=String($(this).val()==null?'':$(this).val()).trim().toUpperCase();});values.__system=String($('#grading_system_used').val()||'numeric');return values;}
function modalDirtyCount(){if(!modalBoundForm||!modalBoundForm.length)return 0;var now=collectModal(),keys={};Object.keys(now).forEach(function(k){keys[k]=1;});Object.keys(modalBaseline).forEach(function(k){keys[k]=1;});var count=0;Object.keys(keys).forEach(function(k){if(String(now[k]??'')!==String(modalBaseline[k]??''))count++;});return count;}
function modalValid(){var system=String($('#grading_system_used').val()||'numeric'),valid=true;modalForm().find('.grade-input').each(function(){var v=String($(this).val()==null?'':$(this).val()).trim().toUpperCase();if(v==='')return;if(system==='letters'){if(['C','B','A','AD'].indexOf(v)<0)valid=false;}else{var n=Number(v);if(isNaN(n)||n<0||n>20)valid=false;}});return valid;}
function extractCardValue(card,label){var value='';card.find('.row.small .col-md-6').each(function(){var html=$(this).html()||'';var text=$('<div>').html(html.replace(/<br\s*\/?\s*>/gi,'\n')).text();var re=new RegExp(label+'\\s*:\\s*([^\\n]+)','i');var m=text.match(re);if(m&&!value)value=$.trim(m[1]);});return value;}
function organizeEvaluationModal(){
    var form=modalForm();if(!form.length)return;var container=form.closest('.container-fluid');var infoCard=form.prevAll('.card').first();
    if(infoCard.length&&!infoCard.hasClass('meg-evaluation-hero')){
        var title=$.trim(infoCard.find('.card-header h6').first().text());var course=extractCardValue(infoCard,'Curso'),level=extractCardValue(infoCard,'Nivel'),grade=extractCardValue(infoCard,'Grado'),section=extractCardValue(infoCard,'Sección'),bim=extractCardValue(infoCard,'Bimestre'),year=extractCardValue(infoCard,'Año Académico');
        var desc=$.trim(infoCard.find('.card-body>p').first().text());var grading=infoCard.find('.form-group').first().detach();var counter=container.children('.meg-counter').first().detach();
        var chips='<div class="meg-context"><span class="meg-context-course">'+esc(course||'Evaluación')+'</span>'+(level?'<span class="meg-context-chip">'+esc(level)+'</span>':'')+(grade?'<span class="meg-context-chip">'+esc(grade)+(section?' · '+esc(section):'')+'</span>':'')+(bim?'<span class="meg-context-chip">'+esc(bim)+'</span>':'')+(year?'<span class="meg-context-chip">'+esc(year)+'</span>':'')+'</div>';
        infoCard.addClass('meg-evaluation-hero').removeClass('shadow');infoCard.find('.card-header').html('<div class="small text-muted text-uppercase font-weight-bold">Registrar notas</div><h6 class="m-0 font-weight-bold text-primary">'+esc(title||'Evaluación')+'</h6>');
        var body=infoCard.find('.card-body').empty().append(chips);if(desc&&desc!=='Sin descripción disponible')body.append('<div class="meg-description">'+esc(desc)+'</div>');if(grading.length)body.append(grading);if(counter.length)body.append(counter);
        var yearAlert=form.prev('.alert-info');if(yearAlert.length&&/pertenece al año académico/i.test(yearAlert.text()))yearAlert.hide();
    }
    form.children('.card.shadow').removeClass('shadow').addClass('meg-competency-card');
    var actions=form.find('.row.mt-4').last();if(actions.length){actions.addClass('meg-bottom-actions');actions.find('button[type="submit"]').attr('id','meg-save-now').html('<i class="fa fa-save mr-1"></i>Guardar ahora');actions.find('button.btn-secondary').html('<i class="fa fa-times mr-1"></i>Cerrar');}
    try{var p=window.parent&&window.parent.$?window.parent.$:$;p('#uni_modal #submit').hide();}catch(_){ }
}
function ensureModalStatus(){
    if($('#meg-save-statusbar').length)return;var form=modalForm(),hero=form.prevAll('.meg-evaluation-hero').first();if(!hero.length)return;
    hero.after('<div class="meg-save-statusbar" id="meg-save-statusbar"><div class="d-flex justify-content-between align-items-center flex-wrap"><div><div class="meg-save-mode" id="meg-save-mode"></div><div class="meg-key-help"><i class="fas fa-keyboard mr-1"></i>Enter: siguiente estudiante · También puedes pegar una lista desde Excel.</div></div><div class="meg-state-wrap text-md-right"><div id="meg-autosave-state" class="meg-autosave-state text-muted"><i class="fas fa-info-circle mr-1"></i>Sin cambios pendientes</div><button type="button" class="btn btn-link btn-sm p-0 mt-1 meg-change-save-pref"><i class="fas fa-cog mr-1"></i>Cambiar preferencia</button> <button type="button" class="btn btn-outline-danger btn-sm ml-2" id="meg-retry-save" style="display:none"><i class="fas fa-redo mr-1"></i>Reintentar</button></div></div></div>');
}
function setModalState(kind,text){
    var node=$('#meg-autosave-state'),bar=$('#meg-save-statusbar');if(!node.length)return;bar.removeClass('manual error pending saving');if(!enabled)bar.addClass('manual');if(kind==='error')bar.addClass('error');else if(kind==='pending')bar.addClass('pending');else if(kind==='saving')bar.addClass('saving');
    var cls='meg-autosave-state ',icon='fa-info-circle';if(kind==='ok'){cls+='text-success';icon='fa-check-circle';}else if(kind==='error'){cls+='text-danger';icon='fa-exclamation-circle';}else if(kind==='saving'){cls+='text-primary';icon='fa-circle-notch fa-spin';}else if(kind==='pending'){cls+='text-warning';icon='fa-circle';}else cls+='text-muted';node.attr('class',cls).html('<i class="fas '+icon+' mr-1"></i>'+esc(text));$('#meg-retry-save').toggle(kind==='error');
}
function refreshModalState(){if(!modalBoundForm||!modalBoundForm.length)return;$('#meg-save-mode').attr('class','meg-save-mode '+(enabled?'text-success':'text-muted')).html(enabled?'<i class="fas fa-bolt mr-1"></i>Guardado automático activo':'<i class="fas fa-hand-paper mr-1"></i>Guardado manual');var count=modalDirtyCount();if(!count){setModalState('ok','Todo guardado');return;}if(!modalValid()){setModalState('error','Corrige las notas inválidas antes de guardar');return;}if(enabled)setModalState('pending',count+' cambio'+(count===1?'':'s')+' pendiente'+(count===1?'':'s')+' · se guardará automáticamente');else setModalState('pending',count+' cambio'+(count===1?'':'s')+' sin guardar · usa “Guardar ahora”');}
function scheduleModal(){clearTimeout(modalTimer);if(!modalBoundForm||!modalBoundForm.length)return;refreshModalState();if(!enabled||modalSaving||!modalDirtyCount()||!modalValid()||!modalForm().find('.grade-input:not(:disabled)').length)return;modalTimer=setTimeout(function(){saveModal(true,false);},1100);}
function setModalButtons(disabled){var editable=modalForm().find('.grade-input:not(:disabled)').length>0;modalForm().find('#meg-save-now,button[type="submit"]').prop('disabled',disabled||!editable);}
function markModalSaved(){modalBaseline=collectModal();modalForm().find('.grade-input').each(function(){$(this).attr('data-original-value',this.value).data('original-value',this.value);});}
function saveModal(automatic,closeAfter){
    var form=modalForm();if(!form.length||modalSaving||!modalDirtyCount())return $.Deferred().resolve(false).promise();if(!modalValid()){refreshModalState();return $.Deferred().resolve(false).promise();}if(!form.find('.grade-input:not(:disabled)').length){setModalState('error','Las notas están en modo de solo lectura');return $.Deferred().resolve(false).promise();}
    clearTimeout(modalTimer);modalSaving=true;setModalButtons(true);setModalState('saving',automatic?'Guardando automáticamente…':'Guardando cambios…');
    return $.ajax({url:'ajax.php?action=save_evaluation_grades',method:'POST',data:form.serialize(),dataType:'json'}).done(function(r){if(!r||Number(r.status)!==1){setModalState('error',(r&&r.message)||'No se pudieron guardar los cambios');return;}markModalSaved();setModalState('ok','Todo guardado · '+clock());$(document).trigger('evaluation:gradesSaved',[r,{evaluation_id:form.find('input[name="evaluation_id"]').val(),source:automatic?'autosave':'manual'}]);if(!automatic)toast('Calificaciones guardadas.','success');if(closeAfter){modalAllowClose=true;setTimeout(function(){$('#uni_modal').modal('hide');},80);}}).fail(function(x){var r=x.responseJSON||{};setModalState('error',(r.message||'Error de conexión')+' · cambios pendientes');}).always(function(){modalSaving=false;setModalButtons(false);if(modalDirtyCount()&&enabled&&!closeAfter)scheduleModal();});
}
function attachEvaluationModal(){
    var form=modalForm();if(!form.length||form.attr('data-grades-autosave-bound')==='1')return;form.attr('data-grades-autosave-bound','1');modalBoundForm=form;clearTimeout(modalTimer);modalSaving=false;modalAllowClose=false;organizeEvaluationModal();ensureModalStatus();modalBaseline=collectModal();refreshModalState();form.off('submit').on('submit.gradesAutosave',function(e){e.preventDefault();e.stopImmediatePropagation();saveModal(false,false);return false;});
}

$(document).on('input.gradesAutosave change.gradesAutosave','#manage-evaluation-grades .grade-input',scheduleModal);
$(document).on('change.gradesAutosave','#manage-evaluation-grades #grading_system',function(){setTimeout(scheduleModal,0);});
$(document).on('click','#meg-retry-save',function(){saveModal(enabled,false);});
$(document).on('evaluation:gradesSaved.gradesAutosave',function(e,r,meta){if(modalForm().length&&meta&&meta.source!=='autosave'&&meta.source!=='manual'){markModalSaved();refreshModalState();}});

$('#uni_modal').on('hide.bs.modal.gradesAutosave',function(e){if(!modalForm().length)return;if(modalAllowClose){modalAllowClose=false;return;}var count=modalDirtyCount();if(!count)return;if(modalSaving){e.preventDefault();setModalState('saving','Espera a que termine el guardado…');return;}if(enabled){if(!modalValid()){e.preventDefault();setModalState('error','Hay notas inválidas; corrígelas antes de cerrar');return;}e.preventDefault();saveModal(true,true);return;}if(!window.confirm('Hay '+count+' cambio'+(count===1?'':'s')+' sin guardar. ¿Cerrar y descartarlos?'))e.preventDefault();});
$('#uni_modal').on('hidden.bs.modal.gradesAutosave',function(){clearTimeout(modalTimer);modalBoundForm=null;modalBaseline={};modalSaving=false;modalAllowClose=false;});

var observer=new MutationObserver(function(){setTimeout(attachEvaluationModal,0);});observer.observe(document.body,{childList:true,subtree:true});

window.EdusyncGradesAutosave={isEnabled:function(){return enabled;},isReady:function(){return ready;},set:function(v){if(ready)persistPreference(!!v);},refresh:function(){loadPreference();}};
loadPreference();
})(window.jQuery);
