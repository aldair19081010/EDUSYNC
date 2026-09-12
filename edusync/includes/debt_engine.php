<?php

if (!defined('EDUSYNC_DEBT_TOLERANCE')) {
    define('EDUSYNC_DEBT_TOLERANCE', 0.009);
}

function debt_engine_table_exists($conn, $table) {
    $safe = $conn->real_escape_string((string)$table);
    $q = $conn->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function debt_engine_column_exists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = $conn->real_escape_string((string)$column);
    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

function debt_engine_available($conn) {
    return debt_engine_table_exists($conn, 'student_ef_list')
        && debt_engine_table_exists($conn, 'payments')
        && debt_engine_table_exists($conn, 'student')
        && debt_engine_table_exists($conn, 'courses');
}

function debt_engine_financial_state(array $row) {
    $debtStatus = (string)($row['debt_status'] ?? 'Activa');
    $total = (float)($row['total'] ?? $row['total_fee'] ?? 0);
    $effective = (float)($row['amount_to_pay'] ?? $row['effective'] ?? $total);
    $paid = (float)($row['pagado'] ?? $row['paid'] ?? 0);
    $discountedValue = $row['discounted_amount'] ?? null;
    $dueDate = trim((string)($row['due_date'] ?? ''));

    if ($debtStatus === 'Anulada') return 'Anulada';
    if ($debtStatus === 'Suspendida') return 'Suspendida';
    if ($discountedValue !== null && $total > 0 && $effective <= EDUSYNC_DEBT_TOLERANCE) return 'Exonerada';
    if ($effective > 0 && $paid + EDUSYNC_DEBT_TOLERANCE >= $effective) return 'Pagada';
    if ($paid > EDUSYNC_DEBT_TOLERANCE) return 'Parcial';
    if ($dueDate !== '' && $dueDate < date('Y-m-d')) return 'Vencida';
    return 'Pendiente';
}

function debt_engine_get_student_debts($conn, $studentId, $schoolId = 0) {
    $studentId = (int)$studentId;
    $schoolId = (int)$schoolId;
    if ($studentId <= 0 || !debt_engine_available($conn)) return [];

    $hasDebtStatus = debt_engine_column_exists($conn, 'student_ef_list', 'debt_status');
    $hasIssueDate = debt_engine_column_exists($conn, 'student_ef_list', 'issue_date');
    $hasDueDate = debt_engine_column_exists($conn, 'student_ef_list', 'due_date');
    $hasBillingPeriod = debt_engine_column_exists($conn, 'student_ef_list', 'billing_period');
    $hasPaymentStatus = debt_engine_column_exists($conn, 'payments', 'payment_status');
    $hasPaymentDate = debt_engine_column_exists($conn, 'payments', 'date_created');
    $hasAcademicDescription = debt_engine_table_exists($conn, 'academic_year')
        && debt_engine_column_exists($conn, 'academic_year', 'description');

    $debtStatusExpr = $hasDebtStatus ? "ef.debt_status" : "'Activa'";
    $issueDateExpr = $hasIssueDate ? "ef.issue_date" : "NULL";
    $dueDateExpr = $hasDueDate ? "ef.due_date" : "NULL";
    $billingExpr = $hasBillingPeriod ? "ef.billing_period" : "NULL";
    $yearDescriptionExpr = $hasAcademicDescription ? "ay.description" : "NULL";

    $paymentFilter = $hasPaymentStatus
        ? " AND COALESCE(p.payment_status, 'Confirmado') = 'Confirmado'"
        : "";
    $paymentDateExpr = $hasPaymentDate ? "MAX(p.date_created)" : "NULL";

    $sql = "
        SELECT
            ef.id,
            ef.student_id,
            ef.course_id,
            ef.total_fee,
            ef.discounted_amount,
            $debtStatusExpr AS debt_status,
            $issueDateExpr AS issue_date,
            $dueDateExpr AS due_date,
            $billingExpr AS billing_period,
            c.course,
            c.level,
            c.academic_year_id,
            ay.year AS anio_academico,
            $yearDescriptionExpr AS anio_descripcion,
            s.school_id,
            COALESCE(SUM(p.amount), 0) AS pagado,
            $paymentDateExpr AS last_payment_date
        FROM student_ef_list ef
        INNER JOIN student s ON s.id = ef.student_id
        INNER JOIN courses c ON c.id = ef.course_id
        LEFT JOIN academic_year ay ON ay.id = c.academic_year_id
        LEFT JOIN payments p ON p.ef_id = ef.id $paymentFilter
        WHERE ef.student_id = ?
    ";

    $types = 'i';
    $params = [$studentId];

    if ($schoolId > 0) {
        $sql .= " AND s.school_id = ?";
        $types .= 'i';
        $params[] = $schoolId;
    }

    $sql .= "
        GROUP BY
            ef.id, ef.student_id, ef.course_id, ef.total_fee, ef.discounted_amount,
            debt_status, issue_date, due_date, billing_period,
            c.course, c.level, c.academic_year_id, ay.year, anio_descripcion, s.school_id
        ORDER BY ay.year DESC, due_date ASC, c.course ASC, ef.id DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('[debt_engine] No se pudo preparar consulta: ' . $conn->error);
        return [];
    }

    if ($types === 'i') {
        $stmt->bind_param('i', $params[0]);
    } else {
        $stmt->bind_param('ii', $params[0], $params[1]);
    }

    if (!$stmt->execute()) {
        error_log('[debt_engine] No se pudo ejecutar consulta: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $total = (float)$row['total_fee'];
        $hasDiscount = $row['discounted_amount'] !== null;
        $effective = $hasDiscount ? (float)$row['discounted_amount'] : $total;
        $effective = max(0, $effective);
        $paid = max(0, (float)$row['pagado']);
        $balance = max(0, $effective - $paid);
        $discountAmount = max(0, $total - $effective);
        $discountPercentage = ($total > 0 && $discountAmount > 0)
            ? round(($discountAmount / $total) * 100, 1)
            : 0.0;

        $normalized = [
            'concepto' => (string)$row['course'],
            'total' => $total,
            'amount_to_pay' => $effective,
            'pagado' => $paid,
            'deuda' => $balance,
            'has_discount' => $hasDiscount,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'course_id' => (int)$row['course_id'],
            'ef_id' => (int)$row['id'],
            'student_id' => (int)$row['student_id'],
            'school_id' => (int)$row['school_id'],
            'academic_year_id' => (int)($row['academic_year_id'] ?? 0),
            'anio_academico' => $row['anio_academico'],
            'anio_descripcion' => $row['anio_descripcion'],
            'nivel' => $row['level'],
            'billing_period' => $row['billing_period'],
            'issue_date' => $row['issue_date'],
            'due_date' => $row['due_date'],
            'debt_status' => (string)($row['debt_status'] ?: 'Activa'),
            'last_payment_date' => $row['last_payment_date'],
            'discounted_amount' => $row['discounted_amount']
        ];

        $normalized['financial_status'] = debt_engine_financial_state($normalized);
        $normalized['is_overdue'] = (
            $normalized['debt_status'] === 'Activa'
            && $balance > EDUSYNC_DEBT_TOLERANCE
            && !empty($normalized['due_date'])
            && $normalized['due_date'] < date('Y-m-d')
        );

        $rows[] = $normalized;
    }

    $stmt->close();
    return $rows;
}

function debt_engine_pending_debts(array $debts) {
    return array_values(array_filter($debts, function ($debt) {
        return (($debt['debt_status'] ?? 'Activa') === 'Activa')
            && (float)($debt['deuda'] ?? 0) > EDUSYNC_DEBT_TOLERANCE;
    }));
}

function debt_engine_summary(array $debts) {
    $summary = [
        'total_original' => 0.0,
        'total_discounts' => 0.0,
        'total_effective' => 0.0,
        'total_paid' => 0.0,
        'total_debt' => 0.0,
        'count_concepts' => 0,
        'overdue_debt' => 0.0,
        'overdue_count' => 0,
        'next_due_date' => null
    ];

    $today = date('Y-m-d');

    foreach ($debts as $debt) {
        $summary['total_original'] += (float)($debt['total'] ?? 0);
        $summary['total_discounts'] += (float)($debt['discount_amount'] ?? 0);
        $summary['total_effective'] += (float)($debt['amount_to_pay'] ?? 0);
        $summary['total_paid'] += min(
            (float)($debt['pagado'] ?? 0),
            (float)($debt['amount_to_pay'] ?? 0)
        );
        $summary['total_debt'] += (float)($debt['deuda'] ?? 0);
        $summary['count_concepts']++;

        if (!empty($debt['is_overdue'])) {
            $summary['overdue_debt'] += (float)($debt['deuda'] ?? 0);
            $summary['overdue_count']++;
        }

        $due = trim((string)($debt['due_date'] ?? ''));
        if ($due !== '' && $due >= $today) {
            if ($summary['next_due_date'] === null || $due < $summary['next_due_date']) {
                $summary['next_due_date'] = $due;
            }
        }
    }

    foreach (['total_original', 'total_discounts', 'total_effective', 'total_paid', 'total_debt', 'overdue_debt'] as $key) {
        $summary[$key] = round($summary[$key], 2);
    }

    return $summary;
}

function debt_engine_year_summary(array $debts) {
    $years = [];

    foreach ($debts as $debt) {
        $year = (string)($debt['anio_academico'] ?? '');
        if ($year === '') $year = 'Sin año';

        if (!isset($years[$year])) {
            $years[$year] = [
                'año' => $year,
                'total_deuda' => 0.0,
                'conceptos' => 0,
                'vencida' => 0.0,
                'vencidas' => 0
            ];
        }

        $years[$year]['total_deuda'] += (float)($debt['deuda'] ?? 0);
        $years[$year]['conceptos']++;

        if (!empty($debt['is_overdue'])) {
            $years[$year]['vencida'] += (float)($debt['deuda'] ?? 0);
            $years[$year]['vencidas']++;
        }
    }

    uasort($years, function ($a, $b) {
        return strnatcmp((string)$b['año'], (string)$a['año']);
    });

    foreach ($years as &$year) {
        $year['total_deuda'] = round($year['total_deuda'], 2);
        $year['vencida'] = round($year['vencida'], 2);
    }
    unset($year);

    return array_values($years);
}

function debt_engine_guard_summary($conn, $studentId, $schoolId = 0) {
    $result = [
        'available' => debt_engine_available($conn),
        'count' => 0,
        'total' => 0.0,
        'student_id' => (int)$studentId,
        'blocked' => false
    ];

    if (!$result['available'] || (int)$studentId <= 0) return $result;

    $pending = debt_engine_pending_debts(
        debt_engine_get_student_debts($conn, (int)$studentId, (int)$schoolId)
    );
    $summary = debt_engine_summary($pending);

    $result['count'] = (int)$summary['count_concepts'];
    $result['total'] = (float)$summary['total_debt'];
    $result['blocked'] = $result['count'] >= 2;
    return $result;
}
