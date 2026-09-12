<?php

require_once __DIR__ . '/../includes/debt_engine.php';

function grade_debt_summary($conn, $studentId) {
    $summary = debt_engine_guard_summary($conn, (int)$studentId);

    return [
        'available' => (bool)$summary['available'],
        'count' => (int)$summary['count'],
        'total' => (float)$summary['total'],
        'student_id' => (int)$summary['student_id']
    ];
}

function grade_debt_blocks_grades($conn, $studentId) {
    $summary = debt_engine_guard_summary($conn, (int)$studentId);

    return [
        'available' => (bool)$summary['available'],
        'count' => (int)$summary['count'],
        'total' => (float)$summary['total'],
        'student_id' => (int)$summary['student_id'],
        'blocked' => (bool)$summary['blocked']
    ];
}
