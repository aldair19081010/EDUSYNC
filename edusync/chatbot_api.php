<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_start();
}

if (empty($_SESSION['login_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => 'Tu sesión ha expirado.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo json_encode(['status' => 0, 'message' => 'Solicitud no autorizada.']);
    exit;
}

$message = trim((string)($_POST['message'] ?? ''));
if ($message === '' || mb_strlen($message) > 500) {
    echo json_encode(['status' => 0, 'message' => 'Escribe una consulta de hasta 500 caracteres.']);
    exit;
}

include_once 'db_connect.php';
$school_id = (int)($_SESSION['login_school_id'] ?? 0);
$user_type = (int)($_SESSION['login_type'] ?? 0);
$chat_context = $_SESSION['chatbot_context'] ?? ['last_intent' => null, 'last_topic' => null, 'last_question' => null];
$normalized = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message) : $message;
$normalized = mb_strtolower((string)$normalized, 'UTF-8');
$normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized);
$normalized = preg_replace('/\s+/', ' ', $normalized);
$normalized = trim($normalized);

$has_any = static function ($text, array $words): bool {
    foreach ($words as $word) {
        if ($word === '' || $word === ' ') continue;
        if (strpos($text, $word) !== false) return true;
    }
    return false;
};

$intents = [
    'register_student' => [
        'must' => ['estudiante', 'alumno', 'matricula'],
        'any' => ['registrar', 'crear', 'agregar', 'nuevo', 'matricular', 'inscribir'],
        'response' => 'Ve a Estudiantes, selecciona Nuevo Estudiante, completa los datos y presiona Guardar.',
        'follow_up' => ['¿Cómo subo un Excel?', '¿Cuántos estudiantes hay?', '¿Cómo registro asistencia?']
    ],
    'import_excel' => [
        'must' => ['excel', 'plantilla', 'archivo'],
        'any' => ['subir', 'cargar', 'importar', 'adjuntar'],
        'response' => 'En Estudiantes selecciona Agregar, luego Subir Excel. Descarga primero la plantilla y elige el año académico.',
        'follow_up' => ['¿Cómo registro un estudiante?', '¿Cuántos estudiantes hay?', '¿Cómo registro asistencia?']
    ],
    'disable_teacher' => [
        'must' => ['docente', 'profesor', 'maestro'],
        'any' => ['desactivar', 'activar', 'inactivar', 'reactivar', 'estado'],
        'response' => 'En Docentes usa el botón rojo para desactivar o el botón verde para reactivar. La información histórica se conserva.',
        'follow_up' => ['¿Cómo registro un estudiante?', '¿Cómo subo un Excel?', '¿Cuántos docentes hay?']
    ],
    'attendance' => [
        'must' => ['asistencia', 'tardanza', 'ausencia'],
        'any' => ['registrar', 'marcar', 'tomar', 'poner'],
        'response' => 'La asistencia se registra desde Asistencia. Las entradas se clasifican según las reglas horarias configuradas por la institución.',
        'follow_up' => ['¿Cómo registro un estudiante?', '¿Cómo subo un Excel?', '¿Cuántos estudiantes hay?']
    ],
    'count_students' => [
        'must' => ['estudiante', 'alumno'],
        'any' => ['cuantos', 'cuantas', 'cantidad', 'total', 'hay'],
        'response' => null,
        'follow_up' => ['¿Cómo registro un estudiante?', '¿Cómo subo un Excel?', '¿Cómo registro asistencia?']
    ],
    'count_teachers' => [
        'must' => ['docente', 'profesor', 'maestro'],
        'any' => ['cuantos', 'cuantas', 'cantidad', 'total', 'hay'],
        'response' => null,
        'follow_up' => ['¿Cómo desactivo un docente?', '¿Cómo registro un estudiante?', '¿Cómo subo un Excel?']
    ],
    'academic_count' => [
        'must' => ['bimestre', 'primaria', 'secundaria', 'inicial', 'critico', 'crítico', 'riesgo', 'nota baja'],
        'any' => ['cuantos', 'cuantas', 'cantidad', 'total', 'hay', 'dime'],
        'response' => null,
        'follow_up' => ['¿Cuántos estudiantes hay en secundaria?', '¿Cuántos están en riesgo en el primer bimestre?', '¿Cómo registro asistencia?']
    ],
    'help' => [
        'must' => ['ayuda', 'opciones'],
        'any' => ['puedo', 'que puedes', 'que haces', 'que puedes hacer'],
        'response' => 'Puedo explicar procesos de estudiantes, docentes, Excel, asistencia y consultas académicas por nivel y bimestre. Ejemplo: “¿Cuántos estudiantes de primaria están críticos en el primer y segundo bimestre?”',
        'follow_up' => ['¿Cómo registro un estudiante?', '¿Cómo subo un Excel?', '¿Cómo registro asistencia?']
    ]
];

$contextual_followup = static function ($text, $last_intent) {
    if (!$last_intent) return false;
    $triggers = ['ademas', 'tambien', 'ahora', 'y ahora', 'siguiente', 'eso mismo', 'igual', 'tambien necesito', 'ademas necesito', 'y luego'];
    foreach ($triggers as $trigger) {
        if (strpos($text, $trigger) !== false) return true;
    }
    return false;
};

$extractLevel = static function ($text) {
    $map = [
        'primaria' => 'Primaria',
        'secundaria' => 'Secundaria',
        'inicial' => 'Inicial',
        'preescolar' => 'Inicial'
    ];
    foreach ($map as $key => $value) {
        if (strpos($text, $key) !== false) return $value;
    }
    return null;
};

$extractBimestres = static function ($text) {
    $bimestres = [];
    $patterns = [
        'primer' => '1',
        'primero' => '1',
        '1er' => '1',
        '1ro' => '1',
        'segundo' => '2',
        '2do' => '2',
        '2do' => '2',
        '2do' => '2',
        'tercer' => '3',
        'tercero' => '3',
        '3ro' => '3',
        'cuarto' => '4',
        '4to' => '4'
    ];

    foreach ($patterns as $token => $value) {
        if (strpos($text, $token) !== false && !in_array($value, $bimestres, true)) {
            $bimestres[] = $value;
        }
    }

    if (empty($bimestres) && preg_match('/\b([1-4])\b/', $text, $matches)) {
        foreach ($matches as $m) {
            if ($m !== '0' && !in_array((string)$m, $bimestres, true)) {
                $bimestres[] = (string)$m;
            }
        }
    }

    sort($bimestres, SORT_NUMERIC);
    return $bimestres;
};

$extractRisk = static function ($text) {
    $critical = ['critico', 'critica', 'criticos', 'criticas', 'crítico', 'crítica', 'críticos', 'críticas', 'riesgo', 'riesgos', 'baja', 'bajo', 'deficiente', 'reprobado', 'reprobada'];
    if (preg_match('/\b(?:' . implode('|', $critical) . ')\b/', $text)) {
        return 'critico';
    }
    return null;
};

$academicQuery = null;
if (preg_match('/\b(?:cuantos|cuantas|cantidad|total|dime)\b/', $normalized) && preg_match('/\b(?:estudiante|alumno)\b/', $normalized)) {
    $academicQuery = [
        'level' => $extractLevel($normalized),
        'bimestres' => $extractBimestres($normalized),
        'risk' => $extractRisk($normalized),
    ];
}

$scores = [];
foreach ($intents as $key => $config) {
    $score = 0;
    foreach ($config['must'] as $word) {
        if (strpos($normalized, $word) !== false) $score += 4;
    }
    foreach ($config['any'] as $word) {
        if (strpos($normalized, $word) !== false) $score += 2;
    }
    if ($score > 0) {
        $scores[$key] = $score;
    }
}

if ($academicQuery !== null) {
    $scores['academic_count'] = ($scores['academic_count'] ?? 0) + 12;
}

$bestIntent = null;
if (!empty($scores)) {
    arsort($scores);
    $bestIntent = array_key_first($scores);
}

if ($contextual_followup($normalized, $chat_context['last_intent'] ?? null) && !empty($chat_context['last_intent'])) {
    $bestIntent = $chat_context['last_intent'];
}

$response = null;
$follow_up = [];

if ($bestIntent === 'register_student') {
    $response = $intents['register_student']['response'];
    $follow_up = $intents['register_student']['follow_up'];
} elseif ($bestIntent === 'import_excel') {
    $response = $intents['import_excel']['response'];
    $follow_up = $intents['import_excel']['follow_up'];
} elseif ($bestIntent === 'disable_teacher') {
    $response = $intents['disable_teacher']['response'];
    $follow_up = $intents['disable_teacher']['follow_up'];
} elseif ($bestIntent === 'attendance') {
    $response = $intents['attendance']['response'];
    $follow_up = $intents['attendance']['follow_up'];
} elseif ($bestIntent === 'academic_count') {
    $level = $academicQuery['level'] ?? $extractLevel($normalized);
    $bimestres = $academicQuery['bimestres'] ?? $extractBimestres($normalized);
    $risk = $academicQuery['risk'] ?? $extractRisk($normalized);

    if ($school_id <= 0) {
        $response = 'No se pudo identificar tu institución.';
    } else {
        $where = ["s.school_id = {$school_id}", "s.status = 'Activo'", "eg.grade REGEXP '^[0-9]+([.][0-9]+)?$'"];
        if ($level) {
            $where[] = "s.nivel = '" . $conn->real_escape_string($level) . "'";
        }
        if (!empty($bimestres)) {
            $quoted = array_map(static fn($v) => "'" . $conn->real_escape_string((string)$v) . "'", $bimestres);
            $where[] = 'e.bimestre IN (' . implode(',', $quoted) . ')';
        }
        if ($risk === 'critico') {
            $where[] = 'CAST(eg.grade AS DECIMAL(10,2)) <= 10';
        }

        $sql = 'SELECT COUNT(DISTINCT s.id) AS total FROM student s INNER JOIN evaluation_grades eg ON eg.student_id = s.id INNER JOIN evaluations e ON e.id = eg.evaluation_id WHERE ' . implode(' AND ', $where);
        $result = $conn->query($sql);
        $total = 0;
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $total = (int)($row['total'] ?? 0);
        }

        $levelText = $level ? strtolower($level) : 'general';
        $periodText = !empty($bimestres) ? ' en el ' . implode(' y ', array_map(static fn($v) => $v . '° bimestre', $bimestres)) : '';
        $riskText = $risk === 'critico' ? 'están críticos' : 'tienen registros académicos';
        $response = sprintf('Hay %d estudiantes de %s %s%s.', $total, $levelText, $riskText, $periodText);
    }
    $follow_up = ['¿Cuántos estudiantes hay en secundaria?', '¿Cuántos docentes hay?', '¿Cómo registro asistencia?'];
} elseif ($bestIntent === 'count_students') {
    if ($school_id <= 0) {
        $response = 'No se pudo identificar tu institución.';
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(status = 'Activo') AS activos, SUM(status = 'Retirado') AS retirados, SUM(status = 'Egresado') AS egresados FROM student WHERE school_id = ?");
        $stmt->bind_param('i', $school_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $response = sprintf('Hay %d estudiantes: %d activos, %d retirados y %d egresados.', (int)$row['total'], (int)$row['activos'], (int)$row['retirados'], (int)$row['egresados']);
    }
    $follow_up = $intents['count_students']['follow_up'];
} elseif ($bestIntent === 'count_teachers') {
    if ($school_id <= 0) {
        $response = 'No se pudo identificar tu institución.';
    } elseif (!in_array($user_type, [1, 3], true)) {
        $response = 'No tienes permisos para consultar esa información.';
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(status = 'Activo') AS activos, SUM(status = 'Inactivo') AS inactivos FROM teacher WHERE school_id = ?");
        $stmt->bind_param('i', $school_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $response = sprintf('Hay %d docentes: %d activos y %d inactivos.', (int)$row['total'], (int)$row['activos'], (int)$row['inactivos']);
    }
    $follow_up = $intents['count_teachers']['follow_up'];
} elseif ($bestIntent === 'help') {
    $response = $intents['help']['response'];
    $follow_up = $intents['help']['follow_up'];
} else {
    $response = 'Puedo ayudarte con procesos de estudiantes, docentes, Excel, asistencia y consultas académicas por nivel y bimestre. Prueba preguntando: “¿Cómo registro un estudiante?”, “¿Cómo subo un Excel?” o “¿Cuántos estudiantes de primaria están críticos en el primer y segundo bimestre?”';
    $follow_up = ['¿Cómo registro un estudiante?', '¿Cómo subo un Excel?', '¿Cuántos estudiantes de primaria están críticos?'];
    error_log('CHATBOT_FALLBACK: ' . json_encode(['message' => $message, 'normalized' => $normalized, 'scores' => $scores]));
}

$_SESSION['chatbot_context'] = [
    'last_intent' => $bestIntent ?: ($chat_context['last_intent'] ?? null),
    'last_topic' => $bestIntent ?: ($chat_context['last_topic'] ?? null),
    'last_question' => $message,
    'last_response' => $response,
];

echo json_encode(['status' => 1, 'message' => $response, 'follow_up' => $follow_up, 'intent' => $bestIntent ?: 'unknown'], JSON_UNESCAPED_UNICODE);
