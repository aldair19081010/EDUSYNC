<?php
require_once __DIR__ . '/grade_access_policy_service.php';

$advanced_policy = grade_policy_load($conn, (int)$school_id);
$grade_policy_concepts = grade_policy_available_concepts($conn, (int)$school_id);
$grade_policy_selected = grade_policy_selected_course_ids($conn, (int)$school_id);
$grade_policy_selected_lookup = array_fill_keys(array_map('intval', $grade_policy_selected), true);
$grade_policy_exceptions = grade_policy_list_exceptions($conn, (int)$school_id, false);

$temp_value = '';
if (!empty($advanced_policy['temporary_access_until'])) {
    try {
        $temp_value = (new DateTimeImmutable((string)$advanced_policy['temporary_access_until'], new DateTimeZone('America/Lima')))->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        $temp_value = '';
    }
}
?>
<style>
.policy-manage-card{overflow:hidden}
.policy-manage-nav{display:flex;gap:.45rem;flex-wrap:wrap;padding:.7rem;background:#f6f8fb;border-bottom:1px solid #e7ebf1}
.policy-manage-tab{border:1px solid transparent;background:transparent;color:#697386;font-weight:700;font-size:.82rem;padding:.55rem .85rem;border-radius:.5rem}
.policy-manage-tab:hover{background:#fff;color:#4e73df}
.policy-manage-tab.active{background:#fff;color:#4e73df;border-color:#dfe5ee;box-shadow:0 2px 6px rgba(31,45,61,.06)}
.policy-manage-pane{display:none}.policy-manage-pane.active{display:block}
.policy-section-title{font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#6c7587;margin-bottom:.8rem}
.policy-compact-box{border:1px solid #e4e8ef;border-radius:.6rem;padding:1rem;background:#fff;height:100%}
.policy-option-note{font-size:.76rem;color:#7b8499;line-height:1.4}
.policy-message-toggle{cursor:pointer;user-select:none}
.policy-message-panel{display:none;margin-top:1rem}.policy-message-panel.open{display:block}
.policy-control-stat{border:1px solid #e5e9f0;border-radius:.55rem;padding:.7rem .45rem;text-align:center;height:100%}
.policy-control-stat strong{display:block;font-size:1.35rem;color:#344767}
.policy-control-stat span{font-size:.7rem;color:#7b8499}
@media(max-width:767.98px){.policy-manage-nav{display:grid;grid-template-columns:1fr}.policy-manage-tab{text-align:left;width:100%}}
</style>

<div class="card policy-card ed-content-card mb-4 policy-manage-card">
    <div class="card-header bg-white py-3 ed-content-card-header">
        <h6 class="mb-1 font-weight-bold text-gray-800"><i class="fas fa-cogs text-primary mr-2"></i>Gestión de la política</h6>
        <div class="small text-muted">Las opciones complementarias están agrupadas para mantener esta pantalla simple.</div>
    </div>

    <div class="policy-manage-nav" role="tablist">
        <button type="button" class="policy-manage-tab active" data-policy-pane="rules"><i class="fas fa-sliders-h mr-1"></i>Reglas</button>
        <button type="button" class="policy-manage-tab" data-policy-pane="exceptions"><i class="fas fa-user-check mr-1"></i>Excepciones <span class="badge badge-light border ml-1" id="gradeExceptionCount"><?php echo count($grade_policy_exceptions); ?></span></button>
        <button type="button" class="policy-manage-tab" data-policy-pane="control"><i class="fas fa-chart-bar mr-1"></i>Control</button>
    </div>

    <div class="card-body p-0">
        <div class="policy-manage-pane active p-3 p-md-4" id="policy-pane-rules">
            <div class="policy-section-title">Condiciones complementarias</div>
            <div class="row">
                <div class="col-lg-4 mb-3">
                    <div class="policy-compact-box">
                        <label class="font-weight-bold mb-1">Días de gracia</label>
                        <input type="number" class="form-control" id="gradeGraceDays" min="0" max="90" value="<?php echo (int)($advanced_policy['grace_days'] ?? 0); ?>" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                        <div class="policy-option-note mt-2">Días adicionales después del vencimiento antes de bloquear.</div>
                    </div>
                </div>
                <div class="col-lg-8 mb-3">
                    <div class="policy-compact-box">
                        <label class="font-weight-bold mb-1">Apertura temporal para todos</label>
                        <input type="datetime-local" class="form-control" id="gradeTemporaryUntil" value="<?php echo htmlspecialchars($temp_value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                        <div class="policy-option-note mt-2">Mientras esté vigente todos podrán ver notas, sin alterar sus deudas.</div>
                    </div>
                </div>
            </div>

            <div class="policy-compact-box mb-3">
                <label class="font-weight-bold mb-1">Conceptos que pueden bloquear las notas</label>
                <div class="policy-option-note mb-2">Selecciona solo los conceptos que deben influir. Sin selección se consideran todos.</div>
                <select class="form-control" id="gradeConceptIds" multiple size="<?php echo min(7, max(4, count($grade_policy_concepts))); ?>" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                    <?php foreach ($grade_policy_concepts as $concept): ?>
                        <option value="<?php echo (int)$concept['id']; ?>" <?php echo isset($grade_policy_selected_lookup[(int)$concept['id']]) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)$concept['course'] . ' · ' . (string)$concept['level'] . ' · ' . (string)$concept['year'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="policy-compact-box">
                <div class="d-flex justify-content-between align-items-center policy-message-toggle" id="togglePolicyMessages">
                    <div>
                        <strong><i class="fas fa-comment-alt text-secondary mr-2"></i>Mensajes especiales</strong>
                        <div class="policy-option-note">Opcional. Solo ábrelo si quieres cambiar los textos que verá la familia.</div>
                    </div>
                    <i class="fas fa-chevron-down text-muted" id="policyMessagesChevron"></i>
                </div>
                <div class="policy-message-panel" id="policyMessagePanel">
                    <div class="row">
                        <div class="col-lg-4 form-group mb-lg-0">
                            <label class="small font-weight-bold">Periodo de gracia</label>
                            <textarea class="form-control" id="gradeGraceMessage" rows="3" maxlength="500"><?php echo htmlspecialchars((string)($advanced_policy['grace_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-lg-4 form-group mb-lg-0">
                            <label class="small font-weight-bold">Apertura temporal</label>
                            <textarea class="form-control" id="gradeTemporaryMessage" rows="3" maxlength="500"><?php echo htmlspecialchars((string)($advanced_policy['temporary_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-lg-4 form-group mb-0">
                            <label class="small font-weight-bold">Excepción individual</label>
                            <textarea class="form-control" id="gradeExceptionMessage" rows="3" maxlength="500"><?php echo htmlspecialchars((string)($advanced_policy['exception_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-light border mt-3 mb-0 py-2">
                <i class="fas fa-sync-alt text-success mr-2"></i><strong>Desbloqueo automático:</strong> al regularizar la deuda, el sistema vuelve a habilitar las notas automáticamente.
            </div>
        </div>

        <div class="policy-manage-pane p-3 p-md-4" id="policy-pane-exceptions">
            <div class="policy-section-title">Accesos autorizados individualmente</div>
            <div class="policy-compact-box mb-3">
                <div class="row align-items-end">
                    <div class="col-lg-5 form-group">
                        <label class="font-weight-bold">Estudiante</label>
                        <select class="form-control" id="gradeExceptionStudent" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                            <option value="">Seleccionar estudiante...</option>
                            <?php foreach ($students_access as $student): ?>
                                <?php if (($student['status'] ?? 'Activo') === 'Activo'): ?>
                                    <option value="<?php echo (int)$student['id']; ?>"><?php echo htmlspecialchars((string)$student['name'] . ' · ' . (string)$student['id_no'] . ' · ' . (string)$student['grado'] . ' ' . (string)$student['seccion'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 form-group">
                        <label class="font-weight-bold">Válida hasta</label>
                        <input type="date" class="form-control" id="gradeExceptionExpiry" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                        <small class="form-text text-muted">Vacío = sin límite.</small>
                    </div>
                    <div class="col-lg-4 form-group">
                        <label class="font-weight-bold">Motivo</label>
                        <input type="text" class="form-control" id="gradeExceptionReason" maxlength="500" placeholder="Ej.: autorización de Dirección" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
                    </div>
                </div>
                <div class="text-right"><button type="button" class="btn btn-success" id="addGradeException" <?php echo $access_migration_ready ? '' : 'disabled'; ?>><i class="fas fa-plus mr-1"></i>Autorizar acceso</button></div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-bordered mb-0" id="gradeExceptionsTable">
                    <thead class="thead-light"><tr><th>Estudiante</th><th>Motivo</th><th>Vigencia</th><th>Autorizó</th><th class="text-center">Acción</th></tr></thead>
                    <tbody>
                    <?php if (!$grade_policy_exceptions): ?>
                        <tr class="grade-exceptions-empty"><td colspan="5" class="text-center text-muted py-4">No hay excepciones vigentes.</td></tr>
                    <?php else: foreach ($grade_policy_exceptions as $exception): ?>
                        <tr data-exception-id="<?php echo (int)$exception['id']; ?>">
                            <td><strong><?php echo htmlspecialchars((string)$exception['student_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars((string)$exception['student_dni'] . ' · ' . (string)$exception['grado'] . ' ' . (string)$exception['seccion'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <td><?php echo htmlspecialchars((string)$exception['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $exception['expires_at'] ? htmlspecialchars((string)$exception['expires_at'], ENT_QUOTES, 'UTF-8') : '<span class="badge badge-success">Sin límite</span>'; ?></td>
                            <td><?php echo htmlspecialchars((string)($exception['created_by_name'] ?? 'Sistema'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-grade-exception" data-id="<?php echo (int)$exception['id']; ?>" title="Retirar excepción"><i class="fas fa-times"></i></button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="policy-manage-pane p-3 p-md-4" id="policy-pane-control">
            <div class="row">
                <div class="col-lg-7 mb-3 mb-lg-0">
                    <div class="policy-compact-box h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div><strong><i class="fas fa-calculator text-info mr-2"></i>Impacto de la política</strong><div class="policy-option-note">Comprueba cuántos estudiantes serían afectados.</div></div>
                            <button type="button" class="btn btn-sm btn-outline-info" id="simulateGradePolicy" <?php echo $access_migration_ready ? '' : 'disabled'; ?>><i class="fas fa-play mr-1"></i>Simular</button>
                        </div>
                        <div id="gradeSimulationBody"><div class="text-muted small">Ejecuta la simulación después de guardar los cambios.</div></div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="policy-compact-box h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div><strong><i class="fas fa-history text-secondary mr-2"></i>Historial</strong><div class="policy-option-note">Cambios y excepciones realizadas.</div></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="loadGradePolicyHistory" <?php echo $access_migration_ready ? '' : 'disabled'; ?>><i class="fas fa-sync-alt"></i></button>
                        </div>
                        <div id="gradePolicyHistoryBody"><div class="text-muted small">Pulsa actualizar para consultar movimientos.</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex flex-column flex-md-row justify-content-between align-items-md-center">
        <div class="small text-muted mb-2 mb-md-0"><i class="fas fa-info-circle mr-1"></i>El botón guarda la política general y las reglas adicionales.</div>
        <button type="button" class="btn btn-primary" id="saveGradePolicy" <?php echo $access_migration_ready ? '' : 'disabled'; ?>>
            <i class="fas fa-save mr-1"></i>Guardar cambios
        </button>
    </div>
</div>

<script>
(function($){
    var policyCsrf = <?php echo json_encode($csrf_token); ?>;
    $('.policy-manage-tab').on('click',function(){
        var pane=$(this).data('policy-pane');
        $('.policy-manage-tab').removeClass('active');
        $(this).addClass('active');
        $('.policy-manage-pane').removeClass('active');
        $('#policy-pane-'+pane).addClass('active');
    });

    $('#togglePolicyMessages').on('click',function(){
        $('#policyMessagePanel').toggleClass('open');
        $('#policyMessagesChevron').toggleClass('fa-chevron-down fa-chevron-up');
    });

    function refreshGradeExceptionCount(){
        $('#gradeExceptionCount').text($('#gradeExceptionsTable tbody tr[data-exception-id]').length);
    }

    function policyEsc(v){ return $('<div>').text(v == null ? '' : v).html(); }
    function policyPost(action,data){
        data = data || {};
        data.action = action;
        data.csrf_token = policyCsrf;
        return $.ajax({url:'users_api.php',method:'POST',data:data,dataType:'json'});
    }
    function policyToast(message,type){
        if(typeof alert_toast === 'function') alert_toast(message,type || 'success');
        else alert(message);
    }

    $('#saveGradePolicy').on('click',function(){
        $('#gradeAccessPolicyForm').trigger('submit');
    });

    $('#addGradeException').on('click',function(){
        var studentId=$('#gradeExceptionStudent').val();
        var reason=$('#gradeExceptionReason').val().trim();
        if(!studentId){ policyToast('Selecciona un estudiante.','warning'); return; }
        if(reason.length<3){ policyToast('Indica el motivo de la excepción.','warning'); return; }
        var btn=$(this).prop('disabled',true);
        policyPost('save_grade_exception',{
            student_id:studentId,
            expires_at:$('#gradeExceptionExpiry').val(),
            reason:reason
        }).done(function(r){
            if(r.status!=1){ policyToast(r.message||'No se pudo guardar.','danger'); return; }
            var x=r.exception||{};
            $('.grade-exceptions-empty').remove();
            $('#gradeExceptionsTable tbody').prepend(
                '<tr data-exception-id="'+Number(x.id||0)+'">'
                +'<td><strong>'+policyEsc(x.student_name)+'</strong><br><small class="text-muted">'+policyEsc(x.student_dni)+' · '+policyEsc(x.location)+'</small></td>'
                +'<td>'+policyEsc(x.reason)+'</td>'
                +'<td>'+(x.expires_at?policyEsc(x.expires_at):'<span class="badge badge-success">Sin límite</span>')+'</td>'
                +'<td>Sesión actual</td>'
                +'<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-grade-exception" data-id="'+Number(x.id||0)+'"><i class="fas fa-times"></i></button></td>'
                +'</tr>'
            );
            $('#gradeExceptionStudent,#gradeExceptionExpiry,#gradeExceptionReason').val('');
            refreshGradeExceptionCount();
            policyToast(r.message,'success');
        }).fail(function(xhr){
            policyToast((xhr.responseJSON||{}).message||'No se pudo guardar la excepción.','danger');
        }).always(function(){ btn.prop('disabled',false); });
    });

    $(document).on('click','.remove-grade-exception',function(){
        var btn=$(this),id=btn.data('id');
        if(!confirm('¿Retirar esta excepción de acceso?')) return;
        policyPost('remove_grade_exception',{exception_id:id}).done(function(r){
            if(r.status!=1){ policyToast(r.message||'No se pudo retirar.','danger'); return; }
            btn.closest('tr').remove();
            if(!$('#gradeExceptionsTable tbody tr').length){
                $('#gradeExceptionsTable tbody').html('<tr class="grade-exceptions-empty"><td colspan="5" class="text-center text-muted py-3">No hay excepciones vigentes.</td></tr>');
            }
            refreshGradeExceptionCount();
            policyToast(r.message,'success');
        }).fail(function(xhr){
            policyToast((xhr.responseJSON||{}).message||'No se pudo retirar la excepción.','danger');
        });
    });

    $('#simulateGradePolicy').on('click',function(){
        var btn=$(this).prop('disabled',true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Calculando');
        $('#gradeSimulationBody').html('<div class="text-muted">Evaluando estudiantes...</div>');
        policyPost('simulate_grade_policy',{}).done(function(r){
            if(r.status!=1){ $('#gradeSimulationBody').html('<div class="text-danger">'+policyEsc(r.message)+'</div>'); return; }
            var s=r.simulation||{},html=
                '<div class="row text-center">'
                +'<div class="col"><strong class="d-block h4 mb-0">'+Number(s.blocked||0)+'</strong><small class="text-danger">Bloqueados</small></div>'
                +'<div class="col"><strong class="d-block h4 mb-0">'+Number(s.grace||0)+'</strong><small class="text-warning">En gracia</small></div>'
                +'<div class="col"><strong class="d-block h4 mb-0">'+Number(s.exceptions||0)+'</strong><small class="text-success">Excepciones</small></div>'
                +'<div class="col"><strong class="d-block h4 mb-0">'+Number(s.allowed||0)+'</strong><small class="text-muted">Disponibles</small></div>'
                +'</div><hr>';
            var sample=s.sample||[];
            if(sample.length){
                html+='<div class="small font-weight-bold mb-2">Muestra de estudiantes afectados</div><div class="small">';
                sample.forEach(function(x){
                    html+='<div class="border-bottom py-1"><strong>'+policyEsc(x.name)+'</strong> · '+policyEsc(x.location)
                        +' <span class="badge '+(x.reason==='grace_period'?'badge-warning':'badge-danger')+'">'+(x.reason==='grace_period'?'Gracia':'Bloqueo')+'</span></div>';
                });
                html+='</div>';
            }else html+='<div class="text-success"><i class="fas fa-check-circle mr-1"></i>No hay estudiantes bloqueados con la política actual.</div>';
            $('#gradeSimulationBody').html(html);
        }).fail(function(xhr){
            $('#gradeSimulationBody').html('<div class="text-danger">'+policyEsc((xhr.responseJSON||{}).message||'No se pudo simular.')+'</div>');
        }).always(function(){ btn.prop('disabled',false).html('<i class="fas fa-play mr-1"></i>Simular'); });
    });

    $('#loadGradePolicyHistory').on('click',function(){
        var box=$('#gradePolicyHistoryBody').html('<div class="text-muted"><i class="fas fa-spinner fa-spin mr-1"></i>Cargando...</div>');
        policyPost('grade_policy_history',{}).done(function(r){
            if(r.status!=1){ box.html('<div class="text-danger">'+policyEsc(r.message)+'</div>'); return; }
            var items=r.items||[],html='';
            items.forEach(function(x){
                var labels={POLICY_UPDATED:'Política actualizada',STUDENT_EXCEPTION_CREATED:'Excepción agregada',STUDENT_EXCEPTION_REMOVED:'Excepción retirada'};
                html+='<div class="history-item"><div class="history-title">'+policyEsc(labels[x.action]||x.action)+'</div>'
                    +'<div class="history-meta">'+policyEsc(x.created_at)+' · '+policyEsc(x.actor_name||'Sistema')+'</div></div>';
            });
            box.html(html||'<div class="text-muted">Sin cambios registrados.</div>');
        }).fail(function(xhr){
            box.html('<div class="text-danger">'+policyEsc((xhr.responseJSON||{}).message||'No se pudo cargar el historial.')+'</div>');
        });
    });
})(jQuery);
</script>
