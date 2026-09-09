<?php
ob_start();
include_once __DIR__ . '/session_config.php';
include __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

function grdf_response(array $data, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function grdf_level_key(string $value): string {
    return mb_strtolower(str_replace(' ', '', trim($value)), 'UTF-8');
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
if ($schoolId <= 0 || $userId <= 0) grdf_response(['status'=>0,'message'=>'Sesión no válida.'], 403);

$stmt = $conn->prepare('SELECT type,is_director FROM users WHERE id=? AND school_id=? LIMIT 1');
if (!$stmt) grdf_response(['status'=>0,'message'=>'No se pudo validar el usuario.'], 500);
$stmt->bind_param('ii', $userId, $schoolId);
$stmt->execute();
$role = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$role || (int)$role['is_director'] !== 1) {
    grdf_response(['status'=>0,'message'=>'Solo Dirección puede usar este catálogo institucional.'], 403);
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$yearId = (int)($_GET['academic_year_id'] ?? $_POST['academic_year_id'] ?? 0);
if ($yearId <= 0) grdf_response(['status'=>0,'message'=>'Seleccione un año académico.']);

if ($action === 'levels') {
    $stmt = $conn->prepare("SELECT DISTINCT ac.level
                            FROM teacher_courses tc
                            INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
                            WHERE tc.school_id=? AND tc.academic_year_id=? AND TRIM(COALESCE(ac.level,''))<>''
                            ORDER BY ac.level");
    $stmt->bind_param('ii', $schoolId, $yearId);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r['level'];
    $stmt->close();
    grdf_response(['status'=>1,'levels'=>$rows]);
}

$level = trim((string)($_GET['level'] ?? $_POST['level'] ?? ''));
if ($level === '') grdf_response(['status'=>0,'message'=>'Seleccione un nivel.']);
$levelKey = grdf_level_key($level);

if ($action === 'grades') {
    $stmt = $conn->prepare("SELECT DISTINCT tc.grado
                            FROM teacher_courses tc
                            INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
                            WHERE tc.school_id=? AND tc.academic_year_id=?
                              AND LOWER(REPLACE(TRIM(ac.level),' ',''))=?
                              AND TRIM(COALESCE(tc.grado,''))<>''
                            ORDER BY tc.grado");
    $stmt->bind_param('iis', $schoolId, $yearId, $levelKey);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r['grado'];
    $stmt->close();
    grdf_response(['status'=>1,'grados'=>$rows]);
}

$grade = trim((string)($_GET['grado'] ?? $_POST['grado'] ?? ''));
if ($grade === '') grdf_response(['status'=>0,'message'=>'Seleccione un grado.']);

if ($action === 'sections') {
    $stmt = $conn->prepare("SELECT DISTINCT COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion
                            FROM teacher_courses tc
                            INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
                            WHERE tc.school_id=? AND tc.academic_year_id=?
                              AND LOWER(REPLACE(TRIM(ac.level),' ',''))=?
                              AND LOWER(TRIM(tc.grado))=LOWER(TRIM(?))
                            ORDER BY seccion");
    $stmt->bind_param('iiss', $schoolId, $yearId, $levelKey, $grade);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r['seccion'];
    $stmt->close();
    grdf_response(['status'=>1,'secciones'=>$rows]);
}

$section = trim((string)($_GET['seccion'] ?? $_POST['seccion'] ?? ''));
if ($section === '') grdf_response(['status'=>0,'message'=>'Seleccione una sección.']);

if ($action === 'courses') {
    $sectionUpper = mb_strtoupper($section, 'UTF-8');
    $isUnique = in_array($sectionUpper, ['U','ÚNICA','UNICA'], true);
    $sectionSql = $isUnique
        ? "COALESCE(NULLIF(TRIM(tc.seccion),''),'U') IN ('U','u','Única','Unica','única','unica')"
        : "LOWER(TRIM(tc.seccion))=LOWER(TRIM(?))";
    $sql = "SELECT DISTINCT ac.id,ac.name
            FROM teacher_courses tc
            INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
            WHERE tc.school_id=? AND tc.academic_year_id=?
              AND LOWER(REPLACE(TRIM(ac.level),' ',''))=?
              AND LOWER(TRIM(tc.grado))=LOWER(TRIM(?))
              AND $sectionSql
            ORDER BY ac.name";
    $stmt = $conn->prepare($sql);
    if ($isUnique) $stmt->bind_param('iiss', $schoolId, $yearId, $levelKey, $grade);
    else $stmt->bind_param('iisss', $schoolId, $yearId, $levelKey, $grade, $section);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = ['id'=>(int)$r['id'],'name'=>$r['name']];
    $stmt->close();
    grdf_response(['status'=>1,'courses'=>$rows]);
}

grdf_response(['status'=>0,'message'=>'Acción no válida.'], 404);
