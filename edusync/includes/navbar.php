<?php
// Sidebar EduSync. No enviar headers aquí: index.php ya comenzó la salida.
if (!isset($_SESSION['login_type']) && !isset($_SESSION['student_logged_in'])) {
    return;
}

$login_type = isset($_SESSION['login_type']) ? (int)$_SESSION['login_type'] : 4;
$is_student = !empty($_SESSION['student_logged_in']);
$current_page = isset($_GET['page']) ? $_GET['page'] : 'home';
$is_director = ($login_type === 1 && !empty($_SESSION['login_is_director']));

$role_label = 'Usuario';
if ($is_student || $login_type === 4) {
    $role_label = 'Estudiante';
} elseif ($login_type === 1) {
    $role_label = $is_director ? 'Director' : 'Administrador';
} elseif ($login_type === 2) {
    $role_label = 'Docente';
} elseif ($login_type === 3) {
    $role_label = 'Auxiliar';
}

$groups = [];

if ($login_type === 1) {
    $groups = [
        [
            'id' => 'collapseAcademico',
            'label' => 'Académico',
            'icon' => 'fa-graduation-cap',
            'items' => [
                ['page' => 'students', 'label' => 'Estudiantes', 'icon' => 'fa-users'],
                ['page' => 'bulk_student_update', 'label' => 'Actualización masiva', 'icon' => 'fa-users-cog'],
                ['page' => 'teachers', 'label' => 'Docentes', 'icon' => 'fa-chalkboard-teacher'],
                ['page' => 'teacher_courses', 'label' => 'Asignación de cursos', 'icon' => 'fa-user-graduate'],
                ['page' => 'academic_management', 'label' => 'Gestión académica', 'icon' => 'fa-book-open'],
                ['page' => 'competencias', 'label' => 'Competencias', 'icon' => 'fa-tasks'],
                ['page' => 'academic_year', 'label' => 'Años académicos', 'icon' => 'fa-calendar-alt'],
            ],
        ],
        [
            'id' => 'collapseEvaluacion',
            'label' => 'Evaluación',
            'icon' => 'fa-clipboard-check',
            'items' => [
                ['page' => 'grades', 'label' => 'Libro de notas', 'icon' => 'fa-clipboard-list'],
                ['page' => 'grades_report', 'label' => 'Reporte de notas', 'icon' => 'fa-chart-bar'],
            ],
        ],
        [
            'id' => 'collapseAsistencia',
            'label' => 'Asistencia',
            'icon' => 'fa-calendar-check',
            'items' => [
                ['page' => 'asistencia', 'label' => 'Registrar asistencia', 'icon' => 'fa-user-check'],
                ['page' => 'attendance_rules_page', 'label' => 'Reglas de asistencia', 'icon' => 'fa-cog'],
                ['page' => 'attendance_report', 'label' => 'Reporte de asistencia', 'icon' => 'fa-chart-line'],
            ],
        ],
        [
            'id' => 'collapseFinanzas',
            'label' => 'Finanzas',
            'icon' => 'fa-wallet',
            'items' => [
                ['page' => 'payments', 'label' => 'Registrar pagos', 'icon' => 'fa-cash-register'],
                ['page' => 'concepts', 'label' => 'Conceptos de pago', 'icon' => 'fa-list-alt'],
                ['page' => 'fees', 'label' => 'Asignar deudas', 'icon' => 'fa-file-invoice-dollar'],
                ['page' => 'discounts', 'label' => 'Descuentos / Becas', 'icon' => 'fa-percentage'],
                ['page' => 'payments_report', 'label' => 'Reporte de pagos', 'icon' => 'fa-chart-pie'],
                ['page' => 'debt_reports', 'label' => 'Reporte de deudas', 'icon' => 'fa-exclamation-circle'],
            ],
        ],
        [
            'id' => 'collapseFacturacion',
            'label' => 'Facturación',
            'icon' => 'fa-file-invoice',
            'items' => [
                ['page' => 'comprobantes', 'label' => 'Comprobantes', 'icon' => 'fa-receipt'],
                ['page' => 'facturacion_deudas', 'label' => 'Facturación de deudas', 'icon' => 'fa-file-invoice-dollar'],
                ['page' => 'config_facturacion', 'label' => 'Configuración SUNAT', 'icon' => 'fa-cog'],
            ],
        ],
        [
            'id' => 'collapseReportesGenerales',
            'label' => 'Reportes',
            'icon' => 'fa-chart-area',
            'items' => [
                ['page' => 'fichas_reportes', 'label' => 'Fichas y reportes', 'icon' => 'fa-file-alt'],
            ],
        ],
        [
            'id' => 'collapseSistema',
            'label' => 'Sistema',
            'icon' => 'fa-cogs',
            'items' => [
                ['page' => 'users', 'label' => 'Usuarios', 'icon' => 'fa-users-cog'],
            ],
        ],
    ];
} elseif ($login_type === 2) {
    $groups = [
        [
            'id' => 'collapseDocenteAcademico',
            'label' => 'Académico',
            'icon' => 'fa-book',
            'items' => [
                ['page' => 'my_courses', 'label' => 'Mis cursos', 'icon' => 'fa-book-open'],
                ['page' => 'competencias', 'label' => 'Competencias', 'icon' => 'fa-tasks'],
            ],
        ],
        [
            'id' => 'collapseDocenteEvaluacion',
            'label' => 'Evaluación',
            'icon' => 'fa-clipboard-check',
            'items' => [
                ['page' => 'grades', 'label' => 'Libro de notas', 'icon' => 'fa-clipboard-list'],
                ['page' => 'grades_report', 'label' => 'Reporte de notas', 'icon' => 'fa-chart-bar'],
            ],
        ],
    ];
} elseif ($login_type === 3) {
    $groups = [
        [
            'id' => 'collapseAuxiliarAsistencia',
            'label' => 'Asistencia',
            'icon' => 'fa-calendar-check',
            'items' => [
                ['page' => 'asistencia', 'label' => 'Registrar asistencia', 'icon' => 'fa-user-check'],
                ['page' => 'attendance_rules_page', 'label' => 'Reglas de asistencia', 'icon' => 'fa-cog'],
                ['page' => 'attendance_report', 'label' => 'Reporte de asistencia', 'icon' => 'fa-chart-line'],
            ],
        ],
    ];
} elseif ($login_type === 4 || $is_student) {
    $groups = [
        [
            'id' => 'collapseEstudianteAcademico',
            'label' => 'Académico',
            'icon' => 'fa-graduation-cap',
            'items' => [
                ['page' => 'student_grades', 'label' => 'Mis notas', 'icon' => 'fa-clipboard-list'],
                ['page' => 'student_attendances', 'label' => 'Mis asistencias', 'icon' => 'fa-calendar-check'],
            ],
        ],
        [
            'id' => 'collapseEstudianteFinanzas',
            'label' => 'Pagos',
            'icon' => 'fa-wallet',
            'items' => [
                ['page' => 'student_payments', 'label' => 'Mis pagos', 'icon' => 'fa-credit-card'],
                ['page' => 'student_debts', 'label' => 'Mis deudas', 'icon' => 'fa-exclamation-triangle'],
            ],
        ],
    ];
}

$render_group = function ($group) use ($current_page) {
    $pages = array_column($group['items'], 'page');
    $is_open = in_array($current_page, $pages, true);
    $id = htmlspecialchars($group['id'], ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars($group['icon'], ENT_QUOTES, 'UTF-8');
    ?>
    <li class="nav-item sidebar-group <?php echo $is_open ? 'active' : ''; ?>">
        <a class="nav-link sidebar-group-toggle <?php echo $is_open ? '' : 'collapsed'; ?>"
           href="#"
           data-toggle="collapse"
           data-target="#<?php echo $id; ?>"
           aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>"
           aria-controls="<?php echo $id; ?>">
            <i class="fas fa-fw <?php echo $icon; ?>"></i>
            <span><?php echo $label; ?></span>
        </a>
        <div id="<?php echo $id; ?>" class="collapse <?php echo $is_open ? 'show' : ''; ?>">
            <div class="bg-white py-2 collapse-inner rounded sidebar-group-inner">
                <?php foreach ($group['items'] as $item):
                    $item_active = ($current_page === $item['page']);
                    $item_page = htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8');
                    $item_label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
                    $item_icon = htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8');
                ?>
                    <a class="collapse-item sidebar-subitem <?php echo $item_active ? 'active' : ''; ?>"
                       href="index.php?page=<?php echo $item_page; ?>">
                        <i class="fas fa-fw <?php echo $item_icon; ?> sidebar-subitem-icon"></i>
                        <span><?php echo $item_label; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </li>
    <?php
};
?>

<script>
// Fallbacks disponibles incluso si una página incluida termina antes de cargar
// los scripts ubicados al final de index.php.
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
    } catch (e) {}
  }
}
if (typeof end_load !== 'function') {
  function end_load(){
    try {
      var el = document.getElementById('page-loader');
      if (el && el.parentNode) el.parentNode.removeChild(el);
    } catch(e) {}
  }
}
if (typeof alert_toast !== 'function') {
  function alert_toast(message, type){
    try {
      var d = document.createElement('div');
      d.className = 'toast-alert-fixed alert alert-' + (type || 'info');
      d.style.position = 'fixed';
      d.style.top = '20px';
      d.style.right = '20px';
      d.style.zIndex = 2000001;
      d.style.minWidth = '220px';
      d.innerText = message;
      document.body.appendChild(d);
      setTimeout(function(){ try { d.remove(); } catch(e){} }, 3500);
    } catch (e) {
      console.log(type, message);
    }
  }
}
</script>

<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion edusync-sidebar" id="accordionSidebar">
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php?page=home">
        <div class="sidebar-brand-icon rotate-n-15">
            <i class="fas fa-graduation-cap"></i>
        </div>
        <div class="sidebar-brand-text mx-3 text-left">
            <span class="d-block">EduSync</span>
            <small class="sidebar-brand-role"><?php echo htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
    </a>

    <hr class="sidebar-divider my-0">

    <li class="nav-item <?php echo ($current_page === 'home') ? 'active' : ''; ?>">
        <a class="nav-link" href="index.php?page=home">
            <i class="fas fa-fw fa-home"></i>
            <span>Inicio</span>
        </a>
    </li>

    <hr class="sidebar-divider">
    <div class="sidebar-heading">Navegación</div>

    <?php foreach ($groups as $group) {
        $render_group($group);
    } ?>

    <hr class="sidebar-divider d-none d-md-block">

    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>
</ul>

<script>
(function () {
    var sidebar = document.getElementById('accordionSidebar');
    if (!sidebar) return;

    function syncToggle(toggle, open) {
        if (!toggle) return;
        toggle.classList.toggle('collapsed', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeGroup(panel) {
        if (!panel) return;
        panel.classList.remove('show', 'collapsing');
        panel.style.height = '';
        var toggle = sidebar.querySelector('.sidebar-group-toggle[data-target="#' + panel.id + '"]');
        syncToggle(toggle, false);
    }

    function openGroup(panel) {
        if (!panel) return;
        panel.classList.remove('collapsing');
        panel.style.height = '';
        panel.classList.add('show');
        var toggle = sidebar.querySelector('.sidebar-group-toggle[data-target="#' + panel.id + '"]');
        syncToggle(toggle, true);
    }

    // Se maneja en JavaScript nativo para que el sidebar siga funcionando aunque
    // una página incluida haga exit/return antes de cargar bootstrap.bundle.js.
    sidebar.querySelectorAll('.sidebar-group-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var selector = toggle.getAttribute('data-target');
            if (!selector) return;
            var target = document.querySelector(selector);
            if (!target) return;

            var shouldOpen = !target.classList.contains('show');

            sidebar.querySelectorAll('.sidebar-group > .collapse.show').forEach(function (panel) {
                if (panel !== target) closeGroup(panel);
            });

            if (shouldOpen) openGroup(target);
            else closeGroup(target);
        });
    });
})();
</script>
