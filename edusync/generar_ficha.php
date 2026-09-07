<?php
require_once 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();

$session_school_id = intval($_SESSION['login_school_id'] ?? 0);
$session_user_id = intval($_SESSION['login_id'] ?? 0);
if ($session_school_id <= 0 || $session_user_id <= 0) die('No autorizado');
$permission = $conn->prepare('SELECT type,is_director FROM users WHERE id=? AND school_id=? LIMIT 1');
$permission->bind_param('ii',$session_user_id,$session_school_id);$permission->execute();$role=$permission->get_result()->fetch_assoc();$permission->close();
if (!$role || (int)$role['type'] !== 1) die('No tiene permiso para generar fichas institucionales.');

if (!isset($_GET['type'])) {
    die('Tipo de ficha no especificado');
}

$type = $_GET['type'];
$format = $_GET['format'] ?? 'pdf';
$allowed_types = ['student','teacher','student_list','teacher_list','year_stats','enrollment_by_level','financial_report','data_quality','unassigned_courses'];
if (!in_array($type,$allowed_types,true)) die('Tipo de ficha no válido');

function auditInstitutionalReport(string $reportType): void {
    global $conn,$session_school_id;
    $exists=$conn->query("SHOW TABLES LIKE 'institutional_report_audit'");
    if(!$exists||!$exists->num_rows)return;
    $user=(int)($_SESSION['login_id']??0);$year=(int)($_GET['year_id']??0);$entity=null;
    if(in_array($reportType,['student','teacher'],true))$entity=(int)($_GET['id']??0);
    $details=json_encode(array_intersect_key($_GET,array_flip(['nivel','grado','seccion','format'])),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ip=$_SERVER['REMOTE_ADDR']??null;
    $stmt=$conn->prepare('INSERT INTO institutional_report_audit (school_id,academic_year_id,user_id,report_type,entity_id,details,ip_address) VALUES (?,NULLIF(?,0),?,?,?,?,?)');
    if($stmt){$stmt->bind_param('iiisiss',$session_school_id,$year,$user,$reportType,$entity,$details,$ip);$stmt->execute();$stmt->close();}
}

auditInstitutionalReport($type);

function annualEnrollmentSql(int $schoolId,int $yearId):string{
    return "SELECT s.id,s.id_no,s.name,
      COALESCE((SELECT sah.nivel FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$schoolId AND sah.academic_year_id=$yearId ORDER BY sah.id DESC LIMIT 1),s.nivel) nivel,
      COALESCE((SELECT sah.grado FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$schoolId AND sah.academic_year_id=$yearId ORDER BY sah.id DESC LIMIT 1),s.grado) grado,
      COALESCE((SELECT sah.seccion FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$schoolId AND sah.academic_year_id=$yearId ORDER BY sah.id DESC LIMIT 1),s.seccion) seccion,
      COALESCE((SELECT sah.status FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$schoolId AND sah.academic_year_id=$yearId ORDER BY sah.id DESC LIMIT 1),s.status) status
      FROM student s WHERE s.school_id=$schoolId AND (s.academic_year_id=$yearId OR EXISTS(SELECT 1 FROM student_academic_history hx WHERE hx.student_id=s.id AND hx.school_id=$schoolId AND hx.academic_year_id=$yearId))";
}

// Si el formato es Excel, redirigir al generador de Excel
if ($format === 'excel') {
    $query_string = $_SERVER['QUERY_STRING'];
    header("Location: generar_ficha_excel.php?$query_string");
    exit;
}

// Función para generar HTML base
function generateHTMLBase($title, $content) {
    global $conn,$session_school_id;
    $school=['name'=>'Institución educativa','address'=>'','contact_number'=>'','logo_path'=>''];
    $schoolStmt=$conn->prepare('SELECT name,address,contact_number,logo_path FROM schools WHERE id=? LIMIT 1');
    if($schoolStmt){$schoolStmt->bind_param('i',$session_school_id);$schoolStmt->execute();$school=array_merge($school,$schoolStmt->get_result()->fetch_assoc()?:[]);$schoolStmt->close();}
    $logo='';if(!empty($school['logo_path'])){$logoPath=(string)$school['logo_path'];if(file_exists(__DIR__.'/'.$logoPath)||file_exists($logoPath))$logo='<img src="'.htmlspecialchars($logoPath,ENT_QUOTES,'UTF-8').'" alt="Logo">';}
    $institution='<div class="institution">'.$logo.'<div><strong>'.htmlspecialchars($school['name'],ENT_QUOTES,'UTF-8').'</strong><span>'.htmlspecialchars(trim(($school['address']??'').(($school['contact_number']??'')?' · Tel. '.$school['contact_number']:'')),ENT_QUOTES,'UTF-8').'</span></div></div>';
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
            .institution{display:flex;align-items:center;gap:12px;padding-bottom:14px;margin-bottom:18px;border-bottom:2px solid #4e73df}.institution img{width:58px;height:58px;object-fit:contain}.institution strong{display:block;font-size:18px;color:#253858}.institution span{display:block;margin-top:3px;color:#667085;font-size:12px}
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
            ' . $institution . '
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
    case 'data_quality':
        generateDataQualityReport();
        break;
    case 'unassigned_courses':
        generateUnassignedCoursesReport();
        break;
    default:
        die('Tipo de ficha no válido');
}

function generateStudentFicha() {
    global $conn, $session_school_id;
    
    $id = intval($_GET['id']);
    $year_id = intval($_GET['year_id'] ?? 0);
    
    // Obtener información del estudiante
    $sql = "SELECT * FROM student WHERE id = $id" . ($session_school_id ? " AND school_id = $session_school_id" : "");
    $result = $conn->query($sql);
    
    if ($result->num_rows == 0) {
        die('Estudiante no encontrado');
    }
    
    $student = $result->fetch_assoc();
    $year_label = 'Todos los años';
    $enrollment_found=true;$enrollment_source='Registro actual';
    if ($year_id > 0) {
        $year_result = $conn->query("SELECT year FROM academic_year WHERE id=$year_id AND school_id=$session_school_id LIMIT 1");
        if (!$year_result || !$year_result->num_rows) die('Año académico no válido');
        $year_label = $year_result->fetch_assoc()['year'];
        $history_check = $conn->query("SHOW TABLES LIKE 'student_academic_history'");
        if ($history_check && $history_check->num_rows) {
            $history = $conn->query("SELECT nivel,grado,seccion,status FROM student_academic_history WHERE student_id=$id AND school_id=$session_school_id AND academic_year_id=$year_id ORDER BY id DESC LIMIT 1");
            if ($history && $history->num_rows){$student = array_merge($student, $history->fetch_assoc());$enrollment_source='Historial académico';}
            elseif((int)($student['academic_year_id']??0)!==$year_id)$enrollment_found=false;
        }
    }
    
    // Obtener cursos asignados
    $courses_sql = "SELECT c.course, c.level, c.total_amount, 
                           COALESCE(ef.discounted_amount, ef.total_fee) as final_amount,
                           (SELECT COALESCE(SUM(amount), 0) FROM payments p WHERE p.ef_id = ef.id AND COALESCE(p.payment_status,'Confirmado')='Confirmado') as paid_amount
                    FROM student_ef_list ef
                    INNER JOIN student s ON s.id = ef.student_id
                    INNER JOIN courses c ON c.id = ef.course_id
                    WHERE ef.student_id = $id" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . ($year_id ? " AND c.academic_year_id=$year_id" : "");
    $courses_result = $conn->query($courses_sql);

    $academic_history = [];
    $history_table = $conn->query("SHOW TABLES LIKE 'student_academic_history'");
    if ($history_table && $history_table->num_rows) {
        $history_sql = "SELECT sah.nivel,sah.grado,sah.seccion,sah.status,sah.change_type,sah.notes,ay.year
                        FROM student_academic_history sah
                        LEFT JOIN academic_year ay ON ay.id=sah.academic_year_id
                        WHERE sah.student_id=$id AND sah.school_id=$session_school_id" . ($year_id ? " AND sah.academic_year_id=$year_id" : '') . "
                        ORDER BY ay.year DESC,sah.id DESC";
        $history_result = $conn->query($history_sql);
        while ($history_result && ($history_row=$history_result->fetch_assoc())) $academic_history[]=$history_row;
    }

    $attendance_summary = ['total'=>0,'present'=>0,'late'=>0,'absent'=>0,'justified'=>0];
    $attendance_where = $year_id ? " AND a.fecha BETWEEN (SELECT start_date FROM academic_year WHERE id=$year_id) AND (SELECT end_date FROM academic_year WHERE id=$year_id)" : '';
    $attendance_result = $conn->query("SELECT COUNT(*) total,
        SUM(CASE WHEN LOWER(a.estado) IN ('temprano','normal','presente') THEN 1 ELSE 0 END) present,
        SUM(CASE WHEN LOWER(a.estado)='tarde' THEN 1 ELSE 0 END) late,
        SUM(CASE WHEN LOWER(a.estado)='ausente' THEN 1 ELSE 0 END) absent,
        SUM(CASE WHEN LOWER(a.estado) LIKE '%justific%' THEN 1 ELSE 0 END) justified
        FROM asistencia a INNER JOIN student s ON s.id=a.student_id
        WHERE a.student_id=$id AND s.school_id=$session_school_id$attendance_where");
    if ($attendance_result) $attendance_summary=array_merge($attendance_summary,$attendance_result->fetch_assoc()?:[]);

    $grade_summary = ['evaluations'=>0,'numeric_average'=>null,'letter_grades'=>0];
    $grade_year = $year_id ? " AND e.academic_year_id=$year_id" : '';
    $grade_result = $conn->query("SELECT COUNT(DISTINCT eg.evaluation_id) evaluations,
        AVG(CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(6,2)) END) numeric_average,
        SUM(CASE WHEN eg.grade IN ('C','B','A','AD') THEN 1 ELSE 0 END) letter_grades
        FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id
        INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
        WHERE eg.student_id=$id AND tc.school_id=$session_school_id$grade_year");
    if ($grade_result) $grade_summary=array_merge($grade_summary,$grade_result->fetch_assoc()?:[]);
    
    $content = '
    <div class="header">
        <h1>FICHA INDIVIDUAL DE ESTUDIANTE</h1>
        <p>Información Personal y Académica · Año: ' . htmlspecialchars($year_label) . '</p>
    </div>
    '.(!$enrollment_found?'<div style="padding:12px 14px;margin-bottom:18px;border:1px solid #f5c2c7;background:#fff5f5;color:#842029;border-radius:6px"><strong>Sin matrícula registrada:</strong> el estudiante pertenece al padrón institucional, pero no registra matrícula en el año seleccionado.</div>':'<div style="text-align:right;color:#667085;font-size:11px;margin:-18px 0 14px">Fuente académica: '.htmlspecialchars($enrollment_source).'</div>').'
    
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

    $content .= '<div class="section"><h3>Resumen académico y asistencia</h3><div class="info-grid">
        <div class="info-item"><span class="info-label">Evaluaciones:</span><span class="info-value">'.(int)$grade_summary['evaluations'].'</span></div>
        <div class="info-item"><span class="info-label">Promedio numérico:</span><span class="info-value">'.($grade_summary['numeric_average']!==null?number_format((float)$grade_summary['numeric_average'],2):'Sin notas numéricas').'</span></div>
        <div class="info-item"><span class="info-label">Registros de asistencia:</span><span class="info-value">'.(int)$attendance_summary['total'].'</span></div>
        <div class="info-item"><span class="info-label">Presentes / tardanzas:</span><span class="info-value">'.(int)$attendance_summary['present'].' / '.(int)$attendance_summary['late'].'</span></div>
        <div class="info-item"><span class="info-label">Ausencias:</span><span class="info-value">'.(int)$attendance_summary['absent'].'</span></div>
        <div class="info-item"><span class="info-label">Justificadas:</span><span class="info-value">'.(int)$attendance_summary['justified'].'</span></div>
    </div></div>';

    if ($academic_history) {
        $content .= '<div class="section"><h3>Historial académico</h3><table class="table"><thead><tr><th>Año</th><th>Nivel</th><th>Grado</th><th>Sección</th><th>Estado</th><th>Cambio</th></tr></thead><tbody>';
        foreach ($academic_history as $item) $content .= '<tr><td>'.htmlspecialchars($item['year']?:'Sin año').'</td><td>'.htmlspecialchars($item['nivel']).'</td><td>'.htmlspecialchars($item['grado']).'</td><td>'.htmlspecialchars($item['seccion']).'</td><td>'.htmlspecialchars($item['status']).'</td><td>'.htmlspecialchars($item['change_type']).'</td></tr>';
        $content .= '</tbody></table></div>';
    }
    
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
            $balance = max(0, (float)$course['final_amount'] - (float)$course['paid_amount']);
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
        
        $total_balance = max(0, $total_amount - $total_paid);
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
    $year_id = intval($_GET['year_id'] ?? 0);
    
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
                    WHERE tc.teacher_id = $id" . ($session_school_id ? " AND tc.school_id = $session_school_id" : "") . ($year_id ? " AND tc.academic_year_id=$year_id" : "") . "
                    ORDER BY ay.year DESC, tc.level, ac.name, tc.grado, tc.seccion";
    $courses_result = $conn->query($courses_sql);
    
    // Obtener información de usuario del sistema
    $user_sql = "SELECT username, type FROM users WHERE teacher_id = $id" . ($session_school_id ? " AND school_id = $session_school_id" : "");
    $user_result = $conn->query($user_sql);
    $user = $user_result->num_rows > 0 ? $user_result->fetch_assoc() : null;
    $employment_history=[];$employment_table=$conn->query("SHOW TABLES LIKE 'teacher_employment_history'");
    if($employment_table&&$employment_table->num_rows){
        $employment_sql="SELECT teh.start_date,teh.end_date,teh.status,teh.departure_reason,teh.notes,ay.year
                         FROM teacher_employment_history teh LEFT JOIN academic_year ay ON ay.id=teh.academic_year_id
                         WHERE teh.teacher_id=$id AND teh.school_id=$session_school_id".($year_id?" AND teh.academic_year_id=$year_id":'')."
                         ORDER BY teh.start_date DESC,teh.id DESC";
        $employment_result=$conn->query($employment_sql);while($employment_result&&($employment=$employment_result->fetch_assoc()))$employment_history[]=$employment;
    }
    
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

    if($employment_history){
        $content.='<div class="section"><h3>Historial laboral</h3><table class="table"><thead><tr><th>Año</th><th>Inicio</th><th>Fin</th><th>Estado</th><th>Motivo</th></tr></thead><tbody>';
        foreach($employment_history as $employment)$content.='<tr><td>'.htmlspecialchars($employment['year']?:'Sin año').'</td><td>'.htmlspecialchars($employment['start_date']?:'—').'</td><td>'.htmlspecialchars($employment['end_date']?:'Vigente').'</td><td>'.htmlspecialchars($employment['status']).'</td><td>'.htmlspecialchars($employment['departure_reason']?:'—').'</td></tr>';
        $content.='</tbody></table></div>';
    }
    
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
    $enrollment_status=trim($_GET['enrollment_status']??'Activo');
    $source="SELECT s.id,s.id_no,s.name,s.email,s.contact,s.nivel,s.grado,s.seccion,s.status FROM student s WHERE s.school_id=$session_school_id";
    $where=' WHERE 1=1';if($nivel)$where.=" AND enrolled.nivel='".$conn->real_escape_string($nivel)."'";if($grado)$where.=" AND enrolled.grado='".$conn->real_escape_string($grado)."'";if($seccion)$where.=" AND enrolled.seccion='".$conn->real_escape_string($seccion)."'";if(in_array($enrollment_status,['Activo','Retirado','Egresado'],true))$where.=" AND enrolled.status='".$conn->real_escape_string($enrollment_status)."'";
    $sql = "SELECT * FROM ($source) enrolled$where ORDER BY name";
    $result = $conn->query($sql);
    
    $title = "RELACIÓN DE ESTUDIANTES";
    if ($nivel) $title .= " - " . $nivel;
    if ($grado) $title .= " " . $grado;
    if ($seccion) $title .= " Sección " . $seccion;
    
    $content = '
    <div class="header">
        <h1>' . $title . '</h1>
        <p>' . ($enrollment_status === 'Activo' ? 'Matriculados actuales (estado Activo)' : ($enrollment_status === '' ? 'Padrón institucional completo' : 'Estudiantes con estado ' . htmlspecialchars($enrollment_status))) . '</p>
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
                    <th>Estado</th>
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
                    <td>' . htmlspecialchars($student['status'] ?? 'Sin estado') . '</td>
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
    
    $year_id = intval($_GET['year_id'] ?? 0);
        $sql = "SELECT t.*, 
                 (SELECT COUNT(*) FROM teacher_courses tc WHERE tc.teacher_id = t.id" . ($session_school_id ? " AND tc.school_id = $session_school_id" : "") . ($year_id ? " AND tc.academic_year_id=$year_id" : "") . ") as course_count,
                   u.username
            FROM teacher t
             LEFT JOIN users u ON u.teacher_id = t.id" . ($session_school_id ? " AND u.school_id = $session_school_id" : "") . "
             " . ($session_school_id ? "WHERE t.school_id = $session_school_id" : "") . ($year_id ? " AND EXISTS(SELECT 1 FROM teacher_courses ty WHERE ty.teacher_id=t.id AND ty.school_id=$session_school_id AND ty.academic_year_id=$year_id)" : "") . "
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
                      (SELECT COUNT(*) FROM (".annualEnrollmentSql($session_school_id,$year_id).") annual_students) as total_students,
                      (SELECT COUNT(DISTINCT teacher_id) FROM teacher_courses WHERE school_id=$session_school_id AND academic_year_id=$year_id) as total_teachers,
                      (SELECT COUNT(*) FROM courses WHERE academic_year_id = $year_id) as total_courses,
                      (SELECT COUNT(*) FROM student_ef_list ef 
                       INNER JOIN courses c ON c.id = ef.course_id
                       INNER JOIN student s ON s.id = ef.student_id
                       WHERE c.academic_year_id = $year_id" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . ") as total_fees,
                      (SELECT COALESCE(SUM(amount), 0) FROM payments p 
                       INNER JOIN student_ef_list ef ON ef.id = p.ef_id
                       INNER JOIN courses c ON c.id = ef.course_id
                       INNER JOIN student s ON s.id = ef.student_id
                       WHERE c.academic_year_id = $year_id AND COALESCE(p.payment_status,'Confirmado')='Confirmado'" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . ") as total_collected";
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

    // En este sistema, un estudiante activo es un estudiante matriculado.
    $enrollment_sql = "SELECT nivel,grado,seccion,COUNT(*) total_students FROM student WHERE school_id=$session_school_id AND status='Activo' GROUP BY nivel,grado,seccion ORDER BY nivel,grado,seccion";
    $enrollment_result = $conn->query($enrollment_sql);
    
    $content = '
    <div class="header">
        <h1>MATRÍCULA ACTUAL POR NIVEL</h1>
        <p>Estudiantes con estado Activo</p>
    </div>
    
    <div class="section">
        <h3>📈 Distribución de Estudiantes</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Nivel</th>
                    <th>Grado</th>
                    <th>Sección</th><th>Total Estudiantes</th>
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
                    <td>' . htmlspecialchars($row['seccion']?:'-') . '</td><td>' . $row['total_students'] . '</td>
                </tr>';
    }
    
    $content .= '
            </tbody>
            <tfoot>
                <tr style="font-weight: bold; background: #e9ecef;">
                    <td colspan="3">TOTAL GENERAL</td>
                    <td>' . $total_general . '</td>
                </tr>
            </tfoot>
        </table>
    </div>';
    
    echo generateHTMLBase('Matrícula actual por nivel', $content);
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
                          COALESCE(SUM((SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.ef_id=ef.id AND COALESCE(p.payment_status,'Confirmado')='Confirmado')), 0) as total_paid
                      FROM courses c
                      LEFT JOIN student_ef_list ef ON ef.course_id = c.id
                      LEFT JOIN student s ON s.id = ef.student_id
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
        $pending = max(0, (float)$row['total_amount'] - (float)$row['total_paid']);
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
    
    $total_pending = max(0, $total_amount - $total_paid);
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

function generateDataQualityReport(){
    global $conn,$session_school_id;$year_id=intval($_GET['year_id']??0);
    $where="s.school_id=$session_school_id".($year_id?" AND (s.academic_year_id=$year_id OR EXISTS(SELECT 1 FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$session_school_id AND sah.academic_year_id=$year_id))":'');
    $result=$conn->query("SELECT s.id_no,s.name,s.nivel,s.grado,s.seccion,s.contact,s.email,s.tutor1_nombre,s.tutor1_telefono FROM student s WHERE $where AND (COALESCE(s.contact,'')='' OR COALESCE(s.email,'')='' OR COALESCE(s.seccion,'')='' OR COALESCE(s.tutor1_nombre,'')='' OR COALESCE(s.tutor1_telefono,'')='') ORDER BY s.name");
    $content='<div class="header"><h1>CONTROL DE DATOS INCOMPLETOS</h1><p>Registros que requieren revisión administrativa</p></div><div class="section"><table class="table"><thead><tr><th>DNI</th><th>Estudiante</th><th>Aula</th><th>Datos pendientes</th></tr></thead><tbody>';$count=0;
    while($result&&($row=$result->fetch_assoc())){$missing=[];if(!$row['contact'])$missing[]='Teléfono';if(!$row['email'])$missing[]='Correo';if(!$row['seccion'])$missing[]='Sección';if(!$row['tutor1_nombre'])$missing[]='Apoderado';if(!$row['tutor1_telefono'])$missing[]='Teléfono del apoderado';$content.='<tr><td>'.htmlspecialchars($row['id_no']).'</td><td>'.htmlspecialchars($row['name']).'</td><td>'.htmlspecialchars($row['nivel'].' · '.$row['grado'].' '.($row['seccion']?:'Sin sección')).'</td><td>'.htmlspecialchars(implode(', ',$missing)).'</td></tr>';$count++;}
    if(!$count)$content.='<tr><td colspan="4" style="text-align:center">No se encontraron datos incompletos.</td></tr>';$content.='</tbody></table><p><strong>Registros por revisar: '.$count.'</strong></p></div>';echo generateHTMLBase('Control de datos incompletos',$content);
}

function generateUnassignedCoursesReport(){
    global $conn,$session_school_id;$year_id=intval($_GET['year_id']??0);if(!$year_id)die('Seleccione un año académico.');
    $year=$conn->query("SELECT year FROM academic_year WHERE id=$year_id AND school_id=$session_school_id LIMIT 1");if(!$year||!$year->num_rows)die('Año académico no válido.');$year_label=$year->fetch_assoc()['year'];
    $result=$conn->query("SELECT ac.course_code,ac.name,ac.level,ac.grades,ac.weekly_hours,ac.course_status FROM academic_courses ac WHERE ac.school_id=$session_school_id AND ac.academic_year_id=$year_id AND COALESCE(ac.course_status,'Activo')='Activo' AND NOT EXISTS(SELECT 1 FROM teacher_courses tc WHERE tc.course_id=ac.id AND tc.school_id=$session_school_id AND tc.academic_year_id=$year_id) ORDER BY ac.level,ac.display_order,ac.name");
    $content='<div class="header"><h1>CURSOS SIN DOCENTE ASIGNADO</h1><p>Año académico: '.htmlspecialchars($year_label).'</p></div><div class="section"><table class="table"><thead><tr><th>Código</th><th>Curso</th><th>Nivel</th><th>Grados</th><th>Horas semanales</th></tr></thead><tbody>';$count=0;
    while($result&&($row=$result->fetch_assoc())){$content.='<tr><td>'.htmlspecialchars($row['course_code']?:'—').'</td><td>'.htmlspecialchars($row['name']).'</td><td>'.htmlspecialchars($row['level']).'</td><td>'.htmlspecialchars($row['grades']?:'Todos').'</td><td>'.number_format((float)$row['weekly_hours'],1).'</td></tr>';$count++;}if(!$count)$content.='<tr><td colspan="5" style="text-align:center">Todos los cursos activos tienen asignación.</td></tr>';$content.='</tbody></table><p><strong>Cursos pendientes: '.$count.'</strong></p></div>';echo generateHTMLBase('Cursos sin docente '.$year_label,$content);
}
?>
