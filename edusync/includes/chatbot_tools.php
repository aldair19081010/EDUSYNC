<?php

require_once __DIR__ . '/chatbot_knowledge.php';

function edu_chat_ai_function(string $name, string $description, array $properties = [], array $required = []): array {
    return [
        'type' => 'function',
        'name' => $name,
        'description' => $description,
        'parameters' => [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false
        ],
        'strict' => true
    ];
}

function edu_chat_ai_nullable_string(string $description, ?array $enum = null): array {
    $stringSchema = ['type' => 'string'];
    if ($enum !== null) $stringSchema['enum'] = $enum;
    return [
        'anyOf' => [$stringSchema, ['type' => 'null']],
        'description' => $description
    ];
}

function edu_chat_ai_tool_definitions(array $actor): array {
    $type = (int)($actor['type'] ?? 0);
    $period = ['type' => 'string', 'enum' => ['today','month','year'], 'description' => 'Periodo solicitado.'];
    $level = edu_chat_ai_nullable_string('Nivel educativo cuando el usuario lo especifica.', ['Inicial','Primaria','Secundaria']);
    $grade = edu_chat_ai_nullable_string('Grado como número en texto, por ejemplo 4.');
    $section = edu_chat_ai_nullable_string('Sección, por ejemplo A o B.');
    $bimestre = edu_chat_ai_nullable_string('Bimestre.', ['1','2','3','4']);
    $course = edu_chat_ai_nullable_string('Nombre del curso si el usuario lo menciona.');

    $tools = [
        edu_chat_ai_function('get_system_help', 'Busca documentación oficial interna de EduSync para responder dudas sobre cómo funciona o dónde está una opción.', [
            'query' => ['type' => 'string', 'description' => 'Duda o tema exacto sobre EduSync.']
        ], ['query'])
    ];

    if ($type === 4) {
        $tools[] = edu_chat_ai_function('get_my_overview', 'Obtiene un resumen integral del estudiante autenticado combinando deudas, pagos, asistencia del mes y notas disponibles. Úsalo para preguntas abiertas como cómo voy, dame mi resumen o cuál es mi situación.');
        $tools[] = edu_chat_ai_function('explain_my_notes_access', 'Explica si el acceso del estudiante autenticado a Mis Notas está bloqueado por deudas.');
        $tools[] = edu_chat_ai_function('get_my_debts', 'Consulta únicamente las deudas pendientes del estudiante autenticado.');
        $tools[] = edu_chat_ai_function('get_my_payments', 'Consulta únicamente el historial/resumen de pagos confirmados vigentes del estudiante autenticado.');
        $tools[] = edu_chat_ai_function('get_my_attendance', 'Consulta únicamente la asistencia del estudiante autenticado.', ['period' => $period], ['period']);
        $tools[] = edu_chat_ai_function('get_my_grades', 'Consulta únicamente las notas del estudiante autenticado. Si hay bloqueo financiero, la herramienta respeta ese bloqueo.', ['course' => $course], ['course']);
        return $tools;
    }

    if ($type === 1) {
        $tools[] = edu_chat_ai_function('get_school_overview', 'Obtiene un resumen integral del colegio autenticado con estudiantes, docentes, deuda, cobranza del mes, asistencia de hoy y riesgo académico. Úsalo para preguntas abiertas como dame un resumen del colegio o cómo estamos.');
        $tools[] = edu_chat_ai_function('get_student_count', 'Cuenta estudiantes activos del colegio autenticado aplicando filtros opcionales.', ['level'=>$level,'grade'=>$grade,'section'=>$section], ['level','grade','section']);
        $tools[] = edu_chat_ai_function('get_teacher_count', 'Cuenta docentes del colegio autenticado y resume activos/inactivos.');
        $tools[] = edu_chat_ai_function('get_debt_summary', 'Resume morosidad del colegio autenticado: estudiantes con deuda, obligaciones y saldo pendiente.', ['level'=>$level,'grade'=>$grade], ['level','grade']);
        $tools[] = edu_chat_ai_function('get_collections_summary', 'Resume cobranza confirmada del colegio autenticado.', ['period'=>$period,'level'=>$level], ['period','level']);
        $tools[] = edu_chat_ai_function('get_attendance_summary', 'Resume asistencia del colegio autenticado por periodo y filtros opcionales.', ['period'=>$period,'level'=>$level,'grade'=>$grade], ['period','level','grade']);
        $tools[] = edu_chat_ai_function('get_academic_risk', 'Cuenta estudiantes con registros académicos críticos del año académico actual.', ['level'=>$level,'grade'=>$grade,'bimestre'=>$bimestre,'course'=>$course], ['level','grade','bimestre','course']);
        return $tools;
    }

    if ($type === 2) {
        $tools[] = edu_chat_ai_function('get_teacher_overview', 'Obtiene un resumen del docente autenticado con asignaciones, estudiantes vinculados y riesgo académico del año actual.');
        $tools[] = edu_chat_ai_function('get_my_courses', 'Consulta las asignaciones/cursos vigentes del docente autenticado.');
        $tools[] = edu_chat_ai_function('get_my_student_count', 'Cuenta estudiantes vinculados a las asignaciones vigentes del docente autenticado.', ['level'=>$level,'grade'=>$grade], ['level','grade']);
        $tools[] = edu_chat_ai_function('get_academic_risk', 'Cuenta estudiantes con registros críticos únicamente en asignaciones del docente y año académico actual.', ['level'=>$level,'grade'=>$grade,'bimestre'=>$bimestre,'course'=>$course], ['level','grade','bimestre','course']);
        return $tools;
    }

    if ($type === 3) {
        $tools[] = edu_chat_ai_function('get_auxiliary_overview', 'Obtiene un resumen operativo para el auxiliar autenticado con estudiantes activos y asistencia de hoy.');
        $tools[] = edu_chat_ai_function('get_student_count', 'Cuenta estudiantes activos del colegio autenticado con filtros opcionales.', ['level'=>$level,'grade'=>$grade,'section'=>$section], ['level','grade','section']);
        $tools[] = edu_chat_ai_function('get_attendance_summary', 'Resume asistencia autorizada por periodo y filtros opcionales.', ['period'=>$period,'level'=>$level,'grade'=>$grade], ['period','level','grade']);
    }

    return $tools;
}

function edu_chat_ai_entities(array $args): array {
    return [
        'level' => isset($args['level']) && $args['level'] !== '' ? $args['level'] : null,
        'grade' => isset($args['grade']) && $args['grade'] !== '' ? (string)$args['grade'] : null,
        'section' => isset($args['section']) && $args['section'] !== '' ? strtoupper((string)$args['section']) : null,
        'bimestre' => isset($args['bimestre']) && $args['bimestre'] !== '' ? (string)$args['bimestre'] : null,
        'period' => isset($args['period']) && $args['period'] !== '' ? (string)$args['period'] : null,
        'course' => isset($args['course']) && $args['course'] !== '' ? (string)$args['course'] : null
    ];
}

function edu_chat_ai_system_help_result(string $query): array {
    $sections = edu_chat_knowledge_search($query, 4);
    $text = [];
    foreach ($sections as $section) $text[] = $section['title'] . ': ' . $section['content'];
    return edu_chat_result(implode("\n\n", $text));
}

function edu_chat_ai_combine_results(array $results): array {
    $messages = [];
    $cards = [];
    $actions = [];
    $followUp = [];
    foreach ($results as $result) {
        $message = trim((string)($result['message'] ?? ''));
        if ($message !== '') $messages[] = $message;
        foreach ((array)($result['cards'] ?? []) as $card) {
            $key = (string)($card['label'] ?? '') . '|' . (string)($card['value'] ?? '');
            $exists = false;
            foreach ($cards as $existing) if (((string)($existing['label'] ?? '') . '|' . (string)($existing['value'] ?? '')) === $key) { $exists = true; break; }
            if (!$exists && count($cards) < 10) $cards[] = $card;
        }
        foreach ((array)($result['actions'] ?? []) as $action) {
            $url = (string)($action['url'] ?? '');
            $exists = false;
            foreach ($actions as $existing) if ((string)($existing['url'] ?? '') === $url) { $exists = true; break; }
            if (!$exists && $url !== '' && count($actions) < 5) $actions[] = $action;
        }
        foreach ((array)($result['follow_up'] ?? []) as $item) {
            if ($item !== '' && !in_array($item, $followUp, true) && count($followUp) < 4) $followUp[] = $item;
        }
    }
    return edu_chat_result(implode("\n", $messages), $followUp, $cards, $actions);
}

function edu_chat_ai_run_tool(mysqli $conn, array $actor, string $name, array $args): array {
    $type = (int)($actor['type'] ?? 0);
    $entities = edu_chat_ai_entities($args);

    switch ($name) {
        case 'get_system_help':
            return edu_chat_ai_system_help_result((string)($args['query'] ?? 'EduSync'));

        case 'get_my_overview':
            if ($type !== 4) break;
            return edu_chat_ai_combine_results([
                edu_chat_student_debt_result($conn, $actor, false),
                edu_chat_student_payment_result($conn, $actor),
                edu_chat_student_attendance_result($conn, $actor, ['period'=>'month']),
                edu_chat_student_grades_result($conn, $actor, ['course'=>null,'bimestre'=>null])
            ]);
        case 'explain_my_notes_access':
            if ($type !== 4) break;
            return edu_chat_student_debt_result($conn, $actor, true);
        case 'get_my_debts':
            if ($type !== 4) break;
            return edu_chat_student_debt_result($conn, $actor, false);
        case 'get_my_payments':
            if ($type !== 4) break;
            return edu_chat_student_payment_result($conn, $actor);
        case 'get_my_attendance':
            if ($type !== 4) break;
            if (empty($entities['period'])) $entities['period'] = 'month';
            return edu_chat_student_attendance_result($conn, $actor, $entities);
        case 'get_my_grades':
            if ($type !== 4) break;
            return edu_chat_student_grades_result($conn, $actor, $entities);

        case 'get_school_overview':
            if ($type !== 1) break;
            return edu_chat_ai_combine_results([
                edu_chat_count_students_result($conn, $actor, []),
                edu_chat_count_teachers_result($conn, $actor),
                edu_chat_debt_summary_result($conn, $actor, []),
                edu_chat_collections_result($conn, $actor, ['period'=>'month']),
                edu_chat_attendance_summary_result($conn, $actor, ['period'=>'today']),
                edu_chat_academic_risk_current_result($conn, $actor, [])
            ]);
        case 'get_student_count':
            if (!in_array($type, [1,3], true)) break;
            return edu_chat_count_students_result($conn, $actor, $entities);
        case 'get_teacher_count':
            if ($type !== 1) break;
            return edu_chat_count_teachers_result($conn, $actor);
        case 'get_debt_summary':
            if ($type !== 1) break;
            return edu_chat_debt_summary_result($conn, $actor, $entities);
        case 'get_collections_summary':
            if ($type !== 1) break;
            if (empty($entities['period'])) $entities['period'] = 'month';
            return edu_chat_collections_result($conn, $actor, $entities);
        case 'get_attendance_summary':
            if (!in_array($type, [1,3], true)) break;
            if (empty($entities['period'])) $entities['period'] = 'today';
            return edu_chat_attendance_summary_result($conn, $actor, $entities);
        case 'get_academic_risk':
            if (!in_array($type, [1,2], true)) break;
            return edu_chat_academic_risk_current_result($conn, $actor, $entities);

        case 'get_teacher_overview':
            if ($type !== 2) break;
            return edu_chat_ai_combine_results([
                edu_chat_teacher_courses_result($conn, $actor),
                edu_chat_teacher_students_current_result($conn, $actor, []),
                edu_chat_academic_risk_current_result($conn, $actor, [])
            ]);
        case 'get_my_courses':
            if ($type !== 2) break;
            return edu_chat_teacher_courses_result($conn, $actor);
        case 'get_my_student_count':
            if ($type !== 2) break;
            return edu_chat_teacher_students_current_result($conn, $actor, $entities);

        case 'get_auxiliary_overview':
            if ($type !== 3) break;
            return edu_chat_ai_combine_results([
                edu_chat_count_students_result($conn, $actor, []),
                edu_chat_attendance_summary_result($conn, $actor, ['period'=>'today'])
            ]);
    }

    return edu_chat_result('La herramienta solicitada no está autorizada para este perfil.');
}
