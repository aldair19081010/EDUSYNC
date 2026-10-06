<?php
require_once __DIR__ . '/grade_access_policy_service.php';

if (!isset($action) || !in_array($action, [
    'save_grade_access_policy',
    'save_grade_exception',
    'remove_grade_exception',
    'simulate_grade_policy',
    'grade_policy_history'
], true)) {
    return;
}

function grade_policy_admin_require_schema($conn) {
    $requiredTables = [
        'school_grade_access_policy',
        'student_grade_access_exception',
        'grade_access_policy_audit_log'
    ];
    foreach ($requiredTables as $table) {
        if (!grade_policy_table_exists($conn, $table)) {
            user_api_reply(0, 'Falta actualizar la base de datos. Ejecuta nuevamente sql/access_control_upgrade.sql.');
        }
    }
    foreach (['grace_days', 'temporary_access_until', 'grace_message', 'temporary_message', 'exception_message'] as $column) {
        if (!grade_policy_column_exists($conn, 'school_grade_access_policy', $column)) {
            user_api_reply(0, 'Falta actualizar la base de datos. Ejecuta nuevamente sql/access_control_upgrade.sql.');
        }
    }
}

function grade_policy_admin_message($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '') $value = $fallback;
    if (mb_strlen($value, 'UTF-8') > 500) {
        user_api_reply(0, 'Los mensajes de la política no pueden superar los 500 caracteres.');
    }
    return $value;
}

if ($action === 'save_grade_access_policy') {
    grade_policy_admin_require_schema($conn);

    $defaults = grade_policy_defaults();
    $enabled = !empty($_POST['block_grades_by_debt']) ? 1 : 0;
    $minimum = max(1, (int)($_POST['minimum_debt_concepts'] ?? 2));
    $scope = (string)($_POST['debt_scope'] ?? 'overdue');
    $graceDays = max(0, (int)($_POST['grace_days'] ?? 0));
    if ($minimum > 20) user_api_reply(0, 'La cantidad mínima de conceptos debe estar entre 1 y 20.');
    if ($graceDays > 90) user_api_reply(0, 'El periodo de gracia debe estar entre 0 y 90 días.');
    if (!in_array($scope, ['overdue', 'pending'], true)) user_api_reply(0, 'Selecciona un tipo de deuda válido.');

    $blockMessage = grade_policy_admin_message($_POST['block_message'] ?? '', $defaults['block_message']);
    $graceMessage = grade_policy_admin_message($_POST['grace_message'] ?? '', $defaults['grace_message']);
    $temporaryMessage = grade_policy_admin_message($_POST['temporary_message'] ?? '', $defaults['temporary_message']);
    $exceptionMessage = grade_policy_admin_message($_POST['exception_message'] ?? '', $defaults['exception_message']);

    $temporaryRaw = trim((string)($_POST['temporary_access_until'] ?? ''));
    $temporaryUntil = null;
    if ($temporaryRaw !== '') {
        try {
            $dt = new DateTimeImmutable($temporaryRaw, new DateTimeZone('America/Lima'));
            $temporaryUntil = $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            user_api_reply(0, 'La fecha de apertura temporal no es válida.');
        }
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO school_grade_access_policy
             (school_id, block_grades_by_debt, minimum_debt_concepts, debt_scope, grace_days,
              block_message, grace_message, temporary_access_until, temporary_message, exception_message, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                block_grades_by_debt = VALUES(block_grades_by_debt),
                minimum_debt_concepts = VALUES(minimum_debt_concepts),
                debt_scope = VALUES(debt_scope),
                grace_days = VALUES(grace_days),
                block_message = VALUES(block_message),
                grace_message = VALUES(grace_message),
                temporary_access_until = VALUES(temporary_access_until),
                temporary_message = VALUES(temporary_message),
                exception_message = VALUES(exception_message),
                updated_by = VALUES(updated_by)'
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param(
            'iiisisssssi',
            $school_id, $enabled, $minimum, $scope, $graceDays,
            $blockMessage, $graceMessage, $temporaryUntil, $temporaryMessage, $exceptionMessage, $login_id
        );
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();

        if (grade_policy_table_exists($conn, 'school_grade_access_policy_concepts')) {
            $legacy = $conn->prepare('DELETE FROM school_grade_access_policy_concepts WHERE school_id = ?');
            if ($legacy) {
                $legacy->bind_param('i', $school_id);
                $legacy->execute();
                $legacy->close();
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[grade_policy] save failed: ' . $e->getMessage());
        user_api_reply(0, 'No se pudo guardar la política de acceso a notas.');
    }

    grade_policy_audit($conn, $school_id, $login_id, 'POLICY_UPDATED', [
        'enabled' => $enabled,
        'minimum_debt_concepts' => $minimum,
        'debt_scope' => $scope,
        'grace_days' => $graceDays,
        'temporary_access_until' => $temporaryUntil
    ]);

    user_api_audit($conn, $school_id, 0, 'POLITICA_NOTAS', 'GRADE_ACCESS_POLICY_UPDATED', [
        'enabled' => $enabled,
        'minimum_debt_concepts' => $minimum,
        'debt_scope' => $scope,
        'grace_days' => $graceDays,
        'temporary_access_until' => $temporaryUntil
    ]);

    user_api_reply(1, 'Política de acceso a notas actualizada.', [
        'policy' => grade_policy_load($conn, $school_id)
    ]);
}

if ($action === 'save_grade_exception') {
    grade_policy_admin_require_schema($conn);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $expiresRaw = trim((string)($_POST['expires_at'] ?? ''));

    $student = load_student_for_access($conn, $studentId, $school_id);
    if (!$student) user_api_reply(0, 'Estudiante no encontrado.');
    if (mb_strlen($reason, 'UTF-8') < 3) user_api_reply(0, 'Indica el motivo de la excepción.');

    $now = grade_policy_now_lima();
    $startsAt = $now->format('Y-m-d H:i:s');
    $expiresAt = null;
    if ($expiresRaw !== '') {
        try {
            $expires = new DateTimeImmutable($expiresRaw . ' 23:59:59', new DateTimeZone('America/Lima'));
            if ($expires < $now) user_api_reply(0, 'La fecha de vencimiento de la excepción no puede estar en el pasado.');
            $expiresAt = $expires->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            user_api_reply(0, 'La fecha de vencimiento de la excepción no es válida.');
        }
    }

    $conn->begin_transaction();
    try {
        $del = $conn->prepare('DELETE FROM student_grade_access_exception WHERE school_id = ? AND student_id = ?');
        if (!$del) throw new RuntimeException($conn->error);
        $del->bind_param('ii', $school_id, $studentId);
        if (!$del->execute()) throw new RuntimeException($del->error);
        $del->close();

        $stmt = $conn->prepare(
            'INSERT INTO student_grade_access_exception
             (school_id, student_id, reason, starts_at, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('iisssi', $school_id, $studentId, $reason, $startsAt, $expiresAt, $login_id);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $exceptionId = (int)$stmt->insert_id;
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[grade_policy] exception save failed: ' . $e->getMessage());
        user_api_reply(0, 'No se pudo guardar la excepción.');
    }

    grade_policy_audit($conn, $school_id, $login_id, 'STUDENT_EXCEPTION_CREATED', [
        'exception_id' => $exceptionId,
        'student_id' => $studentId,
        'student_name' => (string)$student['name'],
        'reason' => $reason,
        'expires_at' => $expiresAt
    ]);

    user_api_reply(1, 'Excepción de acceso guardada.', [
        'exception' => [
            'id' => $exceptionId,
            'student_id' => $studentId,
            'student_name' => (string)$student['name'],
            'student_dni' => (string)$student['id_no'],
            'location' => trim((string)$student['nivel'] . ' ' . (string)$student['grado'] . ' ' . (string)$student['seccion']),
            'reason' => $reason,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt
        ]
    ]);
}

if ($action === 'remove_grade_exception') {
    grade_policy_admin_require_schema($conn);
    $exceptionId = (int)($_POST['exception_id'] ?? 0);
    $stmt = $conn->prepare(
        'SELECT e.id, e.student_id, e.reason, s.name AS student_name
         FROM student_grade_access_exception e
         INNER JOIN student s ON s.id = e.student_id AND s.school_id = e.school_id
         WHERE e.id = ? AND e.school_id = ? LIMIT 1'
    );
    if (!$stmt) user_api_reply(0, 'No se pudo consultar la excepción.');
    $stmt->bind_param('ii', $exceptionId, $school_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) user_api_reply(0, 'La excepción ya no existe.');

    $del = $conn->prepare('DELETE FROM student_grade_access_exception WHERE id = ? AND school_id = ?');
    $del->bind_param('ii', $exceptionId, $school_id);
    $ok = $del->execute();
    $del->close();
    if (!$ok) user_api_reply(0, 'No se pudo retirar la excepción.');

    grade_policy_audit($conn, $school_id, $login_id, 'STUDENT_EXCEPTION_REMOVED', [
        'exception_id' => $exceptionId,
        'student_id' => (int)$row['student_id'],
        'student_name' => (string)$row['student_name'],
        'reason' => (string)$row['reason']
    ]);
    user_api_reply(1, 'Excepción retirada.', ['exception_id' => $exceptionId]);
}

if ($action === 'simulate_grade_policy') {
    grade_policy_admin_require_schema($conn);
    user_api_reply(1, 'Simulación completada.', ['simulation' => grade_policy_simulate($conn, $school_id)]);
}

if ($action === 'grade_policy_history') {
    grade_policy_admin_require_schema($conn);
    $stmt = $conn->prepare(
        "SELECT l.id, l.action, l.details, l.ip_address, l.created_at,
                COALESCE(u.name, CONCAT('Usuario #', l.actor_user_id), 'Sistema') AS actor_name
         FROM grade_access_policy_audit_log l
         LEFT JOIN users u ON u.id = l.actor_user_id AND u.school_id = l.school_id
         WHERE l.school_id = ?
         ORDER BY l.id DESC
         LIMIT 100"
    );
    if (!$stmt) user_api_reply(0, 'No se pudo cargar el historial.');
    $stmt->bind_param('i', $school_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $details = json_decode((string)($row['details'] ?? ''), true);
        $row['details'] = is_array($details) ? $details : [];
        $items[] = $row;
    }
    $stmt->close();
    user_api_reply(1, 'Historial cargado.', ['items' => $items]);
}
