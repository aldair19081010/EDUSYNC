<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../edusync/includes/predictive_risk.php';

$model = [
    'intercept' => 0.0,
    'features' => ['critical_courses_current','absent_30d'],
    'scaler' => [
        'mean' => ['critical_courses_current'=>0.0,'absent_30d'=>0.0],
        'scale' => ['critical_courses_current'=>1.0,'absent_30d'=>1.0],
    ],
    'coefficients' => ['critical_courses_current'=>1.0,'absent_30d'=>0.5],
    'risk_thresholds' => ['medium'=>0.40,'high'=>0.70],
];

$base = edu_predictive_score(['critical_courses_current'=>0.0,'absent_30d'=>0.0], $model);
$high = edu_predictive_score(['critical_courses_current'=>2.0,'absent_30d'=>3.0], $model);

$ok = abs($base['probability'] - 0.5) < 0.000001
    && $base['level'] === 'Medio'
    && $high['probability'] > 0.90
    && $high['level'] === 'Alto';

if (!$ok) {
    fwrite(STDERR, "FALLÓ la prueba del motor predictivo.\n");
    var_export(['base'=>$base,'high'=>$high]);
    exit(1);
}

echo "OK: motor predictivo PHP\n";
echo 'Probabilidad base: ' . number_format($base['probability'] * 100, 2) . "%\n";
echo 'Probabilidad alta: ' . number_format($high['probability'] * 100, 2) . "%\n";
