<?php

/**
 * Motor universal de consultas de solo lectura para EduSync.
 *
 * La IA nunca entrega SQL. Entrega un plan semántico limitado a dominios,
 * métricas, filtros, agrupaciones y ordenamientos permitidos. Este archivo
 * valida el plan y construye únicamente SQL preparado contra relaciones
 * conocidas del sistema.
 */

function edu_chat_uq_allowed_domains(array $actor): array {
    $type=(int)($actor['type']??0);
    if($type===1)return ['school','config','users','students','teachers','courses','assignments','concepts','attendance','payments','debts','grades','evaluations','billing','academic_years'];
    if($type===2)return ['school','students','courses','assignments','grades','evaluations','academic_years'];
    if($type===3)return ['school','students','attendance','academic_years'];
    return [];
}

function edu_chat_uq_allowed_operations(): array {
    return ['count','list','sum','avg','min','max','group'];
}

function edu_chat_uq_clean_text($value,int $max=160): string {
    $value=trim((string)$value);
    if(function_exists('mb_substr'))return mb_substr($value,0,$max,'UTF-8');
    return substr($value,0,$max);
}

function edu_chat_uq_int($value,int $min,int $max,int $default): int {
    if(!is_numeric($value))return $default;
    $n=(int)$value;
    return max($min,min($max,$n));
}

function edu_chat_uq_float_or_null($value): ?float {
    if($value===null||$value===''||!is_numeric($value))return null;
    return (float)$value;
}

function edu_chat_uq_period(mysqli $conn,array $actor,array $filters): array {
    $period=(string)($filters['period']??'');
    $from=edu_chat_uq_clean_text($filters['date_from']??'',10);
    $to=edu_chat_uq_clean_text($filters['date_to']??'',10);
    if($from!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){
        if($to===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=$from;
        if($to<$from){$tmp=$from;$from=$to;$to=$tmp;}
        return [$from,$to,$from===$to?'el '.$from:'del '.$from.' al '.$to];
    }
    if(!in_array($period,['today','yesterday','week','month','last_month','year'],true))$period='year';
    if(function_exists('edu_chat_analytics_period'))return edu_chat_analytics_period($conn,(int)($actor['school_id']??0),$period);
    if($period==='today')return [date('Y-m-d'),date('Y-m-d'),'hoy'];
    if($period==='yesterday'){$d=date('Y-m-d',strtotime('-1 day'));return[$d,$d,'ayer'];}
    if($period==='week')return[date('Y-m-d',strtotime('monday this week')),date('Y-m-d'),'esta semana'];
    if($period==='month')return[date('Y-m-01'),date('Y-m-t'),'este mes'];
    if($period==='last_month')return[date('Y-m-01',strtotime('first day of last month')),date('Y-m-t',strtotime('last day of last month')),'el mes pasado'];
    return[date('Y-01-01'),date('Y-12-31'),'este año'];
}

function edu_chat_uq_year_id_by_label(mysqli $conn,int $school,$year): int {
    $year=trim((string)$year);
    if($year===''||!edu_chat_table_exists($conn,'academic_year'))return 0;
    $stmt=$conn->prepare('SELECT id FROM academic_year WHERE school_id=? AND year=? ORDER BY id DESC LIMIT 1');
    if(!$stmt)return 0;
    $stmt->bind_param('is',$school,$year);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    return(int)($row['id']??0);
}

function edu_chat_uq_active_year_id(mysqli $conn,int $school): int {
    if(function_exists('edu_chat_active_year')){
        $year=edu_chat_active_year($conn,$school);
        return (int)($year['id']??0);
    }
    if(!edu_chat_table_exists($conn,'academic_year'))return 0;
    $stmt=$conn->prepare('SELECT id FROM academic_year WHERE school_id=? ORDER BY is_active DESC,year DESC,id DESC LIMIT 1');
    if(!$stmt)return 0;
    $stmt->bind_param('i',$school);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    return(int)($row['id']??0);
}

function edu_chat_uq_grade_sql(string $expr,string $grade,string &$types,array &$params): string {
    $grade=trim($grade);
    if($grade==='')return '1=1';
    if(preg_match('/^\d+/',$grade,$m)){
        $types.='i';$params[]=(int)$m[0];
        return 'CAST('.$expr.' AS UNSIGNED)=?';
    }
    $types.='s';$params[]=$grade;
    return 'LOWER(TRIM('.$expr.'))=LOWER(TRIM(?))';
}

function edu_chat_uq_student_metric_permissions(int $type): array {
    if($type===1)return ['debt','paid','late','absent','critical','average_grade'];
    if($type===2)return ['critical','average_grade'];
    if($type===3)return ['late','absent'];
    return [];
}

function edu_chat_uq_student_base(mysqli $conn,array $actor,array $plan,array &$types,array &$params): ?string {
    if(!edu_chat_table_exists($conn,'student'))return null;
    $type=(int)($actor['type']??0);$school=(int)($actor['school_id']??0);
    $filters=(array)($plan['filters']??[]);
    [$start,$end]=edu_chat_uq_period($conn,$actor,$filters);
    $yearId=edu_chat_uq_active_year_id($conn,$school);
    $where=['s.school_id=?'];$types='i';$params=[$school];
    if(edu_chat_column_exists($conn,'student','status')){
        if($type===1&&!empty($filters['status'])){$where[]='LOWER(TRIM(s.status))=LOWER(TRIM(?))';$types.='s';$params[]=edu_chat_uq_clean_text($filters['status'],20);}
        else $where[]="LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')";
    }
    if(!empty($filters['year'])&&edu_chat_column_exists($conn,'student','academic_year_id')){
        $studentYear=edu_chat_uq_year_id_by_label($conn,$school,$filters['year']);
        if($studentYear>0){$where[]='s.academic_year_id=?';$types.='i';$params[]=$studentYear;}
    }
    if(!empty($filters['gender'])&&edu_chat_column_exists($conn,'student','genero')){$where[]='LOWER(TRIM(COALESCE(s.genero,\'\')))=LOWER(TRIM(?))';$types.='s';$params[]=edu_chat_uq_clean_text($filters['gender'],20);}
    if(!empty($filters['level'])){$where[]="LOWER(TRIM(s.nivel))=LOWER(TRIM(?))";$types.='s';$params[]=edu_chat_uq_clean_text($filters['level'],40);}
    if(!empty($filters['grade']))$where[]=edu_chat_uq_grade_sql('s.grado',(string)$filters['grade'],$types,$params);
    if(!empty($filters['section'])){$where[]="UPPER(TRIM(COALESCE(s.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=edu_chat_uq_clean_text($filters['section'],10);}
    if(!empty($filters['name'])){$where[]='LOWER(s.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['name'],120).'%';}

    if($type===2){
        $teacher=(int)($actor['teacher_id']??0);
        if($teacher<=0)return null;
        $scope="EXISTS(SELECT 1 FROM teacher_courses scope_tc WHERE scope_tc.school_id=s.school_id AND scope_tc.teacher_id=? AND CAST(scope_tc.grado AS UNSIGNED)=CAST(s.grado AS UNSIGNED) AND UPPER(TRIM(COALESCE(scope_tc.seccion,'')))=UPPER(TRIM(COALESCE(s.seccion,'')))";
        $types.='i';$params[]=$teacher;
        if($yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){$scope.=' AND scope_tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
        $where[]=$scope.')';
    }

    $debtExpr='0';
    if($type===1&&edu_chat_table_exists($conn,'student_ef_list')&&edu_chat_table_exists($conn,'payments')){
        $effective=edu_chat_column_exists($conn,'student_ef_list','discounted_amount')?'COALESCE(ef.discounted_amount,ef.total_fee)':'ef.total_fee';
        $payStatus=edu_chat_column_exists($conn,'payments','payment_status')?" AND COALESCE(p.payment_status,'Confirmado')='Confirmado'":'';
        $debtStatus=edu_chat_column_exists($conn,'student_ef_list','debt_status')?" AND LOWER(TRIM(COALESCE(ef.debt_status,'Activa')))='activa'":'';
        $debtExpr="(SELECT COALESCE(SUM(GREATEST($effective-COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.ef_id=ef.id$payStatus),0),0)),0) FROM student_ef_list ef WHERE ef.student_id=s.id$debtStatus)";
    }

    $paidExpr='0';
    if($type===1&&edu_chat_table_exists($conn,'student_ef_list')&&edu_chat_table_exists($conn,'payments')){
        $payStatus=edu_chat_column_exists($conn,'payments','payment_status')?" AND COALESCE(p.payment_status,'Confirmado')='Confirmado'":'';
        $paidExpr="(SELECT COALESCE(SUM(p.amount),0) FROM payments p INNER JOIN student_ef_list efp ON efp.id=p.ef_id WHERE efp.student_id=s.id AND DATE(p.date_created) BETWEEN '$start' AND '$end'$payStatus)";
    }

    $lateExpr='0';$absentExpr='0';
    if(in_array($type,[1,3],true)&&edu_chat_table_exists($conn,'asistencia')){
        $lateExpr="(SELECT COUNT(*) FROM asistencia a WHERE a.student_id=s.id AND a.tipo='Entrada' AND a.fecha BETWEEN '$start' AND '$end' AND LOWER(TRIM(a.estado))='tarde')";
        $absentExpr="(SELECT COUNT(*) FROM asistencia a WHERE a.student_id=s.id AND a.tipo='Entrada' AND a.fecha BETWEEN '$start' AND '$end' AND LOWER(TRIM(a.estado)) IN ('ausente','falta'))";
    }

    $criticalExpr='0';$avgExpr='NULL';
    if(in_array($type,[1,2],true)&&edu_chat_table_exists($conn,'evaluation_grades')&&edu_chat_table_exists($conn,'evaluations')&&edu_chat_table_exists($conn,'teacher_courses')){
        $academicWhere=[];
        if($yearId>0&&edu_chat_column_exists($conn,'evaluations','academic_year_id'))$academicWhere[]='e.academic_year_id='.(int)$yearId;
        if($type===2)$academicWhere[]='tc.teacher_id='.(int)($actor['teacher_id']??0);
        if(!empty($filters['bimestre']))$academicWhere[]="e.bimestre='".((int)$filters['bimestre'])."'";
        if(!empty($filters['course'])&&edu_chat_table_exists($conn,'academic_courses')){
            $safe=$conn->real_escape_string(edu_chat_uq_clean_text($filters['course'],120));
            $academicWhere[]="LOWER(TRIM(ac.name)) LIKE LOWER('%$safe%')";
        }
        $extra=$academicWhere?' AND '.implode(' AND ',$academicWhere):'';
        $courseJoin=edu_chat_table_exists($conn,'academic_courses')?' LEFT JOIN academic_courses ac ON ac.id=tc.course_id ':'';
        $critical="((eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(eg.grade AS DECIMAL(10,2))<10.5) OR UPPER(TRIM(eg.grade))='C')";
        $criticalExpr="(SELECT COUNT(*) FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id $courseJoin WHERE eg.student_id=s.id AND $critical$extra)";
        $num="CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2)) WHEN UPPER(TRIM(eg.grade))='C' THEN 5 WHEN UPPER(TRIM(eg.grade))='B' THEN 12 WHEN UPPER(TRIM(eg.grade))='A' THEN 15.5 WHEN UPPER(TRIM(eg.grade))='AD' THEN 19 ELSE NULL END";
        $avgExpr="(SELECT ROUND(AVG($num),2) FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id $courseJoin WHERE eg.student_id=s.id$extra)";
    }

    $genderExpr=edu_chat_column_exists($conn,'student','genero')?"COALESCE(NULLIF(TRIM(s.genero),''),'Sin dato')":"'Sin dato'";
    $statusExpr=edu_chat_column_exists($conn,'student','status')?"COALESCE(NULLIF(TRIM(s.status),''),'Activo')":"'Activo'";
    return "SELECT s.id,s.name,$genderExpr gender,$statusExpr status,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion,$debtExpr debt,$paidExpr paid,$lateExpr late,$absentExpr absent,$criticalExpr critical,$avgExpr average_grade FROM student s WHERE ".implode(' AND ',$where);
}

function edu_chat_uq_student_query(mysqli $conn,array $actor,array $plan): array {
    $types='';$params=[];$base=edu_chat_uq_student_base($conn,$actor,$plan,$types,$params);
    if($base===null)return edu_chat_result('No está disponible la información necesaria de estudiantes.');
    $filters=(array)($plan['filters']??[]);$operation=(string)($plan['operation']??'list');$metric=(string)($plan['metric']??'count');
    $type=(int)($actor['type']??0);$allowedMetrics=edu_chat_uq_student_metric_permissions($type);
    if($metric!=='count'&&!in_array($metric,$allowedMetrics,true))return edu_chat_result('Ese cruce de información no está autorizado para este perfil.');

    $outer=[];
    $map=['debt'=>'debt','paid'=>'paid','late'=>'late','absent'=>'absent','critical'=>'critical','average_grade'=>'average_grade'];
    foreach($map as $key=>$col){
        $min=edu_chat_uq_float_or_null($filters[$key.'_min']??null);$max=edu_chat_uq_float_or_null($filters[$key.'_max']??null);
        if(($min!==null||$max!==null)&&!in_array($key,$allowedMetrics,true))return edu_chat_result('Ese filtro no está autorizado para este perfil.');
        if($min!==null){$outer[]="$col>=?";$types.='d';$params[]=$min;}
        if($max!==null){$outer[]="$col<=?";$types.='d';$params[]=$max;}
    }
    $outerSql=$outer?' WHERE '.implode(' AND ',$outer):'';
    $limit=edu_chat_uq_int($plan['limit']??50,1,200,50);
    $group=(string)($plan['group_by']??'none');
    $sort=(string)($plan['sort_by']??'name');$dir=strtolower((string)($plan['sort_dir']??'asc'))==='desc'?'DESC':'ASC';

    if($operation==='count'){
        $sql="SELECT COUNT(*) value FROM ($base) uq$outerSql";
        $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar la consulta solicitada.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        return edu_chat_result('Resultado: '.(int)($row['value']??0).' estudiante'.((int)($row['value']??0)===1?'':'s').'.');
    }

    if(in_array($operation,['sum','avg','min','max'],true)){
        if($metric==='count')return edu_chat_result('Para esa operación necesito una métrica como deuda, pagos, tardanzas, ausencias, riesgo o promedio.');
        $fn=strtoupper($operation);$sql="SELECT $fn($metric) value FROM ($base) uq$outerSql";
        $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar la consulta solicitada.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        $value=$row['value'];if($value===null)return edu_chat_result('No hay datos suficientes para calcular ese valor.');
        $formatted=in_array($metric,['debt','paid'],true)?edu_chat_money((float)$value):number_format((float)$value,2,'.','');
        return edu_chat_result('Resultado: '.$formatted.'.');
    }

    if($operation==='group'){
        $groups=[
            'level'=>['nivel','nivel'],
            'grade'=>['CONCAT(nivel,\' · \',grado)','nivel,CAST(grado AS UNSIGNED),grado'],
            'section'=>['seccion','seccion'],
            'grade_section'=>['CONCAT(nivel,\' · \',grado,\' \',seccion)','nivel,CAST(grado AS UNSIGNED),grado,seccion'],
            'gender'=>['gender','gender'],
            'status'=>['status','status']
        ];
        if(!isset($groups[$group]))$group='grade_section';
        $gexpr=$groups[$group][0];$gorder=$groups[$group][1];
        $valueExpr=$metric==='count'?'COUNT(*)':(in_array($metric,['debt','paid'],true)?"SUM($metric)":"AVG($metric)");
        $sql="SELECT $gexpr label,$valueExpr value,COUNT(*) records FROM ($base) uq$outerSql GROUP BY $gexpr ORDER BY ".($sort==='value'?'value '.$dir:$gorder)." LIMIT $limit";
        $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar el desglose solicitado.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];$cards=[];
        while($row=$res->fetch_assoc()){
            $label=(string)$row['label'];$value=(float)$row['value'];
            $display=in_array($metric,['debt','paid'],true)?edu_chat_money($value):($metric==='count'?(string)(int)$value:number_format($value,2,'.',''));
            $lines[]='• '.$label.': '.$display;
            if(count($cards)<8)$cards[]=['label'=>$label,'value'=>$display,'tone'=>'primary'];
        }
        $stmt->close();if(!$lines)return edu_chat_result('No encontré datos con esos filtros.');
        return edu_chat_result("Resultado por grupo:\n".implode("\n",$lines),[],$cards);
    }

    $allowedSort=['name'=>'name','debt'=>'debt','paid'=>'paid','late'=>'late','absent'=>'absent','critical'=>'critical','average_grade'=>'average_grade'];
    $sortCol=$allowedSort[$sort]??'name';
    $sql="SELECT * FROM ($base) uq$outerSql ORDER BY $sortCol $dir,name ASC LIMIT $limit";
    $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar el listado solicitado.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];$count=0;
    while($row=$res->fetch_assoc()){
        $count++;$grade=function_exists('edu_chat_grade_label')?edu_chat_grade_label($row['grado']):(string)$row['grado'];
        $parts=[];$parts[]=$row['name'].' — '.$grade.' '.$row['seccion'];
        if(in_array('debt',$allowedMetrics,true)&&((float)$row['debt']>0||$metric==='debt'))$parts[]='deuda '.edu_chat_money((float)$row['debt']);
        if(in_array('paid',$allowedMetrics,true)&&($metric==='paid'||isset($filters['paid_min'])||isset($filters['paid_max'])))$parts[]='pagado '.edu_chat_money((float)$row['paid']);
        if(in_array('late',$allowedMetrics,true)&&($metric==='late'||isset($filters['late_min'])||isset($filters['late_max'])))$parts[]=(int)$row['late'].' tardanzas';
        if(in_array('absent',$allowedMetrics,true)&&($metric==='absent'||isset($filters['absent_min'])||isset($filters['absent_max'])))$parts[]=(int)$row['absent'].' ausencias';
        if(in_array('critical',$allowedMetrics,true)&&($metric==='critical'||isset($filters['critical_min'])||isset($filters['critical_max'])))$parts[]=(int)$row['critical'].' registros críticos';
        if(in_array('average_grade',$allowedMetrics,true)&&($metric==='average_grade'||isset($filters['average_grade_min'])||isset($filters['average_grade_max']))&&$row['average_grade']!==null)$parts[]='promedio '.number_format((float)$row['average_grade'],2,'.','');
        $lines[]='• '.implode(' · ',$parts);
    }
    $stmt->close();if(!$lines)return edu_chat_result('No encontré estudiantes que cumplan esos filtros.');
    return edu_chat_result('Estudiantes encontrados ('.$count."):\n".implode("\n",$lines));
}

function edu_chat_uq_teacher_query(mysqli $conn,array $actor,array $plan): array {
    if(!edu_chat_table_exists($conn,'teacher'))return edu_chat_result('No está disponible la información de docentes.');
    $type=(int)($actor['type']??0);if(!in_array($type,[1,2],true))return edu_chat_result('Esta consulta no está autorizada para tu perfil.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);$where=['t.school_id=?'];$types='i';$params=[$school];
    if($type===2){$where[]='t.id=?';$types.='i';$params[]=(int)($actor['teacher_id']??0);}
    if(!empty($filters['name'])){$where[]='LOWER(t.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['name'],120).'%';}
    if(!empty($filters['specialty'])){$where[]='LOWER(t.specialty) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['specialty'],120).'%';}
    $yearId=edu_chat_uq_active_year_id($conn,$school);
    $yearCond=$yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')?' AND tc.academic_year_id='.(int)$yearId:'';
    $base="SELECT t.id,t.name,t.specialty,t.contact,t.email,(SELECT COUNT(*) FROM teacher_courses tc WHERE tc.teacher_id=t.id AND tc.school_id=t.school_id$yearCond) assignments,(SELECT COUNT(DISTINCT tc.course_id) FROM teacher_courses tc WHERE tc.teacher_id=t.id AND tc.school_id=t.school_id$yearCond) courses FROM teacher t WHERE ".implode(' AND ',$where);
    $operation=(string)($plan['operation']??'list');$limit=edu_chat_uq_int($plan['limit']??50,1,200,50);
    if($operation==='count'){$sql="SELECT COUNT(*) value FROM ($base) uq";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado: '.(int)$row['value'].' docente'.((int)$row['value']===1?'':'s').'.');}
    if($operation==='group'){
        $group=(string)($plan['group_by']??'specialty');if($group!=='specialty')$group='specialty';
        $sql="SELECT COALESCE(NULLIF(TRIM(specialty),''),'Sin especialidad') label,COUNT(*) value FROM ($base) uq GROUP BY label ORDER BY value DESC,label LIMIT $limit";
        $stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['label'].': '.(int)$r['value'];$stmt->close();return edu_chat_result($lines?"Docentes por especialidad:\n".implode("\n",$lines):'No encontré docentes con esos filtros.');
    }
    $sort=(string)($plan['sort_by']??'name');$dir=strtolower((string)($plan['sort_dir']??'asc'))==='desc'?'DESC':'ASC';$sortCol=in_array($sort,['name','assignments','courses'],true)?$sort:'name';
    $sql="SELECT * FROM ($base) uq ORDER BY $sortCol $dir,name LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc()){$line='• '.$r['name'];if(trim((string)$r['specialty'])!=='')$line.=' — '.$r['specialty'];$line.=' · '.(int)$r['courses'].' cursos · '.(int)$r['assignments'].' asignaciones';$lines[]=$line;}$stmt->close();return edu_chat_result($lines?"Docentes encontrados:\n".implode("\n",$lines):'No encontré docentes con esos filtros.');
}

function edu_chat_uq_assignment_query(mysqli $conn,array $actor,array $plan,bool $coursesOnly=false): array {
    foreach(['teacher_courses','academic_courses'] as $t)if(!edu_chat_table_exists($conn,$t))return edu_chat_result('No está disponible la información de cursos/asignaciones.');
    $type=(int)($actor['type']??0);if(!in_array($type,[1,2],true))return edu_chat_result('Esta consulta no está autorizada para tu perfil.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);$yearId=edu_chat_uq_active_year_id($conn,$school);
    $where=['tc.school_id=?'];$types='i';$params=[$school];
    if($type===2){$where[]='tc.teacher_id=?';$types.='i';$params[]=(int)($actor['teacher_id']??0);}
    if(edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){
        $requestedYear=!empty($filters['year'])?edu_chat_uq_year_id_by_label($conn,$school,$filters['year']):0;
        $scopeYear=$requestedYear>0?$requestedYear:$yearId;
        if($scopeYear>0){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$scopeYear;}
    }
    if(!empty($filters['level'])){$where[]='LOWER(TRIM(tc.level))=LOWER(TRIM(?))';$types.='s';$params[]=edu_chat_uq_clean_text($filters['level'],40);}
    if(!empty($filters['grade']))$where[]=edu_chat_uq_grade_sql('tc.grado',(string)$filters['grade'],$types,$params);
    if(!empty($filters['section'])){$where[]="UPPER(TRIM(COALESCE(tc.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=edu_chat_uq_clean_text($filters['section'],10);}
    if(!empty($filters['course'])){$where[]='LOWER(ac.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['course'],120).'%';}
    if(!empty($filters['teacher'])){$where[]='LOWER(t.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['teacher'],120).'%';}
    $teacherJoin=edu_chat_table_exists($conn,'teacher')?' LEFT JOIN teacher t ON t.id=tc.teacher_id ':'';
    $teacherName=edu_chat_table_exists($conn,'teacher')?"COALESCE(t.name,'Docente')":"CONCAT('Docente ',tc.teacher_id)";
    $base="SELECT tc.id,ac.name course,tc.level,tc.grado,COALESCE(NULLIF(TRIM(tc.seccion),''),'U') seccion,$teacherName teacher FROM teacher_courses tc INNER JOIN academic_courses ac ON ac.id=tc.course_id $teacherJoin WHERE ".implode(' AND ',$where);
    if($coursesOnly)$base="SELECT MIN(id) id,course,level,grado,seccion,GROUP_CONCAT(DISTINCT teacher ORDER BY teacher SEPARATOR ', ') teacher FROM ($base) z GROUP BY course,level,grado,seccion";
    $operation=(string)($plan['operation']??'list');$limit=edu_chat_uq_int($plan['limit']??100,1,200,100);
    if($operation==='count'){$sql="SELECT COUNT(*) value FROM ($base) uq";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado: '.(int)$row['value'].($coursesOnly?' cursos/aulas.':' asignaciones.'));}
    if($operation==='group'){
        $group=(string)($plan['group_by']??'grade_section');$map=['level'=>'level','grade'=>'CONCAT(level,\' · \',grado)','section'=>'seccion','grade_section'=>'CONCAT(level,\' · \',grado,\' \',seccion)','course'=>'course','teacher'=>'teacher'];$expr=$map[$group]??$map['grade_section'];
        $sql="SELECT $expr label,COUNT(*) value FROM ($base) uq GROUP BY $expr ORDER BY value DESC,label LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['label'].': '.(int)$r['value'];$stmt->close();return edu_chat_result($lines?"Resultado por grupo:\n".implode("\n",$lines):'No encontré datos con esos filtros.');
    }
    $sql="SELECT * FROM ($base) uq ORDER BY level,CAST(grado AS UNSIGNED),grado,seccion,course LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc()){$g=function_exists('edu_chat_grade_label')?edu_chat_grade_label($r['grado']):$r['grado'];$lines[]='• '.$r['course'].' — '.$r['level'].' · '.$g.' '.$r['seccion'].' · '.$r['teacher'];}$stmt->close();return edu_chat_result($lines?"Cursos/asignaciones encontrados:\n".implode("\n",$lines):'No encontré cursos/asignaciones con esos filtros.');
}

function edu_chat_uq_attendance_query(mysqli $conn,array $actor,array $plan): array {
    if(!edu_chat_table_exists($conn,'asistencia')||!edu_chat_table_exists($conn,'student'))return edu_chat_result('No está disponible la información de asistencia.');
    $type=(int)($actor['type']??0);if(!in_array($type,[1,3],true))return edu_chat_result('Esta consulta no está autorizada para tu perfil.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);[$start,$end,$label]=edu_chat_uq_period($conn,$actor,$filters);
    $where=['s.school_id=?',"a.fecha BETWEEN ? AND ?"];$types='iss';$params=[$school,$start,$end];
    if(edu_chat_column_exists($conn,'student','status'))$where[]="LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')";
    if(!empty($filters['level'])){$where[]='LOWER(TRIM(s.nivel))=LOWER(TRIM(?))';$types.='s';$params[]=edu_chat_uq_clean_text($filters['level'],40);}
    if(!empty($filters['grade']))$where[]=edu_chat_uq_grade_sql('s.grado',(string)$filters['grade'],$types,$params);
    if(!empty($filters['section'])){$where[]="UPPER(TRIM(COALESCE(s.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=edu_chat_uq_clean_text($filters['section'],10);}
    if(!empty($filters['name'])){$where[]='LOWER(s.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['name'],120).'%';}
    if(!empty($filters['attendance_status'])){$where[]='LOWER(TRIM(a.estado))=LOWER(TRIM(?))';$types.='s';$params[]=edu_chat_uq_clean_text($filters['attendance_status'],40);}
    $base="SELECT a.id,a.fecha,a.hora,a.tipo,a.estado,s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion FROM asistencia a INNER JOIN student s ON s.id=a.student_id WHERE ".implode(' AND ',$where);
    $operation=(string)($plan['operation']??'count');$limit=edu_chat_uq_int($plan['limit']??100,1,200,100);
    if($operation==='count'){$sql="SELECT COUNT(*) value FROM ($base) uq";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado de asistencia '.$label.': '.(int)$r['value'].' registros.');}
    if($operation==='group'){
        $group=(string)($plan['group_by']??'status');$map=['status'=>'estado','level'=>'nivel','grade'=>'CONCAT(nivel,\' · \',grado)','section'=>'seccion','grade_section'=>'CONCAT(nivel,\' · \',grado,\' \',seccion)','date'=>'fecha'];$expr=$map[$group]??'estado';
        $sql="SELECT $expr label,COUNT(*) value FROM ($base) uq GROUP BY $expr ORDER BY value DESC,label LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['label'].': '.(int)$r['value'];$stmt->close();return edu_chat_result($lines?"Asistencia $label:\n".implode("\n",$lines):'No encontré registros de asistencia con esos filtros.');
    }
    $sql="SELECT * FROM ($base) uq ORDER BY fecha DESC,hora DESC,name LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc()){$g=function_exists('edu_chat_grade_label')?edu_chat_grade_label($r['grado']):$r['grado'];$lines[]='• '.$r['name'].' — '.$g.' '.$r['seccion'].' · '.$r['fecha'].' '.$r['hora'].' · '.$r['tipo'].' · '.$r['estado'];}$stmt->close();return edu_chat_result($lines?"Registros de asistencia $label:\n".implode("\n",$lines):'No encontré registros de asistencia con esos filtros.');
}

function edu_chat_uq_payment_query(mysqli $conn,array $actor,array $plan): array {
    if((int)($actor['type']??0)!==1)return edu_chat_result('Esta consulta financiera solo está disponible para administración.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);[$start,$end,$label]=edu_chat_uq_period($conn,$actor,$filters);
    $types='';$params=[];$base='';

    if(edu_chat_table_exists($conn,'payment_operations')&&edu_chat_table_exists($conn,'payment_operation_methods')&&edu_chat_table_exists($conn,'payment_methods')){
        $where=['po.school_id=?','DATE(po.payment_date) BETWEEN ? AND ?'];$types='iss';$params=[$school,$start,$end];
        if(edu_chat_column_exists($conn,'payment_operations','status'))$where[]="COALESCE(po.status,'Confirmado')='Confirmado'";
        if(!empty($filters['payment_method'])){$where[]='LOWER(TRIM(pm.name))=LOWER(TRIM(?))';$types.='s';$params[]=edu_chat_uq_clean_text($filters['payment_method'],50);}
        if(!empty($filters['name'])){$where[]='LOWER(s.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['name'],120).'%';}
        $base="SELECT po.id,po.payment_date date,s.name student,pm.name payment_method,pom.amount,po.receipt_full receipt FROM payment_operations po INNER JOIN payment_operation_methods pom ON pom.operation_id=po.id INNER JOIN payment_methods pm ON pm.id=pom.payment_method_id INNER JOIN student s ON s.id=po.student_id AND s.school_id=po.school_id WHERE ".implode(' AND ',$where);
    }elseif(edu_chat_table_exists($conn,'payments')&&edu_chat_table_exists($conn,'student_ef_list')){
        $where=['s.school_id=?','DATE(p.date_created) BETWEEN ? AND ?'];$types='iss';$params=[$school,$start,$end];
        if(edu_chat_column_exists($conn,'payments','payment_status'))$where[]="COALESCE(p.payment_status,'Confirmado')='Confirmado'";

        $hasMethods=edu_chat_table_exists($conn,'payment_methods');
        $hasDirectMethod=$hasMethods&&edu_chat_column_exists($conn,'payments','payment_method_id');
        $hasSplit=$hasMethods&&edu_chat_table_exists($conn,'payment_split')
            &&edu_chat_column_exists($conn,'payment_split','payment_id')
            &&edu_chat_column_exists($conn,'payment_split','payment_method_id')
            &&edu_chat_column_exists($conn,'payment_split','amount');

        $methodJoin='';$methodExpr="'Sin método'";$amountExpr='p.amount';
        if($hasSplit){
            $methodJoin.=" LEFT JOIN payment_split ps ON ps.payment_id=p.id LEFT JOIN payment_methods pms ON pms.id=ps.payment_method_id ";
            if($hasDirectMethod)$methodJoin.=" LEFT JOIN payment_methods pmd ON pmd.id=p.payment_method_id ";
            $methodExpr=$hasDirectMethod?"COALESCE(pms.name,pmd.name,'Sin método')":"COALESCE(pms.name,'Sin método')";
            $amountExpr='COALESCE(ps.amount,p.amount)';
        }elseif($hasDirectMethod){
            $methodJoin=' LEFT JOIN payment_methods pmd ON pmd.id=p.payment_method_id ';
            $methodExpr="COALESCE(pmd.name,'Sin método')";
        }

        if(!empty($filters['payment_method'])&&$hasMethods){
            $where[]="LOWER(TRIM($methodExpr))=LOWER(TRIM(?))";$types.='s';$params[]=edu_chat_uq_clean_text($filters['payment_method'],50);
        }
        if(!empty($filters['name'])){$where[]='LOWER(s.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['name'],120).'%';}
        $base="SELECT p.id,p.date_created date,s.name student,$methodExpr payment_method,$amountExpr amount,p.receipt_no receipt FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id INNER JOIN student s ON s.id=ef.student_id $methodJoin WHERE ".implode(' AND ',$where);
    }else return edu_chat_result('No está disponible la información de pagos.');

    $operation=(string)($plan['operation']??'sum');$limit=edu_chat_uq_int($plan['limit']??100,1,200,100);
    $min=edu_chat_uq_float_or_null($filters['amount_min']??null);$max=edu_chat_uq_float_or_null($filters['amount_max']??null);$outer=[];if($min!==null){$outer[]='amount>=?';$types.='d';$params[]=$min;}if($max!==null){$outer[]='amount<=?';$types.='d';$params[]=$max;}$outerSql=$outer?' WHERE '.implode(' AND ',$outer):'';
    if($operation==='count'){$sql="SELECT COUNT(DISTINCT id) value FROM ($base) uq$outerSql";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado '.$label.': '.(int)$r['value'].' pagos/operaciones.');}
    if(in_array($operation,['sum','avg','min','max'],true)){$fn=strtoupper($operation);$sql="SELECT $fn(amount) value FROM ($base) uq$outerSql";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado '.$label.': '.edu_chat_money((float)($r['value']??0)).'.');}
    if($operation==='group'){$group=(string)($plan['group_by']??'payment_method');$map=['payment_method'=>'payment_method','date'=>'DATE(date)','student'=>'student'];$expr=$map[$group]??'payment_method';$sql="SELECT $expr label,SUM(amount) value,COUNT(DISTINCT id) records FROM ($base) uq$outerSql GROUP BY $expr ORDER BY value DESC,label LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['label'].': '.edu_chat_money((float)$r['value']).' · '.(int)$r['records'].' pagos';$stmt->close();return edu_chat_result($lines?"Cobranza $label:\n".implode("\n",$lines):'No encontré pagos con esos filtros.');}
    $sql="SELECT * FROM ($base) uq$outerSql ORDER BY date DESC,id DESC LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['student'].' — '.edu_chat_money((float)$r['amount']).' · '.$r['payment_method'].' · '.$r['date'].' · recibo '.$r['receipt'];$stmt->close();return edu_chat_result($lines?"Pagos $label:\n".implode("\n",$lines):'No encontré pagos con esos filtros.');
}

function edu_chat_uq_debt_query(mysqli $conn,array $actor,array $plan): array {
    if((int)($actor['type']??0)!==1)return edu_chat_result('Esta consulta financiera solo está disponible para administración.');
    foreach(['student_ef_list','student','payments'] as $t)if(!edu_chat_table_exists($conn,$t))return edu_chat_result('No está disponible la información necesaria de deuda.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);$effective=edu_chat_column_exists($conn,'student_ef_list','discounted_amount')?'COALESCE(ef.discounted_amount,ef.total_fee)':'ef.total_fee';$payStatus=edu_chat_column_exists($conn,'payments','payment_status')?" AND COALESCE(p.payment_status,'Confirmado')='Confirmado'":'';
    $courseJoin=edu_chat_table_exists($conn,'courses')?' LEFT JOIN courses c ON c.id=ef.course_id ':'';$concept=edu_chat_table_exists($conn,'courses')?"COALESCE(c.course,'Concepto')":"'Concepto'";
    $where=['s.school_id=?'];$types='i';$params=[$school];if(edu_chat_column_exists($conn,'student','status'))$where[]="LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')";
    if(!empty($filters['level'])){$where[]='LOWER(TRIM(s.nivel))=LOWER(TRIM(?))';$types.='s';$params[]=edu_chat_uq_clean_text($filters['level'],40);}if(!empty($filters['grade']))$where[]=edu_chat_uq_grade_sql('s.grado',(string)$filters['grade'],$types,$params);if(!empty($filters['section'])){$where[]="UPPER(TRIM(COALESCE(s.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=edu_chat_uq_clean_text($filters['section'],10);}if(!empty($filters['name'])){$where[]='LOWER(s.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['name'],120).'%';}
    $debtStatus=edu_chat_column_exists($conn,'student_ef_list','debt_status')?" AND LOWER(TRIM(COALESCE(ef.debt_status,'Activa')))='activa'":'';
    $base="SELECT ef.id,s.name student,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion,$concept concept,GREATEST($effective-COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.ef_id=ef.id$payStatus),0),0) debt FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id $courseJoin WHERE ".implode(' AND ',$where).$debtStatus;
    $outer=['debt>0.009'];$min=edu_chat_uq_float_or_null($filters['debt_min']??null);$max=edu_chat_uq_float_or_null($filters['debt_max']??null);if($min!==null){$outer[]='debt>=?';$types.='d';$params[]=$min;}if($max!==null){$outer[]='debt<=?';$types.='d';$params[]=$max;}$outerSql=' WHERE '.implode(' AND ',$outer);
    $operation=(string)($plan['operation']??'sum');$limit=edu_chat_uq_int($plan['limit']??100,1,200,100);
    if($operation==='count'){$sql="SELECT COUNT(*) value FROM ($base) uq$outerSql";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado: '.(int)$r['value'].' obligaciones con saldo pendiente.');}
    if(in_array($operation,['sum','avg','min','max'],true)){$fn=strtoupper($operation);$sql="SELECT $fn(debt) value FROM ($base) uq$outerSql";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado: '.edu_chat_money((float)($r['value']??0)).'.');}
    if($operation==='group'){$group=(string)($plan['group_by']??'grade_section');$map=['level'=>'nivel','grade'=>'CONCAT(nivel,\' · \',grado)','section'=>'seccion','grade_section'=>'CONCAT(nivel,\' · \',grado,\' \',seccion)','student'=>'student','concept'=>'concept'];$expr=$map[$group]??$map['grade_section'];$sql="SELECT $expr label,SUM(debt) value,COUNT(*) records FROM ($base) uq$outerSql GROUP BY $expr ORDER BY value DESC,label LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['label'].': '.edu_chat_money((float)$r['value']).' · '.(int)$r['records'].' obligaciones';$stmt->close();return edu_chat_result($lines?"Deuda por grupo:\n".implode("\n",$lines):'No encontré deuda con esos filtros.');}
    $sql="SELECT * FROM ($base) uq$outerSql ORDER BY debt DESC,student LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc()){$g=function_exists('edu_chat_grade_label')?edu_chat_grade_label($r['grado']):$r['grado'];$lines[]='• '.$r['student'].' — '.$g.' '.$r['seccion'].' · '.$r['concept'].' · '.edu_chat_money((float)$r['debt']);}$stmt->close();return edu_chat_result($lines?"Deudas encontradas:\n".implode("\n",$lines):'No encontré deuda con esos filtros.');
}

function edu_chat_uq_grade_query(mysqli $conn,array $actor,array $plan,bool $evaluationsOnly=false): array {
    foreach(['evaluation_grades','evaluations','teacher_courses','student'] as $t)if(!edu_chat_table_exists($conn,$t))return edu_chat_result('No está disponible la información académica necesaria.');
    $type=(int)($actor['type']??0);if(!in_array($type,[1,2],true))return edu_chat_result('Esta consulta académica no está autorizada para tu perfil.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);$yearId=edu_chat_uq_active_year_id($conn,$school);
    $where=['s.school_id=?'];$types='i';$params=[$school];
    if(edu_chat_column_exists($conn,'evaluations','academic_year_id')){
        $requestedYear=!empty($filters['year'])?edu_chat_uq_year_id_by_label($conn,$school,$filters['year']):0;
        $scopeYear=$requestedYear>0?$requestedYear:$yearId;
        if($scopeYear>0){$where[]='e.academic_year_id=?';$types.='i';$params[]=$scopeYear;}
    }
    if($type===2){$where[]='tc.teacher_id=?';$types.='i';$params[]=(int)($actor['teacher_id']??0);}
    if(!empty($filters['level'])){$where[]='LOWER(TRIM(s.nivel))=LOWER(TRIM(?))';$types.='s';$params[]=edu_chat_uq_clean_text($filters['level'],40);}if(!empty($filters['grade']))$where[]=edu_chat_uq_grade_sql('s.grado',(string)$filters['grade'],$types,$params);if(!empty($filters['section'])){$where[]="UPPER(TRIM(COALESCE(s.seccion,'')))=UPPER(TRIM(?))";$types.='s';$params[]=edu_chat_uq_clean_text($filters['section'],10);}if(!empty($filters['name'])){$where[]='LOWER(s.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['name'],120).'%';}if(!empty($filters['bimestre'])){$where[]='e.bimestre=?';$types.='s';$params[]=(string)((int)$filters['bimestre']);}
    $courseJoin=edu_chat_table_exists($conn,'academic_courses')?' LEFT JOIN academic_courses ac ON ac.id=tc.course_id ':'';$courseExpr=edu_chat_table_exists($conn,'academic_courses')?"COALESCE(ac.name,'Curso')":"'Curso'";if(!empty($filters['course'])&&edu_chat_table_exists($conn,'academic_courses')){$where[]='LOWER(ac.name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['course'],120).'%';}
    $compJoin=edu_chat_table_exists($conn,'competencias')?' LEFT JOIN competencias cp ON cp.id=eg.competencia_id ':'';$compExpr=edu_chat_table_exists($conn,'competencias')?"COALESCE(cp.name,'Competencia')":"'Competencia'";
    $num="CASE WHEN eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(eg.grade AS DECIMAL(10,2)) WHEN UPPER(TRIM(eg.grade))='C' THEN 5 WHEN UPPER(TRIM(eg.grade))='B' THEN 12 WHEN UPPER(TRIM(eg.grade))='A' THEN 15.5 WHEN UPPER(TRIM(eg.grade))='AD' THEN 19 ELSE NULL END";
    $base="SELECT eg.id,s.name student,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion,$courseExpr course,e.title evaluation,e.type,e.bimestre,$compExpr competencia,eg.grade,$num grade_numeric FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id INNER JOIN student s ON s.id=eg.student_id $courseJoin $compJoin WHERE ".implode(' AND ',$where);
    if($evaluationsOnly)$base="SELECT MIN(id) id,course,evaluation,type,bimestre,COUNT(*) grade_records,ROUND(AVG(grade_numeric),2) average_grade FROM ($base) z GROUP BY course,evaluation,type,bimestre";
    $outer=[];$min=edu_chat_uq_float_or_null($filters['grade_min']??null);$max=edu_chat_uq_float_or_null($filters['grade_max']??null);if(!$evaluationsOnly){if($min!==null){$outer[]='grade_numeric>=?';$types.='d';$params[]=$min;}if($max!==null){$outer[]='grade_numeric<=?';$types.='d';$params[]=$max;}}$outerSql=$outer?' WHERE '.implode(' AND ',$outer):'';
    $operation=(string)($plan['operation']??'list');$limit=edu_chat_uq_int($plan['limit']??100,1,200,100);
    if($operation==='count'){$sql="SELECT COUNT(*) value FROM ($base) uq$outerSql";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado: '.(int)$r['value'].($evaluationsOnly?' evaluaciones.':' registros de nota.'));}
    if(!$evaluationsOnly&&in_array($operation,['avg','min','max'],true)){$fn=strtoupper($operation);$sql="SELECT $fn(grade_numeric) value FROM ($base) uq$outerSql";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result($r['value']===null?'No hay notas suficientes para calcular ese valor.':'Resultado: '.number_format((float)$r['value'],2,'.','').'.');}
    if($operation==='group'){
        $group=(string)($plan['group_by']??'course');$map=$evaluationsOnly?['course'=>'course','bimestre'=>'bimestre','type'=>'type']:['course'=>'course','student'=>'student','bimestre'=>'bimestre','grade_section'=>'CONCAT(nivel,\' · \',grado,\' \',seccion)','competency'=>'competencia','type'=>'type'];$expr=$map[$group]??$map['course'];$valueExpr=$evaluationsOnly?'COUNT(*)':'ROUND(AVG(grade_numeric),2)';$sql="SELECT $expr label,$valueExpr value,COUNT(*) records FROM ($base) uq$outerSql GROUP BY $expr ORDER BY value DESC,label LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['label'].': '.($evaluationsOnly?(int)$r['value']:number_format((float)$r['value'],2,'.','')).' · '.(int)$r['records'].' registros';$stmt->close();return edu_chat_result($lines?"Resultado académico por grupo:\n".implode("\n",$lines):'No encontré información académica con esos filtros.');
    }
    $sql="SELECT * FROM ($base) uq$outerSql ORDER BY ".($evaluationsOnly?'bimestre DESC,course,evaluation':'student,course,evaluation')." LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc()){if($evaluationsOnly)$lines[]='• '.$r['course'].' — '.$r['evaluation'].' · '.$r['type'].' · bimestre '.$r['bimestre'].' · '.(int)$r['grade_records'].' notas · promedio '.($r['average_grade']===null?'sin datos':number_format((float)$r['average_grade'],2,'.',''));else{$g=function_exists('edu_chat_grade_label')?edu_chat_grade_label($r['grado']):$r['grado'];$lines[]='• '.$r['student'].' — '.$g.' '.$r['seccion'].' · '.$r['course'].' · '.$r['evaluation'].' · '.$r['competencia'].' · nota '.$r['grade'];}}$stmt->close();return edu_chat_result($lines?($evaluationsOnly?"Evaluaciones encontradas:\n":"Notas encontradas:\n").implode("\n",$lines):'No encontré información académica con esos filtros.');
}

function edu_chat_uq_billing_query(mysqli $conn,array $actor,array $plan): array {
    if((int)($actor['type']??0)!==1)return edu_chat_result('Esta consulta de facturación solo está disponible para administración.');
    if(!edu_chat_table_exists($conn,'comprobantes_electronicos'))return edu_chat_result('No está disponible la información de comprobantes electrónicos.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);[$start,$end,$label]=edu_chat_uq_period($conn,$actor,$filters);$where=['ce.school_id=?','ce.fecha_emision BETWEEN ? AND ?'];$types='iss';$params=[$school,$start,$end];
    if(!empty($filters['document_type'])){$where[]='ce.serie LIKE ?';$types.='s';$kind=strtolower(edu_chat_uq_clean_text($filters['document_type'],20));$params[]=$kind==='factura'?'F%':($kind==='boleta'?'B%':'%');}
    if(!empty($filters['name'])){$where[]='LOWER(ce.cliente_razon_social) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['name'],120).'%';}
    $base="SELECT ce.id,ce.numero_completo,ce.serie,ce.cliente_num_doc,ce.cliente_razon_social,ce.fecha_emision,ce.sunat_code,ce.sunat_description FROM comprobantes_electronicos ce WHERE ".implode(' AND ',$where);$operation=(string)($plan['operation']??'count');$limit=edu_chat_uq_int($plan['limit']??100,1,200,100);
    if($operation==='count'){$sql="SELECT COUNT(*) value FROM ($base) uq";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado '.$label.': '.(int)$r['value'].' comprobantes.');}
    if($operation==='group'){$group=(string)($plan['group_by']??'status');$expr=$group==='series'?'serie':($group==='date'?'fecha_emision':'COALESCE(NULLIF(sunat_code,\'\'),\'Sin respuesta SUNAT\')');$sql="SELECT $expr label,COUNT(*) value FROM ($base) uq GROUP BY $expr ORDER BY value DESC,label LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['label'].': '.(int)$r['value'];$stmt->close();return edu_chat_result($lines?"Comprobantes $label:\n".implode("\n",$lines):'No encontré comprobantes con esos filtros.');}
    $sql="SELECT * FROM ($base) uq ORDER BY fecha_emision DESC,id DESC LIMIT $limit";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['numero_completo'].' — '.$r['cliente_razon_social'].' · '.$r['fecha_emision'].' · SUNAT '.($r['sunat_code']?:'sin código');$stmt->close();return edu_chat_result($lines?"Comprobantes $label:\n".implode("\n",$lines):'No encontré comprobantes con esos filtros.');
}

function edu_chat_uq_user_query(mysqli $conn,array $actor,array $plan): array {
    if((int)($actor['type']??0)!==1)return edu_chat_result('La información de usuarios solo está disponible para administración.');
    if(!edu_chat_table_exists($conn,'users'))return edu_chat_result('No está disponible la información de usuarios.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);$where=['school_id=?'];$types='i';$params=[$school];
    if(!empty($filters['name'])){$where[]='LOWER(name) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['name'],120).'%';}
    if(isset($filters['role_type'])&&is_numeric($filters['role_type'])){$where[]='type=?';$types.='i';$params[]=(int)$filters['role_type'];}
    $base="SELECT name,type,COALESCE(is_director,0) is_director FROM users WHERE ".implode(' AND ',$where);
    $operation=(string)($plan['operation']??'list');$limit=edu_chat_uq_int($plan['limit']??100,1,200,100);
    if($operation==='count'){$stmt=$conn->prepare("SELECT COUNT(*) value FROM ($base) uq");edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado: '.(int)$r['value'].' usuarios.');}
    if($operation==='group'){
        $sql="SELECT type,COUNT(*) value FROM ($base) uq GROUP BY type ORDER BY type";$stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];
        while($r=$res->fetch_assoc()){$role=((int)$r['type']===1?'Administrador':((int)$r['type']===2?'Docente':((int)$r['type']===3?'Auxiliar':'Otro')));$lines[]='• '.$role.': '.(int)$r['value'];}
        $stmt->close();return edu_chat_result($lines?"Usuarios por perfil:\n".implode("\n",$lines):'No encontré usuarios.');
    }
    $stmt=$conn->prepare("SELECT * FROM ($base) uq ORDER BY type,name LIMIT $limit");edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];
    while($r=$res->fetch_assoc()){$role=((int)$r['type']===1?'Administrador':((int)$r['type']===2?'Docente':((int)$r['type']===3?'Auxiliar':'Otro')));$lines[]='• '.$r['name'].' — '.$role.((int)$r['is_director']===1?' · Director':'');}
    $stmt->close();return edu_chat_result($lines?"Usuarios encontrados:\n".implode("\n",$lines):'No encontré usuarios.');
}

function edu_chat_uq_concept_query(mysqli $conn,array $actor,array $plan): array {
    if((int)($actor['type']??0)!==1)return edu_chat_result('La información de conceptos de pago solo está disponible para administración.');
    if(!edu_chat_table_exists($conn,'courses'))return edu_chat_result('No está disponible la información de conceptos de pago.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);$where=['1=1'];$types='';$params=[];
    $join='';$yearExpr="'Sin año'";
    if(edu_chat_table_exists($conn,'academic_year')){$join=' LEFT JOIN academic_year ay ON ay.id=c.academic_year_id ';$where[]='ay.school_id=?';$types.='i';$params[]=$school;$yearExpr="COALESCE(ay.year,'Sin año')";}
    if(!empty($filters['year'])&&edu_chat_table_exists($conn,'academic_year')){$where[]='ay.year=?';$types.='s';$params[]=edu_chat_uq_clean_text($filters['year'],10);}
    if(!empty($filters['level'])){$where[]='LOWER(TRIM(c.level))=LOWER(TRIM(?))';$types.='s';$params[]=edu_chat_uq_clean_text($filters['level'],40);}
    if(!empty($filters['concept'])){$where[]='LOWER(c.course) LIKE LOWER(?)';$types.='s';$params[]='%'.edu_chat_uq_clean_text($filters['concept'],120).'%';}
    $base="SELECT c.id,c.course concept,c.level,c.grades,c.total_amount amount,$yearExpr academic_year FROM courses c $join WHERE ".implode(' AND ',$where);
    $operation=(string)($plan['operation']??'list');$limit=edu_chat_uq_int($plan['limit']??100,1,200,100);
    if($operation==='count'){$stmt=$conn->prepare("SELECT COUNT(*) value FROM ($base) uq");if(!$stmt)return edu_chat_result('No pude preparar la consulta de conceptos.');if($types!=='')edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado: '.(int)$r['value'].' conceptos de pago.');}
    if(in_array($operation,['sum','avg','min','max'],true)){$fn=strtoupper($operation);$stmt=$conn->prepare("SELECT $fn(amount) value FROM ($base) uq");if($types!=='')edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado: '.edu_chat_money((float)($r['value']??0)).'.');}
    if($operation==='group'){$group=(string)($plan['group_by']??'level');$expr=$group==='year'?'academic_year':'level';$stmt=$conn->prepare("SELECT $expr label,COUNT(*) value FROM ($base) uq GROUP BY $expr ORDER BY label");if($types!=='')edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['label'].': '.(int)$r['value'];$stmt->close();return edu_chat_result($lines?"Conceptos por grupo:\n".implode("\n",$lines):'No encontré conceptos.');}
    $stmt=$conn->prepare("SELECT * FROM ($base) uq ORDER BY academic_year DESC,concept,level LIMIT $limit");if($types!=='')edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['concept'].' — '.$r['level'].' · '.$r['grades'].' · '.$r['academic_year'].' · '.edu_chat_money((float)$r['amount']);$stmt->close();return edu_chat_result($lines?"Conceptos de pago:\n".implode("\n",$lines):'No encontré conceptos.');
}

function edu_chat_uq_school_query(mysqli $conn,array $actor,array $plan): array {
    if(!edu_chat_table_exists($conn,'schools'))return edu_chat_result('No está disponible la información institucional.');
    $school=(int)($actor['school_id']??0);if($school<=0)return edu_chat_result('No pude identificar la institución autenticada.');
    $stmt=$conn->prepare('SELECT name,contact_number,email,address FROM schools WHERE id=? LIMIT 1');
    if(!$stmt)return edu_chat_result('No pude consultar la información institucional.');
    $stmt->bind_param('i',$school);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row)return edu_chat_result('No encontré la información de la institución.');

    $lines=[];
    $lines[]='Institución: '.trim((string)$row['name']);
    if(trim((string)$row['address'])!=='')$lines[]='Dirección: '.trim((string)$row['address']);
    if(trim((string)$row['contact_number'])!=='')$lines[]='Contacto: '.trim((string)$row['contact_number']);
    if(trim((string)$row['email'])!=='')$lines[]='Correo: '.trim((string)$row['email']);

    if((int)($actor['type']??0)===1&&edu_chat_table_exists($conn,'company_config')){
        $sql='SELECT ruc,razon_social,nombre_comercial,direccion,provincia,departamento,distrito,telefono,email,website FROM company_config WHERE school_id=? AND COALESCE(is_active,1)=1 ORDER BY id DESC LIMIT 1';
        $s=$conn->prepare($sql);
        if($s){$s->bind_param('i',$school);$s->execute();$cfg=$s->get_result()->fetch_assoc();$s->close();
            if($cfg){
                if(trim((string)$cfg['ruc'])!=='')$lines[]='RUC: '.trim((string)$cfg['ruc']);
                if(trim((string)$cfg['razon_social'])!=='')$lines[]='Razón social: '.trim((string)$cfg['razon_social']);
                if(trim((string)$cfg['nombre_comercial'])!=='')$lines[]='Nombre comercial: '.trim((string)$cfg['nombre_comercial']);
                $place=trim(implode(', ',array_filter([trim((string)$cfg['distrito']),trim((string)$cfg['provincia']),trim((string)$cfg['departamento'])])));
                if($place!=='')$lines[]='Ubicación fiscal: '.$place;
                if(trim((string)$cfg['website'])!=='')$lines[]='Web: '.trim((string)$cfg['website']);
            }
        }
    }
    return edu_chat_result(implode("\n",$lines));
}

function edu_chat_uq_config_query(mysqli $conn,array $actor,array $plan): array {
    if((int)($actor['type']??0)!==1)return edu_chat_result('La configuración del sistema solo está disponible para administración.');
    $school=(int)($actor['school_id']??0);$filters=(array)($plan['filters']??[]);$resource=strtolower(edu_chat_uq_clean_text($filters['resource']??'',40));
    $parts=[];

    if($resource===''||$resource==='payment_methods'){
        if(edu_chat_table_exists($conn,'payment_methods')){
            $q=$conn->query('SELECT name,description FROM payment_methods ORDER BY name');
            $lines=[];while($q&&($r=$q->fetch_assoc()))$lines[]='• '.$r['name'].(trim((string)$r['description'])!==''?' — '.$r['description']:'');
            if($lines)$parts[]="Medios de pago configurados:\n".implode("\n",$lines);
        }
    }

    if($resource===''||$resource==='attendance_rules'){
        if(edu_chat_table_exists($conn,'attendance_rules')){
            $q=$conn->query("SELECT day_name_es,early_time,late_time,is_active FROM attendance_rules ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),id");
            $lines=[];while($q&&($r=$q->fetch_assoc()))$lines[]='• '.$r['day_name_es'].' — temprano '.$r['early_time'].' · tardanza '.$r['late_time'].' · '.((int)$r['is_active']===1?'activo':'inactivo');
            if($lines)$parts[]="Reglas de asistencia:\n".implode("\n",$lines);
        }
    }

    if($resource===''||$resource==='bimester_locks'){
        if(edu_chat_table_exists($conn,'bimester_locks')){
            $year=edu_chat_uq_active_year_id($conn,$school);$where='school_id=?';$types='i';$params=[$school];
            if($year>0){$where.=' AND academic_year_id=?';$types.='i';$params[]=$year;}
            $stmt=$conn->prepare("SELECT bimester,is_locked FROM bimester_locks WHERE $where ORDER BY bimester");
            if($stmt){edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• Bimestre '.(int)$r['bimester'].' — '.((int)$r['is_locked']===1?'bloqueado':'abierto');$stmt->close();if($lines)$parts[]="Estado de bimestres:\n".implode("\n",$lines);}
        }
    }

    if(!$parts)return edu_chat_result('No encontré configuración consultable para ese recurso.');
    return edu_chat_result(implode("\n\n",$parts));
}

function edu_chat_uq_year_query(mysqli $conn,array $actor,array $plan): array {
    if(!edu_chat_table_exists($conn,'academic_year'))return edu_chat_result('No está disponible la información de años académicos.');
    $school=(int)($actor['school_id']??0);$where='school_id=?';$types='i';$params=[$school];$operation=(string)($plan['operation']??'list');
    if($operation==='count'){$stmt=$conn->prepare("SELECT COUNT(*) value FROM academic_year WHERE $where");edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return edu_chat_result('Resultado: '.(int)$r['value'].' años académicos.');}
    $stmt=$conn->prepare("SELECT year,start_date,end_date,is_active FROM academic_year WHERE $where ORDER BY year DESC");edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$lines=[];while($r=$res->fetch_assoc())$lines[]='• '.$r['year'].' — '.$r['start_date'].' a '.$r['end_date'].' · '.((int)$r['is_active']===1?'activo':'inactivo');$stmt->close();return edu_chat_result($lines?"Años académicos:\n".implode("\n",$lines):'No encontré años académicos.');
}

function edu_chat_universal_query(mysqli $conn,array $actor,array $plan): array {
    $domain=strtolower(edu_chat_uq_clean_text($plan['domain']??'',40));
    $operation=strtolower(edu_chat_uq_clean_text($plan['operation']??'list',20));
    if(!in_array($domain,edu_chat_uq_allowed_domains($actor),true))return edu_chat_result('Ese dominio de información no está autorizado para tu perfil.');
    if(!in_array($operation,edu_chat_uq_allowed_operations(),true))$operation='list';
    $plan['domain']=$domain;$plan['operation']=$operation;
    switch($domain){
        case 'school':return edu_chat_uq_school_query($conn,$actor,$plan);
        case 'config':return edu_chat_uq_config_query($conn,$actor,$plan);
        case 'users':return edu_chat_uq_user_query($conn,$actor,$plan);
        case 'concepts':return edu_chat_uq_concept_query($conn,$actor,$plan);
        case 'students':return edu_chat_uq_student_query($conn,$actor,$plan);
        case 'teachers':return edu_chat_uq_teacher_query($conn,$actor,$plan);
        case 'courses':return edu_chat_uq_assignment_query($conn,$actor,$plan,true);
        case 'assignments':return edu_chat_uq_assignment_query($conn,$actor,$plan,false);
        case 'attendance':return edu_chat_uq_attendance_query($conn,$actor,$plan);
        case 'payments':return edu_chat_uq_payment_query($conn,$actor,$plan);
        case 'debts':return edu_chat_uq_debt_query($conn,$actor,$plan);
        case 'grades':return edu_chat_uq_grade_query($conn,$actor,$plan,false);
        case 'evaluations':return edu_chat_uq_grade_query($conn,$actor,$plan,true);
        case 'billing':return edu_chat_uq_billing_query($conn,$actor,$plan);
        case 'academic_years':return edu_chat_uq_year_query($conn,$actor,$plan);
    }
    return edu_chat_result('No pude interpretar el dominio solicitado.');
}
