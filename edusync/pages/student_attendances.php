<?php
// Verificar que sea estudiante
if ((!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) && 
    (!isset($_SESSION['login_type']) || $_SESSION['login_type'] != 4)) {
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['student_id']) || !isset($_SESSION['student_dni'])) {
    header('Location: login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'] ?? 'Estudiante';
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
            <i class="fas fa-clipboard-list mr-2 text-primary"></i>
            Mis Asistencias
        </h1>
        <div class="text-muted small">Registro de asistencias por año académico</div>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4" id="stats-cards">
    <div class="col-12 text-center">
        <i class="fas fa-spinner fa-spin fa-3x text-muted"></i>
    </div>
</div>

<!-- Attendance Card -->
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #4285f4 0%, #2a75f3 100%); color: white;">
        <h6 class="m-0 font-weight-bold" style="color: white;">
            <i class="fas fa-calendar-check mr-2"></i>Detalle de Asistencias
        </h6>
        <div>
            <select id="year-filter" class="form-control form-control-sm" style="width: 200px; border-color: #e3e6f0;">
                <option value="">Seleccione un año...</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <div id="attendance-container">
            <div class="text-center p-5">
                <i class="fas fa-spinner fa-spin fa-3x text-muted"></i>
                <p class="mt-3 text-muted">Cargando asistencias...</p>
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
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 20px;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(66,133,244,0.3);
    }
    
    /* === Stats Cards === */
    .stat-card {
        background: white;
        border: none;
        border-radius: 10px;
        border-left: 4px solid #4285f4;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        padding: 25px;
        text-align: center;
        margin-bottom: 20px;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    }
    
    .stat-card .stat-value {
        font-size: 2.5rem;
        font-weight: 700;
        margin: 10px 0;
    }
    
    .stat-card .stat-label {
        color: #6c757d;
        font-size: 0.9rem;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    
    .stat-card-icon {
        font-size: 2.5rem;
        margin-bottom: 10px;
    }
    
    .stat-card.present .stat-card-icon { color: #1cc88a; }
    .stat-card.late .stat-card-icon { color: #f6c23e; }
    .stat-card.total .stat-card-icon { color: #4285f4; }
    
    /* === Accordion === */
    .accordion .card {
        background: white;
        transition: all 0.3s ease;
    }
    
    .accordion .card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .accordion .btn-link {
        text-decoration: none;
        color: inherit;
        font-weight: 600;
    }
    
    .accordion .btn-link:not(.collapsed) {
        color: #4285f4;
    }
    
    .accordion .btn-link::after {
        display: none;
    }
    
    .accordion .btn-link .fa-calendar-alt {
        transition: color 0.3s ease;
    }
    
    .accordion .btn-link:not(.collapsed) .fa-calendar-alt {
        color: #4285f4;
    }
    
    /* === Table === */
    .table-hover tbody tr:hover {
        background-color: #f8f9fc !important;
    }
    
    /* === Status Badges === */
    .badge-present {
        background-color: #d4edda;
        color: #155724;
        font-weight: 600;
        padding: 6px 12px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    .badge-late {
        background-color: #fff3cd;
        color: #856404;
        font-weight: 600;
        padding: 6px 12px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    .badge-absent {
        background-color: #f8d7da;
        color: #721c24;
        font-weight: 600;
        padding: 6px 12px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    .badge-other {
        background-color: #e2e3e5;
        color: #383d41;
        font-weight: 600;
        padding: 6px 12px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    .badge-present i,
    .badge-late i,
    .badge-absent i,
    .badge-other i {
        display: inline-block;
        line-height: 1;
        vertical-align: middle;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
    }
    
    .empty-state i {
        font-size: 4rem;
        color: #ccc;
        margin-bottom: 20px;
    }
    
    .empty-state h5 {
        color: #6c757d;
        font-weight: 600;
        margin-bottom: 10px;
    }
    
    /* === Gap Utility === */
    .gap-2 {
        gap: 8px;
    }
</style>

<script>
    let allAttendances = {};

    function loadAttendances() {
        $.ajax({
            url: 'api/my_attendance.php?dni=<?php echo $student_dni; ?>',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'ok') {
                    allAttendances = response;
                    organizeData();
                } else {
                    showError(response.message);
                }
            },
            error: function() {
                showError('Error al cargar las asistencias');
            }
        });
    }

    function organizeData() {
        populateYearFilter(allAttendances.resumen_por_año);
        renderStats();
        if (allAttendances.resumen_por_año && allAttendances.resumen_por_año.length > 0) {
            renderAttendanceTable(allAttendances.resumen_por_año[0]);
        }
    }

    function renderStats() {
        if (!allAttendances.resumen_por_año || allAttendances.resumen_por_año.length === 0) {
            $('#stats-cards').html('<div class="col-12"><div class="alert alert-info">Sin datos de asistencia disponibles</div></div>');
            return;
        }

        const currentYear = allAttendances.resumen_por_año[0];
        
        let html = `
            <div class="col-md-4">
                <div class="stat-card total">
                    <div class="stat-card-icon"><i class="fas fa-book"></i></div>
                    <div class="stat-label">Total de Registros</div>
                    <div class="stat-value">${currentYear.total_registros}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card present">
                    <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-label">Presentes</div>
                    <div class="stat-value">${currentYear.presentes}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card late">
                    <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-label">Tardes</div>
                    <div class="stat-value">${currentYear.tardes}</div>
                </div>
            </div>
        `;
        
        $('#stats-cards').html(html);
    }

    function renderAttendanceTable(yearData) {
        if (!yearData || yearData.total_registros === 0) {
            $('#attendance-container').html(`
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h5>Sin asistencias registradas</h5>
                    <p class="text-muted">No hay datos de asistencia para este período</p>
                </div>
            `);
            return;
        }

        const yearAttendances = allAttendances.data.filter(a => a.anio_academico === yearData.año);
        
        const byMonth = {};
        yearAttendances.forEach(att => {
            const date = new Date(att.fecha);
            const monthKey = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
            if (!byMonth[monthKey]) {
                byMonth[monthKey] = [];
            }
            byMonth[monthKey].push(att);
        });

        let html = `<div class="accordion" id="attendanceAccordion">`;
        
        let monthIndex = 0;
        Object.keys(byMonth).sort().reverse().forEach(monthKey => {
            const attendances = byMonth[monthKey];
            const date = new Date(monthKey + '-01');
            const monthName = date.toLocaleString('es-ES', { month: 'long', year: 'numeric' });
            const cardId = `month-${monthKey}`;
            
            const stats = {
                total: attendances.length,
                presente: attendances.filter(a => a.estado.toLowerCase() === 'presente').length,
                tarde: attendances.filter(a => a.estado.toLowerCase() === 'tarde').length,
                ausente: attendances.filter(a => a.estado.toLowerCase() === 'ausente').length
            };
            
            const presenceRate = Math.round((stats.presente / stats.total) * 100);
            const isOpen = monthIndex === 0 ? 'show' : '';
            
            html += `
                <div class="card mb-3 border-0 shadow-sm">
                    <div class="card-header p-0 bg-light" id="heading${cardId}">
                        <button class="btn btn-link btn-block text-left p-3 ${monthIndex === 0 ? '' : 'collapsed'}" 
                                type="button" data-toggle="collapse" data-target="#${cardId}"
                                aria-expanded="${monthIndex === 0 ? 'true' : 'false'}" aria-controls="${cardId}">
                            <div class="row align-items-center no-gutters">
                                <div class="col">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-calendar-alt mr-3" style="font-size: 1.3rem; color: #4285f4;"></i>
                                        <div>
                                            <h6 class="m-0" style="color: #2c3e50; text-transform: capitalize;">${monthName}</h6>
                                            <small class="text-muted">${stats.total} ${stats.total === 1 ? 'registro' : 'registros'}</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-auto d-flex" style="gap: 8px; align-items: center;">
                                    <span class="badge badge-pill" style="background-color: rgba(66, 133, 244, 0.1); color: #4285f4; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-chart-pie"></i>${presenceRate}%
                                    </span>
                                    <span class="badge-present" style="border: 1px solid #1cc88a;">
                                        <i class="fas fa-check-circle"></i>${stats.presente}
                                    </span>
                                    ${stats.tarde > 0 ? `<span class="badge-late" style="border: 1px solid #f6c23e;">
                                        <i class="fas fa-hourglass-end"></i>${stats.tarde}
                                    </span>` : ''}
                                    ${stats.ausente > 0 ? `<span class="badge-absent" style="border: 1px solid #e74a3b;">
                                        <i class="fas fa-times-circle"></i>${stats.ausente}
                                    </span>` : ''}
                                </div>
                            </div>
                        </button>
                    </div>
                    <div id="${cardId}" class="collapse ${isOpen}" aria-labelledby="heading${cardId}" data-parent="#attendanceAccordion">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm mb-0">
                                    <thead style="background-color: #f8f9fa; border-top: 1px solid #dee2e6;">
                                        <tr>
                                            <th class="pl-4" style="color: #4285f4; font-weight: 700;">Fecha</th>
                                            <th style="color: #4285f4; font-weight: 700;">Hora</th>
                                            <th style="color: #4285f4; font-weight: 700;">Tipo</th>
                                            <th class="pr-4" style="color: #4285f4; font-weight: 700; text-align: center;">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>`;
            
            attendances.forEach(att => {
                const fecha = new Date(att.fecha).toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' });
                // Formatear hora si está disponible
                let horaStr = '--:--';
                if (att.hora) {
                    horaStr = att.hora.substring(0, 5); // Tomar solo HH:MM
                }
                
                let badgeClass = 'badge-other';
                let icon = 'fa-circle';
                
                if (att.estado.toLowerCase() === 'presente') {
                    badgeClass = 'badge-present';
                    icon = 'fa-check-circle';
                } else if (att.estado.toLowerCase() === 'tarde') {
                    badgeClass = 'badge-late';
                    icon = 'fa-hourglass-end';
                } else if (att.estado.toLowerCase() === 'ausente') {
                    badgeClass = 'badge-absent';
                    icon = 'fa-times-circle';
                }
                
                html += `
                    <tr>
                        <td class="pl-4">
                            <i class="far fa-calendar-alt mr-2" style="color: #adb5bd;"></i>
                            <strong>${fecha}</strong>
                        </td>
                        <td>
                            <i class="far fa-clock mr-1" style="color: #adb5bd;"></i>
                            <span class="font-weight-500">${horaStr}</span>
                        </td>
                        <td><small class="text-muted">${att.tipo}</small></td>
                        <td class="pr-4" style="text-align: center;">
                            <span class="${badgeClass}">
                                <i class="fas ${icon}"></i>${att.estado}
                            </span>
                        </td>
                    </tr>`;
            });
            
            html += `
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>`;
            
            monthIndex++;
        });
        
        html += `</div>`;
        $('#attendance-container').html(html);
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

    $('#year-filter').change(function() {
        let selectedYear = $(this).val();
        if (!selectedYear) return;
        const yearData = (allAttendances.resumen_por_año || []).find(y => y.año === selectedYear);
        if (yearData) {
            renderAttendanceTable(yearData);
        }
    });

    function showError(message) {
        $('#attendance-container').html(`
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <strong>Error:</strong> ${message}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        `);
        $('#stats-cards').empty();
    }

    $(document).ready(function() {
        loadAttendances();
    });
</script>
