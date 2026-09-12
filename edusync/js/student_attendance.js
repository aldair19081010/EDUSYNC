document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('student-attendance-app');
    if (!root) return;

    const endpoint = root.dataset.endpoint || 'api/my_attendance.php';
    const yearFilter = document.getElementById('attendance-year-filter');
    const stats = document.getElementById('attendance-stats');
    const months = document.getElementById('attendance-months');
    const caption = document.getElementById('attendance-caption');
    const errorBox = document.getElementById('attendance-error');
    const state = { response: null, days: [], year: '' };

    const monthNames = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    const weekdayNames = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseDate(value) {
        const parts = String(value || '').slice(0, 10).split('-').map(Number);
        if (parts.length !== 3 || !parts[0] || !parts[1] || !parts[2]) return null;
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function formatDate(value) {
        const date = parseDate(value);
        if (!date) return { main: 'Sin fecha', weekday: '' };
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        return { main: `${day}/${month}/${date.getFullYear()}`, weekday: weekdayNames[date.getDay()] };
    }

    function formatTime(value) {
        const text = String(value || '').trim();
        if (!text || text === '00:00:00' || text === '00:00') return '—';
        const parts = text.split(':');
        let hour = Number(parts[0]);
        const minute = String(parts[1] || '00').padStart(2, '0');
        if (Number.isNaN(hour)) return escapeHtml(text);
        const suffix = hour >= 12 ? 'p. m.' : 'a. m.';
        hour = hour % 12 || 12;
        return `${String(hour).padStart(2, '0')}:${minute} ${suffix}`;
    }

    function normalizeStatus(value) {
        const text = String(value || '').trim();
        const lower = text.toLowerCase();
        if (['normal','temprano','presente'].includes(lower)) return 'Presente';
        if (lower === 'tarde') return 'Tarde';
        if (lower === 'ausente justificada' || lower === 'ausencia justificada') return 'Ausente Justificada';
        if (lower === 'ausente' || lower === 'ausencia') return 'Ausente';
        if (lower === 'permiso') return 'Permiso';
        return text || 'Sin estado';
    }

    function statusBadge(value) {
        const status = normalizeStatus(value);
        let cls = 'attendance-badge-other';
        let icon = 'fa-info-circle';
        if (status === 'Presente') { cls = 'attendance-badge-present'; icon = 'fa-check-circle'; }
        else if (status === 'Tarde') { cls = 'attendance-badge-late'; icon = 'fa-clock'; }
        else if (status === 'Ausente') { cls = 'attendance-badge-absent'; icon = 'fa-times-circle'; }
        else if (status === 'Ausente Justificada') { cls = 'attendance-badge-justified'; icon = 'fa-file-medical'; }
        else if (status === 'Permiso') { cls = 'attendance-badge-permission'; icon = 'fa-user-clock'; }
        return `<span class="attendance-badge ${cls}"><i class="fas ${icon} mr-1"></i>${escapeHtml(status)}</span>`;
    }

    function selectedDays() {
        if (!state.year) return state.days.slice();
        return state.days.filter(day => String(day.anio_academico) === String(state.year));
    }

    function summarize(days) {
        const summary = { total: days.length, present: 0, late: 0, absent: 0, justified: 0, permission: 0, other: 0, rate: 0 };
        days.forEach(day => {
            const status = normalizeStatus(day.estado);
            if (status === 'Presente') summary.present++;
            else if (status === 'Tarde') summary.late++;
            else if (status === 'Ausente') summary.absent++;
            else if (status === 'Ausente Justificada') summary.justified++;
            else if (status === 'Permiso') summary.permission++;
            else summary.other++;
        });
        const attended = summary.present + summary.late;
        summary.rate = summary.total > 0 ? Math.round((attended / summary.total) * 1000) / 10 : 0;
        return summary;
    }

    function renderStats() {
        const summary = summarize(selectedDays());
        const absenceTotal = summary.absent + summary.justified;
        stats.innerHTML = `
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-attendance-stat"><div class="card-body d-flex align-items-center justify-content-between"><div><small>Asistencia</small><strong>${summary.rate.toFixed(1)}%</strong><div class="student-attendance-note">${summary.total} días registrados</div></div><div class="stat-icon"><i class="fas fa-chart-pie"></i></div></div></div></div>
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-attendance-stat success"><div class="card-body d-flex align-items-center justify-content-between"><div><small>Presentes</small><strong>${summary.present}</strong></div><div class="stat-icon"><i class="fas fa-check-circle"></i></div></div></div></div>
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-attendance-stat warning"><div class="card-body d-flex align-items-center justify-content-between"><div><small>Tardanzas</small><strong>${summary.late}</strong></div><div class="stat-icon"><i class="fas fa-clock"></i></div></div></div></div>
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-attendance-stat danger"><div class="card-body d-flex align-items-center justify-content-between"><div><small>Ausencias</small><strong>${absenceTotal}</strong><div class="student-attendance-note">${summary.justified} justificada${summary.justified === 1 ? '' : 's'}</div></div><div class="stat-icon"><i class="fas fa-user-times"></i></div></div></div></div>
        `;
    }

    function groupByMonth(days) {
        const grouped = {};
        days.forEach(day => {
            const date = String(day.fecha || '').slice(0, 10);
            const key = date.slice(0, 7);
            if (!grouped[key]) grouped[key] = [];
            grouped[key].push(day);
        });
        return grouped;
    }

    function renderMonths() {
        const days = selectedDays();
        if (!days.length) {
            months.innerHTML = '<div class="student-attendance-empty"><i class="fas fa-calendar-times d-block"></i>No hay asistencias registradas para el año seleccionado.</div>';
            caption.textContent = 'Sin registros para el filtro seleccionado.';
            return;
        }

        const grouped = groupByMonth(days);
        const keys = Object.keys(grouped).sort().reverse();
        caption.textContent = `${days.length} día${days.length === 1 ? '' : 's'} con asistencia registrada. No se generan faltas automáticas por días sin marcación.`;

        months.innerHTML = keys.map((key, index) => {
            const rows = grouped[key].slice().sort((a, b) => String(b.fecha).localeCompare(String(a.fecha)));
            const summary = summarize(rows);
            const [year, month] = key.split('-').map(Number);
            const title = `${monthNames[(month || 1) - 1]} ${year || ''}`;
            const collapseId = `attendance-month-${key.replace(/[^0-9]/g, '')}`;
            const absenceTotal = summary.absent + summary.justified;
            const tableRows = rows.map(day => {
                const date = formatDate(day.fecha);
                return `<tr>
                    <td><div class="attendance-date-main">${escapeHtml(date.main)}</div><div class="attendance-date-weekday">${escapeHtml(date.weekday)}</div></td>
                    <td class="attendance-time">${formatTime(day.entrada)}</td>
                    <td class="attendance-time">${formatTime(day.salida)}</td>
                    <td>${statusBadge(day.estado)}</td>
                </tr>`;
            }).join('');

            return `<div class="student-attendance-month">
                <div class="student-attendance-month-header">
                    <button class="student-attendance-month-toggle ${index === 0 ? '' : 'collapsed'}" type="button" data-toggle="collapse" data-target="#${collapseId}" aria-expanded="${index === 0 ? 'true' : 'false'}" aria-controls="${collapseId}">
                        <div><div class="student-attendance-month-title"><i class="far fa-calendar-alt mr-2 text-primary"></i>${escapeHtml(title)}</div><div class="student-attendance-month-meta">${rows.length} día${rows.length === 1 ? '' : 's'} registrado${rows.length === 1 ? '' : 's'}</div></div>
                        <div class="student-attendance-month-summary"><span class="student-attendance-rate"><i class="fas fa-chart-line mr-1"></i>${summary.rate.toFixed(1)}%</span><span class="badge badge-success">${summary.present} presentes</span>${summary.late ? `<span class="badge badge-warning">${summary.late} tarde${summary.late === 1 ? '' : 's'}</span>` : ''}${absenceTotal ? `<span class="badge badge-danger">${absenceTotal} ausencia${absenceTotal === 1 ? '' : 's'}</span>` : ''}</div>
                    </button>
                </div>
                <div id="${collapseId}" class="collapse ${index === 0 ? 'show' : ''}" data-parent="#attendance-months">
                    <div class="table-responsive"><table class="table table-hover table-sm student-attendance-table"><thead><tr><th>Fecha</th><th>Entrada</th><th>Salida</th><th>Estado</th></tr></thead><tbody>${tableRows}</tbody></table></div>
                </div>
            </div>`;
        }).join('');
    }

    function populateYears() {
        const summaries = Array.isArray(state.response && state.response.resumen_por_año) ? state.response.resumen_por_año : [];
        const valid = summaries.filter(row => String(row['año'] || '') !== '' && String(row['año']) !== 'Sin año');
        yearFilter.innerHTML = valid.length
            ? valid.map(row => `<option value="${escapeHtml(row['año'])}">${escapeHtml(row['año'])} · ${Number(row.total_dias || row.total_registros || 0)} días · ${Number(row.porcentaje_asistencia || 0).toFixed(1)}%</option>`).join('')
            : '<option value="">Sin años disponibles</option>';

        const active = String((state.response && state.response.anio_academico_actual) || '');
        const values = valid.map(row => String(row['año']));
        if (active && values.includes(active)) state.year = active;
        else if (values.length) state.year = values[0];
        else state.year = '';
        yearFilter.value = state.year;
    }

    function refresh() {
        renderStats();
        renderMonths();
    }

    async function load() {
        errorBox.classList.add('d-none');
        try {
            const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || payload.status !== 'ok') throw new Error(payload.message || 'No se pudo cargar el historial de asistencias.');
            state.response = payload;
            state.days = Array.isArray(payload.days) ? payload.days : [];
            populateYears();
            refresh();
        } catch (error) {
            stats.innerHTML = '';
            caption.textContent = 'No disponible.';
            months.innerHTML = '<div class="student-attendance-empty text-danger"><i class="fas fa-exclamation-triangle d-block"></i>No se pudo cargar la información.</div>';
            errorBox.textContent = error.message || 'Error al cargar las asistencias.';
            errorBox.classList.remove('d-none');
        }
    }

    yearFilter.addEventListener('change', function () {
        state.year = yearFilter.value;
        refresh();
    });

    load();
});
