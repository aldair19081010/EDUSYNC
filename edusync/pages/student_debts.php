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
            <i class="fas fa-file-invoice-dollar mr-2 text-primary"></i>
            Mis Deudas
        </h1>
        <div class="text-muted small">Estado de cuentas y conceptos pendientes de pago</div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4" id="stats-cards">
    <!-- Cards will be loaded via AJAX -->
    <div class="col-12 text-center">
        <i class="fas fa-spinner fa-spin fa-3x text-muted"></i>
    </div>
</div>

<!-- Debts Card -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%); color: white;">
        <h6 class="m-0 font-weight-bold" style="color: white;">
            <i class="fas fa-list-ul mr-2"></i>Deudas Pendientes
        </h6>
        <div>
            <select id="year-filter" class="form-control form-control-sm" style="width: 200px;">
                <option value="">Todos los años académicos</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="debtsTable" width="100%">
                <thead>
                    <tr style="background-color: #f8f9fc;">
                        <th>Concepto</th>
                        <th>Año Académico</th>
                        <th>Total</th>
                        <th>Descuento</th>
                        <th>A Pagar</th>
                        <th>Pagado</th>
                        <th>Deuda</th>
                    </tr>
                </thead>
                <tbody id="debts-tbody">
                    <tr>
                        <td colspan="8" class="text-center">
                            <i class="fas fa-spinner fa-spin"></i> Cargando deudas...
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
    .stat-card.debt {
        border-left-color: #e74a3b;
    }
    .stat-card.paid {
        border-left-color: #1cc88a;
    }
    #debtsTable thead tr {
        background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%);
        color: white;
    }
    #debtsTable thead th {
        color: white;
        border-color: #2a75f3;
        font-size: 0.9rem;
    }
    #debtsTable tbody tr:hover {
        background-color: #f3f7ff;
    }
    #debtsTable tbody td {
        vertical-align: middle;
    }
    .badge-info {
        background-color: #4285f4;
    }
    .badge-danger {
        background-color: #e74a3b;
    }
    .badge-success {
        background-color: #1cc88a;
    }
    .badge-warning {
        background-color: #f6c23e;
        color: #5a5c69;
    }
    #year-filter {
        min-width: 200px;
    }
</style>

<script>
let allDebts = [];

$(document).ready(function() {
    loadDebts();
});

function loadDebts() {
    $.ajax({
        url: 'api/my_debts.php',
        method: 'GET',
        data: { dni: '<?php echo $student_dni; ?>' },
        dataType: 'json',
        success: function(resp) {
            if (resp.status === 'ok') {
                allDebts = resp.data;
                renderStats(resp);
                renderDebtsTable(resp.data);
                populateYearFilter(resp.resumen_por_año);
            } else {
                $('#debts-tbody').html(`
                    <tr>
                        <td colspan="8" class="text-center text-danger">
                            <i class="fas fa-exclamation-triangle"></i> ${resp.message}
                        </td>
                    </tr>
                `);
            }
        },
        error: function(err) {
            console.error(err);
            $('#debts-tbody').html(`
                <tr>
                    <td colspan="8" class="text-center text-danger">
                        <i class="fas fa-exclamation-triangle"></i> Error al cargar las deudas
                    </td>
                </tr>
            `);
        }
    });
}

function renderStats(data) {
    let html = `
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card stat-card debt h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #e74a3b;">Deuda Total</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">S/ ${data.summary.total_debt.toFixed(2)}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exclamation-circle fa-2x" style="color: #e74a3b; opacity: 0.3;"></i>
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
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #4285f4;">Conceptos Pendientes</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">${data.summary.count_concepts}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-list fa-2x" style="color: #4285f4; opacity: 0.3;"></i>
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
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #4285f4;">Año Académico Actual</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">${data.anio_academico_actual}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-alt fa-2x" style="color: #4285f4; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('#stats-cards').html(html);
}

function renderDebtsTable(debts) {
    if (debts.length === 0) {
        $('#debts-tbody').html(`
            <tr>
                <td colspan="7" class="text-center text-success">
                    <i class="fas fa-check-circle"></i> No tienes deudas pendientes
                </td>
            </tr>
        `);
        return;
    }
    
    let html = '';
    debts.forEach(function(debt) {
        let discountBadge = debt.has_discount 
            ? `<span class="badge badge-success">${debt.discount_percentage}% OFF</span>` 
            : '<span class="badge badge-secondary">Sin descuento</span>';
        
        html += `
            <tr>
                <td><strong>${debt.concepto}</strong></td>
                <td><span class="badge badge-info">${debt.anio_academico}</span></td>
                <td class="text-right">S/ ${debt.total.toFixed(2)}</td>
                <td class="text-center">${discountBadge}</td>
                <td class="text-right"><strong>S/ ${debt.amount_to_pay.toFixed(2)}</strong></td>
                <td class="text-right text-success">S/ ${debt.pagado.toFixed(2)}</td>
                <td class="text-right"><strong class="text-danger">S/ ${debt.deuda.toFixed(2)}</strong></td>
            </tr>
        `;
    });
    $('#debts-tbody').html(html);
    
    // Reinicializar DataTable si existe
    if ($.fn.DataTable.isDataTable('#debtsTable')) {
        $('#debtsTable').DataTable().destroy();
    }
    
    $('#debtsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        order: [[6, 'desc']], // Ordenar por deuda descendente
        pageLength: 10,
        dom: 'lrtip'
    });
}

function populateYearFilter(years) {
    let options = '<option value="">Todos los años académicos</option>';
    years.forEach(function(year) {
        options += `<option value="${year.año}">${year.año} (${year.conceptos} conceptos - S/ ${year.total_deuda.toFixed(2)})</option>`;
    });
    $('#year-filter').html(options);
}

$('#year-filter').change(function() {
    let selectedYear = $(this).val();
    if (selectedYear === '') {
        renderDebtsTable(allDebts);
    } else {
        let filtered = allDebts.filter(d => d.anio_academico == selectedYear);
        renderDebtsTable(filtered);
    }
});
</script>
