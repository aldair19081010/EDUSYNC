document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('student-payments-app');
    if (!root) return;

    const endpoint = root.dataset.endpoint || 'api/my_payments.php';
    const stats = document.getElementById('student-payments-stats');
    const list = document.getElementById('student-payments-list');
    const yearSelect = document.getElementById('student-payments-year');
    const alertBox = document.getElementById('student-payments-alert');
    const caption = document.getElementById('student-payments-caption');

    const state = { response: null, payments: [], year: '' };

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function money(value) {
        return 'S/ ' + Number(value || 0).toFixed(2);
    }

    function dateValue(value) {
        const text = String(value || '').trim();
        if (!text) return null;
        const normalized = text.indexOf('T') >= 0 ? text : text.replace(' ', 'T');
        const date = new Date(normalized);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatDate(value) {
        const date = dateValue(value);
        return date
            ? date.toLocaleDateString('es-PE', { day: '2-digit', month: 'short', year: 'numeric' })
            : 'Sin fecha';
    }

    function yearsOf(payment) {
        if (Array.isArray(payment.anios_academicos)) return payment.anios_academicos.map(String);
        return payment.anio_academico ? [String(payment.anio_academico)] : [];
    }

    function filteredPayments() {
        if (!state.year) return state.payments.slice();
        return state.payments.filter(function (payment) {
            return yearsOf(payment).indexOf(String(state.year)) >= 0;
        });
    }

    function debtForSelection() {
        if (!state.response) return 0;
        if (!state.year) return Number((state.response.debt_summary || {}).total_debt || 0);

        const rows = Array.isArray(state.response.debt_summary_by_year)
            ? state.response.debt_summary_by_year
            : [];
        const found = rows.find(function (row) {
            return String(row.año) === String(state.year);
        });
        return found ? Number(found.total_deuda || 0) : 0;
    }

    function statsForSelection() {
        const rows = filteredPayments();
        let amount = 0;
        let last = null;

        rows.forEach(function (payment) {
            if (state.year && payment.year_amounts && Object.prototype.hasOwnProperty.call(payment.year_amounts, state.year)) {
                amount += Number(payment.year_amounts[state.year] || 0);
            } else {
                amount += Number(payment.monto_contabilizado || payment.monto || 0);
            }

            const current = dateValue(payment.fecha);
            if (current && (!last || current > last)) last = current;
        });

        return {
            amount: amount,
            operations: rows.length,
            last: last,
            debt: debtForSelection()
        };
    }

    function renderStats() {
        const current = statsForSelection();
        const lastText = current.last
            ? current.last.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric' })
            : 'Sin pagos';

        stats.innerHTML = `
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-payments-stat success"><div class="card-body d-flex align-items-center justify-content-between"><div><span class="student-payments-stat-label">Total pagado</span><div class="student-payments-stat-value">${money(current.amount)}</div></div><div class="student-payments-stat-icon"><i class="fas fa-coins"></i></div></div></div></div>
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-payments-stat"><div class="card-body d-flex align-items-center justify-content-between"><div><span class="student-payments-stat-label">Comprobantes</span><div class="student-payments-stat-value">${current.operations}</div></div><div class="student-payments-stat-icon"><i class="fas fa-receipt"></i></div></div></div></div>
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-payments-stat"><div class="card-body d-flex align-items-center justify-content-between"><div><span class="student-payments-stat-label">Último pago</span><div class="student-payments-stat-value">${escapeHtml(lastText)}</div></div><div class="student-payments-stat-icon"><i class="fas fa-calendar-check"></i></div></div></div></div>
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-payments-stat warning"><div class="card-body d-flex align-items-center justify-content-between"><div><span class="student-payments-stat-label">Saldo pendiente</span><div class="student-payments-stat-value">${money(current.debt)}</div></div><div class="student-payments-stat-icon"><i class="fas fa-file-invoice-dollar"></i></div></div></div></div>
        `;
    }

    function renderAlert() {
        if (!state.response) return;
        const debt = Number((state.response.debt_summary || {}).total_debt || 0);
        const count = Number((state.response.debt_summary || {}).count_concepts || 0);

        if (count <= 0 || debt <= 0) {
            alertBox.className = 'alert d-none';
            alertBox.innerHTML = '';
            return;
        }

        alertBox.className = 'alert alert-info';
        alertBox.innerHTML = `<i class="fas fa-info-circle mr-2"></i>Tienes <strong>${count}</strong> obligación${count === 1 ? '' : 'es'} pendiente${count === 1 ? '' : 's'} por <strong>${money(debt)}</strong>. <a href="index.php?page=student_debts">Ver Mis Deudas</a>.`;
    }

    function receiptButton(payment) {
        const operationId = Number(payment.operation_id || 0);
        const pid = Number(payment.pid || 0);
        const efId = Number(payment.ef_id || 0);
        return `<button type="button" class="btn btn-sm btn-outline-primary student-payment-view" data-operation-id="${operationId}" data-pid="${pid}" data-ef-id="${efId}"><i class="fas fa-file-invoice mr-1"></i>Ver recibo</button>`;
    }

    function renderList() {
        const rows = filteredPayments();

        if (!rows.length) {
            list.innerHTML = '<div class="student-payments-empty"><i class="fas fa-receipt d-block"></i>No hay pagos confirmados en este periodo.</div>';
            caption.textContent = 'Sin pagos confirmados para el filtro seleccionado.';
            return;
        }

        list.innerHTML = rows.map(function (payment) {
            const concepts = Array.isArray(payment.conceptos) && payment.conceptos.length
                ? payment.conceptos.join(' + ')
                : (payment.concepto || 'Concepto de pago');
            const years = yearsOf(payment);
            const yearText = years.length ? years.join(', ') : 'Sin año académico';
            const methods = payment.medio_pago || (Array.isArray(payment.metodos_pago)
                ? payment.metodos_pago.map(function (item) { return item.nombre; }).filter(Boolean).join(' + ')
                : '');

            let amountNote = 'Pago confirmado';
            if (state.year && years.length > 1 && payment.year_amounts && Object.prototype.hasOwnProperty.call(payment.year_amounts, state.year)) {
                amountNote = `Aplicado a ${escapeHtml(state.year)}: ${money(payment.year_amounts[state.year])}`;
            }

            return `
                <div class="student-payment-item">
                    <div>
                        <div class="student-payment-date">${escapeHtml(formatDate(payment.fecha))}</div>
                        <div class="student-payment-receipt">${escapeHtml(payment.recibo || 'Sin número')}</div>
                        <span class="student-payment-badge student-payment-badge-confirmado">Confirmado</span>
                    </div>
                    <div>
                        <div class="student-payment-concepts">${escapeHtml(concepts)}</div>
                        <div class="student-payment-years"><i class="far fa-calendar-alt mr-1"></i>${escapeHtml(yearText)}${payment.legacy ? ' · Registro histórico' : ''}</div>
                    </div>
                    <div class="student-payment-methods"><i class="fas fa-wallet mr-1 text-muted"></i>${methods ? escapeHtml(methods) : '<span class="text-muted">Medio no especificado</span>'}</div>
                    <div class="student-payment-amount"><strong>${money(payment.monto)}</strong><small>${amountNote}</small></div>
                    <div class="student-payment-actions">${receiptButton(payment)}</div>
                </div>
            `;
        }).join('');

        caption.textContent = `${rows.length} comprobante${rows.length === 1 ? '' : 's'} confirmado${rows.length === 1 ? '' : 's'} en el filtro seleccionado.`;
    }

    function populateYears() {
        const years = Array.isArray(state.response.resumen_por_año) ? state.response.resumen_por_año : [];
        yearSelect.innerHTML = '<option value="">Todos los años</option>' + years.map(function (row) {
            return `<option value="${escapeHtml(row.año)}">${escapeHtml(row.año)} · ${Number(row.total_pagos || 0)} pago${Number(row.total_pagos || 0) === 1 ? '' : 's'} · ${money(row.monto_total)}</option>`;
        }).join('');

        if (years.length) {
            state.year = String(years[0].año);
            yearSelect.value = state.year;
        }
    }

    function renderAll() {
        renderStats();
        renderAlert();
        renderList();
    }

    async function loadPayments() {
        try {
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const payload = await response.json();

            if (!response.ok || payload.status !== 'ok') {
                throw new Error(payload.message || 'No se pudo cargar el historial de pagos.');
            }

            state.response = payload;
            state.payments = Array.isArray(payload.data) ? payload.data : [];
            populateYears();
            renderAll();
        } catch (error) {
            stats.innerHTML = '';
            caption.textContent = 'No disponible.';
            list.innerHTML = `<div class="student-payments-empty text-danger"><i class="fas fa-exclamation-triangle d-block"></i>${escapeHtml(error.message || 'Error al cargar los pagos.')}</div>`;
        }
    }

    yearSelect.addEventListener('change', function () {
        state.year = yearSelect.value;
        renderAll();
    });

    list.addEventListener('click', function (event) {
        const button = event.target.closest('.student-payment-view');
        if (!button) return;

        const operationId = Number(button.dataset.operationId || 0);
        const pid = Number(button.dataset.pid || 0);
        const efId = Number(button.dataset.efId || 0);
        let url = 'receipt.php?';

        if (operationId > 0) {
            url += 'operation_id=' + encodeURIComponent(operationId);
        } else {
            url += 'ef_id=' + encodeURIComponent(efId) + '&pid=' + encodeURIComponent(pid);
        }

        if (typeof window.uni_modal === 'function') {
            window.uni_modal('Recibo de Pago', url, 'large');
        } else {
            window.location.href = url;
        }
    });

    loadPayments();
});
