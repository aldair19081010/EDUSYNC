<?php
/**
 * Matrícula histórica de aula (nivel + grado + sección).
 *
 * Las notas prueban un vínculo con una evaluación, no la matrícula en el aula
 * de esa evaluación: importaciones antiguas pueden mezclar alumnos.
 * Fuentes por prioridad:
 *  1. Última matrícula documentada del año (sin imports inferidos).
 *  2. Aula con más cursos DISTINTOS calificados ese año, entre todas las aulas.
 *     Si hay empate, no asignar aula (no usar cantidad de notas para desempatar).
 *  3. Ficha del estudiante sólo si su academic_year_id es el año consultado.
 *
 * La evidencia de nivel se valida también con grades_report_historical_level.
 * Esta resolución es exclusiva para consultas, nunca cambia matrículas/notas.
 */
require_once __DIR__.'/grades_report_historical_level.php';

if (!function_exists('grhc_level')) {
    function grhc_level($value): string {
        return mb_strtolower(preg_replace('/[[:space:]]+/u','',trim((string)$value)),'UTF-8');
    }
}
if (!function_exists('grhc_grade')) {
    function grhc_grade($value): string {
        return mb_strtolower(preg_replace('/[°º[:space:]]+/u','',trim((string)$value)),'UTF-8');
    }
}
if (!function_exists('grhc_section')) {
    function grhc_section($value): string {
        $key=grhc_level($value);
        return in_array($key,['','u','unica','única'],true) ? 'u' : $key;
    }
}
if (!function_exists('grhc_key')) {
    function grhc_key($level,$grade,$section): string {
        $lvl=grhc_level($level);
        $gr=grhc_grade($grade);
        if ($lvl==='' || $gr==='') return '';
        return $lvl.'|'.$gr.'|'.grhc_section($section);
    }
}
if (!function_exists('grhc_choose_class')) {
    /** Función pura para probar que una nota aislada nunca mezcla aulas. */
    function grhc_choose_class(array $courseCounts,string $documented='',string $sameYear=''): string {
        if ($documented!=='') return $documented;
        $winner='';
        $max=0;
        $tie=false;
        foreach ($courseCounts as $key=>$count) {
            $count=(int)$count;
            if ($count<=0 || $key==='') continue;
            if ($count>$max) {
                $winner=(string)$key;
                $max=$count;
                $tie=false;
            } elseif ($count===$max) {
                $tie=true;
            }
        }
        // Si las notas contradicen la pertenencia pero existe una ficha
        // vinculada a ESTE MISMO año, usarla sólo si es una de las aulas
        // candidatas. Sin evidencia suficiente, dejar sin aula.
        if ($max>0 && $tie) return isset($courseCounts[$sameYear]) ? $sameYear : '';
        if ($max>0) return $winner;
        return $sameYear;
    }
}
if (!function_exists('grhc_student_classrooms')) {
    function grhc_student_classrooms(mysqli $db,int $schoolId,int $yearId): array {
        static $cache=[];
        $cacheKey=$schoolId.':'.$yearId;
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        if ($schoolId<=0 || $yearId<=0) return [];

        // Mismo criterio de pertenencia al nivel usado por el reporte web.
        $levels=grhl_student_levels($db,$schoolId,$yearId);
        if (!$levels) return $cache[$cacheKey]=[];

        $documented=[];
        if (grhl_table_exists($db,'student_academic_history')) {
            $stmt=$db->prepare("SELECT student_id,nivel,grado,seccion
                FROM student_academic_history
                WHERE school_id=? AND academic_year_id=?
                  AND change_type IN ('initial','update','bulk')
                ORDER BY student_id,id DESC");
            if ($stmt) {
                $stmt->bind_param('ii',$schoolId,$yearId);
                $stmt->execute();
                foreach ($stmt->get_result() as $row) {
                    $sid=(int)$row['student_id'];
                    if (array_key_exists($sid,$documented)) continue;
                    $key=grhc_key($row['nivel'],$row['grado'],$row['seccion']);
                    // Conservar el último registro válido. Una actualización
                    // sin grado no debe borrar la última matrícula conocida.
                    if ($key!=='') $documented[$sid]=$key;
                }
                $stmt->close();
            }
        }

        // Contar cursos distintos, no filas de nota: un solo curso con diez
        // evaluaciones no demuestra pertenencia a otro salón.
        $counts=[];
        $stmt=$db->prepare("SELECT DISTINCT eg.student_id,ac.level,tc.grado,tc.seccion,
                         tc.course_id
            FROM evaluation_grades eg
            INNER JOIN student st ON st.id=eg.student_id AND st.school_id=?
            INNER JOIN evaluations ev ON ev.id=eg.evaluation_id
            INNER JOIN teacher_courses tc ON tc.id=ev.teacher_course_id
                AND tc.school_id=st.school_id AND tc.academic_year_id=?
            INNER JOIN academic_courses ac ON ac.id=tc.course_id
                AND ac.school_id=tc.school_id
            WHERE eg.grade IS NOT NULL AND TRIM(eg.grade)<>''
              AND (NULLIF(TRIM(tc.level),'') IS NULL
                   OR LOWER(REPLACE(TRIM(tc.level),' ',''))
                    = LOWER(REPLACE(TRIM(ac.level),' ','')))
            ORDER BY eg.student_id,tc.course_id");
        if (!$stmt) {
            error_log('[historical classroom] courses SQL: '.$db->error);
            return $cache[$cacheKey]=[];
        }
        $stmt->bind_param('ii',$schoolId,$yearId);
        $stmt->execute();
        foreach ($stmt->get_result() as $row) {
            $sid=(int)$row['student_id'];
            $key=grhc_key($row['level'],$row['grado'],$row['seccion']);
            if ($key==='') continue;
            $courseId=(int)$row['course_id'];
            if ($courseId>0) $counts[$sid][$key][$courseId]=true;
        }
        $stmt->close();

        $sameYear=[];
        if (grhl_has_column($db,'student','academic_year_id')) {
            $stmt=$db->prepare("SELECT id,nivel,grado,seccion FROM student
                WHERE school_id=? AND academic_year_id=?");
            if ($stmt) {
                $stmt->bind_param('ii',$schoolId,$yearId);
                $stmt->execute();
                foreach ($stmt->get_result() as $row) {
                    $sameYear[(int)$row['id']]=grhc_key($row['nivel'],$row['grado'],$row['seccion']);
                }
                $stmt->close();
            }
        }

        $classrooms=[];
        foreach ($levels as $sid=>$level) {
            $sid=(int)$sid;
            $level=grhc_level($level);
            $documentedClass=$documented[$sid]??'';
            $snapshot=$sameYear[$sid]??'';
            // Los registros contradictorios no justifican asignar otra aula.
            if ($documentedClass!=='' && explode('|',$documentedClass)[0]!==$level) continue;
            if ($snapshot!=='' && explode('|',$snapshot)[0]!==$level) $snapshot='';
            $inLevel=[];
            foreach ($counts[$sid]??[] as $key=>$courseIds) {
                if (explode('|',$key)[0]===$level) {
                    $inLevel[$key]=count($courseIds);
                }
            }
            $class=grhc_choose_class($inLevel,$documentedClass,$snapshot);
            if ($class!=='' && explode('|',$class)[0]===$level) $classrooms[$sid]=$class;
        }
        return $cache[$cacheKey]=$classrooms;
    }
}
if (!function_exists('grhc_sql')) {
    function grhc_sql(mysqli $db,string $studentAlias,string $assignmentAlias,
                      string $courseAlias,int $schoolId,int $yearId,
                      string $level,string $grade,string $section): string {
        // No interpolar nombres de columnas ni alias sin validarlos.
        foreach ([$studentAlias,$assignmentAlias,$courseAlias] as $alias) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D',$alias)) {
                throw new InvalidArgumentException('Alias SQL inválido.');
            }
        }
        $classKey=grhc_key($level,$grade,$section);
        if ($classKey==='' || $schoolId<=0 || $yearId<=0) return ' AND 1=0';
        $resolved=grhc_student_classrooms($db,$schoolId,$yearId);
        $ids=[];
        foreach ($resolved as $sid=>$key) {
            if ($key===$classKey) $ids[]=(int)$sid;
        }
        if (!$ids) return ' AND 1=0';
        $gradeEsc=$db->real_escape_string(grhc_grade($grade));
        $sectionEsc=$db->real_escape_string(grhc_section($section));
        // Impedir que una asignación mal etiquetada mueva un alumno de aula.
        $assignmentGrade="LOWER(REPLACE(REPLACE(REPLACE(TRIM($assignmentAlias.grado),'°',''),'º',''),' ',''))";
        $assignmentSection="LOWER(REPLACE(TRIM(COALESCE($assignmentAlias.seccion,'')),' ',''))";
        $base=grhl_sql($db,$studentAlias,$assignmentAlias,$courseAlias,$schoolId,$yearId,$level);
        return $base.
            " AND $assignmentGrade='$gradeEsc'".
            " AND $assignmentSection IN (".
            ($sectionEsc==='u' ? "'','u','unica','única'" : "'$sectionEsc'").")".
            " AND $studentAlias.id IN (".implode(',',$ids).")";
    }
}
