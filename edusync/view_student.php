<?php
include_once 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    echo '<div class="alert alert-danger">ID de estudiante inválido.</div>';
    exit;
}

$school_id = $_SESSION['login_school_id'] ?? 0;
if ((int)$school_id <= 0) {
    echo '<div class="alert alert-danger">No se pudo determinar el colegio del usuario.</div>';
    exit;
}
$q = $conn->prepare('SELECT * FROM student WHERE id = ? AND school_id = ? LIMIT 1');
$q->bind_param('ii', $id, $school_id);
$q->execute();
$res = $q->get_result();
if (!$res || $res->num_rows === 0) {
    echo '<div class="alert alert-warning">Estudiante no encontrado.</div>';
    exit;
}
$s = $res->fetch_assoc();

$academic_year_name = 'Sin año asignado';
if (!empty($s['academic_year_id'])) {
    $year_q = $conn->prepare('SELECT year, description FROM academic_year WHERE id = ? AND school_id = ? LIMIT 1');
    if ($year_q) {
        $year_q->bind_param('ii', $s['academic_year_id'], $school_id);
        $year_q->execute();
        $year_res = $year_q->get_result();
        if ($year_res && $year_res->num_rows > 0) {
            $year_row = $year_res->fetch_assoc();
            $academic_year_name = $year_row['year'] . (!empty($year_row['description']) ? ' - ' . $year_row['description'] : '');
        }
        $year_q->close();
    }
}

$attendance_summary = ['total' => 0, 'presente' => 0, 'ausente' => 0, 'tardanza' => 0];
$attendance_q = $conn->prepare("SELECT COUNT(*) AS total, SUM(estado IN ('Temprano', 'Normal', 'Tarde', 'Presente')) AS presente, SUM(estado LIKE 'Ausente%') AS ausente, SUM(estado = 'Tarde') AS tardanza FROM asistencia WHERE student_id = ? AND tipo = 'Entrada'");
if ($attendance_q) {
    $attendance_q->bind_param('i', $id);
    $attendance_q->execute();
    $attendance_row = $attendance_q->get_result()->fetch_assoc();
    if ($attendance_row) $attendance_summary = array_map('intval', $attendance_row);
    $attendance_q->close();
}

$financial_summary = ['conceptos' => 0, 'total' => 0.0, 'pagado' => 0.0];
$financial_q = $conn->prepare('SELECT COUNT(ef.id) AS conceptos, COALESCE(SUM(COALESCE(ef.discounted_amount, ef.total_fee)), 0) AS total, COALESCE(SUM((SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.ef_id = ef.id)), 0) AS pagado FROM student_ef_list ef WHERE ef.student_id = ?');
if ($financial_q) {
    $financial_q->bind_param('i', $id);
    $financial_q->execute();
    $financial_row = $financial_q->get_result()->fetch_assoc();
    if ($financial_row) {
        $financial_summary['conceptos'] = (int)$financial_row['conceptos'];
        $financial_summary['total'] = (float)$financial_row['total'];
        $financial_summary['pagado'] = (float)$financial_row['pagado'];
    }
    $financial_q->close();
}
$financial_summary['saldo'] = max(0, $financial_summary['total'] - $financial_summary['pagado']);

$academic_history = [];
$history_q = $conn->prepare('SELECT h.*, COALESCE(u.name, "Usuario eliminado") AS user_name, ay.year AS academic_year FROM student_academic_history h LEFT JOIN users u ON u.id = h.user_id LEFT JOIN academic_year ay ON ay.id = h.academic_year_id WHERE h.student_id = ? AND h.school_id = ? ORDER BY h.created_at DESC, h.id DESC');
if ($history_q) {
    $history_q->bind_param('ii', $id, $school_id);
    $history_q->execute();
    $history_result = $history_q->get_result();
    while ($history_row = $history_result->fetch_assoc()) $academic_history[] = $history_row;
    $history_q->close();
}

// Obtener datos del colegio desde BD (tabla schools)
$school_name = 'Institución Educativa';
$logo_path = '';
if (!empty($school_id)) {
    $school_q = $conn->prepare("SELECT name, logo_path, address FROM schools WHERE id = ? LIMIT 1");
    $school_q->bind_param('i', $school_id);
    $school_q->execute();
    $school_res = $school_q->get_result();
    if ($school_res && $school_res->num_rows > 0) {
        $school_row = $school_res->fetch_assoc();
        $school_name = $school_row['name'] ?: $school_name;
        if (!empty($school_row['logo_path']) && file_exists($school_row['logo_path'])) {
            $logo_path = $school_row['logo_path'];
        }
    }
}
?>
<style>
.student-profile {
    padding: 10px 5px;
}
.student-header { display: flex; align-items: center; margin-bottom: 12px; }
.student-avatar {
    width: 70px; height: 70px; border-radius: 50%;
    background: #f1f3f5; display: flex; align-items: center; justify-content: center;
    font-size: 28px; color: #6c757d; margin-right: 12px;
}
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 576px) { .info-grid { grid-template-columns: 1fr; } }
.info-item { background:#f8f9fa; border:1px solid #e9ecef; border-radius:6px; padding:10px; }
.info-item small { color:#6c757d; font-weight:600; display:block; margin-bottom:4px; }
.badge-soft { padding:4px 8px; border-radius:12px; font-size:12px; font-weight:600; }
.badge-inicial { background:#fff3cd; color:#856404; }
.badge-primaria { background:#d4edda; color:#155724; }
.badge-secundaria { background:#cce5ff; color:#004085; }
.badge-estado { background:#e2e3e5; color:#383d41; }
.profile-summary-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:12px; }
.summary-panel { border:1px solid #e9ecef; border-radius:6px; padding:10px; background:#fff; }
.summary-title { font-weight:700; color:#2c4964; border-bottom:1px solid #e9ecef; padding-bottom:7px; margin-bottom:8px; }
.summary-title i { margin-right:6px; }
.summary-values { display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; text-align:center; }
.summary-values strong { display:block; font-size:1rem; }
.summary-values small { color:#6c757d; font-size:10px; }
.financial-values { grid-template-columns:repeat(4, 1fr); }
.history-panel { margin-top:12px; border:1px solid #e9ecef; border-radius:6px; padding:10px; background:#fff; }
.history-table { width:100%; font-size:12px; }
.history-table th { color:#6c757d; font-weight:700; border-bottom:1px solid #dee2e6; padding:6px; }
.history-table td { border-bottom:1px solid #f1f3f5; padding:6px; }
@media (max-width: 576px) {
    .profile-summary-grid { grid-template-columns:1fr; }
    .summary-values strong { font-size:.9rem; }
}

/* Estilos para impresión */
@media print {
    body * { visibility: hidden; }
    #printable-area, #printable-area * { visibility: visible; }
    #printable-area { position: absolute; left: 0; top: 0; width: 100%; }
    #btn_print_profile { display: none !important; }
    .summary-panel { break-inside: avoid; }
}
.print-header { display:flex; align-items:center; justify-content:space-between; border-bottom:2px solid #e9ecef; padding-bottom:10px; margin-bottom:12px; }
.print-header-left { display:flex; align-items:center; min-width:0; }
.print-header .logo { width:64px; height:64px; border-radius:6px; margin-right:12px; background:#f1f3f5; display:flex; align-items:center; justify-content:center; }
.print-header h4 { margin:0; color:#2c4964; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.print-header small { color:#6c757d; }
.print-footer { margin-top:14px; border-top:1px dashed #dee2e6; padding-top:8px; color:#6c757d; font-size:12px; display:flex; justify-content:space-between; }
</style>

<div class="student-profile" id="printable-area">
    <div class="print-header">
        <div class="print-header-left">
            <div class="logo">
                <?php if ($logo_path): ?>
                    <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="Logo" style="max-width:100%; max-height:100%;" />
                <?php else: ?>
                    <i class="fa fa-school" style="font-size:28px; color:#6c757d;"></i>
                <?php endif; ?>
            </div>
            <div>
                <h4><?php echo htmlspecialchars($school_name); ?></h4>
                <small>Ficha del Estudiante • Fecha: <?php echo date('d/m/Y H:i'); ?></small>
            </div>
        </div>
        <div>
            <button type="button" id="btn_print_profile" class="btn btn-sm btn-light" style="border:1px solid #e9ecef;">
                <i class="fa fa-print mr-1"></i> Imprimir
            </button>
        </div>
    </div>
    <div class="student-header">
        <div class="student-avatar">
            <i class="fa fa-user"></i>
        </div>
        <div>
            <div style="font-size:1.1rem; font-weight:600; color:#2c4964;">
                <?php echo htmlspecialchars($s['name']); ?>
            </div>
            <div>
                <span class="badge badge-soft <?php 
                    echo ($s['nivel']=='Inicial'?'badge-inicial':($s['nivel']=='Primaria'?'badge-primaria':($s['nivel']=='Secundaria'?'badge-secundaria':'badge-estado'))); 
                ?>"><i class="fas fa-school mr-1"></i><?php echo htmlspecialchars($s['nivel'] ?: 'N/A'); ?></span>
                <span class="badge badge-soft badge-estado">Grado: <?php echo htmlspecialchars($s['grado'] ?: 'N/A'); ?></span>
                <span class="badge badge-soft badge-estado">Sección: <?php echo htmlspecialchars($s['seccion'] ?: '-'); ?></span>
                <span class="badge badge-soft badge-estado">Año: <?php echo htmlspecialchars($academic_year_name); ?></span>
                <span class="badge badge-soft badge-estado">Estado: <?php echo htmlspecialchars($s['status'] ?: 'Activo'); ?></span>
                <?php
                    $genero_val = $s['genero'] ?? '';
                    if ($genero_val === 'Masculino') {
                        echo '<span class="badge badge-soft" style="background:#cce5ff;color:#004085;"><i class="fas fa-mars mr-1"></i>Masculino</span>';
                    } elseif ($genero_val === 'Femenino') {
                        echo '<span class="badge badge-soft" style="background:#f8d7da;color:#721c24;"><i class="fas fa-venus mr-1"></i>Femenino</span>';
                    } elseif ($genero_val === 'Otro') {
                        echo '<span class="badge badge-soft" style="background:#e2e3e5;color:#383d41;"><i class="fas fa-transgender mr-1"></i>Otro</span>';
                    }
                ?>
            </div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-item">
            <small>DNI</small>
            <div><?php echo htmlspecialchars($s['id_no'] ?: 'N/A'); ?></div>
        </div>
        <div class="info-item">
            <small>Género</small>
            <div>
            <?php
                $gv = $s['genero'] ?? '';
                if ($gv === 'Masculino')      echo '<i class="fas fa-mars text-primary mr-1"></i>Masculino';
                elseif ($gv === 'Femenino')   echo '<i class="fas fa-venus" style="color:#c0392b" ></i> Femenino';
                elseif ($gv === 'Otro')       echo '<i class="fas fa-transgender text-secondary mr-1"></i>Otro';
                else                          echo '<span class="text-muted">N/A</span>';
            ?>
            </div>
        </div>
        <div class="info-item">
            <small>Correo</small>
            <div><?php echo htmlspecialchars($s['email'] ?: 'N/A'); ?></div>
        </div>
        <div class="info-item">
            <small>Teléfono</small>
            <div><?php echo htmlspecialchars($s['contact'] ?: 'N/A'); ?></div>
        </div>
        <div class="info-item">
            <small>Dirección</small>
            <div><?php echo htmlspecialchars($s['address'] ?: 'N/A'); ?></div>
        </div>
        <?php if (!empty($s['tutor1_nombre']) || !empty($s['tutor1_apellido']) || !empty($s['tutor1_telefono'])): ?>
        <div class="info-item">
            <small>Tutor Principal</small>
            <div><?php echo htmlspecialchars(trim(($s['tutor1_nombre'] ?? '') . ' ' . ($s['tutor1_apellido'] ?? '')) ?: 'N/A'); ?></div>
        </div>
        <div class="info-item">
            <small>Contacto y parentesco</small>
            <div><?php echo htmlspecialchars($s['tutor1_telefono'] ?: 'N/A'); ?> · <?php echo htmlspecialchars($s['tutor1_relacion'] ?: 'Sin especificar'); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($s['tutor2_nombre']) || !empty($s['tutor2_apellido']) || !empty($s['tutor2_telefono'])): ?>
        <div class="info-item">
            <small>Tutor Secundario</small>
            <div><?php echo htmlspecialchars(trim(($s['tutor2_nombre'] ?? '') . ' ' . ($s['tutor2_apellido'] ?? '')) ?: 'N/A'); ?></div>
        </div>
        <div class="info-item">
            <small>Contacto y parentesco</small>
            <div><?php echo htmlspecialchars($s['tutor2_telefono'] ?: 'N/A'); ?> · <?php echo htmlspecialchars($s['tutor2_relacion'] ?: 'Sin especificar'); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="profile-summary-grid">
        <div class="summary-panel">
            <div class="summary-title"><i class="fas fa-calendar-check text-success"></i> Asistencia</div>
            <div class="summary-values">
                <div><strong><?php echo $attendance_summary['total']; ?></strong><small>Registros</small></div>
                <div class="text-success"><strong><?php echo $attendance_summary['presente']; ?></strong><small>Presentes</small></div>
                <div class="text-danger"><strong><?php echo $attendance_summary['ausente']; ?></strong><small>Ausencias</small></div>
                <div class="text-warning"><strong><?php echo $attendance_summary['tardanza']; ?></strong><small>Tardanzas</small></div>
            </div>
        </div>
        <div class="summary-panel">
            <div class="summary-title"><i class="fas fa-wallet text-primary"></i> Pagos</div>
            <div class="summary-values financial-values">
                <div><strong><?php echo $financial_summary['conceptos']; ?></strong><small>Conceptos</small></div>
                <div><strong>S/ <?php echo number_format($financial_summary['total'], 2); ?></strong><small>Total</small></div>
                <div class="text-success"><strong>S/ <?php echo number_format($financial_summary['pagado'], 2); ?></strong><small>Pagado</small></div>
                <div class="text-danger"><strong>S/ <?php echo number_format($financial_summary['saldo'], 2); ?></strong><small>Saldo</small></div>
            </div>
        </div>
    </div>

    <?php if (!empty($academic_history)): ?>
    <div class="history-panel">
        <div class="summary-title"><i class="fas fa-history text-secondary"></i> Historial académico</div>
        <div class="table-responsive">
            <table class="history-table">
                <thead><tr><th>Fecha</th><th>Año</th><th>Nivel</th><th>Grado</th><th>Sección</th><th>Estado</th><th>Usuario</th></tr></thead>
                <tbody>
                <?php foreach ($academic_history as $history): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($history['created_at']))); ?></td>
                        <td><?php echo htmlspecialchars($history['academic_year'] ?: 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($history['nivel']); ?></td>
                        <td><?php echo htmlspecialchars($history['grado']); ?></td>
                        <td><?php echo htmlspecialchars($history['seccion'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($history['status']); ?></td>
                        <td><?php echo htmlspecialchars($history['user_name']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="print-footer">
        <span>Generado por el sistema</span>
        <span><?php echo htmlspecialchars($s['name']); ?> • DNI: <?php echo htmlspecialchars($s['id_no'] ?: 'N/A'); ?></span>
    </div>
</div>

<script>
// Ocultar los botones globales del modal (Guardar/Cancelar) al usar vista de solo lectura
(function(){
    try {
        var $m = typeof $ !== 'undefined' ? $('#uni_modal') : null;
        if ($m && $m.length) {
            $m.find('.modal-footer, .card-footer').hide();
        }
    } catch(_){}
})();

// Imprimir solo la ficha del estudiante
(function(){
    var btn = document.getElementById('btn_print_profile');
    if (!btn) return;
    btn.addEventListener('click', function(){
        try {
            window.print();
        } catch (e) {
            // Fallback: abrir nueva ventana para imprimir
            var content = document.getElementById('printable-area');
            var w = window.open('', '_blank');
            w.document.write('<html><head><title>Ficha de Estudiante</title>');
            w.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">');
            // Copiar estilos locales de esta vista
            var styleTag = document.querySelector('style');
            if (styleTag) w.document.write('<style>' + styleTag.innerHTML + '</style>');
            w.document.write('</head><body>');
            w.document.write(content.outerHTML);
            w.document.write('</body></html>');
            w.document.close();
            w.focus();
            w.print();
            w.close();
        }
    });
})();
</script>
