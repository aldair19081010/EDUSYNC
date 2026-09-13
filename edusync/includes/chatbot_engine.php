<?php

require_once __DIR__ . '/debt_engine.php';

function edu_chat_table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $q = $conn->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function edu_chat_column_exists(mysqli $conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safe = $conn->real_escape_string($column);
    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$safe'");
    return $q && $q->num_rows > 0;
}

function edu_chat_normalize(string $value): string {
    $value = trim($value);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^a-z0-9\s°]/u', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function edu_chat_has(string $text, array $terms): bool {
    foreach ($terms as $term) {
        if ($term !== '' && strpos($text, $term) !== false) return true;
    }
    return false;
}

function edu_chat_bind(mysqli_stmt $stmt, string $types, array &$params): bool {
    if ($types === '' || !$params) return true;
    $args = [$types];
    foreach ($params as $key => &$value) $args[] = &$value;
    return (bool)call_user_func_array([$stmt, 'bind_param'], $args);
}

function edu_chat_money($amount): string {
    return 'S/ ' . number_format((float)$amount, 2, '.', ',');
}

function edu_chat_action(string $label, string $page, string $icon = 'fa-arrow-right'): array {
    return [
        'label' => $label,
        'url' => 'index.php?page=' . rawurlencode($page),
        'icon' => $icon
    ];
}

function edu_chat_result(string $message, array $followUp = [], array $cards = [], array $actions = []): array {
    return [
        'message' => $message,
        'follow_up' => array_values(array_filter($followUp)),
        'cards' => array_values($cards),
        'actions' => array_values($actions)
    ];
}

function edu_chat_resolve_actor(mysqli $conn): ?array {
    $sessionType = (int)($_SESSION['login_type'] ?? 0);
    $isStudent = !empty($_SESSION['student_logged_in']) || $sessionType === 4;

    if ($isStudent && !empty($_SESSION['student_id'])) {
        $studentId = (int)$_SESSION['student_id'];
        $schoolId = (int)($_SESSION['student_school_id'] ?? ($_SESSION['login_school_id'] ?? 0));
        if ($schoolId > 0) {
            $stmt = $conn->prepare('SELECT id,name,id_no,school_id,nivel,grado,seccion,status FROM student WHERE id=? AND school_id=? LIMIT 1');
            $stmt->bind_param('ii', $studentId, $schoolId);
        } else {
            $stmt = $conn->prepare('SELECT id,name,id_no,school_id,nivel,grado,seccion,status FROM student WHERE id=? LIMIT 1');
            $stmt->bind_param('i', $studentId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        return [
            'kind' => 'student',
            'role' => 'Estudiante',
            'type' => 4,
            'id' => (int)$row['id'],
            'student_id' => (int)$row['id'],
            'teacher_id' => 0,
            'school_id' => (int)$row['school_id'],
            'name' => (string)$row['name'],
            'dni' => (string)$row['id_no'],
            'nivel' => (string)($row['nivel'] ?? ''),
            'grado' => (string)($row['grado'] ?? ''),
            'seccion' => (string)($row['seccion'] ?? '')
        ];
    }

    $userId = (int)($_SESSION['login_id'] ?? 0);
    if ($userId <= 0) return null;

    $teacherSelect = edu_chat_column_exists($conn, 'users', 'teacher_id') ? 'teacher_id' : 'NULL AS teacher_id';
    $directorSelect = edu_chat_column_exists($conn, 'users', 'is_director') ? 'is_director' : '0 AS is_director';
    $stmt = $conn->prepare("SELECT id,name,type,school_id,$teacherSelect,$directorSelect FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $type = (int)$row['type'];
    $role = 'Usuario';
    if ($type === 1) $role = !empty($row['is_director']) ? 'Director' : 'Administrador';
    elseif ($type === 2) $role = 'Docente';
    elseif ($type === 3) $role = 'Auxiliar';

    return [
        'kind' => 'user',
        'role' => $role,
        'type' => $type,
        'id' => (int)$row['id'],
        'student_id' => 0,
        'teacher_id' => (int)($row['teacher_id'] ?? ($_SESSION['login_teacher_id'] ?? 0)),
        'school_id' => (int)$row['school_id'],
        'name' => (string)$row['name'],
        'dni' => '',
        'nivel' => '',
        'grado' => '',
        'seccion' => ''
    ];
}

function edu_chat_suggestions(array $actor): array {
    if ($actor['type'] === 4) {
        return [
            '¿Por qué no puedo ver mis notas?',
            '¿Cuáles son mis deudas?',
            '¿Cuál fue mi último pago?',
            '¿Cómo está mi asistencia este mes?',
            '¿Qué notas tengo?'
        ];
    }
    if ($actor['type'] === 1) {
        return [
            '¿Cuántos estudiantes hay?',
            '¿Cuántos estudiantes tienen deuda?',
            '¿Cuánto se ha cobrado este mes?',
            '¿Cómo está la asistencia hoy?',
            '¿Cuántos estudiantes están en riesgo?'
        ];
    }
    if ($actor['type'] === 2) {
        return [
            '¿Cuáles son mis cursos?',
            '¿Cuántos estudiantes tengo?',
            '¿Cuántos estudiantes están en riesgo?',
            '¿Cómo registro notas?'
        ];
    }
    return [
        '¿Cómo está la asistencia hoy?',
        '¿Cuántos estudiantes hay?',
        '¿Cómo registro asistencia?'
    ];
}

function edu_chat_extract_level(string $text): ?string {
    if (strpos($text, 'secundaria') !== false) return 'Secundaria';
    if (strpos($text, 'primaria') !== false) return 'Primaria';
    if (strpos($text, 'inicial') !== false || strpos($text, 'preescolar') !== false) return 'Inicial';
    return null;
}

function edu_chat_extract_bimester(string $text): ?string {
    $map = [
        'primer bimestre' => '1', 'primero bimestre' => '1', '1er bimestre' => '1', '1ro bimestre' => '1', 'bimestre 1' => '1',
        'segundo bimestre' => '2', '2do bimestre' => '2', 'bimestre 2' => '2',
        'tercer bimestre' => '3', 'tercero bimestre' => '3', '3er bimestre' => '3', '3ro bimestre' => '3', 'bimestre 3' => '3',
        'cuarto bimestre' => '4', '4to bimestre' => '4', 'bimestre 4' => '4'
    ];
    foreach ($map as $token => $value) if (strpos($text, $token) !== false) return $value;
    return null;
}

function edu_chat_extract_grade(string $text): ?string {
    if (preg_match('/\b(?:grado\s*)?([1-6])\s*(?:ro|do|to|er|°)?\s*(?:grado)?\b/', $text, $m)) {
        $matched = $m[0];
        if (strpos($matched, 'bimestre') === false) return (string)$m[1];
    }
    return null;
}

function edu_chat_extract_section(string $text): ?string {
    if (preg_match('/\bseccion\s+([a-z0-9]+)\b/', $text, $m)) return strtoupper($m[1]);
    return null;
}

function edu_chat_extract_period(string $text): ?string {
    if (edu_chat_has($text, ['hoy', 'dia de hoy'])) return 'today';
    if (edu_chat_has($text, ['este mes', 'mes actual', 'del mes'])) return 'month';
    if (edu_chat_has($text, ['este ano', 'ano actual', 'este año'])) return 'year';
    return null;
}

function edu_chat_entities(string $text): array {
    return [
        'level' => edu_chat_extract_level($text),
        'grade' => edu_chat_extract_grade($text),
        'section' => edu_chat_extract_section($text),
        'bimestre' => edu_chat_extract_bimester($text),
        'period' => edu_chat_extract_period($text)
    ];
}

function edu_chat_merge_entities(array $fresh, array $old): array {
    $merged = [];
    foreach (['level','grade','section','bimestre','period','course'] as $key) {
        $merged[$key] = $fresh[$key] ?? null;
        if (($merged[$key] === null || $merged[$key] === '') && !empty($old[$key])) $merged[$key] = $old[$key];
    }
    return $merged;
}

function edu_chat_detect_intent(array $actor, string $text): ?string {
    $how = edu_chat_has($text, ['como ', 'como puedo', 'como hago', 'donde ', 'donde puedo']);
    if ($how && edu_chat_has($text, ['registrar estudiante', 'registrar alumno', 'nuevo estudiante', 'matricular'])) return 'help_register_student';
    if ($how && edu_chat_has($text, ['subir excel', 'cargar excel', 'importar excel', 'plantilla excel'])) return 'help_import_excel';
    if ($how && edu_chat_has($text, ['registrar asistencia', 'tomar asistencia', 'marcar asistencia'])) return 'help_attendance';
    if ($how && edu_chat_has($text, ['desactivar docente', 'activar docente', 'reactivar docente'])) return 'help_teacher_status';
    if ($how && edu_chat_has($text, ['registrar notas', 'poner notas', 'cargar notas', 'libro de notas'])) return 'help_grades';
    if ($how && edu_chat_has($text, ['registrar pago', 'cobrar', 'registrar cobro'])) return 'help_payment';

    if (edu_chat_has($text, ['por que no puedo ver mis notas', 'notas bloqueadas', 'bloqueo de notas', 'no puedo ver notas'])) return 'student_notes_block';

    if ($actor['type'] === 4) {
        if (edu_chat_has($text, ['deuda', 'deudas', 'debo', 'pensiones pendientes', 'saldo pendiente'])) return 'student_debts';
        if (edu_chat_has($text, ['ultimo pago', 'último pago', 'mis pagos', 'recibo', 'recibos', 'cuanto he pagado'])) return 'student_payments';
        if (edu_chat_has($text, ['asistencia', 'asistencias', 'tardanza', 'tardanzas', 'ausencia', 'ausencias'])) return 'student_attendance';
        if (edu_chat_has($text, ['nota', 'notas', 'calificacion', 'calificaciones', 'promedio'])) return 'student_grades';
    }

    if (edu_chat_has($text, ['mis cursos', 'cursos asignados', 'que cursos tengo', 'qué cursos tengo'])) return 'teacher_courses';
    if (edu_chat_has($text, ['cobrado', 'cobranza', 'recaudado', 'recaudacion', 'recaudación', 'ingresos por pagos'])) return 'collections_summary';
    if (edu_chat_has($text, ['deuda', 'deudas', 'morosidad', 'morosos', 'pensiones pendientes'])) return 'debt_summary';
    if (edu_chat_has($text, ['asistencia', 'tardanza', 'tardanzas', 'ausencia', 'ausencias']) && edu_chat_has($text, ['cuantos', 'cuantas', 'resumen', 'como esta', 'cómo está', 'hoy', 'este mes'])) return 'attendance_summary';
    if (edu_chat_has($text, ['riesgo', 'critico', 'criticos', 'critica', 'criticas', 'nota baja', 'notas bajas', 'reprobado', 'reprobados'])) return 'academic_risk';
    if (edu_chat_has($text, ['docente', 'docentes', 'profesor', 'profesores']) && edu_chat_has($text, ['cuantos', 'cuantas', 'cantidad', 'total', 'hay'])) return 'count_teachers';
    if (edu_chat_has($text, ['estudiante', 'estudiantes', 'alumno', 'alumnos']) && edu_chat_has($text, ['cuantos', 'cuantas', 'cantidad', 'total', 'hay', 'tengo'])) return 'count_students';
    if (edu_chat_has($text, ['ayuda', 'que puedes hacer', 'qué puedes hacer', 'opciones', 'que haces', 'qué haces'])) return 'help';
    return null;
}

function edu_chat_is_followup(string $text): bool {
    $words = preg_split('/\s+/', trim($text));
    if (count($words) <= 5 && (strpos($text, 'y ') === 0 || strpos($text, 'ahora ') === 0)) return true;
    return edu_chat_has($text, ['ademas', 'tambien', 'y ahora', 'eso mismo', 'igual', 'en primaria', 'en secundaria', 'este mes', 'hoy']);
}

function edu_chat_interpret(mysqli $conn, array $actor, string $message, array $context): array {
    $text = edu_chat_normalize($message);
    $intent = edu_chat_detect_intent($actor, $text);
    $fresh = edu_chat_entities($text);

    if ($intent === null && !empty($context['last_intent']) && edu_chat_is_followup($text)) {
        $intent = (string)$context['last_intent'];
    }

    $sameIntent = $intent !== null && $intent === ($context['last_intent'] ?? null);
    $entities = $sameIntent || ($intent !== null && edu_chat_is_followup($text))
        ? edu_chat_merge_entities($fresh, (array)($context['entities'] ?? []))
        : $fresh;

    if (in_array($intent, ['student_grades','academic_risk'], true)) {
        $entities['course'] = edu_chat_find_course($conn, (int)$actor['school_id'], $text);
        if (!$entities['course'] && $sameIntent && !empty($context['entities']['course'])) $entities['course'] = $context['entities']['course'];
    }

    return ['intent' => $intent ?: 'unknown', 'entities' => $entities, 'normalized' => $text];
}

function edu_chat_find_course(mysqli $conn, int $schoolId, string $text): ?string {
    if (!edu_chat_table_exists($conn, 'academic_courses') || !edu_chat_column_exists($conn, 'academic_courses', 'name')) return null;
    $hasSchool = edu_chat_column_exists($conn, 'academic_courses', 'school_id');
    if ($hasSchool && $schoolId > 0) {
        $stmt = $conn->prepare('SELECT DISTINCT name FROM academic_courses WHERE school_id=? AND name IS NOT NULL ORDER BY LENGTH(name) DESC');
        $stmt->bind_param('i', $schoolId);
    } else {
        $stmt = $conn->prepare('SELECT DISTINCT name FROM academic_courses WHERE name IS NOT NULL ORDER BY LENGTH(name) DESC');
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $found = null;
    while ($row = $res->fetch_assoc()) {
        $name = trim((string)$row['name']);
        if ($name === '') continue;
        $normalized = edu_chat_normalize($name);
        if ($normalized !== '' && strpos($text, $normalized) !== false) { $found = $name; break; }
        $tokens = array_values(array_filter(preg_split('/\s+/', $normalized), static fn($v) => mb_strlen($v) >= 5));
        if ($tokens && edu_chat_has($text, $tokens)) { $found = $name; break; }
    }
    $stmt->close();
    return $found;
}

function edu_chat_allowed(array $actor, string $intent): bool {
    $type = (int)$actor['type'];
    $commonHelp = ['help','help_register_student','help_import_excel','help_attendance','help_teacher_status','help_grades','help_payment'];
    if (in_array($intent, $commonHelp, true)) return true;
    if ($type === 4) return in_array($intent, ['student_notes_block','student_debts','student_payments','student_attendance','student_grades'], true);
    if ($type === 1) return in_array($intent, ['count_students','count_teachers','debt_summary','collections_summary','attendance_summary','academic_risk'], true);
    if ($type === 2) return in_array($intent, ['count_students','academic_risk','teacher_courses'], true);
    if ($type === 3) return in_array($intent, ['count_students','attendance_summary'], true);
    return false;
}

function edu_chat_active_year(mysqli $conn, int $schoolId): ?array {
    if (!edu_chat_table_exists($conn, 'academic_year')) return null;
    $stmt = $conn->prepare('SELECT id,year,start_date,end_date FROM academic_year WHERE school_id=? ORDER BY is_active DESC,year DESC,id DESC LIMIT 1');
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function edu_chat_student_debt_result(mysqli $conn, array $actor, bool $explainBlock = false): array {
    $pending = debt_engine_pending_debts(debt_engine_get_student_debts($conn, (int)$actor['student_id'], (int)$actor['school_id']));
    $summary = debt_engine_summary($pending);
    $count = (int)$summary['count_concepts'];
    $total = (float)$summary['total_debt'];

    if ($explainBlock) {
        if ($count >= 2) {
            $message = 'Mis Notas está bloqueado porque actualmente tienes ' . $count . ' obligaciones pendientes por ' . edu_chat_money($total) . '. El acceso a notas se habilita cuando quedan menos de 2 deudas pendientes.';
        } else {
            $message = 'No detecto un bloqueo de notas por deuda: actualmente tienes ' . $count . ' obligación pendiente. Si Mis Notas sigue sin abrir, puede tratarse de otro problema del módulo.';
        }
    } elseif ($count === 0) {
        $message = 'No tienes deudas pendientes registradas en este momento.';
    } else {
        $message = 'Tienes ' . $count . ' obligación' . ($count === 1 ? '' : 'es') . ' pendiente' . ($count === 1 ? '' : 's') . ' por un total de ' . edu_chat_money($total) . '.';
        if (!empty($summary['overdue_count'])) $message .= ' De ellas, ' . (int)$summary['overdue_count'] . ' están vencidas.';
    }

    return edu_chat_result($message,
        ['¿Cuál fue mi último pago?', '¿Cómo está mi asistencia este mes?', '¿Qué notas tengo?'],
        [
            ['label' => 'Obligaciones', 'value' => (string)$count, 'tone' => $count >= 2 ? 'warning' : 'primary'],
            ['label' => 'Saldo pendiente', 'value' => edu_chat_money($total), 'tone' => $total > 0 ? 'warning' : 'success']
        ],
        [edu_chat_action('Ver Mis Deudas', 'student_debts', 'fa-exclamation-triangle')]
    );
}

function edu_chat_student_payment_result(mysqli $conn, array $actor): array {
    if (!edu_chat_table_exists($conn, 'payments') || !edu_chat_table_exists($conn, 'student_ef_list')) {
        return edu_chat_result('El historial de pagos no está disponible en este momento.');
    }
    $studentId = (int)$actor['student_id'];
    $hasStatus = edu_chat_column_exists($conn, 'payments', 'payment_status');
    $hasOperationId = edu_chat_column_exists($conn, 'payments', 'operation_id');
    $hasOperations = $hasOperationId && edu_chat_table_exists($conn, 'payment_operations');
    $paymentFilter = $hasStatus ? " AND COALESCE(p.payment_status,'Confirmado')='Confirmado'" : '';
    $join = '';
    $validOperation = '';
    $dateExpr = 'p.date_created';
    $receiptExpr = "COALESCE(NULLIF(p.receipt_no,''),CONCAT('PAGO-',p.id))";
    $groupKey = "CONCAT('P',p.id)";
    if ($hasOperations) {
        $join = ' LEFT JOIN payment_operations po ON po.id=p.operation_id';
        $statusFilter = edu_chat_column_exists($conn, 'payment_operations', 'status') ? " AND (po.status IS NULL OR (LOWER(po.status) NOT LIKE '%anul%' AND LOWER(po.status) NOT LIKE '%cancel%' AND LOWER(po.status) NOT LIKE '%correg%'))" : '';
        $correctionFilter = edu_chat_column_exists($conn, 'payment_operations', 'corrected_by_id') ? ' AND po.corrected_by_id IS NULL' : '';
        $validOperation = " AND (p.operation_id IS NULL OR (po.id IS NOT NULL$statusFilter$correctionFilter))";
        if (edu_chat_column_exists($conn, 'payment_operations', 'payment_date')) $dateExpr = 'COALESCE(po.payment_date,p.date_created)';
        if (edu_chat_column_exists($conn, 'payment_operations', 'receipt_full')) $receiptExpr = "COALESCE(NULLIF(po.receipt_full,''),NULLIF(p.receipt_no,''),CONCAT('PAGO-',p.id))";
        $groupKey = "CASE WHEN p.operation_id IS NULL THEN CONCAT('P',p.id) ELSE CONCAT('O',p.operation_id) END";
    }

    $sql = "SELECT COUNT(DISTINCT $groupKey) operations,COALESCE(SUM(p.amount),0) total_paid,MAX($dateExpr) last_date FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id $join WHERE ef.student_id=? $paymentFilter $validOperation";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $sql = "SELECT $groupKey payment_key,$receiptExpr receipt,$dateExpr payment_date,SUM(p.amount) amount FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id $join WHERE ef.student_id=? $paymentFilter $validOperation GROUP BY payment_key,receipt,payment_date ORDER BY payment_date DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $count = (int)($summary['operations'] ?? 0);
    $total = (float)($summary['total_paid'] ?? 0);
    if (!$last) $message = 'Todavía no tienes pagos confirmados registrados.';
    else $message = 'Tu último pago confirmado fue el ' . date('d/m/Y', strtotime((string)$last['payment_date'])) . ' por ' . edu_chat_money($last['amount']) . ', recibo ' . (string)$last['receipt'] . '. En total tienes ' . $count . ' comprobante' . ($count === 1 ? '' : 's') . ' confirmado' . ($count === 1 ? '' : 's') . '.';

    return edu_chat_result($message,
        ['¿Cuáles son mis deudas?', '¿Cómo está mi asistencia este mes?'],
        [
            ['label' => 'Total pagado', 'value' => edu_chat_money($total), 'tone' => 'success'],
            ['label' => 'Comprobantes', 'value' => (string)$count, 'tone' => 'primary']
        ],
        [edu_chat_action('Ver Mis Pagos', 'student_payments', 'fa-credit-card')]
    );
}

function edu_chat_normalize_attendance_status(string $status): string {
    $value = edu_chat_normalize($status);
    if (in_array($value, ['normal','temprano','presente'], true)) return 'Presente';
    if ($value === 'tarde') return 'Tarde';
    if (in_array($value, ['ausente','ausencia'], true)) return 'Ausente';
    if (in_array($value, ['ausente justificada','ausencia justificada'], true)) return 'Ausente Justificada';
    if ($value === 'permiso') return 'Permiso';
    return $status !== '' ? $status : 'Sin estado';
}

function edu_chat_student_attendance_result(mysqli $conn, array $actor, array $entities): array {
    if (!edu_chat_table_exists($conn, 'asistencia')) return edu_chat_result('El módulo de asistencia no está disponible.');
    $studentId = (int)$actor['student_id'];
    $schoolId = (int)$actor['school_id'];
    $period = $entities['period'] ?? 'month';
    $today = date('Y-m-d');
    $start = date('Y-m-01');
    $end = date('Y-m-t');
    $label = date('m/Y');
    if ($period === 'today') { $start = $today; $end = $today; $label = 'hoy'; }
    elseif ($period === 'year') {
        $year = edu_chat_active_year($conn, $schoolId);
        $start = !empty($year['start_date']) ? $year['start_date'] : date('Y-01-01');
        $end = !empty($year['end_date']) ? $year['end_date'] : date('Y-12-31');
        $label = !empty($year['year']) ? (string)$year['year'] : date('Y');
    }

    $schoolFilter = edu_chat_column_exists($conn, 'asistencia', 'school_id') ? ' AND a.school_id=?' : '';
    $cancelFilter = edu_chat_column_exists($conn, 'asistencia', 'is_cancelled') ? ' AND COALESCE(a.is_cancelled,0)=0' : '';
    $sql = "SELECT a.fecha,a.estado FROM asistencia a WHERE a.student_id=? AND a.tipo='Entrada' AND a.fecha BETWEEN ? AND ? $schoolFilter $cancelFilter ORDER BY a.fecha";
    $stmt = $conn->prepare($sql);
    if ($schoolFilter !== '') $stmt->bind_param('issi', $studentId, $start, $end, $schoolId);
    else $stmt->bind_param('iss', $studentId, $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    $byDay = [];
    while ($row = $res->fetch_assoc()) {
        $date = (string)$row['fecha'];
        if (!isset($byDay[$date])) $byDay[$date] = edu_chat_normalize_attendance_status((string)$row['estado']);
    }
    $stmt->close();

    $counts = ['Presente'=>0,'Tarde'=>0,'Ausente'=>0,'Ausente Justificada'=>0,'Permiso'=>0];
    foreach ($byDay as $status) if (isset($counts[$status])) $counts[$status]++;
    $days = count($byDay);
    $attended = $counts['Presente'] + $counts['Tarde'];
    $rate = $days > 0 ? round(($attended / $days) * 100, 1) : 0;
    $message = $days === 0
        ? 'No hay asistencias registradas para ' . $label . '.'
        : 'Para ' . $label . ' tienes ' . $days . ' día' . ($days === 1 ? '' : 's') . ' registrado' . ($days === 1 ? '' : 's') . ': ' . $counts['Presente'] . ' presente' . ($counts['Presente'] === 1 ? '' : 's') . ', ' . $counts['Tarde'] . ' tardanza' . ($counts['Tarde'] === 1 ? '' : 's') . ' y ' . $counts['Ausente'] . ' ausencia' . ($counts['Ausente'] === 1 ? '' : 's') . '.';

    return edu_chat_result($message,
        ['¿Cuáles son mis deudas?', '¿Qué notas tengo?'],
        [
            ['label' => 'Asistencia', 'value' => number_format($rate, 1) . '%', 'tone' => $rate >= 90 ? 'success' : 'warning'],
            ['label' => 'Tardanzas', 'value' => (string)$counts['Tarde'], 'tone' => $counts['Tarde'] > 0 ? 'warning' : 'success'],
            ['label' => 'Ausencias', 'value' => (string)$counts['Ausente'], 'tone' => $counts['Ausente'] > 0 ? 'danger' : 'success']
        ],
        [edu_chat_action('Ver Mis Asistencias', 'student_attendances', 'fa-clipboard-list')]
    );
}

function edu_chat_student_grades_result(mysqli $conn, array $actor, array $entities): array {
    require_once dirname(__DIR__) . '/api/grade_debt_guard.php';
    $debt = grade_debt_blocks_grades($conn, (int)$actor['student_id']);
    if (!empty($debt['blocked'])) return edu_chat_student_debt_result($conn, $actor, true);
    foreach (['evaluation_grades','evaluations','teacher_courses','academic_courses'] as $table) if (!edu_chat_table_exists($conn, $table)) return edu_chat_result('El módulo de notas no está disponible.');

    $studentId = (int)$actor['student_id'];
    $schoolId = (int)$actor['school_id'];
    $year = edu_chat_active_year($conn, $schoolId);
    $yearId = (int)($year['id'] ?? 0);
    $where = ['eg.student_id=?'];
    $types = 'i';
    $params = [$studentId];
    if ($yearId > 0 && edu_chat_column_exists($conn, 'teacher_courses', 'academic_year_id')) { $where[] = 'tc.academic_year_id=?'; $types .= 'i'; $params[] = $yearId; }
    if (!empty($entities['bimestre']) && edu_chat_column_exists($conn, 'evaluations', 'bimestre')) { $where[] = 'e.bimestre=?'; $types .= 's'; $params[] = $entities['bimestre']; }
    if (!empty($entities['course'])) { $where[] = 'ac.name=?'; $types .= 's'; $params[] = $entities['course']; }
    $bimSelect = edu_chat_column_exists($conn, 'evaluations', 'bimestre') ? 'e.bimestre' : "''";
    $sql = "SELECT ac.name course,e.title,$bimSelect bimestre,eg.grade,e.id evaluation_id FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id LEFT JOIN teacher_courses tc ON tc.id=e.teacher_course_id LEFT JOIN academic_courses ac ON ac.id=tc.course_id WHERE " . implode(' AND ', $where) . " ORDER BY e.id DESC LIMIT 30";
    $stmt = $conn->prepare($sql);
    edu_chat_bind($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    if (!$rows) return edu_chat_result('No encontré notas registradas con esos filtros.', ['¿Cuáles son mis deudas?', '¿Cómo está mi asistencia este mes?'], [], [edu_chat_action('Abrir Mis Notas','student_grades','fa-graduation-cap')]);

    $seen = [];
    $lines = [];
    foreach ($rows as $row) {
        $course = trim((string)($row['course'] ?: 'Curso'));
        $key = $course . '|' . (string)$row['bimestre'] . '|' . (string)$row['grade'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $bim = trim((string)$row['bimestre']);
        $lines[] = $course . ': ' . (string)$row['grade'] . ($bim !== '' ? ' · Bim. ' . $bim : '');
        if (count($lines) >= 6) break;
    }
    $message = 'Estos son tus registros de evaluación más recientes' . (!empty($entities['course']) ? ' de ' . $entities['course'] : '') . ":\n• " . implode("\n• ", $lines);
    return edu_chat_result($message, ['¿Cómo está mi asistencia este mes?', '¿Cuál fue mi último pago?'], [['label'=>'Registros encontrados','value'=>(string)count($rows),'tone'=>'primary']], [edu_chat_action('Abrir Mis Notas','student_grades','fa-graduation-cap')]);
}

function edu_chat_count_students_result(mysqli $conn, array $actor, array $entities): array {
    $schoolId = (int)$actor['school_id'];
    if ($actor['type'] === 2) {
        $teacherId = (int)$actor['teacher_id'];
        if ($teacherId <= 0) return edu_chat_result('No pude identificar tu ficha docente.');
        $year = edu_chat_active_year($conn, $schoolId);
        $yearId = (int)($year['id'] ?? 0);
        $where = ['tc.teacher_id=?','tc.school_id=?',"s.status='Activo'",'s.school_id=?','s.grado=tc.grado','s.seccion=tc.seccion'];
        $types = 'iii'; $params = [$teacherId,$schoolId,$schoolId];
        if ($yearId > 0) { $where[] = 'tc.academic_year_id=?'; $types .= 'i'; $params[] = $yearId; }
        if (!empty($entities['level'])) { $where[] = 's.nivel=?'; $types .= 's'; $params[] = $entities['level']; }
        if (!empty($entities['grade'])) { $where[] = 's.grado=?'; $types .= 's'; $params[] = $entities['grade']; }
        $sql = 'SELECT COUNT(DISTINCT s.id) total FROM teacher_courses tc LEFT JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN student s ON s.school_id=tc.school_id AND s.grado=tc.grado AND s.seccion=tc.seccion WHERE ' . implode(' AND ', $where);
        $stmt = $conn->prepare($sql); edu_chat_bind($stmt,$types,$params); $stmt->execute(); $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0); $stmt->close();
        return edu_chat_result('Tienes ' . $total . ' estudiante' . ($total === 1 ? '' : 's') . ' vinculado' . ($total === 1 ? '' : 's') . ' a tus asignaciones' . (!empty($entities['level']) ? ' de ' . strtolower($entities['level']) : '') . '.', ['¿Cuáles son mis cursos?', '¿Cuántos estudiantes están en riesgo?'], [['label'=>'Estudiantes','value'=>(string)$total,'tone'=>'primary']], [edu_chat_action('Ver Mis Cursos','my_courses','fa-book')]);
    }

    $where = ['school_id=?',"status='Activo'"]; $types='i'; $params=[$schoolId];
    if (!empty($entities['level'])) { $where[]='nivel=?'; $types.='s'; $params[]=$entities['level']; }
    if (!empty($entities['grade'])) { $where[]='grado=?'; $types.='s'; $params[]=$entities['grade']; }
    if (!empty($entities['section'])) { $where[]='seccion=?'; $types.='s'; $params[]=$entities['section']; }
    $stmt=$conn->prepare('SELECT COUNT(*) total FROM student WHERE '.implode(' AND ',$where)); edu_chat_bind($stmt,$types,$params); $stmt->execute(); $total=(int)($stmt->get_result()->fetch_assoc()['total']??0); $stmt->close();
    $scope = !empty($entities['level']) ? ' de ' . strtolower($entities['level']) : '';
    if (!empty($entities['grade'])) $scope .= ' de ' . $entities['grade'] . '° grado';
    return edu_chat_result('Hay ' . $total . ' estudiantes activos' . $scope . '.', ['¿Cuántos estudiantes tienen deuda?', '¿Cómo está la asistencia hoy?'], [['label'=>'Estudiantes activos','value'=>(string)$total,'tone'=>'primary']], $actor['type']===1 ? [edu_chat_action('Ver Estudiantes','students','fa-users')] : []);
}

function edu_chat_count_teachers_result(mysqli $conn, array $actor): array {
    $stmt=$conn->prepare("SELECT COUNT(*) total,SUM(status='Activo') activos,SUM(status='Inactivo') inactivos FROM teacher WHERE school_id=?");
    $school=(int)$actor['school_id']; $stmt->bind_param('i',$school); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc()?:[]; $stmt->close();
    $total=(int)($row['total']??0); $active=(int)($row['activos']??0); $inactive=(int)($row['inactivos']??0);
    return edu_chat_result("Hay $total docentes: $active activos y $inactive inactivos.", ['¿Cuántos estudiantes hay?'], [['label'=>'Docentes activos','value'=>(string)$active,'tone'=>'success'],['label'=>'Inactivos','value'=>(string)$inactive,'tone'=>$inactive?'warning':'success']], [edu_chat_action('Ver Docentes','teachers','fa-chalkboard-teacher')]);
}

function edu_chat_debt_summary_result(mysqli $conn, array $actor, array $entities): array {
    if (!debt_engine_available($conn)) return edu_chat_result('El módulo financiero no está disponible.');
    $hasDebtStatus=debt_engine_column_exists($conn,'student_ef_list','debt_status');
    $hasPaymentStatus=debt_engine_column_exists($conn,'payments','payment_status');
    $join="LEFT JOIN payments p ON p.ef_id=ef.id" . ($hasPaymentStatus ? " AND COALESCE(p.payment_status,'Confirmado')='Confirmado'" : '');
    $where=['s.school_id=?',"s.status='Activo'"]; $types='i'; $params=[(int)$actor['school_id']];
    if ($hasDebtStatus) $where[]="ef.debt_status='Activa'";
    if (!empty($entities['level'])) { $where[]='s.nivel=?'; $types.='s'; $params[]=$entities['level']; }
    if (!empty($entities['grade'])) { $where[]='s.grado=?'; $types.='s'; $params[]=$entities['grade']; }
    $sql="SELECT COUNT(DISTINCT x.student_id) students,COUNT(*) debts,COALESCE(SUM(x.balance),0) total FROM (SELECT s.id student_id,ef.id,GREATEST(COALESCE(ef.discounted_amount,ef.total_fee)-COALESCE(SUM(p.amount),0),0) balance FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id $join WHERE ".implode(' AND ',$where)." GROUP BY s.id,ef.id,ef.discounted_amount,ef.total_fee HAVING balance>0.009) x";
    $stmt=$conn->prepare($sql); edu_chat_bind($stmt,$types,$params); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc()?:[]; $stmt->close();
    $students=(int)($row['students']??0); $debts=(int)($row['debts']??0); $total=(float)($row['total']??0);
    $scope=!empty($entities['level'])?' de '.strtolower($entities['level']):'';
    return edu_chat_result("Hay $students estudiantes$scope con deuda pendiente. En conjunto tienen $debts obligaciones por ".edu_chat_money($total).'.', ['¿Cuánto se ha cobrado este mes?', '¿Cómo está la asistencia hoy?'], [['label'=>'Estudiantes con deuda','value'=>(string)$students,'tone'=>'warning'],['label'=>'Obligaciones','value'=>(string)$debts,'tone'=>'warning'],['label'=>'Saldo pendiente','value'=>edu_chat_money($total),'tone'=>'danger']], [edu_chat_action('Ver Reporte de Deudas','debt_reports','fa-exclamation-circle')]);
}

function edu_chat_collections_result(mysqli $conn, array $actor, array $entities): array {
    if (!edu_chat_table_exists($conn,'payments') || !edu_chat_table_exists($conn,'student_ef_list')) return edu_chat_result('El módulo de pagos no está disponible.');
    $school=(int)$actor['school_id']; $period=$entities['period']??'month'; $start=date('Y-m-01'); $end=date('Y-m-t'); $label='este mes';
    if ($period==='today') { $start=date('Y-m-d'); $end=$start; $label='hoy'; }
    elseif ($period==='year') { $year=edu_chat_active_year($conn,$school); $start=$year['start_date']??date('Y-01-01'); $end=$year['end_date']??date('Y-12-31'); $label='este año'; }
    $hasStatus=edu_chat_column_exists($conn,'payments','payment_status'); $hasOpId=edu_chat_column_exists($conn,'payments','operation_id'); $hasOps=$hasOpId&&edu_chat_table_exists($conn,'payment_operations');
    $join=$hasOps?' LEFT JOIN payment_operations po ON po.id=p.operation_id':''; $dateExpr='p.date_created'; $valid='';
    if ($hasOps) {
        if (edu_chat_column_exists($conn,'payment_operations','payment_date')) $dateExpr='COALESCE(po.payment_date,p.date_created)';
        $valid=" AND (p.operation_id IS NULL OR po.id IS NOT NULL)";
        if (edu_chat_column_exists($conn,'payment_operations','status')) $valid.=" AND (p.operation_id IS NULL OR (LOWER(COALESCE(po.status,'')) NOT LIKE '%anul%' AND LOWER(COALESCE(po.status,'')) NOT LIKE '%cancel%' AND LOWER(COALESCE(po.status,'')) NOT LIKE '%correg%'))";
        if (edu_chat_column_exists($conn,'payment_operations','corrected_by_id')) $valid.=' AND (p.operation_id IS NULL OR po.corrected_by_id IS NULL)';
    }
    $statusFilter=$hasStatus?" AND COALESCE(p.payment_status,'Confirmado')='Confirmado'":'';
    $levelFilter=!empty($entities['level'])?' AND s.nivel=?':'';
    $sql="SELECT COALESCE(SUM(p.amount),0) total,COUNT(DISTINCT CASE WHEN ".($hasOpId?"p.operation_id IS NOT NULL THEN CONCAT('O',p.operation_id) ELSE ":'1=0 THEN NULL ELSE ')."CONCAT('P',p.id) END) operations FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id INNER JOIN student s ON s.id=ef.student_id $join WHERE s.school_id=? AND $dateExpr BETWEEN ? AND ? $statusFilter $valid $levelFilter";
    $params=[$school,$start.' 00:00:00',$end.' 23:59:59']; $types='iss'; if ($levelFilter!=='') { $types.='s'; $params[]=$entities['level']; }
    $stmt=$conn->prepare($sql); edu_chat_bind($stmt,$types,$params); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc()?:[]; $stmt->close();
    $total=(float)($row['total']??0); $ops=(int)($row['operations']??0); $scope=!empty($entities['level'])?' en '.strtolower($entities['level']):'';
    return edu_chat_result('Se ha cobrado '.edu_chat_money($total)." $label$scope en $ops operación".($ops===1?'':'es').' confirmada'.($ops===1?'':'s').'.', ['¿Cuántos estudiantes tienen deuda?', '¿Cómo está la asistencia hoy?'], [['label'=>'Cobrado','value'=>edu_chat_money($total),'tone'=>'success'],['label'=>'Operaciones','value'=>(string)$ops,'tone'=>'primary']], [edu_chat_action('Ver Reporte de Pagos','payments_report','fa-chart-pie')]);
}

function edu_chat_attendance_summary_result(mysqli $conn, array $actor, array $entities): array {
    if (!edu_chat_table_exists($conn,'asistencia')) return edu_chat_result('El módulo de asistencia no está disponible.');
    $school=(int)$actor['school_id']; $period=$entities['period']??'today'; $start=date('Y-m-d'); $end=$start; $label='hoy';
    if ($period==='month') { $start=date('Y-m-01'); $end=date('Y-m-t'); $label='este mes'; }
    elseif ($period==='year') { $year=edu_chat_active_year($conn,$school); $start=$year['start_date']??date('Y-01-01'); $end=$year['end_date']??date('Y-12-31'); $label='este año'; }
    $where=["a.tipo='Entrada'",'a.fecha BETWEEN ? AND ?']; $types='ss'; $params=[$start,$end];
    if (edu_chat_column_exists($conn,'asistencia','school_id')) { $where[]='a.school_id=?'; $types.='i'; $params[]=$school; } else { $where[]='s.school_id=?'; $types.='i'; $params[]=$school; }
    if (edu_chat_column_exists($conn,'asistencia','is_cancelled')) $where[]='COALESCE(a.is_cancelled,0)=0';
    if (!empty($entities['level'])) { $where[]='s.nivel=?'; $types.='s'; $params[]=$entities['level']; }
    if (!empty($entities['grade'])) { $where[]='s.grado=?'; $types.='s'; $params[]=$entities['grade']; }
    $sql='SELECT a.student_id,a.fecha,a.estado FROM asistencia a INNER JOIN student s ON s.id=a.student_id WHERE '.implode(' AND ',$where).' ORDER BY a.fecha';
    $stmt=$conn->prepare($sql); edu_chat_bind($stmt,$types,$params); $stmt->execute(); $res=$stmt->get_result(); $days=[];
    while($r=$res->fetch_assoc()){ $key=$r['student_id'].'|'.$r['fecha']; if(!isset($days[$key]))$days[$key]=edu_chat_normalize_attendance_status((string)$r['estado']); } $stmt->close();
    $c=['Presente'=>0,'Tarde'=>0,'Ausente'=>0,'Ausente Justificada'=>0,'Permiso'=>0]; foreach($days as $s)if(isset($c[$s]))$c[$s]++;
    $total=count($days); $scope=!empty($entities['level'])?' en '.strtolower($entities['level']):'';
    $message="La asistencia $label$scope registra $total controles: {$c['Presente']} presentes, {$c['Tarde']} tardanzas y {$c['Ausente']} ausencias.";
    return edu_chat_result($message, ['¿Cuántos estudiantes hay?', '¿Cuántos estudiantes tienen deuda?'], [['label'=>'Presentes','value'=>(string)$c['Presente'],'tone'=>'success'],['label'=>'Tardanzas','value'=>(string)$c['Tarde'],'tone'=>$c['Tarde']?'warning':'success'],['label'=>'Ausencias','value'=>(string)$c['Ausente'],'tone'=>$c['Ausente']?'danger':'success']], [edu_chat_action('Abrir Asistencia','asistencia','fa-calendar-check')]);
}

function edu_chat_teacher_courses_result(mysqli $conn, array $actor): array {
    $teacher=(int)$actor['teacher_id']; if($teacher<=0)return edu_chat_result('No pude identificar tu ficha docente.');
    $year=edu_chat_active_year($conn,(int)$actor['school_id']); $yearId=(int)($year['id']??0);
    $where=['tc.teacher_id=?','tc.school_id=?']; $types='ii'; $params=[$teacher,(int)$actor['school_id']]; if($yearId>0){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    $stmt=$conn->prepare('SELECT ac.name,ac.level,tc.grado,tc.seccion FROM teacher_courses tc LEFT JOIN academic_courses ac ON ac.id=tc.course_id WHERE '.implode(' AND ',$where).' ORDER BY ac.name,tc.grado,tc.seccion'); edu_chat_bind($stmt,$types,$params); $stmt->execute(); $res=$stmt->get_result(); $rows=[]; while($r=$res->fetch_assoc())$rows[]=$r; $stmt->close();
    if(!$rows)return edu_chat_result('No tienes cursos asignados en el año académico actual.',[],[],[edu_chat_action('Ver Mis Cursos','my_courses','fa-book')]);
    $lines=[]; foreach($rows as $r){$lines[]=(string)($r['name']?:'Curso').' · '.$r['grado'].'° '.$r['seccion']; if(count($lines)>=6)break;}
    return edu_chat_result('Tienes '.count($rows)." asignación".(count($rows)===1?'':'es').":\n• ".implode("\n• ",$lines), ['¿Cuántos estudiantes tengo?', '¿Cuántos estudiantes están en riesgo?'], [['label'=>'Asignaciones','value'=>(string)count($rows),'tone'=>'primary']], [edu_chat_action('Ver Mis Cursos','my_courses','fa-book')]);
}

function edu_chat_academic_risk_result(mysqli $conn, array $actor, array $entities): array {
    foreach(['evaluation_grades','evaluations','teacher_courses','student'] as $table)if(!edu_chat_table_exists($conn,$table))return edu_chat_result('No está disponible la información académica necesaria.');
    $where=['s.school_id=?',"s.status='Activo'","((eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(eg.grade AS DECIMAL(10,2))<=10) OR UPPER(TRIM(eg.grade))='C')"]; $types='i'; $params=[(int)$actor['school_id']];
    if($actor['type']===2){$where[]='tc.teacher_id=?';$types.='i';$params[]=(int)$actor['teacher_id'];}
    if(!empty($entities['level'])){$where[]='s.nivel=?';$types.='s';$params[]=$entities['level'];}
    if(!empty($entities['grade'])){$where[]='s.grado=?';$types.='s';$params[]=$entities['grade'];}
    if(!empty($entities['bimestre'])&&edu_chat_column_exists($conn,'evaluations','bimestre')){$where[]='e.bimestre=?';$types.='s';$params[]=$entities['bimestre'];}
    if(!empty($entities['course'])){$where[]='ac.name=?';$types.='s';$params[]=$entities['course'];}
    $sql='SELECT COUNT(DISTINCT s.id) students,COUNT(*) records FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id INNER JOIN evaluations e ON e.id=eg.evaluation_id LEFT JOIN teacher_courses tc ON tc.id=e.teacher_course_id LEFT JOIN academic_courses ac ON ac.id=tc.course_id WHERE '.implode(' AND ',$where);
    $stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$row=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();$students=(int)($row['students']??0);$records=(int)($row['records']??0);
    $scope='';if(!empty($entities['level']))$scope.=' de '.strtolower($entities['level']);if(!empty($entities['course']))$scope.=' en '.$entities['course'];if(!empty($entities['bimestre']))$scope.=' en el '.$entities['bimestre'].'° bimestre';
    return edu_chat_result("Hay $students estudiantes$scope con al menos un registro crítico (C o nota numérica de 10 o menos).", ['¿Cuántos estudiantes tengo?', '¿Cuáles son mis cursos?'], [['label'=>'Estudiantes en riesgo','value'=>(string)$students,'tone'=>$students?'danger':'success'],['label'=>'Registros críticos','value'=>(string)$records,'tone'=>$records?'warning':'success']], [edu_chat_action('Ver Reporte de Notas','grades_report','fa-chart-bar')]);
}

function edu_chat_help_result(array $actor, string $intent): array {
    $type=(int)$actor['type'];
    if($intent==='help_register_student'){
        if($type!==1)return edu_chat_result('El registro de estudiantes está disponible únicamente para administración.');
        return edu_chat_result('Ve a Estudiantes y selecciona Nuevo Estudiante. Completa los datos requeridos y guarda el registro.', ['¿Cómo subo un Excel?'], [], [edu_chat_action('Ir a Estudiantes','students','fa-users')]);
    }
    if($intent==='help_import_excel'){
        if($type!==1)return edu_chat_result('La importación de estudiantes por Excel está disponible únicamente para administración.');
        return edu_chat_result('En Estudiantes abre Agregar y elige Subir Excel. Usa la plantilla del sistema y selecciona el año académico antes de importar.', ['¿Cómo registro un estudiante?'], [], [edu_chat_action('Ir a Estudiantes','students','fa-file-excel')]);
    }
    if($intent==='help_attendance'){
        if(!in_array($type,[1,3],true))return edu_chat_result('La toma de asistencia corresponde a administración o auxiliar. Como estudiante puedes consultar Mis Asistencias.');
        return edu_chat_result('Abre Asistencia, selecciona fecha, nivel, grado y sección, registra los estados y guarda la nómina. Las tardanzas respetan las reglas horarias configuradas.', ['¿Cómo está la asistencia hoy?'], [], [edu_chat_action('Abrir Asistencia','asistencia','fa-calendar-check')]);
    }
    if($intent==='help_teacher_status'){
        if($type!==1)return edu_chat_result('La activación o desactivación de docentes está disponible únicamente para administración.');
        return edu_chat_result('En Docentes usa la acción de estado para desactivar o reactivar al docente. Su información histórica se conserva.', [], [], [edu_chat_action('Ver Docentes','teachers','fa-chalkboard-teacher')]);
    }
    if($intent==='help_grades'){
        if(!in_array($type,[1,2],true))return edu_chat_result('El registro de notas corresponde a administración o docentes. Como estudiante puedes consultar Mis Notas.');
        return edu_chat_result('Abre Libro de Notas, selecciona curso, nivel, grado, sección y bimestre. Puedes registrar las calificaciones en la grilla y usar autoguardado o guardado manual.', [], [], [edu_chat_action('Abrir Libro de Notas','grades','fa-clipboard-list')]);
    }
    if($intent==='help_payment'){
        if($type!==1)return edu_chat_result('El registro de pagos está disponible únicamente para administración.');
        return edu_chat_result('Abre Registrar Pagos, selecciona al estudiante y sus conceptos pendientes, registra el medio de pago y confirma la operación.', [], [], [edu_chat_action('Registrar Pagos','payments','fa-cash-register')]);
    }
    $role=$actor['role'];
    return edu_chat_result('Soy el Asistente EduSync para '.$role.'. Puedo responder consultas del sistema y mostrar solo la información permitida para tu rol.', edu_chat_suggestions($actor));
}

function edu_chat_execute(mysqli $conn, array $actor, string $intent, array $entities): array {
    switch($intent){
        case 'student_notes_block': return edu_chat_student_debt_result($conn,$actor,true);
        case 'student_debts': return edu_chat_student_debt_result($conn,$actor,false);
        case 'student_payments': return edu_chat_student_payment_result($conn,$actor);
        case 'student_attendance': return edu_chat_student_attendance_result($conn,$actor,$entities);
        case 'student_grades': return edu_chat_student_grades_result($conn,$actor,$entities);
        case 'count_students': return edu_chat_count_students_result($conn,$actor,$entities);
        case 'count_teachers': return edu_chat_count_teachers_result($conn,$actor);
        case 'debt_summary': return edu_chat_debt_summary_result($conn,$actor,$entities);
        case 'collections_summary': return edu_chat_collections_result($conn,$actor,$entities);
        case 'attendance_summary': return edu_chat_attendance_summary_result($conn,$actor,$entities);
        case 'teacher_courses': return edu_chat_teacher_courses_result($conn,$actor);
        case 'academic_risk': return edu_chat_academic_risk_result($conn,$actor,$entities);
        case 'help_register_student':
        case 'help_import_excel':
        case 'help_attendance':
        case 'help_teacher_status':
        case 'help_grades':
        case 'help_payment':
        case 'help': return edu_chat_help_result($actor,$intent);
    }
    return edu_chat_result('No entendí del todo la consulta. Puedo ayudarte con información y procesos permitidos para tu rol.', edu_chat_suggestions($actor));
}
