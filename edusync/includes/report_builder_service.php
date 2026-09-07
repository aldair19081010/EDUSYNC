<?php

function rbColumnExists(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $safeColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
        $cache[$key] = $result && $result->num_rows > 0;
    }
    return $cache[$key];
}

function rbDefinitions(): array {
    return [
        'enrollment' => [
            'title' => 'Nómina de estudiantes por aula',
            'views' => ['detail'],
            'columns' => [
                'student_code'=>'Código','student_name'=>'Estudiante','level'=>'Nivel',
                'grade_section'=>'Grado y sección','student_status'=>'Estado',
                'guardian'=>'Apoderado','phone'=>'Teléfono','email'=>'Correo'
            ],
            'defaults'=>['student_code','student_name','level','grade_section','student_status','guardian','phone']
        ],
        'academic' => [
            'title' => 'Rendimiento académico',
            'views' => ['detail', 'grouped', 'consolidated'],
            'columns' => [
                'student_code' => 'Código', 'student_name' => 'Estudiante', 'level' => 'Nivel',
                'grade_section' => 'Grado y sección', 'course' => 'Curso', 'period' => 'Periodo',
                'evaluations' => 'Evaluaciones', 'average' => 'Promedio', 'minimum' => 'Nota mínima',
                'maximum' => 'Nota máxima', 'ungraded' => 'Sin calificar'
            ],
            'defaults' => ['student_code','student_name','level','grade_section','course','period','evaluations','average']
        ],
        'attendance' => [
            'title' => 'Asistencia',
            'views' => ['detail', 'grouped', 'consolidated'],
            'columns' => [
                'date' => 'Fecha', 'student_code' => 'Código', 'student_name' => 'Estudiante',
                'level' => 'Nivel', 'grade_section' => 'Grado y sección', 'entry_type' => 'Tipo',
                'time' => 'Hora', 'attendance_status' => 'Estado', 'records' => 'Registros',
                'present' => 'Presentes', 'late' => 'Tardanzas', 'absent' => 'Ausencias',
                'justified' => 'Justificadas', 'attendance_rate' => 'Porcentaje de asistencia'
            ],
            'defaults' => ['date','student_code','student_name','level','grade_section','entry_type','time','attendance_status']
        ],
        'financial' => [
            'title' => 'Situación financiera',
            'views' => ['detail', 'grouped', 'consolidated'],
            'columns' => [
                'student_code' => 'Código', 'student_name' => 'Estudiante', 'level' => 'Nivel',
                'grade_section' => 'Grado y sección', 'concept' => 'Concepto', 'issue_date' => 'Emisión',
                'due_date' => 'Vencimiento', 'debt_status' => 'Estado', 'original_amount' => 'Importe original',
                'discount' => 'Descuento', 'effective_amount' => 'Importe final', 'paid' => 'Pagado',
                'balance' => 'Saldo', 'debts' => 'Deudas'
            ],
            'defaults' => ['student_code','student_name','level','grade_section','concept','due_date','debt_status','effective_amount','paid','balance']
        ],
        'teachers' => [
            'title' => 'Carga docente',
            'views' => ['detail', 'grouped', 'consolidated'],
            'columns' => [
                'teacher_code' => 'Código', 'teacher_name' => 'Docente', 'teacher_status' => 'Estado',
                'level' => 'Nivel', 'course' => 'Curso', 'grade_section' => 'Grado y sección',
                'weekly_hours' => 'Horas semanales', 'assignments' => 'Asignaciones',
                'courses' => 'Cursos diferentes', 'classrooms' => 'Aulas'
            ],
            'defaults' => ['teacher_code','teacher_name','teacher_status','level','course','grade_section','weekly_hours']
        ]
    ];
}

function rbFilters(mysqli $conn): array {
    $allowedViews = ['detail','grouped','consolidated'];
    $definitions = rbDefinitions();
    $preset = $_GET['preset'] ?? 'roster';
    $catalog = [
        'roster'=>['enrollment','detail',['student_code','student_name','level','grade_section','student_status','guardian','phone']],
        'risk'=>['academic','consolidated',['student_code','student_name','level','grade_section','evaluations','average','minimum','maximum']],
        'attendance_alert'=>['attendance','consolidated',['student_code','student_name','level','grade_section','records','present','late','absent','justified','attendance_rate']],
        'morosity'=>['financial','detail',['student_code','student_name','level','grade_section','concept','due_date','effective_amount','paid','balance']],
        'partial_payments'=>['financial','detail',['student_code','student_name','level','grade_section','concept','due_date','effective_amount','paid','balance']],
        'teacher_load'=>['teachers','consolidated',['teacher_code','teacher_name','teacher_status','weekly_hours','assignments','courses','classrooms']]
    ];
    if(!isset($catalog[$preset]))$preset='roster';
    [$report,$view,$columns]=$catalog[$preset];
    $clean = static function ($value) use ($conn) { return $conn->real_escape_string(trim((string)$value)); };
    return [
        'report'=>$report, 'view'=>$view, 'columns'=>$columns,
        'year_id'=>max(0, (int)($_GET['year_id'] ?? 0)),
        'level'=>$clean($_GET['level'] ?? ''), 'grade'=>$clean($_GET['grade'] ?? ''),
        'section'=>$clean($_GET['section'] ?? ''), 'status'=>$clean($_GET['status'] ?? ''),
        'period'=>$clean($_GET['period'] ?? ''),
        'preset'=>$preset,
        'date_from'=>preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '',
        'date_to'=>preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'] ?? '') ? $_GET['date_to'] : ''
    ];
}

function rbBuild(mysqli $conn, int $schoolId, array $f): array {
    $year = $f['year_id']; $level=$f['level']; $grade=$f['grade']; $section=$f['section'];
    $commonStudent = "s.school_id=$schoolId" . ($level!==''?" AND s.nivel='$level'":'') . ($grade!==''?" AND s.grado='$grade'":'') . ($section!==''?" AND s.seccion='$section'":'');
    if ($f['report']==='enrollment') {
        $studentStatus=$f['status']!==''?$f['status']:'Activo';
        $sql="SELECT s.id_no student_code,s.name student_name,s.nivel level,CONCAT(s.grado,' ',COALESCE(s.seccion,'')) grade_section,s.status student_status,TRIM(CONCAT(COALESCE(s.tutor1_nombre,''),' ',COALESCE(s.tutor1_apellido,''))) guardian,COALESCE(NULLIF(s.tutor1_telefono,''),s.contact) phone,s.email FROM student s WHERE $commonStudent AND s.status='$studentStatus' ORDER BY s.nivel,s.grado,s.seccion,s.name";
    } elseif ($f['report']==='academic') {
        $where = "tc.school_id=$schoolId" . ($year?" AND e.academic_year_id=$year":'') . ($level!==''?" AND tc.level='$level'":'') . ($grade!==''?" AND tc.grado='$grade'":'') . ($section!==''?" AND tc.seccion='$section'":'') . ($f['period']!==''?" AND e.bimestre='{$f['period']}'":'');
        if ($f['view']==='grouped') {
            $sql="SELECT '' student_code,'Todos los estudiantes' student_name,tc.level,CONCAT(tc.grado,' ',tc.seccion) grade_section,ac.name course,e.bimestre period,COUNT(DISTINCT e.id) evaluations,ROUND(AVG(CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2)) END),2) average,MIN(CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2)) END) minimum,MAX(CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2)) END) maximum,SUM(eg.grade IS NULL OR eg.grade='') ungraded FROM evaluations e JOIN teacher_courses tc ON tc.id=e.teacher_course_id JOIN academic_courses ac ON ac.id=tc.course_id LEFT JOIN evaluation_grades eg ON eg.evaluation_id=e.id WHERE $where GROUP BY tc.level,tc.grado,tc.seccion,ac.id,e.bimestre ORDER BY tc.level,tc.grado,tc.seccion,ac.name,e.bimestre";
        } else {
            $group=$f['view']==='consolidated'?'s.id':'s.id,ac.id,e.bimestre';
            $sql="SELECT s.id_no student_code,s.name student_name,s.nivel level,CONCAT(s.grado,' ',COALESCE(s.seccion,'')) grade_section,".($f['view']==='consolidated'?"'Todos los cursos'":"ac.name")." course,".($f['view']==='consolidated'?"'Todos'":"e.bimestre")." period,COUNT(DISTINCT e.id) evaluations,ROUND(AVG(CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2)) END),2) average,MIN(CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2)) END) minimum,MAX(CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2)) END) maximum,SUM(eg.grade IS NULL OR eg.grade='') ungraded FROM evaluation_grades eg JOIN evaluations e ON e.id=eg.evaluation_id JOIN teacher_courses tc ON tc.id=e.teacher_course_id JOIN academic_courses ac ON ac.id=tc.course_id JOIN student s ON s.id=eg.student_id WHERE $where AND $commonStudent GROUP BY $group ORDER BY s.name,ac.name,e.bimestre";
        }
    } elseif ($f['report']==='attendance') {
        $date="".($f['date_from']!==''?" AND a.fecha>='{$f['date_from']}'":'').($f['date_to']!==''?" AND a.fecha<='{$f['date_to']}'":'');
        $status=$f['status']!==''?" AND a.estado='{$f['status']}'":'';
        if ($f['view']==='detail') {
            $sql="SELECT DATE_FORMAT(a.fecha,'%d/%m/%Y') date,s.id_no student_code,s.name student_name,s.nivel level,CONCAT(s.grado,' ',COALESCE(s.seccion,'')) grade_section,a.tipo entry_type,TIME_FORMAT(a.hora,'%H:%i') time,a.estado attendance_status,1 records,0 present,0 late,0 absent,0 justified,NULL attendance_rate FROM asistencia a JOIN student s ON s.id=a.student_id WHERE $commonStudent$date$status ORDER BY a.fecha DESC,s.name,a.tipo";
        } else {
            $group=$f['view']==='grouped'?'s.nivel,s.grado,s.seccion':'s.id';
            $sql="SELECT '' date,".($f['view']==='grouped'?"''":"s.id_no")." student_code,".($f['view']==='grouped'?"CONCAT(s.nivel,' ',s.grado,' ',COALESCE(s.seccion,''))":"s.name")." student_name,s.nivel level,CONCAT(s.grado,' ',COALESCE(s.seccion,'')) grade_section,'' entry_type,'' time,'Resumen' attendance_status,COUNT(*) records,SUM(LOWER(a.estado) IN ('presente','temprano','normal')) present,SUM(LOWER(a.estado)='tarde') late,SUM(LOWER(a.estado)='ausente') absent,SUM(LOWER(a.estado) LIKE '%justific%') justified,ROUND(100*SUM(LOWER(a.estado) IN ('presente','temprano','normal','tarde'))/NULLIF(COUNT(*),0),1) attendance_rate FROM asistencia a JOIN student s ON s.id=a.student_id WHERE $commonStudent$date$status GROUP BY $group ORDER BY s.nivel,s.grado,s.seccion,s.name";
        }
    } elseif ($f['report']==='financial') {
        $hasDebtStatus=rbColumnExists($conn,'student_ef_list','debt_status');$hasDue=rbColumnExists($conn,'student_ef_list','due_date');$hasIssue=rbColumnExists($conn,'student_ef_list','issue_date');
        $debtStatus=$hasDebtStatus?'ef.debt_status':"'Activa'";$due=$hasDue?'ef.due_date':'NULL';$issue=$hasIssue?'ef.issue_date':'DATE(ef.date_created)';
        $where=$commonStudent.($year?" AND c.academic_year_id=$year":'').($f['status']!==''&&$hasDebtStatus?" AND ef.debt_status='{$f['status']}'":'');
        $base="FROM student_ef_list ef JOIN student s ON s.id=ef.student_id JOIN courses c ON c.id=ef.course_id WHERE $where";
        if($f['view']==='detail'){$group='ef.id';$concept='c.course';}elseif($f['view']==='grouped'){$group='c.id';$concept='c.course';}else{$group='s.id';$concept="'Todos los conceptos'";}
        $sql="SELECT ".($f['view']==='grouped'?"''":"s.id_no")." student_code,".($f['view']==='grouped'?"'Todos los estudiantes'":"s.name")." student_name,s.nivel level,CONCAT(s.grado,' ',COALESCE(s.seccion,'')) grade_section,$concept concept,DATE_FORMAT(MIN($issue),'%d/%m/%Y') issue_date,DATE_FORMAT(MAX($due),'%d/%m/%Y') due_date,".($f['view']==='detail'?"$debtStatus":"'Resumen'")." debt_status,SUM(ef.total_fee) original_amount,SUM(GREATEST(0,ef.total_fee-COALESCE(ef.discounted_amount,ef.total_fee))) discount,SUM(COALESCE(ef.discounted_amount,ef.total_fee)) effective_amount,SUM((SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.ef_id=ef.id AND COALESCE(p.payment_status,'Confirmado')='Confirmado')) paid,GREATEST(0,SUM(COALESCE(ef.discounted_amount,ef.total_fee))-SUM((SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.ef_id=ef.id AND COALESCE(p.payment_status,'Confirmado')='Confirmado'))) balance,COUNT(DISTINCT ef.id) debts,".($hasDue?"MAX(ef.due_date)<CURDATE()":"0")." is_overdue $base GROUP BY $group ORDER BY s.name,c.course";
    } else {
        $hasHours=rbColumnExists($conn,'academic_courses','weekly_hours');$hours=$hasHours?'ac.weekly_hours':'0';
        $where="t.school_id=$schoolId".($year?" AND tc.academic_year_id=$year":'').($level!==''?" AND tc.level='$level'":'').($grade!==''?" AND tc.grado='$grade'":'').($section!==''?" AND tc.seccion='$section'":'').($f['status']!==''?" AND t.status='{$f['status']}'":'');
        if($f['view']==='detail'){$group='tc.id';$course='ac.name';}elseif($f['view']==='grouped'){$group='tc.level,tc.grado,tc.seccion';$course="'Todos los cursos'";}else{$group='t.id';$course="'Todos los cursos'";}
        $sql="SELECT ".($f['view']==='grouped'?"''":"t.id_no")." teacher_code,".($f['view']==='grouped'?"'Todos los docentes'":"t.name")." teacher_name,".($f['view']==='grouped'?"'Resumen'":"t.status")." teacher_status,tc.level,$course course,CONCAT(tc.grado,' ',COALESCE(tc.seccion,'')) grade_section,SUM($hours) weekly_hours,COUNT(DISTINCT tc.id) assignments,COUNT(DISTINCT tc.course_id) courses,COUNT(DISTINCT CONCAT(tc.level,'|',tc.grado,'|',tc.seccion)) classrooms FROM teacher_courses tc JOIN teacher t ON t.id=tc.teacher_id JOIN academic_courses ac ON ac.id=tc.course_id WHERE $where GROUP BY $group ORDER BY t.name,tc.level,tc.grado,tc.seccion,ac.name";
    }
    $result=$conn->query($sql);
    if(!$result) throw new RuntimeException($conn->error);
    $rows=[];while($row=$result->fetch_assoc())$rows[]=$row;
    if(($f['preset']??'')==='risk')$rows=array_values(array_filter($rows,static fn($row)=>$row['average']!==null&&(float)$row['average']<11));
    if(($f['preset']??'')==='attendance_alert')$rows=array_values(array_filter($rows,static fn($row)=>(int)($row['absent']??0)>=3||(int)($row['late']??0)>=5||($row['attendance_rate']!==null&&(float)$row['attendance_rate']<80)));
    if(($f['preset']??'')==='morosity')$rows=array_values(array_filter($rows,static fn($row)=>(float)($row['balance']??0)>0&&(int)($row['is_overdue']??0)===1));
    if(($f['preset']??'')==='partial_payments')$rows=array_values(array_filter($rows,static fn($row)=>(float)($row['paid']??0)>0&&(float)($row['balance']??0)>0));
    return $rows;
}

function rbFormat(string $key, $value): string {
    if ($value===null || $value==='') return '-';
    if (in_array($key,['original_amount','discount','effective_amount','paid','balance'],true)) return 'S/ '.number_format((float)$value,2);
    if ($key==='weekly_hours') return number_format((float)$value,1).' h';
    if ($key==='attendance_rate') return number_format((float)$value,1).'%';
    if (in_array($key,['average','minimum','maximum'],true) && is_numeric($value)) return number_format((float)$value,2);
    return (string)$value;
}
