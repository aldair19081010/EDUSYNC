<?php

/**
 * Capa determinista avanzada del Asistente EduSync.
 * Mantiene contexto conversacional estructurado y resuelve consultas sensibles
 * con PHP/MySQL de solo lectura antes de delegar redacción a un modelo.
 */

function edu_chat_context_is_followup_message(string $text): bool {
    $n = edu_chat_normalize($text);
    if ($n === '') return false;
    $words = preg_split('/\s+/', $n);
    if (count($words) <= 9 && edu_chat_has($n, ['ahora','solo','solamente','de esos','de esas','ellos','ellas','ordena','ordenalos','ordenalas','y en','y ahora','este mes','hoy','seccion','grado'])) return true;
    return edu_chat_has($n, ['de esos','de esas','los mismos','las mismas','eso mismo','ahora solo','solo los','solo las','ordenalos','ordenalas']);
}

function edu_chat_context_topic_from_text(string $text): ?string {
    $n = edu_chat_normalize($text);
    if (edu_chat_has($n, ['deuda','deudas','moroso','morosos','morosidad','pension','pensiones','saldo','cobrado','recaudado','pago','pagos','yape','efectivo','transferencia'])) return 'finanzas';
    if (edu_chat_has($n, ['asistencia','tardanza','tardanzas','ausencia','ausencias','falta','faltas','falto','faltaron','entrada','presente','presentes'])) return 'asistencia';
    if (edu_chat_has($n, ['riesgo','nota','notas','calificacion','calificaciones','desaprobado','desaprobados','reprobado','reprobados','competencia','competencias','curso','cursos','area','areas','bimestre'])) return 'academico';
    if (edu_chat_has($n, ['estudiante','estudiantes','alumno','alumnos','ficha 360','perfil 360'])) return 'estudiantes';
    return null;
}

function edu_chat_context_topic_from_result(array $result): ?string {
    foreach ((array)($result['tools_used'] ?? []) as $tool) {
        $tool = strtolower((string)$tool);
        if (strpos($tool,'debt') !== false || strpos($tool,'collection') !== false || strpos($tool,'payment') !== false || strpos($tool,'finance') !== false) return 'finanzas';
        if (strpos($tool,'attendance') !== false) return 'asistencia';
        if (strpos($tool,'academic') !== false || strpos($tool,'grade') !== false || strpos($tool,'risk') !== false) return 'academico';
        if (strpos($tool,'student') !== false) return 'estudiantes';
    }
    return null;
}

function edu_chat_context_explicit_filters(string $message): array {
    $n = edu_chat_normalize($message);
    $out = [];
    $level = function_exists('edu_chat_extract_level') ? edu_chat_extract_level($n) : null;
    $grade = function_exists('edu_chat_router_explicit_grade') ? edu_chat_router_explicit_grade($n) : null;
    $section = function_exists('edu_chat_router_explicit_section') ? edu_chat_router_explicit_section($n) : null;
    if (!$section && function_exists('edu_chat_extract_section')) $section = edu_chat_extract_section($n);
    $period = function_exists('edu_chat_extract_period') ? edu_chat_extract_period($n) : null;
    if ($level) $out['level'] = $level;
    if ($grade) $out['grade'] = $grade;
    if ($section) $out['section'] = strtoupper((string)$section);
    if ($period) $out['period'] = $period;
    if (preg_match('/\b(?:primer|1er|1ro)\s*bimestre\b/',$n)) $out['bimestre']='1';
    elseif (preg_match('/\b(?:segundo|2do)\s*bimestre\b/',$n)) $out['bimestre']='2';
    elseif (preg_match('/\b(?:tercer|tercero|3er|3ro)\s*bimestre\b/',$n)) $out['bimestre']='3';
    elseif (preg_match('/\b(?:cuarto|4to)\s*bimestre\b/',$n)) $out['bimestre']='4';
    return $out;
}

function edu_chat_contextualize_message(string $message, array $state): string {
    if (!$state || empty($state['topic']) || !edu_chat_context_is_followup_message($message)) return $message;
    if (!empty($state['updated_at']) && time() - (int)$state['updated_at'] > 1800) return $message;

    $explicit = edu_chat_context_explicit_filters($message);
    $context = ['tema='.(string)$state['topic']];
    foreach (['level'=>'nivel','grade'=>'grado','section'=>'sección','period'=>'periodo','bimestre'=>'bimestre'] as $key=>$label) {
        if (isset($explicit[$key])) continue;
        if (!empty($state[$key])) $context[] = $label.'='.(string)$state[$key];
    }
    if (!empty($state['root_query'])) $context[] = 'consulta base="'.mb_substr((string)$state['root_query'],0,260,'UTF-8').'"';
    return $message . '. Contexto vigente de la conversación: ' . implode(', ', $context) . '.';
}

function edu_chat_context_update_state(string $originalMessage, array $result, array $previous = []): array {
    $followup = edu_chat_context_is_followup_message($originalMessage);
    $topic = edu_chat_context_topic_from_result($result) ?: edu_chat_context_topic_from_text($originalMessage) ?: ($previous['topic'] ?? null);
    if (!$topic) return $previous;

    $state = $previous;
    if (!$followup && !empty($previous['topic']) && $previous['topic'] !== $topic) $state = [];
    $state['topic'] = $topic;
    $explicit = edu_chat_context_explicit_filters($originalMessage);
    foreach ($explicit as $key=>$value) $state[$key] = $value;
    if (!$followup || empty($state['root_query']) || ($previous['topic'] ?? null) !== $topic) $state['root_query'] = $originalMessage;
    if (edu_chat_has(edu_chat_normalize($originalMessage), ['sin filtro','todos los niveles','todos los grados','todas las secciones','todo el colegio'])) {
        unset($state['level'],$state['grade'],$state['section']);
    }
    $state['updated_at'] = time();
    return $state;
}

function edu_chat_adv_entities(string $text, array $state): array {
    $explicit = edu_chat_context_explicit_filters($text);
    $entities = [];
    foreach (['level','grade','section','period','bimestre'] as $key) {
        if (isset($explicit[$key])) $entities[$key] = $explicit[$key];
        elseif (!empty($state[$key])) $entities[$key] = $state[$key];
        else $entities[$key] = null;
    }
    return $entities;
}

function edu_chat_adv_money_range(string $text): ?array {
    $n = edu_chat_normalize($text);
    $money = '(?:s\/?\.?\s*)?([0-9]+(?:[.,][0-9]{1,2})?)';
    if (preg_match('/\bentre\s+'.$money.'\s+(?:y|a)\s+'.$money.'/',$n,$m)) {
        $a=(float)str_replace(',','.',$m[1]); $b=(float)str_replace(',','.',$m[2]);
        return ['min'=>min($a,$b),'max'=>max($a,$b)];
    }
    if (preg_match('/\b(?:mas de|mayor a|superior a|por encima de)\s+'.$money.'/',$n,$m)) return ['min'=>(float)str_replace(',','.',$m[1]),'max'=>null];
    if (preg_match('/\b(?:al menos|minimo|como minimo)\s+'.$money.'/',$n,$m)) return ['min'=>(float)str_replace(',','.',$m[1]),'max'=>null];
    if (preg_match('/\b(?:menos de|menor a|inferior a|hasta)\s+'.$money.'/',$n,$m)) return ['min'=>0.01,'max'=>(float)str_replace(',','.',$m[1])];
    return null;
}

function edu_chat_adv_due_column(mysqli $conn): ?string {
    foreach (['due_date','fecha_vencimiento','due_on','payment_due_date'] as $column) {
        if (edu_chat_column_exists($conn,'student_ef_list',$column)) return $column;
    }
    return null;
}

function edu_chat_adv_finance_by_amount_result(mysqli $conn,array $actor,array $entities,float $minDebt=0.01,?float $maxDebt=null,bool $overdueOnly=false,int $limit=100): array {
    if ((int)($actor['type']??0)!==1) return edu_chat_result('Esta consulta financiera solo está disponible para administración.');
    if (!debt_engine_available($conn)) return edu_chat_result('El módulo financiero no está disponible.');
    $school=(int)($actor['school_id']??0); if($school<=0)return edu_chat_result('No pude identificar el colegio autenticado.');
    $limit=max(1,min(200,$limit)); $minDebt=max(0.01,$minDebt); if($maxDebt!==null)$maxDebt=max($minDebt,$maxDebt);
    $hasDebtStatus=debt_engine_column_exists($conn,'student_ef_list','debt_status');
    $hasPaymentStatus=debt_engine_column_exists($conn,'payments','payment_status');
    $join="LEFT JOIN payments p ON p.ef_id=ef.id".($hasPaymentStatus?" AND COALESCE(p.payment_status,'Confirmado')='Confirmado'":'');
    $where=['s.school_id=?',"LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];$types='i';$params=[$school];
    if($hasDebtStatus)$where[]="LOWER(TRIM(COALESCE(ef.debt_status,'Activa')))='activa'";
    edu_chat_analytics_apply_student_filters($entities,'s',$where,$types,$params);
    $due=edu_chat_adv_due_column($conn);$overdueExpr='0';
    if($due!==null)$overdueExpr="CASE WHEN ef.`$due` IS NOT NULL AND ef.`$due`<CURDATE() THEN 1 ELSE 0 END";
    if($overdueOnly&&$due===null)return edu_chat_result('La base actual no tiene una fecha de vencimiento compatible para identificar deuda vencida de forma segura.');
    $inner="SELECT s.id student_id,s.name,s.nivel,s.grado,s.seccion,ef.id fee_id,GREATEST(COALESCE(ef.discounted_amount,ef.total_fee)-COALESCE(SUM(p.amount),0),0) balance,$overdueExpr overdue FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id $join WHERE ".implode(' AND ',$where)." GROUP BY s.id,s.name,s.nivel,s.grado,s.seccion,ef.id,ef.discounted_amount,ef.total_fee".($due!==null?",ef.`$due`":'')." HAVING balance>0.009";
    $having=['SUM(x.balance)>=?'];$types.='d';$params[]=$minDebt;
    if($maxDebt!==null){$having[]='SUM(x.balance)<=?';$types.='d';$params[]=$maxDebt;}
    if($overdueOnly)$having[]='SUM(x.overdue)>0';
    $sql="SELECT x.student_id,x.name,x.nivel,x.grado,COALESCE(NULLIF(TRIM(x.seccion),''),'Sin sección') seccion,COUNT(*) obligations,SUM(x.overdue) overdue_obligations,SUM(x.balance) total_debt FROM ($inner) x GROUP BY x.student_id,x.name,x.nivel,x.grado,x.seccion HAVING ".implode(' AND ',$having)." ORDER BY total_debt DESC,obligations DESC,x.name ASC LIMIT ".($limit+1);
    $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar la consulta financiera solicitada.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($row=$res->fetch_assoc())$rows[]=$row;$stmt->close();
    $truncated=count($rows)>$limit;if($truncated)$rows=array_slice($rows,0,$limit);if(!$rows)return edu_chat_result('No encontré estudiantes que cumplan ese filtro financiero.');
    $lines=[];$sum=0.0;foreach($rows as $i=>$r){$sum+=(float)$r['total_debt'];$loc=$r['nivel'].' · '.$r['grado'].'° '.$r['seccion'];$extra=$overdueOnly?' · '.(int)$r['overdue_obligations'].' vencida'.((int)$r['overdue_obligations']===1?'':'s'):'';$lines[]=($i+1).'. '.$r['name'].' — '.edu_chat_money((float)$r['total_debt']).' · '.(int)$r['obligations'].' deuda'.((int)$r['obligations']===1?'':'s').$extra.' · '.$loc;}
    $msg='Estudiantes que cumplen el filtro financiero:'."\n".implode("\n",$lines);if($truncated)$msg.="\nMostrando los primeros $limit resultados.";
    $result=edu_chat_result($msg,['Ahora solo secundaria','Ordénalos por deuda','Muéstrame los vencidos'],[['label'=>'Estudiantes','value'=>(string)count($rows),'tone'=>'warning'],['label'=>'Saldo listado','value'=>edu_chat_money($sum),'tone'=>'danger']],[edu_chat_action('Ver Reporte de Deudas','debt_reports','fa-exclamation-circle')]);$result['tools_used']=['advanced_finance_amount'];return $result;
}

function edu_chat_adv_collection_method_result(mysqli $conn,array $actor,array $entities,string $method): array {
    if((int)($actor['type']??0)!==1)return edu_chat_result('Esta consulta solo está disponible para administración.');
    if(!edu_chat_table_exists($conn,'payments')||!edu_chat_table_exists($conn,'student_ef_list'))return edu_chat_result('El historial de pagos no está disponible.');
    $methodColumn=null;foreach(['payment_method','method','payment_type'] as $c)if(edu_chat_column_exists($conn,'payments',$c)){$methodColumn=$c;break;}
    if($methodColumn===null)return edu_chat_result('La base actual no tiene un campo compatible para filtrar pagos por método.');
    $period=(string)($entities['period']??'month');[$start,$end,$label]=edu_chat_analytics_period($conn,(int)$actor['school_id'],$period);
    $dateColumn=edu_chat_column_exists($conn,'payments','date_created')?'date_created':null;if($dateColumn===null)return edu_chat_result('No pude identificar la fecha de los pagos.');
    $where=['s.school_id=?',"DATE(p.$dateColumn) BETWEEN ? AND ?","LOWER(TRIM(p.`$methodColumn`))=LOWER(TRIM(?))"];$types='isss';$params=[(int)$actor['school_id'],$start,$end,$method];
    if(edu_chat_column_exists($conn,'payments','payment_status'))$where[]="COALESCE(p.payment_status,'Confirmado')='Confirmado'";
    edu_chat_analytics_apply_student_filters($entities,'s',$where,$types,$params);
    $sql="SELECT COUNT(DISTINCT p.id) operations,COUNT(DISTINCT s.id) students,COALESCE(SUM(p.amount),0) total FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id INNER JOIN student s ON s.id=ef.student_id WHERE ".implode(' AND ',$where);
    $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar la consulta de pagos.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$r=$stmt->get_result()->fetch_assoc()?:[];$stmt->close();
    $result=edu_chat_result('Cobranza por '.$method.' '.$label.': '.edu_chat_money((float)($r['total']??0)).' en '.(int)($r['operations']??0).' pago'.((int)($r['operations']??0)===1?'':'s').' de '.(int)($r['students']??0).' estudiante'.((int)($r['students']??0)===1?'':'s').'.',[],[['label'=>'Recaudado','value'=>edu_chat_money((float)($r['total']??0)),'tone'=>'success'],['label'=>'Pagos','value'=>(string)(int)($r['operations']??0),'tone'=>'primary']],[edu_chat_action('Ver Pagos','payments','fa-credit-card')]);$result['tools_used']=['advanced_collections_method'];return $result;
}

function edu_chat_adv_attendance_rate_result(mysqli $conn,array $actor,array $entities,float $maxRate,int $limit=100): array {
    if(!in_array((int)($actor['type']??0),[1,3],true))return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    if(!edu_chat_table_exists($conn,'asistencia'))return edu_chat_result('El módulo de asistencia no está disponible.');
    $maxRate=max(0,min(100,$maxRate));$limit=max(1,min(200,$limit));$period=(string)($entities['period']??'month');[$start,$end,$label]=edu_chat_analytics_period($conn,(int)$actor['school_id'],$period);
    $cancel=edu_chat_column_exists($conn,'asistencia','is_cancelled')?' AND COALESCE(a.is_cancelled,0)=0':'';
    $daily="SELECT a.student_id,a.fecha,CASE WHEN SUM(LOWER(TRIM(a.estado))='tarde')>0 THEN 'Tarde' WHEN SUM(LOWER(TRIM(a.estado)) IN ('presente','normal','temprano'))>0 THEN 'Presente' WHEN SUM(LOWER(TRIM(a.estado))='permiso')>0 THEN 'Permiso' WHEN SUM(LOWER(TRIM(a.estado)) IN ('ausente justificada','ausencia justificada'))>0 THEN 'Ausente Justificada' ELSE 'Ausente' END estado FROM asistencia a WHERE a.tipo='Entrada' AND a.fecha BETWEEN ? AND ? $cancel GROUP BY a.student_id,a.fecha";
    $where=['s.school_id=?',"LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];$types='ssi';$params=[$start,$end,(int)$actor['school_id']];edu_chat_analytics_apply_student_filters($entities,'s',$where,$types,$params);$types.='d';$params[]=$maxRate;
    $sql="SELECT s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion,COUNT(*) records,SUM(d.estado='Tarde') late,SUM(d.estado='Ausente') absent,ROUND((SUM(d.estado IN ('Presente','Tarde'))/COUNT(*))*100,1) rate FROM ($daily) d INNER JOIN student s ON s.id=d.student_id WHERE ".implode(' AND ',$where)." GROUP BY s.id,s.name,s.nivel,s.grado,s.seccion HAVING rate<? ORDER BY rate ASC,absent DESC,late DESC,s.name ASC LIMIT $limit";
    $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar la consulta de asistencia.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();if(!$rows)return edu_chat_result('No encontré estudiantes con asistencia menor a '.number_format($maxRate,1).'% para '.$label.'.');
    $lines=[];foreach($rows as $i=>$r)$lines[]=($i+1).'. '.$r['name'].' — '.number_format((float)$r['rate'],1).'% · '.(int)$r['absent'].' ausencias · '.(int)$r['late'].' tardanzas · '.$r['nivel'].' · '.$r['grado'].'° '.$r['seccion'];
    $result=edu_chat_result('Estudiantes con asistencia menor a '.number_format($maxRate,1).'% (calculada sobre días con registro de Entrada, '.$label."):\n".implode("\n",$lines),[],[['label'=>'Estudiantes','value'=>(string)count($rows),'tone'=>'warning']],[edu_chat_action('Ver Reporte de Asistencia','attendance_report','fa-clipboard-list')]);$result['tools_used']=['advanced_attendance_rate'];return $result;
}

function edu_chat_adv_missing_entry_result(mysqli $conn,array $actor,array $entities,int $limit=100): array {
    if(!in_array((int)($actor['type']??0),[1,3],true))return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    if(!edu_chat_table_exists($conn,'asistencia'))return edu_chat_result('El módulo de asistencia no está disponible.');
    $limit=max(1,min(200,$limit));$where=['s.school_id=?',"LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];$types='i';$params=[(int)$actor['school_id']];edu_chat_analytics_apply_student_filters($entities,'s',$where,$types,$params);
    $cancel=edu_chat_column_exists($conn,'asistencia','is_cancelled')?' AND COALESCE(a.is_cancelled,0)=0':'';
    $where[]="NOT EXISTS (SELECT 1 FROM asistencia a WHERE a.student_id=s.id AND a.tipo='Entrada' AND a.fecha=CURDATE() $cancel)";
    $sql="SELECT s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion FROM student s WHERE ".implode(' AND ',$where)." ORDER BY FIELD(s.nivel,'Inicial','Primaria','Secundaria'),CAST(s.grado AS UNSIGNED),s.grado,seccion,s.name LIMIT $limit";
    $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar la consulta de asistencia.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();if(!$rows)return edu_chat_result('Todos los estudiantes activos con esos filtros tienen al menos un registro de Entrada hoy.');
    $lines=[];foreach($rows as $i=>$r)$lines[]=($i+1).'. '.$r['name'].' — '.$r['nivel'].' · '.$r['grado'].'° '.$r['seccion'];
    $result=edu_chat_result("Estudiantes sin registro de Entrada hoy:\n".implode("\n",$lines)."\nNota: ausencia de registro no equivale automáticamente a falta; puede significar que la asistencia aún no fue marcada.",[],[['label'=>'Sin entrada hoy','value'=>(string)count($rows),'tone'=>'warning']],[edu_chat_action('Ver Reporte de Asistencia','attendance_report','fa-clipboard-list')]);$result['tools_used']=['advanced_missing_entry'];return $result;
}

function edu_chat_adv_academic_courses_result(mysqli $conn,array $actor,array $entities,int $minCourses=1,int $limit=100): array {
    $type=(int)($actor['type']??0);if(!in_array($type,[1,2],true))return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    foreach(['evaluation_grades','evaluations','teacher_courses','student'] as $t)if(!edu_chat_table_exists($conn,$t))return edu_chat_result('No está disponible la información académica necesaria.');
    $school=(int)$actor['school_id'];$year=edu_chat_active_year($conn,$school);$yearId=(int)($year['id']??0);$minCourses=max(1,min(30,$minCourses));$limit=max(1,min(200,$limit));
    $where=['s.school_id=?',"LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')","((eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(eg.grade AS DECIMAL(10,2))<=10) OR UPPER(TRIM(eg.grade))='C')"];$types='i';$params=[$school];
    if($yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($type===2){$teacher=(int)($actor['teacher_id']??0);if($teacher<=0)return edu_chat_result('No pude identificar tu ficha docente.');$where[]='tc.teacher_id=?';$types.='i';$params[]=$teacher;}
    edu_chat_analytics_apply_student_filters($entities,'s',$where,$types,$params);
    if(!empty($entities['bimestre'])&&edu_chat_column_exists($conn,'evaluations','bimestre')){$where[]='e.bimestre=?';$types.='s';$params[]=(string)$entities['bimestre'];}
    if(!empty($entities['course'])){$where[]='LOWER(TRIM(ac.name))=LOWER(TRIM(?))';$types.='s';$params[]=(string)$entities['course'];}
    $types.='i';$params[]=$minCourses;
    $sql="SELECT s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion,COUNT(*) critical_records,COUNT(DISTINCT ac.id) critical_courses,GROUP_CONCAT(DISTINCT ac.name ORDER BY ac.name SEPARATOR ', ') courses FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id INNER JOIN evaluations e ON e.id=eg.evaluation_id LEFT JOIN teacher_courses tc ON tc.id=e.teacher_course_id LEFT JOIN academic_courses ac ON ac.id=tc.course_id WHERE ".implode(' AND ',$where)." GROUP BY s.id,s.name,s.nivel,s.grado,s.seccion HAVING COUNT(DISTINCT ac.id)>=? ORDER BY critical_courses DESC,critical_records DESC,s.name ASC LIMIT $limit";
    $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar la consulta académica.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();if(!$rows)return edu_chat_result('No encontré estudiantes que cumplan ese filtro académico.');
    $lines=[];foreach($rows as $i=>$r)$lines[]=($i+1).'. '.$r['name'].' — '.(int)$r['critical_courses'].' curso'.((int)$r['critical_courses']===1?'':'s').' con riesgo · '.(int)$r['critical_records'].' registros críticos · '.$r['nivel'].' · '.$r['grado'].'° '.$r['seccion'].(!empty($r['courses'])?' · '.$r['courses']:'');
    $result=edu_chat_result("Riesgo académico profundo:\n".implode("\n",$lines),[],[['label'=>'Estudiantes','value'=>(string)count($rows),'tone'=>'danger']],[edu_chat_action('Ver Reporte de Notas','grades_report','fa-chart-bar')]);$result['tools_used']=['advanced_academic_courses'];return $result;
}

function edu_chat_advanced_try(mysqli $conn,array $actor,string $message,array $state=[]): ?array {
    $n=edu_chat_normalize($message);$type=(int)($actor['type']??0);$entities=edu_chat_adv_entities($n,$state);

    if($type===1&&edu_chat_has($n,['pago','pagos','cobrado','recaudado','recaudacion'])){
        foreach(['yape'=>'Yape','efectivo'=>'Efectivo','transferencia'=>'Transferencia'] as $needle=>$method)if(strpos($n,$needle)!==false)return edu_chat_adv_collection_method_result($conn,$actor,$entities,$method);
    }

    if($type===1&&edu_chat_has($n,['deuda','deudas','moroso','morosos','saldo','deben'])){
        $range=edu_chat_adv_money_range($n);$overdue=edu_chat_has($n,['vencida','vencidas','vencido','vencidos']);
        if($range!==null||$overdue){$min=$range['min']??0.01;$max=$range['max']??null;return edu_chat_adv_finance_by_amount_result($conn,$actor,$entities,(float)$min,$max!==null?(float)$max:null,$overdue,100);}
    }

    if(in_array($type,[1,3],true)&&edu_chat_has($n,['asistencia','asistencias','porcentaje','entrada'])){
        if(preg_match('/\b(?:asistencia\s+)?(?:menor|inferior|debajo)\s+(?:a|del)?\s*([0-9]{1,3}(?:[.,][0-9]+)?)\s*%?/',$n,$m))return edu_chat_adv_attendance_rate_result($conn,$actor,$entities,(float)str_replace(',','.',$m[1]),100);
        if(edu_chat_has($n,['sin entrada','sin registrar entrada','sin registro de entrada','no registraron entrada','no tiene entrada','no tienen entrada']))return edu_chat_adv_missing_entry_result($conn,$actor,$entities,100);
    }

    if(in_array($type,[1,2],true)&&edu_chat_has($n,['riesgo','desaprobado','desaprobados','reprobado','reprobados','con c','nota critica','notas criticas','curso','cursos'])){
        $course=function_exists('edu_chat_find_course')?edu_chat_find_course($conn,(int)$actor['school_id'],$n):null;if($course)$entities['course']=$course;
        $minCourses=null;
        $num='(?:[0-9]{1,2}|un|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)';
        if(preg_match('/\b(?:mas de|al menos|minimo|con)\s+('.$num.')\s+(?:cursos|areas)\b/',$n,$m)){$v=function_exists('edu_chat_router_number_value')?edu_chat_router_number_value($m[1]):null;if($v!==null)$minCourses=edu_chat_has($n,['mas de'])?$v+1:$v;}
        if($minCourses!==null||$course!==null||edu_chat_has($n,['varios cursos','dos o mas cursos','multiples cursos']))return edu_chat_adv_academic_courses_result($conn,$actor,$entities,$minCourses??2,100);
    }
    return null;
}
