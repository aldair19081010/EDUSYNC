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

function esc(v){return $('<div>').text(v==null?'':v).html();}
function toast(msg,type){if(typeof window.alert_toast==='function')window.alert_toast(msg,type||'success');else alert(msg);}
function clock(){return new Date().toLocaleTimeString('es-PE',{hour:'2-digit',minute:'2-digit'});}
function localEnabled(){return localStorage.getItem('edusync_gradebook_autosave')==='1';}
function saveLocal(v){localStorage.setItem('edusync_gradebook_autosave',v?'1':'0');}

$('<style id="grades-autosave-style">'+
'.gr-autosave-master{display:inline-flex;align-items:center;gap:.45rem;padding:.28rem .55rem;border:1px solid #dfe5ef;border-radius:.5rem;background:#f8f9fc;vertical-align:middle;margin-right:.35rem}'+
'.gr-autosave-master .custom-control{min-height:1.2rem}.gr-autosave-master-state{font-size:.7rem;white-space:nowrap}'+
'.meg-autosave-bar{border:1px solid #dfe5ef;border-left:4px solid #4e73df;background:#f8f9fc;border-radius:.45rem;padding:.65rem .8rem}'+
'.meg-autosave-state{font-size:.75rem}.meg-autosave-state.text-danger{font-weight:700}'+
'@media(max-width:767.98px){.gr-autosave-master{display:flex;margin:.35rem 0;width:100%;justify-content:space-between}.gr-header>div:last-child{display:flex;flex-wrap:wrap;align-items:center}.gr-header>div:last-child .btn{margin-top:.2rem;margin-bottom:.2rem}}'+
'</style>').appendTo('head');

function ensureMaster(){
    if($('#gr-autosave-control').length)return;
    var html='<div class="gr-autosave-master" id="gr-autosave-control" title="Esta preferencia se aplica a Lista de evaluaciones y Libro de notas">'+
      '<div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="gr-autosave-master"><label class="custom-control-label small font-weight-bold" for="gr-autosave-master">Autoguardado</label></div>'+
      '<span class="gr-autosave-master-state text-muted" id="gr-autosave-master-state">Cargando…</span></div>';
    if($('#gr-general-close').length)$(html).insertBefore('#gr-general-close');else $(html).insertBefore('#gr-new');
}

function renderPreference(triggerGradebook){
    syncing=true;
    $('#gr-autosave-master,#gb-autosave,#meg-autosave').prop('checked',enabled);
    saveLocal(enabled);
    var master=$('#gr-autosave-master-state');
    if(migrationMissing){master.attr('class','gr-autosave-master-state text-warning').html('<i class="fas fa-exclamation-triangle mr-1"></i>'+(enabled?'Activado local':'Desactivado local'));}
    else{master.attr('class','gr-autosave-master-state '+(enabled?'text-success':'text-muted')).html(enabled?'<i class="fas fa-check-circle mr-1"></i>Activado':'<i class="far fa-circle mr-1"></i>Desactivado');}
    if(!$('#gb-autosave-note').length&&$('#gb-autosave').length){$('<span id="gb-autosave-note" class="small ml-1"></span>').insertAfter($('#gb-autosave').closest('.custom-control'));}
    $('#gb-autosave-note').attr('class','small ml-1 '+(enabled?'text-success':'text-muted')).text(enabled?'Activado':'Desactivado');
    $('#meg-autosave-state-pref').attr('class','small '+(enabled?'text-success':'text-muted')).text(enabled?'Activado para ambos modos':'Desactivado para ambos modos');
    if(triggerGradebook&&$('#gb-autosave').length){$('#gb-autosave').trigger('change');}
    syncing=false;
}

function persistPreference(next,source){
    var previous=enabled;
    enabled=!!next;
    renderPreference(source!=='gradebook');
    if(migrationMissing){saveLocal(enabled);toast('La preferencia se guardó solo en este dispositivo. Ejecuta sql/grades_autosave_upgrade.sql para sincronizarla con tu cuenta.','warning');scheduleModal();return;}
    $.ajax({url:api,method:'POST',dataType:'json',data:{csrf_token:csrf,enabled:enabled?1:0}}).done(function(r){
        if(!r||Number(r.status)!==1){enabled=previous;renderPreference(true);toast((r&&r.message)||'No se pudo guardar la preferencia.','danger');return;}
        enabled=!!r.enabled;renderPreference(source!=='gradebook');toast(r.message||'Preferencia actualizada.','success');scheduleModal();
    }).fail(function(x){
        var r=x.responseJSON||{};
        if(r.migration_required){migrationMissing=true;enabled=!!next;renderPreference(source!=='gradebook');saveLocal(enabled);toast(r.message||'Falta ejecutar la migración de autoguardado.','warning');scheduleModal();return;}
        enabled=previous;renderPreference(true);toast(r.message||'No se pudo guardar la preferencia.','danger');
    });
}

function loadPreference(){
    ensureMaster();
    $.getJSON(api).done(function(r){
        if(Number(r.status)===1){enabled=!!r.enabled;migrationMissing=false;ready=true;renderPreference(true);attachEvaluationModal();return;}
        enabled=localEnabled();ready=true;renderPreference(true);
    }).fail(function(x){
        var r=x.responseJSON||{};
        migrationMissing=!!r.migration_required;
        enabled=localEnabled();ready=true;renderPreference(true);attachEvaluationModal();
        if(migrationMissing)$('#gr-autosave-master-state').attr('title',r.message||'Falta la migración de autoguardado.');
    });
}

$(document).on('change.gradesAutosave','#gr-autosave-master,#gb-autosave,#meg-autosave',function(){
    if(syncing||!ready)return;
    var source=this.id==='gb-autosave'?'gradebook':(this.id==='meg-autosave'?'evaluation':'master');
    persistPreference(this.checked,source);
});

function modalForm(){return $('#manage-evaluation-grades');}
function collectModal(){
    var values={};
    modalForm().find('.grade-input').each(function(i){values[$(this).attr('name')||('field_'+i)]=String($(this).val()==null?'':$(this).val()).trim().toUpperCase();});
    values.__system=String($('#grading_system_used').val()||'numeric');
    return values;
}
function modalDirtyCount(){
    if(!modalBoundForm||!modalBoundForm.length)return 0;
    var now=collectModal(),keys={};Object.keys(now).forEach(function(k){keys[k]=1;});Object.keys(modalBaseline).forEach(function(k){keys[k]=1;});
    var count=0;Object.keys(keys).forEach(function(k){if(String(now[k]??'')!==String(modalBaseline[k]??''))count++;});return count;
}
function modalValid(){
    var system=String($('#grading_system_used').val()||'numeric'),valid=true;
    modalForm().find('.grade-input').each(function(){var v=String($(this).val()==null?'':$(this).val()).trim().toUpperCase();if(v==='')return;if(system==='letters'){if(['C','B','A','AD'].indexOf(v)<0)valid=false;}else{var n=Number(v);if(isNaN(n)||n<0||n>20)valid=false;}});
    return valid;
}
function setModalState(kind,text){
    var node=$('#meg-autosave-state');if(!node.length)return;
    var cls='meg-autosave-state ';
    if(kind==='ok')cls+='text-success';else if(kind==='error')cls+='text-danger';else if(kind==='saving')cls+='text-primary';else if(kind==='pending')cls+='text-warning';else cls+='text-muted';
    var icon=kind==='ok'?'fa-check-circle':kind==='error'?'fa-exclamation-circle':kind==='saving'?'fa-circle-notch fa-spin':kind==='pending'?'fa-circle':'fa-info-circle';
    node.attr('class',cls).html('<i class="fas '+icon+' mr-1"></i>'+esc(text));
}
function refreshModalState(){
    var count=modalDirtyCount();
    if(!count){setModalState('idle',enabled?'Sin cambios pendientes · autoguardado activo':'Sin cambios pendientes · guardado manual');return;}
    if(!modalValid()){setModalState('error','Corrige las notas inválidas antes de guardar');return;}
    if(enabled)setModalState('pending',count+' cambio'+(count===1?'':'s')+' pendiente'+(count===1?'':'s')+' · se guardará automáticamente');
    else setModalState('pending',count+' cambio'+(count===1?'':'s')+' sin guardar · usa “Guardar Calificaciones”');
}
function scheduleModal(){
    clearTimeout(modalTimer);
    if(!modalBoundForm||!modalBoundForm.length)return;
    refreshModalState();
    if(!enabled||modalSaving||!modalDirtyCount()||!modalValid()||!modalForm().find('.grade-input:not(:disabled)').length)return;
    modalTimer=setTimeout(function(){saveModalAutomatic(false);},1100);
}
function setModalButtons(disabled){
    var editable=modalForm().find('.grade-input:not(:disabled)').length>0;
    modalForm().find('button[type="submit"]').prop('disabled',disabled||!editable);
}
function saveModalAutomatic(closeAfter){
    var form=modalForm();
    if(!form.length||modalSaving||!modalDirtyCount())return $.Deferred().resolve(false).promise();
    if(!modalValid()){refreshModalState();return $.Deferred().resolve(false).promise();}
    if(!form.find('.grade-input:not(:disabled)').length){setModalState('error','Las notas están en modo de solo lectura');return $.Deferred().resolve(false).promise();}
    clearTimeout(modalTimer);modalSaving=true;setModalButtons(true);setModalState('saving','Guardando automáticamente…');
    return $.ajax({url:'ajax.php?action=save_evaluation_grades',method:'POST',data:form.serialize(),dataType:'json'}).done(function(r){
        if(!r||Number(r.status)!==1){setModalState('error',(r&&r.message)||'No se pudieron guardar los cambios');return;}
        modalBaseline=collectModal();
        setModalState('ok','Guardado automáticamente · '+clock());
        $(document).trigger('evaluation:gradesSaved',[r,{evaluation_id:form.find('input[name="evaluation_id"]').val(),source:'autosave'}]);
        if(closeAfter)setTimeout(function(){$('#uni_modal').modal('hide');},80);
    }).fail(function(x){var r=x.responseJSON||{};setModalState('error',(r.message||'Error de conexión')+' · cambios pendientes');}).always(function(){modalSaving=false;setModalButtons(false);if(modalDirtyCount()&&enabled&&!closeAfter)scheduleModal();});
}
function ensureModalBar(){
    if($('#meg-autosave-bar').length)return;
    var bar='<div class="meg-autosave-bar mb-3" id="meg-autosave-bar"><div class="d-flex justify-content-between align-items-center flex-wrap">'+
      '<div><div class="custom-control custom-switch d-inline-block mr-2"><input type="checkbox" class="custom-control-input" id="meg-autosave"><label class="custom-control-label font-weight-bold" for="meg-autosave">Autoguardado de notas</label></div><span id="meg-autosave-state-pref" class="small text-muted"></span><div class="small text-muted mt-1">La misma elección se usa también en Libro de notas.</div></div>'+
      '<div class="mt-2 mt-md-0"><span id="meg-autosave-state" class="meg-autosave-state text-muted"><i class="fas fa-info-circle mr-1"></i>Sin cambios pendientes</span></div></div></div>';
    $(bar).insertAfter($('.meg-counter').first());
}
function attachEvaluationModal(){
    var form=modalForm();
    if(!form.length||form.attr('data-grades-autosave-bound')==='1')return;
    form.attr('data-grades-autosave-bound','1');modalBoundForm=form;clearTimeout(modalTimer);modalSaving=false;
    ensureModalBar();modalBaseline=collectModal();renderPreference(false);refreshModalState();
}

$(document).on('input.gradesAutosave change.gradesAutosave','#manage-evaluation-grades .grade-input',scheduleModal);
$(document).on('change.gradesAutosave','#manage-evaluation-grades #grading_system',function(){setTimeout(scheduleModal,0);});
$(document).on('evaluation:gradesSaved.gradesAutosave',function(){if(modalForm().length){modalBaseline=collectModal();refreshModalState();}});

$('#uni_modal').on('hide.bs.modal.gradesAutosave',function(e){
    if(!modalForm().length)return;
    var count=modalDirtyCount();if(!count)return;
    if(modalSaving){e.preventDefault();setModalState('saving','Espera a que termine el guardado…');return;}
    if(enabled){
        if(!modalValid()){e.preventDefault();setModalState('error','Hay notas inválidas; corrígelas antes de cerrar');return;}
        e.preventDefault();saveModalAutomatic(true);return;
    }
    if(!window.confirm('Hay '+count+' cambio'+(count===1?'':'s')+' sin guardar. ¿Cerrar y descartarlos?'))e.preventDefault();
});
$('#uni_modal').on('hidden.bs.modal.gradesAutosave',function(){clearTimeout(modalTimer);modalBoundForm=null;modalBaseline={};modalSaving=false;});

var observer=new MutationObserver(function(){attachEvaluationModal();});
observer.observe(document.body,{childList:true,subtree:true});

window.EdusyncGradesAutosave={
    isEnabled:function(){return enabled;},
    isReady:function(){return ready;},
    set:function(v){if(ready)persistPreference(!!v,'external');},
    refresh:function(){loadPreference();}
};

loadPreference();
})(window.jQuery);
