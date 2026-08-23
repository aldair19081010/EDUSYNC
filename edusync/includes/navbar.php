<?php
// Verificar que hay una sesión activa (admin o estudiante)
// No enviar headers aquí - index.php ya comenzó output
if (!isset($_SESSION['login_type']) && !isset($_SESSION['student_logged_in'])) {
    // Esto no debería ocurrir si index.php está validando correctamente
    // pero lo dejamos por seguridad
    return; // Solo retornar, no redirigir
}

$login_type = isset($_SESSION['login_type']) ? $_SESSION['login_type'] : 'student';
$is_student = isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'];
$current_page = isset($_GET['page']) ? $_GET['page'] : 'home';
?>

<script>
// Minimal loader/toast fallbacks that do NOT require jQuery and are available early
if (typeof start_load !== 'function') {
  function start_load(){
    try {
      if (document.getElementById('page-loader')) return;
      var el = document.createElement('div');
      el.id = 'page-loader';
      el.setAttribute('role','status');
      el.style.position = 'fixed';
      el.style.inset = '0';
      el.style.background = 'rgba(0,0,0,0.35)';
      el.style.zIndex = 2000000;
      el.style.display = 'flex';
      el.style.alignItems = 'center';
      el.style.justifyContent = 'center';
      var inner = document.createElement('div');
      inner.style.background = '#fff';
      inner.style.padding = '12px 14px';
      inner.style.borderRadius = '8px';
      inner.style.boxShadow = '0 6px 20px rgba(0,0,0,0.12)';
      inner.innerText = 'Cargando...';
      el.appendChild(inner);
      document.body.appendChild(el);
    } catch (e) { /* silent */ }
  }
}
if (typeof end_load !== 'function') {
  function end_load(){
    try { var el = document.getElementById('page-loader'); if (el && el.parentNode) el.parentNode.removeChild(el); } catch(e){}
  }
}
if (typeof alert_toast !== 'function') {
  function alert_toast(message, type){
    try {
      var d = document.createElement('div');
      d.className = 'toast-alert-fixed alert alert-' + (type || 'info');
      d.style.position = 'fixed'; d.style.top = '20px'; d.style.right = '20px'; d.style.zIndex = 2000001; d.style.minWidth = '220px';
      d.innerText = message;
      document.body.appendChild(d);
      setTimeout(function(){ try { d.remove(); } catch(e){} }, 3500);
    } catch (e) { console.log(type, message); }
  }
}
</script>

<!-- Sidebar -->
<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php?page=home">
        <div class="sidebar-brand-icon rotate-n-15">
            <i class="fas fa-graduation-cap"></i>
        </div>
        <div class="sidebar-brand-text mx-3">EduSync</div>
    </a>

    <!-- Divider -->
    <hr class="sidebar-divider my-0">

    <!-- Nav Item - Dashboard -->
    <li class="nav-item <?php echo ($current_page == 'home') ? 'active' : ''; ?>">
        <a class="nav-link" href="index.php?page=home">
            <i class="fas fa-fw fa-home"></i>
            <span>Inicio</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <?php if ($login_type == 1): // ADMINISTRADOR ?>
        
        <!-- Heading -->
        <div class="sidebar-heading">Estudiantes</div>

        <!-- Nav Item - Estudiantes -->
        <li class="nav-item <?php echo ($current_page == 'students') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=students">
                <i class="fas fa-fw fa-users"></i>
                <span>Lista de Estudiantes</span>
            </a>
        </li>

        <li class="nav-item <?php echo ($current_page == 'bulk_student_update') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=bulk_student_update">
                <i class="fas fa-fw fa-users-cog"></i>
                <span>Actualización Masiva</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Docentes</div>

        <li class="nav-item <?php echo ($current_page == 'teachers') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=teachers">
                <i class="fas fa-fw fa-chalkboard-teacher"></i>
                <span>Lista de Docentes</span>
            </a>
        </li>

        <li class="nav-item <?php echo ($current_page == 'teacher_courses') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=teacher_courses">
                <i class="fas fa-fw fa-user-graduate"></i>
                <span>Asignar a Cursos</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Cursos y Conceptos</div>

        <li class="nav-item <?php echo ($current_page == 'academic_management') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=academic_management">
                <i class="fas fa-fw fa-graduation-cap"></i>
                <span>Gestión Académica</span>
            </a>
        </li>

        <li class="nav-item <?php echo ($current_page == 'academic_year') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=academic_year">
                <i class="fas fa-fw fa-calendar-alt"></i>
                <span>Años Académicos</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Competencias</div>

        <li class="nav-item <?php echo ($current_page == 'competencias') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=competencias">
                <i class="fas fa-fw fa-tasks"></i>
                <span>Competencias por Nivel</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Pagos</div>

        <!-- Nav Item - Pagos Collapse Menu -->
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapsePagos"
                aria-expanded="true" aria-controls="collapsePagos">
                <i class="fas fa-fw fa-dollar-sign"></i>
                <span>Gestión de Pagos</span>
            </a>
            <div id="collapsePagos" class="collapse <?php echo in_array($current_page, ['concepts', 'fees', 'payments', 'discounts']) ? 'show' : ''; ?>" 
                aria-labelledby="headingPagos" data-parent="#accordionSidebar">
                <div class="bg-white py-2 collapse-inner rounded">
                    <h6 class="collapse-header">Opciones de Pagos:</h6>
                    <a class="collapse-item <?php echo ($current_page == 'concepts') ? 'active' : ''; ?>" href="index.php?page=concepts">Conceptos de Pagos</a>
                    <a class="collapse-item <?php echo ($current_page == 'fees') ? 'active' : ''; ?>" href="index.php?page=fees">Asignar Deudas</a>
                    <a class="collapse-item <?php echo ($current_page == 'payments') ? 'active' : ''; ?>" href="index.php?page=payments">Pagos</a>
                    <a class="collapse-item <?php echo ($current_page == 'discounts') ? 'active' : ''; ?>" href="index.php?page=discounts">Descuentos/Becas</a>
                </div>
            </div>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Facturación Electrónica</div>

        <!-- Nav Item - Facturación Collapse Menu -->
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseFacturacion"
                aria-expanded="true" aria-controls="collapseFacturacion">
                <i class="fas fa-fw fa-file-invoice"></i>
                <span>Facturación SUNAT</span>
            </a>
            <div id="collapseFacturacion" class="collapse <?php echo in_array($current_page, ['comprobantes', 'config_facturacion', 'facturacion_deudas']) ? 'show' : ''; ?>" 
                aria-labelledby="headingFacturacion" data-parent="#accordionSidebar">
                <div class="bg-white py-2 collapse-inner rounded">
                    <h6 class="collapse-header">Opciones:</h6>
                    <a class="collapse-item <?php echo ($current_page == 'comprobantes') ? 'active' : ''; ?>" href="index.php?page=comprobantes">
                        <i class="fas fa-receipt"></i> Comprobantes
                    </a>
                    <a class="collapse-item <?php echo ($current_page == 'facturacion_deudas') ? 'active' : ''; ?>" href="index.php?page=facturacion_deudas">
                        <i class="fas fa-file-invoice-dollar"></i> Facturación de Deudas
                    </a>
                    <a class="collapse-item <?php echo ($current_page == 'config_facturacion') ? 'active' : ''; ?>" href="index.php?page=config_facturacion">
                        <i class="fas fa-cog"></i> Configuración
                    </a>
                </div>
            </div>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Asistencia</div>

        <li class="nav-item <?php echo ($current_page == 'asistencia') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=asistencia">
                <i class="fas fa-fw fa-calendar-check"></i>
                <span>Asistencia</span>
            </a>
        </li>

        <li class="nav-item <?php echo ($current_page == 'attendance_rules_page') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=attendance_rules_page">
                <i class="fas fa-fw fa-cog"></i>
                <span>Reglas de Asistencia</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Notas</div>

        <li class="nav-item <?php echo ($current_page == 'grades') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=grades">
                <i class="fas fa-fw fa-clipboard-list"></i>
                <span>Notas</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Reportes</div>

        <!-- Nav Item - Reportes Collapse Menu -->
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseReportes"
                aria-expanded="true" aria-controls="collapseReportes">
                <i class="fas fa-fw fa-chart-area"></i>
                <span>Reportes</span>
            </a>
            <div id="collapseReportes" class="collapse <?php echo in_array($current_page, ['payments_report', 'grades_report', 'attendance_report', 'debt_reports', 'fichas_reportes']) ? 'show' : ''; ?>" 
                aria-labelledby="headingReportes" data-parent="#accordionSidebar">
                <div class="bg-white py-2 collapse-inner rounded">
                    <h6 class="collapse-header">Tipos de Reportes:</h6>
                    <a class="collapse-item <?php echo ($current_page == 'payments_report') ? 'active' : ''; ?>" href="index.php?page=payments_report">Reporte de Pagos</a>
                    <a class="collapse-item <?php echo ($current_page == 'grades_report') ? 'active' : ''; ?>" href="index.php?page=grades_report">Reporte de Notas</a>
                    <a class="collapse-item <?php echo ($current_page == 'attendance_report') ? 'active' : ''; ?>" href="index.php?page=attendance_report">Reporte de Asistencia</a>
                    <a class="collapse-item <?php echo ($current_page == 'debt_reports') ? 'active' : ''; ?>" href="index.php?page=debt_reports">Reporte de Deudas</a>
                    <a class="collapse-item <?php echo ($current_page == 'fichas_reportes') ? 'active' : ''; ?>" href="index.php?page=fichas_reportes">Fichas y Reportes</a>
                </div>
            </div>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Sistema</div>

        <li class="nav-item <?php echo ($current_page == 'users') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=users">
                <i class="fas fa-fw fa-users-cog"></i>
                <span>Usuarios</span>
            </a>
        </li>

    <?php elseif ($login_type == 2): // PROFESOR ?>
        
        <!-- Heading -->
        <div class="sidebar-heading">Académico</div>

        <li class="nav-item <?php echo ($current_page == 'my_courses') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=my_courses">
                <i class="fas fa-fw fa-book"></i>
                <span>Mis Cursos</span>
            </a>
        </li>

        <li class="nav-item <?php echo ($current_page == 'grades') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=grades">
                <i class="fas fa-fw fa-clipboard-list"></i>
                <span>Notas</span>
            </a>
        </li>

        <li class="nav-item <?php echo ($current_page == 'grades_report') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=grades_report">
                <i class="fas fa-fw fa-chart-bar"></i>
                <span>Reporte de Notas</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Competencias</div>

        <li class="nav-item <?php echo ($current_page == 'competencias') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=competencias">
                <i class="fas fa-fw fa-tasks"></i>
                <span>Competencias por Nivel</span>
            </a>
        </li>

    <?php elseif ($login_type == 3): // AUXILIAR ?>
        
        <!-- Heading -->
        <div class="sidebar-heading">Asistencia</div>

        <li class="nav-item <?php echo ($current_page == 'asistencia') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=asistencia">
                <i class="fas fa-fw fa-calendar-check"></i>
                <span>Asistencia</span>
            </a>
        </li>

        <li class="nav-item <?php echo ($current_page == 'attendance_rules_page') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=attendance_rules_page">
                <i class="fas fa-fw fa-cog"></i>
                <span>Reglas de Asistencia</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Reportes</div>

        <li class="nav-item <?php echo ($current_page == 'attendance_report') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=attendance_report">
                <i class="fas fa-fw fa-calendar-alt"></i>
                <span>Reporte de Asistencia</span>
            </a>
        </li>

    <?php elseif ($login_type == 4 || $is_student): // ESTUDIANTE ?>
        
        <!-- Heading -->
        <div class="sidebar-heading">Académico</div>

        <li class="nav-item <?php echo ($current_page == 'student_grades') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=student_grades">
                <i class="fas fa-fw fa-graduation-cap"></i>
                <span>Mis Notas</span>
            </a>
        </li>

        <li class="nav-item <?php echo ($current_page == 'student_attendances') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=student_attendances">
                <i class="fas fa-fw fa-clipboard-list"></i>
                <span>Mis Asistencias</span>
            </a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">Pagos</div>

        <li class="nav-item <?php echo ($current_page == 'student_payments') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=student_payments">
                <i class="fas fa-fw fa-credit-card"></i>
                <span>Mis Pagos</span>
            </a>
        </li>

        <li class="nav-item <?php echo ($current_page == 'student_debts') ? 'active' : ''; ?>">
            <a class="nav-link" href="index.php?page=student_debts">
                <i class="fas fa-fw fa-exclamation-triangle"></i>
                <span>Mis Deudas</span>
            </a>
        </li>

    <?php endif; ?>

    <!-- Divider -->
    <hr class="sidebar-divider d-none d-md-block">

    <!-- Sidebar Toggler (Sidebar) -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>

</ul>
<!-- End of Sidebar -->
