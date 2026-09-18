<?php
/**
 * Diagnóstico temporal de la alerta predictiva.
 * Uso:
 *   php tools/predictive_period_status.php --school=1
 *   php tools/predictive_period_status.php --school=1 --year=3
 */
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Solo CLI.\n");exit(1);}
require_once __DIR__.'/../edusync/db_connect.php';
require_once __DIR__.'/../edusync/includes/predictive_risk.php';
$options=getopt('',['school:','year::']);
$schoolId=(int)($options['school']??0);$yearId=isset($options['year'])&&$options['year']!==false&&$options['year']!==''?(int)$options['year']:null;
if($schoolId<=0){fwrite(STDERR,"Falta --school=<ID>.\n");exit(1);}
$year=edu_predictive_academic_year($conn,$schoolId,$yearId);
if(!$year){fwrite(STDERR,"No se encontró el año académico.\n");exit(1);}
$yearId=(int)$year['id'];
echo "Año académico ID: $yearId\n";
echo "Política: solo un bimestre CERRADO puede ser base de predicción.\n\n";
foreach([1,2,3,4] as $b){
    $c=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$b);
    $state=!empty($c['closed'])?'CERRADO':'ABIERTO / EN PROCESO';
    $date=$c['date']??'sin fecha segura';
    echo edu_predictive_bimester_label($b)." bimestre: $state | corte: $date | fuente: ".($c['source']??'none')."\n";
}
$base=edu_predictive_latest_closed_bimester($conn,$schoolId,$yearId);
echo "\n";
if($base===null){echo "No existe todavía un bimestre cerrado elegible para predecir.\n";exit(0);}
$target=$base+1;
echo "BASE PARA ALERTA: ".edu_predictive_bimester_label($base)." bimestre cerrado\n";
echo "OBJETIVO: riesgo académico en ".edu_predictive_bimester_label($target)." bimestre\n";
$targetClosure=edu_predictive_bimester_closure($conn,$schoolId,$yearId,$target);
echo !empty($targetClosure['closed'])
    ? "El bimestre objetivo ya está cerrado: sirve para validación histórica.\n"
    : "El bimestre objetivo está abierto/en proceso: sirve para alerta en vivo, pero NO como resultado histórico de entrenamiento.\n";
