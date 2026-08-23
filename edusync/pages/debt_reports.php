<?php
include 'db_connect.php';

$school_id = $_SESSION['login_school_id'] ?? 0;

$active_year_q = $conn->query("SELECT id FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
$active_year_id = ($active_year_q && $active_year_q->num_rows > 0) ? $active_year_q->fetch_assoc()['id'] : '';
$years_q = $conn->query("SELECT id, year, is_active FROM academic_year WHERE school_id = $school_id ORDER BY year DESC");

// Consultas para selectores
$students = $conn->query("SELECT id, name, id_no, nivel, grado FROM student WHERE school_id = $school_id ORDER BY name ASC");
$conceptos = $conn->query("SELECT c.id, c.course, c.level, c.grades, ay.year, ay.id as year_id,
                          CONCAT(c.course, ' - ', IFNULL(c.level,''), ' - ', IFNULL(c.grades,''), ' - ', COALESCE(ay.year, 'Sin año')) as concepto_concatenado
                          FROM courses c 
                          LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
                          WHERE ay.school_id = $school_id
                          ORDER BY c.course ASC, c.level ASC, c.grades ASC, ay.year DESC");
?>

<style>
.select2-container {
    z-index: 10 !important;
}

.select2-dropdown {
    z-index: 9999 !important;
}

.select2-container--open .select2-dropdown {
    margin-top: 2px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
    border-color: #4e73df;
}

.select2-results__option {
    padding: 8px 12px !important;
}

.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #4e73df !important;
    color: white !important;
}

.select2.select2-container {
    width: 100% !important;
}

.select2-container--default .select2-results__option:hover {
    background-color: #f5f8ff;
}

.select2-selection__rendered {
    line-height: 31px !important;
}

.select2-selection--single {
    height: 35px !important;
}

.animated {
    animation-duration: 0.5s;
    animation-fill-mode: both;
}

.fadeIn {
    animation-name: fadeIn;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@media (max-width: 768px) {
    .select2-container {
        width: 100% !important;
    }
}
</style>

<div class="container-fluid py-4">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-file-invoice-dollar mr-2"></i>Reporte de Deudas</h1>
        <div class="d-flex align-items-center">
            <button type="button" id="print-report" class="btn btn-success btn-sm mr-2">
                <i class="fas fa-print mr-1"></i>Imprimir
            </button>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-filter mr-2"></i>Filtros de búsqueda</h6>
        </div>
        <div class="card-body">
                <div class="form-row mb-3">
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="academic_year_id">Año Académico</label>
                        <select id="academic_year_id" class="form-control form-control-sm" data-placeholder="Todos">
                            <option value=""></option>
                            <?php
                            mysqli_data_seek($years_q, 0);
                            while($row = $years_q->fetch_assoc()):
                            ?>
                            <option value="<?php echo $row['id']; ?>">
                                <?php echo htmlspecialchars($row['year']); ?><?php echo $row['is_active'] ? ' (Activo)' : ''; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="nivel">Nivel</label>
                        <select id="nivel" class="form-control form-control-sm" data-placeholder="Todos">
                            <option value=""></option>
                            <option value="Inicial">Inicial</option>
                            <option value="Primaria">Primaria</option>
                            <option value="Secundaria">Secundaria</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="grado">Grado</label>
                        <select id="grado" class="form-control form-control-sm" data-placeholder="Todos">
                            <option value=""></option>
                            <?php 
                            $grados = ["1°", "2°", "3°", "4°", "5°", "6°"];
                            foreach($grados as $grado_item): 
                            ?>
                            <option value="<?php echo $grado_item; ?>"><?php echo $grado_item; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="seccion">Sección</label>
                        <select id="seccion" class="form-control form-control-sm" data-placeholder="Todas">
                            <option value=""></option>
                            <?php 
                            $secciones = ["A", "B", "C", "D", "U"];
                            foreach($secciones as $seccion_item): 
                            ?>
                            <option value="<?php echo $seccion_item; ?>"><?php echo $seccion_item; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row mb-3">
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="start_date">Fecha Inicio</label>
                        <input type="date" id="start_date" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="end_date">Fecha Fin</label>
                        <input type="date" id="end_date" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="student_status">Estado del Estudiante</label>
                        <select id="student_status" class="form-control form-control-sm" data-placeholder="Todos">
                            <option value=""></option>
                            <option value="Activo">Activo</option>
                            <option value="Retirado">Retirado</option>
                            <option value="Egresado">Egresado</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="small font-weight-bold" for="student_id">Alumno</label>
                        <select id="student_id" class="form-control form-control-sm" data-placeholder="Todos">
                            <option value=""></option>
                            <?php
                            mysqli_data_seek($students, 0);
                            while ($row = $students->fetch_assoc()):
                            ?>
                            <option value="<?php echo $row['id']; ?>">
                                <?php echo htmlspecialchars($row['id_no'] . ' - ' . ucwords($row['name']) . ' (' . $row['nivel'] . ' - ' . $row['grado'] . ')'); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row mb-3">
                    <div class="col-md-8 mb-3">
                        <label class="small font-weight-bold" for="concepto">Concepto de Pago</label>
                        <select id="concepto" class="form-control form-control-sm select2-concept" data-placeholder="Todos">
                            <option value=""></option>
                            <?php
                            mysqli_data_seek($conceptos, 0);
                            while ($row = $conceptos->fetch_assoc()):
                            ?>
                            <option value="<?php echo $row['id']; ?>"
                                    data-year-id="<?php echo $row['year_id']; ?>" 
                                    data-level="<?php echo htmlspecialchars($row['level'] ?? ''); ?>" 
                                    data-grades="<?php echo htmlspecialchars($row['grades'] ?? ''); ?>">
                                <?php echo htmlspecialchars($row['concepto_concatenado']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3 text-right">
                        <label class="small font-weight-bold d-block">&nbsp;</label>
                        <button type="button" id="generate-report" class="btn btn-primary btn-sm mr-2">
                            <i class="fas fa-search mr-1"></i>Generar Reporte
                        </button>
                        <button type="button" id="clear-filters" class="btn btn-secondary btn-sm">
                            <i class="fas fa-undo mr-1"></i>Limpiar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Results Card -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-table mr-2"></i>Resultados del Reporte (Solo Deudas Pendientes)</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive" id="report-container">
                <table class="table table-hover table-bordered table-sm" id="debt_reports_table" style="width:100%;">
                    <thead class="thead-light">
                        <tr>
                            <th class="text-center" width="5%">#</th>
                            <th width="20%">Alumno</th>
                            <th width="10%">Estado</th>
                            <th width="20%">Concepto</th>
                            <th class="text-right" width="15%">Monto Total</th>
                            <th class="text-right" width="15%">Pagado</th>
                            <th class="text-right" width="15%">Deuda</th>
                        </tr>
                    </thead>
                    <tbody class="animated fadeIn">
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-info-circle mr-2"></i>Seleccione filtros y presione "Generar Reporte"
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="font-weight-bold">
                            <th colspan="4" class="text-right">Total General:</th>
                            <th class="text-right text-primary" id="total-amount">S/ 0.00</th>
                            <th class="text-right text-success" id="total-paid">S/ 0.00</th>
                            <th class="text-right text-danger" id="total-debt">S/ 0.00</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    console.log("Inicializando reporte de deudas...");
    console.log("jQuery version:", $.fn.jquery);
    console.log("Select2 disponible:", typeof $.fn.select2 === 'function');
    console.log("DataTables disponible:", typeof $.fn.DataTable === 'function');

    // Función para cargar Select2 si no está disponible
    function ensureSelect2Loaded(callback) {
        if (typeof $.fn.select2 !== 'function') {
            console.warn("Select2 no está cargado, intentando cargar la librería...");
            
            if ($('link[href*="select2.min.css"]').length === 0) {
                $('head').append('<link href="assets/css/select2.min.css" rel="stylesheet">');
            }
            
            $.getScript('assets/js/select2.min.js', function() {
                console.log("Select2 cargado dinámicamente");
                if (callback && typeof callback === 'function') {
                    callback();
                }
            }).fail(function(jqxhr, settings, exception) {
                console.error("Error al cargar Select2:", exception);
            });
        } else if (callback && typeof callback === 'function') {
            callback();
        }
    }

    // Inicializar Select2
    ensureSelect2Loaded(function() {
        initializeSelectors();
    });

    // Función para inicializar selectores
    function initializeSelectors() {
        if (typeof $.fn.select2 === 'function') {
            console.log("Inicializando selectores con Select2...");
            
            $('.form-control-sm').each(function() {
                if ($(this).data('select2')) {
                    $(this).select2('destroy');
                }
            });
            
            $('#student_id').select2({
                placeholder: 'Seleccione un alumno',
                allowClear: true,
                width: '100%',
                dropdownParent: $('body'),
                language: {
                    noResults: () => 'No se encontraron resultados',
                    searching: () => 'Buscando...'
                }
            });
            
            $('#concepto').select2({
                placeholder: 'Seleccione un concepto',
                allowClear: true,
                width: '100%',
                dropdownParent: $('body'),
                language: {
                    noResults: () => 'No se encontraron conceptos'
                }
            });
            
            $('#academic_year_id, #nivel, #grado, #seccion, #student_status').each(function() {
                $(this).select2({
                    placeholder: $(this).data('placeholder') || 'Todos',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('body'),
                    minimumResultsForSearch: 10
                });
            });

            setupDynamicConceptsLogic();
        }
    }

    function setupDynamicConceptsLogic() {
        var allConcepts = [];
        // Guardamos todo en memoria la primera vez
        if (window.allConceptsStored) return;
        
        $('#concepto option').each(function() {
            if ($(this).val() !== "") {
                allConcepts.push({
                    id: $(this).val(),
                    text: $(this).text(),
                    year_id: $(this).data('year-id'),
                    level: $(this).data('level'),
                    grades: $(this).data('grades'),
                    selected: $(this).is(':selected')
                });
            }
        });
        window.allConceptsStored = allConcepts;

        // Escuchar cambios
        $('#academic_year_id, #nivel, #grado').on('change', function() {
            updateConceptsDropdown();
        });

        // Aplicamos el primer filtrado en la inicialización
        updateConceptsDropdown();
    }

    function updateConceptsDropdown() {
        var selYear = $('#academic_year_id').val();
        var selLevel = $('#nivel').val();
        var selGrado = $('#grado').val();
        
        var $sel = $('#concepto');
        var currentVal = $sel.val();
        
        $sel.empty();
        $sel.append('<option value=""></option>');
        
        window.allConceptsStored.forEach(function(c) {
            var show = true;
            if (selLevel && c.level && c.level !== selLevel) show = false;
            if (show && selGrado && c.grades && c.grades.indexOf(selGrado) === -1) show = false;
            if (show && selYear && c.year_id && c.year_id != selYear) show = false;
            
            if (show) {
                var isSelected = (currentVal == c.id || c.selected) ? ' selected' : '';
                $sel.append('<option value="' + c.id + '"' + isSelected + '>' + c.text + '</option>');
            }
        });
        
        if ($sel.hasClass("select2-hidden-accessible")) {
            $sel.select2('destroy');
        }
        $sel.select2({
            placeholder: 'Todos',
            allowClear: true,
            width: '100%',
            dropdownParent: $('body'),
            language: { noResults: () => 'No se encontraron conceptos' }
        });
    }

    // Función para inicializar DataTable
    function initializeDataTable() {
        try {
            if ($.fn.DataTable.isDataTable('#debt_reports_table')) {
                $('#debt_reports_table').DataTable().destroy();
            }
            
            $('#debt_reports_table').DataTable({
                language: {
                    emptyTable: 'No hay registros de deudas',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                    infoEmpty: 'Mostrando 0 a 0 de 0 registros',
                    infoFiltered: '(filtrado de _MAX_ registros totales)',
                    lengthMenu: 'Mostrar _MENU_ registros por página',
                    loadingRecords: 'Cargando...',
                    processing: 'Procesando...',
                    search: 'Buscar:',
                    zeroRecords: 'No se encontraron registros coincidentes',
                    paginate: {
                        first: 'Primero',
                        last: 'Último',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    }
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'Todos']],
                order: [[6, 'desc']],
                columnDefs: [
                    { orderable: false, targets: 0 }
                ],
                responsive: true
            });
            
            setTimeout(function() {
                $('.table tbody tr').each(function(index) {
                    $(this).css('opacity', 0);
                    $(this).delay(index * 50).animate({ opacity: 1 }, 300);
                });
            }, 300);
        } catch (e) {
            console.error("Error al inicializar DataTable:", e);
        }
    }

    // Reposicionar dropdowns de Select2 al hacer scroll
    $(window).on('scroll resize', function() {
        if ($('.select2-container--open').length > 0) {
            var selectId = $('.select2-container--open').attr('aria-owns');
            if (selectId) {
                selectId = selectId.replace('select2-', '').replace('-results', '');
                var select = $('#' + selectId);
                
                if (select.length > 0) {
                    select.select2('close');
                    select.select2('open');
                }
            }
        }
    });

    // Verificar inicialización después de un tiempo
    setTimeout(function() {
        if ($('#student_id').length > 0 && !$('#student_id').data('select2')) {
            console.error("Los selectores no se inicializaron correctamente. Intentando nuevamente...");
            ensureSelect2Loaded(function() {
                initializeSelectors();
            });
        } else {
            console.log("Selectores inicializados correctamente.");
        }
    }, 2000);

    // Generar reporte
    $('#generate-report').click(function() {
        if (typeof $.fn.select2 === 'function') {
            generateReport();
        } else {
            ensureSelect2Loaded(function() {
                initializeSelectors();
                generateReport();
            });
        }
    });

    // Imprimir reporte
    $('#print-report').click(function() {
        if (typeof $.fn.select2 === 'function') {
            printReport();
        } else {
            ensureSelect2Loaded(function() {
                initializeSelectors();
                printReport();
            });
        }
    });

    function generateReport() {
        start_load();
        
        const student_id = $('#student_id').val();
        const academic_year_id = $('#academic_year_id').val();
        const nivel = $('#nivel').val();
        const grado = $('#grado').val();
        const seccion = $('#seccion').val();
        const concepto = $('#concepto').val();
        const start_date = $('#start_date').val();
        const end_date = $('#end_date').val();
        const student_status = $('#student_status').val();
        
        console.log("Generando reporte con filtros:", {
            student_id, academic_year_id, nivel, grado, seccion, concepto, start_date, end_date, student_status
        });
        
        if ($.fn.DataTable && $.fn.DataTable.isDataTable('#debt_reports_table')) {
            $('#debt_reports_table').DataTable().destroy();
        }
        
        $.ajax({
            url: 'api/debt_reports.php',
            method: 'GET',
            data: { 
                type: 'general',
                academic_year_id,
                student_id, 
                nivel, 
                grado, 
                seccion, 
                concepto,
                start_date, 
                end_date,
                status: student_status
            },
            success: function(response) {
                console.log("Respuesta de API recibida:", response);
                
                if (typeof response === 'string') {
                    try {
                        response = JSON.parse(response);
                    } catch (e) {
                        console.error("Error al parsear respuesta:", e);
                    }
                }
                
                if (response && response.status === 'ok') {
                    const tbody = $('#debt_reports_table tbody');
                    tbody.empty();
                    
                    let totalAmount = 0;
                    let totalPaid = 0;
                    let totalDebt = 0;
                    
                    if (!response.data || response.data.length === 0) {
                        tbody.append(`
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-exclamation-circle text-warning mr-2"></i> 
                                    No se encontraron resultados con los filtros seleccionados.
                                </td>
                            </tr>
                        `);
                        
                        $('#total-amount').text('S/ 0.00');
                        $('#total-paid').text('S/ 0.00');
                        $('#total-debt').text('S/ 0.00');
                    } else {
                        const filteredData = response.data.filter(row => {
                            const debt = parseFloat(row.deuda) || 0;
                            return debt > 0;
                        });
                        
                        if (filteredData.length === 0) {
                            tbody.append(`
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-info-circle text-info mr-2"></i> 
                                        No se encontraron deudas pendientes con los filtros seleccionados.
                                    </td>
                                </tr>
                            `);
                            
                            $('#total-amount').text('S/ 0.00');
                            $('#total-paid').text('S/ 0.00');
                            $('#total-debt').text('S/ 0.00');
                            
                            end_load();
                            return;
                        }
                        
                        filteredData.forEach((row, index) => {
                            const amount = (row.discounted_amount && parseFloat(row.discounted_amount) < parseFloat(row.total_fee))
                                ? parseFloat(row.discounted_amount)
                                : parseFloat(row.total_fee);
                            const paid = parseFloat(row.pagado) || 0;
                            const debt = parseFloat(row.deuda) || 0;

                            totalAmount += amount;
                            totalPaid += paid;
                            totalDebt += debt;

                            let amountHtml = '';
                            if (row.discounted_amount && parseFloat(row.discounted_amount) < parseFloat(row.total_fee)) {
                                amountHtml = `<span style='text-decoration:line-through;color:#888;font-size:0.85em;'>S/ ${formatNumber(row.total_fee)}</span><br>`;
                                amountHtml += `<span class='badge badge-info'>S/ ${formatNumber(amount)}</span>`;
                            } else {
                                amountHtml = `S/ ${formatNumber(amount)}`;
                            }

                            let statusClass = 'secondary';
                            if (row.status === 'Activo') statusClass = 'success';
                            if (row.status === 'Retirado') statusClass = 'danger';
                            if (row.status === 'Egresado') statusClass = 'info';
                            
                            tbody.append(`
                                <tr class="animated fadeIn">
                                    <td class="text-center align-middle">${index + 1}</td>
                                    <td class="align-middle">${htmlEscape(row.student_name)}</td>
                                    <td class="align-middle">
                                        <span class="badge badge-${statusClass}">${row.status || 'Activo'}</span>
                                    </td>
                                    <td class="align-middle">${htmlEscape(row.concepto)}</td>
                                    <td class="text-right align-middle">${amountHtml}</td>
                                    <td class="text-right align-middle">S/ ${formatNumber(paid)}</td>
                                    <td class="text-right align-middle">
                                        <span class="badge badge-danger">S/ ${formatNumber(debt)}</span>
                                    </td>
                                </tr>
                            `);
                        });
                        
                        $('#total-amount').text('S/ ' + formatNumber(totalAmount));
                        $('#total-paid').text('S/ ' + formatNumber(totalPaid));
                        $('#total-debt').text('S/ ' + formatNumber(totalDebt));
                        
                        if ($.fn.DataTable) {
                            initializeDataTable();
                        } else {
                            setTimeout(function() {
                                $('.table tbody tr').each(function(index) {
                                    $(this).css('opacity', 0);
                                    $(this).delay(index * 50).animate({ opacity: 1 }, 300);
                                });
                            }, 300);
                        }
                    }
                } else {
                    const errorMsg = response.message || 'Error desconocido';
                    console.error('Error al generar el reporte:', errorMsg);
                    alert_toast('Error al generar el reporte: ' + errorMsg, 'danger');
                    
                    $('#debt_reports_table tbody').html(`
                        <tr>
                            <td colspan="7" class="text-center text-danger py-4">
                                <i class="fas fa-exclamation-triangle mr-2"></i> 
                                Error al generar el reporte. Por favor intente nuevamente.
                            </td>
                        </tr>
                    `);
                }
                
                end_load();
            },
            error: function(xhr, status, error) {
                console.error("Error en la solicitud:", {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                let errorMsg = 'Error al comunicarse con el servidor';
                
                try {
                    if (xhr.responseText) {
                        const jsonResponse = JSON.parse(xhr.responseText);
                        if (jsonResponse && jsonResponse.error) {
                            errorMsg = 'Error: ' + jsonResponse.error;
                        }
                    }
                } catch (e) {
                    console.log('No se pudo parsear la respuesta JSON:', e);
                }
                
                alert_toast(errorMsg, 'danger');
                end_load();
            }
        });
    }

    function formatNumber(num) {
        return Number(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function htmlEscape(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function start_load() {
        $('body').prepend('<div id="preloader2"></div>');
    }

    function end_load() {
        $('#preloader2').fadeOut('fast', function() {
            $(this).remove();
        });
    }

    function alert_toast(msg, bg) {
        if ($('#alert_toast').length === 0) {
            $('body').append('<div id="alert_toast" class="toast" role="alert" aria-live="assertive" aria-atomic="true"></div>');
            $('#alert_toast').html('<div class="toast-body"></div>');
        }
        
        $('#alert_toast').removeClass('bg-success bg-danger bg-info bg-warning').addClass('bg-' + bg);
        $('#alert_toast .toast-body').html(msg);
        $('#alert_toast').toast({ delay: 3000 }).toast('show');
    }

    function printReport() {
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        
        const nivel = $('#nivel').val() || 'Todos';
        const grado = $('#grado').val() || 'Todos';
        const seccion = $('#seccion').val() || 'Todas';
        const start_date = $('#start_date').val() ? new Date($('#start_date').val()).toLocaleDateString() : 'N/A';
        const end_date = $('#end_date').val() ? new Date($('#end_date').val()).toLocaleDateString() : 'N/A';
        const status = $('#student_status').val() || 'Todos';
        
        let printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Reporte de Deudas</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; font-weight: bold; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .filters { margin-bottom: 20px; background-color: #f8f9fc; padding: 15px; border-radius: 5px; }
                    .filters p { margin: 5px 0; }
                    .text-right { text-align: right; }
                    tfoot { font-weight: bold; background-color: #eaecf4; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>Reporte de Deudas Pendientes</h1>
                    <p><em>Solo se muestran registros con deuda mayor a 0</em></p>
                </div>
                <div class="filters">
                    <p><strong>Nivel:</strong> ${nivel}</p>
                    <p><strong>Grado:</strong> ${grado}</p>
                    <p><strong>Sección:</strong> ${seccion}</p>
                    <p><strong>Estado:</strong> ${status}</p>
                    <p><strong>Fecha Inicio:</strong> ${start_date}</p>
                    <p><strong>Fecha Fin:</strong> ${end_date}</p>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Alumno</th>
                            <th>Estado</th>
                            <th>Concepto</th>
                            <th class="text-right">Monto Total</th>
                            <th class="text-right">Pagado</th>
                            <th class="text-right">Deuda</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        const table = $('#debt_reports_table').DataTable();
        if (table && $.fn.DataTable.isDataTable('#debt_reports_table')) {
            table.rows().every(function() {
                const data = this.node();
                if ($(data).find('td').length > 1) {
                    printContent += '<tr>';
                    $(data).find('td').each(function() {
                        const text = $(this).text().trim();
                        const tdClass = $(this).hasClass('text-right') ? ' class="text-right"' : '';
                        printContent += `<td${tdClass}>${text}</td>`;
                    });
                    printContent += '</tr>';
                }
            });
        } else {
            $('#debt_reports_table tbody tr').each(function() {
                if ($(this).find('td').length > 1) {
                    printContent += '<tr>';
                    $(this).find('td').each(function() {
                        const text = $(this).text().trim();
                        const tdClass = $(this).hasClass('text-right') ? ' class="text-right"' : '';
                        printContent += `<td${tdClass}>${text}</td>`;
                    });
                    printContent += '</tr>';
                }
            });
        }
        
        printContent += `
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-right">Total General:</th>
                            <th class="text-right">${$('#total-amount').text()}</th>
                            <th class="text-right">${$('#total-paid').text()}</th>
                            <th class="text-right">${$('#total-debt').text()}</th>
                        </tr>
                    </tfoot>
                </table>
            </body>
            </html>
        `;
        
        printWindow.document.open();
        printWindow.document.write(printContent);
        printWindow.document.close();
        
        printWindow.onload = function() {
            printWindow.print();
        };
    }

    $('#clear-filters').click(function() {
        $('#academic_year_id, #student_id, #nivel, #grado, #seccion, #concepto, #student_status').val(null).trigger('change');
        $('#start_date, #end_date').val('');
        
        setTimeout(function() {
            $('#generate-report').click();
        }, 300);
    });
});
</script>
