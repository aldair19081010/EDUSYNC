<?php
/**
 * EduSync · integración web + app docente, verificación no destructiva.
 * No ejecuta endpoints autenticados, ni conecta a MySQL, ni cambia notas.
 * Ejecutar: php tools/test_teacher_mobile_web_integration_v1.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);

function verifyFile(string $relative, array $mustContain): void {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Falta archivo de integración: ' . $relative);
    }
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('No se pudo leer: ' . $relative);
    }
    foreach ($mustContain as $needle) {
        if (strpos($source, $needle) === false) {
            throw new RuntimeException(
                $relative . ': falta contrato ' . $needle
            );
        }
    }
    echo '[OK] ' . $relative . PHP_EOL;
}

verifyFile('edusync/auth_login.php', [
    'session_regenerate_id(true)',
    'edrs_process_login(',
    "'session_id' => \$mobile_session_id",
    "'remember_saved' => \$remember_saved",
]);
verifyFile('edusync/api/login.php', [
    'session_regenerate_id(true)',
    'edrs_process_login(',
    "'session_id' => \$mobile_session_id",
]);
verifyFile('edusync/session_config.php', [
    'edrs_restore(',
    "session_id(\$_REQUEST['sid'])",
]);
verifyFile('edusync/includes/remember_session.php', [
    'EDRS_TTL = 30 * 24 * 60 * 60',
    'edrs_restore(',
    'edrs_process_login(',
]);
verifyFile('edusync/api/teacher_courses_mobile.php', [
    "'sid'",
    'session_config.php',
]);
verifyFile('edusync/api/teacher_attendance_mobile.php', [
    'attendance_history_helper.php',
    'session_config.php',
]);
verifyFile('edusync/api/teacher_gradebook_mobile.php', [
    'gradebook_api.php',
    'grade_closure_api.php',
    'session_config.php',
]);
verifyFile('edusync/gradebook_api.php', [
    'grades_by_competency',
    'grade_cells',
    'teacher_closed',
    'evaluation_grade_history',
]);
verifyFile('edusync/grade_closure_api.php', [
    'grade_closure_progress.php',
    'gc_calculate_competency_progress(',
    "grade_cells_complete",
]);
verifyFile('edusync/includes/grade_closure_progress.php', [
    'competency_coverage_progress',
    'competencies_without_evaluations',
]);
verifyFile('edusync/api/my_attendance.php', [
    'attendance_history_helper.php',
]);
verifyFile('edusync/includes/attendance_report_queries.php', [
    'function ',
]);
verifyFile('edusync/includes/academic_alert_policy.php', [
    '<?php',
]);
verifyFile('edusync/cron/web_push_dispatch.php', [
    '<?php',
]);

echo 'Contratos de integración verificados. Para validar inicio real, ';
echo 'notas y cierre se requiere ejecutar pruebas en XAMPP con respaldo.' . PHP_EOL;
