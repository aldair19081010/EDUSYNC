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
        'navegacion' => [
            'title' => 'Módulos principales',
            'keywords' => ['modulo','menu','donde','navegar','seccion'],
            'content' => 'Los módulos visibles dependen del rol. Administración puede tener Estudiantes, Docentes, Gestión Académica, Años Académicos, Competencias, Gestión de Pagos, Facturación SUNAT, Asistencia, Libro de Notas, Reporte de Notas, Fichas y Reportes y Usuarios. Docentes disponen principalmente de Mis Cursos, Competencias y Notas. Estudiantes disponen de Mis Notas, Mis Asistencias, Mis Pagos y Mis Deudas.'
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
        foreach (array_slice(edu_chat_knowledge_sections(), 0, 2) as $key => $section) {
            $results[] = ['topic' => $key, 'title' => $section['title'], 'content' => $section['content']];
        }
    }
    return $results;
}
