<?php
include('db_connect.php');

$login_type = $_SESSION['login_type'] ?? null;
$teacher_id = $_SESSION['login_teacher_id'] ?? null;
$school_id = $_SESSION['login_school_id'] ?? 0;

if ($login_type != 2 || empty($teacher_id)) {
    echo "<div class='container-fluid py-5'><div class='alert alert-danger text-center'>Solo los docentes pueden ver sus cursos asignados.</div></div>";
    exit;
}

$active_year = ['id' => 0, 'year' => '', 'description' => ''];
$stmt_year = $conn->prepare("SELECT id, year, description FROM academic_year WHERE school_id = ? AND is_active = 1 LIMIT 1");
if ($stmt_year) {
    $stmt_year->bind_param('i', $school_id);
    $stmt_year->execute();
    $res_year = $stmt_year->get_result();
    if ($res_year && $res_year->num_rows > 0) {
        $row_year = $res_year->fetch_assoc();
        $active_year = [
            'id' => (int)$row_year['id'],
            'year' => (string)$row_year['year'],
            'description' => (string)($row_year['description'] ?? '')
        ];
    }
    $stmt_year->close();
}

$areas = [];
$total_courses = 0;
$sql = "SELECT 
            a.id AS area_id,
            a.name AS area_name,
            a.color AS area_color,
            a.description AS area_description,
            GROUP_CONCAT(DISTINCT CONCAT(
                COALESCE(ac.name,''),'|',
                COALESCE(tc.grado,''),'|',
                COALESCE(tc.seccion,''),'|',
                COALESCE(ac.level,''),'|',
                COALESCE(ay_assignment.year,'')
            ) ORDER BY ac.name SEPARATOR '||') AS teacher_courses
        FROM areas a
        INNER JOIN academic_courses ac ON ac.area_id = a.id
        INNER JOIN teacher_courses tc ON tc.course_id = ac.id
        INNER JOIN academic_year ay_assignment ON tc.academic_year_id = ay_assignment.id
        WHERE tc.teacher_id = ?
          AND tc.school_id = ?
          AND a.school_id = ?
          AND ay_assignment.is_active = 1
        GROUP BY a.id, a.name, a.color, a.description
        ORDER BY a.name";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('iii', $teacher_id, $school_id, $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $courses_raw = $row['teacher_courses'] ? explode('||', $row['teacher_courses']) : [];
        $courses = [];
        foreach ($courses_raw as $item) {
            if (empty($item)) continue;
            $parts = explode('|', $item);
            if (count($parts) >= 5) {
                $courses[] = [
                    'name' => $parts[0],
                    'grado' => $parts[1],
                    'seccion' => $parts[2] ?: 'U',
                    'level' => $parts[3],
                    'year' => $parts[4]
                ];
            }
        }
        $total_courses += count($courses);
        $areas[] = [
            'id' => $row['area_id'],
            'name' => $row['area_name'],
            'color' => $row['area_color'] ?: '#4e73df',
            'description' => $row['area_description'],
            'courses' => $courses
        ];
    }
    $stmt->close();
}
?>

<div class="container-fluid py-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="fa fa-book mr-2"></i>Mis Cursos Asignados</h1>
        <?php if ($active_year['id'] > 0): ?>
            <span class="badge badge-success py-2 px-3">Año Académico: <?php echo htmlspecialchars($active_year['year']); ?><?php echo $active_year['description'] ? ' - ' . htmlspecialchars($active_year['description']) : ''; ?></span>
        <?php else: ?>
            <span class="badge badge-secondary py-2 px-3">Sin año académico activo</span>
        <?php endif; ?>
    </div>

    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Áreas Académicas</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo count($areas); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-layer-group fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Cursos Asignados</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_courses; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chalkboard-teacher fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Año Activo</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $active_year['id'] ? htmlspecialchars($active_year['year']) : 'N/D'; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-check fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($areas)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fa fa-graduation-cap text-muted mb-3" style="font-size: 2.5rem;"></i>
                <h5 class="text-muted">No tienes áreas académicas asignadas</h5>
                <p class="text-muted mb-1">No hay cursos asignados en el año académico activo.</p>
                <small class="text-muted">Consulte con la administración para la asignación de cursos.</small>
            </div>
        </div>
    <?php else: ?>
        <div class="areas-container">
            <?php foreach ($areas as $area_index => $area): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between" style="background-color: #f8f9fc;">
                        <div class="d-flex align-items-center">
                            <span class="rounded-circle mr-3" style="background-color: <?php echo htmlspecialchars($area['color']); ?>; width: 16px; height: 16px; display: inline-block;"></span>
                            <div>
                                <h5 class="mb-1" style="color: <?php echo htmlspecialchars($area['color']); ?>; font-weight: 600;">
                                    <?php echo htmlspecialchars($area['name']); ?>
                                </h5>
                                <?php if (!empty($area['description'])): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($area['description']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="badge badge-light" style="color: <?php echo htmlspecialchars($area['color']); ?>; border: 1px solid <?php echo htmlspecialchars($area['color']); ?>33;">
                            <?php echo count($area['courses']); ?> curso<?php echo count($area['courses']) != 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0 area-courses-table" data-table-index="<?php echo $area_index; ?>">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:50px;">#</th>
                                        <th>Curso/Capacidad</th>
                                        <th>Grado</th>
                                        <th>Sección</th>
                                        <th>Nivel</th>
                                        <th>Año Académico</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $course_counter = 1; ?>
                                    <?php foreach ($area['courses'] as $course): ?>
                                        <tr>
                                            <td><?php echo $course_counter++; ?></td>
                                            <td class="font-weight-bold text-gray-800">
                                                <i class="fa fa-book mr-2 text-primary"></i><?php echo htmlspecialchars($course['name']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($course['grado']); ?></td>
                                            <td><?php echo htmlspecialchars($course['seccion']); ?></td>
                                            <td><span class="badge badge-info"><?php echo htmlspecialchars($course['level']); ?></span></td>
                                            <td><span class="badge badge-success"><?php echo htmlspecialchars($course['year']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    .areas-container .card-header { border-bottom: 1px solid #e3e6f0; }
    .areas-container .area-courses-table thead th { font-size: 0.85rem; }
    .areas-container .area-courses-table tbody td { vertical-align: middle; }
    .areas-container .area-courses-table tbody tr:hover { background-color: rgba(78, 115, 223, 0.04); }
</style>

<script>
$(function() {
    $('.area-courses-table').each(function(index) {
        $(this).DataTable({
            language: {
                emptyTable: 'No hay cursos en esta área.',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ cursos',
                infoEmpty: 'Mostrando 0 a 0 de 0 cursos',
                infoFiltered: '(filtrado de _MAX_ cursos en total)',
                lengthMenu: 'Mostrar _MENU_ cursos',
                loadingRecords: 'Cargando...',
                processing: 'Procesando...',
                search: 'Buscar:',
                zeroRecords: 'No se encontraron cursos coincidentes',
                paginate: {
                    first: 'Primero',
                    last: 'Último',
                    next: 'Siguiente',
                    previous: 'Anterior'
                }
            },
            pageLength: 5,
            lengthMenu: [[5, 10, 25, -1], [5, 10, 25, 'Todos']],
            order: [[1, 'asc']],
            columnDefs: [
                { orderable: false, targets: 0 },
                { type: 'string', targets: [1, 2, 3, 4, 5] }
            ],
            responsive: true,
            dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row"<"col-sm-5"i><"col-sm-7"p>>'
        });
    });
});
</script>
