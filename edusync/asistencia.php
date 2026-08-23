<?php include('db_connect.php'); ?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 text-gray-800 mb-0"><i class="fa fa-calendar-check mr-2"></i> Gestión de Asistencia</h1>
        <button class="btn btn-primary btn-sm" id="nueva_asistencia">
            <i class="fa fa-plus"></i> Nueva Asistencia Manual
        </button>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Filtros y marcaje rápido</h6>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3 col-sm-6 mb-2">
                    <label class="small mb-1">Filtrar por fecha</label>
                    <input type="date" id="filtro_fecha" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3 col-sm-6 mb-2">
                    <label class="small mb-1">Filtrar por tipo</label>
                    <select id="filtro_tipo" class="form-control">
                        <option value="">Todos</option>
                        <option value="Entrada">Entrada</option>
                        <option value="Salida">Salida</option>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6 mb-2">
                    <label class="small mb-1">Tipo de marcaje</label>
                    <select id="barcode_tipo" class="form-control">
                        <option value="Entrada">Entrada</option>
                        <option value="Salida">Salida</option>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6 mb-2">
                    <label class="small mb-1">Escanear Código de Barras (DNI)</label>
                    <input type="text" id="barcode_input" class="form-control" placeholder="Escanee el código de barras del estudiante" autofocus autocomplete="off">
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Registro de Asistencia</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="tabla_asistencia">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Fecha</th>
                            <th>DNI</th>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>Hora</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="asistencia_body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal_asistencia" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form id="form_asistencia">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Registrar Asistencia Manual</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Fecha</label>
                        <input type="date" name="fecha" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Estudiante</label>
                        <select name="student_id" class="form-control select2" id="student_id_asistencia" required>
                            <option value="">Seleccione un estudiante</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tipo</label>
                        <select name="tipo" class="form-control" required>
                            <option value="Entrada">Entrada</option>
                            <option value="Salida">Salida</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Hora</label>
                        <input type="time" id="hora_actual" name="hora" class="form-control" step="1" required>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado" id="estado_asistencia" class="form-control" required>
                            <option value="Presente" selected>Presente (auto)</option>
                            <option value="Ausente">Ausente</option>
                        </select>
                        <small class="form-text text-muted">Si eliges "Presente (auto)", se calculará Temprano/Normal/Tarde según la hora y las reglas.</small>
                    </div>
                    <div class="form-group" id="justificacion_wrap" style="display:none;">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" value="1" id="chk_justificada">
                            <label class="form-check-label" for="chk_justificada">Inasistencia justificada</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" type="submit">Guardar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Controla si la hora debe autocompletarse con el reloj
let autoHora = true;

function cargarAsistencia(fecha, tipo) {
    $.ajax({
        url: 'ajax.php?action=get_asistencia',
        method: 'POST',
        data: { fecha: fecha, tipo: tipo },
        dataType: 'json',
        success: function(resp) {
            var html = '';
            if (resp.length > 0) {
                let i = 1;
                resp.forEach(function(row) {
                    var esAusente = (typeof row.estado === 'string' && row.estado.indexOf('Ausente') === 0);
                    var horaDisplay = (esAusente || row.hora === '00:00:00' || row.hora === null || row.hora === '') ? '-' : row.hora;
                    html += '<tr>';
                    html += '<td>' + (i++) + '</td>';
                    html += '<td>' + row.fecha + '</td>';
                    html += '<td>' + row.id_no + '</td>';
                    html += '<td>' + row.name + '</td>';
                    html += '<td>' + row.tipo + '</td>';
                    html += '<td>' + horaDisplay + '</td>';
                    var estadoTexto = row.estado;
                    html += '<td><span class="badge ' + badgeClass(row.estado) + '">' + estadoTexto + '</span></td>';
                    html += '</tr>';
                });
            } else {
                html = '<tr><td colspan="7" class="text-center py-3">Sin registros para esta fecha.</td></tr>';
            }
            $('#asistencia_body').html(html);
        },
        error: function() {
            $('#asistencia_body').html('<tr><td colspan="7" class="text-center py-3">Error al cargar la asistencia.</td></tr>');
        }
    });
}

function cargarAlumnosAsistencia() {
    $.ajax({
        url: 'ajax.php?action=get_students_for_asistencia',
        method: 'GET',
        dataType: 'json',
        success: function(resp) {
            var html = '<option value="">Seleccione un estudiante</option>';
            resp.forEach(function(row) {
                html += '<option value="' + row.id + '">' + row.name + ' (' + row.id_no + ')</option>';
            });
            $('#student_id_asistencia').html(html).trigger('change');
        },
        error: function() {
            $('#student_id_asistencia').html('<option value="">Error al cargar estudiantes</option>');
        }
    });
}

function actualizarHora() {
    var now = new Date();
    var hours = String(now.getHours()).padStart(2, '0');
    var minutes = String(now.getMinutes()).padStart(2, '0');
    var seconds = String(now.getSeconds()).padStart(2, '0');
    var horaActual = hours + ':' + minutes + ':' + seconds;
    if (autoHora && !$('#hora_actual').prop('disabled')) {
        $('#hora_actual').val(horaActual);
    }
}

function toggleHoraByEstado() {
    var estado = $('#estado_asistencia').val();
    if (estado === 'Ausente') {
        $('#hora_actual').prop('disabled', true).prop('required', false).val('');
        $('#justificacion_wrap').slideDown(100);
    } else {
        $('#hora_actual').prop('disabled', false).prop('required', true);
        actualizarHora();
        $('#justificacion_wrap').slideUp(100);
    }
}

$(document).ready(function() {
    $('.select2').select2({ width: '100%', dropdownParent: $('#modal_asistencia') });

    // DataTable básico sin ordenar (datos por AJAX)
    $('#tabla_asistencia').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json' },
        responsive: true,
        pageLength: 10,
        ordering: false
    });

    let fecha = $('#filtro_fecha').val();
    let tipo = $('#filtro_tipo').val();
    cargarAsistencia(fecha, tipo);

    actualizarHora();
    setInterval(actualizarHora, 1000);

    $('#filtro_fecha, #filtro_tipo').change(function() {
        cargarAsistencia($('#filtro_fecha').val(), $('#filtro_tipo').val());
    });

    $('#nueva_asistencia').click(function() {
        cargarAlumnosAsistencia();
        autoHora = true;
        actualizarHora();
        $('#modal_asistencia').modal('show');
        $('#estado_asistencia').off('change', toggleHoraByEstado).on('change', toggleHoraByEstado);
        toggleHoraByEstado();
    });

    $('#hora_actual').on('focus input change', function(){
        autoHora = false;
    });

    $('#form_asistencia').submit(function(e) {
        e.preventDefault();
        start_load();
        let formData = $(this).serializeArray();
        let horaInput = formData.find(f => f.name === 'hora');
        let estadoInput = formData.find(f => f.name === 'estado');
        let estadoVal = estadoInput ? estadoInput.value : '';
        if (horaInput && horaInput.value && horaInput.value.length === 5) {
            horaInput.value = horaInput.value + ':00';
        }
        let dataObj = {};
        formData.forEach(function(item) {
            dataObj[item.name] = item.value;
        });
        if (estadoVal === 'Ausente') {
            dataObj['hora'] = '00:00:00';
        }
        if (estadoVal === 'Ausente' && $('#chk_justificada').is(':checked')) {
            dataObj['estado'] = 'Ausente Justificada';
        }
        $.ajax({
            url: 'ajax.php?action=save_asistencia',
            method: 'POST',
            data: dataObj,
            dataType: 'json',
            success: function(resp) {
                if(resp.status == 1) {
                    alert_toast(resp.message, 'success');
                    setTimeout(function(){ 
                        $('#modal_asistencia').modal('hide');
                        cargarAsistencia($('#filtro_fecha').val(), $('#filtro_tipo').val());
                        end_load();
                    }, 800);
                } else {
                    alert_toast(resp.message, 'danger');
                    end_load();
                }
            },
            error: function() {
                alert_toast('Error al guardar asistencia.', 'danger');
                end_load();
            }
        });
    });

    $('#barcode_input').on('keypress', function(e) {
        if (e.which == 13) {
            let dni = $(this).val().trim();
            let fecha = $('#filtro_fecha').val();
            let tipo = $('#barcode_tipo').val();
            if (dni.length === 0) return;
            start_load();
            var now = new Date();
            var horaActual = String(now.getHours()).padStart(2, '0') + ':' + 
                            String(now.getMinutes()).padStart(2, '0') + ':' + 
                            String(now.getSeconds()).padStart(2, '0');
            $.ajax({
                url: 'ajax.php?action=save_asistencia_barcode',
                method: 'POST',
                data: { 
                    dni: dni, 
                    fecha: fecha, 
                    tipo: tipo,
                    hora_actual: horaActual
                },
                dataType: 'json',
                success: function(resp) {
                    if(resp.status == 1) {
                        alert_toast(resp.message, 'success');
                        cargarAsistencia(fecha, $('#filtro_tipo').val());
                    } else {
                        alert_toast(resp.message, 'danger');
                    }
                    $('#barcode_input').val('').focus();
                    end_load();
                },
                error: function() {
                    alert_toast('Error al registrar asistencia.', 'danger');
                    $('#barcode_input').val('').focus();
                    end_load();
                }
            });
        }
    });
});

function badgeClass(estado) {
    switch(estado) {
        case 'Temprano': return 'badge badge-success';
        case 'Normal': return 'badge badge-warning';
        case 'Tarde': return 'badge badge-danger';
        default:
            if (typeof estado === 'string' && estado.indexOf('Ausente') === 0) return 'badge badge-secondary';
            return 'badge badge-secondary';
    }
}
</script>
