<?php
// Prueba independiente y SIN conexión a base de datos.
require_once __DIR__ . '/../edusync/includes/grade_closure_progress.php';

function checkProgress(string $name, array $data, float $overall, int $covered, float $coverage): void {
    if (abs($data['progress'] - $overall) > 0.05 ||
        $data['competencies_with_evaluations'] !== $covered ||
        abs($data['competency_coverage_progress'] - $coverage) > 0.05) {
        throw new RuntimeException(
            "$name: esperado $overall% y $covered competencias ($coverage%), obtenido " .
            json_encode($data, JSON_UNESCAPED_UNICODE)
        );
    }
    echo "[OK] $name\n";
}

$comps = [1=>['percentage'=>50], 2=>['percentage'=>30], 3=>['percentage'=>20]];
checkProgress('Dos competencias completas, tercera sin evaluar',
    gc_calculate_competency_progress(
        $comps, [1=>1,2=>1,3=>0],
        [1=>10,2=>10,3=>0], [1=>10,2=>10,3=>0], 20, 20),
    80.0, 2, 80.0);

checkProgress('Tres competencias con todas sus calificaciones',
    gc_calculate_competency_progress(
        $comps, [1=>1,2=>1,3=>1],
        [1=>10,2=>10,3=>10], [1=>10,2=>10,3=>10], 30, 30),
    100.0, 3, 100.0);

checkProgress('Tres competencias, 50% de notas en la segunda',
    gc_calculate_competency_progress(
        $comps, [1=>1,2=>1,3=>1],
        [1=>10,2=>10,3=>10], [1=>10,2=>5,3=>10], 30, 25),
    85.0, 3, 100.0);

checkProgress('Todas las competencias sin evaluaciones',
    gc_calculate_competency_progress(
        $comps, [1=>0,2=>0,3=>0],
        [1=>0,2=>0,3=>0], [1=>0,2=>0,3=>0], 0, 0),
    0.0, 0, 0.0);

checkProgress('Todos los cursos completos con distintos números de evaluaciones',
    gc_calculate_competency_progress(
        $comps, [1=>4,2=>1,3=>2],
        [1=>40,2=>10,3=>20], [1=>40,2=>10,3=>20], 70, 70),
    100.0, 3, 100.0);

// Pesos minúsculos y notas incompletas no deben redondearse a 100%.
$almost = [1=>['percentage'=>99.99],2=>['percentage'=>0.01]];
$result = gc_calculate_competency_progress(
    $almost, [1=>1,2=>1], [1=>1,2=>1000], [1=>1,2=>999], 1001, 1000);
if ($result['progress'] >= 100.0 || $result['grade_cells_complete']) {
    throw new RuntimeException('El redondeo nunca debe permitir cerrar con notas faltantes.');
}
echo "[OK] Incompletitud no redondea a 100%\n";

echo "6 escenarios correctos; no se modificó ninguna calificación.\n";
