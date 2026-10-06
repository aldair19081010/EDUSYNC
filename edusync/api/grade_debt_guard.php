<?php
require_once __DIR__ . '/../includes/grade_access_policy_service.php';

function grade_debt_table_exists($conn, $table) {
    return grade_policy_table_exists($conn, $table);
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
    $schoolId = grade_debt_student_school_id($conn, (int)$studentId);
    $policy = grade_policy_load($conn, $schoolId);
    return [
        'school_id' => $schoolId,
        'enabled' => (int)$policy['block_grades_by_debt'] === 1,
        'minimum_concepts' => max(1, (int)$policy['minimum_debt_concepts']),
        'scope' => (string)$policy['debt_scope'],
        'grace_days' => max(0, (int)$policy['grace_days']),
        'message' => (string)$policy['block_message'],
        'configured' => !empty($policy['configured']),
        'temporary_access_until' => $policy['temporary_access_until'] ?? null
    ];
}

function grade_debt_summary($conn, $studentId) {
    return grade_policy_evaluate($conn, (int)$studentId);
}

function grade_debt_blocks_grades($conn, $studentId) {
    return grade_policy_evaluate($conn, (int)$studentId);
}
