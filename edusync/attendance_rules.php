<?php 
$is_direct_access = !defined('INCLUDED_IN_WRAPPER') && basename($_SERVER['PHP_SELF']) === basename(__FILE__);

if ($is_direct_access) {
    include('session_check.php'); 
    include('db_connect.php');
    include('header.php');
    require_user_type(1);
}

if ($is_direct_access && isset($_GET['debug'])) {
?>
<div class="alert alert-info alert-dismissible fade show mb-0" role="alert">
    <strong>Información de sesión:</strong> 
    Usuario tipo <?php echo $_SESSION['login_type']; ?> (<?php echo $_SESSION['login_type'] == 1 ? 'Admin' : 'No Admin'; ?>)
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<?php } ?>

<?php
// Obtener configuración general
$settings = [];
$school_id = intval($_SESSION['login_school_id'] ?? 0);
$has_settings_school = false;
$has_rules_school = false;
$col_settings = $conn->query("SHOW COLUMNS FROM attendance_settings LIKE 'school_id'");
if ($col_settings && $col_settings->num_rows > 0) {
    $has_settings_school = true;
}
$col_rules = $conn->query("SHOW COLUMNS FROM attendance_rules LIKE 'school_id'");
if ($col_rules && $col_rules->num_rows > 0) {
    $has_rules_school = true;
}

$settings_sql = "SELECT * FROM attendance_settings";
if ($has_settings_school) {
    $settings_sql .= " WHERE school_id = $school_id";
}
$settings_qry = $conn->query($settings_sql);
if($settings_qry && $settings_qry->num_rows > 0){
    while($row = $settings_qry->fetch_assoc()){
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$default_early_time = $settings['default_early_time'] ?? '07:00';
$default_late_time = $settings['default_late_time'] ?? '07:30';
$use_day_rules = $settings['use_day_rules'] ?? '1';

// Obtener reglas por día
$rules = [];
$rules_sql = "SELECT * FROM attendance_rules";
if ($has_rules_school) {
    $rules_sql .= " WHERE school_id = $school_id";
}
$rules_sql .= " ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
$rules_qry = $conn->query($rules_sql);
if($rules_qry && $rules_qry->num_rows > 0){
    while($row = $rules_qry->fetch_assoc()){
        $rules[$row['day_of_week']] = $row;
    }
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-0 text-gray-800">
                <i class="fa fa-clock mr-2"></i>
                <?php echo isset($page_title) ? $page_title : 'Configuración de Reglas de Asistencia'; ?>
            </h1>
            <p class="text-muted small mb-0 mt-1">
                <i class="fa fa-info-circle"></i> Configure los horarios de temprano y tarde por día o de forma general
            </p>
        </div>
        <button class="btn btn-primary btn-sm" id="save-settings">
            <i class="fa fa-save"></i> Guardar Configuración
        </button>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Modo de configuración</h6>
        </div>
        <div class="card-body">
            <div class="btn-group btn-group-toggle mb-3" data-toggle="buttons">
                <label class="btn btn-outline-primary <?php echo $use_day_rules == '0' ? 'active' : '' ?>" id="toggle-general">
                    <input type="radio" name="options"> General
                </label>
                <label class="btn btn-outline-primary <?php echo $use_day_rules == '1' ? 'active' : '' ?>" id="toggle-day">
                    <input type="radio" name="options"> Por Día
                </label>
            </div>
            <small class="text-muted d-block">Seleccione si desea reglas generales o específicas por día de la semana.</small>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Opciones Generales</h6>
        </div>
        <div class="card-body">
            <form id="settings-form">
                <div class="form-group">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="use_day_rules" name="use_day_rules" <?php echo $use_day_rules == '1' ? 'checked' : '' ?>>
                        <label class="custom-control-label" for="use_day_rules">Usar reglas específicas por día de la semana</label>
                    </div>
                    <small class="form-text text-muted">Si esta opción está activada, se aplicarán las reglas específicas para cada día. De lo contrario, se usará la configuración general.</small>
                </div>

                <div id="general-settings" class="mt-4" <?php echo $use_day_rules == '1' ? 'style="display:none;"' : '' ?>>
                    <h6 class="font-weight-bold mb-3">Configuración General de Horarios</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="default_early_time" class="font-weight-bold">Hora Temprano (General)</label>
                                <input type="time" class="form-control" id="default_early_time" name="default_early_time" value="<?php echo $default_early_time ?>">
                                <small class="form-text text-muted">Cualquier entrada hasta esta hora se considera <strong>Temprano</strong>.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="default_late_time" class="font-weight-bold">Hora Límite (General)</label>
                                <input type="time" class="form-control" id="default_late_time" name="default_late_time" value="<?php echo $default_late_time ?>">
                                <small class="form-text text-muted">Cualquier entrada después de esta hora se considera <strong>Tarde</strong>.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="day-rules" <?php echo $use_day_rules == '0' ? 'style="display:none;"' : '' ?>>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Reglas por Día de la Semana</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php
                    $days_of_week = [
                        'Monday' => 'Lunes',
                        'Tuesday' => 'Martes',
                        'Wednesday' => 'Miércoles',
                        'Thursday' => 'Jueves',
                        'Friday' => 'Viernes',
                        'Saturday' => 'Sábado',
                        'Sunday' => 'Domingo'
                    ];
                    foreach($days_of_week as $day_en => $day_es):
                        $rule = $rules[$day_en] ?? null;
                        $is_active = $rule ? $rule['is_active'] : 0;
                        $early_time = $rule ? $rule['early_time'] : '07:00:00';
                        $late_time = $rule ? $rule['late_time'] : '07:30:00';
                    ?>
                    <div class="col-md-4 mb-4">
                        <div class="card border-left-<?php echo $is_active ? 'success' : 'danger' ?> shadow h-100">
                            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-<?php echo $is_active ? 'success' : 'danger' ?>">
                                    <?php echo $day_es ?>
                                </h6>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input day-status" 
                                        id="status_<?php echo $day_en ?>" 
                                        data-day="<?php echo $day_en ?>" 
                                        <?php echo $is_active ? 'checked' : '' ?>>
                                    <label class="custom-control-label" for="status_<?php echo $day_en ?>"></label>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label class="small font-weight-bold">Hora Temprano</label>
                                    <input type="time" class="form-control form-control-sm day-early-time" 
                                        id="early_<?php echo $day_en ?>" 
                                        data-day="<?php echo $day_en ?>" 
                                        value="<?php echo $early_time ?>">
                                </div>
                                <div class="form-group">
                                    <label class="small font-weight-bold">Hora Límite</label>
                                    <input type="time" class="form-control form-control-sm day-late-time" 
                                        id="late_<?php echo $day_en ?>" 
                                        data-day="<?php echo $day_en ?>" 
                                        value="<?php echo $late_time ?>">
                                </div>
                                <div class="mb-3">
                                    <span class="small font-weight-bold">Estado:</span>
                                    <span class="badge <?php echo $is_active ? 'badge-success' : 'badge-danger' ?> status-badge-<?php echo $day_en ?>">
                                        <?php echo $is_active ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm btn-block save-day-rule" data-day="<?php echo $day_en ?>">
                                    <i class="fa fa-save"></i> Guardar
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#toggle-general').click(function(){
        $('#use_day_rules').prop('checked', false).trigger('change');
        $(this).addClass('active');
        $('#toggle-day').removeClass('active');
    });

    $('#toggle-day').click(function(){
        $('#use_day_rules').prop('checked', true).trigger('change');
        $(this).addClass('active');
        $('#toggle-general').removeClass('active');
    });

    $('#use_day_rules').change(function(){
        if($(this).is(':checked')){
            $('#general-settings').hide();
            $('#day-rules').show();
            $('#toggle-day').addClass('active');
            $('#toggle-general').removeClass('active');
        } else {
            $('#general-settings').show();
            $('#day-rules').hide();
            $('#toggle-general').addClass('active');
            $('#toggle-day').removeClass('active');
        }
    });

    $('#save-settings').click(function(){
        let use_day_rules = $('#use_day_rules').is(':checked') ? 1 : 0;
        let default_early_time = $('#default_early_time').val();
        let default_late_time = $('#default_late_time').val();
        
        if(default_early_time >= default_late_time){
            alert_toast('La hora temprano debe ser menor que la hora límite', 'warning');
            return false;
        }
        
        start_load();
        $.ajax({
            url: 'ajax.php?action=save_attendance_settings',
            method: 'POST',
            data: {
                use_day_rules: use_day_rules,
                default_early_time: default_early_time,
                default_late_time: default_late_time
            },
            success: function(resp){
                if(resp == 1){
                    alert_toast('Configuración guardada correctamente', 'success');
                } else {
                    alert_toast('Error al guardar la configuración', 'danger');
                }
                end_load();
            },
            error: function(){
                alert_toast('Error al guardar la configuración', 'danger');
                end_load();
            }
        });
    });

    $('.save-day-rule').click(function(){
        let day = $(this).data('day');
        let is_active = $('#status_'+day).is(':checked') ? 1 : 0;
        let early_time = $('#early_'+day).val();
        let late_time = $('#late_'+day).val();
        
        if(early_time >= late_time){
            alert_toast('La hora temprano debe ser menor que la hora límite', 'warning');
            return false;
        }
        
        start_load();
        $.ajax({
            url: 'ajax.php?action=save_day_attendance_rule',
            method: 'POST',
            data: {
                day_of_week: day,
                is_active: is_active,
                early_time: early_time,
                late_time: late_time
            },
            success: function(resp){
                if(resp == 1){
                    alert_toast('Regla guardada correctamente', 'success');
                    $('.status-badge-'+day).removeClass('badge-success badge-danger')
                        .addClass(is_active ? 'badge-success' : 'badge-danger')
                        .text(is_active ? 'Activo' : 'Inactivo');
                    
                    $('#status_'+day).closest('.card').removeClass('border-left-success border-left-danger')
                        .addClass(is_active ? 'border-left-success' : 'border-left-danger');
                    $('#status_'+day).closest('.card-header').find('h6')
                        .removeClass('text-success text-danger')
                        .addClass(is_active ? 'text-success' : 'text-danger');
                } else {
                    alert_toast('Error al guardar la regla', 'danger');
                }
                end_load();
            },
            error: function(){
                alert_toast('Error al guardar la regla', 'danger');
                end_load();
            }
        });
    });

    $('.day-status').change(function(){
        let day = $(this).data('day');
        let is_active = $(this).is(':checked');
        
        $('.status-badge-'+day).removeClass('badge-success badge-danger')
            .addClass(is_active ? 'badge-success' : 'badge-danger')
            .text(is_active ? 'Activo' : 'Inactivo');
        
        $(this).closest('.card').removeClass('border-left-success border-left-danger')
            .addClass(is_active ? 'border-left-success' : 'border-left-danger');
        $(this).closest('.card-header').find('h6')
            .removeClass('text-success text-danger')
            .addClass(is_active ? 'text-success' : 'text-danger');
    });
});
</script>

<?php 
if ($is_direct_access) {
    include('footer.php');
}
?>
