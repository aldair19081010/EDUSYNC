<?php

/**
 * Capacidades nominales de lectura para el asistente EduSync.
 * El alcance del colegio y los permisos siempre salen de la sesión autenticada.
 */

function edu_chat_insights_student_matches(mysqli $conn, array $actor, array $entities, string $nameSearch, int $limit = 8): array {
    $schoolId = (int)($actor['school_id'] ?? 0);
    $nameSearch = trim($nameSearch);
    if ($schoolId <= 0 || $nameSearch === '') return [];

    $where = [
        's.school_id=?',
        "LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')",
        'LOWER(s.name) LIKE LOWER(?)'
    ];
    $types = 'is';
    $params = [$schoolId, '%' . $nameSearch . '%'];
    edu_chat_analytics_apply_student_filters($entities, 's', $where, $types, $params);
    $limit = max(1, min(12, $limit));

    $sql = "SELECT s.id,s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion
            FROM student s
            WHERE " . implode(' AND ', $where) . "
            ORDER BY CASE WHEN LOWER(TRIM(s.name))=LOWER(TRIM(?)) THEN 0 ELSE 1 END,
                     FIELD(s.nivel,'Inicial','Primaria','Secundaria'),CAST(s.grado AS UNSIGNED),s.grado,seccion,s.name
            LIMIT $limit";
    $types .= 's';
    $params[] = $nameSearch;
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    edu_chat_bind($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function edu_chat_insights_payment_data(mysqli $conn, int $studentId): array {
    $out = ['operations'=>0,'total_paid'=>0.0,'last_date'=>null,'last_amount'=>0.0,'last_receipt'=>null];
    if (!edu_chat_table_exists($conn,'payments') || !edu_chat_table_exists($conn,'student_ef_list')) return $out;

    $hasStatus = edu_chat_column_exists($conn,'payments','payment_status');
    $hasOperationId = edu_chat_column_exists($conn,'payments','operation_id');
    $hasOperations = $hasOperationId && edu_chat_table_exists($conn,'payment_operations');
    $paymentFilter = $hasStatus ? " AND COALESCE(p.payment_status,'Confirmado')='Confirmado'" : '';
    $join = '';
    $validOperation = '';
    $dateExpr = 'p.date_created';
    $receiptExpr = "COALESCE(NULLIF(p.receipt_no,''),CONCAT('PAGO-',p.id))";
    $groupKey = "CONCAT('P',p.id)";

    if ($hasOperations) {
        $join = ' LEFT JOIN payment_operations po ON po.id=p.operation_id';
        $statusFilter = edu_chat_column_exists($conn,'payment_operations','status') ? " AND (po.status IS NULL OR (LOWER(po.status) NOT LIKE '%anul%' AND LOWER(po.status) NOT LIKE '%cancel%' AND LOWER(po.status) NOT LIKE '%correg%'))" : '';
        $correctionFilter = edu_chat_column_exists($conn,'payment_operations','corrected_by_id') ? ' AND po.corrected_by_id IS NULL' : '';
        $validOperation = " AND (p.operation_id IS NULL OR (po.id IS NOT NULL$statusFilter$correctionFilter))";
        if (edu_chat_column_exists($conn,'payment_operations','payment_date')) $dateExpr = 'COALESCE(po.payment_date,p.date_created)';
        if (edu_chat_column_exists($conn,'payment_operations','receipt_full')) $receiptExpr = "COALESCE(NULLIF(po.receipt_full,''),NULLIF(p.receipt_no,''),CONCAT('PAGO-',p.id))";
        $groupKey = "CASE WHEN p.operation_id IS NULL THEN CONCAT('P',p.id) ELSE CONCAT('O',p.operation_id) END";
    }

    $sql = "SELECT COUNT(DISTINCT $groupKey) operations,COALESCE(SUM(p.amount),0) total_paid,MAX($dateExpr) last_date
            FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id $join
            WHERE ef.student_id=? $paymentFilter $validOperation";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i',$studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $out['operations'] = (int)($row['operations'] ?? 0);
        $out['total_paid'] = (float)($row['total_paid'] ?? 0);
        $out['last_date'] = $row['last_date'] ?? null;
    }

    $sql = "SELECT $groupKey payment_key,$receiptExpr receipt,$dateExpr payment_date,SUM(p.amount) amount
            FROM payments p INNER JOIN student_ef_list ef ON ef.id=p.ef_id $join
            WHERE ef.student_id=? $paymentFilter $validOperation
            GROUP BY payment_key,receipt,payment_date ORDER BY payment_date DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i',$studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $out['last_date'] = $row['payment_date'] ?? $out['last_date'];
            $out['last_amount'] = (float)($row['amount'] ?? 0);
            $out['last_receipt'] = $row['receipt'] ?? null;
        }
    }
    return $out;
}

function edu_chat_insights_attendance_data(mysqli $conn, int $studentId, int $schoolId, string $period = 'month'): array {
    $out = ['records'=>0,'present'=>0,'late'=>0,'absent'=>0,'justified'=>0,'permission'=>0,'rate'=>0.0,'label'=>'este mes'];
    if (!edu_chat_table_exists($conn,'asistencia')) return $out;
    [$start,$end,$label] = edu_chat_analytics_period($conn,$schoolId,$period);
    $out['label'] = $label;
    $cancel = edu_chat_column_exists($conn,'asistencia','is_cancelled') ? ' AND COALESCE(a.is_cancelled,0)=0' : '';
    $sql = "SELECT d.estado,COUNT(*) total FROM (
                SELECT a.fecha,CASE
                    WHEN SUM(LOWER(TRIM(a.estado))='tarde')>0 THEN 'Tarde'
                    WHEN SUM(LOWER(TRIM(a.estado)) IN ('presente','normal','temprano'))>0 THEN 'Presente'
                    WHEN SUM(LOWER(TRIM(a.estado))='permiso')>0 THEN 'Permiso'
                    WHEN SUM(LOWER(TRIM(a.estado)) IN ('ausente justificada','ausencia justificada'))>0 THEN 'Ausente Justificada'
                    ELSE 'Ausente' END estado
                FROM asistencia a
                WHERE a.student_id=? AND a.tipo='Entrada' AND a.fecha BETWEEN ? AND ? $cancel
                GROUP BY a.fecha
            ) d GROUP BY d.estado";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $out;
    $stmt->bind_param('iss',$studentId,$start,$end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $count = (int)($row['total'] ?? 0);
        $out['records'] += $count;
        if ($row['estado']==='Presente') $out['present'] += $count;
        elseif ($row['estado']==='Tarde') $out['late'] += $count;
        elseif ($row['estado']==='Ausente') $out['absent'] += $count;
        elseif ($row['estado']==='Ausente Justificada') $out['justified'] += $count;
        elseif ($row['estado']==='Permiso') $out['permission'] += $count;
    }
    $stmt->close();
    if ($out['records'] > 0) $out['rate'] = round((($out['present'] + $out['late']) / $out['records']) * 100,1);
    return $out;
}

function edu_chat_insights_risk_data(mysqli $conn, int $studentId, int $schoolId): array {
    $out = ['records'=>0,'courses'=>0,'details'=>[]];
    foreach (['evaluation_grades','evaluations','teacher_courses','student'] as $table) if (!edu_chat_table_exists($conn,$table)) return $out;
    $year = edu_chat_active_year($conn,$schoolId);
    $yearId = (int)($year['id'] ?? 0);
    $where = [
        's.id=?','s.school_id=?',
        "((eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(eg.grade AS DECIMAL(10,2))<=10) OR UPPER(TRIM(eg.grade))='C')"
    ];
    $types='ii'; $params=[$studentId,$schoolId];
    if ($yearId>0 && edu_chat_column_exists($conn,'teacher_courses','academic_year_id')) {
        $where[]='tc.academic_year_id=?'; $types.='i'; $params[]=$yearId;
    }
    $sql = "SELECT COALESCE(NULLIF(TRIM(ac.name),''),'Sin curso') course,COUNT(*) records
            FROM evaluation_grades eg
            INNER JOIN student s ON s.id=eg.student_id
            INNER JOIN evaluations e ON e.id=eg.evaluation_id
            LEFT JOIN teacher_courses tc ON tc.id=e.teacher_course_id
            LEFT JOIN academic_courses ac ON ac.id=tc.course_id
            WHERE " . implode(' AND ',$where) . "
            GROUP BY course ORDER BY records DESC,course ASC";
    $stmt=$conn->prepare($sql);
    if (!$stmt) return $out;
    edu_chat_bind($stmt,$types,$params);
    $stmt->execute();
    $res=$stmt->get_result();
    while($row=$res->fetch_assoc()){
        $records=(int)($row['records']??0);
        $out['records'] += $records;
        $out['courses']++;
        if(count($out['details'])<8)$out['details'][]=['course'=>(string)$row['course'],'records'=>$records];
    }
    $stmt->close();
    return $out;
}

function edu_chat_student_360_result(mysqli $conn, array $actor, array $entities, string $nameSearch): array {
    if ((int)($actor['type'] ?? 0) !== 1) return edu_chat_result('La ficha 360 solo está disponible para administración.');
    $nameSearch = trim($nameSearch);
    if ($nameSearch === '') return edu_chat_result('Indica el nombre del estudiante que deseas consultar.');

    $matches = edu_chat_insights_student_matches($conn,$actor,$entities,$nameSearch,8);
    if (!$matches) return edu_chat_result('No encontré estudiantes activos que coincidan con "'.$nameSearch.'" y esos filtros.');
    if (count($matches) > 1) {
        $lines=[];
        foreach($matches as $i=>$row){
            $lines[] = ($i+1).'. '.(string)$row['name'].' — '.(string)$row['nivel'].' · '.(string)$row['grado'].'° '.(string)$row['seccion'];
        }
        return edu_chat_result(
            "Encontré varias coincidencias. Indica el nombre completo o el aula:\n".implode("\n",$lines),
            [],
            [['label'=>'Coincidencias','value'=>(string)count($matches),'tone'=>'warning']],
            [edu_chat_action('Ver Estudiantes','students','fa-users')]
        );
    }

    $student=$matches[0];
    $studentId=(int)$student['id'];
    $schoolId=(int)$actor['school_id'];

    $debt=['count_concepts'=>0,'total_debt'=>0.0,'overdue_count'=>0];
    if (debt_engine_available($conn)) {
        $pending=debt_engine_pending_debts(debt_engine_get_student_debts($conn,$studentId,$schoolId));
        $debt=debt_engine_summary($pending);
    }
    $payments=edu_chat_insights_payment_data($conn,$studentId);
    $attendance=edu_chat_insights_attendance_data($conn,$studentId,$schoolId,'month');
    $risk=edu_chat_insights_risk_data($conn,$studentId,$schoolId);

    $location=(string)$student['nivel'].' · '.(string)$student['grado'].'° '.(string)$student['seccion'];
    $lines=[];
    $lines[]='Estudiante: '.(string)$student['name'];
    $lines[]='Aula: '.$location;
    $lines[]='Finanzas: '.(int)($debt['count_concepts']??0).' obligaciones pendientes · '.edu_chat_money((float)($debt['total_debt']??0));
    if(!empty($debt['overdue_count']))$lines[]='Deudas vencidas: '.(int)$debt['overdue_count'].'.';
    if($payments['operations']>0){
        $last=$payments['last_date']?date('d/m/Y',strtotime((string)$payments['last_date'])):'sin fecha';
        $lines[]='Pagos: '.$payments['operations'].' comprobantes confirmados · total '.edu_chat_money($payments['total_paid']).' · último '.$last.' por '.edu_chat_money($payments['last_amount']).'.';
    } else $lines[]='Pagos: sin pagos confirmados registrados.';
    $lines[]='Asistencia '.$attendance['label'].': '.$attendance['records'].' registros · '.$attendance['present'].' presentes · '.$attendance['late'].' tardanzas · '.$attendance['absent'].' ausencias · '.number_format($attendance['rate'],1).'% asistencia.';
    if($risk['records']>0){
        $detail=[];foreach($risk['details'] as $item)$detail[]=$item['course'].' ('.$item['records'].')';
        $lines[]='Riesgo académico: '.$risk['records'].' registros críticos en '.$risk['courses'].' curso'.($risk['courses']===1?'':'s').'. '.implode(', ',$detail).'.';
    } else $lines[]='Riesgo académico: no encontré registros críticos del año actual.';

    return edu_chat_result(
        implode("\n",$lines),
        ['Muéstrame sus deudas','¿Cómo está su asistencia?','¿En qué cursos está en riesgo?'],
        [
            ['label'=>'Saldo pendiente','value'=>edu_chat_money((float)($debt['total_debt']??0)),'tone'=>(float)($debt['total_debt']??0)>0?'danger':'success'],
            ['label'=>'Tardanzas mes','value'=>(string)$attendance['late'],'tone'=>$attendance['late']>0?'warning':'success'],
            ['label'=>'Ausencias mes','value'=>(string)$attendance['absent'],'tone'=>$attendance['absent']>0?'warning':'success'],
            ['label'=>'Registros críticos','value'=>(string)$risk['records'],'tone'=>$risk['records']>0?'danger':'success']
        ],
        [edu_chat_action('Ver Estudiantes','students','fa-users'),edu_chat_action('Ver Reporte de Deudas','debt_reports','fa-exclamation-circle'),edu_chat_action('Ver Reporte de Notas','grades_report','fa-chart-bar')]
    );
}

function edu_chat_attendance_roster_result(mysqli $conn, array $actor, array $entities, string $status = 'all', int $minOccurrences = 1, int $limit = 100): array {
    $type=(int)($actor['type']??0);
    if(!in_array($type,[1,3],true)) return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    if(!edu_chat_table_exists($conn,'asistencia')) return edu_chat_result('El módulo de asistencia no está disponible.');
    $status=in_array($status,['all','present','late','absent','justified','permission'],true)?$status:'all';
    $minOccurrences=max(1,min(100,$minOccurrences)); $limit=max(1,min(200,$limit));
    $school=(int)$actor['school_id']; $period=(string)($entities['period']??'month'); [$start,$end,$label]=edu_chat_analytics_period($conn,$school,$period);
    $cancel=edu_chat_column_exists($conn,'asistencia','is_cancelled')?' AND COALESCE(a.is_cancelled,0)=0':'';
    $daily="SELECT a.student_id,a.fecha,CASE WHEN SUM(LOWER(TRIM(a.estado))='tarde')>0 THEN 'Tarde' WHEN SUM(LOWER(TRIM(a.estado)) IN ('presente','normal','temprano'))>0 THEN 'Presente' WHEN SUM(LOWER(TRIM(a.estado))='permiso')>0 THEN 'Permiso' WHEN SUM(LOWER(TRIM(a.estado)) IN ('ausente justificada','ausencia justificada'))>0 THEN 'Ausente Justificada' ELSE 'Ausente' END estado FROM asistencia a WHERE a.tipo='Entrada' AND a.fecha BETWEEN ? AND ? $cancel GROUP BY a.student_id,a.fecha";
    $where=['s.school_id=?',"LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];
    $types='ssi';$params=[$start,$end,$school];
    edu_chat_analytics_apply_student_filters($entities,'s',$where,$types,$params);
    $statusMap=['present'=>'Presente','late'=>'Tarde','absent'=>'Ausente','justified'=>'Ausente Justificada','permission'=>'Permiso'];
    if($status!=='all'){$where[]='d.estado=?';$types.='s';$params[]=$statusMap[$status];}
    $types.='i';$params[]=$minOccurrences;
    $sql="SELECT s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion,COUNT(*) occurrences
          FROM ($daily) d INNER JOIN student s ON s.id=d.student_id
          WHERE ".implode(' AND ',$where)."
          GROUP BY s.id,s.name,s.nivel,s.grado,s.seccion HAVING COUNT(*)>=?
          ORDER BY occurrences DESC,FIELD(s.nivel,'Inicial','Primaria','Secundaria'),CAST(s.grado AS UNSIGNED),s.grado,seccion,s.name LIMIT $limit";
    $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar la consulta de asistencia.');
    edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($row=$res->fetch_assoc())$rows[]=$row;$stmt->close();
    if(!$rows)return edu_chat_result('No encontré estudiantes que cumplan ese filtro de asistencia para '.$label.'.');
    $labelStatus=$status==='all'?'registros':strtolower($statusMap[$status]);$lines=[];
    foreach($rows as $i=>$row)$lines[]=($i+1).'. '.$row['name'].' — '.$row['occurrences'].' '.$labelStatus.' · '.$row['nivel'].' · '.$row['grado'].'° '.$row['seccion'];
    return edu_chat_result('Estudiantes por asistencia ('.$label.'):\n'.implode("\n",$lines),[],[['label'=>'Estudiantes listados','value'=>(string)count($rows),'tone'=>'warning']],[edu_chat_action('Ver Reporte de Asistencia','attendance_report','fa-clipboard-list')]);
}

function edu_chat_academic_risk_roster_result(mysqli $conn, array $actor, array $entities, int $minCritical = 1, int $limit = 100): array {
    $type=(int)($actor['type']??0);
    if(!in_array($type,[1,2],true)) return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    foreach(['evaluation_grades','evaluations','teacher_courses','student'] as $table)if(!edu_chat_table_exists($conn,$table))return edu_chat_result('No está disponible la información académica necesaria.');
    $school=(int)$actor['school_id'];$year=edu_chat_active_year($conn,$school);$yearId=(int)($year['id']??0);$minCritical=max(1,min(100,$minCritical));$limit=max(1,min(200,$limit));
    $where=['s.school_id=?',"LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')","((eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(eg.grade AS DECIMAL(10,2))<=10) OR UPPER(TRIM(eg.grade))='C')"];
    $types='i';$params=[$school];
    if($yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($type===2){$teacherId=(int)($actor['teacher_id']??0);if($teacherId<=0)return edu_chat_result('No pude identificar tu ficha docente.');$where[]='tc.teacher_id=?';$types.='i';$params[]=$teacherId;}
    edu_chat_analytics_apply_student_filters($entities,'s',$where,$types,$params);
    if(!empty($entities['bimestre'])&&edu_chat_column_exists($conn,'evaluations','bimestre')){$where[]='e.bimestre=?';$types.='s';$params[]=(string)$entities['bimestre'];}
    if(!empty($entities['course'])){$where[]='LOWER(TRIM(ac.name))=LOWER(TRIM(?))';$types.='s';$params[]=(string)$entities['course'];}
    $types.='i';$params[]=$minCritical;
    $sql="SELECT s.id,s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion,COUNT(*) critical_records,COUNT(DISTINCT ac.id) critical_courses,GROUP_CONCAT(DISTINCT ac.name ORDER BY ac.name SEPARATOR ', ') courses
          FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id INNER JOIN evaluations e ON e.id=eg.evaluation_id LEFT JOIN teacher_courses tc ON tc.id=e.teacher_course_id LEFT JOIN academic_courses ac ON ac.id=tc.course_id
          WHERE ".implode(' AND ',$where)."
          GROUP BY s.id,s.name,s.nivel,s.grado,s.seccion HAVING COUNT(*)>=?
          ORDER BY critical_records DESC,critical_courses DESC,s.name ASC LIMIT $limit";
    $stmt=$conn->prepare($sql);if(!$stmt)return edu_chat_result('No pude preparar la consulta de riesgo académico.');edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($row=$res->fetch_assoc())$rows[]=$row;$stmt->close();
    if(!$rows)return edu_chat_result('No encontré estudiantes con registros académicos críticos que cumplan esos filtros.');
    $lines=[];foreach($rows as $i=>$row){$courses=trim((string)($row['courses']??''));$lines[]=($i+1).'. '.$row['name'].' — '.$row['critical_records'].' registros críticos en '.$row['critical_courses'].' curso'.((int)$row['critical_courses']===1?'':'s').' · '.$row['nivel'].' · '.$row['grado'].'° '.$row['seccion'].($courses!==''?' · '.$courses:'');}
    return edu_chat_result('Estudiantes en riesgo académico:\n'.implode("\n",$lines),[],[['label'=>'Estudiantes listados','value'=>(string)count($rows),'tone'=>'danger']],[edu_chat_action('Ver Reporte de Notas','grades_report','fa-chart-bar')]);
}
