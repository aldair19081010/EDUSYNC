/*
 * EduSync Web | campana compartida entre todos los perfiles.
 * Estudiantes: misma fuente de eventos que la app Android.
 * Personal: reutiliza la bandeja unificada y su sondeo existente.
 * Avisos del navegador son opcionales y funcionan mientras EduSync esté abierto.
 */
(function ($) {
    'use strict';
    if (!$) return;

    $(function () {
        var topbar = $('.edusync-topbar[data-edusync-notification-role]');
        if (!topbar.length) return;
        var role = topbar.attr('data-edusync-notification-role');
        var scope = role + ':' + (topbar.attr('data-edusync-notification-identity') || '');
        var bell = $('#alertsDropdown');
        if (!bell.length) return;

        var firstSnapshot = true;
        var seen = new Set();
        var activeToast = null;
        var preferenceKey = 'edusync:web:desktop-alerts:' + scope;
        var desktopSupported = !!(window.isSecureContext && 'Notification' in window);
        var desktopOn = false;
        try { desktopOn = localStorage.getItem(preferenceKey) === '1'; } catch (_) {}

        function badge(count) {
            count = Math.max(0, Number(count) || 0);
            var element = bell.find('.badge-counter');
            if (!count) { element.remove(); return; }
            if (!element.length) element = $('<span class="badge badge-danger badge-counter"></span>').appendTo(bell);
            element.text(count > 99 ? '99+' : String(count));
        }

        function stamp(value) {
            var match = String(value || '').match(/^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)/);
            return match ? match[3] + '/' + match[2] + ' · ' + match[4] + ':' + match[5] : '';
        }

        function icon(screen) {
            switch (screen) {
                case 'attendance': return {kind: 'Asistencia',glyph: 'fa-calendar-check',cls: 'bg-info'};
                case 'payments': return {kind: 'Pagos',glyph: 'fa-receipt',cls: 'bg-success'};
                case 'debts': return {kind: 'Deudas',glyph: 'fa-file-invoice-dollar',cls: 'bg-warning'};
                case 'grades': return {kind: 'Notas',glyph: 'fa-graduation-cap',cls: 'bg-primary'};
                case 'announcements': return {kind: 'Comunicados',glyph: 'fa-bullhorn',cls: 'bg-primary'};
                default: return {kind: 'Notificación',glyph: 'fa-bell',cls: 'bg-secondary'};
            }
        }

        function toast(item) {
            if (activeToast) activeToast.remove();
            var box = $('<a class="ed-web-toast" href="index.php?page=notifications"></a>');
            $('<span class="ed-web-toast-icon"><i class="fas fa-bell"></i></span>').appendTo(box);
            var copy = $('<span class="ed-web-toast-copy"></span>').appendTo(box);
            $('<strong></strong>').text(item.title || 'Notificación nueva').appendTo(copy);
            $('<small></small>').text(item.body || 'Tienes una nueva actividad en EduSync').appendTo(copy);
            $('<button type="button" class="ed-web-toast-close" aria-label="Cerrar aviso">&times;</button>')
                .on('click', function (e) { e.preventDefault(); e.stopPropagation(); box.remove(); })
                .appendTo(box);
            $('body').append(box);
            activeToast = box;
            window.setTimeout(function () { if (box[0].isConnected) box.remove(); }, 6500);
        }

        function announce(item) {
            if (document.visibilityState !== 'hidden') toast(item);
            if (!desktopSupported || !desktopOn || Notification.permission !== 'granted') return;
            try {
                var notification = new Notification(item.title || 'EduSync', {
                    body: item.body || 'Tienes un nuevo aviso.',
                    icon: 'assets/uploads/logo.jpg',
                    tag: 'edusync-' + scope + '-' + item.id,
                    silent: false
                });
                notification.onclick = function () {
                    window.focus();
                    window.location.href = 'index.php?page=notifications';
                    notification.close();
                };
            } catch (_) {}
        }

        $(document).on('edusync:notification-snapshot.web', function (_event, items) {
            if (!Array.isArray(items)) return;
            var unique = items.filter(function (item) { return item && item.id != null; });
            if (firstSnapshot) {
                unique.forEach(function (item) { seen.add(String(item.id)); });
                firstSnapshot = false;
                return;
            }
            var additions = [];
            unique.forEach(function (item) {
                var key = String(item.id);
                if (!seen.has(key)) additions.push(item);
                seen.add(key);
            });
            // No tormenta de alertas al restaurar el navegador o sincronizar muchas.
            if (additions.length) announce(additions[0]);
        });

        if (desktopSupported) {
            var action = $('<button type="button" id="ed-web-desktop-optin" class="dropdown-item ed-web-desktop-optin"></button>');
            $('.edusync-notification-menu').append(action);
            function refreshOptin() {
                var allowed = Notification.permission;
                if (allowed === 'denied') {
                    action.prop('disabled', true).text('Avisos del navegador bloqueados');
                } else {
                    action.prop('disabled', false).text(desktopOn && allowed === 'granted'
                        ? 'Desactivar avisos del navegador' : 'Activar avisos del navegador');
                }
            }
            action.on('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (desktopOn && Notification.permission === 'granted') {
                    desktopOn = false;
                } else {
                    Notification.requestPermission().then(function (permission) {
                        desktopOn = permission === 'granted';
                        try { localStorage.setItem(preferenceKey, desktopOn ? '1' : '0'); } catch (_) {}
                        refreshOptin();
                    });
                    return;
                }
                try { localStorage.setItem(preferenceKey, desktopOn ? '1' : '0'); } catch (_) {}
                refreshOptin();
            });
            refreshOptin();
        }

        if (role !== 'student') return;
        var api = 'api/student_notifications.php';
        var busy = false;

        function openItem(item) {
            var id = Number(item.id) || 0;
            if (!id) return;
            $.post(api, {action: 'mark_read', id: id}).always(function () {
                window.location.href = 'index.php?page=notifications&notification_id=' + encodeURIComponent(id);
            });
        }

        function refreshStudent() {
            if (busy) return;
            busy = true;
            $.getJSON(api, {action: 'list', limit: 12}).done(function (result) {
                if (!result || result.status !== 'ok' || !Array.isArray(result.items)) return;
                var list = $('#topbar-notification-list').empty();
                var unreadItems = result.items.filter(function (item) { return !item.is_read; });
                unreadItems.slice(0, 10).forEach(function (item) {
                    var type = icon(String(item.screen || ''));
                    var link = $('<a href="#" class="dropdown-item d-flex align-items-center system-notification-item"></a>');
                    link.append($('<div class="mr-3"></div>').append(
                        $('<span class="icon-circle"></span>').addClass(type.cls)
                            .append($('<i class="fas text-white"></i>').addClass(type.glyph))
                    ));
                    var copy = $('<div class="edusync-notification-copy"></div>');
                    var meta = $('<div class="edusync-notification-meta"></div>');
                    meta.append($('<span class="edusync-notification-kind"></span>').text(type.kind));
                    meta.append($('<span class="small text-gray-500"></span>').text(stamp(item.created_at)));
                    copy.append(meta);
                    copy.append($('<span class="font-weight-bold edusync-notification-title"></span>').text(item.title || 'Aviso'));
                    copy.append($('<div class="edusync-notification-detail"></div>').text(item.body || ''));
                    link.append(copy).on('click', function (e) { e.preventDefault(); openItem(item); });
                    list.append(link);
                });
                if (!unreadItems.length) {
                    list.append($('<div class="dropdown-item ed-web-empty"></div>').text('No tienes notificaciones nuevas.'));
                }
                var unread = Number(result.unread_count || 0);
                badge(unread);
                $('.mark-all-read-topbar').toggleClass('d-none', unread < 1);
                $(document).trigger('edusync:notification-snapshot', [result.items.map(function (item) {
                    return {
                        id: 'student:' + item.id,
                        title: item.title || 'Notificación',
                        body: item.body || '',
                        created_at: item.created_at || '',
                        kind: 'student'
                    };
                })]);
            }).fail(function () {
                if (!$('#topbar-notification-list .system-notification-item').length) {
                    $('#topbar-notification-list').empty().append(
                        $('<div class="dropdown-item ed-web-empty"></div>').text('No se pudieron cargar las notificaciones.')
                    );
                }
            }).always(function () { busy = false; });
        }

        window.refreshStudentWebNotifications = refreshStudent;
        refreshStudent();
        window.setInterval(refreshStudent, 10000);
        $(document).on('notifications:all-read.edusyncStudent notifications:changed.edusyncStudent',
            refreshStudent);
    });
})(window.jQuery);
