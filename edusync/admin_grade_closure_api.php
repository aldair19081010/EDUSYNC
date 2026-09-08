<?php
ob_start();
date_default_timezone_set('America/Lima');
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
$conn->query("SET time_zone = '-05:00'");
header('Content-Type: application/json; charset=utf-8');

function agca_response(array $data, int $code=200): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function agca_table(mysqli $db,string $name): bool {
    $safe=$db->real_escape_string($name);
    $q=$db->query("SHOW TABLES LIKE '$safe'");
    return $q&&$q->num_rows>0;
}
function agca_col(mysqli $db,string $table,string $col): bool {
    $t=str_replace('`','',$table);$c=$db->real_escape_string($col);
    $q=$db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $q&&$q->num_rows>0;
}
function agca_csrf(): void {
    $sent=(string)($_POST['csrf_token']??'');$saved=(string)($_SESSION['csrf_token']??'');
    if($sent===''||$saved===''||!hash_equals($saved,$sent)) agca_response(['status'=>0,'message'=>'La sesión de seguridad venció. Recarga la página.'],403);
}
function agca_year(mysqli $db,int $school,int $yearId): ?array {
    $s=$db->prepare('SELECT * FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
    $s->bind_param('ii',$yearId,$school);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
}
function agca_assignment(mysqli $db,int $school,int $yearId,int $tcId): ?array {
    $statusCol=agca_col($db,'academic_year','status')?',ay.status year_status':",'' year_status";
    $s=$db->prepare("SELECT tc.id,tc.teacher_id,tc.course_id,tc.academic_year_id,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,ac.name course_name,ac.level,ay.year,ay.is_active$statusCol,COALESCE(t.name,'Docente no disponible') teacher_name FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id LEFT JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id WHERE tc.id=? AND tc.school_id=? AND tc.academic_year_id=? LIMIT 1");
    $s->bind_param('iii',$tcId,$school,$yearId);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
}
function agca_section_sql(mysqli $db,string $section): string {
    $section=trim($section);
    if(in_array(mb_strtoupper($section,'UTF-8'),['U','ÚNICA','UNICA'],true)) return "COALESCE(NULLIF(TRIM(s.seccion),''),'U') IN ('U','u','Única','Unica','única','unica')";
    return "s.seccion='".$db->real_escape_string($section)."'";
}
function agca_closure(mysqli $db,int $school,int $tcId,int $bim): ?array {
    $s=$db->prepare("SELECT gpc.*,COALESCE(u.name,'Usuario') closed_by_name,COALESCE(u.type,0) closed_by_type FROM grade_period_closures gpc LEFT JOIN users u ON u.id=gpc.closed_by AND u.school_id=gpc.school_id WHERE gpc.school_id=? AND gpc.teacher_course_id=? AND gpc.bimester=? LIMIT 1");
    $s->bind_param('iii',$school,$tcId,$bim);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
}
function agca_meta(array $closure): array {
    $mode=((int)($closure['closed_by_type']??0)===1)?'Administrativo':'Docente';$reason='';$exceptional=false;
    $snap=json_decode((string)($closure['closure_snapshot']??''),true);
    if(is_array($snap)&&isset($snap['closure_meta'])&&is_array($snap['closure_meta'])){
        $m=$snap['closure_meta'];$mode=(string)($m['mode']??$mode);$reason=trim((string)($m['reason']??''));$exceptional=!empty($m['exceptional']);
    }
    return ['mode'=>$mode,'reason'=>$reason,'exceptional'=>$exceptional];
}
function agca_audit(mysqli $db,int $school,int $yearId,int $tcId,int $teacherId,int $bim,int $userId,string $action,array $details): void {
    if(agca_table($db,'grade_period_audit_log')){
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$ip=$_SERVER['REMOTE_ADDR']??null;
        $s=$db->prepare('INSERT INTO grade_period_audit_log(school_id,academic_year_id,teacher_course_id,teacher_id,bimester,user_id,action,details,ip_address) VALUES(?,?,?,?,?,?,?,?,?)');
        if($s){$s->bind_param('iiiiiisss',$school,$yearId,$tcId,$teacherId,$bim,$userId,$action,$json,$ip);$s->execute();$s->close();}
    }
    if(agca_table($db,'academic_year_audit')){
        $json=json_encode($details+['teacher_course_id'=>$tcId,'teacher_id'=>$teacherId,'bimester'=>$bim],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$ip=$_SERVER['REMOTE_ADDR']??null;
        $s=$db->prepare('INSERT INTO academic_year_audit(school_id,academic_year_id,user_id,action,details,ip_address) VALUES(?,?,?,?,?,?)');
        if($s){$s->bind_param('iiisss',$school,$yearId,$userId,$action,$json,$ip);$s->execute();$s->close();}
    }
}
function agca_validate(mysqli $db,int $school,array $a,int $bim): array {
    $tc=(int)$a['id'];$teacher=(int)$a['teacher_id'];$year=(int)$a['academic_year_id'];$course=(int)$a['course_id'];$issues=[];
    if((int)$a['is_active']!==1||in_array((string)($a['year_status']??''),['Cerrado','Archivado'],true)) $issues[]='El año académico no está activo.';

    $comps=[];$pct=0.0;
    $s=$db->prepare('SELECT id,name,percentage FROM general_course_competencies WHERE course_id=? AND teacher_id=? AND academic_year_id=? AND is_active=1 ORDER BY id');
    $s->bind_param('iii',$course,$teacher,$year);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc()){if((float)$x['percentage']<=0)continue;$x['id']=(int)$x['id'];$x['percentage']=(float)$x['percentage'];$comps[$x['id']]=$x;$pct+=$x['percentage'];}$s->close();
    if(!$comps)$issues[]='No hay competencias activas con porcentaje mayor a 0%.';
    if(abs($pct-100)>0.01)$issues[]='Los porcentajes de las competencias suman '.number_format($pct,2).'%. Deben sumar 100%.';

    $status=agca_col($db,'evaluations','status')?" AND (e.status IS NULL OR e.status<>'Anulada')":'';$evals=[];
    $s=$db->prepare("SELECT e.id,e.title,e.type,e.created_at FROM evaluations e WHERE e.teacher_course_id=? AND e.teacher_id=? AND e.academic_year_id=? AND CAST(e.bimestre AS UNSIGNED)=?$status ORDER BY e.id");
    $s->bind_param('iiii',$tc,$teacher,$year,$bim);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc()){$x['id']=(int)$x['id'];$x['competencies']=[];$evals[$x['id']]=$x;}$s->close();
    if(!$evals)$issues[]='No hay evaluaciones activas registradas en el bimestre.';

    $compEval=array_fill_keys(array_keys($comps),0);
    if($evals){$ids=implode(',',array_map('intval',array_keys($evals)));$q=$db->query("SELECT ec.evaluation_id,ec.competencia_id,gcc.name,gcc.percentage,gcc.is_active FROM evaluation_competencias ec LEFT JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id WHERE ec.evaluation_id IN ($ids)");while($q&&($x=$q->fetch_assoc())){$eid=(int)$x['evaluation_id'];$cid=(int)$x['competencia_id'];if(!isset($evals[$eid]))continue;$evals[$eid]['competencies'][]=['id'=>$cid,'name'=>$x['name']?:'Competencia no disponible','percentage'=>(float)($x['percentage']??0),'active'=>(int)($x['is_active']??0)];if(isset($compEval[$cid]))$compEval[$cid]++;}}
    foreach($comps as $cid=>$comp)if(($compEval[$cid]??0)<1)$issues[]='La competencia “'.$comp['name'].'” no tiene evaluación activa.';
    foreach($evals as $ev){if(!$ev['competencies']){$issues[]='La evaluación “'.$ev['title'].'” no tiene competencia asociada.';continue;}foreach($ev['competencies'] as $link)if(!isset($comps[$link['id']]))$issues[]='La evaluación “'.$ev['title'].'” usa una competencia inactiva o con porcentaje 0%.';}

    $level=$db->real_escape_string(mb_strtolower((string)$a['level'],'UTF-8'));$grade=$db->real_escape_string((string)$a['grado']);$section=agca_section_sql($db,(string)$a['seccion']);$students=[];
    $q=$db->query("SELECT s.id,s.id_no,s.name FROM student s WHERE s.school_id=$school AND (s.status='Activo' OR s.status IS NULL) AND LOWER(s.nivel)='$level' AND s.grado='$grade' AND $section ORDER BY s.name");while($q&&($x=$q->fetch_assoc()))$students[(int)$x['id']]=['id'=>(int)$x['id'],'id_no'=>$x['id_no'],'name'=>$x['name']];
    if(!$students)$issues[]='No hay estudiantes activos en el aula.';

    $expected=0;$completed=0;
    if($evals&&$students){$eids=implode(',',array_map('intval',array_keys($evals)));$sids=implode(',',array_map('intval',array_keys($students)));$map=[];$q=$db->query("SELECT evaluation_id,student_id,competencia_id FROM evaluation_grades WHERE evaluation_id IN ($eids) AND student_id IN ($sids) AND grade IS NOT NULL AND TRIM(grade)<>''");while($q&&($x=$q->fetch_assoc()))$map[(int)$x['evaluation_id']][(int)$x['competencia_id']][(int)$x['student_id']]=true;foreach($evals as $ev){foreach($ev['competencies'] as $link){$cid=(int)$link['id'];if(!isset($comps[$cid]))continue;$missing=0;foreach($students as $sid=>$st){$expected++;if(!empty($map[$ev['id']][$cid][$sid]))$completed++;else$missing++;}if($missing)$issues[]='La evaluación “'.$ev['title'].'” tiene '.$missing.' estudiante(s) sin nota en “'.$link['name'].'”.';}}}
    $issues=array_values(array_unique($issues));$progress=$expected?round(($completed/$expected)*100,1):0;
    $snapshot=['generated_at'=>date('Y-m-d H:i:s'),'assignment'=>['teacher_course_id'=>$tc,'teacher_id'=>$teacher,'teacher_name'=>$a['teacher_name'],'course_id'=>$course,'course_name'=>$a['course_name'],'level'=>$a['level'],'grado'=>$a['grado'],'seccion'=>$a['seccion'],'academic_year_id'=>$year,'year'=>$a['year'],'bimester'=>$bim],'competencies'=>array_values($comps),'evaluations'=>array_values($evals),'students'=>array_values($students),'summary'=>['percentage_total'=>round($pct,2),'competencies'=>count($comps),'evaluations'=>count($evals),'students'=>count($students),'expected_grade_cells'=>$expected,'completed_grade_cells'=>$completed,'progress'=>$progress]];
    return ['ready'=>count($issues)===0,'issues'=>$issues,'summary'=>$snapshot['summary'],'snapshot'=>$snapshot];
}
function agca_notify(mysqli $db,int $school,array $a,int $bim,int $closureId,int $userId,bool $exceptional,string $reason): void {
    if(!agca_table($db,'notification_events'))return;$teacher=(int)$a['teacher_id'];$status=agca_col($db,'users','status')?" AND (status='Activo' OR status IS NULL)":'';
    $s=$db->prepare("SELECT id FROM users WHERE school_id=? AND teacher_id=? AND type=2$status ORDER BY id LIMIT 1");if(!$s)return;$s->bind_param('ii',$school,$teacher);$s->execute();$u=$s->get_result()->fetch_assoc();$s->close();if(!$u)return;
    $recipient=(int)$u['id'];$type='Académica';$priority=$exceptional?'Alta':'Normal';$title=$exceptional?'Cierre administrativo excepcional de notas':'Notas cerradas por Administración';$message=$a['course_name'].' · '.$a['grado'].'° '.$a['seccion'].' · '.$bim.'° Bimestre'.($exceptional&&$reason!==''?' · Motivo: '.$reason:'');$sourceType='grade_period_closed';$sourceId=$closureId;
    $e=$db->prepare('INSERT INTO notification_events(school_id,recipient_user_id,notification_type,priority,title,message,source_type,source_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)');if($e){$e->bind_param('iisssssii',$school,$recipient,$type,$priority,$title,$message,$sourceType,$sourceId,$userId);$e->execute();$e->close();}
}
function agca_close_one(mysqli $db,int $school,int $yearId,int $tcId,int $bim,int $userId,bool $force,string $reason): array {
    $a=agca_assignment($db,$school,$yearId,$tcId);if(!$a)return['ok'=>false,'code'=>'invalid','message'=>'La asignación no existe.'];$closure=agca_closure($db,$school,$tcId,$bim);if($closure&&($closure['status']??'')==='Cerrado')return['ok'=>false,'code'=>'closed','message'=>'La asignación ya está cerrada.'];
    $v=agca_validate($db,$school,$a,$bim);if(!$v['ready']&&!$force)return['ok'=>false,'code'=>'incomplete','message'=>'La asignación todavía tiene pendientes.','validation'=>$v];if(!$v['ready']&&$force&&mb_strlen($reason,'UTF-8')<5)return['ok'=>false,'code'=>'reason','message'=>'Indica un motivo de al menos 5 caracteres para el cierre excepcional.','validation'=>$v];
    $mode=$v['ready']?'Administrativo':'Administrativo excepcional';$snap=$v['snapshot'];$snap['closure_meta']=['mode'=>$mode,'exceptional'=>!$v['ready'],'reason'=>$reason,'closed_by_user_id'=>$userId,'closed_at'=>date('Y-m-d H:i:s')];$json=json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$teacher=(int)$a['teacher_id'];
    $s=$db->prepare("INSERT INTO grade_period_closures(school_id,academic_year_id,teacher_course_id,teacher_id,bimester,status,closed_by,closed_at,closure_snapshot,closure_version) VALUES(?,?,?,?,?,'Cerrado',?,NOW(),?,1) ON DUPLICATE KEY UPDATE academic_year_id=VALUES(academic_year_id),teacher_id=VALUES(teacher_id),status='Cerrado',closed_by=VALUES(closed_by),closed_at=NOW(),closure_snapshot=VALUES(closure_snapshot),closure_version=closure_version+1");$s->bind_param('iiiiiis',$school,$yearId,$tcId,$teacher,$bim,$userId,$json);if(!$s->execute())throw new RuntimeException($s->error);$s->close();$closure=agca_closure($db,$school,$tcId,$bim);$closureId=(int)($closure['id']??0);
    $details=['mode'=>$mode,'exceptional'=>!$v['ready'],'reason'=>$reason,'teacher_name'=>$a['teacher_name'],'course_name'=>$a['course_name'],'level'=>$a['level'],'grado'=>$a['grado'],'seccion'=>$a['seccion'],'validation_summary'=>$v['summary'],'issues'=>$v['issues']];agca_audit($db,$school,$yearId,$tcId,$teacher,$bim,$userId,$v['ready']?'period_closed_by_admin':'period_closed_exceptionally_by_admin',$details);agca_notify($db,$school,$a,$bim,$closureId,$userId,!$v['ready'],$reason);
    return['ok'=>true,'mode'=>$mode,'closure_id'=>$closureId,'assignment'=>$a,'validation'=>$v];
}

$school=(int)($_SESSION['login_school_id']??0);$userId=(int)($_SESSION['login_id']??0);if(!$school||!$userId)agca_response(['status'=>0,'message'=>'No autorizado.'],403);
$s=$conn->prepare('SELECT type FROM users WHERE id=? AND school_id=? LIMIT 1');$s->bind_param('ii',$userId,$school);$s->execute();$u=$s->get_result()->fetch_assoc();$s->close();if(!$u||(int)$u['type']!==1)agca_response(['status'=>0,'message'=>'Solo Administración puede realizar cierres administrativos.'],403);
foreach(['grade_period_closures','grade_period_audit_log','teacher_courses','academic_courses','general_course_competencies','evaluations','evaluation_competencias','evaluation_grades','student'] as $t)if(!agca_table($conn,$t))agca_response(['status'=>0,'migration_required'=>true,'message'=>'Ejecuta sql/grade_closure_upgrade.sql antes de usar el cierre administrativo.','missing_table'=>$t],409);
$action=(string)($_GET['action']??$_POST['action']??'closure_info');if($_SERVER['REQUEST_METHOD']==='POST')agca_csrf();

try{
    if($action==='closure_info'){
        $year=(int)($_GET['academic_year_id']??0);$bim=(int)($_GET['bimester']??0);if($year<=0||$bim<1||$bim>4)agca_response(['status'=>0,'message'=>'Año o bimestre inválido.']);$items=[];$s=$conn->prepare("SELECT gpc.*,COALESCE(u.name,'Usuario') closed_by_name,COALESCE(u.type,0) closed_by_type FROM grade_period_closures gpc LEFT JOIN users u ON u.id=gpc.closed_by AND u.school_id=gpc.school_id WHERE gpc.school_id=? AND gpc.academic_year_id=? AND gpc.bimester=?");$s->bind_param('iii',$school,$year,$bim);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc()){$m=agca_meta($x);$items[(string)(int)$x['teacher_course_id']]=['closure_id'=>(int)$x['id'],'status'=>$x['status'],'closed_at'=>$x['closed_at'],'closed_by_name'=>$x['closed_by_name'],'closed_by_type'=>(int)$x['closed_by_type'],'mode'=>$m['mode'],'reason'=>$m['reason'],'exceptional'=>$m['exceptional'],'closure_version'=>(int)$x['closure_version']];}$s->close();agca_response(['status'=>1,'items'=>$items]);
    }
    if($action==='close_assignment'){
        $year=(int)($_POST['academic_year_id']??0);$tc=(int)($_POST['teacher_course_id']??0);$bim=(int)($_POST['bimester']??0);$force=(int)($_POST['force']??0)===1;$reason=trim((string)($_POST['reason']??''));$y=agca_year($conn,$school,$year);if(!$y||($y['status']??'')!=='Activo'||$tc<=0||$bim<1||$bim>4)agca_response(['status'=>0,'message'=>'El año, asignación o bimestre no es válido.']);$conn->begin_transaction();try{$res=agca_close_one($conn,$school,$year,$tc,$bim,$userId,$force,$reason);if(!$res['ok']){$conn->rollback();if(($res['code']??'')==='incomplete')agca_response(['status'=>2,'needs_exceptional'=>true,'message'=>$res['message'],'validation'=>['issues'=>$res['validation']['issues'],'summary'=>$res['validation']['summary']]]);agca_response(['status'=>0,'message'=>$res['message'],'code'=>$res['code']??'error']);}$conn->commit();agca_response(['status'=>1,'message'=>$res['mode']==='Administrativo excepcional'?'Cierre administrativo excepcional registrado.':'Asignación cerrada por Administración.','mode'=>$res['mode'],'closure_id'=>$res['closure_id']]);}catch(Throwable $e){$conn->rollback();throw $e;}
    }
    if($action==='close_bulk'){
        $year=(int)($_POST['academic_year_id']??0);$bim=(int)($_POST['bimester']??0);$raw=json_decode((string)($_POST['teacher_course_ids']??'[]'),true);if(!is_array($raw))$raw=[];$ids=array_values(array_unique(array_filter(array_map('intval',$raw))));$y=agca_year($conn,$school,$year);if(!$y||($y['status']??'')!=='Activo'||$bim<1||$bim>4||!$ids)agca_response(['status'=>0,'message'=>'Selecciona asignaciones válidas de un bimestre activo.']);if(count($ids)>500)agca_response(['status'=>0,'message'=>'Solo se pueden procesar hasta 500 asignaciones por operación.']);$closed=[];$skipped=[];$conn->begin_transaction();try{foreach($ids as $id){$res=agca_close_one($conn,$school,$year,$id,$bim,$userId,false,'');if($res['ok'])$closed[]=['teacher_course_id'=>$id,'course_name'=>$res['assignment']['course_name'],'teacher_name'=>$res['assignment']['teacher_name']];else$skipped[]=['teacher_course_id'=>$id,'reason'=>$res['message'],'code'=>$res['code']??'error'];}agca_audit($conn,$school,$year,0,0,$bim,$userId,'bulk_ready_periods_closed_by_admin',['requested'=>count($ids),'closed'=>count($closed),'skipped'=>count($skipped),'closed_items'=>$closed,'skipped_items'=>$skipped]);$conn->commit();agca_response(['status'=>1,'message'=>count($closed).' asignación(es) cerrada(s). '.count($skipped).' omitida(s).','closed_count'=>count($closed),'skipped_count'=>count($skipped),'closed'=>$closed,'skipped'=>$skipped]);}catch(Throwable $e){$conn->rollback();throw $e;}
    }
    agca_response(['status'=>0,'message'=>'Acción no válida.'],404);
}catch(Throwable $e){error_log('[admin_grade_closure] '.$e->getMessage());agca_response(['status'=>0,'message'=>'No se pudo completar el cierre administrativo.','detail'=>$e->getMessage()],500);}
