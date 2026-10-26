<?php
/**
 * Prueba estática y sin BD para la navegación y el contrato de cierre bimestral.
 * Ejecutar: php tools/test_bimester_closure_workspace_contract.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$read = static function (string $file) use ($root): string {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "Archivo no encontrado: $file\n");
        exit(1);
    }
    return (string)file_get_contents($path);
};
$page = $read('edusync/pages/bimester_closure.php');
$js = $read('edusync/js/bimester_closure_admin.js');
$css = $read('edusync/css/bimester_closure_admin.css');
$index = $read('edusync/index.php');
$navbar = $read('edusync/includes/navbar_view.php');
$year = $read('edusync/academic_year.php');
$gradeClosure = $read('edusync/js/grade_closure.js');
$checks = [
    'Menú administrativo directo' => str_contains($navbar, "'page' => 'bimester_closure'"),
    'Se eliminó el antiguo acceso lateral duplicado' => !str_contains($navbar, "'page' => 'teacher_followup'"),
    'Lista de módulos permitidos' => str_contains($index, "'bimester_closure'"),
    'Antiguos enlaces a Seguimiento Docente redirigen' => str_contains($index, 'page=bimester_closure&tab=teachers'),
    'Año académico ofrece acceso contextual' => str_contains($year, 'page=bimester_closure&amp;year_id='),
    'Página solo administrativa' => str_contains($page, "(\$_SESSION['login_type'] ?? 0) !== 1"),
    'Filtro de colegio' => str_contains($page, 'WHERE school_id=?'),
    'CSRF enviado en el nuevo panel' => str_contains($page, "'csrf'=>\$bcCsrf") && str_contains($js, 'csrf_token:cfg.csrf'),
    'Resumen visible' => str_contains($page, 'id="bc-overview"'),
    'Seguimiento docente visible' => str_contains($page, 'id="bc-teachers"'),
    'Control por asignaciones visible' => str_contains($page, 'id="bc-courses"'),
    'Reportes visibles' => str_contains($page, 'id="bc-reports"'),
    'Cierre institucional visible' => str_contains($page, 'id="bc-institution"'),
    'Tablas nativas paginadas sin buscador duplicado' => str_contains($js, 'DataTable({') && !str_contains($page, 'id="bc-search"'),
    'Validación de avance desde API consolidada' => str_contains($js, "gradeGet('progress')"),
    'Cierre individual validado por API' => str_contains($js, "adminPost('close_assignment'"),
    'Cierre masivo en lotes de 500' => str_contains($js, 'n+=500') && str_contains($js, "adminPost('close_bulk'"),
    'Cierre excepcional individual separado' => str_contains($js, "closeOne(x,true)"),
    'Reapertura institucional' => str_contains($js, "institutionAction('reopen')"),
    'Cierre institucional normal y excepcional' => str_contains($js, "institutionAction('Normal')") && str_contains($js, "institutionAction('Excepcional')"),
    'Motivo y palabra de confirmación' => str_contains($js, "word.toUpperCase()!=='CERRAR'") && str_contains($js, "reason.length<5"),
    'Avisos configurables sin duplicar API' => str_contains($js, "followPost('save_settings'") && str_contains($js, "followPost('send_reminder'"),
    'Exportación Excel y PDF mediante impresión' => str_contains($js, 'reportCsv()') && str_contains($js, 'reportPrint()'),
    'Salvaguarda de fórmulas en CSV' => str_contains($js, 'if(/^[\\t\\r\\n ]*[=+@-]/.test(t))'),
    'Estilo alineado a EduSync' => str_contains($css, '.bc-root') && str_contains($css, '.bc-root .ed-table'),
    'Docentes agrupados por identificador estable' => str_contains($read('edusync/academic_year_grade_control_api.php'), "'teacher_id'=>(int)\$assignment['teacher_id']") && str_contains($js, 'key=String(teacher||'),
    'Cierre docente mantiene sus endpoints originales' => str_contains($gradeClosure, 'grade_closure_api.php') && str_contains($gradeClosure, 'gc-confirm-close'),
];
$passed=0;
foreach ($checks as $label=>$ok) {
    echo ($ok ? '[OK] ' : '[ERROR] ') . $label . PHP_EOL;
    if ($ok) $passed++;
}
echo "Resultado: $passed/" . count($checks) . PHP_EOL;
exit($passed===count($checks)?0:1);
