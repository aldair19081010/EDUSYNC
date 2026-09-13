<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/chatbot_engine.php';
require_once __DIR__ . '/includes/chatbot_queries.php';
require_once __DIR__ . '/includes/chatbot_ai.php';
require_once __DIR__ . '/includes/chatbot_advanced_assistant.php';
require_once __DIR__ . '/includes/predictive_risk_router.php';

function edu_chat_api_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function edu_chat_first_name(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return '';
    $parts = explode(' ', $name);
    return (string)($parts[0] ?? $name);
}

function edu_chat_history_add(string $role, string $text, array $extra = []): void {
    if (!isset($_SESSION['chatbot_history']) || !is_array($_SESSION['chatbot_history'])) $_SESSION['chatbot_history'] = [];
    $_SESSION['chatbot_history'][] = array_merge(['role' => $role, 'text' => $text], $extra);
    if (count($_SESSION['chatbot_history']) > 16) $_SESSION['chatbot_history'] = array_slice($_SESSION['chatbot_history'], -16);
}

function edu_chat_rate_limit(): void {
    $now = microtime(true);
    $state = is_array($_SESSION['chatbot_rate'] ?? null) ? $_SESSION['chatbot_rate'] : ['last' => 0.0, 'hits' => []];
    $last = (float)($state['last'] ?? 0);
    if ($last > 0 && ($now - $last) < 0.45) {
        edu_chat_api_reply(['status' => 0, 'message' => 'Espera un momento antes de enviar otra consulta.'], 429);
    }
    $hits = array_values(array_filter((array)($state['hits'] ?? []), static fn($ts) => ($now - (float)$ts) <= 600));
    if (count($hits) >= 60) {
        edu_chat_api_reply(['status' => 0, 'message' => 'Has realizado muchas consultas seguidas. Inténtalo nuevamente más tarde.'], 429);
    }
    $hits[] = $now;
    $_SESSION['chatbot_rate'] = ['last' => $now, 'hits' => $hits];
}

function edu_chat_local_result(mysqli $conn, array $actor, string $message): array {
    $context = is_array($_SESSION['chatbot_context'] ?? null)
        ? $_SESSION['chatbot_context']
        : ['last_intent' => null, 'entities' => []];

    $parsed = edu_chat_interpret($conn, $actor, $message, $context);
    $intent = (string)$parsed['intent'];
    $entities = (array)$parsed['entities'];
    $normalized = (string)($parsed['normalized'] ?? '');

    if (!empty($entities['bimestre']) && strpos($normalized, 'grado') === false) {
        $explicitGrade = preg_match('/\b[1-6](?:ro|do|to|er|°)\s+(?:de\s+)?(?:primaria|secundaria)\b/', $normalized);
        if (!$explicitGrade) $entities['grade'] = null;
    }

    if (!edu_chat_allowed($actor, $intent) && $intent !== 'unknown') {
        $result = edu_chat_result(
            'Esa consulta no está disponible para tu perfil de ' . $actor['role'] . '. Solo puedo mostrar información autorizada para tu rol.',
            edu_chat_suggestions($actor)
        );
    } elseif ($intent === 'academic_risk') {
        $result = edu_chat_academic_risk_current_result($conn, $actor, $entities);
    } elseif ($intent === 'count_students' && (int)$actor['type'] === 2) {
        $result = edu_chat_teacher_students_current_result($conn, $actor, $entities);
    } else {
        $result = edu_chat_execute($conn, $actor, $intent, $entities);
    }

    $_SESSION['chatbot_context'] = [
        'last_intent' => $intent !== 'unknown' ? $intent : ($context['last_intent'] ?? null),
        'entities' => $intent !== 'unknown' ? $entities : ($context['entities'] ?? []),
        'last_question' => $message,
        'updated_at' => time()
    ];

    $result['intent'] = $intent;
    $result['mode'] = 'local';
    return $result;
}

try {
    $actor = edu_chat_resolve_actor($conn);
    if (!$actor) edu_chat_api_reply(['status' => 0, 'message' => 'Tu sesión ha expirado.'], 401);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = trim((string)($_REQUEST['action'] ?? 'message'));

    if ($method === 'GET' && $action === 'bootstrap') {
        $firstName = edu_chat_first_name((string)$actor['name']);
        $aiEnabled = edu_chat_ai_enabled();
        $greeting = 'Hola' . ($firstName !== '' ? ', ' . $firstName : '') . '. Soy el Asistente EduSync. Puedo ayudarte con dudas del sistema y consultar información según los permisos de tu perfil.';
        edu_chat_api_reply([
            'status' => 1,
            'greeting' => $greeting,
            'role' => (string)$actor['role'],
            'assistant_mode' => $aiEnabled ? edu_chat_ai_mode() : 'local',
            'suggestions' => edu_chat_suggestions($actor),
            'history' => array_values((array)($_SESSION['chatbot_history'] ?? []))
        ]);
    }

    if ($method !== 'POST') edu_chat_api_reply(['status' => 0, 'message' => 'Método no permitido.'], 405);

    $token = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
        edu_chat_api_reply(['status' => 0, 'message' => 'La sesión de seguridad venció. Recarga la página.'], 403);
    }

    if ($action === 'reset') {
        unset($_SESSION['chatbot_context'], $_SESSION['chatbot_query_state'], $_SESSION['chatbot_history'], $_SESSION['chatbot_rate']);
        edu_chat_api_reply([
            'status' => 1,
            'message' => 'Conversación reiniciada.',
            'assistant_mode' => edu_chat_ai_enabled() ? edu_chat_ai_mode() : 'local',
            'suggestions' => edu_chat_suggestions($actor)
        ]);
    }

    edu_chat_rate_limit();

    $message = trim((string)($_POST['message'] ?? ''));
    if ($message === '' || mb_strlen($message, 'UTF-8') > 500) {
        edu_chat_api_reply(['status' => 0, 'message' => 'Escribe una consulta de hasta 500 caracteres.'], 422);
    }

    $history = array_values((array)($_SESSION['chatbot_history'] ?? []));
    $queryState = is_array($_SESSION['chatbot_query_state'] ?? null) ? $_SESSION['chatbot_query_state'] : [];
    $effectiveMessage = edu_chat_contextualize_message($message, $queryState);
    $result = null;
    $aiError = null;

    // La alerta temprana predictiva se ejecuta antes de Groq. El cálculo y la
    // explicación provienen del modelo entrenado; el LLM no altera probabilidades.
    try {
        $result = edu_predictive_try($conn, $actor, $effectiveMessage, $queryState, $history);
        if (is_array($result)) {
            $result['mode'] = edu_chat_ai_enabled() ? edu_chat_ai_mode() : 'local';
            $result['intent'] = 'predictive_risk';
        }
    } catch (Throwable $predictiveException) {
        error_log('[chatbot_predictive fallback] ' . $predictiveException->getMessage() . ' line ' . $predictiveException->getLine());
        $result = null;
    }

    // Las consultas administrativas inequívocas se resuelven de forma determinista
    // para conservar nombres, montos, porcentajes y filtros exactamente como salen de MySQL.
    if (!is_array($result)) {
        try {
            $result = edu_chat_advanced_try($conn, $actor, $effectiveMessage, $queryState);
            if (is_array($result)) {
                $result['mode'] = edu_chat_ai_enabled() ? edu_chat_ai_mode() : 'local';
                $result['intent'] = 'advanced';
            }
        } catch (Throwable $advancedException) {
            error_log('[chatbot_advanced fallback] ' . $advancedException->getMessage() . ' line ' . $advancedException->getLine());
            $result = null;
        }
    }

    if (!is_array($result) && edu_chat_ai_enabled()) {
        try {
            $result = edu_chat_ai_ask($conn, $actor, $effectiveMessage, $history);
        } catch (Throwable $aiException) {
            $aiError = $aiException->getMessage();
            error_log('[chatbot_ai fallback] ' . $aiException->getMessage() . ' line ' . $aiException->getLine());
        }
    }

    if (!is_array($result)) $result = edu_chat_local_result($conn, $actor, $effectiveMessage);

    $_SESSION['chatbot_query_state'] = edu_chat_context_update_state($message, $result, $queryState);

    edu_chat_history_add('user', $message);
    edu_chat_history_add('assistant', (string)($result['message'] ?? ''), [
        'cards' => (array)($result['cards'] ?? []),
        'actions' => (array)($result['actions'] ?? [])
    ]);

    edu_chat_api_reply([
        'status' => 1,
        'message' => (string)($result['message'] ?? 'Consulta procesada.'),
        'follow_up' => (array)($result['follow_up'] ?? []),
        'cards' => (array)($result['cards'] ?? []),
        'actions' => (array)($result['actions'] ?? []),
        'intent' => (string)($result['intent'] ?? 'ai'),
        'role' => (string)$actor['role'],
        'assistant_mode' => (string)($result['mode'] ?? 'local'),
        'fallback_used' => $aiError !== null
    ]);
} catch (Throwable $e) {
    error_log('[chatbot_api] ' . $e->getMessage() . ' line ' . $e->getLine());
    edu_chat_api_reply(['status' => 0, 'message' => 'No pude procesar la consulta en este momento. Inténtalo nuevamente.'], 500);
}
