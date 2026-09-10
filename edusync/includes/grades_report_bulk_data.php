<?php

function grbd_norm($value) {
    $value = mb_strtolower(trim((string)$value), 'UTF-8');
    return preg_replace('/[°º\s]+/u', '', $value) ?: $value;
}

function grbd_section($value) {
    $value = grbd_norm($value);
    return in_array($value, ['', 'u', 'unica', 'única'], true) ? 'u' : $value;
}

function grbd_numeric($grade) {
    if ($grade === null) return null;
    $text = strtoupper(trim((string)$grade));
    if ($text === '') return null;
    $map = ['C' => 5.0, 'B' => 12.0, 'A' => 15.5, 'AD' => 19.0];
    if (isset($map[$text])) return $map[$text];
    return is_numeric($text) ? (float)$text : null;
}

function grbd_letter($value) {
    if ($value === null) return '-';
    $rounded = (int)round((float)$value);
    if ($rounded >= 18) return 'AD';
    if ($rounded >= 14) return 'A';
    if ($rounded >= 11) return 'B';
    return 'C';
}

function grbd_display_raw($grade, $format) {
    $num = grbd_numeric($grade);
    if ($num === null) return '-';
    return $format === 'letters' ? grbd_letter($num) : (string)((int)round($num));
}

function grbd_display_avg($value, $format) {
    if ($value === null) return '-';
    return $format === 'letters' ? grbd_letter($value) : (string)((int)round((float)$value));
}

function grbd_auto_format(array $grades) {
    foreach ($grades as $studentGrades) {
        foreach ($studentGrades as $evalGrades) {
            foreach ($evalGrades as $grade) {
                if (in_array(strtoupper(trim((string)$grade)), ['C', 'B', 'A', 'AD'], true)) {
                    return 'letters';
                }
            }
        }
    }
    return 'numeric';
}

function grbd_grade_for(array $grades, $studentId, $evaluationId, $competencyId) {
    if (isset($grades[$studentId][$evaluationId][$competencyId])) {
        return $grades[$studentId][$evaluationId][$competencyId];
    }
    if (isset($grades[$studentId][$evaluationId][0])) {
        return $grades[$studentId][$evaluationId][0];
    }
    return null;
}

function grbd_course_result(array $courseCompetencies, array $grades, $studentId) {
    $weighted = 0.0;
    $hasAny = false;
    foreach ($courseCompetencies as $competencyId => $competency) {
        $values = [];
        foreach ($competency['evaluations'] as $evaluation) {
            $raw = grbd_grade_for($grades, $studentId, $evaluation['id'], $competencyId);
            $num = grbd_numeric($raw);
            if ($num !== null) $values[] = $num;
        }
        if ($values) {
            $avg = array_sum($values) / count($values);
            $weighted += $avg * ((float)$competency['percentage'] / 100);
            $hasAny = true;
        }
    }
    return $hasAny ? $weighted : null;
}

function grbd_build(mysqli $conn, array $filters, array $access) {
    $schoolId = (int)$access['school_id'];
    $yearId = (int)$filters['academic_year_id'];
    $level = trim((string)$filters['level']);
    $grade = trim((string)$filters['grado']);
    $section = trim((string)$filters['seccion']);
    $bimester = (int)$filters['bimestre'];
    $courseId = (int)($filters['course_id'] ?? 0);
    $teacherId = (int)($access['teacher_id'] ?? 0);
    $isInstitutional = !empty($access['institutional']);

    if ($schoolId <= 0 || $yearId <= 0 || $level === '' || $grade === '' || $section === '' || $bimester < 1 || $bimester > 4) {
        throw new RuntimeException('Debe seleccionar año, nivel, grado, sección y bimestre.');
    }

    $yearStmt = $conn->prepare('SELECT year FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
    $yearStmt->bind_param('ii', $yearId, $schoolId);
    $yearStmt->execute();
    $year = $yearStmt->get_result()->fetch_assoc();
    $yearStmt->close();
    if (!$year) throw new RuntimeException('El año académico seleccionado no pertenece a la institución.');

    $sql = "SELECT tc.id teacher_course_id, tc.teacher_id, tc.course_id, tc.grado,
                   COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,
                   ac.name course_name, ac.level
            FROM teacher_courses tc
            INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
            WHERE tc.school_id=? AND tc.academic_year_id=?";
    $params = [$schoolId, $yearId];
    $types = 'ii';
    if (!$isInstitutional) {
        $sql .= ' AND tc.teacher_id=?';
        $params[] = $teacherId;
        $types .= 'i';
    }
    if ($courseId > 0) {
        $sql .= ' AND tc.course_id=?';
        $params[] = $courseId;
        $types .= 'i';
    }
    $sql .= ' ORDER BY ac.name, tc.id';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignments = [];
    $courses = [];
    while ($row = $result->fetch_assoc()) {
        if (grbd_norm($row['level']) !== grbd_norm($level)) continue;
        if (grbd_norm($row['grado']) !== grbd_norm($grade)) continue;
        if (grbd_section($row['seccion']) !== grbd_section($section)) continue;
        $tcId = (int)$row['teacher_course_id'];
        $cid = (int)$row['course_id'];
        $assignments[$tcId] = $row;
        if (!isset($courses[$cid])) {
            $courses[$cid] = [
                'id' => $cid,
                'name' => (string)$row['course_name'],
                'teacher_courses' => []
            ];
        }
        $courses[$cid]['teacher_courses'][$tcId] = true;
    }
    $stmt->close();

    if (!$courses) throw new RuntimeException('No se encontraron cursos asignados para el aula seleccionada.');
    uasort($courses, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

    $students = [];
    $studentStmt = $conn->prepare("SELECT id,name,id_no,nivel,grado,COALESCE(NULLIF(TRIM(seccion),''),'U') seccion
                                  FROM student
                                  WHERE school_id=? AND (status='Activo' OR status='1' OR status IS NULL)
                                  ORDER BY name ASC");
    $studentStmt->bind_param('i', $schoolId);
    $studentStmt->execute();
    $studentResult = $studentStmt->get_result();
    while ($row = $studentResult->fetch_assoc()) {
        if (grbd_norm($row['nivel']) !== grbd_norm($level)) continue;
        if (grbd_norm($row['grado']) !== grbd_norm($grade)) continue;
        if (grbd_section($row['seccion']) !== grbd_section($section)) continue;
        $students[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'dni' => (string)$row['id_no']
        ];
    }
    $studentStmt->close();
    uasort($students, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

    $tcIds = array_keys($assignments);
    $tcList = implode(',', array_map('intval', $tcIds));
    $statusClause = '';
    $statusCheck = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
    if ($statusCheck && $statusCheck->num_rows > 0) {
        $statusClause = " AND (e.status IS NULL OR e.status<>'Anulada')";
    }

    $evalStmt = $conn->prepare("SELECT e.id,e.teacher_course_id,e.title
                               FROM evaluations e
                               WHERE e.teacher_course_id IN ($tcList)
                                 AND e.academic_year_id=?
                                 AND CAST(e.bimestre AS UNSIGNED)=?$statusClause
                               ORDER BY e.teacher_course_id,e.id");
    $evalStmt->bind_param('ii', $yearId, $bimester);
    $evalStmt->execute();
    $evalResult = $evalStmt->get_result();
    $evaluations = [];
    $evalIds = [];
    while ($row = $evalResult->fetch_assoc()) {
        $evaluationId = (int)$row['id'];
        $tcId = (int)$row['teacher_course_id'];
        if (!isset($assignments[$tcId])) continue;
        $cid = (int)$assignments[$tcId]['course_id'];
        $evaluations[$evaluationId] = [
            'id' => $evaluationId,
            'teacher_course_id' => $tcId,
            'course_id' => $cid,
            'title' => (string)$row['title']
        ];
        $evalIds[] = $evaluationId;
    }
    $evalStmt->close();

    $competencies = [];
    $grades = [];
    if ($evalIds) {
        $evalList = implode(',', array_map('intval', $evalIds));
        $linkSql = "SELECT ec.evaluation_id,gcc.id competency_id,gcc.name,gcc.percentage
                    FROM evaluation_competencias ec
                    INNER JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id
                    WHERE ec.evaluation_id IN ($evalList)
                      AND (gcc.is_active=1 OR gcc.is_active IS NULL)
                    ORDER BY gcc.id,ec.evaluation_id";
        $links = $conn->query($linkSql);
        while ($links && ($row = $links->fetch_assoc())) {
            $evaluationId = (int)$row['evaluation_id'];
            if (!isset($evaluations[$evaluationId])) continue;
            $cid = (int)$evaluations[$evaluationId]['course_id'];
            $competencyId = (int)$row['competency_id'];
            if (!isset($competencies[$cid][$competencyId])) {
                $competencies[$cid][$competencyId] = [
                    'id' => $competencyId,
                    'name' => (string)$row['name'],
                    'percentage' => (float)$row['percentage'],
                    'evaluations' => []
                ];
            }
            $competencies[$cid][$competencyId]['evaluations'][$evaluationId] = $evaluations[$evaluationId];
        }

        $studentIds = array_keys($students);
        if ($studentIds) {
            $studentList = implode(',', array_map('intval', $studentIds));
            $gradeSql = "SELECT evaluation_id,student_id,competencia_id,grade
                         FROM evaluation_grades
                         WHERE evaluation_id IN ($evalList)
                           AND student_id IN ($studentList)
                           AND grade IS NOT NULL
                           AND TRIM(grade)<>''";
            $gradeResult = $conn->query($gradeSql);
            while ($gradeResult && ($row = $gradeResult->fetch_assoc())) {
                $evaluationId = (int)$row['evaluation_id'];
                $studentId = (int)$row['student_id'];
                $competencyId = $row['competencia_id'] === null ? 0 : (int)$row['competencia_id'];
                $grades[$studentId][$evaluationId][$competencyId] = $row['grade'];
            }
        }
    }

    foreach ($competencies as &$courseCompetencies) {
        ksort($courseCompetencies);
        foreach ($courseCompetencies as &$competency) {
            ksort($competency['evaluations']);
        }
        unset($competency);
    }
    unset($courseCompetencies);

    $locked = false;
    $closedAt = null;
    $lockTable = $conn->query("SHOW TABLES LIKE 'bimester_locks'");
    if ($lockTable && $lockTable->num_rows > 0) {
        $lockStmt = $conn->prepare('SELECT is_locked FROM bimester_locks WHERE school_id=? AND academic_year_id=? AND bimester=? LIMIT 1');
        if ($lockStmt) {
            $lockStmt->bind_param('iii', $schoolId, $yearId, $bimester);
            $lockStmt->execute();
            $lockedRow = $lockStmt->get_result()->fetch_assoc();
            $lockStmt->close();
            $locked = ((int)($lockedRow['is_locked'] ?? 0) === 1);
        }
    }

    $closureTable = $conn->query("SHOW TABLES LIKE 'institutional_bimester_closures'");
    if ($closureTable && $closureTable->num_rows > 0) {
        $closeStmt = $conn->prepare("SELECT closed_at FROM institutional_bimester_closures
                                    WHERE school_id=? AND academic_year_id=? AND bimester=? AND status='Cerrado'
                                    ORDER BY version DESC,id DESC LIMIT 1");
        if ($closeStmt) {
            $closeStmt->bind_param('iii', $schoolId, $yearId, $bimester);
            $closeStmt->execute();
            $closeRow = $closeStmt->get_result()->fetch_assoc();
            $closeStmt->close();
            $closedAt = $closeRow['closed_at'] ?? null;
        }
    }

    return [
        'year' => (string)$year['year'],
        'level' => $level,
        'grade' => $grade,
        'section' => $section,
        'bimester' => $bimester,
        'courses' => $courses,
        'students' => $students,
        'competencies' => $competencies,
        'grades' => $grades,
        'locked' => $locked,
        'closed_at' => $closedAt,
        'auto_format' => grbd_auto_format($grades)
    ];
}
