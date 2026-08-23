<?php 
include 'db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
?>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-file-invoice"></i> Comprobantes Electrónicos
        </h1>
        <button class="btn btn-primary shadow-sm" onclick="uni_modal('Emitir Comprobante Libre', 'manage_comprobante.php', 'large')">
            <i class="fas fa-plus fa-sm text-white-50"></i> Emitir Nuevo Comprobante
        </button>
    </div>

    <!-- Filtros -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Búsqueda y Filtros</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <label><strong>Estudiante</strong></label>
                    <select id="filtro_estudiante" class="form-control form-control-sm select2" style="width: 100%;"></select>
                </div>
                <div class="col-md-2">
                    <label><strong>Desde</strong></label>
                    <input type="date" id="filtro_fecha_desde" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label><strong>Hasta</strong></label>
                    <input type="date" id="filtro_fecha_hasta" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label><strong>Tipo</strong></label>
                    <select id="filtro_tipo" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        <option value="01">Factura</option>
                        <option value="03">Boleta</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label><strong>Estado</strong></label>
                    <select id="filtro_estado" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        <option value="aceptado">Aceptado ✅</option>
                        <option value="pendiente">Pendiente ⏳</option>
                        <option value="rechazado">Rechazado ❌</option>
                    </select>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-12">
                    <button class="btn btn-primary btn-sm" onclick="buscarComprobantes()">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="limpiarFiltros()">
                        <i class="fas fa-times"></i> Limpiar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla -->
    <div class="card shadow">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Listado de Comprobantes</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="comprobantesTable">
                    <thead class="bg-light">
                        <tr>
                            <th>Número</th>
                            <th>Tipo</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Monto</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Reintento -->
    <div class="modal fade" id="modalReintentar" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">Reintentar Envío</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="reintento_comprobante_id">
                    <p>¿Desea reintentar el envío de este comprobante a SUNAT?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning" onclick="confirmarReintento()">Reintentar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Auditoría -->
    <div class="modal fade" id="modalAuditoria" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Log de Auditoría</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="auditoria-content"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
let dataTable = null;

function initTable() {
    if (dataTable) {
        dataTable.destroy();
    }
    
    dataTable = $('#comprobantesTable').DataTable({
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.10.25/i18n/Spanish.json'
        },
        pageLength: 25,
        processing: true,
        serverSide: false,
        autoWidth: false,
        columnDefs: [
            { width: '15%', targets: 0 },
            { width: '10%', targets: 1 },
            { width: '12%', targets: 2 },
            { width: '20%', targets: 3 },
            { width: '12%', targets: 4 },
            { width: '12%', targets: 5 },
            { width: '19%', targets: 6 }
        ]
    });
}

function buscarComprobantes() {
    let filters = {
        estudiante: $('#filtro_estudiante').val(),
        fecha_desde: $('#filtro_fecha_desde').val(),
        fecha_hasta: $('#filtro_fecha_hasta').val(),
        tipo: $('#filtro_tipo').val(),
        estado: $('#filtro_estado').val()
    };

    $.ajax({
        url: 'ajax.php?action=listar_comprobantes_historial',
        type: 'GET',
        data: filters,
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                renderizarTabla(resp.comprobantes);
            } else {
                alert_toast(resp.message || 'Error', 'error');
            }
        },
        error: function() {
            alert_toast('Error de conexión', 'error');
        }
    });
}

function renderizarTabla(comprobantes) {
    let data = [];
    
    if (comprobantes && comprobantes.length > 0) {
        comprobantes.forEach(comp => {
            let tipoBadge = comp.tipo_comprobante === '01' ? 
                '<span class="badge badge-success">FACTURA</span>' : 
                '<span class="badge badge-info">BOLETA</span>';
            
            let estadoBadge = '';
            if (comp.estado_sunat === 'aceptado') {
                estadoBadge = '<span class="badge badge-success">Aceptado ✅</span>';
            } else if (comp.estado_sunat === 'rechazado') {
                estadoBadge = '<span class="badge badge-danger">Rechazado ❌</span>';
            } else {
                estadoBadge = '<span class="badge badge-warning">Pendiente ⏳</span>';
            }
            
            let acciones = `
                <button class="btn btn-sm btn-info" onclick="verDetalle(${comp.id})" title="Ver detalles">
                    <i class="fas fa-eye"></i>
                </button>
            `;
            
            if (comp.xml_content) {
                acciones += ` <a href="ajax.php?action=descargar_xml&comprobante_id=${comp.id}" class="btn btn-sm btn-primary" title="Descargar XML">
                    <i class="fas fa-file-code"></i>
                </a>`;
            }
            
            if (comp.cdr_content) {
                acciones += ` <a href="ajax.php?action=descargar_cdr&comprobante_id=${comp.id}" class="btn btn-sm btn-success" title="Descargar CDR">
                    <i class="fas fa-file-archive"></i>
                </a>`;
            }
            
            acciones += ` <button class="btn btn-sm btn-secondary" onclick="verAuditoria(${comp.id})" title="Ver auditoría">
                <i class="fas fa-history"></i>
            </button>`;
            
            if (comp.estado_sunat === 'rechazado') {
                acciones += ` <button class="btn btn-sm btn-warning" onclick="abrirModalReintento(${comp.id})" title="Reintentar">
                    <i class="fas fa-redo"></i>
                </button>`;
                acciones += ` <button class="btn btn-sm btn-danger" onclick="eliminarComprobante(${comp.id})" title="Eliminar (Libera el pago)">
                    <i class="fas fa-trash"></i>
                </button>`;
            }
            
            data.push([
                comp.numero_completo,
                tipoBadge,
                comp.fecha_emision,
                comp.cliente_razon_social,
                'S/ ' + parseFloat(comp.total_precio_venta).toFixed(2),
                estadoBadge,
                acciones
            ]);
        });
    }
    
    // Actualizar tabla
    if (dataTable) {
        dataTable.clear();
        dataTable.rows.add(data);
        dataTable.draw();
    }
}

function limpiarFiltros() {
    $('#filtro_estudiante').val(null).trigger('change');
    $('#filtro_fecha_desde').val('');
    $('#filtro_fecha_hasta').val('');
    $('#filtro_tipo').val('');
    $('#filtro_estado').val('');
    
    if (dataTable) {
        dataTable.clear();
        dataTable.draw();
    }
}

function cargarEstudiantes() {
    $.ajax({
        url: 'ajax.php?action=listar_estudiantes_activos',
        type: 'GET',
        dataType: 'json',
        success: function(resp) {
            console.log('Respuesta de estudiantes:', resp);
            if (Array.isArray(resp) && resp.length > 0) {
                let estudiantes = [
                    { id: '', text: 'Todos los estudiantes' }
                ];
                
                resp.forEach(est => {
                    estudiantes.push({
                        id: est.name,
                        text: est.name + ' (' + est.id_no + ')'
                    });
                });
                
                $('#filtro_estudiante').select2({
                    data: estudiantes,
                    allowClear: true,
                    placeholder: 'Seleccionar estudiante...'
                });
                console.log('Select2 inicializado correctamente');
            } else {
                console.error('Respuesta sin estudiantes:', resp);
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error('Error al cargar estudiantes:', {
                status: jqXHR.status,
                statusText: jqXHR.statusText,
                responseText: jqXHR.responseText,
                textStatus: textStatus,
                errorThrown: errorThrown
            });
        }
    });
}

function abrirModalReintento(comprobanteId) {
    $('#reintento_comprobante_id').val(comprobanteId);
    $('#modalReintentar').modal('show');
}

function confirmarReintento() {
    let comprobanteId = $('#reintento_comprobante_id').val();
    $('#modalReintentar').modal('hide');
    
    alert_toast('Reintentando envío...', 'info');

    $.ajax({
        url: 'ajax.php?action=reintentar_sunat',
        type: 'POST',
        data: { comprobante_id: comprobanteId },
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                alert_toast('✅ Reintento enviado', 'success');
                setTimeout(buscarComprobantes, 2000);
            } else {
                alert_toast(resp.message || 'Error', 'error');
            }
        }
    });
}

function eliminarComprobante(comprobanteId) {
    if (confirm('¿Está seguro de eliminar este comprobante rechazado? Esto liberará la deuda para que pueda ser facturada nuevamente.')) {
        alert_toast('Eliminando...', 'info');
        $.ajax({
            url: 'ajax.php?action=eliminar_comprobante_rechazado',
            type: 'POST',
            data: { comprobante_id: comprobanteId },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    alert_toast('✅ Comprobante eliminado. Puede volver a generarlo.', 'success');
                    buscarComprobantes();
                } else {
                    alert_toast(resp.message || 'Error al eliminar', 'error');
                }
            }
        });
    }
}

function verAuditoria(comprobanteId) {
    $('#modalAuditoria').modal('show');
    
    $.ajax({
        url: 'ajax.php?action=obtener_auditoria',
        type: 'GET',
        data: { comprobante_id: comprobanteId },
        dataType: 'json',
        success: function(resp) {
            if (resp.success && resp.logs && resp.logs.length > 0) {
                let html = '<table class="table table-sm"><thead><tr><th>Fecha</th><th>Operación</th><th>Estado</th></tr></thead><tbody>';
                resp.logs.forEach(log => {
                    html += `<tr><td>${log.created_at}</td><td>${log.operacion}</td><td><span class="badge badge-${log.estado === 'success' ? 'success' : 'danger'}">${log.estado}</span></td></tr>`;
                });
                html += '</tbody></table>';
                $('#auditoria-content').html(html);
            } else {
                $('#auditoria-content').html('<div class="alert alert-info">Sin registros</div>');
            }
        }
    });
}

function verDetalle(comprobanteId) {
    uni_modal('Detalle del Comprobante', 'view_comprobante.php?id=' + comprobanteId, 'large');
}

$(document).ready(function() {
    cargarEstudiantes();
    initTable();
});
</script>

