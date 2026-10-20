<?php
/**
 * EduSync docente: prueba de contrato sin base de datos ni escrituras.
 *
 * Ejecutar en el repositorio web:
 * php tools/test_teacher_mobile_api_restore.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$rules = [
    'edusync/api/teacher_courses_mobile.php' => [
        "session_config.php", "teacher_course_id", "'courses'", "'students'",
        "sessionTeacherId", "sessionSchoolId", "teacher_mobile_reply"
    ],
    'edusync/api/teacher_attendance_mobile.php' => [
        "session_config.php", "attendance_history_helper.php", "'history'",
        "'daily'", "read_only", "teacher_id"
    ],
    'edusync/api/teacher_gradebook_mobile.php' => [
        "session_config.php", "gradebook_api.php", "grade_closure_api.php",
        "'load'", "'save'", "'closure_status'", "'close_period'",
        "'request_reopen'", "csrf_token"
    ],
    'edusync/includes/attendance_history_helper.php' => [
        "function attendance_group_days(", "function attendance_teacher_roster_sql("
    ],
    'edusync/gradebook_api.php' => [
        "grades_by_competency", "grade_cells", "evaluation_competencies",
        "validEvaluationCompetencies", "edun_notify_admin_grade_changes"
    ],
    'edusync/grade_closure_api.php' => [
        "grade_closure_progress.php", "gc_calculate_competency_progress(",
        "edwp_queue_staff_event("
    ],
    'edusync/includes/grade_closure_progress.php' => [
        "function gc_calculate_competency_progress(", "grade_cells_complete"
    ],
    'edusync/session_config.php' => [
        "session_id(\$_REQUEST['sid'])"
    ],
    'edusync/auth_login.php' => [
        "'session_id' => \$mobile_session_id"
    ]
];
$failed = false;
foreach ($rules as $file => $tokens) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "[FALTA] $file\n");
        $failed = true;
        continue;
    }
    $source = (string)file_get_contents($path);
    foreach ($tokens as $token) {
        if (strpos($source, $token) === false) {
            fwrite(STDERR, "[INCOMPATIBLE] $file: falta $token\n");
            $failed = true;
        }
    }
    if (!$failed) echo "[OK] $file\n";
}
if ($failed) exit(1);
echo "Contratos presentes; validar tambien con php -l y peticiones autenticadas de prueba.\n";
