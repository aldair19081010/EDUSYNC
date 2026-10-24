<?php
/**
 * EduSync Web Push - suscripciones FCM por dispositivo/perfil/colegio.
 * Conserva los endpoints de la app Android completamente independientes.
 */
ini_set('display_errors', '0');
include_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../db_connect.php';
// subscribed_at y last_seen_at son DATETIME: almacenar siempre en UTC.
$conn->query("SET time_zone = '+00:00'");
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function wp_reply(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function wp_exists(mysqli $db, string $name): bool {
    $q = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($name) . "'");
    return (bool)($q && $q->num_rows > 0);
}
function wp_identity(mysqli $db): ?array {
    if (!empty($_SESSION['student_logged_in'])
        && (int)($_SESSION['student_id'] ?? 0) > 0
        && (int)($_SESSION['student_school_id'] ?? 0) > 0) {
        $school = (int)$_SESSION['student_school_id'];
        $id = (int)$_SESSION['student_id'];
        $s = $db->prepare('SELECT id FROM student WHERE id=? AND school_id=? LIMIT 1');
        if (!$s) return null;
        $s->bind_param('ii', $id, $school);
        $s->execute();
        $ok = (bool)$s->get_result()->fetch_assoc();
        $s->close();
        return $ok ? ['school'=>$school,'id'=>$id,'kind'=>'student','role'=>4] : null;
    }
    $school = (int)($_SESSION['login_school_id'] ?? 0);
    $id = (int)($_SESSION['login_id'] ?? 0);
    $type = (int)($_SESSION['login_type'] ?? 0);
    if ($school <= 0 || $id <= 0 || !in_array($type, [1,2,3], true)) return null;
    $s = $db->prepare("SELECT u.type, u.teacher_id FROM users u WHERE u.id=? AND u.school_id=? LIMIT 1");
    if (!$s) return null;
    $s->bind_param('ii', $id, $school);
    $s->execute();
    $record = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$record || (int)$record['type'] !== $type) return null;
    $activeCol = $db->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($activeCol && $activeCol->num_rows) {
        $s = $db->prepare('SELECT id FROM users WHERE id=? AND school_id=? AND status=? LIMIT 1');
        $active = 'Activo';
        $s->bind_param('iis', $id, $school, $active);
        $s->execute();
        $ok = (bool)$s->get_result()->fetch_assoc();
        $s->close();
        if (!$ok) return null;
    }
    if ($type === 2) {
        $teacher = (int)($record['teacher_id'] ?? 0);
        if ($teacher <= 0) return null;
        $s = $db->prepare("SELECT id FROM teacher WHERE id=? AND school_id=? AND status='Activo' LIMIT 1");
        $s->bind_param('ii', $teacher, $school);
        $s->execute();$ok = (bool)$s->get_result()->fetch_assoc();$s->close();
        if (!$ok) return null;
    }
    return ['school'=>$school,'id'=>$id,'kind'=>'staff','role'=>$type];
}

$identity = wp_identity($conn);
if (!$identity) wp_reply(['status'=>'error','message'=>'La sesión no es válida. Vuelve a ingresar.'],401);
$action = (string)($_REQUEST['action'] ?? 'config');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if ($action === 'config') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') wp_reply(['status'=>'error','message'=>'Método no permitido.'],405);
    $firebase = json_decode((string)(getenv('EDUSYNC_FIREBASE_WEB_CONFIG_JSON') ?: ''), true);
    $vapid = trim((string)(getenv('EDUSYNC_FIREBASE_VAPID_KEY') ?: ''));

    // hPanel/Hostinger: el archivo de configuración Web se instala FUERA
    // de public_html, junto a la carpeta pública del dominio:
    // /home/usuario/domains/dominio.tld/firebase/edusync-web-public.json
    // Conserva el fallback existente de Windows/XAMPP.
    // Opcionalmente se puede indicar una ruta absoluta con
    // EDUSYNC_FIREBASE_WEB_CONFIG_FILE (también fuera de la carpeta pública).
    if (!is_array($firebase) || $vapid === '') {
        $candidates = [];
        $specifiedFile = trim((string)(getenv('EDUSYNC_FIREBASE_WEB_CONFIG_FILE') ?: ''));
        if ($specifiedFile !== '') $candidates[] = $specifiedFile;

        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== false) {
            $candidates[] = dirname($documentRoot) . '/firebase/edusync-web-public.json';
        }
        // Alternativa para EduSync instalado en public_html/edusync/.
        $candidates[] = dirname(__DIR__, 3) . '/firebase/edusync-web-public.json';

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = dirname(__DIR__, 4) . '/firebase/edusync-web-public.json';
        }
        $publicRoot = $documentRoot !== false ? rtrim($documentRoot, '/\\') : '';
        foreach (array_unique($candidates) as $candidate) {
            $filePath = realpath($candidate);
            if ($filePath === false || !is_file($filePath) || !is_readable($filePath)) continue;
            // Nunca usar un archivo ubicado dentro del directorio público.
            if ($publicRoot !== '' && (
                strcasecmp($filePath, $publicRoot) === 0 ||
                strncasecmp($filePath, $publicRoot . DIRECTORY_SEPARATOR, strlen($publicRoot) + 1) === 0
            )) continue;
            $local = json_decode((string)file_get_contents($filePath), true);
            if (!is_array($local)) continue;
            if (!is_array($firebase) && isset($local['firebase']) && is_array($local['firebase'])) {
                $firebase = $local['firebase'];
            }
            if ($vapid === '') $vapid = trim((string)($local['vapid_key'] ?? ''));
            if (is_array($firebase) && $vapid !== '') break;
        }
    }
    $fields = ['apiKey','authDomain','projectId','messagingSenderId','appId'];
    $ok = is_array($firebase) && strlen($vapid) > 30;
    if ($ok) foreach ($fields as $key) if (empty($firebase[$key])) $ok = false;
    // Comprobar si ESTE navegador está realmente asociado con la cuenta
    // autenticada. Nunca devolver el token FCM ni datos de otros dispositivos.
    $deviceRegistered=false;
    $requestedBrowser=trim((string)($_GET['browser_id']??''));
    if(preg_match('/^[a-zA-Z0-9._:-]{20,100}$/D',$requestedBrowser)
        && wp_exists($conn,'web_push_subscriptions')) {
        $check=$conn->prepare('SELECT id FROM web_push_subscriptions WHERE browser_id=? AND school_id=? AND account_kind=? AND account_id=? AND is_active=1 LIMIT 1');
        if($check){
            $check->bind_param('sisi',$requestedBrowser,$identity['school'],$identity['kind'],$identity['id']);
            $check->execute();
            $deviceRegistered=(bool)$check->get_result()->fetch_assoc();
            $check->close();
        }
    }
    wp_reply([
        'status'=>'ok','configured'=>$ok,
        'device_registered'=>$deviceRegistered,
        'firebase'=>$ok ? array_intersect_key($firebase,array_fill_keys($fields,true)) : null,
        'vapid_key'=>$ok ? $vapid : '',
        'csrf_token'=>$_SESSION['csrf_token'],
        'identity'=>$identity['kind'].':'.$identity['school'].':'.$identity['id'],
        'push_ready'=>wp_exists($conn,'web_push_subscriptions') && wp_exists($conn,'web_push_delivery'),
    ]);
}
if ($action === 'status') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        wp_reply(['status'=>'error','message'=>'Método no permitido.'],405);
    }
    $ready=wp_exists($conn,'web_push_subscriptions') && wp_exists($conn,'web_push_delivery');
    require_once __DIR__ . '/../includes/web_push_fcm_sender.php';
    $private=edwp_load_firebase_credentials();
    $senderReady=is_array($private) && !empty($private['project_id'])
        && !empty($private['client_email']) && !empty($private['private_key']);
    $activeCount=0;
    $latest=null;
    $stats=[];
    if ($ready) {
        $stmt=$conn->prepare('SELECT COUNT(*) total FROM web_push_subscriptions WHERE school_id=? AND account_kind=? AND account_id=? AND is_active=1');
        $stmt->bind_param('isi',$identity['school'],$identity['kind'],$identity['id']);
        $stmt->execute();
        $activeCount=(int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        $stmt=$conn->prepare('SELECT d.event_kind,d.status,d.updated_at FROM web_push_delivery d INNER JOIN web_push_subscriptions s ON s.id=d.subscription_id WHERE s.school_id=? AND s.account_kind=? AND s.account_id=? ORDER BY d.updated_at DESC LIMIT 12');
        $stmt->bind_param('isi',$identity['school'],$identity['kind'],$identity['id']);
        $stmt->execute();
        $res=$stmt->get_result();
        while ($row=$res->fetch_assoc()) {
            if ($latest === null) $latest=(string)$row['updated_at'];
            $key=(string)$row['status'];
            $stats[$key]=($stats[$key] ?? 0)+1;
        }
        $stmt->close();
    }
    wp_reply([
        'status'=>'ok',
        'push_tables_ready'=>$ready,
        'sender_credentials_present'=>$senderReady,
        'active_subscriptions'=>$activeCount,
        'latest_delivery_attempt_utc'=>$latest,
        'recent_delivery_status'=>$stats,
    ]);
}

// Diagnóstico privado de la APP NATIVA: un navegador registrado no equivale
// a que Flutter tenga su propia suscripción FCM. Nunca exponer el token.
if ($action === 'app_status') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        wp_reply(['status'=>'error','message'=>'Método no permitido.'],405);
    }
    if ($identity['kind'] !== 'staff' ||
        !in_array((int)$identity['role'],[1,2,3],true)) {
        wp_reply(['status'=>'error','message'=>'Disponible para personal autorizado.'],403);
    }
    $browserId = trim((string)($_GET['browser_id'] ?? ''));
    if (!preg_match('/^app-[a-zA-Z0-9._:-]{16,96}$/D', $browserId)) {
        wp_reply(['status'=>'error','message'=>'Identificador de aplicación inválido.'],422);
    }
    if (!wp_exists($conn,'web_push_subscriptions')) {
        wp_reply(['status'=>'ok','registered'=>false,'push_ready'=>false,
            'latest_delivery_status'=>null,'last_seen_at_utc'=>null]);
    }
    $stmt=$conn->prepare("SELECT id,last_seen_at FROM web_push_subscriptions
        WHERE school_id=? AND account_kind='staff' AND role_type=?
          AND account_id=? AND browser_id=? AND is_active=1 LIMIT 1");
    $stmt->bind_param('iiis',$identity['school'],$identity['role'],
        $identity['id'],$browserId);
    $stmt->execute();$subscription=$stmt->get_result()->fetch_assoc();$stmt->close();
    $delivery=null;
    if ($subscription && wp_exists($conn,'web_push_delivery')) {
        $stmt=$conn->prepare("SELECT status,updated_at FROM web_push_delivery
            WHERE school_id=? AND subscription_id=?
            ORDER BY id DESC LIMIT 1");
        $subscriptionId=(int)$subscription['id'];
        $stmt->bind_param('ii',$identity['school'],$subscriptionId);
        $stmt->execute();$delivery=$stmt->get_result()->fetch_assoc();$stmt->close();
    }
    require_once __DIR__ . '/../includes/web_push_fcm_sender.php';
    $credentials=edwp_load_firebase_credentials();
    $projectId=is_array($credentials)?(string)($credentials['project_id']??''):'';
    wp_reply(['status'=>'ok','registered'=>(bool)$subscription,'push_ready'=>true,
        'sender_project_id'=>$projectId,
        'last_seen_at_utc'=>$subscription['last_seen_at']??null,
        'latest_delivery_status'=>$delivery['status']??null,
        'latest_delivery_at_utc'=>$delivery['updated_at']??null]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') wp_reply(['status'=>'error','message'=>'Método no permitido.'],405);
if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    wp_reply(['status'=>'error','message'=>'Sesión de seguridad vencida. Recarga EduSync.'],403);
}
if (!wp_exists($conn,'web_push_subscriptions')) {
    wp_reply(['status'=>'error','migration_required'=>true,'message'=>'Falta ejecutar sql/web_push_fcm_v1.sql en la BD de pruebas.'],409);
}
$browserId = trim((string)($_POST['browser_id'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9._:-]{20,100}$/D', $browserId)) {
    wp_reply(['status'=>'error','message'=>'Identificador de navegador inválido.'],422);
}
$school=$identity['school'];$id=$identity['id'];$kind=$identity['kind'];$role=$identity['role'];

if ($action === 'unregister') {
    $s = $conn->prepare("UPDATE web_push_subscriptions SET is_active=0 WHERE browser_id=? AND school_id=? AND account_kind=? AND account_id=?");
    $s->bind_param('sisi',$browserId,$school,$kind,$id);
    $s->execute();
    $s->close();
    // Se permite cerrar incluso si el navegador ya no tenía suscripción activa.
    wp_reply(['status'=>'ok','message'=>'Notificaciones desactivadas en este navegador.']);
}
if ($action !== 'register') {
    wp_reply(['status'=>'error','message'=>'Acción no válida.'],400);
}

if ($action === 'register') {
    $token=trim((string)($_POST['fcm_token'] ?? ''));
    if (strlen($token)<30 || strlen($token)>1024) wp_reply(['status'=>'error','message'=>'Token FCM inválido.'],422);
    $tokenHash=hash('sha256',$token);
    $conn->begin_transaction();
    try {
        // Si no cambia la cuenta ni el token, NO reiniciar subscribed_at:
        // esto evita perder avisos entre las navegaciones de EduSync.
        $stmt=$conn->prepare('SELECT id,school_id,account_kind,account_id,token_hash FROM web_push_subscriptions WHERE browser_id=? FOR UPDATE');
        $stmt->bind_param('s',$browserId);$stmt->execute();
        $current=$stmt->get_result()->fetch_assoc();$stmt->close();
        if ($current && (int)$current['school_id']===$school
            && $current['account_kind']===$kind
            && (int)$current['account_id']===$id
            && hash_equals($current['token_hash'],$tokenHash)) {
            $key=(int)$current['id'];
            $stmt=$conn->prepare('UPDATE web_push_subscriptions SET subscribed_at=IF(is_active=0,NOW(),subscribed_at),role_type=?,is_active=1,last_seen_at=NOW() WHERE id=?');
            $stmt->bind_param('ii',$role,$key);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
        } else {
            // Al cambiar de perfil/colegio/token se elimina la asociación
            // anterior para evitar entregar avisos de otra cuenta.
            $stmt=$conn->prepare('DELETE FROM web_push_subscriptions WHERE browser_id=? OR token_hash=?');
            $stmt->bind_param('ss',$browserId,$tokenHash);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            $stmt=$conn->prepare('INSERT INTO web_push_subscriptions (school_id,account_kind,account_id,role_type,browser_id,fcm_token,token_hash,is_active,subscribed_at,last_seen_at) VALUES (?,?,?,?,?,?,?,1,NOW(),NOW())');
            $stmt->bind_param('isiisss',$school,$kind,$id,$role,$browserId,$token,$tokenHash);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
        }
        $conn->commit();
        setcookie('edusync_web_push_browser',$browserId,[
            'expires'=>time()+365*24*60*60,
            'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off',
            'httponly'=>true,'samesite'=>'Lax'
        ]);
        wp_reply(['status'=>'ok','message'=>'Este navegador recibirá notificaciones de EduSync.']);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[EduSync web push register] '.$e->getMessage());
        wp_reply(['status'=>'error','message'=>'No se pudo registrar el navegador.'],500);
    }
}
