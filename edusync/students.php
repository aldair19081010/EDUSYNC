<?php
// Base de datos (evitar reiniciar sesión aquí: `index.php` ya inicia la sesión)
include_once 'db_connect.php';
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$can_manage_students = false;
if (!empty($_SESSION['login_id'])) {
	$role_query = $conn->prepare('SELECT type, is_director FROM users WHERE id = ? LIMIT 1');
	if ($role_query) {
		$login_id = (int)$_SESSION['login_id'];
		$role_query->bind_param('i', $login_id);
		$role_query->execute();
		$role = $role_query->get_result()->fetch_assoc();
		$can_manage_students = $role && ((int)$role['type'] === 1 || (int)$role['is_director'] === 1);
		$role_query->close();
	}
}
if (!$can_manage_students) {
	header('HTTP/1.1 403 Forbidden');
	exit('No tienes permisos para acceder al módulo de estudiantes.');
}
$active_year = null;
$academic_years = [];
$active_year_query = $conn->prepare('SELECT year, description FROM academic_year WHERE school_id = ? AND is_active = 1 ORDER BY start_date DESC LIMIT 1');
$active_school_id = intval($_SESSION['login_school_id'] ?? 0);
if ($active_year_query) {
	$active_year_query->bind_param('i', $active_school_id);
	$active_year_query->execute();
	$active_year_result = $active_year_query->get_result();
	$active_year = $active_year_result->fetch_assoc() ?: null;
	$active_year_query->close();
}
$years_query = $conn->prepare('SELECT id, year, description, is_active FROM academic_year WHERE school_id = ? ORDER BY is_active DESC, start_date DESC');
if ($years_query) {
	$years_query->bind_param('i', $active_school_id);
	$years_query->execute();
	$years_result = $years_query->get_result();
	while ($year_row = $years_result->fetch_assoc()) $academic_years[] = $year_row;
	$years_query->close();
}
?>
<style>
	input[type=checkbox] {
		-ms-transform: scale(1.3);
		-moz-transform: scale(1.3);
		-webkit-transform: scale(1.3);
		-o-transform: scale(1.3);
		transform: scale(1.3);
		padding: 10px;
		cursor: pointer;
	}
	
	/* Estilos generales para la página de estudiantes */
	.main-content-area {
		padding: 10px 5px;
	}
	
	.page-title {
		color: #4285f4;
		font-weight: 600;
		margin-bottom: 10px;
		display: flex;
		align-items: center;
	}
	
	.page-title i {
		margin-right: 8px;
		font-size: 1.2em;
	}
	
	.action-buttons .btn {
		border-radius: 8px;
		box-shadow: 0 2px 6px rgba(0,0,0,0.08);
		transition: all 0.3s ease;
		font-weight: 500;
	}
	
	.action-buttons .btn:hover {
		transform: translateY(-2px);
		box-shadow: 0 4px 8px rgba(0,0,0,0.12);
	}
	
	.action-buttons .btn i {
		margin-right: 6px;
	}
	
	.students-card {
		border-radius: 8px;
		box-shadow: 0 2px 15px rgba(0,0,0,0.05);
		border: none;
		margin-bottom: 20px;
		overflow: hidden;
		transition: transform 0.2s, box-shadow 0.2s;
	}
	.students-card:hover {
		transform: translateY(-3px);
		box-shadow: 0 4px 20px rgba(0,0,0,0.1);
	}
	.students-card .card-header {
		padding: .75rem 1.25rem;
		background: transparent;
		color: inherit;
		border-bottom: 1px solid #e3e6f0;
		font-weight: 600;
		font-size: 1rem;
	}
	.card-header .btn-add {
		border-radius: 6px;
		font-weight: 500;
		transition: all 0.2s;
		background: #fff;
		color: #2a75f3;
		border: none;
		box-shadow: 0 2px 5px rgba(66,133,244,0.10);
	}
	.card-header .btn-add:hover {
		/* NO usar transform aquí para evitar efectos de zoom */
		box-shadow: 0 4px 8px rgba(66,133,244,0.15);
		background: #f8f9fc;
		color: #1a65e3;
		transform: none !important;
	}
	
	.student-table {
		box-shadow: 0 2px 15px rgba(0,0,0,0.03);
		border-radius: 8px;
		overflow: hidden;
		background: #fff;
	}
	.student-table th, .student-table td {
		vertical-align: middle !important;
		padding: 12px 15px;
	}
	.student-table thead th {
		background: #f8f9fa;
		color: #2c4964;
		font-weight: 600;
		border-bottom: 2px solid #e3e6f0;
	}
	.student-table tbody tr {
		transition: background-color 0.2s;
	}
	.student-table tbody tr:hover {
		background-color: rgba(252, 125, 28, 0.05);
		transform: translateY(-1px);
		box-shadow: 0 2px 8px rgba(0,0,0,0.05);
	}
	
	.level-badge {
		background-color: #e8f4fe;
		color: #4285f4;
		padding: 4px 8px;
		border-radius: 4px;
		font-size: 0.85rem;
		font-weight: 500;
	}
	
	.level-badge.egresado {
		background-color: #f3e5f5;
		color: #6a1b9a;
	}
	
	.level-badge.retirado {
		background-color: #ffebee;
		color: #b71c1c;
	}
	
	/* Estilo para los botones de filtro */
	.btn-outline-purple {
		color: #6a1b9a;
		border-color: #9c27b0;
	}
	
	.btn-outline-purple:hover, 
	.btn-outline-purple:active, 
	.btn-outline-purple.active {
		background-color: #9c27b0 !important;
		color: white !important;
		border-color: #9c27b0 !important;
	}
	
	/* Keep button groups simple and let SB Admin / Bootstrap handle colors */
	.btn-group .btn {
		border-radius: 4px;
		margin: 0 4px;
		transition: transform 0.12s ease, box-shadow 0.12s ease;
	}
	.btn-group .btn:hover {
		transform: translateY(-1px);
	} 
	
	.empty-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 30px 20px;
	}
	
	/* Estilos adicionales */
	img {
		max-width: 100px;
		max-height: 150px;
		border-radius: 4px;
	}
	
	/* Mejoras para DataTables */
	.dataTables_wrapper .dataTables_filter input {
		border: 1px solid #e3e6f0;
		border-radius: 6px;
		padding: 6px 12px;
		margin-left: 8px;
	}
	
	.dataTables_info, .dataTables_paginate {
		margin-top: 15px;
	}
	
	.dataTables_paginate .paginate_button {
		border-radius: 4px !important;
		margin: 0 2px;
	}
	
	.dataTables_paginate .paginate_button.current {
		background: #4285f4 !important;
		border-color: #2a75f3 !important;
		color: white !important;
	}

/* Nuevo encabezado compacto y limpio */
.page-title-wrapper .page-title {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 6px 10px;
}
.page-title .title-left {
	display: flex;
	align-items: center;
}
.page-title .title-icon {
	width: 44px;
	height: 44px;
	border-radius: 10px;
	background: linear-gradient(135deg, rgba(66,133,244,0.12), rgba(42,117,243,0.08));
	display: flex;
	align-items: center;
	justify-content: center;
	margin-right: 10px;
	color: #2a75f3;
	font-size: 1.1rem;
}
.page-title .page-title-text {
	font-size: 1.05rem;
	font-weight: 700;
	color: #233b52;
}
.page-title .page-title-sub {
	font-size: 0.85rem;
	color: #6c757d;
	margin-top: 2px;
	font-weight: 500;
}
.page-title .title-actions { 
	display: flex;
	align-items: center;
}
/* badge de contador eliminado: espacio reservado para futuras acciones */

@media (max-width: 575px) {
	.page-title .page-title-sub { display: none; }
	.page-title .title-icon { width: 40px; height: 40px; }
}
	
	/* Mejoras para la visualización de tablas */
	.table {
		font-size: 0.9rem;
	}
	
	.table th {
		font-weight: 600;
		padding: 12px 10px;
		background-color: #f8f9fc;
		border-top: none;
	}
	
	.table td {
		padding: 12px 10px;
		vertical-align: middle;
	}
	
	/* Mejorar la visualización de estados de estudiantes */
	.badge-status {
		padding: 6px 10px;
		font-weight: 500;
		font-size: 12px;
		letter-spacing: 0.3px;
		border-radius: 50px;
	}
	
	.badge-activo {
		background-color: #e3f2fd;
		color: #1565c0;
	}
	
	.badge-egresado {
		background-color: #f3e5f5;
		color: #6a1b9a;
	}
	
	.badge-retirado {
		background-color: #ffebee;
		color: #b71c1c;
	}
	
	/* Mejorar visualización en móviles */
	@media (max-width: 767px) {
		.table {
			font-size: 0.85rem;
		}
		
		.table td, .table th {
			padding: 10px 8px;
		}
	}
	
	/* Mejorar apariencia de los botones de filtro */
	.btn-outline-purple {
		color: #6a1b9a;
		border-color: #9c27b0;
	}
	
	.btn-outline-purple:hover, 
	.btn-outline-purple:active, 
	.btn-outline-purple.active {
		background-color: #9c27b0 !important;
		color: white !important;
		border-color: #9c27b0 !important;
	}
	
	/* Mejorar visualización de botones de filtro en dispositivos móviles */
	@media (max-width: 767px) {
		.btn-group {
			flex-wrap: wrap;
			width: 100%;
		}
		
		.btn-group .btn {
			flex-grow: 1;
			padding: 5px;
			margin-bottom: 5px;
			font-size: 0.85rem;
		}
	}
	
	/* Mejorar apariencia de badges y etiquetas */
	.badge {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		box-shadow: 0 1px 3px rgba(0,0,0,0.08);
	}
	
	.badge i {
		margin-right: 4px;
	}
	
	/* Mejorar el espaciado de las tablas */
	.table-responsive {
		padding: 0;
		border: none;
	}

	.student-table-container {
		min-height: 260px;
	}
	/* Estilo de la fila vacía de DataTables */
	.dataTables_empty {
		padding: 40px 10px !important;
		color: #6c757d;
	}

	.filter-bar-students {
		background: #f8f9fc;
		border-radius: 8px;
		padding: 12px 16px;
		margin-bottom: 16px;
		display: flex;
		gap: 12px;
		align-items: end;
		flex-wrap: wrap;
	}
	.filter-bar-students .filter-group { display: flex; flex-direction: column; gap: 4px; }
	.filter-bar-students .filter-group label { font-size: 0.75rem; font-weight: 600; color: #5a5c69; margin: 0; }
	.filter-bar-students .filter-group select { min-width: 150px; }
	
	@media (max-width: 768px) {
		.filter-bar-students { flex-direction: column; align-items: stretch; }
		.filter-bar-students .filter-group { width: 100%; }
		.filter-bar-students .filter-group select { min-width: 100%; }
	}
.student-add-menu { width:310px; max-width:calc(100vw - 30px); padding:8px; border:0; border-radius:10px; box-shadow:0 10px 30px rgba(35,59,82,.18); }
.student-add-menu .dropdown-header { padding:7px 10px 5px; color:#858796; font-size:.7rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
.student-add-menu .dropdown-item { display:flex; align-items:center; padding:9px 10px; border-radius:7px; white-space:normal; }
.student-add-menu .dropdown-item:hover { background:#f3f6ff; }
.student-add-menu .menu-icon { width:38px; height:38px; flex:0 0 38px; display:flex; align-items:center; justify-content:center; border-radius:8px; margin-right:10px; }
.student-add-menu .menu-copy { min-width:0; }.student-add-menu .menu-title { display:block; color:#2c4964; font-size:.88rem; font-weight:700; line-height:1.2; }.student-add-menu .menu-description { display:block; color:#858796; font-size:.72rem; line-height:1.25; margin-top:3px; }
.student-menu-new { background:#e8f0fe; color:#2e59d9; }.student-menu-upload { background:#e8f9f0; color:#1e7e34; }.student-menu-template { background:#fff3cd; color:#856404; }.student-menu-export { background:#e2e3e5; color:#5a5c69; }
</style>

<div class="main-content-area">
	<div class="d-sm-flex align-items-center justify-content-between mb-4">
		<div class="d-flex align-items-center">
			<div class="title-icon mr-3"><i class="fa fa-graduation-cap fa-lg"></i></div>
			<div>
				<h1 class="h3 mb-0 text-gray-800">Gestión de Estudiantes</h1>
				<div class="small text-muted">Lista, filtros y acciones principales · Año activo predeterminado: <strong><?php echo htmlspecialchars($active_year['year'] ?? 'No configurado', ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($active_year['description']) ? ' - ' . htmlspecialchars($active_year['description'], ENT_QUOTES, 'UTF-8') : ''; ?></strong></div>
			</div>
		</div>
		<div class="d-flex align-items-center">
			<?php if ($can_manage_students): ?><div class="btn-group mr-2">
				<button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="fa fa-plus mr-1"></i>Agregar / importar</button>
				<div class="dropdown-menu dropdown-menu-right student-add-menu">
					<h6 class="dropdown-header">Registro</h6>
					<a class="dropdown-item" href="#" id="action_new_student"><span class="menu-icon student-menu-new"><i class="fa fa-user-plus"></i></span><span class="menu-copy"><span class="menu-title">Nuevo estudiante</span><span class="menu-description">Registrar manualmente un estudiante.</span></span></a>
					<div class="dropdown-divider"></div><h6 class="dropdown-header">Importación</h6>
					<a class="dropdown-item" href="#" id="action_upload_excel"><span class="menu-icon student-menu-upload"><i class="fa fa-file-excel"></i></span><span class="menu-copy"><span class="menu-title">Importar desde Excel</span><span class="menu-description">Agregar varios estudiantes desde un archivo.</span></span></a>
					<a class="dropdown-item" href="#" id="action_download_format"><span class="menu-icon student-menu-template"><i class="fa fa-file-download"></i></span><span class="menu-copy"><span class="menu-title">Descargar plantilla</span><span class="menu-description">Obtener el formato correcto para importar.</span></span></a>
					<div class="dropdown-divider"></div><h6 class="dropdown-header">Exportación</h6>
					<a class="dropdown-item" href="#" id="action_export_students"><span class="menu-icon student-menu-export"><i class="fa fa-download"></i></span><span class="menu-copy"><span class="menu-title">Exportar estudiantes</span><span class="menu-description">Descargar en CSV según los filtros actuales.</span></span></a>
				</div>
			</div><?php endif; ?>
		</div>
	</div>
	
	<div class="row mb-4">
		<div class="col-md-12"></div>
	</div>
	
	<div class="row">
		<!-- Table Panel -->
		<div class="col-md-12">
			<div class="card shadow mb-4 students-card">
				<div class="card-header py-3 d-flex align-items-center justify-content-between">
					<h5 class="card-title mb-0"><i class="fa fa-list mr-2"></i>Lista de Estudiantes</h5>
				</div>
				<div class="card-body">
					<!-- Barra de Filtros -->
                    <div class="filter-bar-students">
                        <div class="filter-group">
                            <label><i class="fa fa-graduation-cap mr-1"></i>Nivel</label>
                            <select id="filter_nivel_students" class="form-control form-control-sm">
                                <option value="all">Todos</option>
                                <option value="Inicial">Inicial</option>
                                <option value="Primaria">Primaria</option>
                                <option value="Secundaria">Secundaria</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fa fa-layer-group mr-1"></i>Grado</label>
                            <select id="grado_filter" class="form-control form-control-sm">
                                <option value="">Todos</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fa fa-door-open mr-1"></i>Sección</label>
                            <select id="seccion_filter" class="form-control form-control-sm">
                                <option value="">Todas</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fa fa-user mr-1"></i>Estado</label>
                            <select id="filter_student_status" class="form-control form-control-sm">
                                <option value="all">Todos</option>
                                <option value="Activo" selected>Activo</option>
                                <option value="Egresado">Egresado</option>
                                <option value="Retirado">Retirado</option>
                            </select>
                        </div>
                        <div class="filter-group ml-auto d-flex flex-row align-items-end" style="justify-content: flex-end; gap: 8px;">
							<button id="export_csv" class="btn btn-outline-success btn-sm" title="Exportar CSV"><i class="fa fa-file-csv mr-1"></i> Exportar</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="clear_students_filters" title="Limpiar filtros">
                                <i class="fa fa-times mr-1"></i> Limpiar
                            </button>
                        </div>
                    </div>
					<?php if ($can_manage_students): ?>
					<div id="bulk-student-toolbar" class="alert alert-light border d-none align-items-center justify-content-between mb-3">
						<span><strong id="selected-students-count">0</strong> estudiantes seleccionados</span>
						<div><button type="button" id="export-selected-students" class="btn btn-outline-success btn-sm mr-2"><i class="fas fa-file-csv mr-1"></i>Exportar seleccionados</button><button type="button" id="open-bulk-students" class="btn btn-primary btn-sm"><i class="fas fa-tasks mr-1"></i>Acciones masivas</button></div>
					</div>
					<?php endif; ?>

					<div class="table-responsive student-table-container">
					<table class="table table-hover student-table" id="student-table">
						<thead class="bg-light">
							<tr>
								<th class="text-center" width="40px"><input type="checkbox" id="select-all-students" title="Seleccionar todos"></th>
								<th width="120px">DNI</th>
								<th>Nombre</th>
								<th>Género</th>
								<th>Información de Contacto</th>
								<th>Nivel</th>
								<th>Grado</th>
								<th>Sección</th>
								<th>Estado</th>
								<th class="text-center" width="120px">Acciones</th>
							</tr>
						</thead>
						<tbody>
							<!-- El contenido de la tabla será llenado por DataTables usando AJAX server-side -->
						</tbody>
					</table>
					</div>
				</div>
			</div>
		</div>
		<!-- Table Panel -->
	</div>
</div>

<?php if ($can_manage_students): ?>
<div class="modal fade" id="bulkStudentsModal" tabindex="-1" role="dialog" aria-labelledby="bulkStudentsModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
		<div class="modal-header"><h5 class="modal-title" id="bulkStudentsModalLabel"><i class="fas fa-tasks mr-2"></i>Acciones masivas</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
		<div class="modal-body">
			<div class="form-group"><label for="bulk-action">Acción</label><select id="bulk-action" class="form-control"><option value="status">Cambiar estado</option><option value="academic">Cambiar nivel, grado y sección</option></select></div>
			<div id="bulk-status-fields" class="form-group"><label for="bulk-status">Nuevo estado</label><select id="bulk-status" class="form-control"><option value="Activo">Activo</option><option value="Egresado">Egresado</option><option value="Retirado">Retirado</option></select></div>
			<div id="bulk-academic-fields" class="d-none"><div class="form-group"><label for="bulk-level">Nivel</label><select id="bulk-level" class="form-control"><option value="Inicial">Inicial</option><option value="Primaria">Primaria</option><option value="Secundaria">Secundaria</option></select></div><div class="form-row"><div class="form-group col"><label for="bulk-grade">Grado</label><select id="bulk-grade" class="form-control"><option value="1°">1°</option><option value="2°">2°</option><option value="3°">3°</option><option value="4°">4°</option><option value="5°">5°</option><option value="6°">6°</option></select></div><div class="form-group col"><label for="bulk-section">Sección</label><select id="bulk-section" class="form-control"><option value="U">U</option><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option><option value="E">E</option><option value="F">F</option></select></div></div></div>
			<div id="bulk-students-error" class="alert alert-danger d-none"></div>
		</div>
		<div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button><button type="button" id="apply-bulk-students" class="btn btn-primary"><i class="fas fa-check mr-1"></i>Aplicar cambios</button></div>
	</div></div>
</div>
<?php endif; ?>

<style>
	.upload-info-box {
		display: flex;
		background-color: #e8f4fe;
		border-left: 4px solid #4285f4;
		border-radius: 6px;
		padding: 15px;
	}
	
	.upload-info-icon {
		color: #4285f4;
		font-size: 1.5rem;
		margin-right: 15px;
		padding-top: 3px;
	}
	
	.upload-info-content {
		flex: 1;
	}
	
	.upload-info-content p {
		margin-bottom: 5px;
		font-size: 0.95rem;
	}
	
	.custom-file-label {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		padding-right: 90px;
	}		.custom-file-input:lang(es)~.custom-file-label::after {
		content: "Explorar";
		background-color: #4285f4;
		color: white;
		transition: all 0.3s ease;
	}
	
	.custom-file-input:focus ~ .custom-file-label {
		border-color: #4285f4;
		box-shadow: 0 0 0 0.2rem rgba(66, 133, 244, 0.25);
	}
	
	/* Use SB Admin's default .btn-primary styles */
</style>

<!-- Modal para subir Excel -->
<div class="modal fade" id="uploadExcelModal" tabindex="-1" role="dialog" aria-labelledby="uploadExcelModalLabel" aria-hidden="true">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header" style="background: linear-gradient(to right, #f8f9fc, #fff);">
				<h5 class="modal-title" id="uploadExcelModalLabel">
					<i class="fa fa-file-excel text-success mr-2"></i>Subir Archivo Excel
				</h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<div class="upload-info-box mb-4">
					<div class="upload-info-icon">
						<i class="fa fa-info-circle"></i>
					</div>
					<div class="upload-info-content">
						<p>Suba un archivo Excel con la información de estudiantes. Asegúrese de usar el formato correcto.</p>
							<p class="mb-1"><strong>Valores válidos:</strong> Nivel: Inicial, Primaria o Secundaria. Estado: Activo, Egresado o Retirado.</p>
							<p class="mb-0">Género: Masculino, Femenino u Otro. Puede escribirlos en mayúsculas o minúsculas.</p>
						<p class="mb-0">Puede <a href="#" id="download_format_link" style="color: #4285f4; font-weight: 500;">descargar el formato aquí</a>.</p>
					</div>
				</div>
				
				<form id="upload-excel-form" enctype="multipart/form-data">
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
					<div class="form-group">
						<label for="upload_academic_year_id">Año académico:</label>
						<select class="form-control" id="upload_academic_year_id" name="academic_year_id" required>
							<option value="">Seleccionar...</option>
							<?php foreach ($academic_years as $year): ?>
								<option value="<?php echo (int)$year['id']; ?>" <?php echo !empty($year['is_active']) ? 'selected' : ''; ?>>
									<?php echo htmlspecialchars($year['year'] . (!empty($year['description']) ? ' - ' . $year['description'] : '') . ($year['is_active'] ? ' (Activo)' : ''), ENT_QUOTES, 'UTF-8'); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="form-group">
						<label for="excel_file">Seleccionar archivo Excel:</label>
						<div class="custom-file">
							<input type="file" class="custom-file-input" id="excel_file" name="excel_file" accept=".xls,.xlsx" required>
							<label class="custom-file-label" for="excel_file">Seleccionar archivo...</label>
						</div>
						<small class="form-text text-muted">
							Formatos permitidos: .xls, .xlsx
						</small>
					</div>
					<div class="form-group mt-4">
					<button type="submit" class="btn btn-primary btn-block">
							<i class="fa fa-upload mr-2"></i> Subir Excel
						</button>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-dismiss="modal">
					<i class="fa fa-times mr-2"></i>Cancelar
				</button>
			</div>
		</div>
	</div>
</div>

<script>
(function waitForDependencies(){
    var jq = window.jQuery;
    if (typeof jq === 'undefined' || typeof jq.fn === 'undefined' || typeof jq.fn.dataTable === 'undefined') {
        return setTimeout(waitForDependencies, 50);
    }
    (function($){

	$(document).ready(function() {
		var table = $('#student-table').DataTable({
			language: {
				url: '//cdn.datatables.net/plug-ins/1.10.21/i18n/Spanish.json'
			},
			responsive: true,
			autoWidth: false,
			pageLength: 10,
			ordering: true,
			columnDefs: [
				{ orderable: false, targets: -1 },
				{ orderable: false, targets: 3 }, // Género (badge, no orderable)
				{ orderable: false, targets: 4 }, // Contacto (no orderable)
				{ targets: 0, responsivePriority: 1  }, // #
				{ targets: 2, responsivePriority: 2  }, // Nombre
				{ targets: 9, responsivePriority: 1  }, // Acciones
				{ targets: 1, responsivePriority: 3  }, // DNI
				{ targets: 3, responsivePriority: 4  }, // Género
				{ targets: 4, responsivePriority: 5  }, // Info contacto
				{ targets: 5, responsivePriority: 6  }, // Nivel
				{ targets: 6, responsivePriority: 7  }, // Grado
				{ targets: 7, responsivePriority: 8  }, // Sección
				{ targets: 8, responsivePriority: 9  }  // Estado
			],
			serverSide: true,
			processing: true,
			ajax: {
				url: 'students_table_data.php',
				type: 'GET'
			},
			initComplete: function() {
				$('[data-toggle="tooltip"]').tooltip();
			},
			drawCallback: function(settings) {
				// Re-inicializar tooltips después de cada redraw
				try { $('[data-toggle="tooltip"]').tooltip(); } catch(_) {}
			}
		});

		// Refrescar tabla cuando se guarde un estudiante desde el modal
		$(document).on('student:saved', function(e, payload){
			try { table.ajax.reload(null, false); } catch(_) {}
		});
		window.studentTable = table;
		function updateBulkSelection() {
			var selected = $('#student-table .select-student:checked').length;
			$('#selected-students-count').text(selected);
			$('#bulk-student-toolbar').toggleClass('d-none', selected === 0).toggleClass('d-flex', selected > 0);
			$('#select-all-students').prop('checked', selected > 0 && selected === $('#student-table .select-student').length);
		}
		$('#student-table').off('change.studentsBulk', '.select-student').on('change.studentsBulk', '.select-student', updateBulkSelection);
		$('#select-all-students').off('click.studentsBulk change.studentsBulk').on('click.studentsBulk', function(e) { e.stopPropagation(); }).on('change.studentsBulk', function() {
			$('#student-table .select-student').prop('checked', this.checked);
			updateBulkSelection();
		});
		$('#student-table').off('draw.studentsBulk').on('draw.studentsBulk', function() { $('#select-all-students').prop('checked', false); updateBulkSelection(); });
		$('#open-bulk-students').off('click.studentsBulk').on('click.studentsBulk', function() {
			if (!$('#student-table .select-student:checked').length) return;
			$('#bulk-students-error').addClass('d-none').empty();
			$('#bulkStudentsModal').modal('show');
		});
		$('#export-selected-students').off('click.studentsBulk').on('click.studentsBulk', function() {
			var ids = $('#student-table .select-student:checked').map(function() { return this.value; }).get();
			if (!ids.length) return;
			var params = $.param({ selected_ids: ids.join(',') });
			window.location.assign('students_export.php?' + params + '&download=' + Date.now());
		});
		$('#bulk-action').on('change', function() {
			var academic = this.value === 'academic';
			$('#bulk-status-fields').toggleClass('d-none', academic);
			$('#bulk-academic-fields').toggleClass('d-none', !academic);
		});
		$('#apply-bulk-students').on('click', function() {
			var ids = $('#student-table .select-student:checked').map(function() { return this.value; }).get();
			var academic = $('#bulk-action').val() === 'academic';
			var data = {
				student_ids: JSON.stringify(ids),
				update_type: academic ? 'nivel_grado' : 'status_' + $('#bulk-status').val().toLowerCase(),
				csrf_token: <?php echo json_encode($csrf_token); ?>
			};
			if (academic) {
				data.new_nivel = $('#bulk-level').val();
				data.new_grado = $('#bulk-grade').val();
				data.new_seccion = $('#bulk-section').val();
			}
			var $button = $(this);
			$button.prop('disabled', true);
			$.ajax({ url: 'ajax.php?action=bulk_update_students', type: 'POST', data: data, dataType: 'json' })
				.done(function(resp) {
					if (resp.status == 1) {
						$('#bulkStudentsModal').modal('hide');
						$('#student-table').DataTable().ajax.reload(null, false);
						alert_toast(resp.msg || 'Estudiantes actualizados correctamente.', 'success');
					} else { $('#bulk-students-error').removeClass('d-none').text(resp.msg || 'No se pudieron actualizar los estudiantes.'); }
				}).fail(function(xhr) { $('#bulk-students-error').removeClass('d-none').text((xhr.responseJSON && xhr.responseJSON.msg) || 'Error en el servidor.'); })
				.always(function() { $button.prop('disabled', false); });
		});


		// Filtros personalizados (nivel y estado)
		$('#filter_nivel_students, #filter_student_status, #grado_filter, #seccion_filter').on('change', function() {
			table.ajax.reload();
		});

		$('#clear_students_filters').on('click', function() {
			$('#filter_nivel_students').val('all');
			$('#filter_student_status').val('Activo');
			$('#grado_filter').val('');
			$('#seccion_filter').val('');
			table.ajax.reload();
		});

		// Modificar el ajax.data para enviar los filtros al backend
		table.on('preXhr.dt', function(e, settings, data) {
			data.nivel = $('#filter_nivel_students').val() || 'all';
			data.status = $('#filter_student_status').val() || 'all';
			data.grado = $('#grado_filter').val() || '';
			data.seccion = $('#seccion_filter').val() || '';
		});

		// Delegar eventos para los botones de acción
		$('#student-table tbody').on('click', '.edit_student', function() {
			uni_modal("Gestionar Información de Estudiante", "manage_student.php?id=" + $(this).attr('data-id'), "mid-large");
		});
		$('#student-table tbody').on('click', '.view_student', function() {
			uni_modal("Ver Estudiante", "view_student.php?id=" + $(this).attr('data-id'), "mid-large");
		});
		$('#student-table tbody').on('click', '.delete_student', function() {
			confirmStudentDeletion(this);
		});

		// Inicializar tooltips para botones
		$('[data-toggle="tooltip"]').tooltip();



		// Poblar selects de grado y sección desde la respuesta del servidor (meta)
		function populateAdvancedFiltersFromTable(api) {
			try {
				var json = api.ajax.json && api.ajax.json();
				var grados = (json && json.meta && Array.isArray(json.meta.grados)) ? json.meta.grados : [];
				var secciones = (json && json.meta && Array.isArray(json.meta.secciones)) ? json.meta.secciones : [];
				var $grado = $('#grado_filter');
				var $seccion = $('#seccion_filter');
				var currentGrado = $grado.val();
				var currentSeccion = $seccion.val();
				
				$grado.find('option:not(:first)').remove();
				$seccion.find('option:not(:first)').remove();
				
				grados.forEach(function(g){ if (g) $grado.append('<option value="'+g+'">'+g+'</option>'); });
				secciones.forEach(function(s){ if (s) $seccion.append('<option value="'+s+'">'+s+'</option>'); });
				
				if (currentGrado && $grado.find('option[value="'+currentGrado+'"]').length) $grado.val(currentGrado);
				if (currentSeccion && $seccion.find('option[value="'+currentSeccion+'"]').length) $seccion.val(currentSeccion);
			} catch (_) {}
		}

		// Poblar al cargar y en cada redraw
		populateAdvancedFiltersFromTable(table);
		table.on('draw', function(){ populateAdvancedFiltersFromTable(table); });



		// Exportar CSV desde servidor con todos los registros filtrados
		$('#export_csv, #action_export_students').off('click.studentsExport').on('click.studentsExport', function(e){
			e.preventDefault();
			var params = $.param({
				nivel: $('#filter_nivel_students').val(),
				status: $('#filter_student_status').val(),
				grado: $('#grado_filter').val() || '',
				seccion: $('#seccion_filter').val() || '',
				search: ($('.dataTables_filter input').val() || '').trim()
			});
			var url = 'students_export.php?' + params + '&download=' + Date.now();
			if (typeof start_load === 'function') start_load();
			window.location.assign(url);
			setTimeout(function(){ if (typeof end_load === 'function') end_load(); }, 1500);
		});
	});

    $('#new_student, #action_new_student').click(function(e) {
		e.preventDefault();
        uni_modal("Nuevo Estudiante", "manage_student.php", "mid-large");
    });

	$('.edit_student').click(function() {
		uni_modal("Gestionar Información de Estudiante", "manage_student.php?id=" + $(this).attr('data-id'), "mid-large");
	});

	// Doble clic en fila para abrir vista (usa data-id del botón Ver)
	$('#student-table tbody').on('dblclick', 'tr', function() {
		var $viewBtn = $(this).find('.view_student');
		if ($viewBtn.length) {
			uni_modal("Ver Estudiante", "view_student.php?id=" + $viewBtn.attr('data-id'), "mid-large");
		}
	});

	$('.delete_student').click(function() {
		confirmStudentDeletion(this);
	});

	function confirmStudentDeletion(button) {
		var $button = $(button);
		var name = $button.attr('data-name') || 'este estudiante';
		var dni = $button.attr('data-dni') || '';
		var details = dni ? '<br><small class="text-muted">DNI: ' + $('<div>').text(dni).html() + '</small>' : '';
		var message = '<div class="text-center">' +
			'<i class="fas fa-exclamation-triangle text-warning fa-2x mb-2"></i>' +
			'<p class="mb-1">¿Está seguro de eliminar a <strong>' + $('<div>').text(name).html() + '</strong>?</p>' +
			details +
			'<small class="d-block text-danger mt-2">Esta acción eliminará sus datos relacionados y no se puede deshacer.</small>' +
		'</div>';
		_conf(message, 'delete_student', [$button.attr('data-id')]);
	}

window.delete_student = function(id) {
		if(typeof start_load === 'function') start_load();
		// Cerrar cualquier modal de confirmación abierto y limpiar backdrop
		try {
			if ($('.modal.show').length) {
				$('.modal.show').modal('hide');
			}
			$('.modal-backdrop').remove();
			$('body').removeClass('modal-open');
		} catch (err) {
			console.warn('No se pudo cerrar el modal explícitamente:', err);
		}
		$.ajax({
			url: 'ajax.php?action=delete_student',
			method: 'POST',
			data: { id: id, csrf_token: <?php echo json_encode($csrf_token); ?> },
			dataType: 'json',
			success: function(resp) {
				if (resp.status == 1) {
					alert_toast("Estudiante eliminado exitosamente.", 'success');
					try { $('#student-table').DataTable().ajax.reload(null, false); } catch(_) {}
					if(typeof end_load === 'function') end_load();
				} else {
					alert_toast(resp.message || "Error al eliminar el estudiante.", 'danger');
					if(typeof end_load === 'function') end_load();
				}
			},
			error: function(err) {
				console.error("Error en la solicitud AJAX:", err);
				alert_toast("Error en el servidor. Intente nuevamente más tarde.", 'danger');
				if(typeof end_load === 'function') end_load();
			}
		});
	}

    // Abrir modal para subir Excel
	$('#upload_excel, #action_upload_excel').off('click.studentsUpload').on('click.studentsUpload', function() {
        $('#uploadExcelModal').modal('show');
    });
	
	// Manejar la descarga del formato Excel
	function handleFormatDownload() {
		if(typeof start_load === 'function') start_load(); // Mostrar pantalla de carga
		
		// Crear un iframe oculto para la descarga
		var iframe = document.createElement('iframe');
		iframe.style.display = 'none';
		iframe.src = 'download_format.php';
		document.body.appendChild(iframe);
		
		// Ocultar el loader después de un tiempo razonable para la descarga
		setTimeout(function() {
			if(typeof end_load === 'function') end_load(); // Quitar pantalla de carga
			// Remover el iframe después de un tiempo adicional
			setTimeout(function() {
				document.body.removeChild(iframe);
			}, 5000);
		}, 2000);
	} 
	
    // Asignar handler al botón de descarga de formato
	$('#download_format, #download_format_link, #action_download_format').off('click.studentsDownload').on('click.studentsDownload', function(e) {
		e.preventDefault();
		handleFormatDownload();
	});
	
	// Manejar el envío del formulario de Excel
	$(document).off('submit.studentsUpload', '#upload-excel-form').on('submit.studentsUpload', '#upload-excel-form', function(e) {
		e.preventDefault(); // Prevenir el comportamiento predeterminado del formulario
		e.stopPropagation(); // Detener la propagación del evento
		var form = this;
		var $form = $(form);
		var $submitButton = $form.find('button[type="submit"]');
		if ($form.data('uploading')) return false;
		
		// Verificar que se seleccionó un archivo
		var fileInput = form.querySelector('input[type="file"]');
		if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
			alert_toast('Por favor, seleccione un archivo Excel.', 'warning');
			return false;
		} 

		$form.data('uploading', true);
		$submitButton.prop('disabled', true);
		if(typeof start_load === 'function') start_load();
		var formData = new FormData(form);
		
		$.ajax({
			url: 'ajax.php?action=upload_excel',
			method: 'POST',
			data: formData,
			cache: false,
			contentType: false,
			processData: false,
			success: function(resp) {
				$form.data('uploading', false);
				$submitButton.prop('disabled', false);
				if(typeof end_load === 'function') end_load();
				try {
					// Si la respuesta es un string, convertirla a objeto
					if (typeof resp === 'string') {
						resp = JSON.parse(resp);
					}
					
					if (resp.status === 'success') {
						alert_toast(resp.message, 'success');
						$('#uploadExcelModal').modal('hide');
						try { $('#student-table').DataTable().ajax.reload(null, false); } catch(_) {}
					} else {
						alert_toast(resp.message || 'Error desconocido.', 'danger');
					}
				} catch (err) {
					console.error('Error al procesar la respuesta:', err);
					console.log('Respuesta del servidor:', resp);
					alert_toast('Error inesperado. Verifique la consola.', 'danger');
				}
			},
			error: function(xhr, status, error) {
				$form.data('uploading', false);
				$submitButton.prop('disabled', false);
					if(typeof end_load === 'function') end_load();
				console.error('Error en la solicitud AJAX:', status, error);
				console.log('Respuesta del servidor:', xhr.responseText);
				alert_toast('Error en el servidor. Intente nuevamente.', 'danger');
			}
		});
		
		return false; // Importante: retornar false para evitar el envío del formulario
	});
	
	// Mejoras de accesibilidad y UX para el input de archivo Excel
	$(document).on('change', '.custom-file-input', function (e) {
		var fileName = e.target.files[0] ? e.target.files[0].name : '';
		$(this).next('.custom-file-label').html(fileName ? fileName : 'Seleccionar archivo...');
	});

    })(window.jQuery);
})();
</script>
