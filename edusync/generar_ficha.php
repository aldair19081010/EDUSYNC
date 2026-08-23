<?php
require_once 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();

$session_school_id = intval($_SESSION['login_school_id'] ?? 0);

if (!isset($_GET['type'])) {
    die('Tipo de ficha no especificado');
}

$type = $_GET['type'];
$format = $_GET['format'] ?? 'pdf';

// Si el formato es Excel, redirigir al generador de Excel
if ($format === 'excel') {
    $query_string = $_SERVER['QUERY_STRING'];
    header("Location: generar_ficha_excel.php?$query_string");
    exit;
}

// Función para generar HTML base
function generateHTMLBase($title, $content) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . $title . '</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 20px;
                background: #f5f5f5;
            }
            .ficha-container {
                max-width: 800px;
                margin: 0 auto;
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 15px rgba(0,0,0,0.1);
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 3px solid #4e73df;
                padding-bottom: 20px;
            }
            .header h1 {
                color: #4e73df;
                margin: 0;
                font-size: 24px;
            }
            .header p {
                color: #666;
                margin: 5px 0 0 0;
            }
            .section {
                margin-bottom: 25px;
            }
            .section h3 {
                background: #4e73df;
                color: white;
                padding: 10px 15px;
                margin: 0 0 15px 0;
                border-radius: 5px;
                font-size: 16px;
            }
            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin-bottom: 15px;
            }
            .info-item {
                display: flex;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }
            .info-label {
                font-weight: bold;
                color: #333;
                min-width: 120px;
            }
            .info-value {
                color: #666;
                flex: 1;
            }
            .table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            .table th, .table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            .table th {
                background: #f8f9fa;
                font-weight: bold;
                color: #333;
            }
            .table tr:nth-child(even) {
                background: #f9f9f9;
            }
            .tutor-subsection {
                background: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 5px;
                padding: 15px;
                margin-bottom: 15px;
            }
            .tutor-subsection h4 {
                color: #4e73df;
                margin: 0 0 10px 0;
                font-size: 14px;
                font-weight: 600;
                padding-bottom: 5px;
                border-bottom: 1px solid #dee2e6;
            }
            .footer {
                text-align: center;
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #eee;
                color: #666;
                font-size: 12px;
            }
            .no-print {
                text-align: center;
                margin-bottom: 20px;
            }
            .btn-print {
                background: #1cc88a;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 5px;
                cursor: pointer;
                font-size: 14px;
            }
            .btn-print:hover {
                background: #17a673;
            }
            @media print {
                .no-print { display: none; }
                body { background: white; }
                .ficha-container { box-shadow: none; }
            }
        </style>
    </head>
    <body>
        <div class="ficha-container">
            <div class="no-print">
                <button class="btn-print" onclick="window.print()">🖨️ Imprimir Ficha</button>
            </div>
            ' . $content . '
            <div class="footer">
                <p>Generado el ' . date('d/m/Y H:i') . ' | Sistema de Gestión Educativa</p>
            </div>
        </div>
    </body>
    </html>';
}

switch ($type) {
    case 'student':
        generateStudentFicha();
        break;
    case 'teacher':
        generateTeacherFicha();
        break;
    case 'student_list':
        generateStudentList();
        break;
    case 'teacher_list':
        generateTeacherList();
        break;
    case 'year_stats':
        generateYearStats();
        break;
    case 'enrollment_by_level':
        generateEnrollmentByLevel();
        break;
    case 'financial_report':
        generateFinancialReport();
        break;
    default:
        die('Tipo de ficha no válido');
}

function generateStudentFicha() {
    global $conn, $session_school_id;
    
    $id = intval($_GET['id']);
    
    // Obtener información del estudiante
    $sql = "SELECT * FROM student WHERE id = $id" . ($session_school_id ? " AND school_id = $session_school_id" : "");
    $result = $conn->query($sql);
    
    if ($result->num_rows == 0) {
        die('Estudiante no encontrado');
    }
    
    $student = $result->fetch_assoc();
    
    // Obtener cursos asignados
    $courses_sql = "SELECT c.course, c.level, c.total_amount, 
                           COALESCE(ef.discounted_amount, ef.total_fee) as final_amount,
                           (SELECT COALESCE(SUM(amount), 0) FROM payments p WHERE p.ef_id = ef.id) as paid_amount
                    FROM student_ef_list ef
                    INNER JOIN student s ON s.id = ef.student_id
                    INNER JOIN courses c ON c.id = ef.course_id
                    WHERE ef.student_id = $id" . ($session_school_id ? " AND s.school_id = $session_school_id" : "");
    $courses_result = $conn->query($courses_sql);
    
    $content = '
    <div class="header">
        <h1>FICHA INDIVIDUAL DE ESTUDIANTE</h1>
        <p>Información Personal y Académica</p>
    </div>
    
    <div class="section">
        <h3>📋 Información Personal</h3>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">ID Estudiante:</span>
                <span class="info-value">' . htmlspecialchars($student['id_no']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Nombre Completo:</span>
                <span class="info-value">' . htmlspecialchars($student['name']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Email:</span>
                <span class="info-value">' . htmlspecialchars($student['email']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Teléfono:</span>
                <span class="info-value">' . htmlspecialchars($student['contact']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Dirección:</span>
                <span class="info-value">' . htmlspecialchars($student['address']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Fecha de Registro:</span>
                <span class="info-value">' . date('d/m/Y', strtotime($student['date_created'])) . '</span>
            </div>
        </div>
    </div>
    
    <div class="section">
        <h3>🎓 Información Académica</h3>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Nivel:</span>
                <span class="info-value">' . htmlspecialchars($student['nivel']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Grado:</span>
                <span class="info-value">' . htmlspecialchars($student['grado']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Sección:</span>
                <span class="info-value">' . htmlspecialchars($student['seccion'] ?? 'N/A') . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Estado:</span>
                <span class="info-value">' . ($student['status'] == 'Activo' ? 'Activo' : $student['status']) . '</span>
            </div>
        </div>
    </div>';
    
    // Sección de información del apoderado/tutor
    $content .= '
    <div class="section">
        <h3>👨‍👩‍👧‍👦 Información del Apoderado/Tutor</h3>';
    
    // Tutor/Apoderado Principal
    if (!empty($student['tutor1_nombre'])) {
        $content .= '
        <div class="tutor-subsection">
            <h4>Apoderado Principal</h4>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Nombre:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor1_nombre'] . ' ' . $student['tutor1_apellido']) . '</span>
                </div>
                <div class="info-item">
                    <span class="info-label">DNI:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor1_dni'] ?: 'No registrado') . '</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Teléfono:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor1_telefono'] ?: 'No registrado') . '</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Relación:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor1_relacion'] ?: 'No especificada') . '</span>
                </div>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <span class="info-label">Dirección:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor1_direccion'] ?: 'No registrada') . '</span>
                </div>
            </div>
        </div>';
    }
    
    // Tutor/Apoderado Secundario
    if (!empty($student['tutor2_nombre'])) {
        $content .= '
        <div class="tutor-subsection">
            <h4>Apoderado Secundario</h4>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Nombre:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor2_nombre'] . ' ' . $student['tutor2_apellido']) . '</span>
                </div>
                <div class="info-item">
                    <span class="info-label">DNI:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor2_dni'] ?: 'No registrado') . '</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Teléfono:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor2_telefono'] ?: 'No registrado') . '</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Relación:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor2_relacion'] ?: 'No especificada') . '</span>
                </div>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <span class="info-label">Dirección:</span>
                    <span class="info-value">' . htmlspecialchars($student['tutor2_direccion'] ?: 'No registrada') . '</span>
                </div>
            </div>
        </div>';
    }
    
    // Si no hay tutores registrados
    if (empty($student['tutor1_nombre']) && empty($student['tutor2_nombre'])) {
        $content .= '
        <div class="info-item">
            <span class="info-value" style="color: #999; font-style: italic;">No hay información de apoderados registrada</span>
        </div>';
    }
    
    $content .= '
    </div>';
    
    if ($courses_result->num_rows > 0) {
        $content .= '
        <div class="section">
            <h3>💰 Información Financiera</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Curso</th>
                        <th>Nivel</th>
                        <th>Monto Final</th>
                        <th>Pagado</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>';
        
        $total_amount = 0;
        $total_paid = 0;
        
        while ($course = $courses_result->fetch_assoc()) {
            $balance = $course['final_amount'] - $course['paid_amount'];
            $total_amount += $course['final_amount'];
            $total_paid += $course['paid_amount'];
            
            $content .= '
                    <tr>
                        <td>' . htmlspecialchars($course['course']) . '</td>
                        <td>' . htmlspecialchars($course['level']) . '</td>
                        <td>S/ ' . number_format($course['final_amount'], 2) . '</td>
                        <td>S/ ' . number_format($course['paid_amount'], 2) . '</td>
                        <td>S/ ' . number_format($balance, 2) . '</td>
                    </tr>';
        }
        
        $total_balance = $total_amount - $total_paid;
        $content .= '
                </tbody>
                <tfoot>
                    <tr style="font-weight: bold; background: #e9ecef;">
                        <td colspan="2">TOTALES</td>
                        <td>S/ ' . number_format($total_amount, 2) . '</td>
                        <td>S/ ' . number_format($total_paid, 2) . '</td>
                        <td>S/ ' . number_format($total_balance, 2) . '</td>
                    </tr>
                </tfoot>
            </table>
        </div>';
    }
    
    echo generateHTMLBase('Ficha de Estudiante - ' . $student['name'], $content);
}

function generateTeacherFicha() {
    global $conn, $session_school_id;
    
    $id = intval($_GET['id']);
    
    // Obtener información del docente
    $sql = "SELECT * FROM teacher WHERE id = $id" . ($session_school_id ? " AND school_id = $session_school_id" : "");
    $result = $conn->query($sql);
    
    if ($result->num_rows == 0) {
        die('Docente no encontrado');
    }
    
    $teacher = $result->fetch_assoc();
    
    // Obtener cursos asignados
    $courses_sql = "SELECT ac.name as course, tc.level, tc.grado, tc.seccion, ay.year
                    FROM teacher_courses tc
                    INNER JOIN academic_courses ac ON ac.id = tc.course_id
                    LEFT JOIN academic_year ay ON tc.academic_year_id = ay.id
                    WHERE tc.teacher_id = $id" . ($session_school_id ? " AND tc.school_id = $session_school_id" : "") . "
                    ORDER BY ay.year DESC, tc.level, ac.name, tc.grado, tc.seccion";
    $courses_result = $conn->query($courses_sql);
    
    // Obtener información de usuario del sistema
    $user_sql = "SELECT username, type FROM users WHERE teacher_id = $id" . ($session_school_id ? " AND school_id = $session_school_id" : "");
    $user_result = $conn->query($user_sql);
    $user = $user_result->num_rows > 0 ? $user_result->fetch_assoc() : null;
    
    $content = '
    <div class="header">
        <h1>FICHA INDIVIDUAL DE DOCENTE</h1>
        <p>Información Personal y Profesional</p>
    </div>
    
    <div class="section">
        <h3>👨‍🏫 Información Personal</h3>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">ID Docente:</span>
                <span class="info-value">' . htmlspecialchars($teacher['id_no']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Nombre Completo:</span>
                <span class="info-value">' . htmlspecialchars($teacher['name']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Email:</span>
                <span class="info-value">' . htmlspecialchars($teacher['email']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Teléfono:</span>
                <span class="info-value">' . htmlspecialchars($teacher['contact']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Dirección:</span>
                <span class="info-value">' . htmlspecialchars($teacher['address']) . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Especialidad:</span>
                <span class="info-value">' . htmlspecialchars($teacher['specialty'] ?? 'No especificada') . '</span>
            </div>
        </div>
    </div>';
    
    if ($user) {
        $content .= '
        <div class="section">
            <h3>🔐 Acceso al Sistema</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Usuario:</span>
                    <span class="info-value">' . htmlspecialchars($user['username']) . '</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Tipo de Usuario:</span>
                    <span class="info-value">' . ($user['type'] == 1 ? 'Administrador' : 'Docente') . '</span>
                </div>
            </div>
        </div>';
    }
    
    if ($courses_result->num_rows > 0) {
        $content .= '
        <div class="section">
            <h3>📚 Cursos Asignados</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Curso</th>
                        <th>Nivel</th>
                        <th>Grado</th>
                        <th>Sección</th>
                        <th>Año Académico</th>
                    </tr>
                </thead>
                <tbody>';
        
        while ($course = $courses_result->fetch_assoc()) {
            $content .= '
                    <tr>
                        <td>' . htmlspecialchars($course['course']) . '</td>
                        <td>' . htmlspecialchars($course['level']) . '</td>
                        <td>' . htmlspecialchars($course['grado']) . '</td>
                        <td>' . htmlspecialchars($course['seccion']) . '</td>
                        <td>' . htmlspecialchars($course['year'] ?: 'N/A') . '</td>
                    </tr>';
        }
        
        $content .= '
                </tbody>
            </table>
        </div>';
    }
    
    echo generateHTMLBase('Ficha de Docente - ' . $teacher['name'], $content);
}

function generateStudentList() {
    global $conn, $session_school_id;
    
    $nivel = $_GET['nivel'] ?? '';
    $grado = $_GET['grado'] ?? '';
    $seccion = $_GET['seccion'] ?? '';
    
    $where = "WHERE (status = 'Activo' OR status IS NULL)" . ($session_school_id ? " AND school_id = $session_school_id" : "");
    if ($nivel) $where .= " AND nivel = '" . $conn->real_escape_string($nivel) . "'";
    if ($grado) $where .= " AND grado = '" . $conn->real_escape_string($grado) . "'";
    if ($seccion) $where .= " AND seccion = '" . $conn->real_escape_string($seccion) . "'";
    
    $sql = "SELECT * FROM student $where ORDER BY name";
    $result = $conn->query($sql);
    
    $title = "RELACIÓN DE ESTUDIANTES";
    if ($nivel) $title .= " - " . $nivel;
    if ($grado) $title .= " " . $grado;
    if ($seccion) $title .= " Sección " . $seccion;
    
    $content = '
    <div class="header">
        <h1>' . $title . '</h1>
        <p>Lista de estudiantes activos</p>
    </div>
    
    <div class="section">
        <table class="table">
            <thead>
                <tr>
                    <th>N°</th>
                    <th>ID</th>
                    <th>Nombre Completo</th>
                    <th>Nivel</th>
                    <th>Grado</th>
                    <th>Sección</th>
                    <th>Teléfono</th>
                </tr>
            </thead>
            <tbody>';
    
    $count = 1;
    while ($student = $result->fetch_assoc()) {
        $content .= '
                <tr>
                    <td>' . $count++ . '</td>
                    <td>' . htmlspecialchars($student['id_no']) . '</td>
                    <td>' . htmlspecialchars($student['name']) . '</td>
                    <td>' . htmlspecialchars($student['nivel']) . '</td>
                    <td>' . htmlspecialchars($student['grado']) . '</td>
                    <td>' . htmlspecialchars($student['seccion'] ?? '-') . '</td>
                    <td>' . htmlspecialchars($student['contact']) . '</td>
                </tr>';
    }
    
    $content .= '
            </tbody>
        </table>
        <p><strong>Total de estudiantes: ' . ($count - 1) . '</strong></p>
    </div>';
    
    echo generateHTMLBase($title, $content);
}

function generateTeacherList() {
    global $conn, $session_school_id;
    
        $sql = "SELECT t.*, 
                 (SELECT COUNT(*) FROM teacher_courses tc WHERE tc.teacher_id = t.id" . ($session_school_id ? " AND tc.school_id = $session_school_id" : "") . ") as course_count,
                   u.username
            FROM teacher t
             LEFT JOIN users u ON u.teacher_id = t.id" . ($session_school_id ? " AND u.school_id = $session_school_id" : "") . "
             " . ($session_school_id ? "WHERE t.school_id = $session_school_id" : "") . "
            ORDER BY t.name";
    $result = $conn->query($sql);
    
    $content = '
    <div class="header">
        <h1>RELACIÓN DE DOCENTES</h1>
        <p>Lista completa de docentes de la institución</p>
    </div>
    
    <div class="section">
        <table class="table">
            <thead>
                <tr>
                    <th>N°</th>
                    <th>ID</th>
                    <th>Nombre Completo</th>
                    <th>Email</th>
                    <th>Teléfono</th>
                    <th>Cursos Asignados</th>
                    <th>Acceso Sistema</th>
                </tr>
            </thead>
            <tbody>';
    
    $count = 1;
    while ($teacher = $result->fetch_assoc()) {
        $content .= '
                <tr>
                    <td>' . $count++ . '</td>
                    <td>' . htmlspecialchars($teacher['id_no']) . '</td>
                    <td>' . htmlspecialchars($teacher['name']) . '</td>
                    <td>' . htmlspecialchars($teacher['email']) . '</td>
                    <td>' . htmlspecialchars($teacher['contact']) . '</td>
                    <td>' . $teacher['course_count'] . '</td>
                    <td>' . ($teacher['username'] ? 'Sí' : 'No') . '</td>
                </tr>';
    }
    
    $content .= '
            </tbody>
        </table>
        <p><strong>Total de docentes: ' . ($count - 1) . '</strong></p>
    </div>';
    
    echo generateHTMLBase('Relación de Docentes', $content);
}

function generateYearStats() {
    global $conn, $session_school_id;
    
    $year_id = intval($_GET['year_id']);
    
    // Obtener información del año académico
    $year_sql = "SELECT * FROM academic_year WHERE id = $year_id" . ($session_school_id ? " AND school_id = $session_school_id" : "");
    $year_result = $conn->query($year_sql);
    
    if ($year_result->num_rows == 0) {
        die('Año académico no encontrado');
    }
    
    $year = $year_result->fetch_assoc();
    
    // Estadísticas generales
    $stats_sql = "SELECT 
                      (SELECT COUNT(*) FROM student WHERE (status = 'Activo' OR status IS NULL)" . ($session_school_id ? " AND school_id = $session_school_id" : "") . ") as total_students,
                      (SELECT COUNT(*) FROM teacher" . ($session_school_id ? " WHERE school_id = $session_school_id" : "") . ") as total_teachers,
                      (SELECT COUNT(*) FROM courses WHERE academic_year_id = $year_id) as total_courses,
                      (SELECT COUNT(*) FROM student_ef_list ef 
                       INNER JOIN courses c ON c.id = ef.course_id
                       INNER JOIN student s ON s.id = ef.student_id
                       WHERE c.academic_year_id = $year_id" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . ") as total_fees,
                      (SELECT COALESCE(SUM(amount), 0) FROM payments p 
                       INNER JOIN student_ef_list ef ON ef.id = p.ef_id
                       INNER JOIN courses c ON c.id = ef.course_id
                       INNER JOIN student s ON s.id = ef.student_id
                       WHERE c.academic_year_id = $year_id" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . ") as total_collected";
    $stats_result = $conn->query($stats_sql);
    $stats = $stats_result->fetch_assoc();
    
    $content = '
    <div class="header">
        <h1>ESTADÍSTICAS GENERALES</h1>
        <p>Año Académico: ' . htmlspecialchars($year['year']) . '</p>
    </div>
    
    <div class="section">
        <h3>📊 Resumen General</h3>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Total Estudiantes:</span>
                <span class="info-value">' . $stats['total_students'] . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Total Docentes:</span>
                <span class="info-value">' . $stats['total_teachers'] . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Total Cursos:</span>
                <span class="info-value">' . $stats['total_courses'] . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Deudas Asignadas:</span>
                <span class="info-value">' . $stats['total_fees'] . '</span>
            </div>
            <div class="info-item">
                <span class="info-label">Total Recaudado:</span>
                <span class="info-value">S/ ' . number_format($stats['total_collected'], 2) . '</span>
            </div>
        </div>
    </div>';
    
    echo generateHTMLBase('Estadísticas ' . $year['year'], $content);
}

function generateEnrollmentByLevel() {
    global $conn, $session_school_id;
    
    $year_id = intval($_GET['year_id']);
    
    // Obtener información del año académico
    $year_sql = "SELECT * FROM academic_year WHERE id = $year_id" . ($session_school_id ? " AND school_id = $session_school_id" : "");
    $year_result = $conn->query($year_sql);
    
    if ($year_result->num_rows == 0) {
        die('Año académico no encontrado');
    }
    
    $year = $year_result->fetch_assoc();
    
    // Matrícula por nivel
    $enrollment_sql = "SELECT 
                           nivel,
                           grado,
                           COUNT(*) as total_students
                       FROM student 
                       WHERE (status = 'Activo' OR status IS NULL)" . ($session_school_id ? " AND school_id = $session_school_id" : "") . "
                       GROUP BY nivel, grado
                       ORDER BY nivel, grado";
    $enrollment_result = $conn->query($enrollment_sql);
    
    $content = '
    <div class="header">
        <h1>MATRÍCULA POR NIVEL</h1>
        <p>Año Académico: ' . htmlspecialchars($year['year']) . '</p>
    </div>
    
    <div class="section">
        <h3>📈 Distribución de Estudiantes</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Nivel</th>
                    <th>Grado</th>
                    <th>Total Estudiantes</th>
                </tr>
            </thead>
            <tbody>';
    
    $total_general = 0;
    while ($row = $enrollment_result->fetch_assoc()) {
        $total_general += $row['total_students'];
        $content .= '
                <tr>
                    <td>' . htmlspecialchars($row['nivel']) . '</td>
                    <td>' . htmlspecialchars($row['grado']) . '</td>
                    <td>' . $row['total_students'] . '</td>
                </tr>';
    }
    
    $content .= '
            </tbody>
            <tfoot>
                <tr style="font-weight: bold; background: #e9ecef;">
                    <td colspan="2">TOTAL GENERAL</td>
                    <td>' . $total_general . '</td>
                </tr>
            </tfoot>
        </table>
    </div>';
    
    echo generateHTMLBase('Matrícula por Nivel ' . $year['year'], $content);
}

function generateFinancialReport() {
    global $conn, $session_school_id;
    
    $year_id = intval($_GET['year_id']);
    
    // Obtener información del año académico
    $year_sql = "SELECT * FROM academic_year WHERE id = $year_id" . ($session_school_id ? " AND school_id = $session_school_id" : "");
    $year_result = $conn->query($year_sql);
    
    if ($year_result->num_rows == 0) {
        die('Año académico no encontrado');
    }
    
    $year = $year_result->fetch_assoc();
    
    // Reporte financiero
    $financial_sql = "SELECT 
                          c.course,
                          c.level,
                          c.grades,
                          COUNT(ef.id) as total_assignments,
                          SUM(COALESCE(ef.discounted_amount, ef.total_fee)) as total_amount,
                          COALESCE(SUM(p.amount), 0) as total_paid
                      FROM courses c
                      LEFT JOIN student_ef_list ef ON ef.course_id = c.id
                      LEFT JOIN student s ON s.id = ef.student_id
                      LEFT JOIN payments p ON p.ef_id = ef.id
                      WHERE c.academic_year_id = $year_id
                      " . ($session_school_id ? " AND (s.school_id = $session_school_id OR s.school_id IS NULL)" : "") . "
                      GROUP BY c.id, c.course, c.level, c.grades
                      ORDER BY c.level, c.course";
    $financial_result = $conn->query($financial_sql);
    
    $content = '
    <div class="header">
        <h1>REPORTE FINANCIERO</h1>
        <p>Año Académico: ' . htmlspecialchars($year['year']) . '</p>
    </div>
    
    <div class="section">
        <h3>💰 Resumen Financiero por Curso</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Curso</th>
                    <th>Nivel</th>
                    <th>Grados</th>
                    <th>Asignaciones</th>
                    <th>Monto Total</th>
                    <th>Pagado</th>
                    <th>Pendiente</th>
                </tr>
            </thead>
            <tbody>';
    
    $total_amount = 0;
    $total_paid = 0;
    while ($row = $financial_result->fetch_assoc()) {
        $pending = $row['total_amount'] - $row['total_paid'];
        $total_amount += $row['total_amount'];
        $total_paid += $row['total_paid'];
        
        $content .= '
                <tr>
                    <td>' . htmlspecialchars($row['course']) . '</td>
                    <td>' . htmlspecialchars($row['level']) . '</td>
                    <td>' . htmlspecialchars($row['grades'] ?: 'Todos') . '</td>
                    <td>' . $row['total_assignments'] . '</td>
                    <td>S/ ' . number_format($row['total_amount'], 2) . '</td>
                    <td>S/ ' . number_format($row['total_paid'], 2) . '</td>
                    <td>S/ ' . number_format($pending, 2) . '</td>
                </tr>';
    }
    
    $total_pending = $total_amount - $total_paid;
    $content .= '
            </tbody>
            <tfoot>
                <tr style="font-weight: bold; background: #e9ecef;">
                    <td colspan="4">TOTALES</td>
                    <td>S/ ' . number_format($total_amount, 2) . '</td>
                    <td>S/ ' . number_format($total_paid, 2) . '</td>
                    <td>S/ ' . number_format($total_pending, 2) . '</td>
                </tr>
            </tfoot>
        </table>
    </div>';
    
    echo generateHTMLBase('Reporte Financiero ' . $year['year'], $content);
}
?>
