<?php

function edu_chat_knowledge_sections(): array {
    return [
        'alcance' => [
            'title' => 'Alcance del Asistente EduSync',
            'keywords' => ['asistente','chatbot','ia','puedes hacer','ayuda','alcance'],
            'content' => 'El Asistente EduSync responde únicamente sobre el uso de EduSync y sobre datos que el usuario autenticado tenga permiso de consultar. No debe responder preguntas generales ajenas al sistema, revelar información de otros colegios, credenciales, DNI de terceros, SQL, claves ni información interna sensible. Las consultas de datos son de solo lectura.'
        ],
        'roles' => [
            'title' => 'Perfiles y permisos',
            'keywords' => ['rol','perfil','administrador','director','docente','auxiliar','estudiante','permiso'],
            'content' => 'Administrador/Director: gestiona estudiantes, docentes, cursos, pagos, deudas, asistencia, notas, reportes y configuración según las opciones habilitadas. Docente: trabaja con sus cursos, libro de notas y reportes académicos dentro de sus asignaciones. Auxiliar: se orienta principalmente a asistencia y consultas operativas autorizadas. Estudiante: solo consulta su propia información en Mis Notas, Mis Asistencias, Mis Pagos y Mis Deudas.'
        ],
        'estudiantes' => [
            'title' => 'Estudiantes',
            'keywords' => ['estudiante','alumno','matricula','matricular','excel','importar'],
            'content' => 'Administración puede registrar estudiantes desde Estudiantes > Nuevo Estudiante. La carga masiva usa la opción de subir Excel y la plantilla del sistema, seleccionando el año académico correspondiente. El portal del estudiante se autentica por colegio y DNI/código según la configuración vigente.'
        ],
        'docentes' => [
            'title' => 'Docentes',
            'keywords' => ['docente','profesor','maestro','asignar curso','desactivar'],
            'content' => 'Administración puede gestionar docentes y asignarlos a cursos por año académico, nivel, grado y sección. Desactivar un docente conserva su información histórica. Un docente autenticado solo debe consultar información asociada a sus asignaciones vigentes.'
        ],
        'notas' => [
            'title' => 'Notas y Libro de Notas',
            'keywords' => ['nota','notas','calificacion','evaluacion','bimestre','libro de notas','competencia'],
            'content' => 'Libro de Notas permite seleccionar curso, nivel, grado, sección y bimestre. La grilla muestra competencias y evaluaciones, permite crear evaluaciones desde cada competencia y dispone de autoguardado y guardado manual. El portal del estudiante muestra Mis Notas. Por la regla financiera vigente, Mis Notas se bloquea cuando el estudiante tiene 2 o más obligaciones pendientes activas; con 0 o 1 obligación pendiente las notas permanecen visibles.'
        ],
        'asistencia' => [
            'title' => 'Asistencia',
            'keywords' => ['asistencia','entrada','salida','tarde','tardanza','ausente','permiso'],
            'content' => 'El módulo de Asistencia registra entradas y salidas y utiliza las reglas horarias configuradas por la institución. Los estados operativos incluyen Presente, Tarde, Ausente, Ausente Justificada y Permiso. En Mis Asistencias, Entrada y Salida del mismo día se consolidan como un solo día de asistencia. EduSync no debe inventar una ausencia únicamente porque no exista marcación.'
        ],
        'pagos' => [
            'title' => 'Pagos',
            'keywords' => ['pago','pagos','recibo','comprobante','yape','transferencia','efectivo','cobranza'],
            'content' => 'Administración registra pagos desde Gestión de Pagos. Una operación puede cubrir varios conceptos y utilizar los medios de pago configurados. Mis Pagos muestra comprobantes confirmados y vigentes; pagos anulados o comprobantes reemplazados por una corrección no se presentan como pagos válidos al estudiante. El detalle puede abrir el recibo correspondiente.'
        ],
        'deudas' => [
            'title' => 'Deudas',
            'keywords' => ['deuda','deudas','morosidad','saldo','pension','obligacion'],
            'content' => 'Las deudas se calculan con el monto efectivo después de descuentos menos los pagos confirmados. Solo las obligaciones activas con saldo mayor a la tolerancia financiera cuentan como pendientes. Mis Deudas muestra saldo pendiente, obligaciones, saldo vencido y próximo vencimiento. Dos o más obligaciones pendientes activas bloquean Mis Notas.'
        ],
        'facturacion' => [
            'title' => 'Facturación electrónica',
            'keywords' => ['facturacion','sunat','boleta','comprobante electronico','ose','pse'],
            'content' => 'EduSync cuenta con un bloque de Facturación Electrónica que incluye Comprobantes, Facturación de Deudas y Configuración. Las acciones de emisión o configuración son administrativas y no deben ejecutarse desde el chatbot; el asistente solo puede orientar o navegar hacia el módulo correspondiente.'
        ],
        'gestion_academica' => [
            'title' => 'Gestión Académica y Años Académicos',
            'keywords' => ['gestion academica','area','areas','curso','cursos','ano academico','años academicos','año academico','academic year'],
            'content' => 'Administración dispone de Gestión Académica para organizar áreas y cursos académicos del colegio, y de Años Académicos para administrar los periodos escolares. Las asignaciones de docentes y las evaluaciones se vinculan al año académico correspondiente. EduSync también dispone de controles y exportaciones relacionados con años académicos.'
        ],
        'competencias' => [
            'title' => 'Competencias',
            'keywords' => ['competencia','competencias','competencias por nivel','porcentaje competencia','peso competencia'],
            'content' => 'El módulo Competencias por Nivel administra las competencias asociadas a cursos y niveles. En Libro de Notas las evaluaciones se registran dentro de competencias. Las competencias oficiales con peso positivo participan en el cálculo académico configurado; elementos marcados con peso 0 pueden usarse como evaluaciones no oficiales que no promedian.'
        ],
        'conceptos_deudas' => [
            'title' => 'Conceptos de Pago y Asignación de Deudas',
            'keywords' => ['concepto','conceptos','conceptos de pago','asignar deuda','asignar deudas','fees','mensualidad','matricula'],
            'content' => 'En Gestión de Pagos, Conceptos de Pagos define conceptos como matrícula o mensualidades por año académico, nivel, grados y monto. Asignar Deudas crea las obligaciones para estudiantes. El sistema conserva el historial de conceptos que ya tienen deudas o pagos y dispone de operaciones masivas y exportaciones según el módulo.'
        ],
        'descuentos' => [
            'title' => 'Descuentos y Becas',
            'keywords' => ['descuento','descuentos','beca','becas','beneficio'],
            'content' => 'Administración dispone de Descuentos / Becas para registrar beneficios sobre obligaciones. Los pagos utilizan el monto efectivo de la deuda después de descuentos. Las correcciones de pagos mantienen trazabilidad y no deben alterar silenciosamente el historial.'
        ],
        'reportes_financieros' => [
            'title' => 'Reportes de Pagos y Deudas',
            'keywords' => ['reporte pagos','reporte de pagos','reporte deudas','reporte de deudas','exportar pagos','exportar deudas'],
            'content' => 'Gestión de Pagos incluye Reporte de Pagos y Reporte de Deudas. Estos módulos permiten consultar información financiera por los filtros disponibles y cuentan con opciones de exportación o impresión. El chatbot puede consultar datos de cobranza y deuda en modo lectura según el perfil.'
        ],
        'reglas_asistencia' => [
            'title' => 'Reglas y Reporte de Asistencia',
            'keywords' => ['reglas asistencia','regla asistencia','horario asistencia','reporte asistencia','reporte de asistencia'],
            'content' => 'Administración y Auxiliar disponen de Asistencia, Reglas de Asistencia y Reporte de Asistencia. Las reglas definen horarios operativos para clasificar marcaciones; el reporte permite revisar los registros y el sistema dispone de exportación e impresión. El chatbot trata Entrada como referencia principal cuando resume asistencia, salvo que se solicite Salida.'
        ],
        'reporte_notas' => [
            'title' => 'Reporte de Notas y Cierres',
            'keywords' => ['reporte notas','reporte de notas','cerrar bimestre','cierre bimestre','reabrir notas','bloqueo bimestre'],
            'content' => 'EduSync dispone de Reporte de Notas, vistas de impresión/exportación y controles de cierre o bloqueo de periodos académicos. Los bloqueos de bimestre limitan modificaciones según la configuración institucional. Las acciones de cierre, reapertura o edición no se ejecutan desde el chatbot; el asistente solo consulta y orienta.'
        ],
        'fichas_reportes' => [
            'title' => 'Fichas y Reportes',
            'keywords' => ['ficha','fichas','fichas y reportes','report builder','generar ficha','reporte personalizado'],
            'content' => 'El módulo Fichas y Reportes permite generar fichas y reportes institucionales. El sistema incluye generación y exportación de reportes; el chatbot puede orientar hacia el módulo, pero no crea ni modifica registros desde la conversación.'
        ],
        'usuarios' => [
            'title' => 'Usuarios del Sistema',
            'keywords' => ['usuario','usuarios','cuenta','cuentas','director','administrador','perfil usuario'],
            'content' => 'Administración dispone del módulo Usuarios para gestionar cuentas y perfiles autorizados. El chatbot puede consultar nombres y tipos de perfil permitidos, pero nunca revela contraseñas, hashes, tokens, credenciales ni otros secretos.'
        ],
        'alerta_temprana' => [
            'title' => 'Alerta Temprana IA',
            'keywords' => ['alerta temprana','riesgo predictivo','prediccion','predicción','riesgo curso','probabilidad'],
            'content' => 'Alerta Temprana IA estima, para un estudiante y curso, la probabilidad de que ese mismo curso presente rendimiento crítico en el siguiente bimestre. El modelo usa señales longitudinales del curso, evaluaciones, competencias, persistencia académica, asistencia y contexto de clase disponibles al corte. La probabilidad es predictiva, no una explicación causal; las notas faltantes se tratan como ausentes y no como cero.'
        ],
        'perfil_institucion' => [
            'title' => 'Institución y Configuración',
            'keywords' => ['institucion','colegio','perfil colegio','ruc','razon social','configuracion colegio','datos colegio'],
            'content' => 'EduSync mantiene datos de la institución y, para administración, configuración fiscal necesaria para facturación. El chatbot puede consultar información institucional segura como nombre, dirección, contacto, RUC o razón social cuando esté disponible, pero nunca certificados, contraseñas SUNAT, claves privadas ni tokens.'
        ],
        'catalogo_modulos' => [
            'title' => 'Catálogo de Módulos',
            'keywords' => ['modulos','módulos','todo el sistema','que tiene edusync','funciones edusync','opciones sistema'],
            'content' => 'Para Administración, el menú principal incluye Lista de Estudiantes, Actualización Masiva, Lista de Docentes, Asignar a Cursos, Gestión Académica, Años Académicos, Competencias por Nivel, Conceptos de Pagos, Asignar Deudas, Registrar Pagos, Descuentos / Becas, Reporte de Pagos, Reporte de Deudas, Comprobantes, Facturación de Deudas, Configuración de Facturación, Asistencia, Reglas de Asistencia, Reporte de Asistencia, Libro de Notas, Reporte de Notas, Fichas y Reportes, Usuarios y Alerta Temprana IA. Las opciones visibles cambian según el rol.'
        ],
        'navegacion' => [
            'title' => 'Módulos principales',
            'keywords' => ['modulo','menu','donde','navegar','seccion'],
            'content' => 'Los módulos visibles dependen del rol. Administración puede usar Estudiantes, Docentes, Gestión Académica, Años Académicos, Competencias, Gestión de Pagos, Facturación SUNAT, Asistencia, Libro de Notas, Reporte de Notas, Fichas y Reportes, Usuarios y Alerta Temprana IA. Docentes disponen principalmente de Mis Cursos, Competencias, Libro de Notas y Reporte de Notas. Auxiliares disponen de Asistencia, Reglas de Asistencia y Reporte de Asistencia. Estudiantes disponen de Mis Notas, Mis Asistencias, Mis Pagos y Mis Deudas.'
        ]
    ];
}

function edu_chat_knowledge_compact(): string {
    $lines = [];
    foreach (edu_chat_knowledge_sections() as $section) {
        $lines[] = $section['title'] . ': ' . $section['content'];
    }
    return implode("\n", $lines);
}

function edu_chat_knowledge_search(string $query, int $limit = 4): array {
    $normalized = function_exists('edu_chat_normalize') ? edu_chat_normalize($query) : mb_strtolower(trim($query), 'UTF-8');
    $scores = [];
    foreach (edu_chat_knowledge_sections() as $key => $section) {
        $score = 0;
        foreach ($section['keywords'] as $keyword) {
            $term = function_exists('edu_chat_normalize') ? edu_chat_normalize($keyword) : mb_strtolower($keyword, 'UTF-8');
            if ($term !== '' && strpos($normalized, $term) !== false) $score += 3;
        }
        $title = function_exists('edu_chat_normalize') ? edu_chat_normalize($section['title']) : mb_strtolower($section['title'], 'UTF-8');
        foreach (array_filter(preg_split('/\s+/', $title)) as $token) {
            if (mb_strlen($token, 'UTF-8') >= 5 && strpos($normalized, $token) !== false) $score++;
        }
        if ($score > 0) $scores[$key] = $score;
    }
    arsort($scores);
    $results = [];
    foreach (array_slice(array_keys($scores), 0, max(1, $limit)) as $key) {
        $section = edu_chat_knowledge_sections()[$key];
        $results[] = ['topic' => $key, 'title' => $section['title'], 'content' => $section['content']];
    }
    if (!$results) {
        $all=edu_chat_knowledge_sections();
        foreach (['catalogo_modulos','alcance'] as $key) {
            if(!isset($all[$key]))continue;
            $section=$all[$key];
            $results[]=['topic'=>$key,'title'=>$section['title'],'content'=>$section['content']];
        }
    }
    return $results;
}
