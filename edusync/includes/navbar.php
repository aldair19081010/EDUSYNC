<?php
require __DIR__ . '/navbar_view.php';
?>
<?php
// El CSS se entrega desde <head> en index.php para evitar el parpadeo FOUC.
// Aquí sólo cargamos los scripts de la interfaz; no se vuelve a cambiar el href del CSS.
$ui_js_file = dirname(__DIR__) . '/js/ui_consistency.js';
$ui_modals_js_file = dirname(__DIR__) . '/js/ui_modals.js';
$ui_feedback_js_file = dirname(__DIR__) . '/js/ui_feedback.js';
$ui_table_actions_js_file = dirname(__DIR__) . '/js/ui_table_actions.js';
$student_grades_js_file = dirname(__DIR__) . '/js/student_grades.js';
$student_payments_js_file = dirname(__DIR__) . '/js/student_payments.js';
$student_attendance_js_file = dirname(__DIR__) . '/js/student_attendance.js';
$ui_js_version = @filemtime($ui_js_file) ?: time();
$ui_modals_js_version = @filemtime($ui_modals_js_file) ?: time();
$ui_feedback_js_version = @filemtime($ui_feedback_js_file) ?: time();
$ui_table_actions_js_version = @filemtime($ui_table_actions_js_file) ?: time();
$student_grades_js_version = @filemtime($student_grades_js_file) ?: time();
$student_payments_js_version = @filemtime($student_payments_js_file) ?: time();
$student_attendance_js_version = @filemtime($student_attendance_js_file) ?: time();
$is_student_grades_page = ($current_page ?? '') === 'student_grades';
$is_student_payments_page = ($current_page ?? '') === 'student_payments';
$is_student_attendance_page = ($current_page ?? '') === 'student_attendances';
?>
<script src="js/ui_consistency.js?v=<?php echo rawurlencode((string)$ui_js_version); ?>"></script>
<script src="js/ui_modals.js?v=<?php echo rawurlencode((string)$ui_modals_js_version); ?>"></script>
<script src="js/ui_feedback.js?v=<?php echo rawurlencode((string)$ui_feedback_js_version); ?>"></script>
<script src="js/ui_table_actions.js?v=<?php echo rawurlencode((string)$ui_table_actions_js_version); ?>"></script>
<?php if ($is_student_grades_page): ?>
<script src="js/student_grades.js?v=<?php echo rawurlencode((string)$student_grades_js_version); ?>"></script>
<?php endif; ?>
<?php if ($is_student_payments_page): ?>
<script src="js/student_payments.js?v=<?php echo rawurlencode((string)$student_payments_js_version); ?>"></script>
<?php endif; ?>
<?php if ($is_student_attendance_page): ?>
<script src="js/student_attendance.js?v=<?php echo rawurlencode((string)$student_attendance_js_version); ?>"></script>
<?php endif; ?>