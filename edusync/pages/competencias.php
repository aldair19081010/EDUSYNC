<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include('db_connect.php');
$teacher_id = $_SESSION['login_teacher_id'] ?? null;
$login_type = $_SESSION['login_type'] ?? null;
$school_id = $_SESSION['login_school_id'] ?? 0;

if ($login_type != 2 || !$teacher_id) {
	echo "<div class='container mt-5'>
		<div class='alert alert-danger text-center'>
			<i class='fas fa-exclamation-triangle'></i> Solo los docentes pueden gestionar competencias.
		</div>
	</div>";
	exit;
}

// Obtener parámetros
$level = $_GET['level'] ?? '';
$course_id = $_GET['course_id'] ?? 0;

// Si no se pasa nivel, mostrar lista de niveles
if (!$level) {
    // Obtener niveles únicos de los cursos del profesor
    $levels_query = $conn->prepare("
        SELECT DISTINCT tc.level
        FROM teacher_courses tc
        WHERE tc.teacher_id = ? AND tc.school_id = ?
        ORDER BY tc.level
    ");
    $levels_query->bind_param('ii', $teacher_id, $school_id);
    $levels_query->execute();
    $levels = $levels_query->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (empty($levels)) {
        echo "<div class='container mt-5'>
            <div class='alert alert-info text-center'>
                <i class='fas fa-info-circle'></i> No tienes cursos asignados. Contacta al administrador para que te asigne cursos.
            </div>
        </div>";
        exit;
    }
    
    // Mostrar lista de niveles
    ?>
    <div class="container-fluid">
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-tasks"></i> Competencias por Curso</h1>
        </div>
        
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Selecciona un nivel para ver sus cursos y gestionar competencias</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($levels as $level_data): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2" style="cursor: pointer;" onclick="selectLevel('<?php echo htmlspecialchars($level_data['level']); ?>')">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Nivel</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo htmlspecialchars($level_data['level']); ?>
                                            </div>
                                            <div class="text-xs text-gray-600 mt-1">Click para ver cursos</div>
                                        </div>
                                        <div class="col-auto">
                                            <?php 
                                            $icon = 'fa-school';
                                            switch(strtolower($level_data['level'])) {
                                                case 'inicial': $icon = 'fa-baby'; break;
                                                case 'primaria': $icon = 'fa-graduation-cap'; break;
                                                case 'secundaria': $icon = 'fa-user-graduate'; break;
                                            }
                                            ?>
                                            <i class="fas <?php echo $icon; ?> fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function selectLevel(level) {
            const url = new URL(window.location);
            url.searchParams.set('level', level);
            window.location.href = url.toString();
        }
    </script>
    <?php
    exit;
}

// Si se pasa nivel pero no curso, mostrar cursos del nivel
if ($level && !$course_id) {
    // Obtener cursos únicos del nivel (agrupados por curso)
    $courses_query = $conn->prepare("
        SELECT 
            ac.id,
            ac.name as course_name,
            a.name as area_name,
            a.color as area_color,
            tc.level,
            COUNT(DISTINCT CONCAT(tc.grado, '-', tc.seccion)) as num_grados
        FROM academic_courses ac
        LEFT JOIN areas a ON ac.area_id = a.id
        INNER JOIN teacher_courses tc ON ac.id = tc.course_id
        WHERE tc.teacher_id = ? AND tc.school_id = ? AND tc.level = ?
        GROUP BY ac.id, ac.name, a.name, a.color, tc.level
        ORDER BY a.name, ac.name
    ");
    $courses_query->bind_param('iis', $teacher_id, $school_id, $level);
    $courses_query->execute();
    $courses = $courses_query->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (empty($courses)) {
        echo "<div class='container mt-5'>
            <div class='alert alert-info text-center'>
                <i class='fas fa-info-circle'></i> No tienes cursos asignados en el nivel " . htmlspecialchars($level) . ".
            </div>
        </div>";
        exit;
    }
    
    // Mostrar lista de cursos del nivel
    ?>
    <div class="container-fluid">
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-tasks"></i> Cursos de <?php echo htmlspecialchars(ucfirst($level)); ?></h1>
            <a href="index.php?page=competencias" class="btn btn-sm btn-secondary">
                <i class="fa fa-arrow-left"></i> Volver a Niveles
            </a>
        </div>
        
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Selecciona un curso para gestionar sus competencias</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($courses as $course): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card shadow h-100" style="cursor: pointer;" onclick="selectCourse(<?php echo $course['id']; ?>, '<?php echo htmlspecialchars($course['level']); ?>')">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h6 class="font-weight-bold text-primary"><?php echo htmlspecialchars($course['course_name']); ?></h6>
                                            <p class="text-muted mb-0 small"><?php echo htmlspecialchars($course['area_name']); ?></p>
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <span class="badge badge-success"><?php echo htmlspecialchars($course['level']); ?></span>
                                        <span class="badge badge-secondary"><?php echo $course['num_grados']; ?> grado(s)</span>
                                    </div>
                                    <div class="text-center mt-3">
                                        <small class="text-muted"><i class="fas fa-arrow-right text-primary"></i> Click para gestionar competencias</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function selectCourse(courseId, level) {
            const url = new URL(window.location);
            url.searchParams.set('course_id', courseId);
            url.searchParams.set('level', level);
            window.location.href = url.toString();
        }
    </script>
    <?php
    exit;
}

// Obtener información del curso
$course_query = $conn->prepare("
    SELECT 
        ac.*, 
        a.name as area_name, 
        a.color as area_color,
        tc.level
    FROM academic_courses ac
    LEFT JOIN areas a ON ac.area_id = a.id
    INNER JOIN teacher_courses tc ON ac.id = tc.course_id
    WHERE ac.id = ? AND tc.teacher_id = ? AND tc.school_id = ? AND tc.level = ?
    LIMIT 1
");
$course_query->bind_param('iiis', $course_id, $teacher_id, $school_id, $level);
$course_query->execute();
$course = $course_query->get_result()->fetch_assoc();

if (!$course) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Curso no encontrado o no tienes permisos para acceder a él. <a href='index.php?page=competencias&level=" . urlencode($level) . "'>Volver a cursos</a></div></div>";
    exit;
}

// Asegurar columnas para historial por año escolar y versión activa/inactiva
$conn->query("ALTER TABLE general_course_competencies ADD COLUMN IF NOT EXISTS academic_year_id INT NULL DEFAULT NULL AFTER teacher_id");
$conn->query("ALTER TABLE general_course_competencies ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER percentage");
$conn->query("ALTER TABLE general_course_competencies ADD COLUMN IF NOT EXISTS parent_competency_id INT NULL DEFAULT NULL AFTER is_active");
$active_year_query = $conn->query("SELECT id FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
$active_year_id = 0;
if ($active_year_query && $active_year_query->num_rows > 0) {
    $active_year_id = intval($active_year_query->fetch_assoc()['id']);
}
if ($active_year_id > 0) {
    $conn->query("UPDATE general_course_competencies SET academic_year_id = COALESCE(academic_year_id, $active_year_id) WHERE academic_year_id IS NULL OR academic_year_id = 0");
}
$conn->query("UPDATE general_course_competencies SET is_active = 1 WHERE is_active IS NULL");

// CRUD de competencias generales por curso
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	$name = trim($_POST['name'] ?? '');
	$percentage = floatval($_POST['percentage'] ?? 0);
	$id = intval($_POST['id'] ?? 0);
	
	if ($action === 'add' && $name && $percentage > 0) {
		$stmt = $conn->prepare("INSERT INTO general_course_competencies (course_id, teacher_id, name, percentage, academic_year_id, is_active) VALUES (?, ?, ?, ?, ?, 1)");
		$stmt->bind_param('iisdi', $course_id, $teacher_id, $name, $percentage, $active_year_id);
		$stmt->execute();
		if (!headers_sent()) {
			header('Location: ' . $_SERVER['REQUEST_URI']);
			exit;
		} else {
			echo '<script>window.location.href="' . $_SERVER['REQUEST_URI'] . '";</script>';
			exit;
		}
	}
	
	if ($action === 'edit' && $id && $name && $percentage > 0) {
		$conn->query("UPDATE general_course_competencies SET is_active = 0 WHERE id = $id AND course_id = $course_id AND teacher_id = $teacher_id");
		$stmt = $conn->prepare("INSERT INTO general_course_competencies (course_id, teacher_id, name, percentage, academic_year_id, parent_competency_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
		$stmt->bind_param('iisdii', $course_id, $teacher_id, $name, $percentage, $active_year_id, $id);
		$stmt->execute();
		if (!headers_sent()) {
			header('Location: ' . $_SERVER['REQUEST_URI']);
			exit;
		} else {
			echo '<script>window.location.href="' . $_SERVER['REQUEST_URI'] . '";</script>';
			exit;
		}
	}
	
	if ($action === 'delete' && $id) {
		$stmt = $conn->prepare("UPDATE general_course_competencies SET is_active = 0 WHERE id=? AND course_id=? AND teacher_id=?");
		$stmt->bind_param('iii', $id, $course_id, $teacher_id);
		$stmt->execute();
		if (!headers_sent()) {
			header('Location: ' . $_SERVER['REQUEST_URI']);
			exit;
		} else {
			echo '<script>window.location.href="' . $_SERVER['REQUEST_URI'] . '";</script>';
			exit;
		}
	}
}

// Listar competencias generales del curso
$competencias_query = $conn->prepare("
    SELECT id, name, percentage
    FROM general_course_competencies
    WHERE course_id = ? AND teacher_id = ? AND is_active = 1
    ORDER BY id
");
$competencias_query->bind_param('ii', $course_id, $teacher_id);
$competencias_query->execute();
$competencias = $competencias_query->get_result()->fetch_all(MYSQLI_ASSOC);

// Calcular total de porcentajes
$total_percentage = array_sum(array_column($competencias, 'percentage'));
?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-globe"></i> Competencias del Curso</h1>
        <a href="index.php?page=competencias&level=<?php echo urlencode($level); ?>" class="btn btn-sm btn-secondary">
            <i class="fa fa-arrow-left"></i> Volver a Cursos
        </a>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="m-0 font-weight-bold text-primary"><?php echo htmlspecialchars($course['name']); ?></h6>
                    <small class="text-muted">
                        <?php echo htmlspecialchars($course['area_name']); ?> - 
                        <?php echo htmlspecialchars($course['level']); ?> - 
                        <span class="badge badge-info">Para todos los grados</span>
                    </small>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fa fa-check-circle"></i> Acción realizada correctamente.
                </div>
            <?php endif; ?>
            
            <!-- Total de porcentajes -->
            <div class="alert <?php echo $total_percentage == 100 ? 'alert-success' : 'alert-warning'; ?> mb-4">
                <div class="d-flex align-items-center">
                    <i class="fa <?php echo $total_percentage == 100 ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> fa-2x mr-3"></i>
                    <div>
                        <div class="mb-1">
                            <strong>Total: <?php echo number_format($total_percentage, 2); ?>%</strong>
                        </div>
                        <p class="mb-0 small">
                            <?php if ($total_percentage == 100): ?>
                                El porcentaje total es correcto. Las competencias suman 100%.
                            <?php else: ?>
                                El porcentaje total debe ser exactamente 100%.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info mb-4">
                <div class="d-flex align-items-start">
                    <i class="fa fa-info-circle mt-1 mr-2"></i>
                    <div>
                        <strong>¿Qué son las competencias del curso?</strong>
                        <p class="mb-0 small">Las competencias del curso se aplican a <strong>todos los grados</strong> de este curso. Son útiles cuando quieres usar las mismas competencias para todos los grados del mismo curso.</p>
                    </div>
                </div>
            </div>

            <form method="POST" class="card bg-light mb-4">
                <div class="card-body">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-7 mb-2 mb-md-0">
                            <input type="text" name="name" class="form-control form-control-sm" placeholder="Nombre de la competencia" required>
                        </div>
                        <div class="col-md-3 mb-2 mb-md-0">
                            <div class="input-group input-group-sm">
                                <input type="number" name="percentage" class="form-control" placeholder="%" min="1" max="100" step="0.01" required>
                                <div class="input-group-append">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-success btn-sm btn-block" type="submit">
                                <i class="fa fa-plus"></i> Agregar
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Nombre</th>
                            <th width="140px">Porcentaje (%)</th>
                            <th width="100px" class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($competencias)): ?>
                        <tr>
                            <td colspan="3" class="text-center py-4">
                                <div class="text-muted">
                                    <i class="fa fa-info-circle fa-2x mb-2"></i>
                                    <p>No hay competencias registradas para este curso. Agregue su primera competencia usando el formulario superior.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($competencias as $c): ?>
                            <tr>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="id" value="<?php echo $c['id'] ?>">
                                    <td>
                                        <input type="text" name="name" value="<?php echo htmlspecialchars($c['name']) ?>" class="form-control form-control-sm" required>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="percentage" value="<?php echo $c['percentage'] ?>" class="form-control percentage-input" min="1" max="100" step="0.01" required>
                                            <div class="input-group-append">
                                                <span class="input-group-text">%</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <input type="hidden" name="action" value="edit">
                                        <button class="btn btn-primary btn-sm mr-1" type="submit" data-toggle="tooltip" title="Guardar cambios">
                                            <i class="fa fa-save"></i>
                                        </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="id" value="<?php echo $c['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="btn btn-danger btn-sm" type="submit" data-toggle="tooltip" title="Eliminar competencia" 
                                        onclick="return confirm('¿Está seguro que desea eliminar esta competencia?\nEsta acción no se puede deshacer.')">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                                    </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="alert <?php echo $total_percentage == 100 ? 'alert-success' : 'alert-warning'; ?> mb-0" id="totalPercentageContainer">
                <div class="d-flex align-items-center">
                    <i class="fa <?php echo $total_percentage == 100 ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> fa-2x mr-3"></i>
                    <div>
                        <div class="mb-1">
                            <strong>Total: <span id="totalValue"><?php echo number_format($total_percentage, 2); ?></span>%</strong>
                        </div>
                        <p class="mb-0 small" id="totalMessage">
                            <?php if ($total_percentage == 100): ?>
                                El porcentaje total es correcto. Las competencias suman 100%.
                            <?php else: ?>
                                El porcentaje total debe ser exactamente 100%.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <small class="text-muted"><i class="fa fa-info-circle"></i> Las competencias se utilizan para calcular los promedios finales por bimestre.</small>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
	// Inicializar tooltips
	$('[data-toggle="tooltip"]').tooltip();
	
	// Función para calcular y mostrar el porcentaje total
	function updateTotalPercentage() {
		let total = 0;
		$('.percentage-input').each(function() {
			total += parseFloat($(this).val() || 0);
		});
		
		let alertClass = total == 100 ? 'alert-success' : 'alert-warning';
		let iconClass = total == 100 ? 'fa-check-circle' : 'fa-exclamation-triangle';
		let message = total == 100 ? 
			'El porcentaje total es correcto. Las competencias suman 100%.' : 
			'El porcentaje total debe ser exactamente 100%.';
		
		$('#totalPercentageContainer').removeClass('alert-success alert-warning').addClass(alertClass);
		$('#totalPercentageContainer i').removeClass('fa-check-circle fa-exclamation-triangle').addClass(iconClass);
		$('#totalValue').text(total.toFixed(2));
		$('#totalMessage').text(message);
	}
	
	// Calcular el porcentaje total al cargar la página
	updateTotalPercentage();
	
	// Actualizar el porcentaje total cuando cambie cualquier valor
	$(document).on('input', '.percentage-input', function() {
		let value = parseFloat($(this).val() || 0);
		if (value > 100) {
			$(this).val(100);
		}
		updateTotalPercentage();
	});
	
	// Si hay mensaje de éxito, ocultarlo después de 3 segundos
	setTimeout(function() {
		$('.alert-success').not('#totalPercentageContainer').fadeOut(500);
	}, 3000);
});
</script>
