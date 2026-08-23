<?php 
// Configuración de sesión consistente y arranque seguro
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
include 'db_connect.php';

// Comprobar si el usuario está logueado y tiene los permisos adecuados
if(!isset($_SESSION['login_id']) || (isset($_SESSION['login_type']) && $_SESSION['login_type'] != 1)){
    header('location: login.php');
    exit;
}

$school_id = $_SESSION['login_school_id'] ?? 0;

// Estadísticas rápidas
$stats_q = $conn->query("
    SELECT 
        COUNT(*) as total_deudas,
        COALESCE(SUM(CASE WHEN ef.discounted_amount > 0 THEN ef.discounted_amount ELSE ef.total_fee END), 0) as total_facturado,
        COALESCE((SELECT SUM(p.amount) FROM payments p INNER JOIN student_ef_list ef2 ON p.ef_id = ef2.id INNER JOIN student s2 ON ef2.student_id = s2.id WHERE s2.school_id = $school_id), 0) as total_cobrado
    FROM student_ef_list ef 
    INNER JOIN student s ON ef.student_id = s.id 
    WHERE s.school_id = $school_id
");
$stats = $stats_q ? $stats_q->fetch_assoc() : ['total_deudas' => 0, 'total_facturado' => 0, 'total_cobrado' => 0];
$total_pendiente = $stats['total_facturado'] - $stats['total_cobrado'];

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
.stat-card-fees {
    border-radius: 10px;
    padding: 16px 18px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.12);
    transition: transform 0.2s;
}
.stat-card-fees:hover { transform: translateY(-2px); }
.stat-card-fees .stat-icon { font-size: 1.8rem; opacity: 0.85; }
.stat-card-fees .stat-info h3 { margin: 0; font-size: 1.35rem; font-weight: 700; }
.stat-card-fees .stat-info small { opacity: 0.85; font-size: 0.78rem; }
.bg-grad-blue { background: linear-gradient(135deg, #4e73df, #224abe); }
.bg-grad-green { background: linear-gradient(135deg, #1cc88a, #13855c); }
.bg-grad-red { background: linear-gradient(135deg, #e74a3b, #be2617); }
.bg-grad-yellow { background: linear-gradient(135deg, #f6c23e, #dda20a); }

.filter-bar-fees {
    background: #f8f9fc;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
    display: flex;
    gap: 12px;
    align-items: end;
    flex-wrap: wrap;
}
.filter-bar-fees .filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-bar-fees .filter-group label { font-size: 0.75rem; font-weight: 600; color: #5a5c69; margin: 0; }
.filter-bar-fees .filter-group select { min-width: 150px; }

/* Estilos para botones agrupados */
.btn-group-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.btn-group .dropdown-toggle {
    border-radius: 6px;
    font-weight: 500;
    padding: 8px 16px;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s;
}
.btn-group .dropdown-toggle:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.btn-group .dropdown-menu {
    border-radius: 8px;
    border: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    padding: 8px 0;
    margin-top: 4px;
    min-width: 220px;
}
.btn-group .dropdown-item {
    padding: 10px 16px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
}
.btn-group .dropdown-item:hover {
    background-color: #f8f9fc;
    color: #4e73df;
}
.btn-group .dropdown-divider { margin: 6px 0; }

@media (max-width: 768px) {
    .btn-group-actions { flex-direction: column; width: 100%; }
    .btn-group { width: 100%; }
    .btn-group .dropdown-toggle { width: 100%; justify-content: center; }
    .btn-group .dropdown-menu { width: 100%; min-width: auto; }
    .card-header .d-flex { flex-direction: column; gap: 12px; }
    .filter-bar-fees { flex-direction: column; }
}
</style>

<div class="container-fluid">
    <div class="col-lg-12">

        <!-- Resumen de deudas -->
        <div class="row mb-3">
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="stat-card-fees bg-grad-blue">
                    <div class="stat-icon"><i class="fa fa-file-invoice"></i></div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total_deudas']); ?></h3>
                        <small>Deudas asignadas</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="stat-card-fees bg-grad-yellow">
                    <div class="stat-icon"><i class="fa fa-receipt"></i></div>
                    <div class="stat-info">
                        <h3>S/. <?php echo number_format($stats['total_facturado'], 2); ?></h3>
                        <small>Total facturado</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="stat-card-fees bg-grad-green">
                    <div class="stat-icon"><i class="fa fa-check-circle"></i></div>
                    <div class="stat-info">
                        <h3>S/. <?php echo number_format($stats['total_cobrado'], 2); ?></h3>
                        <small>Total cobrado</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="stat-card-fees bg-grad-red">
                    <div class="stat-icon"><i class="fa fa-exclamation-circle"></i></div>
                    <div class="stat-info">
                        <h3>S/. <?php echo number_format($total_pendiente, 2); ?></h3>
                        <small>Total pendiente</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-weight-bold text-primary">
                        <i class="fa fa-money-bill-wave mr-2"></i> Gestión de Deudas de Estudiantes
                    </h5>
                    <div class="btn-group-actions text-right">
                        <!-- Botón de Eliminar Selección --->
                        <button class="btn btn-danger btn-sm mr-2" id="bulk_delete_fees_btn">
                            <i class="fa fa-trash mr-1"></i> Eliminar Selección
                        </button>
                        <!-- Asignación de Deudas -->
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-success btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fa fa-plus mr-1"></i> Asignar Deudas
                            </button>
                            <div class="dropdown-menu dropdown-menu-right">
                                <a class="dropdown-item" href="javascript:void(0)" id="new_fees">
                                    <i class="fa fa-user-plus mr-2"></i> Asignación Individual
                                </a>
                                <a class="dropdown-item" href="javascript:void(0)" id="bulk_assign_fees">
                                    <i class="fa fa-users mr-2"></i> Asignación Masiva
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="javascript:void(0)" id="upload_payment_excel">
                                    <i class="fa fa-upload mr-2"></i> Subir desde Excel
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card-body">
                <div id="msg"></div>

                <!-- Filtros -->
                <div class="filter-bar-fees">
                    <div class="filter-group">
                        <label><i class="fa fa-calendar-check mr-1"></i>Año Académico</label>
                        <select id="filter_year_fees" class="form-control form-control-sm">
                            <option value="">Todos</option>
                            <?php foreach ($years_list as $y): ?>
                                <option value="<?php echo htmlspecialchars($y['year']); ?>" <?php echo $y['is_active'] == 1 ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($y['year']); ?><?php echo $y['is_active'] == 1 ? ' (Activo)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fa fa-filter mr-1"></i>Estado de pago</label>
                        <select id="filter_estado_pago" class="form-control form-control-sm">
                            <option value="">Todos</option>
                            <option value="pendiente">Pendiente</option>
                            <option value="parcial">Pago parcial</option>
                            <option value="pagado">Pagado</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fa fa-graduation-cap mr-1"></i>Nivel</label>
                        <select id="filter_nivel_fees" class="form-control form-control-sm">
                            <option value="">Todos</option>
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
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="clear_fees_filters" title="Limpiar filtros">
                            <i class="fa fa-times mr-1"></i> Limpiar
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="feesTable" class="table table-striped table-hover">
                        <thead class="bg-light">
                            <tr>
                                <th class="text-center" width="5%">
                                    <div class="custom-control custom-checkbox text-center" style="display:inline-block;">
                                        <input type="checkbox" class="custom-control-input" id="check_all_fees">
                                        <label class="custom-control-label" for="check_all_fees"></label>
                                    </div>
                                </th>
                                <th>ID No.</th>
                                <th>Nombre</th>
                                <th>Concepto de Pago</th>
                                <th>Tarifa</th>
                                <th>Pago</th>
                                <th>Balance</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- El contenido de la tabla será llenado por DataTables usando AJAX -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    var table = $('#feesTable').DataTable({
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
        order: [[2, 'asc']],
        columnDefs: [
            { targets: [0, 7], orderable: false },
            { targets: 0, responsivePriority: 1 },
            { targets: 2, responsivePriority: 2 },
            { targets: 3, responsivePriority: 3 },
            { targets: 7, responsivePriority: 1 }
        ],
        serverSide: true,
        processing: true,
        ajax: {
            url: 'fees_table_data.php',
            type: 'GET',
            data: function(d) {
                d.year = $('#filter_year_fees').val();
                d.estado_pago = $('#filter_estado_pago').val();
                d.nivel = $('#filter_nivel_fees').val();
                d.student_status = $('#filter_student_status').val();
            },
            error: function(xhr, error, thrown) {
                console.error('Error loading fees_table_data:', xhr.responseText || error || thrown);
                alert_toast('Error al cargar datos.', 'danger');
            }
        }
    });

    // Filtros: recargar tabla al cambiar
    $('#filter_year_fees, #filter_estado_pago, #filter_nivel_fees, #filter_student_status').on('change', function() {
        table.ajax.reload();
    });

    // Limpiar filtros
    $('#clear_fees_filters').on('click', function() {
        $('#filter_year_fees').val(''); // Limpia también el año, opcional
        $('#filter_estado_pago').val('');
        $('#filter_nivel_fees').val('');
        $('#filter_student_status').val('Activo');
        table.ajax.reload();
    });

    // Delegación de eventos para botones dinámicos
    $(document).on('click', '.view_payment', function() {
        uni_modal("Información de Pagos", "view_payment.php?ef_id=" + $(this).data('id') + "&pid=0", "mid-large");
    });
    
    $(document).on('click', '.edit_fees', function() {
        uni_modal("Editar Detalles de Inscripción", "manage_fee.php?id=" + $(this).data('id'), "mid-large");
    });
    
    $(document).on('click', '.delete_fees', function() {
        _conf("¿Está seguro de eliminar estas tarifas?", "delete_fees", [$(this).data('id')]);
    });

    // Eventos de botones
    $('#new_fees').click(function() {
        uni_modal("Asignar Deuda Individual", "manage_fee.php", "mid-large");
    });
    
    $('#bulk_assign_fees').click(function() {
        uni_modal("Asignación Masiva de Deudas", "bulk_assign_fees.php", "large");
    });
    
    $('#upload_payment_excel').click(function() {
        uni_modal("Subir Asignaciones desde Excel", "show_payment_upload_form.php", "large");
    });
    
    $('#download_format').click(function() {
        var link = document.createElement('a');
        link.href = 'download_payment_format.php';
        link.download = 'formato_pagos.xlsx';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    });
    
    $('#export_fees').click(function() {
        window.open('export_fees_excel.php', '_blank');
    });
    
    $('#export_school_data').click(function() {
        window.open('export_school_data.php', '_blank');
    });

    // Eventos para selección múltiple
    $('#check_all_fees').change(function() {
        if($(this).is(':checked')) {
            $('.fee-checkbox').prop('checked', true);
        } else {
            $('.fee-checkbox').prop('checked', false);
        }
    });

    // Sincronizar checkbox maestro al cambiar página
    table.on('draw.dt', function() {
        $('#check_all_fees').prop('checked', false);
    });

    // Acción de eliminar selección
    $('#bulk_delete_fees_btn').click(function() {
        var ids = [];
        $('.fee-checkbox:checked').each(function() {
            ids.push($(this).val());
        });

        if (ids.length <= 0) {
            alert_toast("Por favor seleccione al menos una deuda para eliminar.", "warning");
            return;
        }

        _conf("¿Está seguro de eliminar las " + ids.length + " cuotas seleccionadas de forma permanente?", "execute_bulk_delete", [ids]);
    });
});

function execute_bulk_delete(ids) {
    start_load();
    $.ajax({
        url: 'ajax.php?action=bulk_delete_fees',
        method: 'POST',
        data: { ids: ids },
        dataType: 'json',
        success: function(resp) {
            end_load();
            if (resp && resp.status == 1) {
                alert_toast(resp.message || "Tarifas eliminadas exitosamente", 'success');
                $('#feesTable').DataTable().ajax.reload();
                $('#check_all_fees').prop('checked', false);
            } else {
                alert_toast(resp.message || "Error al eliminar las tarifas", 'danger');
            }
        },
        error: function(err) {
            end_load();
            console.error("Error en la solicitud AJAX:", err);
            alert_toast("Error en el servidor al intentar eliminar en masa", 'danger');
        }
    });
}

function delete_fees(id) {
    start_load();
    $.ajax({
        url: 'ajax.php?action=delete_fees',
        method: 'POST',
        data: { id: id },
        dataType: 'json',
        success: function(resp) {
            end_load();
            if (resp && resp.status == 1) {
                alert_toast("Tarifa eliminada exitosamente", 'success');
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                alert_toast(resp.msg || "Error al eliminar la tarifa", 'danger');
            }
        },
        error: function(err) {
            end_load();
            console.error("Error en la solicitud AJAX:", err);
            alert_toast("Error en el servidor. Intente nuevamente más tarde", 'danger');
        }
    });
}

$('#uni_modal').on('hidden.bs.modal', function () {
    $(this).find('.modal-title').html('');
    $(this).find('.modal-body').html('');
});

window.reload_table = function() {
    location.reload();
};
</script>
