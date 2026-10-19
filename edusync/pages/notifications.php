<?php
// La bandeja de alumnos utiliza los mismos eventos que la aplicación Android.
if (!empty($_SESSION['student_logged_in']) || (int)($_SESSION['login_type'] ?? 0)===4) {
    include __DIR__.'/student_notifications_web.php';
    return;
}
include_once __DIR__ . '/../db_connect.php';
$school = (int)($_SESSION['login_school_id'] ?? 0);
$user = (int)($_SESSION['login_id'] ?? 0);
$notificationRole=(int)($_SESSION['login_type'] ?? 0);
$notificationHelp=[
    1=>'Alertas académicas, cierres bimestrales y solicitudes que necesitan seguimiento.',
    2=>'Avisos de tus cursos y estudiantes, evaluaciones y cierres bimestrales.',
    3=>'Cambios de asistencia, solicitudes y comunicaciones dirigidas a tu perfil.',
][$notificationRole] ?? 'Comunicaciones y avisos de la institución.';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$ready = true;
foreach (['notification_events', 'notification_user_state', 'notification_audit_log'] as $table) {
    $q = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$q || !$q->num_rows) $ready = false;
}
?>
<style>
.nt-head{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4285f4;border-radius:.55rem;padding:.8rem 1rem;gap:.65rem}
.nt-stat{background:#fff;border:1px solid #e3e6f0;border-radius:.55rem;padding:.65rem .85rem;height:100%}
.nt-stat small{display:block;color:#6e7891;font-size:.72rem;font-weight:700;text-transform:uppercase}
.nt-stat strong{font-size:1.2rem;color:#263754;line-height:1.25;display:block;margin-top:.14rem}
.nt-filter{background:#fff;border:1px solid #e3e6f0;border-radius:.55rem;padding:.65rem .85rem}
.nt-tabs{background:#fff;border:1px solid #e3e6f0;border-bottom:0;border-radius:.55rem .55rem 0 0;padding:.5rem .7rem;gap:.45rem}
.nt-panel{background:#fff;border:1px solid #e3e6f0;border-radius:0 0 .55rem .55rem;padding:0;overflow:hidden}
.nt-title{font-weight:700;color:#263754}
.nt-message{font-size:.82rem;color:#6e7891;line-height:1.35;margin-top:.15rem}
.nt-unread .nt-title:before{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;background:#4e73df;margin-right:7px}
.nt-actions{white-space:nowrap}
.nt-priority{font-size:.7rem}
.nt-date{white-space:nowrap;font-size:.82rem;color:#5e6679}
.nt-resolved{background:#fbfcfe}
.nt-type-icon{width:32px;height:32px;flex:0 0 32px;border-radius:9px;background:#edf3ff;color:#4e73df;display:inline-flex;align-items:center;justify-content:center;font-size:.85rem}
.nt-type-icon.attendance{background:#fff6e5;color:#b7791f}
.nt-type-icon.alert{background:#fff0f0;color:#be3d3d}
.nt-type-icon.finance{background:#eefaf1;color:#288452}
.nt-event-copy{min-width:0;overflow-wrap:anywhere}
.nt-actions .btn{padding:.32rem .48rem}
@media(max-width:575px){.nt-stat{padding:.55rem .65rem}.nt-stat strong{font-size:1.12rem}.nt-tabs{padding:.45rem .55rem}.nt-filter{padding:.55rem .65rem}}
@media(max-width:767px){.nt-tabs .btn-group{display:flex;flex-wrap:wrap}.nt-tabs .btn{flex:1 0 42%}}

/* Misma densidad de las tablas estándar de EduSync: controles nativos, sin duplicados. */
.nt-head h1{font-size:1.15rem;font-weight:700;line-height:1.3}
.nt-head .small{font-size:.78rem;line-height:1.35}
.nt-filter .form-row{margin-bottom:0}
.nt-filter .form-row > [class*="col-"]{margin-bottom:0!important}
.nt-filter label{display:block;font-size:.75rem;margin-bottom:.22rem!important}
.nt-filter .form-control-sm{min-height:32px;height:32px}
.nt-filter #nt-clear{height:32px;min-height:32px}
.nt-tabs .btn-group{flex-wrap:wrap;gap:.25rem}
.nt-tabs .btn{font-size:.76rem;padding:.28rem .6rem;border-radius:.36rem!important;margin:0!important}
#nt-table_wrapper{margin:0!important}
#nt-table_wrapper > .row:first-child,
#nt-table_wrapper > .row:last-child{margin:0!important;padding:.4rem .75rem!important;align-items:center}
#nt-table_wrapper > .row:first-child{background:#f8f9fc;border-bottom:1px solid #edf0f5}
#nt-table_wrapper > .row:last-child{border-top:1px solid #edf0f5}
#nt-table_wrapper > .row > [class*="col-"]{padding:0!important}
#nt-table_wrapper .dataTables_filter,
#nt-table_wrapper .dataTables_length,
#nt-table_wrapper .dataTables_info,
#nt-table_wrapper .dataTables_paginate{
    margin:0!important;padding:0!important;color:#6e7891;font-size:.78rem;line-height:1.2
}
#nt-table_wrapper .dataTables_filter label,
#nt-table_wrapper .dataTables_length label{
    display:inline-flex;align-items:center;gap:.4rem;
    white-space:nowrap;margin:0!important;font-size:.78rem
}
#nt-table_wrapper .dataTables_filter input,
#nt-table_wrapper .dataTables_length select{
    box-sizing:border-box;height:30px;min-height:30px;margin:0!important;
    background:#fff;border:1px solid #d8deea;border-radius:.35rem;
    font-size:.78rem;color:#49566a
}
#nt-table_wrapper .dataTables_filter input{width:165px;padding:.2rem .5rem}
#nt-table_wrapper .dataTables_length select{width:65px;padding:.15rem 1.35rem .15rem .45rem}
#nt-table_wrapper .pagination{margin:0!important}
#nt-table{margin:0!important;width:100%!important}
#nt-table thead th{background:#f8f9fc;color:#526274;font-size:.76rem;border-top:0}
#nt-table tbody td{vertical-align:middle;font-size:.82rem}
@media(max-width:767.98px){
    .nt-filter .form-row>[class*="col-"]{margin-bottom:.5rem!important}
    #nt-table_wrapper > .row:first-child,
    #nt-table_wrapper > .row:last-child{row-gap:.4rem}
    #nt-table_wrapper > .row > [class*="col-"]{
       flex:0 0 100%;max-width:100%;text-align:left!important
    }
    #nt-table_wrapper .dataTables_filter,
    #nt-table_wrapper .dataTables_length,
    #nt-table_wrapper .dataTables_paginate,
    #nt-table_wrapper .dataTables_info{text-align:left!important}
}
</style>

<div class="container-fluid py-3">
    <div class="nt-head d-flex flex-wrap align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1 text-gray-800"><i class="fas fa-bell text-primary mr-2"></i>Centro de notificaciones</h1>
            <div class="small text-muted"><?php echo htmlspecialchars($notificationHelp, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <button id="nt-read-all" class="btn btn-outline-primary btn-sm mt-2 mt-md-0"><i class="fas fa-check-double mr-1"></i>Marcar visibles como leídas</button>
    </div>

    <?php if (!$ready): ?>
        <div class="alert alert-warning"><i class="fas fa-database mr-2"></i>Ejecute manualmente <strong>sql/notifications_module_upgrade.sql</strong>. No se realizaron cambios automáticos en la base de datos.</div>
    <?php endif; ?>
    <div id="nt-error" class="alert alert-danger d-none"></div>

    <div class="row mb-3">
        <div class="col-md-3 col-6 mb-2"><div class="nt-stat"><small>Total disponible</small><strong id="nt-total">0</strong></div></div>
        <div class="col-md-3 col-6 mb-2"><div class="nt-stat"><small>Sin leer</small><strong id="nt-unread">0</strong></div></div>
        <div class="col-md-3 col-6 mb-2"><div class="nt-stat"><small>Prioridad alta</small><strong id="nt-high">0</strong></div></div>
        <div class="col-md-3 col-6 mb-2"><div class="nt-stat"><small>Requieren acción</small><strong id="nt-action">0</strong></div></div>
    </div>

    <div class="nt-filter mb-3">
        <div class="form-row">
            <div class="col-lg-2 col-md-4 mb-2"><label class="small font-weight-bold">Desde</label><input id="nt-from" type="date" class="form-control form-control-sm"></div>
            <div class="col-lg-2 col-md-4 mb-2"><label class="small font-weight-bold">Hasta</label><input id="nt-to" type="date" class="form-control form-control-sm"></div>
            <div class="col-lg-3 col-md-4 mb-2"><label class="small font-weight-bold">Tipo</label><select id="nt-category" class="form-control form-control-sm"><option value="">Todos</option><option>Asistencia</option><option>Académica</option><option>Alerta estudiantil</option><option>Sistema</option></select></div>
            <div class="col-lg-2 col-md-4 mb-2"><label class="small font-weight-bold">Prioridad</label><select id="nt-priority" class="form-control form-control-sm"><option value="">Todas</option><option>Alta</option><option>Normal</option><option>Baja</option></select></div>
            <div class="col-lg-2 col-md-4 mb-2"><label class="small font-weight-bold">Lectura</label><select id="nt-state" class="form-control form-control-sm"><option value="">Todas</option><option value="unread">Sin leer</option><option value="read">Leídas</option></select></div>
            <div class="col-lg-1 col-md-4 mb-2 d-flex align-items-end"><button id="nt-clear" type="button" class="btn btn-light border btn-sm btn-block" title="Limpiar filtros" aria-label="Limpiar filtros"><i class="fas fa-eraser" aria-hidden="true"></i></button></div>
        </div>
    </div>

    <div class="nt-tabs d-flex flex-wrap justify-content-between align-items-center">
        <div class="btn-group btn-group-sm">
            <button data-tab="pending" class="btn btn-primary">Pendientes</button>
            <button data-tab="all" class="btn btn-outline-primary">Todas</button>
            <button data-tab="attendance" class="btn btn-outline-primary">Asistencia</button>
            <button data-tab="academic" class="btn btn-outline-primary">Académicas</button>
            <?php if ($notificationRole === 1 || $notificationRole === 2): ?>
            <button data-tab="student_alerts" class="btn btn-outline-primary">Seguimiento académico</button>
            <?php endif; ?>
            <button data-tab="archived" class="btn btn-outline-primary">Archivadas</button>
        </div>
        <small class="text-muted mt-2 mt-md-0" id="nt-tab-help">Sin leer o que requieren atención.</small>
    </div>

    <div class="nt-panel">
        <div class="table-responsive ed-table-responsive">
            <table id="nt-table" class="table table-hover table-sm ed-table" width="100%">
                <thead><tr><th>Fecha</th><th>Notificación</th><th>Tipo</th><th>Prioridad</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function($){
    const ready = <?php echo $ready ? 'true' : 'false'; ?>;
    const api = 'notifications_api.php';
    const csrf = <?php echo json_encode($csrf); ?>;
    let table = null;
    let tab = 'pending';

    function esc(v){ return $('<div>').text(v == null ? '' : v).html(); }

    // La API entrega fecha/hora de Perú. Se formatea el texto sin crear un Date
    // para evitar que el navegador vuelva a aplicar otra zona horaria.
    function date(v){
        if(!v) return '—';
        const text = String(v).trim();
        const m = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?/);
        if(!m) return esc(text);
        let hour = Number(m[4]);
        const suffix = hour >= 12 ? 'p. m.' : 'a. m.';
        hour = hour % 12 || 12;
        return '<span class="nt-date">' + m[3] + '/' + m[2] + '/' + m[1] + '<br>' + String(hour).padStart(2,'0') + ':' + m[5] + ' ' + suffix + '</span>';
    }

    function filters(){
        return {
            tab: tab,
            date_from: $('#nt-from').val(),
            date_to: $('#nt-to').val(),
            category: $('#nt-category').val(),
            priority: $('#nt-priority').val(),
            state: $('#nt-state').val()
        };
    }

    function post(action, data){
        return $.post(api + '?action=' + action, $.extend({csrf_token: csrf}, data || {}), null, 'json');
    }

    function notificationInfo(r) {
        const source=String(r.source_type || '');
        const category=String(r.category || '');
        let icon='fa-bell',tone='';
        if(source==='low_grade') {icon='fa-exclamation-triangle';tone='alert';}
        else if(category==='Asistencia') {icon='fa-user-check';tone='attendance';}
        else if(source.indexOf('grade')!==-1 || source==='evaluation' || category==='Académica') icon='fa-book-open';
        else if(category==='Finanzas' || category==='Cobros') {icon='fa-wallet';tone='finance';}
        else if(source.indexOf('announcement')!==-1) icon='fa-bullhorn';
        const typeLabel=source==='low_grade'?'Seguimiento académico':category;
        return '<div class="d-flex align-items-start" style="gap:.65rem">' +
            '<span class="nt-type-icon ' + tone + '"><i class="fas ' + icon + '"></i></span>' +
            '<div class="nt-event-copy"><div class="nt-title">' + esc(r.title) + '</div>' +
            '<div class="nt-message">' + esc(r.message) + '</div>' +
            '<div class="small text-muted mt-1">' + esc(typeLabel) + '</div></div></div>';
    }

    function priority(v){
        const c = v === 'Alta' ? 'danger' : (v === 'Baja' ? 'secondary' : 'primary');
        return '<span class="badge badge-' + c + ' nt-priority">' + esc(v) + '</span>';
    }

    function stateBadge(r){
        if(r.workflow_status === 'Pendiente') return '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pendiente</span>';
        if(r.workflow_status === 'Aprobada') return '<span class="badge badge-success"><i class="fas fa-check mr-1"></i>Aprobada</span>';
        if(r.workflow_status === 'Rechazada') return '<span class="badge badge-danger"><i class="fas fa-times mr-1"></i>Rechazada</span>';
        return Number(r.is_read)
            ? '<span class="text-muted small"><i class="fas fa-check mr-1"></i>Leída</span>'
            : '<span class="text-primary small font-weight-bold">Nueva</span>';
    }

    function actions(r){
        const resolvedRequest = ['attendance_request','attendance_admin_result','attendance_result'].includes(r.source_type)
            && (r.workflow_status === 'Aprobada' || r.workflow_status === 'Rechazada');
        const openTitle = resolvedRequest ? 'Ver resolución' : 'Abrir';
        const openClass = resolvedRequest ? 'btn-outline-success' : 'btn-outline-primary';
        const open = r.open_mode === 'modal'
            ? '<button class="btn btn-sm ' + openClass + ' nt-open" title="' + openTitle + '"><i class="fas fa-eye"></i></button>'
            : '<a class="btn btn-sm ' + openClass + '" href="' + esc(r.open_url) + '" title="' + openTitle + '"><i class="fas fa-eye"></i></a>';
        const read = Number(r.is_read) ? '' : '<button class="btn btn-sm btn-outline-secondary nt-read" title="Marcar como leída"><i class="fas fa-check"></i></button>';
        const archive = tab === 'archived'
            ? '<button class="btn btn-sm btn-outline-secondary nt-restore" title="Restaurar"><i class="fas fa-undo"></i></button>'
            : '<button class="btn btn-sm btn-outline-secondary nt-archive" title="Archivar"><i class="fas fa-archive"></i></button>';
        return '<div class="btn-group nt-actions" data-key="' + esc(r.nkey) + '" data-url="' + esc(r.open_url) + '">' + open + read + archive + '</div>';
    }

    function init(){
        if(!ready || !$.fn.DataTable) return;
        if(table) table.destroy();
        table = $('#nt-table').DataTable({
            serverSide: true,
            processing: true,
            pageLength: 20,
            lengthMenu: [[20,50,100],[20,50,100]],
            order: [],
            ajax: {
                url: api,
                data: d => $.extend(d, filters()),
                dataSrc: r => {
                    const s = r.summary || {};
                    $('#nt-total').text(s.total || 0);
                    $('#nt-unread').text(s.unread || 0);
                    $('#nt-high').text(s.high_priority || 0);
                    $('#nt-action').text(s.requires_action || 0);
                    $('#nt-error').addClass('d-none');
                    return r.data || [];
                },
                error: x => {
                    let m = 'No se pudo cargar la bandeja.';
                    try { m = JSON.parse(x.responseText).message || m; } catch(e) {}
                    $('#nt-error').removeClass('d-none').text(m);
                }
            },
            createdRow: (row, data) => {
                if(!Number(data.is_read)) $(row).addClass('nt-unread');
                if(['attendance_request','attendance_admin_result','attendance_result'].includes(data.source_type)
                    && data.workflow_status && data.workflow_status !== 'Pendiente') $(row).addClass('nt-resolved');
            },
            columns: [
                {data:'created_at', render:date},
                {data:null, render:notificationInfo},
                {data:'category', render:v => '<span class="badge badge-light border">' + esc(v) + '</span>'},
                {data:'priority', render:priority},
                {data:null, render:stateBadge},
                {data:null, orderable:false, render:actions}
            ],
            language: {
                search:'Buscar:', lengthMenu:'Mostrar _MENU_', processing:'Actualizando bandeja...',
                zeroRecords:'No hay notificaciones en esta vista', info:'Mostrando _START_ a _END_ de _TOTAL_',
                infoEmpty:'Sin notificaciones', paginate:{previous:'Anterior',next:'Siguiente'}
            }
        });
    }

    function reload(){ if(table) table.ajax.reload(null, false); }

    $('.nt-tabs button').click(function(){
        tab = $(this).data('tab');
        $('.nt-tabs button').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).addClass('btn-primary').removeClass('btn-outline-primary');
        $('#nt-tab-help').text({
            pending:'Solo notificaciones sin leer y solicitudes que aún requieren acción.',
            all:'Historial completo disponible.',
            attendance:'Solicitudes pendientes y resoluciones de asistencia.',
            academic:'Evaluaciones y actividad académica.',
            student_alerts:'Un caso nuevo aparece una sola vez. Si sigue en riesgo, conserva su estado de lectura.',
            archived:'Notificaciones retiradas de la bandeja.'
        }[tab]);
        init();
    });

    $('#nt-from,#nt-to,#nt-category,#nt-priority,#nt-state').change(init);
    $('#nt-clear').click(function(){ $('#nt-from,#nt-to,#nt-category,#nt-priority,#nt-state').val(''); init(); });

    $(document).on('click','.nt-open',function(){
        const b = $(this).closest('.nt-actions');
        uni_modal('Detalle de notificación', b.data('url'), 'modal-lg');
        post('mark',{key:b.data('key')}).always(reload);
    });
    $(document).on('click','.nt-read',function(){ post('mark',{key:$(this).closest('.nt-actions').data('key')}).done(reload); });
    $(document).on('click','.nt-archive',function(){ post('archive',{key:$(this).closest('.nt-actions').data('key')}).done(reload); });
    $(document).on('click','.nt-restore',function(){ post('restore',{key:$(this).closest('.nt-actions').data('key')}).done(reload); });

    $('#nt-read-all').click(() => post('mark_all', filters()).done(r => {
        if(typeof alert_toast === 'function') alert_toast(r.message, 'success');
        reload();
    }));

    if(ready) init();

    // El usuario toca un Web Push con EduSync cerrado: abrir el aviso
    // exacto tras verificar que realmente pertenece a su perfil/colegio.
    const pushParams = new URLSearchParams(window.location.search);
    const pushEventId = Number(pushParams.get('push_event_id') || 0);
    const pushKind = pushParams.get('push_kind') === 'low_grade' ? 'low_grade' : 'event';
    if(ready && Number.isSafeInteger(pushEventId) && pushEventId > 0){
        $.getJSON(api,{action:'peek',tab:'all',id:pushEventId,kind:pushKind}).done(function(result){
            if(!result || Number(result.status)!==1 || !result.data)return;
            const item=result.data;
            let attempt=0;
            const open=function(){
                if(typeof window.uni_modal !== 'function'){
                    if(attempt++<20)window.setTimeout(open,150);
                    return;
                }
                window.uni_modal('Detalle de notificación',item.open_url,'modal-lg');
                if(!Number(item.is_read))post('mark',{key:item.nkey}).always(reload);
            };
            open();
        });
    }

    window.setInterval(() => {
        if(table && document.visibilityState !== 'hidden') table.ajax.reload(null, false);
    }, 8000);
    $(document).on('attendance:request-reviewed.notifications', reload);
})(jQuery);
</script>
