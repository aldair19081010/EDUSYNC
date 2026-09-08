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

$sections = [];

if ($login_type === 1) {
    $sections = [
        [
            'heading' => 'Estudiantes',
            'items' => [
                ['page' => 'students', 'label' => 'Lista de Estudiantes', 'icon' => 'fa-users'],
                ['page' => 'bulk_student_update', 'label' => 'Actualización Masiva', 'icon' => 'fa-users-cog'],
            ],
        ],
        [
            'heading' => 'Docentes',
            'items' => [
                ['page' => 'teachers', 'label' => 'Lista de Docentes', 'icon' => 'fa-chalkboard-teacher'],
                ['page' => 'teacher_courses', 'label' => 'Asignar a Cursos', 'icon' => 'fa-user-graduate'],
            ],
        ],
        [
            'heading' => 'Cursos y Conceptos',
            'items' => [
                ['page' => 'academic_management', 'label' => 'Gestión Académica', 'icon' => 'fa-graduation-cap'],
                ['page' => 'academic_year', 'label' => 'Años Académicos', 'icon' => 'fa-calendar-alt'],
            ],
        ],
        [
            'heading' => 'Competencias',
            'items' => [
                ['page' => 'competencias', 'label' => 'Competencias por Nivel', 'icon' => 'fa-tasks'],
            ],
        ],
        [
            'heading' => 'Pagos',
            'collapse' => [
                'id' => 'collapsePagos',
                'label' => 'Gestión de Pagos',
                'icon' => 'fa-dollar-sign',
                'header' => 'Opciones de Pagos',
                'items' => [
                    ['page' => 'concepts', 'label' => 'Conceptos de Pagos', 'icon' => 'fa-list-alt'],
                    ['page' => 'fees', 'label' => 'Asignar Deudas', 'icon' => 'fa-file-invoice-dollar'],
                    ['page' => 'payments', 'label' => 'Registrar Pagos', 'icon' => 'fa-cash-register'],
                    ['page' => 'discounts', 'label' => 'Descuentos / Becas', 'icon' => 'fa-percentage'],
                    ['page' => 'payments_report', 'label' => 'Reporte de Pagos', 'icon' => 'fa-chart-pie', 'separator_before' => true],
                    ['page' => 'debt_reports', 'label' => 'Reporte de Deudas', 'icon' => 'fa-exclamation-circle'],
                ],
            ],
        ],
        [
            'heading' => 'Facturación Electrónica',
            'collapse' => [
                'id' => 'collapseFacturacion',
                'label' => 'Facturación SUNAT',
                'icon' => 'fa-file-invoice',
                'header' => 'Opciones',
                'items' => [
                    ['page' => 'comprobantes', 'label' => 'Comprobantes', 'icon' => 'fa-receipt'],
                    ['page' => 'facturacion_deudas', 'label' => 'Facturación de Deudas', 'icon' => 'fa-file-invoice-dollar'],
                    ['page' => 'config_facturacion', 'label' => 'Configuración', 'icon' => 'fa-cog'],
                ],
            ],
        ],
        [
            'heading' => 'Asistencia',
            'items' => [
                ['page' => 'asistencia', 'label' => 'Asistencia', 'icon' => 'fa-calendar-check'],
                ['page' => 'attendance_rules_page', 'label' => 'Reglas de Asistencia', 'icon' => 'fa-cog'],
                ['page' => 'attendance_report', 'label' => 'Reporte de Asistencia', 'icon' => 'fa-chart-line'],
            ],
        ],
        [
            'heading' => 'Notas',
            'items' => [
                ['page' => 'grades', 'label' => 'Libro de Notas', 'icon' => 'fa-clipboard-list'],
                ['page' => 'grades_report', 'label' => 'Reporte de Notas', 'icon' => 'fa-chart-bar'],
            ],
        ],
        [
            'heading' => 'Fichas y Reportes',
            'items' => [
                ['page' => 'fichas_reportes', 'label' => 'Fichas y Reportes', 'icon' => 'fa-file-alt'],
            ],
        ],
        [
            'heading' => 'Sistema',
            'items' => [
                ['page' => 'users', 'label' => 'Usuarios', 'icon' => 'fa-users-cog'],
            ],
        ],
    ];
} elseif ($login_type === 2) {
    $sections = [
        [
            'heading' => 'Académico',
            'items' => [
                ['page' => 'my_courses', 'label' => 'Mis Cursos', 'icon' => 'fa-book'],
                ['page' => 'competencias', 'label' => 'Competencias por Nivel', 'icon' => 'fa-tasks'],
            ],
        ],
        [
            'heading' => 'Notas',
            'items' => [
                ['page' => 'grades', 'label' => 'Libro de Notas', 'icon' => 'fa-clipboard-list'],
                ['page' => 'grades_report', 'label' => 'Reporte de Notas', 'icon' => 'fa-chart-bar'],
            ],
        ],
    ];
} elseif ($login_type === 3) {
    $sections = [
        [
            'heading' => 'Asistencia',
            'items' => [
                ['page' => 'asistencia', 'label' => 'Asistencia', 'icon' => 'fa-calendar-check'],
                ['page' => 'attendance_rules_page', 'label' => 'Reglas de Asistencia', 'icon' => 'fa-cog'],
                ['page' => 'attendance_report', 'label' => 'Reporte de Asistencia', 'icon' => 'fa-chart-line'],
            ],
        ],
    ];
} elseif ($login_type === 4 || $is_student) {
    $sections = [
        [
            'heading' => 'Académico',
            'items' => [
                ['page' => 'student_grades', 'label' => 'Mis Notas', 'icon' => 'fa-graduation-cap'],
                ['page' => 'student_attendances', 'label' => 'Mis Asistencias', 'icon' => 'fa-clipboard-list'],
            ],
        ],
        [
            'heading' => 'Pagos',
            'items' => [
                ['page' => 'student_payments', 'label' => 'Mis Pagos', 'icon' => 'fa-credit-card'],
                ['page' => 'student_debts', 'label' => 'Mis Deudas', 'icon' => 'fa-exclamation-triangle'],
            ],
        ],
    ];
}

$render_direct_item = function ($item) use ($current_page) {
    $active = ($current_page === $item['page']);
    $page = htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8');
    ?>
    <li class="nav-item <?php echo $active ? 'active' : ''; ?>">
        <a class="nav-link" href="index.php?page=<?php echo $page; ?>">
            <i class="fas fa-fw <?php echo $icon; ?>"></i>
            <span><?php echo $label; ?></span>
        </a>
    </li>
    <?php
};

$render_collapse = function ($group) use ($current_page) {
    $pages = array_column($group['items'], 'page');
    $is_open = in_array($current_page, $pages, true);
    $id = htmlspecialchars($group['id'], ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars($group['icon'], ENT_QUOTES, 'UTF-8');
    $header = htmlspecialchars($group['header'] ?? '', ENT_QUOTES, 'UTF-8');
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
                <?php if ($header !== ''): ?>
                    <h6 class="collapse-header"><?php echo $header; ?>:</h6>
                <?php endif; ?>
                <?php foreach ($group['items'] as $item):
                    $item_active = ($current_page === $item['page']);
                    $item_page = htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8');
                    $item_label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
                    $item_icon = htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8');
                ?>
                    <?php if (!empty($item['separator_before'])): ?>
                        <div class="dropdown-divider my-1"></div>
                    <?php endif; ?>
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

    <?php foreach ($sections as $index => $section): ?>
        <hr class="sidebar-divider<?php echo $index === 0 ? '' : ' my-0'; ?>">
        <div class="sidebar-heading"><?php echo htmlspecialchars($section['heading'], ENT_QUOTES, 'UTF-8'); ?></div>

        <?php if (!empty($section['collapse'])): ?>
            <?php $render_collapse($section['collapse']); ?>
        <?php else: ?>
            <?php foreach ($section['items'] as $item) {
                $render_direct_item($item);
            } ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <hr class="sidebar-divider d-none d-md-block">

    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>
</ul>

<script>
(function () {
    var sidebar = document.getElementById('accordionSidebar');
    if (!sidebar) return;

    var SIDEBAR_STATE_KEY = 'edusync_sidebar_collapsed';
    var SIDEBAR_SCROLL_KEY = 'edusync_sidebar_scroll_top_<?php echo (int)$login_type; ?>';
    var scrollSaveScheduled = false;
    var flyoutPositionScheduled = false;

    function isDesktop() {
        return window.matchMedia ? window.matchMedia('(min-width: 768px)').matches : window.innerWidth >= 768;
    }

    function syncToggle(toggle, open) {
        if (!toggle) return;
        toggle.classList.toggle('collapsed', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function getPanelToggle(panel) {
        if (!panel || !panel.id) return null;
        return sidebar.querySelector('.sidebar-group-toggle[data-target="#' + panel.id + '"]');
    }

    function clearFlyoutPosition(panel) {
        if (!panel) return;
        panel.style.removeProperty('left');
        panel.style.removeProperty('top');
    }

    function closeGroup(panel) {
        if (!panel) return;
        panel.classList.remove('show', 'collapsing');
        panel.style.height = '';
        clearFlyoutPosition(panel);
        syncToggle(getPanelToggle(panel), false);
    }

    function closeAllGroups() {
        sidebar.querySelectorAll('.sidebar-group > .collapse.show').forEach(function (panel) {
            closeGroup(panel);
        });
    }

    function positionCollapsedFlyout(toggle, panel) {
        if (!toggle || !panel) return;
        if (!isDesktop() || !sidebar.classList.contains('toggled') || !panel.classList.contains('show')) {
            clearFlyoutPosition(panel);
            return;
        }

        var sidebarRect = sidebar.getBoundingClientRect();
        var toggleRect = toggle.getBoundingClientRect();
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var panelHeight = panel.getBoundingClientRect().height || panel.offsetHeight || 0;
        var desiredTop = toggleRect.top;
        var maxTop = Math.max(8, viewportHeight - panelHeight - 8);
        var top = Math.max(8, Math.min(desiredTop, maxTop));

        panel.style.left = Math.round(sidebarRect.right + 6) + 'px';
        panel.style.top = Math.round(top) + 'px';
    }

    function positionOpenFlyouts() {
        flyoutPositionScheduled = false;
        if (!isDesktop() || !sidebar.classList.contains('toggled')) return;
        sidebar.querySelectorAll('.sidebar-group > .collapse.show').forEach(function (panel) {
            positionCollapsedFlyout(getPanelToggle(panel), panel);
        });
    }

    function scheduleFlyoutPosition() {
        if (flyoutPositionScheduled) return;
        flyoutPositionScheduled = true;
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(positionOpenFlyouts);
        } else {
            setTimeout(positionOpenFlyouts, 16);
        }
    }

    function openGroup(panel) {
        if (!panel) return;
        panel.classList.remove('collapsing');
        panel.style.height = '';
        panel.classList.add('show');
        var toggle = getPanelToggle(panel);
        syncToggle(toggle, true);
        if (isDesktop() && sidebar.classList.contains('toggled')) {
            positionCollapsedFlyout(toggle, panel);
        } else {
            clearFlyoutPosition(panel);
        }
    }

    function saveSidebarScroll() {
        if (!isDesktop()) return;
        try {
            sessionStorage.setItem(SIDEBAR_SCROLL_KEY, String(sidebar.scrollTop || 0));
        } catch (e) {}
    }

    function keepActiveItemVisible() {
        if (!isDesktop()) return;
        var activeItem = sidebar.querySelector('.nav-item.active > .nav-link');
        if (!activeItem) return;

        var sidebarRect = sidebar.getBoundingClientRect();
        var activeRect = activeItem.getBoundingClientRect();
        var activeInsideView = activeRect.top >= sidebarRect.top && activeRect.bottom <= sidebarRect.bottom;
        if (activeInsideView) return;

        var targetTop = sidebar.scrollTop + (activeRect.top - sidebarRect.top) - ((sidebar.clientHeight - activeRect.height) / 2);
        sidebar.scrollTop = Math.max(0, targetTop);
        saveSidebarScroll();
    }

    function restoreSidebarScroll() {
        if (!isDesktop()) return;
        var savedPosition = null;
        try {
            savedPosition = sessionStorage.getItem(SIDEBAR_SCROLL_KEY);
        } catch (e) {}

        if (savedPosition !== null && savedPosition !== '' && !isNaN(Number(savedPosition))) {
            sidebar.scrollTop = Math.max(0, Number(savedPosition));
            scheduleFlyoutPosition();
            return;
        }

        keepActiveItemVisible();
    }

    function setSidebarCollapsed(collapsed, savePreference) {
        if (!isDesktop()) return;

        document.body.classList.toggle('sidebar-toggled', collapsed);
        sidebar.classList.toggle('toggled', collapsed);

        if (collapsed) {
            closeAllGroups();
        } else {
            sidebar.querySelectorAll('.sidebar-group > .collapse').forEach(clearFlyoutPosition);
        }

        if (savePreference) {
            try {
                localStorage.setItem(SIDEBAR_STATE_KEY, collapsed ? '1' : '0');
            } catch (e) {}
        }
    }

    function setMobileSidebarHidden(hidden) {
        if (isDesktop()) return;
        document.body.classList.toggle('sidebar-toggled', hidden);
        sidebar.classList.toggle('toggled', hidden);
        if (hidden) closeAllGroups();
        sidebar.querySelectorAll('.sidebar-group > .collapse').forEach(clearFlyoutPosition);
    }

    function restoreSidebarState() {
        if (!isDesktop()) return;
        var collapsed = false;
        try {
            collapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === '1';
        } catch (e) {}
        setSidebarCollapsed(collapsed, false);
    }

    if (isDesktop()) restoreSidebarState();
    else setMobileSidebarHidden(true);

    if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(restoreSidebarScroll);
        });
    } else {
        setTimeout(restoreSidebarScroll, 0);
    }

    sidebar.addEventListener('scroll', function () {
        scheduleFlyoutPosition();
        if (scrollSaveScheduled) return;
        scrollSaveScheduled = true;
        var save = function () {
            scrollSaveScheduled = false;
            saveSidebarScroll();
        };
        if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(save);
        else setTimeout(save, 50);
    }, { passive: true });

    sidebar.querySelectorAll('a[href^="index.php?page="]').forEach(function (link) {
        link.addEventListener('click', function () {
            saveSidebarScroll();
        });
    });

    // Un único controlador en fase de captura evita que SB Admin, index.php y el
    // topbar ejecuten el toggle varias veces sobre el mismo clic.
    document.addEventListener('click', function (event) {
        var target = event.target;
        var button = target && target.closest ? target.closest('#sidebarToggle, #sidebarToggleTop') : null;
        if (!button) return;

        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();

        if (isDesktop()) {
            if (button.id !== 'sidebarToggle') return;
            var collapsed = !sidebar.classList.contains('toggled');
            setSidebarCollapsed(collapsed, true);
            saveSidebarScroll();
        } else {
            if (button.id !== 'sidebarToggleTop') return;
            var shouldHide = !sidebar.classList.contains('toggled');
            setMobileSidebarHidden(shouldHide);
        }
    }, true);

    // Solo Pagos y Facturación usan submenú desplegable.
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

            saveSidebarScroll();
            scheduleFlyoutPosition();
        });
    });

    window.addEventListener('beforeunload', saveSidebarScroll);

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (isDesktop()) {
                restoreSidebarState();
                restoreSidebarScroll();
                scheduleFlyoutPosition();
            } else {
                setMobileSidebarHidden(true);
            }
        }, 80);
    });
})();
</script>