<?php

require_once __DIR__ . '/chatbot_knowledge.php';
require_once __DIR__ . '/chatbot_analytics.php';
require_once __DIR__ . '/chatbot_totals.php';
require_once __DIR__ . '/chatbot_finance_filters.php';
require_once __DIR__ . '/chatbot_student_insights.php';

function edu_chat_ai_function(string $name, string $description, array $properties = [], array $required = []): array {
    return [
        'type' => 'function',
        'name' => $name,
        'description' => $description,
        'parameters' => [
            'type' => 'object',
            'properties' => $properties ?: new stdClass(),
            'required' => array_values($required)
        ]
    ];
}

function edu_chat_ai_optional_string(string $description, ?array $enum = null): array {
    $schema = ['type' => 'string', 'description' => $description];
    if ($enum !== null) $schema['enum'] = $enum;
    return $schema;
}

function edu_chat_ai_tool_definitions(array $actor): array {
    $type = (int)($actor['type'] ?? 0);
    $period = ['type'=>'string','enum'=>['today','month','year'],'description'=>'Periodo solicitado. Si no se especifica, la herramienta usa su periodo predeterminado.'];
    $level = edu_chat_ai_optional_string('Nivel educativo cuando el usuario lo especifica.', ['Inicial','Primaria','Secundaria']);
    $grade = edu_chat_ai_optional_string('Grado como número en texto, por ejemplo 4.');
    $section = edu_chat_ai_optional_string('Sección, por ejemplo A o B.');
    $bimestre = edu_chat_ai_optional_string('Bimestre.', ['1','2','3','4']);
    $course = edu_chat_ai_optional_string('Nombre del curso si el usuario lo menciona.');
    $groupBy = ['type'=>'string','enum'=>['level','grade','section','grade_section'],'description'=>'Cómo agrupar. Usa grade_section para cada sección, por aula o por grado y sección.'];
    $riskGroupBy = ['type'=>'string','enum'=>['level','grade','section','grade_section','course'],'description'=>'Cómo agrupar el riesgo académico. Usa course para desglose por curso y grade_section para aulas/secciones.'];
    $debtGroupBy = ['type'=>'string','enum'=>['none','level','grade','section','grade_section'],'description'=>'Cómo organizar el listado nominal de deudores. Usa grade_section para nivel, grado y sección.'];
    $debtSortBy = ['type'=>'string','enum'=>['obligations','debt','name','location'],'description'=>'Orden del listado: cantidad de deudas, monto, nombre o ubicación académica.'];
    $attendanceStatus = ['type'=>'string','enum'=>['all','present','late','absent','justified','permission'],'description'=>'Estado de asistencia a listar. late=tardanzas, absent=ausencias, justified=ausencias justificadas.'];
    $debtCount = ['type'=>'integer','minimum'=>1,'maximum'=>100,'description'=>'Cantidad de obligaciones/deudas pendientes usada como filtro.'];
    $occurrences = ['type'=>'integer','minimum'=>1,'maximum'=>100,'description'=>'Cantidad mínima de ocurrencias solicitadas, por ejemplo 3 tardanzas o 2 registros críticos.'];
    $limit20 = ['type'=>'integer','minimum'=>1,'maximum'=>20,'description'=>'Cantidad de estudiantes a listar. Si no se especifica, usa 10.'];
    $limit50 = ['type'=>'integer','minimum'=>1,'maximum'=>50,'description'=>'Cantidad máxima de estudiantes a listar. Si no se especifica, usa 20.'];
    $limit200 = ['type'=>'integer','minimum'=>1,'maximum'=>200,'description'=>'Cantidad máxima de coincidencias a listar. Si no se especifica, usa 100.'];
    $nameSearch = ['type'=>'string','description'=>'Nombre o parte del nombre del estudiante a buscar.'];

    $tools = [
        edu_chat_ai_function('get_system_help', 'Busca documentación oficial interna de EduSync para dudas sobre cómo funciona o dónde está una opción.', [
            'query'=>['type'=>'string','description'=>'Duda o tema exacto sobre EduSync.']
        ], ['query'])
    ];

    if ($type === 4) {
        $tools[] = edu_chat_ai_function('get_my_overview', 'Obtiene un resumen integral del estudiante autenticado combinando deudas, pagos, asistencia del mes y notas disponibles.');
        $tools[] = edu_chat_ai_function('explain_my_notes_access', 'Explica si el acceso del estudiante autenticado a Mis Notas está bloqueado por deudas.');
        $tools[] = edu_chat_ai_function('get_my_debts', 'Consulta únicamente las deudas pendientes del estudiante autenticado.');
        $tools[] = edu_chat_ai_function('get_my_payments', 'Consulta únicamente el historial/resumen de pagos confirmados vigentes del estudiante autenticado.');
        $tools[] = edu_chat_ai_function('get_my_attendance', 'Consulta únicamente la asistencia del estudiante autenticado.', ['period'=>$period]);
        $tools[] = edu_chat_ai_function('get_my_grades', 'Consulta únicamente las notas del estudiante autenticado. Si hay bloqueo financiero, la herramienta respeta ese bloqueo.', ['course'=>$course]);
        return $tools;
    }

    if ($type === 1) {
        $tools[] = edu_chat_ai_function('get_school_overview', 'Obtiene un resumen integral del colegio autenticado con estudiantes, docentes, deuda, cobranza del mes, asistencia de hoy y riesgo académico.');
        $tools[] = edu_chat_ai_function('get_student_count', 'Devuelve UN SOLO TOTAL de estudiantes activos con filtros opcionales. NO la uses para preguntas con cada, por grado, por sección, distribución, desglose o aulas.', ['level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_student_distribution', 'Devuelve conteos REALES agrupados de estudiantes activos. Úsala para cada sección, por grado, por nivel o por aula.', ['group_by'=>$groupBy,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_student_roster', 'Lista nombres de estudiantes activos con nivel, grado y sección.', ['name_search'=>$nameSearch,'limit'=>$limit50,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_student_360', 'Obtiene la FICHA 360 de un estudiante por nombre: aula, deuda pendiente, pagos confirmados, asistencia del mes y riesgo académico. Úsala para resumen, ficha, perfil, estado general o información completa de un alumno. Si hay homónimos devuelve las coincidencias y no adivina.', ['name_search'=>$nameSearch,'level'=>$level,'grade'=>$grade,'section'=>$section], ['name_search']);
        $tools[] = edu_chat_ai_function('get_teacher_count', 'Cuenta docentes del colegio autenticado y resume activos/inactivos.');
        $tools[] = edu_chat_ai_function('get_debt_summary', 'Devuelve un único resumen total de morosidad. Para deuda por nivel, grado o sección usa get_debt_distribution.', ['level'=>$level,'grade'=>$grade]);
        $tools[] = edu_chat_ai_function('get_debt_distribution', 'Desglosa deuda pendiente por nivel, grado, sección o aula, con estudiantes, obligaciones y monto real por grupo.', ['group_by'=>$groupBy,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_top_debtors', 'Lista y ordena de mayor a menor a los estudiantes con más deuda pendiente.', ['limit'=>$limit20,'level'=>$level,'grade'=>$grade]);
        $tools[] = edu_chat_ai_function('get_students_by_debt_count', 'Lista NOMBRES según la cantidad de deudas pendientes. Entiende más de 3, 4 o más, exactamente 3, menos de 5 o entre 2 y 4.', ['min_obligations'=>$debtCount,'max_obligations'=>$debtCount,'limit'=>$limit200,'group_by'=>$debtGroupBy,'sort_by'=>$debtSortBy,'level'=>$level,'grade'=>$grade,'section'=>$section], ['min_obligations']);
        $tools[] = edu_chat_ai_function('get_collections_summary', 'Resume cobranza confirmada del colegio autenticado.', ['period'=>$period,'level'=>$level]);
        $tools[] = edu_chat_ai_function('get_attendance_summary', 'Devuelve un único resumen de asistencia. Para asistencia por aula usa get_attendance_distribution.', ['period'=>$period,'level'=>$level,'grade'=>$grade]);
        $tools[] = edu_chat_ai_function('get_attendance_distribution', 'Desglosa asistencia REAL por nivel, grado, sección o aula.', ['group_by'=>$groupBy,'period'=>$period,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_attendance_roster', 'Lista NOMBRES de estudiantes por asistencia: quién faltó, quién llegó tarde, ausencias, tardanzas, permisos o presentes. Permite exigir una cantidad mínima, por ejemplo más de 3 tardanzas este mes.', ['status'=>$attendanceStatus,'min_occurrences'=>$occurrences,'limit'=>$limit200,'period'=>$period,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_academic_risk', 'Devuelve un total de estudiantes con registros académicos críticos del año actual.', ['level'=>$level,'grade'=>$grade,'bimestre'=>$bimestre,'course'=>$course]);
        $tools[] = edu_chat_ai_function('get_academic_risk_distribution', 'Desglosa estudiantes en riesgo académico por nivel, grado, sección, aula o curso.', ['group_by'=>$riskGroupBy,'level'=>$level,'grade'=>$grade,'section'=>$section,'bimestre'=>$bimestre,'course'=>$course]);
        $tools[] = edu_chat_ai_function('get_academic_risk_roster', 'Lista NOMBRES de estudiantes en riesgo académico y sus cursos críticos. Úsala para quiénes están en riesgo, nombres con C/notas críticas, riesgo en un curso o estudiantes con N o más registros críticos.', ['min_critical_records'=>$occurrences,'limit'=>$limit200,'level'=>$level,'grade'=>$grade,'section'=>$section,'bimestre'=>$bimestre,'course'=>$course]);
        return $tools;
    }

    if ($type === 2) {
        $tools[] = edu_chat_ai_function('get_teacher_overview', 'Obtiene un resumen del docente autenticado con asignaciones, estudiantes vinculados y riesgo académico del año actual.');
        $tools[] = edu_chat_ai_function('get_my_courses', 'Consulta las asignaciones/cursos vigentes del docente autenticado.');
        $tools[] = edu_chat_ai_function('get_my_student_count', 'Devuelve un total de estudiantes vinculados a las asignaciones del docente.', ['level'=>$level,'grade'=>$grade]);
        $tools[] = edu_chat_ai_function('get_student_distribution', 'Desglosa únicamente estudiantes vinculados al docente por nivel, grado o sección.', ['group_by'=>$groupBy,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_student_roster', 'Lista únicamente estudiantes vinculados a las asignaciones del docente.', ['name_search'=>$nameSearch,'limit'=>$limit50,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_academic_risk', 'Cuenta estudiantes con registros críticos únicamente en asignaciones del docente y año actual.', ['level'=>$level,'grade'=>$grade,'bimestre'=>$bimestre,'course'=>$course]);
        $tools[] = edu_chat_ai_function('get_academic_risk_distribution', 'Desglosa el riesgo académico únicamente dentro de las asignaciones del docente.', ['group_by'=>$riskGroupBy,'level'=>$level,'grade'=>$grade,'section'=>$section,'bimestre'=>$bimestre,'course'=>$course]);
        $tools[] = edu_chat_ai_function('get_academic_risk_roster', 'Lista nombres de estudiantes en riesgo únicamente en las asignaciones del docente.', ['min_critical_records'=>$occurrences,'limit'=>$limit200,'level'=>$level,'grade'=>$grade,'section'=>$section,'bimestre'=>$bimestre,'course'=>$course]);
        return $tools;
    }

    if ($type === 3) {
        $tools[] = edu_chat_ai_function('get_auxiliary_overview', 'Obtiene un resumen operativo para el auxiliar autenticado con estudiantes activos y asistencia de hoy.');
        $tools[] = edu_chat_ai_function('get_student_count', 'Devuelve un total de estudiantes activos.', ['level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_student_distribution', 'Desglosa estudiantes activos por nivel, grado o sección.', ['group_by'=>$groupBy,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_student_roster', 'Lista estudiantes activos por nivel, grado o sección.', ['name_search'=>$nameSearch,'limit'=>$limit50,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_attendance_summary', 'Devuelve un resumen general de asistencia autorizada.', ['period'=>$period,'level'=>$level,'grade'=>$grade]);
        $tools[] = edu_chat_ai_function('get_attendance_distribution', 'Desglosa asistencia autorizada por nivel, grado o sección.', ['group_by'=>$groupBy,'period'=>$period,'level'=>$level,'grade'=>$grade,'section'=>$section]);
        $tools[] = edu_chat_ai_function('get_attendance_roster', 'Lista nombres por tardanzas, ausencias, permisos o presentes dentro del colegio autenticado.', ['status'=>$attendanceStatus,'min_occurrences'=>$occurrences,'limit'=>$limit200,'period'=>$period,'level'=>$level,'grade'=>$grade,'section'=>$section]);
    }
    return $tools;
}

function edu_chat_ai_entities(array $args): array {
    return [
        'level'=>isset($args['level']) && $args['level']!=='' ? $args['level'] : null,
        'grade'=>isset($args['grade']) && $args['grade']!=='' ? (string)$args['grade'] : null,
        'section'=>isset($args['section']) && $args['section']!=='' ? strtoupper((string)$args['section']) : null,
        'bimestre'=>isset($args['bimestre']) && $args['bimestre']!=='' ? (string)$args['bimestre'] : null,
        'period'=>isset($args['period']) && $args['period']!=='' ? (string)$args['period'] : null,
        'course'=>isset($args['course']) && $args['course']!=='' ? (string)$args['course'] : null
    ];
}

function edu_chat_ai_system_help_result(string $query): array {
    $sections=edu_chat_knowledge_search($query,4); $text=[];
    foreach($sections as $section)$text[]=$section['title'].': '.$section['content'];
    return edu_chat_result(implode("\n\n",$text));
}

function edu_chat_ai_combine_results(array $results): array {
    $messages=[];$cards=[];$actions=[];$followUp=[];
    foreach($results as $result){
        $message=trim((string)($result['message']??'')); if($message!=='')$messages[]=$message;
        foreach((array)($result['cards']??[]) as $card){$key=(string)($card['label']??'').'|'.(string)($card['value']??'');$exists=false;foreach($cards as $existing)if(((string)($existing['label']??'').'|'.(string)($existing['value']??''))===$key){$exists=true;break;}if(!$exists&&count($cards)<10)$cards[]=$card;}
        foreach((array)($result['actions']??[]) as $action){$url=(string)($action['url']??'');$exists=false;foreach($actions as $existing)if((string)($existing['url']??'')===$url){$exists=true;break;}if(!$exists&&$url!==''&&count($actions)<5)$actions[]=$action;}
        foreach((array)($result['follow_up']??[]) as $item)if($item!==''&&!in_array($item,$followUp,true)&&count($followUp)<4)$followUp[]=$item;
    }
    return edu_chat_result(implode("\n",$messages),$followUp,$cards,$actions);
}

function edu_chat_ai_run_tool(mysqli $conn, array $actor, string $name, array $args): array {
    $type=(int)($actor['type']??0); $entities=edu_chat_ai_entities($args);
    switch($name){
        case 'get_system_help': return edu_chat_ai_system_help_result((string)($args['query']??'EduSync'));
        case 'get_my_overview': if($type!==4)break; return edu_chat_ai_combine_results([edu_chat_student_debt_result($conn,$actor,false),edu_chat_student_payment_result($conn,$actor),edu_chat_student_attendance_result($conn,$actor,['period'=>'month']),edu_chat_student_grades_result($conn,$actor,['course'=>null,'bimestre'=>null])]);
        case 'explain_my_notes_access': if($type!==4)break; return edu_chat_student_debt_result($conn,$actor,true);
        case 'get_my_debts': if($type!==4)break; return edu_chat_student_debt_result($conn,$actor,false);
        case 'get_my_payments': if($type!==4)break; return edu_chat_student_payment_result($conn,$actor);
        case 'get_my_attendance': if($type!==4)break; if(empty($entities['period']))$entities['period']='month'; return edu_chat_student_attendance_result($conn,$actor,$entities);
        case 'get_my_grades': if($type!==4)break; return edu_chat_student_grades_result($conn,$actor,$entities);

        case 'get_school_overview': if($type!==1)break; return edu_chat_ai_combine_results([edu_chat_student_total_result($conn,$actor,[]),edu_chat_count_teachers_result($conn,$actor),edu_chat_debt_summary_result($conn,$actor,[]),edu_chat_collections_result($conn,$actor,['period'=>'month']),edu_chat_attendance_summary_result($conn,$actor,['period'=>'today']),edu_chat_academic_risk_current_result($conn,$actor,[])]);
        case 'get_student_count': if(!in_array($type,[1,3],true))break; return edu_chat_student_total_result($conn,$actor,$entities);
        case 'get_student_distribution': if(!in_array($type,[1,2,3],true))break; return edu_chat_student_distribution_result($conn,$actor,$entities,(string)($args['group_by']??'grade_section'));
        case 'get_student_roster': if(!in_array($type,[1,2,3],true))break; return edu_chat_student_roster_result($conn,$actor,$entities,(string)($args['name_search']??''),(int)($args['limit']??20));
        case 'get_student_360': if($type!==1)break; return edu_chat_student_360_result($conn,$actor,$entities,(string)($args['name_search']??''));
        case 'get_teacher_count': if($type!==1)break; return edu_chat_count_teachers_result($conn,$actor);
        case 'get_debt_summary': if($type!==1)break; return edu_chat_debt_summary_result($conn,$actor,$entities);
        case 'get_debt_distribution': if($type!==1)break; return edu_chat_debt_distribution_result($conn,$actor,$entities,(string)($args['group_by']??'grade_section'));
        case 'get_top_debtors': if($type!==1)break; return edu_chat_top_debtors_result($conn,$actor,$entities,(int)($args['limit']??10));
        case 'get_students_by_debt_count': if($type!==1)break; return edu_chat_students_by_debt_count_result($conn,$actor,$entities,(int)($args['min_obligations']??1),isset($args['max_obligations'])?(int)$args['max_obligations']:null,(int)($args['limit']??100),(string)($args['group_by']??'grade_section'),(string)($args['sort_by']??'obligations'));
        case 'get_collections_summary': if($type!==1)break; if(empty($entities['period']))$entities['period']='month'; return edu_chat_collections_result($conn,$actor,$entities);
        case 'get_attendance_summary': if(!in_array($type,[1,3],true))break; if(empty($entities['period']))$entities['period']='today'; return edu_chat_attendance_summary_result($conn,$actor,$entities);
        case 'get_attendance_distribution': if(!in_array($type,[1,3],true))break; if(empty($entities['period']))$entities['period']='today'; return edu_chat_attendance_distribution_result($conn,$actor,$entities,(string)($args['group_by']??'grade_section'));
        case 'get_attendance_roster': if(!in_array($type,[1,3],true))break; if(empty($entities['period']))$entities['period']='month'; return edu_chat_attendance_roster_result($conn,$actor,$entities,(string)($args['status']??'all'),(int)($args['min_occurrences']??1),(int)($args['limit']??100));
        case 'get_academic_risk': if(!in_array($type,[1,2],true))break; return edu_chat_academic_risk_current_result($conn,$actor,$entities);
        case 'get_academic_risk_distribution': if(!in_array($type,[1,2],true))break; return edu_chat_academic_risk_distribution_result($conn,$actor,$entities,(string)($args['group_by']??'grade_section'));
        case 'get_academic_risk_roster': if(!in_array($type,[1,2],true))break; return edu_chat_academic_risk_roster_result($conn,$actor,$entities,(int)($args['min_critical_records']??1),(int)($args['limit']??100));

        case 'get_teacher_overview': if($type!==2)break; return edu_chat_ai_combine_results([edu_chat_teacher_courses_result($conn,$actor),edu_chat_student_total_result($conn,$actor,[]),edu_chat_academic_risk_current_result($conn,$actor,[])]);
        case 'get_my_courses': if($type!==2)break; return edu_chat_teacher_courses_result($conn,$actor);
        case 'get_my_student_count': if($type!==2)break; return edu_chat_student_total_result($conn,$actor,$entities);
        case 'get_auxiliary_overview': if($type!==3)break; return edu_chat_ai_combine_results([edu_chat_student_total_result($conn,$actor,[]),edu_chat_attendance_summary_result($conn,$actor,['period'=>'today'])]);
    }
    return edu_chat_result('La herramienta solicitada no está autorizada para este perfil.');
}
