<?php
// Exportador inteligente del Reporte de notas.
// - Curso seleccionado: conserva el exportador oficial existente.
// - Curso = Todos: genera un único .xlsx con RESUMEN + una hoja por curso.

date_default_timezone_set('America/Lima');

if (session_status() === PHP_SESSION_NONE) {
    $session_save_path = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($session_save_path)) {
        @mkdir($session_save_path, 0755, true);
    }
    ini_set('session.save_path', $session_save_path);
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/db_connect.php';

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
$sessionType = (int)($_SESSION['login_type'] ?? 0);
$teacherId = (int)($_SESSION['login_teacher_id'] ?? 0);

if ($schoolId <= 0 || $userId <= 0 || $sessionType <= 0) {
    http_response_code(401);
    exit('Sesión no válida. Inicie sesión nuevamente.');
}

// Validar el rol contra la base de datos para no confiar solo en la sesión.
$roleStmt = $conn->prepare('SELECT type, is_director, teacher_id FROM users WHERE id=? AND school_id=? LIMIT 1');
if (!$roleStmt) {
    http_response_code(500);
    exit('No se pudo validar el acceso al reporte.');
}
$roleStmt->bind_param('ii', $userId, $schoolId);
$roleStmt->execute();
$role = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();

if (!$role || !in_array((int)$role['type'], [1, 2], true)) {
    http_response_code(403);
    exit('No tiene permisos para exportar el reporte de notas.');
}

$isDirector = ((int)($role['is_director'] ?? 0) === 1);
$isAdminScope = ((int)$role['type'] === 1) || $isDirector;
if (!$isAdminScope) {
    $teacherId = (int)($role['teacher_id'] ?? $teacherId);
    if ($teacherId <= 0) {
        http_response_code(403);
        exit('La cuenta docente no tiene un docente vinculado.');
    }
}

$courseId = (int)($_GET['course_id'] ?? 0);

// Si se eligió un curso, mantener exactamente el exportador oficial actual.
if ($courseId > 0) {
    $originalType = (int)($_SESSION['login_type'] ?? 0);
    if ($isDirector && $originalType !== 1) {
        $_SESSION['login_type'] = 1;
        register_shutdown_function(static function () use ($originalType): void {
            $_SESSION['login_type'] = $originalType;
        });
    }
    require __DIR__ . '/export_grades_excel.php';
    exit;
}

$yearId = (int)($_GET['academic_year_id'] ?? 0);
$level = trim((string)($_GET['level'] ?? ''));
$grade = trim((string)($_GET['grado'] ?? ''));
$section = trim((string)($_GET['seccion'] ?? ''));
$bimester = (int)($_GET['bimestre'] ?? 0);
$exportFormat = (string)($_GET['export_format'] ?? 'numeric');

if ($yearId <= 0 || $level === '' || $grade === '' || $section === '' || $bimester < 1 || $bimester > 4) {
    http_response_code(422);
    exit('Para exportar todos los cursos debe seleccionar año, nivel, grado, sección y bimestre.');
}
if (!in_array($exportFormat, ['numeric', 'letters'], true)) {
    $exportFormat = 'numeric';
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('PhpSpreadsheet no está disponible. Ejecute composer install en la carpeta edusync.');
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function gre_norm(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return preg_replace('/[°º\s]+/u', '', $value) ?? $value;
}
function gre_section(string $value): string {
    $v = gre_norm($value);
    return in_array($v, ['', 'u', 'unica', 'única'], true) ? 'u' : $v;
}
function gre_numeric($grade): ?float {
    if ($grade === null) return null;
    $text = strtoupper(trim((string)$grade));
    if ($text === '') return null;
    $map = ['C' => 5.0, 'B' => 12.0, 'A' => 15.5, 'AD' => 19.0];
    if (array_key_exists($text, $map)) return $map[$text];
    return is_numeric($text) ? (float)$text : null;
}
function gre_letter(?float $value): string {
    if ($value === null) return '—';
    $n = (int)round($value);
    if ($n >= 18) return 'AD';
    if ($n >= 14) return 'A';
    if ($n >= 11) return 'B';
    return 'C';
}
function gre_display_raw($grade, string $format): string {
    $num = gre_numeric($grade);
    if ($num === null) return '—';
    return $format === 'letters' ? gre_letter($num) : (string)((int)round($num));
}
function gre_display_avg(?float $value, string $format): string {
    if ($value === null) return '—';
    return $format === 'letters' ? gre_letter($value) : (string)((int)round($value));
}
function gre_safe_sheet_name(string $name, array &$used): string {
    $name = preg_replace('/[\\\/?*\[\]:]/u', ' ', trim($name)) ?: 'CURSO';
    $name = preg_replace('/\s+/u', ' ', $name) ?: 'CURSO';
    $base = mb_substr($name, 0, 31, 'UTF-8');
    if ($base === '') $base = 'CURSO';
    $candidate = $base;
    $n = 2;
    while (isset($used[mb_strtolower($candidate, 'UTF-8')])) {
        $suffix = ' ' . $n++;
        $candidate = mb_substr($base, 0, max(1, 31 - mb_strlen($suffix, 'UTF-8')), 'UTF-8') . $suffix;
    }
    $used[mb_strtolower($candidate, 'UTF-8')] = true;
    return $candidate;
}
function gre_style_header($sheet, string $range): void {
    $sheet->getStyle($range)->getFont()->setBold(true);
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEAF0F8');
    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFB7C3D4');
}
function gre_style_table($sheet, string $range): void {
    $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFD6DCE5');
    $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}

$yearStmt = $conn->prepare('SELECT year FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
$yearStmt->bind_param('ii', $yearId, $schoolId);
$yearStmt->execute();
$yearRow = $yearStmt->get_result()->fetch_assoc();
$yearStmt->close();
if (!$yearRow) {
    http_response_code(404);
    exit('El año académico seleccionado no pertenece a esta institución.');
}
$yearLabel = (string)$yearRow['year'];

$bimesterLocked = false;
$lockCheck = $conn->query("SHOW TABLES LIKE 'bimester_locks'");
if ($lockCheck && $lockCheck->num_rows > 0) {
    $lockStmt = $conn->prepare('SELECT is_locked FROM bimester_locks WHERE school_id=? AND academic_year_id=? AND bimester=? LIMIT 1');
    if ($lockStmt) {
        $lockStmt->bind_param('iii', $schoolId, $yearId, $bimester);
        $lockStmt->execute();
        $lockRow = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        $bimesterLocked = ((int)($lockRow['is_locked'] ?? 0) === 1);
    }
}

$closedAt = null;
$closureCheck = $conn->query("SHOW TABLES LIKE 'institutional_bimester_closures'");
if ($closureCheck && $closureCheck->num_rows > 0) {
    $closureStmt = $conn->prepare("SELECT closed_at FROM institutional_bimester_closures WHERE school_id=? AND academic_year_id=? AND bimester=? AND status='Cerrado' ORDER BY version DESC,id DESC LIMIT 1");
    if ($closureStmt) {
        $closureStmt->bind_param('iii', $schoolId, $yearId, $bimester);
        $closureStmt->execute();
        $closureRow = $closureStmt->get_result()->fetch_assoc();
        $closureStmt->close();
        $closedAt = $closureRow['closed_at'] ?? null;
    }
}

$sqlAssignments = "SELECT tc.id teacher_course_id,tc.teacher_id,tc.course_id,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,ac.name course_name,ac.level
                   FROM teacher_courses tc
                   INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
                   WHERE tc.school_id=? AND tc.academic_year_id=?";
$params = [$schoolId, $yearId];
$types = 'ii';
if (!$isAdminScope) {
    $sqlAssignments .= ' AND tc.teacher_id=?';
    $params[] = $teacherId;
    $types .= 'i';
}
$sqlAssignments .= ' ORDER BY ac.name,tc.id';
$assignmentStmt = $conn->prepare($sqlAssignments);
$assignmentStmt->bind_param($types, ...$params);
$assignmentStmt->execute();
$assignmentRes = $assignmentStmt->get_result();
$assignments = [];
$courseMeta = [];
while ($row = $assignmentRes->fetch_assoc()) {
    if (gre_norm((string)$row['level']) !== gre_norm($level)) continue;
    if (gre_norm((string)$row['grado']) !== gre_norm($grade)) continue;
    if (gre_section((string)$row['seccion']) !== gre_section($section)) continue;
    $tcId = (int)$row['teacher_course_id'];
    $cid = (int)$row['course_id'];
    $assignments[$tcId] = $row;
    if (!isset($courseMeta[$cid])) {
        $courseMeta[$cid] = ['id'=>$cid, 'name'=>(string)$row['course_name'], 'teacher_courses'=>[]];
    }
    $courseMeta[$cid]['teacher_courses'][] = $tcId;
}
$assignmentStmt->close();

if (!$courseMeta) {
    http_response_code(404);
    exit('No se encontraron cursos asignados para el aula seleccionada.');
}

uasort($courseMeta, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
$tcIds = array_keys($assignments);
$tcList = implode(',', array_map('intval', $tcIds));

$studentStmt = $conn->prepare("SELECT id,name,id_no,nivel,grado,COALESCE(NULLIF(TRIM(seccion),''),'U') seccion
                              FROM student WHERE school_id=? AND (status='Activo' OR status='1' OR status IS NULL)
                              ORDER BY name");
$studentStmt->bind_param('i', $schoolId);
$studentStmt->execute();
$studentRes = $studentStmt->get_result();
$students = [];
while ($row = $studentRes->fetch_assoc()) {
    if (gre_norm((string)$row['nivel']) === gre_norm($level)
        && gre_norm((string)$row['grado']) === gre_norm($grade)
        && gre_section((string)$row['seccion']) === gre_section($section)) {
        $students[(int)$row['id']] = ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'dni'=>(string)$row['id_no']];
    }
}
$studentStmt->close();

$statusClause = '';
$statusCol = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
if ($statusCol && $statusCol->num_rows > 0) {
    $statusClause = " AND (e.status IS NULL OR e.status<>'Anulada')";
}
$evalSql = "SELECT e.id,e.teacher_course_id,e.title
            FROM evaluations e
            WHERE e.teacher_course_id IN ($tcList) AND CAST(e.bimestre AS UNSIGNED)=?$statusClause
            ORDER BY e.teacher_course_id,e.id";
$evalStmt = $conn->prepare($evalSql);
$evalStmt->bind_param('i', $bimester);
$evalStmt->execute();
$evalRes = $evalStmt->get_result();
$evaluations = [];
$evalIds = [];
while ($row = $evalRes->fetch_assoc()) {
    $eid = (int)$row['id'];
    $tcid = (int)$row['teacher_course_id'];
    $cid = (int)$assignments[$tcid]['course_id'];
    $evaluations[$eid] = ['id'=>$eid,'tcid'=>$tcid,'course_id'=>$cid,'title'=>(string)$row['title']];
    $evalIds[] = $eid;
}
$evalStmt->close();

$linksByEval = [];
$competencies = [];
$grades = [];
if ($evalIds) {
    $evalList = implode(',', array_map('intval', $evalIds));
    $linkSql = "SELECT ec.evaluation_id,gcc.id competencia_id,gcc.name,gcc.percentage
                FROM evaluation_competencias ec
                INNER JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id
                WHERE ec.evaluation_id IN ($evalList) AND (gcc.is_active=1 OR gcc.is_active IS NULL)
                ORDER BY gcc.id,ec.evaluation_id";
    $linkRes = $conn->query($linkSql);
    while ($linkRes && ($row = $linkRes->fetch_assoc())) {
        $eid = (int)$row['evaluation_id'];
        if (!isset($evaluations[$eid])) continue;
        $cid = (int)$evaluations[$eid]['course_id'];
        $compId = (int)$row['competencia_id'];
        $linksByEval[$eid][$compId] = true;
        if (!isset($competencies[$cid][$compId])) {
            $competencies[$cid][$compId] = [
                'id'=>$compId,
                'name'=>(string)$row['name'],
                'percentage'=>(float)$row['percentage'],
                'evaluations'=>[]
            ];
        }
        $competencies[$cid][$compId]['evaluations'][$eid] = $evaluations[$eid];
    }

    $gradeSql = "SELECT eg.evaluation_id,eg.student_id,eg.competencia_id,eg.grade
                 FROM evaluation_grades eg
                 WHERE eg.evaluation_id IN ($evalList) AND eg.grade IS NOT NULL AND TRIM(eg.grade)<>''";
    $gradeRes = $conn->query($gradeSql);
    while ($gradeRes && ($row = $gradeRes->fetch_assoc())) {
        $eid = (int)$row['evaluation_id'];
        $sid = (int)$row['student_id'];
        $compKey = $row['competencia_id'] === null ? 0 : (int)$row['competencia_id'];
        $grades[$sid][$eid][$compKey] = $row['grade'];
        if (!isset($students[$sid])) {
            $historyStmt = $conn->prepare("SELECT id,name,id_no FROM student WHERE id=? AND school_id=? AND (status='Activo' OR status='1' OR status IS NULL) LIMIT 1");
            if ($historyStmt) {
                $historyStmt->bind_param('ii', $sid, $schoolId);
                $historyStmt->execute();
                $historyRow = $historyStmt->get_result()->fetch_assoc();
                $historyStmt->close();
                if ($historyRow) {
                    $students[$sid] = ['id'=>$sid,'name'=>(string)$historyRow['name'],'dni'=>(string)$historyRow['id_no']];
                }
            }
        }
    }
}

uasort($students, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

function gre_grade_for(array $grades, int $studentId, int $evaluationId, int $compId) {
    if (isset($grades[$studentId][$evaluationId][$compId])) return $grades[$studentId][$evaluationId][$compId];
    if (isset($grades[$studentId][$evaluationId][0])) return $grades[$studentId][$evaluationId][0];
    return null;
}
function gre_course_result(array $courseComps, array $grades, int $studentId): ?float {
    $weighted = 0.0;
    $hasAny = false;
    foreach ($courseComps as $compId => $comp) {
        $nums = [];
        foreach ($comp['evaluations'] as $eval) {
            $raw = gre_grade_for($grades, $studentId, (int)$eval['id'], (int)$compId);
            $num = gre_numeric($raw);
            if ($num !== null) $nums[] = $num;
        }
        if ($nums) {
            $avg = array_sum($nums) / count($nums);
            $weighted += $avg * ((float)$comp['percentage'] / 100);
            $hasAny = true;
        }
    }
    return $hasAny ? $weighted : null;
}

$spreadsheet = new Spreadsheet();
$summary = $spreadsheet->getActiveSheet();
$summary->setTitle('RESUMEN');

$courseCount = count($courseMeta);
$summaryLastCol = Coordinate::stringFromColumnIndex(3 + $courseCount);
$summary->mergeCells("A1:{$summaryLastCol}1");
$summary->setCellValue('A1', 'REPORTE DE NOTAS - CONSOLIDADO DEL AULA');
$summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$summary->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$summary->mergeCells("A2:{$summaryLastCol}2");
$summary->setCellValue('A2', "Año: {$yearLabel}   |   Nivel: {$level}   |   Grado: {$grade}   |   Sección: {$section}   |   {$bimester}° Bimestre");
$summary->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$summary->mergeCells("A3:{$summaryLastCol}3");
$statusText = $bimesterLocked ? 'CERRADO INSTITUCIONALMENTE' : 'ABIERTO - las calificaciones pueden cambiar';
if ($closedAt) $statusText .= ' | Cierre: ' . date('d/m/Y H:i', strtotime((string)$closedAt));
$summary->setCellValue('A3', 'Estado: ' . $statusText);
$summary->getStyle('A3')->getFont()->setBold(true);
$summary->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$headers = ['N°','DNI','APELLIDOS Y NOMBRES'];
foreach ($courseMeta as $course) $headers[] = mb_strtoupper($course['name'], 'UTF-8');
$summary->fromArray($headers, null, 'A5');
gre_style_header($summary, "A5:{$summaryLastCol}5");

$rowIndex = 6;
$studentNo = 1;
foreach ($students as $student) {
    $row = [$studentNo++, $student['dni'], $student['name']];
    foreach ($courseMeta as $cid => $course) {
        $value = gre_course_result($competencies[(int)$cid] ?? [], $grades, (int)$student['id']);
        $row[] = gre_display_avg($value, $exportFormat);
    }
    $summary->fromArray($row, null, 'A' . $rowIndex++);
}
if ($rowIndex > 6) gre_style_table($summary, "A6:{$summaryLastCol}" . ($rowIndex - 1));
$summary->freezePane('D6');
$summary->getColumnDimension('A')->setWidth(6);
$summary->getColumnDimension('B')->setWidth(14);
$summary->getColumnDimension('C')->setWidth(36);
for ($i = 4; $i <= 3 + $courseCount; $i++) {
    $summary->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(17);
}
$summary->getStyle("A5:{$summaryLastCol}" . max(5, $rowIndex - 1))->getAlignment()->setWrapText(true);

$usedSheetNames = ['resumen'=>true];
foreach ($courseMeta as $cid => $course) {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle(gre_safe_sheet_name(mb_strtoupper($course['name'], 'UTF-8'), $usedSheetNames));
    $courseComps = $competencies[(int)$cid] ?? [];

    $columns = 3;
    foreach ($courseComps as $comp) $columns += max(1, count($comp['evaluations'])) + 1;
    $columns += 1;
    $lastCol = Coordinate::stringFromColumnIndex($columns);

    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue('A1', mb_strtoupper($course['name'], 'UTF-8'));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->mergeCells("A2:{$lastCol}2");
    $sheet->setCellValue('A2', "{$level} | {$grade} {$section} | {$bimester}° Bimestre | Año {$yearLabel} | Formato: " . ($exportFormat === 'letters' ? 'Letras' : 'Numérico'));
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells('A4:A5'); $sheet->setCellValue('A4', 'N°');
    $sheet->mergeCells('B4:B5'); $sheet->setCellValue('B4', 'DNI');
    $sheet->mergeCells('C4:C5'); $sheet->setCellValue('C4', 'APELLIDOS Y NOMBRES');
    $colIndex = 4;
    foreach ($courseComps as $compId => $comp) {
        $evalCount = max(1, count($comp['evaluations']));
        $startCol = Coordinate::stringFromColumnIndex($colIndex);
        $endGroupCol = Coordinate::stringFromColumnIndex($colIndex + $evalCount);
        $sheet->mergeCells("{$startCol}4:{$endGroupCol}4");
        $sheet->setCellValue("{$startCol}4", mb_strtoupper($comp['name'], 'UTF-8') . ' (' . rtrim(rtrim(number_format((float)$comp['percentage'], 2, '.', ''), '0'), '.') . '%)');
        if ($comp['evaluations']) {
            foreach ($comp['evaluations'] as $eval) {
                $evalCol = Coordinate::stringFromColumnIndex($colIndex++);
                $sheet->setCellValue("{$evalCol}5", $eval['title']);
            }
        } else {
            $evalCol = Coordinate::stringFromColumnIndex($colIndex++);
            $sheet->setCellValue("{$evalCol}5", 'Sin evaluaciones');
        }
        $avgCol = Coordinate::stringFromColumnIndex($colIndex++);
        $sheet->setCellValue("{$avgCol}5", 'PROMEDIO');
    }
    $finalCol = Coordinate::stringFromColumnIndex($colIndex);
    $sheet->mergeCells("{$finalCol}4:{$finalCol}5");
    $sheet->setCellValue("{$finalCol}4", 'PROMEDIO FINAL');
    gre_style_header($sheet, "A4:{$finalCol}5");

    $dataRow = 6;
    $no = 1;
    foreach ($students as $student) {
        $sheet->setCellValue("A{$dataRow}", $no++);
        $sheet->setCellValueExplicit("B{$dataRow}", (string)$student['dni'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue("C{$dataRow}", $student['name']);
        $colIndex = 4;
        $weighted = 0.0;
        $hasAny = false;
        foreach ($courseComps as $compId => $comp) {
            $nums = [];
            if ($comp['evaluations']) {
                foreach ($comp['evaluations'] as $eval) {
                    $raw = gre_grade_for($grades, (int)$student['id'], (int)$eval['id'], (int)$compId);
                    $num = gre_numeric($raw);
                    if ($num !== null) $nums[] = $num;
                    $cellCol = Coordinate::stringFromColumnIndex($colIndex++);
                    $sheet->setCellValue("{$cellCol}{$dataRow}", gre_display_raw($raw, $exportFormat));
                }
            } else {
                $cellCol = Coordinate::stringFromColumnIndex($colIndex++);
                $sheet->setCellValue("{$cellCol}{$dataRow}", '—');
            }
            $avg = $nums ? array_sum($nums) / count($nums) : null;
            $avgCol = Coordinate::stringFromColumnIndex($colIndex++);
            $sheet->setCellValue("{$avgCol}{$dataRow}", gre_display_avg($avg, $exportFormat));
            if ($avg !== null) {
                $weighted += $avg * ((float)$comp['percentage'] / 100);
                $hasAny = true;
            }
        }
        $finalCol = Coordinate::stringFromColumnIndex($colIndex);
        $sheet->setCellValue("{$finalCol}{$dataRow}", gre_display_avg($hasAny ? $weighted : null, $exportFormat));
        $dataRow++;
    }

    if (!$courseComps) {
        $sheet->mergeCells('D4:D5');
        $sheet->setCellValue('D4', 'SIN EVALUACIONES / COMPETENCIAS PARA ESTE BIMESTRE');
        gre_style_header($sheet, 'A4:D5');
    }

    if ($dataRow > 6) gre_style_table($sheet, "A6:{$lastCol}" . ($dataRow - 1));
    $sheet->freezePane('D6');
    $sheet->getColumnDimension('A')->setWidth(6);
    $sheet->getColumnDimension('B')->setWidth(14);
    $sheet->getColumnDimension('C')->setWidth(36);
    for ($i = 4; $i <= $columns; $i++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(16);
    }
    $sheet->getStyle("A4:{$lastCol}" . max(5, $dataRow - 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("D6:{$lastCol}" . max(6, $dataRow - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

$spreadsheet->setActiveSheetIndex(0);
$cleanLevel = preg_replace('/[^A-Za-z0-9_-]+/u', '_', $level) ?: 'Nivel';
$cleanGrade = preg_replace('/[^A-Za-z0-9_-]+/u', '_', $grade) ?: 'Grado';
$cleanSection = preg_replace('/[^A-Za-z0-9_-]+/u', '_', $section) ?: 'Seccion';
$filename = "Reporte_Notas_{$cleanLevel}_{$cleanGrade}_{$cleanSection}_{$bimester}B_{$yearLabel}.xlsx";

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;
