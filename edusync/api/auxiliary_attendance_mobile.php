<?php
declare(strict_types=1);

// Registro móvil de portería: exclusivo para Auxiliar (users.type=3).
// Misma tabla de asistencia, reglas y trazabilidad del sistema web.
require_once __DIR__ . '/../includes/dev_cors.php';
ini_set('display_errors', '0');
date_default_timezone_set('America/Lima');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function aam_reply(array $data, int $code = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    aam_reply(['status'=>'error','message'=>'Método no permitido.'],405);
}
$sid = trim((string)($_POST['sid'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $sid)) {
    aam_reply(['status'=>'error','message'=>'Inicia sesión nuevamente.'],401);
}
try {
    ob_start();
    require_once __DIR__ . '/../session_config.php';
    require_once __DIR__ . '/../db_connect.php';
    ob_end_clean();
    $school = (int)($_SESSION['login_school_id'] ?? 0);
    $userId = (int)($_SESSION['login_id'] ?? 0);
    if ($school <= 0 || $userId <= 0 || (int)($_SESSION['login_type'] ?? 0) !== 3) {
        aam_reply(['status'=>'error','message'=>'No autorizado para la sesión Auxiliar.'],403);
    }
    $statusCol = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
    $active = $statusCol && $statusCol->num_rows > 0 ? " AND status='Activo'" : '';
    $check = $conn->prepare("SELECT id FROM users WHERE id=? AND school_id=? AND type=3 {$active} LIMIT 1");
    if (!$check) throw new RuntimeException('validar_usuario');
    $check->bind_param('ii', $userId, $school);
    $check->execute();
    $allowed = (bool)$check->get_result()->fetch_assoc();
    $check->close();
    if (!$allowed) aam_reply(['status'=>'error','message'=>'La cuenta Auxiliar no está habilitada.'],403);

    // Sin tablas de asistencia/migración no se permite crear registros parciales.
    foreach (['asistencia','attendance_audit_log','attendance_day_closures'] as $table) {
        $q = $conn->query("SHOW TABLES LIKE '{$table}'");
        if (!$q || !$q->num_rows) {
            aam_reply(['status'=>'error','message'=>'Falta instalar el módulo actualizado de asistencia en el servidor.'],409);
        }
    }
    $conn->query("SET time_zone = '-05:00'");
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    $day = $now->format('Y-m-d');
    $clock = $now->format('H:i:s');
    $action = trim((string)($_POST['action'] ?? 'summary'));

    // Datos de la ficha limitados a identificación y asistencia; sin notas/pagos.
    $studentColumns = 'st.id,st.id_no,st.name,st.nivel,st.grado,st.seccion';
    $todayJoins = "LEFT JOIN (
        SELECT student_id,MIN(hora) AS entry_time,
               MAX(CASE WHEN estado='Tarde' THEN 'Tarde'
                   ELSE estado END) AS entry_state
        FROM asistencia
        WHERE school_id=? AND fecha=? AND tipo='Entrada' AND is_cancelled=0
        GROUP BY student_id
      ) en ON en.student_id=st.id
      LEFT JOIN (
        SELECT student_id,MAX(hora) AS exit_time FROM asistencia
        WHERE school_id=? AND fecha=? AND tipo='Salida' AND is_cancelled=0
        GROUP BY student_id
      ) ex ON ex.student_id=st.id";
    $attendanceCols = ",en.entry_time,en.entry_state,ex.exit_time,
        (SELECT ax.estado FROM asistencia ax
         WHERE ax.student_id=st.id AND ax.school_id=st.school_id
           AND ax.fecha='$day' AND ax.tipo='Entrada' AND ax.is_cancelled=0
         ORDER BY ax.id DESC LIMIT 1) AS record_state";

    if ($action === 'summary') {
        $summary = ['entries'=>0,'exits'=>0];
        $stmt = $conn->prepare("SELECT tipo,COUNT(DISTINCT student_id) AS total
            FROM asistencia WHERE school_id=? AND fecha=? AND is_cancelled=0
            AND tipo IN ('Entrada','Salida') GROUP BY tipo");
        if (!$stmt) throw new RuntimeException('resumen');
        $stmt->bind_param('is', $school, $day);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $key = $row['tipo'] === 'Salida' ? 'exits' : 'entries';
            $summary[$key] = (int)$row['total'];
        }
        $stmt->close();
        $q = $conn->prepare("SELECT COUNT(*) AS total FROM student
            WHERE school_id=? AND status='Activo'");
        $q->bind_param('i',$school);
        $q->execute();
        $summary['students'] = (int)($q->get_result()->fetch_assoc()['total'] ?? 0);
        $q->close();
        aam_reply(['status'=>'ok','date'=>$day,'time'=>$clock,'summary'=>$summary]);
    }

    if ($action === 'filters') {
        $sql = "SELECT DISTINCT nivel,grado,seccion FROM student
                WHERE school_id=? AND status='Activo'
                ORDER BY FIELD(nivel,'Inicial','Primaria','Secundaria'),nivel,grado,seccion";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('filtros');
        $stmt->bind_param('i',$school);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        aam_reply(['status'=>'ok','classrooms'=>$rows]);
    }

    if ($action === 'students') {
        $query = trim((string)($_POST['query'] ?? ''));
        $level = trim((string)($_POST['level'] ?? ''));
        $grade = trim((string)($_POST['grade'] ?? ''));
        $section = trim((string)($_POST['section'] ?? ''));
        if (strlen($query)>80 || strlen($level)>35 || strlen($grade)>15 || strlen($section)>15) {
            aam_reply(['status'=>'error','message'=>'Filtro no válido.'],422);
        }
        $page = max(1,min(10000,(int)($_POST['page'] ?? 1)));
        $limit = 40;
        $offset = ($page-1)*$limit;
        $search = '%'.$query.'%';
        $sql = "SELECT {$studentColumns}{$attendanceCols}
                FROM student st {$todayJoins}
                WHERE st.school_id=? AND st.status='Activo'
                  AND (?='' OR st.nivel=?)
                  AND (?='' OR st.grado=?)
                  AND (?='' OR st.seccion=?)
                  AND (?='' OR st.name LIKE ? OR st.id_no LIKE ?)
                ORDER BY st.nivel,st.grado,st.seccion,st.name,st.id
                LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('nomina');
        $stmt->bind_param('isisisssssssssii',
            $school,$day,$school,$day,
            $school,$level,$level,$grade,$grade,$section,$section,
            $query,$search,$search,$limit,$offset);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        aam_reply(['status'=>'ok','date'=>$day,'students'=>$rows,'page'=>$page,
            'has_more'=>count($rows)===$limit]);
    }

    if ($action === 'lookup') {
        // El código del carné es exactamente student.id_no, igual que el
        // escáner actual de attendance_api.php. Nunca deducir DNI de un QR.
        $code = trim((string)($_POST['code'] ?? ''));
        if ($code === '' || strlen($code)>64) {
            aam_reply(['status'=>'error','message'=>'Ingresa o escanea un código válido.'],422);
        }
        $stmt = $conn->prepare("SELECT {$studentColumns}{$attendanceCols}
            FROM student st {$todayJoins}
            WHERE st.school_id=? AND st.status='Activo' AND st.id_no=?
            LIMIT 2");
        if (!$stmt) throw new RuntimeException('identificar');
        $stmt->bind_param('isisis',$school,$day,$school,$day,$school,$code);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$rows) aam_reply(['status'=>'error',
            'message'=>'No se encontró un estudiante activo con ese código en este colegio.'],404);
        if (count($rows)>1) aam_reply(['status'=>'error',
            'message'=>'Hay códigos repetidos. Solicita a administración corregirlos.'],409);
        aam_reply(['status'=>'ok','date'=>$day,'student'=>$rows[0]]);
    }

    if ($action === 'scan_status') {
        // Un cliente que perdió la respuesta puede comprobar su petición
        // sin crear ninguna asistencia adicional.
        $requestId = strtolower(trim((string)($_POST['request_id'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
            aam_reply(['status'=>'error','message'=>'Identificador inválido.'],422);
        }
        $pattern = '%"request_id":"'.$requestId.'"%';
        $check = $conn->prepare("SELECT a.id AS attendance_id,a.student_id,
            a.fecha AS date,a.hora AS time,a.tipo AS kind,a.estado AS state,
            st.name,st.nivel,st.grado,st.seccion
            FROM attendance_audit_log audit
            INNER JOIN asistencia a ON a.id=audit.attendance_id
                AND a.school_id=audit.school_id
            INNER JOIN student st ON st.id=a.student_id AND st.school_id=a.school_id
            WHERE audit.school_id=? AND audit.user_id=?
              AND audit.action='attendance_auxiliary_mobile'
              AND audit.details LIKE ? AND a.is_cancelled=0
            ORDER BY audit.id DESC LIMIT 1");
        if (!$check) throw new RuntimeException('estado_marcacion');
        $check->bind_param('iis',$school,$userId,$pattern);
        $check->execute();
        $record = $check->get_result()->fetch_assoc();
        $check->close();
        aam_reply(['status'=>'ok','confirmed'=>(bool)$record,
            'attendance'=>$record ?: null]);
    }


    // Las correcciones siguen el contrato de attendance_api.php: un cambio
    // de estado existente solo se aplica cuando Administración lo aprueba.
    $requestChange = static function(
        int $attendance, string $type, array $payload, string $reason = ''
    ) use ($conn,$school,$userId): int {
        if ($attendance > 0) {
            $d=$conn->prepare("SELECT id FROM attendance_change_requests
                WHERE school_id=? AND attendance_id=? AND requested_by=?
                  AND request_type=? AND status='Pendiente'
                LIMIT 1");
            $d->bind_param('iiis',$school,$attendance,$userId,$type);
            $d->execute();
            $existing=$d->get_result()->fetch_assoc();
            $d->close();
            if ($existing) return (int)$existing['id'];
        }
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $q=$conn->prepare("INSERT INTO attendance_change_requests
            (school_id,attendance_id,requested_by,request_type,
             request_payload,reason,status)
            VALUES (?,NULLIF(?,0),?,?,?,?,'Pendiente')");
        if (!$q) throw new RuntimeException('solicitar_cambio');
        $q->bind_param('iiisss',
            $school,$attendance,$userId,$type,$json,$reason);
        if (!$q->execute()) throw new RuntimeException('guardar_solicitud');
        $id=(int)$q->insert_id; $q->close();
        try {
            require_once __DIR__.'/../includes/web_push_staff_immediate.php';
            register_shutdown_function(static function() use ($conn,$school,$id):void {
                try { edwp_send_staff_attendance_now($conn,$school,$id,'att_request'); }
                catch (Throwable $e) { error_log('[aux request push] '.$e->getMessage()); }
            });
        } catch (Throwable $e) { error_log('[aux request init] '.$e->getMessage()); }
        return $id;
    };

    if ($action === 'student_state') {
        $studentId=(int)($_POST['student_id']??0);
        if ($studentId<=0) aam_reply(['status'=>'error','message'=>'Estudiante inválido.'],422);
        $q=$conn->prepare("SELECT id,id_no,name,nivel,grado,seccion
            FROM student WHERE id=? AND school_id=? AND status='Activo' LIMIT 1");
        $q->bind_param('ii',$studentId,$school);$q->execute();
        $student=$q->get_result()->fetch_assoc();$q->close();
        if (!$student) aam_reply(['status'=>'error','message'=>'Estudiante no disponible.'],404);
        $q=$conn->prepare("SELECT id,tipo,hora,estado,notes,source,
            justification_status,justification_reason FROM asistencia
            WHERE school_id=? AND student_id=? AND fecha=? AND is_cancelled=0
            ORDER BY id DESC");
        $q->bind_param('iis',$school,$studentId,$day);$q->execute();
        $records=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $entry=null;$exit=null;
        foreach ($records as $record) {
            if ($record['tipo']==='Entrada' && !$entry) {
                $record['estado'] = in_array($record['estado'],['Normal','Temprano'],true)
                    ? 'Presente' : $record['estado'];
                $entry=$record;
            }
            if ($record['tipo']==='Salida' && !$exit) $exit=$record;
        }
        $q=$conn->prepare("SELECT id FROM attendance_day_closures
            WHERE school_id=? AND attendance_date=? AND nivel=? AND grado=?
              AND seccion=? AND status='Cerrada' LIMIT 1");
        $q->bind_param('issss',$school,$day,
            $student['nivel'],$student['grado'],$student['seccion']);
        $q->execute();$closed=(bool)$q->get_result()->fetch_assoc();$q->close();
        aam_reply(['status'=>'ok','date'=>$day,'student'=>$student,
            'entry'=>$entry,'exit'=>$exit,'closed'=>$closed]);
    }

    if ($action === 'classroom_state') {
        $level=trim((string)($_POST['level']??''));
        $grade=trim((string)($_POST['grade']??''));
        $section=trim((string)($_POST['section']??''));
        if (!$level||!$grade||!$section)
            aam_reply(['status'=>'error','message'=>'Selecciona un aula.'],422);
        $q=$conn->prepare("SELECT COUNT(*) AS expected,
            SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS marked
            FROM student st LEFT JOIN asistencia a ON
              a.id=(SELECT x.id FROM asistencia x
                WHERE x.school_id=st.school_id AND x.student_id=st.id
                  AND x.fecha=? AND x.tipo='Entrada' AND x.is_cancelled=0
                ORDER BY x.id DESC LIMIT 1)
            WHERE st.school_id=? AND st.status='Activo'
              AND st.nivel=? AND st.grado=? AND st.seccion=?");
        $q->bind_param('sisss',$day,$school,$level,$grade,$section);
        $q->execute();$totals=$q->get_result()->fetch_assoc();$q->close();
        $q=$conn->prepare("SELECT id FROM attendance_day_closures
            WHERE school_id=? AND attendance_date=? AND nivel=? AND grado=?
              AND seccion=? AND status='Cerrada' LIMIT 1");
        $q->bind_param('issss',$school,$day,$level,$grade,$section);
        $q->execute();$closed=(bool)$q->get_result()->fetch_assoc();$q->close();
        aam_reply(['status'=>'ok','date'=>$day,'closed'=>$closed,
            'expected'=>(int)($totals['expected']??0),
            'marked'=>(int)($totals['marked']??0)]);
    }

    if ($action === 'my_requests') {
        $q=$conn->prepare("SELECT id,request_type,reason,status,
            created_at,reviewed_at,review_notes FROM attendance_change_requests
            WHERE school_id=? AND requested_by=? ORDER BY id DESC LIMIT 40");
        $q->bind_param('ii',$school,$userId);$q->execute();
        $requests=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        aam_reply(['status'=>'ok','requests'=>$requests]);
    }

    if ($action === 'set_status') {
        $studentId=(int)($_POST['student_id']??0);
        $state=trim((string)($_POST['attendance_status']??''));
        $notes=trim((string)($_POST['notes']??''));
        $validStates=['Presente','Tarde','Ausente','Ausente Justificada','Permiso'];
        if ($studentId<=0 || !in_array($state,$validStates,true) || strlen($notes)>500)
            aam_reply(['status'=>'error','message'=>'Estado u observación no válidos.'],422);
        $conn->begin_transaction();
        try {
            $q=$conn->prepare("SELECT id,name,nivel,grado,seccion FROM student
                WHERE id=? AND school_id=? AND status='Activo' LIMIT 1 FOR UPDATE");
            $q->bind_param('ii',$studentId,$school);$q->execute();
            $student=$q->get_result()->fetch_assoc();$q->close();
            if (!$student) {
                $conn->rollback();
                aam_reply(['status'=>'error','message'=>'Estudiante no encontrado.'],404);
            }
            $q=$conn->prepare("SELECT id FROM attendance_day_closures
                WHERE school_id=? AND attendance_date=? AND nivel=? AND grado=?
                  AND seccion=? AND status='Cerrada' LIMIT 1");
            $q->bind_param('issss',$school,$day,
                $student['nivel'],$student['grado'],$student['seccion']);
            $q->execute();$closed=(bool)$q->get_result()->fetch_assoc();$q->close();
            if ($closed) {
                $conn->rollback();
                aam_reply(['status'=>'error',
                    'message'=>'Aula cerrada. Solicita su reapertura a Administración.'],409);
            }
            $q=$conn->prepare("SELECT id,hora,estado,notes FROM asistencia
                WHERE school_id=? AND student_id=? AND fecha=?
                  AND tipo='Entrada' AND is_cancelled=0 LIMIT 1 FOR UPDATE");
            $q->bind_param('iis',$school,$studentId,$day);$q->execute();
            $previous=$q->get_result()->fetch_assoc();$q->close();
            $isAbsent=in_array($state,['Ausente','Ausente Justificada','Permiso'],true);
            $recordTime=$isAbsent?'00:00:00':$clock;
            $just=$state==='Ausente Justificada'?'Aprobada':null;
            if ($previous) {
                $oldState=in_array($previous['estado'],['Normal','Temprano'],true)
                    ? 'Presente' : $previous['estado'];
                $recordTime=$isAbsent?'00:00:00':(string)$previous['hora'];
                if ($oldState===$state && (string)($previous['notes']??'')===$notes) {
                    $conn->commit();
                    aam_reply(['status'=>'ok','mode'=>'unchanged',
                        'message'=>'La asistencia no tiene cambios.']);
                }
                $payload=['requested'=>['status'=>$state,'time'=>$recordTime,
                    'notes'=>$notes,'justification_status'=>$just],
                    'previous'=>['status'=>$oldState,'time'=>$previous['hora'],
                        'notes'=>$previous['notes']]];
                $requestId=$requestChange((int)$previous['id'],
                    'Editar registro',$payload,'Cambio solicitado desde la app Auxiliar');
                $conn->commit();
                aam_reply(['status'=>'ok','mode'=>'approval','request_id'=>$requestId,
                    'message'=>'Cambio enviado a Administración para autorización.']);
            }
            $y=$conn->prepare("SELECT id FROM academic_year WHERE school_id=?
                AND ? BETWEEN start_date AND end_date
                ORDER BY is_active DESC,id DESC LIMIT 1");
            $y->bind_param('is',$school,$day);$y->execute();
            $yearId=(int)($y->get_result()->fetch_assoc()['id']??0);$y->close();
            $q=$conn->prepare("INSERT INTO asistencia
                (school_id,academic_year_id,student_id,fecha,tipo,hora,
                 estado,source,notes,justification_status,created_by,updated_by)
                VALUES (?,NULLIF(?,0),?,?,'Entrada',?,?,?,?,?,?,?)");
            $q->bind_param('iiisssssii',$school,$yearId,$studentId,$day,
                $recordTime,$state,$notes,$just,$userId,$userId);
            if (!$q->execute()) throw new RuntimeException('crear_asistencia');
            $attId=(int)$q->insert_id; $q->close();
            $auditPayload=json_encode(['status'=>$state,'source'=>'Aula',
                'date'=>$day],JSON_UNESCAPED_UNICODE);
            $ip=(string)($_SERVER['REMOTE_ADDR']??'');
            $q=$conn->prepare("INSERT INTO attendance_audit_log
                (school_id,attendance_id,student_id,user_id,action,details,ip_address)
                VALUES (?,?,?,?,'attendance_created',?,?)");
            $q->bind_param('iiiiss',$school,$attId,$studentId,$userId,$auditPayload,$ip);
            if (!$q->execute()) throw new RuntimeException('auditar_asistencia');
            $q->close();$conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();throw $e;
        }
        try {
            require_once __DIR__.'/../includes/push_notifications.php';
            push_send_attendance_state_notification($conn,$school,$studentId,
                $attId,$day,'',$state,$recordTime);
        } catch (Throwable $e) { error_log('[aux state push] '.$e->getMessage()); }
        aam_reply(['status'=>'ok','mode'=>'created',
            'message'=>'Asistencia registrada correctamente.','state'=>$state]);
    }

    if ($action === 'mark_unmarked_present') {
        $level=trim((string)($_POST['level']??''));
        $grade=trim((string)($_POST['grade']??''));
        $section=trim((string)($_POST['section']??''));
        if (!$level||!$grade||!$section)
            aam_reply(['status'=>'error','message'=>'Selecciona un aula.'],422);
        $saved=0;$events=[];
        $conn->begin_transaction();
        try {
            // Bloquear filas de estudiantes: el escáner usa el mismo
            // bloqueo y no puede registrar simultáneamente dos Entradas.
            $q=$conn->prepare("SELECT id,name,nivel,grado,seccion
                FROM student WHERE school_id=? AND status='Activo'
                  AND nivel=? AND grado=? AND seccion=?
                ORDER BY id FOR UPDATE");
            $q->bind_param('isss',$school,$level,$grade,$section);
            $q->execute();$students=$q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();
            if (!$students) {
                $conn->rollback();
                aam_reply(['status'=>'error','message'=>'El aula no tiene estudiantes.'],404);
            }
            $q=$conn->prepare("SELECT id FROM attendance_day_closures
                WHERE school_id=? AND attendance_date=? AND nivel=?
                  AND grado=? AND seccion=? AND status='Cerrada' LIMIT 1");
            $q->bind_param('issss',$school,$day,$level,$grade,$section);
            $q->execute();$closed=(bool)$q->get_result()->fetch_assoc();$q->close();
            if ($closed) {
                $conn->rollback();
                aam_reply(['status'=>'error','message'=>'El aula está cerrada.'],409);
            }
            $q=$conn->prepare("SELECT id FROM academic_year
                WHERE school_id=? AND ? BETWEEN start_date AND end_date
                ORDER BY is_active DESC,id DESC LIMIT 1");
            $q->bind_param('is',$school,$day);$q->execute();
            $yearId=(int)($q->get_result()->fetch_assoc()['id']??0);$q->close();
            foreach ($students as $student) {
                $id=(int)$student['id'];
                $q=$conn->prepare("SELECT id FROM asistencia
                    WHERE school_id=? AND student_id=? AND fecha=?
                      AND tipo='Entrada' AND is_cancelled=0 LIMIT 1");
                $q->bind_param('iis',$school,$id,$day);$q->execute();
                $found=(bool)$q->get_result()->fetch_assoc();$q->close();
                if($found)continue;
                $q=$conn->prepare("INSERT INTO asistencia
                    (school_id,academic_year_id,student_id,fecha,tipo,hora,
                    estado,source,created_by,updated_by)
                    VALUES (?,NULLIF(?,0),?,?,'Entrada',?,'Presente','Aula',?,?)");
                $q->bind_param('iiissii',
                    $school,$yearId,$id,$day,$clock,$userId,$userId);
                if (!$q->execute()) throw new RuntimeException('marcar_nómina');
                $attId=(int)$q->insert_id;$q->close();
                $details=json_encode(['status'=>'Presente','date'=>$day,
                    'source'=>'Aula'],JSON_UNESCAPED_UNICODE);
                $ip=(string)($_SERVER['REMOTE_ADDR']??'');
                $q=$conn->prepare("INSERT INTO attendance_audit_log
                    (school_id,attendance_id,student_id,user_id,action,details,ip_address)
                    VALUES (?,?,?,?,'attendance_created',?,?)");
                $q->bind_param('iiiiss',
                    $school,$attId,$id,$userId,$details,$ip);
                if(!$q->execute())throw new RuntimeException('auditar_nómina');
                $q->close();
                $events[]=[$id,$attId];
                $saved++;
            }
            $conn->commit();
        } catch(Throwable $e){$conn->rollback();throw $e;}
        try {
            require_once __DIR__.'/../includes/push_notifications.php';
            foreach($events as $event) {
                push_send_attendance_state_notification(
                    $conn,$school,$event[0],$event[1],$day,'',
                    'Presente',$clock);
            }
        } catch(Throwable $e) {error_log('[aux bulk push] '.$e->getMessage());}
        aam_reply(['status'=>'ok','saved'=>$saved,
            'message'=>"$saved estudiantes sin marcar registrados como Presente."]);
    }

    if (in_array($action,['close_day','reopen_request'],true)) {
        $level=trim((string)($_POST['level']??''));
        $grade=trim((string)($_POST['grade']??''));
        $section=trim((string)($_POST['section']??''));
        $reason=trim((string)($_POST['reason']??''));
        if (!$level || !$grade || !$section)
            aam_reply(['status'=>'error','message'=>'Selecciona nivel, grado y sección.'],422);
        if ($action==='reopen_request' && strlen($reason)<3)
            aam_reply(['status'=>'error','message'=>'Indica el motivo de reapertura.'],422);
        $q=$conn->prepare("SELECT COUNT(*) n FROM student WHERE school_id=?
            AND status='Activo' AND nivel=? AND grado=? AND seccion=?");
        $q->bind_param('isss',$school,$level,$grade,$section);$q->execute();
        $total=(int)($q->get_result()->fetch_assoc()['n']??0);$q->close();
        if (!$total) aam_reply(['status'=>'error','message'=>'El aula no tiene estudiantes activos.'],404);
        $q=$conn->prepare("SELECT id FROM attendance_day_closures
            WHERE school_id=? AND attendance_date=? AND nivel=? AND grado=?
                AND seccion=? AND status='Cerrada' LIMIT 1");
        $q->bind_param('issss',$school,$day,$level,$grade,$section);
        $q->execute();$closure=$q->get_result()->fetch_assoc();$q->close();
        if ($action==='reopen_request') {
            if (!$closure) aam_reply(['status'=>'error','message'=>'El aula no está cerrada.'],409);
            $rid=$requestChange(0,'Reabrir día',['closure_id'=>(int)$closure['id'],
                'date'=>$day,'nivel'=>$level,'grado'=>$grade,'seccion'=>$section],$reason);
            aam_reply(['status'=>'ok','mode'=>'approval','request_id'=>$rid,
                'message'=>'Solicitud de reapertura enviada a Administración.']);
        }
        if ($closure) aam_reply(['status'=>'error','message'=>'El aula ya está cerrada.'],409);
        $q=$conn->prepare("SELECT COUNT(*) AS missing FROM student st
            LEFT JOIN asistencia a ON a.student_id=st.id AND a.school_id=?
                AND a.fecha=? AND a.tipo='Entrada' AND a.is_cancelled=0
            WHERE st.school_id=? AND st.status='Activo'
                AND st.nivel=? AND st.grado=? AND st.seccion=? AND a.id IS NULL");
        $q->bind_param('isisss',$school,$day,$school,$level,$grade,$section);
        $q->execute();$missing=(int)($q->get_result()->fetch_assoc()['missing']??0);
        $q->close();
        if ($missing) aam_reply(['status'=>'error',
            'message'=>"Faltan $missing estudiantes por marcar."],409);
        $y=$conn->prepare("SELECT id FROM academic_year WHERE school_id=?
            AND ? BETWEEN start_date AND end_date ORDER BY is_active DESC,id DESC LIMIT 1");
        $y->bind_param('is',$school,$day);$y->execute();
        $yearId=(int)($y->get_result()->fetch_assoc()['id']??0);$y->close();
        $q=$conn->prepare("INSERT INTO attendance_day_closures
            (school_id,academic_year_id,attendance_date,nivel,grado,seccion,
             status,closed_at,closed_by) VALUES
            (?,NULLIF(?,0),?,?,?,?,'Cerrada',NOW(),?)
            ON DUPLICATE KEY UPDATE status='Cerrada',
                closed_at=NOW(),closed_by=VALUES(closed_by),
                reopened_at=NULL,reopened_by=NULL,reopen_reason=NULL");
        $q->bind_param('iissssi',$school,$yearId,$day,$level,$grade,$section,$userId);
        if (!$q->execute()) throw new RuntimeException('cerrar_aula');
        $q->close();
        aam_reply(['status'=>'ok','mode'=>'closed',
            'message'=>'Asistencia del aula cerrada.']);
    }

    if (in_array($action,['cancel_request','justify_request'],true)) {
        $attendanceId=(int)($_POST['attendance_id']??0);
        $reason=trim((string)($_POST['reason']??''));
        $justStatus=trim((string)($_POST['justification_status']??'Pendiente'));
        if ($attendanceId<=0 || strlen($reason)<3 || strlen($reason)>255)
            aam_reply(['status'=>'error','message'=>'Indica el motivo de la solicitud.'],422);
        if ($action==='justify_request' &&
            !in_array($justStatus,['Pendiente','Aprobada','Rechazada'],true))
            aam_reply(['status'=>'error','message'=>'Estado de justificación inválido.'],422);
        $q=$conn->prepare("SELECT a.id,a.estado FROM asistencia a
            INNER JOIN student st ON st.id=a.student_id AND st.school_id=a.school_id
            WHERE a.id=? AND a.school_id=? AND st.status='Activo'
              AND a.tipo='Entrada' AND a.is_cancelled=0 LIMIT 1");
        $q->bind_param('ii',$attendanceId,$school);$q->execute();
        $entry=$q->get_result()->fetch_assoc();$q->close();
        if (!$entry) aam_reply(['status'=>'error','message'=>'Marcación no disponible.'],404);
        if ($action==='justify_request' &&
            !in_array($entry['estado'],['Ausente','Ausente Justificada'],true))
            aam_reply(['status'=>'error','message'=>'Solo puede justificarse una ausencia.'],422);
        if ($action==='cancel_request') {
            $type='Anular registro';
            $payload=['attendance_id'=>$attendanceId];
        } else {
            $type='Justificar ausencia';
            $payload=['attendance_id'=>$attendanceId,
                'status'=>$justStatus,
                'new_state'=>$justStatus==='Aprobada'?'Ausente Justificada':'Ausente'];
        }
        $rid=$requestChange($attendanceId,$type,$payload,$reason);
        aam_reply(['status'=>'ok','mode'=>'approval','request_id'=>$rid,
            'message'=>'Solicitud enviada a Administración para autorización.']);
    }

    if ($action === 'register' || $action === 'scan_register') {
        $byCode = $action === 'scan_register';
        $studentId = (int)($_POST['student_id'] ?? 0);
        $code = trim((string)($_POST['code'] ?? ''));
        $kind = trim((string)($_POST['kind'] ?? ''));
        $source = trim((string)($_POST['source'] ?? ($byCode ? 'Escáner' : 'Manual')));
        $requestId = strtolower(trim((string)($_POST['request_id'] ?? '')));
        if (($byCode && ($code === '' || strlen($code)>64))
            || (!$byCode && $studentId<=0)
            || !in_array($kind,['Entrada','Salida'],true)
            || !in_array($source,['Manual','Escáner'],true)
            || ($requestId !== '' && !preg_match('/^[a-f0-9]{32}$/',$requestId))
            || ($byCode && $requestId==='')) {
            aam_reply(['status'=>'error','message'=>'Registro no válido.'],422);
        }
        // El propio registro en student se bloquea por transacción: dos
        // móviles del portón no pueden insertar la misma marca simultáneamente.
        $conn->begin_transaction();
        try {
            if ($byCode) {
                // Se obtiene y bloquea el estudiante en la MISMA operación.
                // No se puede marcar un código de otra institución.
                $stmt = $conn->prepare("SELECT id,name,nivel,grado,seccion
                    FROM student WHERE school_id=? AND status='Activo'
                        AND id_no=? LIMIT 2 FOR UPDATE");
                if (!$stmt) throw new RuntimeException('identidad_carnet');
                $stmt->bind_param('is',$school,$code);
            } else {
                $stmt = $conn->prepare("SELECT id,name,nivel,grado,seccion
                    FROM student WHERE id=? AND school_id=? AND status='Activo'
                    LIMIT 1 FOR UPDATE");
                if (!$stmt) throw new RuntimeException('identidad');
                $stmt->bind_param('ii',$studentId,$school);
            }
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (count($results)>1) {
                $conn->rollback();
                aam_reply(['status'=>'error',
                    'message'=>'Código repetido. Solicita a administración corregir el carné.'],409);
            }
            $student = $results[0] ?? null;
            if ($student) $studentId = (int)$student['id'];
            if (!$student) {
                $conn->rollback();
                aam_reply(['status'=>'error','message'=>'Estudiante no válido para este colegio.'],403);
            }
            $closure = $conn->prepare("SELECT id FROM attendance_day_closures
                WHERE school_id=? AND attendance_date=? AND nivel=?
                  AND grado=? AND seccion=? AND status='Cerrada' LIMIT 1");
            $closure->bind_param('issss',$school,$day,
                $student['nivel'],$student['grado'],$student['seccion']);
            $closure->execute();
            $closed = (bool)$closure->get_result()->fetch_assoc();
            $closure->close();
            if ($closed) {
                $conn->rollback();
                aam_reply(['status'=>'error',
                    'message'=>'La asistencia del aula ya está cerrada.'],409);
            }
            $dup = $conn->prepare("SELECT id,hora FROM asistencia
                WHERE school_id=? AND student_id=? AND fecha=? AND tipo=?
                  AND is_cancelled=0 LIMIT 1");
            $dup->bind_param('iiss',$school,$studentId,$day,$kind);
            $dup->execute();
            $existing = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($existing) {
                $conn->rollback();
                aam_reply(['status'=>'error',
                    'message'=>"Ya existe una $kind registrada a las ".
                       substr((string)$existing['hora'],0,5).'.'],409);
            }
            require_once __DIR__ . '/../attendance_state_helper.php';
            $status = calcular_estado_asistencia($clock,$day,$kind,$conn,$school);
            $yearStmt = $conn->prepare("SELECT id FROM academic_year
                WHERE school_id=? AND ? BETWEEN start_date AND end_date
                ORDER BY is_active DESC,id DESC LIMIT 1");
            $yearStmt->bind_param('is',$school,$day);
            $yearStmt->execute();
            $yearId = (int)($yearStmt->get_result()->fetch_assoc()['id'] ?? 0);
            $yearStmt->close();
            $insert = $conn->prepare("INSERT INTO asistencia
                (school_id,academic_year_id,student_id,fecha,tipo,hora,estado,
                 source,created_by,updated_by)
                VALUES (?,NULLIF(?,0),?,?,?,?,?,?,?,?)");
            if (!$insert) throw new RuntimeException('insertar');
            $insert->bind_param('iiisssssii',$school,$yearId,$studentId,
                $day,$kind,$clock,$status,$source,$userId,$userId);
            if (!$insert->execute()) throw new RuntimeException('guardar');
            $recordId = (int)$insert->insert_id;
            $insert->close();
            $details = json_encode(['type'=>$kind,'status'=>$status,
                'date'=>$day,'source'=>$source,'request_id'=>$requestId],
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $audit = $conn->prepare("INSERT INTO attendance_audit_log
                (school_id,attendance_id,student_id,user_id,action,details,ip_address)
                VALUES (?,?,?,?,?,?,?)");
            if (!$audit) throw new RuntimeException('auditoria');
            $event = 'attendance_auxiliary_mobile';
            $audit->bind_param('iiiisss',$school,$recordId,$studentId,
                $userId,$event,$details,$ip);
            if (!$audit->execute()) throw new RuntimeException('auditoria');
            $audit->close();
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        // Las notificaciones existentes no deben impedir registrar asistencia.
        try {
            require_once __DIR__ . '/../includes/push_notifications.php';
            push_send_attendance_notification($conn,$school,$studentId,$recordId,
                (string)$student['name'],$kind,$day,$clock,$status);
        } catch (Throwable $error) {
            error_log('[auxiliary mobile push] '.$error->getMessage());
        }
        aam_reply(['status'=>'ok','message'=>"$kind registrada correctamente.",
            'date'=>$day,'time'=>$clock,'kind'=>$kind,'state'=>$status,
            'student_id'=>$studentId,'attendance_id'=>$recordId,
            'request_id'=>$requestId,
            'student'=>['id'=>$studentId,'name'=>$student['name'],
                'nivel'=>$student['nivel'],'grado'=>$student['grado'],
                'seccion'=>$student['seccion']]]);
    }
    aam_reply(['status'=>'error','message'=>'Acción no permitida.'],422);
} catch (Throwable $error) {
    error_log('[auxiliary mobile] '.$error->getMessage());
    aam_reply(['status'=>'error',
        'message'=>'No se pudo completar la operación de asistencia. Revisa el servidor.'],500);
}
