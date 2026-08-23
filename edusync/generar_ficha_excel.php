<?php
include('db_connect.php');
include_once 'includes/session_check.php';
require_login_modal();
require_once 'vendor/autoload.php'; // Para PHPSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$session_school_id = intval($_SESSION['login_school_id'] ?? 0);

// Verificar si PHPSpreadsheet está disponible
if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    // Fallback: generar CSV si no está PHPSpreadsheet
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="reporte.csv"');
    
    $type = $_GET['type'] ?? '';
    generateCSV($type);
    exit;
}

$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';
$nivel = $_GET['nivel'] ?? '';
$grado = $_GET['grado'] ?? '';
$seccion = $_GET['seccion'] ?? '';
$year_id = $_GET['year_id'] ?? '';

switch ($type) {
    case 'student':
        generateStudentExcel($id);
        break;
    case 'teacher':
        generateTeacherExcel($id);
        break;
    case 'student_list':
        generateStudentListExcel($nivel, $grado, $seccion);
        break;
    case 'teacher_list':
        generateTeacherListExcel();
        break;
    case 'year_stats':
        generateYearStatsExcel($year_id);
        break;
    case 'enrollment_by_level':
        generateEnrollmentByLevelExcel($year_id);
        break;
    case 'financial_report':
        generateFinancialReportExcel($year_id);
        break;
    default:
        echo "Tipo de reporte no válido";
        exit;
}

function generateStudentExcel($student_id) {
    global $conn, $session_school_id;
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Obtener datos del estudiante
    $query = $conn->query("SELECT *, 
                          tutor1_nombre, tutor1_apellido, tutor1_dni, tutor1_telefono, tutor1_direccion, tutor1_relacion,
                          tutor2_nombre, tutor2_apellido, tutor2_dni, tutor2_telefono, tutor2_direccion, tutor2_relacion
                          FROM student WHERE id = $student_id" . ($session_school_id ? " AND school_id = $session_school_id" : ""));
    $student = $query->fetch_assoc();
    
    if (!$student) {
        echo "Estudiante no encontrado";
        exit;
    }
    
    // Configurar título
    $sheet->setCellValue('A1', 'FICHA DE ESTUDIANTE');
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Datos personales
    $row = 3;
    $sheet->setCellValue('A' . $row, 'DATOS PERSONALES');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Código:');
    $sheet->setCellValue('B' . $row, $student['id_no']);
    $sheet->setCellValue('D' . $row, 'Nombre:');
    $sheet->setCellValue('E' . $row, $student['name']);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Email:');
    $sheet->setCellValue('B' . $row, $student['email']);
    $sheet->setCellValue('D' . $row, 'Teléfono:');
    $sheet->setCellValue('E' . $row, $student['contact']);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Dirección:');
    $sheet->setCellValue('B' . $row, $student['address']);
    $row++;
    
    // Información académica
    $row++;
    $sheet->setCellValue('A' . $row, 'INFORMACIÓN ACADÉMICA');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Nivel:');
    $sheet->setCellValue('B' . $row, $student['nivel']);
    $sheet->setCellValue('D' . $row, 'Grado:');
    $sheet->setCellValue('E' . $row, $student['grado']);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Sección:');
    $sheet->setCellValue('B' . $row, $student['seccion']);
    $sheet->setCellValue('D' . $row, 'Estado:');
    $sheet->setCellValue('E' . $row, $student['status']);
    $row++;
    
    // Información del apoderado/tutor
    $row++;
    $sheet->setCellValue('A' . $row, 'INFORMACIÓN DEL APODERADO/TUTOR');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    
    // Apoderado Principal
    if (!empty($student['tutor1_nombre'])) {
        $sheet->setCellValue('A' . $row, 'Apoderado Principal');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->getColor()->setRGB('1976D2');
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Nombre:');
        $sheet->setCellValue('B' . $row, $student['tutor1_nombre'] . ' ' . $student['tutor1_apellido']);
        $sheet->setCellValue('D' . $row, 'DNI:');
        $sheet->setCellValue('E' . $row, $student['tutor1_dni'] ?: 'No registrado');
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Teléfono:');
        $sheet->setCellValue('B' . $row, $student['tutor1_telefono'] ?: 'No registrado');
        $sheet->setCellValue('D' . $row, 'Relación:');
        $sheet->setCellValue('E' . $row, $student['tutor1_relacion'] ?: 'No especificada');
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Dirección:');
        $sheet->setCellValue('B' . $row, $student['tutor1_direccion'] ?: 'No registrada');
        $row++;
    }
    
    // Apoderado Secundario
    if (!empty($student['tutor2_nombre'])) {
        $row++;
        $sheet->setCellValue('A' . $row, 'Apoderado Secundario');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->getColor()->setRGB('1976D2');
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Nombre:');
        $sheet->setCellValue('B' . $row, $student['tutor2_nombre'] . ' ' . $student['tutor2_apellido']);
        $sheet->setCellValue('D' . $row, 'DNI:');
        $sheet->setCellValue('E' . $row, $student['tutor2_dni'] ?: 'No registrado');
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Teléfono:');
        $sheet->setCellValue('B' . $row, $student['tutor2_telefono'] ?: 'No registrado');
        $sheet->setCellValue('D' . $row, 'Relación:');
        $sheet->setCellValue('E' . $row, $student['tutor2_relacion'] ?: 'No especificada');
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Dirección:');
        $sheet->setCellValue('B' . $row, $student['tutor2_direccion'] ?: 'No registrada');
        $row++;
    }
    
    // Si no hay tutores registrados
    if (empty($student['tutor1_nombre']) && empty($student['tutor2_nombre'])) {
        $sheet->setCellValue('A' . $row, 'No hay información de apoderados registrada');
        $sheet->getStyle('A' . $row)->getFont()->setItalic(true)->getColor()->setRGB('999999');
        $row++;
    }
    
    $row++;
    
    // Ajustar anchos de columna
    $sheet->getColumnDimension('A')->setWidth(15);
    $sheet->getColumnDimension('B')->setWidth(20);
    $sheet->getColumnDimension('C')->setWidth(5);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(20);
    $sheet->getColumnDimension('F')->setWidth(15);
    
    // Generar archivo
    $writer = new Xlsx($spreadsheet);
    $filename = 'ficha_estudiante_' . $student['id_no'] . '_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}

function generateTeacherExcel($teacher_id) {
    global $conn, $session_school_id;
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Obtener datos del docente
    $query = $conn->query("SELECT * FROM teacher WHERE id = $teacher_id" . ($session_school_id ? " AND school_id = $session_school_id" : ""));
    $teacher = $query->fetch_assoc();
    
    if (!$teacher) {
        echo "Docente no encontrado";
        exit;
    }
    
    // Configurar título
    $sheet->setCellValue('A1', 'FICHA DE DOCENTE');
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Datos personales
    $row = 3;
    $sheet->setCellValue('A' . $row, 'DATOS PERSONALES');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Código:');
    $sheet->setCellValue('B' . $row, $teacher['id_no']);
    $sheet->setCellValue('D' . $row, 'Nombre:');
    $sheet->setCellValue('E' . $row, $teacher['name']);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Email:');
    $sheet->setCellValue('B' . $row, $teacher['email']);
    $sheet->setCellValue('D' . $row, 'Teléfono:');
    $sheet->setCellValue('E' . $row, $teacher['contact']);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Dirección:');
    $sheet->setCellValue('B' . $row, $teacher['address']);
    $row++;
    
    // Obtener cursos asignados
    $courses_query = $conn->query("SELECT ac.name as course, tc.level, tc.grado, tc.seccion, ay.year
                                   FROM teacher_courses tc
                                   INNER JOIN academic_courses ac ON ac.id = tc.course_id
                                   LEFT JOIN academic_year ay ON tc.academic_year_id = ay.id
                                   WHERE tc.teacher_id = $teacher_id" . ($session_school_id ? " AND tc.school_id = $session_school_id" : "") . "
                                   ORDER BY ay.year DESC, tc.level, ac.name, tc.grado, tc.seccion");
    
    // Información de acceso al sistema
    $user_query = $conn->query("SELECT username, type FROM users WHERE teacher_id = $teacher_id" . ($session_school_id ? " AND school_id = $session_school_id" : ""));
    $user = $user_query->num_rows > 0 ? $user_query->fetch_assoc() : null;
    
    if ($user) {
        $row++;
        $sheet->setCellValue('A' . $row, 'ACCESO AL SISTEMA');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        
        $sheet->setCellValue('A' . $row, 'Usuario:');
        $sheet->setCellValue('B' . $row, $user['username']);
        $sheet->setCellValue('D' . $row, 'Tipo:');
        $sheet->setCellValue('E' . $row, $user['type'] == 1 ? 'Administrador' : 'Docente');
        $row++;
    }
    
    // Cursos asignados
    if ($courses_query->num_rows > 0) {
        $row++;
        $sheet->setCellValue('A' . $row, 'CURSOS ASIGNADOS');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        
        // Encabezados
        $sheet->setCellValue('A' . $row, 'Curso');
        $sheet->setCellValue('B' . $row, 'Nivel');
        $sheet->setCellValue('C' . $row, 'Grado');
        $sheet->setCellValue('D' . $row, 'Sección');
        $sheet->setCellValue('E' . $row, 'Año Académico');
        $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);
        $row++;
        
        while ($course = $courses_query->fetch_assoc()) {
            $sheet->setCellValue('A' . $row, $course['course']);
            $sheet->setCellValue('B' . $row, $course['level']);
            $sheet->setCellValue('C' . $row, $course['grado']);
            $sheet->setCellValue('D' . $row, $course['seccion']);
            $sheet->setCellValue('E' . $row, $course['year'] ?: 'N/A');
            $row++;
        }
    }
    
    $row++;
    
    // Ajustar anchos de columna
    $sheet->getColumnDimension('A')->setWidth(15);
    $sheet->getColumnDimension('B')->setWidth(20);
    $sheet->getColumnDimension('C')->setWidth(5);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(20);
    $sheet->getColumnDimension('F')->setWidth(15);
    
    // Generar archivo
    $writer = new Xlsx($spreadsheet);
    $filename = 'ficha_docente_' . $teacher['id_no'] . '_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}

function generateStudentListExcel($nivel, $grado, $seccion = '') {
    global $conn, $session_school_id;
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Construir consulta
    $where_clause = "WHERE (status = 'Activo' OR status IS NULL)" . ($session_school_id ? " AND school_id = $session_school_id" : "") . " AND nivel = '" . $conn->real_escape_string($nivel) . "' AND grado = '" . $conn->real_escape_string($grado) . "'";
    if ($seccion) {
        $where_clause .= " AND seccion = '" . $conn->real_escape_string($seccion) . "'";
    }
    
    $query = $conn->query("SELECT id_no, name, email, contact, nivel, grado, seccion, status FROM student $where_clause ORDER BY name");
    
    // Configurar título
    $title = "RELACIÓN DE ESTUDIANTES - $nivel - $grado";
    if ($seccion) {
        $title .= " - $seccion";
    }
    
    $sheet->setCellValue('A1', $title);
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Encabezados
    $row = 3;
    $headers = ['Código', 'Nombre', 'Email', 'Teléfono', 'Nivel', 'Grado', 'Sección', 'Estado'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $col++;
    }
    
    // Datos
    $row++;
    while ($student = $query->fetch_assoc()) {
        $sheet->setCellValue('A' . $row, $student['id_no']);
        $sheet->setCellValue('B' . $row, $student['name']);
        $sheet->setCellValue('C' . $row, $student['email']);
        $sheet->setCellValue('D' . $row, $student['contact']);
        $sheet->setCellValue('E' . $row, $student['nivel']);
        $sheet->setCellValue('F' . $row, $student['grado']);
        $sheet->setCellValue('G' . $row, $student['seccion']);
        $sheet->setCellValue('H' . $row, $student['status']);
        $row++;
    }
    
    // Ajustar anchos de columna
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Generar archivo
    $writer = new Xlsx($spreadsheet);
    $filename = 'relacion_estudiantes_' . $nivel . '_' . $grado . '_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}

function generateTeacherListExcel() {
    global $conn, $session_school_id;
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    $query = $conn->query("SELECT id_no, name, email, contact, address FROM teacher" . ($session_school_id ? " WHERE school_id = $session_school_id" : "") . " ORDER BY name");
    
    // Configurar título
    $sheet->setCellValue('A1', 'RELACIÓN COMPLETA DE DOCENTES');
    $sheet->mergeCells('A1:E1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Encabezados
    $row = 3;
    $headers = ['Código', 'Nombre', 'Email', 'Teléfono', 'Dirección'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $col++;
    }
    
    // Datos
    $row++;
    while ($teacher = $query->fetch_assoc()) {
        $sheet->setCellValue('A' . $row, $teacher['id_no']);
        $sheet->setCellValue('B' . $row, $teacher['name']);
        $sheet->setCellValue('C' . $row, $teacher['email']);
        $sheet->setCellValue('D' . $row, $teacher['contact']);
        $sheet->setCellValue('E' . $row, $teacher['address']);
        $row++;
    }
    
    // Ajustar anchos de columna
    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Generar archivo
    $writer = new Xlsx($spreadsheet);
    $filename = 'relacion_docentes_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}

function generateYearStatsExcel($year_id) {
    global $conn, $session_school_id;
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Obtener año académico
    $year_query = $conn->query("SELECT year FROM academic_year WHERE id = $year_id" . ($session_school_id ? " AND school_id = $session_school_id" : ""));
    $year_data = $year_query->fetch_assoc();
    
    // Configurar título
    $sheet->setCellValue('A1', 'ESTADÍSTICAS GENERALES - AÑO ' . $year_data['year']);
    $sheet->mergeCells('A1:C1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $row = 3;
    
    // Total estudiantes
    $students_query = $conn->query("SELECT COUNT(*) as total FROM student WHERE (status = 'Activo' OR status IS NULL)" . ($session_school_id ? " AND school_id = $session_school_id" : ""));
    $students_total = $students_query->fetch_assoc()['total'];
    
    $sheet->setCellValue('A' . $row, 'Total de Estudiantes:');
    $sheet->setCellValue('B' . $row, $students_total);
    $row++;
    
    // Total docentes
    $teachers_query = $conn->query("SELECT COUNT(*) as total FROM teacher" . ($session_school_id ? " WHERE school_id = $session_school_id" : ""));
    $teachers_total = $teachers_query->fetch_assoc()['total'];
    
    $sheet->setCellValue('A' . $row, 'Total de Docentes:');
    $sheet->setCellValue('B' . $row, $teachers_total);
    $row++;
    
    // Estudiantes por nivel
    $row++;
    $sheet->setCellValue('A' . $row, 'ESTUDIANTES POR NIVEL');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    
    $niveles_query = $conn->query("SELECT nivel, COUNT(*) as total FROM student WHERE (status = 'Activo' OR status IS NULL)" . ($session_school_id ? " AND school_id = $session_school_id" : "") . " GROUP BY nivel ORDER BY nivel");
    while ($nivel = $niveles_query->fetch_assoc()) {
        $sheet->setCellValue('A' . $row, $nivel['nivel'] . ':');
        $sheet->setCellValue('B' . $row, $nivel['total']);
        $row++;
    }
    
    // Ajustar anchos de columna
    $sheet->getColumnDimension('A')->setWidth(25);
    $sheet->getColumnDimension('B')->setWidth(15);
    $sheet->getColumnDimension('C')->setWidth(15);
    
    // Generar archivo
    $writer = new Xlsx($spreadsheet);
    $filename = 'estadisticas_' . $year_data['year'] . '_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}

function generateEnrollmentByLevelExcel($year_id) {
    global $conn, $session_school_id;
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Obtener año académico
    $year_query = $conn->query("SELECT year FROM academic_year WHERE id = $year_id" . ($session_school_id ? " AND school_id = $session_school_id" : ""));
    $year_data = $year_query->fetch_assoc();
    
    // Configurar título
    $sheet->setCellValue('A1', 'MATRÍCULA POR NIVEL - AÑO ' . $year_data['year']);
    $sheet->mergeCells('A1:D1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Encabezados
    $row = 3;
    $headers = ['Nivel', 'Grado', 'Sección', 'Total Estudiantes'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $col++;
    }
    
    // Datos
    $row++;
    $query = $conn->query("SELECT nivel, grado, seccion, COUNT(*) as total FROM student WHERE (status = 'Activo' OR status IS NULL)" . ($session_school_id ? " AND school_id = $session_school_id" : "") . " GROUP BY nivel, grado, seccion ORDER BY nivel, grado, seccion");
    
    while ($data = $query->fetch_assoc()) {
        $sheet->setCellValue('A' . $row, $data['nivel']);
        $sheet->setCellValue('B' . $row, $data['grado']);
        $sheet->setCellValue('C' . $row, $data['seccion']);
        $sheet->setCellValue('D' . $row, $data['total']);
        $row++;
    }
    
    // Ajustar anchos de columna
    foreach (range('A', 'D') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Generar archivo
    $writer = new Xlsx($spreadsheet);
    $filename = 'matricula_por_nivel_' . $year_data['year'] . '_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}

function generateFinancialReportExcel($year_id) {
    global $conn, $session_school_id;
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Obtener información del año académico
    $year_query = $conn->query("SELECT * FROM academic_year WHERE id = $year_id" . ($session_school_id ? " AND school_id = $session_school_id" : ""));
    if ($year_query->num_rows == 0) {
        echo "Año académico no encontrado";
        exit;
    }
    $year = $year_query->fetch_assoc();
    
    // Configurar título
    $sheet->setCellValue('A1', 'REPORTE FINANCIERO');
    $sheet->mergeCells('A1:G1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A2', 'Año Académico: ' . $year['year']);
    $sheet->mergeCells('A2:G2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $row = 4;
    
    // Obtener datos del reporte financiero
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
    
    if ($financial_result->num_rows > 0) {
        // Encabezados de la tabla
        $sheet->setCellValue('A' . $row, 'Curso');
        $sheet->setCellValue('B' . $row, 'Nivel');
        $sheet->setCellValue('C' . $row, 'Grados');
        $sheet->setCellValue('D' . $row, 'Asignaciones');
        $sheet->setCellValue('E' . $row, 'Monto Total');
        $sheet->setCellValue('F' . $row, 'Pagado');
        $sheet->setCellValue('G' . $row, 'Pendiente');
        
        // Aplicar formato a los encabezados
        $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':G' . $row)->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setRGB('E9ECEF');
        $row++;
        
        $total_amount = 0;
        $total_paid = 0;
        
        while ($data = $financial_result->fetch_assoc()) {
            $pending = $data['total_amount'] - $data['total_paid'];
            $total_amount += $data['total_amount'];
            $total_paid += $data['total_paid'];
            
            $sheet->setCellValue('A' . $row, $data['course']);
            $sheet->setCellValue('B' . $row, $data['level']);
            $sheet->setCellValue('C' . $row, $data['grades'] ?: 'Todos');
            $sheet->setCellValue('D' . $row, $data['total_assignments']);
            $sheet->setCellValue('E' . $row, '$' . number_format($data['total_amount'], 2));
            $sheet->setCellValue('F' . $row, '$' . number_format($data['total_paid'], 2));
            $sheet->setCellValue('G' . $row, '$' . number_format($pending, 2));
            $row++;
        }
        
        // Fila de totales
        $total_pending = $total_amount - $total_paid;
        $sheet->setCellValue('A' . $row, 'TOTALES');
        $sheet->mergeCells('A' . $row . ':D' . $row);
        $sheet->setCellValue('E' . $row, '$' . number_format($total_amount, 2));
        $sheet->setCellValue('F' . $row, '$' . number_format($total_paid, 2));
        $sheet->setCellValue('G' . $row, '$' . number_format($total_pending, 2));
        
        // Aplicar formato a la fila de totales
        $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':G' . $row)->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setRGB('E9ECEF');
    } else {
        $sheet->setCellValue('A' . $row, 'No hay datos financieros disponibles para este año académico');
    }
    
    // Ajustar anchos de columna
    $sheet->getColumnDimension('A')->setWidth(25);
    $sheet->getColumnDimension('B')->setWidth(15);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(12);
    $sheet->getColumnDimension('E')->setWidth(15);
    $sheet->getColumnDimension('F')->setWidth(15);
    $sheet->getColumnDimension('G')->setWidth(15);
    
    // Generar archivo
    $writer = new Xlsx($spreadsheet);
    $filename = 'reporte_financiero_' . $year['year'] . '_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}

// Función fallback para generar CSV
function generateCSV($type) {
    global $conn, $session_school_id;
    
    $output = fopen('php://output', 'w');
    
    switch ($type) {
        case 'student_list':
            $nivel = $_GET['nivel'] ?? '';
            $grado = $_GET['grado'] ?? '';
            $seccion = $_GET['seccion'] ?? '';
            
            fputcsv($output, ['Código', 'Nombre', 'Email', 'Teléfono', 'Nivel', 'Grado', 'Sección', 'Estado']);
            
            $where_clause = "WHERE (status = 'Activo' OR status IS NULL)" . ($session_school_id ? " AND school_id = $session_school_id" : "") . " AND nivel = '" . $conn->real_escape_string($nivel) . "' AND grado = '" . $conn->real_escape_string($grado) . "'";
            if ($seccion) {
                $where_clause .= " AND seccion = '" . $conn->real_escape_string($seccion) . "'";
            }
            
            $query = $conn->query("SELECT id_no, name, email, contact, nivel, grado, seccion, status FROM student $where_clause ORDER BY name");
            while ($row = $query->fetch_assoc()) {
                fputcsv($output, $row);
            }
            break;
            
        case 'teacher_list':
            fputcsv($output, ['Código', 'Nombre', 'Email', 'Teléfono', 'Dirección']);
            
            $query = $conn->query("SELECT id_no, name, email, contact, address FROM teacher" . ($session_school_id ? " WHERE school_id = $session_school_id" : "") . " ORDER BY name");
            while ($row = $query->fetch_assoc()) {
                fputcsv($output, $row);
            }
            break;
    }
    
    fclose($output);
}
?>
