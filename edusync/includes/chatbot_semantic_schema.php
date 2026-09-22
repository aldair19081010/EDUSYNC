<?php

/**
 * Diccionario semántico controlado del Asistente EduSync.
 *
 * La IA conoce conceptos y reglas de negocio, pero NO recibe libertad para
 * construir/ejecutar SQL. Las herramientas PHP siguen siendo la única vía
 * autorizada para consultar datos.
 */

function edu_chat_payment_method_from_text(string $text): ?string {
    $n = function_exists('edu_chat_normalize') ? edu_chat_normalize($text) : mb_strtolower(trim($text), 'UTF-8');

    $aliases = [
        'Efectivo' => ['efectivo','cash','dinero en efectivo','en caja'],
        'Yape' => ['yape','yapearon','yapeado','por yape'],
        'Transferencia' => ['transferencia','transferencias','transferido','deposito','deposito bancario','banco']
    ];

    foreach ($aliases as $canonical => $terms) {
        foreach ($terms as $term) {
            if ($term !== '' && strpos($n, $term) !== false) return $canonical;
        }
    }
    return null;
}

function edu_chat_finance_language(string $text): bool {
    $n = function_exists('edu_chat_normalize') ? edu_chat_normalize($text) : mb_strtolower(trim($text), 'UTF-8');
    foreach ([
        'pago','pagos','cobro','cobros','cobrado','cobramos','cobranza',
        'recaudado','recaudacion','recibido','recibimos','recibieron',
        'ingreso','ingresos','entro','entrada de dinero','dinero entro',
        'pagado','pagaron','caja'
    ] as $term) {
        if (strpos($n, $term) !== false) return true;
    }
    return edu_chat_payment_method_from_text($n) !== null;
}

function edu_chat_semantic_schema_prompt(array $actor): string {
    $type = (int)($actor['type'] ?? 0);
    $lines = [
        'MAPA SEMÁNTICO AUTORIZADO DE EDUSYNC:',
        '- Los datos reales se obtienen únicamente mediante herramientas PHP de solo lectura; nunca escribas SQL.',
        '- El colegio/usuario permitido se determina por la sesión. Nunca solicites ni inventes school_id, student_id o teacher_id.',
        '- estudiantes: nombre, nivel, grado, sección y estado.',
        '- asistencia: registros de entrada/salida y estados como presente, tarde, ausente, justificado o permiso.',
        '- académico: cursos, evaluaciones, competencias, notas y riesgo predictivo.',
        '- finanzas: obligaciones/deudas, pagos confirmados y cobranza.',
        '- pagos: cada registro tiene monto, fecha y, cuando existe en la instalación, método de pago.',
        '- métodos de pago canónicos: Efectivo, Yape y Transferencia. Sinónimos como cash, depósito o banco deben mapearse al método canónico correspondiente.',
        '- periodos conversacionales soportados: hoy, ayer, esta semana, este mes, mes pasado y año académico actual.',
        '- “cuánto”, “total”, “ingresó”, “entró”, “recibimos”, “cobramos” o “recaudamos” sobre pagos significa consultar cobranza real; no responder desde conocimiento general.',
        '- una consulta por método de pago debe usar get_collections_by_method cuando esa herramienta esté disponible.',
        '- una consulta general de cobranza sin método usa get_collections_summary.',
        '- los montos, conteos, nombres y estados devueltos por las herramientas son la fuente de verdad y no deben modificarse.'
    ];

    if ($type !== 1) {
        $lines[] = '- El acceso financiero agregado del colegio está reservado al perfil Administrador.';
    }

    return implode("\n", $lines);
}
