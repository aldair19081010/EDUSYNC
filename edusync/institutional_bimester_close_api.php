<?php
ob_start();
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function ibc_response(array $data, int $code = 200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ibc_table(mysqli $db, string $name): bool {
    $safe = $db->real_escape_string($name);
    $q = $db->query("SHOW TABLES LIKE '$safe'");
    return $q && $q->num_rows > 0;
}
function ibc_col(mysqli $db, string $table, string $column): bool {
    $t = str_replace('`', '', $table);
    $c = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $q && $q->num_rows > 0;
}
function ibc_csrf(): void {
    $sent = (string)($_POST['csrf_token'] ?? '');
    $saved = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $saved === '' || !hash_equals($saved, $sent)) {
        ibc_response(['status'=>0,'message'=>'La sesión de seguridad venció. Recarga la página.'], 403);
    }
}
function ibc_norm(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return preg_replace('/[°º\s]+/u', '', $value) ?? $value;
}
function ibc_section(string $value): string {
    $value = ibc_norm($value);
    return in_array($value, ['', 'u', 'unica', 'única'], true) ? 'u' : $value;
}
function ibc_student_key(string $level, string $grade, string $section): string {
    return ibc_norm($level) . '|' . ibc_norm($grade) . '|' . ibc_section($section);
}
function ibc_year(mysqli $db, int $schoolId, int $yearId): ?array {
    $s = $db->prepare('SELECT * FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
    $s->bind_param('ii', $yearId, $schoolId);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r ?: null;
}
function ibc_is_locked(mysqli $db, int $schoolId, int $yearId, int $bimester): bool {
    $s = $db->prepare('SELECT is_locked FROM bimester_locks WHERE school_id=? AND academic_year_id=? AND bimester=? LIMIT 1');
    $s->bind_param('iii', $schoolId, $yearId, $bimester);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return (int)($r['is_locked'] ?? 0) === 1;
}
function ibc_set_lock(mysqli $db, int $schoolId, int $yearId, int $bimester, int $locked): void {
    $s = $db->prepare('INSERT INTO bimester_locks(academic_year_id,school_id,bimester,is_locked) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE is_locked=VALUES(is_locked)');
    $s->bind_param('iiii', $yearId, $schoolId, $bimester, $locked);
    if (!$s->execute()) throw new RuntimeException($s->error);
    $s->close();
    $s = $db->prepare('UPDATE academic_periods SET is_locked=? WHERE academic_year_id=? AND school_id=? AND period_number=?');
    $s->bind_param('iiii', $locked, $yearId, $schoolId, $bimester);
    if (!$s->execute()) throw new RuntimeException($s->error);
    $s->close();
}
function ibc_audit(mysqli $db, int $schoolId, int $yearId, int $userId, string $action, array $details): void {
    if (!ibc_table($db, 'academic_year_audit')) return;
    $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $s = $db->prepare('INSERT INTO academic_year_audit(school_id,academic_year_id,user_id,action,details,ip_address) VALUES(?,?,?,?,?,?)');
    if (!$s) return;
    $s->bind_param('iiisss', $schoolId, $yearId, $userId, $action, $json, $ip);
    $s->execute();
    $s->close();
}
function ibc_latest(mysqli $db, int $schoolId, int $yearId, int $bimester): ?array {
    $s = $db->prepare("SELECT c.*,COALESCE(u.name,'Usuario') closed_by_name,COALESCE(r.name,'Usuario') reopened_by_name
                       FROM institutional_bimester_closures c
                       LEFT JOIN users u ON u.id=c.closed_by AND u.school_id=c.school_id
                       LEFT JOIN users r ON r.id=c.reopened_by AND r.school_id=c.school_id
                       WHERE c.school_id=? AND c.academic_year_id=? AND c.bimester=?
                       ORDER BY c.version DESC,c.id DESC LIMIT 1");
    $s->bind_param('iii', $schoolId, $yearId, $bimester);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$row) return null;
    $row['id'] = (int)$row['id'];
    $row['version'] = (int)$row['version'];
    $row['bimester'] = (int)$row['bimester'];
    $row['snapshot'] = json_decode((string)$row['snapshot_json'], true) ?: [];
    unset($row['snapshot_json']);
    return $row;
}
function ibc_history(mysqli $db, int $schoolId, int $yearId, int $bimester): array {
    $items = [];
    $s = $db->prepare("SELECT c.*,COALESCE(u.name,'Usuario') closed_by_name,COALESCE(r.name,'Usuario') reopened_by_name
                       FROM institutional_bimester_closures c
                       LEFT JOIN users u ON u.id=c.closed_by AND u.school_id=c.school_id
                       LEFT JOIN users r ON r.id=c.reopened_by AND r.school_id=c.school_id
                       WHERE c.school_id=? AND c.academic_year_id=? AND c.bimester=?
                       ORDER BY c.version DESC,c.id DESC");
    $s->bind_param('iii', $schoolId, $yearId, $bimester);
    $s->execute();
    $q = $s->get_result();
    while ($row = $q->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['version'] = (int)$row['version'];
        $row['bimester'] = (int)$row['bimester'];
        $row['snapshot'] = json_decode((string)$row['snapshot_json'], true) ?: [];
        unset($row['snapshot_json']);
        $items[] = $row;
    }
    $s->close();
    return $items;
}
function ibc_snapshot(mysqli $db, int $schoolId, int $yearId, int $bimester): array {
    $year = ibc_year($db, $schoolId, $yearId);
    if (!$year) throw new RuntimeException('El año académico no existe.');

    $assignments = [];
    $s = $db->prepare("SELECT tc.id,tc.teacher_id,tc.course_id,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,
                              ac.name course_name,ac.level,COALESCE(t.name,'Docente no disponible') teacher_name
                       FROM teacher_courses tc
                       INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
                       LEFT JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
                       WHERE tc.school_id=? AND tc.academic_year_id=? AND (ac.is_active=1 OR ac.is_active IS NULL)
                       ORDER BY ac.level,tc.grado,tc.seccion,ac.name,t.name");
    $s->bind_param('ii', $schoolId, $yearId);
    $s->execute();
    $q = $s->get_result();
    while ($r = $q->fetch_assoc()) {
        $r['id'] = (int)$r['id'];
        $r['teacher_id'] = (int)$r['teacher_id'];
        $r['course_id'] = (int)$r['course_id'];
        $assignments[$r['id']] = $r;
    }
    $s->close();

    $students = [];
    $s = $db->prepare("SELECT id,nivel,grado,COALESCE(NULLIF(TRIM(seccion),''),'U') seccion
                       FROM student WHERE school_id=? AND (status='Activo' OR status IS NULL)");
    $s->bind_param('i', $schoolId);
    $s->execute();
    $q = $s->get_result();
    while ($r = $q->fetch_assoc()) {
        $key = ibc_student_key((string)$r['nivel'], (string)$r['grado'], (string)$r['seccion']);
        $students[$key][(int)$r['id']] = true;
    }
    $s->close();

    $competencies = [];
    $s = $db->prepare('SELECT id,course_id,teacher_id,name,percentage FROM general_course_competencies WHERE academic_year_id=? AND is_active=1');
    $s->bind_param('i', $yearId);
    $s->execute();
    $q = $s->get_result();
    while ($r = $q->fetch_assoc()) {
        if ((float)$r['percentage'] <= 0) continue;
        $key = (int)$r['course_id'] . ':' . (int)$r['teacher_id'];
        $competencies[$key][(int)$r['id']] = ['name'=>$r['name'],'percentage'=>(float)$r['percentage']];
    }
    $s->close();

    $evalStatus = ibc_col($db, 'evaluations', 'status') ? " AND (e.status IS NULL OR e.status<>'Anulada')" : '';
    $evaluations = [];
    $evaluationIds = [];
    $s = $db->prepare("SELECT e.id,e.teacher_course_id,e.title FROM evaluations e
                       INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id AND tc.school_id=?
                       WHERE e.academic_year_id=? AND CAST(e.bimestre AS UNSIGNED)=?$evalStatus");
    $s->bind_param('iii', $schoolId, $yearId, $bimester);
    $s->execute();
    $q = $s->get_result();
    while ($r = $q->fetch_assoc()) {
        $id = (int)$r['id'];
        $tc = (int)$r['teacher_course_id'];
        $evaluations[$tc][$id] = ['id'=>$id,'title'=>$r['title']];
        $evaluationIds[] = $id;
    }
    $s->close();

    $links = [];
    $gradeMap = [];
    if ($evaluationIds) {
        $ids = implode(',', array_map('intval', $evaluationIds));
        $q = $db->query("SELECT evaluation_id,competencia_id FROM evaluation_competencias WHERE evaluation_id IN ($ids)");
        while ($q && ($r = $q->fetch_assoc())) $links[(int)$r['evaluation_id']][] = (int)$r['competencia_id'];
        $q = $db->query("SELECT evaluation_id,student_id,competencia_id FROM evaluation_grades
                         WHERE evaluation_id IN ($ids) AND grade IS NOT NULL AND TRIM(grade)<>''");
        while ($q && ($r = $q->fetch_assoc())) $gradeMap[(int)$r['evaluation_id']][(int)$r['competencia_id']][(int)$r['student_id']] = true;
    }

    $closures = [];
    $s = $db->prepare("SELECT teacher_course_id,status,reopened_at FROM grade_period_closures
                       WHERE school_id=? AND academic_year_id=? AND bimester=?");
    $s->bind_param('iii', $schoolId, $yearId, $bimester);
    $s->execute();
    $q = $s->get_result();
    while ($r = $q->fetch_assoc()) $closures[(int)$r['teacher_course_id']] = $r;
    $s->close();

    $pendingReopen = [];
    $s = $db->prepare("SELECT teacher_course_id FROM grade_reopen_requests
                       WHERE school_id=? AND academic_year_id=? AND bimester=? AND status='Pendiente'");
    $s->bind_param('iii', $schoolId, $yearId, $bimester);
    $s->execute();
    $q = $s->get_result();
    while ($r = $q->fetch_assoc()) $pendingReopen[(int)$r['teacher_course_id']] = true;
    $s->close();

    $summary = ['total'=>count($assignments),'closed'=>0,'ready'=>0,'incomplete'=>0,'reopened'=>0,'pending_reopen'=>0,
                'evaluations'=>count($evaluationIds),'expected_grade_cells'=>0,'completed_grade_cells'=>0,'grade_progress'=>0,'closure_progress'=>0];
    $openItems = [];

    foreach ($assignments as $a) {
        $tc = (int)$a['id'];
        $compKey = (int)$a['course_id'] . ':' . (int)$a['teacher_id'];
        $comps = $competencies[$compKey] ?? [];
        $pct = 0.0;
        foreach ($comps as $c) $pct += (float)$c['percentage'];
        $evals = $evaluations[$tc] ?? [];
        $studentKey = ibc_student_key((string)$a['level'], (string)$a['grado'], (string)$a['seccion']);
        $studentIds = array_keys($students[$studentKey] ?? []);
        $issues = [];
        if (!$comps) $issues[] = 'Sin competencias activas.';
        if (abs($pct - 100.0) > 0.01) $issues[] = 'Porcentajes de competencias: ' . number_format($pct, 2) . '%.';
        if (!$evals) $issues[] = 'Sin evaluaciones activas.';
        if (!$studentIds) $issues[] = 'Sin estudiantes activos en el aula.';

        $compEvalCount = [];
        foreach ($comps as $cid => $_) $compEvalCount[(int)$cid] = 0;
        $expected = 0;
        $completed = 0;
        foreach ($evals as $ev) {
            $evalLinks = array_values(array_unique($links[(int)$ev['id']] ?? []));
            if (!$evalLinks) {
                $issues[] = 'La evaluación “' . $ev['title'] . '” no tiene competencia.';
                continue;
            }
            foreach ($evalLinks as $cid) {
                if (!isset($comps[$cid])) {
                    $issues[] = 'La evaluación “' . $ev['title'] . '” usa una competencia inactiva.';
                    continue;
                }
                $compEvalCount[$cid] = ($compEvalCount[$cid] ?? 0) + 1;
                foreach ($studentIds as $studentId) {
                    $expected++;
                    if (!empty($gradeMap[(int)$ev['id']][$cid][$studentId])) $completed++;
                }
            }
        }
        foreach ($comps as $cid => $comp) if (($compEvalCount[(int)$cid] ?? 0) < 1) $issues[] = 'La competencia “' . $comp['name'] . '” no tiene evaluación.';
        if ($expected > $completed) $issues[] = 'Faltan ' . ($expected - $completed) . ' calificación(es).';

        $summary['expected_grade_cells'] += $expected;
        $summary['completed_grade_cells'] += $completed;
        $closure = $closures[$tc] ?? null;
        $closed = $closure && ($closure['status'] ?? '') === 'Cerrado';
        $reopened = $closure && !$closed && !empty($closure['reopened_at']);
        $ready = count(array_unique($issues)) === 0;
        $pending = !empty($pendingReopen[$tc]);
        if ($closed) $summary['closed']++;
        elseif ($reopened) $summary['reopened']++;
        elseif ($ready) $summary['ready']++;
        else $summary['incomplete']++;
        if ($pending) $summary['pending_reopen']++;

        if (!$closed) {
            $openItems[] = [
                'teacher_course_id'=>$tc,'teacher_name'=>$a['teacher_name'],'course_name'=>$a['course_name'],
                'level'=>$a['level'],'grado'=>$a['grado'],'seccion'=>$a['seccion'],
                'state'=>$reopened?'Reabierto':($ready?'Listo':'Incompleto'),'pending_reopen'=>$pending,
                'grade_progress'=>$expected ? round(($completed/$expected)*100,1) : 0,
                'issues'=>array_slice(array_values(array_unique($issues)),0,6)
            ];
        }
    }

    $summary['grade_progress'] = $summary['expected_grade_cells'] > 0 ? round(($summary['completed_grade_cells']/$summary['expected_grade_cells'])*100,1) : 0;
    $summary['closure_progress'] = $summary['total'] > 0 ? round(($summary['closed']/$summary['total'])*100,1) : 0;
    $summary['open'] = max(0, $summary['total'] - $summary['closed']);

    return [
        'generated_at'=>date('Y-m-d H:i:s'),
        'year'=>['id'=>(int)$year['id'],'year'=>$year['year'],'status'=>$year['status'] ?? '','period_type'=>$year['period_type'] ?? 'Bimestre'],
        'bimester'=>$bimester,
        'summary'=>$summary,
        'open_assignments'=>$openItems
    ];
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
$loginType = (int)($_SESSION['login_type'] ?? 0);
if ($schoolId <= 0 || $userId <= 0 || $loginType !== 1) ibc_response(['status'=>0,'message'=>'Solo Administración puede gestionar el cierre institucional.'], 403);

$required = ['institutional_bimester_closures','bimester_locks','academic_periods','academic_year_audit','grade_period_closures','grade_reopen_requests','teacher_courses','academic_courses','general_course_competencies','evaluations','evaluation_competencias','evaluation_grades','student'];
$missing = [];
foreach ($required as $table) if (!ibc_table($conn, $table)) $missing[] = $table;
if ($missing) ibc_response(['status'=>0,'migration_required'=>true,'message'=>'Falta actualizar la base de datos para el cierre institucional. Ejecuta sql/institutional_bimester_closure_upgrade.sql.','missing_tables'=>$missing], 409);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');
if ($_SERVER['REQUEST_METHOD'] === 'POST') ibc_csrf();

try {
    if ($action === 'status' || $action === 'history') {
        $yearId = (int)($_GET['academic_year_id'] ?? 0);
        $bimester = (int)($_GET['bimester'] ?? 0);
        if ($yearId <= 0 || $bimester < 1 || $bimester > 4) ibc_response(['status'=>0,'message'=>'El año o bimestre no es válido.']);
        $current = ibc_latest($conn, $schoolId, $yearId, $bimester);
        $history = ibc_history($conn, $schoolId, $yearId, $bimester);
        ibc_response(['status'=>1,'locked'=>ibc_is_locked($conn,$schoolId,$yearId,$bimester),'current'=>$current,'history'=>$history]);
    }

    if ($action === 'close') {
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $bimester = (int)($_POST['bimester'] ?? 0);
        $mode = (string)($_POST['mode'] ?? 'Normal');
        $reason = trim((string)($_POST['reason'] ?? ''));
        $confirmation = trim((string)($_POST['confirmation'] ?? ''));
        $exceptionConfirmed = (int)($_POST['exception_confirmed'] ?? 0) === 1;
        if ($yearId <= 0 || $bimester < 1 || $bimester > 4) ibc_response(['status'=>0,'message'=>'El año o bimestre no es válido.']);
        if (!in_array($mode, ['Normal','Excepcional'], true)) ibc_response(['status'=>0,'message'=>'El tipo de cierre no es válido.']);
        if (mb_strtoupper($confirmation, 'UTF-8') !== 'CERRAR') ibc_response(['status'=>0,'message'=>'Escribe CERRAR para confirmar el cierre institucional.']);
        if ($mode === 'Excepcional' && (mb_strlen($reason, 'UTF-8') < 5 || !$exceptionConfirmed)) ibc_response(['status'=>0,'message'=>'El cierre excepcional requiere motivo y confirmación expresa.']);
        $year = ibc_year($conn, $schoolId, $yearId);
        if (!$year || ($year['status'] ?? '') !== 'Activo') ibc_response(['status'=>0,'message'=>'Solo se puede cerrar un bimestre del año académico activo.']);
        $latest = ibc_latest($conn, $schoolId, $yearId, $bimester);
        if (ibc_is_locked($conn,$schoolId,$yearId,$bimester)) {
            if ($latest && ($latest['status'] ?? '') === 'Cerrado') ibc_response(['status'=>0,'message'=>'Este bimestre ya tiene un cierre institucional vigente.']);
            ibc_response(['status'=>0,'legacy_lock'=>true,'message'=>'Este bimestre ya estaba bloqueado con el mecanismo anterior. Reábrelo primero para crear un cierre institucional con acta e historial.'],409);
        }

        $snapshot = ibc_snapshot($conn, $schoolId, $yearId, $bimester);
        $summary = $snapshot['summary'];
        if ($mode === 'Normal') {
            if ((int)$summary['total'] <= 0) ibc_response(['status'=>0,'message'=>'No hay asignaciones docentes para cerrar este bimestre.']);
            if ((int)$summary['open'] > 0 || (int)$summary['pending_reopen'] > 0) {
                ibc_response(['status'=>2,'needs_exceptional'=>true,'message'=>'El bimestre todavía tiene asignaciones sin cierre o solicitudes de reapertura pendientes.','snapshot'=>$snapshot]);
            }
        }

        $nextVersion = 1;
        $s = $conn->prepare('SELECT COALESCE(MAX(version),0)+1 next_version FROM institutional_bimester_closures WHERE school_id=? AND academic_year_id=? AND bimester=?');
        $s->bind_param('iii', $schoolId, $yearId, $bimester);
        $s->execute();
        $nextVersion = (int)($s->get_result()->fetch_assoc()['next_version'] ?? 1);
        $s->close();
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $conn->begin_transaction();
        try {
            $s = $conn->prepare("INSERT INTO institutional_bimester_closures(school_id,academic_year_id,bimester,version,status,closure_type,reason,snapshot_json,closed_by,closed_at)
                                 VALUES(?,?,?,?,'Cerrado',?,?,?,?,NOW())");
            $s->bind_param('iiiisssi', $schoolId, $yearId, $bimester, $nextVersion, $mode, $reason, $json, $userId);
            if (!$s->execute()) throw new RuntimeException($s->error);
            $closureId = (int)$s->insert_id;
            $s->close();
            ibc_set_lock($conn, $schoolId, $yearId, $bimester, 1);
            ibc_audit($conn, $schoolId, $yearId, $userId, 'institutional_bimester_closed', [
                'closure_id'=>$closureId,'bimester'=>$bimester,'version'=>$nextVersion,'type'=>$mode,'reason'=>$reason,'summary'=>$summary
            ]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        ibc_response(['status'=>1,'message'=>$mode==='Excepcional'?'Bimestre cerrado institucionalmente de forma excepcional.':'Bimestre cerrado institucionalmente.','closure_id'=>$closureId,'version'=>$nextVersion,'snapshot'=>$snapshot]);
    }

    if ($action === 'reopen') {
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $bimester = (int)($_POST['bimester'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($yearId <= 0 || $bimester < 1 || $bimester > 4) ibc_response(['status'=>0,'message'=>'El año o bimestre no es válido.']);
        if (mb_strlen($reason, 'UTF-8') < 5) ibc_response(['status'=>0,'message'=>'Indica un motivo de reapertura de al menos 5 caracteres.']);
        $year = ibc_year($conn, $schoolId, $yearId);
        if (!$year || ($year['status'] ?? '') !== 'Activo') ibc_response(['status'=>0,'message'=>'Solo se puede reabrir un bimestre del año académico activo.']);
        $latest = ibc_latest($conn, $schoolId, $yearId, $bimester);
        $locked = ibc_is_locked($conn, $schoolId, $yearId, $bimester);
        if (!$locked) ibc_response(['status'=>0,'message'=>'El bimestre ya está abierto.']);

        $conn->begin_transaction();
        try {
            if ($latest && ($latest['status'] ?? '') === 'Cerrado') {
                $id = (int)$latest['id'];
                $s = $conn->prepare("UPDATE institutional_bimester_closures SET status='Reabierto',reopened_by=?,reopened_at=NOW(),reopen_reason=? WHERE id=? AND school_id=? AND status='Cerrado'");
                $s->bind_param('isii', $userId, $reason, $id, $schoolId);
                if (!$s->execute()) throw new RuntimeException($s->error);
                $s->close();
            }
            ibc_set_lock($conn, $schoolId, $yearId, $bimester, 0);
            ibc_audit($conn, $schoolId, $yearId, $userId, $latest?'institutional_bimester_reopened':'legacy_bimester_reopened', [
                'closure_id'=>(int)($latest['id'] ?? 0),'bimester'=>$bimester,'reason'=>$reason,
                'individual_closures_remain_closed'=>true
            ]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        ibc_response(['status'=>1,'message'=>$latest?'Bimestre reabierto institucionalmente. Los cierres individuales permanecen cerrados.':'Bloqueo institucional anterior retirado. Los cierres individuales permanecen cerrados.']);
    }

    ibc_response(['status'=>0,'message'=>'Acción no válida.'],404);
} catch (Throwable $e) {
    error_log('[institutional_bimester_close] ' . $e->getMessage());
    ibc_response(['status'=>0,'message'=>'No se pudo completar la operación.','detail'=>$e->getMessage()],500);
}
