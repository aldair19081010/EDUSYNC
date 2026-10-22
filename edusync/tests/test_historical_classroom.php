<?php
/** Pruebas sin MySQL: php tests/test_historical_classroom.php */
require_once __DIR__.'/../includes/grades_report_historical_classroom.php';

function expect_class($actual,$expected,$label) {
    if ($actual!==$expected) {
        throw new RuntimeException($label.': esperado '.var_export($expected,true).
            ', recibido '.var_export($actual,true));
    }
}

$a='primaria|3|a';
$b='primaria|3|b';
$c='secundaria|1|u';

expect_class(grhc_key('Primaria','3°',' A '),$a,'Normalizar grado/sección');
expect_class(grhc_key('Secundaria','1º','Única'),$c,'Normalizar única');
expect_class(grhc_key('Primaria','','A'),'','Sin grado conocido');
expect_class(grhc_choose_class([$a=>4,$b=>1],$b),$b,'Matrícula documentada prevalece');
expect_class(grhc_choose_class([$a=>4,$b=>1]),$a,'Aula con más cursos');
expect_class(grhc_choose_class([$a=>1,$b=>1]),'','Empate de evidencia no mezcla');
expect_class(grhc_choose_class([$a=>1,$b=>1],'',$b),$b,'Ficha del mismo año resuelve empate');
expect_class(grhc_choose_class([$a=>1,$b=>1],'','secundaria|2|u'),'','Ficha ajena al empate');
expect_class(grhc_choose_class([],'',$a),$a,'Respaldo año coincidente');
expect_class(grhc_choose_class([]),'','Sin evidencia no se inventa aula');
echo "OK: 10 pruebas de aula histórica.\n";
