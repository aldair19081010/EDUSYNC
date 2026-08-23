<?php
// Verificar que sea estudiante
if ((!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) && 
    (!isset($_SESSION['login_type']) || $_SESSION['login_type'] != 4)) {
    header('Location: login.php');
    exit;
}

// Asegurar que las variables de sesión estén disponibles
if (!isset($_SESSION['student_id']) || !isset($_SESSION['student_dni'])) {
    header('Location: login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$student_dni = $_SESSION['student_dni'];
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
            <i class="fas fa-graduation-cap mr-2 text-primary"></i>
            Mis Notas
        </h1>
        <div class="text-muted small">Historial de calificaciones por bimestre y año académico</div>
    </div>
</div>

<!-- Alert for Debts -->
<div id="debt-alert" class="alert alert-danger alert-dismissible fade show" role="alert" style="display:none;">
    <i class="fas fa-exclamation-circle mr-2"></i>
    <strong>Acceso Restringido:</strong> <span id="debt-message"></span>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>

<!-- Stats Cards -->
<div class="row mb-4" id="stats-cards">
    <!-- Cards will be loaded via AJAX -->
    <div class="col-12 text-center">
        <i class="fas fa-spinner fa-spin fa-3x text-muted"></i>
    </div>
</div>

<!-- Grades Card -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%); color: white;">
        <h6 class="m-0 font-weight-bold" style="color: white;">
            <i class="fas fa-book mr-2"></i>Calificaciones
        </h6>
        <div>
            <select id="year-filter" class="form-control form-control-sm" style="width: 200px;">
                <option value="">Seleccione un año...</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <div id="grades-container">
            <div class="text-center">
                <i class="fas fa-spinner fa-spin fa-3x text-muted"></i>
                <p class="mt-2 text-muted">Cargando calificaciones...</p>
            </div>
        </div>
    </div>
</div>

<style>
    /* === Profile Bar === */
    .student-profile-bar {
        padding: 15px 18px;
        background: linear-gradient(135deg, #f8f9fc 0%, #ffffff 100%);
        border: 2px solid #e3e6f0;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .avatar-circle {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        box-shadow: 0 4px 12px rgba(66,133,244,0.3);
    }
    
    /* === Stats Cards === */
    .stat-card {
        border-left: 4px solid #4285f4;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    }
    
    /* === Tabs === */
    .nav-tabs {
        border-bottom: 2px solid #4285f4;
    }
    .nav-tabs .nav-link {
        border: none;
        border-bottom: 3px solid transparent;
        color: #6c757d;
        font-weight: 600;
        padding: 14px 20px;
        transition: all 0.3s ease;
    }
    .nav-tabs .nav-link:hover {
        border-color: #4285f4;
        color: #4285f4;
        background: #f8f9fc;
    }
    .nav-tabs .nav-link.active {
        border-color: #4285f4;
        color: #4285f4;
        background: #f8f9fc;
    }
    
    /* === Table === */
    .table-hover tbody tr:hover {
        background-color: #f8f9fc !important;
    }
    
    /* === Modals === */
    .modal-xl {
        max-width: 1200px;
    }
    .modal-content {
        border: none;
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    }
    .modal-header {
        border: none;
        padding: 20px 25px;
    }
    .modal-header.bg-primary {
        background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%) !important;
    }
    .modal-body {
        padding: 25px;
    }
    .modal-footer {
        border-top: 1px solid #e3e6f0;
        padding: 15px 25px;
    }
</style>

<script>
let allGrades = [];

$(document).ready(function() {
    loadGrades();
});

function loadGrades() {
    let dni = '<?php echo htmlspecialchars($student_dni); ?>';
    
    $.ajax({
        url: 'api/my_grades.php',
        method: 'GET',
        data: { dni: dni },
        dataType: 'json',
        success: function(resp) {
            console.log('API Response:', resp);
            
            try {
                if (resp.status === 'ok') {
                    const yearsAvailable = (resp.data && resp.data.años_disponibles && resp.data.años_disponibles.length)
                        ? resp.data.años_disponibles
                        : (resp.data.años_academicos || []);
                    allGrades = resp.data;
                    populateYearFilter(yearsAvailable);

                    if (yearsAvailable.length > 0) {
                        const defaultYear = yearsAvailable[0].año;
                        const yearData = (resp.data.años_academicos || []).find(y => String(y.año) === String(defaultYear));
                        if (yearData) {
                            renderGrades(yearData);
                        } else {
                            showNoGrades(defaultYear);
                        }
                        $('#year-filter').val(defaultYear);
                        renderStats(resp.data, defaultYear);
                    } else {
                        showNoGrades();
                        renderStats(resp.data, null);
                    }
                } else if (resp.reason === 'debt') {
                    $('#debt-alert').show();
                    $('#debt-message').text(resp.message);
                    $('#grades-container').html(`
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle mr-2"></i>
                            Para ver tus calificaciones, debes estar al día con tus pagos.
                            <br><strong>Deuda pendiente: S/ ${resp.total_pendiente_ultimas}</strong>
                        </div>
                    `);
                } else {
                    $('#grades-container').html(`
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            ${resp.message}
                        </div>
                    `);
                }
            } catch(e) {
                console.error('Error processing grades:', e);
                $('#grades-container').html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Error procesando calificaciones: ${e.message}
                    </div>
                `);
            }
        },
        error: function(err) {
            console.error('AJAX Error:', err);
            console.log('Response Text:', err.responseText);
            $('#grades-container').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Error al cargar las calificaciones: ${err.status} ${err.statusText}
                </div>
            `);
        }
    });
}

function renderStats(data, selectedYear = null) {
    let promedioAnual = '-';
    let txtPromedio = 'Promedio General';
    
    if (data.años_academicos && data.años_academicos.length > 0) {
        if (selectedYear) {
            const yearData = data.años_academicos.find(y => y.año == selectedYear);
            if (yearData) {
                promedioAnual = yearData.promedio_anual;
                txtPromedio = `Promedio ${selectedYear}`;
            }
        } else {
            promedioAnual = data.años_academicos[0].promedio_anual;
        }
    }
    
    let html = `
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card stat-card h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #4285f4;">${txtPromedio}</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">${promedioAnual}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-star fa-2x" style="color: #4285f4; opacity: 0.3;"></i>
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
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #4285f4;">Años Académicos</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">${data.total_años_con_notas}</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-alt fa-2x" style="color: #4285f4; opacity: 0.3;"></i>
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
                            <i class="fas fa-book-open fa-2x" style="color: #4285f4; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('#stats-cards').html(html);
}

function renderGrades(yearData) {
    let html = `<div class="card shadow mb-4">
        <div class="card-header py-3" style="background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%); border: none;">
            <h6 class="m-0 font-weight-bold text-white">
                <i class="fas fa-calendar-check mr-2"></i>${yearData.año} - ${yearData.descripcion}
                <span class="badge badge-light ml-3" style="font-size: 0.9rem; padding: 6px 12px;">
                    <i class="fas fa-star mr-1" style="color: #4285f4;"></i>Promedio Anual: <strong>${yearData.promedio_anual}</strong>
                </span>
            </h6>
        </div>
        <div class="card-body p-0">`;
    
    if (yearData.bimestres && yearData.bimestres.length > 0) {
        let fYear = String(yearData.año).replace(/[^a-zA-Z0-9]/g, '');
        html += `<ul class="nav nav-tabs" role="tablist" style="border-bottom: 2px solid #4285f4; margin-bottom: 0;">`;
        yearData.bimestres.forEach(function(bimestre, index) {
            let isActive = index === 0 ? 'active' : '';
            html += `<li class="nav-item"><a class="nav-link ${isActive}" data-toggle="tab" href="#bim${fYear}_${bimestre.numero}" style="font-weight: 600; color: #6c757d;">
                <i class="fas fa-calendar-day mr-1" style="color: #4285f4;"></i>Bimestre ${bimestre.numero}
                <span class="badge ml-2" style="background: #4285f4; color: white;">${bimestre.promedio_bimestre}</span>
            </a></li>`;
        });
        html += `</ul><div class="tab-content" style="padding: 0;">`;
        
        yearData.bimestres.forEach(function(bimestre, index) {
            let isActive = index === 0 ? 'show active' : '';
            html += `<div class="tab-pane fade ${isActive}" id="bim${fYear}_${bimestre.numero}"><div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="bg-light"><tr>
                        <th style="color: #4285f4; font-weight: 700;">Curso</th>
                        <th style="color: #4285f4; font-weight: 700;">Área</th>
                        <th style="color: #4285f4; font-weight: 700; text-align: center;">Promedio</th>
                        <th style="color: #4285f4; font-weight: 700; text-align: center;">Acción</th>
                    </tr></thead>
                    <tbody>`;
            
            if (bimestre.cursos && bimestre.cursos.length > 0) {
                bimestre.cursos.forEach(function(curso, cursoIndex) {
                    let grado = parseFloat(curso.promedio);
                    let gradeColor = '#4285f4', gradeBgColor = '#e3f2fd';
                    if (grado >= 16) { gradeColor = '#1cc88a'; gradeBgColor = '#d4edda'; }
                    else if (grado <= 11) { gradeColor = '#e74a3b'; gradeBgColor = '#f8d7da'; }
                    else { gradeColor = '#f6c23e'; gradeBgColor = '#fff3cd'; }
                    
                    let areaNombre = typeof curso.area === 'object' && curso.area ? curso.area.nombre : (curso.area || 'Sin área');
                    let modalId = `m${fYear}b${bimestre.numero}c${cursoIndex}`;
                    
                    html += `<tr>
                        <td style="font-weight: 600; color: #2c3e50;">${curso.curso}</td>
                        <td style="color: #6c757d;">${areaNombre}</td>
                        <td style="text-align: center;"><span style="background: ${gradeBgColor}; color: ${gradeColor}; padding: 8px 16px; border-radius: 20px; font-weight: 700;">${curso.promedio}</span></td>
                        <td style="text-align: center;"><button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#${modalId}" style="background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%); border: none;"><i class="fas fa-eye mr-1"></i>Ver Detalle</button></td>
                    </tr>`;
                });
            }
            html += `</tbody></table></div></div>`;
        });
        html += `</div>`;
    }
    html += `</div></div>`;
    
    $('#grades-container').html(html);
    
    // Crear modales en contenedor separado
    let modalsHtml = '';
    if (yearData.bimestres) {
        let fYear = String(yearData.año).replace(/[^a-zA-Z0-9]/g, '');
        yearData.bimestres.forEach(function(bimestre) {
            if (bimestre.cursos) {
                bimestre.cursos.forEach(function(curso, cursoIndex) {
                    let modalId = `m${fYear}b${bimestre.numero}c${cursoIndex}`;
                    let areaNombre = typeof curso.area === 'object' && curso.area ? curso.area.nombre : (curso.area || 'Sin área');
                    
                    modalsHtml += `<div class="modal fade" id="${modalId}" tabindex="-1">
                        <div class="modal-dialog modal-xl modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header bg-primary text-white">
                                    <div>
                                        <h5 class="modal-title" style="font-weight: 700;"><i class="fas fa-book-open mr-2"></i>${curso.curso}</h5>
                                        <small style="opacity: 0.9;">${areaNombre} - Bimestre ${bimestre.numero}</small>
                                    </div>
                                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                                </div>
                                <div class="modal-body">
                                    <div class="card border-left-primary shadow mb-4">
                                        <div class="card-body">
                                            <div class="text-primary font-weight-bold text-uppercase mb-1">Promedio del Curso</div>
                                            <div class="h3 mb-0 font-weight-bold text-gray-800">${curso.promedio}</div>
                                        </div>
                                    </div>
                                    <h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-info-circle mr-2"></i>Competencias Evaluadas</h6>
                                    <p class="text-muted small mb-4">Resumen de competencias y sus evaluaciones.</p>`;
                    
                    if (curso.competencias && typeof curso.competencias === 'object') {
                        Object.values(curso.competencias).forEach(function(comp) {
                            let peso = (comp.peso * 100).toFixed(0);
                            modalsHtml += `<div class="card border-left-primary mb-4">
                                <div class="card-body small">
                                    <h6 class="font-weight-bold text-gray-800 mb-2">
                                        <i class="fas fa-check-circle mr-2" style="color: #4285f4;"></i>${comp.nombre}
                                        <span class="badge badge-primary" style="background: #4285f4;">${peso}%</span>
                                        <span class="badge badge-info ml-2">Promedio: ${comp.promedio}</span>
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead class="bg-light"><tr><th>Evaluación</th><th class="text-center">Nota</th><th>Observación</th></tr></thead>
                                            <tbody>`;
                            
                            if (comp.evaluaciones && comp.evaluaciones.length > 0) {
                                comp.evaluaciones.forEach(function(ev) {
                                    let notaEval = parseFloat(ev.nota), notaBg = '#e3f2fd';
                                    if (notaEval >= 16) notaBg = '#d4edda';
                                    else if (notaEval <= 11) notaBg = '#f8d7da';
                                    else notaBg = '#fff3cd';
                                    modalsHtml += `<tr><td>${ev.evaluacion}</td><td class="text-center" style="background: ${notaBg}; font-weight: 700;">${ev.nota}</td><td><small class="text-muted">${ev.observacion || '-'}</small></td></tr>`;
                                });
                                let cantEval = comp.evaluaciones.length;
                                modalsHtml += `<tr class="bg-light font-weight-bold"><td colspan="3">Promedio: (${comp.evaluaciones.map(e => e.nota).join(' + ')}) ÷ ${cantEval} = <span style="color: #4285f4;">${comp.promedio_simple}</span></td></tr>`;
                            } else {
                                modalsHtml += `<tr><td colspan="3" class="text-center text-muted">Sin evaluaciones</td></tr>`;
                            }
                            modalsHtml += `</tbody></table></div></div></div>`;
                        });
                    }
                    modalsHtml += `</div><div class="modal-footer bg-light"><button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times mr-1"></i>Cerrar</button></div></div></div></div>`;
                });
            }
        });
    }
    
    // Agregar modales al DOM si no existen
    if (!$('#modals-container').length) {
        $('body').append('<div id="modals-container"></div>');
    }
    $('#modals-container').html(modalsHtml);
}

function populateYearFilter(years) {
    let options = '<option value="">Seleccione un año...</option>';
    years.forEach(function(year) {
        options += `<option value="${year.año}">${year.año}</option>`;
    });
    $('#year-filter').html(options);
    if (years.length > 0) {
        $('#year-filter').val(years[0].año);
    }
}

$(document).on('change', '#year-filter', function() {
    let selectedYear = $(this).val();
    if (!selectedYear) return;
    
    renderStats(allGrades, selectedYear);
    
    const yearData = (allGrades.años_academicos || []).find(y => String(y.año) === String(selectedYear));
    if (yearData) {
        renderGrades(yearData);
    } else {
        showNoGrades(selectedYear);
    }
});

function showNoGrades(year) {
    const yearLabel = year ? `para el año ${year}` : '';
    $('#grades-container').html(`
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle mr-2"></i>
            <strong>Sin calificaciones disponibles ${yearLabel}</strong>
            <br>Aún no hay calificaciones registradas. Por favor, comunícate con tu docente.
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `);
    $('#stats-cards').empty();
}
</script>
