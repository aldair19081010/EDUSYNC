<?php

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
$sessionTeacherId = (int)($_SESSION['login_teacher_id'] ?? 0);
if ($schoolId <= 0 || $userId <= 0) {
    http_response_code(401);
    exit('Sesión no válida.');
}

$roleStmt = $conn->prepare('SELECT type,is_director,teacher_id FROM users WHERE id=? AND school_id=? LIMIT 1');
$roleStmt->bind_param('ii', $userId, $schoolId);
$roleStmt->execute();
$role = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();
if (!$role || !in_array((int)$role['type'], [1,2], true)) {
    http_response_code(403);
    exit('No tiene permisos para imprimir este reporte.');
}

$isInstitutional = ((int)$role['type'] === 1) || ((int)($role['is_director'] ?? 0) === 1);
$teacherId = (int)($role['teacher_id'] ?? $sessionTeacherId);
if (!$isInstitutional && $teacherId <= 0) {
    http_response_code(403);
    exit('La cuenta docente no tiene un docente vinculado.');
}

$filters = [
    'academic_year_id' => (int)($_GET['academic_year_id'] ?? 0),
    'level' => trim((string)($_GET['level'] ?? '')),
    'grado' => trim((string)($_GET['grado'] ?? '')),
    'seccion' => trim((string)($_GET['seccion'] ?? '')),
    'bimestre' => (int)($_GET['bimestre'] ?? 0),
    'course_id' => (int)($_GET['course_id'] ?? 0)
];

try {
    $data = grbd_build($conn, $filters, [
        'school_id' => $schoolId,
        'teacher_id' => $teacherId,
        'institutional' => $isInstitutional
    ]);
} catch (Throwable $e) {
    http_response_code(422);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$format = (string)($_GET['print_format'] ?? 'auto');
if (!in_array($format, ['numeric','letters'], true)) $format = $data['auto_format'];

function grp_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function grp_course_page(array $data, $courseId, $format, $index, $total) {
    $course = $data['courses'][$courseId];
    $competencies = $data['competencies'][$courseId] ?? [];
    ?>
    <section class="print-page">
        <div class="page-topline">
            <span>REPORTE DE NOTAS</span>
            <?php if ($total > 1): ?><span>Curso <?= (int)$index ?> de <?= (int)$total ?></span><?php endif; ?>
        </div>
        <h1><?= grp_h(mb_strtoupper($course['name'], 'UTF-8')) ?></h1>
        <div class="meta">
            Año <?= grp_h($data['year']) ?> · <?= grp_h($data['level']) ?> · <?= grp_h($data['grade']) ?> <?= grp_h($data['section']) ?> · <?= (int)$data['bimester'] ?>° Bimestre · <?= $format === 'letters' ? 'Letras' : 'Numérico' ?>
        </div>
        <div class="state">Estado: <?= $data['locked'] ? 'CERRADO INSTITUCIONALMENTE' : 'ABIERTO' ?><?= $data['closed_at'] ? ' · Cierre: ' . grp_h(date('d/m/Y H:i', strtotime($data['closed_at']))) : '' ?></div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2" class="student-col">APELLIDOS Y NOMBRES</th>
                        <?php foreach ($competencies as $competency):
                            $span = max(1, count($competency['evaluations'])) + 1;
                            $pct = rtrim(rtrim(number_format((float)$competency['percentage'], 2, '.', ''), '0'), '.'); ?>
                            <th colspan="<?= $span ?>" class="competency"><?= grp_h(mb_strtoupper($competency['name'], 'UTF-8')) ?> (<?= grp_h($pct) ?>%)</th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="final-col">PROMEDIO FINAL</th>
                    </tr>
                    <tr>
                        <?php foreach ($competencies as $competency): ?>
                            <?php if ($competency['evaluations']): ?>
                                <?php foreach ($competency['evaluations'] as $evaluation): ?>
                                    <th><?= grp_h($evaluation['title']) ?></th>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <th>Sin evaluaciones</th>
                            <?php endif; ?>
                            <th class="avg-col">PROMEDIO</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['students'] as $student):
                        $weighted = 0.0;
                        $hasAny = false; ?>
                        <tr>
                            <td class="student-col"><?= grp_h($student['name']) ?></td>
                            <?php foreach ($competencies as $competencyId => $competency):
                                $values = []; ?>
                                <?php if ($competency['evaluations']): ?>
                                    <?php foreach ($competency['evaluations'] as $evaluation):
                                        $raw = grbd_grade_for($data['grades'], $student['id'], $evaluation['id'], $competencyId);
                                        $num = grbd_numeric($raw);
                                        if ($num !== null) $values[] = $num; ?>
                                        <td><?= grp_h(grbd_display_raw($raw, $format)) ?></td>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <td>-</td>
                                <?php endif; ?>
                                <?php
                                $avg = $values ? array_sum($values) / count($values) : null;
                                if ($avg !== null) {
                                    $weighted += $avg * ((float)$competency['percentage'] / 100);
                                    $hasAny = true;
                                }
                                ?>
                                <td class="avg-col"><?= grp_h(grbd_display_avg($avg, $format)) ?></td>
                            <?php endforeach; ?>
                            <td class="final-col"><?= grp_h(grbd_display_avg($hasAny ? $weighted : null, $format)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

$courseIds = array_keys($data['courses']);
$totalCourses = count($courseIds);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Vista de impresión - Reporte de notas</title>
<style>
*{box-sizing:border-box}html,body{margin:0;padding:0}body{font-family:Arial,Helvetica,sans-serif;background:#e9edf3;color:#1f2937;font-size:9.5px}.print-page{background:#fff;width:calc(100% - 24px);margin:12px auto;padding:12mm 9mm;min-height:185mm;box-shadow:0 2px 10px rgba(0,0,0,.12);page-break-after:always;break-after:page}.print-page:last-child{page-break-after:auto;break-after:auto}.page-topline{display:flex;justify-content:space-between;font-size:9px;font-weight:700;color:#667085;margin-bottom:5px}.print-page h1{text-align:center;font-size:16px;margin:0 0 4px;color:#25324b}.meta,.state{text-align:center;margin-bottom:4px}.state{font-weight:700;margin-bottom:12px}.table-wrap{width:100%;overflow:visible}table{width:100%;border-collapse:collapse;table-layout:auto}thead{display:table-header-group}tr{page-break-inside:avoid;break-inside:avoid}th,td{border:1px solid #111827;padding:4px 5px;text-align:center;vertical-align:middle}th{background:#f2f2f2;font-weight:700}.competency{background:#e6eff9}.avg-col,.final-col{background:#dae3f3;font-weight:700}.student-col{text-align:left;min-width:190px;white-space:normal}@page{size:A4 landscape;margin:7mm}@media print{body{background:#fff}.print-page{width:100%;margin:0;padding:0;min-height:0;box-shadow:none}}
</style>
</head>
<body>
<?php
$index = 1;
foreach ($courseIds as $courseId) {
    grp_course_page($data, $courseId, $format, $index++, $totalCourses);
}
?>
</body>
</html>
