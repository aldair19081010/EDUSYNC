<?php
include_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$school_id = isset($_SESSION['login_school_id']) ? (int) $_SESSION['login_school_id'] : 0;
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];
?>
<div class="container-fluid">
    <div class="col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h4 class="mb-0"><i class="fa fa-layer-group mr-2"></i>Actualización Masiva de Estudiantes</h4>
                <span class="badge badge-primary">Herramienta administrativa</span>
            </div>
            <div class="card-body">
                <div class="alert alert-warning" role="alert">
                    <i class="fa fa-exclamation-triangle mr-2"></i>
                    <strong>Importante:</strong> Antes de promover de grado, confirma que existe un <strong>año académico activo</strong>. Así el sistema podrá consultar notas del año previo.
                </div>

                <form id="bulk-update-form">
                    <input type="hidden" name="school_id" value="<?php echo $school_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="row form-group">
                        <div class="col-md-4">
                            <label for="current_nivel">Nivel Actual</label>
                            <select name="current_nivel" id="current_nivel" class="form-control">
                                <option value="">Todos los niveles</option>
                                <option value="Inicial">Inicial</option>
                                <option value="Primaria">Primaria</option>
                                <option value="Secundaria">Secundaria</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="current_grado">Grado Actual</label>
                            <select name="current_grado" id="current_grado" class="form-control">
                                <option value="">Todos los grados</option>
                                <?php $all_grades = ['1°','2°','3°','4°','5°','6°']; foreach ($all_grades as $g): ?>
                                    <option value="<?php echo $g; ?>"><?php echo $g; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="current_seccion">Sección Actual</label>
                            <select name="current_seccion" id="current_seccion" class="form-control">
                                <option value="">Todas las secciones</option>
                                <option value="U">U (Única)</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                                <option value="E">E</option>
                                <option value="F">F</option>
                            </select>
                        </div>
                    </div>

                    <hr>

                    <div class="row form-group">
                        <div class="col-md-12">
                            <label for="update_type">Tipo de Actualización</label>
                            <select name="update_type" id="update_type" class="form-control" required>
                                <option value="nivel_grado">Cambio de Nivel/Grado/Sección</option>
                                <option value="status_egresado">Marcar como Egresados</option>
                                <option value="status_retirado">Marcar como Retirados</option>
                                <option value="reactivar_estudiante">Reactivar Estudiantes Retirados</option>
                            </select>
                        </div>
                    </div>

                    <div id="nivel_grado_fields">
                        <h5 class="mt-3">Nuevos valores</h5>
                        <div class="row form-group">
                            <div class="col-md-4">
                                <label for="new_nivel">Nuevo Nivel</label>
                                <select name="new_nivel" id="new_nivel" class="form-control nivel-required">
                                    <option value="">Seleccione un nivel</option>
                                    <option value="Inicial">Inicial</option>
                                    <option value="Primaria">Primaria</option>
                                    <option value="Secundaria">Secundaria</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="new_grado">Nuevo Grado</label>
                                <select name="new_grado" id="new_grado" class="form-control nivel-required">
                                    <option value="">Seleccione un grado</option>
                                    <?php foreach ($all_grades as $g): ?>
                                        <option value="<?php echo $g; ?>"><?php echo $g; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="new_seccion">Nueva Sección</label>
                                <select name="new_seccion" id="new_seccion" class="form-control nivel-required">
                                    <option value="">Seleccione una sección</option>
                                    <option value="U">U (Única)</option>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                    <option value="D">D</option>
                                    <option value="E">E</option>
                                    <option value="F">F</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="status_fields" style="display:none;">
                        <div class="alert alert-info" id="status_message">
                            Los estudiantes seleccionados cambiarán de estado.
                        </div>
                    </div>

                    <div class="form-group mt-3">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="preview_only" name="preview_only" checked>
                            <label class="custom-control-label" for="preview_only">Solo previsualizar cambios (no aplicar)</label>
                        </div>
                        <small class="form-text text-muted">Active esta opción para ver qué estudiantes serán afectados antes de aplicar cambios.</small>
                    </div>

                    <div class="form-group mt-3">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search mr-1"></i>Buscar Estudiantes</button>
                    </div>
                </form>

                <div id="results" class="mt-4">
                    <div class="alert alert-info mb-0">
                        Seleccione los criterios y haga clic en "Buscar Estudiantes" para ver los estudiantes que serán afectados.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function($){
    const $form = $('#bulk-update-form');
    const $results = $('#results');

    function toggleFields(){
        const type = $('#update_type').val();
        if(type === 'nivel_grado'){
            $('#nivel_grado_fields').show();
            $('#status_fields').hide();
            $('.nivel-required').prop('required', true);
        } else if(type === 'reactivar_estudiante') {
            $('#nivel_grado_fields').show();
            $('#status_fields').show();
            $('.nivel-required').prop('required', false);
            $('#status_message').text('Los estudiantes retirados/egresados serán reactivados como Activos. Opcionalmente puede asignar nuevo nivel, grado y sección.');
        } else {
            $('#nivel_grado_fields').hide();
            $('#status_fields').show();
            $('.nivel-required').prop('required', false);
            if(type === 'status_egresado') {
                $('#status_message').text('Los estudiantes seleccionados serán marcados como Egresados.');
            } else if(type === 'status_retirado') {
                $('#status_message').text('Los estudiantes seleccionados serán marcados como Retirados.');
            }
        }
    }

    $('#update_type').on('change', toggleFields);
    toggleFields();

    // Promoción sugerida
    $('#current_nivel, #current_grado').on('change', function(){
        const currentNivel = $('#current_nivel').val();
        const currentGrado = $('#current_grado').val();
        if (!currentNivel || !currentGrado) return;

        let newNivel = currentNivel;
        let newGrado = '';
        if (currentNivel === 'Inicial' && currentGrado === '5°') {
            newNivel = 'Primaria';
            newGrado = '1°';
        } else if (currentNivel === 'Primaria' && currentGrado === '6°') {
            newNivel = 'Secundaria';
            newGrado = '1°';
        } else {
            const order = ['1°','2°','3°','4°','5°','6°'];
            const idx = order.indexOf(currentGrado);
            if (idx >= 0 && idx < order.length - 1) {
                newGrado = order[idx + 1];
            }
        }
        if (newNivel) $('#new_nivel').val(newNivel).trigger('change');
        if (newGrado) $('#new_grado').val(newGrado).trigger('change');
    });

    function renderStudentsTable(students, previewOnly){
        if (!Array.isArray(students) || students.length === 0){
            $results.html('<div class="alert alert-warning mb-0">No se encontraron estudiantes con los criterios seleccionados.</div>');
            return;
        }

        let html = `
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Estudiantes Encontrados (${students.length})</h5>
                ${previewOnly ? '<button class="btn btn-success btn-sm" id="apply-changes"><i class="fa fa-check mr-1"></i>Aplicar Cambios</button>' : '<span class="badge badge-success">Cambios Aplicados</span>'}
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="check-all" ${previewOnly ? '' : 'disabled'}></th>
                                <th>DNI</th>
                                <th>Nombre</th>
                                <th>Nivel Actual</th>
                                <th>Grado Actual</th>
                                <th>Sección Actual</th>`;

        const ut = students[0] ? students[0].update_type : '';
        if (ut === 'nivel_grado') {
            html += `<th>Nuevo Nivel</th><th>Nuevo Grado</th><th>Nueva Sección</th>`;
        } else if (ut === 'status_activo' || ut === 'status_egresado' || ut === 'status_retirado') {
            html += `<th>Estado Actual</th><th>Nuevo Estado</th>`;
        } else if (ut === 'reactivar_estudiante') {
            html += `<th>Estado Actual</th><th>Nuevo Estado</th><th>Nuevo Nivel</th><th>Nuevo Grado</th><th>Nueva Sección</th>`;
        }

        html += `</tr></thead><tbody>`;

        students.forEach(function(s){
            html += `<tr>
                <td><input type="checkbox" class="student-check" value="${s.id}" ${previewOnly ? '' : 'disabled'} checked></td>
                <td>${s.id_no || ''}</td>
                <td>${s.name || ''}</td>
                <td>${s.nivel || ''}</td>
                <td>${s.grado || ''}</td>
                <td>${s.seccion || ''}</td>`;

            if (s.update_type === 'nivel_grado') {
                html += `<td class="bg-light">${s.new_nivel || ''}</td><td class="bg-light">${s.new_grado || ''}</td><td class="bg-light">${s.new_seccion || ''}</td>`;
            } else if (s.update_type === 'status_activo' || s.update_type === 'status_egresado' || s.update_type === 'status_retirado') {
                html += `<td>${s.status || 'Activo'}</td><td class="bg-light">${s.new_status || ''}</td>`;
            } else if (s.update_type === 'reactivar_estudiante') {
                html += `<td>${s.status || 'Retirado'}</td><td class="bg-light">${s.new_status || ''}</td><td class="bg-light">${s.new_nivel || '---'}</td><td class="bg-light">${s.new_grado || '---'}</td><td class="bg-light">${s.new_seccion || '---'}</td>`;
            }

            html += '</tr>';
        });

        html += '</tbody></table></div></div></div>';
        $results.html(html);

        $('#check-all').on('change', function(){
            $('.student-check').prop('checked', $(this).is(':checked'));
        });

        $('#apply-changes').on('click', function(){
            const selected = $('.student-check:checked').map(function(){ return $(this).val(); }).get();
            if (!selected.length){
                alert('Seleccione al menos un estudiante.');
                return;
            }
            if (!confirm(`¿Está seguro de actualizar ${selected.length} estudiantes?`)) return;

            const $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-1"></i>Procesando...');
            const formData = $form.serialize() + '&student_ids=' + encodeURIComponent(JSON.stringify(selected));
            $.ajax({
                url: 'ajax.php?action=bulk_update_students',
                method: 'POST',
                data: formData,
                dataType: 'json',
                success: function(resp){
                    if (resp && resp.status == 1){
                        alert_toast('Estudiantes actualizados exitosamente.', 'success');
                        setTimeout(function(){ $form.submit(); }, 800);
                    } else {
                        $btn.prop('disabled', false).html('<i class="fa fa-check mr-1"></i>Aplicar Cambios');
                        alert_toast(resp && resp.msg ? resp.msg : 'Ha ocurrido un error desconocido.', 'error');
                    }
                },
                error: function(xhr, status, error){
                    $btn.prop('disabled', false).html('<i class="fa fa-check mr-1"></i>Aplicar Cambios');
                    console.error('Error en la solicitud AJAX:', xhr);
                    alert_toast('Error en el servidor: ' + (error || 'Error de conexión'), 'error');
                }
            });
        });
    }

    $form.on('submit', function(e){
        e.preventDefault();
        if (typeof start_load === 'function') start_load();

        const updateType = $('#update_type').val();
        if (updateType === 'nivel_grado'){
            if (!$('#new_nivel').val() || !$('#new_grado').val() || !$('#new_seccion').val()){
                if (typeof end_load === 'function') end_load();
                $results.html('<div class="alert alert-danger mb-0">Debe completar Nivel, Grado y Sección nuevos.</div>');
                return;
            }
        }

        $.ajax({
            url: 'ajax.php?action=get_students_for_bulk_update',
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(resp){
                if (typeof end_load === 'function') end_load();
                if (resp && resp.status == 1){
                    renderStudentsTable(resp.data, resp.preview_only);
                } else {
                    const msg = resp && resp.msg ? resp.msg : 'Ha ocurrido un error desconocido.';
                    $results.html('<div class="alert alert-danger mb-0">' + msg + '</div>');
                    console.error('Respuesta AJAX:', resp);
                }
            },
            error: function(xhr, status, error){
                if (typeof end_load === 'function') end_load();
                console.error('Error en la solicitud AJAX:', xhr);
                $results.html('<div class="alert alert-danger mb-0">Error en el servidor: ' + (error || 'Error de conexión') + '</div>');
            }
        });
    });
})(jQuery);
</script>
