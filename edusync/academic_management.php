<?php include('db_connect.php'); ?>

<style>
/* Estilos para la vista unificada */
.management-tabs {
    margin-bottom: 20px;
}

.management-tabs .nav-link {
    padding: 12px 24px;
    font-weight: 500;
    border-radius: 8px 8px 0 0;
    margin-right: 5px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 1px solid #dee2e6;
    color: #6c757d;
    transition: all 0.3s ease;
}

.management-tabs .nav-link.active {
    background: linear-gradient(135deg, #4285f4 0%, #3367d6 100%);
    color: white;
    border-color: #4285f4;
    box-shadow: 0 2px 4px rgba(66, 133, 244, 0.3);
}

.management-tabs .nav-link:hover {
    background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
    color: #2c3e50;
    transform: translateY(-1px);
}

.management-tabs .nav-link.active:hover {
    background: linear-gradient(135deg, #3367d6 0%, #2a5bc7 100%);
    color: white;
}

.tab-content {
    border: 1px solid #dee2e6;
    border-top: none;
    border-radius: 0 0 8px 8px;
    padding: 20px;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.area-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    margin-bottom: 15px;
    transition: all 0.3s ease;
}

.area-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.area-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 15px;
    border-bottom: 1px solid #dee2e6;
    border-radius: 8px 8px 0 0;
}

.area-title {
    font-size: 1.2rem;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.area-color-badge {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: inline-block;
}

.area-actions {
    margin-top: 10px;
}

.courses-in-area {
    padding: 15px;
}

.course-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    margin: 5px 0;
    background: #f8f9fa;
    border-radius: 6px;
    border-left: 4px solid #007bff;
}

.course-item:hover {
    background: #e9ecef;
}

.course-info {
    flex: 1;
}

.course-name {
    font-weight: 500;
    color: #495057;
}

.course-level {
    font-size: 0.85rem;
    color: #6c757d;
}

.course-actions {
    display: flex;
    gap: 5px;
}

.no-courses {
    text-align: center;
    color: #6c757d;
    font-style: italic;
    padding: 20px;
}

.stats-card {
    background: white;
    color: #495057;
    border-radius: 0.35rem;
    padding: 20px;
    margin-bottom: 15px;
    border-left: 0.25rem solid #4e73df;
    transition: all 0.2s ease;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

/* Iconos y números de estadísticas simplificados */
.stats-icon {
    font-size: 2rem;
    margin-bottom: 10px;
    color: #4e73df;
}

.stats-number {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.stats-label {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 500;
}

/* Estilos para tablas simplificados */
.table-responsive {
    border-radius: 4px;
    overflow: hidden;
    border: 1px solid #dee2e6;
}

.table {
    margin-bottom: 0;
}

.table th {
    background-color: #f8f9fc;
    border-bottom: 2px solid #e3e6f0;
    font-weight: 600;
    color: #858796;
    padding: 12px 8px;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
}

.table td {
    padding: 12px 10px;
    vertical-align: middle;
    border-top: 1px solid #e3e6f0;
    color: #5a5c69;
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: rgba(0,0,0,.02);
}

.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.075);
}

.btn-sm {
    padding: 4px 8px;
    font-size: 0.875rem;
    margin: 0 2px;
}

/* Botones personalizados con el color del sistema */
.btn-primary {
    background: linear-gradient(135deg, #4285f4 0%, #3367d6 100%);
    border-color: #4285f4;
    box-shadow: 0 2px 4px rgba(66, 133, 244, 0.2);
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #3367d6 0%, #2a5bc7 100%);
    border-color: #3367d6;
    box-shadow: 0 4px 8px rgba(66, 133, 244, 0.3);
    transform: translateY(-1px);
}

.btn-primary:focus {
    box-shadow: 0 0 0 0.2rem rgba(66, 133, 244, 0.25);
}

.btn-primary:active {
    background: linear-gradient(135deg, #2a5bc7 0%, #1e4a9c 100%);
    border-color: #2a5bc7;
}

/* Estilos simplificados para la jerarquía: Niveles > Áreas > Cursos */
.level-container {
    margin-bottom: 30px;
    background: white;
    border-radius: 6px;
    border: 1px solid #e9ecef;
    overflow: hidden;
}

.level-header {
    background: #f8f9fa;
    color: #495057;
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
}

.level-title {
    color: #495057;
    font-weight: 600;
    margin: 0;
    font-size: 1.25em;
    display: flex;
    align-items: center;
    gap: 8px;
}

.level-title i {
    font-size: 1em;
    color: #6c757d;
}

.areas-in-level {
    padding: 20px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 15px;
}

.area-card {
    background: white;
    border-radius: 4px;
    border: 1px solid #dee2e6;
    transition: all 0.2s ease;
    overflow: hidden;
}

.area-card:hover {
    border-color: #4e73df;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
    transform: translateY(-2px);
}

.area-header {
    padding: 15px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.area-title {
    color: #495057;
    font-weight: 600;
    margin-bottom: 5px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.1em;
}

.area-color-badge {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}

.area-actions {
    margin-top: 10px;
    display: flex;
    justify-content: flex-end;
}

/* Botones de acción para áreas */
.area-actions {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.area-actions .btn {
    padding: 4px 8px;
    font-size: 0.8rem;
    border-radius: 4px;
    transition: all 0.2s ease;
    min-width: 32px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.area-actions .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.area-actions .btn i {
    font-size: 0.9rem;
}

.courses-in-area {
    padding: 15px;
}

.courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 8px;
}

.course-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: #f8f9fc;
    border-radius: 0.35rem;
    border: 1px solid #e3e6f0;
    border-left: 3px solid #4e73df;
    transition: all 0.2s ease;
}

.course-item:hover {
    background: #eaecf4;
    border-color: #4e73df;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.course-info {
    flex: 1;
}

.course-name {
    font-weight: 500;
    color: #495057;
    margin-bottom: 2px;
    font-size: 0.9em;
}

.course-description {
    font-size: 0.8em;
    color: #6c757d;
}

.course-actions {
    display: flex;
    gap: 3px;
}

.no-courses, .no-areas {
    text-align: center;
    color: #6c757d;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 4px;
    border: 1px solid #dee2e6;
    margin: 15px 0;
}

.no-areas {
    background: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
}

/* Navegación por niveles simplificada */
.level-navigation {
    background: white;
    border-radius: 4px;
    padding: 15px;
    border: 1px solid #dee2e6;
    margin-bottom: 20px;
}

.level-navigation .nav-pills .nav-link {
    border-radius: 4px;
    padding: 8px 16px;
    margin: 0 3px;
    font-weight: 500;
    transition: all 0.2s ease;
    border: 1px solid #dee2e6;
    color: #495057;
}

.level-navigation .nav-pills .nav-link:hover {
    background: #f8f9fc;
    border-color: #4e73df;
    color: #4e73df;
}

.level-navigation .nav-pills .nav-link.active {
    background: #4e73df;
    border-color: #4e73df;
    color: white;
}

.level-navigation .nav-pills .nav-link i {
    margin-right: 6px;
    font-size: 1em;
}

/* Responsive para navegación */
@media (max-width: 768px) {
    .level-navigation .nav-pills {
        flex-direction: column;
    }
    
    .level-navigation .nav-pills .nav-link {
        margin: 5px 0;
        text-align: center;
    }
}

/* Estilos de paginación */
.pagination {
    margin-top: 20px;
}

.pagination .page-link {
    color: #4e73df;
    border: 1px solid #dddfeb;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
}

.pagination .page-link:hover {
    background-color: #eaecf4;
    border-color: #dddfeb;
    color: #2e59d9;
}

.pagination .page-item.active .page-link {
    background-color: #4e73df;
    border-color: #4e73df;
    color: #fff;
}

.pagination .page-item.disabled .page-link {
    color: #858796;
    background-color: #fff;
    border-color: #dddfeb;
}

/* Responsive table fixes */
.table-responsive {
    display: block;
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border: 1px solid #e3e6f0;
    border-radius: 0.35rem;
}

/* Asegurar scroll en todas las tablas dinámicas */
#unified-view .table-responsive,
#inicial-view .table-responsive,
#primaria-view .table-responsive,
#secundaria-view .table-responsive {
    display: block;
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

#unified-view table,
#inicial-view table,
#primaria-view table,
#secundaria-view table {
    min-width: 100%;
}

@media (max-width: 768px) {
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .table td,
    .table th {
        white-space: nowrap;
        font-size: 0.85rem;
        padding: 10px 8px;
    }
    
    .btn-sm {
        padding: 3px 6px;
        font-size: 0.75rem;
    }
    
    /* Asegurar tablas dinámicas con scroll */
    #unified-view .table,
    #inicial-view .table,
    #primaria-view .table,
    #secundaria-view .table {
        min-width: 100%;
    }
}


</style>

<div class="container-fluid">
    <!-- Estadísticas generales -->
    <div class="row mb-4">
        <div class="col-md-12 mb-3">
            <h4 class="mb-0">Estadísticas Generales</h4>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Áreas Académicas
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="total-areas">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-layer-group fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Cursos Totales
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="total-courses">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-book fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Cursos Asignados
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="assigned-courses">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Sin Asignar
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="unassigned-courses">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exclamation-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    

    <!-- Pestañas de gestión -->
    <div class="management-tabs">
        <ul class="nav nav-tabs" id="managementTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="unified-tab" data-toggle="tab" data-target="#unified-pane" type="button" role="tab">
                    <i class="fa fa-sitemap"></i> Vista Unificada
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="areas-tab" data-toggle="tab" data-target="#areas-pane" type="button" role="tab">
                    <i class="fa fa-layer-group"></i> Áreas Académicas
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="courses-tab" data-toggle="tab" data-target="#courses-pane" type="button" role="tab">
                    <i class="fa fa-book"></i> Cursos / Capacidades
                </button>
            </li>
        </ul>
    </div>

    <!-- Contenido de las pestañas -->
    <div class="tab-content" id="managementTabsContent">
        <!-- Pestaña Vista Unificada -->
        <div class="tab-pane fade show active" id="unified-pane" role="tabpanel">
            <div class="row">
                <div class="col-md-12">
                    <!-- Navegación por niveles -->
                    <div class="level-navigation mb-4">
                        <div class="nav nav-pills nav-fill" id="level-tabs" role="tablist">
                            <a class="nav-item nav-link active" id="all-levels-tab" data-toggle="tab" href="#all-levels" role="tab">
                                <i class="fa fa-th-large"></i> Todos los Niveles
                            </a>
                            <a class="nav-item nav-link" id="inicial-tab" data-toggle="tab" href="#inicial" role="tab">
                                <i class="fa fa-baby"></i> Inicial
                            </a>
                            <a class="nav-item nav-link" id="primaria-tab" data-toggle="tab" href="#primaria" role="tab">
                                <i class="fa fa-child"></i> Primaria
                            </a>
                            <a class="nav-item nav-link" id="secundaria-tab" data-toggle="tab" href="#secundaria" role="tab">
                                <i class="fa fa-graduation-cap"></i> Secundaria
                            </a>
                        </div>
                    </div>
                    
                    <!-- Contenido de cada nivel -->
                    <div class="tab-content" id="level-tabContent">
                        <div class="tab-pane fade show active" id="all-levels" role="tabpanel">
                            <div id="unified-view">
                                <div class="text-center">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="sr-only">Cargando...</span>
                                    </div>
                                    <p class="mt-2">Cargando vista unificada...</p>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="inicial" role="tabpanel">
                            <div id="inicial-view">
                                <div class="text-center">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="sr-only">Cargando...</span>
                                    </div>
                                    <p class="mt-2">Cargando nivel Inicial...</p>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="primaria" role="tabpanel">
                            <div id="primaria-view">
                                <div class="text-center">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="sr-only">Cargando...</span>
                                    </div>
                                    <p class="mt-2">Cargando nivel Primaria...</p>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="secundaria" role="tabpanel">
                            <div id="secundaria-view">
                                <div class="text-center">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="sr-only">Cargando...</span>
                                    </div>
                                    <p class="mt-2">Cargando nivel Secundaria...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pestaña de Áreas -->
        <div class="tab-pane fade" id="areas-pane" role="tabpanel">
            <div class="row mb-3">
                <div class="col-md-12">
                    <button class="btn btn-primary" id="new_area">
                        <i class="fa fa-plus"></i> Nueva Área
                    </button>
                </div>
            </div>
            
            <!-- Sub-pestañas por nivel para Áreas -->
            <div class="level-navigation mb-4">
                <div class="nav nav-pills nav-fill" id="areas-level-tabs" role="tablist">
                    <a class="nav-item nav-link active" id="areas-all-levels-tab" data-toggle="tab" href="#areas-all-levels" role="tab">
                        <i class="fa fa-th-large"></i> Todas las Áreas
                    </a>
                    <a class="nav-item nav-link" id="areas-inicial-tab" data-toggle="tab" href="#areas-inicial" role="tab">
                        <i class="fa fa-baby"></i> Inicial
                    </a>
                    <a class="nav-item nav-link" id="areas-primaria-tab" data-toggle="tab" href="#areas-primaria" role="tab">
                        <i class="fa fa-child"></i> Primaria
                    </a>
                    <a class="nav-item nav-link" id="areas-secundaria-tab" data-toggle="tab" href="#areas-secundaria" role="tab">
                        <i class="fa fa-graduation-cap"></i> Secundaria
                    </a>
                </div>
            </div>
            
            <!-- Contenido de las sub-pestañas de Áreas -->
            <div class="tab-content" id="areas-level-tabContent">
                <!-- Todas las Áreas -->
                <div class="tab-pane fade show active" id="areas-all-levels" role="tabpanel">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Lista de Áreas Académicas - Todos los Niveles</h6>
                                </div>
                                <div class="card-body">
                                    <!-- Buscador de Áreas -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text">
                                                        <i class="fa fa-search"></i>
                                                    </span>
                                                </div>
                                                <input type="text" class="form-control" id="areas_search" placeholder="Buscar áreas por nombre o descripción...">
                                                <div class="input-group-append">
                                                    <button class="btn btn-outline-secondary" type="button" id="clear_areas_search">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 text-right">
                                            <small class="text-muted">
                                                <span id="areas_search_info">Mostrando todas las áreas</span>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-condensed table-bordered table-hover" id="areas_table">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">#</th>
                                                    <th>Área</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Color</th>
                                                    <th class="text-center">Cursos</th>
                                                    <th class="text-center">Estado</th>
                                                    <th class="text-center">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php
                                            $i = 1;
                                            $school_id = $_SESSION['login_school_id'] ?? 0;
                                            $areas = $conn->query("SELECT a.*, 
                                                (SELECT COUNT(*) FROM academic_courses ac WHERE ac.area_id = a.id) as course_count
                                                FROM areas a 
                                                WHERE a.school_id = $school_id 
                                                ORDER BY a.name ASC");
                                            while($row = $areas->fetch_assoc()):
                                            ?>
                                            <tr>
                                                <td class="text-center"><?php echo $i++ ?></td>
                                                <td><?php echo ucwords($row['name']) ?></td>
                                                <td><?php echo $row['description'] ?></td>
                                                <td class="text-center">
                                                    <span class="badge" style="background-color: <?php echo $row['color'] ?>; color: white; padding: 5px 10px;">
                                                        <?php echo $row['color'] ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-info"><?php echo $row['course_count'] ?> cursos</span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if($row['is_active']): ?>
                                                        <span class="badge badge-success">Activo</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Inactivo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <button class="btn btn-primary btn-sm edit_area" type="button" data-id="<?php echo $row['id'] ?>">
                                                        <i class="fa fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm delete_area" type="button" data-id="<?php echo $row['id'] ?>">
                                                        <i class="fa fa-trash-alt"></i>
                                                    </button>
                                                    <button class="btn btn-info btn-sm view_courses" type="button" data-id="<?php echo $row['id'] ?>" data-name="<?php echo $row['name'] ?>">
                                                        <i class="fa fa-eye"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Paginación -->
                                    <div id="areas_pagination" class="mt-3">
                                        <!-- La paginación se carga aquí dinámicamente -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Áreas por Nivel -->
                <div class="tab-pane fade" id="areas-inicial" role="tabpanel">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Áreas Académicas - Nivel Inicial</h6>
                                </div>
                                <div class="card-body">
                                    <!-- Buscador de Áreas Inicial -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text">
                                                        <i class="fa fa-search"></i>
                                                    </span>
                                                </div>
                                                <input type="text" class="form-control" id="areas_inicial_search" placeholder="Buscar áreas del nivel Inicial...">
                                                <div class="input-group-append">
                                                    <button class="btn btn-outline-secondary" type="button" id="clear_areas_inicial_search">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 text-right">
                                            <small class="text-muted">
                                                <span id="areas_inicial_search_info">Mostrando todas las áreas</span>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-condensed table-bordered table-hover" id="areas_inicial_table">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">#</th>
                                                    <th>Área</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Color</th>
                                                    <th class="text-center">Cursos</th>
                                                    <th class="text-center">Estado</th>
                                                    <th class="text-center">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted">
                                                        <div class="spinner-border text-primary" role="status">
                                                            <span class="sr-only">Cargando...</span>
                                                        </div>
                                                        <p class="mt-2">Cargando áreas del nivel Inicial...</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Paginación -->
                                    <div id="areas_inicial_pagination" class="mt-3">
                                        <!-- La paginación se carga aquí dinámicamente -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="areas-primaria" role="tabpanel">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Áreas Académicas - Nivel Primaria</h6>
                                </div>
                                <div class="card-body">
                                    <!-- Buscador de Áreas Primaria -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text">
                                                        <i class="fa fa-search"></i>
                                                    </span>
                                                </div>
                                                <input type="text" class="form-control" id="areas_primaria_search" placeholder="Buscar áreas del nivel Primaria...">
                                                <div class="input-group-append">
                                                    <button class="btn btn-outline-secondary" type="button" id="clear_areas_primaria_search">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 text-right">
                                            <small class="text-muted">
                                                <span id="areas_primaria_search_info">Mostrando todas las áreas</span>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-condensed table-bordered table-hover" id="areas_primaria_table">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">#</th>
                                                    <th>Área</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Color</th>
                                                    <th class="text-center">Cursos</th>
                                                    <th class="text-center">Estado</th>
                                                    <th class="text-center">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted">
                                                        <div class="spinner-border text-primary" role="status">
                                                            <span class="sr-only">Cargando...</span>
                                                        </div>
                                                        <p class="mt-2">Cargando áreas del nivel Primaria...</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Paginación -->
                                    <div id="areas_primaria_pagination" class="mt-3">
                                        <!-- La paginación se carga aquí dinámicamente -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="areas-secundaria" role="tabpanel">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Áreas Académicas - Nivel Secundaria</h6>
                                </div>
                                <div class="card-body">
                                    <!-- Buscador de Áreas Secundaria -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text">
                                                        <i class="fa fa-search"></i>
                                                    </span>
                                                </div>
                                                <input type="text" class="form-control" id="areas_secundaria_search" placeholder="Buscar áreas del nivel Secundaria...">
                                                <div class="input-group-append">
                                                    <button class="btn btn-outline-secondary" type="button" id="clear_areas_secundaria_search">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 text-right">
                                            <small class="text-muted">
                                                <span id="areas_secundaria_search_info">Mostrando todas las áreas</span>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-condensed table-bordered table-hover" id="areas_secundaria_table">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">#</th>
                                                    <th>Área</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Color</th>
                                                    <th class="text-center">Cursos</th>
                                                    <th class="text-center">Estado</th>
                                                    <th class="text-center">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted">
                                                        <div class="spinner-border text-primary" role="status">
                                                            <span class="sr-only">Cargando...</span>
                                                        </div>
                                                        <p class="mt-2">Cargando áreas del nivel Secundaria...</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Paginación -->
                                    <div id="areas_secundaria_pagination" class="mt-3">
                                        <!-- La paginación se carga aquí dinámicamente -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pestaña de Cursos -->
        <div class="tab-pane fade" id="courses-pane" role="tabpanel">
            <div class="row mb-3">
                <div class="col-md-12">
                    <button class="btn btn-primary" id="new_course">
                        <i class="fa fa-plus"></i> Nuevo Curso / Capacidad
                    </button>
                </div>
            </div>
            
            <!-- Sub-pestañas por nivel para Cursos -->
            <div class="level-navigation mb-4">
                <div class="nav nav-pills nav-fill" id="courses-level-tabs" role="tablist">
                    <a class="nav-item nav-link active" id="courses-all-levels-tab" data-toggle="tab" href="#courses-all-levels" role="tab">
                        <i class="fa fa-th-large"></i> Todos los Cursos
                    </a>
                    <a class="nav-item nav-link" id="courses-inicial-tab" data-toggle="tab" href="#courses-inicial" role="tab">
                        <i class="fa fa-baby"></i> Inicial
                    </a>
                    <a class="nav-item nav-link" id="courses-primaria-tab" data-toggle="tab" href="#courses-primaria" role="tab">
                        <i class="fa fa-child"></i> Primaria
                    </a>
                    <a class="nav-item nav-link" id="courses-secundaria-tab" data-toggle="tab" href="#courses-secundaria" role="tab">
                        <i class="fa fa-graduation-cap"></i> Secundaria
                    </a>
                </div>
            </div>
            
            <!-- Contenido de las sub-pestañas de Cursos -->
            <div class="tab-content" id="courses-level-tabContent">
                <!-- Todos los Cursos -->
                <div class="tab-pane fade show active" id="courses-all-levels" role="tabpanel">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Lista de Cursos / Capacidades - Todos los Niveles</h6>
                                </div>
                                <div class="card-body">
                                    <!-- Buscador de Cursos -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text">
                                                        <i class="fa fa-search"></i>
                                                    </span>
                                                </div>
                                                <input type="text" class="form-control" id="courses_search" placeholder="Buscar cursos por nombre, área o descripción...">
                                                <div class="input-group-append">
                                                    <button class="btn btn-outline-secondary" type="button" id="clear_courses_search">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 text-right">
                                            <small class="text-muted">
                                                <span id="courses_search_info">Mostrando todos los cursos</span>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-condensed table-bordered table-hover" id="courses_table">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">#</th>
                                                    <th>Nombre</th>
                                                    <th>Área</th>
                                                    <th>Nivel</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php
                                            $i = 1;
                                            $courses = $conn->query("SELECT ac.*, a.name as area_name, a.color as area_color 
                                                FROM academic_courses ac 
                                                LEFT JOIN areas a ON ac.area_id = a.id 
                                                WHERE ac.school_id = $school_id 
                                                ORDER BY a.name ASC, ac.name ASC");
                                            while($row = $courses->fetch_assoc()):
                                            ?>
                                            <tr>
                                                <td class="text-center"><?php echo $i++ ?></td>
                                                <td><?php echo ucwords($row['name']) ?></td>
                                                <td>
                                                    <?php if($row['area_name']): ?>
                                                        <span class="badge" style="background-color: <?php echo $row['area_color'] ?>; color: white;">
                                                            <?php echo $row['area_name'] ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Sin área</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $row['level'] ?></td>
                                                <td><?php echo $row['description'] ?></td>
                                                <td class="text-center">
                                                    <button class="btn btn-primary btn-sm edit_course" type="button" data-id="<?php echo $row['id'] ?>">
                                                        <i class="fa fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm delete_course" type="button" data-id="<?php echo $row['id'] ?>">
                                                        <i class="fa fa-trash-alt"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Paginación -->
                                    <div id="courses_pagination" class="mt-3">
                                        <!-- La paginación se carga aquí dinámicamente -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Cursos por Nivel -->
                <div class="tab-pane fade" id="courses-inicial" role="tabpanel">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Cursos / Capacidades - Nivel Inicial</h6>
                                </div>
                                <div class="card-body">
                                    <!-- Buscador de Cursos Inicial -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text">
                                                        <i class="fa fa-search"></i>
                                                    </span>
                                                </div>
                                                <input type="text" class="form-control" id="courses_inicial_search" placeholder="Buscar cursos del nivel Inicial...">
                                                <div class="input-group-append">
                                                    <button class="btn btn-outline-secondary" type="button" id="clear_courses_inicial_search">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 text-right">
                                            <small class="text-muted">
                                                <span id="courses_inicial_search_info">Mostrando todos los cursos</span>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-condensed table-bordered table-hover" id="courses_inicial_table">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">#</th>
                                                    <th>Nombre</th>
                                                    <th>Área</th>
                                                    <th>Nivel</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">
                                                        <div class="spinner-border text-primary" role="status">
                                                            <span class="sr-only">Cargando...</span>
                                                        </div>
                                                        <p class="mt-2">Cargando cursos del nivel Inicial...</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Paginación -->
                                    <div id="courses_inicial_pagination" class="mt-3">
                                        <!-- La paginación se carga aquí dinámicamente -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="courses-primaria" role="tabpanel">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Cursos / Capacidades - Nivel Primaria</h6>
                                </div>
                                <div class="card-body">
                                    <!-- Buscador de Cursos Primaria -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text">
                                                        <i class="fa fa-search"></i>
                                                    </span>
                                                </div>
                                                <input type="text" class="form-control" id="courses_primaria_search" placeholder="Buscar cursos del nivel Primaria...">
                                                <div class="input-group-append">
                                                    <button class="btn btn-outline-secondary" type="button" id="clear_courses_primaria_search">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 text-right">
                                            <small class="text-muted">
                                                <span id="courses_primaria_search_info">Mostrando todos los cursos</span>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-condensed table-bordered table-hover" id="courses_primaria_table">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">#</th>
                                                    <th>Nombre</th>
                                                    <th>Área</th>
                                                    <th>Nivel</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">
                                                        <div class="spinner-border text-primary" role="status">
                                                            <span class="sr-only">Cargando...</span>
                                                        </div>
                                                        <p class="mt-2">Cargando cursos del nivel Primaria...</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Paginación -->
                                    <div id="courses_primaria_pagination" class="mt-3">
                                        <!-- La paginación se carga aquí dinámicamente -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="courses-secundaria" role="tabpanel">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Cursos / Capacidades - Nivel Secundaria</h6>
                                </div>
                                <div class="card-body">
                                    <!-- Buscador de Cursos Secundaria -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text">
                                                        <i class="fa fa-search"></i>
                                                    </span>
                                                </div>
                                                <input type="text" class="form-control" id="courses_secundaria_search" placeholder="Buscar cursos del nivel Secundaria...">
                                                <div class="input-group-append">
                                                    <button class="btn btn-outline-secondary" type="button" id="clear_courses_secundaria_search">
                                                        <i class="fa fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 text-right">
                                            <small class="text-muted">
                                                <span id="courses_secundaria_search_info">Mostrando todos los cursos</span>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-condensed table-bordered table-hover" id="courses_secundaria_table">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">#</th>
                                                    <th>Nombre</th>
                                                    <th>Área</th>
                                                    <th>Nivel</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">
                                                        <div class="spinner-border text-primary" role="status">
                                                            <span class="sr-only">Cargando...</span>
                                                        </div>
                                                        <p class="mt-2">Cargando cursos del nivel Secundaria...</p>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Paginación -->
                                    <div id="courses_secundaria_pagination" class="mt-3">
                                        <!-- La paginación se carga aquí dinámicamente -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Cargar estadísticas
    loadStats();
    
    // Cargar vista unificada automáticamente al abrir la página
    loadUnifiedView();
    
    // Agregar estilos a las tablas
    try {
        addTableStyling();
    } catch(e) {
        console.log('Error al agregar estilos:', e);
    }

    // Eventos para áreas usando delegación de eventos
    $(document).on('click', '#new_area', function(){
        uni_modal("Nueva Área", "manage_area.php", "mid-large");
    });

    $(document).on('click', '.edit_area', function(){
        uni_modal("Editar Área", "manage_area.php?id=" + $(this).attr('data-id'), "mid-large");
    });

    $(document).on('click', '.delete_area', function(){
        _conf("¿Estás seguro de eliminar esta área?", "delete_area", [$(this).attr('data-id')]);
    });

    $(document).on('click', '.view_courses', function(){
        uni_modal("Cursos / Capacidades de " + $(this).attr('data-name'), "area_courses.php?id=" + $(this).attr('data-id'), "modal-xl");
    });

    // Eventos para cursos usando delegación de eventos

    $(document).on('click', '.edit_course', function(){
        uni_modal("Editar Curso / Capacidad", "manage_academic_course.php?id=" + $(this).attr('data-id'), "mid-large");
    });

    $(document).on('click', '.delete_course', function(){
        _conf("¿Estás seguro de eliminar este curso?", "delete_academic_course", [$(this).attr('data-id')]);
    });


    // Cargar vista unificada cuando se seleccione la pestaña
    $('#unified-tab').click(function(){
        loadUnifiedView();
    });
    
    // Navegación por niveles
    $('#all-levels-tab').click(function(){
        loadUnifiedView();
    });
    
    $('#inicial-tab').click(function(){
        loadLevelView('Inicial');
    });
    
    $('#primaria-tab').click(function(){
        loadLevelView('Primaria');
    });
    
    $('#secundaria-tab').click(function(){
        loadLevelView('Secundaria');
    });
    
    // Event listeners para sub-pestañas de Áreas
    $('#areas-all-levels-tab').click(function(){
        loadAreasByLevel('all');
    });
    
    $('#areas-inicial-tab').click(function(){
        loadAreasByLevel('inicial');
    });
    
    $('#areas-primaria-tab').click(function(){
        loadAreasByLevel('primaria');
    });
    
    $('#areas-secundaria-tab').click(function(){
        loadAreasByLevel('secundaria');
    });
    
    // Event listeners para buscadores de áreas
    $('#areas_search').on('keyup', function() {
        var search = $(this).val();
        $('#areas_search_info').text(search ? 'Buscando: "' + search + '"' : 'Mostrando todas las áreas');
        loadAreasTable(1, search);
    });
    
    $('#clear_areas_search').click(function() {
        $('#areas_search').val('');
        $('#areas_search_info').text('Mostrando todas las áreas');
        loadAreasTable(1, '');
    });
    
    // Event listeners para buscadores de áreas por nivel
    $('#areas_inicial_search').on('keyup', function() {
        var search = $(this).val();
        $('#areas_inicial_search_info').text(search ? 'Buscando: "' + search + '"' : 'Mostrando todas las áreas');
        loadAreasByLevel('inicial', 1, search);
    });
    
    $('#clear_areas_inicial_search').click(function() {
        $('#areas_inicial_search').val('');
        $('#areas_inicial_search_info').text('Mostrando todas las áreas');
        loadAreasByLevel('inicial', 1, '');
    });
    
    $('#areas_primaria_search').on('keyup', function() {
        var search = $(this).val();
        $('#areas_primaria_search_info').text(search ? 'Buscando: "' + search + '"' : 'Mostrando todas las áreas');
        loadAreasByLevel('primaria', 1, search);
    });
    
    $('#clear_areas_primaria_search').click(function() {
        $('#areas_primaria_search').val('');
        $('#areas_primaria_search_info').text('Mostrando todas las áreas');
        loadAreasByLevel('primaria', 1, '');
    });
    
    $('#areas_secundaria_search').on('keyup', function() {
        var search = $(this).val();
        $('#areas_secundaria_search_info').text(search ? 'Buscando: "' + search + '"' : 'Mostrando todas las áreas');
        loadAreasByLevel('secundaria', 1, search);
    });
    
    $('#clear_areas_secundaria_search').click(function() {
        $('#areas_secundaria_search').val('');
        $('#areas_secundaria_search_info').text('Mostrando todas las áreas');
        loadAreasByLevel('secundaria', 1, '');
    });
    
    // Event listeners para sub-pestañas de Cursos
    $('#courses-all-levels-tab').click(function(){
        loadCoursesByLevel('all');
    });
    
    $('#courses-inicial-tab').click(function(){
        loadCoursesByLevel('inicial');
    });
    
    $('#courses-primaria-tab').click(function(){
        loadCoursesByLevel('primaria');
    });
    
    $('#courses-secundaria-tab').click(function(){
        loadCoursesByLevel('secundaria');
    });
    
    // Event listeners para buscadores de cursos
    $('#courses_search').on('keyup', function() {
        var search = $(this).val();
        $('#courses_search_info').text(search ? 'Buscando: "' + search + '"' : 'Mostrando todos los cursos');
        loadCoursesTable(1, search);
    });
    
    $('#clear_courses_search').click(function() {
        $('#courses_search').val('');
        $('#courses_search_info').text('Mostrando todos los cursos');
        loadCoursesTable(1, '');
    });
    
    // Event listeners para buscadores de cursos por nivel
    $('#courses_inicial_search').on('keyup', function() {
        var search = $(this).val();
        $('#courses_inicial_search_info').text(search ? 'Buscando: "' + search + '"' : 'Mostrando todos los cursos');
        loadCoursesByLevel('inicial', 1, search);
    });
    
    $('#clear_courses_inicial_search').click(function() {
        $('#courses_inicial_search').val('');
        $('#courses_inicial_search_info').text('Mostrando todos los cursos');
        loadCoursesByLevel('inicial', 1, '');
    });
    
    $('#courses_primaria_search').on('keyup', function() {
        var search = $(this).val();
        $('#courses_primaria_search_info').text(search ? 'Buscando: "' + search + '"' : 'Mostrando todos los cursos');
        loadCoursesByLevel('primaria', 1, search);
    });
    
    $('#clear_courses_primaria_search').click(function() {
        $('#courses_primaria_search').val('');
        $('#courses_primaria_search_info').text('Mostrando todos los cursos');
        loadCoursesByLevel('primaria', 1, '');
    });
    
    $('#courses_secundaria_search').on('keyup', function() {
        var search = $(this).val();
        $('#courses_secundaria_search_info').text(search ? 'Buscando: "' + search + '"' : 'Mostrando todos los cursos');
        loadCoursesByLevel('secundaria', 1, search);
    });
    
    $('#clear_courses_secundaria_search').click(function() {
        $('#courses_secundaria_search').val('');
        $('#courses_secundaria_search_info').text('Mostrando todos los cursos');
        loadCoursesByLevel('secundaria', 1, '');
    });
    
    
    // Reasignar eventos cuando se cambie de pestaña
    $('button[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        var target = $(e.target).attr('data-target');
        
        // Reasignar eventos para la pestaña activa
        if (target === '#courses-pane') {
            // Los eventos ya están delegados, no necesitan reasignación
            console.log('Pestaña de cursos activada');
        } else if (target === '#areas-pane') {
            console.log('Pestaña de áreas activada');
        }
    });
    
    // Delegación de eventos para botones dentro de modales
    $(document).on('click', '.remove_course_from_area', function(){
        var id = $(this).attr('data-id');
        var name = $(this).attr('data-name');
        _conf("¿Estás seguro de quitar el curso '" + name + "' de esta área?", "remove_course_from_area", [id]);
    });
    
    
    
    
    // Listener simple para el botón de agregar curso
    $(document).on('click', '#new_course', function(e){
        e.preventDefault();
        uni_modal("Crear Curso / Capacidad", "manage_academic_course.php", "mid-large");
    });
    
    // Función para actualizar todas las vistas dinámicamente
    window.forceUpdateAll = function() {
        console.log('Actualizando todas las vistas dinámicamente...');
        
        // Actualizar estadísticas
        if (typeof loadStats === 'function') {
            loadStats();
        }
        
        // Actualizar todas las vistas de la vista unificada
        if (typeof window.updateAllUnifiedViews === 'function') {
            window.updateAllUnifiedViews();
        }
        
        // Actualizar tablas principales
        if (typeof loadAreasTable === 'function') {
            loadAreasTable();
        }
        
        if (typeof loadCoursesTable === 'function') {
            loadCoursesTable();
        }
    };
    
    // Función específica para actualizar solo las tablas principales (sin afectar modales)
    window.updateMainTables = function() {
        console.log('Actualizando solo tablas principales...');
        
        // Actualizar estadísticas
        if (typeof loadStats === 'function') {
            loadStats();
        }
        
        // Actualizar todas las vistas de la vista unificada
        if (typeof window.updateAllUnifiedViews === 'function') {
            window.updateAllUnifiedViews();
        }
        
        // Actualizar tablas principales
        if (typeof loadAreasTable === 'function') {
            loadAreasTable();
        }
        
        if (typeof loadCoursesTable === 'function') {
            loadCoursesTable();
        }
    };
    
    // Función más segura para actualizar solo cuando no hay modales abiertos
    window.updateMainTablesSafely = function() {
        console.log('Actualizando tablas principales de forma segura...');
        
        // Actualizar todas las vistas de la vista unificada siempre (es seguro)
        if (typeof window.updateAllUnifiedViews === 'function') {
            window.updateAllUnifiedViews();
        }
        
        // Actualizar estadísticas siempre (es seguro)
        if (typeof loadStats === 'function') {
            loadStats();
        }
        
        // Solo actualizar tablas si no hay modales abiertos
        if (!$('.modal.show').length) {
            // Actualizar tablas principales
            if (typeof loadAreasTable === 'function') {
                loadAreasTable();
            }
            
            if (typeof loadCoursesTable === 'function') {
                loadCoursesTable();
            }
        } else {
            console.log('Hay modales abiertos, actualización de tablas diferida...');
            // Actualizar tablas después de un breve delay
            setTimeout(function() {
                if (!$('.modal.show').length) {
                    if (typeof loadAreasTable === 'function') {
                        loadAreasTable();
                    }
                    
                    if (typeof loadCoursesTable === 'function') {
                        loadCoursesTable();
                    }
                }
            }, 1000);
        }
    };
    
    // Función específica para actualizar solo la tabla de cursos y vista unificada
    window.updateCoursesAndUnifiedView = function() {
        console.log('Actualizando tabla de cursos y vista unificada...');
        
        // Actualizar todas las vistas de la vista unificada (siempre seguro)
        if (typeof window.updateAllUnifiedViews === 'function') {
            window.updateAllUnifiedViews();
        }
        
        // Actualizar tabla de cursos (solo si no hay modales abiertos)
        if (!$('.modal.show').length && typeof loadCoursesTable === 'function') {
            loadCoursesTable();
        }
    };
    
    
    // Listener para actualizar solo después de acciones específicas que no se manejan en sus modales
    $(document).on('click', '#confirm_modal #confirm', function() {
        var action = $(this).attr('onclick');
        // Ejecutar la acción del botón de confirmación
        if (action) {
            eval(action);
        }
    });
    
    // Listener para actualizar tablas cuando se cierren modales
    $(document).on('hidden.bs.modal', '.modal', function() {
        console.log('Modal cerrado, actualizando tablas...');
        
        // Prevenir actualizaciones duplicadas
        if (window.updatingAfterModalClose) {
            return;
        }
        
        window.updatingAfterModalClose = true;
        // Actualizar tablas después de cerrar cualquier modal
        setTimeout(function() {
            if (!$('.modal.show').length) {
                if (typeof loadAreasTable === 'function') {
                    loadAreasTable();
                }
                
                if (typeof loadCoursesTable === 'function') {
                    loadCoursesTable();
                }
                
                if (typeof loadStats === 'function') {
                    loadStats();
                }
            }
            window.updatingAfterModalClose = false;
        }, 500);
    });
    
    // Sistema de polling para verificar cambios cada 5 segundos (deshabilitado temporalmente)
    // setInterval(function() {
    //     // Solo actualizar si no hay modales abiertos
    //     if (!$('.modal.show').length) {
    //         loadStats();
    //     }
    // }, 5000);
    
});

// Función para cargar la tabla de áreas dinámicamente
window.loadAreasTable = function(page = 1, search = '') {
    console.log('Cargando tabla de áreas, página:', page, 'búsqueda:', search);
    $.ajax({
        url: 'ajax.php?action=get_areas_table',
        method: 'GET',
        data: { page: page, search: search },
        dataType: 'json',
        success: function(resp) {
            if(resp && resp.status == 1) {
                $('#areas_table tbody').html(resp.html);
                $('#areas_pagination').html(resp.pagination);
                console.log('Tabla de áreas actualizada - Página ' + resp.current_page + ' de ' + resp.total_pages);
            } else {
                console.log('Error al cargar tabla de áreas:', resp);
                $('#areas_table tbody').html('<tr><td colspan="7" class="text-center text-danger">Error al cargar datos</td></tr>');
                $('#areas_pagination').html('');
            }
        },
        error: function(xhr, status, error) {
            console.log('Error AJAX al cargar áreas:', error);
            $('#areas_table tbody').html('<tr><td colspan="7" class="text-center text-danger">Error de conexión: ' + error + '</td></tr>');
            $('#areas_pagination').html('');
        }
    });
}

// Función para cargar la tabla de cursos dinámicamente
window.loadCoursesTable = function(page = 1, search = '') {
    console.log('Cargando tabla de cursos, página:', page, 'búsqueda:', search);
    $.ajax({
        url: 'ajax.php?action=get_courses_table',
        method: 'GET',
        data: { page: page, search: search },
        dataType: 'json',
        success: function(resp) {
            if(resp && resp.status == 1) {
                $('#courses_table tbody').html(resp.html);
                $('#courses_pagination').html(resp.pagination);
                console.log('Tabla de cursos actualizada - Página ' + resp.current_page + ' de ' + resp.total_pages);
            } else {
                console.log('Error al cargar tabla de cursos:', resp);
                $('#courses_table tbody').html('<tr><td colspan="6" class="text-center text-danger">Error al cargar datos</td></tr>');
                $('#courses_pagination').html('');
            }
        },
        error: function(xhr, status, error) {
            console.log('Error AJAX al cargar cursos:', error);
            $('#courses_table tbody').html('<tr><td colspan="6" class="text-center text-danger">Error de conexión: ' + error + '</td></tr>');
            $('#courses_pagination').html('');
        }
    });
}

// Funciones para cargar tablas segmentadas por nivel
window.loadAreasByLevel = function(level, page = 1, search = '') {
    console.log('Cargando áreas del nivel:', level, 'página:', page, 'búsqueda:', search);
    var tableId = level === 'all' ? 'areas_table' : 'areas_' + level.toLowerCase() + '_table';
    var paginationId = level === 'all' ? 'areas_pagination' : 'areas_' + level.toLowerCase() + '_pagination';
    
    $.ajax({
        url: 'ajax.php?action=get_areas_by_level',
        method: 'GET',
        data: { level: level, page: page, search: search },
        dataType: 'json',
        success: function(resp) {
            if(resp && resp.status == 1) {
                $('#' + tableId + ' tbody').html(resp.html);
                $('#' + paginationId).html(resp.pagination);
                console.log('Tabla de áreas del nivel ' + level + ' actualizada - Página ' + resp.current_page + ' de ' + resp.total_pages);
            } else {
                console.log('Error al cargar áreas del nivel ' + level + ':', resp);
                $('#' + tableId + ' tbody').html('<tr><td colspan="7" class="text-center text-danger">Error al cargar datos</td></tr>');
                $('#' + paginationId).html('');
            }
        },
        error: function() {
            console.log('Error de conexión al cargar áreas del nivel ' + level);
            $('#' + tableId + ' tbody').html('<tr><td colspan="7" class="text-center text-danger">Error de conexión</td></tr>');
            $('#' + paginationId).html('');
        }
    });
}

window.loadCoursesByLevel = function(level, page = 1, search = '') {
    console.log('Cargando cursos del nivel:', level, 'página:', page, 'búsqueda:', search);
    var tableId = level === 'all' ? 'courses_table' : 'courses_' + level.toLowerCase() + '_table';
    var paginationId = level === 'all' ? 'courses_pagination' : 'courses_' + level.toLowerCase() + '_pagination';
    
    $.ajax({
        url: 'ajax.php?action=get_courses_by_level',
        method: 'GET',
        data: { level: level, page: page, search: search },
        dataType: 'json',
        success: function(resp) {
            if(resp && resp.status == 1) {
                $('#' + tableId + ' tbody').html(resp.html);
                $('#' + paginationId).html(resp.pagination);
                console.log('Tabla de cursos del nivel ' + level + ' actualizada - Página ' + resp.current_page + ' de ' + resp.total_pages);
            } else {
                console.log('Error al cargar cursos del nivel ' + level + ':', resp);
                $('#' + tableId + ' tbody').html('<tr><td colspan="6" class="text-center text-danger">Error al cargar datos</td></tr>');
                $('#' + paginationId).html('');
            }
        },
        error: function() {
            console.log('Error de conexión al cargar cursos del nivel ' + level);
            $('#' + tableId + ' tbody').html('<tr><td colspan="6" class="text-center text-danger">Error de conexión</td></tr>');
            $('#' + paginationId).html('');
        }
    });
}

function loadStats() {
    $.ajax({
        url: 'ajax.php?action=get_academic_stats',
        method: 'GET',
        dataType: 'json',
        success: function(resp) {
            if(resp.status == 1) {
                // Estadísticas de áreas y cursos
                $('#total-areas').text(resp.stats.total_areas);
                $('#total-courses').text(resp.stats.total_courses);
                $('#assigned-courses').text(resp.stats.assigned_courses);
                $('#unassigned-courses').text(resp.stats.unassigned_courses);
            }
        }
    });
}

function loadUnifiedView() {
    console.log('Cargando vista unificada...');
    $.ajax({
        url: 'ajax.php?action=get_unified_view',
        method: 'GET',
        dataType: 'json',
        success: function(resp) {
            console.log('Respuesta de vista unificada:', resp);
            if(resp.status == 1) {
                $('#unified-view').html(resp.html);
                console.log('Vista unificada cargada correctamente');
                
            } else {
                $('#unified-view').html('<div class="alert alert-danger">Error al cargar la vista unificada</div>');
                console.log('Error en respuesta:', resp);
            }
        },
        error: function(xhr, status, error) {
            console.log('Error AJAX:', error);
            $('#unified-view').html('<div class="alert alert-danger">Error de conexión: ' + error + '</div>');
        }
    });
}

// Función para actualizar todas las vistas de la vista unificada
window.updateAllUnifiedViews = function() {
    console.log('Actualizando todas las vistas de la vista unificada...');
    
    // Actualizar vista general
    if (typeof loadUnifiedView === 'function') {
        loadUnifiedView();
    }
    
    // Actualizar vistas por nivel
    if (typeof loadLevelView === 'function') {
        loadLevelView('Inicial');
        loadLevelView('Primaria');
        loadLevelView('Secundaria');
    }
};

function loadLevelView(level) {
    var targetId = level.toLowerCase() + '-view';
    
    $.ajax({
        url: 'ajax.php?action=get_level_view&level=' + level,
        method: 'GET',
        dataType: 'json',
        success: function(resp) {
            if(resp.status == 1) {
                $('#' + targetId).html(resp.html);
                
            } else {
                $('#' + targetId).html('<div class="alert alert-danger">Error al cargar el nivel ' + level + '</div>');
            }
        },
        error: function() {
            $('#' + targetId).html('<div class="alert alert-danger">Error de conexión</div>');
        }
    });
}

// Funciones de carga deshabilitadas - usar recarga de página
function loadAreasTable() {
    location.reload();
}

function loadCoursesTable() {
    location.reload();
}

// Funciones globales simplificadas
window.refreshAfterCourseAction = function() {
    location.reload();
};

window.refreshAfterAreaAction = function() {
    location.reload();
};

// Funciones de utilidad para las tablas (sin DataTables)
function addTableStyling() {
    // Agregar estilos básicos a las tablas
    $('#areas_table, #courses_table').addClass('table-striped table-hover');
}

window.delete_area = function($id){
    // Prevenir múltiples llamadas
    if (window.deletingArea) {
        console.log('Ya se está eliminando un área, ignorando llamada duplicada');
        return;
    }
    
    window.deletingArea = true;
    start_load();
    $.ajax({
        url: 'ajax.php?action=delete_area',
        method: 'POST',
        data: {id: $id},
        dataType: 'json',
        success: function(resp){
            console.log('Respuesta de eliminar área:', resp);
            if(resp.status == 1){
                // Cerrar el modal de confirmación
                $('#confirm_modal').modal('hide');
                alert_toast("Área eliminada exitosamente", "success");
                setTimeout(function(){
                    // Actualizar todas las vistas dinámicamente
                    if (typeof window.forceUpdateAll === 'function') {
                        window.forceUpdateAll();
                    }
                }, 1500);
            } else {
                // Cerrar el modal de confirmación
                $('#confirm_modal').modal('hide');
                var errorMsg = resp.message || resp.msg || "Error desconocido";
                console.log('Mensaje de error:', errorMsg);
                alert_toast("Error al eliminar el área: " + errorMsg, "danger");
            }
            end_load();
            window.deletingArea = false;
        },
        error: function(){
            // Cerrar el modal de confirmación
            $('#confirm_modal').modal('hide');
            alert_toast("Error de conexión", "error");
            end_load();
            window.deletingArea = false;
        }
    });
}

window.delete_academic_course = function($id){
    start_load();
    $.ajax({
        url: 'ajax.php?action=delete_academic_course',
        method: 'POST',
        data: {id: $id},
        dataType: 'json',
        success: function(resp){
            if(resp.status == 1){
                // Cerrar el modal de confirmación
                $('#confirm_modal').modal('hide');
                alert_toast("Curso eliminado exitosamente", "success");
                setTimeout(function(){
                    // Actualizar todas las vistas dinámicamente
                    if (typeof window.forceUpdateAll === 'function') {
                        window.forceUpdateAll();
                    }
                }, 1500);
            } else {
                // Cerrar el modal de confirmación
                $('#confirm_modal').modal('hide');
                alert_toast("Error al eliminar el curso: " + (resp.message || resp.msg || "Error desconocido"), "error");
            }
            end_load();
        },
        error: function(){
            // Cerrar el modal de confirmación
            $('#confirm_modal').modal('hide');
            alert_toast("Error de conexión", "error");
            end_load();
        }
    });
}

function removeCourseFromArea(courseId, courseName) {
    _conf("¿Estás seguro de quitar el curso '" + courseName + "' de esta área?", "remove_course_from_area", [courseId]);
}

// Función global para remover curso de área
window.remove_course_from_area = function($id){
    // Cerrar el modal de confirmación primero
    $('#confirm_modal').modal('hide');
    
    start_load();
    $.ajax({
        url: 'ajax.php?action=remove_course_from_area',
        method: 'POST',
        data: {id: $id},
        dataType: 'json',
        success: function(resp){
            if(resp.status == 1){
                alert_toast("Curso removido de la área exitosamente", "success");
                
                // Actualizar todas las vistas de academic_management dinámicamente
                if (typeof window.updateMainTablesSafely === 'function') {
                    window.updateMainTablesSafely();
                }
            } else {
                alert_toast("Error al remover el curso: " + (resp.message || resp.msg || "Error desconocido"), "error");
            }
            end_load();
        },
        error: function(){
            alert_toast("Error de conexión", "error");
            end_load();
        }
    });
}

// Función global para eliminar área
window.deleteArea = function(id, name) {
    _conf("¿Estás seguro de eliminar esta área?", "delete_area", [id]);
}
</script>
