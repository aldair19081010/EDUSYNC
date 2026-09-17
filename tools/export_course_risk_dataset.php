<?php
/**
 * Punto de entrada del exportador v6.
 * La implementación optimizada carga datos por bimestre en bloques para evitar
 * miles de consultas repetidas por estudiante y curso.
 */
require __DIR__.'/export_course_risk_dataset_fast.php';
