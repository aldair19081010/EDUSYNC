(function ($) {
    'use strict';

    var cfg = window.GUARDIANS_CONFIG || {};
    if (!cfg.api || !$('#guardians-table').length) return;

    var table = null;
    var cache = {};

    function esc(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function toast(message, type) {
        if (typeof window.alert_toast === 'function') {
            window.alert_toast(message, type || 'success');
        } else {
            alert(message);
        }
    }

    function fail(message) {
        $('#guardian-error').removeClass('d-none').text(message || 'Ocurrió un error.');
    }

    function clearFail() {
        $('#guardian-error').addClass('d-none').text('');
    }

    function get(action, data) {
        return $.ajax({
            url: cfg.api,
            method: 'GET',
            dataType: 'json',
            cache: false,
            data: $.extend({ action: action }, data || {})
        });
    }

    function post(action, data) {
        return $.ajax({
            url: cfg.api + '?action=' + encodeURIComponent(action),
            method: 'POST',
            dataType: 'json',
            data: $.extend({ csrf_token: cfg.csrf }, data || {})
        });
    }

    function actions(row) {
        var id = Number(row.id || 0);
        var name = esc(row.full_name || 'Apoderado');
        var hasAccess = Number(row.has_access || 0) === 1;
        var keyTitle = hasAccess ? 'Restablecer clave' : 'Crear acceso';
        return '' +
            '<div class="btn-group btn-group-sm" role="group">' +
            '<button class="btn btn-outline-primary btn-guardian-links" data-id="' + id + '" title="Vincular estudiantes"><i class="fas fa-link"></i></button>' +
            '<button class="btn btn-outline-secondary btn-guardian-edit" data-id="' + id + '" title="Editar"><i class="fas fa-edit"></i></button>' +
            '<button class="btn btn-outline-warning btn-guardian-pin" data-id="' + id + '" data-name="' + name + '" data-access="' + (hasAccess ? '1' : '0') + '" title="' + keyTitle + '"><i class="fas fa-key"></i></button>' +
            '<button class="btn btn-outline-info btn-guardian-history" data-id="' + id + '" data-name="' + name + '" title="Historial"><i class="fas fa-history"></i></button>' +
            '<button class="btn ' + (row.status === 'Activo' ? 'btn-outline-danger' : 'btn-outline-success') + ' btn-guardian-toggle" data-id="' + id + '" data-status="' + esc(row.status) + '" title="' + (row.status === 'Activo' ? 'Desactivar' : 'Activar') + '"><i class="fas ' + (row.status === 'Activo' ? 'fa-user-slash' : 'fa-user-check') + '"></i></button>' +
            '</div>';
    }

    function renderStudents(row) {
        var count = Number(row.linked_students || 0);
        var names = String(row.student_names || '').split(' | ').filter(Boolean);
        var chips = names.slice(0, 2).map(function (name) {
            return '<span class="badge badge-light border mr-1 mb-1">' + esc(name) + '</span>';
        }).join('');
        if (names.length > 2) chips += '<span class="badge badge-secondary mr-1 mb-1">+' + (names.length - 2) + '</span>';
        return '<strong>' + count + '</strong><div class="mt-1">' + (chips || '<span class="text-muted small">Sin vínculos</span>') + '</div>';
    }

    function renderStatus(row) {
        var status = row.status || 'Activo';
        var statusBadge = '<span class="badge badge-' + (status === 'Activo' ? 'success' : 'secondary') + '">' + esc(status) + '</span>';
        var accessBadge = Number(row.has_access || 0) === 1
            ? '<span class="badge badge-light border mt-1"><i class="fas fa-key text-success mr-1"></i>Acceso creado</span>'
            : '<span class="badge badge-warning mt-1"><i class="fas fa-key mr-1"></i>Sin acceso</span>';
        return statusBadge + '<div>' + accessBadge + '</div>';
    }

    function rebuild(rows) {
        cache = {};
        (rows || []).forEach(function (row) { cache[row.id] = row; });

        if (table) {
            table.clear().rows.add(rows || []).draw();
        } else {
            table = $('#guardians-table').DataTable({
                data: rows || [],
                order: [[1, 'asc']],
                pageLength: 25,
                language: {
                    search: 'Buscar:',
                    lengthMenu: 'Mostrar _MENU_',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_',
                    infoEmpty: 'Sin registros',
                    zeroRecords: 'No se encontraron apoderados',
                    paginate: { previous: 'Anterior', next: 'Siguiente' }
                },
                columns: [
                    { data: 'dni', render: function (v) { return '<strong>' + esc(v) + '</strong>'; } },
                    { data: null, render: function (row) { return '<strong>' + esc(row.full_name) + '</strong><div class="small text-muted">' + esc(row.email || 'Sin correo') + '</div>'; } },
                    { data: null, render: function (row) { return esc(row.telefono || '—'); } },
                    { data: null, render: renderStudents, orderable: false },
                    { data: null, render: renderStatus },
                    { data: null, render: actions, orderable: false, searchable: false }
                ]
            });
            $('#guardian-status-filter').on('change', function () {
                table.column(4).search(this.value).draw();
            });
        }

        var active = (rows || []).filter(function (r) { return r.status === 'Activo'; }).length;
        var links = (rows || []).reduce(function (sum, r) { return sum + Number(r.linked_students || 0); }, 0);
        $('#guardian-stat-total').text((rows || []).length);
        $('#guardian-stat-active').text(active);
        $('#guardian-stat-links').text(links);
    }

    function loadGuardians() {
        clearFail();
        get('list').done(function (resp) {
            if (!resp || Number(resp.status) !== 1) return fail(resp && resp.message);
            rebuild(resp.guardians || []);
        }).fail(function (xhr) {
            var resp = xhr.responseJSON || {};
            fail(resp.message || 'No se pudo cargar el listado de apoderados.');
        });
    }

    function newGuardian() {
        $('#guardian-form')[0].reset();
        $('#guardian-id').val('0');
        $('#guardian-status').val('Activo');
        $('#guardian-form-title').text('Nuevo apoderado');
        $('#guardian-pin-label').text('Clave numérica inicial *');
        $('#guardian-pin-note').text('6 a 12 dígitos. Se creará el acceso junto con el perfil.');
        $('#guardian-pin').prop('required', true).val('');
        $('#guardian-form-modal').modal('show');
    }

    function editGuardian(id) {
        get('detail', { id: id }).done(function (resp) {
            if (!resp || Number(resp.status) !== 1) return toast(resp && resp.message || 'No se pudo cargar el apoderado.', 'danger');
            var g = resp.guardian;
            var hasAccess = Number(g.has_access || 0) === 1;
            $('#guardian-id').val(g.id);
            $('#guardian-dni').val(g.dni);
            $('#guardian-nombres').val(g.nombres);
            $('#guardian-apellido-paterno').val(g.apellido_paterno);
            $('#guardian-apellido-materno').val(g.apellido_materno || '');
            $('#guardian-telefono').val(g.telefono || '');
            $('#guardian-email').val(g.email || '');
            $('#guardian-status').val(g.status);
            $('#guardian-pin').val('').prop('required', false);
            $('#guardian-pin-label').text(hasAccess ? 'Nueva clave numérica (opcional)' : 'Crear acceso con clave (opcional)');
            $('#guardian-pin-note').text(hasAccess ? 'Déjala vacía para conservar la clave actual.' : 'Este perfil vino de la ficha de un alumno y aún no tiene acceso. Déjala vacía para conservarlo así.');
            $('#guardian-form-title').text('Editar apoderado');
            $('#guardian-form-modal').modal('show');
        }).fail(function (xhr) {
            toast((xhr.responseJSON || {}).message || 'No se pudo cargar el apoderado.', 'danger');
        });
    }

    function permissionBadges(link) {
        var labels = [];
        if (Number(link.can_view_grades)) labels.push('Notas');
        if (Number(link.can_view_attendance)) labels.push('Asistencia');
        if (Number(link.can_view_payments)) labels.push('Pagos');
        if (Number(link.can_receive_communications)) labels.push('Comunicaciones');
        return labels.map(function (label) { return '<span class="badge badge-light border mr-1 mb-1">' + esc(label) + '</span>'; }).join('') || '<span class="text-muted small">Sin permisos</span>';
    }

    function loadLinks(id, openModal) {
        get('detail', { id: id }).done(function (resp) {
            if (!resp || Number(resp.status) !== 1) return toast(resp && resp.message || 'No se pudieron cargar los vínculos.', 'danger');
            $('#guardian-links-id').val(id);
            $('#guardian-links-name').text(resp.guardian.full_name + ' · ' + resp.guardian.dni);
            var html = '';
            (resp.links || []).forEach(function (link) {
                var fromStudent = link.source_type === 'StudentForm';
                var source = fromStudent ? '<div><span class="badge badge-info mt-1">Ficha del alumno</span></div>' : '';
                var unlink = fromStudent
                    ? '<span class="text-muted small" title="Este vínculo se administra desde la ficha del alumno"><i class="fas fa-lock"></i></span>'
                    : '<button class="btn btn-sm btn-outline-danger btn-guardian-unlink" data-student="' + Number(link.student_id) + '" title="Desvincular"><i class="fas fa-unlink"></i></button>';
                html += '<tr>' +
                    '<td><strong>' + esc(link.name) + '</strong><div class="small text-muted">' + esc(link.id_no) + '</div></td>' +
                    '<td>' + esc(link.nivel + ' · ' + link.grado + ' ' + (link.seccion || 'U')) + '<div class="small text-muted">' + esc(link.student_status) + '</div></td>' +
                    '<td>' + esc(link.parentesco) + source + '</td>' +
                    '<td>' + (Number(link.is_primary) ? '<span class="badge badge-primary">Sí</span>' : '<span class="text-muted">No</span>') + '</td>' +
                    '<td>' + permissionBadges(link) + '</td>' +
                    '<td class="text-right">' + unlink + '</td>' +
                    '</tr>';
            });
            if (!html) html = '<tr><td colspan="6" class="text-center text-muted py-4">Este apoderado todavía no tiene estudiantes vinculados.</td></tr>';
            $('#guardian-links-body').html(html);
            if (openModal) $('#guardian-links-modal').modal('show');
        }).fail(function (xhr) {
            toast((xhr.responseJSON || {}).message || 'No se pudieron cargar los vínculos.', 'danger');
        });
    }

    function initStudentSearch() {
        if (!$.fn.select2) return;
        $('#guardian-student-id').select2({
            dropdownParent: $('#guardian-links-modal'),
            width: '100%',
            placeholder: 'Buscar por DNI o nombre...',
            minimumInputLength: 1,
            allowClear: true,
            ajax: {
                url: cfg.api,
                dataType: 'json',
                delay: 250,
                data: function (params) { return { action: 'students', q: params.term || '' }; },
                processResults: function (resp) { return { results: resp && resp.results ? resp.results : [] }; }
            }
        });
    }

    function openPin(id, name, hasAccess) {
        $('#guardian-pin-id').val(id);
        $('#guardian-pin-name').text(name || 'Apoderado');
        $('#guardian-new-pin').val('');
        $('#guardian-pin-modal .modal-title').html('<i class="fas fa-key mr-2"></i>' + (hasAccess ? 'Restablecer clave' : 'Crear acceso'));
        $('#guardian-pin-form button[type="submit"]').text(hasAccess ? 'Guardar nueva clave' : 'Crear acceso');
        $('#guardian-pin-modal').modal('show');
    }

    function showHistory(id, name) {
        $('#guardian-history-name').text(name || '');
        $('#guardian-history-body').html('<tr><td colspan="4" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-1"></i> Cargando...</td></tr>');
        $('#guardian-history-modal').modal('show');
        get('history', { id: id }).done(function (resp) {
            if (!resp || Number(resp.status) !== 1) return $('#guardian-history-body').html('<tr><td colspan="4" class="text-danger">' + esc(resp && resp.message || 'No se pudo cargar.') + '</td></tr>');
            var html = '';
            (resp.history || []).forEach(function (row) {
                var detail = row.details ? JSON.stringify(row.details, null, 2) : '—';
                html += '<tr><td class="text-nowrap">' + esc(row.created_at) + '</td><td><span class="badge badge-light border">' + esc(row.action) + '</span></td><td>' + esc(row.actor_name) + '</td><td><div class="guardian-history-details">' + esc(detail) + '</div></td></tr>';
            });
            if (!html) html = '<tr><td colspan="4" class="text-center text-muted py-4">Sin movimientos registrados.</td></tr>';
            $('#guardian-history-body').html(html);
        }).fail(function (xhr) {
            $('#guardian-history-body').html('<tr><td colspan="4" class="text-danger">' + esc((xhr.responseJSON || {}).message || 'No se pudo cargar el historial.') + '</td></tr>');
        });
    }

    $('#btn-new-guardian').on('click', newGuardian);

    $('#guardian-form').on('submit', function (e) {
        e.preventDefault();
        var btn = $('#guardian-save-btn').prop('disabled', true);
        var data = {
            id: $('#guardian-id').val(),
            dni: $('#guardian-dni').val(),
            nombres: $('#guardian-nombres').val(),
            apellido_paterno: $('#guardian-apellido-paterno').val(),
            apellido_materno: $('#guardian-apellido-materno').val(),
            telefono: $('#guardian-telefono').val(),
            email: $('#guardian-email').val(),
            status: $('#guardian-status').val(),
            pin: $('#guardian-pin').val()
        };
        post('save', data).done(function (resp) {
            if (!resp || Number(resp.status) !== 1) return toast(resp && resp.message || 'No se pudo guardar.', 'danger');
            $('#guardian-form-modal').modal('hide');
            toast(resp.message || 'Apoderado guardado.');
            loadGuardians();
        }).fail(function (xhr) {
            toast((xhr.responseJSON || {}).message || 'No se pudo guardar el apoderado.', 'danger');
        }).always(function () { btn.prop('disabled', false); });
    });

    $('#guardians-table tbody').on('click', '.btn-guardian-edit', function () { editGuardian($(this).data('id')); });
    $('#guardians-table tbody').on('click', '.btn-guardian-links', function () { loadLinks($(this).data('id'), true); });
    $('#guardians-table tbody').on('click', '.btn-guardian-pin', function () { openPin($(this).data('id'), $(this).data('name'), Number($(this).data('access')) === 1); });
    $('#guardians-table tbody').on('click', '.btn-guardian-history', function () { showHistory($(this).data('id'), $(this).data('name')); });
    $('#guardians-table tbody').on('click', '.btn-guardian-toggle', function () {
        var id = $(this).data('id');
        var status = $(this).data('status');
        var action = status === 'Activo' ? 'desactivar' : 'activar';
        if (!confirm('¿Deseas ' + action + ' este apoderado?')) return;
        post('toggle_status', { guardian_id: id }).done(function (resp) {
            if (!resp || Number(resp.status) !== 1) return toast(resp && resp.message || 'No se pudo cambiar el estado.', 'danger');
            toast(resp.message || 'Estado actualizado.');
            loadGuardians();
        }).fail(function (xhr) { toast((xhr.responseJSON || {}).message || 'No se pudo cambiar el estado.', 'danger'); });
    });

    $('#guardian-link-form').on('submit', function (e) {
        e.preventDefault();
        var guardianId = Number($('#guardian-links-id').val() || 0);
        var studentId = Number($('#guardian-student-id').val() || 0);
        if (!guardianId || !studentId) return toast('Selecciona un estudiante.', 'warning');
        post('link', {
            guardian_id: guardianId,
            student_id: studentId,
            parentesco: $('#guardian-parentesco').val(),
            is_primary: $('#guardian-is-primary').is(':checked') ? 1 : 0,
            can_view_grades: $('#guardian-can-grades').is(':checked') ? 1 : 0,
            can_view_attendance: $('#guardian-can-attendance').is(':checked') ? 1 : 0,
            can_view_payments: $('#guardian-can-payments').is(':checked') ? 1 : 0,
            can_receive_communications: $('#guardian-can-communications').is(':checked') ? 1 : 0
        }).done(function (resp) {
            if (!resp || Number(resp.status) !== 1) return toast(resp && resp.message || 'No se pudo vincular.', 'danger');
            toast(resp.message || 'Estudiante vinculado.');
            $('#guardian-student-id').val(null).trigger('change');
            $('#guardian-is-primary').prop('checked', false);
            loadLinks(guardianId, false);
            loadGuardians();
        }).fail(function (xhr) { toast((xhr.responseJSON || {}).message || 'No se pudo vincular.', 'danger'); });
    });

    $('#guardian-links-body').on('click', '.btn-guardian-unlink', function () {
        var guardianId = Number($('#guardian-links-id').val() || 0);
        var studentId = Number($(this).data('student') || 0);
        if (!confirm('¿Desvincular este estudiante del apoderado?')) return;
        post('unlink', { guardian_id: guardianId, student_id: studentId }).done(function (resp) {
            if (!resp || Number(resp.status) !== 1) return toast(resp && resp.message || 'No se pudo desvincular.', 'danger');
            toast(resp.message || 'Estudiante desvinculado.');
            loadLinks(guardianId, false);
            loadGuardians();
        }).fail(function (xhr) { toast((xhr.responseJSON || {}).message || 'No se pudo desvincular.', 'danger'); });
    });

    $('#guardian-pin-form').on('submit', function (e) {
        e.preventDefault();
        var id = Number($('#guardian-pin-id').val() || 0);
        var pin = String($('#guardian-new-pin').val() || '');
        post('reset_pin', { guardian_id: id, pin: pin }).done(function (resp) {
            if (!resp || Number(resp.status) !== 1) return toast(resp && resp.message || 'No se pudo guardar la clave.', 'danger');
            $('#guardian-pin-modal').modal('hide');
            toast(resp.message || 'Clave actualizada.');
            loadGuardians();
        }).fail(function (xhr) { toast((xhr.responseJSON || {}).message || 'No se pudo guardar la clave.', 'danger'); });
    });

    $('#guardian-links-modal').on('shown.bs.modal', function () {
        if (!$('#guardian-student-id').hasClass('select2-hidden-accessible')) initStudentSearch();
    });

    loadGuardians();
})(window.jQuery);
