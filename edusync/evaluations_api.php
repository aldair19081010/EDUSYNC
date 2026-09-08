<?php
// Incluido desde ajax.php para devolver únicamente evaluaciones del docente autenticado.
if ($action === 'get_teacher_evaluations') {
    header('Content-Type: application/json; charset=utf-8');

    $userId = (int)($_SESSION['login_id'] ?? 0);
    $teacherId = (int)($_SESSION['login_teacher_id'] ?? 0);
    $schoolId = (int)($_SESSION['login_school_id'] ?? 0);
    $loginType = (int)($_SESSION['login_type'] ?? 0);

    if (!$userId || $loginType !== 2 || !$teacherId || !$schoolId) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'msg' => 'No tiene permisos para consultar evaluaciones.']);
        exit;
    }

    $yearId = (int)($_POST['academic_year_id'] ?? 0);
    if ($yearId <= 0) {
        $q = $conn->prepare('SELECT id FROM academic_year WHERE school_id=? AND is_active=1 LIMIT 1');
        $q->bind_param('i', $schoolId);
        $q->execute();
        $yearId = (int)($q->get_result()->fetch_assoc()['id'] ?? 0);
        $q->close();
    }

    $filters = [
        'level' => trim($_POST['level'] ?? ''),
        'grado' => trim($_POST['grado'] ?? ''),
        'seccion' => trim($_POST['seccion'] ?? ''),
        'course' => trim($_POST['course'] ?? ''),
        'bimestre' => trim($_POST['bimestre'] ?? ''),
        'type' => trim($_POST['type'] ?? ''),
        'date' => trim($_POST['date'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'search' => trim($_POST['search'] ?? '')
    ];

    $hasStatus = false;
    $col = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
    if ($col && $col->num_rows) $hasStatus = true;

    $hasLocks = false;
    $table = $conn->query("SHOW TABLES LIKE 'bimester_locks'");
    if ($table && $table->num_rows) $hasLocks = true;

    $hasClosures = false;
    $closureTable = $conn->query("SHOW TABLES LIKE 'grade_period_closures'");
    if ($closureTable && $closureTable->num_rows) $hasClosures = true;

    $statusSelect = $hasStatus
        ? "e.status,e.annulled_at,e.annulment_reason,"
        : "'Activa' status,NULL annulled_at,NULL annulment_reason,";

    $globalLockExpr = $hasLocks
        ? "EXISTS(SELECT 1 FROM bimester_locks bl WHERE bl.school_id=tc.school_id AND bl.academic_year_id=e.academic_year_id AND bl.bimester=CAST(e.bimestre AS UNSIGNED) AND bl.is_locked=1)"
        : "0";

    $periodClosureExpr = $hasClosures
        ? "EXISTS(SELECT 1 FROM grade_period_closures gpc WHERE gpc.school_id=tc.school_id AND gpc.teacher_course_id=e.teacher_course_id AND gpc.bimester=CAST(e.bimestre AS UNSIGNED) AND gpc.status='Cerrado')"
        : "0";

    $lockSelect = "(($globalLockExpr) OR ($periodClosureExpr)) is_locked,($periodClosureExpr) is_period_closed";

    $where = "e.teacher_id=$teacherId AND tc.teacher_id=$teacherId AND tc.school_id=$schoolId AND ay.school_id=$schoolId";
    if ($yearId > 0) $where .= " AND e.academic_year_id=$yearId";

    if ($filters['level'] !== '') {
        $v = $conn->real_escape_string($filters['level']);
        $where .= " AND TRIM(LOWER(ac.level))=TRIM(LOWER('$v'))";
    }
    if ($filters['grado'] !== '') {
        $v = $conn->real_escape_string(preg_replace('/[°º\s]+/u', '', $filters['grado']));
        $where .= " AND REPLACE(REPLACE(REPLACE(LOWER(tc.grado),'°',''),'º',''),' ','')=LOWER('$v')";
    }
    if ($filters['seccion'] !== '') {
        $v = $conn->real_escape_string($filters['seccion']);
        $where .= " AND TRIM(LOWER(COALESCE(NULLIF(tc.seccion,''),'U')))=TRIM(LOWER('$v'))";
    }
    if ($filters['course'] !== '') {
        $v = $conn->real_escape_string($filters['course']);
        $where .= " AND TRIM(ac.name)=TRIM('$v')";
    }
    if ($filters['bimestre'] !== '') {
        $v = $conn->real_escape_string($filters['bimestre']);
        $where .= " AND e.bimestre='$v'";
    }
    if ($filters['type'] !== '') {
        $v = $conn->real_escape_string($filters['type']);
        $where .= " AND e.type='$v'";
    }
    if ($filters['date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
        $v = $conn->real_escape_string($filters['date']);
        $where .= " AND DATE(e.created_at)='$v'";
    }
    if ($filters['search'] !== '') {
        $v = $conn->real_escape_string($filters['search']);
        $where .= " AND (e.title LIKE '%$v%' OR e.description LIKE '%$v%')";
    }
    if ($hasStatus && $filters['state'] === 'annulled') {
        $where .= " AND e.status='Anulada'";
    } elseif ($hasStatus) {
        $where .= " AND e.status<>'Anulada'";
    }

    // La tabla debe usar la misma idea de completitud que el cierre bimestral:
    // notas esperadas = alumnos activos del aula x competencias vinculadas a la evaluación.
    // Se normalizan nivel, grado y sección para evitar diferencias como 5 / 5° o U / Única.
    $studentMatch = "
        st.school_id=tc.school_id
        AND (st.status='Activo' OR st.status IS NULL)
        AND TRIM(LOWER(st.nivel))=TRIM(LOWER(ac.level))
        AND REPLACE(REPLACE(REPLACE(LOWER(TRIM(st.grado)),'°',''),'º',''),' ','')=
            REPLACE(REPLACE(REPLACE(LOWER(TRIM(tc.grado)),'°',''),'º',''),' ','')
        AND (
            TRIM(LOWER(COALESCE(NULLIF(st.seccion,''),'U')))=TRIM(LOWER(COALESCE(NULLIF(tc.seccion,''),'U')))
            OR (
                TRIM(LOWER(COALESCE(NULLIF(st.seccion,''),'U'))) IN ('u','única','unica')
                AND TRIM(LOWER(COALESCE(NULLIF(tc.seccion,''),'U'))) IN ('u','única','unica')
            )
        )";

    $sql = "SELECT
        e.id,e.title,e.description,e.type,e.bimestre,e.teacher_course_id,e.academic_year_id,e.created_at,
        $statusSelect
        ac.name course_name,ac.level,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,
        ay.year academic_year,ay.is_active,
        (SELECT gcc.name
            FROM evaluation_competencias ec
            INNER JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id
            WHERE ec.evaluation_id=e.id
            LIMIT 1) competency_name,
        (SELECT COUNT(*) FROM student st WHERE $studentMatch) student_count,
        (SELECT COUNT(DISTINCT ec.competencia_id)
            FROM evaluation_competencias ec
            WHERE ec.evaluation_id=e.id) competency_count,
        (SELECT COUNT(DISTINCT CONCAT(eg.student_id,':',eg.competencia_id))
            FROM evaluation_grades eg
            INNER JOIN evaluation_competencias ec
                ON ec.evaluation_id=e.id AND ec.competencia_id=eg.competencia_id
            INNER JOIN student st ON st.id=eg.student_id
            WHERE eg.evaluation_id=e.id
              AND eg.grade IS NOT NULL
              AND TRIM(eg.grade)<>''
              AND $studentMatch) completed_grade_cells,
        (SELECT COUNT(*) FROM evaluation_grades eg
            WHERE eg.evaluation_id=e.id
              AND eg.grade IS NOT NULL
              AND TRIM(eg.grade)<>'') grades_count,
        $lockSelect
        FROM evaluations e
        INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
        INNER JOIN academic_courses ac ON ac.id=tc.course_id
        INNER JOIN academic_year ay ON ay.id=e.academic_year_id
        WHERE $where
        ORDER BY e.created_at DESC,e.id DESC";

    $result = $conn->query($sql);
    if (!$result) {
        http_response_code(500);
        echo json_encode([
            'status' => 0,
            'msg' => 'No se pudieron consultar las evaluaciones.',
            'detail' => $conn->error
        ]);
        exit;
    }

    $data = [];
    $summary = ['total'=>0,'pending'=>0,'partial'=>0,'complete'=>0,'locked'=>0,'annulled'=>0];

    while ($row = $result->fetch_assoc()) {
        $students = (int)$row['student_count'];
        $competencies = (int)$row['competency_count'];
        $expectedCells = $students * $competencies;
        $completedCells = (int)$row['completed_grade_cells'];
        if ($expectedCells > 0 && $completedCells > $expectedCells) $completedCells = $expectedCells;

        $progress = $expectedCells > 0
            ? min(100, round(($completedCells / $expectedCells) * 100))
            : 0;

        if ($row['status'] === 'Anulada') {
            $state = 'annulled';
        } elseif ((int)$row['is_locked'] === 1) {
            $state = 'locked';
        } elseif ($completedCells === 0) {
            $state = 'pending';
        } elseif ($expectedCells > 0 && $completedCells >= $expectedCells) {
            $state = 'complete';
        } else {
            $state = 'partial';
        }

        if ($filters['state'] !== '' && $filters['state'] !== 'all' && $filters['state'] !== $state) continue;

        // Se conservan los nombres usados por la vista para no romper compatibilidad.
        // En esta tabla representan celdas de nota completadas / esperadas, no solo alumnos iniciados.
        $row['expected_students'] = $expectedCells;
        $row['graded_students'] = $completedCells;
        $row['actual_students'] = $students;
        $row['competency_count'] = $competencies;
        $row['expected_grade_cells'] = $expectedCells;
        $row['completed_grade_cells'] = $completedCells;
        $row['grades_count'] = (int)$row['grades_count'];
        $row['progress'] = $progress;
        $row['state'] = $state;
        $row['is_locked'] = (bool)$row['is_locked'];
        $row['is_period_closed'] = (bool)$row['is_period_closed'];
        $row['is_active'] = (bool)$row['is_active'];

        $data[] = $row;
        $summary['total']++;
        if (isset($summary[$state])) $summary[$state]++;
    }

    echo json_encode([
        'status' => 1,
        'data' => $data,
        'summary' => $summary,
        'migration_ready' => $hasStatus,
        'closure_ready' => $hasClosures
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
