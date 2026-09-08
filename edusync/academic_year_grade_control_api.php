<?php
ob_start();
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
header('Content-Type: application/json; charset=utf-8');

function agc_response(array $data, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function agc_table(mysqli $db, string $name): bool {
    $safe = $db->real_escape_string($name);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}
function agc_col(mysqli $db, string $table, string $column): bool {
    $safeTable = str_replace('`', '', $table);
    $safeColumn = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return $q && $q->num_rows > 0;
}
function agc_csrf(): void {
    $sent = (string)($_POST['csrf_token'] ?? '');
    $saved = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $saved === '' || !hash_equals($saved, $sent)) {
        agc_response(['status'=>0,'message'=>'La sesión de seguridad venció. Recarga la página.'], 403);
    }
}
function agc_norm(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return preg_replace('/[°º\s]+/u', '', $value) ?? $value;
}
function agc_section(string $value): string {
    $value = agc_norm($value);
    return in_array($value, ['', 'u', 'unica', 'única'], true) ? 'u' : $value;
}
function agc_student_key(string $level, string $grade, string $section): string {
    return agc_norm($level) . '|' . agc_norm($grade) . '|' . agc_section($section);
}
function agc_audit(mysqli $db, int $schoolId, int $yearId, int $userId, string $action, array $details = []): void {
    if (!agc_table($db, 'academic_year_audit')) return;
    $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $db->prepare('INSERT INTO academic_year_audit(school_id,academic_year_id,user_id,action,details,ip_address) VALUES(?,?,?,?,?,?)');
    if (!$stmt) return;
    $stmt->bind_param('iiisss', $schoolId, $yearId, $userId, $action, $json, $ip);
    $stmt->execute();
    $stmt->close();
}
function agc_year(mysqli $db, int $schoolId, int $yearId): ?array {
    $stmt = $db->prepare('SELECT * FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
    $stmt->bind_param('ii', $yearId, $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function agc_required_tables(mysqli $db): array {
    $required = [
        'grade_period_closures','grade_reopen_requests','teacher_courses','academic_courses','teacher',
        'general_course_competencies','evaluations','evaluation_competencias','evaluation_grades','student',
        'bimester_locks','academic_periods','academic_year_audit'
    ];
    $missing = [];
    foreach ($required as $table) if (!agc_table($db, $table)) $missing[] = $table;
    return $missing;
}
function agc_matrix(mysqli $db, int $schoolId, int $yearId): array {
    $year = agc_year($db, $schoolId, $yearId);
    if (!$year) throw new RuntimeException('El año académico no existe.');

    $assignments = [];
    $stmt = $db->prepare("SELECT tc.id,tc.teacher_id,tc.course_id,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,
                                ac.name course_name,ac.level,COALESCE(t.name,'Docente no disponible') teacher_name
                         FROM teacher_courses tc
                         INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
                         LEFT JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
                         WHERE tc.school_id=? AND tc.academic_year_id=?
                           AND (ac.is_active=1 OR ac.is_active IS NULL)
                         ORDER BY ac.level,tc.grado,tc.seccion,ac.name,t.name");
    $stmt->bind_param('ii', $schoolId, $yearId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['teacher_id'] = (int)$row['teacher_id'];
        $row['course_id'] = (int)$row['course_id'];
        $assignments[$row['id']] = $row;
    }
    $stmt->close();

    $competencies = [];
    $stmt = $db->prepare("SELECT id,course_id,teacher_id,name,percentage
                          FROM general_course_competencies
                          WHERE academic_year_id=? AND is_active=1");
    $stmt->bind_param('i', $yearId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $pct = (float)$row['percentage'];
        if ($pct <= 0) continue;
        $key = (int)$row['course_id'] . ':' . (int)$row['teacher_id'];
        $row['id'] = (int)$row['id'];
        $row['percentage'] = $pct;
        $competencies[$key][$row['id']] = $row;
    }
    $stmt->close();

    $evalStatus = agc_col($db, 'evaluations', 'status') ? " AND (e.status IS NULL OR e.status<>'Anulada')" : '';
    $evaluations = [];
    $evaluationIds = [];
    $stmt = $db->prepare("SELECT e.id,e.teacher_course_id,CAST(e.bimestre AS UNSIGNED) bimester,e.title
                          FROM evaluations e
                          INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
                          WHERE tc.school_id=? AND e.academic_year_id=?$evalStatus");
    $stmt->bind_param('ii', $schoolId, $yearId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $b = (int)$row['bimester'];
        if ($b < 1 || $b > 4) continue;
        $id = (int)$row['id'];
        $tc = (int)$row['teacher_course_id'];
        $row['id'] = $id;
        $row['links'] = [];
        $evaluations[$tc][$b][$id] = $row;
        $evaluationIds[] = $id;
    }
    $stmt->close();

    $links = [];
    if ($evaluationIds) {
        $idList = implode(',', array_map('intval', $evaluationIds));
        $q = $db->query("SELECT evaluation_id,competencia_id FROM evaluation_competencias WHERE evaluation_id IN ($idList)");
        while ($q && ($row = $q->fetch_assoc())) {
            $links[(int)$row['evaluation_id']][] = (int)$row['competencia_id'];
        }
    }

    $students = [];
    $stmt = $db->prepare("SELECT id,nivel,grado,COALESCE(NULLIF(TRIM(seccion),''),'U') seccion
                          FROM student WHERE school_id=? AND (status='Activo' OR status IS NULL)");
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $key = agc_student_key((string)$row['nivel'], (string)$row['grado'], (string)$row['seccion']);
        $students[$key][] = (int)$row['id'];
    }
    $stmt->close();

    $gradeMap = [];
    if ($evaluationIds) {
        $idList = implode(',', array_map('intval', $evaluationIds));
        $q = $db->query("SELECT evaluation_id,student_id,competencia_id
                         FROM evaluation_grades
                         WHERE evaluation_id IN ($idList) AND grade IS NOT NULL AND TRIM(grade)<>''");
        while ($q && ($row = $q->fetch_assoc())) {
            $gradeMap[(int)$row['evaluation_id']][(int)$row['competencia_id']][(int)$row['student_id']] = true;
        }
    }

    $closures = [];
    $stmt = $db->prepare("SELECT gpc.*,u.name closed_by_name,ru.name reopened_by_name
                          FROM grade_period_closures gpc
                          LEFT JOIN users u ON u.id=gpc.closed_by AND u.school_id=gpc.school_id
                          LEFT JOIN users ru ON ru.id=gpc.reopened_by AND ru.school_id=gpc.school_id
                          WHERE gpc.school_id=? AND gpc.academic_year_id=?");
    $stmt->bind_param('ii', $schoolId, $yearId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $closures[(int)$row['teacher_course_id']][(int)$row['bimester']] = $row;
    }
    $stmt->close();

    $pendingReopen = [];
    $stmt = $db->prepare("SELECT teacher_course_id,bimester,id,reason,created_at
                          FROM grade_reopen_requests
                          WHERE school_id=? AND academic_year_id=? AND status='Pendiente'");
    $stmt->bind_param('ii', $schoolId, $yearId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $pendingReopen[(int)$row['teacher_course_id']][(int)$row['bimester']] = $row;
    }
    $stmt->close();

    $locks = [1=>0,2=>0,3=>0,4=>0];
    $stmt = $db->prepare('SELECT bimester,is_locked FROM bimester_locks WHERE school_id=? AND academic_year_id=?');
    $stmt->bind_param('ii', $schoolId, $yearId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $b = (int)$row['bimester'];
        if ($b >= 1 && $b <= 4) $locks[$b] = (int)$row['is_locked'];
    }
    $stmt->close();

    $periods = [];
    for ($b = 1; $b <= 4; $b++) {
        $summary = [
            'total'=>count($assignments),'closed'=>0,'ready'=>0,'incomplete'=>0,'reopened'=>0,
            'pending_reopen'=>0,'locked'=>(bool)$locks[$b],'progress'=>0
        ];
        $items = [];
        foreach ($assignments as $assignment) {
            $tc = (int)$assignment['id'];
            $compKey = (int)$assignment['course_id'] . ':' . (int)$assignment['teacher_id'];
            $comps = $competencies[$compKey] ?? [];
            $pctTotal = 0.0;
            foreach ($comps as $comp) $pctTotal += (float)$comp['percentage'];
            $evals = $evaluations[$tc][$b] ?? [];
            $studentKey = agc_student_key((string)$assignment['level'], (string)$assignment['grado'], (string)$assignment['seccion']);
            $studentIds = $students[$studentKey] ?? [];
            $issues = [];
            if (!$comps) $issues[] = 'No hay competencias activas con porcentaje mayor a 0%.';
            if (abs($pctTotal - 100.0) > 0.01) $issues[] = 'Los porcentajes de competencias suman ' . number_format($pctTotal, 2) . '%.';
            if (!$evals) $issues[] = 'No hay evaluaciones activas en el bimestre.';
            if (!$studentIds) $issues[] = 'No hay estudiantes activos en el aula.';

            $compEvalCount = [];
            foreach ($comps as $cid => $comp) $compEvalCount[(int)$cid] = 0;
            $expected = 0;
            $completed = 0;
            foreach ($evals as $evaluation) {
                $evalId = (int)$evaluation['id'];
                $evalLinks = array_values(array_unique($links[$evalId] ?? []));
                if (!$evalLinks) {
                    $issues[] = 'La evaluación “' . $evaluation['title'] . '” no tiene competencia asociada.';
                    continue;
                }
                foreach ($evalLinks as $cid) {
                    if (!isset($comps[$cid])) {
                        $issues[] = 'La evaluación “' . $evaluation['title'] . '” usa una competencia inactiva o con porcentaje 0%.';
                        continue;
                    }
                    $compEvalCount[$cid] = ($compEvalCount[$cid] ?? 0) + 1;
                    foreach ($studentIds as $studentId) {
                        $expected++;
                        if (!empty($gradeMap[$evalId][$cid][$studentId])) $completed++;
                    }
                }
            }
            foreach ($comps as $cid => $comp) {
                if (($compEvalCount[(int)$cid] ?? 0) < 1) {
                    $issues[] = 'La competencia “' . $comp['name'] . '” no tiene evaluación activa.';
                }
            }
            if ($expected > $completed) $issues[] = 'Faltan ' . ($expected - $completed) . ' calificación(es).';
            $ready = count($issues) === 0;
            $gradeProgress = $expected > 0 ? round(($completed / $expected) * 100, 1) : 0;
            $closure = $closures[$tc][$b] ?? null;
            $pending = $pendingReopen[$tc][$b] ?? null;
            $closed = $closure && ($closure['status'] ?? '') === 'Cerrado';
            $reopened = $closure && !$closed && !empty($closure['reopened_at']);

            if ($closed) $summary['closed']++;
            elseif ($reopened) $summary['reopened']++;
            elseif ($ready) $summary['ready']++;
            else $summary['incomplete']++;
            if ($pending) $summary['pending_reopen']++;

            $state = $closed ? ($pending ? 'Reapertura pendiente' : 'Cerrado') : ($reopened ? 'Reabierto' : ($ready ? 'Listo' : 'Incompleto'));
            $items[] = [
                'teacher_course_id'=>$tc,
                'course_name'=>$assignment['course_name'],
                'level'=>$assignment['level'],
                'grado'=>$assignment['grado'],
                'seccion'=>$assignment['seccion'],
                'teacher_name'=>$assignment['teacher_name'],
                'state'=>$state,
                'ready'=>$ready,
                'closed'=>$closed,
                'reopened'=>$reopened,
                'pending_reopen'=>(bool)$pending,
                'grade_progress'=>$gradeProgress,
                'issues'=>array_slice(array_values(array_unique($issues)), 0, 6),
                'closed_at'=>$closure['closed_at'] ?? null,
                'closed_by_name'=>$closure['closed_by_name'] ?? null
            ];
        }
        $summary['progress'] = $summary['total'] > 0 ? round(($summary['closed'] / $summary['total']) * 100, 1) : 0;
        $periods[$b] = ['bimester'=>$b,'summary'=>$summary,'items'=>$items];
    }

    return ['year'=>$year,'periods'=>$periods,'locks'=>$locks];
}
function agc_annual_checklist(mysqli $db, int $schoolId, int $yearId): array {
    $matrix = agc_matrix($db, $schoolId, $yearId);
    $unlocked = 0;
    $pendingClosures = 0;
    $totalClosures = 0;
    $closedClosures = 0;
    for ($b = 1; $b <= 4; $b++) {
        $s = $matrix['periods'][$b]['summary'];
        if (empty($s['locked'])) $unlocked++;
        $totalClosures += (int)$s['total'];
        $closedClosures += (int)$s['closed'];
        $pendingClosures += max(0, (int)$s['total'] - (int)$s['closed']);
    }

    $openPeriods = 0;
    $stmt = $db->prepare('SELECT COUNT(*) total FROM academic_periods WHERE school_id=? AND academic_year_id=? AND is_locked=0');
    $stmt->bind_param('ii', $schoolId, $yearId);
    $stmt->execute();
    $openPeriods = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $badCompetencies = 0;
    $stmt = $db->prepare("SELECT COUNT(*) total FROM (
        SELECT course_id,teacher_id,SUM(CASE WHEN is_active=1 AND percentage>0 THEN percentage ELSE 0 END) total_pct
        FROM general_course_competencies
        WHERE academic_year_id=?
        GROUP BY course_id,teacher_id
        HAVING ABS(total_pct-100)>0.01
    ) x");
    $stmt->bind_param('i', $yearId);
    $stmt->execute();
    $badCompetencies = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $coursesWithoutTeacher = 0;
    $stmt = $db->prepare("SELECT COUNT(*) total FROM academic_courses ac
                          WHERE ac.school_id=? AND ac.academic_year_id=? AND (ac.is_active=1 OR ac.is_active IS NULL)
                            AND NOT EXISTS(SELECT 1 FROM teacher_courses tc WHERE tc.school_id=ac.school_id AND tc.academic_year_id=ac.academic_year_id AND tc.course_id=ac.id)");
    $stmt->bind_param('ii', $schoolId, $yearId);
    $stmt->execute();
    $coursesWithoutTeacher = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $evaluations = 0;
    $stmt = $db->prepare('SELECT COUNT(*) total FROM evaluations WHERE academic_year_id=?');
    $stmt->bind_param('i', $yearId);
    $stmt->execute();
    $evaluations = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $items = [
        ['label'=>'Bimestres sin bloqueo institucional','count'=>$unlocked,'blocking'=>$unlocked>0,'severity'=>$unlocked>0?'danger':'success'],
        ['label'=>'Periodos académicos abiertos','count'=>$openPeriods,'blocking'=>$openPeriods>0,'severity'=>$openPeriods>0?'warning':'success'],
        ['label'=>'Cursos activos sin docente','count'=>$coursesWithoutTeacher,'blocking'=>$coursesWithoutTeacher>0,'severity'=>$coursesWithoutTeacher>0?'warning':'success'],
        ['label'=>'Competencias con porcentaje distinto de 100%','count'=>$badCompetencies,'blocking'=>$badCompetencies>0,'severity'=>$badCompetencies>0?'warning':'success'],
        ['label'=>'Cierres docentes todavía no realizados','count'=>$pendingClosures,'blocking'=>false,'severity'=>$pendingClosures>0?'warning':'success'],
        ['label'=>'Evaluaciones registradas','count'=>$evaluations,'blocking'=>false,'severity'=>'info']
    ];
    $canClose = $unlocked === 0 && $openPeriods === 0 && $coursesWithoutTeacher === 0 && $badCompetencies === 0;
    return [
        'year'=>$matrix['year'],'items'=>$items,'can_close'=>$canClose,
        'closure_summary'=>['total'=>$totalClosures,'closed'=>$closedClosures,'pending'=>$pendingClosures],
        'periods'=>$matrix['periods']
    ];
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
$loginType = (int)($_SESSION['login_type'] ?? 0);
if ($schoolId <= 0 || $userId <= 0 || $loginType !== 1) {
    agc_response(['status'=>0,'message'=>'Solo Administración puede gestionar el cierre institucional.'], 403);
}
$missing = agc_required_tables($conn);
if ($missing) {
    agc_response([
        'status'=>0,'migration_required'=>true,
        'message'=>'Falta habilitar el cierre de notas. Ejecuta sql/grade_closure_upgrade.sql y las migraciones de Año académico.',
        'missing_tables'=>$missing
    ], 409);
}
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'progress');
if ($_SERVER['REQUEST_METHOD'] === 'POST') agc_csrf();

try {
    if ($action === 'progress') {
        $yearId = (int)($_GET['academic_year_id'] ?? 0);
        if ($yearId <= 0) agc_response(['status'=>0,'message'=>'Selecciona un año académico.']);
        $matrix = agc_matrix($conn, $schoolId, $yearId);
        agc_response(['status'=>1] + $matrix);
    }

    if ($action === 'set_lock') {
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $bimester = (int)($_POST['bimester'] ?? 0);
        $locked = (int)($_POST['locked'] ?? 0) === 1 ? 1 : 0;
        $force = (int)($_POST['force'] ?? 0) === 1;
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($yearId <= 0 || $bimester < 1 || $bimester > 4) agc_response(['status'=>0,'message'=>'El año o bimestre no es válido.']);
        $year = agc_year($conn, $schoolId, $yearId);
        if (!$year || ($year['status'] ?? '') !== 'Activo') agc_response(['status'=>0,'message'=>'Solo se puede cambiar el bloqueo del año académico activo.']);
        $matrix = agc_matrix($conn, $schoolId, $yearId);
        $summary = $matrix['periods'][$bimester]['summary'];
        $pending = max(0, (int)$summary['total'] - (int)$summary['closed']);

        if ($locked === 1 && ($pending > 0 || (int)$summary['total'] === 0) && !$force) {
            $msg = (int)$summary['total'] === 0
                ? 'No hay asignaciones docentes registradas para este año. Confirma el bloqueo anticipado si deseas continuar.'
                : "Todavía hay $pending asignación(es) sin cierre docente. Para bloquear de todos modos debes registrar un motivo.";
            agc_response(['status'=>2,'needs_force'=>true,'message'=>$msg,'summary'=>$summary]);
        }
        if (($force || $locked === 0) && mb_strlen($reason, 'UTF-8') < 5) {
            agc_response(['status'=>0,'message'=>$locked ? 'Indica el motivo del bloqueo anticipado.' : 'Indica el motivo del desbloqueo institucional.']);
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('INSERT INTO bimester_locks(academic_year_id,school_id,bimester,is_locked) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE is_locked=VALUES(is_locked)');
            $stmt->bind_param('iiii', $yearId, $schoolId, $bimester, $locked);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            $stmt = $conn->prepare('UPDATE academic_periods SET is_locked=? WHERE academic_year_id=? AND school_id=? AND period_number=?');
            $stmt->bind_param('iiii', $locked, $yearId, $schoolId, $bimester);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            agc_audit($conn, $schoolId, $yearId, $userId, $locked ? 'bimester_institutionally_locked' : 'bimester_institutionally_unlocked', [
                'bimester'=>$bimester,'forced'=>$force,'reason'=>$reason,
                'teacher_closures'=>['total'=>(int)$summary['total'],'closed'=>(int)$summary['closed'],'pending'=>$pending]
            ]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        agc_response(['status'=>1,'message'=>$locked ? 'Bimestre bloqueado institucionalmente.' : 'Bimestre desbloqueado institucionalmente.']);
    }

    if ($action === 'annual_checklist') {
        $yearId = (int)($_GET['academic_year_id'] ?? 0);
        if ($yearId <= 0) agc_response(['status'=>0,'message'=>'Selecciona un año académico.']);
        $checklist = agc_annual_checklist($conn, $schoolId, $yearId);
        agc_response(['status'=>1] + $checklist);
    }

    if ($action === 'close_year') {
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($yearId <= 0 || mb_strlen($notes, 'UTF-8') < 5) agc_response(['status'=>0,'message'=>'Indica una observación de cierre de al menos 5 caracteres.']);
        $year = agc_year($conn, $schoolId, $yearId);
        if (!$year || ($year['status'] ?? '') !== 'Activo') agc_response(['status'=>0,'message'=>'Solo se puede cerrar el año académico activo.']);
        $checklist = agc_annual_checklist($conn, $schoolId, $yearId);
        if (!$checklist['can_close']) {
            agc_response(['status'=>0,'message'=>'El año todavía no está listo. Primero completa los bloqueos institucionales y corrige los pendientes del checklist.','checklist'=>$checklist]);
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE academic_year SET status='Cerrado',is_active=0,closed_at=NOW(),closed_by=?,close_notes=? WHERE id=? AND school_id=? AND status='Activo'");
            $stmt->bind_param('isii', $userId, $notes, $yearId, $schoolId);
            if (!$stmt->execute() || $stmt->affected_rows < 1) throw new RuntimeException('No se pudo actualizar el año académico.');
            $stmt->close();
            $stmt = $conn->prepare('UPDATE academic_periods SET is_locked=1 WHERE academic_year_id=? AND school_id=?');
            $stmt->bind_param('ii', $yearId, $schoolId);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            for ($b = 1; $b <= 4; $b++) {
                $stmt = $conn->prepare('INSERT INTO bimester_locks(academic_year_id,school_id,bimester,is_locked) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE is_locked=1');
                $stmt->bind_param('iii', $yearId, $schoolId, $b);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();
            }
            agc_audit($conn, $schoolId, $yearId, $userId, 'year_closed_with_grade_control', [
                'notes'=>$notes,'teacher_closures'=>$checklist['closure_summary']
            ]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        agc_response(['status'=>1,'message'=>'Año académico cerrado. Los cuatro bimestres quedaron bloqueados institucionalmente.']);
    }

    agc_response(['status'=>0,'message'=>'Acción no válida.'], 404);
} catch (Throwable $e) {
    error_log('[academic_year_grade_control] ' . $e->getMessage());
    agc_response(['status'=>0,'message'=>'No se pudo completar la operación.','detail'=>$e->getMessage()], 500);
}
