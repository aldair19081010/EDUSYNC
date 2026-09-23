<?php

/**
 * Ontología semántica controlada del Asistente EduSync.
 *
 * La IA conoce conceptos, relaciones y reglas de negocio suficientes para
 * seleccionar herramientas. Nunca recibe permiso para crear/ejecutar SQL libre.
 */

function edu_chat_payment_method_from_text(string $text): ?string {
    $n = function_exists('edu_chat_normalize') ? edu_chat_normalize($text) : mb_strtolower(trim($text), 'UTF-8');
    $aliases = [
        'Efectivo' => ['efectivo','cash','dinero en efectivo','en caja'],
        'Yape' => ['yape','yapearon','yapeado','por yape'],
        'Transferencia' => ['transferencia','transferencias','transferido','deposito','deposito bancario','banco']
    ];
    foreach ($aliases as $canonical => $terms) {
        foreach ($terms as $term) if ($term !== '' && strpos($n,$term) !== false) return $canonical;
    }
    return null;
}

function edu_chat_semantic_domain(string $text): ?string {
    $n=function_exists('edu_chat_normalize')?edu_chat_normalize($text):mb_strtolower(trim($text),'UTF-8');
    $domains=[
        'payments'=>['pago','pagos','cobro','cobros','cobranza','recaudado','recaudacion','recibido','recibimos','ingreso','ingresos','entro','efectivo','yape','transferencia','deposito','caja','comprobante','recibo'],
        'debts'=>['deuda','deudas','moroso','morosos','morosidad','saldo pendiente','obligacion','obligaciones','pension','pensiones','cuota','cuotas','vencida','vencidas'],
        'attendance'=>['asistencia','asistencias','entrada','salida','presente','presentes','tarde','tardanza','tardanzas','ausente','ausentes','ausencia','ausencias','falta','faltas','permiso','permisos'],
        'risk'=>['riesgo','riesgos','critico','criticos','critica','criticas','alerta temprana','desaprobar','desaprobado','reprobar','reprobado','rendimiento critico'],
        'grades'=>['nota','notas','calificacion','calificaciones','evaluacion','evaluaciones','competencia','competencias','libro de notas','bimestre'],
        'courses'=>['curso','cursos','area','areas','asignatura','asignaturas'],
        'teachers'=>['docente','docentes','profesor','profesores','maestro','maestros'],
        'students'=>['estudiante','estudiantes','alumno','alumnos','alumna','alumnas','matricula','matriculados','ficha 360','perfil 360'],
        'system'=>['como hago','como puedo','donde esta','donde encuentro','ayuda','modulo','menu','configuracion','configurar','registrar','subir excel','importar']
    ];
    foreach($domains as $domain=>$terms)foreach($terms as $term)if(strpos($n,$term)!==false)return$domain;
    return null;
}

function edu_chat_semantic_operation(string $text): string {
    $n=function_exists('edu_chat_normalize')?edu_chat_normalize($text):mb_strtolower(trim($text),'UTF-8');
    if(edu_chat_has($n,['por seccion','por grado','por nivel','por aula','cada seccion','cada grado','cada nivel','distribucion','desglose','desglosado']))return'distribution';
    if(edu_chat_has($n,['quienes','quien','lista','listar','listame','muestrame','mostrar','nombres','dime los','dime las']))return'list';
    if(edu_chat_has($n,['cuantos','cuantas','cantidad','numero de','total de estudiantes','total de docentes']))return'count';
    if(edu_chat_has($n,['ficha','perfil 360','estado general','informacion completa','resumen del estudiante']))return'profile';
    if(edu_chat_has($n,['cuanto','total','resumen','como esta','estado','recaudado','cobrado','recibido','ingreso']))return'summary';
    if(edu_chat_has($n,['como','donde','ayuda','explica','explicame']))return'help';
    return'query';
}

function edu_chat_finance_language(string $text): bool {
    $domain=edu_chat_semantic_domain($text);
    return in_array($domain,['payments','debts'],true) || edu_chat_payment_method_from_text($text)!==null;
}

function edu_chat_semantic_schema_prompt(array $actor): string {
    $type=(int)($actor['type']??0);
    $lines=[
        'MAPA SEMÁNTICO AUTORIZADO DE EDUSYNC:',
        'PRINCIPIO: interpreta lenguaje natural y selecciona herramientas; nunca escribas ni ejecutes SQL.',
        'SEGURIDAD: colegio, usuario, docente y estudiante autorizado provienen de la sesión. Nunca pidas IDs internos para ampliar acceso.',
        'DOMINIO estudiantes: estudiantes activos, nombre, nivel, grado, sección, conteos, distribución, listados y ficha 360 cuando el rol lo permita.',
        'DOMINIO docentes: docentes del colegio y, para un docente autenticado, sus cursos/asignaciones y estudiantes vinculados.',
        'DOMINIO pagos: pagos confirmados, montos, fechas, comprobantes y métodos Efectivo/Yape/Transferencia usando el desglose real del módulo de pagos.',
        'DOMINIO deudas: obligaciones activas, saldo pendiente, morosidad, cantidad de deudas, vencimientos cuando exista fecha compatible.',
        'DOMINIO asistencia: registros de Entrada, Presente, Tarde, Ausente, Ausente Justificada y Permiso; no asumir falta solo por ausencia de marcación.',
        'DOMINIO académico/notas: cursos, bimestres, evaluaciones, competencias y calificaciones disponibles según el perfil.',
        'DOMINIO riesgo: alerta académica y riesgo predictivo; usar las herramientas/modelo existentes y no inventar causalidad.',
        'DOMINIO sistema: ayuda sobre módulos, navegación y procesos documentados de EduSync.',
        'OPERACIONES: count=cuántos; list=quiénes/lista; distribution=por nivel/grado/sección/aula; summary=total/resumen; profile=ficha/estado integral; help=cómo/dónde.',
        'FILTROS COMUNES: nivel, grado, sección, bimestre, curso y periodo cuando correspondan.',
        'PERIODOS: hoy, ayer, esta semana, este mes, mes pasado y año académico actual.',
        'MÉTODOS DE PAGO: Efectivo, Yape, Transferencia. cash→Efectivo; depósito/banco→Transferencia.',
        'REGLA: si una herramienta específica cubre completamente el filtro solicitado, úsala. Si la pregunta combina varios dominios o necesita filtros que una herramienta específica no soporta, usa query_edusync_data.',
        'MOTOR UNIVERSAL: query_edusync_data acepta un plan estructurado de solo lectura. Nunca le envíes SQL. Sirve para cruzar estudiantes con deuda, asistencia y notas; consultar docentes, cursos, pagos, evaluaciones y otros datos autorizados del sistema.',
        'CRUCES: conserva todos los criterios del usuario. Ejemplo: estudiantes de 3° con deuda > 500 Y al menos 2 tardanzas requiere subject=students, operation=list, debt_min=500, late_min=2 y los filtros académicos.',
        'REGLA: montos, conteos, nombres, notas, porcentajes y estados solo pueden salir de una herramienta autorizada.',
        'REGLA: antes de decir que no existe información, intenta una herramienta específica y después el motor universal cuando el perfil lo permita. Si aun así no existe una consulta autorizada, dilo brevemente; no inventes.'
    ];
    if($type!==1)$lines[]='El acceso financiero agregado del colegio está reservado al perfil Administrador.';
    if($type===2)$lines[]='El Docente solo puede consultar estudiantes/cursos vinculados a sus asignaciones.';
    if($type===3)$lines[]='El Auxiliar se limita a estudiantes y asistencia autorizada.';
    if($type===4)$lines[]='El Estudiante solo puede consultar su propia información.';
    return implode("\n",$lines);
}
