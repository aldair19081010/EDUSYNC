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

function grade_debt_guard_student_ids($conn, $studentId) {
    $studentId = (int)$studentId;
    if ($studentId <= 0) return [];

    $ids = [$studentId];
    $stmt = $conn->prepare('SELECT school_id, id_no, name FROM student WHERE id = ? LIMIT 1');
    if (!$stmt) return $ids;
    $stmt->bind_param('i', $studentId);
    if (!$stmt->execute()) {
        $stmt->close();
        return $ids;
    }

    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) return $ids;

    $schoolId = (int)($student['school_id'] ?? 0);
    $dni = trim((string)($student['id_no'] ?? ''));
    $name = trim((string)($student['name'] ?? ''));
    if ($schoolId <= 0) return $ids;

    if ($dni !== '') {
        $stmt = $conn->prepare("SELECT id FROM student WHERE school_id = ? AND (id_no = ? OR (name = ? AND (id_no IS NULL OR TRIM(id_no) = ''))) ");
        if ($stmt) {
            $stmt->bind_param('iss', $schoolId, $dni, $name);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) $ids[] = (int)$row['id'];
            }
            $stmt->close();
        }
    } elseif ($name !== '') {
        $stmt = $conn->prepare('SELECT id FROM student WHERE school_id = ? AND name = ?');
        if ($stmt) {
            $stmt->bind_param('is', $schoolId, $name);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) $ids[] = (int)$row['id'];
            }
            $stmt->close();
        }
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
        return $id > 0;
    })));
    return $ids;
}

function grade_debt_summary($conn, $studentId) {
    $summary = [
        'available' => false,
        'count' => 0,
        'total' => 0.0,
        'student_ids' => []
    ];

    if (!grade_debt_guard_table_exists($conn, 'student_ef_list') || !grade_debt_guard_table_exists($conn, 'payments')) {
        return $summary;
    }

    $studentIds = grade_debt_guard_student_ids($conn, $studentId);
    if (!$studentIds) return $summary;
    $summary['student_ids'] = $studentIds;
    $studentIdsSql = implode(',', $studentIds);

    $hasDebtStatus = grade_debt_guard_column_exists($conn, 'student_ef_list', 'debt_status');
    $hasPaymentStatus = grade_debt_guard_column_exists($conn, 'payments', 'payment_status');

    $paymentJoin = 'LEFT JOIN payments p ON p.ef_id = ef.id';
    if ($hasPaymentStatus) {
        $paymentJoin .= " AND COALESCE(p.payment_status, 'Confirmado') = 'Confirmado'";
    }

    $debtStatusFilter = $hasDebtStatus ? " AND ef.debt_status = 'Activa'" : '';

    // Mismo criterio del reporte financiero oficial:
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
            WHERE ef.student_id IN ($studentIdsSql)
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
