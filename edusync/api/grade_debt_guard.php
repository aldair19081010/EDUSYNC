<?php

function grade_debt_guard_table_exists($conn, $table) {
    $safe = $conn->real_escape_string((string)$table);
    $q = $conn->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function grade_debt_summary($conn, $studentId) {
    $studentId = (int)$studentId;
    $summary = [
        'available' => false,
        'count' => 0,
        'total' => 0.0
    ];

    if ($studentId <= 0) return $summary;
    if (!grade_debt_guard_table_exists($conn, 'student_ef_list') || !grade_debt_guard_table_exists($conn, 'payments')) {
        return $summary;
    }

    $sql = "
        SELECT COUNT(*) AS debt_count, COALESCE(SUM(deuda), 0) AS total_debt
        FROM (
            SELECT
                ef.id,
                GREATEST(
                    (CASE
                        WHEN ef.discounted_amount IS NOT NULL THEN ef.discounted_amount
                        ELSE ef.total_fee
                    END) - COALESCE(SUM(p.amount), 0),
                    0
                ) AS deuda
            FROM student_ef_list ef
            LEFT JOIN payments p ON p.ef_id = ef.id
            WHERE ef.student_id = ?
            GROUP BY ef.id, ef.discounted_amount, ef.total_fee
            HAVING deuda > 0.01
        ) debts
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return $summary;
    $stmt->bind_param('i', $studentId);
    if (!$stmt->execute()) {
        $stmt->close();
        return $summary;
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $summary['available'] = true;
    $summary['count'] = (int)($row['debt_count'] ?? 0);
    $summary['total'] = (float)($row['total_debt'] ?? 0);
    return $summary;
}

function grade_debt_blocks_grades($conn, $studentId) {
    $summary = grade_debt_summary($conn, $studentId);
    $summary['blocked'] = $summary['count'] >= 2;
    return $summary;
}
