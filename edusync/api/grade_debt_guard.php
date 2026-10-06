<?php

require_once __DIR__ . '/../includes/debt_engine.php';

function grade_debt_table_exists($conn, $table) {
    $safe = $conn->real_escape_string((string)$table);
    $q = $conn->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function grade_debt_student_school_id($conn, $studentId) {
    $studentId = (int)$studentId;
    if ($studentId <= 0) return 0;

    $stmt = $conn->prepare('SELECT school_id FROM student WHERE id = ? LIMIT 1');
    if (!$stmt) return 0;
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['school_id'] ?? 0);
}

function grade_debt_policy($conn, $studentId) {
    $schoolId = grade_debt_student_school_id($conn, $studentId);
    $policy = [
        'school_id' => $schoolId,
        'enabled' => true,
        'minimum_concepts' => 2,
        'scope' => 'overdue',
        'message' => 'Las calificaciones están temporalmente restringidas por obligaciones de pago vencidas. Comunícate con la institución para regularizar tu situación.',
        'configured' => false
    ];

    if ($schoolId <= 0 || !grade_debt_table_exists($conn, 'school_grade_access_policy')) {
        return $policy;
    }

    $stmt = $conn->prepare(
        'SELECT block_grades_by_debt, minimum_debt_concepts, debt_scope, block_message
         FROM school_grade_access_policy
         WHERE school_id = ?
         LIMIT 1'
    );
    if (!$stmt) return $policy;

    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return $policy;

    $scope = ($row['debt_scope'] ?? 'overdue') === 'pending'
        ? 'pending'
        : 'overdue';

    $policy['enabled'] = (int)($row['block_grades_by_debt'] ?? 1) === 1;
    $policy['minimum_concepts'] = max(
        1,
        (int)($row['minimum_debt_concepts'] ?? 2)
    );
    $policy['scope'] = $scope;

    $message = trim((string)($row['block_message'] ?? ''));
    if ($message !== '') $policy['message'] = $message;
    $policy['configured'] = true;

    return $policy;
}

function grade_debt_summary($conn, $studentId) {
    $policy = grade_debt_policy($conn, (int)$studentId);
    $summary = debt_engine_guard_summary(
        $conn,
        (int)$studentId,
        (int)$policy['school_id'],
        (int)$policy['minimum_concepts'],
        (string)$policy['scope']
    );

    return [
        'available' => (bool)$summary['available'],
        'count' => (int)$summary['count'],
        'total' => (float)$summary['total'],
        'student_id' => (int)$summary['student_id'],
        'school_id' => (int)$policy['school_id'],
        'minimum_concepts' => (int)$policy['minimum_concepts'],
        'scope' => (string)$policy['scope'],
        'policy_enabled' => (bool)$policy['enabled'],
        'policy_configured' => (bool)$policy['configured']
    ];
}

function grade_debt_blocks_grades($conn, $studentId) {
    $policy = grade_debt_policy($conn, (int)$studentId);
    $summary = debt_engine_guard_summary(
        $conn,
        (int)$studentId,
        (int)$policy['school_id'],
        (int)$policy['minimum_concepts'],
        (string)$policy['scope']
    );

    return [
        'available' => (bool)$summary['available'],
        'count' => (int)$summary['count'],
        'total' => (float)$summary['total'],
        'student_id' => (int)$summary['student_id'],
        'school_id' => (int)$policy['school_id'],
        'minimum_concepts' => (int)$policy['minimum_concepts'],
        'scope' => (string)$policy['scope'],
        'policy_enabled' => (bool)$policy['enabled'],
        'policy_configured' => (bool)$policy['configured'],
        'message' => (string)$policy['message'],
        'blocked' => (bool)$policy['enabled'] && (bool)$summary['blocked']
    ];
}
