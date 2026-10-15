<?php
/**
 * Filtrado defensivo para nóminas históricas del Reporte de notas.
 *
 * La columna student.nivel es la matrícula ACTUAL; no debe usarse para
 * decidir a qué nivel pertenecía un estudiante en un año anterior.
 *
 * En cambio se exige consistencia de nivel con teacher_courses.level
 * (snapshot del curso asignado en ese año), y, si existe historial de
 * matrícula real, se verifica que haya un registro del mismo nivel/año.
 * No se confían matrículas inferidas con change_type=import, porque
 * pueden contener el nivel actual aplicado retroactivamente.
 */
if (!function_exists('grhl_has_student_history')) {
    function grhl_has_student_history(mysqli $db): bool {
        static $tableExists = null;
        if ($tableExists === null) {
            $r = $db->query("SHOW TABLES LIKE 'student_academic_history'");
            $tableExists = $r && $r->num_rows > 0;
            if ($r) $r->free();
        }
        return $tableExists;
    }
}
if (!function_exists('grhl_sql')) {
    function grhl_sql(mysqli $db, string $studentAlias='s',
                      string $assignmentAlias='tc', string $courseAlias='ac'): string {
        foreach ([$studentAlias,$assignmentAlias,$courseAlias] as $alias) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $alias)) {
                throw new InvalidArgumentException('Alias SQL inválido.');
            }
        }
        $student=$studentAlias;
        $assignment=$assignmentAlias;
        $course=$courseAlias;
        $normAssignment="LOWER(REPLACE(TRIM($assignment.level),' ',''))";
        $normCourse="LOWER(REPLACE(TRIM($course.level),' ',''))";
        // Evita mezclar asignaciones de otro nivel aunque compartan
        // grado, sección, nombre de curso o datos actuales del alumno.
        $guard=" AND (NULLIF(TRIM($assignment.level),'') IS NULL OR $normAssignment=$normCourse)";
        if (!grhl_has_student_history($db)) return $guard;
        $normHistory="LOWER(REPLACE(TRIM(sah.nivel),' ',''))";
        $relation="sah.student_id=$student.id AND sah.school_id=$student.school_id ".
            "AND sah.academic_year_id=$assignment.academic_year_id ".
            "AND sah.change_type IN ('initial','update','bulk')";
        // Cuando haya matrícula real del año, la pertenencia histórica
        // debe coincidir con el nivel seleccionado. Si no hay historial
        // verificado, se mantiene la evidencia de evaluaciones antiguas.
        return $guard." AND (NOT EXISTS (SELECT 1 FROM student_academic_history sah WHERE $relation)".
            " OR EXISTS (SELECT 1 FROM student_academic_history sah WHERE $relation".
            " AND $normHistory=$normCourse))";
    }
}
