<?php 
include 'db_connect.php';

// Comprobar si el usuario está logueado y tiene los permisos adecuados
if(!isset($_SESSION['login_id']) || (isset($_SESSION['login_type']) && $_SESSION['login_type'] != 1)){
    header('location: login.php');
    exit;
}

$school_id = $_SESSION['login_school_id'] ?? 0;

// Obtener años académicos para el filtro
$years = $conn->query("SELECT id, year, description, is_active FROM academic_year WHERE school_id = $school_id ORDER BY year DESC");
$active_year_id = 0;
$years_list = [];
if ($years) {
    while ($y = $years->fetch_assoc()) {
        $years_list[] = $y;
        if ($y['is_active'] == 1) $active_year_id = $y['id'];
    }
}

// Totales para resumen
$resumen = $conn->query("
    SELECT 
        COUNT(*) as total_conceptos,
        COALESCE(SUM(total_amount), 0) as suma_montos,
        COUNT(DISTINCT level) as total_niveles
    FROM courses c 
    LEFT JOIN academic_year ay ON c.academic_year_id = ay.id 
    WHERE ay.school_id = $school_id
");
$stats = $resumen ? $resumen->fetch_assoc() : ['total_conceptos' => 0, 'suma_montos' => 0, 'total_niveles' => 0];
?>

<style>
.stat-card {
    border-radius: 10px;
    padding: 18px 20px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 15px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.12);
    transition: transform 0.2s;
}
.stat-card:hover { transform: translateY(-2px); }
.stat-card .stat-icon { font-size: 2rem; opacity: 0.85; }
.stat-card .stat-info h3 { margin: 0; font-size: 1.5rem; font-weight: 700; }
.stat-card .stat-info small { opacity: 0.85; font-size: 0.8rem; }
.stat-card.bg-gradient-primary { background: linear-gradient(135deg, #4e73df, #224abe); }
.stat-card.bg-gradient-success { background: linear-gradient(135deg, #1cc88a, #13855c); }
.stat-card.bg-gradient-info { background: linear-gradient(135deg, #36b9cc, #258391); }

.filter-bar {
    background: #f8f9fc;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
    display: flex;
    gap: 12px;
    align-items: end;
    flex-wrap: wrap;
}
.filter-bar .filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-bar .filter-group label { font-size: 0.75rem; font-weight: 600; color: #5a5c69; margin: 0; }
.filter-bar .filter-group select { min-width: 160px; }
</style>

<div class="container-fluid">
    <div class="col-lg-12">

        <!-- Resumen -->
        <div class="row mb-3">
            <div class="col-md-4 mb-2">
                <div class="stat-card bg-gradient-primary">
                    <div class="stat-icon"><i class="fa fa-list"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_conceptos']; ?></h3>
                        <small>Conceptos registrados</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="stat-card bg-gradient-success">
                    <div class="stat-icon"><i class="fa fa-money-bill-wave"></i></div>
                    <div class="stat-info">
                        <h3>S/. <?php echo number_format($stats['suma_montos'], 2); ?></h3>
                        <small>Suma total de montos</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="stat-card bg-gradient-info">
                    <div class="stat-icon"><i class="fa fa-layer-group"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total_niveles']; ?></h3>
                        <small>Niveles con conceptos</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h5 class="m-0 font-weight-bold text-primary">
                    <i class="fa fa-money-bill mr-2"></i> Conceptos de Pagos
                </h5>
                <button class="btn btn-primary btn-sm" id="new_concept">
                    <i class="fa fa-plus mr-1"></i> Nuevo Concepto
                </button>
            </div>
            
            <div class="card-body">
                <!-- Filtros -->
                <div class="filter-bar">
                    <div class="filter-group">
                        <label>Nivel</label>
                        <select id="filter_nivel" class="form-control form-control-sm">
                            <option value="">Todos</option>
                            <option value="Inicial">Inicial</option>
                            <option value="Primaria">Primaria</option>
                            <option value="Secundaria">Secundaria</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Año Académico</label>
                        <select id="filter_year" class="form-control form-control-sm">
                            <option value="">Todos</option>
                            <?php foreach ($years_list as $y): ?>
                                <option value="<?php echo htmlspecialchars($y['year']); ?>" <?php echo $y['is_active'] == 1 ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($y['year']); ?><?php echo $y['is_active'] == 1 ? ' (Activo)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="clear_filters" title="Limpiar filtros">
                            <i class="fa fa-times mr-1"></i> Limpiar
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="concepts_table">
                        <thead class="bg-light">
                            <tr>
                                <th>#</th>
                                <th>Concepto</th>
                                <th>Nivel</th>
                                <th>Grados</th>
                                <th>Año Académico</th>
                                <th>Monto</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $conceptos = $conn->query("
                                SELECT c.*, ay.year as academic_year, ay.description as year_description 
                                FROM courses c 
                                LEFT JOIN academic_year ay ON c.academic_year_id = ay.id 
                                WHERE ay.school_id = $school_id
                                ORDER BY c.course ASC
                            ");
                            
                            if($conceptos && $conceptos->num_rows > 0):
                                $i = 1;
                                while ($row = $conceptos->fetch_assoc()) :
                            ?>
                                <tr>
                                    <td><strong><?php echo $i++; ?></strong></td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($row['course']); ?></strong>
                                        </div>
                                        <?php if (!empty($row['description'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($row['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($row['level']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['grades'])): ?>
                                            <small><?php echo str_replace(',', ', ', htmlspecialchars($row['grades'])); ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">---</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['academic_year']): ?>
                                            <span class="badge badge-primary"><?php echo htmlspecialchars($row['academic_year']); ?></span>
                                            <?php if (!empty($row['year_description'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($row['year_description']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">No asignado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <span class="badge badge-success">
                                            S/. <?php echo number_format($row['total_amount'] ?? 0, 2); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-primary btn-sm edit_concept" type="button" data-id="<?php echo $row['id']; ?>">
                                            <i class="fa fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm delete_concept" type="button" data-id="<?php echo $row['id']; ?>">
                                            <i class="fa fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No hay conceptos de pago registrados</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    var table;
    if ($('#concepts_table').length > 0) {
        try {
            table = $('#concepts_table').DataTable({
                "language": {
                    "processing": "Procesando...",
                    "lengthMenu": "Mostrar _MENU_ registros por página",
                    "zeroRecords": "No se encontraron resultados",
                    "emptyTable": "Ningún dato disponible en esta tabla",
                    "info": "Mostrando registros del _START_ al _END_ de un total de _TOTAL_",
                    "infoEmpty": "Mostrando registros del 0 al 0 de un total de 0",
                    "infoFiltered": "(filtrado de un total de _MAX_ registros)",
                    "search": "Buscar:",
                    "loadingRecords": "Cargando...",
                    "paginate": {
                        "first": "Primero",
                        "last": "Último",
                        "next": "Siguiente",
                        "previous": "Anterior"
                    }
                },
                "pageLength": 15,
                "responsive": true,
                "order": [[1, 'asc']],
                "columnDefs": [
                    { "targets": [0, 6], "orderable": false }
                ]
            });
        } catch (error) {
            console.error('Error al inicializar DataTable:', error);
        }
    }

    // Filtro por Nivel (columna 2)
    $('#filter_nivel').on('change', function() {
        if (table) {
            table.column(2).search(this.value).draw();
        }
    });

    // Filtro por Año Académico (columna 4)
    $('#filter_year').on('change', function() {
        if (table) {
            table.column(4).search(this.value).draw();
        }
    });

    // Limpiar filtros
    $('#clear_filters').on('click', function() {
        $('#filter_nivel').val('');
        $('#filter_year').val('');
        if (table) {
            table.columns().search('').draw();
        }
    });

    // Aplicar filtro de año activo al cargar
    var initialYear = $('#filter_year').val();
    if (initialYear && table) {
        table.column(4).search(initialYear).draw();
    }

    // Evento para nuevo concepto
    $('#new_concept').click(function() {
        uni_modal("Nuevo Concepto de Pago", "manage_concept.php", 'large');
    });

    // Evento para editar concepto
    $(document).on('click', '.edit_concept', function() {
        uni_modal("Editar Concepto de Pago", "manage_concept.php?id=" + $(this).data('id'), 'large');
    });

    // Evento para eliminar concepto
    $(document).on('click', '.delete_concept', function() {
        _conf("¿Está seguro de eliminar este concepto de pago?", "delete_concept", [$(this).data('id')]);
    });
});

function delete_concept(id) {
    start_load();
    $.ajax({
        url: 'ajax.php?action=delete_course',
        method: 'POST',
        data: { id: id },
        dataType: 'json',
        success: function(resp) {
            end_load();
            if (resp && resp.status == 1) {
                alert_toast("Concepto eliminado exitosamente", 'success');
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                alert_toast(resp.msg || "Error al eliminar el concepto", 'danger');
            }
        },
        error: function(err) {
            end_load();
            console.error("Error en la solicitud AJAX:", err);
            alert_toast("Error en el servidor. Intente nuevamente más tarde", 'danger');
        }
    });
}
</script>
