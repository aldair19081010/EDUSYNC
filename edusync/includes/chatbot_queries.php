<?php

function edu_chat_teacher_students_current_result(mysqli $conn, array $actor, array $entities): array {
    $teacherId = (int)($actor['teacher_id'] ?? 0);
    $schoolId = (int)($actor['school_id'] ?? 0);
    if ($teacherId <= 0 || $schoolId <= 0) return edu_chat_result('No pude identificar tu ficha docente.');

    $year = edu_chat_active_year($conn, $schoolId);
    $yearId = (int)($year['id'] ?? 0);
    $where = [
        'tc.teacher_id=?',
        'tc.school_id=?',
        's.school_id=?',
        "s.status='Activo'",
        's.grado=tc.grado',
        's.seccion=tc.seccion'
    ];
    $types = 'iii';
    $params = [$teacherId, $schoolId, $schoolId];

    if ($yearId > 0 && edu_chat_column_exists($conn, 'teacher_courses', 'academic_year_id')) {
        $where[] = 'tc.academic_year_id=?';
        $types .= 'i';
        $params[] = $yearId;
    }
    if (edu_chat_column_exists($conn, 'academic_courses', 'level')) $where[] = '(ac.level IS NULL OR ac.level=s.nivel)';
    if (!empty($entities['level'])) {
        $where[] = 's.nivel=?';
        $types .= 's';
        $params[] = $entities['level'];
    }
    if (!empty($entities['grade'])) {
        $where[] = 's.grado=?';
        $types .= 's';
        $params[] = $entities['grade'];
    }

    $sql = 'SELECT COUNT(DISTINCT s.id) total FROM teacher_courses tc LEFT JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN student s ON s.school_id=tc.school_id AND s.grado=tc.grado AND s.seccion=tc.seccion WHERE ' . implode(' AND ', $where);
    $stmt = $conn->prepare($sql);
    edu_chat_bind($stmt, $types, $params);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $scope = !empty($entities['level']) ? ' de ' . strtolower((string)$entities['level']) : '';
    if (!empty($entities['grade'])) $scope .= ' de ' . $entities['grade'] . '° grado';
    return edu_chat_result(
        'Tienes ' . $total . ' estudiante' . ($total === 1 ? '' : 's') . ' vinculado' . ($total === 1 ? '' : 's') . ' a tus asignaciones actuales' . $scope . '.',
        ['¿Cuáles son mis cursos?', '¿Cuántos estudiantes están en riesgo?'],
        [['label' => 'Estudiantes', 'value' => (string)$total, 'tone' => 'primary']],
        [edu_chat_action('Ver Mis Cursos', 'my_courses', 'fa-book')]
    );
}

function edu_chat_academic_risk_current_result(mysqli $conn, array $actor, array $entities): array {
    foreach (['evaluation_grades','evaluations','teacher_courses','student'] as $table) {
        if (!edu_chat_table_exists($conn, $table)) return edu_chat_result('No está disponible la información académica necesaria.');
    }

    $schoolId = (int)($actor['school_id'] ?? 0);
    $year = edu_chat_active_year($conn, $schoolId);
    $yearId = (int)($year['id'] ?? 0);
    $where = [
        's.school_id=?',
        "s.status='Activo'",
        "((eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(eg.grade AS DECIMAL(10,2))<=10) OR UPPER(TRIM(eg.grade))='C')"
    ];
    $types = 'i';
    $params = [$schoolId];

    if ($yearId > 0 && edu_chat_column_exists($conn, 'teacher_courses', 'academic_year_id')) {
        $where[] = 'tc.academic_year_id=?';
        $types .= 'i';
        $params[] = $yearId;
    }
    if ((int)$actor['type'] === 2) {
        $teacherId = (int)($actor['teacher_id'] ?? 0);
        if ($teacherId <= 0) return edu_chat_result('No pude identificar tu ficha docente.');
        $where[] = 'tc.teacher_id=?';
        $types .= 'i';
        $params[] = $teacherId;
    }
    if (!empty($entities['level'])) {
        $where[] = 's.nivel=?';
        $types .= 's';
        $params[] = $entities['level'];
    }
    if (!empty($entities['grade'])) {
        $where[] = 's.grado=?';
        $types .= 's';
        $params[] = $entities['grade'];
    }
    if (!empty($entities['bimestre']) && edu_chat_column_exists($conn, 'evaluations', 'bimestre')) {
        $where[] = 'e.bimestre=?';
        $types .= 's';
        $params[] = $entities['bimestre'];
    }
    if (!empty($entities['course'])) {
        $where[] = 'ac.name=?';
        $types .= 's';
        $params[] = $entities['course'];
    }

    $sql = 'SELECT COUNT(DISTINCT s.id) students,COUNT(*) records FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id INNER JOIN evaluations e ON e.id=eg.evaluation_id LEFT JOIN teacher_courses tc ON tc.id=e.teacher_course_id LEFT JOIN academic_courses ac ON ac.id=tc.course_id WHERE ' . implode(' AND ', $where);
    $stmt = $conn->prepare($sql);
    edu_chat_bind($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $students = (int)($row['students'] ?? 0);
    $records = (int)($row['records'] ?? 0);
    $scope = '';
    if (!empty($entities['level'])) $scope .= ' de ' . strtolower((string)$entities['level']);
    if (!empty($entities['course'])) $scope .= ' en ' . $entities['course'];
    if (!empty($entities['bimestre'])) $scope .= ' en el ' . $entities['bimestre'] . '° bimestre';
    $yearLabel = !empty($year['year']) ? ' del año ' . $year['year'] : '';

    return edu_chat_result(
        'Hay ' . $students . ' estudiantes' . $scope . $yearLabel . ' con al menos un registro crítico (C o nota numérica de 10 o menos).',
        (int)$actor['type'] === 2 ? ['¿Cuántos estudiantes tengo?', '¿Cuáles son mis cursos?'] : ['¿Cuántos estudiantes hay?', '¿Cuántos estudiantes tienen deuda?'],
        [
            ['label' => 'Estudiantes en riesgo', 'value' => (string)$students, 'tone' => $students ? 'danger' : 'success'],
            ['label' => 'Registros críticos', 'value' => (string)$records, 'tone' => $records ? 'warning' : 'success']
        ],
        [edu_chat_action('Ver Reporte de Notas', 'grades_report', 'fa-chart-bar')]
    );
}

function edu_chat_top_debtors_result(mysqli $conn, array $actor, array $entities, int $limit = 10): array {
    if ((int)($actor['type'] ?? 0) !== 1) return edu_chat_result('Esta consulta solo está disponible para administración.');
    if (!debt_engine_available($conn)) return edu_chat_result('El módulo financiero no está disponible.');

    $schoolId = (int)($actor['school_id'] ?? 0);
    if ($schoolId <= 0) return edu_chat_result('No pude identificar el colegio autenticado.');

    $limit = max(1, min(20, $limit));
    $hasDebtStatus = debt_engine_column_exists($conn, 'student_ef_list', 'debt_status');
    $hasPaymentStatus = debt_engine_column_exists($conn, 'payments', 'payment_status');
    $join = "LEFT JOIN payments p ON p.ef_id=ef.id" . ($hasPaymentStatus ? " AND COALESCE(p.payment_status,'Confirmado')='Confirmado'" : '');

    $where = ['s.school_id=?', "s.status='Activo'"];
    $types = 'i';
    $params = [$schoolId];
    if ($hasDebtStatus) $where[] = "ef.debt_status='Activa'";
    if (!empty($entities['level'])) {
        $where[] = 's.nivel=?';
        $types .= 's';
        $params[] = $entities['level'];
    }
    if (!empty($entities['grade'])) {
        $where[] = 's.grado=?';
        $types .= 's';
        $params[] = $entities['grade'];
    }

    $sql = "SELECT x.student_id,x.name,x.nivel,x.grado,x.seccion,COUNT(*) obligations,COALESCE(SUM(x.balance),0) total_debt
            FROM (
                SELECT s.id student_id,s.name,s.nivel,s.grado,s.seccion,ef.id fee_id,
                       GREATEST(COALESCE(ef.discounted_amount,ef.total_fee)-COALESCE(SUM(p.amount),0),0) balance
                FROM student_ef_list ef
                INNER JOIN student s ON s.id=ef.student_id
                $join
                WHERE " . implode(' AND ', $where) . "
                GROUP BY s.id,s.name,s.nivel,s.grado,s.seccion,ef.id,ef.discounted_amount,ef.total_fee
                HAVING balance>0.009
            ) x
            GROUP BY x.student_id,x.name,x.nivel,x.grado,x.seccion
            ORDER BY total_debt DESC,x.name ASC
            LIMIT $limit";

    $stmt = $conn->prepare($sql);
    edu_chat_bind($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    if (!$rows) {
        $scope = !empty($entities['level']) ? ' en ' . $entities['level'] : '';
        if (!empty($entities['grade'])) $scope .= ' ' . $entities['grade'] . '°';
        return edu_chat_result('No encontré estudiantes con deuda pendiente' . $scope . '.');
    }

    $lines = [];
    $totalListed = 0.0;
    foreach ($rows as $index => $row) {
        $debt = (float)($row['total_debt'] ?? 0);
        $totalListed += $debt;
        $location = trim((string)($row['nivel'] ?? ''));
        if (!empty($row['grado'])) $location .= ($location !== '' ? ' · ' : '') . $row['grado'] . '°';
        if (!empty($row['seccion'])) $location .= ($location !== '' ? ' ' : '') . $row['seccion'];
        $lines[] = ($index + 1) . '. ' . (string)$row['name'] . ' — ' . edu_chat_money($debt)
            . ' (' . (int)$row['obligations'] . ' obligación' . ((int)$row['obligations'] === 1 ? '' : 'es') . ')'
            . ($location !== '' ? ' · ' . $location : '');
    }

    $scope = '';
    if (!empty($entities['level'])) $scope .= ' de ' . strtolower((string)$entities['level']);
    if (!empty($entities['grade'])) $scope .= ' de ' . $entities['grade'] . '° grado';
    $message = 'Estos son los ' . count($rows) . ' estudiantes' . $scope . " con mayor deuda pendiente:\n" . implode("\n", $lines);

    return edu_chat_result(
        $message,
        ['¿Cuánto deben en total?', 'Dame los 5 que más deben', '¿Cuánto se ha cobrado este mes?'],
        [
            ['label' => 'Estudiantes listados', 'value' => (string)count($rows), 'tone' => 'warning'],
            ['label' => 'Deuda del ranking', 'value' => edu_chat_money($totalListed), 'tone' => 'danger']
        ],
        [edu_chat_action('Ver Reporte de Deudas', 'debt_reports', 'fa-exclamation-circle')]
    );
}
