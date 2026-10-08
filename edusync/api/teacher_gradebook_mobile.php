<?php
// Puente móvil del Libro de Notas: reutiliza la lógica y reglas de EduSync Web.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function tgb_reply(array $value, int $code = 200): void {
    http_response_code($code);
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function tgb_table(mysqli $db, string $table): bool {
    $t = $db->real_escape_string($table);
    $found = $db->query("SHOW TABLES LIKE '$t'");
    return $found && $found->num_rows > 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tgb_reply(['status' => 0, 'message' => 'Método no permitido.'], 405);
}
$sid = trim((string)($_POST['sid'] ?? ''));
if ($sid === '' || !preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $sid)) {
    tgb_reply(['status' => 0, 'message' => 'Inicia sesión nuevamente.'], 401);
}
ob_start();
require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../db_connect.php';
ob_end_clean();

$uid = (int)($_SESSION['login_id'] ?? 0);
$teacherId = (int)($_SESSION['login_teacher_id'] ?? 0);
$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
if ((int)($_SESSION['login_type'] ?? 0) !== 2 || $uid <= 0 || $teacherId <= 0 || $schoolId <= 0) {
    tgb_reply(['status' => 0, 'message' => 'Sesión docente inválida.'], 403);
}
$userStatusCol = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$activeUserFilter = ($userStatusCol && $userStatusCol->num_rows)
    ? " AND u.status='Activo'" : '';
$owner = $conn->prepare("SELECT u.id FROM users u INNER JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id WHERE u.id=? AND u.school_id=? AND u.teacher_id=? AND u.type=2 AND t.status='Activo' {$activeUserFilter} LIMIT 1");
if (!$owner) tgb_reply(['status' => 0, 'message' => 'No se pudo validar la cuenta.'], 500);
$owner->bind_param('iii', $uid, $schoolId, $teacherId);
$owner->execute();
$validUser = (bool)$owner->get_result()->fetch_assoc();
$owner->close();
if (!$validUser) tgb_reply(['status' => 0, 'message' => 'La cuenta docente no está habilitada.'], 403);

$action = trim((string)($_POST['action'] ?? 'load'));
if ($action === 'token') {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    tgb_reply(['status' => 1, 'csrf_token' => $_SESSION['csrf_token']]);
}
// Sincronizar la preferencia ya utilizada por EduSync Web.
// Si la migración aún no existe, no bloquear el Libro de Notas.
if ($action === 'preference' || $action === 'set_preference') {
    $column = $conn->query("SHOW COLUMNS FROM users LIKE 'grades_autosave'");
    $hasColumn = $column && $column->num_rows > 0;
    if ($action === 'preference') {
        if (!$hasColumn) {
            tgb_reply([
                'status' => 1,
                'enabled' => isset($_SESSION['login_grades_autosave'])
                    ? (int)$_SESSION['login_grades_autosave'] === 1 : true,
                'persisted' => false,
            ]);
        }
        $pref = $conn->prepare('SELECT grades_autosave FROM users WHERE id=? AND school_id=? LIMIT 1');
        $pref->bind_param('ii', $uid, $schoolId);
        $pref->execute();
        $row = $pref->get_result()->fetch_assoc();
        $pref->close();
        if (!$row) tgb_reply(['status'=>0,'message'=>'No se encontró el usuario.'],404);
        tgb_reply(['status'=>1,'enabled'=>(int)$row['grades_autosave']===1,'persisted'=>true]);
    }
    $token = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        tgb_reply(['status'=>0,'message'=>'La sesión de seguridad venció.'],403);
    }
    $enabled = ($_POST['enabled'] ?? '') === '1' ? 1 : 0;
    if ($hasColumn) {
        $pref = $conn->prepare('UPDATE users SET grades_autosave=? WHERE id=? AND school_id=?');
        $pref->bind_param('iii', $enabled, $uid, $schoolId);
        if (!$pref->execute()) {
            $pref->close();
            tgb_reply(['status'=>0,'message'=>'No se pudo guardar la preferencia.'],500);
        }
        $pref->close();
    }
    $_SESSION['login_grades_autosave'] = $enabled;
    tgb_reply([
        'status'=>1,'enabled'=>$enabled===1,'persisted'=>$hasColumn,
        'message'=>$hasColumn ? 'Preferencia sincronizada con EduSync Web.'
            : 'Preferencia temporal. Para sincronizarla con la web se requiere la migración de autoguardado.',
    ]);
}

if (!in_array($action, ['load', 'save', 'create_evaluation'], true)) {
    tgb_reply(['status' => 0, 'message' => 'Acción no válida.'], 400);
}
$tcid = (int)($_POST['teacher_course_id'] ?? 0);
$bim = (int)($_POST['bimestre'] ?? 0);
if ($tcid <= 0 || $bim < 1 || $bim > 4) {
    tgb_reply(['status' => 0, 'message' => 'Selecciona un curso y bimestre válidos.'], 422);
}
$stmt = $conn->prepare("SELECT tc.id,tc.course_id,tc.academic_year_id,ay.is_active,
       ac.is_active course_active, COALESCE(ac.course_status,'Activo') course_status
       FROM teacher_courses tc
       INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
       INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
       WHERE tc.id=? AND tc.teacher_id=? AND tc.school_id=? LIMIT 1");
if (!$stmt) tgb_reply(['status' => 0, 'message' => 'No se pudo consultar la asignación.'], 500);
$stmt->bind_param('iii', $tcid, $teacherId, $schoolId);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$assignment) tgb_reply(['status' => 0, 'message' => 'El curso no pertenece a este docente.'], 403);

if ($action === 'load') {
    // El controlador Web comprueba los periodos cerrados y devuelve solo lectura.
    $_GET['action'] = 'load';
    $_GET['teacher_course_id'] = (string)$tcid;
    $_GET['bimestre'] = (string)$bim;
    require __DIR__ . '/../gradebook_api.php';
    exit;
}

$token = trim((string)($_POST['csrf_token'] ?? ''));
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    tgb_reply(['status' => 0, 'message' => 'La sesión de seguridad venció. Vuelve a ingresar.'], 403);
}
if ($action === 'save') {
    // Guardado, auditoría, detección de conflictos y avisos: controlador oficial.
    $_GET['action'] = 'save';
    require __DIR__ . '/../gradebook_api.php';
    exit;
}

// Crear evaluación de la misma forma que EduSync Web, sin delegar permisos.
if ((int)$assignment['is_active'] !== 1 || (int)$assignment['course_active'] !== 1 ||
    $assignment['course_status'] !== 'Activo') {
    tgb_reply(['status' => 0, 'message' => 'Este curso o año solo permite consultar el historial.'], 403);
}
$yearId = (int)$assignment['academic_year_id'];
$hasYearStatus = $conn->query("SHOW COLUMNS FROM academic_year LIKE 'status'");
if ($hasYearStatus && $hasYearStatus->num_rows) {
    $yearStmt = $conn->prepare('SELECT status FROM academic_year WHERE id=? AND school_id=?');
    $yearStmt->bind_param('ii', $yearId, $schoolId);
    $yearStmt->execute();
    $yearStatus = $yearStmt->get_result()->fetch_assoc()['status'] ?? '';
    $yearStmt->close();
    if (in_array($yearStatus, ['Cerrado', 'Archivado'], true)) {
        tgb_reply(['status' => 0, 'message' => 'El año académico está cerrado.'], 403);
    }
}
if (tgb_table($conn, 'bimester_locks')) {
    $locked = $conn->prepare('SELECT is_locked FROM bimester_locks WHERE school_id=? AND academic_year_id=? AND bimester=? LIMIT 1');
    $locked->bind_param('iii', $schoolId, $yearId, $bim);
    $locked->execute();
    $lockedRow = $locked->get_result()->fetch_assoc();
    $locked->close();
    if ($lockedRow && (int)$lockedRow['is_locked'] === 1) {
        tgb_reply(['status' => 0, 'message' => 'Este bimestre está bloqueado.'], 403);
    }
}
if (tgb_table($conn, 'grade_period_closures')) {
    $closed = $conn->prepare("SELECT id FROM grade_period_closures WHERE school_id=? AND teacher_course_id=? AND bimester=? AND status='Cerrado' LIMIT 1");
    $closed->bind_param('iii', $schoolId, $tcid, $bim);
    $closed->execute();
    $isClosed = (bool)$closed->get_result()->fetch_assoc();
    $closed->close();
    if ($isClosed) tgb_reply(['status' => 0, 'message' => 'Este bimestre fue cerrado por el docente.'], 403);
}
$compId = (int)($_POST['competencia_id'] ?? 0);
$competency = $conn->prepare("SELECT id FROM general_course_competencies WHERE id=? AND course_id=? AND teacher_id=? AND academic_year_id=? AND is_active=1 LIMIT 1");
$courseId = (int)$assignment['course_id'];
$competency->bind_param('iiii', $compId, $courseId, $teacherId, $yearId);
$competency->execute();
$validCompetency = (bool)$competency->get_result()->fetch_assoc();
$competency->close();
if (!$validCompetency) tgb_reply(['status' => 0, 'message' => 'La competencia no pertenece al curso activo.'], 422);
$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$type = trim((string)($_POST['type'] ?? 'Tarea'));
if ($title === '' || mb_strlen($title, 'UTF-8') > 150 || mb_strlen($description, 'UTF-8') > 1200) {
    tgb_reply(['status' => 0, 'message' => 'Revisa el título y descripción de la evaluación.'], 422);
}
if (!in_array($type, ['Tarea', 'Examen', 'Examen Parcial', 'Examen Final', 'Quiz', 'Práctica', 'Proyecto', 'Participación', 'Otro'], true)) {
    tgb_reply(['status' => 0, 'message' => 'Tipo de evaluación inválido.'], 422);
}
$duplicate = $conn->prepare('SELECT id FROM evaluations WHERE teacher_course_id=? AND teacher_id=? AND academic_year_id=? AND bimestre=? AND title=? LIMIT 1');
$duplicate->bind_param('iiiis', $tcid, $teacherId, $yearId, $bim, $title);
$duplicate->execute();
$exists = (bool)$duplicate->get_result()->fetch_assoc();
$duplicate->close();
if ($exists) tgb_reply(['status' => 0, 'message' => 'Ya existe una evaluación con este nombre en el bimestre.'], 409);

// Action::save_evaluation es también el guardado oficial de la interfaz Web.
$_POST['id'] = '0';
$_POST['teacher_course_id'] = (string)$tcid;
$_POST['academic_year_id'] = (string)$yearId;
$_POST['teacher_id'] = (string)$teacherId;
$_POST['bimestre'] = (string)$bim;
$_POST['description'] = $description !== '' ? $description : $title;
$_POST['competencias'] = [(string)$compId];
// admin_class.php usa rutas relativas al director edusync al crear Action.
// Proteger el JSON de advertencias o salida HTML no deseada.
$previousDirectory = getcwd();
$bufferLevel = ob_get_level();
ob_start();
try {
    if (!chdir(__DIR__ . '/..')) {
        throw new RuntimeException('No se pudo establecer el directorio de EduSync.');
    }
    require_once __DIR__ . '/../admin_class.php';
    $actionHandler = new Action();
    $result = json_decode($actionHandler->save_evaluation(), true);
} catch (Throwable $error) {
    error_log('[teacher gradebook evaluation] ' . $error->getMessage());
    $result = null;
} finally {
    while (ob_get_level() > $bufferLevel) ob_end_clean();
    if ($previousDirectory !== false) chdir($previousDirectory);
}
if (!is_array($result)) tgb_reply(['status'=>0,'message'=>'El servidor no pudo guardar la evaluación.'],500);
tgb_reply($result, (int)($result['status'] ?? 0) === 1 ? 200 : 422);
