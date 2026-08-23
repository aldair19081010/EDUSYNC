<?php
include 'db_connect.php';

$school_id = $_SESSION['login_school_id'] ?? 0;

// Valores por defecto de horario (pueden venir de configuraciones)
$default_early = "07:30";
$default_late = "08:10";
$early_time = $_POST['early_time'] ?? $default_early;
$late_time = $_POST['late_time'] ?? $default_late;

// Filtros iniciales
$student_id = $_POST['student_id'] ?? '';
$date_from = $_POST['date_from'] ?? date('Y-m-01');
$date_to = $_POST['date_to'] ?? date('Y-m-d');
$nivel = $_POST['nivel'] ?? '';
$grado = $_POST['grado'] ?? '';
$seccion = $_POST['seccion'] ?? '';
$tipo = $_POST['tipo'] ?? '';
$estado = $_POST['estado'] ?? '';

// Consultas para selectores
$students = $conn->query("SELECT id, name, id_no, grado, nivel, seccion FROM student WHERE school_id = $school_id AND status = 'Activo' ORDER BY grado ASC, name ASC");
$niveles = $conn->query("SELECT DISTINCT nivel FROM student WHERE school_id = $school_id AND nivel IS NOT NULL AND nivel != '' AND status = 'Activo' ORDER BY nivel ASC");
$grados = $conn->query("SELECT DISTINCT grado FROM student WHERE school_id = $school_id AND grado IS NOT NULL AND grado != '' AND status = 'Activo' ORDER BY grado ASC");
$secciones = $conn->query("SELECT DISTINCT seccion FROM student WHERE school_id = $school_id AND seccion IS NOT NULL AND seccion != '' AND status = 'Activo' ORDER BY seccion ASC");
?>
<style>
.select2-container { z-index: 10 !important; }
.select2-dropdown { z-index: 9999 !important; }
.select2.select2-container { width: 100% !important; }
.select2-selection__rendered { line-height: 32px !important; }
.select2-selection--single { height: 36px !important; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height: 34px; }
.select2-container--default .select2-selection--single .select2-selection__clear { right: 24px; }
@media print {
  body * { visibility: hidden !important; }
  #attendance-report-table, #attendance-report-table * { visibility: visible !important; }
  #attendance-report-table { position: absolute; left: 0; top: 0; width: 100vw; background: #fff; color: #000; box-shadow: none; }
  .btn, form, .card, .select2-container { display: none !important; }
}
</style>

<div class="container-fluid py-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="fa fa-calendar-check mr-2"></i>Reporte de Asistencia</h1>
        <div class="d-flex align-items-center">
            <button type="button" id="btn-excel-report" class="btn btn-success btn-sm mr-2"><i class="fa fa-file-excel mr-1"></i>Exportar Excel</button>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-filter mr-2"></i>Filtros de búsqueda</h6>
        </div>
        <div class="card-body">
            <form id="attendance-filter">
                <div class="form-row mb-3">
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="date_from">Fecha de inicio</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo $date_from ?>" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="date_to">Fecha de fin</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo $date_to ?>" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="filtro_tipo">Tipo de registro</label>
                        <select name="tipo" id="filtro_tipo" class="form-control form-control-sm" data-placeholder="Seleccione un tipo">
                            <option value=""></option>
                            <option value="Entrada" <?php echo $tipo == 'Entrada' ? 'selected' : '' ?>>Entrada</option>
                            <option value="Salida" <?php echo $tipo == 'Salida' ? 'selected' : '' ?>>Salida</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="filtro_estado">Estado</label>
                        <select name="estado" id="filtro_estado" class="form-control form-control-sm" data-placeholder="Seleccione un estado">
                            <option value=""></option>
                            <option value="Temprano" <?php echo $estado == 'Temprano' ? 'selected' : '' ?>>Temprano</option>
                            <option value="Normal" <?php echo $estado == 'Normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="Tarde" <?php echo $estado == 'Tarde' ? 'selected' : '' ?>>Tarde</option>
                            <option value="Ausente" <?php echo $estado == 'Ausente' ? 'selected' : '' ?>>Ausente</option>
                            <option value="Ausente Justificada" <?php echo $estado == 'Ausente Justificada' ? 'selected' : '' ?>>Ausente Justificada</option>
                            <option value="Presente" <?php echo $estado == 'Presente' ? 'selected' : '' ?>>Presente</option>
                        </select>
                    </div>
                </div>

                <div class="form-row mb-3">
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="filtro_nivel">Nivel</label>
                        <select name="nivel" id="filtro_nivel" class="form-control form-control-sm" data-placeholder="Seleccione un nivel">
                            <option value=""></option>
                            <?php while($n = $niveles->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($n['nivel']) ?>" <?php echo $nivel == $n['nivel'] ? 'selected' : '' ?>><?php echo htmlspecialchars($n['nivel']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="filtro_grado">Grado</label>
                        <select name="grado" id="filtro_grado" class="form-control form-control-sm" data-placeholder="Seleccione un grado">
                            <option value=""></option>
                            <?php while($g = $grados->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($g['grado']) ?>" <?php echo $grado == $g['grado'] ? 'selected' : '' ?>><?php echo htmlspecialchars($g['grado']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="filtro_seccion">Sección</label>
                        <select name="seccion" id="filtro_seccion" class="form-control form-control-sm" data-placeholder="Seleccione una sección">
                            <option value=""></option>
                            <?php while($s = $secciones->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($s['seccion']) ?>" <?php echo $seccion == $s['seccion'] ? 'selected' : '' ?>><?php echo htmlspecialchars($s['seccion']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="filtro_alumno">Estudiante</label>
                        <select name="student_id" id="filtro_alumno" class="form-control form-control-sm" data-placeholder="Seleccione un alumno">
                            <option value=""></option>
                            <?php mysqli_data_seek($students, 0); while($stu = $students->fetch_assoc()): ?>
                                <option value="<?php echo $stu['id'] ?>" <?php echo $student_id == $stu['id'] ? 'selected' : '' ?>>
                                    <?php echo ucwords($stu['name']) . " ({$stu['id_no']}) - " . $stu['grado'] ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm btn-block"><i class="fas fa-search mr-2"></i>Buscar</button>
                    </div>
                </div>
            </form>
            <form id="export-form" action="export_attendance_excel.php" method="GET" target="_blank" style="display:none;"></form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-list mr-2"></i>Resultados</h6>
        </div>
        <div class="card-body">
            <div id="attendance-report-table" class="table-responsive"></div>
        </div>
    </div>
</div>

<script>
function initializeSelectors() {
    if (typeof $.fn.select2 === 'function') {
        $('.form-control-sm').each(function() {
            if ($(this).data('select2')) { $(this).select2('destroy'); }
        });
        $('#filtro_nivel, #filtro_grado, #filtro_seccion, #filtro_tipo, #filtro_estado').select2({
            placeholder: 'Seleccione una opción', allowClear: true, width: '100%', dropdownParent: $('body'), minimumResultsForSearch: 10
        });
        $('#filtro_alumno').select2({
            placeholder: 'Seleccione un alumno', allowClear: true, width: '100%', dropdownParent: $('body'),
            language: { noResults: () => 'No se encontraron resultados', searching: () => 'Buscando...' }
        });
    }
}

$(document).ready(function() {
    initializeSelectors();

    $('#attendance-filter').submit(function(e) {
        e.preventDefault();
        if (typeof start_load === 'function') { start_load(); }
        $.ajax({
            url: 'attendance_report_table.php',
            method: 'POST',
            data: $(this).serialize(),
            success: function(resp) {
                $('#attendance-report-table').html(resp);
                if (typeof end_load === 'function') { end_load(); }
            },
            error: function() {
                if (typeof alert_toast === 'function') { alert_toast('Error al cargar el reporte.', 'danger'); }
                if (typeof end_load === 'function') { end_load(); }
            }
        });
    });

    $('#btn-excel-report').on('click', function() {
        var params = $('#attendance-filter').serializeArray();
        var $form = $('#export-form');
        $form.empty();
        params.forEach(function(p){ $('<input>', { type: 'hidden', name: p.name, value: p.value }).appendTo($form); });
        try { if (typeof alert_toast === 'function') { alert_toast('Generando Excel…', 'info'); } } catch(e) {}
        $form.trigger('submit');
    });
});
</script>
