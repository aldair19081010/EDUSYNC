<?php 
include 'db_connect.php'; 

// Obtener los datos del colegio
$school_id = $_SESSION['login_school_id'] ?? 0;
// Timestamp de última actualización (servidor)
$last_updated_at = date('d/m/Y H:i');
$school_query = "SELECT * FROM schools WHERE id = $school_id";
$school_result = $conn->query($school_query);
$school = null;

if ($school_result && $school_result->num_rows > 0) {
    $school = $school_result->fetch_assoc();
} else {
    $school_query = "SELECT * FROM schools ORDER BY id ASC LIMIT 1";
    $school_result = $conn->query($school_query);
    if ($school_result && $school_result->num_rows > 0) {
        $school = $school_result->fetch_assoc();
    }
}
?>
<style>
/* Estilos modernizados para el dashboard de inicio */

/* Estilos para el nuevo panel de control */
.bg-primary-soft { background-color: rgba(66, 133, 244, 0.15); }
.bg-success-soft { background-color: rgba(40, 167, 69, 0.15); }
.bg-info-soft { background-color: rgba(23, 162, 184, 0.15); }
.bg-warning-soft { background-color: rgba(255, 193, 7, 0.15); }
.bg-danger-soft { background-color: rgba(220, 53, 69, 0.15); }

.nav-tabs .nav-link {
    font-weight: 600;
    color: #6c757d;
    border-top: none;
    border-left: none;
    border-right: none;
    padding: 0.75rem 1rem;
    margin-right: 1rem;
}

.nav-tabs .nav-link.active {
    color: #4285f4;
    border-bottom: 2px solid #4285f4;
    background-color: transparent;
}

.tab-content {
    padding: 1rem 0;
}
.inicio-dashboard {
    max-width: 1000px;
    margin: 30px auto 0 auto;
    background: linear-gradient(to bottom, #ffffff, #f9f9fc);
    border-radius: 15px;
    box-shadow: 0 5px 20px rgba(66, 133, 244, 0.08);
    padding: 40px 30px 35px 30px;
    text-align: left;
    border-top: 4px solid #4285f4;
}

.inicio-dashboard img {
    border-radius: 10px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.1);
    padding: 5px;
    background: white;
}

.inicio-dashboard h2 {
    font-weight: 700;
    color: #4285f4;
    margin-bottom: 15px;
    font-size: 2rem;
    text-shadow: 0 1px 1px rgba(0,0,0,0.05);
}

.inicio-dashboard p {
    font-size: 1.18rem;
    color: #5a5c69;
    margin-bottom: 30px;
    max-width: 700px;
    margin-left: auto;
    margin-right: auto;
    line-height: 1.6;
}

.inicio-cards {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 25px;
    margin-top: 30px;
}

.inicio-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    padding: 25px 20px;
    width: 210px;
    min-height: 130px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
    text-decoration: none;
    border: 1px solid #e3e6f0;
    position: relative;
    overflow: hidden;
}

.inicio-card:before {
    content: '';
    position: absolute;
    width: 100%;
    height: 3px;
    top: 0;
    left: 0;
    background: linear-gradient(90deg, #4285f4, #2a75f3);
    opacity: 0;
    transition: opacity 0.3s;
}

.inicio-card:hover:before {
    opacity: 1;
}

.inicio-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(66, 133, 244, 0.15);
    border-color: #cdd8f6;
}

.inicio-card:after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border-radius: 12px;
    box-shadow: 0 0 0 0 rgba(66, 133, 244, 0);
    transition: box-shadow 0.3s;
    z-index: -1;
}

.inicio-card:hover:after {
    box-shadow: 0 0 20px 5px rgba(66, 133, 244, 0.2);
}

.inicio-card i {
    font-size: 2.4rem;
    color: #4285f4;
    margin-bottom: 12px;
    transition: all 0.3s;
}

.admin-card i { color: #4285f4; }
.teacher-card i { color: #4285f4; }
.aux-card i { color: #f6c23e; }
.student-card i { color: #36b9cc; }

.inicio-card:hover i {
    transform: scale(1.2);
}

.inicio-card span {
    font-size: 1.1rem;
    font-weight: 600;
    color: #5a5c69;
    transition: color 0.3s;
}
/* Estilos del banner de bienvenida */
.welcome-banner {
    background: linear-gradient(135deg, #f8f9fc, #eaecf4);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 30px;
    border-left: 4px solid #4285f4;
    text-align: left;
    display: flex;
    align-items: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.welcome-icon {
    color: white;
    width: 80px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    margin-right: 20px;
}

.welcome-banner h3 {
    margin: 0 0 5px 0;
    font-weight: 600;
    color: #4285f4;
    font-size: 1.3rem;
}

.welcome-banner p {
    margin: 0;
    font-size: 1rem;
    color: #6e707e;
}

/* Estilos para diferentes tipos de tarjetas */
.admin-card:hover { /* Applied to non-admin cards if class is used */
    background: linear-gradient(135deg, #4285f4, #2a75f3);
}
.admin-card:hover i, .admin-card:hover span {
    color: #fff;
}

.teacher-card:hover {
    background: linear-gradient(135deg, #4285f4, #2a75f3);
}
.teacher-card:hover i, .teacher-card:hover span {
    color: #fff;
}

.aux-card:hover {
    background: linear-gradient(135deg, #f6c23e, #dda20a);
}
.aux-card:hover i, .aux-card:hover span {
    color: #fff;
}

.student-card:hover {
    background: linear-gradient(135deg, #36b9cc, #258391);
}
.student-card:hover i, .student-card:hover span {
    color: #fff;
}

/* Pie de página */
.inicio-footer {
    margin-top: 40px;
    padding-top: 20px;
    border-top: 1px solid #e3e6f0;
    color: #858796;
    font-size: 0.9rem;
    text-align: center;
}

.inicio-footer p {
    margin-bottom: 10px;
    font-size: 0.9rem;
}

/* Para dispositivos móviles */
@media (max-width: 992px) {
    .inicio-dashboard {
        margin: 20px 15px;
        padding: 25px 15px;
    }
    
    .inicio-cards {
        gap: 15px;
    }
    
    .inicio-card {
        width: calc(50% - 15px);
        min-height: 120px;
        padding: 15px;
    }
    
    .welcome-banner {
        flex-direction: column;
        text-align: center;
        padding: 15px;
    }
    
    .welcome-icon {
        margin: 0 0 15px 0;
    }
}

@media (max-width: 576px) {
    .inicio-cards {
        flex-direction: column;
        align-items: center;
    }
    
    .inicio-card {
        width: 100%;
    }
    
    .inicio-dashboard h2 {
        font-size: 1.8rem;
    }
    
    .inicio-dashboard p {
        font-size: 1rem;
    }
}

/* Responsive improvements for dashboard */
@media (max-width: 768px) {
    .col-xl-3, .col-xl-4 {
        margin-bottom: 20px;
    }
    
    .chart-area {
        height: 250px !important;
    }
    
    .card-body {
        padding: 15px;
    }
    
    .table-responsive {
        font-size: 0.9rem;
    }
    
    .btn-group {
        flex-direction: column;
        width: 100%;
    }
    
    .btn-group .btn {
        margin-bottom: 5px;
        border-radius: 4px !important;
    }
}

/* Chart loading states */
.chart-loading {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 200px;
    color: #6c757d;
}

/* Enhanced card shadows */
.card.shadow-enhanced {
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
    transition: all 0.3s ease;
}

/* Progress bars for dashboard metrics */
.metric-progress {
    height: 4px;
    background: #e9ecef;
    border-radius: 2px;
    overflow: hidden;
    margin-top: 8px;
}

.metric-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #28a745, #20c997);
    transition: width 0.6s ease;
}

/* Alert enhancements */
.alert {
    border-left: 4px solid transparent;
    border-radius: 8px;
}

.alert-warning {
    border-left-color: #ffc107;
    background: linear-gradient(90deg, rgba(255, 193, 7, 0.1), rgba(255, 255, 255, 0.05));
}

.alert-danger {
    border-left-color: #dc3545;
    background: linear-gradient(90deg, rgba(220, 53, 69, 0.1), rgba(255, 255, 255, 0.05));
}

.alert-info {
    border-left-color: #17a2b8;
    background: linear-gradient(90deg, rgba(23, 162, 184, 0.1), rgba(255, 255, 255, 0.05));
}

.alert-success {
    border-left-color: #28a745;
    background: linear-gradient(90deg, rgba(40, 167, 69, 0.1), rgba(255, 255, 255, 0.05));
}

/* Badge enhancements */
.badge {
    font-weight: 500;
    padding: 0.4em 0.8em;
    font-size: 0.85em;
}

/* Table enhancements */
.table-hover tbody tr:hover {
    background-color: rgba(66, 133, 244, 0.05);
    transform: scale(1.01);
    transition: all 0.2s ease;
}

/* Button group improvements */
.btn-group .btn {
    transition: all 0.2s ease;
}

.btn-group .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

/* Card header improvements */
.card-header {
    background: linear-gradient(135deg, #f8f9fc, #ffffff);
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

/* Quick actions bar improvements */
.btn-outline-primary:hover,
.btn-outline-success:hover,
.btn-outline-info:hover,
.btn-outline-secondary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Performance optimization for animations */
.card, .btn, .alert {
    will-change: transform;
}

/* Loading spinner customization */
.spinner-border {
    border-width: 3px;
}

</style>

<!-- Incluir Animate.css para algunas animaciones sutiles -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />

<div class="inicio-dashboard">
	<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid #eaeaea;">
		<!-- Logo del colegio a la izquierda -->
		<div style="display: flex; align-items: center; text-align: left; flex: 1;">
			<?php if($school): ?>
				<?php if(!empty($school['logo_path']) && file_exists($school['logo_path'])): ?>
					<img src="<?php echo htmlspecialchars($school['logo_path']); ?>" alt="Logo del colegio" style="width: 100px; height: 100px; object-fit: contain; border-radius: 8px; background-color: transparent; padding: 3px; box-shadow: 0 3px 6px rgba(0,0,0,0.1); margin-right: 20px;">
				<?php else: ?>
					<div style="margin-right: 20px;">
						<i class="fas fa-school" style="background: linear-gradient(135deg, #4285f4, #2a75f3); width: 80px; height: 80px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 40px;"></i>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<div style="margin-right: 20px;">
					<i class="fa fa-home" style="background: linear-gradient(135deg, #4285f4, #2a75f3); width: 80px; height: 80px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 40px;"></i>
				</div>
			<?php endif; ?>
			
			<!-- Información del colegio -->
			<div>
				<h2 style="margin-bottom: 10px; color: #4285f4; text-align: left; font-size: 1.8rem;"><?php echo htmlspecialchars($school['name'] ?? 'Sistema de Gestión Educativa'); ?></h2>
				<?php if($school): ?>
					<p style="margin-bottom: 6px; color: #555;"><strong>Dirección:</strong> <?php echo htmlspecialchars($school['address'] ?? ''); ?></p>
					<p style="margin-bottom: 0; color: #555;">
						<?php if(!empty($school['contact_number'])): ?>
							<i class="fas fa-phone-alt mr-2"></i><?php echo htmlspecialchars($school['contact_number']); ?> 
						<?php endif; ?>
						
						<?php if(!empty($school['email'])): ?>
							<i class="fas fa-envelope ml-3 mr-2"></i><?php echo htmlspecialchars($school['email']); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		
		<!-- Logo del sistema a la derecha -->
		<div style="text-align: right;">
			<img src="assets/uploads/logo.jpg" alt="Logo EduSync" style="max-width: 110px; margin-bottom: 0;">
		</div>
	</div>
	
	<div style="margin-top: 20px; text-align: center;">
		<p style="margin-bottom: 0; font-size: 1.1rem; color: #4285f4;">
			<?php
			$user = $_SESSION['login_name'] ?? '';
			echo $user ? "Hola, <b>" . htmlspecialchars($user) . "</b>. " : "";
			?>
			Bienvenido(a) al sistema de gestión educativa. ¿Qué deseas hacer hoy?
		</p>
	</div>
	
	<?php if (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 1): // ADMIN ?>
    <?php
    // Query for total active students
    $students_query = "SELECT COUNT(id) as total_students FROM student WHERE status = 'Activo'";
    $students_result = $conn->query($students_query);
    $total_students = 0;
    if ($students_result && $students_result->num_rows > 0) {
        $row = $students_result->fetch_assoc();
        $total_students = $row['total_students'];
    }

    // Query for total active teachers
    $teachers_query = "SELECT COUNT(id) as total_teachers FROM teacher";
    $teachers_result = $conn->query($teachers_query);
    $total_teachers = 0;
    if ($teachers_result && $teachers_result->num_rows > 0) {
        $row = $teachers_result->fetch_assoc();
        $total_teachers = $row['total_teachers'];
    }

    // Query for total courses from 'courses' table
    $courses_query = "SELECT COUNT(id) as total_courses FROM courses";
    $courses_result = $conn->query($courses_query);
    $total_courses = 0;
    if ($courses_result && $courses_result->num_rows > 0) {
        $row = $courses_result->fetch_assoc();
        $total_courses = $row['total_courses'];
    }
    
    $levels_query = "
        SELECT
            s.nivel as level,  -- Usar s.nivel directamente y renombrarlo a level
            COUNT(s.id) as student_count
        FROM
            student s
        WHERE
            s.nivel IS NOT NULL AND s.nivel != ''
            AND s.status = 'Activo'
        GROUP BY
            s.nivel
        ORDER BY
            FIELD(s.nivel, 'Inicial', 'Primaria', 'Secundaria')
    ";
    $levels_result = $conn->query($levels_query);
    $students_by_level = [];
    if ($levels_result && $levels_result->num_rows > 0) {
        while($row = $levels_result->fetch_assoc()){
            $students_by_level[$row['level']] = $row['student_count'];
        }
    }
    $expected_levels = ['Inicial', 'Primaria', 'Secundaria'];
    foreach ($expected_levels as $level) {
        if (!isset($students_by_level[$level])) {
            $students_by_level[$level] = 0;
        }
    }

    // Query for detailed student distribution by nivel, grado, and seccion
    $detailed_distribution_query = "
        SELECT 
            s.nivel, 
            s.grado, 
            s.seccion, 
            COUNT(s.id) as student_count 
        FROM student s 
        WHERE s.nivel IS NOT NULL AND s.nivel != '' 
          AND s.grado IS NOT NULL AND s.grado != '' 
          AND s.seccion IS NOT NULL AND s.seccion != '' 
          AND s.status = 'Activo'
        GROUP BY s.nivel, s.grado, s.seccion 
        ORDER BY FIELD(s.nivel, 'Inicial', 'Primaria', 'Secundaria'), s.grado, s.seccion
    ";
    $detailed_distribution_result = $conn->query($detailed_distribution_query);
    $students_by_level_grade_section = [];
    if ($detailed_distribution_result && $detailed_distribution_result->num_rows > 0) {
        while($row = $detailed_distribution_result->fetch_assoc()){
            // Ensure levels are initialized even if they come from the query first
            if (!isset($students_by_level_grade_section[$row['nivel']])) {
                $students_by_level_grade_section[$row['nivel']] = [];
            }
            // Ensure grades are initialized
            if (!isset($students_by_level_grade_section[$row['nivel']][$row['grado']])) {
                $students_by_level_grade_section[$row['nivel']][$row['grado']] = [];
            }
            $students_by_level_grade_section[$row['nivel']][$row['grado']][$row['seccion']] = $row['student_count'];
        }
    }
    // Ensure all expected levels are present in the main array for consistent iteration in HTML
    // $expected_levels is already defined and used for $students_by_level
    foreach ($expected_levels as $level_key) { 
        if (!isset($students_by_level_grade_section[$level_key])) {
            $students_by_level_grade_section[$level_key] = [];
        }
    }

    // --- Financial Summary Data ---
    $current_month_payments_query = "
        SELECT SUM(amount) as total_monthly_payments 
        FROM payments 
        WHERE MONTH(date_created) = MONTH(CURDATE()) AND YEAR(date_created) = YEAR(CURDATE())
    ";
    $current_month_payments_result = $conn->query($current_month_payments_query);
    $total_payments_current_month = 0;
    if ($current_month_payments_result && $current_month_payments_result->num_rows > 0) {
        $row = $current_month_payments_result->fetch_assoc();
        $total_payments_current_month = $row['total_monthly_payments'] ?? 0;
    }

    $financial_status_query = "
        SELECT 
            s.id as student_id,
            (SUM(COALESCE(sef.discounted_amount, sef.total_fee)) - SUM(COALESCE(p.total_paid, 0))) as outstanding_balance
        FROM student s
        JOIN student_ef_list sef ON s.id = sef.student_id
        LEFT JOIN (
            SELECT ef_id, SUM(amount) as total_paid 
            FROM payments 
            GROUP BY ef_id
        ) p ON sef.id = p.ef_id
        WHERE s.status = 'Activo'
        GROUP BY s.id
    ";
    
    $financial_status_result = $conn->query($financial_status_query);
    $total_outstanding_balance = 0;
    $students_with_pending_payments_ids = [];

    if ($financial_status_result && $financial_status_result->num_rows > 0) {
        while($row = $financial_status_result->fetch_assoc()) {
            if ($row['outstanding_balance'] > 0) {
                $total_outstanding_balance += $row['outstanding_balance'];
                $students_with_pending_payments_ids[] = $row['student_id'];
            }
        }
    }
    $count_students_with_pending_payments = count(array_unique($students_with_pending_payments_ids));

    $financial_chart_labels = ['Pagos Recibidos (Mes Actual)', 'Saldo Pendiente Total'];
    $financial_chart_data = [$total_payments_current_month, $total_outstanding_balance];

    // --- Detailed Financial Dashboard Data ---

    // Payments Today
    $payments_today_query = "SELECT SUM(amount) as total_payments_today FROM payments WHERE DATE(date_created) = CURDATE()";
    $payments_today_result = $conn->query($payments_today_query);
    $total_payments_today = 0;
    if ($payments_today_result && $payments_today_result->num_rows > 0) {
        $row = $payments_today_result->fetch_assoc();
        $total_payments_today = $row['total_payments_today'] ?? 0;
    }

    // Payments This Week (Monday to Sunday)
    $payments_week_query = "SELECT SUM(amount) as total_payments_week FROM payments WHERE YEARWEEK(date_created, 1) = YEARWEEK(CURDATE(), 1)";
    $payments_week_result = $conn->query($payments_week_query);
    $total_payments_week = 0;
    if ($payments_week_result && $payments_week_result->num_rows > 0) {
        $row = $payments_week_result->fetch_assoc();
        $total_payments_week = $row['total_payments_week'] ?? 0;
    }

    // Payments This Year
    $payments_year_query = "SELECT SUM(amount) as total_payments_year FROM payments WHERE YEAR(date_created) = YEAR(CURDATE())";
    $payments_year_result = $conn->query($payments_year_query);
    $total_payments_year = 0;
    if ($payments_year_result && $payments_year_result->num_rows > 0) {
        $row = $payments_year_result->fetch_assoc();
        $total_payments_year = $row['total_payments_year'] ?? 0;
    }

    // Pagos del mes anterior (para tendencia)
    $prev_month_payments_query = "SELECT SUM(amount) as total_prev_month 
        FROM payments 
        WHERE YEAR(date_created) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
          AND MONTH(date_created) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
    $prev_month_payments_result = $conn->query($prev_month_payments_query);
    $total_prev_month_payments = 0;
    if ($prev_month_payments_result && $prev_month_payments_result->num_rows > 0) {
        $row = $prev_month_payments_result->fetch_assoc();
        $total_prev_month_payments = $row['total_prev_month'] ?? 0;
    }

    // Income by Fee Type (Concept)
    $income_by_concept_query = "
        SELECT 
            c.course as concept_name, 
            SUM(p.amount) as total_amount
        FROM payments p
        JOIN student_ef_list sef ON p.ef_id = sef.id
        JOIN courses c ON sef.course_id = c.id
        GROUP BY c.course
        ORDER BY total_amount DESC
    ";
    $income_by_concept_result = $conn->query($income_by_concept_query);
    $income_by_concept = [];
    if ($income_by_concept_result && $income_by_concept_result->num_rows > 0) {
        while($row = $income_by_concept_result->fetch_assoc()){
            $income_by_concept[$row['concept_name']] = $row['total_amount'];
        }
    }

    // Define a mapping for month names if they are in Spanish from the DB/PHP locale
    define('MONTH_MAP_ES_SHORT_TO_EN_FULL', [
        'Ene' => 'January', 'Feb' => 'February', 'Mar' => 'March', 'Abr' => 'April',
        'May' => 'May', 'Jun' => 'June', 'Jul' => 'July', 'Ago' => 'August',
        'Sep' => 'September', 'Oct' => 'October', 'Nov' => 'November', 'Dic' => 'December'
    ]);

    // Monthly Income Trend (Current Year) - Enhanced with proper month filling
    $monthly_income_trend_query = "
        SELECT 
            DATE_FORMAT(date_created, '%Y-%m') as month_year, 
            SUM(amount) as monthly_total
        FROM payments
        WHERE YEAR(date_created) = YEAR(CURDATE())
        GROUP BY month_year
        ORDER BY month_year ASC
    ";
    $monthly_income_trend_result = $conn->query($monthly_income_trend_query);
    $monthly_income_labels = [];
    $monthly_income_data = [];
    $raw_monthly_data = []; 

    if ($monthly_income_trend_result && $monthly_income_trend_result->num_rows > 0) { 
        while($row = $monthly_income_trend_result->fetch_assoc()) { 
            $raw_monthly_data[$row['month_year']] = $row['monthly_total'];
        }
    }
    
    // Ensure all months of the current year up to current month are present
    $current_year = date('Y');
    $current_month = date('n');
    for ($m = 1; $m <= $current_month; $m++) {
        $month_key = date('Y-m', mktime(0, 0, 0, $m, 1, $current_year));
        $month_label = date('M Y', mktime(0, 0, 0, $m, 1, $current_year));
        $monthly_income_labels[] = $month_label;
        $monthly_income_data[] = $raw_monthly_data[$month_key] ?? 0;
    }

    // Top Students with Outstanding Balances
    $top_debtors_query = "
        SELECT 
            s.name as student_name,
            s.id_no as student_id_no,
            (SUM(COALESCE(sef.discounted_amount, sef.total_fee)) - SUM(COALESCE(p.total_paid, 0))) as outstanding_balance
        FROM student s
        JOIN student_ef_list sef ON s.id = sef.student_id
        LEFT JOIN (
            SELECT ef_id, SUM(amount) as total_paid 
            FROM payments 
            GROUP BY ef_id
        ) p ON sef.id = p.ef_id
        WHERE s.status = 'Activo'
        GROUP BY s.id, s.name, s.id_no
        HAVING outstanding_balance > 0
        ORDER BY outstanding_balance DESC
        LIMIT 10 
    ";
    $top_debtors_result = $conn->query($top_debtors_query);
    $top_debtors = [];
    if ($top_debtors_result && $top_debtors_result->num_rows > 0) {
        while($row = $top_debtors_result->fetch_assoc()){
            $top_debtors[] = $row;
        }
    }

    // Expected Payments (Current Month) vs Received Payments (Current Month)
    $expected_payments_month_query = "
        SELECT SUM(COALESCE(discounted_amount, total_fee)) as total_expected 
        FROM student_ef_list 
        WHERE MONTH(date_created) = MONTH(CURDATE()) AND YEAR(date_created) = YEAR(CURDATE())
    ";
    $expected_payments_month_result = $conn->query($expected_payments_month_query);
    $total_expected_payments_month = 0;
    if ($expected_payments_month_result && $expected_payments_month_result->num_rows > 0) {
        $row = $expected_payments_month_result->fetch_assoc();
        $total_expected_payments_month = $row['total_expected'] ?? 0;
    }
    // $total_payments_current_month is already available

    // Query for total academic courses (asignaturas)
    $total_academic_courses_query = "SELECT COUNT(id) as total_academic_courses FROM academic_courses";
    $total_academic_courses_result = $conn->query($total_academic_courses_query);
    $total_academic_courses = 0;
    if ($total_academic_courses_result && $total_academic_courses_result->num_rows > 0) {
        $row = $total_academic_courses_result->fetch_assoc();
        $total_academic_courses = $row['total_academic_courses'];
    }

    // Query for academic course distribution by level
    $academic_courses_by_level_query = "
        SELECT
            level,
            COUNT(id) as course_count
        FROM
            academic_courses
        WHERE
            level IS NOT NULL AND level != ''
        GROUP BY
            level
        ORDER BY
            FIELD(level, 'Inicial', 'Primaria', 'Secundaria')
    ";
    $academic_courses_by_level_result = $conn->query($academic_courses_by_level_query);
    $academic_courses_by_level = [];
    if ($academic_courses_by_level_result && $academic_courses_by_level_result->num_rows > 0) {
        while($row = $academic_courses_by_level_result->fetch_assoc()){
            $academic_courses_by_level[$row['level']] = $row['course_count'];
        }
    }
    // Ensure all expected levels are present for academic courses
    // $expected_levels is already defined: ['Inicial', 'Primaria', 'Secundaria'];
    foreach ($expected_levels as $level) {
        if (!isset($academic_courses_by_level[$level])) {
            $academic_courses_by_level[$level] = 0;
        }
    }

    // --- Attendance Data ---
    // Ventana de análisis para asistencia (últimos 30 días)
    $ATT_DAYS = 30;
    
    // Average Daily Attendance (considering present states in 'Entrada' type for unique student-day combinations) en los últimos 30 días
    $avg_daily_attendance_query = "
        SELECT AVG(daily_count) as average_daily_attendance
        FROM (
            SELECT COUNT(DISTINCT a.student_id) as daily_count
            FROM asistencia a
            JOIN student s ON a.student_id = s.id
            WHERE a.estado IN ('Temprano','Normal','Tarde','Presente') AND a.tipo = 'Entrada'
            AND a.fecha >= CURDATE() - INTERVAL $ATT_DAYS DAY
            AND s.status = 'Activo'
            GROUP BY a.fecha
        ) as daily_attendance_counts
    ";
    $avg_daily_attendance_result = $conn->query($avg_daily_attendance_query);
    $average_daily_attendance = 0;
    if ($avg_daily_attendance_result && $avg_daily_attendance_result->num_rows > 0) {
        $row = $avg_daily_attendance_result->fetch_assoc();
        $average_daily_attendance = $row['average_daily_attendance'] ?? 0;
    }

        // General Absenteeism Percentage (últimos 30 días)
        // Total possible attendance days (unique student-day pairs recorded) - últimos 30 días
        $total_attendance_records_query = "SELECT COUNT(DISTINCT a.student_id, a.fecha) as total_records 
    FROM asistencia a
    JOIN student s ON a.student_id = s.id
        WHERE a.tipo = 'Entrada' AND s.status = 'Activo' 
            AND a.fecha >= CURDATE() - INTERVAL $ATT_DAYS DAY"; // Counting only 'Entrada' to represent a day en ventana
    $total_attendance_records_result = $conn->query($total_attendance_records_query);
    $total_possible_attendance_days = 0;
    if ($total_attendance_records_result && $total_attendance_records_result->num_rows > 0) {
        $row = $total_attendance_records_result->fetch_assoc();
        $total_possible_attendance_days = $row['total_records'] ?? 0;
    }

        $total_absences_query = "SELECT COUNT(a.id) as total_absences 
    FROM asistencia a
    JOIN student s ON a.student_id = s.id
    WHERE a.estado = 'Ausente' AND a.tipo = 'Entrada'
        AND s.status = 'Activo' 
            AND a.fecha >= CURDATE() - INTERVAL $ATT_DAYS DAY";
    $total_absences_result = $conn->query($total_absences_query);
    $total_absences = 0;
    if ($total_absences_result && $total_absences_result->num_rows > 0) {
        $row = $total_absences_result->fetch_assoc();
        $total_absences = $row['total_absences'] ?? 0;
    }

    $absenteeism_percentage = 0;
    if ($total_possible_attendance_days > 0) {
        // This calculation assumes that an 'Ausente' record means the student was expected.
        // If a student has no record for a day, they are not counted in $total_possible_attendance_days for this simple calculation.
        // A more accurate absenteeism rate might need to compare against total enrolled students * school days.
        $absenteeism_percentage = ($total_absences / $total_possible_attendance_days) * 100;
    }

    // Porcentaje de ausentismo del periodo anterior (30 días previos al periodo actual)
    $total_attendance_records_prev_query = "SELECT COUNT(DISTINCT a.student_id, a.fecha) as total_records 
        FROM asistencia a
        JOIN student s ON a.student_id = s.id
        WHERE a.tipo = 'Entrada' AND s.status = 'Activo' 
          AND a.fecha >= CURDATE() - INTERVAL " . ($ATT_DAYS*2) . " DAY 
          AND a.fecha < CURDATE() - INTERVAL $ATT_DAYS DAY";
    $total_attendance_records_prev_result = $conn->query($total_attendance_records_prev_query);
    $total_possible_attendance_days_prev = 0;
    if ($total_attendance_records_prev_result && $total_attendance_records_prev_result->num_rows > 0) {
        $row = $total_attendance_records_prev_result->fetch_assoc();
        $total_possible_attendance_days_prev = $row['total_records'] ?? 0;
    }

    $total_absences_prev_query = "SELECT COUNT(a.id) as total_absences 
        FROM asistencia a
        JOIN student s ON a.student_id = s.id
        WHERE a.estado = 'Ausente' AND a.tipo = 'Entrada'
          AND s.status = 'Activo' 
          AND a.fecha >= CURDATE() - INTERVAL " . ($ATT_DAYS*2) . " DAY 
          AND a.fecha < CURDATE() - INTERVAL $ATT_DAYS DAY";
    $total_absences_prev_result = $conn->query($total_absences_prev_query);
    $total_absences_prev = 0;
    if ($total_absences_prev_result && $total_absences_prev_result->num_rows > 0) {
        $row = $total_absences_prev_result->fetch_assoc();
        $total_absences_prev = $row['total_absences'] ?? 0;
    }
    $absenteeism_percentage_prev = 0;
    if ($total_possible_attendance_days_prev > 0) {
        $absenteeism_percentage_prev = ($total_absences_prev / $total_possible_attendance_days_prev) * 100;
    }
    
    // Attendance trend data (e.g., daily attendance for the last 30 days)
    $attendance_trend_query = "
        SELECT 
            a.fecha, 
            COUNT(DISTINCT a.student_id) as present_students
        FROM asistencia a
        JOIN student s ON a.student_id = s.id
        WHERE a.estado IN ('Temprano','Normal','Tarde','Presente') AND a.tipo = 'Entrada' 
        AND a.fecha >= CURDATE() - INTERVAL 30 DAY
        AND s.status = 'Activo'
        GROUP BY a.fecha
        ORDER BY a.fecha ASC
    ";
    $attendance_trend_result = $conn->query($attendance_trend_query);
    $attendance_trend_labels = [];
    $attendance_trend_data = [];
    if ($attendance_trend_result && $attendance_trend_result->num_rows > 0) {
        while($row = $attendance_trend_result->fetch_assoc()) {
            $attendance_trend_labels[] = date("d M", strtotime($row['fecha'])); // Format date like "11 Jun"
            $attendance_trend_data[] = (int)$row['present_students'];
        }
    }

    // --- Días de la semana con mayor ausentismo ---
    $dias_semana_es = [
        1 => 'Domingo', 2 => 'Lunes', 3 => 'Martes', 4 => 'Miércoles', 
        5 => 'Jueves', 6 => 'Viernes', 7 => 'Sábado'
    ];
    $absenteeism_by_dow_query = "
        SELECT 
            DAYOFWEEK(a.fecha) as day_of_week_number, 
            COUNT(a.id) as absence_count
        FROM 
            asistencia a
        JOIN
            student s ON a.student_id = s.id
        WHERE 
            a.estado = 'Ausente' AND a.tipo = 'Entrada'
            AND s.status = 'Activo'
        GROUP BY 
            day_of_week_number
    "; 
    $absenteeism_by_dow_result = $conn->query($absenteeism_by_dow_query);
    $absenteeism_by_dow = [];
    // Initialize all days of the week with 0 absences
    foreach ($dias_semana_es as $num => $name) {
        $absenteeism_by_dow[$name] = 0;
    }

    if ($absenteeism_by_dow_result && $absenteeism_by_dow_result->num_rows > 0) {
        while($row = $absenteeism_by_dow_result->fetch_assoc()) {
            if (isset($dias_semana_es[$row['day_of_week_number']])) {
                $day_name_es = $dias_semana_es[$row['day_of_week_number']];
                $absenteeism_by_dow[$day_name_es] = (int)$row['absence_count'];
            }
        }
    }
    // Sort by absence count in descending order
    arsort($absenteeism_by_dow);

    // Define MONTH_MAP_ES_SHORT_TO_EN_FULL if not already defined (e.g. if this block is moved or duplicated)
    if (!defined('MONTH_MAP_ES_SHORT_TO_EN_FULL')) {
        define('MONTH_MAP_ES_SHORT_TO_EN_FULL', [
            'Ene' => 'January', 'Feb' => 'February', 'Mar' => 'March', 'Abr' => 'April', 
            'May' => 'May', 'Jun' => 'June', 'Jul' => 'July', 'Ago' => 'August', 
            'Sep' => 'September', 'Oct' => 'October', 'Nov' => 'November', 'Dic' => 'December'
        ]);
    }

    // Preparación de datos para Dashboard de Rendimiento Académico (normaliza escala a 0–100)
    $outstanding_students = [];
    $students_needing_support = [];
    $limit_display_performance = 5;
    $threshold_outstanding = 90; // %
    $threshold_needs_support = 70; // %

    // Detectar escala de notas (si el máximo numérico <= 20 asumimos escala 0–20)
    $grade_scale_max = 0;
    $max_grade_sql = "SELECT MAX(CAST(grade AS DECIMAL(6,2))) AS max_grade
        FROM evaluation_grades
        WHERE grade REGEXP '^[0-9]+(\\.[0-9]+)?$'";
    $max_grade_res = $conn->query($max_grade_sql);
    if ($max_grade_res && $max_grade_res->num_rows > 0) {
        $row = $max_grade_res->fetch_assoc();
        $grade_scale_max = (float)($row['max_grade'] ?? 0);
    }
    $assumed_scale = ($grade_scale_max > 0 && $grade_scale_max <= 20) ? 20 : 100;
    $scale_factor = $assumed_scale > 0 ? (100 / $assumed_scale) : 1; // 5 si escala 20; 1 si ya está en 100

    // Promedios por estudiante, usando solo notas numéricas
    $query_academic_performance_sql = "
        SELECT
            s.id AS student_id,
            s.name AS student_name,
            AVG(CAST(eg.grade AS DECIMAL(6,2))) AS average_grade_raw
        FROM student s
        JOIN evaluation_grades eg ON s.id = eg.student_id
        WHERE s.status = 'Activo'
          AND eg.grade REGEXP '^[0-9]+(\\.[0-9]+)?$'
        GROUP BY s.id, s.name
        HAVING AVG(CAST(eg.grade AS DECIMAL(6,2))) IS NOT NULL
        ORDER BY average_grade_raw DESC
    ";

    $stmt_academic_performance = $conn->prepare($query_academic_performance_sql);
    $all_students_performance_data = [];

    if ($stmt_academic_performance) {
        $stmt_academic_performance->execute();
        $result_academic_performance = $stmt_academic_performance->get_result();

        if ($result_academic_performance && $result_academic_performance->num_rows > 0) {
            while ($row = $result_academic_performance->fetch_assoc()) {
                // Normalizar a porcentaje
                $row['average_grade_percent'] = (float)$row['average_grade_raw'] * $scale_factor;
                $all_students_performance_data[] = $row;
            }
        }
        $stmt_academic_performance->close();

        foreach ($all_students_performance_data as $student_data) {
            if (count($outstanding_students) < $limit_display_performance && $student_data['average_grade_percent'] >= $threshold_outstanding) {
                $outstanding_students[] = [
                    'name' => htmlspecialchars($student_data['student_name']),
                    'grade' => number_format($student_data['average_grade_percent'], 1)
                ];
            }
        }

        $potential_support_students_data = [];
        foreach ($all_students_performance_data as $student_data) {
            if ($student_data['average_grade_percent'] < $threshold_needs_support) {
                $potential_support_students_data[] = [
                    'name' => htmlspecialchars($student_data['student_name']),
                    'grade_raw' => (float)$student_data['average_grade_percent']
                ];
            }
        }

        usort($potential_support_students_data, function($a, $b) {
            return $a['grade_raw'] <=> $b['grade_raw'];
        });

        $students_needing_support_temp = array_slice($potential_support_students_data, 0, $limit_display_performance);

        foreach($students_needing_support_temp as $student) {
            $students_needing_support[] = [
                'name' => $student['name'],
                'grade' => number_format($student['grade_raw'], 1)
            ];
        }
    }

    // Promedio de calificaciones por Nivel
    $sql_avg_grades_lgs = "SELECT
        c.level AS nivel_name,
        AVG(eg.grade) AS average_grade,
        COUNT(DISTINCT eg.student_id) as student_count
    FROM
        evaluation_grades eg
    JOIN
        evaluations e ON eg.evaluation_id = e.id
    JOIN
        teacher_courses tc ON e.teacher_course_id = tc.id
    JOIN
        courses c ON tc.course_id = c.id
    JOIN
        student s ON eg.student_id = s.id
    WHERE
        eg.grade IS NOT NULL
        AND s.status = 'Activo'
    GROUP BY
        c.level
    ORDER BY
        average_grade DESC";
    $result_avg_grades_lgs = $conn->query($sql_avg_grades_lgs);
    $avg_grades_by_level_grade_subject = [];
    if ($result_avg_grades_lgs && $result_avg_grades_lgs->num_rows > 0) {
        while ($row = $result_avg_grades_lgs->fetch_assoc()) {
            $avg_grades_by_level_grade_subject[$row['nivel_name']] = [
                'average_grade' => $row['average_grade'],
                'student_count' => $row['student_count']
            ];
        }
    }
    ?>

    <div class="container-fluid" style="padding: 20px 15px;">
        <!-- Resumen de indicadores principales -->
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header bg-white border-bottom-0">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-primary"><i class="fas fa-tachometer-alt mr-2"></i>Panel de Control</h5>
                    <small class="text-muted">Actualizado: <?php echo htmlspecialchars($last_updated_at); ?></small>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="row m-0">
                    <div class="col-lg-3 col-md-6 p-4 border-right border-bottom">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-light p-3 mr-3">
                                <i class="fas fa-user-graduate text-primary fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 font-weight-bold"><?php echo $total_students; ?></h3>
                                <div class="text-muted">Estudiantes Activos</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 p-4 border-right border-bottom">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-light p-3 mr-3">
                                <i class="fas fa-chalkboard-teacher text-success fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 font-weight-bold"><?php echo $total_teachers; ?></h3>
                                <div class="text-muted">Docentes</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 p-4 border-right border-bottom">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-light p-3 mr-3">
                                <i class="fas fa-book-open text-info fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 font-weight-bold"><?php echo $total_courses; ?></h3>
                                <div class="text-muted">Cursos Ofrecidos</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 p-4 border-bottom">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-light p-3 mr-3">
                                <i class="fas fa-chalkboard text-warning fa-2x"></i>
                            </div>
                            <div>
                                <h3 class="mb-0 font-weight-bold"><?php echo $total_academic_courses; ?></h3>
                                <div class="text-muted">Asignaturas Activas</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row m-0">
                    <div class="col-lg-3 col-md-6 p-3 text-center border-right">
                        <h5 class="text-info mb-0"><?php echo number_format(($total_students > 0 ? ($total_students - $count_students_with_pending_payments) / $total_students * 100 : 0), 1); ?>%</h5>
                        <div class="small text-muted">Estudiantes al Día (global)</div>
                    </div>
                    <div class="col-lg-3 col-md-6 p-3 text-center border-right">
                        <h5 class="text-success mb-0"><?php echo number_format((100 - $absenteeism_percentage), 1); ?>%</h5>
                        <div class="small text-muted">Asistencia Promedio (últimos 30 días)</div>
                    </div>
                    <div class="col-lg-3 col-md-6 p-3 text-center border-right">
                        <h5 class="text-warning mb-0"><?php echo number_format(($total_payments_current_month > 0 && $total_expected_payments_month > 0 ? $total_payments_current_month / $total_expected_payments_month * 100 : 0), 1); ?>%</h5>
                        <div class="small text-muted">Eficiencia de Cobranza (mes actual)</div>
                    </div>
                    <div class="col-lg-3 col-md-6 p-3 text-center">
                        <h5 class="text-primary mb-0"><?php echo count($outstanding_students); ?>/<?php echo count($students_needing_support); ?></h5>
                        <div class="small text-muted">Destacados / Apoyo (académico)</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sistema de pestañas para organizar mejor el contenido -->
        <ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="academic-tab" data-toggle="tab" href="#academic" role="tab" aria-selected="true">
                    <i class="fas fa-graduation-cap mr-2"></i>Académico
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="financial-tab" data-toggle="tab" href="#financial" role="tab" aria-selected="false">
                    <i class="fas fa-dollar-sign mr-2"></i>Financiero
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="attendance-tab" data-toggle="tab" href="#attendance" role="tab" aria-selected="false">
                    <i class="fas fa-calendar-check mr-2"></i>Asistencia
                </a>
            </li>
        </ul>
        
        <div class="tab-content" id="myTabContent">
            <!-- Pestaña Académica -->
            <div class="tab-pane fade show active" id="academic" role="tabpanel" aria-labelledby="academic-tab">
                <!-- Distribución de estudiantes por nivel -->
                <h5 class="text-primary mb-3"><i class="fas fa-users-class mr-2"></i>Distribución de Estudiantes</h5>
                <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Estudiantes Activos</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_students; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-user-graduate fa-2x text-gray-300"></i>
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
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Docentes</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_teachers; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-chalkboard-teacher fa-2x text-gray-300"></i>
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
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Cursos Ofrecidos</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_courses; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-book-open fa-2x text-gray-300"></i>
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
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Asignaturas Activas</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $total_academic_courses; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-chalkboard fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- 2. DASHBOARD ACADÉMICO -->
        <!-- ============================================ -->
        <div class="row mt-5 mb-4">
            <div class="col-md-12">
                <h3 class="text-success mb-3"><i class="fas fa-graduation-cap"></i> Dashboard Académico</h3>
                <hr>
            </div>
        </div>

        <!-- Distribución de estudiantes por nivel -->
        <div class="row">
            <?php if (!empty($students_by_level) && count(array_filter($students_by_level)) > 0): ?>
                <?php 
                    $level_colors = ['Inicial' => 'warning', 'Primaria' => 'danger', 'Secundaria' => 'info'];
                    $level_icons = ['Inicial' => 'fas fa-baby', 'Primaria' => 'fas fa-shapes', 'Secundaria' => 'fas fa-atom'];
                ?>
                <?php foreach ($students_by_level as $level => $count): ?>
                    <?php 
                        $color_class_fragment = $level_colors[$level] ?? 'secondary'; 
                        $icon_class = $level_icons[$level] ?? 'fas fa-layer-group';
                    ?>
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card border-left-<?php echo $color_class_fragment; ?> shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-<?php echo $color_class_fragment; ?> text-uppercase mb-1">Nivel <?php echo htmlspecialchars($level); ?></div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $count; ?> estudiantes</div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="<?php echo $icon_class; ?> fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-md-12">
                    <div class="alert alert-info">No hay datos de estudiantes por nivel disponibles.</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Gráficos académicos y distribuciones -->
        <div class="row mt-4">
            <div class="col-lg-6">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Distribución de Estudiantes por Nivel</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-area" style="height: 300px;">
                            <canvas id="studentsByLevelChart"></canvas>
                        </div>
                        <hr>
                        <small class="text-muted">Visualización de la distribución de estudiantes por nivel académico.</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Asignaturas por Nivel</h6>
                    </div>
                    <div class="card-body" style="max-height: 350px; overflow-y: auto;">
                        <?php if (!empty($academic_courses_by_level) && count(array_filter($academic_courses_by_level)) > 0): ?>
                            <?php 
                                $course_level_colors = ['Inicial' => 'warning', 'Primaria' => 'danger', 'Secundaria' => 'info'];
                            ?>
                            <?php foreach ($academic_courses_by_level as $level => $count): ?>
                                <?php $color_class = $course_level_colors[$level] ?? 'secondary'; ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-<?php echo $color_class; ?> font-weight-bold"><?php echo htmlspecialchars($level); ?></span>
                                    <span class="badge badge-<?php echo $color_class; ?> badge-pill"><?php echo $count; ?> asignaturas</span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">No hay datos de asignaturas por nivel disponibles.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- 3. DASHBOARD DE RENDIMIENTO ACADÉMICO -->
        <!-- ============================================ -->
        <div class="row mt-5 mb-4">
            <div class="col-md-12">
                <h3 class="text-info mb-3"><i class="fas fa-chart-line"></i> Rendimiento Académico</h3>
                <hr>
            </div>
        </div>

        <div class="row">
            <!-- Estudiantes Destacados -->
            <div class="col-md-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-success"><i class="fas fa-star"></i> Estudiantes Destacados (Top <?php echo $limit_display_performance; ?>)</h6>
                    </div>
                    <div class="card-body" style="min-height: 200px; overflow-y: auto;">
                        <?php if (!empty($outstanding_students)): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($outstanding_students as $student): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <?php echo $student['name']; ?>
                                        <span class="badge badge-success badge-pill" style="font-size: 0.9em;"><?php echo $student['grade']; ?>%</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-center text-muted mt-3" style="font-size: 0.9rem;">No hay estudiantes destacados disponibles (Promedio ≥ <?php echo $threshold_outstanding; ?>%).</p>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center bg-light">
                        <small><a href="index.php?page=grades_report" class="text-success font-weight-bold">Ver informe completo &raquo;</a></small>
                    </div>
                </div>
            </div>

            <!-- Estudiantes con Necesidad de Apoyo -->
            <div class="col-md-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-warning"><i class="fas fa-hands-helping"></i> Estudiantes que Requieren Apoyo</h6>
                    </div>
                    <div class="card-body" style="min-height: 200px; overflow-y: auto;">
                        <?php if (!empty($students_needing_support)): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($students_needing_support as $student): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <?php echo $student['name']; ?>
                                        <span class="badge badge-danger badge-pill" style="font-size: 0.9em;"><?php echo $student['grade']; ?>%</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-center text-muted mt-3" style="font-size: 0.9rem;">No hay estudiantes que requieran apoyo (Promedio < <?php echo $threshold_needs_support; ?>%).</p>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center bg-light">
                        <small><a href="index.php?page=grades_report" class="text-warning font-weight-bold">Ver informe completo &raquo;</a></small>
                    </div>
                </div>
            </div>
        </div>

    <!-- Sección de promedio por nivel removida por solicitud -->

            </div>
            
            <!-- Pestaña Financiera -->
            <div class="tab-pane fade" id="financial" role="tabpanel" aria-labelledby="financial-tab">
                <h5 class="text-success mb-3"><i class="fas fa-dollar-sign mr-2"></i>Información Financiera</h5>
                
                <!-- Resumen financiero principal -->
        <div class="row">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Ingresos Este Mes</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">S/ <?php echo number_format($total_payments_current_month, 2); ?></div>
                                <?php 
                                $deltaMonth = $total_prev_month_payments > 0 ? (($total_payments_current_month - $total_prev_month_payments) / $total_prev_month_payments) * 100 : null; 
                                if ($deltaMonth !== null): ?>
                                <small class="d-block mt-1 <?php echo $deltaMonth >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $deltaMonth >= 0 ? '<i class=\'fas fa-arrow-up\'></i>' : '<i class=\'fas fa-arrow-down\'></i>'; ?>
                                    <?php echo number_format(abs($deltaMonth), 1); ?>% vs. mes anterior
                                </small>
                                <?php endif; ?>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
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
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Ingresos Este Año</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">S/ <?php echo number_format($total_payments_year, 2); ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-landmark fa-2x text-gray-300"></i>
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
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Pagos Recibidos (Mes Actual)</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">S/ <?php echo number_format($total_payments_current_month, 2); ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-receipt fa-2x text-gray-300"></i>
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
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Saldo Pendiente Total</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">S/ <?php echo number_format($total_outstanding_balance, 2); ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos financieros -->
        <div class="row mt-4">
            <div class="col-lg-6">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Resumen Financiero</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-area" style="height: 320px;">
                            <canvas id="financialSummaryChart"></canvas>
                        </div>
                        <hr>
                        <small class="text-muted">Comparativa de ingresos vs saldos pendientes.</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Top 10 Estudiantes con Mayores Deudas</h6>
                    </div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($top_debtors)): ?>
                            <table class="table table-sm table-hover table-striped">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th>
                                        <th>ID Estudiante</th>
                                        <th>Nombre Estudiante</th>
                                        <th class="text-right">Saldo Pendiente</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1; foreach ($top_debtors as $debtor): ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td><?php echo htmlspecialchars($debtor['student_id_no']); ?></td>
                                            <td><?php echo htmlspecialchars($debtor['student_name']); ?></td>
                                            <td class="text-right">S/ <?php echo number_format($debtor['outstanding_balance'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="alert alert-info">No hay estudiantes con deudas pendientes.</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center">
                        <a href="index.php?page=payments_report" class="btn btn-sm btn-outline-primary">Ver Reporte Completo de Pagos</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detalles financieros adicionales -->
        <div class="row mt-3">
            <div class="col-lg-8 mb-4">
                <div class="card shadow">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Ingresos por Concepto</h6>
                    </div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($income_by_concept)): ?>
                            <table class="table table-sm table-hover">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Concepto</th>
                                        <th class="text-right">Monto Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($income_by_concept as $concept => $amount): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($concept); ?></td>
                                            <td class="text-right">S/ <?php echo number_format($amount, 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="alert alert-info">No hay datos de ingresos por concepto.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 mb-4">
                <div class="card shadow">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Resumen de Pagos</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-column align-items-center">
                            <div class="mb-3">
                                <span class="text-muted">Total Esperado (Mes Actual):</span>
                                <div class="h5 font-weight-bold">S/ <?php echo number_format($total_expected_payments_month, 2); ?></div>
                            </div>
                            <div class="mb-3">
                                <span class="text-muted">Total Recibido (Mes Actual):</span>
                                <div class="h5 font-weight-bold">S/ <?php echo number_format($total_payments_current_month, 2); ?></div>
                            </div>
                            <div>
                                <span class="text-muted">Saldo Pendiente Total:</span>
                                <div class="h5 font-weight-bold">S/ <?php echo number_format($total_outstanding_balance, 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

            </div>
            
            <!-- Pestaña de Asistencia -->
            <div class="tab-pane fade" id="attendance" role="tabpanel" aria-labelledby="attendance-tab">
                <h5 class="text-warning mb-3"><i class="fas fa-calendar-check mr-2"></i>Control de Asistencia</h5>
                
                <!-- KPIs de asistencia -->
        <div class="row">
            <div class="col-xl-4 col-md-6 mb-4">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Promedio Asistencia Diaria</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($average_daily_attendance, 0); ?> estudiantes</div>
                                <small class="text-muted">Ventana: últimos 30 días</small>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clipboard-user fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6 mb-4">
                <div class="card border-left-danger shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Porcentaje de Ausentismo</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($absenteeism_percentage, 2); ?>%</div>
                                <?php 
                                $deltaAbs = ($absenteeism_percentage_prev > 0) ? (($absenteeism_percentage - $absenteeism_percentage_prev) / $absenteeism_percentage_prev) * 100 : null; 
                                if ($deltaAbs !== null): ?>
                                <small class="d-block mt-1 <?php echo $deltaAbs <= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $deltaAbs <= 0 ? '<i class=\'fas fa-arrow-down\'></i> Mejora' : '<i class=\'fas fa-arrow-up\'></i> Peor'; ?>
                                    <?php echo number_format(abs($deltaAbs), 1); ?>% vs. periodo previo
                                </small>
                                <?php endif; ?>
                                <small class="text-muted">Ventana: últimos 30 días</small>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-user-times fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Registros</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total_possible_attendance_days, 0); ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos y análisis de asistencia -->
        <div class="row mt-3">
            <div class="col-lg-8 mb-4">
                <div class="card shadow">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Tendencia de Asistencia (Últimos 30 días)</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-area" style="height: 350px;">
                            <canvas id="attendanceTrendChart"></canvas>
                        </div>
                        <hr>
                        <small class="text-muted">Número de estudiantes presentes por día en el último mes.</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 mb-4">
                <div class="card shadow">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Ausentismo por Día de la Semana</h6>
                    </div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($absenteeism_by_dow) && count(array_filter(array_values($absenteeism_by_dow))) > 0): ?>
                            <table class="table table-sm table-hover">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Día de la Semana</th>
                                        <th class="text-right">Ausencias</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($absenteeism_by_dow as $day => $count): ?>
                                        <?php if ($count > 0): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($day); ?></td>
                                                <td class="text-right">
                                                    <span class="badge badge-danger"><?php echo $count; ?></span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i> No se registraron ausencias significativas.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center">
                        <a href="index.php?page=attendance_report" class="btn btn-sm btn-outline-primary">Ver Reporte Completo</a>
                    </div>
                </div>
            </div>
        </div>

                <!-- Cierre necesario -->
            </div>
        </div>
        
        <!-- Distribución detallada por grado y sección -->
        <div class="card mt-4 border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-secondary"><i class="fas fa-sitemap mr-2"></i>Distribución por Grado y Sección</h5>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse" data-target="#detalleDistribucion">
                    <i class="fas fa-angle-down"></i> Ver detalles
                </button>
            </div>
            <div class="card-body collapse" id="detalleDistribucion">

        <div class="row">
            <?php 
            $has_detailed_data = false;
            if (!empty($students_by_level_grade_section)) {
                foreach ($students_by_level_grade_section as $level_data_check) {
                    if (!empty($level_data_check)) {
                        $has_detailed_data = true;
                        break;
                    }
                }
            }
            ?>
            <?php if ($has_detailed_data): ?>
                <?php 
                    $level_order = ['Inicial', 'Primaria', 'Secundaria'];
                    $displayed_levels_count = 0;
                    $levels_with_data_ordered = [];
                    foreach($level_order as $level_name) {
                        if (isset($students_by_level_grade_section[$level_name]) && !empty($students_by_level_grade_section[$level_name])) {
                            $levels_with_data_ordered[$level_name] = $students_by_level_grade_section[$level_name];
                        }
                    }
                ?>

                <?php foreach ($levels_with_data_ordered as $level => $grades): ?>
                    <?php $displayed_levels_count++; ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Nivel <?php echo htmlspecialchars($level); ?></h6>
                            </div>
                            <div class="card-body" style="max-height: 300px; overflow-y: auto; padding-top: 10px; padding-bottom: 10px;">
                                <?php if (!empty($grades)): ?>
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Grado</th>
                                                <th>Sección</th>
                                                <th class="text-right">Estudiantes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php ksort($grades); ?>
                                            <?php foreach ($grades as $grade => $sections): ?>
                                                <?php ksort($sections); ?>
                                                <?php foreach ($sections as $section => $count): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($grade); ?></td>
                                                        <td><?php echo htmlspecialchars($section); ?></td>
                                                        <td class="text-right">
                                                            <span class="badge badge-primary"><?php echo $count; ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <p class="text-center text-muted">No hay estudiantes registrados en este nivel.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php 
                    $empty_cols_to_add = ($displayed_levels_count > 0 && $displayed_levels_count < 3) ? (3 - ($displayed_levels_count % 3)) % 3 : 0;
                    for ($i = 0; $i < $empty_cols_to_add; $i++):
                ?>
                    <div class="col-lg-4 col-md-6 mb-4 d-none d-lg-block"></div>
                <?php endfor; ?>

            <?php else: ?>
                <div class="col-md-12">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No hay datos detallados de estudiantes por grado y sección disponibles.
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div> <!-- Fin del container-fluid -->

    <!-- Quick Actions Bar -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-bolt text-primary mr-2"></i>
                            <span class="font-weight-bold text-muted">Acciones Rápidas:</span>
                        </div>
                        <div class="btn-group" role="group">
                            <a href="index.php?page=students" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-user-plus"></i> Nuevo Estudiante
                            </a>
                            <a href="index.php?page=payments" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-money-bill-wave"></i> Registrar Pago
                            </a>
                            <a href="index.php?page=asistencia" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-calendar-check"></i> Asistencia
                            </a>
                            <a href="index.php?page=reports" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-chart-bar"></i> Reportes
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php elseif (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 2): // PROFESOR ?>
		<div class="inicio-cards">
			<a href="index.php?page=my_courses" class="inicio-card teacher-card" data-toggle="tooltip" title="Ver mis cursos asignados">
				<i class="fa fa-book"></i>
				<span>Mis Cursos</span>
			</a>
			<a href="index.php?page=grades" class="inicio-card teacher-card" data-toggle="tooltip" title="Gestionar notas de estudiantes">
				<i class="fa fa-clipboard-list"></i>
				<span>Notas</span>
			</a>
			<a href="index.php?page=competencias" class="inicio-card teacher-card" data-toggle="tooltip" title="Gestionar competencias">
				<i class="fa fa-star-half-alt"></i>
				<span>Competencias</span>
			</a>
			<a href="index.php?page=grades_report" class="inicio-card teacher-card" data-toggle="tooltip" title="Ver reportes de calificaciones">
				<i class="fa fa-chart-line"></i>
				<span>Reporte de Notas</span>
			</a>
		</div>
	<?php elseif (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 3): // AUXILIAR ?>
		<div class="inicio-cards">
			<a href="index.php?page=asistencia" class="inicio-card aux-card" data-toggle="tooltip" title="Registrar asistencia">
				<i class="fa fa-calendar-check"></i>
				<span>Asistencia</span>
			</a>
			<a href="index.php?page=attendance_report" class="inicio-card aux-card" data-toggle="tooltip" title="Ver reportes de asistencia">
				<i class="fa fa-calendar-alt"></i>
				<span>Reporte de Asistencia</span>
			</a>
			<a href="index.php?page=students" class="inicio-card aux-card" data-toggle="tooltip" title="Ver lista de estudiantes">
				<i class="fa fa-users"></i>
				<span>Estudiantes</span>
			</a>
		</div>
	<?php elseif (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 4): // ESTUDIANTE ?>
		<div class="inicio-cards">
			<a href="index.php?page=my_payments" class="inicio-card student-card" data-toggle="tooltip" title="Ver mis pagos realizados">
				<i class="fa fa-receipt"></i>
				<span>Mis Pagos</span>
			</a>
			<a href="index.php?page=my_debts" class="inicio-card student-card" data-toggle="tooltip" title="Ver mis deudas pendientes">
				<i class="fa fa-money-bill-wave"></i>
				<span>Mis Deudas</span>
			</a>
			<a href="index.php?page=my_grades" class="inicio-card student-card" data-toggle="tooltip" title="Ver mis calificaciones">
				<i class="fa fa-clipboard-list"></i>
				<span>Mis Notas</span>
			</a>
		</div>
    <?php else: ?>
        <div class="inicio-cards">
            <p class="text-muted">No hay opciones disponibles para su rol o no ha iniciado sesión correctamente.</p>
        </div>
	<?php endif; ?>
	
	<div class="inicio-footer">
		<p>&copy; <?php echo date('Y'); ?> Sistema de Gestión Educativa</p>
		<p>Versión 2.0</p>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function(){
    // ===== IMPORTANTE: SOLO TOOLTIPS DENTRO DEL DASHBOARD, NUNCA EN TOPBAR =====
    var dashboardTooltips = $('.inicio-dashboard [data-toggle="tooltip"], .inicio-cards [data-toggle="tooltip"]');
    if (dashboardTooltips.length > 0 && typeof dashboardTooltips.tooltip === 'function') {
        try {
            dashboardTooltips.tooltip();
        } catch(e) {
            console.log('Tooltip init skipped');
        }
    }
    
    // Animaciones de tarjetas SOLO dentro del dashboard
    var inicioCards = $('.inicio-cards .inicio-card');
    if (inicioCards.length > 0 && typeof inicioCards.addClass === 'function') {
        inicioCards.addClass('animate__animated animate__fadeIn');
        
        inicioCards.on('mousedown', function() {
            $(this).css('transform', 'scale(0.98)');
        });
        
        inicioCards.on('mouseup mouseleave', function() {
            if ($(this).is(':hover')) {
                $(this).css('transform', 'translateY(-5px)');
            } else {
                $(this).css('transform', 'none');
            }
        });
    }
    
    // Gráficos
    if ($('#studentsByLevelChart').length && typeof Chart !== 'undefined') {
        try {
            var ctxStudents = document.getElementById('studentsByLevelChart').getContext('2d');
            var studentData = <?php echo json_encode(array_values($students_by_level)); ?>;
            var studentLabels = <?php echo json_encode(array_keys($students_by_level)); ?>;
            
            if (studentData.some(val => val > 0)) {
                new Chart(ctxStudents, {
                    type: 'pie',
                    data: {
                        labels: studentLabels,
                        datasets: [{
                            label: 'Estudiantes por Nivel',
                            data: studentData,
                            backgroundColor: [
                                'rgba(255, 159, 64, 0.7)',
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(54, 162, 235, 0.7)'
                            ],
                            borderColor: [
                                'rgba(255, 159, 64, 1)',
                                'rgba(255, 99, 132, 1)',
                                'rgba(54, 162, 235, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        legend: { position: 'top' },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.label + ': ' + context.parsed + ' estudiantes';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Error creating students chart:', error);
        }
    }

    if ($('#financialSummaryChart').length && typeof Chart !== 'undefined') {
        try {
            var ctxFinancial = document.getElementById('financialSummaryChart').getContext('2d');
            var financialData = <?php echo json_encode($financial_chart_data); ?>;
            var financialLabels = <?php echo json_encode($financial_chart_labels); ?>;
            
            if (financialData.some(val => val > 0)) {
                new Chart(ctxFinancial, {
                    type: 'bar',
                    data: {
                        labels: financialLabels,
                        datasets: [{
                            label: 'Monto (S/)',
                            data: financialData,
                            backgroundColor: [
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(255, 99, 132, 0.7)'
                            ],
                            borderColor: [
                                'rgba(75, 192, 192, 1)',
                                'rgba(255, 99, 132, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            yAxes: [{
                                ticks: {
                                    beginAtZero: true,
                                    callback: function(value) { return 'S/ ' + value.toLocaleString(); }
                                }
                            }]
                        },
                        legend: { display: false },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'S/ ' + context.parsed.y.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Error creating financial chart:', error);
        }
    }

    if ($('#attendanceTrendChart').length && typeof Chart !== 'undefined') {
        try {
            var ctxAttendance = document.getElementById('attendanceTrendChart').getContext('2d');
            var attendanceData = <?php echo json_encode($attendance_trend_data); ?>;
            var attendanceLabels = <?php echo json_encode($attendance_trend_labels); ?>;
            
            if (attendanceData.length > 0 && attendanceData.some(val => val > 0)) {
                new Chart(ctxAttendance, {
                    type: 'line',
                    data: {
                        labels: attendanceLabels,
                        datasets: [{
                            label: 'Estudiantes Presentes',
                            data: attendanceData,
                            backgroundColor: 'rgba(54, 162, 235, 0.5)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: 'rgba(54, 162, 235, 1)',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            yAxes: [{
                                ticks: {
                                    beginAtZero: true,
                                    stepSize: 1
                                },
                                scaleLabel: {
                                    display: true,
                                    labelString: 'Número de Estudiantes'
                                }
                            }],
                            xAxes: [{
                                scaleLabel: {
                                    display: true,
                                    labelString: 'Fecha'
                                }
                            }]
                        },
                        legend: { display: true, position: 'top' },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y + ' estudiantes';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Error creating attendance chart:', error);
        }
    }
});
</script>