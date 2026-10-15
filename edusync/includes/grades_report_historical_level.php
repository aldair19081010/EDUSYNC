<?php
/**
 * Nivel académico real por alumno/año para consultas HISTÓRICAS de notas.
 *
 * "evaluation_grades" demuestra una relación con una evaluación, pero NO
 * demuestra que el alumno pertenezca al nivel de esa evaluación. Datos
 * migrados o evaluaciones antiguas pueden mezclar Primaria/Secundaria.
 *
 * Prioridad:
 * 1. Última matrícula real de student_academic_history en el año (no la
 *    migración inferida "import" que reutiliza el nivel actual).
 * 2. Nivel con más evaluaciones calificadas en ese año, considerando todos
 *    los niveles; en empate entre niveles, el alumno queda indeterminado.
 * 3. Nivel de student sólo si student.academic_year_id coincide con el año.
 *
 * Nunca utilizar student.nivel actual para un año pasado sin comprobar año.
 * Un alumno con nivel indeterminado NO debe mostrarse en dos niveles.
 */
if (!function_exists('grhl_norm')) {
    function grhl_norm($value): string {
        return strtolower(preg_replace('/\s+/u', '', trim((string)$value)));
    }
}
if (!function_exists('grhl_choose_level')) {
    // Función pura, accesible a las pruebas sin base de datos.
    function grhl_choose_level(array $gradeCounts, string $documentedLevel='',
                               string $yearEnrollmentLevel=''): string {
        $documented = grhl_norm($documentedLevel);
        if ($documented !== '') return $documented;
        $totals = [];
        foreach ($gradeCounts as $level=>$count) {
            $key = grhl_norm($level);
            if ($key !== '' && (int)$count > 0) {
                $totals[$key] = ($totals[$key] ?? 0) + (int)$count;
            }
        }
        if ($totals) {
            $max = max($totals);
            $leaders = array_keys(array_filter($totals, static function($n) use($max) {
                return $n === $max;
            }));
            // No elegir arbitrariamente un nivel cuando el historial de
            // calificaciones tiene la misma evidencia para varios.
            return count($leaders) === 1 ? $leaders[0] : '';
        }
        return grhl_norm($yearEnrollmentLevel);
    }
}
if (!function_exists('grhl_table_exists')) {
    function grhl_table_exists(mysqli $db,string $table): bool {
        static $seen = [];
        if (!preg_match('/^[a-z_]+$/D', $table)) return false;
        if (!array_key_exists($table,$seen)) {
            $r = $db->query("SHOW TABLES LIKE '".$table."'");
            $seen[$table] = $r && $r->num_rows > 0;
            if ($r) $r->free();
        }
        return $seen[$table];
    }
}
if (!function_exists('grhl_has_column')) {
    function grhl_has_column(mysqli $db,string $table,string $column): bool {
        static $seen = [];
        if (!preg_match('/^[a-z_]+$/D',$table) ||
            !preg_match('/^[a-z_]+$/D',$column)) return false;
        $key=$table.'.'.$column;
        if (!array_key_exists($key,$seen)) {
            $r=$db->query("SHOW COLUMNS FROM $table LIKE '$column'");
            $seen[$key]=$r && $r->num_rows > 0;
            if ($r) $r->free();
        }
        return $seen[$key];
    }
}
if (!function_exists('grhl_student_levels')) {
    function grhl_student_levels(mysqli $db,int $schoolId,int $yearId): array {
        static $cache=[];
        $cacheKey=$schoolId.':'.$yearId;
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        if ($schoolId<=0 || $yearId<=0) return [];

        // Historial explícito. Se toma el último cambio registrado ese año.
        $documented=[];
        if (grhl_table_exists($db,'student_academic_history')) {
            $q=$db->prepare("SELECT student_id,nivel FROM student_academic_history
                WHERE school_id=? AND academic_year_id=?
                  AND change_type IN ('initial','update','bulk')
                ORDER BY student_id ASC,id DESC");
            if (!$q) {
                error_log('[grades historical roster] history SQL: '.$db->error);
                return $cache[$cacheKey]=[];
            }
            $q->bind_param('ii',$schoolId,$yearId);
            $q->execute();
            $rows=$q->get_result();
            while ($row=$rows->fetch_assoc()) {
                $sid=(int)$row['student_id'];
                if (!array_key_exists($sid,$documented)) {
                    $documented[$sid]=(string)$row['nivel'];
                }
            }
            $q->close();
        }

        // Agrupar calificaciones del año en TODOS los niveles para decidir
        // la pertenencia cuando no existe una matrícula documentada.
        $gradeCounts=[];
        $sql="SELECT eg.student_id,ac.level,
                 COUNT(DISTINCT eg.evaluation_id) total
              FROM evaluation_grades eg
              INNER JOIN student s ON s.id=eg.student_id AND s.school_id=?
              INNER JOIN evaluations e ON e.id=eg.evaluation_id
              INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
                   AND tc.school_id=s.school_id AND tc.academic_year_id=?
              INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
              WHERE eg.grade IS NOT NULL AND TRIM(eg.grade)<>''
                AND (NULLIF(TRIM(tc.level),'') IS NULL
                     OR LOWER(REPLACE(TRIM(tc.level),' ',''))
                      = LOWER(REPLACE(TRIM(ac.level),' ','')))
              GROUP BY eg.student_id,ac.level";
        $q=$db->prepare($sql);
        if (!$q) {
            error_log('[grades historical roster] grades SQL: '.$db->error);
            return $cache[$cacheKey]=[];
        }
        $q->bind_param('ii',$schoolId,$yearId);
        $q->execute();
        $rows=$q->get_result();
        while ($row=$rows->fetch_assoc()) {
            $sid=(int)$row['student_id'];
            $key=grhl_norm($row['level']);
            if ($key !== '') {
                $gradeCounts[$sid][$key]=
                    ($gradeCounts[$sid][$key]??0)+(int)$row['total'];
            }
        }
        $q->close();

        // La fila actual solamente puede actuar como respaldo si su año
        // corresponde EXACTAMENTE al consultado.
        $sameYear=[];
        if (grhl_has_column($db,'student','academic_year_id')) {
            $q=$db->prepare("SELECT id,nivel FROM student
                WHERE school_id=? AND academic_year_id=?");
            if ($q) {
                $q->bind_param('ii',$schoolId,$yearId);
                $q->execute();
                $rows=$q->get_result();
                while ($row=$rows->fetch_assoc()) {
                    $sameYear[(int)$row['id']]=(string)$row['nivel'];
                }
                $q->close();
            }
        }

        $studentIds=array_unique(array_merge(array_keys($documented),
            array_keys($gradeCounts),array_keys($sameYear)));
        $levels=[];
        foreach ($studentIds as $sid) {
            $sid=(int)$sid;
            $level=grhl_choose_level($gradeCounts[$sid]??[],
                $documented[$sid]??'', $sameYear[$sid]??'');
            if ($level !== '') $levels[$sid]=$level;
        }
        return $cache[$cacheKey]=$levels;
    }
}
if (!function_exists('grhl_sql')) {
    function grhl_sql(mysqli $db,string $studentAlias='s',
                      string $assignmentAlias='tc',string $courseAlias='ac',
                      int $schoolId=0,int $yearId=0,string $selectedLevel=''): string {
        foreach ([$studentAlias,$assignmentAlias,$courseAlias] as $alias) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $alias)) {
                throw new InvalidArgumentException('Alias SQL inválido.');
            }
        }
        // Aplicar estrictamente la misma nómina a listas, tablas,
        // impresión y Excel. Si no hay evidencia suficiente, no mezclar.
        $norm=grhl_norm($selectedLevel);
        if ($norm==='' || $schoolId<=0 || $yearId<=0) return ' AND 1=0';
        $levels=grhl_student_levels($db,$schoolId,$yearId);
        $ids=[];
        foreach ($levels as $sid=>$level) {
            if ($level===$norm) $ids[]=(int)$sid;
        }
        if (!$ids) return ' AND 1=0';

        $levelOfAssignment="LOWER(REPLACE(TRIM($assignmentAlias.level),' ',''))";
        $levelOfCourse="LOWER(REPLACE(TRIM($courseAlias.level),' ',''))";
        return " AND (NULLIF(TRIM($assignmentAlias.level),'') IS NULL ".
               "OR $levelOfAssignment=$levelOfCourse)".
               " AND $studentAlias.id IN (".implode(',',$ids).")";
    }
}
