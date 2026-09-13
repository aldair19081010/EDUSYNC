<?php

function edu_chat_student_total_result(mysqli $conn, array $actor, array $entities = []): array {
    $type = (int)($actor['type'] ?? 0);
    if (!in_array($type, [1,2,3], true)) return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    if (!edu_chat_table_exists($conn, 'student')) return edu_chat_result('No está disponible la información de estudiantes.');

    $schoolId = (int)($actor['school_id'] ?? 0);
    $where = ['s.school_id=?', "LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];
    $types = 'i';
    $params = [$schoolId];
    $join = '';

    if ($type === 2) {
        $teacherId = (int)($actor['teacher_id'] ?? 0);
        if ($teacherId <= 0) return edu_chat_result('No pude identificar tu ficha docente.');
        $join = ' INNER JOIN teacher_courses tc ON tc.school_id=s.school_id AND TRIM(tc.grado)=TRIM(s.grado) AND UPPER(TRIM(COALESCE(tc.seccion,\'\')))=UPPER(TRIM(COALESCE(s.seccion,\'\')))';
        $where[] = 'tc.teacher_id=?';
        $types .= 'i';
        $params[] = $teacherId;
        $year = edu_chat_active_year($conn, $schoolId);
        $yearId = (int)($year['id'] ?? 0);
        if ($yearId > 0 && edu_chat_column_exists($conn, 'teacher_courses', 'academic_year_id')) {
            $where[] = 'tc.academic_year_id=?';
            $types .= 'i';
            $params[] = $yearId;
        }
    }

    edu_chat_analytics_apply_student_filters($entities, 's', $where, $types, $params);
    $sql = "SELECT COUNT(DISTINCT s.id) total FROM student s $join WHERE " . implode(' AND ', $where);
    $stmt = $conn->prepare($sql);
    edu_chat_bind($stmt, $types, $params);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $scope = [];
    if (!empty($entities['level'])) $scope[] = (string)$entities['level'];
    if (!empty($entities['grade'])) $scope[] = (string)$entities['grade'] . '° grado';
    if (!empty($entities['section'])) $scope[] = 'sección ' . (string)$entities['section'];
    $scopeText = $scope ? ' de ' . implode(' · ', $scope) : '';
    $prefix = $type === 2 ? 'Tienes ' : 'Hay ';

    return edu_chat_result(
        $prefix . $total . ' estudiante' . ($total === 1 ? '' : 's') . ' activo' . ($total === 1 ? '' : 's') . $scopeText . '.',
        ['¿Cómo se distribuyen por sección?', '¿Cuántos tienen deuda?'],
        [['label'=>'Estudiantes activos','value'=>(string)$total,'tone'=>'primary']],
        $type === 1 ? [edu_chat_action('Ver Estudiantes','students','fa-users')] : []
    );
}
