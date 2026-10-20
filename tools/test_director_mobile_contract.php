<?php
/**
 * Contrato de API de Dirección, sin conexión a MySQL ni cambios de datos.
 * Ejecutar: php tools/test_director_mobile_contract.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$checks = [
    'edusync/auth_login.php' => [
        "'is_director' => (int)(\$row['is_director']",
        "'session_id' => \$mobile_session_id",
    ],
    'edusync/api/director_academic_mobile.php' => [
        "session_config.php",
        "u.is_director=1",
        "u.school_id=?",
        "u.status='Activo'",
        "student_detail",
        "academic_year_id",
        "teacher_course_id",
        "read_only",
        "dma_class_key",
        "set_exception_handler(",
        "register_shutdown_function(",
        "X-EduSync-Request-ID",
        "dma_stage",
        "SHOW COLUMNS FROM users LIKE 'status'",
        "SHOW COLUMNS FROM users LIKE 'is_director'",
    ],
    'edusync/api/teacher_courses_mobile.php' => [
        'sessionTeacherId',
        'sessionSchoolId',
        'tc.teacher_id=?',
    ],
];
$errors = [];
foreach ($checks as $name => $needles) {
    $path = $root . '/' . $name;
    if (!is_file($path)) {
        $errors[] = "Falta $name";
        continue;
    }
    $source = (string) file_get_contents($path);
    foreach ($needles as $needle) {
        if (strpos($source, $needle) === false) {
            $errors[] = "Contrato ausente: $name / $needle";
        }
    }
}
if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, "[ERROR] $error\n");
    exit(1);
}
echo "Contratos verificados (lectura estática).\n";
echo "Pendiente: php -l, validación de permisos y datos en XAMPP.\n";
