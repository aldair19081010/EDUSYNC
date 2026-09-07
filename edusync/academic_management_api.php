<?php
ob_start();
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
$action = $_GET['action'] ?? '';

function amResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function amHasColumn($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    try {
        $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $q && $q->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function amCanWrite($conn, $userId) {
    $stmt = $conn->prepare('SELECT type, is_director FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && (int)$row['type'] === 1;
}
function amAudit($conn, $schoolId, $yearId, $userId, $type, $entityId, $action, $details = []) {
    if (!amHasColumn($conn, 'academic_management_audit', 'id')) return;
    $json = json_encode($details, JSON_UNESCAPED_UNICODE);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $conn->prepare('INSERT INTO academic_management_audit (school_id, academic_year_id, user_id, entity_type, entity_id, action, details, ip_address) VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, NULLIF(?,0), ?, ?, ?)');
    if ($stmt) {
        $stmt->bind_param('iiisisss', $schoolId, $yearId, $userId, $type, $entityId, $action, $json, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
function amEnsureYearWritable($conn, $schoolId, $yearId) {
    $statusSelect = amHasColumn($conn, 'academic_year', 'status') ? ', status' : '';
    $stmt=$conn->prepare("SELECT end_date$statusSelect FROM academic_year WHERE id=? AND school_id=? LIMIT 1");
    $stmt->bind_param('ii',$yearId,$schoolId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(isset($row['status']) && in_array($row['status'], ['Cerrado','Archivado'], true)) amResponse(['status'=>0,'message'=>'El año académico está cerrado y su estructura no puede modificarse.'],409);
    if(!$row) amResponse(['status'=>0,'message'=>'Año académico inválido.']);
    if(!empty($row['end_date']) && $row['end_date'] < date('Y-m-d')) amResponse(['status'=>0,'message'=>'El año académico está cerrado y su estructura no puede modificarse.'],409);
}

if (!$schoolId || !$userId) amResponse(['status' => 0, 'message' => 'Sesión inválida.'], 401);
$upgraded = amHasColumn($conn, 'academic_courses', 'academic_year_id') && amHasColumn($conn, 'academic_courses', 'grades') && amHasColumn($conn, 'academic_courses', 'course_status');

if ($action === 'bootstrap') {
    $years = [];
    $q = $conn->query("SELECT id, year, description, is_active, start_date, end_date FROM academic_year WHERE school_id=$schoolId ORDER BY year DESC");
    while ($q && ($row = $q->fetch_assoc())) $years[] = $row;
    amResponse(['status' => 1, 'upgraded' => $upgraded, 'years' => $years]);
}

if (!$upgraded) amResponse(['status' => 0, 'migration_required' => true, 'message' => 'Ejecuta sql/academic_management_upgrade.sql antes de utilizar la nueva gestión académica.'], 409);

if ($action === 'list') {
    $yearId = (int)($_GET['academic_year_id'] ?? 0);
    $level = trim($_GET['level'] ?? '');
    $grade = trim($_GET['grade'] ?? '');
    $status = trim($_GET['status'] ?? 'all');
    $search = trim($_GET['search'] ?? '');
    $where = ['ac.school_id = ?']; $types = 'i'; $params = [$schoolId];
    if ($yearId) { $where[] = 'ac.academic_year_id = ?'; $types .= 'i'; $params[] = $yearId; }
    if ($level !== '') { $where[] = 'ac.level = ?'; $types .= 's'; $params[] = $level; }
    if ($grade !== '') { $where[] = "FIND_IN_SET(?, REPLACE(COALESCE(ac.grades,''), ' ', '')) > 0"; $types .= 's'; $params[] = str_replace(' ', '', $grade); }
    if ($status === 'active') $where[] = "ac.course_status = 'Activo'";
    if ($status === 'suspended') $where[] = "ac.course_status = 'Suspendido'";
    if ($status === 'inactive') $where[] = "ac.course_status = 'Inactivo'";
    if ($search !== '') { $where[] = '(ac.name LIKE ? OR ac.course_code LIKE ? OR a.name LIKE ?)'; $like = "%$search%"; $types .= 'sss'; array_push($params, $like, $like, $like); }
    $sql = "SELECT ac.*, a.name area_name, a.color area_color,
            (SELECT COUNT(*) FROM general_course_competencies gcc WHERE gcc.course_id=ac.id AND (gcc.academic_year_id=ac.academic_year_id OR gcc.academic_year_id IS NULL) AND gcc.is_active=1) competency_count,
            (SELECT COUNT(*) FROM teacher_courses tc WHERE tc.course_id=ac.id AND tc.academic_year_id=ac.academic_year_id) teacher_assignment_count
            FROM academic_courses ac LEFT JOIN areas a ON a.id=ac.area_id AND a.school_id=ac.school_id
            WHERE " . implode(' AND ', $where) . ' ORDER BY ac.level, ac.display_order, a.name, ac.name';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params); $stmt->execute(); $res = $stmt->get_result(); $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    $totalAreas = 0; $active = 0; $withoutArea = 0; $withoutCompetencies = 0;
    $areaIds = [];
    foreach ($rows as $row) {
        if ($row['area_id']) $areaIds[(int)$row['area_id']] = true; else $withoutArea++;
        if (($row['course_status'] ?? 'Activo') === 'Activo') $active++;
        if ((int)$row['competency_count'] === 0) $withoutCompetencies++;
    }
    $totalAreas = count($areaIds);
    amResponse(['status'=>1, 'courses'=>$rows, 'stats'=>['areas'=>$totalAreas,'courses'=>count($rows),'active'=>$active,'without_area'=>$withoutArea,'without_competencies'=>$withoutCompetencies]]);
}

if ($action === 'areas') {
    $yearId = (int)($_GET['academic_year_id'] ?? 0);
    $sql = 'SELECT id,name,color,is_active FROM areas WHERE school_id=? AND (academic_year_id=? OR academic_year_id IS NULL) ORDER BY name';
    $stmt=$conn->prepare($sql); $stmt->bind_param('ii',$schoolId,$yearId); $stmt->execute(); $res=$stmt->get_result(); $rows=[];
    while($row=$res->fetch_assoc()) $rows[]=$row; $stmt->close();
    amResponse(['status'=>1,'areas'=>$rows]);
}

if ($action === 'audit') {
    if (!amHasColumn($conn, 'academic_management_audit', 'id')) amResponse(['status'=>1,'logs'=>[]]);
    $yearId=(int)($_GET['academic_year_id']??0);$stmt=$conn->prepare("SELECT l.*,COALESCE(u.name,'Sistema') user_name FROM academic_management_audit l LEFT JOIN users u ON u.id=l.user_id WHERE l.school_id=? AND (l.academic_year_id=? OR ?=0) ORDER BY l.created_at DESC LIMIT 100");
    $stmt->bind_param('iii',$schoolId,$yearId,$yearId);$stmt->execute();$res=$stmt->get_result();$logs=[];while($row=$res->fetch_assoc())$logs[]=$row;$stmt->close();amResponse(['status'=>1,'logs'=>$logs]);
}

if ($action === 'competencies') {
    $courseId=(int)($_GET['course_id']??0);$yearId=(int)($_GET['academic_year_id']??0);
    $courseStmt=$conn->prepare('SELECT id,name FROM academic_courses WHERE id=? AND school_id=? AND academic_year_id=? LIMIT 1');$courseStmt->bind_param('iii',$courseId,$schoolId,$yearId);$courseStmt->execute();$course=$courseStmt->get_result()->fetch_assoc();$courseStmt->close();if(!$course)amResponse(['status'=>0,'message'=>'Curso inválido.']);
    $teachers=[];$stmt=$conn->prepare("SELECT DISTINCT t.id,t.name FROM teacher_courses tc INNER JOIN teacher t ON t.id=tc.teacher_id WHERE tc.course_id=? AND tc.academic_year_id=? AND tc.school_id=? ORDER BY t.name");$stmt->bind_param('iii',$courseId,$yearId,$schoolId);$stmt->execute();$res=$stmt->get_result();while($row=$res->fetch_assoc())$teachers[]=$row;$stmt->close();
    $items=[];$stmt=$conn->prepare("SELECT gcc.id,gcc.teacher_id,gcc.name,gcc.percentage,gcc.is_active,t.name teacher_name,(SELECT COUNT(*) FROM evaluation_competencias ec WHERE ec.competencia_id=gcc.id) usage_count FROM general_course_competencies gcc INNER JOIN teacher t ON t.id=gcc.teacher_id AND t.school_id=? WHERE gcc.course_id=? AND gcc.academic_year_id=? ORDER BY t.name,gcc.is_active DESC,gcc.name");$stmt->bind_param('iii',$schoolId,$courseId,$yearId);$stmt->execute();$res=$stmt->get_result();while($row=$res->fetch_assoc())$items[]=$row;$stmt->close();
    amResponse(['status'=>1,'course'=>$course,'teachers'=>$teachers,'competencies'=>$items]);
}

if ($action === 'impact') {
    $ids=json_decode($_POST['ids']??'[]',true);$ids=array_values(array_unique(array_filter(array_map('intval',is_array($ids)?$ids:[]))));$yearId=(int)($_POST['academic_year_id']??0);
    if(!$ids) amResponse(['status'=>0,'message'=>'Selecciona al menos un curso.']);
    $ph=implode(',',array_fill(0,count($ids),'?'));$types=str_repeat('i',count($ids)).'ii';$params=[...$ids,$schoolId,$yearId];
    $sql="SELECT COUNT(DISTINCT tc.id) assignments,COUNT(DISTINCT tc.teacher_id) teachers,COUNT(DISTINCT gcc.id) competencies,COUNT(DISTINCT e.id) evaluations,COUNT(DISTINCT eg.id) grades
          FROM academic_courses ac LEFT JOIN teacher_courses tc ON tc.course_id=ac.id AND tc.academic_year_id=?
          LEFT JOIN general_course_competencies gcc ON gcc.course_id=ac.id AND (gcc.academic_year_id=? OR gcc.academic_year_id IS NULL)
          LEFT JOIN evaluations e ON e.teacher_course_id=tc.id LEFT JOIN evaluation_grades eg ON eg.evaluation_id=e.id
          WHERE ac.id IN ($ph) AND ac.school_id=?";
    $stmt=$conn->prepare($sql);$bindTypes='ii'.str_repeat('i',count($ids)).'i';$bind=[$yearId,$yearId,...$ids,$schoolId];$stmt->bind_param($bindTypes,...$bind);$stmt->execute();$impact=$stmt->get_result()->fetch_assoc();$stmt->close();amResponse(['status'=>1,'impact'=>$impact]);
}

$writeActions = ['bulk','copy_year','save_area','save_course','save_competency','toggle_competency'];
if (in_array($action, $writeActions, true)) {
    if (!amCanWrite($conn, $userId)) amResponse(['status'=>0,'message'=>'No tienes permisos para modificar la gestión académica.'],403);
    $token = $_POST['csrf_token'] ?? ''; $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$sessionToken || !$token || !hash_equals($sessionToken, $token)) amResponse(['status'=>0,'message'=>'Solicitud no autorizada. Recarga la página.'],403);
}

if ($action === 'save_competency') {
    $id=(int)($_POST['id']??0);$courseId=(int)($_POST['course_id']??0);$yearId=(int)($_POST['academic_year_id']??0);$teacherId=(int)($_POST['teacher_id']??0);$name=trim($_POST['name']??'');$percentage=(float)($_POST['percentage']??0);
    amEnsureYearWritable($conn,$schoolId,$yearId);if(!$courseId||!$teacherId||$name===''||$percentage<0||$percentage>100)amResponse(['status'=>0,'message'=>'Completa docente, nombre y un porcentaje entre 0 y 100.']);
    $assignment=$conn->prepare('SELECT tc.id FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id WHERE tc.teacher_id=? AND tc.course_id=? AND tc.academic_year_id=? AND tc.school_id=? AND ac.school_id=? LIMIT 1');$assignment->bind_param('iiiii',$teacherId,$courseId,$yearId,$schoolId,$schoolId);$assignment->execute();if(!$assignment->get_result()->fetch_assoc())amResponse(['status'=>0,'message'=>'El docente no está asignado a este curso en el año seleccionado.']);$assignment->close();
    $dup=$conn->prepare('SELECT id FROM general_course_competencies WHERE course_id=? AND teacher_id=? AND academic_year_id=? AND name=? AND id<>? LIMIT 1');$dup->bind_param('iiisi',$courseId,$teacherId,$yearId,$name,$id);$dup->execute();if($dup->get_result()->fetch_assoc())amResponse(['status'=>0,'message'=>'Ese docente ya tiene una competencia con el mismo nombre.']);$dup->close();
    $sum=$conn->prepare('SELECT COALESCE(SUM(percentage),0) total FROM general_course_competencies WHERE course_id=? AND teacher_id=? AND academic_year_id=? AND is_active=1 AND id<>?');$sum->bind_param('iiii',$courseId,$teacherId,$yearId,$id);$sum->execute();$currentTotal=(float)$sum->get_result()->fetch_assoc()['total'];$sum->close();if($currentTotal+$percentage>100.0001)amResponse(['status'=>0,'message'=>'El porcentaje total del docente superaría el 100%. Actualmente tiene '.number_format($currentTotal,2).'% asignado.']);
    if($id){$stmt=$conn->prepare('UPDATE general_course_competencies SET name=?,percentage=?,is_active=1 WHERE id=? AND course_id=? AND teacher_id=? AND academic_year_id=?');$stmt->bind_param('sdiiii',$name,$percentage,$id,$courseId,$teacherId,$yearId);$audit='competency_updated';}
    else{$stmt=$conn->prepare('INSERT INTO general_course_competencies (course_id,teacher_id,academic_year_id,name,percentage,is_active) VALUES (?,?,?,?,?,1)');$stmt->bind_param('iiisd',$courseId,$teacherId,$yearId,$name,$percentage);$audit='competency_created';}
    if(!$stmt->execute())amResponse(['status'=>0,'message'=>$stmt->error]);if(!$id)$id=$stmt->insert_id;$stmt->close();amAudit($conn,$schoolId,$yearId,$userId,'competency',$id,$audit,['course_id'=>$courseId,'teacher_id'=>$teacherId,'name'=>$name,'percentage'=>$percentage]);amResponse(['status'=>1,'message'=>'Competencia guardada correctamente.']);
}

if ($action === 'toggle_competency') {
    $id=(int)($_POST['id']??0);$yearId=(int)($_POST['academic_year_id']??0);$active=(int)($_POST['is_active']??0);amEnsureYearWritable($conn,$schoolId,$yearId);
    if($active){$check=$conn->prepare('SELECT gcc.course_id,gcc.teacher_id,gcc.percentage FROM general_course_competencies gcc INNER JOIN academic_courses ac ON ac.id=gcc.course_id WHERE gcc.id=? AND gcc.academic_year_id=? AND ac.school_id=?');$check->bind_param('iii',$id,$yearId,$schoolId);$check->execute();$row=$check->get_result()->fetch_assoc();$check->close();if(!$row)amResponse(['status'=>0,'message'=>'Competencia inválida.']);$toggleCourseId=(int)$row['course_id'];$toggleTeacherId=(int)$row['teacher_id'];$sum=$conn->prepare('SELECT COALESCE(SUM(percentage),0) total FROM general_course_competencies WHERE course_id=? AND teacher_id=? AND academic_year_id=? AND is_active=1 AND id<>?');$sum->bind_param('iiii',$toggleCourseId,$toggleTeacherId,$yearId,$id);$sum->execute();$total=(float)$sum->get_result()->fetch_assoc()['total'];$sum->close();if($total+(float)$row['percentage']>100.0001)amResponse(['status'=>0,'message'=>'No puede reactivarse porque el porcentaje total superaría el 100%.']);}
    $stmt=$conn->prepare('UPDATE general_course_competencies gcc INNER JOIN academic_courses ac ON ac.id=gcc.course_id SET gcc.is_active=? WHERE gcc.id=? AND gcc.academic_year_id=? AND ac.school_id=?');$stmt->bind_param('iiii',$active,$id,$yearId,$schoolId);if(!$stmt->execute()||$stmt->affected_rows<1)amResponse(['status'=>0,'message'=>'No se pudo actualizar la competencia.']);$stmt->close();amAudit($conn,$schoolId,$yearId,$userId,'competency',$id,$active?'competency_activated':'competency_deactivated');amResponse(['status'=>1,'message'=>$active?'Competencia reactivada.':'Competencia desactivada; su historial se conserva.']);
}

if ($action === 'save_area') {
    $id=(int)($_POST['id']??0); $yearId=(int)($_POST['academic_year_id']??0); $name=trim($_POST['name']??'');
    $description=trim($_POST['description']??''); $color=trim($_POST['color']??'#4e73df'); $active=(int)($_POST['is_active']??1);
    if(!$yearId||$name==='') amResponse(['status'=>0,'message'=>'El año y el nombre del área son obligatorios.']);
    amEnsureYearWritable($conn,$schoolId,$yearId);
    if(!preg_match('/^#[0-9a-fA-F]{6}$/',$color)) $color='#4e73df';
    $year=$conn->prepare('SELECT id FROM academic_year WHERE id=? AND school_id=?'); $year->bind_param('ii',$yearId,$schoolId); $year->execute();
    if(!$year->get_result()->fetch_assoc()) amResponse(['status'=>0,'message'=>'Año académico inválido.']); $year->close();
    $dup=$conn->prepare('SELECT id FROM areas WHERE school_id=? AND academic_year_id=? AND name=? AND id<>?'); $dup->bind_param('iisi',$schoolId,$yearId,$name,$id); $dup->execute();
    if($dup->get_result()->fetch_assoc()) amResponse(['status'=>0,'message'=>'Ya existe un área con ese nombre en el año seleccionado.']); $dup->close();
    if($id){$stmt=$conn->prepare('UPDATE areas SET name=?,description=?,color=?,is_active=? WHERE id=? AND school_id=?');$stmt->bind_param('sssiii',$name,$description,$color,$active,$id,$schoolId);$audit='update';}
    else{$stmt=$conn->prepare('INSERT INTO areas (school_id,academic_year_id,name,description,color,is_active) VALUES (?,?,?,?,?,?)');$stmt->bind_param('iisssi',$schoolId,$yearId,$name,$description,$color,$active);$audit='create';}
    if(!$stmt->execute()) amResponse(['status'=>0,'message'=>$stmt->error]); if(!$id)$id=$stmt->insert_id;$stmt->close();amAudit($conn,$schoolId,$yearId,$userId,'area',$id,$audit,['name'=>$name]);amResponse(['status'=>1,'message'=>'Área guardada correctamente.']);
}

if ($action === 'save_course') {
    $id=(int)($_POST['id']??0);$yearId=(int)($_POST['academic_year_id']??0);$areaId=(int)($_POST['area_id']??0);$name=trim($_POST['name']??'');$code=trim($_POST['course_code']??'');$description=trim($_POST['description']??'');$level=trim($_POST['level']??'');$grades=$_POST['grades']??[];$grades=is_array($grades)?implode(',',array_map('trim',$grades)):trim($grades);$hours=max(0,(float)($_POST['weekly_hours']??0));$type=trim($_POST['course_type']??'Curso');$order=(int)($_POST['display_order']??0);$courseStatus=trim($_POST['course_status']??'Activo');if(!in_array($courseStatus,['Activo','Suspendido','Inactivo'],true))$courseStatus='Activo';$active=$courseStatus==='Activo'?1:0;
    if(!$yearId||!$areaId||$name===''||!in_array($level,['Inicial','Primaria','Secundaria'],true)) amResponse(['status'=>0,'message'=>'Completa año, área, nombre y nivel.']);
    amEnsureYearWritable($conn,$schoolId,$yearId);
    $area=$conn->prepare('SELECT id FROM areas WHERE id=? AND school_id=? AND (academic_year_id=? OR academic_year_id IS NULL)');$area->bind_param('iii',$areaId,$schoolId,$yearId);$area->execute();if(!$area->get_result()->fetch_assoc())amResponse(['status'=>0,'message'=>'El área no pertenece al año seleccionado.']);$area->close();
    $dup=$conn->prepare('SELECT id FROM academic_courses WHERE school_id=? AND academic_year_id=? AND name=? AND level=? AND id<>?');$dup->bind_param('iissi',$schoolId,$yearId,$name,$level,$id);$dup->execute();if($dup->get_result()->fetch_assoc())amResponse(['status'=>0,'message'=>'Ya existe ese curso para el nivel y año seleccionados.']);$dup->close();
    if($id){$stmt=$conn->prepare('UPDATE academic_courses SET academic_year_id=?,area_id=?,name=?,course_code=?,description=?,level=?,grades=?,weekly_hours=?,course_type=?,display_order=?,is_active=?,course_status=? WHERE id=? AND school_id=?');$stmt->bind_param('iisssssdsiisii',$yearId,$areaId,$name,$code,$description,$level,$grades,$hours,$type,$order,$active,$courseStatus,$id,$schoolId);$audit='update';}
    else{$stmt=$conn->prepare('INSERT INTO academic_courses (school_id,academic_year_id,area_id,name,course_code,description,level,grades,weekly_hours,course_type,display_order,is_active,course_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');$stmt->bind_param('iiisssssdsiis',$schoolId,$yearId,$areaId,$name,$code,$description,$level,$grades,$hours,$type,$order,$active,$courseStatus);$audit='create';}
    if(!$stmt->execute())amResponse(['status'=>0,'message'=>$stmt->error]);if(!$id)$id=$stmt->insert_id;$stmt->close();amAudit($conn,$schoolId,$yearId,$userId,'course',$id,$audit,['name'=>$name,'level'=>$level,'grades'=>$grades]);amResponse(['status'=>1,'message'=>'Curso guardado correctamente.']);
}

if ($action === 'bulk') {
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));
    $operation = $_POST['operation'] ?? '';
    $yearId = (int)($_POST['academic_year_id'] ?? 0);
    amEnsureYearWritable($conn,$schoolId,$yearId);
    if (!$ids) amResponse(['status'=>0,'message'=>'Selecciona al menos un curso.']);
    $allowed = ['activate','suspend','deactivate','assign_area'];
    if (!in_array($operation,$allowed,true)) amResponse(['status'=>0,'message'=>'Acción masiva inválida.']);
    $placeholders = implode(',', array_fill(0,count($ids),'?'));
    $types = str_repeat('i',count($ids));
    $conn->begin_transaction();
    try {
        if ($operation === 'assign_area') {
            $areaId=(int)($_POST['area_id'] ?? 0);
            $check=$conn->prepare('SELECT id FROM areas WHERE id=? AND school_id=? AND (academic_year_id=? OR academic_year_id IS NULL)');
            $check->bind_param('iii',$areaId,$schoolId,$yearId); $check->execute();
            if (!$check->get_result()->fetch_assoc()) throw new Exception('El área seleccionada no es válida.'); $check->close();
            $sql="UPDATE academic_courses SET area_id=? WHERE id IN ($placeholders) AND school_id=? AND academic_year_id=?";
            $stmt=$conn->prepare($sql); $bindTypes='i'.$types.'ii'; $bind=[$areaId,...$ids,$schoolId,$yearId]; $stmt->bind_param($bindTypes,...$bind);
        } else {
            $courseStatus=$operation === 'activate' ? 'Activo' : ($operation === 'suspend' ? 'Suspendido' : 'Inactivo');
            $newStatus=$operation === 'activate' ? 1 : 0;
            $sql="UPDATE academic_courses SET is_active=?,course_status=? WHERE id IN ($placeholders) AND school_id=? AND academic_year_id=?";
            $stmt=$conn->prepare($sql); $bindTypes='is'.$types.'ii'; $bind=[$newStatus,$courseStatus,...$ids,$schoolId,$yearId]; $stmt->bind_param($bindTypes,...$bind);
        }
        if (!$stmt->execute()) throw new Exception($stmt->error); $affected=$stmt->affected_rows; $stmt->close();
        amAudit($conn,$schoolId,$yearId,$userId,'course',0,'bulk_'.$operation,['ids'=>$ids,'affected'=>$affected]);
        $conn->commit(); amResponse(['status'=>1,'message'=>"Se actualizaron $affected cursos."]);
    } catch(Throwable $e) { $conn->rollback(); amResponse(['status'=>0,'message'=>$e->getMessage()]); }
}

if ($action === 'copy_year') {
    $source=(int)($_POST['source_year_id'] ?? 0); $target=(int)($_POST['target_year_id'] ?? 0);
    if (!$source || !$target || $source===$target) amResponse(['status'=>0,'message'=>'Selecciona años de origen y destino diferentes.']);
    amEnsureYearWritable($conn,$schoolId,$target);
    $check=$conn->prepare('SELECT COUNT(*) total FROM academic_year WHERE id IN (?,?) AND school_id=?');
    $check->bind_param('iii',$source,$target,$schoolId); $check->execute(); $valid=(int)$check->get_result()->fetch_assoc()['total']; $check->close();
    if ($valid !== 2) amResponse(['status'=>0,'message'=>'Los años seleccionados no pertenecen al colegio.']);
    $conn->begin_transaction();
    try {
        $areaMap=[]; $areas=$conn->query("SELECT * FROM areas WHERE school_id=$schoolId AND academic_year_id=$source ORDER BY id");
        $findArea=$conn->prepare('SELECT id FROM areas WHERE school_id=? AND academic_year_id=? AND name=? LIMIT 1');
        $insertArea=$conn->prepare('INSERT INTO areas (school_id,academic_year_id,name,description,color,is_active) VALUES (?,?,?,?,?,?)');
        while($area=$areas->fetch_assoc()) {
            $findArea->bind_param('iis',$schoolId,$target,$area['name']); $findArea->execute(); $found=$findArea->get_result()->fetch_assoc();
            if ($found) $newId=(int)$found['id']; else { $active=(int)$area['is_active']; $insertArea->bind_param('iisssi',$schoolId,$target,$area['name'],$area['description'],$area['color'],$active); if(!$insertArea->execute()) throw new Exception($insertArea->error); $newId=$insertArea->insert_id; }
            $areaMap[(int)$area['id']]=$newId;
        }
        $findArea->close(); $insertArea->close();
        $courses=$conn->query("SELECT * FROM academic_courses WHERE school_id=$schoolId AND academic_year_id=$source ORDER BY id");
        $find=$conn->prepare('SELECT id FROM academic_courses WHERE school_id=? AND academic_year_id=? AND name=? AND level=? LIMIT 1');
        $insert=$conn->prepare('INSERT INTO academic_courses (school_id,academic_year_id,area_id,name,course_code,description,level,grades,weekly_hours,course_type,display_order,is_active,course_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $copied=0; $skipped=0;
        while($course=$courses->fetch_assoc()) {
            $find->bind_param('iiss',$schoolId,$target,$course['name'],$course['level']); $find->execute();
            if ($find->get_result()->fetch_assoc()) { $skipped++; continue; }
            $newArea=isset($areaMap[(int)$course['area_id']]) ? $areaMap[(int)$course['area_id']] : null;
            $hours=(float)$course['weekly_hours']; $order=(int)$course['display_order']; $active=(int)$course['is_active'];
            $courseStatus=$course['course_status']??($active?'Activo':'Inactivo');
            $insert->bind_param('iiisssssdsiis',$schoolId,$target,$newArea,$course['name'],$course['course_code'],$course['description'],$course['level'],$course['grades'],$hours,$course['course_type'],$order,$active,$courseStatus);
            if(!$insert->execute()) throw new Exception($insert->error); $copied++;
        }
        $find->close(); $insert->close();
        amAudit($conn,$schoolId,$target,$userId,'structure',0,'copy_year',['source_year_id'=>$source,'copied'=>$copied,'skipped'=>$skipped]);
        $conn->commit(); amResponse(['status'=>1,'message'=>"Estructura copiada: $copied cursos nuevos y $skipped omitidos por duplicidad."]);
    } catch(Throwable $e) { $conn->rollback(); amResponse(['status'=>0,'message'=>'No se pudo copiar: '.$e->getMessage()]); }
}

amResponse(['status'=>0,'message'=>'Acción no reconocida.'],404);
