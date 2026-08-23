<?php include 'db_connect.php'; ?>

<style>
.select2-container--default .select2-selection--single {
    height: calc(1.5em + 0.75rem + 2px) !important;
    padding: 0.375rem 0.75rem;
    border: 1px solid #d1d3e2;
    border-radius: 0.35rem;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: calc(1.5em + 0.75rem) !important;
    color: #6e707e;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: calc(1.5em + 0.75rem + 2px) !important;
}

.select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #bac8f3;
}

.select2-dropdown {
    border: 1px solid #d1d3e2;
    border-radius: 0.35rem;
}

.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #4e73df;
}

.report-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
}

.report-icon.icon-student {
    background-color: rgba(78, 115, 223, 0.1);
    color: #4e73df;
}

.report-icon.icon-teacher {
    background-color: rgba(28, 200, 138, 0.1);
    color: #1cc88a;
}

.report-icon.icon-stats {
    background-color: rgba(54, 185, 204, 0.1);
    color: #36b9cc;
}

.report-icon.icon-money {
    background-color: rgba(246, 194, 62, 0.1);
    color: #f6c23e;
}

.ficha-option {
    border: 1px solid #e3e6f0;
    border-radius: 0.35rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
    background-color: #fff;
}

.ficha-option:hover {
    border-color: #4e73df;
    box-shadow: 0 0.125rem 0.25rem rgba(78, 115, 223, 0.15);
    transform: translateY(-2px);
}

.ficha-option h6 {
    color: #5a5c69;
    font-weight: 700;
    margin-bottom: 0.5rem;
    font-size: 1rem;
}

.ficha-option p {
    color: #858796;
    margin-bottom: 1rem;
    font-size: 0.875rem;
}

.btn-icon {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.gap-2 {
    gap: 0.5rem !important;
}
</style>

<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-id-card mr-2"></i>Fichas y Reportes Institucionales</h1>
    </div>

    <div class="row">
        <!-- Fichas de Estudiantes -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-user-graduate mr-2"></i>Fichas de Estudiantes
                    </h6>
                </div>
                <div class="card-body">
                    <div class="ficha-option">
                        <div class="d-flex align-items-center mb-3">
                            <div class="report-icon icon-student">
                                <i class="fas fa-id-card fa-lg"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Ficha Individual de Estudiante</h6>
                            </div>
                        </div>
                        <p>Genera una ficha completa con toda la información personal, académica y financiera de un estudiante específico.</p>
                        
                        <div class="form-group">
                            <label class="small font-weight-bold" for="student_select">Seleccionar Estudiante:</label>
                            <select class="form-control form-control-sm" id="student_select">
                                <option value="">Cargando estudiantes...</option>
                            </select>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm btn-icon" onclick="generarFichaEstudiante('pdf')">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button class="btn btn-success btn-sm btn-icon" onclick="generarFichaEstudiante('excel')">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                        </div>
                    </div>
                    
                    <div class="ficha-option">
                        <div class="d-flex align-items-center mb-3">
                            <div class="report-icon icon-student">
                                <i class="fas fa-list fa-lg"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Relación de Estudiantes por Nivel</h6>
                            </div>
                        </div>
                        <p>Genera una lista completa de estudiantes filtrada por nivel, grado y sección.</p>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="small font-weight-bold" for="nivel_select">Nivel:</label>
                                    <select class="form-control form-control-sm" id="nivel_select" onchange="cargarGrados()">
                                        <option value="">Seleccionar nivel...</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="small font-weight-bold" for="grado_select">Grado:</label>
                                    <select class="form-control form-control-sm" id="grado_select" onchange="cargarSecciones()">
                                        <option value="">Seleccionar grado...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="small font-weight-bold" for="seccion_select">Sección (opcional):</label>
                            <select class="form-control form-control-sm" id="seccion_select">
                                <option value="">Todas las secciones</option>
                            </select>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm btn-icon" onclick="generarRelacionEstudiantes('pdf')">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button class="btn btn-success btn-sm btn-icon" onclick="generarRelacionEstudiantes('excel')">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fichas de Docentes -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-success">
                        <i class="fas fa-chalkboard-teacher mr-2"></i>Fichas de Docentes
                    </h6>
                </div>
                <div class="card-body">
                    <div class="ficha-option">
                        <div class="d-flex align-items-center mb-3">
                            <div class="report-icon icon-teacher">
                                <i class="fas fa-id-card fa-lg"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Ficha Individual de Docente</h6>
                            </div>
                        </div>
                        <p>Genera una ficha completa con toda la información personal, cursos asignados y datos de contacto del docente.</p>
                        
                        <div class="form-group">
                            <label class="small font-weight-bold" for="teacher_select">Seleccionar Docente:</label>
                            <select class="form-control form-control-sm" id="teacher_select">
                                <option value="">Cargando docentes...</option>
                            </select>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm btn-icon" onclick="generarFichaDocente('pdf')">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button class="btn btn-success btn-sm btn-icon" onclick="generarFichaDocente('excel')">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                        </div>
                    </div>
                    
                    <div class="ficha-option">
                        <div class="d-flex align-items-center mb-3">
                            <div class="report-icon icon-teacher">
                                <i class="fas fa-users fa-lg"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Relación Completa de Docentes</h6>
                            </div>
                        </div>
                        <p>Genera una lista completa de todos los docentes de la institución con información resumida.</p>
                        
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle mr-2"></i> 
                            Esta relación incluye: datos personales, cursos asignados, años de servicio y información de contacto.
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm btn-icon" onclick="generarRelacionDocentes('pdf')">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button class="btn btn-success btn-sm btn-icon" onclick="generarRelacionDocentes('excel')">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reportes por Año Académico -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-info">
                        <i class="fas fa-calendar-alt mr-2"></i>Reportes por Año Académico
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-4 mb-4">
                            <div class="ficha-option h-100">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="report-icon icon-stats">
                                        <i class="fas fa-chart-bar fa-lg"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Estadísticas Generales</h6>
                                    </div>
                                </div>
                                <p>Reporte completo con estadísticas del año académico seleccionado.</p>
                                
                                <div class="form-group">
                                    <label class="small font-weight-bold" for="year_select">Año Académico:</label>
                                    <select class="form-control form-control-sm" id="year_select">
                                        <option value="">Cargando años...</option>
                                    </select>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <button class="btn btn-primary btn-sm btn-icon" onclick="generarEstadisticasAnio('pdf')">
                                        <i class="fas fa-file-pdf"></i> PDF
                                    </button>
                                    <button class="btn btn-success btn-sm btn-icon" onclick="generarEstadisticasAnio('excel')">
                                        <i class="fas fa-file-excel"></i> Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <div class="ficha-option h-100">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="report-icon icon-stats">
                                        <i class="fas fa-layer-group fa-lg"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Matrícula por Nivel</h6>
                                    </div>
                                </div>
                                <p>Distribución de estudiantes por nivel educativo en el año académico.</p>
                                
                                <div class="form-group">
                                    <label class="small font-weight-bold" for="year_select2">Año Académico:</label>
                                    <select class="form-control form-control-sm" id="year_select2">
                                        <option value="">Cargando años...</option>
                                    </select>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <button class="btn btn-primary btn-sm btn-icon" onclick="generarMatriculaNivel('pdf')">
                                        <i class="fas fa-file-pdf"></i> PDF
                                    </button>
                                    <button class="btn btn-success btn-sm btn-icon" onclick="generarMatriculaNivel('excel')">
                                        <i class="fas fa-file-excel"></i> Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <div class="ficha-option h-100">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="report-icon icon-money">
                                        <i class="fas fa-money-bill-wave fa-lg"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0">Reporte Financiero</h6>
                                    </div>
                                </div>
                                <p>Resumen de pagos, deudas y estado financiero del año académico.</p>
                                
                                <div class="form-group">
                                    <label class="small font-weight-bold" for="year_select3">Año Académico:</label>
                                    <select class="form-control form-control-sm" id="year_select3">
                                        <option value="">Cargando años...</option>
                                    </select>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <button class="btn btn-primary btn-sm btn-icon" onclick="generarReporteFinanciero('pdf')">
                                        <i class="fas fa-file-pdf"></i> PDF
                                    </button>
                                    <button class="btn btn-success btn-sm btn-icon" onclick="generarReporteFinanciero('excel')">
                                        <i class="fas fa-file-excel"></i> Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    initSelect2();
    cargarEstudiantes();
    cargarDocentes();
    cargarNiveles();
    cargarAniosAcademicos();
});

function initSelect2() {
    $('#student_select').select2({
        placeholder: 'Buscar estudiante por nombre o código...',
        allowClear: true,
        width: '100%',
        language: {
            noResults: () => 'No se encontraron estudiantes',
            searching: () => 'Buscando...'
        }
    });

    $('#teacher_select').select2({
        placeholder: 'Buscar docente por nombre o código...',
        allowClear: true,
        width: '100%',
        language: {
            noResults: () => 'No se encontraron docentes',
            searching: () => 'Buscando...'
        }
    });

    $('#nivel_select, #grado_select, #seccion_select, #year_select, #year_select2, #year_select3').select2({
        width: '100%',
        minimumResultsForSearch: Infinity
    });
}

function cargarEstudiantes() {
    $.ajax({
        url: 'ajax.php?action=get_students_for_ficha',
        method: 'GET',
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                let options = '<option value="">Seleccionar estudiante...</option>';
                resp.students.forEach(function(student) {
                    options += `<option value="${student.id}">${student.id_no} - ${student.name} (${student.nivel} - ${student.grado})</option>`;
                });
                $('#student_select').html(options);
                $('#student_select').trigger('change.select2');
            } else {
                $('#student_select').html('<option value="">Error al cargar estudiantes</option>');
            }
        },
        error: function() {
            $('#student_select').html('<option value="">Error al cargar estudiantes</option>');
        }
    });
}

function cargarDocentes() {
    $.ajax({
        url: 'ajax.php?action=get_teachers_for_ficha',
        method: 'GET',
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                let options = '<option value="">Seleccionar docente...</option>';
                resp.teachers.forEach(function(teacher) {
                    options += `<option value="${teacher.id}">${teacher.id_no} - ${teacher.name}</option>`;
                });
                $('#teacher_select').html(options);
                $('#teacher_select').trigger('change.select2');
            } else {
                $('#teacher_select').html('<option value="">Error al cargar docentes</option>');
            }
        },
        error: function() {
            $('#teacher_select').html('<option value="">Error al cargar docentes</option>');
        }
    });
}

function cargarNiveles() {
    $.ajax({
        url: 'ajax.php?action=get_niveles_for_ficha',
        method: 'GET',
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                let options = '<option value="">Seleccionar nivel...</option>';
                resp.niveles.forEach(function(nivel) {
                    options += `<option value="${nivel}">${nivel}</option>`;
                });
                $('#nivel_select').html(options);
                $('#nivel_select').trigger('change.select2');
            } else {
                $('#nivel_select').html('<option value="">Error al cargar niveles</option>');
            }
        },
        error: function() {
            $('#nivel_select').html('<option value="">Error al cargar niveles</option>');
        }
    });
}

function cargarAniosAcademicos() {
    $.ajax({
        url: 'ajax.php?action=get_academic_years',
        method: 'GET',
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                let options = '<option value="">Seleccionar año...</option>';
                resp.years.forEach(function(year) {
                    options += `<option value="${year.id}">${year.year}</option>`;
                });
                $('#year_select, #year_select2, #year_select3').html(options);
                $('#year_select, #year_select2, #year_select3').trigger('change.select2');
            } else {
                $('#year_select, #year_select2, #year_select3').html('<option value="">Error al cargar años</option>');
            }
        },
        error: function() {
            $('#year_select, #year_select2, #year_select3').html('<option value="">Error al cargar años</option>');
        }
    });
}

function cargarGrados() {
    let nivel = $('#nivel_select').val();
    if (!nivel) {
        $('#grado_select').html('<option value="">Seleccionar grado...</option>');
        $('#seccion_select').html('<option value="">Todas las secciones</option>');
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=get_grados_by_nivel_for_ficha',
        method: 'GET',
        data: { nivel: nivel },
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                let options = '<option value="">Seleccionar grado...</option>';
                resp.grados.forEach(function(grado) {
                    options += `<option value="${grado}">${grado}</option>`;
                });
                $('#grado_select').html(options);
                $('#seccion_select').html('<option value="">Todas las secciones</option>');
                $('#grado_select, #seccion_select').trigger('change.select2');
            } else {
                $('#grado_select').html('<option value="">Error al cargar grados</option>');
                $('#seccion_select').html('<option value="">Todas las secciones</option>');
                $('#grado_select, #seccion_select').trigger('change.select2');
            }
        },
        error: function() {
            $('#grado_select').html('<option value="">Error al cargar grados</option>');
            $('#seccion_select').html('<option value="">Todas las secciones</option>');
            $('#grado_select, #seccion_select').trigger('change.select2');
        }
    });
}

function cargarSecciones() {
    let nivel = $('#nivel_select').val();
    let grado = $('#grado_select').val();
    
    if (!nivel || !grado) {
        $('#seccion_select').html('<option value="">Todas las secciones</option>');
        return;
    }
    
    $.ajax({
        url: 'ajax.php?action=get_secciones_by_nivel_grado_for_ficha',
        method: 'GET',
        data: { nivel: nivel, grado: grado },
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                let options = '<option value="">Todas las secciones</option>';
                resp.secciones.forEach(function(seccion) {
                    options += `<option value="${seccion}">${seccion}</option>`;
                });
                $('#seccion_select').html(options);
                $('#seccion_select').trigger('change.select2');
            } else {
                $('#seccion_select').html('<option value="">Error al cargar secciones</option>');
                $('#seccion_select').trigger('change.select2');
            }
        },
        error: function() {
            $('#seccion_select').html('<option value="">Error al cargar secciones</option>');
            $('#seccion_select').trigger('change.select2');
        }
    });
}

function generarFichaEstudiante(format = 'pdf') {
    let studentId = $('#student_select').val();
    if (!studentId) {
        alert('Por favor seleccione un estudiante');
        return;
    }
    window.open('generar_ficha.php?type=student&id=' + studentId + '&format=' + format, '_blank');
}

function generarFichaDocente(format = 'pdf') {
    let teacherId = $('#teacher_select').val();
    if (!teacherId) {
        alert('Por favor seleccione un docente');
        return;
    }
    window.open('generar_ficha.php?type=teacher&id=' + teacherId + '&format=' + format, '_blank');
}

function generarRelacionEstudiantes(format = 'pdf') {
    let nivel = $('#nivel_select').val();
    let grado = $('#grado_select').val();
    let seccion = $('#seccion_select').val();
    
    if (!nivel || !grado) {
        alert('Por favor seleccione nivel y grado');
        return;
    }
    
    let params = new URLSearchParams({
        type: 'student_list',
        nivel: nivel,
        grado: grado,
        format: format
    });
    
    if (seccion) params.append('seccion', seccion);
    
    window.open('generar_ficha.php?' + params.toString(), '_blank');
}

function generarRelacionDocentes(format = 'pdf') {
    window.open('generar_ficha.php?type=teacher_list&format=' + format, '_blank');
}

function generarEstadisticasAnio(format = 'pdf') {
    let yearId = $('#year_select').val();
    if (!yearId) {
        alert('Por favor seleccione un año académico');
        return;
    }
    window.open('generar_ficha.php?type=year_stats&year_id=' + yearId + '&format=' + format, '_blank');
}

function generarMatriculaNivel(format = 'pdf') {
    let yearId = $('#year_select2').val();
    if (!yearId) {
        alert('Por favor seleccione un año académico');
        return;
    }
    window.open('generar_ficha.php?type=enrollment_by_level&year_id=' + yearId + '&format=' + format, '_blank');
}

function generarReporteFinanciero(format = 'pdf') {
    let yearId = $('#year_select3').val();
    if (!yearId) {
        alert('Por favor seleccione un año académico');
        return;
    }
    window.open('generar_ficha.php?type=financial_report&year_id=' + yearId + '&format=' + format, '_blank');
}
</script>
