
<?php
// Configuración de sesión consistente
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
include 'db_connect.php';

$login_type = $_SESSION['login_type'] ?? null;
if (!$login_type || !in_array((int)$login_type, [1,2,3])) {
    echo "<div class='alert alert-danger'>No autorizado para ver este reporte.</div>";
    exit;
}

// Filtros
$student_id = $_POST['student_id'] ?? '';
$date_from = $_POST['date_from'] ?? date('Y-m-01');
$date_to = $_POST['date_to'] ?? date('Y-m-d');
$nivel = $_POST['nivel'] ?? '';
$grado = $_POST['grado'] ?? '';
$seccion = $_POST['seccion'] ?? '';
$tipo = $_POST['tipo'] ?? '';
$estado = $_POST['estado'] ?? '';

// Construcción de consulta con prepared statements
$school_id = intval($_SESSION['login_school_id'] ?? 0);
$where_main = " AND s.status = 'Activo' AND s.school_id = ?";
$where_summary = " AND s.status = 'Activo' AND s.school_id = ?";
$params_main = [];
$params_summary = [];
$types_main = '';
$types_summary = '';

$params_main[] = $school_id;
$params_summary[] = $school_id;
$types_main .= 'i';
$types_summary .= 'i';

if (!empty($student_id)) {
    $where_main .= " AND a.student_id = ?";
    $where_summary .= " AND a.student_id = ?";
    $params_main[] = (int)$student_id;
    $params_summary[] = (int)$student_id;
    $types_main .= 'i';
    $types_summary .= 'i';
}
if (!empty($date_from) && !empty($date_to)) {
    $where_main .= " AND a.fecha BETWEEN ? AND ?";
    $where_summary .= " AND a.fecha BETWEEN ? AND ?";
    $params_main[] = $date_from;
    $params_main[] = $date_to;
    $params_summary[] = $date_from;
    $params_summary[] = $date_to;
    $types_main .= 'ss';
    $types_summary .= 'ss';
}
if (!empty($nivel)) {
    $where_main .= " AND s.nivel = ?";
    $where_summary .= " AND s.nivel = ?";
    $params_main[] = $nivel;
    $params_summary[] = $nivel;
    $types_main .= 's';
    $types_summary .= 's';
}
if (!empty($grado)) {
    $where_main .= " AND s.grado = ?";
    $where_summary .= " AND s.grado = ?";
    $params_main[] = $grado;
    $params_summary[] = $grado;
    $types_main .= 's';
    $types_summary .= 's';
}
if (!empty($seccion)) {
    $where_main .= " AND s.seccion = ?";
    $where_summary .= " AND s.seccion = ?";
    $params_main[] = $seccion;
    $params_summary[] = $seccion;
    $types_main .= 's';
    $types_summary .= 's';
}
if (!empty($tipo)) {
    $where_main .= " AND a.tipo = ?";
    $where_summary .= " AND a.tipo = ?";
    $params_main[] = $tipo;
    $params_summary[] = $tipo;
    $types_main .= 's';
    $types_summary .= 's';
}
// Solo aplicar filtro de estado a consulta principal, NO al resumen
if (!empty($estado)) {
    $where_main .= " AND a.estado = ?";
    $params_main[] = $estado;
    $types_main .= 's';
}

// Consulta de datos principales
$sql_main = "SELECT a.id, a.student_id, a.fecha, a.hora, a.tipo, a.estado, s.name, s.id_no, s.nivel, s.grado, s.seccion
             FROM asistencia a
             LEFT JOIN student s ON a.student_id = s.id
             WHERE 1=1 $where_main
             ORDER BY s.grado ASC, s.name ASC, a.fecha DESC, a.hora ASC";

$stmt_main = $conn->prepare($sql_main);
$rows = [];
if ($stmt_main) {
    if (!empty($params_main)) {
        $stmt_main->bind_param($types_main, ...$params_main);
    }
    $stmt_main->execute();
    $res_main = $stmt_main->get_result();
    while ($r = $res_main->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt_main->close();
}

// Consulta de resumen (sin filtro de estado)
$sql_summary = "SELECT 
    SUM(CASE WHEN a.estado = 'Ausente' THEN 1 ELSE 0 END) AS ausentes,
    SUM(CASE WHEN a.estado = 'Tarde' THEN 1 ELSE 0 END) AS tardanzas,
    COUNT(*) AS total
FROM asistencia a
LEFT JOIN student s ON a.student_id = s.id
WHERE 1=1 $where_summary";

$stmt_summary = $conn->prepare($sql_summary);
if ($stmt_summary) {
    if (!empty($params_summary)) {
        $stmt_summary->bind_param($types_summary, ...$params_summary);
    }
    $stmt_summary->execute();
    $res_summary = $stmt_summary->get_result();
    $summary = $res_summary->fetch_assoc();
    $stmt_summary->close();
} else {
    $summary = ['ausentes' => 0, 'tardanzas' => 0, 'total' => 0];
}

$ausentes = (int)($summary['ausentes'] ?? 0);
$tardanzas = (int)($summary['tardanzas'] ?? 0);
$total = (int)($summary['total'] ?? 0);
$presentes = max($total - $ausentes, 0);

function badge_class($estado) {
    switch ($estado) {
        case 'Temprano': return 'badge-success';
        case 'Normal': return 'badge-primary';
        case 'Presente': return 'badge-info';
        case 'Tarde': return 'badge-warning';
        case 'Ausente': return 'badge-danger';
        case 'Ausente Justificada': return 'badge-secondary';
        default: return 'badge-light';
    }
}
?>

<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-left-danger shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Inasistencias</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($ausentes); ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-times-circle fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-left-warning shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Tardanzas</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($tardanzas); ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-clock fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Registros (Periodo)</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total); ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-calendar-alt fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Presentes (estimado)</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($presentes); ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-check-circle fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<small class="text-muted d-block mb-3"><i class="fa fa-info-circle mr-1"></i>Nota: Los totales ignoran el filtro "Estado" para mostrar un panorama completo según los demás filtros.</small>

<div class="table-responsive">
    <table class="table table-hover table-bordered table-sm" id="attendanceReportData" style="width:100%;">
        <thead class="thead-light">
            <tr>
                <th>#</th>
                <th>ID Estudiante</th>
                <th>Estudiante</th>
                <th>Nivel</th>
                <th>Grado</th>
                <th>Sección</th>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Tipo</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $i = 1;
            if (count($rows) > 0):
                foreach ($rows as $row):
                    $date_created = date('d/m/Y', strtotime($row['fecha']));
                    $hora = date('H:i', strtotime($row['hora']));
            ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars($row['id_no']); ?></td>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo htmlspecialchars($row['nivel']); ?></td>
                <td><?php echo htmlspecialchars($row['grado']); ?></td>
                <td><?php echo htmlspecialchars($row['seccion']); ?></td>
                <td><?php echo htmlspecialchars($date_created); ?></td>
                <td><?php echo htmlspecialchars($hora); ?></td>
                <td><?php echo htmlspecialchars($row['tipo']); ?></td>
                <td><span class="badge <?php echo badge_class($row['estado']); ?>"><?php echo htmlspecialchars($row['estado']); ?></span></td>
            </tr>
            <?php
                endforeach;
            else:
            ?>
            <tr>
                <td colspan="10" class="text-center text-muted py-4"><i class="fa fa-info-circle mr-2"></i>No se encontraron registros</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
(function() {
    if ($.fn.dataTable.isDataTable('#attendanceReportData')) {
        $('#attendanceReportData').DataTable().destroy();
    }
    $('#attendanceReportData').DataTable({
        language: {
            emptyTable: 'No hay registros de asistencia.',
            info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
            infoEmpty: 'Mostrando 0 a 0 de 0 registros',
            infoFiltered: '(filtrado de _MAX_ registros en total)',
            lengthMenu: 'Mostrar _MENU_ registros',
            loadingRecords: 'Cargando...',
            processing: 'Procesando...',
            search: 'Buscar:',
            zeroRecords: 'No se encontraron registros coincidentes',
            paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' }
        },
        pageLength: 10,
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'Todos']],
        order: [[6, 'desc'], [7, 'asc']],
        columnDefs: [ { orderable: false, targets: 0 } ],
        responsive: true,
        dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-5"i><"col-sm-7"p>>'
    });
})();
</script>
