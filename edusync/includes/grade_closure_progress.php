<?php
/**
 * Avance real de cierre por competencia. Una competencia sin evaluaciones
 * cuenta como 0%, aun cuando todas las demás evaluaciones estén calificadas.
 * Función pura: NO escribe datos ni modifica el libro.
 */
function gc_calculate_competency_progress(
    array $competencies,
    array $evaluationsPerCompetency,
    array $expectedPerCompetency,
    array $completedPerCompetency,
    int $expectedGradeCells,
    int $completedGradeCells
): array {
    $competencyCount = count($competencies);
    $coveredCount = 0;
    $weightTotal = 0.0;
    $coveredWeight = 0.0;
    $weightedCompleted = 0.0;

    foreach ($competencies as $id => $competency) {
        $id = (int)$id;
        $weight = max(0.0, (float)($competency['percentage'] ?? 0));
        $weightTotal += $weight;
        $hasEvaluation = (int)($evaluationsPerCompetency[$id] ?? 0) > 0;
        if (!$hasEvaluation) continue; // Aporta 0% hasta tener evaluación.
        $coveredCount++;
        $coveredWeight += $weight;
        $expected = (int)($expectedPerCompetency[$id] ?? 0);
        $completed = (int)($completedPerCompetency[$id] ?? 0);
        if ($expected > 0) {
            $weightedCompleted += $weight * min(1.0, max(0.0, $completed / $expected));
        }
    }

    $coverageProgress = $weightTotal > 0
        ? round(100.0 * $coveredWeight / $weightTotal, 1) : 0.0;
    $gradeProgress = $expectedGradeCells > 0
        ? round(100.0 * $completedGradeCells / $expectedGradeCells, 1) : 0.0;
    $overallProgress = $weightTotal > 0
        ? round(100.0 * $weightedCompleted / $weightTotal, 1) : 0.0;

    // Nunca anunciar 100.0% si falta una competencia, un alumno, o no
    // existen celdas previstas (también protege del redondeo a 1 decimal).
    $complete = $competencyCount > 0 &&
        $coveredCount === $competencyCount &&
        $expectedGradeCells > 0 &&
        $completedGradeCells === $expectedGradeCells;
    if (!$complete) $overallProgress = min(99.9, $overallProgress);

    return [
        'progress' => $overallProgress,
        'grade_progress' => $gradeProgress,
        'competency_coverage_progress' => $coverageProgress,
        'competencies_with_evaluations' => $coveredCount,
        'competencies_without_evaluations' => $competencyCount - $coveredCount,
        'competencies' => $competencyCount,
        'grade_cells_complete' => $complete,
    ];
}
