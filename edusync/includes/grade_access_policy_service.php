<?php
require_once __DIR__ . '/debt_engine.php';

function grade_policy_table_exists($conn, $table) {
    $safe = $conn->real_escape_string((string)$table);
    $q = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $q && $q->num_rows > 0;
}

function grade_policy_column_exists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $safe = $conn->real_escape_string((string)$column);
    $q = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safe}'");
    return $q && $q->num_rows > 0;
}

function grade_policy_now_lima() {
    return new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
}

function grade_policy_defaults() {
    return [
        'school_id' => 0,
        'block_grades_by_debt' => 1,
        'minimum_debt_concepts' => 2,
        'debt_scope' => 'overdue',
        'grace_days' => 0,
        'block_message' => 'Las calificaciones están temporalmente restringidas por obligaciones de pago vencidas. Comunícate con la institución para regularizar tu situación.',
        'grace_message' => 'Tienes obligaciones vencidas, pero todavía estás dentro del periodo de gracia definido por la institución.',
        'temporary_access_until' => null,
        'temporary_message' => 'La institución ha habilitado temporalmente la consulta de calificaciones.',
        'exception_message' => 'Tu acceso a calificaciones está habilitado por una excepción autorizada por la institución.',
        'updated_by' => null,
        'updated_at' => null,
        'configured' => false
    ];
}

function grade_policy_load($conn, $schoolId) {
    $policy = grade_policy_defaults();
    $policy['school_id'] = (int)$schoolId;
    if ((int)$schoolId <= 0 || !grade_policy_table_exists($conn, 'school_grade_access_policy')) {
        return $policy;
    }

    $stmt = $conn->prepare('SELECT * FROM school_grade_access_policy WHERE school_id = ? LIMIT 1');
    if (!$stmt) return $policy;
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        foreach ($policy as $key => $value) {
            if (array_key_exists($key, $row)) $policy[$key] = $row[$key];
        }
        $policy['configured'] = true;
    }

    $policy['block_grades_by_debt'] = (int)($policy['block_grades_by_debt'] ?? 1);
    $policy['minimum_debt_concepts'] = max(1, (int)($policy['minimum_debt_concepts'] ?? 2));
    $policy['grace_days'] = max(0, (int)($policy['grace_days'] ?? 0));
    $policy['debt_scope'] = ($policy['debt_scope'] ?? 'overdue') === 'pending' ? 'pending' : 'overdue';
    return $policy;
}

function grade_policy_active_exception($conn, $schoolId, $studentId) {
    if (!grade_policy_table_exists($conn, 'student_grade_access_exception')) return null;
    $now = grade_policy_now_lima()->format('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "SELECT id, school_id, student_id, reason, starts_at, expires_at, created_by, created_at, updated_at
         FROM student_grade_access_exception
         WHERE school_id = ? AND student_id = ?
           AND starts_at <= ?
           AND (expires_at IS NULL OR expires_at = '' OR expires_at >= ?)
         ORDER BY id DESC
         LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('iiss', $schoolId, $studentId, $now, $now);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function grade_policy_list_exceptions($conn, $schoolId, $includeExpired = false) {
    if (!grade_policy_table_exists($conn, 'student_grade_access_exception')) return [];
    $now = grade_policy_now_lima()->format('Y-m-d H:i:s');
    $sql = "SELECT e.id, e.student_id, e.reason, e.starts_at, e.expires_at, e.created_at, e.updated_at,
                   s.name AS student_name, s.id_no AS student_dni, s.nivel, s.grado, s.seccion,
                   u.name AS created_by_name
            FROM student_grade_access_exception e
            INNER JOIN student s ON s.id = e.student_id AND s.school_id = e.school_id
            LEFT JOIN users u ON u.id = e.created_by
            WHERE e.school_id = ?";
    if (!$includeExpired) $sql .= " AND (e.expires_at IS NULL OR e.expires_at = '' OR e.expires_at >= ?)";
    $sql .= ' ORDER BY e.id DESC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($includeExpired) $stmt->bind_param('i', $schoolId);
    else $stmt->bind_param('is', $schoolId, $now);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function grade_policy_is_temp_open(array $policy) {
    $until = trim((string)($policy['temporary_access_until'] ?? ''));
    if ($until === '') return false;
    try {
        $end = new DateTimeImmutable($until, new DateTimeZone('America/Lima'));
        return $end >= grade_policy_now_lima();
    } catch (Throwable $e) {
        return false;
    }
}

function grade_policy_debt_is_after_grace(array $debt, $graceDays) {
    $due = trim((string)($debt['due_date'] ?? ''));
    if ($due === '') return true;
    try {
        $dueDate = new DateTimeImmutable($due . ' 23:59:59', new DateTimeZone('America/Lima'));
        if ((int)$graceDays > 0) $dueDate = $dueDate->modify('+' . (int)$graceDays . ' days');
        return grade_policy_now_lima() > $dueDate;
    } catch (Throwable $e) {
        return true;
    }
}

function grade_policy_evaluate($conn, $studentId, array $overridePolicy = []) {
    $studentId = (int)$studentId;
    $schoolId = 0;
    if ($studentId > 0) {
        $stmt = $conn->prepare('SELECT school_id FROM student WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $schoolId = (int)($row['school_id'] ?? 0);
        }
    }

    $policy = array_merge(grade_policy_load($conn, $schoolId), $overridePolicy);
    $exception = grade_policy_active_exception($conn, $schoolId, $studentId);

    $result = [
        'available' => debt_engine_available($conn),
        'student_id' => $studentId,
        'school_id' => $schoolId,
        'blocked' => false,
        'reason' => 'allowed',
        'message' => '',
        'count' => 0,
        'total' => 0.0,
        'raw_overdue_count' => 0,
        'grace_count' => 0,
        'minimum_concepts' => max(1, (int)$policy['minimum_debt_concepts']),
        'scope' => (string)$policy['debt_scope'],
        'grace_days' => max(0, (int)$policy['grace_days']),
        'policy_enabled' => (int)$policy['block_grades_by_debt'] === 1,
        'policy_configured' => !empty($policy['configured']),
        'exception' => $exception,
        'temporary_access_until' => $policy['temporary_access_until'] ?? null
    ];

    if (!$result['available'] || $studentId <= 0 || $schoolId <= 0) {
        $result['reason'] = 'unavailable';
        return $result;
    }

    $pending = debt_engine_pending_debts(debt_engine_get_student_debts($conn, $studentId, $schoolId));

    $scope = $result['scope'];
    $graceDays = $result['grace_days'];
    $countable = [];
    $graceDebts = [];
    $rawOverdue = [];

    foreach ($pending as $debt) {
        if (!empty($debt['is_overdue'])) $rawOverdue[] = $debt;

        if ($scope === 'pending') {
            $countable[] = $debt;
            continue;
        }

        if (empty($debt['is_overdue'])) continue;
        if (grade_policy_debt_is_after_grace($debt, $graceDays)) $countable[] = $debt;
        else $graceDebts[] = $debt;
    }

    $summary = debt_engine_summary($countable);
    $result['count'] = (int)$summary['count_concepts'];
    $result['total'] = (float)$summary['total_debt'];
    $result['raw_overdue_count'] = count($rawOverdue);
    $result['grace_count'] = count($graceDebts);

    if (!$result['policy_enabled']) {
        $result['reason'] = 'policy_disabled';
        return $result;
    }

    if ($exception) {
        $result['reason'] = 'student_exception';
        $result['message'] = (string)($policy['exception_message'] ?? '');
        return $result;
    }

    if (grade_policy_is_temp_open($policy)) {
        $result['reason'] = 'temporary_access';
        $result['message'] = (string)($policy['temporary_message'] ?? '');
        return $result;
    }

    if ($scope === 'overdue' && $result['grace_count'] > 0 && $result['count'] < $result['minimum_concepts']) {
        $result['reason'] = 'grace_period';
        $result['message'] = (string)($policy['grace_message'] ?? '');
        return $result;
    }

    $result['blocked'] = $result['count'] >= $result['minimum_concepts'];
    if ($result['blocked']) {
        $result['reason'] = 'debt';
        $result['message'] = (string)($policy['block_message'] ?? '');
    }
    return $result;
}

function grade_policy_audit($conn, $schoolId, $actorUserId, $action, array $details = []) {
    if (!grade_policy_table_exists($conn, 'grade_access_policy_audit_log')) return;
    $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $conn->prepare(
        'INSERT INTO grade_access_policy_audit_log (school_id, actor_user_id, action, details, ip_address)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) return;
    $actor = (int)$actorUserId ?: null;
    $stmt->bind_param('iisss', $schoolId, $actor, $action, $json, $ip);
    $stmt->execute();
    $stmt->close();
}

function grade_policy_simulate($conn, $schoolId) {
    $result = [
        'total_students' => 0,
        'blocked' => 0,
        'grace' => 0,
        'exceptions' => 0,
        'temporary_access' => 0,
        'allowed' => 0,
        'sample' => []
    ];
    $stmt = $conn->prepare("SELECT id, name, id_no, nivel, grado, seccion FROM student WHERE school_id = ? AND COALESCE(status, 'Activo') = 'Activo' ORDER BY name ASC");
    if (!$stmt) return $result;
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($student = $res->fetch_assoc()) {
        $result['total_students']++;
        $evaluation = grade_policy_evaluate($conn, (int)$student['id']);
        if ($evaluation['blocked']) $result['blocked']++;
        elseif ($evaluation['reason'] === 'grace_period') $result['grace']++;
        elseif ($evaluation['reason'] === 'student_exception') $result['exceptions']++;
        elseif ($evaluation['reason'] === 'temporary_access') $result['temporary_access']++;
        else $result['allowed']++;

        if (($evaluation['blocked'] || $evaluation['reason'] === 'grace_period') && count($result['sample']) < 30) {
            $result['sample'][] = [
                'id' => (int)$student['id'],
                'name' => (string)$student['name'],
                'dni' => (string)$student['id_no'],
                'location' => trim((string)$student['nivel'] . ' ' . (string)$student['grado'] . ' ' . (string)$student['seccion']),
                'reason' => (string)$evaluation['reason'],
                'count' => (int)$evaluation['count'],
                'grace_count' => (int)$evaluation['grace_count']
            ];
        }
    }
    $stmt->close();
    return $result;
}
