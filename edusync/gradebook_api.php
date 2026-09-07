<?php
include_once __DIR__ . '/session_config.php';
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function gradebook_response(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gradebook_table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$safe'");
    return $result && $result->num_rows > 0;
}

function gradebook_column_exists(mysqli $conn, string $table, string $column): bool {
    $safeTable = str_replace('`', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return $result && $result->num_rows > 0;
}

$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
$teacherId = (int)($_SESSION['login_teacher_id'] ?? 0);
$loginType = (int)($_SESSION['login_type'] ?? 0);
if ($loginType !== 2 || $schoolId <= 0 || $teacherId <= 0) {
    gradebook_response(['status' => 0, 'message' => 'Solo los docentes vinculados pueden acceder al libro de notas.'], 403);
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'load');

if ($action === 'contexts') {
    $yearId = (int)($_GET['academic_year_id'] ?? 0);
    $whereYear = $yearId > 0 ? " AND tc.academic_year_id = $yearId" : '';
    $sql = "SELECT tc.id,tc.academic_year_id,tc.grado,tc.seccion,ac.name course_name,ac.level,ay.year,ay.is_active
            FROM teacher_courses tc
            INNER JOIN academic_courses ac ON ac.id=tc.course_id
            INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
            WHERE tc.teacher_id=$teacherId AND tc.school_id=$schoolId$whereYear
            ORDER BY ay.year DESC,ac.level,tc.grado,tc.seccion,ac.name";
    $result = $conn->query($sql);
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = [
            'id' => (int)$row['id'],
            'academic_year_id' => (int)$row['academic_year_id'],
            'year' => $row['year'],
            'is_active' => (int)$row['is_active'],
            'course_name' => $row['course_name'],
            'level' => $row['level'],
            'grado' => $row['grado'],
            'seccion' => $row['seccion'] ?: 'U'
        ];
    }
    gradebook_response(['status' => 1, 'assignments' => $rows]);
}

if ($action === 'history') {
    if(!gradebook_table_exists($conn,'evaluation_grade_history'))gradebook_response(['status'=>0,'migration_required'=>true,'message'=>'Ejecuta sql/gradebook_history_upgrade.sql para habilitar el historial por nota.']);
    $evaluationId=(int)($_GET['evaluation_id']??0);$studentId=(int)($_GET['student_id']??0);
    $owner=$conn->query("SELECT e.title,s.name student_name FROM evaluations e INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN student s ON s.id=$studentId AND s.school_id=tc.school_id WHERE e.id=$evaluationId AND e.teacher_id=$teacherId AND tc.teacher_id=$teacherId AND tc.school_id=$schoolId LIMIT 1");$context=$owner?$owner->fetch_assoc():null;
    if(!$context)gradebook_response(['status'=>0,'message'=>'La nota no existe o no te pertenece.'],403);
    $rows=[];$history=$conn->query("SELECT h.previous_grade,h.new_grade,h.source,h.created_at,u.name changed_by_name FROM evaluation_grade_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.school_id=$schoolId AND h.evaluation_id=$evaluationId AND h.student_id=$studentId ORDER BY h.created_at DESC,h.id DESC LIMIT 50");while($history&&($row=$history->fetch_assoc()))$rows[]=$row;
    gradebook_response(['status'=>1,'evaluation'=>$context['title'],'student'=>$context['student_name'],'history'=>$rows]);
}

if ($action === 'load') {
    $teacherCourseId = (int)($_GET['teacher_course_id'] ?? 0);
    $bimester = (int)($_GET['bimestre'] ?? 0);
    if ($teacherCourseId <= 0 || $bimester < 1 || $bimester > 4) {
        gradebook_response(['status' => 0, 'message' => 'Selecciona un curso y un bimestre.']);
    }

    $hasYearStatus = gradebook_column_exists($conn, 'academic_year', 'status');
    $yearStatusSelect = $hasYearStatus ? ',ay.status year_status' : '';
    $assignmentSql = "SELECT tc.id,tc.course_id,tc.academic_year_id,tc.grado,tc.seccion,ac.name course_name,ac.level,ay.year,ay.is_active$yearStatusSelect
                      FROM teacher_courses tc
                      INNER JOIN academic_courses ac ON ac.id=tc.course_id
                      INNER JOIN academic_year ay ON ay.id=tc.academic_year_id AND ay.school_id=tc.school_id
                      WHERE tc.id=$teacherCourseId AND tc.teacher_id=$teacherId AND tc.school_id=$schoolId LIMIT 1";
    $assignmentResult = $conn->query($assignmentSql);
    $assignment = $assignmentResult ? $assignmentResult->fetch_assoc() : null;
    if (!$assignment) gradebook_response(['status' => 0, 'message' => 'La asignación seleccionada no existe o no te pertenece.'], 403);

    $hasEvaluationStatus = gradebook_column_exists($conn, 'evaluations', 'status');
    $evaluationStatusSelect = $hasEvaluationStatus ? ',e.status' : ", 'Activa' status";
    $evaluationsSql = "SELECT e.id,e.title,e.type,e.created_at$evaluationStatusSelect,
                              (SELECT ec.competencia_id FROM evaluation_competencias ec WHERE ec.evaluation_id=e.id ORDER BY ec.id LIMIT 1) competencia_id,
                              (SELECT gcc.name FROM evaluation_competencias ec2 INNER JOIN general_course_competencies gcc ON gcc.id=ec2.competencia_id WHERE ec2.evaluation_id=e.id ORDER BY ec2.id LIMIT 1) competency_name,
                              (SELECT gcc.percentage FROM evaluation_competencias ec3 INNER JOIN general_course_competencies gcc ON gcc.id=ec3.competencia_id WHERE ec3.evaluation_id=e.id ORDER BY ec3.id LIMIT 1) competency_percentage,
                              (SELECT COUNT(*) FROM evaluation_grades egc WHERE egc.evaluation_id=e.id) grades_count
                       FROM evaluations e
                       WHERE e.teacher_course_id=$teacherCourseId AND e.teacher_id=$teacherId
                         AND e.academic_year_id=".(int)$assignment['academic_year_id']." AND e.bimestre='".$conn->real_escape_string((string)$bimester)."'
                       ORDER BY e.created_at,e.id";
    $evaluationResult = $conn->query($evaluationsSql);
    $evaluations = [];
    $evaluationIds = [];
    while ($evaluationResult && ($row = $evaluationResult->fetch_assoc())) {
        $row['id'] = (int)$row['id'];
        $row['competencia_id'] = (int)($row['competencia_id'] ?? 0);
        $row['competency_percentage'] = (float)($row['competency_percentage'] ?? 0);
        $row['grades_count'] = (int)($row['grades_count'] ?? 0);
        $row['annulled'] = ($row['status'] ?? '') === 'Anulada';
        $evaluations[] = $row;
        $evaluationIds[] = $row['id'];
    }

    $activeYearId = 0;
    $activeYearResult = $conn->query("SELECT id FROM academic_year WHERE school_id=$schoolId AND is_active=1 LIMIT 1");
    if ($activeYearResult && $activeYearResult->num_rows) $activeYearId = (int)$activeYearResult->fetch_assoc()['id'];

    $levelEsc = $conn->real_escape_string(mb_strtolower((string)$assignment['level'], 'UTF-8'));
    $gradeEsc = $conn->real_escape_string((string)$assignment['grado']);
    $section = trim((string)$assignment['seccion']);
    $sectionEsc = $conn->real_escape_string($section);
    $sectionCondition = "s.seccion='$sectionEsc'";
    if (in_array(mb_strtoupper($section, 'UTF-8'), ['U','ÚNICA','UNICA'], true)) {
        $sectionCondition = "COALESCE(NULLIF(TRIM(s.seccion),''),'U') IN ('U','u','Única','Unica','única','unica')";
    }

    $studentParts = [];
    if ((int)$assignment['academic_year_id'] === $activeYearId) {
        $studentParts[] = "SELECT s.id,s.id_no,s.name FROM student s WHERE s.school_id=$schoolId AND (s.status='Activo' OR s.status IS NULL) AND LOWER(s.nivel)='$levelEsc' AND s.grado='$gradeEsc' AND $sectionCondition";
    }
    if ($evaluationIds) {
        $evaluationList = implode(',', $evaluationIds);
        $studentParts[] = "SELECT s.id,s.id_no,s.name FROM student s INNER JOIN evaluation_grades eg ON eg.student_id=s.id WHERE s.school_id=$schoolId AND eg.evaluation_id IN ($evaluationList)";
    }
    $students = [];
    if ($studentParts) {
        $studentResult = $conn->query(implode(' UNION ', $studentParts).' ORDER BY name');
        while ($studentResult && ($row = $studentResult->fetch_assoc())) {
            $students[] = ['id'=>(int)$row['id'],'id_no'=>$row['id_no'],'name'=>$row['name']];
        }
    }

    $grades = [];
    if ($evaluationIds && $students) {
        $studentIds = implode(',', array_map(static function ($student) { return (int)$student['id']; }, $students));
        $evaluationList = implode(',', $evaluationIds);
        $gradeResult = $conn->query("SELECT evaluation_id,student_id,competencia_id,grade FROM evaluation_grades WHERE evaluation_id IN ($evaluationList) AND student_id IN ($studentIds)");
        while ($gradeResult && ($row = $gradeResult->fetch_assoc())) {
            $grades[(int)$row['evaluation_id']][(int)$row['student_id']] = (string)$row['grade'];
        }
    }

    $locked = false;
    if (gradebook_table_exists($conn, 'bimester_locks')) {
        $lockResult = $conn->query("SELECT is_locked FROM bimester_locks WHERE academic_year_id=".(int)$assignment['academic_year_id']." AND school_id=$schoolId AND bimester=$bimester LIMIT 1");
        $locked = $lockResult && $lockResult->num_rows && (int)$lockResult->fetch_assoc()['is_locked'] === 1;
    }
    $yearReadOnly = (int)$assignment['is_active'] !== 1 || (isset($assignment['year_status']) && in_array($assignment['year_status'], ['Cerrado','Archivado'], true));

    $competencies=[];
    $competencyResult=$conn->query("SELECT id,name,percentage FROM general_course_competencies WHERE course_id=".(int)$assignment['course_id']." AND teacher_id=$teacherId AND academic_year_id=".(int)$assignment['academic_year_id']." AND is_active=1 ORDER BY id");
    while($competencyResult&&($row=$competencyResult->fetch_assoc()))$competencies[]=['id'=>(int)$row['id'],'name'=>$row['name'],'percentage'=>(float)$row['percentage']];
    foreach($evaluations as $evaluation){$found=false;foreach($competencies as $competency)if($competency['id']===$evaluation['competencia_id']){$found=true;break;}if(!$found&&$evaluation['competencia_id']>0)$competencies[]=['id'=>$evaluation['competencia_id'],'name'=>$evaluation['competency_name']?:'Competencia histórica','percentage'=>$evaluation['competency_percentage']];}

    gradebook_response([
        'status' => 1,
        'assignment' => [
            'id'=>(int)$assignment['id'],'academic_year_id'=>(int)$assignment['academic_year_id'],'year'=>$assignment['year'],
            'course_name'=>$assignment['course_name'],'level'=>$assignment['level'],'grado'=>$assignment['grado'],'seccion'=>$assignment['seccion'] ?: 'U'
        ],
        'bimestre' => $bimester,
        'evaluations' => $evaluations,
        'competencies' => $competencies,
        'students' => $students,
        'grades' => $grades,
        'read_only' => $locked || $yearReadOnly,
        'read_only_reason' => $locked ? 'El bimestre está bloqueado.' : ($yearReadOnly ? 'El año académico es histórico o está cerrado.' : ''),
        'history_ready' => gradebook_table_exists($conn,'evaluation_grade_history')
    ]);
}

if ($action === 'save') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        gradebook_response(['status'=>0,'message'=>'La sesión de seguridad venció. Recarga la página.'], 403);
    }
    $teacherCourseId = (int)($_POST['teacher_course_id'] ?? 0);
    $bimester = (int)($_POST['bimestre'] ?? 0);
    $gradingSystem = ($_POST['grading_system'] ?? 'numeric') === 'letters' ? 'letters' : 'numeric';
    $changes = json_decode((string)($_POST['changes'] ?? ''), true);
    if ($teacherCourseId <= 0 || $bimester < 1 || $bimester > 4 || !is_array($changes) || !$changes) {
        gradebook_response(['status'=>0,'message'=>'No hay cambios válidos para guardar.']);
    }
    if (count($changes) > 3000) gradebook_response(['status'=>0,'message'=>'Solo puedes guardar hasta 3000 celdas por operación.']);

    $assignmentResult = $conn->query("SELECT tc.academic_year_id,tc.grado,tc.seccion,ac.level,ay.is_active".(gradebook_column_exists($conn,'academic_year','status')?',ay.status year_status':'')." FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=tc.academic_year_id WHERE tc.id=$teacherCourseId AND tc.teacher_id=$teacherId AND tc.school_id=$schoolId AND ay.school_id=$schoolId LIMIT 1");
    $assignment = $assignmentResult ? $assignmentResult->fetch_assoc() : null;
    if (!$assignment) gradebook_response(['status'=>0,'message'=>'La asignación no existe o no te pertenece.'],403);
    if ((int)$assignment['is_active'] !== 1 || (isset($assignment['year_status']) && in_array($assignment['year_status'],['Cerrado','Archivado'],true))) gradebook_response(['status'=>0,'message'=>'El año académico está cerrado y sus notas son de solo lectura.']);
    if (gradebook_table_exists($conn,'bimester_locks')) {
        $lock=$conn->query("SELECT is_locked FROM bimester_locks WHERE academic_year_id=".(int)$assignment['academic_year_id']." AND school_id=$schoolId AND bimester=$bimester LIMIT 1");
        if($lock&&$lock->num_rows&&(int)$lock->fetch_assoc()['is_locked']===1) gradebook_response(['status'=>0,'message'=>'El bimestre está bloqueado para editar notas.']);
    }

    $evaluationIds=[];$studentIds=[];
    foreach($changes as $change){$evaluationIds[]=(int)($change['evaluation_id']??0);$studentIds[]=(int)($change['student_id']??0);}
    $evaluationIds=array_values(array_unique(array_filter($evaluationIds)));$studentIds=array_values(array_unique(array_filter($studentIds)));
    if(!$evaluationIds||!$studentIds)gradebook_response(['status'=>0,'message'=>'Los cambios no contienen estudiantes o evaluaciones válidas.']);
    $evaluationList=implode(',',$evaluationIds);$studentList=implode(',',$studentIds);
    $statusCondition=gradebook_column_exists($conn,'evaluations','status')?" AND (e.status IS NULL OR e.status<>'Anulada')":'';
    $validEvaluations=[];$evaluationResult=$conn->query("SELECT e.id,(SELECT ec.competencia_id FROM evaluation_competencias ec WHERE ec.evaluation_id=e.id ORDER BY ec.id LIMIT 1) competencia_id FROM evaluations e WHERE e.id IN ($evaluationList) AND e.teacher_course_id=$teacherCourseId AND e.teacher_id=$teacherId AND e.academic_year_id=".(int)$assignment['academic_year_id']." AND e.bimestre='".$conn->real_escape_string((string)$bimester)."'$statusCondition");
    while($evaluationResult&&($row=$evaluationResult->fetch_assoc()))$validEvaluations[(int)$row['id']]=(int)($row['competencia_id']??0);
    if(count($validEvaluations)!==count($evaluationIds))gradebook_response(['status'=>0,'message'=>'Una evaluación fue anulada o no pertenece al curso seleccionado.']);
    foreach($validEvaluations as $compId)if($compId<=0)gradebook_response(['status'=>0,'message'=>'Una evaluación no tiene competencia asociada y no puede calificarse desde el libro.']);

    $activeYearId=0;$activeYearResult=$conn->query("SELECT id FROM academic_year WHERE school_id=$schoolId AND is_active=1 LIMIT 1");if($activeYearResult&&$activeYearResult->num_rows)$activeYearId=(int)$activeYearResult->fetch_assoc()['id'];
    $levelEsc=$conn->real_escape_string(mb_strtolower((string)$assignment['level'],'UTF-8'));$gradeEsc=$conn->real_escape_string((string)$assignment['grado']);$section=trim((string)$assignment['seccion']);$sectionEsc=$conn->real_escape_string($section);$sectionCondition="s.seccion='$sectionEsc'";
    if(in_array(mb_strtoupper($section,'UTF-8'),['U','ÚNICA','UNICA'],true))$sectionCondition="COALESCE(NULLIF(TRIM(s.seccion),''),'U') IN ('U','u','Única','Unica','única','unica')";
    $currentClassCondition=(int)$assignment['academic_year_id']===$activeYearId?"((s.status='Activo' OR s.status IS NULL) AND LOWER(s.nivel)='$levelEsc' AND s.grado='$gradeEsc' AND $sectionCondition)":"0=1";
    $validStudents=[];$studentResult=$conn->query("SELECT DISTINCT s.id FROM student s WHERE s.id IN ($studentList) AND s.school_id=$schoolId AND ($currentClassCondition OR EXISTS (SELECT 1 FROM evaluation_grades eg WHERE eg.student_id=s.id AND eg.evaluation_id IN ($evaluationList)))");while($studentResult&&($row=$studentResult->fetch_assoc()))$validStudents[(int)$row['id']]=true;
    if(count($validStudents)!==count($studentIds))gradebook_response(['status'=>0,'message'=>'Uno de los estudiantes no pertenece al colegio.']);

    $select=$conn->prepare('SELECT id,grade FROM evaluation_grades WHERE evaluation_id=? AND student_id=? AND competencia_id=? LIMIT 1');
    $insert=$conn->prepare('INSERT INTO evaluation_grades (evaluation_id,student_id,competencia_id,grade) VALUES (?,?,?,?)');
    $update=$conn->prepare('UPDATE evaluation_grades SET grade=? WHERE id=?');
    $delete=$conn->prepare('DELETE FROM evaluation_grades WHERE id=?');
    $historyReady=gradebook_table_exists($conn,'evaluation_grade_history');$academicYearId=(int)$assignment['academic_year_id'];
    $saved=0;
    $conn->begin_transaction();
    try{
        foreach($changes as $change){
            $evaluationId=(int)($change['evaluation_id']??0);$studentId=(int)($change['student_id']??0);
            if(!isset($validEvaluations[$evaluationId],$validStudents[$studentId]))throw new RuntimeException('Se encontró una celda fuera del curso seleccionado.');
            $value=trim((string)($change['grade']??''));
            if($gradingSystem==='letters'){
                $value=mb_strtoupper($value,'UTF-8');
                if($value!==''&&!in_array($value,['C','B','A','AD'],true))throw new RuntimeException("La calificación $value no es válida. Usa C, B, A o AD.");
            }elseif($value!==''){
                if(!is_numeric($value))throw new RuntimeException("La calificación $value no es numérica.");
                $number=(float)$value;if($number<0||$number>20)throw new RuntimeException('Las calificaciones deben estar entre 0 y 20.');
                $value=rtrim(rtrim(number_format($number,2,'.',''),'0'),'.');
            }
            $competencyId=$validEvaluations[$evaluationId];
            $select->bind_param('iii',$evaluationId,$studentId,$competencyId);$select->execute();$existing=$select->get_result()->fetch_assoc();
            $currentValue=$existing?mb_strtoupper(trim((string)$existing['grade']),'UTF-8'):'';
            $originalValue=mb_strtoupper(trim((string)($change['original']??'')),'UTF-8');
            $sameOriginal=$currentValue===$originalValue;
            if($gradingSystem==='numeric'&&$currentValue!==''&&$originalValue!==''&&is_numeric($currentValue)&&is_numeric($originalValue))$sameOriginal=abs((float)$currentValue-(float)$originalValue)<0.0001;
            if(!$sameOriginal)throw new RuntimeException('Algunas notas cambiaron mientras editabas. Recarga el libro antes de volver a guardar.');
            if($existing){$gradeId=(int)$existing['id'];if($value===''){$delete->bind_param('i',$gradeId);if(!$delete->execute())throw new RuntimeException($delete->error);}else{$update->bind_param('si',$value,$gradeId);if(!$update->execute())throw new RuntimeException($update->error);}}
            elseif($value!==''){$insert->bind_param('iiis',$evaluationId,$studentId,$competencyId,$value);if(!$insert->execute())throw new RuntimeException($insert->error);}
            if($historyReady){$userId=(int)($_SESSION['login_id']??0);$history=$conn->prepare("INSERT INTO evaluation_grade_history (school_id,academic_year_id,evaluation_id,student_id,competency_id,previous_grade,new_grade,changed_by,source) VALUES (?,?,?,?,?,?,?,?, 'Libro de notas')");$history->bind_param('iiiiissi',$schoolId,$academicYearId,$evaluationId,$studentId,$competencyId,$currentValue,$value,$userId);if(!$history->execute())throw new RuntimeException($history->error);$history->close();}
            $saved++;
        }
        if(gradebook_table_exists($conn,'evaluation_audit_log')){
            $details=$conn->real_escape_string(json_encode(['teacher_course_id'=>$teacherCourseId,'bimestre'=>$bimester,'changed_cells'=>$saved,'grading_system'=>$gradingSystem],JSON_UNESCAPED_UNICODE));$userId=(int)($_SESSION['login_id']??0);$ip=$conn->real_escape_string($_SERVER['REMOTE_ADDR']??'');
            foreach(array_keys($validEvaluations) as $evaluationId)$conn->query("INSERT INTO evaluation_audit_log (school_id,academic_year_id,evaluation_id,teacher_id,user_id,action,details,ip_address) VALUES ($schoolId,".(int)$assignment['academic_year_id'].",$evaluationId,$teacherId,$userId,'gradebook_saved','$details','$ip')");
        }
        $conn->commit();
    }catch(Throwable $error){$conn->rollback();gradebook_response(['status'=>0,'message'=>$error->getMessage()]);}

    include_once __DIR__.'/admin_class.php';
    $actions=new Action();foreach(array_keys($validEvaluations) as $evaluationId)$actions->generate_low_grade_notifications($evaluationId);
    gradebook_response(['status'=>1,'message'=>"Se guardaron $saved celdas correctamente.",'saved'=>$saved]);
}

gradebook_response(['status'=>0,'message'=>'Acción no válida.'],400);
