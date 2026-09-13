<?php
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Solo CLI.\n");exit(1);}
require_once __DIR__ . '/../edusync/includes/predictive_interventions.php';

$features=[
    'grade_mean_current'=>11.5,
    'grade_mean_previous'=>13.0,
    'grade_trend'=>-1.5,
    'critical_records_current'=>4.0,
    'critical_courses_current'=>2.0,
    'attendance_rate_30d'=>82.0,
    'late_30d'=>5.0,
    'absent_30d'=>4.0,
];
$model=[
    'features'=>array_keys($features),
    'scaler'=>['mean'=>[],'scale'=>[]],
    'coefficients'=>[],
    'intercept'=>0.2,
    'risk_thresholds'=>['medium'=>0.40,'high'=>0.70]
];
foreach($features as $name=>$value){$model['scaler']['mean'][$name]=0.0;$model['scaler']['scale'][$name]=1.0;$model['coefficients'][$name]=0.0;}
$model['coefficients']['grade_mean_current']=-0.08;
$model['coefficients']['grade_trend']=-0.10;
$model['coefficients']['critical_records_current']=0.18;
$model['coefficients']['critical_courses_current']=0.25;
$model['coefficients']['attendance_rate_30d']=-0.018;
$model['coefficients']['late_30d']=0.08;
$model['coefficients']['absent_30d']=0.14;

$score=edu_predictive_score($features,$model);
$prediction=['available'=>true,'features'=>$features,'model'=>$model,'probability'=>$score['probability'],'level'=>$score['level'],'explanation'=>edu_predictive_explanation($score)];
$scenarios=edu_risk_counterfactual_scenarios($prediction);
if(!$scenarios){fwrite(STDERR,"FALLO: no se generaron escenarios.\n");exit(1);}
foreach($scenarios as $scenario){
    if($scenario['probability'] >= $prediction['probability']){fwrite(STDERR,"FALLO: un escenario no reduce la probabilidad.\n");exit(1);}
    if(empty($scenario['changes'])){fwrite(STDERR,"FALLO: escenario sin cambios explicables.\n");exit(1);}
}
echo "OK: escenarios contrafactuales generados: ".count($scenarios)."\n";
echo "Riesgo base: ".number_format($prediction['probability']*100,1)."%\n";
echo "Mejor escenario: ".$scenarios[0]['name']." -> ".number_format($scenarios[0]['probability']*100,1)."%\n";
