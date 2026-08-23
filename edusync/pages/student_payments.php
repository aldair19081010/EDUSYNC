<?php
// Verificar que sea estudiante
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$student_dni = $_SESSION['student_dni'];

// Obtener información del estudiante
$student_query = $conn->query("SELECT s.* FROM student s WHERE s.id = $student_id");
$student_data = $student_query->fetch_assoc();
?>
<div class="mb-3 d-flex align-items-center student-profile-bar">
    <div class="avatar-circle mr-3">
        <i class="fas fa-user-graduate"></i>
    </div>
    <div>
        <div class="text-xs text-uppercase text-muted">Estudiante</div>
        <div class="h5 mb-0 font-weight-bold text-primary"><?php echo htmlspecialchars($student_name); ?></div>
        <div class="text-muted small">DNI: <?php echo htmlspecialchars($student_dni); ?></div>
    </div>
</div>

<div class="d-sm-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0 text-gray-800 d-flex align-items-center">
            <i class="fas fa-money-bill-wave mr-2 text-primary"></i>
            Mis Pagos
        </h1>
        <div class="text-muted small">Histórico de pagos y boletas del estudiante</div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4" id="stats-cards">
    <!-- Cards will be loaded via AJAX -->
    <div class="col-12 text-center">
        <i class="fas fa-spinner fa-spin fa-3x text-muted"></i>
    </div>
</div>

<!-- Payments Card -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%); color: white;">
        <h6 class="m-0 font-weight-bold" style="color: white;">
            <i class="fas fa-receipt mr-2"></i>Historial de Pagos
        </h6>
        <div>
            <select id="year-filter" class="form-control form-control-sm" style="width: 200px;">
                <option value="">Todos los años académicos</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="paymentsTable" width="100%">
                <thead>
                    <tr style="background-color: #f8f9fc;">
                        <th>Fecha</th>
                        <th>Concepto</th>
                        <th>Año Académico</th>
                        <th>Monto</th>
                        <th>Recibo</th>
                        <th>Medio de Pago</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="payments-tbody">
                    <tr>
                        <td colspan="6" class="text-center">
                            <i class="fas fa-spinner fa-spin"></i> Cargando pagos...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    .student-profile-bar {
        padding: 12px 14px;
        background: #f8f9fc;
        border: 1px solid #e2e6f0;
        border-radius: 12px;
    }
    .avatar-circle {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        box-shadow: 0 4px 10px rgba(66,133,244,0.25);
    }
    .stat-card {
        border-left: 4px solid #4285f4;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(18,38,63,0.08);
    }
    #paymentsTable thead tr {
        background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%);
        color: white;
    }
    #paymentsTable thead th {
        color: white;
        border-color: #2a75f3;
        font-size: 0.9rem;
    }
    #paymentsTable tbody tr:hover {
        background-color: #f3f7ff;
    }
    #paymentsTable tbody td {
        vertical-align: middle;
    }
    .badge-info {
        background-color: #4285f4;
    }
    .badge-receipt {
        background-color: #f1f3f9;
        color: #3a3b45;
        border: 1px solid #e2e6f0;
    }
    #year-filter {
        min-width: 200px;
    }
</style>

<script>
let allPayments = [];

$(document).ready(function() {
    loadPayments();
});

function loadPayments() {
    $.ajax({
        url: 'api/my_payments.php',
        method: 'GET',
        data: { dni: '<?php echo $student_dni; ?>' },
        dataType: 'json',
        success: function(resp) {
            if (resp.status === 'ok') {
                allPayments = resp.data;
                renderStats(resp);
                renderPaymentsTable(resp.data);
                populateYearFilter(resp.resumen_por_año);
            } else {
                $('#payments-tbody').html(`
                    <tr>
                        <td colspan="7" class="text-center text-danger">
                            <i class="fas fa-exclamation-triangle"></i> ${resp.message}
                        </td>
                    </tr>
                `);
            }
        },
        error: function(err) {
            console.error(err);
            $('#payments-tbody').html(`
                <tr>
                    <td colspan="7" class="text-center text-danger">
                        <i class="fas fa-exclamation-triangle"></i> Error al cargar los pagos
                    </td>
                </tr>
            `);
        }
    });
}

function renderStats(data) {
    let html = `
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card stat-card h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #4285f4;">Total de Pagos</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">${data.total_pagos}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-receipt fa-2x" style="color: #4285f4; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card stat-card h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #4285f4;">Monto Total Pagado</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">S/ ${data.monto_total.toFixed(2)}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-dollar-sign fa-2x" style="color: #4285f4; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card stat-card h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #4285f4;">Años Académicos</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">${data.resumen_por_año.length}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar fa-2x" style="color: #4285f4; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('#stats-cards').html(html);
}

function renderPaymentsTable(payments) {
    if (payments.length === 0) {
        $('#payments-tbody').html(`
            <tr>
                <td colspan="7" class="text-center text-muted">
                    <i class="fas fa-info-circle"></i> No hay pagos registrados
                </td>
            </tr>
        `);
        return;
    }
    
    let html = '';
    payments.forEach(function(payment) {
        let fecha = new Date(payment.fecha).toLocaleDateString('es-PE', {year: 'numeric', month: '2-digit', day: '2-digit'});
        html += `
            <tr>
                <td>${fecha}</td>
                <td><strong>${payment.concepto}</strong></td>
                <td><span class="badge badge-info">${payment.anio_academico}</span></td>
                <td class="text-right"><strong>S/ ${payment.monto.toFixed(2)}</strong></td>
                <td><span class="badge badge-receipt">${payment.recibo}</span></td>
                <td>${payment.medio_pago || '<em class="text-muted">No especificado</em>'}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary view_receipt" data-ef-id="${payment.ef_id}" data-pid="${payment.pid}">
                        <i class="fas fa-file-invoice"></i> Ver recibo
                    </button>
                </td>
            </tr>
        `;
    });
    $('#payments-tbody').html(html);
    
    // Reinicializar DataTable si existe
    if ($.fn.DataTable.isDataTable('#paymentsTable')) {
        $('#paymentsTable').DataTable().destroy();
    }
    
    $('#paymentsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        order: [[0, 'desc']],
        pageLength: 10,
        dom: 'lrtip'
    });
}

function populateYearFilter(years) {
    let options = '<option value="">Todos los años académicos</option>';
    years.forEach(function(year) {
        options += `<option value="${year.año}">${year.año} (${year.total_pagos} pagos)</option>`;
    });
    $('#year-filter').html(options);
}

$('#year-filter').change(function() {
    let selectedYear = $(this).val();
    if (selectedYear === '') {
        renderPaymentsTable(allPayments);
    } else {
        let filtered = allPayments.filter(p => p.anio_academico == selectedYear);
        renderPaymentsTable(filtered);
    }
});

// Evento para abrir recibo en modal
$(document).on('click', '.view_receipt', function(){
    let ef_id = $(this).data('ef-id');
    let pid = $(this).data('pid');
    uni_modal("Recibo de Pago", `receipt.php?ef_id=${ef_id}&pid=${pid}`, "large");
});
</script>
