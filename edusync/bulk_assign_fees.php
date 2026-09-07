<?php include 'db_connect.php' ?>
<?php include_once 'includes/session_check.php'; require_login_modal(); if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32)); $bulk_csrf=$_SESSION['csrf_token']; $bulk_school=(int)($_SESSION['login_school_id']??0); ?>
<style>
/* Estilos específicos e independientes para el modal de asignación masiva */
/* Solo se aplicarán cuando el modal tenga el formulario #bulk-assign-fees */

/* Layout de dos columnas independiente */
.bulk-assignment-grid {
    display: grid;
    grid-template-columns: 42% 58%;
    gap: 20px;
    margin-bottom: 20px;
}

.bulk-students-section {
    background: #f8f9fa;
    border: 1px solid #e3e6f0;
    border-radius: 8px;
    padding: 20px;
}

.bulk-concepts-section {
    background: #ffffff;
    border: 1px solid #e3e6f0;
    border-radius: 8px;
    padding: 20px;
}

.bulk-students-container, .bulk-concepts-container {
    height: 450px;
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 15px;
    background: white;
}

.bulk-concepts-container {
    background: #fafafa;
}

.student-selector {
    border: 1px solid #e3e6f0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    background: #f8f9fa;
}

.concept-selector {
    border: 1px solid #e3e6f0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    background: #ffffff;
}

.selection-summary {
    border: 1px solid #28a745;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    background: #d4edda;
}

.student-item, .concept-item {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 10px;
    background: white;
    transition: all 0.2s;
    cursor: pointer;
}

.student-item:hover, .concept-item:hover {
    background: #f8f9fa;
    border-color: #007bff;
}

.student-item.selected {
    background: #e3f2fd;
    border-color: #2196f3;
}

.concept-item.selected {
    background: #d4edda;
    border-color: #28a745;
}

.filter-box {
    margin-bottom: 15px;
}

.section-title {
    color: #495057;
    font-weight: 600;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
}

.section-title i {
    margin-right: 8px;
}

.badge-level {
    font-size: 0.75rem;
    padding: 4px 8px;
}

.summary-item {
    background: white;
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 8px;
    border-left: 4px solid #007bff;
}

.btn-toggle-all {
    margin-bottom: 15px;
}

/* Responsive específico para este modal */
@media (max-width: 992px) {
    .bulk-assignment-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .bulk-students-container, .bulk-concepts-container {
        height: 350px;
    }
}

@media (max-width: 768px) {
    .bulk-students-container, .bulk-concepts-container {
        height: 300px;
    }
}
</style>

<div class="container-fluid">
    <form id="bulk-assign-fees">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($bulk_csrf); ?>">
        <div id="msg"></div>
        
        <!-- Grid de dos columnas independiente -->
        <div class="bulk-assignment-grid">
            <!-- Columna de Estudiantes -->
            <div class="bulk-students-section">
                <h5 class="section-title">
                    <i class="fa fa-users text-primary"></i>
                    Seleccionar Estudiantes
                </h5>
                
                <div class="row">
                    <div class="col-12">
                        <div class="filter-box">
                            <label class="form-label">Filtrar por Nivel:</label>
                            <select id="filter-nivel" class="form-control form-control-sm">
                                <option value="">Todos los niveles</option>
                                <option value="Inicial">Inicial</option>
                                <option value="Primaria">Primaria</option>
                                <option value="Secundaria">Secundaria</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="filter-box">
                            <label class="form-label">Filtrar por Grado:</label>
                            <select id="filter-grado" class="form-control form-control-sm">
                                <option value="">Todos los grados</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="filter-box">
                            <label class="form-label">Filtrar por Sección:</label>
                            <select id="filter-seccion" class="form-control form-control-sm">
                                <option value="">Todas las secciones</option>
                                <option value="U">U</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                                <option value="E">E</option>
                                <option value="F">F</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="filter-box">
                            <label class="form-label">Filtrar por Estado:</label>
                            <select id="filter-status" class="form-control form-control-sm">
                                <option value="">Todos los estados</option>
                                <option value="Activo" selected>Activo</option>
                                <option value="Egresado">Egresado</option>
                                <option value="Retirado">Retirado</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="filter-box">
                            <label class="form-label">Buscar por Nombre:</label>
                            <input type="text" id="filter-name" class="form-control form-control-sm" placeholder="Escriba el nombre...">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm btn-toggle-all" id="toggle-all-students">
                        <i class="fa fa-check-square"></i> Seleccionar/Deseleccionar Todos
                    </button>
                    <span id="selected-students-count" class="badge badge-primary ml-2">0 seleccionados</span>
                </div>
                
                <div class="bulk-students-container" id="students-container">
                    <div class="text-center">
                        <i class="fa fa-spinner fa-spin"></i> Cargando estudiantes...
                    </div>
                </div>
            </div>

            <!-- Columna de Conceptos -->
            <div class="bulk-concepts-section">
                <h5 class="section-title">
                    <i class="fa fa-file-text text-success"></i>
                    Seleccionar Conceptos de Pago
                    <small id="concepts-status" class="ml-2 text-muted">
                        <i class="fa fa-info-circle"></i> Seleccione estudiantes para ver conceptos compatibles
                    </small>
                </h5>
                
                <div class="row">
                    <div class="col-12">
                        <div class="filter-box">
                            <label class="form-label">Buscar Concepto:</label>
                            <input type="text" id="filter-concept" class="form-control form-control-sm" placeholder="Buscar concepto...">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <button type="button" class="btn btn-outline-success btn-sm btn-toggle-all" id="toggle-all-concepts">
                        <i class="fa fa-check-square"></i> Seleccionar/Deseleccionar Todos
                    </button>
                    <span id="selected-concepts-count" class="badge badge-success ml-2">0 seleccionados</span>
                </div>
                
                <div class="bulk-concepts-container" id="concepts-container">
                    <div class="text-center">
                        <i class="fa fa-spinner fa-spin"></i> Cargando conceptos...
                    </div>
                </div>
            </div>
        </div>

        <!-- Resumen de Selección -->
        <div class="selection-summary" id="selection-summary" style="display: none;">
            <h5 class="section-title">
                <i class="fa fa-clipboard-check text-success"></i>
                Resumen de Asignación
            </h5>
            <div class="row">
                <div class="col-md-6">
                    <strong>Estudiantes seleccionados:</strong>
                    <div id="summary-students"></div>
                </div>
                <div class="col-md-6">
                    <strong>Conceptos seleccionados:</strong>
                    <div id="summary-concepts"></div>
                </div>
            </div>
            <div class="mt-3">
                <strong>Total de asignaciones que se crearán: <span id="total-assignments" class="text-primary"></span></strong>
            </div>
        </div>

        <div class="card border-0 bg-light mb-3"><div class="card-body py-3"><div class="form-group mb-0"><label>Fecha de vencimiento (opcional)</label><input type="date" id="bulk-due-date" class="form-control"><small class="form-text text-muted">Los conceptos se tomarán automáticamente del año académico activo.</small></div></div></div>

        <!-- Botones de Acción -->
        <div class="text-right">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                <i class="fa fa-times"></i> Cancelar
            </button>
            <button type="submit" class="btn btn-success" id="btn-assign" disabled>
                <i class="fa fa-check"></i> Asignar Deudas
            </button>
        </div>
    </form>
</div>

<script>
// Función para forzar el ancho del modal de forma independiente
function forceBulkModalSize() {
    // Solo aplicar si el modal contiene específicamente el formulario de bulk assignment
    var modal = $('#uni_modal');
    if (modal.length > 0 && modal.find('#bulk-assign-fees').length > 0) {
        // Aplicar estilos directamente al modal solo si contiene nuestro formulario
        modal.find('.modal-dialog').css({
            'width': '95%',
            'max-width': '1400px',
            'margin': '30px auto'
        });
        
        // Agregar clase específica para identificar este modal
        modal.addClass('bulk-assignment-modal');
    }
}

// Función para remover estilos cuando no es nuestro modal
function removeBulkModalStyles() {
    var modal = $('#uni_modal');
    if (modal.length > 0 && modal.find('#bulk-assign-fees').length === 0) {
        // Si el modal no contiene nuestro formulario, remover la clase
        modal.removeClass('bulk-assignment-modal');
        
        // Remover estilos específicos
        modal.find('.modal-dialog').css({
            'width': '',
            'max-width': '',
            'margin': ''
        });
    }
}

// Aplicar estilos CSS específicos de forma dinámica
function injectBulkModalCSS() {
    var cssId = 'bulk-modal-independent-styles';
    if (!document.getElementById(cssId)) {
        var head = document.getElementsByTagName('head')[0];
        var link = document.createElement('style');
        link.id = cssId;
        link.type = 'text/css';
        link.innerHTML = `
            /* Solo aplicar a modales que tengan la clase bulk-assignment-modal */
            .bulk-assignment-modal .modal-dialog {
                width: 95% !important;
                max-width: 1400px !important;
                margin: 30px auto !important;
            }
            
            .bulk-assignment-modal.modal-lg .modal-dialog {
                width: 98% !important;
                max-width: 1500px !important;
            }
            
            .bulk-assignment-modal .modal-content {
                border-radius: 12px !important;
                box-shadow: 0 8px 32px rgba(0,0,0,0.15) !important;
            }
            
            @media (max-width: 992px) {
                .bulk-assignment-modal .modal-dialog {
                    width: 98% !important;
                }
            }
            
            @media (max-width: 768px) {
                .bulk-assignment-modal .modal-dialog {
                    width: 99% !important;
                    margin: 15px auto !important;
                }
            }
        `;
        head.appendChild(link);
    }
}

// Aplicar configuración inmediatamente
$(document).ready(function() {
    // Inyectar CSS específico
    injectBulkModalCSS();
    
    // Aplicar configuración inicial solo si nuestro formulario está presente
    setTimeout(function() {
        if ($('#bulk-assign-fees').length > 0) {
            forceBulkModalSize();
        }
    }, 100);
});

// Observar cambios en el DOM para mantener la configuración
var bulkModalObserver = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
        if (mutation.type === 'childList') {
            // Verificar si se agregó o removió contenido del modal
            var modal = $('#uni_modal');
            if (modal.length > 0) {
                if (modal.find('#bulk-assign-fees').length > 0) {
                    // Si se detecta nuestro formulario, aplicar estilos
                    forceBulkModalSize();
                } else {
                    // Si no se detecta nuestro formulario, remover estilos
                    removeBulkModalStyles();
                }
            }
        }
    });
});

// Iniciar observación cuando el documento esté listo
$(document).ready(function() {
    setTimeout(function() {
        if (document.body) {
            bulkModalObserver.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    }, 500);
});

var selectedStudents = [];
var selectedConcepts = [];
var allStudents = [];
var allConcepts = [];

// Cargar estudiantes
function loadStudents() {
    $.ajax({
        url: 'ajax.php?action=get_active_students',
        method: 'GET',
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                allStudents = resp.students;
                filterStudents();
            } else {
                $('#students-container').html('<div class="text-danger">Error: ' + (resp.message || 'No se pudieron cargar los estudiantes') + '</div>');
            }
        },
        error: function(err) {
            console.log('Error al cargar estudiantes:', err);
            $('#students-container').html('<div class="text-danger">Error de conexión al cargar estudiantes</div>');
        }
    });
}

// Cargar conceptos
function loadConcepts() {
    // Usar los estudiantes seleccionados para filtrar conceptos
    var studentIds = selectedStudents.length > 0 ? selectedStudents : [];
    
    $.ajax({
        url: 'ajax.php?action=get_available_concepts_for_bulk',
        method: 'POST',
        data: {
            student_ids: studentIds
        },
        dataType: 'json',
        success: function(resp) {
            if (resp.status == 1) {
                allConcepts = resp.concepts;
                filterConcepts();
            } else {
                $('#concepts-container').html('<div class="text-danger">Error: ' + (resp.message || 'No se pudieron cargar los conceptos') + '</div>');
            }
        },
        error: function(err) {
            console.log('Error al cargar conceptos:', err);
            $('#concepts-container').html('<div class="text-danger">Error de conexión al cargar conceptos</div>');
        }
    });
}

// Mostrar estudiantes
function displayStudents(students) {
    var html = '';
    for (var i = 0; i < students.length; i++) {
        var student = students[i];
        var isSelected = selectedStudents.indexOf(student.id.toString()) !== -1;
        
        html += '<div class="student-item ' + (isSelected ? 'selected' : '') + '" data-id="' + student.id + '">';
        html += '<div class="d-flex justify-content-between align-items-center">';
        html += '<div>';
        html += '<strong>' + student.name + '</strong>';
        html += '<div class="text-muted small">ID: ' + student.id_no + '</div>';
        html += '</div>';
        html += '<div class="text-right">';
        html += '<span class="badge badge-level badge-info">' + (student.nivel || 'N/A') + '</span> ';
        html += '<span class="badge badge-level badge-secondary">' + (student.grado || 'N/A') + ' ' + (student.seccion || '') + '</span><br>';
        
        var statusClass = 'badge-success';
        if (student.status == 'Retirado') statusClass = 'badge-danger';
        else if (student.status == 'Egresado') statusClass = 'badge-warning';
        
        html += '<span class="badge badge-level ' + statusClass + '">' + (student.status || 'Activo') + '</span>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
    }
    
    if (html === '') {
        html = '<div class="text-muted text-center">No hay estudiantes que coincidan con los filtros</div>';
    }
    
    $('#students-container').html(html);
    updateStudentCount();
}

// Mostrar conceptos
function displayConcepts(concepts) {
    var html = '';
    for (var i = 0; i < concepts.length; i++) {
        var concept = concepts[i];
        var isSelected = selectedConcepts.indexOf(concept.id.toString()) !== -1;
        
        // Información de disponibilidad
        var availabilityInfo = '';
        if (selectedStudents.length > 0 && concept.available_for_students !== undefined) {
            var availableFor = concept.available_for_students;
            var totalStudents = concept.total_students;
            if (availableFor < totalStudents) {
                availabilityInfo = ' | Disponible para ' + availableFor + '/' + totalStudents + ' estudiantes';
            }
        }
        
        // Información de compatibilidad de grados
        var gradeInfo = '';
        if (concept.grades && concept.grades.trim() !== '') {
            gradeInfo = ' | Grados: ' + concept.grades;
        } else {
            gradeInfo = ' | Todos los grados';
        }
        
        html += '<div class="concept-item ' + (isSelected ? 'selected' : '') + '" data-id="' + concept.id + '">';
        html += '<div class="d-flex justify-content-between align-items-center">';
        html += '<div>';
        html += '<strong>' + concept.course + '</strong>';
        html += '<div class="text-muted small">';
        html += '<i class="fa fa-layer-group"></i> ' + (concept.level || 'N/A');
        html += gradeInfo + ' | ';
        html += '<i class="fa fa-calendar"></i> ' + (concept.year || 'Sin año');
        html += availabilityInfo;
        html += '</div>';
        html += '</div>';
        html += '<div class="text-right">';
        html += '<span class="badge badge-success">S/ ' + parseFloat(concept.total_amount || 0).toLocaleString() + '</span>';
        // Mostrar indicador de disponibilidad parcial
        if (selectedStudents.length > 0 && concept.available_for_students !== undefined && concept.available_for_students < concept.total_students) {
            html += '<br><small class="text-warning"><i class="fa fa-exclamation-triangle"></i> Parcial</small>';
        }
        html += '</div>';
        html += '</div>';
        html += '</div>';
    }
    
    if (html === '') {
        if (selectedStudents.length > 0) {
            html = '<div class="text-muted text-center"><i class="fa fa-info-circle"></i> No hay conceptos disponibles para los estudiantes seleccionados.<br><small>Esto puede ser porque todos los conceptos ya están asignados o no corresponden al nivel/grado de los estudiantes.</small></div>';
        } else {
            html = '<div class="text-muted text-center">No hay conceptos que coincidan con los filtros</div>';
        }
    }
    
    $('#concepts-container').html(html);
    updateConceptCount();
}

// Filtrar estudiantes
function filterStudents() {
    var nivel = $('#filter-nivel').val().toLowerCase();
    var grado = $('#filter-grado').val().toLowerCase();
    var seccion = $('#filter-seccion').val().toLowerCase();
    var status = $('#filter-status').val().toLowerCase();
    var name = $('#filter-name').val().toLowerCase();
    
    var filteredStudents = allStudents.filter(function(student) {
        var matchNivel = !nivel || (student.nivel && student.nivel.toLowerCase().indexOf(nivel) !== -1);
        var matchGrado = !grado || (student.grado && student.grado.toLowerCase().indexOf(grado) !== -1);
        var matchSeccion = !seccion || (student.seccion && student.seccion.toLowerCase() === seccion);
        var sStatus = student.status ? student.status.toLowerCase() : 'activo';
        var matchStatus = !status || sStatus === status;
        var matchName = !name || student.name.toLowerCase().indexOf(name) !== -1 || student.id_no.indexOf(name) !== -1;
        
        return matchNivel && matchGrado && matchSeccion && matchStatus && matchName;
    });
    
    displayStudents(filteredStudents);
}

// Filtrar conceptos
function filterConcepts() {
    var conceptName = $('#filter-concept').val().toLowerCase();
    
    var filteredConcepts = allConcepts.filter(function(concept) {
        var matchName = !conceptName || concept.course.toLowerCase().indexOf(conceptName) !== -1;
        
        return matchName;
    });
    
    displayConcepts(filteredConcepts);
}

// Actualizar contador de estudiantes
function updateStudentCount() {
    $('#selected-students-count').text(selectedStudents.length + ' seleccionados');
    
    // Actualizar status de conceptos
    if (selectedStudents.length > 0) {
        $('#concepts-status').html('<i class="fa fa-sync fa-spin"></i> Actualizando conceptos compatibles...');
    } else {
        $('#concepts-status').html('<i class="fa fa-info-circle"></i> Seleccione estudiantes para ver conceptos compatibles con su nivel y grado');
    }
}

// Actualizar contador de conceptos
function updateConceptCount() {
    $('#selected-concepts-count').text(selectedConcepts.length + ' seleccionados');
    
    // Actualizar status de conceptos
    if (selectedStudents.length > 0) {
        // Obtener información de niveles y grados de estudiantes seleccionados
        var studentLevels = [];
        var studentGrades = [];
        
        for (var i = 0; i < allStudents.length; i++) {
            if (selectedStudents.indexOf(allStudents[i].id.toString()) !== -1) {
                var student = allStudents[i];
                if (student.nivel && studentLevels.indexOf(student.nivel) === -1) {
                    studentLevels.push(student.nivel);
                }
                if (student.grado && studentGrades.indexOf(student.grado) === -1) {
                    studentGrades.push(student.grado);
                }
            }
        }
        
        var levelText = studentLevels.length > 0 ? studentLevels.join(', ') : 'Varios';
        var gradeText = studentGrades.length > 0 ? studentGrades.join(', ') : 'Varios';
        
        $('#concepts-status').html('<i class="fa fa-check-circle text-success"></i> Mostrando conceptos disponibles para ' + selectedStudents.length + ' estudiante(s) - Niveles: ' + levelText + ' - Grados: ' + gradeText);
    } else {
        $('#concepts-status').html('<i class="fa fa-info-circle"></i> Seleccione estudiantes para ver conceptos compatibles con su nivel y grado');
    }
}

// Actualizar resumen
function updateSummary() {
    if (selectedStudents.length > 0 && selectedConcepts.length > 0) {
        $('#selection-summary').show();
        
        // Mostrar estudiantes seleccionados
        var studentsHtml = '';
        var studentCount = 0;
        for (var i = 0; i < allStudents.length; i++) {
            if (selectedStudents.indexOf(allStudents[i].id.toString()) !== -1) {
                studentsHtml += '<div class="summary-item">' + allStudents[i].name + ' (' + allStudents[i].id_no + ')</div>';
                studentCount++;
            }
        }
        if (studentCount > 5) {
            studentsHtml = '<div class="summary-item">' + studentCount + ' estudiantes seleccionados</div>';
        }
        $('#summary-students').html(studentsHtml);
        
        // Mostrar conceptos seleccionados
        var conceptsHtml = '';
        var conceptCount = 0;
        var totalAmount = 0;
        for (var i = 0; i < allConcepts.length; i++) {
            if (selectedConcepts.indexOf(allConcepts[i].id.toString()) !== -1) {
                var amount = parseFloat(allConcepts[i].total_amount || 0);
                totalAmount += amount;
                conceptsHtml += '<div class="summary-item">' + allConcepts[i].course + ' - ' + (allConcepts[i].level || 'N/A') + ' (' + (allConcepts[i].year || 'Sin año') + ') - S/ ' + amount.toLocaleString() + '</div>';
                conceptCount++;
            }
        }
        if (conceptCount > 5) {
            conceptsHtml = '<div class="summary-item">' + conceptCount + ' conceptos seleccionados (Total: S/ ' + totalAmount.toLocaleString() + ')</div>';
        }
        $('#summary-concepts').html(conceptsHtml);
        
        // Total de asignaciones
        var totalAssignments = selectedStudents.length * selectedConcepts.length;
        var grandTotal = totalAmount * selectedStudents.length;
        $('#total-assignments').html(totalAssignments + ' <span class="text-success">(Monto total: S/ ' + grandTotal.toLocaleString() + ')</span>');
        
        $('#btn-assign').prop('disabled', false);
    } else {
        $('#selection-summary').hide();
        $('#btn-assign').prop('disabled', true);
    }
}

// Event handlers
$(document).ready(function() {
    // Manejar clicks en estudiantes
    $(document).on('click', '.student-item', function() {
        var studentId = $(this).data('id').toString();
        var index = selectedStudents.indexOf(studentId);
        
        if (index > -1) {
            selectedStudents.splice(index, 1);
            $(this).removeClass('selected');
        } else {
            selectedStudents.push(studentId);
            $(this).addClass('selected');
        }
        
        updateStudentCount();
        updateSummary();
        
        // Recargar conceptos cuando cambien los estudiantes seleccionados
        loadConcepts();
    });

    // Manejar clicks en conceptos
    $(document).on('click', '.concept-item', function() {
        var conceptId = $(this).data('id').toString();
        var index = selectedConcepts.indexOf(conceptId);
        
        if (index > -1) {
            selectedConcepts.splice(index, 1);
            $(this).removeClass('selected');
        } else {
            selectedConcepts.push(conceptId);
            $(this).addClass('selected');
        }
        
        updateConceptCount();
        updateSummary();
    });

    // Toggle todos los estudiantes
    $('#toggle-all-students').click(function() {
        var visibleStudents = $('#students-container .student-item:visible');
        var allSelected = true;
        
        visibleStudents.each(function() {
            var studentId = $(this).data('id').toString();
            if (selectedStudents.indexOf(studentId) === -1) {
                allSelected = false;
                return false;
            }
        });
        
        visibleStudents.each(function() {
            var studentId = $(this).data('id').toString();
            var index = selectedStudents.indexOf(studentId);
            
            if (allSelected) {
                // Deseleccionar todos
                if (index > -1) {
                    selectedStudents.splice(index, 1);
                }
                $(this).removeClass('selected');
            } else {
                // Seleccionar todos
                if (index === -1) {
                    selectedStudents.push(studentId);
                }
                $(this).addClass('selected');
            }
        });
        
        updateStudentCount();
        updateSummary();
        
        // Recargar conceptos cuando cambien los estudiantes seleccionados
        loadConcepts();
    });

    // Toggle todos los conceptos
    $('#toggle-all-concepts').click(function() {
        var visibleConcepts = $('#concepts-container .concept-item:visible');
        var allSelected = true;
        
        visibleConcepts.each(function() {
            var conceptId = $(this).data('id').toString();
            if (selectedConcepts.indexOf(conceptId) === -1) {
                allSelected = false;
                return false;
            }
        });
        
        visibleConcepts.each(function() {
            var conceptId = $(this).data('id').toString();
            var index = selectedConcepts.indexOf(conceptId);
            
            if (allSelected) {
                // Deseleccionar todos
                if (index > -1) {
                    selectedConcepts.splice(index, 1);
                }
                $(this).removeClass('selected');
            } else {
                // Seleccionar todos
                if (index === -1) {
                    selectedConcepts.push(conceptId);
                }
                $(this).addClass('selected');
            }
        });
        
        updateConceptCount();
        updateSummary();
    });

    // Cargar grados cuando cambia el nivel
    $('#filter-nivel').change(function() {
        var nivel = $(this).val();
        var grados = [];
        
        if (nivel === 'Inicial') {
            grados = ['3°', '4°', '5°'];
        } else if (nivel === 'Primaria') {
            grados = ['1°', '2°', '3°', '4°', '5°', '6°'];
        } else if (nivel === 'Secundaria') {
            grados = ['1°', '2°', '3°', '4°', '5°'];
        }
        
        var options = '<option value="">Todos los grados</option>';
        for (var i = 0; i < grados.length; i++) {
            options += '<option value="' + grados[i] + '">' + grados[i] + '</option>';
        }
        $('#filter-grado').html(options);
        
        // Aplicar filtro después de cargar grados
        filterStudents();
    });

    // Eventos de filtros
    $('#filter-grado, #filter-seccion, #filter-status, #filter-name').on('change keyup', function() {
        filterStudents();
    });
    
    $('#filter-concept').on('change keyup', function() {
        filterConcepts();
    });

    // Enviar formulario
    $('#bulk-assign-fees').submit(function(e) {
        e.preventDefault();
        
        if (selectedStudents.length === 0 || selectedConcepts.length === 0) {
            alert_toast('Debe seleccionar al menos un estudiante y un concepto.', 'warning');
            return;
        }
        
        // Verificar conceptos con disponibilidad parcial
        var partialConcepts = [];
        var totalAmount = 0;
        var incompatibleWarning = '';
        
        for (var i = 0; i < allConcepts.length; i++) {
            if (selectedConcepts.indexOf(allConcepts[i].id.toString()) !== -1) {
                var concept = allConcepts[i];
                totalAmount += parseFloat(concept.total_amount || 0);
                
                if (concept.available_for_students !== undefined && concept.available_for_students < selectedStudents.length) {
                    partialConcepts.push(concept.course + ' (disponible para ' + concept.available_for_students + '/' + selectedStudents.length + ' estudiantes)');
                }
            }
        }
        
        var grandTotal = totalAmount * selectedStudents.length;
        
        var confirmMsg = '¿Está seguro de asignar ' + selectedConcepts.length + ' concepto(s) a ' + selectedStudents.length + ' estudiante(s)?\n\n';
        confirmMsg += 'Esto creará ' + (selectedStudents.length * selectedConcepts.length) + ' nueva(s) deuda(s).\n';
        confirmMsg += 'Monto total estimado: S/ ' + grandTotal.toLocaleString();
        confirmMsg += '\n\nNOTA: Solo se asignarán conceptos compatibles con el nivel y grado de cada estudiante.';
        
        if (partialConcepts.length > 0) {
            confirmMsg += '\n\nAVISO: Algunos conceptos ya están asignados a algunos estudiantes:\n';
            confirmMsg += partialConcepts.join('\n');
            confirmMsg += '\n\nSolo se asignarán a los estudiantes que aún no los tienen.';
        }
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        // Mostrar progreso
        $('#btn-assign').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Procesando...');
        start_load();
        
        $.ajax({
            url: 'fees_api.php?action=bulk_assign',
            method: 'POST',
            data: {
                students: selectedStudents,
                concepts: selectedConcepts,
                due_date: $('#bulk-due-date').val(),
                csrf_token: $('#bulk-assign-fees input[name="csrf_token"]').val()
            },
            dataType: 'json',
            success: function(resp) {
                if (resp.status == 1) {
                    end_load();
                    alert_toast(resp.message, 'success');
                    setTimeout(function() {
                        $('#uni_modal').modal('hide');
                        if (window.reload_table) window.reload_table();
                        else location.reload();
                    }, 1500);
                } else {
                    alert_toast(resp.message, 'danger');
                    $('#btn-assign').prop('disabled', false).html('<i class="fa fa-check"></i> Asignar Deudas');
                    end_load();
                }
            },
            error: function(err) {
                console.log(err);
                alert_toast('Error en el servidor', 'danger');
                $('#btn-assign').prop('disabled', false).html('<i class="fa fa-check"></i> Asignar Deudas');
                end_load();
            }
        });
    });

    // Inicializar
    loadStudents();
    loadConcepts();
    
    // Aplicar configuración del modal inmediatamente solo si nuestro formulario está presente
    if ($('#bulk-assign-fees').length > 0) {
        forceBulkModalSize();
    }
});

// Event listeners específicos para mantener el modal independiente
$(document).on('show.bs.modal', '#uni_modal', function(e) {
    // Esperar un poco para que el contenido se cargue y luego verificar
    setTimeout(function() {
        if ($('#uni_modal').find('#bulk-assign-fees').length > 0) {
            forceBulkModalSize();
        } else {
            removeBulkModalStyles();
        }
    }, 100);
});

$(document).on('shown.bs.modal', '#uni_modal', function(e) {
    // Verificar si este modal contiene nuestro formulario
    if ($(this).find('#bulk-assign-fees').length > 0) {
        forceBulkModalSize();
    } else {
        removeBulkModalStyles();
    }
});

$(document).on('hidden.bs.modal', '#uni_modal', function(e) {
    // Limpiar estilos cuando el modal se cierre
    removeBulkModalStyles();
});

// Mantener el tamaño cuando se redimensione la ventana, solo si es nuestro modal
$(window).on('resize', function() {
    if ($('#bulk-assign-fees').is(':visible')) {
        forceBulkModalSize();
    }
});

// Detectar cuando se carga contenido en el modal
$(document).on('DOMNodeInserted', '#uni_modal', function(e) {
    setTimeout(function() {
        if ($('#uni_modal').find('#bulk-assign-fees').length > 0) {
            forceBulkModalSize();
        } else {
            removeBulkModalStyles();
        }
    }, 50);
});
</script>
