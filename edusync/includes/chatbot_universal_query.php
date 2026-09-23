<?php

/**
 * Motor universal de consultas de solo lectura para EduSync.
 *
 * La IA nunca entrega SQL. Solo un plan estructurado con campos permitidos.
 * Este archivo valida rol, colegio, filtros, métricas y relaciones antes de
 * ejecutar consultas preparadas.
 */

require_once __DIR__ . '/chatbot_engine.php';
require_once __DIR__ . '/chatbot_analytics.php';
require_once __DIR__ . '/chatbot_student_insights.php';

function edu_chat_universal_allowed_subjects(array $actor): array {
    $type=(int)($actor['type']??0);
    if($type===1)return ['students','teachers','courses','payments','debts','attendance','grades','evaluations','risk'];
    if($type===2)return ['students','courses','grades','evaluations','risk'];
    if($type===3)return ['students','attendance'];
    return [];
}

function edu_chat_universal_plan(array $args): array {
    $subject=strtolower(trim((string)($args['subject']??'')));
    $operation=strtolower(trim((string)($args['operation']??'summary')));
    $group=strtolower(trim((string)($args['group_by']??'none')));
    $sort=strtolower(trim((string)($args['sort_by']??'default')));
    $direction=strtolower(trim((string)($args['sort_direction']??'desc')))==='asc'?'asc':'desc';
    $limit=max(1,min(200,(int)($args['limit']??50)));

    $allowedOps=['summary','count','list','sum','average','distribution','ranking'];
    if(!in_array($operation,$allowedOps,true))$operation='summary';

    return [
        'subject'=>$subject,
        'operation'=>$operation,
        'group_by'=>$group,
        'sort_by'=>$sort,
        'sort_direction'=>$direction,
        'limit'=>$limit,
        'level'=>trim((string)($args['level']??'')),
        'grade'=>trim((string)($args['grade']??'')),
        'section'=>strtoupper(trim((string)($args['section']??''))),
        'name_search'=>trim((string)($args['name_search']??'')),
        'status'=>trim((string)($args['status']??'')),
        'gender'=>trim((string)($args['gender']??'')),
        'course'=>trim((string)($args['course']??'')),
        'teacher_name'=>trim((string)($args['teacher_name']??'')),
        'bimestre'=>trim((string)($args['bimestre']??'')),
        'evaluation_type'=>trim((string)($args['evaluation_type']??'')),
        'period'=>trim((string)($args['period']??'')),
        'date_from'=>trim((string)($args['date_from']??'')),
        'date_to'=>trim((string)($args['date_to']??'')),
        'payment_method'=>trim((string)($args['payment_method']??'')),
        'attendance_status'=>trim((string)($args['attendance_status']??'')),
        'amount_min'=>isset($args['amount_min'])?(float)$args['amount_min']:null,
        'amount_max'=>isset($args['amount_max'])?(float)$args['amount_max']:null,
        'debt_min'=>isset($args['debt_min'])?(float)$args['debt_min']:null,
        'debt_max'=>isset($args['debt_max'])?(float)$args['debt_max']:null,
        'debt_count_min'=>isset($args['debt_count_min'])?max(1,(int)$args['debt_count_min']):null,
        'late_min'=>isset($args['late_min'])?max(1,(int)$args['late_min']):null,
        'absent_min'=>isset($args['absent_min'])?max(1,(int)$args['absent_min']):null,
        'critical_min'=>isset($args['critical_min'])?max(1,(int)$args['critical_min']):null,
        'grade_min'=>isset($args['grade_min'])?(float)$args['grade_min']:null,
        'grade_max'=>isset($args['grade_max'])?(float)$args['grade_max']:null,
        'grade_value'=>strtoupper(trim((string)($args['grade_value']??'')))
    ];
}

function edu_chat_universal_period(mysqli $conn,int $school,array $p): array {
    if($p['date_from']!==''||$p['date_to']!==''){
        $from=preg_match('/^\d{4}-\d{2}-\d{2}$/',$p['date_from'])?$p['date_from']:$p['date_to'];
        $to=preg_match('/^\d{4}-\d{2}-\d{2}$/',$p['date_to'])?$p['date_to']:$from;
        if($from!==''&&$to!==''){
            if($from>$to){$x=$from;$from=$to;$to=$x;}
            return[$from,$to,'del '.$from.' al '.$to];
        }
    }
    $period=in_array($p['period'],['today','yesterday','week','month','last_month','year'],true)?$p['period']:'month';
    return edu_chat_analytics_period($conn,$school,$period);
}

function edu_chat_universal_student_where(array $p,string $alias,array &$where,string &$types,array &$params): void {
    if($p['status']!==''){
        $where[]="LOWER(TRIM(COALESCE($alias.status,'')))=LOWER(TRIM(?))";$types.='s';$params[]=$p['status'];
    }else{
        $where[]="LOWER(TRIM(COALESCE($alias.status,'Activo'))) IN ('activo','active')";
    }
    if($p['level']!==''){$where[]="LOWER(TRIM($alias.nivel))=LOWER(TRIM(?))";$types.='s';$params[]=$p['level'];}
    if($p['grade']!=='')$where[]=edu_chat_analytics_grade_filter($alias,$p['grade'],$types,$params);
    if($p['section']!==''){$where[]="UPPER(TRIM(COALESCE($alias.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=$p['section'];}
    if($p['gender']!==''){$where[]="LOWER(TRIM(COALESCE($alias.genero,'')))=LOWER(TRIM(?))";$types.='s';$params[]=$p['gender'];}
    if($p['name_search']!==''){$where[]="LOWER($alias.name) LIKE LOWER(?)";$types.='s';$params[]='%'.$p['name_search'].'%';}
}

function edu_chat_universal_debt_join(mysqli $conn,array $p): string {
    if(!edu_chat_table_exists($conn,'student_ef_list')||!edu_chat_table_exists($conn,'payments'))return '';
    $payStatus=edu_chat_column_exists($conn,'payments','payment_status')?" AND COALESCE(p.payment_status,'Confirmado')='Confirmado'":'';
    $debtStatus=edu_chat_column_exists($conn,'student_ef_list','debt_status')?" WHERE LOWER(TRIM(COALESCE(ef.debt_status,'Activa')))='activa'":'';
    return " LEFT JOIN (
        SELECT z.student_id,SUM(z.balance) debt_total,COUNT(*) debt_count
        FROM (
            SELECT ef.student_id,ef.id,
                   GREATEST(COALESCE(ef.discounted_amount,ef.total_fee)-COALESCE(SUM(p.amount),0),0) balance
            FROM student_ef_list ef
            LEFT JOIN payments p ON p.ef_id=ef.id$payStatus
            $debtStatus
            GROUP BY ef.student_id,ef.id,ef.discounted_amount,ef.total_fee
            HAVING balance>0.009
        ) z
        GROUP BY z.student_id
    ) ud ON ud.student_id=s.id";
}

function edu_chat_universal_attendance_join(mysqli $conn,array $p,int $school,string &$types,array &$params): string {
    if(!edu_chat_table_exists($conn,'asistencia'))return '';
    [$from,$to]=edu_chat_universal_period($conn,$school,$p);
    $types.='ss';$params[]=$from;$params[]=$to;
    $cancel=edu_chat_column_exists($conn,'asistencia','is_cancelled')?' AND COALESCE(a.is_cancelled,0)=0':'';
    return " LEFT JOIN (
        SELECT a.student_id,
               SUM(LOWER(TRIM(a.estado))='tarde') late_count,
               SUM(LOWER(TRIM(a.estado)) IN ('ausente','ausente justificada','ausencia justificada')) absent_count,
               SUM(LOWER(TRIM(a.estado)) IN ('presente','normal','temprano')) present_count,
               COUNT(DISTINCT a.fecha) attendance_days
        FROM asistencia a
        WHERE a.tipo='Entrada' AND a.fecha BETWEEN ? AND ?$cancel
        GROUP BY a.student_id
    ) ua ON ua.student_id=s.id";
}

function edu_chat_universal_grade_join(mysqli $conn,array $actor,array $p,string &$types,array &$params): string {
    foreach(['evaluation_grades','evaluations','teacher_courses','academic_courses'] as $t)if(!edu_chat_table_exists($conn,$t))return '';
    $school=(int)$actor['school_id'];
    $year=edu_chat_active_year($conn,$school);$yearId=(int)($year['id']??0);
    $where=['tc.school_id=?'];$types.='i';$params[]=$school;
    if($yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if((int)($actor['type']??0)===2){
        $teacher=(int)($actor['teacher_id']??0);
        if($teacher<=0)return '';
        $where[]='tc.teacher_id=?';$types.='i';$params[]=$teacher;
    }
    if($p['course']!==''){$where[]='LOWER(TRIM(ac.name)) LIKE LOWER(?)';$types.='s';$params[]='%'.$p['course'].'%';}
    if($p['bimestre']!==''){$where[]='e.bimestre=?';$types.='s';$params[]=$p['bimestre'];}
    return " LEFT JOIN (
        SELECT eg.student_id,
               COUNT(*) grade_records,
               SUM(((eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(eg.grade AS DECIMAL(10,2))<10.5) OR UPPER(TRIM(eg.grade))='C')) critical_count,
               AVG(CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2))
                        WHEN UPPER(TRIM(eg.grade))='C' THEN 5
                        WHEN UPPER(TRIM(eg.grade))='B' THEN 12
                        WHEN UPPER(TRIM(eg.grade))='A' THEN 15.5
                        WHEN UPPER(TRIM(eg.grade))='AD' THEN 19 ELSE NULL END) grade_average
        FROM evaluation_grades eg
        INNER JOIN evaluations e ON e.id=eg.evaluation_id
        INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
        LEFT JOIN academic_courses ac ON ac.id=tc.course_id
        WHERE ".implode(' AND ',$where)."
        GROUP BY eg.student_id
    ) ug ON ug.student_id=s.id";
}

function edu_chat_universal_students(mysqli $conn,array $actor,array $p): array {
    if(!edu_chat_table_exists($conn,'student'))return edu_chat_result('No está disponible la información de estudiantes.');
    $school=(int)$actor['school_id'];$type=(int)($actor['type']??0);
    $join='';$joinTypes='';$joinParams=[];$where=['s.school_id=?'];$whereTypes='i';$whereParams=[$school];

    if($type===2){
        $teacher=(int)($actor['teacher_id']??0);if($teacher<=0)return edu_chat_result('No pude identificar tu ficha docente.');
        $join.=" INNER JOIN teacher_courses utc ON utc.school_id=s.school_id AND TRIM(utc.grado)=TRIM(s.grado) AND UPPER(TRIM(COALESCE(utc.seccion,'')))=UPPER(TRIM(COALESCE(s.seccion,'')))";
        $where[]='utc.teacher_id=?';$whereTypes.='i';$whereParams[]=$teacher;
        $year=edu_chat_active_year($conn,$school);$yearId=(int)($year['id']??0);
        if($yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='utc.academic_year_id=?';$whereTypes.='i';$whereParams[]=$yearId;}
    }

    $needsDebt=$p['debt_min']!==null||$p['debt_max']!==null||$p['debt_count_min']!==null||in_array($p['sort_by'],['debt','debt_count'],true);
    $needsAttendance=$p['late_min']!==null||$p['absent_min']!==null||in_array($p['sort_by'],['late','absent'],true);
    $needsGrades=$p['critical_min']!==null||$p['grade_min']!==null||$p['grade_max']!==null||$p['course']!==''||$p['bimestre']!==''||in_array($p['sort_by'],['grade','critical'],true);

    if($needsDebt)$join.=edu_chat_universal_debt_join($conn,$p);
    if($needsAttendance)$join.=edu_chat_universal_attendance_join($conn,$p,$school,$joinTypes,$joinParams);
    if($needsGrades)$join.=edu_chat_universal_grade_join($conn,$actor,$p,$joinTypes,$joinParams);

    edu_chat_universal_student_where($p,'s',$where,$whereTypes,$whereParams);

    if($p['debt_min']!==null)$where[]='COALESCE(ud.debt_total,0)>=?',$whereTypes.='d',$whereParams[]=$p['debt_min'];
    if($p['debt_max']!==null)$where[]='COALESCE(ud.debt_total,0)<=?',$whereTypes.='d',$whereParams[]=$p['debt_max'];
    if($p['debt_count_min']!==null)$where[]='COALESCE(ud.debt_count,0)>=?',$whereTypes.='i',$whereParams[]=$p['debt_count_min'];
    if($p['late_min']!==null)$where[]='COALESCE(ua.late_count,0)>=?',$whereTypes.='i',$whereParams[]=$p['late_min'];
    if($p['absent_min']!==null)$where[]='COALESCE(ua.absent_count,0)>=?',$whereTypes.='i',$whereParams[]=$p['absent_min'];
    if($p['critical_min']!==null)$where[]='COALESCE(ug.critical_count,0)>=?',$whereTypes.='i',$whereParams[]=$p['critical_min'];
    if($p['grade_min']!==null)$where[]='ug.grade_average>=?',$whereTypes.='d',$whereParams[]=$p['grade_min'];
    if($p['grade_max']!==null)$where[]='ug.grade_average<=?',$whereTypes.='d',$whereParams[]=$p['grade_max'];

    $types=$joinTypes.$whereTypes;$params=array_merge($joinParams,$whereParams);
    $whereSql=implode(' AND ',$where);

    if($p['operation']==='count'){
        $sql="SELECT COUNT(DISTINCT s.id) total FROM student s$join WHERE $whereSql";
        $st=$conn->prepare($sql);if(!$st)return edu_chat_result('No pude preparar la consulta solicitada.');
        edu_chat_bind($st,$types,$params);$st->execute();$r=$st->get_result()->fetch_assoc()?:[];$st->close();
        return edu_chat_result('Resultado: '.(int)($r['total']??0).' estudiante'.((int)($r['total']??0)===1?'':'s').'.');
    }

    if($p['operation']==='distribution'){
        $group=in_array($p['group_by'],['level','grade','section','grade_section'],true)?$p['group_by']:'grade_section';
        [$select,$groupSql,$order]=edu_chat_analytics_group_sql($group,'s');
        $sql="SELECT $select,COUNT(DISTINCT s.id) total FROM student s$join WHERE $whereSql GROUP BY $groupSql ORDER BY $order";
        $st=$conn->prepare($sql);if(!$st)return edu_chat_result('No pude preparar la distribución solicitada.');
        edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$rows=[];$grand=0;
        while($r=$res->fetch_assoc()){$r['total']=(int)$r['total'];$grand+=$r['total'];$rows[]=$r;}$st->close();
        if(!$rows)return edu_chat_result('No encontré estudiantes con esos filtros.');
        $lines=[];foreach($rows as $r)$lines[]='• '.edu_chat_analytics_row_label($r,$group).': '.$r['total'];
        return edu_chat_result("Distribución de estudiantes:\n".implode("\n",$lines)."\nTotal: $grand.");
    }

    $select="s.id,s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion";
    if($needsDebt)$select.=",COALESCE(ud.debt_total,0) debt_total,COALESCE(ud.debt_count,0) debt_count";
    if($needsAttendance)$select.=",COALESCE(ua.late_count,0) late_count,COALESCE(ua.absent_count,0) absent_count";
    if($needsGrades)$select.=",COALESCE(ug.critical_count,0) critical_count,ug.grade_average";

    $order='s.name ASC';
    $dir=$p['sort_direction']==='asc'?'ASC':'DESC';
    if($p['sort_by']==='debt'&&$needsDebt)$order="debt_total $dir,s.name ASC";
    elseif($p['sort_by']==='debt_count'&&$needsDebt)$order="debt_count $dir,s.name ASC";
    elseif($p['sort_by']==='late'&&$needsAttendance)$order="late_count $dir,s.name ASC";
    elseif($p['sort_by']==='absent'&&$needsAttendance)$order="absent_count $dir,s.name ASC";
    elseif($p['sort_by']==='critical'&&$needsGrades)$order="critical_count $dir,s.name ASC";
    elseif($p['sort_by']==='grade'&&$needsGrades)$order="grade_average $dir,s.name ASC";
    elseif($p['sort_by']==='location')$order="FIELD(s.nivel,'Inicial','Primaria','Secundaria'),CAST(s.grado AS UNSIGNED),s.grado,seccion,s.name";

    $sql="SELECT DISTINCT $select FROM student s$join WHERE $whereSql ORDER BY $order LIMIT ".$p['limit'];
    $st=$conn->prepare($sql);if(!$st)return edu_chat_result('No pude preparar la consulta solicitada.');
    edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$st->close();
    if(!$rows)return edu_chat_result('No encontré estudiantes que cumplan todos esos criterios.');

    $lines=[];
    foreach($rows as $r){
        $extra=[];
        if($needsDebt)$extra[]='deuda '.edu_chat_money((float)$r['debt_total']).' · '.(int)$r['debt_count'].' pendiente'.((int)$r['debt_count']===1?'':'s');
        if($needsAttendance)$extra[]=(int)$r['late_count'].' tardanza'.((int)$r['late_count']===1?'':'s').' · '.(int)$r['absent_count'].' ausencia'.((int)$r['absent_count']===1?'':'s');
        if($needsGrades)$extra[]=(int)$r['critical_count'].' registro'.((int)$r['critical_count']===1?'':'s').' crítico'.((int)$r['critical_count']===1?'':'s').($r['grade_average']!==null?' · promedio '.number_format((float)$r['grade_average'],1):'');
        $lines[]='• '.$r['name'].' — '.$r['nivel'].' · '.edu_chat_grade_label($r['grado']).' '.$r['seccion'].($extra?' — '.implode(' — ',$extra):'');
    }
    return edu_chat_result('Estudiantes encontrados ('.count($rows)."):\n".implode("\n",$lines));
}

function edu_chat_universal_teachers(mysqli $conn,array $actor,array $p): array {
    if((int)($actor['type']??0)!==1)return edu_chat_result('Esta consulta no está autorizada para este perfil.');
    if(!edu_chat_table_exists($conn,'teacher'))return edu_chat_result('No está disponible la información de docentes.');
    $school=(int)$actor['school_id'];$where=['t.school_id=?'];$types='i';$params=[$school];
    if($p['name_search']!==''){$where[]='LOWER(t.name) LIKE LOWER(?)';$types.='s';$params[]='%'.$p['name_search'].'%';}
    if($p['course']!==''){
        $where[]="EXISTS(SELECT 1 FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id WHERE tc.teacher_id=t.id AND tc.school_id=t.school_id AND LOWER(TRIM(ac.name)) LIKE LOWER(?))";
        $types.='s';$params[]='%'.$p['course'].'%';
    }
    $w=implode(' AND ',$where);
    if($p['operation']==='count'||$p['operation']==='summary'){
        $st=$conn->prepare("SELECT COUNT(DISTINCT t.id) total FROM teacher t WHERE $w");edu_chat_bind($st,$types,$params);$st->execute();$r=$st->get_result()->fetch_assoc()?:[];$st->close();
        return edu_chat_result('Docentes encontrados: '.(int)($r['total']??0).'.');
    }
    $sql="SELECT t.name,t.specialty,
          (SELECT COUNT(*) FROM teacher_courses tc WHERE tc.teacher_id=t.id AND tc.school_id=t.school_id) assignments
          FROM teacher t WHERE $w ORDER BY t.name LIMIT ".$p['limit'];
    $st=$conn->prepare($sql);if(!$st)return edu_chat_result('No pude preparar la consulta de docentes.');edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$st->close();
    if(!$rows)return edu_chat_result('No encontré docentes con esos filtros.');
    $lines=[];foreach($rows as $r)$lines[]='• '.$r['name'].(!empty($r['specialty'])?' — '.$r['specialty']:'').' — '.(int)$r['assignments'].' asignación'.((int)$r['assignments']===1?'':'es');
    return edu_chat_result('Docentes encontrados ('.count($rows)."):\n".implode("\n",$lines));
}

function edu_chat_universal_courses(mysqli $conn,array $actor,array $p): array {
    if(!edu_chat_table_exists($conn,'academic_courses')||!edu_chat_table_exists($conn,'teacher_courses'))return edu_chat_result('No está disponible la información de cursos.');
    $school=(int)$actor['school_id'];$type=(int)($actor['type']??0);
    $where=['tc.school_id=?'];$types='i';$params=[$school];
    $year=edu_chat_active_year($conn,$school);$yearId=(int)($year['id']??0);
    if($yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($type===2){$teacher=(int)($actor['teacher_id']??0);if($teacher<=0)return edu_chat_result('No pude identificar tu ficha docente.');$where[]='tc.teacher_id=?';$types.='i';$params[]=$teacher;}
    if($p['level']!==''){$where[]='LOWER(TRIM(tc.level))=LOWER(TRIM(?))';$types.='s';$params[]=$p['level'];}
    if($p['grade']!=='')$where[]=edu_chat_analytics_grade_filter('tc',$p['grade'],$types,$params);
    if($p['section']!==''){$where[]="UPPER(TRIM(COALESCE(tc.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=$p['section'];}
    if($p['course']!==''){$where[]='LOWER(TRIM(ac.name)) LIKE LOWER(?)';$types.='s';$params[]='%'.$p['course'].'%';}
    if($p['teacher_name']!==''&&$type===1){$where[]='LOWER(t.name) LIKE LOWER(?)';$types.='s';$params[]='%'.$p['teacher_name'].'%';}
    $join=$type===1?' LEFT JOIN teacher t ON t.id=tc.teacher_id ':'';
    $w=implode(' AND ',$where);
    if($p['operation']==='count'){
        $st=$conn->prepare("SELECT COUNT(DISTINCT tc.id) total FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id $join WHERE $w");edu_chat_bind($st,$types,$params);$st->execute();$r=$st->get_result()->fetch_assoc()?:[];$st->close();
        return edu_chat_result('Asignaciones de cursos encontradas: '.(int)($r['total']??0).'.');
    }
    $sql="SELECT DISTINCT ac.name,tc.level,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'Sin sección') seccion".($type===1?",COALESCE(t.name,'Sin docente') teacher_name":'')."
          FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id $join
          WHERE $w ORDER BY ac.name,CAST(tc.grado AS UNSIGNED),tc.grado,seccion LIMIT ".$p['limit'];
    $st=$conn->prepare($sql);if(!$st)return edu_chat_result('No pude preparar la consulta de cursos.');edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$st->close();
    if(!$rows)return edu_chat_result('No encontré cursos con esos filtros.');
    $lines=[];foreach($rows as $r)$lines[]='• '.$r['name'].' — '.$r['level'].' · '.edu_chat_grade_label($r['grado']).' '.$r['seccion'].($type===1?' — '.$r['teacher_name']:'');
    return edu_chat_result('Cursos/asignaciones encontrados ('.count($rows)."):\n".implode("\n",$lines));
}

function edu_chat_universal_payments(mysqli $conn,array $actor,array $p): array {
    if((int)($actor['type']??0)!==1)return edu_chat_result('Esta consulta financiera solo está disponible para administración.');
    $school=(int)$actor['school_id'];[$from,$to,$label]=edu_chat_universal_period($conn,$school,$p);

    // Esquema moderno con operaciones y desglose real por medio.
    if(edu_chat_table_exists($conn,'payment_operations')&&edu_chat_table_exists($conn,'payment_operation_methods')&&edu_chat_table_exists($conn,'payment_methods')){
        $where=['po.school_id=?','DATE(po.payment_date) BETWEEN ? AND ?'];$types='iss';$params=[$school,$from,$to];
        if(edu_chat_column_exists($conn,'payment_operations','status'))$where[]="COALESCE(po.status,'Confirmado')='Confirmado'";
        if($p['level']!==''){$where[]='LOWER(TRIM(s.nivel))=LOWER(TRIM(?))';$types.='s';$params[]=$p['level'];}
        if($p['grade']!=='')$where[]=edu_chat_analytics_grade_filter('s',$p['grade'],$types,$params);
        if($p['section']!==''){$where[]="UPPER(TRIM(COALESCE(s.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=$p['section'];}
        if($p['payment_method']!==''){$where[]='LOWER(TRIM(pm.name))=LOWER(TRIM(?))';$types.='s';$params[]=$p['payment_method'];}
        if($p['amount_min']!==null){$where[]='pom.amount>=?';$types.='d';$params[]=$p['amount_min'];}
        if($p['amount_max']!==null){$where[]='pom.amount<=?';$types.='d';$params[]=$p['amount_max'];}
        $w=implode(' AND ',$where);
        if($p['operation']==='distribution'&&$p['group_by']==='payment_method'){
            $sql="SELECT pm.name label,COUNT(DISTINCT po.id) operations,SUM(pom.amount) total FROM payment_operations po INNER JOIN payment_operation_methods pom ON pom.operation_id=po.id INNER JOIN payment_methods pm ON pm.id=pom.payment_method_id INNER JOIN student s ON s.id=po.student_id WHERE $w GROUP BY pm.id,pm.name ORDER BY total DESC";
            $st=$conn->prepare($sql);edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$lines=[];$grand=0;while($r=$res->fetch_assoc()){$grand+=(float)$r['total'];$lines[]='• '.$r['label'].': '.edu_chat_money((float)$r['total']).' · '.(int)$r['operations'].' pago'.((int)$r['operations']===1?'':'s');}$st->close();
            return $lines?edu_chat_result("Cobranza por método $label:\n".implode("\n",$lines)."\nTotal: ".edu_chat_money($grand)):edu_chat_result('No encontré pagos con esos filtros.');
        }
        if(in_array($p['operation'],['sum','summary','count'],true)){
            $sql="SELECT COUNT(DISTINCT po.id) operations,COUNT(DISTINCT po.student_id) students,COALESCE(SUM(pom.amount),0) total FROM payment_operations po INNER JOIN payment_operation_methods pom ON pom.operation_id=po.id INNER JOIN payment_methods pm ON pm.id=pom.payment_method_id INNER JOIN student s ON s.id=po.student_id WHERE $w";
            $st=$conn->prepare($sql);edu_chat_bind($st,$types,$params);$st->execute();$r=$st->get_result()->fetch_assoc()?:[];$st->close();
            return edu_chat_result('Cobranza '.$label.': '.edu_chat_money((float)($r['total']??0)).' · '.(int)($r['operations']??0).' operaciones · '.(int)($r['students']??0).' estudiantes.');
        }
        $sql="SELECT po.receipt_full,po.payment_date,s.name,pm.name payment_method,pom.amount FROM payment_operations po INNER JOIN payment_operation_methods pom ON pom.operation_id=po.id INNER JOIN payment_methods pm ON pm.id=pom.payment_method_id INNER JOIN student s ON s.id=po.student_id WHERE $w ORDER BY po.payment_date DESC,po.id DESC LIMIT ".$p['limit'];
        $st=$conn->prepare($sql);edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$st->close();
        if(!$rows)return edu_chat_result('No encontré pagos con esos filtros.');
        $lines=[];foreach($rows as $r)$lines[]='• '.$r['payment_date'].' — '.$r['name'].' — '.$r['payment_method'].' — '.edu_chat_money((float)$r['amount']).(!empty($r['receipt_full'])?' — '.$r['receipt_full']:'');
        return edu_chat_result('Pagos encontrados ('.count($rows)."):\n".implode("\n",$lines));
    }

    // Fallback al esquema histórico de database.sql.
    if(!edu_chat_table_exists($conn,'payments')||!edu_chat_table_exists($conn,'student_ef_list'))return edu_chat_result('El historial de pagos no está disponible.');
    $where=['s.school_id=?','DATE(p.date_created) BETWEEN ? AND ?'];$types='iss';$params=[$school,$from,$to];
    if($p['level']!==''){$where[]='LOWER(TRIM(s.nivel))=LOWER(TRIM(?))';$types.='s';$params[]=$p['level'];}
    if($p['grade']!=='')$where[]=edu_chat_analytics_grade_filter('s',$p['grade'],$types,$params);
    if($p['section']!==''){$where[]="UPPER(TRIM(COALESCE(s.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=$p['section'];}
    $join=' LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id';
    if($p['payment_method']!==''){$where[]='LOWER(TRIM(pm.name))=LOWER(TRIM(?))';$types.='s';$params[]=$p['payment_method'];}
    if($p['amount_min']!==null){$where[]='p.amount>=?';$types.='d';$params[]=$p['amount_min'];}
    if($p['amount_max']!==null){$where[]='p.amount<=?';$types.='d';$params[]=$p['amount_max'];}
    $w=implode(' AND ',$where);
    if($p['operation']==='distribution'&&$p['group_by']==='payment_method'){
        $sql="SELECT COALESCE(pm.name,'Sin método') label,COUNT(DISTINCT p.id) operations,SUM(p.amount) total FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id INNER JOIN student s ON s.id=ef.student_id $join WHERE $w GROUP BY pm.id,pm.name ORDER BY total DESC";
        $st=$conn->prepare($sql);edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$lines=[];$grand=0;while($r=$res->fetch_assoc()){$grand+=(float)$r['total'];$lines[]='• '.$r['label'].': '.edu_chat_money((float)$r['total']);}$st->close();
        return $lines?edu_chat_result("Cobranza por método $label:\n".implode("\n",$lines)."\nTotal: ".edu_chat_money($grand)):edu_chat_result('No encontré pagos con esos filtros.');
    }
    $sql="SELECT COUNT(DISTINCT p.id) operations,COUNT(DISTINCT s.id) students,COALESCE(SUM(p.amount),0) total FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id INNER JOIN student s ON s.id=ef.student_id $join WHERE $w";
    $st=$conn->prepare($sql);edu_chat_bind($st,$types,$params);$st->execute();$r=$st->get_result()->fetch_assoc()?:[];$st->close();
    return edu_chat_result('Cobranza '.$label.': '.edu_chat_money((float)($r['total']??0)).' · '.(int)($r['operations']??0).' pagos · '.(int)($r['students']??0).' estudiantes.');
}

function edu_chat_universal_grades(mysqli $conn,array $actor,array $p): array {
    foreach(['evaluation_grades','evaluations','teacher_courses','academic_courses','student'] as $t)if(!edu_chat_table_exists($conn,$t))return edu_chat_result('No está disponible la información académica necesaria.');
    $type=(int)($actor['type']??0);$school=(int)$actor['school_id'];
    $where=['s.school_id=?','tc.school_id=?'];$types='ii';$params=[$school,$school];
    $year=edu_chat_active_year($conn,$school);$yearId=(int)($year['id']??0);
    if($yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($type===2){$teacher=(int)($actor['teacher_id']??0);if($teacher<=0)return edu_chat_result('No pude identificar tu ficha docente.');$where[]='tc.teacher_id=?';$types.='i';$params[]=$teacher;}
    if($p['level']!==''){$where[]='LOWER(TRIM(s.nivel))=LOWER(TRIM(?))';$types.='s';$params[]=$p['level'];}
    if($p['grade']!=='')$where[]=edu_chat_analytics_grade_filter('s',$p['grade'],$types,$params);
    if($p['section']!==''){$where[]="UPPER(TRIM(COALESCE(s.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=$p['section'];}
    if($p['name_search']!==''){$where[]='LOWER(s.name) LIKE LOWER(?)';$types.='s';$params[]='%'.$p['name_search'].'%';}
    if($p['course']!==''){$where[]='LOWER(TRIM(ac.name)) LIKE LOWER(?)';$types.='s';$params[]='%'.$p['course'].'%';}
    if($p['bimestre']!==''){$where[]='e.bimestre=?';$types.='s';$params[]=$p['bimestre'];}
    if($p['evaluation_type']!==''){$where[]='LOWER(TRIM(e.type)) LIKE LOWER(?)';$types.='s';$params[]='%'.$p['evaluation_type'].'%';}
    if($p['grade_value']!==''){$where[]='UPPER(TRIM(eg.grade))=?';$types.='s';$params[]=$p['grade_value'];}
    $numeric="CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2)) WHEN UPPER(TRIM(eg.grade))='C' THEN 5 WHEN UPPER(TRIM(eg.grade))='B' THEN 12 WHEN UPPER(TRIM(eg.grade))='A' THEN 15.5 WHEN UPPER(TRIM(eg.grade))='AD' THEN 19 ELSE NULL END";
    if($p['grade_min']!==null){$where[]="$numeric>=?";$types.='d';$params[]=$p['grade_min'];}
    if($p['grade_max']!==null){$where[]="$numeric<=?";$types.='d';$params[]=$p['grade_max'];}
    $w=implode(' AND ',$where);
    if(in_array($p['operation'],['average','summary'],true)){
        $sql="SELECT COUNT(*) records,COUNT(DISTINCT s.id) students,AVG($numeric) average FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN student s ON s.id=eg.student_id WHERE $w";
        $st=$conn->prepare($sql);edu_chat_bind($st,$types,$params);$st->execute();$r=$st->get_result()->fetch_assoc()?:[];$st->close();
        return edu_chat_result('Registros de notas: '.(int)($r['records']??0).' · estudiantes: '.(int)($r['students']??0).' · promedio equivalente: '.($r['average']===null?'sin datos':number_format((float)$r['average'],2)).'.');
    }
    if($p['operation']==='distribution'&&in_array($p['group_by'],['course','bimestre','grade_section'],true)){
        if($p['group_by']==='course'){$sel='ac.name label';$grp='ac.id,ac.name';$ord='ac.name';}
        elseif($p['group_by']==='bimestre'){$sel='e.bimestre label';$grp='e.bimestre';$ord='CAST(e.bimestre AS UNSIGNED)';}
        else{$sel="CONCAT(s.nivel,' · ',s.grado,' ',COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección')) label";$grp='s.nivel,s.grado,s.seccion';$ord="FIELD(s.nivel,'Inicial','Primaria','Secundaria'),CAST(s.grado AS UNSIGNED),s.grado,s.seccion";}
        $sql="SELECT $sel,COUNT(*) records,COUNT(DISTINCT s.id) students,AVG($numeric) average FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN student s ON s.id=eg.student_id WHERE $w GROUP BY $grp ORDER BY $ord";
        $st=$conn->prepare($sql);edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['label'].': '.(int)$r['records'].' registros · promedio '.($r['average']===null?'—':number_format((float)$r['average'],2));$st->close();
        return $lines?edu_chat_result("Distribución académica:\n".implode("\n",$lines)):edu_chat_result('No encontré notas con esos filtros.');
    }
    $sql="SELECT s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion,ac.name course,e.bimestre,e.title,e.type,eg.grade FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN student s ON s.id=eg.student_id WHERE $w ORDER BY s.name,ac.name,CAST(e.bimestre AS UNSIGNED),e.id DESC LIMIT ".$p['limit'];
    $st=$conn->prepare($sql);edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$st->close();
    if(!$rows)return edu_chat_result('No encontré notas con esos filtros.');
    $lines=[];foreach($rows as $r)$lines[]='• '.$r['name'].' — '.$r['nivel'].' · '.edu_chat_grade_label($r['grado']).' '.$r['seccion'].' — '.$r['course'].' — B'.$r['bimestre'].' — '.$r['title'].' — '.$r['grade'];
    return edu_chat_result('Notas encontradas ('.count($rows)."):\n".implode("\n",$lines));
}

function edu_chat_universal_evaluations(mysqli $conn,array $actor,array $p): array {
    foreach(['evaluations','teacher_courses','academic_courses'] as $t)if(!edu_chat_table_exists($conn,$t))return edu_chat_result('No está disponible la información de evaluaciones.');
    $type=(int)($actor['type']??0);$school=(int)$actor['school_id'];$where=['tc.school_id=?'];$types='i';$params=[$school];
    $year=edu_chat_active_year($conn,$school);$yearId=(int)($year['id']??0);
    if($yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($type===2){$teacher=(int)($actor['teacher_id']??0);if($teacher<=0)return edu_chat_result('No pude identificar tu ficha docente.');$where[]='tc.teacher_id=?';$types.='i';$params[]=$teacher;}
    if($p['level']!==''){$where[]='LOWER(TRIM(tc.level))=LOWER(TRIM(?))';$types.='s';$params[]=$p['level'];}
    if($p['grade']!=='')$where[]=edu_chat_analytics_grade_filter('tc',$p['grade'],$types,$params);
    if($p['section']!==''){$where[]="UPPER(TRIM(COALESCE(tc.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=$p['section'];}
    if($p['course']!==''){$where[]='LOWER(TRIM(ac.name)) LIKE LOWER(?)';$types.='s';$params[]='%'.$p['course'].'%';}
    if($p['bimestre']!==''){$where[]='e.bimestre=?';$types.='s';$params[]=$p['bimestre'];}
    if($p['evaluation_type']!==''){$where[]='LOWER(TRIM(e.type)) LIKE LOWER(?)';$types.='s';$params[]='%'.$p['evaluation_type'].'%';}
    $w=implode(' AND ',$where);
    if($p['operation']==='count'||$p['operation']==='summary'){
        $st=$conn->prepare("SELECT COUNT(*) total FROM evaluations e INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN academic_courses ac ON ac.id=tc.course_id WHERE $w");edu_chat_bind($st,$types,$params);$st->execute();$r=$st->get_result()->fetch_assoc()?:[];$st->close();
        return edu_chat_result('Evaluaciones encontradas: '.(int)($r['total']??0).'.');
    }
    $sql="SELECT e.title,e.type,e.bimestre,e.created_at,ac.name course,tc.level,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'Sin sección') seccion FROM evaluations e INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN academic_courses ac ON ac.id=tc.course_id WHERE $w ORDER BY e.created_at DESC,e.id DESC LIMIT ".$p['limit'];
    $st=$conn->prepare($sql);edu_chat_bind($st,$types,$params);$st->execute();$res=$st->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$st->close();
    if(!$rows)return edu_chat_result('No encontré evaluaciones con esos filtros.');
    $lines=[];foreach($rows as $r)$lines[]='• '.$r['title'].' — '.$r['course'].' — '.$r['level'].' · '.edu_chat_grade_label($r['grado']).' '.$r['seccion'].' — B'.$r['bimestre'].' — '.$r['type'];
    return edu_chat_result('Evaluaciones encontradas ('.count($rows)."):\n".implode("\n",$lines));
}

function edu_chat_universal_query(mysqli $conn,array $actor,array $args): array {
    $p=edu_chat_universal_plan($args);
    if(!in_array($p['subject'],edu_chat_universal_allowed_subjects($actor),true)){
        return edu_chat_result('Ese tipo de información no está autorizado para este perfil.');
    }

    switch($p['subject']){
        case 'students': return edu_chat_universal_students($conn,$actor,$p);
        case 'teachers': return edu_chat_universal_teachers($conn,$actor,$p);
        case 'courses': return edu_chat_universal_courses($conn,$actor,$p);
        case 'payments': return edu_chat_universal_payments($conn,$actor,$p);
        case 'debts':
            if((int)($actor['type']??0)!==1)return edu_chat_result('Esta consulta financiera solo está disponible para administración.');
            $entities=['level'=>$p['level']?:null,'grade'=>$p['grade']?:null,'section'=>$p['section']?:null];
            if($p['operation']==='distribution')return edu_chat_debt_distribution_result($conn,$actor,$entities,in_array($p['group_by'],['level','grade','section','grade_section'],true)?$p['group_by']:'grade_section');
            if(in_array($p['operation'],['list','ranking'],true)||$p['debt_min']!==null||$p['debt_max']!==null){
                $min=$p['debt_min']!==null?max(.01,$p['debt_min']):.01;
                return edu_chat_adv_finance_by_amount_result($conn,$actor,$entities,$min,$p['debt_max'],false,$p['limit']);
            }
            return edu_chat_debt_summary_result($conn,$actor,$entities);
        case 'attendance':
            $entities=['level'=>$p['level']?:null,'grade'=>$p['grade']?:null,'section'=>$p['section']?:null,'period'=>$p['period']?:'month'];
            if($p['operation']==='distribution')return edu_chat_attendance_distribution_result($conn,$actor,$entities,in_array($p['group_by'],['level','grade','section','grade_section'],true)?$p['group_by']:'grade_section');
            if(in_array($p['operation'],['list','ranking'],true)||$p['attendance_status']!==''||$p['late_min']!==null||$p['absent_min']!==null){
                $status=$p['attendance_status']!==''?$p['attendance_status']:($p['late_min']!==null?'late':($p['absent_min']!==null?'absent':'all'));
                $min=$status==='late'?($p['late_min']??1):($status==='absent'?($p['absent_min']??1):1);
                return edu_chat_attendance_roster_result($conn,$actor,$entities,$status,$min,$p['limit']);
            }
            return edu_chat_attendance_summary_result($conn,$actor,$entities);
        case 'grades': return edu_chat_universal_grades($conn,$actor,$p);
        case 'evaluations': return edu_chat_universal_evaluations($conn,$actor,$p);
        case 'risk':
            $entities=['level'=>$p['level']?:null,'grade'=>$p['grade']?:null,'section'=>$p['section']?:null,'bimestre'=>$p['bimestre']?:null,'course'=>$p['course']?:null];
            if($p['operation']==='distribution')return edu_chat_academic_risk_distribution_result($conn,$actor,$entities,in_array($p['group_by'],['level','grade','section','grade_section','course'],true)?$p['group_by']:'grade_section');
            if(in_array($p['operation'],['list','ranking'],true))return edu_chat_academic_risk_roster_result($conn,$actor,$entities,$p['critical_min']??1,$p['limit']);
            return edu_chat_academic_risk_current_result($conn,$actor,$entities);
    }
    return edu_chat_result('No pude interpretar el dominio solicitado.');
}
