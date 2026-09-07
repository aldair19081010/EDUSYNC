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
.ir-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:18px 20px;margin-bottom:18px;background:#fff;border:1px solid #e4e9f2;border-left:4px solid #4e73df;border-radius:10px;box-shadow:0 2px 8px rgba(31,45,61,.05)}
.ir-hero h1{font-size:1.45rem;font-weight:700;color:#344767;margin:0}.ir-hero p{margin:4px 0 0;color:#7b8499}.ir-tools{display:flex;align-items:flex-end;gap:10px;min-width:280px}.ir-tools>div{flex:1}.ir-tools label{font-size:.76rem;font-weight:700;color:#596579}.ficha-option.is-hidden{display:none}.ficha-option{box-shadow:0 1px 4px rgba(31,45,61,.04)}
.report-catalog{margin-bottom:22px}.catalog-title{font-size:1.05rem;font-weight:700;color:#344767;margin-bottom:4px}.catalog-card{height:100%;background:#fff;border:1px solid #e3e6f0;border-radius:8px;padding:18px;display:flex;flex-direction:column;transition:.2s}.catalog-card:hover{border-color:#4e73df;box-shadow:0 3px 10px rgba(78,115,223,.12);transform:translateY(-2px)}.catalog-number{width:30px;height:30px;border-radius:6px;background:#eef2ff;color:#4e73df;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.84rem}.catalog-card h6{font-weight:700;color:#3f4d67;margin:12px 0 6px}.catalog-card p{font-size:.82rem;color:#7b8499;flex:1}.catalog-card .btn{align-self:flex-start}.catalog-category{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#4e73df;font-weight:700}
@media(max-width:850px){.ir-hero{align-items:stretch;flex-direction:column}.ir-tools{min-width:0;flex-direction:column;align-items:stretch}}
</style>

<div class="container-fluid">
    <!-- Page Heading -->
    <div class="ir-hero">
        <div><h1><i class="fas fa-id-card text-primary mr-2"></i>Fichas y reportes institucionales</h1><p>El padrón general no depende del año; cada reporte académico o financiero solicita su propio periodo.</p></div>
        <div class="ir-tools"><div><label>Buscar reporte</label><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div><input id="institutional-report-search" class="form-control" placeholder="Estudiante, docente, financiero..."></div></div></div>
    </div>

    <section class="report-catalog"><div class="mb-3"><div class="catalog-title">Reportes principales</div><p class="small text-muted mb-0">Selecciona el documento que necesitas y luego aplica sus filtros.</p></div><div class="row">
        <div class="col-md-6 col-xl-4 mb-3"><div class="catalog-card"><div><span class="catalog-number">1</span></div><div class="catalog-category mt-3">Estudiantes</div><h6>Nómina de matriculados por aula</h6><p>Lista oficial de estudiantes activos con apoderado y contacto, organizada por nivel, grado y sección.</p><button class="btn btn-sm btn-primary" onclick="abrirFiltrosCatalogo('enrollment')"><i class="fas fa-users mr-1"></i>Generar reporte</button></div></div>
        <div class="col-md-6 col-xl-4 mb-3"><div class="catalog-card"><div><span class="catalog-number">2</span></div><div class="catalog-category mt-3">Académico</div><h6>Estudiantes en riesgo académico</h6><p>Identifica estudiantes cuyo promedio numérico registrado es menor a 11.</p><button class="btn btn-sm btn-primary" onclick="abrirFiltrosCatalogo('risk')"><i class="fas fa-chart-line mr-1"></i>Generar reporte</button></div></div>
        <div class="col-md-6 col-xl-4 mb-3"><div class="catalog-card"><div><span class="catalog-number">3</span></div><div class="catalog-category mt-3">Asistencia</div><h6>Alertas de asistencia</h6><p>Muestra estudiantes con tres ausencias, cinco tardanzas o asistencia inferior al 80 %.</p><button class="btn btn-sm btn-primary" onclick="abrirFiltrosCatalogo('attendance_alert')"><i class="fas fa-user-clock mr-1"></i>Generar reporte</button></div></div>
        <div class="col-md-6 col-xl-4 mb-3"><div class="catalog-card"><div><span class="catalog-number">4</span></div><div class="catalog-category mt-3">Finanzas</div><h6>Morosidad y pagos parciales</h6><p>Consulta por separado las deudas vencidas y los pagos que todavía mantienen saldo.</p><button class="btn btn-sm btn-primary" onclick="abrirFiltrosCatalogo('financial_alerts')"><i class="fas fa-file-invoice-dollar mr-1"></i>Generar reporte</button></div></div>
        <div class="col-md-6 col-xl-4 mb-3"><div class="catalog-card"><div><span class="catalog-number">5</span></div><div class="catalog-category mt-3">Docentes</div><h6>Carga académica docente</h6><p>Resume asignaciones, cursos, aulas y horas semanales de cada docente.</p><button class="btn btn-sm btn-primary" onclick="abrirFiltrosCatalogo('teacher_load')"><i class="fas fa-chalkboard-teacher mr-1"></i>Generar reporte</button></div></div>
        <div class="col-md-6 col-xl-4 mb-3"><div class="catalog-card"><div><span class="catalog-number">6</span></div><div class="catalog-category mt-3">Control</div><h6>Calidad de datos y carga académica</h6><p>Accede a estudiantes con datos incompletos y cursos activos que no tienen docente.</p><button class="btn btn-sm btn-primary" data-toggle="collapse" data-target="#predefined-reports"><i class="fas fa-clipboard-check mr-1"></i>Ver controles</button></div></div>
    </div></section>

    <div class="d-flex align-items-center justify-content-between mb-3"><div><h5 class="mb-1 text-gray-800 font-weight-bold">Fichas y reportes predefinidos</h5><p class="small text-muted mb-0">Accesos rápidos para documentos de uso frecuente.</p></div><button class="btn btn-sm btn-light" type="button" data-toggle="collapse" data-target="#predefined-reports" aria-expanded="true"><i class="fas fa-chevron-down mr-1"></i>Mostrar u ocultar</button></div>
    <div class="collapse show" id="predefined-reports">
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
                            <button class="btn btn-primary btn-sm btn-icon" onclick="generarFichaEstudiante('preview')">
                                <i class="fas fa-eye"></i> Vista previa
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
                        <p>Genera la relación de matriculados actuales o, si lo necesitas, el padrón institucional.</p>
                        
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
                        <div class="form-group"><label class="small font-weight-bold" for="student_year_select">Contexto académico de la ficha (opcional):</label><select class="form-control form-control-sm report-year-select" id="student_year_select"><option value="">Todos los años / ficha general</option></select><small class="text-muted">Solo afecta notas, asistencia, ubicación académica histórica y finanzas de la ficha individual.</small></div>
                        <div class="form-group"><label class="small font-weight-bold" for="enrollment_status_select">Estado del estudiante:</label><select class="form-control form-control-sm" id="enrollment_status_select"><option value="Activo" selected>Matriculados (activos)</option><option value="">Todo el padrón institucional</option><option value="Retirado">Retirados</option><option value="Egresado">Egresados</option></select><small class="text-muted">La matrícula actual se determina por el estado Activo, no por el año de registro.</small></div>
                        
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm btn-icon" onclick="generarRelacionEstudiantes('preview')">
                                <i class="fas fa-eye"></i> Vista previa
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
                        <div class="form-group"><label class="small font-weight-bold" for="teacher_year_select">Carga académica (opcional):</label><select class="form-control form-control-sm report-year-select" id="teacher_year_select"><option value="">Todos los años</option></select></div>
                        
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm btn-icon" onclick="generarFichaDocente('preview')">
                                <i class="fas fa-eye"></i> Vista previa
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
                            <button class="btn btn-primary btn-sm btn-icon" onclick="generarRelacionDocentes('preview')">
                                <i class="fas fa-eye"></i> Vista previa
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
                                    <button class="btn btn-primary btn-sm btn-icon" onclick="generarEstadisticasAnio('preview')">
                                        <i class="fas fa-eye"></i> Vista previa
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
                                        <h6 class="mb-0">Matrícula actual por nivel</h6>
                                    </div>
                                </div>
                                <p>Distribución actual de los estudiantes activos. No depende del año almacenado en su ficha.</p>
                                
                                <div class="d-flex gap-2">
                                    <button class="btn btn-primary btn-sm btn-icon" onclick="generarMatriculaNivel('preview')">
                                        <i class="fas fa-eye"></i> Vista previa
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
                                    <button class="btn btn-primary btn-sm btn-icon" onclick="generarReporteFinanciero('preview')">
                                        <i class="fas fa-eye"></i> Vista previa
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
    <div class="row"><div class="col-lg-6 mb-4"><div class="card shadow h-100"><div class="card-header py-3"><h6 class="m-0 font-weight-bold text-warning"><i class="fas fa-user-check mr-2"></i>Control de calidad de datos</h6></div><div class="card-body"><div class="ficha-option mb-0"><h6>Estudiantes con información incompleta</h6><p>Revisa todo el padrón institucional, sin depender del año activo.</p><div class="d-flex gap-2"><button class="btn btn-primary btn-sm btn-icon" onclick="generarControlDatos('preview')"><i class="fas fa-eye"></i> Vista previa</button><button class="btn btn-success btn-sm btn-icon" onclick="generarControlDatos('excel')"><i class="fas fa-file-excel"></i> Excel</button></div></div></div></div></div><div class="col-lg-6 mb-4"><div class="card shadow h-100"><div class="card-header py-3"><h6 class="m-0 font-weight-bold text-danger"><i class="fas fa-book-open mr-2"></i>Control de carga académica</h6></div><div class="card-body"><div class="ficha-option mb-0"><h6>Cursos activos sin docente</h6><p>Muestra los cursos de un año específico que todavía no tienen asignación.</p><div class="form-group"><label class="small font-weight-bold">Año académico:</label><select id="year_select4" class="form-control form-control-sm report-year-select"><option value="">Seleccionar año...</option></select></div><div class="d-flex gap-2"><button class="btn btn-primary btn-sm btn-icon" onclick="generarCursosSinDocente('preview')"><i class="fas fa-eye"></i> Vista previa</button><button class="btn btn-success btn-sm btn-icon" onclick="generarCursosSinDocente('excel')"><i class="fas fa-file-excel"></i> Excel</button></div></div></div></div></div></div>
    </div>
</div>
<div class="modal fade" id="catalog-filter-modal" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title mb-1" id="catalog-filter-title">Filtros del reporte</h5><p class="small text-muted mb-0" id="catalog-filter-description"></p></div><button class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body"><div class="row"><div class="col-md-6 form-group cf-year-wrap"><label class="small font-weight-bold">Año académico</label><select id="cf-year" class="form-control report-year-select"><option value="">Todos</option></select></div><div class="col-md-6 form-group cf-period-wrap"><label class="small font-weight-bold">Bimestre</label><select id="cf-period" class="form-control"><option value="">Todos</option><option value="1">1.er bimestre</option><option value="2">2.do bimestre</option><option value="3">3.er bimestre</option><option value="4">4.to bimestre</option></select></div><div class="col-md-4 form-group"><label class="small font-weight-bold">Nivel</label><select id="cf-level" class="form-control"><option value="">Todos</option><option>Inicial</option><option>Primaria</option><option>Secundaria</option></select></div><div class="col-md-4 form-group"><label class="small font-weight-bold">Grado</label><select id="cf-grade" class="form-control"><option value="">Todos</option></select></div><div class="col-md-4 form-group"><label class="small font-weight-bold">Sección</label><select id="cf-section" class="form-control"><option value="">Todas</option><option>U</option><option>A</option><option>B</option><option>C</option><option>D</option><option>E</option><option>F</option></select></div><div class="col-md-6 form-group cf-date-wrap"><label class="small font-weight-bold">Desde</label><input id="cf-date-from" type="date" class="form-control"></div><div class="col-md-6 form-group cf-date-wrap"><label class="small font-weight-bold">Hasta</label><input id="cf-date-to" type="date" class="form-control"></div><div class="col-md-6 form-group cf-financial-wrap"><label class="small font-weight-bold">Tipo de seguimiento</label><select id="cf-financial-type" class="form-control"><option value="morosity">Deudas vencidas con saldo</option><option value="partial_payments">Pagos parciales con saldo</option></select></div></div></div><div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Cancelar</button><button class="btn btn-outline-primary" onclick="generarReporteCatalogo('preview')"><i class="fas fa-eye mr-1"></i>Vista previa</button><button class="btn btn-success" onclick="generarReporteCatalogo('excel')"><i class="fas fa-file-excel mr-1"></i>Excel</button></div></div></div></div>
<div class="modal fade" id="institutional-report-modal" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog modal-xl" style="max-width:96vw"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title"><i class="fas fa-file-alt text-primary mr-2"></i>Vista previa institucional</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body p-0" style="height:82vh"><div id="institutional-report-loading" class="h-100 d-flex align-items-center justify-content-center text-muted"><i class="fas fa-spinner fa-spin mr-2"></i>Preparando documento...</div><iframe id="institutional-report-frame" style="display:none;width:100%;height:100%;border:0"></iframe></div></div></div></div>

<script>
const catalogReports={
 enrollment:{title:'Nómina de matriculados por aula',description:'Se incluyen solamente estudiantes con estado Activo.',report:'enrollment',view:'detail',preset:'roster',columns:['student_code','student_name','level','grade_section','student_status','guardian','phone']},
 risk:{title:'Estudiantes en riesgo académico',description:'Promedios numéricos inferiores a 11.',report:'academic',view:'consolidated',preset:'risk',columns:['student_code','student_name','level','grade_section','evaluations','average','minimum','maximum']},
 attendance_alert:{title:'Alertas de asistencia',description:'Tres ausencias, cinco tardanzas o asistencia menor al 80 %.',report:'attendance',view:'consolidated',preset:'attendance_alert',columns:['student_code','student_name','level','grade_section','records','present','late','absent','justified','attendance_rate']},
 financial_alerts:{title:'Morosidad y pagos parciales',description:'Selecciona el tipo de seguimiento financiero.',report:'financial',view:'detail',preset:'morosity',columns:['student_code','student_name','level','grade_section','concept','due_date','effective_amount','paid','balance']},
 teacher_load:{title:'Carga académica docente',description:'Consolidado de asignaciones y horas por docente.',report:'teachers',view:'consolidated',preset:'teacher_load',columns:['teacher_code','teacher_name','teacher_status','weekly_hours','assignments','courses','classrooms']}
};
let currentCatalogReport='';
function catalogGrades(){let level=$('#cf-level').val(),grades=[];if(level==='Inicial')grades=['3 años','4 años','5 años'];if(level==='Primaria')grades=['1°','2°','3°','4°','5°','6°'];if(level==='Secundaria')grades=['1°','2°','3°','4°','5°'];let html='<option value="">Todos</option>';grades.forEach(v=>html+=`<option>${v}</option>`);$('#cf-grade').html(html).trigger('change.select2')}
function abrirFiltrosCatalogo(key){currentCatalogReport=key;let cfg=catalogReports[key];$('#catalog-filter-title').text(cfg.title);$('#catalog-filter-description').text(cfg.description);$('#cf-level,#cf-grade,#cf-section,#cf-period').val('').trigger('change');$('#cf-year').val(['risk','financial_alerts','teacher_load'].includes(key)?(window.activeReportYear||''):'').trigger('change');$('#cf-date-from,#cf-date-to').val('');$('.cf-year-wrap').toggle(key!=='enrollment'&&key!=='attendance_alert');$('.cf-period-wrap').toggle(key==='risk');$('.cf-date-wrap').toggle(key==='attendance_alert');$('.cf-financial-wrap').toggle(key==='financial_alerts');$('#catalog-filter-modal').modal('show')}
function generarReporteCatalogo(format){let cfg=catalogReports[currentCatalogReport];if(!cfg)return;let preset=currentCatalogReport==='financial_alerts'?$('#cf-financial-type').val():cfg.preset;let params=new URLSearchParams({report:cfg.report,view:cfg.view,preset:preset,year_id:$('#cf-year').val()||'',level:$('#cf-level').val()||'',grade:$('#cf-grade').val()||'',section:$('#cf-section').val()||'',period:$('#cf-period').val()||'',date_from:$('#cf-date-from').val()||'',date_to:$('#cf-date-to').val()||''});cfg.columns.forEach(c=>params.append('columns[]',c));let url=(format==='excel'?'export_report_builder.php':'report_builder.php')+'?'+params.toString();if(format==='excel')window.location.href=url;else{$('#catalog-filter-modal').modal('hide');abrirReporteInstitucional(url,'preview')}}
function abrirReporteInstitucional(url,format){if(format==='excel'){window.location.href=url.replace('format=preview','format=excel');return}let frame=$('#institutional-report-frame');$('#institutional-report-loading').removeClass('d-none').addClass('d-flex');frame.hide().off('load.ficha').on('load.ficha',function(){$('#institutional-report-loading').removeClass('d-flex').addClass('d-none');frame.show()}).attr('src',url.replace('format=preview','format=pdf'));$('#institutional-report-modal').modal('show')}
$('#institutional-report-modal').on('hidden.bs.modal',function(){$('#institutional-report-frame').attr('src','about:blank').hide()});
$(document).ready(function() {
    initSelect2();
    cargarEstudiantes();
    cargarDocentes();
    cargarNiveles();
    cargarAniosAcademicos();
    $('#cf-level').on('change',catalogGrades);
    $('#institutional-report-search').on('input',function(){let term=$(this).val().toLocaleLowerCase('es');$('.ficha-option').each(function(){$(this).toggleClass('is-hidden',term!==''&&$(this).text().toLocaleLowerCase('es').indexOf(term)===-1)})});
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

    $('#nivel_select, #grado_select, #seccion_select, #enrollment_status_select, #student_year_select, #teacher_year_select, #year_select, #year_select3, #year_select4, #cf-year, #cf-level, #cf-grade, #cf-section, #cf-period, #cf-financial-type').select2({
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
                    options += `<option value="${student.id}">${student.id_no} - ${student.name} (${student.status||'Sin estado'})</option>`;
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
                    options += `<option value="${teacher.id}">${teacher.id_no} - ${teacher.name} (${teacher.status||'Sin estado'})</option>`;
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
                let optional = '<option value="">Todos los años / vista general</option>',required='<option value="">Seleccionar año...</option>',active='';
                resp.years.forEach(function(year) {
                    let option=`<option value="${year.id}">${year.year}${Number(year.is_active)===1?' (Activo)':''}</option>`;optional+=option;required+=option;
                    if(Number(year.is_active)===1)active=String(year.id);
                });
                window.activeReportYear=active;
                $('#student_year_select,#teacher_year_select,#cf-year').html(optional).val('').trigger('change.select2');
                $('#year_select,#year_select3,#year_select4').html(required).val(active).trigger('change.select2');
            } else {
                $('.report-year-select,#year_select,#year_select3,#year_select4').html('<option value="">Error al cargar años</option>');
            }
        },
        error: function() {
            $('.report-year-select,#year_select,#year_select3,#year_select4').html('<option value="">Error al cargar años</option>');
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
    abrirReporteInstitucional('generar_ficha.php?type=student&id='+studentId+'&year_id='+($('#student_year_select').val()||'')+'&format='+format,format);
}

function generarFichaDocente(format = 'pdf') {
    let teacherId = $('#teacher_select').val();
    if (!teacherId) {
        alert('Por favor seleccione un docente');
        return;
    }
    abrirReporteInstitucional('generar_ficha.php?type=teacher&id='+teacherId+'&year_id='+($('#teacher_year_select').val()||'')+'&format='+format,format);
}

function generarRelacionEstudiantes(format = 'pdf') {
    let nivel = $('#nivel_select').val();
    let grado = $('#grado_select').val();
    let seccion = $('#seccion_select').val();
    let enrollmentStatus=$('#enrollment_status_select').val();
    
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
    if (enrollmentStatus) params.append('enrollment_status',enrollmentStatus);
    abrirReporteInstitucional('generar_ficha.php?'+params.toString(),format);
}

function generarRelacionDocentes(format = 'pdf') {
    abrirReporteInstitucional('generar_ficha.php?type=teacher_list&format='+format,format);
}

function generarEstadisticasAnio(format = 'pdf') {
    let yearId = $('#year_select').val();
    if (!yearId) {
        alert('Por favor seleccione un año académico');
        return;
    }
    abrirReporteInstitucional('generar_ficha.php?type=year_stats&year_id='+yearId+'&format='+format,format);
}

function generarMatriculaNivel(format = 'pdf') {
    abrirReporteInstitucional('generar_ficha.php?type=enrollment_by_level&format='+format,format);
}

function generarReporteFinanciero(format = 'pdf') {
    let yearId = $('#year_select3').val();
    if (!yearId) {
        alert('Por favor seleccione un año académico');
        return;
    }
    abrirReporteInstitucional('generar_ficha.php?type=financial_report&year_id='+yearId+'&format='+format,format);
}
function generarControlDatos(format='preview'){abrirReporteInstitucional('generar_ficha.php?type=data_quality&format='+format,format)}
function generarCursosSinDocente(format='preview'){let year=$('#year_select4').val();if(!year){alert('Seleccione un año académico');return}abrirReporteInstitucional('generar_ficha.php?type=unassigned_courses&year_id='+year+'&format='+format,format)}
</script>
