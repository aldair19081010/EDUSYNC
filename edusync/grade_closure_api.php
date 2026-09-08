<?php
ob_start();
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
header('Content-Type: application/json; charset=utf-8');

function gc_response(array $data, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function gc_table(mysqli $db, string $name): bool {
    $safe = $db->real_escape_string($name);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}
function gc_column(mysqli $db, string $table, string $column): bool {
    $t = str_replace('`', '', $table);
    $c = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $q && $q->num_rows > 0;
}
function gc_csrf(): void {
    $posted = (string)($_POST['csrf_token'] ?? '');
    $session = (string)($_SESSION['csrf_token'] ?? '');
    if ($posted === '' || $session === '' || !hash_equals($session, $posted)) {
        gc_response(['status'=>0,'message'=>'La sesión de seguridad venció. Recarga la página.'], 403);
    }
}
function gc_audit(mysqli $db, int $schoolId, int $yearId, int $teacherCourseId, int $teacherId, int $bimester, int $userId, string $action, array $details = []): void {
    if (!gc_table($db, 'grade_period_audit_log')) return;
    $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $db->prepare('INSERT INTO grade_period_audit_log(school_id,academic_year_id,teacher_course_id,teacher_id,bimester,user_id,action,details,ip_address) VALUES(?,?,?,?,?,?,?,?,?)');
    if (!$stmt) return;
    $stmt->bind_param('iiiiissss', $schoolId, $yearId, $teacherCourseId, $teacherId, $bimester, $userId, $action, $json, $ip);
    $stmt->execute();
    $stmt->close();
}
function gc_assignment(mysqli $db, int $schoolId, int $teacherCourseId): ?array {
    $stmt = $db->prepare("SELECT tc.id,tc.school_id,tc.teacher_id,tc.course_id,tc.academic_year_id,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,ac.name course_name,ac.level,ay.year,ay.is_active" . (gc_column($db,'academic_year','status') ? ',ay.status year_status' : ",'' year_status") . ",t.name teacher_name FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id LEFT JOIN teacher t ON t.id=tc.teacher_id WHERE tc.id=? AND tc.school_id=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('ii', $teacherCourseId, $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function gc_section_condition(mysqli $db, string $section): string {
    $section = trim($section);
    if (in_array(mb_strtoupper($section, 'UTF-8'), ['U','ÚNICA','UNICA'], true)) {
        return "COALESCE(NULLIF(TRIM(s.seccion),''),'U') IN ('U','u','Única','Unica','única','unica')";
    }
    return "s.seccion='" . $db->real_escape_string($section) . "'";
}
function gc_period_row(mysqli $db, int $schoolId, int $teacherCourseId, int $bimester): ?array {
    if (!gc_table($db, 'grade_period_closures')) return null;
    $stmt = $db->prepare('SELECT gpc.*,u.name closed_by_name,ru.name reopened_by_name FROM grade_period_closures gpc LEFT JOIN users u ON u.id=gpc.closed_by AND u.school_id=gpc.school_id LEFT JOIN users ru ON ru.id=gpc.reopened_by AND ru.school_id=gpc.school_id WHERE gpc.school_id=? AND gpc.teacher_course_id=? AND gpc.bimester=? LIMIT 1');
    $stmt->bind_param('iii', $schoolId, $teacherCourseId, $bimester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function gc_pending_reopen(mysqli $db, int $schoolId, int $closureId): ?array {
    if (!gc_table($db, 'grade_reopen_requests') || $closureId <= 0) return null;
    $stmt = $db->prepare("SELECT grr.*,u.name requested_by_name,rv.name reviewed_by_name FROM grade_reopen_requests grr LEFT JOIN users u ON u.id=grr.requested_by AND u.school_id=grr.school_id LEFT JOIN users rv ON rv.id=grr.reviewed_by AND rv.school_id=grr.school_id WHERE grr.school_id=? AND grr.closure_id=? AND grr.status='Pendiente' ORDER BY grr.id DESC LIMIT 1");
    $stmt->bind_param('ii', $schoolId, $closureId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function gc_global_locked(mysqli $db, int $schoolId, int $yearId, int $bimester): bool {
    if (!gc_table($db, 'bimester_locks')) return false;
    $stmt = $db->prepare('SELECT is_locked FROM bimester_locks WHERE school_id=? AND academic_year_id=? AND bimester=? LIMIT 1');
    $stmt->bind_param('iii', $schoolId, $yearId, $bimester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && (int)$row['is_locked'] === 1;
}
function gc_validate(mysqli $db, int $schoolId, array $assignment, int $bimester): array {
    $teacherCourseId = (int)$assignment['id'];
    $teacherId = (int)$assignment['teacher_id'];
    $yearId = (int)$assignment['academic_year_id'];
    $courseId = (int)$assignment['course_id'];
    $issues = [];
    $warnings = [];

    if ((int)$assignment['is_active'] !== 1 || in_array((string)($assignment['year_status'] ?? ''), ['Cerrado','Archivado'], true)) {
        $issues[] = ['code'=>'year_closed','message'=>'El año académico no está activo o ya fue cerrado.'];
    }
    if (gc_global_locked($db, $schoolId, $yearId, $bimester)) {
        $issues[] = ['code'=>'global_lock','message'=>'El bimestre fue bloqueado institucionalmente por Administración.'];
    }

    $competencies = [];
    $compStmt = $db->prepare('SELECT id,name,percentage FROM general_course_competencies WHERE course_id=? AND teacher_id=? AND academic_year_id=? AND is_active=1 ORDER BY id');
    $compStmt->bind_param('iii', $courseId, $teacherId, $yearId);
    $compStmt->execute();
    $compRes = $compStmt->get_result();
    $percentageTotal = 0.0;
    while ($row = $compRes->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['percentage'] = (float)$row['percentage'];
        if ($row['percentage'] > 0) {
            $competencies[$row['id']] = $row;
            $percentageTotal += $row['percentage'];
        }
    }
    $compStmt->close();
    if (!$competencies) {
        $issues[] = ['code'=>'no_competencies','message'=>'No hay competencias activas con porcentaje mayor a 0%.'];
    }
    if (abs($percentageTotal - 100.0) > 0.01) {
        $issues[] = ['code'=>'percentage_total','message'=>'Los porcentajes de las competencias activas suman ' . number_format($percentageTotal, 2) . '%. Deben sumar 100%.'];
    }

    $statusCondition = gc_column($db, 'evaluations', 'status') ? " AND (e.status IS NULL OR e.status<>'Anulada')" : '';
    $evalStmt = $db->prepare("SELECT e.id,e.title,e.type,e.created_at FROM evaluations e WHERE e.teacher_course_id=? AND e.teacher_id=? AND e.academic_year_id=? AND e.bimestre=?$statusCondition ORDER BY e.id");
    $bimText = (string)$bimester;
    $evalStmt->bind_param('iiis', $teacherCourseId, $teacherId, $yearId, $bimText);
    $evalStmt->execute();
    $evalRes = $evalStmt->get_result();
    $evaluations = [];
    while ($row = $evalRes->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['competencies'] = [];
        $evaluations[$row['id']] = $row;
    }
    $evalStmt->close();
    if (!$evaluations) {
        $issues[] = ['code'=>'no_evaluations','message'=>'No hay evaluaciones activas registradas para este bimestre.'];
    }

    $compEvaluationCount = array_fill_keys(array_keys($competencies), 0);
    if ($evaluations) {
        $evalIds = implode(',', array_map('intval', array_keys($evaluations)));
        $links = $db->query("SELECT ec.evaluation_id,ec.competencia_id,gcc.name,gcc.percentage,gcc.is_active FROM evaluation_competencias ec LEFT JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id WHERE ec.evaluation_id IN ($evalIds) ORDER BY ec.evaluation_id,ec.id");
        while ($links && ($row = $links->fetch_assoc())) {
            $eid = (int)$row['evaluation_id'];
            $cid = (int)$row['competencia_id'];
            if (!isset($evaluations[$eid])) continue;
            $evaluations[$eid]['competencies'][] = ['id'=>$cid,'name'=>$row['name'] ?: 'Competencia no disponible','percentage'=>(float)($row['percentage'] ?? 0),'active'=>(int)($row['is_active'] ?? 0)];
            if (isset($compEvaluationCount[$cid])) $compEvaluationCount[$cid]++;
        }
    }
    foreach ($competencies as $cid => $comp) {
        if (($compEvaluationCount[$cid] ?? 0) < 1) {
            $issues[] = ['code'=>'competency_without_evaluation','competency_id'=>$cid,'message'=>'La competencia “' . $comp['name'] . '” (' . number_format($comp['percentage'], 2) . '%) no tiene ninguna evaluación activa en el bimestre.'];
        }
    }
    foreach ($evaluations as $evaluation) {
        if (!$evaluation['competencies']) {
            $issues[] = ['code'=>'evaluation_without_competency','evaluation_id'=>$evaluation['id'],'message'=>'La evaluación “' . $evaluation['title'] . '” no tiene una competencia asociada.'];
            continue;
        }
        foreach ($evaluation['competencies'] as $link) {
            if (!isset($competencies[$link['id']])) {
                $issues[] = ['code'=>'evaluation_invalid_competency','evaluation_id'=>$evaluation['id'],'competency_id'=>$link['id'],'message'=>'La evaluación “' . $evaluation['title'] . '” usa una competencia inactiva o con porcentaje 0%.'];
            }
        }
    }

    $levelEsc = $db->real_escape_string(mb_strtolower((string)$assignment['level'], 'UTF-8'));
    $gradeEsc = $db->real_escape_string((string)$assignment['grado']);
    $sectionCondition = gc_section_condition($db, (string)$assignment['seccion']);
    $students = [];
    $studentResult = $db->query("SELECT s.id,s.id_no,s.name FROM student s WHERE s.school_id=$schoolId AND (s.status='Activo' OR s.status IS NULL) AND LOWER(s.nivel)='$levelEsc' AND s.grado='$gradeEsc' AND $sectionCondition ORDER BY s.name");
    while ($studentResult && ($row = $studentResult->fetch_assoc())) {
        $students[(int)$row['id']] = ['id'=>(int)$row['id'],'id_no'=>$row['id_no'],'name'=>$row['name']];
    }
    if (!$students) {
        $issues[] = ['code'=>'no_students','message'=>'No hay estudiantes activos en el aula seleccionada.'];
    }

    $expectedCells = 0;
    $completedCells = 0;
    $missingByEvaluation = [];
    if ($evaluations && $students) {
        $evalIds = implode(',', array_map('intval', array_keys($evaluations)));
        $studentIds = implode(',', array_map('intval', array_keys($students)));
        $gradeMap = [];
        $gradeResult = $db->query("SELECT evaluation_id,student_id,competencia_id,grade FROM evaluation_grades WHERE evaluation_id IN ($evalIds) AND student_id IN ($studentIds) AND grade IS NOT NULL AND TRIM(grade)<>''");
        while ($gradeResult && ($row = $gradeResult->fetch_assoc())) {
            $gradeMap[(int)$row['evaluation_id']][(int)$row['competencia_id']][(int)$row['student_id']] = (string)$row['grade'];
        }
        foreach ($evaluations as $evaluation) {
            foreach ($evaluation['competencies'] as $link) {
                if (!isset($competencies[$link['id']])) continue;
                $missing = [];
                foreach ($students as $studentId => $student) {
                    $expectedCells++;
                    if (isset($gradeMap[$evaluation['id']][$link['id']][$studentId])) {
                        $completedCells++;
                    } else {
                        $missing[] = $student['name'];
                    }
                }
                if ($missing) {
                    $missingByEvaluation[] = [
                        'evaluation_id'=>$evaluation['id'],
                        'evaluation'=>$evaluation['title'],
                        'competency_id'=>$link['id'],
                        'competency'=>$link['name'],
                        'missing_count'=>count($missing),
                        'missing_students'=>array_slice($missing, 0, 10)
                    ];
                    $issues[] = ['code'=>'missing_grades','evaluation_id'=>$evaluation['id'],'competency_id'=>$link['id'],'missing_count'=>count($missing),'message'=>'La evaluación “' . $evaluation['title'] . '” tiene ' . count($missing) . ' estudiante(s) sin nota en “' . $link['name'] . '”.'];
                }
            }
        }
    }

    $progress = $expectedCells > 0 ? round(($completedCells / $expectedCells) * 100, 1) : 0;
    $snapshot = [
        'generated_at'=>date('Y-m-d H:i:s'),
        'assignment'=>[
            'teacher_course_id'=>$teacherCourseId,'teacher_id'=>$teacherId,'teacher_name'=>$assignment['teacher_name'],
            'course_id'=>$courseId,'course_name'=>$assignment['course_name'],'level'=>$assignment['level'],
            'grado'=>$assignment['grado'],'seccion'=>$assignment['seccion'],'academic_year_id'=>$yearId,'year'=>$assignment['year'],'bimester'=>$bimester
        ],
        'competencies'=>array_values($competencies),
        'evaluations'=>array_values($evaluations),
        'students'=>array_values($students),
        'summary'=>[
            'percentage_total'=>round($percentageTotal,2),'competencies'=>count($competencies),'evaluations'=>count($evaluations),
            'students'=>count($students),'expected_grade_cells'=>$expectedCells,'completed_grade_cells'=>$completedCells,'progress'=>$progress
        ]
    ];

    return [
        'ready'=>count($issues)===0,
        'issues'=>$issues,
        'warnings'=>$warnings,
        'missing'=>$missingByEvaluation,
        'summary'=>$snapshot['summary'],
        'snapshot'=>$snapshot
    ];
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
$sessionTeacherId = (int)($_SESSION['login_teacher_id'] ?? 0);
$loginType = (int)($_SESSION['login_type'] ?? 0);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');
if ($schoolId <= 0 || $userId <= 0) gc_response(['status'=>0,'message'=>'No autorizado.'], 403);

$roleStmt = $conn->prepare('SELECT type,teacher_id FROM users WHERE id=? AND school_id=? LIMIT 1');
$roleStmt->bind_param('ii', $userId, $schoolId);
$roleStmt->execute();
$role = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();
if (!$role) gc_response(['status'=>0,'message'=>'El usuario no pertenece al colegio activo.'], 403);
$loginType = (int)$role['type'];
$sessionTeacherId = (int)($role['teacher_id'] ?? 0);
$isAdmin = $loginType === 1;
$isTeacher = $loginType === 2 && $sessionTeacherId > 0;

$requiredTables = ['grade_period_closures','grade_reopen_requests','grade_period_audit_log'];
foreach ($requiredTables as $table) {
    if (!gc_table($conn, $table)) gc_response(['status'=>0,'migration_required'=>true,'message'=>'Ejecuta manualmente sql/grade_closure_upgrade.sql para habilitar el cierre de notas.'], 409);
}

if (in_array($action, ['close','request_reopen','review_reopen'], true)) gc_csrf();

if ($action === 'status' || $action === 'close' || $action === 'request_reopen') {
    if (!$isTeacher) gc_response(['status'=>0,'message'=>'Solo los docentes pueden cerrar o solicitar reapertura de sus notas.'], 403);
    $teacherCourseId = (int)($_REQUEST['teacher_course_id'] ?? 0);
    $bimester = (int)($_REQUEST['bimestre'] ?? 0);
    if ($teacherCourseId <= 0 || $bimester < 1 || $bimester > 4) gc_response(['status'=>0,'message'=>'Selecciona un curso y un bimestre.']);
    $assignment = gc_assignment($conn, $schoolId, $teacherCourseId);
    if (!$assignment || (int)$assignment['teacher_id'] !== $sessionTeacherId) gc_response(['status'=>0,'message'=>'La asignación no existe o no te pertenece.'], 403);
    $closure = gc_period_row($conn, $schoolId, $teacherCourseId, $bimester);

    if ($action === 'status') {
        $validation = gc_validate($conn, $schoolId, $assignment, $bimester);
        $pending = $closure ? gc_pending_reopen($conn, $schoolId, (int)$closure['id']) : null;
        $state = ($closure && $closure['status'] === 'Cerrado') ? 'Cerrado' : ($validation['ready'] ? 'Listo' : 'Incompleto');
        gc_response([
            'status'=>1,'state'=>$state,'closed'=>$state==='Cerrado','ready'=>$validation['ready'],
            'assignment'=>[
                'teacher_course_id'=>(int)$assignment['id'],'course_name'=>$assignment['course_name'],'level'=>$assignment['level'],
                'grado'=>$assignment['grado'],'seccion'=>$assignment['seccion'],'year'=>$assignment['year'],'bimestre'=>$bimester
            ],
            'closure'=>$closure ? [
                'id'=>(int)$closure['id'],'status'=>$closure['status'],'closed_at'=>$closure['closed_at'],'closed_by_name'=>$closure['closed_by_name'],
                'closure_version'=>(int)$closure['closure_version'],'reopened_at'=>$closure['reopened_at'],'reopened_by_name'=>$closure['reopened_by_name']
            ] : null,
            'pending_reopen'=>$pending ? ['id'=>(int)$pending['id'],'reason'=>$pending['reason'],'created_at'=>$pending['created_at']] : null,
            'validation'=>['issues'=>$validation['issues'],'warnings'=>$validation['warnings'],'missing'=>$validation['missing'],'summary'=>$validation['summary']]
        ]);
    }

    if ($action === 'close') {
        if ($closure && $closure['status'] === 'Cerrado') gc_response(['status'=>0,'message'=>'Este bimestre ya está cerrado.']);
        $validation = gc_validate($conn, $schoolId, $assignment, $bimester);
        if (!$validation['ready']) gc_response(['status'=>0,'validation_failed'=>true,'message'=>'No se puede cerrar el bimestre porque aún hay información pendiente.','validation'=>['issues'=>$validation['issues'],'summary'=>$validation['summary']]]);
        $snapshotJson = json_encode($validation['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $yearId = (int)$assignment['academic_year_id'];
        $teacherId = (int)$assignment['teacher_id'];
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO grade_period_closures(school_id,academic_year_id,teacher_course_id,teacher_id,bimester,status,closed_by,closed_at,closure_snapshot,closure_version) VALUES(?,?,?,?,?,'Cerrado',?,NOW(),?,1) ON DUPLICATE KEY UPDATE academic_year_id=VALUES(academic_year_id),teacher_id=VALUES(teacher_id),status='Cerrado',closed_by=VALUES(closed_by),closed_at=NOW(),closure_snapshot=VALUES(closure_snapshot),closure_version=closure_version+1");
            $stmt->bind_param('iiiiiis', $schoolId, $yearId, $teacherCourseId, $teacherId, $bimester, $userId, $snapshotJson);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            $closure = gc_period_row($conn, $schoolId, $teacherCourseId, $bimester);
            gc_audit($conn,$schoolId,$yearId,$teacherCourseId,$teacherId,$bimester,$userId,'period_closed',['closure_id'=>(int)$closure['id'],'snapshot'=>$validation['snapshot']]);
            if (gc_table($conn,'notification_events')) {
                $recipientRole='Administrador';$notificationType='Académica';$priority='Normal';
                $title='Notas cerradas: ' . $assignment['course_name'] . ' · ' . $assignment['grado'] . ' ' . $assignment['seccion'];
                $message=($assignment['teacher_name'] ?: 'Docente') . ' cerró el ' . $bimester . '° Bimestre · ' . $assignment['year'];
                $sourceType='grade_period_closed';$sourceId=(int)$closure['id'];
                $event=$conn->prepare('INSERT INTO notification_events(school_id,recipient_role,notification_type,priority,title,message,source_type,source_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
                $event->bind_param('issssssii',$schoolId,$recipientRole,$notificationType,$priority,$title,$message,$sourceType,$sourceId,$userId);
                if(!$event->execute()) throw new RuntimeException($event->error);
                $event->close();
            }
            $conn->commit();
            gc_response(['status'=>1,'message'=>'Notas cerradas correctamente. El bimestre quedó en modo solo lectura.','closure_id'=>(int)$closure['id']]);
        } catch (Throwable $e) {
            $conn->rollback();
            gc_response(['status'=>0,'message'=>'No se pudo cerrar el bimestre.','detail'=>$e->getMessage()],500);
        }
    }

    if ($action === 'request_reopen') {
        if (!$closure || $closure['status'] !== 'Cerrado') gc_response(['status'=>0,'message'=>'Solo puedes solicitar reapertura de un bimestre cerrado.']);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (mb_strlen($reason, 'UTF-8') < 5) gc_response(['status'=>0,'message'=>'Explica el motivo de la reapertura con al menos 5 caracteres.']);
        $pending = gc_pending_reopen($conn, $schoolId, (int)$closure['id']);
        if ($pending) gc_response(['status'=>0,'message'=>'Ya existe una solicitud de reapertura pendiente.']);
        $stmt = $conn->prepare("INSERT INTO grade_reopen_requests(closure_id,school_id,academic_year_id,teacher_course_id,teacher_id,bimester,requested_by,reason,status) VALUES(?,?,?,?,?,?,?,?,'Pendiente')");
        $closureId=(int)$closure['id'];$yearId=(int)$assignment['academic_year_id'];$teacherId=(int)$assignment['teacher_id'];
        $stmt->bind_param('iiiiiiis',$closureId,$schoolId,$yearId,$teacherCourseId,$teacherId,$bimester,$userId,$reason);
        if(!$stmt->execute()) gc_response(['status'=>0,'message'=>'No se pudo registrar la solicitud.','detail'=>$stmt->error],500);
        $requestId=(int)$stmt->insert_id;$stmt->close();
        gc_audit($conn,$schoolId,$yearId,$teacherCourseId,$teacherId,$bimester,$userId,'reopen_requested',['request_id'=>$requestId,'reason'=>$reason]);
        gc_response(['status'=>1,'message'=>'Solicitud de reapertura enviada a Administración.','request_id'=>$requestId]);
    }
}

if ($action === 'review_reopen') {
    if (!$isAdmin) gc_response(['status'=>0,'message'=>'Solo Administración puede resolver reaperturas.'],403);
    $requestId=(int)($_POST['id']??0);$decision=trim((string)($_POST['decision']??''));$notes=trim((string)($_POST['notes']??''));
    if($requestId<=0||!in_array($decision,['Aprobada','Rechazada'],true))gc_response(['status'=>0,'message'=>'La decisión no es válida.']);
    if($decision==='Rechazada'&&mb_strlen($notes,'UTF-8')<3)gc_response(['status'=>0,'message'=>'Indica el motivo del rechazo.']);
    $conn->begin_transaction();
    try{
        $stmt=$conn->prepare("SELECT grr.*,gpc.status closure_status,tc.course_id,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,ac.name course_name,ac.level,t.name teacher_name FROM grade_reopen_requests grr INNER JOIN grade_period_closures gpc ON gpc.id=grr.closure_id AND gpc.school_id=grr.school_id INNER JOIN teacher_courses tc ON tc.id=grr.teacher_course_id AND tc.school_id=grr.school_id INNER JOIN academic_courses ac ON ac.id=tc.course_id LEFT JOIN teacher t ON t.id=grr.teacher_id WHERE grr.id=? AND grr.school_id=? AND grr.status='Pendiente' LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ii',$requestId,$schoolId);$stmt->execute();$request=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$request)throw new RuntimeException('La solicitud ya fue revisada o no existe.');
        if($decision==='Aprobada'){
            $update=$conn->prepare("UPDATE grade_period_closures SET status='Abierto',reopened_by=?,reopened_at=NOW(),reopen_reason=? WHERE id=? AND school_id=? AND status='Cerrado'");
            $reason=$request['reason'];$closureId=(int)$request['closure_id'];$update->bind_param('isii',$userId,$reason,$closureId,$schoolId);if(!$update->execute())throw new RuntimeException($update->error);if($update->affected_rows<1)throw new RuntimeException('El periodo ya no está cerrado.');$update->close();
        }
        $update=$conn->prepare('UPDATE grade_reopen_requests SET status=?,reviewed_by=?,reviewed_at=NOW(),review_notes=? WHERE id=? AND school_id=?');
        $update->bind_param('sisii',$decision,$userId,$notes,$requestId,$schoolId);if(!$update->execute())throw new RuntimeException($update->error);$update->close();
        gc_audit($conn,$schoolId,(int)$request['academic_year_id'],(int)$request['teacher_course_id'],(int)$request['teacher_id'],(int)$request['bimester'],$userId,$decision==='Aprobada'?'reopen_approved':'reopen_rejected',['request_id'=>$requestId,'reason'=>$request['reason'],'review_notes'=>$notes]);
        if(gc_table($conn,'notification_events')){
            $recipient=(int)$request['requested_by'];$notificationType='Académica';$priority=$decision==='Aprobada'?'Normal':'Alta';
            $title=$decision==='Aprobada'?'Reapertura de notas aprobada':'Reapertura de notas rechazada';
            $message=$request['course_name'].' · '.$request['grado'].' '.$request['seccion'].' · '.$request['bimester'].'° Bimestre'.($notes!==''?' · '.$notes:'');
            $sourceType='grade_reopen_result';$sourceId=$requestId;
            $event=$conn->prepare('INSERT INTO notification_events(school_id,recipient_user_id,notification_type,priority,title,message,source_type,source_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
            $event->bind_param('iisssssii',$schoolId,$recipient,$notificationType,$priority,$title,$message,$sourceType,$sourceId,$userId);if(!$event->execute())throw new RuntimeException($event->error);$event->close();
        }
        $conn->commit();
        gc_response(['status'=>1,'message'=>$decision==='Aprobada'?'Reapertura autorizada. El docente ya puede editar nuevamente.':'Solicitud de reapertura rechazada.','decision'=>$decision]);
    }catch(Throwable $e){$conn->rollback();gc_response(['status'=>0,'message'=>$e->getMessage()]);}
}

if ($action === 'request_detail') {
    $requestId=(int)($_GET['id']??0);if($requestId<=0)gc_response(['status'=>0,'message'=>'Solicitud inválida.']);
    $sql="SELECT grr.*,tc.grado,COALESCE(NULLIF(tc.seccion,''),'U') seccion,ac.name course_name,ac.level,ay.year,t.name teacher_name,u.name requested_by_name,rv.name reviewed_by_name FROM grade_reopen_requests grr INNER JOIN teacher_courses tc ON tc.id=grr.teacher_course_id AND tc.school_id=grr.school_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=grr.academic_year_id LEFT JOIN teacher t ON t.id=grr.teacher_id LEFT JOIN users u ON u.id=grr.requested_by AND u.school_id=grr.school_id LEFT JOIN users rv ON rv.id=grr.reviewed_by AND rv.school_id=grr.school_id WHERE grr.id=? AND grr.school_id=? LIMIT 1";
    $stmt=$conn->prepare($sql);$stmt->bind_param('ii',$requestId,$schoolId);$stmt->execute();$request=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$request)gc_response(['status'=>0,'message'=>'La solicitud no existe.'],404);
    if(!$isAdmin && !($isTeacher && (int)$request['teacher_id']===$sessionTeacherId))gc_response(['status'=>0,'message'=>'No tienes permiso para ver esta solicitud.'],403);
    gc_response(['status'=>1,'request'=>$request]);
}

gc_response(['status'=>0,'message'=>'Acción no válida.'],404);
