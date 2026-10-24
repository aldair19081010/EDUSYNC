<?php
declare(strict_types=1);

// Emisión de códigos QR firmados: SOLO Administración, mediante sesión web.
require_once __DIR__.'/../includes/dev_cors.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function qr_admin_reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    qr_admin_reply(405, ['status'=>'error','message'=>'Método no permitido.']);
}
try {
    require_once __DIR__.'/../session_config.php';
    require_once __DIR__.'/../db_connect.php';
    require_once __DIR__.'/../includes/attendance_qr_code.php';

    $school = (int)($_SESSION['login_school_id'] ?? 0);
    $userId = (int)($_SESSION['login_id'] ?? 0);
    if ($school <= 0 || $userId <= 0 || (int)($_SESSION['login_type'] ?? 0) !== 1) {
        qr_admin_reply(403, ['status'=>'error','message'=>'Solo Administración puede emitir códigos QR.']);
    }
    // Previene solicitudes de otros orígenes cuando el administrador está autenticado.
    $sentCsrf = (string)($_POST['csrf_token'] ?? '');
    $expectedCsrf = (string)($_SESSION['csrf_token'] ?? '');
    if ($sentCsrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $sentCsrf)) {
        qr_admin_reply(403, ['status'=>'error','message'=>'Token de seguridad no válido. Actualiza la página.']);
    }
    $q = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
    $active = $q && $q->num_rows > 0 ? " AND status='Activo'" : '';
    $q = $conn->prepare("SELECT id FROM users WHERE id=? AND school_id=? AND type=1 {$active} LIMIT 1");
    $q->bind_param('ii', $userId, $school);
    $q->execute();
    $allowed = (bool)$q->get_result()->fetch_assoc();
    $q->close();
    if (!$allowed) qr_admin_reply(403, ['status'=>'error','message'=>'Cuenta no autorizada.']);

    $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($studentId === false || $studentId === null) {
        qr_admin_reply(422, ['status'=>'error','message'=>'Selecciona un estudiante válido.']);
    }
    $q = $conn->prepare("SELECT id,name,nivel,grado,seccion FROM student WHERE id=? AND school_id=? AND status='Activo' LIMIT 1");
    $q->bind_param('ii', $studentId, $school);
    $q->execute();
    $student = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$student) {
        qr_admin_reply(404, ['status'=>'error','message'=>'El estudiante no pertenece a tu colegio o está inactivo.']);
    }

    qr_admin_reply(200, [
        'status'=>'ok',
        'format'=>'ESQ1',
        'code'=>edusync_attendance_qr_issue($school, (int)$student['id']),
        'student'=>[
            'id'=>(int)$student['id'], 'name'=>$student['name'],
            'nivel'=>$student['nivel'], 'grado'=>$student['grado'],
            'seccion'=>$student['seccion'],
        ],
        'message'=>'Contenido QR generado. Conviértelo en un QR para el carné; no modifiques el texto.',
    ]);
} catch (RuntimeException $e) {
    // Un QR sin secreto NO debe emitirse con una firma predecible.
    qr_admin_reply(503, ['status'=>'error','message'=>'La emisión QR aún no está configurada.']);
} catch (Throwable $e) {
    error_log('[attendance QR admin] '.$e->getMessage());
    qr_admin_reply(500, ['status'=>'error','message'=>'No se pudo generar el QR del estudiante.']);
}
