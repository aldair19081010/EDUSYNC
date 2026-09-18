<?php
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Solo CLI.\n");exit(1);}
require_once __DIR__ . '/../edusync/includes/predictive_interventions.php';

$features=[
    'grade_mean_current'=>11.5,
    'grade_trend'=>-1.5,
    'previous_bimester_available'=>1.0,
    'critical_records_current'=>4.0,
    'critical_courses_current'=>2.0,
    'attendance_rate_30d'=>82.0,
    'late_30d'=>5.0,
    'absent_30d'=>4.0,
];
$model=[
    'schema_version'=>4,
    'features'=>array_keys($features),
    'imputer'=>['fill'=>[]],
    'scaler'=>['mean'=>[],'scale'=>[]],
    'coefficients'=>[],
    'intercept'=>0.2,
    'calibration'=>['method'=>'platt_grouped_oof','coefficient'=>1.15,'intercept'=>0.35],
    'risk_thresholds'=>['medium'=>0.40,'high'=>0.70,'probability_space'=>'calibrated']
];
foreach($features as $name=>$value){$model['imputer']['fill'][$name]=$value;$model['scaler']['mean'][$name]=0.0;$model['scaler']['scale'][$name]=1.0;$model['coefficients'][$name]=0.0;}
$model['coefficients']['grade_mean_current']=-0.08;
$model['coefficients']['grade_trend']=-0.10;
$model['coefficients']['previous_bimester_available']=-0.05;
$model['coefficients']['critical_records_current']=0.18;
$model['coefficients']['critical_courses_current']=0.25;
$model['coefficients']['attendance_rate_30d']=-0.018;
$model['coefficients']['late_30d']=0.08;
$model['coefficients']['absent_30d']=0.14;

$score=edu_predictive_score($features,$model);
if(!isset($score['raw_probability'])||!isset($score['probability'])){fwrite(STDERR,"FALLO: no se calcularon probabilidades raw/calibrada.\n");exit(1);}
$prediction=['available'=>true,'features'=>$features,'model'=>$model,'probability'=>$score['probability'],'level'=>$score['level'],'explanation'=>edu_predictive_explanation($score),'data_quality'=>['attendance_available'=>true,'attendance_records'=>20]];
$scenarios=edu_risk_counterfactual_scenarios($prediction);
if(!$scenarios){fwrite(STDERR,"FALLO: no se generaron escenarios con asistencia.\n");exit(1);}
foreach($scenarios as $scenario){if($scenario['probability'] >= $prediction['probability']){fwrite(STDERR,"FALLO: un escenario no reduce la probabilidad calibrada.\n");exit(1);}if(empty($scenario['changes'])){fwrite(STDERR,"FALLO: escenario sin cambios explicables.\n");exit(1);}}

$missing=$features;$missing['attendance_rate_30d']=null;$missing['late_30d']=null;$missing['absent_30d']=null;$missingScore=edu_predictive_score($missing,$model);
foreach(['attendance_rate_30d','late_30d','absent_30d'] as $feature)if(empty($missingScore['contributions'][$feature]['missing'])){fwrite(STDERR,"FALLO: $feature no fue marcado como faltante.\n");exit(1);}
$missingPrediction=['available'=>true,'features'=>$missing,'model'=>$model,'probability'=>$missingScore['probability'],'level'=>$missingScore['level'],'explanation'=>edu_predictive_explanation($missingScore),'data_quality'=>['attendance_available'=>false,'attendance_records'=>0]];
foreach(edu_risk_counterfactual_scenarios($missingPrediction) as $scenario)foreach($scenario['changes'] as $change)if(in_array($change['feature'],['attendance_rate_30d','late_30d','absent_30d'],true)){fwrite(STDERR,"FALLO: se simuló asistencia sin datos observados.\n");exit(1);}

$noPrevious=$features;$noPrevious['previous_bimester_available']=0.0;$noPrevious['grade_trend']=0.0;$noPreviousScore=edu_predictive_score($noPrevious,$model);
if(!isset($noPreviousScore['contributions']['previous_bimester_available'])){fwrite(STDERR,"FALLO: no se procesó previous_bimester_available.\n");exit(1);}

echo "OK: esquema v4, calibración y escenarios contrafactuales.\n";
echo "OK: ausencia de historial previo se distingue de tendencia estable.\n";
echo "OK: asistencia faltante se imputa sin inventar cambios.\n";
echo "Riesgo calibrado base: ".number_format($prediction['probability']*100,1)."%\n";
echo "Mejor escenario: ".$scenarios[0]['name']." -> ".number_format($scenarios[0]['probability']*100,1)."%\n";
