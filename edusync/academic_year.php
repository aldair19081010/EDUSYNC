<?php 
include 'db_connect.php'; 

// Comprobar si el usuario está logueado y tiene los permisos adecuados
if(!isset($_SESSION['login_id']) || (isset($_SESSION['login_type']) && $_SESSION['login_type'] != 1)){
    header('location: login.php');
    exit;
}

$school_id = $_SESSION['login_school_id'] ?? 0;
?>

<div class="container-fluid">
    <div class="col-lg-12">
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card shadow mb-4 border-left-primary">
                    <div class="card-header bg-primary text-white py-3">
                        <h4 class="m-0 font-weight-bold"><i class="fa fa-calendar-alt mr-2"></i> Gestión de Años Académicos</h4>
                    </div>
                    <div class="card-body">
                        <form id="manage-academic-year-form" method="post" action="#">
                            <input type="hidden" name="id" id="academic-year-id">
                            <input type="hidden" name="school_id" value="<?php echo $school_id; ?>">
                            
                            <div class="row form-group">
                                <div class="col-md-6">
                                    <label for="year" class="font-weight-bold">Año Académico</label>
                                    <input type="text" name="year" id="year" class="form-control" placeholder="Ej: 2025" required>
                                    <small class="form-text text-muted">Formato: YYYY (año en concreto)</small>
                                </div>
                                <div class="col-md-6">
                                    <label for="is_active" class="font-weight-bold">Estado</label>
                                    <select name="is_active" id="is_active" class="form-control" required>
                                        <option value="1">Activo</option>
                                        <option value="0">Inactivo</option>
                                    </select>
                                    <small class="form-text text-muted">Solo puede haber un año activo a la vez</small>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="description" class="font-weight-bold">Descripción</label>
                                <textarea name="description" id="description" rows="2" class="form-control" placeholder="Descripción opcional"></textarea>
                            </div>
                            
                            <div class="row form-group">
                                <div class="col-md-6">
                                    <label for="start_date" class="font-weight-bold">Fecha de inicio</label>
                                    <input type="date" name="start_date" id="start_date" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="end_date" class="font-weight-bold">Fecha de finalización</label>
                                    <input type="date" name="end_date" id="end_date" class="form-control" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary btn-block">Guardar Año Académico</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card shadow mb-4 border-left-info">
                    <div class="card-header bg-info text-white py-3">
                        <h4 class="m-0 font-weight-bold"><i class="fa fa-info-circle mr-2"></i> Información del Sistema</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info" role="alert">
                            <h5 class="alert-heading"><i class="fa fa-lightbulb mr-2"></i> Importante</h5>
                            <p class="mb-2">El año académico se utiliza para organizar las evaluaciones y registros académicos. Tenga en cuenta que:</p>
                            <ul class="mb-0">
                                <li>Solo puede haber un año académico activo a la vez</li>
                                <li>Al activar un nuevo año, el sistema desactivará automáticamente el año anterior</li>
                                <li>Las evaluaciones creadas se asociarán automáticamente al año activo</li>
                                <li>Puede consultar evaluaciones de años anteriores desde el filtro de evaluaciones</li>
                            </ul>
                        </div>
                        
                        <?php
                        $active_year_query = $conn->query("SELECT * FROM academic_year WHERE is_active = 1 AND school_id = $school_id LIMIT 1");
                        if($active_year_query && $active_year_query->num_rows > 0):
                            $active_year = $active_year_query->fetch_assoc();
                        ?>
                        <div class="card bg-light border-left-success">
                            <div class="card-body">
                                <h5 class="text-success font-weight-bold"><i class="fa fa-check-circle mr-2"></i> Año Académico Activo</h5>
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Año:</strong> <span class="badge badge-success"><?php echo $active_year['year']; ?></span></p>
                                        <p class="mb-0"><strong>Inicio:</strong> <?php echo date('d/m/Y', strtotime($active_year['start_date'])); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Descripción:</strong> <?php echo $active_year['description'] ?? 'No disponible'; ?></p>
                                        <p class="mb-0"><strong>Fin:</strong> <?php echo date('d/m/Y', strtotime($active_year['end_date'])); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning" role="alert">
                            <h5 class="alert-heading"><i class="fa fa-exclamation-triangle mr-2"></i> Atención</h5>
                            <p class="mb-0">No hay ningún año académico activo. Por favor, cree uno para continuar utilizando el sistema correctamente.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-weight-bold text-primary"><i class="fa fa-history mr-2"></i> Histórico de Años Académicos</h5>
                    <button id="btn-bimester-locks" type="button" class="btn btn-sm btn-outline-secondary">
                        <i class="fa fa-lock mr-1"></i> Bloqueos de Bimestres
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="academic-years-table">
                        <thead class="bg-light">
                            <tr>
                                <th>#</th>
                                <th>Año</th>
                                <th>Descripción</th>
                                <th>Fecha Inicio</th>
                                <th>Fecha Fin</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $i = 1;
                            $years_query = $conn->query("SELECT * FROM academic_year WHERE school_id = $school_id ORDER BY is_active DESC, start_date DESC");
                            if($years_query && $years_query->num_rows > 0):
                                while($row = $years_query->fetch_assoc()):
                            ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><strong><?php echo $row['year']; ?></strong></td>
                                <td><?php echo htmlspecialchars($row['description'] ?? '---'); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($row['start_date'])); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($row['end_date'])); ?></td>
                                <td>
                                    <?php if($row['is_active']): ?>
                                        <span class="badge badge-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary edit-year" data-id="<?php echo $row['id']; ?>">
                                            <i class="fa fa-edit"></i>
                                        </button>
                                        <?php if(!$row['is_active']): ?>
                                        <button type="button" class="btn btn-outline-success activate-year" data-id="<?php echo $row['id']; ?>">
                                            <i class="fa fa-check"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger delete-year" data-id="<?php echo $row['id']; ?>">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            endif;
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function submitAcademicYearForm(e){
    if (e) e.preventDefault();
    start_load();
    
    // Validación de formato de año
    const yearPattern = /^\d{4}$/;
    const yearInput = $('#year').val();
    if (!yearPattern.test(yearInput)) {
        alert_toast("El formato del año debe ser YYYY (ej: 2025)", 'warning');
        end_load();
        return;
    }
    
    // Validación de fechas
    const startDate = new Date($('#start_date').val());
    const endDate = new Date($('#end_date').val());
    if (endDate <= startDate) {
        alert_toast("La fecha de finalización debe ser posterior a la fecha de inicio", 'warning');
        end_load();
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=save_academic_year',
        method: 'POST',
        data: $('#manage-academic-year-form').serialize(),
        dataType: 'json',
        success: function(resp){
            end_load();
            if(resp.status == 1){
                alert_toast("Año académico guardado exitosamente", 'success');
                setTimeout(function(){
                    window.location.href = 'index.php?page=academic_year';
                }, 1500);
            } else {
                alert_toast(resp.msg || "Error al guardar el año académico", 'error');
            }
        },
        error: function(err){
            end_load();
            console.log(err);
            alert_toast("Ocurrió un error", 'error');
        }
    });
}

$(document).ready(function(){
    // Manejo del formulario
    $(document).off('submit.academicYear', '#manage-academic-year-form').on('submit.academicYear', '#manage-academic-year-form', submitAcademicYearForm);
    $(document).off('click.academicYear', '#manage-academic-year-form button[type="submit"]').on('click.academicYear', '#manage-academic-year-form button[type="submit"]', submitAcademicYearForm);

    // Inicializar DataTable
    try {
        $('#academic-years-table').dataTable({
            "language": {
                "search": "Buscar:",
                "lengthMenu": "Mostrar _MENU_ registros por página",
                "zeroRecords": "No se encontraron resultados",
                "info": "Mostrando _START_ a _END_ de _TOTAL_ registros",
                "infoEmpty": "Mostrando 0 a 0 de 0 registros",
                "infoFiltered": "(filtrados de _MAX_ registros totales)",
                "emptyTable": "No hay años académicos registrados",
                "paginate": {
                    "first": "Primero",
                    "last": "Último",
                    "next": "Siguiente",
                    "previous": "Anterior"
                }
            },
            "columnDefs": [
                { "orderable": false, "targets": [6] }
            ]
        });
    } catch (e) {
        console.error('Error inicializando DataTable academic_year:', e);
    }
    
    // Editar año académico
    $(document).on('click', '.edit-year', function(){
        start_load();
        const id = $(this).data('id');
        $.ajax({
            url: 'ajax.php?action=get_academic_year',
            method: 'POST',
            data: {id: id},
            dataType: 'json',
            success: function(data){
                end_load();
                if(data){
                    $('#academic-year-id').val(data.id);
                    $('#year').val(data.year);
                    $('#description').val(data.description);
                    $('#is_active').val(data.is_active);
                    $('#start_date').val(data.start_date);
                    $('#end_date').val(data.end_date);
                    
                    $('html, body').animate({
                        scrollTop: $("#manage-academic-year-form").offset().top - 100
                    }, 500);
                }
            },
            error: function(err){
                end_load();
                console.log(err);
                alert_toast("Error al cargar los datos", 'error');
            }
        });
    });
    
    // Activar año académico
    $(document).on('click', '.activate-year', function(){
        const id = $(this).data('id');
        _conf("¿Está seguro de activar este año académico?<br><small>Esto desactivará el año actualmente activo.</small>", "activate_academic_year", [id]);
    });
    
    // Eliminar año académico
    $(document).on('click', '.delete-year', function(){
        const id = $(this).data('id');
        _conf("¿Está seguro de eliminar este año académico?<br><small>Esta acción no se puede deshacer.</small>", "delete_academic_year", [id]);
    });
});

function activate_academic_year(id){
    start_load();
    $.ajax({
        url: 'ajax.php?action=activate_academic_year',
        method: 'POST',
        data: {id: id},
        dataType: 'json',
        success: function(resp){
            end_load();
            if(resp.status == 1){
                alert_toast("Año académico activado exitosamente", 'success');
                setTimeout(function(){
                    window.location.href = 'index.php?page=academic_year';
                }, 1500);
            } else {
                alert_toast(resp.msg || "Error al activar el año académico", 'error');
            }
        },
        error: function(err){
            end_load();
            console.log(err);
            alert_toast("Ocurrió un error", 'error');
        }
    });
}

function delete_academic_year(id){
    start_load();
    $.ajax({
        url: 'ajax.php?action=delete_academic_year',
        method: 'POST',
        data: {id: id},
        dataType: 'json',
        success: function(resp){
            end_load();
            if(resp.status == 1){
                alert_toast("Año académico eliminado exitosamente", 'success');
                setTimeout(function(){
                    window.location.href = 'index.php?page=academic_year';
                }, 1500);
            } else {
                alert_toast(resp.msg || "Error al eliminar el año académico", 'error');
            }
        },
        error: function(err){
            end_load();
            console.log(err);
            alert_toast("Ocurrió un error", 'error');
        }
    });
}

// --- Bloqueos de Bimestres ---
$(document).on('click', '#btn-bimester-locks', function(){
    // Obtener el ID del año activo
    const activeBtn = $('span.badge-success').closest('tr').find('.edit-year');
    const yearId = activeBtn.length ? activeBtn.data('id') : 0;
    if(!yearId){
        alert_toast('No hay año académico activo para configurar.', 'warning');
        return;
    }
    openBimesterLocksModal(yearId);
});

function openBimesterLocksModal(academicYearId){
    if(!academicYearId){
        alert_toast('No hay año académico activo para configurar.', 'warning');
        return;
    }
    uni_modal('Bloqueos de Bimestres', 'manage_bimester_locks.php?academic_year_id=' + academicYearId, 'mid-large');
}
</script>
