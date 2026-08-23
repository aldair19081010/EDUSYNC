<?php
include 'db_connect.php';
include_once 'includes/session_check.php'; require_login_modal();

// Obtener el school_id del administrador
$school_id = $_SESSION['login_school_id'] ?? 0;

if(isset($_GET['id'])){
    // Verificar que el área pertenezca al colegio del administrador
    $area_qry = $conn->query("SELECT * FROM areas WHERE id = ".$_GET['id']." AND school_id = $school_id");
    if($area_qry->num_rows > 0){
        $area = $area_qry->fetch_assoc();
    } else {
        echo "<div class='alert alert-danger'><i class='fa fa-exclamation-circle'></i> Área no encontrada</div>";
        exit;
    }
} else {
    echo "<div class='alert alert-danger'><i class='fa fa-exclamation-circle'></i> ID de área no especificado</div>";
    exit;
}
?>

<style>
    /* Modal responsivo */
    .modal-xl {
        max-width: 90% !important;
    }
    
    .modal-xl .modal-body {
        padding: 20px;
    }
    
    /* Header del área */
    .area-header-badge {
        display: inline-block;
        padding: 10px 20px;
        border-radius: 0.35rem;
        font-weight: 600;
        color: white;
        font-size: 1.1rem;
        box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    }
    
    .area-description {
        color: #6c757d;
        font-size: 0.95rem;
        margin-top: 8px;
    }
    
    /* Tabla */
    #courses_table {
        font-size: 0.9rem;
    }
    
    #courses_table th {
        background-color: #f8f9fc;
        border-bottom: 2px solid #e3e6f0;
        font-weight: 600;
        color: #858796;
        padding: 12px 8px;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }
    
    #courses_table td {
        padding: 12px 10px;
        vertical-align: middle;
        border-top: 1px solid #e3e6f0;
        color: #5a5c69;
    }
    
    #courses_table tbody tr:hover {
        background-color: rgba(78, 115, 223, 0.05);
    }
    
    .table-responsive {
        border: 1px solid #e3e6f0;
        border-radius: 0.35rem;
    }
    
    /* Badges de nivel */
    .badge-level {
        padding: 5px 10px;
        border-radius: 0.25rem;
        font-size: 0.8rem;
        font-weight: 600;
    }
    
    .badge-inicial {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .badge-primaria {
        background-color: #d1ecf1;
        color: #0c5460;
    }
    
    .badge-secundaria {
        background-color: #d4edda;
        color: #155724;
    }
    
    /* Botones */
    .btn-sm {
        padding: 4px 8px;
        font-size: 0.85rem;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        #courses_table th,
        #courses_table td {
            font-size: 0.8rem;
            padding: 8px 6px;
            white-space: nowrap;
        }
        
        .area-header-badge {
            font-size: 0.95rem;
            padding: 8px 16px;
        }
    }
    
    /* Mensaje vacío */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
    }
    
    .empty-state i {
        font-size: 3rem;
        color: #d1d3d6;
        margin-bottom: 15px;
        display: block;
    }
    
    .empty-state-text {
        color: #6c757d;
        font-size: 1rem;
    }
</style>

<div class="container-fluid">
    <!-- Header del Área -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="area-header-badge" style="background-color: <?php echo htmlspecialchars($area['color']); ?>;">
                <?php echo htmlspecialchars($area['name']); ?>
                <?php if(isset($_GET['level']) && !empty($_GET['level'])): ?>
                    <span style="margin-left: 10px; opacity: 0.9;">- <?php echo htmlspecialchars($_GET['level']); ?></span>
                <?php endif; ?>
            </div>
            <?php if(!empty($area['description'])): ?>
            <p class="area-description">
                <i class="fa fa-align-left"></i> <?php echo htmlspecialchars($area['description']); ?>
            </p>
            <?php endif; ?>
        </div>
        <div class="col-md-4 text-right">
            <button class="btn btn-primary" id="add_course_to_area">
                <i class="fa fa-plus"></i> Agregar Curso
            </button>
        </div>
    </div>
    
    <!-- Tabla de Cursos -->
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fa fa-book"></i> Cursos en esta Área
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="courses_table">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 50px;">#</th>
                                    <th style="min-width: 180px;">Nombre del Curso</th>
                                    <th style="width: 100px;">Nivel</th>
                                    <th style="min-width: 200px;">Descripción</th>
                                    <th class="text-center" style="width: 80px;">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $i = 1;
                                // Construir consulta con filtro de nivel si se proporciona
                                $level_filter = "";
                                if(isset($_GET['level']) && !empty($_GET['level'])) {
                                    $level_filter = " AND level = '" . $conn->real_escape_string($_GET['level']) . "'";
                                }
                                
                                $courses = $conn->query("SELECT * FROM academic_courses 
                                    WHERE area_id = ".$_GET['id']." AND school_id = $school_id" . $level_filter . "
                                    ORDER BY name ASC");
                                    
                                if($courses && $courses->num_rows > 0):
                                    while($row = $courses->fetch_assoc()):
                                ?>
                                <tr>
                                    <td class="text-center"><strong><?php echo $i++ ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-level badge-<?php echo strtolower($row['level']); ?>">
                                            <?php echo htmlspecialchars($row['level']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars($row['description'] ?? '---'); ?></small>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-danger btn-sm remove_course_from_area" type="button" 
                                                data-id="<?php echo $row['id'] ?>" data-name="<?php echo htmlspecialchars($row['name']) ?>">
                                            <i class="fa fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class="fa fa-inbox"></i>
                                            <div class="empty-state-text">
                                                <strong>No hay cursos</strong><br>
                                                <?php if(isset($_GET['level']) && !empty($_GET['level'])): ?>
                                                    No hay cursos de nivel <?php echo htmlspecialchars($_GET['level']); ?> asignados a esta área
                                                <?php else: ?>
                                                    No hay cursos asignados a esta área
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Ocultar los botones del footer del modal para esta vista
    $('#uni_modal .modal-footer').hide();
    
    // Agregar curso al área
    $('#add_course_to_area').click(function(){
        uni_modal("Agregar Curso a <?php echo htmlspecialchars($area['name']); ?>", 
                  "manage_academic_course.php?area_id=<?php echo $_GET['id']; ?>", 
                  "mid-large");
    });
    
    // Listener para cuando se muestre el modal
    $('#uni_modal').on('shown.bs.modal', function() {
        // Asegurar que el botón X esté visible
        $('.modal-header .close').show().css('opacity', '1');
    });
});

// Función para remover curso del área
window.remove_course_from_area = function($id){
    // Cerrar el modal de confirmación primero
    $('#confirm_modal').modal('hide');
    
    start_load();
    $.ajax({
        url: 'ajax.php?action=remove_course_from_area',
        method: 'POST',
        data: {id: $id},
        dataType: 'json',
        success: function(resp){
            end_load();
            if(resp.status == 1){
                alert_toast("Curso removido de la área exitosamente", "success");
                
                // Remover la fila de la tabla dinámicamente
                $('button[data-id="' + $id + '"]').closest('tr').fadeOut(300, function(){
                    $(this).remove();
                    
                    // Verificar si no quedan cursos
                    var remainingRows = $('#courses_table tbody tr:not(:hidden)').length;
                    if(remainingRows === 0) {
                        $('#courses_table tbody').html(`
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="fa fa-inbox"></i>
                                        <div class="empty-state-text">
                                            <strong>No hay cursos</strong><br>
                                            No hay cursos asignados a esta área
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        `);
                    }
                });
                
                // Actualizar todas las vistas de academic_management dinámicamente
                if (typeof window.updateMainTablesSafely === 'function') {
                    window.updateMainTablesSafely();
                }
            } else {
                alert_toast("Error al remover el curso: " + (resp.message || resp.msg || "Error desconocido"), "error");
            }
        },
        error: function(){
            end_load();
            alert_toast("Error de conexión", "error");
        }
    });
};

// Función global para refrescar la tabla de cursos del área
window.refreshAreaCoursesTable = function() {
    location.reload();
};
</script>

<!-- Script para inicializar tooltips después de que cargue Bootstrap -->
<script>
$(function() {
    // Inicializar tooltips con reintentos
    var tooltipAttempts = 0;
    var tooltipInterval = setInterval(function() {
        try {
            if (typeof $.fn.tooltip === 'function') {
                $('[data-toggle="tooltip"]').tooltip({
                    delay: { show: 100, hide: 100 }
                });
                clearInterval(tooltipInterval);
                console.log('Tooltips inicializados correctamente en area_courses');
            } else if (tooltipAttempts++ < 10) {
                console.log('Esperando que Bootstrap cargue...');
            } else {
                console.error('No se pudo cargar Bootstrap Tooltip');
                clearInterval(tooltipInterval);
            }
        } catch(e) {
            console.log('Error al inicializar tooltips:', e);
            if (tooltipAttempts++ >= 10) {
                clearInterval(tooltipInterval);
            }
        }
    }, 100);
});
</script>
