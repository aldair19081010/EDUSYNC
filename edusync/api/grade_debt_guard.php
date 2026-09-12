<?php

function grade_debt_guard_table_exists($conn, $table) {
    $safe = $conn->real_escape_string((string)$table);
    $q = $conn->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function grade_debt_guard_column_exists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = $conn->real_escape_string((string)$column);
    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

function grade_debt_summary($conn, $studentId) {
    $studentId = (int)$studentId;
    $summary = [
        'available' => false,
        'count' => 0,
        'total' => 0.0,
        'student_id' => $studentId
    ];

    if ($studentId <= 0) return $summary;
    if (!grade_debt_guard_table_exists($conn, 'student_ef_list') || !grade_debt_guard_table_exists($conn, 'payments')) {
        return $summary;
    }

    $hasDebtStatus = grade_debt_guard_column_exists($conn, 'student_ef_list', 'debt_status');
    $hasPaymentStatus = grade_debt_guard_column_exists($conn, 'payments', 'payment_status');

    $paymentJoin = 'LEFT JOIN payments p ON p.ef_id = ef.id';
    if ($hasPaymentStatus) {
        $paymentJoin .= " AND COALESCE(p.payment_status, 'Confirmado') = 'Confirmado'";
    }

    $debtStatusFilter = $hasDebtStatus ? " AND ef.debt_status = 'Activa'" : '';

    // Mismo criterio del reporte financiero oficial para el registro actual del estudiante:
    // deuda activa = monto efectivo - pagos confirmados > S/ 0.009.
    $sql = "
        SELECT COUNT(*) AS debt_count, COALESCE(SUM(deuda), 0) AS total_debt
        FROM (
            SELECT
                ef.id,
                GREATEST(
                    COALESCE(ef.discounted_amount, ef.total_fee) - COALESCE(SUM(p.amount), 0),
                    0
                ) AS deuda
            FROM student_ef_list ef
            $paymentJoin
            WHERE ef.student_id = $studentId
            $debtStatusFilter
            GROUP BY ef.id, ef.discounted_amount, ef.total_fee
            HAVING deuda > 0.009
        ) debts
    ";

    $q = $conn->query($sql);
    if (!$q) return $summary;

    $row = $q->fetch_assoc();
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
