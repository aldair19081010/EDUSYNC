<?php
/**
 * Dirección académica móvil (solo consulta).
 * La escuela y el privilegio Director SIEMPRE se revalidan en la base de datos.
 * No reutilizar los endpoints del docente: estos limitan datos al profesor.
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function dma_reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function dma_class_key(string $level, string $grade, string $section): string {
    $level = function_exists('mb_strtolower') ? mb_strtolower(trim($level), 'UTF-8') : strtolower(trim($level));
    $grade = trim(str_replace(['°', 'º'], '', $grade));
    $section = strtoupper(trim($section));
    return $level . '|' . $grade . '|' . ($section === '' ? 'U' : $section);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    dma_reply(['status'=>'error','message'=>'Método no permitido.'], 405);
}
$sid = trim((string)($_POST['sid'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $sid)) {
    dma_reply(['status'=>'error','message'=>'Inicia sesión nuevamente.'], 401);
}
ob_start();
require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../db_connect.php';
ob_end_clean();

$userId = (int)($_SESSION['login_id'] ?? 0);
$schoolId = (int)($_SESSION['login_school_id'] ?? 0);
if ($userId <= 0 || $schoolId <= 0) {
    dma_reply(['status'=>'error','message'=>'Sesión no válida.'], 401);
}
// Nunca confiar en el indicador director enviado por Flutter ni en la sesión
// sin verificar que siga activo en la tabla users del colegio correspondiente.
$director = $conn->prepare(
    "SELECT u.id,u.name,u.type,u.is_director,u.teacher_id
     FROM users u
     LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id
     WHERE u.id=? AND u.school_id=? AND u.is_director=1
       AND u.type IN (1,2) AND u.status='Activo'
       AND (u.type<>2 OR (u.teacher_id>0 AND t.status='Activo'))
     LIMIT 1"
);
if (!$director) dma_reply(['status'=>'error','message'=>'No se pudo verificar Dirección.'], 500);
$director->bind_param('ii', $userId, $schoolId);
$director->execute();
$account = $director->get_result()->fetch_assoc();
$director->close();
if (!$account) dma_reply(['status'=>'error','message'=>'No tienes acceso al perfil de Dirección.'], 403);
$action = trim((string)($_POST['action'] ?? 'overview'));
if (!in_array($action, ['overview','students','student_detail'], true)) {
    dma_reply(['status'=>'error','message'=>'Acción no permitida.'], 400);
}
if ($action === 'students') {
    $level = trim((string)($_POST['level'] ?? ''));
    $grade = trim(str_replace(['°','º'], '', (string)($_POST['grade'] ?? '')));
    $section = strtoupper(trim((string)($_POST['section'] ?? '')));
    if ($level === '' || $grade === '' || $section === '' ||
        strlen($level)>50 || strlen($grade)>12 || strlen($section)>20) {
        dma_reply(['status'=>'error','message'=>'Selecciona un aula válida.'], 422);
    }
    $stmt = $conn->prepare(
        "SELECT st.id,st.id_no,st.name,st.nivel,st.grado,st.seccion
         FROM student st WHERE st.school_id=? AND st.status='Activo'
           AND LOWER(TRIM(st.nivel))=LOWER(TRIM(?))
           AND TRIM(REPLACE(REPLACE(st.grado,'°',''),'º',''))=?
           AND UPPER(COALESCE(NULLIF(TRIM(st.seccion),''),'U'))=?
         ORDER BY st.name,st.id LIMIT 300"
    );
    if (!$stmt) dma_reply(['status'=>'error','message'=>'No se pudo consultar el aula.'],500);
    $stmt->bind_param('isss',$schoolId,$level,$grade,$section);
    $stmt->execute();
    $students=[];
    $res=$stmt->get_result();
    while ($res && ($row=$res->fetch_assoc())) {
        $row['id']=(int)$row['id'];
        $students[]=$row;
    }
    $stmt->close();
    dma_reply(['status'=>'ok','students'=>$students,'read_only'=>true]);
}
if ($action === 'student_detail') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $yearId = (int)($_POST['academic_year_id'] ?? 0);
    if ($studentId<=0) dma_reply(['status'=>'error','message'=>'Estudiante inválido.'],422);
    $studentStmt=$conn->prepare(
        "SELECT id,id_no,name,nivel,grado,seccion FROM student WHERE id=? AND school_id=? LIMIT 1"
    );
    if (!$studentStmt) dma_reply(['status'=>'error','message'=>'No se pudo consultar al estudiante.'],500);
    $studentStmt->bind_param('ii',$studentId,$schoolId);
    $studentStmt->execute();
    $student=$studentStmt->get_result()->fetch_assoc();
    $studentStmt->close();
    if (!$student) dma_reply(['status'=>'error','message'=>'Estudiante fuera de esta institución.'],404);
    // Un año seleccionado debe pertenecer a la institución. No mezclar años.
    if ($yearId>0) {
        $validYear=$conn->prepare('SELECT id FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
        $validYear->bind_param('ii',$yearId,$schoolId);
        $validYear->execute();
        $valid=(bool)$validYear->get_result()->fetch_assoc();
        $validYear->close();
        if (!$valid) dma_reply(['status'=>'error','message'=>'Año académico no autorizado.'],404);
    } else {
        $yr=$conn->prepare('SELECT id FROM academic_year WHERE school_id=? ORDER BY is_active DESC, year DESC, id DESC LIMIT 1');
        $yr->bind_param('i',$schoolId);
        $yr->execute();
        $yearId=(int)($yr->get_result()->fetch_assoc()['id'] ?? 0);
        $yr->close();
    }
    $marks=[];
    if ($yearId>0) {
        $stmt=$conn->prepare(
            "SELECT ac.name course_name,t.name teacher_name,
                    ev.bimestre, ev.title evaluation_name,
                    COALESCE(gcc.name,'Competencia histórica') competency_name,
                    eg.grade
             FROM evaluation_grades eg
             INNER JOIN evaluations ev ON ev.id=eg.evaluation_id
             INNER JOIN teacher_courses tc ON tc.id=ev.teacher_course_id
               AND tc.academic_year_id=ev.academic_year_id
             INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
             LEFT JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
             LEFT JOIN general_course_competencies gcc ON gcc.id=eg.competencia_id
             WHERE eg.student_id=? AND tc.school_id=? AND tc.academic_year_id=?
             ORDER BY ac.name,CAST(ev.bimestre AS UNSIGNED),ev.id,eg.id LIMIT 300"
        );
        if (!$stmt) dma_reply(['status'=>'error','message'=>'No se pudieron consultar las notas.'],500);
        $stmt->bind_param('iii',$studentId,$schoolId,$yearId);
        $stmt->execute();
        $res=$stmt->get_result();
        while ($res && ($row=$res->fetch_assoc())) $marks[]=$row;
        $stmt->close();
    }
    $student['id']=(int)$student['id'];
    dma_reply(['status'=>'ok','student'=>$student,'academic_year_id'=>$yearId,'grades'=>$marks,'read_only'=>true]);
}
// Vista institucional. Todas las asignaciones pertenecen al colegio de la
// sesión; no se aceptan teacher_id ni school_id desde el dispositivo.
$schoolStmt=$conn->prepare('SELECT name,logo_path FROM schools WHERE id=? LIMIT 1');
$schoolStmt->bind_param('i',$schoolId);
$schoolStmt->execute();
$school=$schoolStmt->get_result()->fetch_assoc() ?: [];
$schoolStmt->close();
$yearsStmt=$conn->prepare('SELECT id,year,description,is_active FROM academic_year WHERE school_id=? ORDER BY is_active DESC,year DESC,id DESC');
$yearsStmt->bind_param('i',$schoolId);
$yearsStmt->execute();
$years=[];
while ($row=$yearsStmt->get_result()->fetch_assoc()) {
    $row['id']=(int)$row['id'];
    $row['is_active']=(int)$row['is_active']===1;
    $years[]=$row;
}
$yearsStmt->close();
$requestedYear=(int)($_POST['academic_year_id'] ?? 0);
$selectedYear=null;
foreach ($years as $year) {
    if ($requestedYear>0 && $year['id']===$requestedYear) $selectedYear=$year;
}
if ($requestedYear>0 && $selectedYear===null) {
    dma_reply(['status'=>'error','message'=>'El año no pertenece a este colegio.'],404);
}
if ($selectedYear===null) $selectedYear=$years[0] ?? null;
$yearId=(int)($selectedYear['id'] ?? 0);
$bimester=(int)($_POST['bimester'] ?? 0);
if ($bimester<0 || $bimester>4) dma_reply(['status'=>'error','message'=>'Bimestre inválido.'],422);
if ($bimester===0) {
    $bimester=1;
    if ($yearId>0) {
        $stmt=$conn->prepare(
            "SELECT MAX(CAST(ev.bimestre AS UNSIGNED)) n
             FROM evaluations ev INNER JOIN teacher_courses tc
               ON tc.id=ev.teacher_course_id AND tc.school_id=?
             WHERE tc.academic_year_id=? AND CAST(ev.bimestre AS UNSIGNED) BETWEEN 1 AND 4"
        );
        if ($stmt) {
            $stmt->bind_param('ii',$schoolId,$yearId);
            $stmt->execute();
            $n=(int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
            if ($n>=1 && $n<=4) $bimester=$n;
            $stmt->close();
        }
    }
}
$totals=['students'=>0,'teachers'=>0,'classrooms'=>0,'courses'=>0,'pending_courses'=>0,'completed_courses'=>0];
$cnt=$conn->prepare("SELECT COUNT(*) total FROM student WHERE school_id=? AND status='Activo'");
$cnt->bind_param('i',$schoolId);$cnt->execute();
$totals['students']=(int)($cnt->get_result()->fetch_assoc()['total']??0);$cnt->close();
$cnt=$conn->prepare("SELECT COUNT(*) total FROM teacher WHERE school_id=? AND status='Activo'");
$cnt->bind_param('i',$schoolId);$cnt->execute();
$totals['teachers']=(int)($cnt->get_result()->fetch_assoc()['total']??0);$cnt->close();
// Las aulas proceden de los estudiantes y existen aunque no haya un curso
// asignado todavía en teacher_courses.
$classrooms=[];
$byKey=[];
$roomsStmt=$conn->prepare(
    "SELECT nivel level,grado grade,COALESCE(NULLIF(TRIM(seccion),''),'U') section,
            COUNT(*) student_count FROM student
     WHERE school_id=? AND status='Activo'
     GROUP BY nivel,grado,COALESCE(NULLIF(TRIM(seccion),''),'U')
     ORDER BY nivel,grado,section"
);
$roomsStmt->bind_param('i',$schoolId);$roomsStmt->execute();
foreach ($roomsStmt->get_result() as $row) {
    $key=dma_class_key((string)$row['level'],(string)$row['grade'],(string)$row['section']);
    if (!isset($byKey[$key])) {
        $row['student_count']=(int)$row['student_count'];
        $row['courses']=[];
        $byKey[$key]=count($classrooms);
        $classrooms[]=$row;
    } else {
        $classrooms[$byKey[$key]]['student_count']+=(int)$row['student_count'];
    }
}
$roomsStmt->close();
$courses=[];
if ($yearId>0) {
    $courseStmt=$conn->prepare(
        "SELECT tc.id teacher_course_id,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,
                ac.level,ac.name course_name,
                COALESCE(t.name,'Docente no asignado') teacher_name,
                COALESCE(a.name,'Sin área') area_name
         FROM teacher_courses tc
         INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
         LEFT JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
         LEFT JOIN areas a ON a.id=ac.area_id AND a.school_id=tc.school_id
         WHERE tc.school_id=? AND tc.academic_year_id=?
         ORDER BY ac.level,tc.grado,tc.seccion,ac.name,t.name"
    );
    if (!$courseStmt) dma_reply(['status'=>'error','message'=>'No se pudieron consultar cursos.'],500);
    $courseStmt->bind_param('ii',$schoolId,$yearId);
    $courseStmt->execute();
    foreach ($courseStmt->get_result() as $course) {
        $key=dma_class_key((string)$course['level'],(string)$course['grado'],(string)$course['seccion']);
        if (!isset($byKey[$key])) {
            $byKey[$key]=count($classrooms);
            $classrooms[]=[
                'level'=>$course['level'],'grade'=>$course['grado'],'section'=>$course['seccion'],
                'student_count'=>0,'courses'=>[]
            ];
        }
        $id=(int)$course['teacher_course_id'];
        $course['teacher_course_id']=$id;
        $course['grade_progress']=null;
        $course['competency_count']=0;
        $course['bimester_evaluation_count']=0;
        $course['read_only']=true;
        $courses[$id]=$course;
        $classrooms[$byKey[$key]]['courses'][]=$id;
    }
    $courseStmt->close();
}
$totals['classrooms']=count($classrooms);
$totals['courses']=count($courses);

// Avance institucional: promedio del porcentaje de celdas llenas POR
// competencia. Una competencia sin evaluaciones cuenta como 0%.
// Se devuelve null cuando el curso todavía no tiene competencias configuradas.
if ($courses && !empty($selectedYear['is_active'])) {
    $evStatusCol=$conn->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
    $activeEvalFilter=($evStatusCol && $evStatusCol->num_rows>0)
        ? " AND COALESCE(e.status,'Activa')<>'Anulada'" : '';
    $stmt=$conn->prepare(
        "SELECT tc.id teacher_course_id,gcc.id competency_id,
                COUNT(DISTINCT e.id) evaluation_count,
                COUNT(DISTINCT CASE WHEN st.id IS NOT NULL AND eg.grade IS NOT NULL
                      AND TRIM(eg.grade)<>'' THEN CONCAT(eg.evaluation_id,':',eg.student_id) END) filled_count
         FROM teacher_courses tc
         INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
         INNER JOIN general_course_competencies gcc
           ON gcc.course_id=tc.course_id AND gcc.teacher_id=tc.teacher_id
           AND gcc.academic_year_id=tc.academic_year_id AND gcc.is_active=1
         LEFT JOIN evaluation_competencias ec ON ec.competencia_id=gcc.id
         LEFT JOIN evaluations e ON e.id=ec.evaluation_id AND e.teacher_course_id=tc.id
           AND e.teacher_id=tc.teacher_id AND e.academic_year_id=tc.academic_year_id
           AND e.bimestre=? {$activeEvalFilter}
         LEFT JOIN evaluation_grades eg ON eg.evaluation_id=e.id AND eg.competencia_id=gcc.id
         LEFT JOIN student st ON st.id=eg.student_id AND st.school_id=tc.school_id
           AND st.status='Activo' AND st.nivel=ac.level
           AND TRIM(REPLACE(REPLACE(st.grado,'°',''),'º',''))=
               TRIM(REPLACE(REPLACE(tc.grado,'°',''),'º',''))
           AND COALESCE(NULLIF(TRIM(st.seccion),''),'U')=COALESCE(NULLIF(TRIM(tc.seccion),''),'U')
         WHERE tc.school_id=? AND tc.academic_year_id=?
         GROUP BY tc.id,gcc.id ORDER BY tc.id,gcc.id"
    );
    if ($stmt) {
        $bimString=(string)$bimester;
        $stmt->bind_param('sii',$bimString,$schoolId,$yearId);
        if ($stmt->execute()) {
            $rates=[];
            $evalCount=[];
            foreach ($stmt->get_result() as $row) {
                $id=(int)$row['teacher_course_id'];
                if (!isset($courses[$id])) continue;
                $key=dma_class_key($courses[$id]['level'],$courses[$id]['grado'],$courses[$id]['seccion']);
                $studentCount=isset($byKey[$key]) ? (int)$classrooms[$byKey[$key]]['student_count'] : 0;
                $evaluations=(int)$row['evaluation_count'];
                $expected=$evaluations*$studentCount;
                $filled=(int)$row['filled_count'];
                $rates[$id][]=$expected>0 ? min(1,$filled/$expected) : 0.0;
                $courses[$id]['competency_count']++;
                $evalCount[$id]=($evalCount[$id]??0)+$evaluations;
            }
            foreach ($courses as $id=>&$course) {
                if (isset($rates[$id])) {
                    $course['grade_progress']=(int)round(array_sum($rates[$id])*100/count($rates[$id]));
                    $course['bimester_evaluation_count']=$evalCount[$id]??0;
                    if ($course['grade_progress']===100 && $course['bimester_evaluation_count']>0) {
                        $totals['completed_courses']++;
                    } else {
                        $totals['pending_courses']++;
                    }
                } else $totals['pending_courses']++;
            }
            unset($course);
        }
        $stmt->close();
    }
}
foreach ($classrooms as &$room) {
    $room['courses']=array_values(array_map(static function ($id) use (&$courses) {
        return $courses[$id];
    }, $room['courses']));
}
unset($room);
dma_reply([
    'status'=>'ok','director_name'=>(string)$account['name'],
    'school_name'=>(string)($school['name']??''),
    'academic_year'=>$selectedYear,'years'=>$years,
    'selected_bimester'=>$bimester,'summary'=>$totals,
    'classrooms'=>$classrooms,'read_only'=>true
]);
