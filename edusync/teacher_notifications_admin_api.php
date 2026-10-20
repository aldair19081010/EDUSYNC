<?php
/**
 * Configuración y seguimiento de avisos docentes.
 * Sólo Administración de la institución; año/bimestre/aula se validan en servidor.
 */
ob_start();
date_default_timezone_set('America/Lima');
include_once __DIR__.'/session_config.php';
include_once __DIR__.'/includes/session_check.php';
require_login_modal();
include __DIR__.'/db_connect.php';
require_once __DIR__.'/includes/teacher_action_notifications.php';
$conn->query("SET time_zone='-05:00'");
header('Content-Type: application/json; charset=utf-8');

function taca_response(array $data,int $code=200):void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function taca_csrf():void {
    $incoming=(string)($_POST['csrf_token']??'');
    $session=(string)($_SESSION['csrf_token']??'');
    if ($incoming===''||$session===''||!hash_equals($session,$incoming)) {
        taca_response(['status'=>0,'message'=>'Sesión de seguridad vencida. Recarga la página.'],403);
    }
}
function taca_year(mysqli $db,int $school,int $year):?array {
    $s=$db->prepare('SELECT id,year,status,is_active,start_date,end_date FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
    $s->bind_param('ii',$year,$school);$s->execute();
    $row=$s->get_result()->fetch_assoc();$s->close();
    return $row?:null;
}
function taca_period(mysqli $db,int $school,int $year,int $bim):?array {
    $s=$db->prepare('SELECT id,name,start_date,end_date,is_locked FROM academic_periods WHERE school_id=? AND academic_year_id=? AND period_number=? LIMIT 1');
    $s->bind_param('iii',$school,$year,$bim);$s->execute();
    $row=$s->get_result()->fetch_assoc();$s->close();
    return $row?:null;
}
function taca_settings(mysqli $db,int $school,int $year,int $bim):array {
    $default=['deadline_date'=>null,'notice_days_before'=>3,
        'closure_enabled'=>1,'incomplete_enabled'=>1,'admin_edits_enabled'=>1];
    $s=$db->prepare('SELECT deadline_date,notice_days_before,closure_enabled,incomplete_enabled,admin_edits_enabled FROM teacher_alert_settings WHERE school_id=? AND academic_year_id=? AND bimester=? LIMIT 1');
    $s->bind_param('iii',$school,$year,$bim);$s->execute();
    $row=$s->get_result()->fetch_assoc();$s->close();
    return $row?array_merge($default,$row):$default;
}
function taca_user_status_condition(mysqli $db):string {
    $q=$db->query("SHOW COLUMNS FROM users LIKE 'status'");
    return ($q&&$q->num_rows>0)?" AND u.status='Activo'":"";
}
/**
 * Primer render: sólo asignaciones y cierres, sin consultar cada nota.
 * Se retiene el argumento opcional por compatibilidad interna.
 */
function taca_teachers(mysqli $db,int $school,int $year,int $bim,bool $scanMissing=false):array {
    $extra=taca_user_status_condition($db);
    $sql="SELECT u.id recipient_user_id,t.id teacher_id,t.name teacher_name,
                 tc.id teacher_course_id,tc.grado,tc.seccion,ac.level,ac.name course_name,
                 (gpc.id IS NOT NULL) closed
          FROM teacher_courses tc
          INNER JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
               AND t.status='Activo'
          INNER JOIN users u ON u.teacher_id=t.id AND u.school_id=tc.school_id
               AND u.type=2 $extra
          INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
          LEFT JOIN grade_period_closures gpc ON gpc.teacher_course_id=tc.id
               AND gpc.school_id=tc.school_id AND gpc.bimester=?
               AND gpc.status='Cerrado'
          WHERE tc.school_id=? AND tc.academic_year_id=?
          ORDER BY t.name,ac.name,tc.grado,tc.seccion";
    $s=$db->prepare($sql);if(!$s)throw new RuntimeException($db->error);
    $s->bind_param('iii',$bim,$school,$year);$s->execute();
    $result=$s->get_result();$teachers=[];
    while($row=$result->fetch_assoc()){
        $uid=(int)$row['recipient_user_id'];
        if(!isset($teachers[$uid])){
            $teachers[$uid]=['recipient_user_id'=>$uid,'teacher_id'=>(int)$row['teacher_id'],
                'teacher_name'=>$row['teacher_name'],'total'=>0,'closed'=>0,'pending'=>0,
                'incomplete_courses'=>0,'missing_cells'=>0,'pending_courses'=>[],
                'pending_course_ids'=>[]];
        }
        $teachers[$uid]['total']++;
        if((int)$row['closed']===1){
            $teachers[$uid]['closed']++;
        }else{
            $teachers[$uid]['pending']++;
            $teachers[$uid]['pending_courses'][]=
                $row['course_name'].' · '.$row['level'].' · '.$row['grado'].'° '.$row['seccion'];
            $teachers[$uid]['pending_course_ids'][]=(int)$row['teacher_course_id'];
        }
    }
    $s->close();
    return array_values($teachers);
}

/**
 * Segunda fase: una sola consulta agrupada por asignación para todas las
 * calificaciones faltantes del colegio/año/bimestre. Ya no se realizan
 * N consultas pesadas por cada asignación.
 */
function taca_missing_by_course(mysqli $db,int $school,int $year,int $bim):array {
    $extra='';
    $hasStatus=$db->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
    if($hasStatus&&$hasStatus->num_rows>0){
        $extra=" AND (e.status IS NULL OR e.status<>'Anulada')";
    }
    $sql="SELECT tc.id teacher_course_id,COUNT(*) missing_cells
        FROM teacher_courses tc
        INNER JOIN academic_courses ac ON ac.id=tc.course_id
            AND ac.school_id=tc.school_id
        INNER JOIN evaluations e ON e.teacher_course_id=tc.id
            AND e.academic_year_id=tc.academic_year_id
            AND CAST(e.bimestre AS UNSIGNED)=?
        INNER JOIN evaluation_competencias ec ON ec.evaluation_id=e.id
        INNER JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id
            AND gcc.course_id=tc.course_id AND gcc.teacher_id=tc.teacher_id
            AND gcc.academic_year_id=tc.academic_year_id
            AND gcc.is_active=1 AND gcc.percentage>0
        INNER JOIN student st ON st.school_id=tc.school_id
            AND (st.status='Activo' OR st.status IS NULL)
            AND LOWER(TRIM(st.nivel))=LOWER(TRIM(ac.level))
            AND st.grado=tc.grado
            AND (
               st.seccion=tc.seccion OR (
                   UPPER(TRIM(COALESCE(tc.seccion,''))) IN ('U','UNICA','ÚNICA')
                   AND UPPER(TRIM(COALESCE(st.seccion,''))) IN ('','U','UNICA','ÚNICA')
               )
            )
        LEFT JOIN grade_period_closures gpc ON gpc.teacher_course_id=tc.id
            AND gpc.school_id=tc.school_id AND gpc.bimester=?
            AND gpc.status='Cerrado'
        WHERE tc.school_id=? AND tc.academic_year_id=?
            AND gpc.id IS NULL $extra
            AND NOT EXISTS (
                SELECT 1 FROM evaluation_grades eg
                WHERE eg.evaluation_id=e.id AND eg.student_id=st.id
                  AND eg.competencia_id=ec.competencia_id
                  AND eg.grade IS NOT NULL AND TRIM(eg.grade)<>''
            )
        GROUP BY tc.id";
    $s=$db->prepare($sql);
    if(!$s)throw new RuntimeException($db->error);
    $s->bind_param('iiii',$bim,$bim,$school,$year);
    $s->execute();$r=$s->get_result();$items=[];
    while($row=$r->fetch_assoc())$items[(string)(int)$row['teacher_course_id']]=(int)$row['missing_cells'];
    $s->close();
    return $items;
}

/**
 * Carga diferida del historial: primero obtiene 30 eventos del bimestre,
 * luego consulta FCM SOLO para esos IDs. No agrega la tabla histórica entera.
 */
function taca_history(mysqli $db,int $school,int $year,int $bim,int $periodId):array {
    $sql="SELECT ne.id,ne.title,ne.message,ne.source_type,ne.created_at,
                 COALESCE(u.name,'Docente') recipient_name
          FROM notification_events ne
          LEFT JOIN users u ON u.id=ne.recipient_user_id AND u.school_id=ne.school_id
          LEFT JOIN teacher_alert_manual_log ml ON ne.source_type='teacher_manual_reminder'
               AND ne.source_id=ml.id AND ml.school_id=ne.school_id
          WHERE ne.school_id=?
            AND (
                (ne.source_type IN ('teacher_closure_soon','teacher_closure_pending',
                     'teacher_grades_incomplete') AND ne.source_id=?)
                OR (ne.source_type='teacher_manual_reminder'
                     AND ml.academic_year_id=? AND ml.bimester=?)
            )
          ORDER BY ne.id DESC LIMIT 30";
    $s=$db->prepare($sql);if(!$s)throw new RuntimeException($db->error);
    $s->bind_param('iiii',$school,$periodId,$year,$bim);
    $s->execute();$result=$s->get_result();$items=[];$ids=[];
    while($row=$result->fetch_assoc()){
        $row['sent']=0;$row['failed']=0;$row['pending']=0;
        $ids[]=(int)$row['id'];$items[(int)$row['id']]=$row;
    }
    $s->close();
    if($ids && tna_table($db,'web_push_delivery')){
        $in=implode(',',array_map('intval',$ids));
        $sql="SELECT event_id,
                  SUM(status='delivered') sent,
                  SUM(status='failed') failed,
                  SUM(status='retry') pending
              FROM web_push_delivery
              WHERE school_id=? AND event_kind='staff' AND event_id IN ($in)
              GROUP BY event_id";
        $p=$db->prepare($sql);
        if($p){
            $p->bind_param('i',$school);$p->execute();$del=$p->get_result();
            while($row=$del->fetch_assoc()){
                $id=(int)$row['event_id'];
                if(isset($items[$id])){
                    foreach(['sent','failed','pending'] as $k)$items[$id][$k]=(int)$row[$k];
                }
            }
            $p->close();
        }
    }
    return array_values($items);
}

$school=(int)($_SESSION['login_school_id']??0);
$user=(int)($_SESSION['login_id']??0);
if($school<=0||$user<=0)taca_response(['status'=>0,'message'=>'Acceso no autorizado.'],403);
$check=$conn->prepare('SELECT type FROM users WHERE id=? AND school_id=? LIMIT 1');
$check->bind_param('ii',$user,$school);$check->execute();
$account=$check->get_result()->fetch_assoc();$check->close();
if(!$account||(int)$account['type']!==1)
    taca_response(['status'=>0,'message'=>'Esta configuración es exclusiva de Administración.'],403);
// Mostrar los cierres aunque todavía falte configurar los avisos en producción.
// La consulta académica no depende de las tablas auxiliares de recordatorios.
foreach(['academic_periods','grade_period_closures','teacher_courses'] as $table) {
    if(!tna_table($conn,$table)) {
        taca_response(['status'=>0,'migration_required'=>true,
            'message'=>'Falta la tabla académica '.$table.'. Revisa las migraciones de años académicos y cierre bimestral.',
            'missing_table'=>$table],409);
    }
}
$missingAlertTables=[];
foreach(['teacher_alert_settings','teacher_alert_manual_log','notification_events'] as $table) {
    if(!tna_table($conn,$table))$missingAlertTables[]=$table;
}
$alertsReady=empty($missingAlertTables);
$defaultAlertSettings=['deadline_date'=>null,'notice_days_before'=>3,
    'closure_enabled'=>1,'incomplete_enabled'=>1,'admin_edits_enabled'=>1];
$action=(string)($_POST['action']??$_GET['action']??'status');
if($_SERVER['REQUEST_METHOD']==='POST')taca_csrf();
$yearId=(int)($_POST['academic_year_id']??$_GET['academic_year_id']??0);
$bim=(int)($_POST['bimester']??$_GET['bimester']??0);
if($yearId<=0||$bim<1||$bim>4)taca_response(['status'=>0,'message'=>'Año y bimestre inválidos.'],422);
$year=taca_year($conn,$school,$yearId);
$period=taca_period($conn,$school,$yearId,$bim);
if(!$year||!$period)taca_response(['status'=>0,'message'=>'El periodo no pertenece a tu institución.'],404);
// Evita que la consulta agrupada de notas bloquee, por la sesión PHP,
// la bandeja y otras peticiones del mismo administrador.
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try {
    if($action==='status') {
        $settings=$alertsReady?taca_settings($conn,$school,$yearId,$bim):$defaultAlertSettings;
        $globalLock=$conn->prepare('SELECT is_locked FROM bimester_locks WHERE school_id=? AND academic_year_id=? AND bimester=? LIMIT 1');
        if($globalLock) {
            $globalLock->bind_param('iii',$school,$yearId,$bim);
            $globalLock->execute();
            $globalRow=$globalLock->get_result()->fetch_assoc();
            $globalLock->close();
            $period['global_locked']=(int)($globalRow['is_locked']??0);
        }
        $teachers=taca_teachers($conn,$school,$yearId,$bim);
        $totals=['teachers'=>count($teachers),'assignments'=>0,'closed'=>0,
                 'pending'=>0,'incomplete'=>0];
        foreach($teachers as $t) {
            $totals['assignments']+=(int)$t['total'];
            $totals['closed']+=(int)$t['closed'];
            $totals['pending']+=(int)$t['pending'];
            $totals['incomplete']+=(int)$t['incomplete_courses'];
        }
        taca_response(['status'=>1,'year'=>$year,'period'=>$period,'settings'=>$settings,
            'alerts_ready'=>$alertsReady,'alerts_missing_tables'=>$missingAlertTables,
            'effective_deadline'=>$settings['deadline_date']?:$period['end_date'],
            'totals'=>$totals,'teachers'=>$teachers]);
    }
    if($action==='history') {
        if(!$alertsReady)taca_response(['status'=>0,'migration_required'=>true,
            'message'=>'Historial no disponible: instala sql/teacher_alert_admin_control_v1.sql y las tablas de notificaciones.',
            'missing_tables'=>$missingAlertTables],409);
        taca_response(['status'=>1,'history'=>
            taca_history($conn,$school,$yearId,$bim,(int)$period['id'])]);
    }
    if($action==='missing_counts') {
        // Los registros de otros años no usan el padrón activo como base.
        if(($year['status']??'')!=='Activo'||(int)$year['is_active']!==1) {
            taca_response(['status'=>1,'missing_by_course'=>[],'historical'=>true]);
        }
        taca_response(['status'=>1,'missing_by_course'=>
            taca_missing_by_course($conn,$school,$yearId,$bim)]);
    }

    // Las operaciones con efectos requieren siempre las tablas auxiliares.
    if(!$alertsReady)taca_response(['status'=>0,'migration_required'=>true,
        'message'=>'Para configurar o enviar avisos debes instalar sql/teacher_alert_admin_control_v1.sql y las migraciones de notificaciones.',
        'missing_tables'=>$missingAlertTables],409);

    if(($year['status']??'')!=='Activo'||(int)$year['is_active']!==1)
        taca_response(['status'=>0,'message'=>'Solo puede modificar avisos del año activo.'],409);

    if($action==='save_settings') {
        $deadline=trim((string)($_POST['deadline_date']??''));
        if($deadline!=='') {
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$deadline);
            if(!$d||$d->format('Y-m-d')!==$deadline)
                taca_response(['status'=>0,'message'=>'La fecha límite no es válida.'],422);
            // Permitir un plazo después del fin lectivo, pero no indefinidamente.
            $min=$period['start_date']?:$year['start_date'];
            $max=(new DateTimeImmutable($year['end_date']))->modify('+30 days')->format('Y-m-d');
            if($deadline<$min||$deadline>$max)
                taca_response(['status'=>0,'message'=>'La fecha límite debe estar entre el inicio del periodo y 30 días después del fin del año.'],422);
        }
        $days=(int)($_POST['notice_days_before']??3);
        if($days<1||$days>14)taca_response(['status'=>0,'message'=>'El recordatorio puede anticiparse entre 1 y 14 días.'],422);
        $closure=($_POST['closure_enabled']??'0')==='1'?1:0;
        $incomplete=($_POST['incomplete_enabled']??'0')==='1'?1:0;
        $admin=($_POST['admin_edits_enabled']??'0')==='1'?1:0;
        $sql="INSERT INTO teacher_alert_settings
            (school_id,academic_year_id,bimester,deadline_date,notice_days_before,
             closure_enabled,incomplete_enabled,admin_edits_enabled,updated_by)
            VALUES(?,?,?,NULLIF(?,''),?,?,?,?,?)
            ON DUPLICATE KEY UPDATE deadline_date=VALUES(deadline_date),
              notice_days_before=VALUES(notice_days_before),
              closure_enabled=VALUES(closure_enabled),incomplete_enabled=VALUES(incomplete_enabled),
              admin_edits_enabled=VALUES(admin_edits_enabled),updated_by=VALUES(updated_by)";
        $stmt=$conn->prepare($sql);
        $stmt->bind_param('iiisiiiii',$school,$yearId,$bim,$deadline,$days,$closure,$incomplete,$admin,$user);
        if(!$stmt->execute())throw new RuntimeException($stmt->error);
        $stmt->close();
        if(tna_table($conn,'academic_year_audit')) {
            $actionName='teacher_alert_settings_updated';
            $details=json_encode(['bimester'=>$bim,'deadline'=>$deadline?:null,'days_before'=>$days,
                'closure'=>$closure,'incomplete'=>$incomplete,'admin_edits'=>$admin],JSON_UNESCAPED_UNICODE);
            $ip=(string)($_SERVER['REMOTE_ADDR']??'');
            $audit=$conn->prepare('INSERT INTO academic_year_audit(school_id,academic_year_id,user_id,action,details,ip_address) VALUES(?,?,?,?,?,?)');
            if($audit) {
                $audit->bind_param('iiisss',$school,$yearId,$user,$actionName,$details,$ip);
                $audit->execute();$audit->close();
            }
        }
        taca_response(['status'=>1,'message'=>'Configuración de avisos docentes guardada.']);
    }
    if($action==='send_reminder') {
        if((int)$period['is_locked']===1)
            taca_response(['status'=>0,'message'=>'El bimestre está bloqueado; no se permiten recordatorios de cierre.'],409);
        $lockCheck=$conn->prepare('SELECT is_locked FROM bimester_locks WHERE school_id=? AND academic_year_id=? AND bimester=? LIMIT 1');
        if($lockCheck){
            $lockCheck->bind_param('iii',$school,$yearId,$bim);$lockCheck->execute();
            $row=$lockCheck->get_result()->fetch_assoc();$lockCheck->close();
            if($row&&(int)$row['is_locked']===1)
                taca_response(['status'=>0,'message'=>'El bloqueo institucional está activo.'],409);
        }
        $recipient=(int)($_POST['recipient_user_id']??0);
        if($recipient<=0)taca_response(['status'=>0,'message'=>'Selecciona un docente válido.'],422);
        $note=trim((string)($_POST['note']??''));
        if(mb_strlen($note,'UTF-8')>250)
            taca_response(['status'=>0,'message'=>'La observación no puede superar 250 caracteres.'],422);
        $teachers=taca_teachers($conn,$school,$yearId,$bim);
        $target=null;foreach($teachers as $t)if((int)$t['recipient_user_id']===$recipient){$target=$t;break;}
        if(!$target||(int)$target['pending']<1)
            taca_response(['status'=>0,'message'=>'El docente no tiene cursos pendientes o no pertenece al colegio.'],409);
        $teacher=(int)$target['teacher_id'];
        $lockName='taca_manual_'.substr(hash('sha256',"$school:$yearId:$bim:$teacher"),0,39);
        $lock=$conn->prepare('SELECT GET_LOCK(?,5) acquired');
        $lock->bind_param('s',$lockName);$lock->execute();
        $acquired=(int)($lock->get_result()->fetch_assoc()['acquired']??0);$lock->close();
        if($acquired!==1)taca_response(['status'=>0,'message'=>'Ya se está procesando un aviso para este docente.'],409);
        try {
            $last=$conn->prepare('SELECT id,created_at FROM teacher_alert_manual_log WHERE school_id=? AND academic_year_id=? AND bimester=? AND teacher_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 1');
            $last->bind_param('iiii',$school,$yearId,$bim,$teacher);$last->execute();
            $previous=$last->get_result()->fetch_assoc();$last->close();
            if($previous) {
                $unlock=$conn->prepare('SELECT RELEASE_LOCK(?)');
                if($unlock){$unlock->bind_param('s',$lockName);$unlock->execute();$unlock->close();}
                taca_response(['status'=>0,'message'=>'Ya se envió un recordatorio a este docente en las últimas 24 horas.'],409);
            }
            $conn->begin_transaction();
            $log=$conn->prepare('INSERT INTO teacher_alert_manual_log(school_id,academic_year_id,bimester,teacher_id,recipient_user_id,created_by) VALUES(?,?,?,?,?,?)');
            $log->bind_param('iiiiii',$school,$yearId,$bim,$teacher,$recipient,$user);
            if(!$log->execute())throw new RuntimeException($log->error);
            $logId=(int)$log->insert_id;$log->close();
            $title='Recordatorio de cierre · '.$bim.'° bimestre';
            $pending=(int)$target['pending'];$category='Académica';$priority='Normal';
            $body='Administración recuerda que tienes '.$pending.' '.
                ($pending===1?'curso pendiente':'cursos pendientes').
                ' de cierre del '.$bim.'° bimestre.';
            if($note!=='')$body.=' · Observación: '.$note;
            $body=mb_substr($body,0,500,'UTF-8');
            $source='teacher_manual_reminder';
            $event=$conn->prepare('INSERT INTO notification_events(school_id,recipient_user_id,notification_type,priority,title,message,source_type,source_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)');
            $event->bind_param('iisssssii',$school,$recipient,$category,$priority,$title,$body,$source,$logId,$user);
            if(!$event->execute())throw new RuntimeException($event->error);
            $eventId=(int)$event->insert_id;$event->close();
            $up=$conn->prepare('UPDATE teacher_alert_manual_log SET event_id=? WHERE id=? AND school_id=?');
            $up->bind_param('iii',$eventId,$logId,$school);
            if(!$up->execute())throw new RuntimeException($up->error);
            $up->close();$conn->commit();
        } catch(Throwable $e) {
            $conn->rollback();throw $e;
        } finally {
            $unlock=$conn->prepare('SELECT RELEASE_LOCK(?)');
            if($unlock){$unlock->bind_param('s',$lockName);$unlock->execute();$unlock->close();}
        }
        require_once __DIR__.'/includes/web_push_staff_events.php';
        edwp_queue_staff_event($conn,$school,'staff',$eventId);
        taca_response(['status'=>1,'message'=>'Recordatorio registrado para el docente. La entrega al dispositivo depende de FCM.','event_id'=>$eventId]);
    }
    taca_response(['status'=>0,'message'=>'Acción no válida.'],404);
} catch(Throwable $e) {
    error_log('[EduSync teacher alerts admin] '.$e->getMessage());
    taca_response(['status'=>0,'message'=>'No se pudo procesar la solicitud de notificaciones.'],500);
}
