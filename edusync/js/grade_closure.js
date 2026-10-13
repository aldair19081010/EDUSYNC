(function($){
    'use strict';
    if (!$ || !$('#gr-list-view').length || !$('#gr-book-view').length) return;

    var api='grade_closure_api.php';
    var contextsApi='gradebook_api.php';
    var csrf=window.EDUSYNC_CSRF||'';
    var actionContext=null;
    var actionMode='book';
    var modeState={book:{status:null,ctx:null,label:''},list:{status:null,ctx:null,label:''}};
    var statusSeq={book:0,list:0};
    var listCache={year:'',rows:[],seq:0};

    var style='<style id="gc-style">'+
        '.gc-period-btn{border-radius:1rem;font-weight:700;padding:.28rem .7rem;white-space:nowrap}.gc-period-btn i{font-size:.75rem}'+
        '.gc-menu-note{font-size:.72rem;color:#858796;max-width:250px;white-space:normal;line-height:1.3}'+
        '.gc-detail-row{display:flex;align-items:flex-start;padding:.45rem 0;border-bottom:1px solid #f0f1f5}.gc-detail-row:last-child{border-bottom:0}.gc-detail-row i{width:22px;margin-top:.15rem}'+
        '.gc-detail-box{background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.4rem;padding:.65rem .8rem}'+
        '@media(max-width:575px){.gb-period-action .gc-period-btn{width:100%}}'+
        '</style>';
    if(!$('#gc-style').length)$('head').append(style);

    $('#gr-general-close,#gc-general-modal,#gc-book-panel').remove();

    function esc(v){return $('<div>').text(v==null?'':v).html();}
    function normalize(v){return String(v==null?'':v).replace(/[°º]+/g,'').trim().toLowerCase();}
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
    function validContext(ctx){return ctx&&Number(ctx.assignment)>0&&/^[1-4]$/.test(String(ctx.bimester||''));}
    function target(mode){return mode==='list'?$('#gr-period-action'):$('#gb-period-action');}
    function pendingCount(data){
        var s=(data&&data.validation&&data.validation.summary)||{};
        return Math.max(0,Number(s.expected_grade_cells||0)-Number(s.completed_grade_cells||0));
    }
    function stateData(data){
        if(data&&data.closed)return {label:'Cerrado',icon:'fa-lock',btn:'btn-outline-info'};
        if(data&&data.ready)return {label:'Listo para cerrar',icon:'fa-check-circle',btn:'btn-outline-success'};
        return {label:'En edición',icon:'fa-pencil-alt',btn:'btn-outline-warning'};
    }
    function bookSelection(){
        var course=$('#gb-course').val(),level=$('#gb-level').val(),grade=$('#gb-grade').val(),section=$('#gb-section').val(),bim=$('#gb-bimester').val();
        var p=[];
        if(course)p.push(course);
        if(level)p.push(level);
        if(grade)p.push(gradeLabel(grade)+(section?' '+section:''));
        if(bim)p.push(bim+'° Bimestre');
        return {
            ctx:{assignment:String($('#gb-assignment').val()||''),bimester:String(bim||'')},
            label:p.join(' · ')
        };
    }
    function listSelection(){
        return {
            year:String($('#gr-year').val()||''),
            course:String($('#gr-course').val()||''),
            level:String($('#gr-level').val()||''),
            grade:String($('#gr-grade').val()||''),
            section:String($('#gr-section').val()||''),
            bimester:String($('#gr-bimester').val()||'')
        };
    }
    function listLabel(sel){
        var p=[];
        if(sel.course)p.push(sel.course);
        if(sel.level)p.push(sel.level);
        if(sel.grade)p.push(gradeLabel(sel.grade)+(sel.section?' '+sel.section:''));
        if(sel.bimester)p.push(sel.bimester+'° Bimestre');
        return p.join(' · ');
    }
    function resolveListContext(done){
        var sel=listSelection();
        var label=listLabel(sel);
        if(!sel.year||!sel.course||!sel.level||!sel.grade||!sel.section||!/^[1-4]$/.test(sel.bimester)){
            done(null,label);return;
        }
        function resolve(rows){
            var matches=(rows||[]).filter(function(a){
                return normalize(a.course_name)===normalize(sel.course)&&
                    normalize(a.level)===normalize(sel.level)&&
                    normalize(a.grado)===normalize(sel.grade)&&
                    normalize(a.seccion||'U')===normalize(sel.section||'U');
            });
            done(matches.length===1?{assignment:String(matches[0].id),bimester:sel.bimester}:null,label);
        }
        if(listCache.year===sel.year){
            resolve(listCache.rows);
            return;
        }
        var seq=++listCache.seq;
        $.getJSON(contextsApi,{action:'contexts',academic_year_id:sel.year})
            .done(function(r){
                if(seq!==listCache.seq)return;
                listCache.year=sel.year;
                listCache.rows=r&&Number(r.status)===1?(r.assignments||[]):[];
                resolve(listCache.rows);
            })
            .fail(function(){
                if(seq!==listCache.seq)return;
                listCache.year=sel.year;
                listCache.rows=[];
                done(null,label);
            });
    }
    function withContext(mode,done){
        if(mode==='book'){
            var b=bookSelection();
            done(validContext(b.ctx)?b.ctx:null,b.label);
        }else{
            resolveListContext(done);
        }
    }

    function renderPeriodAction(mode,data,ctx,label){
        var t=target(mode);
        if(!t.length||!data){if(t.length)t.empty();return;}
        modeState[mode]={status:data,ctx:ctx,label:label};
        var st=stateData(data),pending=pendingCount(data),menu='';
        if(data.closed){
            menu+='<button class="dropdown-item gc-detail" data-gc-mode="'+mode+'"><i class="fas fa-info-circle text-primary mr-2"></i>Ver detalle del cierre</button>';
            menu+='<div class="dropdown-divider"></div>';
            if(data.pending_reopen){
                menu+='<span class="dropdown-item-text small text-warning"><i class="fas fa-hourglass-half mr-2"></i>Reapertura pendiente</span>';
            }else{
                menu+='<button class="dropdown-item gc-request-reopen" data-gc-mode="'+mode+'"><i class="fas fa-unlock-alt text-warning mr-2"></i>Solicitar reapertura</button>';
            }
        }else if(data.ready){
            menu+='<button class="dropdown-item gc-close" data-gc-mode="'+mode+'"><i class="fas fa-lock text-success mr-2"></i>Revisar y cerrar</button>';
            menu+='<button class="dropdown-item gc-detail" data-gc-mode="'+mode+'"><i class="fas fa-clipboard-check text-primary mr-2"></i>Ver verificación</button>';
        }else{
            if(mode==='book'&&pending>0){
                menu+='<button class="dropdown-item gc-show-pending"><i class="fas fa-user-clock text-warning mr-2"></i>Ver pendientes ('+pending+')</button>';
            }
            menu+='<button class="dropdown-item gc-detail" data-gc-mode="'+mode+'"><i class="fas fa-clipboard-check text-primary mr-2"></i>Ver qué falta</button>';
        }
        t.html(
            '<div class="dropdown">'+
                '<button class="btn btn-sm '+st.btn+' gc-period-btn dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'+
                    '<i class="fas '+st.icon+' mr-1"></i>'+st.label+
                '</button>'+
                '<div class="dropdown-menu dropdown-menu-right shadow">'+
                    '<h6 class="dropdown-header">'+esc((mode==='book'?$('#gb-bimester option:selected').text():$('#gr-bimester option:selected').text())||'Bimestre')+'</h6>'+
                    '<div class="px-3 pb-2 gc-menu-note">'+esc(label)+'</div>'+
                    menu+
                '</div>'+
            '</div>'
        );
    }
    function refreshMode(mode){
        var seq=++statusSeq[mode];
        var t=target(mode);
        modeState[mode]={status:null,ctx:null,label:''};
        withContext(mode,function(ctx,label){
            if(seq!==statusSeq[mode])return;
            if(!ctx||!validContext(ctx)){t.empty();return;}
            t.html('<button class="btn btn-light border btn-sm gc-period-btn" disabled><i class="fas fa-spinner fa-spin mr-1"></i>Verificando</button>');
            $.getJSON(api,{action:'status',teacher_course_id:ctx.assignment,bimestre:ctx.bimester})
                .done(function(r){
                    if(seq!==statusSeq[mode])return;
                    if(!r||Number(r.status)!==1){
                        t.html('<button class="btn btn-outline-danger btn-sm gc-period-btn gc-detail" data-gc-mode="'+mode+'"><i class="fas fa-exclamation-circle mr-1"></i>Revisar cierre</button>');
                        modeState[mode]={status:r||null,ctx:ctx,label:label};
                        return;
                    }
                    renderPeriodAction(mode,r,ctx,label);
                })
                .fail(function(){
                    if(seq!==statusSeq[mode])return;
                    t.html('<button class="btn btn-outline-danger btn-sm gc-period-btn gc-detail" data-gc-mode="'+mode+'"><i class="fas fa-exclamation-circle mr-1"></i>Revisar cierre</button>');
                    modeState[mode]={status:null,ctx:ctx,label:label};
                });
        });
    }
    function refreshAll(){
        refreshMode('list');
        refreshMode('book');
    }

    function detailRows(data){
        var validation=(data&&data.validation)||{},issues=validation.issues||[],s=validation.summary||{},pending=pendingCount(data);
        var pct=Number(s.percentage_total||0);
        var html='<div class="gc-detail-box">';
        html+='<div class="gc-detail-row '+(Math.abs(pct-100)<0.01?'text-success':'text-danger')+'"><i class="fas '+(Math.abs(pct-100)<0.01?'fa-check-circle':'fa-times-circle')+'"></i><div><strong>Porcentajes de competencias: '+pct.toFixed(2)+'%</strong></div></div>';
        html+='<div class="gc-detail-row '+(Number(s.evaluations||0)>0?'text-success':'text-danger')+'"><i class="fas '+(Number(s.evaluations||0)>0?'fa-check-circle':'fa-times-circle')+'"></i><div><strong>'+Number(s.evaluations||0)+' evaluaciones</strong></div></div>';
        html+='<div class="gc-detail-row '+(pending===0?'text-success':'text-danger')+'"><i class="fas '+(pending===0?'fa-check-circle':'fa-times-circle')+'"></i><div><strong>'+pending+' calificaciones pendientes</strong><br><small>'+Number(s.completed_grade_cells||0)+' de '+Number(s.expected_grade_cells||0)+' registradas</small></div></div>';
        if(issues.length){
            html+='<div class="pt-2">';
            issues.forEach(function(issue){html+='<div class="small text-danger mb-1"><i class="fas fa-angle-right mr-1"></i>'+esc(issue.message||'Hay información pendiente.')+'</div>';});
            html+='</div>';
        }
        if(data&&data.closed&&data.closure){
            html+='<div class="small text-muted mt-2"><i class="fas fa-lock mr-1"></i>Cerrado por <strong>'+esc(data.closure.closed_by_name||'Docente')+'</strong> · '+fmtDate(data.closure.closed_at)+' · Cierre #'+Number(data.closure.closure_version||1)+'</div>';
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
                '<div class="modal-body"><div id="gc-close-checks" class="mb-3"></div><div class="alert alert-light border small">Al cerrar, las evaluaciones y calificaciones de este curso quedarán en solo lectura en ambas vistas. La reapertura requerirá autorización de Administración.</div><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="gc-confirm-check"><label class="custom-control-label" for="gc-confirm-check">He revisado las notas y deseo cerrar este bimestre.</label></div></div>'+
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
    function stateForMode(mode){return modeState[mode]||{status:null,ctx:null,label:''};}
    function afterClosureChange(){
        if($('#gb-assignment').val()&&$('#gb-bimester').val())$('#gb-bimester').trigger('change');
        setTimeout(refreshAll,250);
        $(document).trigger('grade:closure-changed');
        $(document).trigger('evaluation:gradesSaved',[{status:1,source:'grade_closure'}]);
        if(typeof window.refreshUnifiedNotifications==='function')window.refreshUnifiedNotifications();
    }

    $(document).on('click','.gc-show-pending',function(){
        $('#gb-student-filter').val('pending').trigger('change');
        var t=$('#gb-container');if(t.length)$('html,body').animate({scrollTop:Math.max(0,t.offset().top-120)},180);
    });
    $(document).on('click','.gc-detail',function(){
        var mode=String($(this).data('gc-mode')||'book'),st=stateForMode(mode);
        if(!st.status){
            if(typeof alert_toast==='function')alert_toast('No se pudo obtener el estado del bimestre.','warning');
            return;
        }
        ensureDetailModal();
        $('#gc-detail-context').text(st.label);
        $('#gc-detail-body').html(detailRows(st.status));
        $('#gc-detail-modal').modal('show');
    });
    $(document).on('click','.gc-close',function(){
        var mode=String($(this).data('gc-mode')||'book'),st=stateForMode(mode);
        if(!validContext(st.ctx)||!st.status||!st.status.ready||st.status.closed)return;
        if($('.gb-cell.gb-dirty').length){
            if(typeof alert_toast==='function')alert_toast('Guarda o descarta las notas modificadas antes de cerrar.','warning');
            return;
        }
        actionContext=st.ctx;actionMode=mode;
        ensureCloseModal();
        $('#gc-close-context').text(st.label);
        $('#gc-close-checks').html(detailRows(st.status));
        $('#gc-close-modal').modal('show');
    });
    $(document).on('click','#gc-confirm-close',function(){
        if(!actionContext||!validContext(actionContext))return;
        if(!csrf){if(typeof alert_toast==='function')alert_toast('No se encontró el token de seguridad. Recarga la página.','warning');return;}
        var btn=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Cerrando...');
        $.post(api+'?action=close',{csrf_token:csrf,teacher_course_id:actionContext.assignment,bimestre:actionContext.bimester},null,'json')
            .done(function(r){
                if(!r||Number(r.status)!==1){
                    if(typeof alert_toast==='function')alert_toast((r&&r.message)||'No se pudo cerrar el bimestre.','danger');
                    refreshMode(actionMode);return;
                }
                $('#gc-close-modal').modal('hide');
                if(typeof alert_toast==='function')alert_toast(r.message,'success');
                afterClosureChange();
            })
            .fail(function(x){if(typeof alert_toast==='function')alert_toast((x.responseJSON||{}).message||'No se pudo cerrar el bimestre.','danger');})
            .always(function(){btn.prop('disabled',false).html('<i class="fas fa-lock mr-1"></i>Cerrar bimestre');});
    });
    $(document).on('click','.gc-request-reopen',function(){
        var mode=String($(this).data('gc-mode')||'book'),st=stateForMode(mode);
        if(!validContext(st.ctx))return;
        actionContext=st.ctx;actionMode=mode;
        ensureReopenModal();
        $('#gc-reopen-context').text(st.label);
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

    $(document).on('change.gradeClosure','#gb-year,#gb-course,#gb-level,#gb-grade,#gb-section,#gb-bimester',function(){setTimeout(function(){refreshMode('book');},100);});
    $(document).on('change.gradeClosure','#gr-year,#gr-course,#gr-level,#gr-grade,#gr-section,#gr-bimester',function(){
        if(this.id==='gr-year'){listCache.year='';listCache.rows=[];}
        setTimeout(function(){refreshMode('list');},100);
    });
    $(document).on('click.gradeClosure','#gr-show-book',function(){setTimeout(function(){refreshMode('book');},100);});
    $(document).on('click.gradeClosure','#gr-show-list',function(){setTimeout(function(){refreshMode('list');},100);});
    $(document).on('evaluation:saved.gradeClosure evaluation:gradesSaved.gradeClosure',function(e,payload){
        if(payload&&payload.source==='grade_closure')return;
        setTimeout(refreshAll,250);
    });
    $(document).on('grade:reopen-reviewed.gradeClosure',afterClosureChange);

    setTimeout(refreshAll,220);
})(window.jQuery);
