(function ($) {
    'use strict';
    if (!$) return;

    $(function () {
        var root = $('#student-web-notifications');
        if (!root.length || !$('#swn-items').length) return;

        var api = 'api/student_notifications.php';
        var rows = [];
        var filter = 'all';
        var loading = false;
        var requestedOpen = Number(root.attr('data-open-id') || 0);
        var more = false;
        var unreadTotal = 0;

        function kind(item) {
            var screen = String(item.screen || '').toLowerCase();
            if (screen === 'announcements') return {label:'Comunicado', icon:'fa-bullhorn', group:'announcements'};
            if (screen === 'grades') return {label:'Notas', icon:'fa-graduation-cap', group:'alerts'};
            if (screen === 'debts') return {label:'Deudas', icon:'fa-file-invoice-dollar', group:'alerts'};
            if (screen === 'payments') return {label:'Pagos', icon:'fa-receipt', group:'activity'};
            if (screen === 'attendance') return {label:'Asistencia', icon:'fa-calendar-check', group:'activity'};
            return {label:'Aviso',icon:'fa-bell',group:'activity'};
        }

        function formatDate(value) {
            var m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
            return m ? m[3]+'/'+m[2]+'/'+m[1]+' · '+m[4]+':'+m[5] : String(value || '');
        }

        function error(message) {
            $('#swn-error').toggleClass('d-none', !message).text(message || '');
        }

        function render() {
            var visible = rows.filter(function(item){
                return filter === 'all' || kind(item).group === filter;
            });
            var list = $('#swn-items').empty();
            if (!visible.length) {
                list.append($('<div class="swn-empty"></div>').text(
                    rows.length ? 'No hay notificaciones en esta categoría.' : 'Todavía no tienes notificaciones.'
                ));
            }
            visible.forEach(function(item) {
                var type = kind(item);
                var unread = !item.is_read;
                var link = $('<button type="button" class="swn-item w-100 text-left border-0"></button>');
                if (unread) link.addClass('unread');
                link.append($('<span class="swn-icon"></span>').append(
                    $('<i class="fas"></i>').addClass(type.icon)
                ));
                var main = $('<span class="swn-main"></span>');
                var title = $('<span class="swn-row"></span>').append(
                    $('<span class="swn-item-title"></span>').text(item.title || 'Notificación')
                );
                main.append(title);
                main.append($('<span class="swn-message d-block"></span>').text(item.body || ''));
                main.append($('<span class="swn-meta d-block"></span>').text(type.label+' · '+formatDate(item.created_at)));
                link.append(main);
                if (unread) link.append('<span class="swn-dot" title="Sin leer"></span>');
                link.on('click',function(){openItem(item);});
                list.append(link);
            });
            $('#swn-load-more').toggleClass('d-none', !more);
        }

        function syncCounters(result) {
            var count = Number(result.unread_count || 0);
            unreadTotal = count;
            $('#swn-unread-count').text(count ? count+' sin leer' : 'Todo leído');
            $('#swn-mark-all').toggleClass('d-none', !count);
            if (typeof window.refreshStudentWebNotifications === 'function') {
                window.refreshStudentWebNotifications();
            }
        }

        function setRead(item) {
            if (item.is_read) return $.Deferred().resolve().promise();
            return $.post(api, {action:'mark_read', id:item.id}, null, 'json').done(function(result) {
                if (result && result.status === 'ok') {
                    item.is_read = true;
                    unreadTotal = Math.max(0, unreadTotal - 1);
                    $('#swn-unread-count').text(unreadTotal ? unreadTotal+' sin leer' : 'Todo leído');
                    $('#swn-mark-all').toggleClass('d-none', unreadTotal === 0);
                    render();
                    if (typeof window.refreshStudentWebNotifications === 'function') window.refreshStudentWebNotifications();
                }
            });
        }

        function directPage(item) {
            var pages = {
                attendance: 'student_attendances',
                payments: 'student_payments',
                debts: 'student_debts',
                grades: 'student_grades'
            };
            var screen = String(item.screen || '').toLowerCase();
            return pages[screen] ? 'index.php?page='+pages[screen] : '';
        }

        function announcement(item) {
            var id = Number(item.entity_id || (item.data && item.data.announcement_id) || 0);
            if (!id) {
                error('No se encontró el contenido de este comunicado.');
                return;
            }
            $('#swn-announcement-title').text(item.title || 'Comunicado');
            $('#swn-announcement-body').text('Cargando comunicado...');
            $('#swn-announcement-sender').empty();
            $('#swn-announcement-modal').modal('show');

            $.getJSON(api, {action:'announcement_detail',announcement_id:id}).done(function(result){
                var data = result.announcement || {};
                if (result.status !== 'ok') {
                    $('#swn-announcement-body').text(result.message || 'El comunicado no está disponible.');
                    return;
                }
                $('#swn-announcement-title').text(data.title || 'Comunicado');
                $('#swn-announcement-body').text(data.content || '');
                $('#swn-announcement-sender').text((data.sender_name || 'Administración')+' · '+formatDate(data.created_at));
            }).fail(function(xhr){
                $('#swn-announcement-body').text((xhr.responseJSON || {}).message || 'No se pudo consultar el comunicado.');
            });
        }

        function openItem(item) {
            setRead(item).always(function () {
                if (String(item.screen || '') === 'announcements') {
                    announcement(item);
                } else {
                    var url = directPage(item);
                    if (url) window.location.href = url;
                    else {
                        $('#swn-announcement-title').text(item.title || 'Notificación');
                        $('#swn-announcement-sender').text(formatDate(item.created_at));
                        $('#swn-announcement-body').text(item.body || '');
                        $('#swn-announcement-modal').modal('show');
                    }
                }
            });
        }

        function load(older) {
            if (loading) return;
            loading = true;
            $('#swn-refresh,#swn-load-more').prop('disabled', true);
            var query = {action:'list',limit:50};
            if (older && rows.length) query.before_id = rows[rows.length-1].id;
            $.getJSON(api,query).done(function(result){
                if (!result || result.status !== 'ok' || !Array.isArray(result.items)) {
                    error((result && result.message) || 'No se pudieron cargar los avisos.');
                    return;
                }
                error('');
                rows = older ? rows.concat(result.items.filter(function(item){
                    return !rows.some(function(other){return Number(other.id)===Number(item.id);});
                })) : result.items;
                more = result.items.length === 50;
                render();
                syncCounters(result);
                if (requestedOpen > 0 && !older) {
                    var id = requestedOpen;
                    requestedOpen = 0;
                    var match = rows.find(function(item){return Number(item.id)===id;});
                    if (match) openItem(match);
                }
            }).fail(function(xhr){
                error((xhr.responseJSON || {}).message || 'No se pudieron cargar los avisos. Comprueba la conexión.');
                if (!rows.length) {
                    $('#swn-items').html('<div class="swn-empty">No se pudo cargar la bandeja. Pulsa Actualizar.</div>');
                }
            }).always(function(){
                loading = false;
                $('#swn-refresh,#swn-load-more').prop('disabled', false);
            });
        }

        $('.swn-tab').on('click',function(){
            $('.swn-tab').removeClass('active').attr('aria-selected','false');
            $(this).addClass('active').attr('aria-selected','true');
            filter = String($(this).data('filter'));
            render();
        });
        $('#swn-refresh').on('click',function(){load(false);});
        $('#swn-load-more').on('click',function(){load(true);});
        $('#swn-mark-all').on('click',function(){
            var button = $(this).prop('disabled',true);
            $.post(api,{action:'mark_all_read'},null,'json').done(function(result){
                if (!result || result.status !== 'ok') {
                    error((result && result.message) || 'No se pudo marcar las notificaciones.');
                    return;
                }
                rows.forEach(function(item){item.is_read=true;});
                render();
                unreadTotal = 0;
                $('#swn-unread-count').text('Todo leído');
                button.addClass('d-none');
                $(document).trigger('notifications:all-read');
            }).fail(function(){
                error('No se pudieron marcar los avisos como leídos.');
            }).always(function(){button.prop('disabled',false);});
        });
        $(document).on('notifications:all-read.studentCenter', function(){
            rows.forEach(function(item){ item.is_read = true; });
            unreadTotal = 0;
            $('#swn-unread-count').text('Todo leído');
            $('#swn-mark-all').addClass('d-none');
            render();
        });
        load(false);
    });
})(window.jQuery);
