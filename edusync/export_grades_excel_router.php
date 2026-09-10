<?php
// Exportador del Reporte de notas.
// Curso específico: conserva el exportador oficial existente.
// Curso = Todos: genera RESUMEN + una hoja por curso con el mismo orden visual del reporte individual.

date_default_timezone_set('America/Lima');

if (session_status() === PHP_SESSION_NONE) {
    $session_save_path = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($session_save_path)) @mkdir($session_save_path, 0755, true);
    ini_set('session.save_path', $session_save_path);
    session_name('EDUSYNCSESSID');
    session_set_cookie_params(['path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/grades_report_bulk_data.php';

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
$sessionType = (int)($_SESSION['login_type'] ?? 0);
$sessionTeacherId = (int)($_SESSION['login_teacher_id'] ?? 0);
if ($schoolId <= 0 || $userId <= 0 || $sessionType <= 0) {
    http_response_code(401);
    exit('Sesión no válida. Inicie sesión nuevamente.');
}

$roleStmt = $conn->prepare('SELECT type,is_director,teacher_id FROM users WHERE id=? AND school_id=? LIMIT 1');
$roleStmt->bind_param('ii', $userId, $schoolId);
$roleStmt->execute();
$role = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();
if (!$role || !in_array((int)$role['type'], [1,2], true)) {
    http_response_code(403);
    exit('No tiene permisos para exportar el reporte de notas.');
}

$isInstitutional = ((int)$role['type'] === 1) || ((int)($role['is_director'] ?? 0) === 1);
$teacherId = (int)($role['teacher_id'] ?? $sessionTeacherId);
if (!$isInstitutional && $teacherId <= 0) {
    http_response_code(403);
    exit('La cuenta docente no tiene un docente vinculado.');
}

$courseId = (int)($_GET['course_id'] ?? 0);
if ($courseId > 0) {
    require __DIR__ . '/export_grades_excel.php';
    exit;
}

$filters = [
    'academic_year_id' => (int)($_GET['academic_year_id'] ?? 0),
    'level' => trim((string)($_GET['level'] ?? '')),
    'grado' => trim((string)($_GET['grado'] ?? '')),
    'seccion' => trim((string)($_GET['seccion'] ?? '')),
    'bimestre' => (int)($_GET['bimestre'] ?? 0),
    'course_id' => 0
];
$format = (string)($_GET['export_format'] ?? 'numeric');
if (!in_array($format, ['numeric','letters'], true)) $format = 'numeric';

try {
    $data = grbd_build($conn, $filters, [
        'school_id' => $schoolId,
        'teacher_id' => $teacherId,
        'institutional' => $isInstitutional
    ]);
} catch (Throwable $e) {
    http_response_code(422);
    exit($e->getMessage());
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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

function gre_style_header($sheet, $range) {
    $sheet->getStyle($range)->getFont()->setBold(true);
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE6EFF9');
    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');
}

function gre_style_body($sheet, $range) {
    $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');
    $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
}

function gre_sheet_name($name, array &$used) {
    $name = preg_replace('/[\\\/?*\[\]:]/u', ' ', trim((string)$name));
    $name = preg_replace('/\s+/u', ' ', $name ?: 'CURSO');
    $base = mb_substr($name, 0, 31, 'UTF-8') ?: 'CURSO';
    $candidate = $base;
    $n = 2;
    while (isset($used[mb_strtolower($candidate, 'UTF-8')])) {
        $suffix = ' ' . $n++;
        $candidate = mb_substr($base, 0, 31 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
    }
    $used[mb_strtolower($candidate, 'UTF-8')] = true;
    return $candidate;
}

$spreadsheet = new Spreadsheet();
$summary = $spreadsheet->getActiveSheet();
$summary->setTitle('RESUMEN');
$courseCount = count($data['courses']);
$summaryLastCol = Coordinate::stringFromColumnIndex(3 + $courseCount);

$summary->mergeCells("A1:{$summaryLastCol}1");
$summary->setCellValue('A1', 'REPORTE DE NOTAS - CONSOLIDADO DEL AULA');
$summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$summary->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$summary->mergeCells("A2:{$summaryLastCol}2");
$summary->setCellValue('A2', "Año {$data['year']} | {$data['level']} | {$data['grade']} {$data['section']} | {$data['bimester']}° Bimestre | " . ($format === 'letters' ? 'Letras' : 'Numérico'));
$summary->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$summary->mergeCells("A3:{$summaryLastCol}3");
$status = $data['locked'] ? 'CERRADO INSTITUCIONALMENTE' : 'ABIERTO';
if ($data['closed_at']) $status .= ' | Cierre: ' . date('d/m/Y H:i', strtotime($data['closed_at']));
$summary->setCellValue('A3', 'Estado: ' . $status);
$summary->getStyle('A3')->getFont()->setBold(true);
$summary->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$headers = ['N°','DNI','APELLIDOS Y NOMBRES'];
foreach ($data['courses'] as $course) $headers[] = mb_strtoupper($course['name'], 'UTF-8');
$summary->fromArray($headers, null, 'A5');
gre_style_header($summary, "A5:{$summaryLastCol}5");

$row = 6;
$number = 1;
foreach ($data['students'] as $student) {
    $summary->setCellValue("A{$row}", $number++);
    $summary->setCellValueExplicit("B{$row}", $student['dni'], DataType::TYPE_STRING);
    $summary->setCellValue("C{$row}", $student['name']);
    $col = 4;
    foreach ($data['courses'] as $cid => $course) {
        $result = grbd_course_result($data['competencies'][$cid] ?? [], $data['grades'], $student['id']);
        $summary->setCellValue(Coordinate::stringFromColumnIndex($col++) . $row, grbd_display_avg($result, $format));
    }
    $row++;
}
if ($row > 6) gre_style_body($summary, "A6:{$summaryLastCol}" . ($row - 1));
$summary->freezePane('D6');
$summary->getColumnDimension('A')->setWidth(6);
$summary->getColumnDimension('B')->setWidth(14);
$summary->getColumnDimension('C')->setWidth(38);
for ($c=4; $c<=3+$courseCount; $c++) $summary->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(18);
$summary->getStyle("A5:{$summaryLastCol}" . max(5,$row-1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
$summary->getStyle("A5:B" . max(5,$row-1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$summary->getStyle("D6:{$summaryLastCol}" . max(6,$row-1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$summary->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
$summary->getPageSetup()->setFitToPage(true);
$summary->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.25)->setRight(0.25);

$usedNames = ['resumen'=>true];
foreach ($data['courses'] as $cid => $course) {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle(gre_sheet_name(mb_strtoupper($course['name'], 'UTF-8'), $usedNames));
    $competencies = $data['competencies'][$cid] ?? [];

    // Igual que el Excel individual: A = alumno; desde B empiezan competencias/evaluaciones.
    $colIndex = 2;
    foreach ($competencies as $competency) {
        $colIndex += max(1, count($competency['evaluations'])) + 1;
    }
    $finalColumnIndex = $colIndex;
    $lastCol = Coordinate::stringFromColumnIndex($finalColumnIndex);

    $sheet->mergeCells("A1:{$lastCol}1");
    $sheet->setCellValue('A1', mb_strtoupper($course['name'], 'UTF-8'));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->mergeCells("A2:{$lastCol}2");
    $sheet->setCellValue('A2', "{$data['level']} | {$data['grade']} {$data['section']} | {$data['bimester']}° Bimestre | Año {$data['year']} | " . ($format === 'letters' ? 'Letras' : 'Numérico'));
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells('A4:A5');
    $sheet->setCellValue('A4', 'APELLIDOS Y NOMBRES');
    $colIndex = 2;
    foreach ($competencies as $competencyId => $competency) {
        $evalCount = max(1, count($competency['evaluations']));
        $start = Coordinate::stringFromColumnIndex($colIndex);
        $end = Coordinate::stringFromColumnIndex($colIndex + $evalCount);
        $sheet->mergeCells("{$start}4:{$end}4");
        $pct = rtrim(rtrim(number_format((float)$competency['percentage'], 2, '.', ''), '0'), '.');
        $sheet->setCellValue("{$start}4", mb_strtoupper($competency['name'], 'UTF-8') . " ({$pct}%)");

        if ($competency['evaluations']) {
            foreach ($competency['evaluations'] as $evaluation) {
                $cell = Coordinate::stringFromColumnIndex($colIndex++);
                $sheet->setCellValue("{$cell}5", $evaluation['title']);
            }
        } else {
            $cell = Coordinate::stringFromColumnIndex($colIndex++);
            $sheet->setCellValue("{$cell}5", 'Sin evaluaciones');
        }
        $avgCell = Coordinate::stringFromColumnIndex($colIndex++);
        $sheet->setCellValue("{$avgCell}5", 'PROMEDIO');
    }
    $finalCol = Coordinate::stringFromColumnIndex($colIndex);
    $sheet->mergeCells("{$finalCol}4:{$finalCol}5");
    $sheet->setCellValue("{$finalCol}4", 'PROMEDIO FINAL');
    gre_style_header($sheet, "A4:{$finalCol}5");

    $dataRow = 6;
    foreach ($data['students'] as $student) {
        $sheet->setCellValue("A{$dataRow}", $student['name']);
        $colIndex = 2;
        $weighted = 0.0;
        $hasAny = false;

        foreach ($competencies as $competencyId => $competency) {
            $values = [];
            if ($competency['evaluations']) {
                foreach ($competency['evaluations'] as $evaluation) {
                    $raw = grbd_grade_for($data['grades'], $student['id'], $evaluation['id'], $competencyId);
                    $num = grbd_numeric($raw);
                    if ($num !== null) $values[] = $num;
                    $cell = Coordinate::stringFromColumnIndex($colIndex++);
                    $sheet->setCellValue("{$cell}{$dataRow}", grbd_display_raw($raw, $format));
                }
            } else {
                $cell = Coordinate::stringFromColumnIndex($colIndex++);
                $sheet->setCellValue("{$cell}{$dataRow}", '-');
            }

            $avg = $values ? array_sum($values) / count($values) : null;
            $avgCell = Coordinate::stringFromColumnIndex($colIndex++);
            $sheet->setCellValue("{$avgCell}{$dataRow}", grbd_display_avg($avg, $format));
            if ($avg !== null) {
                $weighted += $avg * ((float)$competency['percentage'] / 100);
                $hasAny = true;
            }
        }

        $finalCol = Coordinate::stringFromColumnIndex($colIndex);
        $sheet->setCellValue("{$finalCol}{$dataRow}", grbd_display_avg($hasAny ? $weighted : null, $format));
        $dataRow++;
    }

    if ($dataRow > 6) gre_style_body($sheet, "A6:{$lastCol}" . ($dataRow - 1));
    $sheet->getColumnDimension('A')->setWidth(38);
    for ($c=2; $c<=$finalColumnIndex; $c++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(16);
    $sheet->getStyle("B6:{$lastCol}" . max(6,$dataRow-1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A4:{$lastCol}" . max(5,$dataRow-1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->freezePane('B6');
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageSetup()->setFitToPage(true);
    $sheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.25)->setRight(0.25);
}

$spreadsheet->setActiveSheetIndex(0);
$clean = function ($text, $fallback) {
    $value = preg_replace('/[^A-Za-z0-9_-]+/u', '_', (string)$text);
    return trim((string)$value, '_') ?: $fallback;
};
$filename = 'Reporte_Notas_' . $clean($data['level'],'Nivel') . '_' . $clean($data['grade'],'Grado') . '_' . $clean($data['section'],'Seccion') . '_' . $data['bimester'] . 'B_' . $data['year'] . '.xlsx';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;
