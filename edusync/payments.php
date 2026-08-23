<?php 
// Configuración de sesión consistente y arranque seguro
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
include 'db_connect.php';

// Comprobar si el usuario está logueado y tiene los permisos adecuados
if(!isset($_SESSION['login_id']) || (isset($_SESSION['login_type']) && $_SESSION['login_type'] != 1)){
    header('location: login.php');
    exit;
}

$school_id = $_SESSION['login_school_id'] ?? 0;

// Estadísticas de pagos
$stats_q = $conn->query("
    SELECT 
        COUNT(*) as total_pagos,
        COALESCE(SUM(p.amount), 0) as total_recaudado,
        COALESCE(SUM(CASE WHEN DATE(p.date_created) = CURDATE() THEN p.amount ELSE 0 END), 0) as recaudado_hoy,
        COALESCE(SUM(CASE WHEN MONTH(p.date_created) = MONTH(CURDATE()) AND YEAR(p.date_created) = YEAR(CURDATE()) THEN p.amount ELSE 0 END), 0) as recaudado_mes
    FROM payments p 
    INNER JOIN student_ef_list ef ON ef.id = p.ef_id
    INNER JOIN student s ON s.id = ef.student_id
    WHERE s.school_id = $school_id
");
$stats = $stats_q ? $stats_q->fetch_assoc() : ['total_pagos' => 0, 'total_recaudado' => 0, 'recaudado_hoy' => 0, 'recaudado_mes' => 0];

// Obtener años académicos para el filtro
$years = $conn->query("SELECT id, year, description, is_active FROM academic_year WHERE school_id = $school_id ORDER BY year DESC");
$years_list = [];
if ($years) {
    while ($y = $years->fetch_assoc()) {
        $years_list[] = $y;
    }
}
?>

<style>
.stat-card-pay {
    border-radius: 10px;
    padding: 16px 18px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.12);
    transition: transform 0.2s;
}
.stat-card-pay:hover { transform: translateY(-2px); }
.stat-card-pay .stat-icon { font-size: 1.8rem; opacity: 0.85; }
.stat-card-pay .stat-info h3 { margin: 0; font-size: 1.35rem; font-weight: 700; }
.stat-card-pay .stat-info small { opacity: 0.85; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;}
.bg-pay-blue { background: linear-gradient(135deg, #4e73df, #224abe); }
.bg-pay-green { background: linear-gradient(135deg, #1cc88a, #13855c); }
.bg-pay-teal { background: linear-gradient(135deg, #20c9a6, #128e75); }
.bg-pay-purple { background: linear-gradient(135deg, #6f42c1, #4e298c); }

.filter-bar-pay {
    background: #f8f9fc;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
    display: flex;
    gap: 15px;
    align-items: end;
    flex-wrap: wrap;
    border: 1px solid #e3e6f0;
}
.filter-bar-pay .filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-bar-pay .filter-group label { font-size: 0.75rem; font-weight: 600; color: #5a5c69; margin: 0; }
.filter-bar-pay .filter-group select, .filter-bar-pay .filter-group input { min-width: 160px; }

@media (max-width: 768px) {
    .filter-bar-pay { flex-direction: column; align-items: stretch;}
    .filter-bar-pay .filter-group select, .filter-bar-pay .filter-group input { min-width: 100%; }
}
</style>

<div class="container-fluid">
    <div class="col-lg-12">
        <!-- Resumen de Pagos -->
        <div class="row mb-3">
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="stat-card-pay bg-pay-blue">
                    <div class="stat-icon"><i class="fa fa-receipt"></i></div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total_pagos']); ?></h3>
                        <small>Total Transacciones</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="stat-card-pay bg-pay-purple">
                    <div class="stat-icon"><i class="fa fa-chart-line"></i></div>
                    <div class="stat-info">
                        <h3>S/. <?php echo number_format($stats['total_recaudado'], 2); ?></h3>
                        <small>Histórico Total</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="stat-card-pay bg-pay-teal">
                    <div class="stat-icon"><i class="fa fa-calendar-alt"></i></div>
                    <div class="stat-info">
                        <h3>S/. <?php echo number_format($stats['recaudado_mes'], 2); ?></h3>
                        <small>Recaudado este mes</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="stat-card-pay bg-pay-green">
                    <div class="stat-icon"><i class="fa fa-money-bill-wave"></i></div>
                    <div class="stat-info">
                        <h3>S/. <?php echo number_format($stats['recaudado_hoy'], 2); ?></h3>
                        <small>Recaudado hoy</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-weight-bold text-primary">
                        <i class="fa fa-credit-card mr-2"></i> Gestión de Pagos Realizados
                    </h5>
                    <div>
                        <button class="btn btn-danger btn-sm mr-2" id="bulk_delete_payment_btn">
                            <i class="fa fa-trash"></i> Eliminar Selección
                        </button>
                        <button class="btn btn-primary btn-sm" id="new_payment">
                            <i class="fa fa-plus"></i> Registrar Pago
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <!-- Filtros -->
                <div class="filter-bar-pay">
                    <div class="filter-group">
                        <label><i class="fa fa-calendar-check mr-1"></i>Año Académico</label>
                        <select id="filter_year_pagos" class="form-control form-control-sm">
                            <option value="">Todos</option>
                            <?php foreach ($years_list as $y): ?>
                                <option value="<?php echo htmlspecialchars($y['year']); ?>" <?php echo $y['is_active'] == 1 ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($y['year']); ?><?php echo $y['is_active'] == 1 ? ' (Activo)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fa fa-calendar mr-1"></i>Mes del Pago</label>
                        <input type="month" id="filter_mes" class="form-control form-control-sm">
                    </div>
                    <div class="filter-group">
                        <label><i class="fa fa-graduation-cap mr-1"></i>Nivel</label>
                        <select id="filter_nivel_pagos" class="form-control form-control-sm">
                            <option value="">Cualquier nivel</option>
                            <option value="Inicial">Inicial</option>
                            <option value="Primaria">Primaria</option>
                            <option value="Secundaria">Secundaria</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fa fa-user mr-1"></i>Estudiante</label>
                        <select id="filter_student_status" class="form-control form-control-sm">
                            <option value="">Todos</option>
                            <option value="Activo" selected>Activo</option>
                            <option value="Retirado">Retirado</option>
                            <option value="Egresado">Egresado</option>
                        </select>
                    </div>
                    <div class="filter-group justify-content-end">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="clear_pay_filters" title="Limpiar filtros">
                            <i class="fa fa-times mr-1"></i> Limpiar
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover w-100" id="payments_table">
                        <thead class="bg-light">
                            <tr>
                                <th class="text-center" width="5%">
                                    <div class="custom-control custom-checkbox text-center" style="display:inline-block;">
                                        <input type="checkbox" class="custom-control-input" id="check_all_payments">
                                        <label class="custom-control-label" for="check_all_payments"></label>
                                    </div>
                                </th>
                                <th>Fecha</th>
                                <th>DNI</th>
                                <th>N° Boleta</th>
                                <th>Nombre Completo</th>
                                <th>Monto Pagado</th>
                                <th>Concepto Detallado</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- El contenido de la tabla será llenado por DataTables usando AJAX server-side -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    var table = $('#payments_table').DataTable({
        language: {
            "search": "Buscar:",
            "lengthMenu": "Mostrar _MENU_ registros por página",
            "zeroRecords": "No se encontraron resultados",
            "info": "Mostrando _START_ a _END_ de _TOTAL_ registros",
            "infoEmpty": "Mostrando 0 a 0 de 0 registros",
            "infoFiltered": "(filtrados de _MAX_ registros totales)",
            "paginate": {
                "first": "Primero",
                "last": "Último",
                "next": "Siguiente",
                "previous": "Anterior"
            }
        },
        responsive: true,
        autoWidth: false,
        pageLength: 15,
        order: [[0, 'desc']], // Ordenar por la columna ID (oculta) típicamente, o fecha. Pero aquí 0 es `#`. Vamos a interceptarlo en el backend.
        columnDefs: [
            { targets: [0, 7], orderable: false },
            { targets: 0, responsivePriority: 1 },
            { targets: 4, responsivePriority: 2 },
            { targets: 5, responsivePriority: 1 },
            { targets: 6, responsivePriority: 3 },
            { targets: 7, responsivePriority: 1 }
        ],
        serverSide: true,
        processing: true,
        ajax: {
            url: 'payments_table_data.php',
            type: 'GET',
            data: function(d) {
                d.year = $('#filter_year_pagos').val();
                d.mes = $('#filter_mes').val();
                d.nivel = $('#filter_nivel_pagos').val();
                d.student_status = $('#filter_student_status').val();
            },
            error: function(xhr, error, thrown) {
                console.error('Error loading payments_table_data:', xhr.responseText || error || thrown);
                alert_toast('Error al cargar datos. Revise la consola.', 'danger');
            }
        }
    });

    // Filtros
    $('#filter_year_pagos, #filter_mes, #filter_nivel_pagos, #filter_student_status').on('change', function() {
        table.ajax.reload();
    });

    $('#clear_pay_filters').on('click', function() {
        $('#filter_year_pagos').val(''); // Limpia también el año, pero si quieres puedes dejarlo en el activo
        $('#filter_mes').val('');
        $('#filter_nivel_pagos').val('');
        $('#filter_student_status').val('Activo');
        table.ajax.reload();
    });

    // Carga inicial (para que tome en cuenta el año activo si hay uno seleccionado por defecto)
    if ($('#filter_year_pagos').val()) {
        // DataTables lo hará automáticamente con el data() en el primer request
    }

    // Eventos para botones
    $('#new_payment').click(function() {
        uni_modal("Registrar Nuevo Pago", "manage_payment.php", "mid-large");
    });

    // Delegación de eventos para botones dinámicos
    $(document).on('click', '.view_payment', function() {
        uni_modal("Información de Pago", "view_payment.php?ef_id=" + $(this).data('ef_id') + "&pid=" + $(this).data('id'), "mid-large");
    });

    $(document).on('click', '.edit_payment', function() {
        uni_modal("Editar Registro de Pago", "edit_payment.php?id=" + $(this).data('id'), "mid-large");
    });

    $(document).on('click', '.delete_payment', function() {
        _conf("¿Estás seguro de que deseas eliminar este pago permanentemente?", "delete_payment", [$(this).data('id')]);
    });

    // Eventos para selección múltiple
    $('#check_all_payments').change(function() {
        if($(this).is(':checked')) {
            $('.payment-checkbox').prop('checked', true);
        } else {
            $('.payment-checkbox').prop('checked', false);
        }
    });

    // Sincronizar checkbox maestro al cambiar página
    table.on('draw.dt', function() {
        $('#check_all_payments').prop('checked', false);
    });

    // Acción de eliminar selección
    $('#bulk_delete_payment_btn').click(function() {
        var ids = [];
        $('.payment-checkbox:checked').each(function() {
            ids.push($(this).val());
        });

        if (ids.length <= 0) {
            alert_toast("Por favor seleccione al menos un pago para eliminar.", "warning");
            return;
        }

        _conf("¿Está seguro de eliminar los " + ids.length + " pagos seleccionados de forma permanente?", "execute_bulk_delete_payments", [ids]);
    });
});

function execute_bulk_delete_payments(ids) {
    start_load();
    $.ajax({
        url: 'ajax.php?action=bulk_delete_payment',
        method: 'POST',
        data: { ids: ids },
        dataType: 'json',
        success: function(resp) {
            end_load();
            if (resp && resp.status == 1) {
                alert_toast(resp.message || "Pagos eliminados exitosamente", 'success');
                $('#payments_table').DataTable().ajax.reload();
                $('#check_all_payments').prop('checked', false);
            } else {
                alert_toast(resp.message || "Error al eliminar los pagos", 'danger');
            }
        },
        error: function(err) {
            end_load();
            console.error("Error en la solicitud AJAX:", err);
            alert_toast("Error en el servidor al intentar eliminar en masa", 'danger');
        }
    });
}

function delete_payment($id) {
    start_load();
    $.ajax({
        url: 'ajax.php?action=delete_payment',
        method: 'POST',
        data: { id: $id },
        dataType: 'json',
        success: function(resp) {
            end_load();
            if (resp && resp.status == 1) {
                alert_toast("Pago eliminado exitosamente", 'success');
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                alert_toast(resp.message || "Error al eliminar el pago", 'danger');
            }
        },
        error: function(err) {
            end_load();
            console.error("Error en AJAX:", err);
            alert_toast("Error crítico en el servidor.", 'danger');
        }
    });
}
</script>
