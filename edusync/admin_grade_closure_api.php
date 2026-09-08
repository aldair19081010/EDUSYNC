<?php
ob_start();
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
header('Content-Type: application/json; charset=utf-8');

function agca_response(array $data, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function agca_table(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}
function agca_column(mysqli $db, string $table, string $column): bool {
    $t = str_replace('`', '', $table);
    $c = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $q && $q->num_rows > 0;
}
function agca_csrf(): void {
    $sent = (string)($_POST['csrf_token'] ?? '');
    $saved = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $saved === '' || !hash_equals($saved, $sent)) {
        agca_response(['status'=>0,'message'=>'La sesión de seguridad venció. Recarga la página.'], 403);
    }
}
function agca_norm(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return preg_replace('/[°º\s]+/u', '', $value) ?? $value;
}
function agca_section_condition(mysqli $db, string $section): string {
    $section = trim($section);
    if (in_array(mb_strtoupper($section, 'UTF-8'), ['U','ÚNICA','UNICA'], true)) {
        return "COALESCE(NULLIF(TRIM(s.seccion),''),'U') IN ('U','u','Única','Unica','única','unica')";
    }
    return "s.seccion='" . $db->real_escape_string($section) . "'";
}
function agca_year(mysqli $db, int $schoolId, int $yearId): ?array {
    $stmt = $db->prepare('SELECT * FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
    $stmt->bind_param('ii', $yearId, $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function agca_assignment(mysqli $db, int $schoolId, int $yearId, int $teacherCourseId): ?array {
    $yearStatus = agca_column($db, 'academic_year', 'status') ? ',ay.status year_status' : ",'' year_status";
    $stmt = $db->prepare("SELECT tc.id,tc.school_id,tc.teacher_id,tc.course_id,tc.academic_year_id,tc.grado,
                                COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,
                                ac.name course_name,ac.level,ay.year,ay.is_active$yearStatus,
                                COALESCE(t.name,'Docente no disponible') teacher_name
                         FROM teacher_courses tc
                         INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
                         INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
                         LEFT JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
                         WHERE tc.id=? AND tc.school_id=? AND tc.academic_year_id=? LIMIT 1");
    $stmt->bind_param('iii', $teacherCourseId, $schoolId, $yearId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function agca_closure(mysqli $db, int $schoolId, int $teacherCourseId, int $bimester): ?array {
    $stmt = $db->prepare("SELECT gpc.*,COALESCE(u.name,'Usuario') closed_by_name,COALESCE(u.type,0) closed_by_type
                          FROM grade_period_closures gpc
                          LEFT JOIN users u ON u.id=gpc.closed_by AND u.school_id=gpc.school_id
                          WHERE gpc.school_id=? AND gpc.teacher_course_id=? AND gpc.bimester=? LIMIT 1");
    $stmt->bind_param('iii', $schoolId, $teacherCourseId, $bimester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function agca_audit(mysqli $db, int $schoolId, int $yearId, int $teacherCourseId, int $teacherId, int $bimester, int $userId, string $action, array $details): void {
    if (agca_table($db, 'grade_period_audit_log')) {
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $db->prepare('INSERT INTO grade_period_audit_log(school_id,academic_year_id,teacher_course_id,teacher_id,bimester,user_id,action,details,ip_address) VALUES(?,?,?,?,?,?,?,?,?)');
        if ($stmt) {
            $stmt->bind_param('iiiiissss', $schoolId, $yearId, $teacherCourseId, $teacherId, $bimester, $userId, $action, $json, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
    if (agca_table($db, 'academic_year_audit')) {
        $json = json_encode($details + ['teacher_course_id'=>$teacherCourseId,'teacher_id'=>$teacherId,'bimester'=>$bimester], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $db->prepare('INSERT INTO academic_year_audit(school_id,academic_year_id,user_id,action,details,ip_address) VALUES(?,?,?,?,?,?)');
        if ($stmt) {
            $stmt->bind_param('iiisss', $schoolId, $yearId, $userId, $action, $json, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
}
function agca_notify_teacher(mysqli $db, int $schoolId, array $assignment, int $bimester, int $closureId, int $userId, bool $exceptional, string $reason): void {
    if (!agca_table($db, 'notification_events')) return;
    $teacherId = (int)$assignment['teacher_id'];
    $stmt = $db->prepare("SELECT id FROM users WHERE school_id=? AND teacher_id=? AND type=2" . (agca_column($db,'users','status') ? " AND (status='Activo' OR status IS NULL)" : '') . ' ORDER BY id LIMIT 1');
    if (!$stmt) return;
    $stmt->bind_param('ii', $schoolId, $teacherId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) return;
    $recipient = (int)$user['id'];
    $notificationType = 'Académica';
    $priority = $exceptional ? 'Alta' : 'Normal';
    $title = $exceptional ? 'Cierre administrativo excepcional de notas' : 'Notas cerradas por Administración';
    $message = $assignment['course_name'] . ' · ' . $assignment['grado'] . '° ' . $assignment['seccion'] . ' · ' . $bimester . '° Bimestre';
    if ($exceptional && $reason !== '') $message .= ' · Motivo: ' . $reason;
    $sourceType = 'grade_period_closed';
    $sourceId = $closureId;
    $event = $db->prepare('INSERT INTO notification_events(school_id,recipient_user_id,notification_type,priority,title,message,source_type,source_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
    if (!$event) return;
    $event->bind_param('iisssssii', $schoolId, $recipient, $notificationType, $priority, $title, $message, $sourceType, $sourceId, $userId);
    $event->execute();
    $event->close();
}
function agca_validate(mysqli $db, int $schoolId, array $assignment, int $bimester): array {
    $teacherCourseId = (int)$assignment['id'];
    $teacherId = (int)$assignment['teacher_id'];
    $yearId = (int)$assignment['academic_year_id'];
    $courseId = (int)$assignment['course_id'];
    $issues = [];

    if ((int)$assignment['is_active'] !== 1 || in_array((string)($assignment['year_status'] ?? ''), ['Cerrado','Archivado'], true)) {
        $issues[] = 'El año académico no está activo.';
    }

    $competencies = [];
    $stmt = $db->prepare('SELECT id,name,percentage FROM general_course_competencies WHERE course_id=? AND teacher_id=? AND academic_year_id=? AND is_active=1 ORDER BY id');
    $stmt->bind_param('iii', $courseId, $teacherId, $yearId);
    $stmt->execute();
    $res = $stmt->get_result();
    $percentageTotal = 0.0;
    while ($row = $res->fetch_assoc()) {
        $pct = (float)$row['percentage'];
        if ($pct <= 0) continue;
        $row['id'] = (int)$row['id'];
        $row['percentage'] = $pct;
        $competencies[$row['id']] = $row;
        $percentageTotal += $pct;
    }
    $stmt->close();
    if (!$competencies) $issues[] = 'No hay competencias activas con porcentaje mayor a 0%.';
    if (abs($percentageTotal - 100.0) > 0.01) $issues[] = 'Los porcentajes de las competencias suman ' . number_format($percentageTotal, 2) . '%. Deben sumar 100%.';

    $statusCondition = agca_column($db, 'evaluations', 'status') ? " AND (e.status IS NULL OR e.status<>'Anulada')" : '';
    $stmt = $db->prepare("SELECT e.id,e.title,e.type,e.created_at FROM evaluations e WHERE e.teacher_course_id=? AND e.teacher_id=? AND e.academic_year_id=? AND CAST(e.bimestre AS UNSIGNED)=?$statusCondition ORDER BY e.id");
    $stmt->bind_param('iiii', $teacherCourseId, $teacherId, $yearId, $bimester);
    $stmt->execute();
    $res = $stmt->get_result();
    $evaluations = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['competencies'] = [];
        $evaluations[$row['id']] = $row;
    }
    $stmt->close();
    if (!$evaluations) $issues[] = 'No hay evaluaciones activas registradas en el bimestre.';

    $compEvalCount = array_fill_keys(array_keys($competencies), 0);
    if ($evaluations) {
        $ids = implode(',', array_map('intval', array_keys($evaluations)));
        $q = $db->query("SELECT ec.evaluation_id,ec.competencia_id,gcc.name,gcc.percentage,gcc.is_active FROM evaluation_competencias ec LEFT JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id WHERE ec.evaluation_id IN ($ids)");
        while ($q && ($row = $q->fetch_assoc())) {
            $eid = (int)$row['evaluation_id'];
            $cid = (int)$row['competencia_id'];
            if (!isset($evaluations[$eid])) continue;
            $evaluations[$eid]['competencies'][] = ['id'=>$cid,'name'=>$row['name'] ?: 'Competencia no disponible','percentage'=>(float)($row['percentage'] ?? 0),'active'=>(int)($row['is_active'] ?? 0)];
            if (isset($compEvalCount[$cid])) $compEvalCount[$cid]++;
        }
    }
    foreach ($competencies as $cid => $comp) {
        if (($compEvalCount[$cid] ?? 0) < 1) $issues[] = 'La competencia “' . $comp['name'] . '” no tiene evaluación activa.';
    }
    foreach ($evaluations as $evaluation) {
        if (!$evaluation['competencies']) {
            $issues[] = 'La evaluación “' . $evaluation['title'] . '” no tiene competencia asociada.';
            continue;
        }
        foreach ($evaluation['competencies'] as $link) {
            if (!isset($competencies[$link['id']])) $issues[] = 'La evaluación “' . $evaluation['title'] . '” usa una competencia inactiva o con porcentaje 0%.';
        }
    }

    $level = $db->real_escape_string(mb_strtolower((string)$assignment['level'], 'UTF-8'));
    $grade = $db->real_escape_string((string)$assignment['grado']);
    $sectionCondition = agca_section_condition($db, (string)$assignment['seccion']);
    $students = [];
    $q = $db->query("SELECT s.id,s.id_no,s.name FROM student s WHERE s.school_id=$schoolId AND (s.status='Activo' OR s.status IS NULL) AND LOWER(s.nivel)='$level' AND s.grado='$grade' AND $sectionCondition ORDER BY s.name");
    while ($q && ($row = $q->fetch_assoc())) $students[(int)$row['id']] = ['id'=>(int)$row['id'],'id_no'=>$row['id_no'],'name'=>$row['name']];
    if (!$students) $issues[] = 'No hay estudiantes activos en el aula.';

    $expected = 0;
    $completed = 0;
    $missing = [];
    if ($evaluations && $students) {
        $evalIds = implode(',', array_map('intval', array_keys($evaluations)));
        $studentIds = implode(',', array_map('intval', array_keys($students)));
        $gradeMap = [];
        $q = $db->query("SELECT evaluation_id,student_id,competencia_id FROM evaluation_grades WHERE evaluation_id IN ($evalIds) AND student_id IN ($studentIds) AND grade IS NOT NULL AND TRIM(grade)<>''");
        while ($q && ($row = $q->fetch_assoc())) $gradeMap[(int)$row['evaluation_id']][(int)$row['competencia_id']][(int)$row['student_id']] = true;
        foreach ($evaluations as $evaluation) {
            foreach ($evaluation['competencies'] as $link) {
                $cid = (int)$link['id'];
                if (!isset($competencies[$cid])) continue;
                $missingCount = 0;
                foreach ($students as $studentId => $student) {
                    $expected++;
                    if (!empty($gradeMap[$evaluation['id']][$cid][$studentId])) $completed++;
                    else $missingCount++;
                }
                if ($missingCount > 0) {
                    $missing[] = ['evaluation'=>$evaluation['title'],'competency'=>$link['name'],'count'=>$missingCount];
                    $issues[] = 'La evaluación “' . $evaluation['title'] . '” tiene ' . $missingCount . ' estudiante(s) sin nota en “' . $link['name'] . '”.';
                }
            }
        }
    }
    $issues = array_values(array_unique($issues));
    $progress = $expected > 0 ? round(($completed / $expected) * 100, 1) : 0;
    $snapshot = [
        'generated_at'=>date('Y-m-d H:i:s'),
        'assignment'=>[
            'teacher_course_id'=>$teacherCourseId,'teacher_id'=>$teacherId,'teacher_name'=>$assignment['teacher_name'],
            'course_id'=>$courseId,'course_name'=>$assignment['course_name'],'level'=>$assignment['level'],
            'grado'=>$assignment['grado'],'seccion'=>$assignment['seccion'],'academic_year_id'=>$yearId,
            'year'=>$assignment['year'],'bimester'=>$bimester
        ],
        'competencies'=>array_values($competencies),
        'evaluations'=>array_values($evaluations),
        'students'=>array_values($students),
        'summary'=>[
            'percentage_total'=>round($percentageTotal,2),'competencies'=>count($competencies),'evaluations'=>count($evaluations),
            'students'=>count($students),'expected_grade_cells'=>$expected,'completed_grade_cells'=>$completed,'progress'=>$progress
        ]
    ];
    return ['ready'=>count($issues)===0,'issues'=>$issues,'missing'=>$missing,'summary'=>$snapshot['summary'],'snapshot'=>$snapshot];
}
function agca_close_one(mysqli $db, int $schoolId, int $yearId, int $teacherCourseId, int $bimester, int $userId, bool $force, string $reason): array {
    $assignment = agca_assignment($db, $schoolId, $yearId, $teacherCourseId);
    if (!$assignment) return ['ok'=>false,'code'=>'invalid','message'=>'La asignación no existe.'];
    $closure = agca_closure($db, $schoolId, $teacherCourseId, $bimester);
    if ($closure && ($closure['status'] ?? '') === 'Cerrado') return ['ok'=>false,'code'=>'closed','message'=>'La asignación ya está cerrada.'];
    $validation = agca_validate($db, $schoolId, $assignment, $bimester);
    if (!$validation['ready'] && !$force) {
        return ['ok'=>false,'code'=>'incomplete','message'=>'La asignación todavía tiene pendientes.','validation'=>$validation];
    }
    if (!$validation['ready'] && $force && mb_strlen($reason, 'UTF-8') < 5) {
        return ['ok'=>false,'code'=>'reason','message'=>'Indica un motivo de al menos 5 caracteres para el cierre excepcional.','validation'=>$validation];
    }
    $mode = $validation['ready'] ? 'Administrativo' : 'Administrativo excepcional';
    $snapshot = $validation['snapshot'];
    $snapshot['closure_meta'] = [
        'mode'=>$mode,'exceptional'=>!$validation['ready'],'reason'=>$reason,
        'closed_by_user_id'=>$userId,'closed_at'=>date('Y-m-d H:i:s')
    ];
    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $teacherId = (int)$assignment['teacher_id'];
    $stmt = $db->prepare("INSERT INTO grade_period_closures(school_id,academic_year_id,teacher_course_id,teacher_id,bimester,status,closed_by,closed_at,closure_snapshot,closure_version)
                          VALUES(?,?,?,?,?,'Cerrado',?,NOW(),?,1)
                          ON DUPLICATE KEY UPDATE academic_year_id=VALUES(academic_year_id),teacher_id=VALUES(teacher_id),status='Cerrado',closed_by=VALUES(closed_by),closed_at=NOW(),closure_snapshot=VALUES(closure_snapshot),closure_version=closure_version+1");
    $stmt->bind_param('iiiiiis', $schoolId, $yearId, $teacherCourseId, $teacherId, $bimester, $userId, $snapshotJson);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $stmt->close();
    $closure = agca_closure($db, $schoolId, $teacherCourseId, $bimester);
    $closureId = (int)($closure['id'] ?? 0);
    $details = [
        'mode'=>$mode,'exceptional'=>!$validation['ready'],'reason'=>$reason,
        'teacher_course_id'=>$teacherCourseId,'teacher_name'=>$assignment['teacher_name'],
        'course_name'=>$assignment['course_name'],'level'=>$assignment['level'],'grado'=>$assignment['grado'],'seccion'=>$assignment['seccion'],
        'validation_summary'=>$validation['summary'],'issues'=>$validation['issues']
    ];
    agca_audit($db, $schoolId, $yearId, $teacherCourseId, $teacherId, $bimester, $userId,
        $validation['ready'] ? 'period_closed_by_admin' : 'period_closed_exceptionally_by_admin', $details);
    agca_notify_teacher($db, $schoolId, $assignment, $bimester, $closureId, $userId, !$validation['ready'], $reason);
    return ['ok'=>true,'closure_id'=>$closureId,'mode'=>$mode,'assignment'=>$assignment,'validation'=>$validation];
}
function agca_mode_from_closure(array $closure): array {
    $mode = ((int)($closure['closed_by_type'] ?? 0) === 1) ? 'Administrativo' : 'Docente';
    $reason = '';
    $exceptional = false;
    $snapshot = json_decode((string)($closure['closure_snapshot'] ?? ''), true);
    if (is_array($snapshot) && !empty($snapshot['closure_meta']) && is_array($snapshot['closure_meta'])) {
        $meta = $snapshot['closure_meta'];
        if (!empty($meta['mode'])) $mode = (string)$meta['mode'];
        $reason = trim((string)($meta['reason'] ?? ''));
        $exceptional = !empty($meta['exceptional']);
    }
    return ['mode'=>$mode,'reason'=>$reason,'exceptional'=>$exceptional];
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
if ($schoolId <= 0 || $userId <= 0) agca_response(['status'=>0,'message'=>'No autorizado.'], 403);
$stmt = $conn->prepare('SELECT type,name FROM users WHERE id=? AND school_id=? LIMIT 1');
$stmt->bind_param('ii', $userId, $schoolId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user || (int)$user['type'] !== 1) agca_response(['status'=>0,'message'=>'Solo Administración puede realizar cierres administrativos.'], 403);
foreach (['grade_period_closures','grade_period_audit_log','teacher_courses','academic_courses','general_course_competencies','evaluations','evaluation_competencias','evaluation_grades','student'] as $table) {
    if (!agca_table($conn, $table)) agca_response(['status'=>0,'migration_required'=>true,'message'=>'Ejecuta sql/grade_closure_upgrade.sql antes de usar el cierre administrativo.','missing_table'=>$table], 409);
}
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'closure_info');
if ($_SERVER['REQUEST_METHOD'] === 'POST') agca_csrf();

try {
    if ($action === 'closure_info') {
        $yearId = (int)($_GET['academic_year_id'] ?? 0);
        $bimester = (int)($_GET['bimester'] ?? 0);
        if ($yearId <= 0 || $bimester < 1 || $bimester > 4) agca_response(['status'=>0,'message'=>'Año o bimestre inválido.']);
        $items = [];
        $stmt = $conn->prepare("SELECT gpc.*,COALESCE(u.name,'Usuario') closed_by_name,COALESCE(u.type,0) closed_by_type
                                FROM grade_period_closures gpc
                                LEFT JOIN users u ON u.id=gpc.closed_by AND u.school_id=gpc.school_id
                                WHERE gpc.school_id=? AND gpc.academic_year_id=? AND gpc.bimester=?");
        $stmt->bind_param('iii', $schoolId, $yearId, $bimester);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $meta = agca_mode_from_closure($row);
            $items[(string)(int)$row['teacher_course_id']] = [
                'closure_id'=>(int)$row['id'],'status'=>$row['status'],'closed_at'=>$row['closed_at'],
                'closed_by_name'=>$row['closed_by_name'],'closed_by_type'=>(int)$row['closed_by_type'],
                'mode'=>$meta['mode'],'reason'=>$meta['reason'],'exceptional'=>$meta['exceptional'],
                'closure_version'=>(int)$row['closure_version']
            ];
        }
        $stmt->close();
        agca_response(['status'=>1,'items'=>$items]);
    }

    if ($action === 'close_assignment') {
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $teacherCourseId = (int)($_POST['teacher_course_id'] ?? 0);
        $bimester = (int)($_POST['bimester'] ?? 0);
        $force = (int)($_POST['force'] ?? 0) === 1;
        $reason = trim((string)($_POST['reason'] ?? ''));
        $year = agca_year($conn, $schoolId, $yearId);
        if (!$year || ($year['status'] ?? '') !== 'Activo' || $teacherCourseId <= 0 || $bimester < 1 || $bimester > 4) {
            agca_response(['status'=>0,'message'=>'El año, asignación o bimestre no es válido.']);
        }
        $conn->begin_transaction();
        try {
            $result = agca_close_one($conn, $schoolId, $yearId, $teacherCourseId, $bimester, $userId, $force, $reason);
            if (!$result['ok']) {
                $conn->rollback();
                if (($result['code'] ?? '') === 'incomplete') {
                    agca_response(['status'=>2,'needs_exceptional'=>true,'message'=>$result['message'],'validation'=>[
                        'issues'=>$result['validation']['issues'],'summary'=>$result['validation']['summary']
                    ]]);
                }
                agca_response(['status'=>0,'message'=>$result['message'],'code'=>$result['code'] ?? 'error']);
            }
            $conn->commit();
            agca_response(['status'=>1,'message'=>$result['mode']==='Administrativo excepcional'?'Cierre administrativo excepcional registrado.':'Asignación cerrada por Administración.','mode'=>$result['mode'],'closure_id'=>$result['closure_id']]);
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    if ($action === 'close_bulk') {
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $bimester = (int)($_POST['bimester'] ?? 0);
        $raw = json_decode((string)($_POST['teacher_course_ids'] ?? '[]'), true);
        if (!is_array($raw)) $raw = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
        if (count($ids) > 500) agca_response(['status'=>0,'message'=>'Solo se pueden procesar hasta 500 asignaciones por operación.']);
        $year = agca_year($conn, $schoolId, $yearId);
        if (!$year || ($year['status'] ?? '') !== 'Activo' || $bimester < 1 || $bimester > 4 || !$ids) {
            agca_response(['status'=>0,'message'=>'Selecciona asignaciones válidas de un bimestre activo.']);
        }
        $closed = [];
        $skipped = [];
        $conn->begin_transaction();
        try {
            foreach ($ids as $id) {
                $result = agca_close_one($conn, $schoolId, $yearId, $id, $bimester, $userId, false, '');
                if ($result['ok']) {
                    $closed[] = ['teacher_course_id'=>$id,'course_name'=>$result['assignment']['course_name'],'teacher_name'=>$result['assignment']['teacher_name']];
                } else {
                    $skipped[] = ['teacher_course_id'=>$id,'reason'=>$result['message'],'code'=>$result['code'] ?? 'error'];
                }
            }
            agca_audit($conn, $schoolId, $yearId, 0, 0, $bimester, $userId, 'bulk_ready_periods_closed_by_admin', [
                'requested'=>count($ids),'closed'=>count($closed),'skipped'=>count($skipped),'closed_items'=>$closed,'skipped_items'=>$skipped
            ]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        agca_response([
            'status'=>1,
            'message'=>count($closed) . ' asignación(es) cerrada(s). ' . count($skipped) . ' omitida(s).',
            'closed_count'=>count($closed),'skipped_count'=>count($skipped),'closed'=>$closed,'skipped'=>$skipped
        ]);
    }

    agca_response(['status'=>0,'message'=>'Acción no válida.'], 404);
} catch (Throwable $e) {
    error_log('[admin_grade_closure] ' . $e->getMessage());
    agca_response(['status'=>0,'message'=>'No se pudo completar el cierre administrativo.','detail'=>$e->getMessage()], 500);
}
