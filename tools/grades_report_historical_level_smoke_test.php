<?php
/**
 * php tools/grades_report_historical_level_smoke_test.php
 * No necesita MySQL. Comprueba la clasificación que comparten lista,
 * detalle, impresión y Excel.
 */
require_once __DIR__.'/../edusync/includes/grades_report_historical_level.php';

$examples=[
    // Un estudiante puede estar HOY en otro nivel; domina su año histórico.
    [['primaria'=>12,'secundaria'=>1],'','secundaria','primaria'],
    [['secundaria'=>9,'primaria'=>2],'','primaria','secundaria'],
    // Matrícula real del año prevalece sobre evaluaciones erróneas.
    [['primaria'=>2,'secundaria'=>6],'Primaria','','primaria'],
    [['primaria'=>5,'secundaria'=>1],'Secundaria','','secundaria'],
    // Si no hay pruebas suficientes no se atribuye a dos niveles.
    [['primaria'=>3,'secundaria'=>3],'','',''],
    [[], '','Primaria','primaria'],
    [[], '', '',''],
    [['Primaria'=>2,'primaria'=>2,'secundaria'=>3],'','','primaria'],
];
foreach ($examples as $i=>$example) {
    [$counts,$document,$enrolledYear,$expected]=$example;
    $actual=grhl_choose_level($counts,$document,$enrolledYear);
    if ($actual!==$expected) {
        fwrite(STDERR,"CASO ".($i+1)." FALLÓ: se esperaba '$expected', salió '$actual'\n");
        exit(1);
    }
}
echo "OK: ".count($examples)." escenarios de nivel histórico. La consulta con datos reales requiere MySQL.\n";
