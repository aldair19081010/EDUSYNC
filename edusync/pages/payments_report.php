<?php
include 'db_connect.php';

// Get school info
$school_id = intval($_SESSION['login_school_id'] ?? 0);

// Get Active Year
$active_year_q = $conn->query("SELECT id FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
$active_year_id = ($active_year_q && $active_year_q->num_rows > 0) ? $active_year_q->fetch_assoc()['id'] : '';

// Initialize Filter Variables
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$academic_year_id = isset($_GET['academic_year_id']) ? $_GET['academic_year_id'] : '';
$nivel_educativo = isset($_GET['nivel_educativo']) ? $_GET['nivel_educativo'] : '';
$grado = isset($_GET['grado']) ? $_GET['grado'] : '';
$seccion = isset($_GET['seccion']) ? $_GET['seccion'] : '';
$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : '';
$payment_method_id = isset($_GET['payment_method_id']) ? $_GET['payment_method_id'] : '';
$course_id_filter = isset($_GET['course_id']) ? $_GET['course_id'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$total_amount = 0;
$total_method_amount = 0;
$i = 1;
$period_label = "Todos los Pagos"; // Default for printing

$school_name = 'Institución Educativa'; // Default fallback
$school_logo = '';

$school_query = $conn->query("SELECT name, logo_path FROM schools WHERE id = $school_id LIMIT 1");
if ($school_query && $school_query->num_rows > 0) {
    $school_data = $school_query->fetch_assoc();
    
    if (!empty($school_data['name'])) {
        $school_name = $school_data['name'];
    }
    
    if (!empty($school_data['logo_path']) && file_exists($school_data['logo_path'])) {
        $school_logo = $school_data['logo_path'];
    }
}
?>

<div class="container-fluid py-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="fa fa-file-invoice-dollar mr-2"></i> Reporte de Pagos</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-filter mr-2"></i> Filtros del Reporte</h6>
        </div>
        <div class="card-body">
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="payments_report">
                <div class="row mb-3">
                    <div class="col-md-3 mb-2">
                        <label for="academic_year_id" class="small font-weight-bold mb-1">Año Académico</label>
                        <select class="form-control form-control-sm" name="academic_year_id" id="academic_year_id">
                            <option value="">Todos</option>
                            <?php
                            $years_q = $conn->query("SELECT id, year, is_active FROM academic_year WHERE school_id = $school_id ORDER BY year DESC");
                            while($row = $years_q->fetch_assoc()):
                            ?>
                            <option value="<?php echo $row['id']; ?>" <?php echo ($academic_year_id == $row['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['year']); ?><?php echo $row['is_active'] ? ' (Activo)' : ''; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="start_date" class="small font-weight-bold mb-1">Fecha Desde</label>
                        <input type="date" class="form-control form-control-sm" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="end_date" class="small font-weight-bold mb-1">Fecha Hasta</label>
                        <input type="date" class="form-control form-control-sm" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="nivel_educativo" class="small font-weight-bold mb-1">Nivel Educativo</label>
                        <select class="form-control form-control-sm" name="nivel_educativo" id="nivel_educativo">
                            <option value="">Todos</option>
                            <?php
                            $niveles_q = $conn->query("SELECT DISTINCT nivel FROM student WHERE nivel IS NOT NULL AND nivel != '' AND school_id = $school_id ORDER BY nivel ASC");
                            while($row = $niveles_q->fetch_assoc()):
                            ?>
                            <option value="<?php echo htmlspecialchars($row['nivel']); ?>" <?php echo ($nivel_educativo == $row['nivel']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($row['nivel']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 mb-2">
                        <label for="grado" class="small font-weight-bold mb-1">Grado</label>
                        <select class="form-control form-control-sm" name="grado" id="grado">
                            <option value="">Todos</option>
                            <?php
                            $grados_q = $conn->query("SELECT DISTINCT grado FROM student WHERE grado IS NOT NULL AND grado != '' AND school_id = $school_id ORDER BY grado ASC");
                            while($row = $grados_q->fetch_assoc()):
                            ?>
                            <option value="<?php echo htmlspecialchars($row['grado']); ?>" <?php echo ($grado == $row['grado']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($row['grado']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="seccion" class="small font-weight-bold mb-1">Sección</label>
                        <select class="form-control form-control-sm" name="seccion" id="seccion">
                            <option value="">Todas</option>
                            <?php
                            $secciones_q = $conn->query("SELECT DISTINCT seccion FROM student WHERE seccion IS NOT NULL AND seccion != '' AND school_id = $school_id ORDER BY seccion ASC");
                            while($row = $secciones_q->fetch_assoc()):
                            ?>
                            <option value="<?php echo htmlspecialchars($row['seccion']); ?>" <?php echo ($seccion == $row['seccion']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($row['seccion']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="status" class="small font-weight-bold mb-1">Estado del Estudiante</label>
                        <select class="form-control form-control-sm" name="status" id="status">
                            <option value="">Todos</option>
                            <option value="Activo" <?php echo ($status_filter == 'Activo') ? 'selected' : ''; ?>>Activo</option>
                            <option value="Retirado" <?php echo ($status_filter == 'Retirado') ? 'selected' : ''; ?>>Retirado</option>
                            <option value="Egresado" <?php echo ($status_filter == 'Egresado') ? 'selected' : ''; ?>>Egresado</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="student_selector" class="small font-weight-bold mb-1">Alumno</label>
                        <select class="form-control form-control-sm select2-student" name="student_id" id="student_selector">
                            <option value="">Todos los alumnos</option>
                            <?php
                            $students_q = $conn->query("SELECT id, name, id_no, nivel, grado FROM student WHERE school_id = $school_id ORDER BY name ASC");
                            while($row = $students_q->fetch_assoc()):
                            ?>
                            <option value="<?php echo $row['id']; ?>" <?php echo (!empty($student_id) && $student_id == $row['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($row['id_no'] . ' - ' . ucwords($row['name']) . ' (' . $row['nivel'] . ' - ' . $row['grado'] . ')'); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 mb-2">
                        <label for="payment_method_id" class="small font-weight-bold mb-1">Método de Pago</label>
                        <select class="form-control form-control-sm" name="payment_method_id" id="payment_method_id">
                            <option value="">Todos</option>
                            <?php
                            $methods_q = $conn->query("SELECT id, name FROM payment_methods ORDER BY name ASC");
                            while($row = $methods_q->fetch_assoc()):
                            ?>
                            <option value="<?php echo $row['id']; ?>" <?php echo ($payment_method_id == $row['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($row['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-9 mb-2">
                        <label for="concept_selector" class="small font-weight-bold mb-1">Concepto de Pago</label>
                        <select class="form-control form-control-sm select2-concept" name="course_id" id="concept_selector">
                            <option value="">Todos los conceptos</option>
                            <?php
                            $courses_q = $conn->query("SELECT c.id, c.course, c.level, c.grades, ay.year, ay.id as year_id,
                                                      CONCAT(c.course, ' - ', IFNULL(c.level,''), ' - ', IFNULL(c.grades,''), ' - ', COALESCE(ay.year, 'Sin año')) as concepto_concatenado
                                                      FROM courses c 
                                                      LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
                                                      WHERE ay.school_id = $school_id
                                                      ORDER BY c.course ASC, c.level ASC, c.grades ASC, ay.year DESC");
                            while($row = $courses_q->fetch_assoc()):
                            ?>
                            <option value="<?php echo $row['id']; ?>" 
                                    data-year-id="<?php echo $row['year_id']; ?>" 
                                    data-level="<?php echo htmlspecialchars($row['level'] ?? ''); ?>" 
                                    data-grades="<?php echo htmlspecialchars($row['grades'] ?? ''); ?>"
                                    <?php echo (!empty($course_id_filter) && $course_id_filter == $row['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['concepto_concatenado']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-md-12 text-right">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa fa-filter"></i> Aplicar Filtros
                        </button>
                        <button type="button" class="btn btn-success btn-sm" id="print_btn" 
                                data-school-name="<?php echo htmlspecialchars($school_name); ?>" 
                                data-school-logo="<?php echo htmlspecialchars($school_logo); ?>">
                            <i class="fa fa-print"></i> Imprimir
                        </button>
                        <a href="index.php?page=payments_report" class="btn btn-secondary btn-sm">
                            <i class="fa fa-undo"></i> Limpiar
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fa fa-table mr-2"></i> Resultados del Reporte</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive" id="report-list">
                <table class="table table-striped table-hover table-sm" id="report-list-table">
                    <thead>
                        <tr>
                            <th class="text-center" width="5%">#</th>
                            <th width="12%">Fecha de Pago</th>
                            <th width="8%">ID Alumno</th>
                            <th width="15%">Nombre Alumno</th>
                            <th width="8%">Estado</th>
                            <th width="15%">Concepto de Pago</th>
                            <th class="text-right" width="8%">Monto Total</th>
                            <th class="text-right" width="10%">Monto del Método<?php echo !empty($payment_method_id) ? ' (Filtrado)' : ''; ?></th>
                            <th width="19%">Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $where_clauses = [];
                        $bind_params = [];
                        $types = "";

                        // Build filter conditions
                        if (!empty($start_date)) {
                            $where_clauses[] = "DATE(p.date_created) >= ?";
                            $bind_params[] = $start_date;
                            $types .= "s";
                        }
                        if (!empty($end_date)) {
                            $where_clauses[] = "DATE(p.date_created) <= ?";
                            $bind_params[] = $end_date;
                            $types .= "s";
                        }

                        // Set period label for printing
                        if (!empty($start_date) && !empty($end_date)) {
                            $period_label = "Pagos del " . date("d/m/Y", strtotime($start_date)) . " al " . date("d/m/Y", strtotime($end_date));
                        } elseif (!empty($start_date)) {
                            $period_label = "Pagos desde el " . date("d/m/Y", strtotime($start_date));
                        } elseif (!empty($end_date)) {
                            $period_label = "Pagos hasta el " . date("d/m/Y", strtotime($end_date));
                        }

                        if (!empty($nivel_educativo)) {
                            $where_clauses[] = "s.nivel = ?";
                            $bind_params[] = $nivel_educativo;
                            $types .= "s";
                        }
                        if (!empty($grado)) {
                            $where_clauses[] = "s.grado = ?";
                            $bind_params[] = $grado;
                            $types .= "s";
                        }
                        if (!empty($seccion)) {
                            $where_clauses[] = "s.seccion = ?";
                            $bind_params[] = $seccion;
                            $types .= "s";
                        }
                        if (!empty($student_id)) {
                            $where_clauses[] = "s.id = ?";
                            $bind_params[] = $student_id;
                            $types .= "i";
                        }
                        if (!empty($payment_method_id)) {
                            $where_clauses[] = "(p.payment_method_id = ? OR EXISTS (
                                SELECT 1 FROM payment_split ps 
                                WHERE ps.payment_id = p.id 
                                AND ps.payment_method_id = ?
                            ))";
                            $bind_params[] = $payment_method_id;
                            $bind_params[] = $payment_method_id;
                            $types .= "ii";
                        }
                        if (!empty($academic_year_id)) {
                            // Pagos deben pertenecer a conceptos de ese año
                            $where_clauses[] = "ay.id = ?";
                            $bind_params[] = $academic_year_id;
                            $types .= "i";
                        }
                        if (!empty($course_id_filter)) {
                            $where_clauses[] = "ef.course_id = ?";
                            $bind_params[] = $course_id_filter;
                            $types .= "i";
                        }

                        if (!empty($status_filter)) {
                            $where_clauses[] = "s.status = ?";
                            $bind_params[] = $status_filter;
                            $types .= "s";
                        }

                        // Check if any actual filtering is taking place
                        $has_filters = count($where_clauses) > 0;

                        // Siempre filtrar por colegio
                        $where_clauses[] = "s.school_id = ?";
                        $bind_params[] = $school_id;
                        $types .= "i";
                        
                        if ($has_filters) {
                            $sql = "SELECT p.id as payment_id, p.date_created, p.amount, p.remarks, p.receipt_no,
                                           s.id_no as student_id_no, s.name as student_name, s.status as student_status, s.grado,
                                           c.course as concept_name, c.level as course_level, c.grades, ay.year,
                                           CONCAT(c.course, ' - ', c.level, ' - ', s.grado, ' - ', COALESCE(ay.year, 'Sin año')) as concepto_concatenado,
                                           pm.name as payment_method_name
                                    FROM payments p
                                    INNER JOIN student_ef_list ef ON p.ef_id = ef.id
                                    INNER JOIN student s ON ef.student_id = s.id
                                    INNER JOIN courses c ON ef.course_id = c.id
                                    LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
                                    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                                    WHERE " . implode(" AND ", $where_clauses) . "
                                    ORDER BY p.date_created DESC, s.name ASC";

                            $stmt = $conn->prepare($sql);

                            if ($stmt) {
                                if (!empty($types) && count($bind_params) > 0) {
                                    $stmt->bind_param($types, ...$bind_params);
                                }
                                $stmt->execute();
                                $result = $stmt->get_result();

                                if ($result->num_rows > 0) {
                                    while ($row = $result->fetch_assoc()) {
                                        $method_specific_amount = 0;
                                        
                                        if (!empty($payment_method_id)) {
                                            $split_amount_query = $conn->query("SELECT amount FROM payment_split 
                                                                               WHERE payment_id = " . $row['payment_id'] . " 
                                                                               AND payment_method_id = " . $payment_method_id);
                                            if ($split_amount_query && $split_amount_query->num_rows > 0) {
                                                $split_data = $split_amount_query->fetch_assoc();
                                                $method_specific_amount = $split_data['amount'];
                                            }
                                        } else {
                                            $all_splits_query = $conn->query("SELECT SUM(amount) as total FROM payment_split 
                                                                             WHERE payment_id = " . $row['payment_id']);
                                            if ($all_splits_query && $all_splits_query->num_rows > 0) {
                                                $all_splits_data = $all_splits_query->fetch_assoc();
                                                $method_specific_amount = $all_splits_data['total'] ?: $row['amount'];
                                            } else {
                                                $method_specific_amount = $row['amount'];
                                            }
                                        }
                                        
                                        $total_amount += $row['amount'];
                                        $total_method_amount += $method_specific_amount;
                                        ?>
                                        <tr>
                                            <td class="text-center"><?php echo $i++; ?></td>
                                            <td>
                                                <div class="small">
                                                    <strong><?php echo htmlspecialchars(date("d/m/Y", strtotime($row['date_created']))); ?></strong><br>
                                                    <span class="text-muted"><i class="fa fa-clock"></i> <?php echo htmlspecialchars(date("h:i A", strtotime($row['date_created']))); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['student_id_no']); ?></td>
                                            <td><?php echo htmlspecialchars(ucwords($row['student_name'])); ?></td>
                                            <td>
                                            <?php 
                                                $statusClass = 'secondary';
                                                if ($row['student_status'] === 'Activo') $statusClass = 'success';
                                                if ($row['student_status'] === 'Retirado') $statusClass = 'danger';
                                                if ($row['student_status'] === 'Egresado') $statusClass = 'info';
                                            ?>
                                                <span class="badge badge-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($row['student_status'] ?: 'Activo'); ?></span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($row['concepto_concatenado']); ?></small>
                                            </td>
                                            <td class="text-right">
                                                <span class="badge badge-success">S/ <?php echo htmlspecialchars(number_format($row['amount'], 2)); ?></span>
                                            </td>
                                            <td class="text-right">
                                                <span class="badge badge-primary">S/ <?php echo htmlspecialchars(number_format($method_specific_amount, 2)); ?></span>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <?php if (!empty($row['receipt_no'])): ?>
                                                        <span class="badge badge-info mb-1">
                                                            <i class="fa fa-receipt"></i> <?php echo htmlspecialchars($row['receipt_no']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <?php
                                                    $splits_query = $conn->query("SELECT ps.amount, pm.name as method_name 
                                                                                  FROM payment_split ps 
                                                                                  LEFT JOIN payment_methods pm ON ps.payment_method_id = pm.id 
                                                                                  WHERE ps.payment_id = " . $row['payment_id']);
                                                    
                                                    if ($splits_query && $splits_query->num_rows > 0) {
                                                        while ($split = $splits_query->fetch_assoc()) {
                                                            $paymentMethodIcon = 'dollar-sign';
                                                            $paymentMethodClass = 'secondary';
                                                            
                                                            if (strpos(strtolower($split['method_name'] ?? ''), 'tarjeta') !== false) {
                                                                $paymentMethodIcon = 'credit-card';
                                                                $paymentMethodClass = 'info';
                                                            } elseif (strpos(strtolower($split['method_name'] ?? ''), 'transferencia') !== false) {
                                                                $paymentMethodIcon = 'exchange-alt';
                                                                $paymentMethodClass = 'primary';
                                                            } elseif (strpos(strtolower($split['method_name'] ?? ''), 'efectivo') !== false) {
                                                                $paymentMethodIcon = 'money-bill-alt';
                                                                $paymentMethodClass = 'dark';
                                                            } elseif (strpos(strtolower($split['method_name'] ?? ''), 'cheque') !== false) {
                                                                $paymentMethodIcon = 'money-check';
                                                                $paymentMethodClass = 'warning';
                                                            }
                                                            ?>
                                                            <span class="badge badge-<?php echo $paymentMethodClass; ?> d-block mb-1">
                                                                <i class="fa fa-<?php echo $paymentMethodIcon; ?>"></i> <?php echo htmlspecialchars($split['method_name'] ?? 'N/A'); ?> 
                                                                <small>S/ <?php echo number_format($split['amount'], 2); ?></small>
                                                            </span>
                                                            <?php
                                                        }
                                                    } else {
                                                        $paymentMethodIcon = 'dollar-sign';
                                                        $paymentMethodClass = 'secondary';
                                                        
                                                        if (strpos(strtolower($row['payment_method_name'] ?? ''), 'tarjeta') !== false) {
                                                            $paymentMethodIcon = 'credit-card';
                                                            $paymentMethodClass = 'info';
                                                        } elseif (strpos(strtolower($row['payment_method_name'] ?? ''), 'transferencia') !== false) {
                                                            $paymentMethodIcon = 'exchange-alt';
                                                            $paymentMethodClass = 'primary';
                                                        } elseif (strpos(strtolower($row['payment_method_name'] ?? ''), 'efectivo') !== false) {
                                                            $paymentMethodIcon = 'money-bill-alt';
                                                            $paymentMethodClass = 'dark';
                                                        } elseif (strpos(strtolower($row['payment_method_name'] ?? ''), 'cheque') !== false) {
                                                            $paymentMethodIcon = 'money-check';
                                                            $paymentMethodClass = 'warning';
                                                        }
                                                        ?>
                                                        <span class="badge badge-<?php echo $paymentMethodClass; ?> d-block mb-1">
                                                            <i class="fa fa-<?php echo $paymentMethodIcon; ?>"></i> <?php echo htmlspecialchars($row['payment_method_name'] ?? 'N/A'); ?>
                                                        </span>
                                                        <?php
                                                    }
                                                    ?>
                                                    
                                                    <?php if (!empty($row['remarks'])): ?>
                                                        <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                                            <i class="fa fa-comment"></i> <?php echo htmlspecialchars(substr($row['remarks'], 0, 50)) . (strlen($row['remarks']) > 50 ? '...' : ''); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    echo '<tr><td colspan="9" class="text-center py-4"><div class="alert alert-warning mb-0"><i class="fa fa-exclamation-circle mr-2"></i> No se encontraron pagos con los filtros seleccionados.</div></td></tr>';
                                }
                                $stmt->close();
                            } else {
                                echo '<tr><td colspan="9" class="text-center py-4"><div class="alert alert-danger mb-0"><i class="fa fa-exclamation-triangle mr-2"></i> Error al preparar la consulta: ' . htmlspecialchars($conn->error) . '</div></td></tr>';
                            }
                        } else {
                            echo '<tr><td colspan="9" class="text-center py-4"><div class="alert alert-info mb-0"><i class="fa fa-info-circle mr-2"></i> Por favor, selecciona al menos un filtro para ver los resultados del reporte.</div></td></tr>';
                        }
                        ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="6" class="text-right">Total General:</th>
                            <th class="text-right"><span class="badge badge-success">S/ <?php echo htmlspecialchars(number_format($total_amount, 2)); ?></span></th>
                            <th class="text-right"><span class="badge badge-primary">S/ <?php echo htmlspecialchars(number_format($total_method_amount, 2)); ?></span></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    function initializeSelect2() {
        const select2Config = {
            width: '100%',
            language: {
                noResults: function() {
                    return "No se encontraron resultados";
                },
                searching: function() {
                    return "Buscando...";
                },
                inputTooShort: function() {
                    return "Escriba para buscar";
                },
                removeAllItems: function() {
                    return "Eliminar todos los elementos";
                }
            }
        };
        
        if ($('.select2-student').length > 0) {
            $('.select2-student').select2({
                ...select2Config,
                placeholder: "Buscar alumno...",
                allowClear: true,
                templateResult: function(state) {
                    if (!state.id) {
                        return state.text;
                    }
                    return $('<span style="font-size: 0.9rem;">' + state.text + '</span>');
                }
            });
        }
        
        if ($('.select2-concept').length > 0) {
            $('.select2-concept').select2({
                ...select2Config,
                placeholder: "Buscar concepto...",
                allowClear: true,
                templateResult: function(state) {
                    if (!state.id) {
                        return state.text;
                    }
                    return $('<span style="font-size: 0.9rem;">' + state.text + '</span>');
                }
            });
        }
    }
    
    // Dynamic Concepts Logic
    var allConcepts = [];
    // Store all options in memory
    $('#concept_selector option').each(function() {
        if ($(this).val() !== "") {
            allConcepts.push({
                id: $(this).val(),
                text: $(this).text(),
                year_id: $(this).data('year-id'),
                level: $(this).data('level'),
                grades: $(this).data('grades'),
                selected: $(this).is(':selected')
            });
        }
    });

    function updateConceptsDropdown() {
        var selYear = $('#academic_year_id').val();
        var selLevel = $('#nivel_educativo').val();
        var selGrado = $('#grado').val();
        
        var $sel = $('#concept_selector');
        var currentVal = $sel.val();
        
        $sel.empty();
        $sel.append('<option value="">Todos los conceptos</option>');
        
        allConcepts.forEach(function(c) {
            var show = true;
            // Evaluamos Nivel
            if (selLevel && c.level && c.level !== selLevel) {
                show = false;
            }
            // Evaluamos Grado (c.grades puede ser "5°, 6°")
            if (show && selGrado && c.grades && c.grades.indexOf(selGrado) === -1) {
                show = false;
            }
            // Evaluamos Año
            if (show && selYear && c.year_id && c.year_id != selYear) {
                show = false;
            }
            
            if (show) {
                var isSelected = (currentVal == c.id || c.selected) ? ' selected' : '';
                $sel.append('<option value="' + c.id + '"' + isSelected + '>' + c.text + '</option>');
            }
        });
        
        // Re-initialize select2 if it was destroyed
        if ($sel.hasClass("select2-hidden-accessible")) {
            $sel.select2('destroy');
        }
        initializeSelect2();
    }

    // Escuchar cambios
    $('#academic_year_id, #nivel_educativo, #grado').on('change', function() {
        updateConceptsDropdown();
    });

    initializeSelect2();
    updateConceptsDropdown();
    
    $(document).on('DOMNodeInserted', function() {
        setTimeout(function() {
            $('.select2-student:not(.select2-hidden-accessible)').each(function() {
                initializeSelect2();
            });
            $('.select2-concept:not(.select2-hidden-accessible)').each(function() {
                initializeSelect2();
            });
        }, 100);
    });
});
</script>

<script src="payments_print.js?v=5.0"></script>
