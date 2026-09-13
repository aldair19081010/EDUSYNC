<?php

/**
 * Enrutamiento determinista para preguntas de datos donde un modelo pequeño
 * puede escoger una herramienta demasiado general. Solo decide QUÉ herramienta
 * de lectura usar y extrae filtros simples; los permisos siguen validándose en
 * chatbot_tools.php y el alcance real sale de la sesión.
 */
function edu_chat_ai_forced_route(array $actor, string $message): ?array {
    $type = (int)($actor['type'] ?? 0);
    if (!in_array($type, [1,2,3], true)) return null;

    $text = edu_chat_normalize($message);
    if ($text === '') return null;

    $level = edu_chat_extract_level($text);
    $grade = edu_chat_extract_grade($text);
    $section = edu_chat_extract_section($text);
    $period = edu_chat_extract_period($text);

    $args = [];
    if ($level) $args['level'] = $level;
    if ($grade) $args['grade'] = $grade;
    if ($section) $args['section'] = $section;
    if ($period) $args['period'] = $period;

    $isStudentTopic = edu_chat_has($text, ['estudiante','estudiantes','alumno','alumnos','matricula','matriculados']);
    $isDebtTopic = edu_chat_has($text, ['deuda','deudas','debe','deben','moroso','morosos','morosidad','pendiente','pendientes']);
    $isAttendanceTopic = edu_chat_has($text, ['asistencia','asistencias','tardanza','tardanzas','ausencia','ausencias','presente','presentes']);
    $isRiskTopic = edu_chat_has($text, ['riesgo','riesgos','critico','criticos','critica','criticas','nota baja','notas bajas','desaprobado','desaprobados','reprobado','reprobados']);

    $wantsBreakdown = edu_chat_has($text, [
        'cada seccion','cada sección','por seccion','por sección','por aula','cada aula',
        'por grado','cada grado','por nivel','cada nivel','distribucion','distribución',
        'desglose','desglosado','desglosada','secciones','aulas'
    ]);

    $groupBy = 'grade_section';
    if (edu_chat_has($text, ['por nivel','cada nivel'])) $groupBy = 'level';
    elseif (edu_chat_has($text, ['por grado','cada grado']) && !edu_chat_has($text, ['seccion','sección','aula'])) $groupBy = 'grade';
    elseif (edu_chat_has($text, ['por seccion','por sección']) && !$grade) $groupBy = 'grade_section';
    $args['group_by'] = $groupBy;

    // Ranking de deudores: admin/director solamente.
    if ($type === 1 && $isDebtTopic && edu_chat_has($text, ['mas deben','más deben','mayor deuda','mayores deudores','top ','ranking','morosos con mas','morosos con más'])) {
        $limit = 10;
        if (preg_match('/\b([1-9]|1[0-9]|20)\b/', $text, $m)) $limit = (int)$m[1];
        $debtArgs = $args;
        unset($debtArgs['group_by'], $debtArgs['period'], $debtArgs['section']);
        $debtArgs['limit'] = $limit;
        return ['name'=>'get_top_debtors','arguments'=>$debtArgs];
    }

    if ($wantsBreakdown) {
        if ($type === 1 && $isDebtTopic) return ['name'=>'get_debt_distribution','arguments'=>$args];
        if (in_array($type, [1,3], true) && $isAttendanceTopic) {
            if (empty($args['period'])) $args['period'] = 'today';
            return ['name'=>'get_attendance_distribution','arguments'=>$args];
        }
        if (in_array($type, [1,2], true) && $isRiskTopic) {
            if (edu_chat_has($text, ['por curso','cada curso','por area','por área'])) $args['group_by'] = 'course';
            return ['name'=>'get_academic_risk_distribution','arguments'=>$args];
        }
        if ($isStudentTopic) return ['name'=>'get_student_distribution','arguments'=>$args];
    }

    // "Cuántos hay en cada sección" a veces no incluye literalmente estudiante/alumno.
    if ($wantsBreakdown && edu_chat_has($text, ['cuantos','cuántos','cantidad','hay']) && !$isDebtTopic && !$isAttendanceTopic && !$isRiskTopic) {
        return ['name'=>'get_student_distribution','arguments'=>$args];
    }

    // Listados nominales. No forzar cuando la pregunta es solo un conteo.
    $wantsRoster = edu_chat_has($text, ['lista','listar','listame','lístame','quienes son','quiénes son','nombres de','muestrame los alumnos','muéstrame los alumnos','dime los alumnos','busca al estudiante','buscar estudiante']);
    if ($isStudentTopic && $wantsRoster && !edu_chat_has($text, ['cuantos','cuántos','cantidad'])) {
        $rosterArgs = $args;
        unset($rosterArgs['group_by'], $rosterArgs['period']);
        $rosterArgs['limit'] = 20;
        if (preg_match('/\b([1-9]|[1-4][0-9]|50)\b/', $text, $m)) $rosterArgs['limit'] = (int)$m[1];
        return ['name'=>'get_student_roster','arguments'=>$rosterArgs];
    }

    return null;
}
