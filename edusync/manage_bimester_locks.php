<?php
include 'db_connect.php';

// Alinear configuración de sesión con ajax.php
if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) session_start();

// No necesita validar sesión aquí - ya fue validada en academic_year.php
// Si llegó a este punto, ya tiene permisos de admin

$academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;
?>

<div class="container-fluid p-4">
    <?php if (!$academic_year_id): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-triangle mr-2"></i>
            No hay un año académico activo para configurar.
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php else: ?>
    
    <div class="card border-left-primary">
        <div class="card-header bg-light py-3">
            <h5 class="m-0 font-weight-bold text-primary">
                <i class="fa fa-lock mr-2"></i> Configuración de Bloqueos de Bimestres
            </h5>
        </div>
        
        <div class="card-body">
            <p class="text-muted mb-4">
                <i class="fa fa-info-circle mr-2"></i>
                Seleccione los bimestres que desea bloquear. Los docentes no podrán editar calificaciones en bimestres bloqueados.
            </p>
            
            <form id="form-bimester-locks">
                <input type="hidden" name="academic_year_id" value="<?php echo $academic_year_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="form-group">
                    <div class="custom-control custom-switch mb-3">
                        <input type="checkbox" class="custom-control-input" id="lock1" name="lock_1" value="1">
                        <label class="custom-control-label" for="lock1">
                            <strong>Bloquear 1° Bimestre</strong>
                            <br>
                            <small class="text-muted">Los docentes no podrán editar calificaciones del 1° bimestre</small>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="custom-control custom-switch mb-3">
                        <input type="checkbox" class="custom-control-input" id="lock2" name="lock_2" value="1">
                        <label class="custom-control-label" for="lock2">
                            <strong>Bloquear 2° Bimestre</strong>
                            <br>
                            <small class="text-muted">Los docentes no podrán editar calificaciones del 2° bimestre</small>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="custom-control custom-switch mb-3">
                        <input type="checkbox" class="custom-control-input" id="lock3" name="lock_3" value="1">
                        <label class="custom-control-label" for="lock3">
                            <strong>Bloquear 3° Bimestre</strong>
                            <br>
                            <small class="text-muted">Los docentes no podrán editar calificaciones del 3° bimestre</small>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="lock4" name="lock_4" value="1">
                        <label class="custom-control-label" for="lock4">
                            <strong>Bloquear 4° Bimestre</strong>
                            <br>
                            <small class="text-muted">Los docentes no podrán editar calificaciones del 4° bimestre</small>
                        </label>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="form-group text-right">
                    <button type="button" class="btn btn-secondary mr-2" data-dismiss="modal">
                        <i class="fa fa-times mr-2"></i>Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save mr-2"></i>Guardar Configuración
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<script>
(function(){
    var ay = <?php echo json_encode($academic_year_id); ?>;
    if(!ay){ return; }
    
    // Cargar estado actual de bloqueos
    $.getJSON('ajax.php?action=get_bimester_locks', { academic_year_id: ay })
        .done(function(resp){
            if(resp && resp.status==1 && resp.locks){
                $('#lock1').prop('checked', !!resp.locks[1]);
                $('#lock2').prop('checked', !!resp.locks[2]);
                $('#lock3').prop('checked', !!resp.locks[3]);
                $('#lock4').prop('checked', !!resp.locks[4]);
            } else {
                alert_toast('No se pudieron cargar los bloqueos actuales.', 'warning');
            }
        })
        .fail(function(){
            alert_toast('Error al cargar bloqueos actuales.', 'danger');
        });

    // Guardar bloqueos
    $(document).off('submit', '#form-bimester-locks').on('submit', '#form-bimester-locks', function(e){
        e.preventDefault();
        start_load();
        
        var payload = $(this).serializeArray();
        var present = new Set(payload.map(function(p){ return p.name; }));
        
        // Asegurar que todos los bloqueos estén en el payload
        ['lock_1','lock_2','lock_3','lock_4'].forEach(function(n){ 
            if(!present.has(n)) {
                payload.push({name:n, value:0}); 
            }
        });
        
        $.ajax({
            url: 'ajax.php?action=save_bimester_locks',
            method: 'POST',
            data: payload,
            dataType: 'json'
        }).done(function(r){
            end_load();
            if(r && r.status==1){
                alert_toast('Bloqueos guardados exitosamente', 'success');
                setTimeout(function(){
                    $('#uni_modal').modal('hide');
                }, 500);
            } else {
                alert_toast((r && r.msg) || 'Error al guardar bloqueos', 'danger');
            }
        }).fail(function(){
            end_load();
            alert_toast('Error en el servidor', 'danger');
        });
        
        return false;
    });
})();
</script>
