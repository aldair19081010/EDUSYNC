<?php
require_once __DIR__ . '/../includes/dev_cors.php';
require_once __DIR__ . '/../includes/attendance_history_helper.php';
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
    $GLOBALS['dma_replied'] = true;
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
// Registrar información técnica solo en error_log; el cliente recibe una
// fase segura y referencia para diagnosticar un error SQL sin revelar datos.
$GLOBALS['dma_replied'] = false;
$GLOBALS['dma_stage'] = 'inicio';
$GLOBALS['dma_request_id'] = bin2hex(random_bytes(5));
header('X-EduSync-Request-ID: ' . $GLOBALS['dma_request_id']);
set_exception_handler(static function (Throwable $error): void {
    $id = $GLOBALS['dma_request_id'] ?? 'unknown';
    $stage = $GLOBALS['dma_stage'] ?? 'unknown';
    error_log('[director_mobile][' . $id . '][' . $stage . '] ' . $error->getMessage());
    dma_reply([
        'status' => 'error',
        'message' => 'No se pudo completar la consulta de Dirección. Referencia: ' . $id,
        'stage' => $stage,
        'reference' => $id,
    ], 500);
});
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['dma_replied'])) return;
    $last = error_get_last();
    if (!is_array($last) || !in_array($last['type'] ?? 0,
        [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $id = $GLOBALS['dma_request_id'] ?? 'unknown';
    $stage = $GLOBALS['dma_stage'] ?? 'unknown';
    error_log('[director_mobile][' . $id . '][' . $stage . '] ' .
        ($last['message'] ?? 'Error fatal de PHP'));
    dma_reply([
        'status' => 'error',
        'message' => 'Se produjo un error PHP en la consulta de Dirección. Referencia: ' . $id,
        'stage' => $stage,
        'reference' => $id,
    ], 500);
});

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
$GLOBALS['dma_stage'] = 'sesion_y_conexion';
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
$GLOBALS['dma_stage'] = 'validar_director';
$flagColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'is_director'");
if (!$flagColumn || $flagColumn->num_rows === 0) {
    dma_reply([
        'status'=>'error',
        'message'=>'El servidor no tiene habilitado el perfil Director. '
            . 'Comprueba la migración del módulo Usuarios.',
        'stage'=>'validar_director',
    ],409);
}
$statusColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$activeUserCondition = ($statusColumn && $statusColumn->num_rows > 0)
    ? " AND u.status='Activo'" : '';
$director = $conn->prepare(
    "SELECT u.id,u.name,u.type,u.is_director,u.teacher_id
     FROM users u
     LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id
     WHERE u.id=? AND u.school_id=? AND u.is_director=1
       AND u.type IN (1,2) {$activeUserCondition}
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
if (!in_array($action, ['overview','students','student_detail','student_course','course_roster','identity','attendance_overview','attendance_classroom','attendance_student','change_password'], true)) {
    dma_reply(['status'=>'error','message'=>'Acción no permitida.'], 400);
}
if ($action === 'change_password') {
    // Modificación permitida solo a la cuenta Directora autenticada; el
    // privilegio, colegio, rol y estado fueron validados antes en MySQL.
    // Nunca se actualiza una cuenta aportada por el dispositivo.
    $GLOBALS['dma_stage']='cambiar_contrasena';
    if (!hash_equals(session_id(), $sid) ||
        strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        dma_reply(['status'=>'error','message'=>'La sesión de seguridad no es válida.'],403);
    }
    $old=(string)($_POST['current_password'] ?? '');
    $new=(string)($_POST['new_password'] ?? '');
    $confirm=(string)($_POST['confirm_password'] ?? '');
    if ($old==='' || $new==='' || $confirm==='') {
        dma_reply(['status'=>'error','message'=>'Completa todos los campos.'],422);
    }
    if (!hash_equals($new,$confirm)) {
        dma_reply(['status'=>'error','message'=>'La confirmación no coincide.'],422);
    }
    $length=function_exists('mb_strlen') ? mb_strlen($new,'UTF-8') : strlen($new);
    if ($length<8 || $length>64 ||
        !preg_match('/\p{L}/u',$new) || !preg_match('/\d/u',$new)) {
        dma_reply(['status'=>'error','message'=>'La contraseña debe tener entre 8 y 64 caracteres, con letras y números.'],422);
    }
    $passwordStmt=$conn->prepare(
        'SELECT password FROM users WHERE id=? AND school_id=? AND is_director=1 AND type IN (1,2) LIMIT 1'
    );
    if (!$passwordStmt) throw new RuntimeException('No se pudo consultar la cuenta.');
    $passwordStmt->bind_param('ii',$userId,$schoolId);
    $passwordStmt->execute();
    $passwordRow=$passwordStmt->get_result()->fetch_assoc();
    $passwordStmt->close();
    if (!$passwordRow) dma_reply(['status'=>'error','message'=>'Cuenta no autorizada.'],403);
    $storedHash=(string)$passwordRow['password'];
    $verified=$storedHash!=='' && (
        password_verify($old,$storedHash) ||
        (preg_match('/^[a-f0-9]{32}$/i',$storedHash) &&
         hash_equals(strtolower($storedHash),md5($old)))
    );
    if (!$verified) dma_reply(['status'=>'error','message'=>'La contraseña actual es incorrecta.'],422);
    if (hash_equals($old,$new)) {
        dma_reply(['status'=>'error','message'=>'La nueva contraseña debe ser diferente.'],422);
    }
    $newHash=password_hash($new,PASSWORD_DEFAULT);
    if ($newHash===false) throw new RuntimeException('No se pudo cifrar la contraseña.');
    $up=$conn->prepare(
        'UPDATE users SET password=? WHERE id=? AND school_id=? AND is_director=1 AND password=?'
    );
    if (!$up) throw new RuntimeException('No se pudo preparar el cambio.');
    $up->bind_param('siis',$newHash,$userId,$schoolId,$storedHash);
    $ok=$up->execute();
    $affected=$up->affected_rows;
    $up->close();
    if (!$ok) throw new RuntimeException('No se pudo guardar el cambio.');
    if ($affected!==1) {
        dma_reply(['status'=>'error','message'=>'Tu contraseña cambió en otra sesión. Inicia sesión nuevamente.'],409);
    }
    dma_reply(['status'=>'ok','message'=>'Contraseña actualizada correctamente.']);
}
if ($action === 'identity') {
    // Comprobación ligera para restaurar el perfil híbrido Dirección + Docente.
    // Solo llega aquí una cuenta cuyo is_director fue validado en MySQL.
    dma_reply([
        'status'=>'ok',
        'is_director'=>true,
        'is_teacher'=> (int)$account['type'] === 2,
        'name'=>(string)$account['name'],
    ]);
}
// Asistencia institucional: acceso exclusivo al Director validado por MySQL.
// Ninguna acción modifica la tabla asistencia ni depende de cursos dictados.
if (in_array($action, ['attendance_overview','attendance_classroom','attendance_student'], true)) {
    date_default_timezone_set('America/Lima');
    $GLOBALS['dma_stage']='asistencia_validar';
    $tableReady=$conn->query("SHOW TABLES LIKE 'asistencia'");
    if (!$tableReady || $tableReady->num_rows===0) {
        dma_reply(['status'=>'error','message'=>'El colegio aún no tiene habilitado el módulo de asistencia.'],409);
    }
    $checkCol = static function(string $name) use ($conn): bool {
        $safe=$conn->real_escape_string($name);
        $found=$conn->query("SHOW COLUMNS FROM asistencia LIKE '$safe'");
        return $found && $found->num_rows>0;
    };
    $hasSchool=$checkCol('school_id');
    $hasCancelled=$checkCol('is_cancelled');
    $hasAcademicYear=$checkCol('academic_year_id');
    $date=trim((string)($_POST['date']??date('Y-m-d')));
    $validDate=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$validDate || $validDate->format('Y-m-d')!==$date) {
        dma_reply(['status'=>'error','message'=>'Fecha de asistencia no válida.'],422);
    }
    // La fecha determina el año de la consulta. Nunca interpretar un día de
    // 2024 como si correspondiera al aula actualmente matriculada en 2026.
    $GLOBALS['dma_stage']='asistencia_resolver_ano';
    $attendanceYear=null;
    $yearStmt=$conn->prepare(
        "SELECT id,year,is_active FROM academic_year
         WHERE school_id=? AND ? BETWEEN start_date AND end_date
         ORDER BY is_active DESC,id DESC LIMIT 1"
    );
    if (!$yearStmt) throw new RuntimeException('No se pudo resolver el año escolar.');
    $yearStmt->bind_param('is',$schoolId,$date);
    $yearStmt->execute();
    $attendanceYear=$yearStmt->get_result()->fetch_assoc();
    $yearStmt->close();
    if (!$attendanceYear) {
        // Calendarios antiguos pueden no tener límites registrados.
        $calendarYear=(int)$validDate->format('Y');
        $yearStmt=$conn->prepare(
            'SELECT id,year,is_active FROM academic_year
             WHERE school_id=? AND year=? ORDER BY id DESC LIMIT 1'
        );
        if ($yearStmt) {
            $yearStmt->bind_param('ii',$schoolId,$calendarYear);
            $yearStmt->execute();
            $attendanceYear=$yearStmt->get_result()->fetch_assoc();
            $yearStmt->close();
        }
    }
    if (!$attendanceYear) {
        dma_reply(['status'=>'error',
            'message'=>'No existe un año académico registrado para esta fecha.'],422);
    }
    $attendanceYearId=(int)$attendanceYear['id'];
    $historicAttendance=(int)$attendanceYear['is_active']!==1;
    $level=trim((string)($_POST['level']??''));
    $grade=trim(str_replace(['°','º'],'',(string)($_POST['grade']??'')));
    $section=strtoupper(trim((string)($_POST['section']??'')));
    if (strlen($level)>50 || strlen($grade)>12 || strlen($section)>20) {
        dma_reply(['status'=>'error','message'=>'El filtro de aula es inválido.'],422);
    }
    if ($action==='attendance_classroom' && ($level==='' || $grade==='' || $section==='')) {
        dma_reply(['status'=>'error','message'=>'Selecciona nivel, grado y sección.'],422);
    }
    $historicRoster=[];
    if ($historicAttendance) {
        require_once __DIR__.'/../includes/grades_report_historical_classroom.php';
        $historicRoster=grhc_student_classrooms($conn,$schoolId,$attendanceYearId);
        // Pertenencia a una sola aula histórica, nunca grado/sección actual.
        foreach ($historicRoster as $studentId=>$roomKey) {
            [$hLevel,$hGrade,$hSection]=explode('|',$roomKey);
            if ($level!=='' && grhc_level($level)!==$hLevel) unset($historicRoster[$studentId]);
            elseif ($grade!=='' && grhc_grade($grade)!==$hGrade) unset($historicRoster[$studentId]);
            elseif ($section!=='' && grhc_section($section)!==$hSection) unset($historicRoster[$studentId]);
        }
        $historicIds=$historicRoster ? implode(',',array_map('intval',array_keys($historicRoster))) : '0';
        $classWhere="st.school_id=? AND st.id IN ($historicIds)";
    } else {
        $classWhere="st.school_id=? AND st.status='Activo'";
    }
    $types='i';
    $params=[$schoolId];
    if (!$historicAttendance && $level!=='') {
        $classWhere.=" AND LOWER(TRIM(st.nivel))=LOWER(TRIM(?))";
        $types.='s';$params[]=$level;
    }
    if (!$historicAttendance && $grade!=='') {
        $classWhere.=" AND TRIM(REPLACE(REPLACE(st.grado,'°',''),'º',''))=?";
        $types.='s';$params[]=$grade;
    }
    if (!$historicAttendance && $section!=='') {
        $classWhere.=" AND UPPER(COALESCE(NULLIF(TRIM(st.seccion),''),'U'))=?";
        $types.='s';$params[]=$section;
    }
    if ($action==='attendance_student') {
        $GLOBALS['dma_stage']='asistencia_historial_estudiante';
        $studentId=(int)($_POST['student_id']??0);
        if ($studentId<=0) dma_reply(['status'=>'error','message'=>'Selecciona un estudiante.'],422);
        $studentStmt=$conn->prepare("SELECT st.id,st.id_no,st.name,st.nivel,st.grado,st.seccion FROM student st WHERE st.id=? AND st.school_id=? LIMIT 1");
        $studentStmt->bind_param('ii',$studentId,$schoolId);
        $studentStmt->execute();
        $student=$studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();
        if (!$student) dma_reply(['status'=>'error','message'=>'El estudiante no pertenece a este colegio.'],404);
        $from=$validDate->modify('first day of this month')->format('Y-m-d');
        $until=$validDate->modify('first day of next month')->format('Y-m-d');
        $where=['a.student_id=?','a.fecha>=?','a.fecha<?'];
        $paramTypes='iss';$markParams=[$studentId,$from,$until];
        if ($hasSchool){$where[]='a.school_id=?';$paramTypes.='i';$markParams[]=$schoolId;}
        if ($hasCancelled) $where[]='COALESCE(a.is_cancelled,0)=0';
        if ($hasAcademicYear) {
            $where[]='(a.academic_year_id=? OR a.academic_year_id IS NULL)';
            $paramTypes.='i';$markParams[]=$attendanceYearId;
        }
        $stmt=$conn->prepare("SELECT a.fecha,a.tipo,a.hora,a.estado FROM asistencia a WHERE ".implode(' AND ',$where)." ORDER BY a.fecha,a.hora,a.id");
        $stmt->bind_param($paramTypes,...$markParams);$stmt->execute();
        $raw=[];
        foreach ($stmt->get_result() as $mark) {
            $mark['anio_academico']=(string)$validDate->format('Y');
            $mark['anio_descripcion']='';
            $raw[]=$mark;
        }
        $stmt->close();
        $days=attendance_group_days($raw);
        dma_reply(['status'=>'ok','read_only'=>true,'student'=>$student,'month'=>$from,
                   'days'=>$days,'has_records'=>count($days)>0]);
    }
    $GLOBALS['dma_stage']='asistencia_nomina';
    $rosterStmt=$conn->prepare("SELECT st.id,st.id_no,st.name,st.nivel,st.grado,COALESCE(NULLIF(TRIM(st.seccion),''),'U') seccion
         FROM student st WHERE $classWhere ORDER BY st.nivel,st.grado,st.seccion,st.name,st.id");
    $rosterStmt->bind_param($types,...$params);$rosterStmt->execute();
    $students=[];$groups=[];
    foreach ($rosterStmt->get_result() as $st) {
        $id=(int)$st['id'];$st['id']=$id;$st['status']='Sin registro';$st['entrada']=null;$st['salida']=null;
        if ($historicAttendance) {
            $verified=$historicRoster[$id]??'';
            if ($verified==='') continue;
            [$hLevel,$hGrade,$hSection]=explode('|',$verified);
            $st['nivel']=ucfirst($hLevel);
            $st['grado']=$hGrade;
            $st['seccion']=strtoupper($hSection);
        }
        $key=dma_class_key($st['nivel'],$st['grado'],$st['seccion']);
        $st['_key']=$key;
        $students[$id]=$st;
        if (!isset($groups[$key])) {
            $groups[$key]=['level'=>$st['nivel'],'grade'=>$st['grado'],'section'=>$st['seccion'],
              'expected'=>0,'recorded'=>0,'present'=>0,'late'=>0,'absent'=>0,
              'justified'=>0,'permission'=>0,'other'=>0,'unmarked'=>0,
              'with_entry'=>0,'with_exit'=>0];
        }
        $groups[$key]['expected']++;
    }
    $rosterStmt->close();
    $GLOBALS['dma_stage']='asistencia_marcaciones';
    // Obtener todas las marcaciones de este colegio y día EN UNA consulta.
    // La nómina se valida por institución; nunca usar school_id del cliente.
    $markWhere=['a.fecha>=?','a.fecha<?','st.school_id=?'];
    $start=$date;$end=$validDate->modify('+1 day')->format('Y-m-d');
    $mt='ssi';$mp=[$start,$end,$schoolId];
    if ($hasSchool) $markWhere[]='a.school_id=st.school_id';
    if ($hasCancelled) $markWhere[]='COALESCE(a.is_cancelled,0)=0';
    if ($hasAcademicYear) {
        $markWhere[]="(a.academic_year_id=$attendanceYearId OR a.academic_year_id IS NULL)";
    }
    $markStmt=$conn->prepare("SELECT a.student_id,a.fecha,a.tipo,a.hora,a.estado
       FROM asistencia a INNER JOIN student st ON st.id=a.student_id
       WHERE ".implode(' AND ',$markWhere)." ORDER BY a.fecha,a.hora,a.id");
    $markStmt->bind_param($mt,...$mp);$markStmt->execute();
    $rawByStudent=[];
    foreach ($markStmt->get_result() as $mark) {
        $id=(int)$mark['student_id'];
        if (!isset($students[$id])) continue;
        $mark['anio_academico']=(string)$validDate->format('Y');
        $mark['anio_descripcion']='';
        $rawByStudent[$id][]=$mark;
    }
    $markStmt->close();
    $summary=['expected'=>count($students),'recorded'=>0,'present'=>0,'late'=>0,
      'absent'=>0,'justified'=>0,'permission'=>0,'other'=>0,'unmarked'=>0,
      'with_entry'=>0,'with_exit'=>0];
    $statusCodes=['Presente'=>'present','Tarde'=>'late','Ausente'=>'absent',
      'Ausente Justificada'=>'justified','Permiso'=>'permission'];
    foreach ($students as $studentId=>&$studentRow) {
        $days=attendance_group_days($rawByStudent[$studentId]??[]);
        $day=$days[0]??null;
        $status=$day===null?'Sin registro':(string)$day['estado'];
        $code=$day===null?'unmarked':($statusCodes[$status]??'other');
        $summary[$code]++;$groups[$studentRow['_key']][$code]++;
        if ($day!==null) {
            $summary['recorded']++;$groups[$studentRow['_key']]['recorded']++;
            $studentRow['status']=$status;
            $studentRow['entrada']=$day['entrada'];
            $studentRow['salida']=$day['salida'];
            foreach (['entrada'=>'with_entry','salida'=>'with_exit'] as $field=>$metric) {
                if (!empty($day[$field])) {
                    $summary[$metric]++;$groups[$studentRow['_key']][$metric]++;
                }
            }
        }
        unset($studentRow['_key']);
    }
    unset($studentRow);
    $rooms=array_values($groups);
    if ($action==='attendance_classroom') {
        dma_reply(['status'=>'ok','read_only'=>true,'date'=>$date,
          'academic_year_id'=>$attendanceYearId,
          'roster_scope'=>$historicAttendance?'historical_verified':'current',
          'summary'=>$summary,'students'=>array_values($students),'classrooms'=>$rooms]);
    }
    dma_reply(['status'=>'ok','read_only'=>true,'date'=>$date,
      'academic_year_id'=>$attendanceYearId,
      'roster_scope'=>$historicAttendance?'historical_verified':'current',
      'summary'=>$summary,'classrooms'=>$rooms]);
}

if ($action === 'student_course' || $action === 'course_roster') {
    // Una ficha individual o el libro del aula por curso con una sola
    // consulta para toda la nómina, sin calcular un promedio alternativo.
    $rosterMode = $action === 'course_roster';
    // Ficha institucional unitaria, en solo lectura. No aceptar school_id,
    // teacher_id ni permiso Director enviados por el cliente.
    $GLOBALS['dma_stage'] = 'ficha_por_curso';
    $studentId = (int)($_POST['student_id'] ?? 0);
    $teacherCourseId = (int)($_POST['teacher_course_id'] ?? 0);
    $yearId = (int)($_POST['academic_year_id'] ?? 0);
    $bimester = (int)($_POST['bimester'] ?? 0);
    if ((!$rosterMode && $studentId <= 0) || $teacherCourseId <= 0 ||
        $yearId <= 0 || $bimester < 1 || $bimester > 4) {
        dma_reply(['status'=>'error','message'=>'Selecciona estudiante, curso, año y bimestre válidos.'],422);
    }

    $student = null;
    $students = [];
    if (!$rosterMode) {
        $studentStmt = $conn->prepare(
            "SELECT id,id_no,name,nivel,grado,seccion,status FROM student
             WHERE id=? AND school_id=? LIMIT 1"
        );
        $studentStmt->bind_param('ii', $studentId, $schoolId);
        $studentStmt->execute();
        $student = $studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();
        if (!$student) dma_reply(['status'=>'error','message'=>'Estudiante fuera de la institución.'],404);
    }

    $courseStmt = $conn->prepare(
        "SELECT tc.id,tc.course_id,tc.teacher_id,tc.academic_year_id,
                tc.grado,tc.seccion,ac.level,ac.name course_name,
                COALESCE(t.name,'Docente no asignado') teacher_name,
                ay.year academic_year,ay.is_active academic_year_active
         FROM teacher_courses tc
         INNER JOIN academic_courses ac ON ac.id=tc.course_id
             AND ac.school_id=tc.school_id
         INNER JOIN academic_year ay ON ay.id=tc.academic_year_id
             AND ay.school_id=tc.school_id
         LEFT JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
         WHERE tc.id=? AND tc.school_id=? AND tc.academic_year_id=? LIMIT 1"
    );
    $courseStmt->bind_param('iii', $teacherCourseId, $schoolId, $yearId);
    $courseStmt->execute();
    $course = $courseStmt->get_result()->fetch_assoc();
    $courseStmt->close();
    if (!$course) dma_reply(['status'=>'error','message'=>'Curso fuera del año o institución seleccionados.'],404);

    // La matrícula actual vincula la ficha con el aula. Para ciclos
    // históricos únicamente se admite un alumno con calificaciones
    // registradas en ESTA asignación concreta.
    if (!$rosterMode) {
        $studentKey = dma_class_key(
            (string)$student['nivel'], (string)$student['grado'], (string)$student['seccion']
        );
        $courseKey = dma_class_key(
            (string)$course['level'], (string)$course['grado'], (string)$course['seccion']
        );
        if ($studentKey !== $courseKey) {
            $historyStmt = $conn->prepare(
                "SELECT eg.id FROM evaluation_grades eg
                 INNER JOIN evaluations e ON e.id=eg.evaluation_id
                 WHERE eg.student_id=? AND e.teacher_course_id=? LIMIT 1"
            );
            $historyStmt->bind_param('ii', $studentId, $teacherCourseId);
            $historyStmt->execute();
            $wasEnrolled = (bool)$historyStmt->get_result()->fetch_assoc();
            $historyStmt->close();
            if (!$wasEnrolled) {
                dma_reply(['status'=>'error','message'=>'No hay vinculación académica de este estudiante con el curso.'],404);
            }
        }
    }

    // Un alumno con notas migradas a otra aula no puede consultar por ellas
    // asignaturas de un grado o sección al que nunca perteneció.
    if (!$rosterMode && (int)$course['academic_year_active'] !== 1) {
        require_once __DIR__.'/../includes/grades_report_historical_classroom.php';
        $studentClass=grhc_student_classrooms($conn,$schoolId,$yearId)[$studentId]??'';
        $courseClass=grhc_key($course['level'],$course['grado'],$course['seccion']);
        if ($studentClass==='' || $studentClass!==$courseClass) {
            dma_reply(['status'=>'error',
                'message'=>'El estudiante no pertenece al aula histórica del curso.'],404);
        }
    }

    if ($rosterMode) {
        $GLOBALS['dma_stage'] = 'nomina_del_curso';
        $isActiveYear = (int)$course['academic_year_active'] === 1;
        if ($isActiveYear) {
            // Matrícula del aula actual, aun si todavía no hay evaluaciones.
            $rosterStmt = $conn->prepare(
                "SELECT st.id,st.id_no,st.name FROM student st
                 WHERE st.school_id=? AND st.status='Activo'
                   AND LOWER(TRIM(st.nivel))=LOWER(TRIM(?))
                   AND TRIM(REPLACE(REPLACE(st.grado,'°',''),'º',''))=?
                   AND UPPER(COALESCE(NULLIF(TRIM(st.seccion),''),'U'))=?
                 ORDER BY st.name,st.id LIMIT 300"
            );
            $level = (string)$course['level'];
            $grade = trim(str_replace(['°','º'],'',(string)$course['grado']));
            $section = strtoupper(trim((string)$course['seccion']));
            if ($section === '') $section = 'U';
            $rosterStmt->bind_param('isss', $schoolId,$level,$grade,$section);
        } else {
            // La nómina histórica reproduce los criterios del Reporte de Notas web.
            require_once __DIR__.'/../includes/grades_report_historical_classroom.php';
            $historicalGuard=grhc_sql($conn,'st','tc','ac',$schoolId,$yearId,
                (string)$course['level'],(string)$course['grado'],(string)$course['seccion']);
            $rosterStmt = $conn->prepare(
                "SELECT DISTINCT st.id,st.id_no,st.name FROM student st
                 INNER JOIN evaluation_grades eg ON eg.student_id=st.id
                 INNER JOIN evaluations e ON e.id=eg.evaluation_id
                 INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
                     AND tc.school_id=st.school_id
                 INNER JOIN academic_courses ac ON ac.id=tc.course_id
                     AND ac.school_id=tc.school_id
                 WHERE st.school_id=? AND tc.id=? AND tc.academic_year_id=?
                   {$historicalGuard}
                 ORDER BY st.name,st.id LIMIT 300"
            );
            $rosterStmt->bind_param('iii',$schoolId,$teacherCourseId,$yearId);
        }
        $rosterStmt->execute();
        $rosterResult = $rosterStmt->get_result();
        while ($row=$rosterResult->fetch_assoc()) {
            $row['id']=(int)$row['id'];
            $students[]=$row;
        }
        $rosterStmt->close();
    }

    $GLOBALS['dma_stage'] = 'competencias_del_curso';
    $historicalYear=(int)$course['academic_year_active']!==1;
    $competencies = [];
    $competencyById = [];
    $compStmt = $conn->prepare(
        "SELECT id,name,percentage,is_active
         FROM general_course_competencies
         WHERE course_id=? AND teacher_id=? AND academic_year_id=?
         ORDER BY id"
    );
    $compStmt->bind_param('iii',
        $course['course_id'],$course['teacher_id'],$yearId);
    $compStmt->execute();
    $compRes = $compStmt->get_result();
    while ($row = $compRes->fetch_assoc()) {
        $id = (int)$row['id'];
        $active = (int)$row['is_active'] === 1;
        $competency = [
            'id'=>$id,'name'=>(string)$row['name'],
            'percentage'=>($active || $historicalYear)
                ? (float)$row['percentage'] : 0.0,
            'historical'=>!$active && !$historicalYear
        ];
        $competencies[] = $competency;
        $competencyById[$id] = count($competencies)-1;
    }
    $compStmt->close();

    $GLOBALS['dma_stage'] = 'evaluaciones_del_curso';
    $statusColumn = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
    $statusFilter = ($statusColumn && $statusColumn->num_rows)
        ? " AND (e.status IS NULL OR e.status<>'Anulada')" : '';
    // Para los historiales la asignación validada determina el año.
    // Una evaluación migrada puede no tener sincronizado e.academic_year_id.
    $evalOwnershipFilter=(int)$course['academic_year_active']===1
        ? " AND e.teacher_id=".(int)$course['teacher_id'].
            " AND e.academic_year_id=".$yearId : '';
    $evalStmt = $conn->prepare(
        "SELECT e.id,e.title,e.description FROM evaluations e
         WHERE e.teacher_course_id=? {$evalOwnershipFilter}
           AND e.bimestre=? {$statusFilter}
         ORDER BY e.created_at,e.id"
    );
    $evalStmt->bind_param('ii',$teacherCourseId,$bimester);
    $evalStmt->execute();
    $evaluations = [];
    $evaluationById = [];
    $evalRes = $evalStmt->get_result();
    while ($row = $evalRes->fetch_assoc()) {
        $id = (int)$row['id'];
        $evaluationById[$id] = count($evaluations);
        $evaluations[] = [
            'id'=>$id,'title'=>(string)$row['title'],
            'description'=>(string)($row['description']??''),
            'competency_ids'=>[], 'annulled'=>false
        ];
    }
    $evalStmt->close();

    $gradesByCompetency = [];
    $unassignedGrades = [];
    if ($evaluations) {
        $GLOBALS['dma_stage'] = 'vinculos_competencias';
        $evaluationIds = array_keys($evaluationById);
        $in = implode(',',array_map('intval',$evaluationIds));
        $linkSql = "SELECT ec.evaluation_id,ec.competencia_id,
                           COALESCE(gcc.name,'Competencia histórica') competency_name,
                           COALESCE(gcc.percentage,0) percentage
                    FROM evaluation_competencias ec
                    LEFT JOIN general_course_competencies gcc ON gcc.id=ec.competencia_id
                    WHERE ec.evaluation_id IN ($in)
                    ORDER BY ec.evaluation_id,ec.id";
        $links = $conn->query($linkSql);
        while ($row = $links->fetch_assoc()) {
            $eid = (int)$row['evaluation_id'];
            $cid = (int)$row['competencia_id'];
            if ($cid <= 0 || !isset($evaluationById[$eid])) continue;
            $index = $evaluationById[$eid];
            if (!in_array($cid,$evaluations[$index]['competency_ids'],true)) {
                $evaluations[$index]['competency_ids'][] = $cid;
            }
            if (!isset($competencyById[$cid])) {
                $competencyById[$cid] = count($competencies);
                $competencies[] = [
                    'id'=>$cid,'name'=>(string)$row['competency_name'],
                    'percentage'=>$historicalYear
                        ? (float)$row['percentage'] : 0.0,
                    'historical'=>!$historicalYear
                ];
            }
        }

        // En años cerrados la tabla de competencias puede contener otras
        // que no formaron parte del bimestre. Reporte web usa solo las
        // competencias asociadas a evaluaciones de ese periodo.
        if ($historicalYear) {
            $linkedCompetencyIds=[];
            foreach ($evaluations as $evaluation) {
                foreach ($evaluation['competency_ids'] as $linkedId) {
                    $linkedCompetencyIds[(int)$linkedId]=true;
                }
            }
            $competencies=array_values(array_filter(
                $competencies,
                static function(array $item) use ($linkedCompetencyIds): bool {
                    return isset($linkedCompetencyIds[(int)$item['id']]);
                }
            ));
        }

        $GLOBALS['dma_stage'] = 'notas_del_curso';
        $studentIds=$rosterMode
            ? array_column($students,'id') : [$studentId];
        if ($studentIds) {
            $studentIn=implode(',',array_map('intval',$studentIds));
            $gradesStmt = $conn->prepare(
                "SELECT eg.id,eg.student_id,eg.evaluation_id,eg.competencia_id,eg.grade
                 FROM evaluation_grades eg
                 WHERE eg.student_id IN ($studentIn) AND eg.evaluation_id IN ($in)
                 ORDER BY eg.id"
            );
            $gradesStmt->execute();
            $gradeRes=$gradesStmt->get_result();
            while ($row=$gradeRes->fetch_assoc()) {
            $cellStudentId=(int)$row['student_id'];
            $eid=(int)$row['evaluation_id'];
            if (!isset($evaluationById[$eid])) continue;
            $ids=$evaluations[$evaluationById[$eid]]['competency_ids'];
            $cid=(int)($row['competencia_id']??0);
            // Las celdas legacy sin competencia solo se atribuyen cuando
            // el vínculo de evaluación es inequívoco.
            if ($cid<=0 && count($ids)===1) $cid=$ids[0];
            if ($cid<=0 && count($ids)===0) {
                // Evaluaciones antiguas sin vínculo de competencia:
                // visualizarlas aparte, sin inventar aportes ni promedios.
                if (!$rosterMode) {
                    $unassignedGrades[$eid] = trim((string)($row['grade']??''));
                }
                continue;
            }
            if ($cid<=0 || !in_array($cid,$ids,true)) continue;
            $gradesByCompetency[(string)$eid][(string)$cid][(string)$cellStudentId] =
                trim((string)($row['grade']??''));
            }
            $gradesStmt->close();
        }
    }
    $unassigned = [];
    foreach ($evaluations as $evaluation) {
        if ($evaluation['competency_ids']) continue;
        $eid=(int)$evaluation['id'];
        $unassigned[]=[
            'id'=>$eid,
            'title'=>$evaluation['title'],
            'grade'=>$unassignedGrades[$eid]??'',
            'historical'=>true
        ];
    }
    if (!$rosterMode) $student['id']=(int)$student['id'];
    dma_reply([
        'status'=>'ok','read_only'=>true,
        'student'=>$student,
        'students'=>$students,
        'roster_scope'=>$rosterMode && (int)$course['academic_year_active']!==1
            ? 'historical_grades' : 'current_classroom',
        'academic_year_id'=>$yearId,'bimester'=>$bimester,
        'course'=>[
            'teacher_course_id'=>(int)$course['id'],
            'name'=>(string)$course['course_name'],
            'teacher_name'=>(string)$course['teacher_name'],
            'level'=>(string)$course['level'],
            'grade'=>(string)$course['grado'],
            'section'=>(string)$course['seccion'],
            'year'=>(string)$course['academic_year']
        ],
        // Contrato de TeacherCourseStudentSummary: misma regla y escala
        // ponderada de la ficha individual docente, nunca una media distinta.
        'competencies'=>$competencies,
        'evaluations'=>$evaluations,
        'grades_by_competency'=>$gradesByCompetency,
        'unassigned_evaluations'=>$unassigned
    ]);
}

if ($action === 'students') {
    $GLOBALS['dma_stage'] = 'nomina_de_aula';
    $studentYearId=(int)($_POST['academic_year_id']??0);
    if ($studentYearId<=0) dma_reply(['status'=>'error','message'=>'Selecciona un año académico.'],422);
    $yearStmt=$conn->prepare('SELECT is_active FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
    $yearStmt->bind_param('ii',$studentYearId,$schoolId);
    $yearStmt->execute();
    $yearRow=$yearStmt->get_result()->fetch_assoc();
    $yearStmt->close();
    if (!$yearRow) dma_reply(['status'=>'error','message'=>'Año no autorizado.'],404);
    $level = trim((string)($_POST['level'] ?? ''));
    $grade = trim(str_replace(['°','º'], '', (string)($_POST['grade'] ?? '')));
    $section = strtoupper(trim((string)($_POST['section'] ?? '')));
    if ($level === '' || $grade === '' || $section === '' ||
        strlen($level)>50 || strlen($grade)>12 || strlen($section)>20) {
        dma_reply(['status'=>'error','message'=>'Selecciona un aula válida.'], 422);
    }
    if ((int)$yearRow['is_active']!==1) {
        // Mismo criterio de Reporte de Notas: curso y grado del año consultado,
        // con nivel histórico validado; nunca utilizar el grado actual del alumno.
        $GLOBALS['dma_stage']='nomina_historica_de_aula';
        require_once __DIR__.'/../includes/grades_report_historical_classroom.php';
        $historicalGuard=grhc_sql($conn,'st','tc','ac',$schoolId,$studentYearId,
            $level,$grade,$section);
        $stmt=$conn->prepare(
            "SELECT DISTINCT st.id,st.id_no,st.name
             FROM student st
             INNER JOIN evaluation_grades eg ON eg.student_id=st.id
             INNER JOIN evaluations ev ON ev.id=eg.evaluation_id
             INNER JOIN teacher_courses tc ON tc.id=ev.teacher_course_id
                 AND tc.school_id=st.school_id
             INNER JOIN academic_courses ac ON ac.id=tc.course_id
                 AND ac.school_id=tc.school_id
             WHERE st.school_id=? AND tc.school_id=? AND tc.academic_year_id=?
               AND LOWER(REPLACE(TRIM(ac.level),' ',''))=LOWER(REPLACE(TRIM(?),' ',''))
               AND TRIM(REPLACE(REPLACE(tc.grado,'°',''),'º',''))=?
               AND UPPER(COALESCE(NULLIF(TRIM(tc.seccion),''),'U'))=?
               {$historicalGuard}
             ORDER BY st.name,st.id LIMIT 300"
        );
        if (!$stmt) dma_reply(['status'=>'error','message'=>'No se pudo consultar la nómina histórica.'],500);
        $stmt->bind_param('iiisss',$schoolId,$schoolId,$studentYearId,$level,$grade,$section);
        $stmt->execute();
        $students=[];
        foreach ($stmt->get_result() as $row) {
            $row['id']=(int)$row['id'];
            // La etiqueta debe ser el aula elegida, no una variante de
            // grado/sección guardada en alguna asignación antigua.
            $row['nivel']=$level;
            $row['grado']=$grade;
            $row['seccion']=$section;
            $students[]=$row;
        }
        $stmt->close();
        dma_reply(['status'=>'ok','students'=>$students,
            'roster_scope'=>'historical_verified_classroom','read_only'=>true]);
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
    $GLOBALS['dma_stage'] = 'ficha_estudiante';
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
        $evStatusCol=$conn->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
        $visibleEvaluations=($evStatusCol && $evStatusCol->num_rows>0)
            ? " AND COALESCE(ev.status,'Activa')<>'Anulada'" : '';
        $stmt=$conn->prepare(
            "SELECT ac.name course_name,t.name teacher_name,
                    ev.bimestre, ev.title evaluation_name,
                    COALESCE(gcc.name,'Competencia histórica') competency_name,
                    eg.grade
             FROM evaluation_grades eg
             INNER JOIN evaluations ev ON ev.id=eg.evaluation_id
             INNER JOIN teacher_courses tc ON tc.id=ev.teacher_course_id
             INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
             LEFT JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
             LEFT JOIN general_course_competencies gcc ON gcc.id=eg.competencia_id
             WHERE eg.student_id=? AND tc.school_id=? AND tc.academic_year_id=?
               {$visibleEvaluations}
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
$GLOBALS['dma_stage'] = 'consultar_colegio';
$schoolStmt=$conn->prepare('SELECT name,logo_path FROM schools WHERE id=? LIMIT 1');
$schoolStmt->bind_param('i',$schoolId);
$schoolStmt->execute();
$school=$schoolStmt->get_result()->fetch_assoc() ?: [];
$schoolStmt->close();
$GLOBALS['dma_stage'] = 'consultar_anos';
$yearsStmt=$conn->prepare('SELECT id,year,description,is_active FROM academic_year WHERE school_id=? ORDER BY is_active DESC,year DESC,id DESC');
$yearsStmt->bind_param('i',$schoolId);
$yearsStmt->execute();
$years=[];
$yearsResult=$yearsStmt->get_result();
if (!$yearsResult) {
    throw new RuntimeException('No se pudo obtener el listado de años académicos.');
}
while ($row=$yearsResult->fetch_assoc()) {
    $row['id']=(int)$row['id'];
    $row['is_active']=(int)$row['is_active']===1;
    $years[]=$row;
}
$yearsResult->free();
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
$GLOBALS['dma_stage'] = 'resumen_institucional';
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
$GLOBALS['dma_stage'] = 'obtener_aulas';
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
// En años históricos no usar las aulas y matrículas ACTIVAS como si
// pertenecieran a ese año. Solo usar asignaciones del año solicitado.
if ($selectedYear && empty($selectedYear['is_active'])) {
    $classrooms=[];
    $byKey=[];
}
$courses=[];
if ($yearId>0) {
    $GLOBALS['dma_stage'] = 'cursos_asignados';
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

// Estado REAL de cierre bimestral. Las notas al 100% NO implican cierre.
// Cuando la migración falta, null = desconocido; nunca declarar "Abierto".
$closureSupported=false;
$closedCount=0;
$closureTable=$conn->query("SHOW TABLES LIKE 'grade_period_closures'");
if ($closureTable && $closureTable->num_rows>0) {
    $closureSupported=true;
    if ($courses) {
        $stmt=$conn->prepare(
            "SELECT gpc.teacher_course_id,gpc.closed_at FROM grade_period_closures gpc
             WHERE gpc.school_id=? AND gpc.academic_year_id=?
               AND gpc.bimester=? AND gpc.status='Cerrado'"
        );
        if (!$stmt) throw new RuntimeException('No se pudo consultar el cierre bimestral.');
        $stmt->bind_param('iii',$schoolId,$yearId,$bimester);
        $stmt->execute();
        foreach ($stmt->get_result() as $row) {
            $id=(int)$row['teacher_course_id'];
            if (!isset($courses[$id])) continue;
            $courses[$id]['closure_status']='Cerrado';
            $courses[$id]['closed_at']=(string)($row['closed_at']??'');
            $closedCount++;
        }
        $stmt->close();
    }
}
foreach ($courses as &$course) {
    if (!isset($course['closure_status'])) {
        $course['closure_status']=$closureSupported?'Abierto':null;
        $course['closed_at']=null;
    }
}
unset($course);
$totals['closed_courses']=$closureSupported?$closedCount:null;
$totals['closure_supported']=$closureSupported;

// Avance institucional: promedio del porcentaje de celdas llenas POR
// competencia. Una competencia sin evaluaciones cuenta como 0%.
// Se devuelve null cuando el curso todavía no tiene competencias configuradas.
if ($courses && !empty($selectedYear['is_active'])) {
    $GLOBALS['dma_stage'] = 'calcular_avance';
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
$GLOBALS['dma_stage'] = 'respuesta';
dma_reply([
    'status'=>'ok','director_name'=>(string)$account['name'],
    'school_name'=>(string)($school['name']??''),
    'academic_year'=>$selectedYear,'years'=>$years,
    'selected_bimester'=>$bimester,'summary'=>$totals,
    'classrooms'=>$classrooms,'read_only'=>true
]);
