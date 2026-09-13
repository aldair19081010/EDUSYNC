<?php

/**
 * Consultas financieras nominales para administración.
 * Nunca acepta school_id/student_id desde el modelo: el colegio sale de la sesión.
 */
function edu_chat_students_by_debt_count_result(
    mysqli $conn,
    array $actor,
    array $entities,
    int $minObligations = 1,
    ?int $maxObligations = null,
    int $limit = 100,
    string $groupBy = 'grade_section',
    string $sortBy = 'obligations'
): array {
    if ((int)($actor['type'] ?? 0) !== 1) {
        return edu_chat_result('Esta consulta solo está disponible para administración.');
    }
    if (!debt_engine_available($conn)) {
        return edu_chat_result('El módulo financiero no está disponible.');
    }

    $schoolId = (int)($actor['school_id'] ?? 0);
    if ($schoolId <= 0) return edu_chat_result('No pude identificar el colegio autenticado.');

    $minObligations = max(1, min(100, $minObligations));
    if ($maxObligations !== null) {
        $maxObligations = max(1, min(100, $maxObligations));
        if ($maxObligations < $minObligations) {
            [$minObligations, $maxObligations] = [$maxObligations, $minObligations];
        }
    }
    $limit = max(1, min(200, $limit));
    $groupBy = in_array($groupBy, ['none','level','grade','section','grade_section'], true) ? $groupBy : 'grade_section';
    $sortBy = in_array($sortBy, ['obligations','debt','name','location'], true) ? $sortBy : 'obligations';

    $hasDebtStatus = debt_engine_column_exists($conn, 'student_ef_list', 'debt_status');
    $hasPaymentStatus = debt_engine_column_exists($conn, 'payments', 'payment_status');
    $paymentJoin = "LEFT JOIN payments p ON p.ef_id=ef.id"
        . ($hasPaymentStatus ? " AND COALESCE(p.payment_status,'Confirmado')='Confirmado'" : '');

    $where = [
        's.school_id=?',
        "LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"
    ];
    $types = 'i';
    $params = [$schoolId];
    if ($hasDebtStatus) $where[] = "LOWER(TRIM(COALESCE(ef.debt_status,'Activa')))='activa'";

    if (!empty($entities['level'])) {
        $where[] = 'LOWER(TRIM(s.nivel))=LOWER(TRIM(?))';
        $types .= 's';
        $params[] = (string)$entities['level'];
    }
    if (!empty($entities['grade'])) {
        $grade = trim((string)$entities['grade']);
        if (preg_match('/^[1-9][0-9]*$/', $grade)) {
            $where[] = 'CAST(s.grado AS UNSIGNED)=?';
            $types .= 'i';
            $params[] = (int)$grade;
        } else {
            $where[] = 'LOWER(TRIM(s.grado))=LOWER(TRIM(?))';
            $types .= 's';
            $params[] = $grade;
        }
    }
    if (!empty($entities['section'])) {
        $where[] = 'UPPER(TRIM(s.seccion))=UPPER(TRIM(?))';
        $types .= 's';
        $params[] = (string)$entities['section'];
    }

    $having = ['COUNT(*)>=?'];
    $types .= 'i';
    $params[] = $minObligations;
    if ($maxObligations !== null) {
        $having[] = 'COUNT(*)<=?';
        $types .= 'i';
        $params[] = $maxObligations;
    }

    $orderSql = 'obligations DESC,total_debt DESC,x.name ASC';
    if ($sortBy === 'debt') $orderSql = 'total_debt DESC,obligations DESC,x.name ASC';
    elseif ($sortBy === 'name') $orderSql = 'x.name ASC';
    elseif ($sortBy === 'location') $orderSql = "FIELD(x.nivel,'Inicial','Primaria','Secundaria'),CAST(x.grado AS UNSIGNED),UPPER(TRIM(x.seccion)),x.name ASC";

    // Pedimos una fila adicional para saber si la respuesta fue truncada.
    $sqlLimit = $limit + 1;
    $sql = "SELECT x.student_id,x.name,x.nivel,x.grado,x.seccion,COUNT(*) obligations,COALESCE(SUM(x.balance),0) total_debt
            FROM (
                SELECT s.id student_id,s.name,s.nivel,s.grado,s.seccion,ef.id fee_id,
                       GREATEST(COALESCE(ef.discounted_amount,ef.total_fee)-COALESCE(SUM(p.amount),0),0) balance
                FROM student_ef_list ef
                INNER JOIN student s ON s.id=ef.student_id
                $paymentJoin
                WHERE " . implode(' AND ', $where) . "
                GROUP BY s.id,s.name,s.nivel,s.grado,s.seccion,ef.id,ef.discounted_amount,ef.total_fee
                HAVING balance>0.009
            ) x
            GROUP BY x.student_id,x.name,x.nivel,x.grado,x.seccion
            HAVING " . implode(' AND ', $having) . "
            ORDER BY $orderSql
            LIMIT $sqlLimit";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return edu_chat_result('No pude preparar la consulta financiera solicitada.');
    edu_chat_bind($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    $truncated = count($rows) > $limit;
    if ($truncated) $rows = array_slice($rows, 0, $limit);

    $condition = $maxObligations === null
        ? ($minObligations === 1 ? 'con deuda pendiente' : 'con ' . $minObligations . ' o más obligaciones pendientes')
        : ($minObligations === $maxObligations
            ? 'con exactamente ' . $minObligations . ' obligaciones pendientes'
            : 'con entre ' . $minObligations . ' y ' . $maxObligations . ' obligaciones pendientes');

    if (!$rows) {
        $scope = '';
        if (!empty($entities['level'])) $scope .= ' en ' . (string)$entities['level'];
        if (!empty($entities['grade'])) $scope .= ' ' . (string)$entities['grade'] . '°';
        if (!empty($entities['section'])) $scope .= ' ' . (string)$entities['section'];
        return edu_chat_result('No encontré estudiantes ' . $condition . $scope . '.');
    }

    $groupLabel = static function(array $row, string $mode): string {
        $level = trim((string)($row['nivel'] ?? ''));
        $grade = trim((string)($row['grado'] ?? ''));
        $section = trim((string)($row['seccion'] ?? ''));
        if ($mode === 'none') return '';
        if ($mode === 'level') return $level !== '' ? $level : 'Sin nivel';
        if ($mode === 'grade') return trim(($level !== '' ? $level . ' · ' : '') . ($grade !== '' ? $grade . '°' : 'Sin grado'));
        if ($mode === 'section') return trim(($level !== '' ? $level . ' · ' : '') . ($section !== '' ? 'Sección ' . $section : 'Sin sección'));
        return trim(($level !== '' ? $level . ' · ' : '') . ($grade !== '' ? $grade . '°' : 'Sin grado') . ($section !== '' ? ' ' . $section : ''));
    };

    $groups = [];
    $totalDebt = 0.0;
    $totalObligations = 0;
    foreach ($rows as $row) {
        $key = $groupLabel($row, $groupBy);
        if (!isset($groups[$key])) $groups[$key] = [];
        $groups[$key][] = $row;
        $totalDebt += (float)($row['total_debt'] ?? 0);
        $totalObligations += (int)($row['obligations'] ?? 0);
    }

    $lines = [];
    $globalIndex = 1;
    foreach ($groups as $label => $items) {
        if ($groupBy !== 'none') $lines[] = $label . ':';
        foreach ($items as $row) {
            $location = trim((string)($row['nivel'] ?? ''));
            if (!empty($row['grado'])) $location .= ($location !== '' ? ' · ' : '') . $row['grado'] . '°';
            if (!empty($row['seccion'])) $location .= ($location !== '' ? ' ' : '') . $row['seccion'];
            $prefix = $groupBy === 'none' ? ($globalIndex . '. ') : '- ';
            $lines[] = $prefix . (string)$row['name']
                . ' — ' . (int)$row['obligations'] . ' deuda' . ((int)$row['obligations'] === 1 ? '' : 's')
                . ' — ' . edu_chat_money((float)$row['total_debt'])
                . ($groupBy === 'none' && $location !== '' ? ' · ' . $location : '');
            $globalIndex++;
        }
    }

    $message = 'Encontré ' . count($rows) . ' estudiante' . (count($rows) === 1 ? '' : 's') . ' ' . $condition . ".\n" . implode("\n", $lines);
    if ($truncated) $message .= "\nMostrando los primeros {$limit} resultados; hay más coincidencias.";

    return edu_chat_result(
        $message,
        ['Dame los que tienen 5 o más deudas', 'Ordénalos por monto de deuda', 'Muéstrame solo secundaria'],
        [
            ['label'=>'Estudiantes encontrados','value'=>(string)count($rows),'tone'=>'warning'],
            ['label'=>'Obligaciones listadas','value'=>(string)$totalObligations,'tone'=>'warning'],
            ['label'=>'Saldo listado','value'=>edu_chat_money($totalDebt),'tone'=>'danger']
        ],
        [edu_chat_action('Ver Reporte de Deudas','debt_reports','fa-exclamation-circle')]
    );
}
