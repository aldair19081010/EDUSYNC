<?php
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$student_name = $_SESSION['student_name'] ?? 'Estudiante';
$student_dni = $_SESSION['student_dni'] ?? '';
?>

<div id="student-debts-app" data-endpoint="api/my_debts.php">
    <div class="mb-3 d-flex align-items-center student-profile-bar">
        <div class="avatar-circle mr-3">
            <i class="fas fa-user-graduate"></i>
        </div>
        <div>
            <div class="text-xs text-uppercase text-muted">Estudiante</div>
            <div class="h5 mb-0 font-weight-bold text-primary"><?php echo htmlspecialchars($student_name); ?></div>
            <?php if ($student_dni !== ''): ?>
                <div class="text-muted small">DNI: <?php echo htmlspecialchars($student_dni); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-sm-flex align-items-end justify-content-between mb-3 student-debts-heading">
        <div>
            <h1 class="h4 mb-1 text-gray-800">
                <i class="fas fa-file-invoice-dollar mr-2 text-primary"></i>Mis Deudas
            </h1>
            <div class="text-muted small">Consulta tus obligaciones pendientes, pagos aplicados y vencimientos.</div>
        </div>
        <div class="student-debts-year mt-3 mt-sm-0">
            <label for="year-filter" class="small font-weight-bold text-muted mb-1">Año académico</label>
            <select id="year-filter" class="form-control form-control-sm">
                <option value="">Cargando...</option>
            </select>
        </div>
    </div>

    <div id="debt-grade-warning" class="alert alert-warning d-none student-debts-warning" role="alert"></div>
    <div id="debt-error" class="alert alert-danger d-none" role="alert"></div>

    <div class="row mb-2" id="stats-cards">
        <div class="col-12 text-center py-4 text-muted">
            <i class="fas fa-spinner fa-spin mr-2"></i>Cargando estado de cuenta...
        </div>
    </div>

    <div class="card shadow-sm mb-4 student-debts-card">
        <div class="card-header py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h6 class="m-0 font-weight-bold text-gray-800">
                    <i class="fas fa-list-ul mr-2 text-primary"></i>Obligaciones pendientes
                </h6>
                <div id="debt-table-caption" class="small text-muted mt-1">Cargando información...</div>
            </div>
            <div class="student-debt-legend small text-muted mt-2 mt-md-0">
                <span class="badge badge-danger mr-1">Vencida</span>
                <span class="badge badge-warning mr-1">Parcial</span>
                <span class="badge badge-primary">Pendiente</span>
            </div>
        </div>
        <div class="card-body p-0 p-md-3">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0" id="debtsTable">
                    <thead>
                        <tr>
                            <th>Concepto</th>
                            <th>Periodo</th>
                            <th>Estado</th>
                            <th>Vencimiento</th>
                            <th class="text-right">A pagar</th>
                            <th class="text-right">Pagado</th>
                            <th class="text-right">Saldo</th>
                        </tr>
                    </thead>
                    <tbody id="debts-tbody">
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="fas fa-spinner fa-spin mr-2"></i>Cargando deudas...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.student-profile-bar{padding:12px 14px;background:#f8f9fc;border:1px solid #e2e6f0;border-radius:12px}
.avatar-circle{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#4285f4 0%,#2a75f3 100%);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:18px;box-shadow:0 4px 10px rgba(66,133,244,.25)}
.student-debts-year{min-width:220px}.student-debts-card{border:1px solid #e4e9f2;border-radius:12px;overflow:hidden}.student-debts-card .card-header{background:#fff;border-bottom:1px solid #e9edf5}
.student-debt-stat{border:1px solid #e4e9f2;border-radius:12px;box-shadow:0 3px 10px rgba(31,45,61,.05);height:100%}.student-debt-stat .card-body{padding:1rem}.student-debt-stat small{display:block;text-transform:uppercase;font-weight:700;letter-spacing:.03em;color:#7b8499;margin-bottom:4px}.student-debt-stat strong{font-size:1.25rem;color:#253858}.student-debt-stat .stat-icon{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#eef4ff;color:#4285f4}.student-debt-stat.danger .stat-icon{background:#fff0ee;color:#e74a3b}.student-debt-stat.warning .stat-icon{background:#fff8e1;color:#d39e00}.student-debt-stat.success .stat-icon{background:#eafaf4;color:#1cc88a}
.student-debts-warning{border-left:4px solid #f6c23e}.student-debts-warning strong{color:#7b5800}
#debtsTable thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:#596579;background:#f8f9fc;border-top:0;white-space:nowrap}#debtsTable tbody td{vertical-align:middle}
.debt-concept-name{font-weight:700;color:#344767}.debt-concept-meta{font-size:.78rem;color:#858796}.debt-money{white-space:nowrap;font-variant-numeric:tabular-nums}.debt-balance{font-weight:700;color:#e74a3b}.debt-paid{color:#1cc88a}.debt-discount{font-size:.74rem;color:#1cc88a}.debt-due-overdue{color:#e74a3b;font-weight:700}
@media(max-width:767.98px){.student-debts-heading{align-items:stretch!important}.student-debts-year{width:100%;min-width:0}.student-debt-legend{display:none}#debtsTable{font-size:.86rem}#debtsTable th:nth-child(2),#debtsTable td:nth-child(2),#debtsTable th:nth-child(5),#debtsTable td:nth-child(5),#debtsTable th:nth-child(6),#debtsTable td:nth-child(6){display:none}}
</style>

<script>
(function(){
    const root=document.getElementById('student-debts-app');
    if(!root)return;

    const endpoint=root.dataset.endpoint;
    const tbody=document.getElementById('debts-tbody');
    const yearFilter=document.getElementById('year-filter');
    const stats=document.getElementById('stats-cards');
    const warning=document.getElementById('debt-grade-warning');
    const errorBox=document.getElementById('debt-error');
    const caption=document.getElementById('debt-table-caption');
    let responseData=null;
    let allDebts=[];

    function escapeHtml(value){
        return String(value??'').replace(/[&<>"']/g,function(ch){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
        });
    }
    function money(value){return 'S/ '+Number(value||0).toFixed(2)}
    function dateText(value){
        if(!value)return 'Sin fecha';
        const parts=String(value).slice(0,10).split('-');
        return parts.length===3?`${parts[2]}/${parts[1]}/${parts[0]}`:escapeHtml(value);
    }
    function statusBadge(debt){
        const state=debt.financial_status||'Pendiente';
        const map={Vencida:'danger',Parcial:'warning',Pendiente:'primary',Pagada:'success',Exonerada:'info',Suspendida:'secondary',Anulada:'dark'};
        return `<span class="badge badge-${map[state]||'secondary'}">${escapeHtml(state)}</span>`;
    }
    function selectedDebts(){
        const year=yearFilter.value;
        return year?allDebts.filter(d=>String(d.anio_academico)===year):allDebts;
    }
    function calculateSummary(debts){
        const today=new Date().toISOString().slice(0,10);
        const summary={total:0,count:debts.length,overdue:0,overdueCount:0,nextDue:null};
        debts.forEach(d=>{
            const balance=Number(d.deuda||0);
            summary.total+=balance;
            if(d.is_overdue){summary.overdue+=balance;summary.overdueCount++}
            const due=d.due_date?String(d.due_date).slice(0,10):'';
            if(due&&due>=today&&(!summary.nextDue||due<summary.nextDue))summary.nextDue=due;
        });
        return summary;
    }
    function renderStats(debts){
        const s=calculateSummary(debts);
        stats.innerHTML=`
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-debt-stat danger"><div class="card-body d-flex align-items-center justify-content-between"><div><small>Saldo pendiente</small><strong>${money(s.total)}</strong></div><div class="stat-icon"><i class="fas fa-wallet"></i></div></div></div></div>
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-debt-stat"><div class="card-body d-flex align-items-center justify-content-between"><div><small>Obligaciones</small><strong>${s.count}</strong></div><div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div></div></div></div>
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-debt-stat warning"><div class="card-body d-flex align-items-center justify-content-between"><div><small>Saldo vencido</small><strong>${money(s.overdue)}</strong><div class="small text-muted">${s.overdueCount} vencida${s.overdueCount===1?'':'s'}</div></div><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div></div></div></div>
            <div class="col-xl-3 col-md-6 mb-3"><div class="card student-debt-stat success"><div class="card-body d-flex align-items-center justify-content-between"><div><small>Próximo vencimiento</small><strong>${s.nextDue?dateText(s.nextDue):'—'}</strong></div><div class="stat-icon"><i class="fas fa-calendar-check"></i></div></div></div></div>
        `;
    }
    function renderTable(debts){
        if(!debts.length){
            tbody.innerHTML='<tr><td colspan="7" class="text-center text-success py-4"><i class="fas fa-check-circle mr-2"></i>No tienes deudas pendientes en este periodo.</td></tr>';
            caption.textContent='Sin obligaciones pendientes para el filtro seleccionado.';
            return;
        }
        tbody.innerHTML=debts.map(debt=>{
            const period=debt.billing_period?escapeHtml(debt.billing_period):`Año ${escapeHtml(debt.anio_academico||'—')}`;
            const discount=Number(debt.discount_amount||0)>0?`<div class="debt-discount">Descuento: ${money(debt.discount_amount)}</div>`:'';
            const dueClass=debt.is_overdue?'debt-due-overdue':'text-muted';
            return `<tr>
                <td><div class="debt-concept-name">${escapeHtml(debt.concepto)}</div><div class="debt-concept-meta">${escapeHtml(debt.anio_academico||'Sin año')}${debt.nivel?' · '+escapeHtml(debt.nivel):''}</div></td>
                <td>${period}</td>
                <td>${statusBadge(debt)}</td>
                <td class="${dueClass}">${dateText(debt.due_date)}</td>
                <td class="text-right debt-money">${money(debt.amount_to_pay)}${discount}</td>
                <td class="text-right debt-money debt-paid">${money(debt.pagado)}</td>
                <td class="text-right debt-money debt-balance">${money(debt.deuda)}</td>
            </tr>`;
        }).join('');
        caption.textContent=`${debts.length} obligación${debts.length===1?'':'es'} pendiente${debts.length===1?'':'s'} en el filtro seleccionado.`;
    }
    function renderWarning(){
        const globalSummary=responseData?.summary||{};
        if(responseData?.block_grades){
            warning.classList.remove('d-none');
            warning.innerHTML=`<i class="fas fa-lock mr-2"></i><strong>Tienes ${Number(globalSummary.count_concepts||0)} obligaciones pendientes.</strong> Tu saldo total pendiente es ${money(globalSummary.total_debt)}. Regulariza tu situación para volver a consultar Mis Notas.`;
        }else{
            warning.classList.add('d-none');
            warning.innerHTML='';
        }
    }
    function populateYears(){
        const years=(responseData?.resumen_por_año||[]).map(y=>String(y['año']));
        const active=String(responseData?.anio_academico_actual||'');
        let html='<option value="">Todos los años</option>';
        (responseData?.resumen_por_año||[]).forEach(y=>{
            html+=`<option value="${escapeHtml(y['año'])}">${escapeHtml(y['año'])} · ${Number(y.conceptos||0)} pend. · ${money(y.total_deuda)}</option>`;
        });
        yearFilter.innerHTML=html;
        if(active&&years.includes(active))yearFilter.value=active;
        else if(years.length)yearFilter.value=years[0];
    }
    function refresh(){
        const debts=selectedDebts();
        renderStats(debts);
        renderTable(debts);
    }
    async function load(){
        errorBox.classList.add('d-none');
        try{
            const res=await fetch(endpoint,{credentials:'same-origin',headers:{'Accept':'application/json'}});
            const data=await res.json();
            if(!res.ok||data.status!=='ok')throw new Error(data.message||'No se pudo cargar el estado de cuenta.');
            responseData=data;
            allDebts=Array.isArray(data.data)?data.data:[];
            populateYears();
            renderWarning();
            refresh();
        }catch(err){
            errorBox.textContent=err.message||'Error al cargar las deudas.';
            errorBox.classList.remove('d-none');
            stats.innerHTML='';
            tbody.innerHTML='<tr><td colspan="7" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle mr-2"></i>No se pudo cargar la información.</td></tr>';
            caption.textContent='No disponible.';
        }
    }
    yearFilter.addEventListener('change',refresh);
    load();
})();
</script>
