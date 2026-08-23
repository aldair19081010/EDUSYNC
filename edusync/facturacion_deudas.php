<?php
/**
 * Facturación de Deudas - Sistema de generación de boletas y facturas electrónicas
 * Diseño: Replanteo limpio con flujo: Listar deudas → Generar comprobante → Ver/Descargar PDF
 */

$school_id = $_SESSION['login_school_id'];
?>

<style>
/* Estilos para alertas más visibles */
.toast.bg-warning {
    background-color: #ff9800 !important;
    color: #fff !important;
    font-weight: 600 !important;
    opacity: 1 !important;
}

.toast.bg-warning .toast-body {
    color: #fff !important;
}

.swal2-popup.swal2-toast.swal2-show {
    opacity: 1 !important;
}

/* Modal de advertencia personalizado */
.modal-advertencia .modal-content {
    border-left: 5px solid #ff9800;
}

.modal-advertencia .modal-header {
    background-color: #fff3cd;
    border-bottom: 2px solid #ff9800;
}

.modal-advertencia .modal-body {
    font-size: 15px;
    padding: 25px;
}
</style>

<div class="container-fluid">
    <div class="col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <div class="d-sm-flex align-items-center justify-content-between">
                    <h5 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-file-invoice-dollar"></i> Facturación de Deudas (Comprobantes Electrónicos)
                    </h5>
                    <button class="btn btn-success btn-sm" onclick="abrirGeneracionMasiva()">
                        <i class="fas fa-file-invoice"></i> Generación Masiva de Boletas
                    </button>
                </div>
            </div>
            <div class="card-body">
                <!-- Filtros -->
                <div class="row mb-3">
                    <div class="col-md-2">
                        <label><strong>Estado</strong></label>
                        <select id="filtro_estado" class="form-control form-control-sm">
                            <option value="" selected>Todos</option>
                            <option value="sin_comprobante">Sin Comprobante</option>
                            <option value="con_comprobante">Con Comprobante</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label><strong>Desde</strong></label>
                        <input type="date" id="fecha_desde" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label><strong>Hasta</strong></label>
                        <input type="date" id="fecha_hasta" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary btn-sm btn-block" onclick="cargarDeudas()">
                            <i class="fa fa-search"></i> Buscar
                        </button>
                    </div>
                </div>

                <!-- Tabla de Deudas -->
                <div class="table-responsive">
                    <table id="tablaDeudas" class="table table-striped table-hover table-sm">
                        <thead class="bg-light">
                            <tr>
                                <th class="text-center" style="width:40px">#</th>
                                <th style="width:120px">Estudiante</th>
                                <th style="width:80px">DNI</th>
                                <th style="width:80px">Código</th>
                                <th style="width:100px">Fecha</th>
                                <th style="width:150px">Concepto</th>
                                <th class="text-right" style="width:80px">Monto</th>
                                <th style="width:120px">Comprobante</th>
                                <th class="text-center" style="width:100px">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="listaDeudas">
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="sr-only">Cargando...</span>
                                    </div>
                                    <p class="text-muted mt-2">Cargando deudas...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Generación Masiva de Boletas -->
<div class="modal fade" id="modalGeneracionMasiva" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-file-invoice"></i> Generación Masiva de Boletas Electrónicas</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Filtros para selección masiva -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label><strong>Año Académico</strong></label>
                        <select id="masivo_anio" class="form-control form-control-sm" onchange="cargarConceptosPorAnio()">
                            <option value="">Seleccione año</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label><strong>Nivel</strong></label>
                        <select id="masivo_nivel" class="form-control form-control-sm" onchange="cargarConceptosPorAnio()">
                            <option value="">Todos</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label><strong>Grado</strong></label>
                        <select id="masivo_grado" class="form-control form-control-sm" onchange="cargarConceptosPorAnio()">
                            <option value="">Todos</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label><strong>Concepto</strong></label>
                        <select id="masivo_concepto" class="form-control form-control-sm">
                            <option value="">Todos</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary btn-sm btn-block" onclick="cargarDeudasMasivas()">
                            <i class="fa fa-search"></i> Buscar Deudas
                        </button>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> <strong>Importante:</strong> Aquí se muestran todas las deudas del sistema, independientemente de si tienen pagos registrados o no.
                    Use el filtro "Estado" para ver todas, solo las sin comprobante o las ya con comprobante.
                </div>

                <!-- Tabla de selección -->
                <div class="table-responsive" style="max-height: 400px;">
                    <table id="tablaMasiva" class="table table-sm table-bordered table-hover">
                        <thead class="bg-light sticky-top">
                            <tr>
                                <th class="text-center" style="width:40px">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                                </th>
                                <th>Estudiante</th>
                                <th>DNI Estudiante</th>
                                <th>Tutor</th>
                                <th>Concepto</th>
                                <th class="text-right">Monto</th>
                            </tr>
                        </thead>
                        <tbody id="listaDeudasMasivas">
                            <tr>
                                <td colspan="7" class="text-center py-3 text-muted">
                                    Seleccione filtros y presione "Buscar Deudas"
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    <strong>Deudas seleccionadas: <span id="contadorSeleccionadas" class="badge badge-primary">0</span></strong>
                    <strong class="ml-3">Total: <span id="totalSeleccionado" class="badge badge-success">S/ 0.00</span></strong>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="confirmarGeneracionMasiva()" id="btnGenerarMasivo" disabled>
                    <i class="fas fa-check"></i> Generar Boletas Seleccionadas
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para seleccionar tipo de comprobante -->
<div class="modal fade" id="modalSeleccionarComprobante" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-receipt"></i> Generar Comprobante</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="deuda_id">
                <input type="hidden" id="deuda_monto_original">
                <p class="mb-3"><strong>¿Qué tipo de comprobante desea generar?</strong></p>
                <button type="button" class="btn btn-outline-primary btn-block mb-2" onclick="irAGenerarBoleta()">
                    <i class="fas fa-file-invoice"></i> Boleta Electrónica (DNI Tutor)
                </button>
                <button type="button" class="btn btn-outline-success btn-block" onclick="irAGenerarFactura()">
                    <i class="fas fa-file-contract"></i> Factura Electrónica (RUC)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para generar BOLETA (con datos del tutor) -->
<div class="modal fade" id="modalBoleta" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-file-invoice"></i> Generar Boleta Electrónica</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <input type="hidden" id="boleta_deuda_id">
                
                <!-- Monto a facturar -->
                <div class="form-group">
                    <label><strong>Monto a Facturar (S/) <span class="text-danger">*</span></strong></label>
                    <input type="number" step="0.01" min="0.01" id="boleta_monto_facturar" class="form-control" required>
                    <small class="text-muted">Puede modificar este monto antes de generar la boleta.</small>
                </div>

                <!-- Mostrar datos del tutor (read-only) -->
                <div class="alert alert-info" id="info_tutor">
                    <small><strong>Receptor (Tutor del estudiante):</strong></small>
                    <p class="mb-1">
                        <strong>DNI:</strong> <span id="boleta_tutor_dni"></span>
                    </p>
                    <p class="mb-1">
                        <strong>Nombre:</strong> <span id="boleta_tutor_nombre"></span>
                    </p>
                    <p class="mb-0">
                        <strong>Dirección:</strong> <span id="boleta_tutor_direccion"></span>
                    </p>
                </div>

                <!-- Opción para emitir a otro receptor -->
                <div class="form-group">
                    <label class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="usar_otro_receptor" onchange="toggleOtroReceptor()">
                        <span class="custom-control-label">
                            <i class="fas fa-user-edit"></i> Emitir a otro receptor (DNI diferente)
                        </span>
                    </label>
                </div>

                <!-- Campos para otro receptor (ocultos por defecto) -->
                <div id="campos_otro_receptor" style="display: none;">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Importante:</strong> La boleta se emitirá al DNI y nombre que ingrese a continuación.
                    </div>
                    
                    <div class="form-group">
                        <label><strong>DNI del Receptor <span class="text-danger">*</span></strong></label>
                        <input type="text" id="boleta_otro_dni" class="form-control" placeholder="8 dígitos" maxlength="8" pattern="[0-9]{8}">
                    </div>
                    
                    <div class="form-group">
                        <label><strong>Nombre Completo <span class="text-danger">*</span></strong></label>
                        <input type="text" id="boleta_otro_nombre" class="form-control" placeholder="Nombres y apellidos completos">
                    </div>
                    
                    <div class="form-group">
                        <label><strong>Dirección</strong></label>
                        <input type="text" id="boleta_otro_direccion" class="form-control" placeholder="Dirección (opcional)">
                    </div>
                </div>

                <!-- Confirmación -->
                <div class="form-group mt-3">
                    <label class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="confirmar_boleta">
                        <span class="custom-control-label">
                            <span id="texto_confirmacion">Confirmo que esta boleta será emitida a nombre del tutor</span>
                        </span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="confirmarGenerarBoleta()">
                    <i class="fas fa-check"></i> Generar Boleta
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para generar FACTURA (con RUC) -->
<div class="modal fade" id="modalFactura" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-file-contract"></i> Generar Factura Electrónica</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="factura_deuda_id">
                
                <div class="form-group">
                    <label><strong>Monto a Facturar (S/) <span class="text-danger">*</span></strong></label>
                    <input type="number" step="0.01" min="0.01" id="factura_monto_facturar" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label><strong>RUC <span class="text-danger">*</span></strong></label>
                    <input type="text" id="factura_ruc" class="form-control" placeholder="11 dígitos" maxlength="11" pattern="[0-9]{11}">
                    <small class="text-muted">Ingrese 11 dígitos numéricos</small>
                </div>

                <div class="form-group">
                    <label><strong>Razón Social <span class="text-danger">*</span></strong></label>
                    <input type="text" id="factura_razon_social" class="form-control" placeholder="Nombre de la empresa">
                </div>

                <div class="form-group">
                    <label><strong>Dirección <span class="text-danger">*</span></strong></label>
                    <textarea id="factura_direccion" class="form-control" rows="2" placeholder="Dirección fiscal"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="confirmarGenerarFactura()">
                    <i class="fas fa-check"></i> Generar Factura
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para validar DNI/Nombre antes de generar boleta -->
<div class="modal fade" id="modalValidarDNI" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Validación de Datos</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong>⚠️ Importante:</strong> Antes de generar la boleta, verifique que los datos coincidan exactamente con el documento.
                </div>
                
                <div class="form-group">
                    <label><strong>DNI en el sistema:</strong></label>
                    <input type="text" id="validar_dni_sistema" class="form-control" readonly style="background-color: #f8f9fa;">
                </div>

                <div class="form-group">
                    <label><strong>Nombre en el sistema:</strong></label>
                    <input type="text" id="validar_nombre_sistema" class="form-control" readonly style="background-color: #f8f9fa;">
                </div>

                <hr>

                <div class="form-group">
                    <label><strong>DNI en el documento (físico):</strong> <span class="text-danger">*</span></label>
                    <input type="text" id="validar_dni_documento" class="form-control" placeholder="Ingrese el DNI del documento" maxlength="8" pattern="[0-9]{8}">
                </div>

                <div class="form-group">
                    <label><strong>Nombre en el documento (físico):</strong> <span class="text-danger">*</span></label>
                    <input type="text" id="validar_nombre_documento" class="form-control" placeholder="Ingrese el nombre exacto del documento" style="text-transform: uppercase;">
                </div>

                <div class="alert alert-info mt-3">
                    <small><strong>💡 Nota:</strong> Los datos deben coincidir exactamente para que SUNAT apruebe la boleta. Si no coinciden, edite los datos en el sistema primero.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" onclick="validarYGenerarBoleta()">
                    <i class="fas fa-check"></i> Validar y Generar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ==== CARGAR DEUDAS ====
function cargarDeudas() {
    let estado = $('#filtro_estado').val();
    let fecha_desde = $('#fecha_desde').val();
    let fecha_hasta = $('#fecha_hasta').val();

    $.ajax({
        url: 'ajax.php?action=listar_deudas_facturacion',
        type: 'GET',
        data: {
            estado: estado,
            fecha_desde: fecha_desde,
            fecha_hasta: fecha_hasta
        },
        dataType: 'json',
        success: function(resp) {
            if (resp && resp.success) {
                renderDeudas(resp.deudas);
            } else {
                alert_toast('Error al cargar deudas: ' + (resp.message || 'Error desconocido'), 'error');
            }
        },
        error: function(err) {
            console.error(err);
            alert_toast('Error al cargar deudas', 'error');
        }
    });
}

// ==== RENDERIZAR TABLA DE DEUDAS ====
function renderDeudas(deudas) {
    // Destruir DataTable si existe
    if ($.fn.DataTable.isDataTable('#tablaDeudas')) {
        $('#tablaDeudas').DataTable().destroy();
    }

    let html = '';

    if (!deudas || deudas.length === 0) {
        html = '<tr><td colspan="9" class="text-center text-muted py-3">No hay deudas para mostrar</td></tr>';
        $('#listaDeudas').html(html);
        return;
    }

    deudas.forEach(function(deuda, index) {
        let comprobante = '';
        let botones = '';

        if (deuda.comprobante_id) {
            // Ya tiene comprobante
            let tipo = deuda.tipo_comprobante === '01' ? '📋 Factura' : '🧾 Boleta';
            let estado = deuda.estado_sunat === 'aceptado' ? '✅' : (deuda.estado_sunat === 'rechazado' ? '❌' : '⏳');
            comprobante = `<span class="badge badge-info">${tipo} ${estado}</span><br><small>${deuda.numero_completo}</small>`;
            
            botones = `
                <button class="btn btn-sm btn-danger" onclick="descargarPDF(${deuda.comprobante_id})" title="PDF">
                    <i class="fas fa-file-pdf"></i>
                </button>
                <button class="btn btn-sm btn-success" onclick="descargarCDR(${deuda.comprobante_id})" title="CDR">
                    <i class="fas fa-file-archive"></i>
                </button>
                <button class="btn btn-sm btn-primary" onclick="descargarXML(${deuda.comprobante_id})" title="XML">
                    <i class="fas fa-file-code"></i>
                </button>
            `;
        } else {
            // Sin comprobante
            comprobante = '<span class="badge badge-secondary">Sin generar</span>';
            botones = `
                <button class="btn btn-sm btn-primary" onclick="seleccionarTipoComprobante(${deuda.ef_id}, ${deuda.amount})" title="Generar">
                    <i class="fas fa-plus"></i> Generar
                </button>
            `;
        }

        html += `
            <tr>
                <td class="text-center">${index + 1}</td>
                <td><small>${deuda.student_name}</small></td>
                <td><small>${deuda.student_dni}</small></td>
                <td><small>DEU-${String(deuda.ef_id).padStart(6, '0')}</small></td>
                <td><small>${deuda.fecha_deuda}</small></td>
                <td><small>${deuda.concepto}</small></td>
                <td class="text-right"><small><strong>S/ ${parseFloat(deuda.amount).toFixed(2)}</strong></small></td>
                <td><small>${comprobante}</small></td>
                <td class="text-center">${botones}</td>
            </tr>
        `;
    });

    $('#listaDeudas').html(html);

    // Inicializar DataTable
    $('#tablaDeudas').DataTable({
        "ordering": true,
        "searching": true,
        "paging": true,
        "pageLength": 25,
        "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Todos"]],
        "language": {
            "lengthMenu": "Mostrar _MENU_ registros",
            "zeroRecords": "No se encontraron resultados",
            "info": "Mostrando _START_ a _END_ de _TOTAL_ registros",
            "infoEmpty": "Mostrando 0 a 0 de 0 registros",
            "infoFiltered": "(filtrado de _MAX_ registros totales)",
            "search": "Buscar:",
            "paginate": {
                "first": "Primero",
                "last": "Último",
                "next": "Siguiente",
                "previous": "Anterior"
            }
        },
        "order": [[4, 'desc']], // Ordenar por fecha (columna 4) descendente
        "columnDefs": [
            { "orderable": false, "targets": [8] } // Deshabilitar orden en columna Acciones
        ]
    });
}

// ==== SELECCIONAR TIPO DE COMPROBANTE ====
function seleccionarTipoComprobante(ef_id, amount) {
    $('#deuda_id').val(ef_id);
    $('#deuda_monto_original').val(amount);
    $('#modalSeleccionarComprobante').modal('show');
}

// ==== IR A GENERAR BOLETA ====
function irAGenerarBoleta() {
    $('#modalSeleccionarComprobante').modal('hide');
    let ef_id = $('#deuda_id').val();
    
    // Obtener datos del tutor
    $.ajax({
        url: 'ajax.php?action=obtener_datos_tutor',
        type: 'GET',
        data: { ef_id: ef_id },
        dataType: 'json',
        success: function(resp) {
            if (resp && resp.success) {
                let amount = parseFloat($('#deuda_monto_original').val() || 0).toFixed(2);
                $('#boleta_deuda_id').val(ef_id);
                $('#boleta_monto_facturar').val(amount);
                $('#boleta_tutor_dni').text(resp.tutor_dni || '---');
                $('#boleta_tutor_nombre').text(resp.tutor_nombre || '---');
                $('#boleta_tutor_direccion').text(resp.tutor_direccion || '---');
                
                // Resetear opciones
                $('#usar_otro_receptor').prop('checked', false);
                $('#campos_otro_receptor').hide();
                $('#info_tutor').removeClass('alert-secondary').addClass('alert-info');
                $('#texto_confirmacion').text('Confirmo que esta boleta será emitida a nombre del tutor');
                $('#boleta_otro_dni').val('');
                $('#boleta_otro_nombre').val('');
                $('#boleta_otro_direccion').val('');
                $('#confirmar_boleta').prop('checked', false);
                
                $('#modalBoleta').modal('show');
            } else {
                // Si hay error, mostrarlo con modal visible
                if (resp.message && resp.message.includes('tutor') || resp.message.includes('apoderado')) {
                    mostrarAdvertencia(resp.message || 'Error al obtener datos del tutor');
                } else {
                    alert_toast(resp.message || 'Error al obtener datos del tutor', 'error');
                }
            }
        },
        error: function(err) {
            console.error(err);
            alert_toast('Error al obtener datos del tutor', 'error');
        }
    });
}

// ==== TOGGLE OTRO RECEPTOR ====
function toggleOtroReceptor() {
    let checked = $('#usar_otro_receptor').is(':checked');
    
    if (checked) {
        $('#campos_otro_receptor').slideDown();
        $('#info_tutor').removeClass('alert-info').addClass('alert-secondary');
        $('#texto_confirmacion').text('Confirmo los datos ingresados y que esta boleta será emitida al receptor especificado');
    } else {
        $('#campos_otro_receptor').slideUp();
        $('#info_tutor').removeClass('alert-secondary').addClass('alert-info');
        $('#texto_confirmacion').text('Confirmo que esta boleta será emitida a nombre del tutor');
        // Limpiar campos
        $('#boleta_otro_dni').val('');
        $('#boleta_otro_nombre').val('');
        $('#boleta_otro_direccion').val('');
    }
    
    // Desmarcar confirmación al cambiar
    $('#confirmar_boleta').prop('checked', false);
}

// ==== CONFIRMAR Y GENERAR BOLETA ====
function confirmarGenerarBoleta() {
    if (!$('#confirmar_boleta').is(':checked')) {
        alert_toast('Debe confirmar para continuar', 'warning');
        return;
    }

    let ef_id = $('#boleta_deuda_id').val();
    let monto = $('#boleta_monto_facturar').val();
    
    if (!monto || parseFloat(monto) <= 0) {
        alert_toast('El monto a facturar debe ser mayor a 0', 'warning');
        return;
    }

    let data = { ef_id: ef_id, monto: monto };
    
    // Si se usa otro receptor, validar y agregar datos
    if ($('#usar_otro_receptor').is(':checked')) {
        let otro_dni = $('#boleta_otro_dni').val().trim();
        let otro_nombre = $('#boleta_otro_nombre').val().trim();
        let otro_direccion = $('#boleta_otro_direccion').val().trim();
        
        // Validaciones
        if (!otro_dni || !/^\d{8}$/.test(otro_dni)) {
            alert_toast('El DNI debe tener 8 dígitos numéricos', 'warning');
            return;
        }
        if (!otro_nombre || otro_nombre.length < 3) {
            alert_toast('El nombre completo es requerido (mínimo 3 caracteres)', 'warning');
            return;
        }
        
        // Agregar datos del receptor alternativo
        data.receptor_dni = otro_dni;
        data.receptor_nombre = otro_nombre;
        data.receptor_direccion = otro_direccion || '-';
    }
    
    $('#modalBoleta').modal('hide');

    alert_toast('Generando boleta y enviando a SUNAT...', 'info');

    $.ajax({
        url: 'ajax.php?action=generar_boleta_deuda',
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(resp) {
            if (resp && resp.status === 1) {
                alert_toast('✅ Boleta generada: ' + resp.numero, 'success');
                setTimeout(cargarDeudas, 2000);
            } else if (resp && resp.validation_error) {
                // Mostrar modal de advertencia más visible para errores de validación
                mostrarAdvertencia(resp.message);
            } else {
                alert_toast(resp.message || 'Error al generar boleta', 'error');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error("Respuesta cruda del servidor:", jqXHR.responseText);
            alert_toast('Error HTTP: ' + textStatus + ' - ' + errorThrown + '. Revisa la consola (F12) para más detalles.', 'error');
        }
    });
}


// ============================================================
// GENERACIÓN MASIVA DE BOLETAS
// ============================================================

function abrirGeneracionMasiva() {
    $('#modalGeneracionMasiva').modal('show');
    cargarFiltrosMasivos();
}

function cargarFiltrosMasivos() {
    // Cargar años académicos
    $.ajax({
        url: 'ajax.php?action=obtener_anios_academicos',
        type: 'GET',
        dataType: 'json',
        success: function(resp) {
            console.log('Años académicos recibidos:', resp);
            if (resp && resp.success) {
                let html = '<option value="">Seleccione año</option>';
                resp.anios.forEach(a => {
                    let label = a.year;
                    if (a.is_active == 1) {
                        label += ' (Activo)';
                    }
                    html += `<option value="${a.id}" ${a.is_active == 1 ? 'selected' : ''}>${label}</option>`;
                });
                $('#masivo_anio').html(html);
                
                // Cargar conceptos del año seleccionado automáticamente
                if (resp.anios.length > 0) {
                    cargarConceptosPorAnio();
                }
            } else {
                console.error('Error en respuesta de años:', resp);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error al cargar años académicos:', error);
            alert_toast('Error al cargar años académicos', 'error');
        }
    });

    // Cargar niveles
    $.ajax({
        url: 'ajax.php?action=obtener_niveles',
        type: 'GET',
        dataType: 'json',
        success: function(resp) {
            console.log('Niveles recibidos:', resp);
            if (resp && resp.success) {
                let html = '<option value="">Todos</option>';
                resp.niveles.forEach(n => {
                    html += `<option value="${n}">${n}</option>`;
                });
                $('#masivo_nivel').html(html);
            } else {
                console.error('Error en respuesta de niveles:', resp);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error al cargar niveles:', error);
            alert_toast('Error al cargar niveles', 'error');
        }
    });

    // Cargar grados
    $.ajax({
        url: 'ajax.php?action=obtener_grados',
        type: 'GET',
        dataType: 'json',
        success: function(resp) {
            console.log('Grados recibidos:', resp);
            if (resp && resp.success) {
                let html = '<option value="">Todos</option>';
                resp.grados.forEach(g => {
                    html += `<option value="${g}">${g}</option>`;
                });
                $('#masivo_grado').html(html);
            } else {
                console.error('Error en respuesta de grados:', resp);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error al cargar grados:', error);
            alert_toast('Error al cargar grados', 'error');
        }
    });

}

function cargarConceptosPorAnio() {
    let academic_year_id = $('#masivo_anio').val();
    let nivel = $('#masivo_nivel').val();
    let grado = $('#masivo_grado').val();
    
    if (!academic_year_id) {
        $('#masivo_concepto').html('<option value="">Seleccione primero un año</option>');
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=obtener_conceptos',
        type: 'GET',
        data: { 
            academic_year_id: academic_year_id,
            nivel: nivel,
            grado: grado
        },
        dataType: 'json',
        success: function(resp) {
            console.log('Conceptos recibidos:', resp);
            if (resp && resp.success) {
                let html = '<option value="">Todos</option>';
                resp.conceptos.forEach(c => {
                    html += `<option value="${c.id}">${c.nombre}</option>`;
                });
                $('#masivo_concepto').html(html);
            } else {
                console.error('Error en respuesta de conceptos:', resp);
                $('#masivo_concepto').html('<option value="">Error al cargar</option>');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error al cargar conceptos:', error);
            $('#masivo_concepto').html('<option value="">Error al cargar</option>');
            alert_toast('Error al cargar conceptos', 'error');
        }
    });
}

function cargarDeudasMasivas() {
    let nivel = $('#masivo_nivel').val();
    let grado = $('#masivo_grado').val();
    let concepto = $('#masivo_concepto').val();

    $.ajax({
        url: 'ajax.php?action=listar_deudas_masivas',
        type: 'GET',
        data: {
            nivel: nivel,
            grado: grado,
            concepto: concepto
        },
        dataType: 'json',
        beforeSend: function() {
            $('#listaDeudasMasivas').html('<tr><td colspan="6" class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Cargando...</td></tr>');
        },
        success: function(resp) {
            if (resp && resp.success) {
                console.log('Deudas cargadas:', resp.deudas); // Debug
                renderDeudasMasivas(resp.deudas);
            } else {
                $('#listaDeudasMasivas').html('<tr><td colspan="6" class="text-center py-3 text-danger">' + (resp.message || 'Error al cargar') + '</td></tr>');
            }
        },
        error: function() {
            $('#listaDeudasMasivas').html('<tr><td colspan="6" class="text-center py-3 text-danger">Error de conexión</td></tr>');
        }
    });
}

function renderDeudasMasivas(deudas) {
    let html = '';

    if (!deudas || deudas.length === 0) {
        html = '<tr><td colspan="6" class="text-center py-3 text-muted">No se encontraron deudas sin comprobante</td></tr>';
        $('#listaDeudasMasivas').html(html);
        $('#btnGenerarMasivo').prop('disabled', true);
        return;
    }

    deudas.forEach(d => {
        // Determinar si hay dos tutores
        let tieneDosTutores = (d.tutor2_dni && d.tutor2_dni.trim() !== '') || (d.tutor2_nombre && d.tutor2_nombre.trim() !== '');
        
        let tutorTexto = `<strong>${d.tutor1_nombre}</strong> <span class="badge badge-success">Seleccionado</span>`;
        
        if (tieneDosTutores) {
            tutorTexto = `<div>
                            <div class="mb-2"><strong>${d.tutor1_nombre}</strong> <span class="badge badge-success">Seleccionado</span></div>
                            <div><small class="text-muted">${d.tutor2_nombre}</small></div>
                            <button type="button" class="btn btn-xs btn-warning mt-1" onclick="seleccionarTutor('${d.ef_id}', '${d.tutor1_dni}', '${d.tutor1_nombre.replace(/'/g, "\\'")}', '${d.tutor2_dni || ''}', '${d.tutor2_nombre.replace(/'/g, "\\'") || ''}')">
                                <i class="fas fa-exchange-alt"></i> Cambiar
                            </button>
                         </div>`;
        }

        html += `
            <tr>
                <td class="text-center">
                    <input type="checkbox" class="deuda-check" value="${d.ef_id}" data-monto="${d.amount}" data-tutor1-dni="${d.tutor1_dni}" data-tutor1-nombre="${d.tutor1_nombre}" data-tutor1-dir="${d.tutor1_direccion || ''}">
                </td>
                <td><small>${d.student_name}</small></td>
                <td><small>${d.student_dni}</small></td>
                <td id="tutor-${d.ef_id}">
                    <small>${tutorTexto}</small>
                    <input type="hidden" id="tutor-dni-${d.ef_id}" value="${d.tutor1_dni}">
                    <input type="hidden" id="tutor-nombre-${d.ef_id}" value="${d.tutor1_nombre}">
                    <input type="hidden" id="tutor-dir-${d.ef_id}" value="${d.tutor1_direccion || ''}">
                </td>
                <td><small>${d.concepto}</small></td>
                <td class="text-right"><small><strong>S/ ${parseFloat(d.amount).toFixed(2)}</strong></small></td>
            </tr>
        `;
    });

    $('#listaDeudasMasivas').html(html);

    // Event listeners para checkboxes
    $('.deuda-check').on('change', actualizarContadores);
    $('#selectAll').prop('checked', false);
}

function toggleSelectAll() {
    let checked = $('#selectAll').is(':checked');
    $('.deuda-check').prop('checked', checked);
    actualizarContadores();
}

function actualizarContadores() {
    let seleccionadas = $('.deuda-check:checked').length;
    let total = 0;

    $('.deuda-check:checked').each(function() {
        total += parseFloat($(this).data('monto'));
    });

    $('#contadorSeleccionadas').text(seleccionadas);
    $('#totalSeleccionado').text('S/ ' + total.toFixed(2));
    $('#btnGenerarMasivo').prop('disabled', seleccionadas === 0);
}

function confirmarGeneracionMasiva() {
    let seleccionadas = [];
    $('.deuda-check:checked').each(function() {
        seleccionadas.push($(this).val());
    });

    if (seleccionadas.length === 0) {
        alert_toast('Debe seleccionar al menos una deuda', 'warning');
        return;
    }

    if (!confirm(`¿Está seguro de generar ${seleccionadas.length} boleta(s) electrónica(s)?\n\nEste proceso puede tardar varios minutos.`)) {
        return;
    }

    $('#modalGeneracionMasiva').modal('hide');
    
    // Mostrar modal de progreso
    mostrarProgresoMasivo(seleccionadas);
}

function mostrarProgresoMasivo(ids) {
    let total = ids.length;
    let procesadas = 0;
    let exitosas = 0;
    let fallidas = 0;
    let errores = [];

    // Crear modal de progreso
    let modalProgreso = `
        <div class="modal fade" id="modalProgreso" data-backdrop="static" data-keyboard="false" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-cog fa-spin"></i> Generando Boletas Masivas</h5>
                    </div>
                    <div class="modal-body">
                        <p><strong>Progreso: <span id="progreso">0</span> / ${total}</strong></p>
                        <div class="progress mb-3">
                            <div id="barraProgreso" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                        </div>
                        <div id="logProgreso" style="max-height: 200px; overflow-y: auto; font-size: 12px; background: #f8f9fa; padding: 10px; border-radius: 4px;">
                            <div class="text-muted">Iniciando proceso...</div>
                        </div>
                        <div class="mt-3">
                            <span class="badge badge-success mr-2">Exitosas: <span id="exitosas">0</span></span>
                            <span class="badge badge-danger">Fallidas: <span id="fallidas">0</span></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="btnCerrarProgreso" disabled>Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    $('body').append(modalProgreso);
    $('#modalProgreso').modal('show');

    // Procesar una por una
    procesarBoletaMasiva(ids, 0, total, exitosas, fallidas, errores);
}

function procesarBoletaMasiva(ids, index, total, exitosas, fallidas, errores) {
    if (index >= total) {
        // Terminar
        finalizarProcesoMasivo(exitosas, fallidas, errores);
        return;
    }

    let ef_id = ids[index];
    let porcentaje = Math.round(((index + 1) / total) * 100);

    $('#progreso').text(index + 1);
    $('#barraProgreso').css('width', porcentaje + '%');
    $('#logProgreso').append(`<div class="text-info">⏳ Procesando deuda ${index + 1}...</div>`);

    // Obtener datos del tutor seleccionado para esta deuda
    let tutorDni = $(`#tutor-dni-${ef_id}`).val();
    let tutorNombre = $(`#tutor-nombre-${ef_id}`).val();
    let tutorDir = $(`#tutor-dir-${ef_id}`).val();

    $.ajax({
        url: 'ajax.php?action=generar_boleta_deuda',
        type: 'POST',
        data: { 
            ef_id: ef_id,
            receptor_dni: tutorDni,
            receptor_nombre: tutorNombre,
            receptor_direccion: tutorDir
        },
        dataType: 'json',
        success: function(resp) {
            if (resp && resp.status === 1) {
                exitosas++;
                $('#exitosas').text(exitosas);
                $('#logProgreso').append(`<div class="text-success">✅ ${resp.numero} - Generada correctamente</div>`);
            } else {
                fallidas++;
                $('#fallidas').text(fallidas);
                errores.push({ ef_id: ef_id, error: resp.message || 'Error desconocido' });
                $('#logProgreso').append(`<div class="text-danger">❌ Deuda ${ef_id} - ${resp.message || 'Error'}</div>`);
            }
        },
        error: function() {
            fallidas++;
            $('#fallidas').text(fallidas);
            errores.push({ ef_id: ef_id, error: 'Error de conexión' });
            $('#logProgreso').append(`<div class="text-danger">❌ Deuda ${ef_id} - Error de conexión</div>`);
        },
        complete: function() {
            // Scroll al final del log
            $('#logProgreso').scrollTop($('#logProgreso')[0].scrollHeight);
            
            // Procesar siguiente
            setTimeout(function() {
                procesarBoletaMasiva(ids, index + 1, total, exitosas, fallidas, errores);
            }, 1000); // Esperar 1 segundo entre cada generación
        }
    });
}

function finalizarProcesoMasivo(exitosas, fallidas, errores) {
    $('#barraProgreso').removeClass('progress-bar-animated').addClass('bg-success');
    $('#logProgreso').append(`<div class="text-success mt-2"><strong>✅ Proceso completado</strong></div>`);
    $('#btnCerrarProgreso').prop('disabled', false);

    $('#btnCerrarProgreso').off('click').on('click', function() {
        // Cerrar y limpiar modal de progreso
        $('#modalProgreso').modal('hide');
        
        // Esperar a que se cierre completamente y luego eliminar
        $('#modalProgreso').on('hidden.bs.modal', function() {
            $(this).remove();
            // Limpiar cualquier backdrop que quede
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open');
            $('body').css('padding-right', '');
        });
        
        // Recargar tabla principal
        cargarDeudas();
    });

    alert_toast(`Proceso completado: ${exitosas} exitosas, ${fallidas} fallidas`, exitosas > 0 ? 'success' : 'warning');
}
// ==== IR A GENERAR FACTURA ====
function irAGenerarFactura() {
    $('#modalSeleccionarComprobante').modal('hide');
    let ef_id = $('#deuda_id').val();
    let amount = parseFloat($('#deuda_monto_original').val() || 0).toFixed(2);
    $('#factura_deuda_id').val(ef_id);
    $('#factura_monto_facturar').val(amount);
    $('#factura_ruc').val('');
    $('#factura_razon_social').val('');
    $('#factura_direccion').val('');
    $('#modalFactura').modal('show');
}

// ==== CONFIRMAR Y GENERAR FACTURA ====
function confirmarGenerarFactura() {
    let ef_id = $('#factura_deuda_id').val();
    let monto = $('#factura_monto_facturar').val();
    let ruc = $('#factura_ruc').val().trim();
    let razon_social = $('#factura_razon_social').val().trim();
    let direccion = $('#factura_direccion').val().trim();

    // Validaciones
    if (!monto || parseFloat(monto) <= 0) {
        alert_toast('El monto a facturar debe ser mayor a 0', 'warning');
        return;
    }
    if (!ruc || !/^\d{11}$/.test(ruc)) {
        alert_toast('El RUC debe tener 11 dígitos numéricos', 'warning');
        return;
    }
    if (!razon_social) {
        alert_toast('La Razón Social es requerida', 'warning');
        return;
    }
    if (!direccion) {
        alert_toast('La Dirección es requerida', 'warning');
        return;
    }

    $('#modalFactura').modal('hide');

    alert_toast('Generando factura y enviando a SUNAT...', 'info');

    $.ajax({
        url: 'ajax.php?action=generar_factura_deuda',
        type: 'POST',
        data: {
            ef_id: ef_id,
            monto: monto,
            ruc: ruc,
            razon_social: razon_social,
            direccion: direccion
        },
        dataType: 'json',
        success: function(resp) {
            if (resp && resp.status === 1) {
                alert_toast('✅ Factura generada: ' + resp.numero, 'success');
                setTimeout(cargarDeudas, 2000);
            } else if (resp && resp.validation_error) {
                alert_toast(resp.message, 'warning');
            } else {
                alert_toast(resp.message || 'Error al generar factura', 'error');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error("Respuesta cruda del servidor:", jqXHR.responseText);
            alert_toast('Error HTTP: ' + textStatus + ' - ' + errorThrown + '. Revisa la consola (F12) para más detalles.', 'error');
        }
    });
}

// ==== DESCARGAR PDF ====
function descargarPDF(comprobante_id) {
    window.open('ajax.php?action=descargar_pdf&comprobante_id=' + comprobante_id, '_blank');
}

// ==== DESCARGAR CDR ====
function descargarCDR(comprobante_id) {
    window.location.href = 'ajax.php?action=descargar_cdr&comprobante_id=' + comprobante_id;
}

// ==== DESCARGAR XML ====
function descargarXML(comprobante_id) {
    window.location.href = 'ajax.php?action=descargar_xml&comprobante_id=' + comprobante_id;
}

// ==== MOSTRAR ADVERTENCIA VISIBLE ====
function mostrarAdvertencia(mensaje) {
    // Crear modal de advertencia con estilo destacado
    let modalHtml = `
        <div class="modal fade" id="modalAdvertencia" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-dialog-centered modal-advertencia" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-exclamation-triangle text-warning" style="font-size: 24px;"></i>
                            <strong>Advertencia</strong>
                        </h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning" style="background-color: #fff3cd; border-left: 4px solid #ff9800; font-size: 15px;">
                            <i class="fas fa-info-circle"></i> ${mensaje}
                        </div>
                        <p class="mb-0"><strong>Sugerencia:</strong> Puede usar la opción <em>"Emitir a otro receptor"</em> para generar el comprobante con los datos de otra persona.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-dismiss="modal">Entendido</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remover modal anterior si existe
    $('#modalAdvertencia').remove();
    
    // Agregar y mostrar
    $('body').append(modalHtml);
    $('#modalAdvertencia').modal('show');
    
    // Eliminar del DOM después de cerrar
    $('#modalAdvertencia').on('hidden.bs.modal', function() {
        $(this).remove();
    });
}

// ==== SELECCIONAR TUTOR (cuando hay dos) ====
function seleccionarTutor(efId, tutor1Dni, tutor1Nombre, tutor2Dni, tutor2Nombre) {
    let modalTutor = `
        <div class="modal fade" id="modalSeleccionarTutor" data-backdrop="static" data-keyboard="false" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-user-check"></i> Seleccionar Tutor</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3"><strong>Este estudiante tiene dos tutores. ¿Cuál desea usar?</strong></p>
                        <div class="list-group">
                            <button type="button" class="list-group-item list-group-item-action text-left" onclick="confirmarTutor('${efId}', '${tutor1Dni}', '${tutor1Nombre.replace(/'/g, "\\'")}')">
                                <h6 class="mb-1"><i class="fas fa-user"></i> Tutor 1</h6>
                                <p class="mb-0"><small><strong>Nombre:</strong> ${tutor1Nombre}</small></p>
                                <p class="mb-0"><small><strong>DNI:</strong> ${tutor1Dni}</small></p>
                            </button>
                            <button type="button" class="list-group-item list-group-item-action text-left" onclick="confirmarTutor('${efId}', '${tutor2Dni}', '${tutor2Nombre.replace(/'/g, "\\'")}')">
                                <h6 class="mb-1"><i class="fas fa-user"></i> Tutor 2</h6>
                                <p class="mb-0"><small><strong>Nombre:</strong> ${tutor2Nombre}</small></p>
                                <p class="mb-0"><small><strong>DNI:</strong> ${tutor2Dni}</small></p>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    $('#modalSeleccionarTutor').remove();
    $('body').append(modalTutor);
    $('#modalSeleccionarTutor').modal('show');
    
    $('#modalSeleccionarTutor').on('hidden.bs.modal', function() {
        $(this).remove();
    });
}

function confirmarTutor(efId, tutorDni, tutorNombre) {
    // Actualizar los campos ocultos con el tutor seleccionado
    $(`#tutor-dni-${efId}`).val(tutorDni);
    $(`#tutor-nombre-${efId}`).val(tutorNombre);
    $(`#tutor-${efId}`).html(`<strong>${tutorNombre}</strong> <span class="badge badge-success">✓</span>`);
    
    // Actualizar el checkbox con los nuevos datos
    $(`.deuda-check[value="${efId}"]`).attr('data-tutor1-dni', tutorDni).attr('data-tutor1-nombre', tutorNombre);
    
    $('#modalSeleccionarTutor').modal('hide');
    alert_toast('Tutor seleccionado correctamente', 'success');
}

// ==== INICIALIZAR ====
$(document).ready(function() {
    cargarDeudas();
});
</script>
