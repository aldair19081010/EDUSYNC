<?php

function edu_chat_analytics_grade_filter(string $alias, string $grade, string &$types, array &$params): string {
    $grade = trim($grade);
    if ($grade === '') return '1=1';
    if (preg_match('/^\d+/', $grade, $m)) {
        $types .= 'i';
        $params[] = (int)$m[0];
        return "CAST($alias.grado AS UNSIGNED)=?";
    }
    $types .= 's';
    $params[] = $grade;
    return "LOWER(TRIM($alias.grado))=LOWER(TRIM(?))";
}

function edu_chat_analytics_apply_student_filters(array $entities, string $alias, array &$where, string &$types, array &$params): void {
    if (!empty($entities['level'])) {
        $where[] = "LOWER(TRIM($alias.nivel))=LOWER(TRIM(?))";
        $types .= 's';
        $params[] = (string)$entities['level'];
    }
    if (!empty($entities['grade'])) $where[] = edu_chat_analytics_grade_filter($alias, (string)$entities['grade'], $types, $params);
    if (!empty($entities['section'])) {
        $where[] = "UPPER(TRIM(COALESCE($alias.seccion,'')))=UPPER(TRIM(?))";
        $types .= 's';
        $params[] = (string)$entities['section'];
    }
}

function edu_chat_analytics_group_sql(string $groupBy, string $alias = 's'): array {
    $section = "COALESCE(NULLIF(TRIM($alias.seccion),''),'Sin sección')";
    switch ($groupBy) {
        case 'level':
            return ["$alias.nivel nivel", "$alias.nivel", "FIELD($alias.nivel,'Inicial','Primaria','Secundaria'),$alias.nivel"];
        case 'grade':
            return ["$alias.nivel nivel,$alias.grado grado", "$alias.nivel,$alias.grado", "FIELD($alias.nivel,'Inicial','Primaria','Secundaria'),CAST($alias.grado AS UNSIGNED),$alias.grado"];
        case 'section':
            return ["$alias.nivel nivel,$section seccion", "$alias.nivel,$section", "FIELD($alias.nivel,'Inicial','Primaria','Secundaria'),seccion"];
        case 'grade_section':
        default:
            return ["$alias.nivel nivel,$alias.grado grado,$section seccion", "$alias.nivel,$alias.grado,$section", "FIELD($alias.nivel,'Inicial','Primaria','Secundaria'),CAST($alias.grado AS UNSIGNED),$alias.grado,seccion"];
    }
}

function edu_chat_analytics_row_label(array $row, string $groupBy): string {
    $level = trim((string)($row['nivel'] ?? ''));
    $grade = trim((string)($row['grado'] ?? ''));
    $section = trim((string)($row['seccion'] ?? ''));
    if ($groupBy === 'level') return $level !== '' ? $level : 'Sin nivel';
    if ($groupBy === 'grade') return trim(($level !== '' ? $level . ' · ' : '') . ($grade !== '' ? $grade . '°' : 'Sin grado'));
    if ($groupBy === 'section') return trim(($level !== '' ? $level . ' · ' : '') . ($section !== '' ? $section : 'Sin sección'));
    return trim(($level !== '' ? $level . ' · ' : '') . ($grade !== '' ? $grade . '°' : 'Sin grado') . ($section !== '' ? ' ' . $section : ''));
}

function edu_chat_student_distribution_result(mysqli $conn, array $actor, array $entities, string $groupBy = 'grade_section'): array {
    $type = (int)($actor['type'] ?? 0);
    if (!in_array($type, [1,2,3], true)) return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    if (!edu_chat_table_exists($conn, 'student')) return edu_chat_result('No está disponible la información de estudiantes.');

    $groupBy = in_array($groupBy, ['level','grade','section','grade_section'], true) ? $groupBy : 'grade_section';
    $schoolId = (int)($actor['school_id'] ?? 0);
    $where = ['s.school_id=?', "LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];
    $types = 'i';
    $params = [$schoolId];
    $join = '';

    if ($type === 2) {
        $teacherId = (int)($actor['teacher_id'] ?? 0);
        if ($teacherId <= 0) return edu_chat_result('No pude identificar tu ficha docente.');
        $join = ' INNER JOIN teacher_courses tc ON tc.school_id=s.school_id AND TRIM(tc.grado)=TRIM(s.grado) AND UPPER(TRIM(COALESCE(tc.seccion,\'\')))=UPPER(TRIM(COALESCE(s.seccion,\'\')))';
        $where[] = 'tc.teacher_id=?';
        $types .= 'i';
        $params[] = $teacherId;
        $year = edu_chat_active_year($conn, $schoolId);
        $yearId = (int)($year['id'] ?? 0);
        if ($yearId > 0 && edu_chat_column_exists($conn, 'teacher_courses', 'academic_year_id')) {
            $where[] = 'tc.academic_year_id=?';
            $types .= 'i';
            $params[] = $yearId;
        }
    }

    edu_chat_analytics_apply_student_filters($entities, 's', $where, $types, $params);
    [$select, $group, $order] = edu_chat_analytics_group_sql($groupBy, 's');
    $sql = "SELECT $select,COUNT(DISTINCT s.id) total FROM student s $join WHERE " . implode(' AND ', $where) . " GROUP BY $group ORDER BY $order";
    $stmt = $conn->prepare($sql);
    edu_chat_bind($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    $grandTotal = 0;
    while ($row = $res->fetch_assoc()) {
        $row['total'] = (int)($row['total'] ?? 0);
        $grandTotal += $row['total'];
        $rows[] = $row;
    }
    $stmt->close();

    if (!$rows) return edu_chat_result('No encontré estudiantes activos con esos filtros.');

    $lines = [];
    $cards = [];
    foreach ($rows as $row) {
        $label = edu_chat_analytics_row_label($row, $groupBy);
        $lines[] = '• ' . $label . ': ' . (int)$row['total'] . ' estudiante' . ((int)$row['total'] === 1 ? '' : 's');
        if (count($cards) < 8) $cards[] = ['label'=>$label,'value'=>(string)$row['total'],'tone'=>'primary'];
    }

    $scope = !empty($entities['level']) ? ' de ' . strtolower((string)$entities['level']) : '';
    $message = 'Distribución real de estudiantes activos' . $scope . ":\n" . implode("\n", $lines) . "\nTotal: " . $grandTotal . ' estudiantes.';
    return edu_chat_result($message, ['¿Y cuántos tienen deuda?', '¿Cómo está la asistencia por sección?'], $cards, $type === 1 ? [edu_chat_action('Ver Estudiantes','students','fa-users')] : []);
}

function edu_chat_student_roster_result(mysqli $conn, array $actor, array $entities, string $nameSearch = '', int $limit = 20): array {
    $type = (int)($actor['type'] ?? 0);
    if (!in_array($type, [1,2,3], true)) return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    $limit = max(1, min(50, $limit));
    $schoolId = (int)($actor['school_id'] ?? 0);
    $where = ['s.school_id=?', "LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];
    $types = 'i';
    $params = [$schoolId];
    $join = '';

    if ($type === 2) {
        $teacherId = (int)($actor['teacher_id'] ?? 0);
        if ($teacherId <= 0) return edu_chat_result('No pude identificar tu ficha docente.');
        $join = ' INNER JOIN teacher_courses tc ON tc.school_id=s.school_id AND TRIM(tc.grado)=TRIM(s.grado) AND UPPER(TRIM(COALESCE(tc.seccion,\'\')))=UPPER(TRIM(COALESCE(s.seccion,\'\')))';
        $where[] = 'tc.teacher_id=?';
        $types .= 'i';
        $params[] = $teacherId;
        $year = edu_chat_active_year($conn, $schoolId);
        $yearId = (int)($year['id'] ?? 0);
        if ($yearId > 0 && edu_chat_column_exists($conn, 'teacher_courses', 'academic_year_id')) {
            $where[] = 'tc.academic_year_id=?';
            $types .= 'i';
            $params[] = $yearId;
        }
    }

    edu_chat_analytics_apply_student_filters($entities, 's', $where, $types, $params);
    $nameSearch = trim($nameSearch);
    if ($nameSearch !== '') {
        $where[] = 'LOWER(s.name) LIKE LOWER(?)';
        $types .= 's';
        $params[] = '%' . $nameSearch . '%';
    }

    $sql = "SELECT DISTINCT s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion FROM student s $join WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(s.nivel,'Inicial','Primaria','Secundaria'),CAST(s.grado AS UNSIGNED),s.grado,seccion,s.name LIMIT $limit";
    $stmt = $conn->prepare($sql);
    edu_chat_bind($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    if (!$rows) return edu_chat_result('No encontré estudiantes activos con esos filtros.');
    $lines = [];
    foreach ($rows as $i => $row) {
        $lines[] = ($i + 1) . '. ' . (string)$row['name'] . ' — ' . (string)$row['nivel'] . ' · ' . (string)$row['grado'] . '° ' . (string)$row['seccion'];
    }
    return edu_chat_result('Estudiantes encontrados (' . count($rows) . "):\n" . implode("\n", $lines), [], [['label'=>'Estudiantes listados','value'=>(string)count($rows),'tone'=>'primary']], $type === 1 ? [edu_chat_action('Ver Estudiantes','students','fa-users')] : []);
}

function edu_chat_debt_distribution_result(mysqli $conn, array $actor, array $entities, string $groupBy = 'grade_section'): array {
    if ((int)($actor['type'] ?? 0) !== 1) return edu_chat_result('Esta consulta solo está disponible para administración.');
    if (!debt_engine_available($conn)) return edu_chat_result('El módulo financiero no está disponible.');
    $groupBy = in_array($groupBy, ['level','grade','section','grade_section'], true) ? $groupBy : 'grade_section';

    $hasDebtStatus = debt_engine_column_exists($conn,'student_ef_list','debt_status');
    $hasPaymentStatus = debt_engine_column_exists($conn,'payments','payment_status');
    $join = "LEFT JOIN payments p ON p.ef_id=ef.id" . ($hasPaymentStatus ? " AND COALESCE(p.payment_status,'Confirmado')='Confirmado'" : '');
    $where = ['s.school_id=?', "LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];
    $types = 'i';
    $params = [(int)$actor['school_id']];
    if ($hasDebtStatus) $where[] = "ef.debt_status='Activa'";
    edu_chat_analytics_apply_student_filters($entities, 's', $where, $types, $params);

    $inner = "SELECT s.id student_id,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion,ef.id fee_id,GREATEST(COALESCE(ef.discounted_amount,ef.total_fee)-COALESCE(SUM(p.amount),0),0) balance FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id $join WHERE " . implode(' AND ', $where) . " GROUP BY s.id,s.nivel,s.grado,s.seccion,ef.id,ef.discounted_amount,ef.total_fee HAVING balance>0.009";

    if ($groupBy === 'level') { $select='x.nivel nivel'; $group='x.nivel'; $order="FIELD(x.nivel,'Inicial','Primaria','Secundaria'),x.nivel"; }
    elseif ($groupBy === 'grade') { $select='x.nivel nivel,x.grado grado'; $group='x.nivel,x.grado'; $order="FIELD(x.nivel,'Inicial','Primaria','Secundaria'),CAST(x.grado AS UNSIGNED),x.grado"; }
    elseif ($groupBy === 'section') { $select='x.nivel nivel,x.seccion seccion'; $group='x.nivel,x.seccion'; $order="FIELD(x.nivel,'Inicial','Primaria','Secundaria'),x.seccion"; }
    else { $select='x.nivel nivel,x.grado grado,x.seccion seccion'; $group='x.nivel,x.grado,x.seccion'; $order="FIELD(x.nivel,'Inicial','Primaria','Secundaria'),CAST(x.grado AS UNSIGNED),x.grado,x.seccion"; }

    $sql = "SELECT $select,COUNT(DISTINCT x.student_id) students,COUNT(*) obligations,COALESCE(SUM(x.balance),0) total_debt FROM ($inner) x GROUP BY $group ORDER BY $order";
    $stmt=$conn->prepare($sql); edu_chat_bind($stmt,$types,$params); $stmt->execute(); $res=$stmt->get_result();
    $rows=[]; $grand=0.0; $students=0;
    while($row=$res->fetch_assoc()){ $row['students']=(int)$row['students']; $row['obligations']=(int)$row['obligations']; $row['total_debt']=(float)$row['total_debt']; $grand+=$row['total_debt']; $students+=$row['students']; $rows[]=$row; }
    $stmt->close();
    if(!$rows) return edu_chat_result('No encontré deuda pendiente con esos filtros.');

    $lines=[]; $cards=[];
    foreach($rows as $row){ $label=edu_chat_analytics_row_label($row,$groupBy); $lines[]='• '.$label.': '.$row['students'].' estudiantes · '.$row['obligations'].' obligaciones · '.edu_chat_money($row['total_debt']); if(count($cards)<8)$cards[]=['label'=>$label,'value'=>edu_chat_money($row['total_debt']),'tone'=>'danger']; }
    return edu_chat_result("Deuda pendiente por grupo:\n".implode("\n",$lines)."\nTotal de los grupos: ".edu_chat_money($grand).'.', ['Dame los 10 que más deben','¿Cuánto se ha cobrado este mes?'], $cards, [edu_chat_action('Ver Reporte de Deudas','debt_reports','fa-exclamation-circle')]);
}

function edu_chat_analytics_period(mysqli $conn, int $schoolId, string $period): array {
    $period = in_array($period, ['today','month','year'], true) ? $period : 'today';
    if ($period === 'today') return [date('Y-m-d'), date('Y-m-d'), 'hoy'];
    if ($period === 'month') return [date('Y-m-01'), date('Y-m-t'), 'este mes'];
    $year = edu_chat_active_year($conn, $schoolId);
    return [$year['start_date'] ?? date('Y-01-01'), $year['end_date'] ?? date('Y-12-31'), !empty($year['year']) ? 'el año '.$year['year'] : 'este año'];
}

function edu_chat_attendance_distribution_result(mysqli $conn, array $actor, array $entities, string $groupBy = 'grade_section'): array {
    $type=(int)($actor['type']??0);
    if(!in_array($type,[1,3],true)) return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    if(!edu_chat_table_exists($conn,'asistencia')) return edu_chat_result('El módulo de asistencia no está disponible.');
    $groupBy=in_array($groupBy,['level','grade','section','grade_section'],true)?$groupBy:'grade_section';
    $school=(int)$actor['school_id']; $period=(string)($entities['period']??'today'); [$start,$end,$label]=edu_chat_analytics_period($conn,$school,$period);

    $cancel = edu_chat_column_exists($conn,'asistencia','is_cancelled') ? ' AND COALESCE(a.is_cancelled,0)=0' : '';
    $daily = "SELECT a.student_id,a.fecha,CASE WHEN SUM(LOWER(TRIM(a.estado))='tarde')>0 THEN 'Tarde' WHEN SUM(LOWER(TRIM(a.estado)) IN ('presente','normal','temprano'))>0 THEN 'Presente' WHEN SUM(LOWER(TRIM(a.estado))='permiso')>0 THEN 'Permiso' WHEN SUM(LOWER(TRIM(a.estado)) IN ('ausente justificada','ausencia justificada'))>0 THEN 'Ausente Justificada' ELSE 'Ausente' END estado FROM asistencia a WHERE a.tipo='Entrada' AND a.fecha BETWEEN ? AND ? $cancel GROUP BY a.student_id,a.fecha";
    $where=['s.school_id=?',"LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];
    $types='ssi'; $params=[$start,$end,$school];
    edu_chat_analytics_apply_student_filters($entities,'s',$where,$types,$params);
    [$select,$group,$order]=edu_chat_analytics_group_sql($groupBy,'s');
    $sql="SELECT $select,COUNT(DISTINCT CONCAT(d.student_id,'|',d.fecha)) records,SUM(d.estado='Presente') presentes,SUM(d.estado='Tarde') tardes,SUM(d.estado='Ausente') ausentes,SUM(d.estado='Ausente Justificada') justificadas,SUM(d.estado='Permiso') permisos FROM ($daily) d INNER JOIN student s ON s.id=d.student_id WHERE ".implode(' AND ',$where)." GROUP BY $group ORDER BY $order";
    $stmt=$conn->prepare($sql); edu_chat_bind($stmt,$types,$params); $stmt->execute(); $res=$stmt->get_result(); $rows=[];
    while($row=$res->fetch_assoc())$rows[]=$row; $stmt->close();
    if(!$rows)return edu_chat_result('No hay registros de asistencia para '.$label.' con esos filtros.');
    $lines=[]; $cards=[];
    foreach($rows as $row){$name=edu_chat_analytics_row_label($row,$groupBy);$records=(int)$row['records'];$present=(int)$row['presentes'];$late=(int)$row['tardes'];$abs=(int)$row['ausentes'];$rate=$records>0?round((($present+$late)/$records)*100,1):0;$lines[]='• '.$name.': '.$records.' registros · '.$present.' presentes · '.$late.' tardanzas · '.$abs.' ausencias · '.$rate.'% asistencia';if(count($cards)<8)$cards[]=['label'=>$name,'value'=>number_format($rate,1).'%','tone'=>$rate>=90?'success':'warning'];}
    return edu_chat_result('Asistencia por grupo para '.$label.":\n".implode("\n",$lines), [], $cards, [edu_chat_action('Ver Reporte de Asistencia','attendance_report','fa-clipboard-list')]);
}

function edu_chat_academic_risk_distribution_result(mysqli $conn, array $actor, array $entities, string $groupBy = 'grade_section'): array {
    $type=(int)($actor['type']??0);
    if(!in_array($type,[1,2],true)) return edu_chat_result('Esta consulta no está disponible para tu perfil.');
    foreach(['evaluation_grades','evaluations','teacher_courses','student'] as $table) if(!edu_chat_table_exists($conn,$table)) return edu_chat_result('No está disponible la información académica necesaria.');
    $groupBy=in_array($groupBy,['level','grade','section','grade_section','course'],true)?$groupBy:'grade_section';
    $school=(int)$actor['school_id']; $year=edu_chat_active_year($conn,$school); $yearId=(int)($year['id']??0);
    $where=['s.school_id=?',"LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')","((eg.grade REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(eg.grade AS DECIMAL(10,2))<=10) OR UPPER(TRIM(eg.grade))='C')"];
    $types='i';$params=[$school];
    if($yearId>0&&edu_chat_column_exists($conn,'teacher_courses','academic_year_id')){$where[]='tc.academic_year_id=?';$types.='i';$params[]=$yearId;}
    if($type===2){$teacherId=(int)($actor['teacher_id']??0);if($teacherId<=0)return edu_chat_result('No pude identificar tu ficha docente.');$where[]='tc.teacher_id=?';$types.='i';$params[]=$teacherId;}
    edu_chat_analytics_apply_student_filters($entities,'s',$where,$types,$params);
    if(!empty($entities['bimestre'])&&edu_chat_column_exists($conn,'evaluations','bimestre')){$where[]='e.bimestre=?';$types.='s';$params[]=(string)$entities['bimestre'];}
    if(!empty($entities['course'])){$where[]='LOWER(TRIM(ac.name))=LOWER(TRIM(?))';$types.='s';$params[]=(string)$entities['course'];}
    if($groupBy==='course'){$select="COALESCE(NULLIF(TRIM(ac.name),''),'Sin curso') course";$group='course';$order='course';}
    else {[$select,$group,$order]=edu_chat_analytics_group_sql($groupBy,'s');}
    $sql="SELECT $select,COUNT(DISTINCT s.id) students,COUNT(*) records FROM evaluation_grades eg INNER JOIN student s ON s.id=eg.student_id INNER JOIN evaluations e ON e.id=eg.evaluation_id LEFT JOIN teacher_courses tc ON tc.id=e.teacher_course_id LEFT JOIN academic_courses ac ON ac.id=tc.course_id WHERE ".implode(' AND ',$where)." GROUP BY $group ORDER BY $order";
    $stmt=$conn->prepare($sql);edu_chat_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($row=$res->fetch_assoc())$rows[]=$row;$stmt->close();
    if(!$rows)return edu_chat_result('No encontré registros académicos críticos con esos filtros.');
    $lines=[];$cards=[];
    foreach($rows as $row){$name=$groupBy==='course'?(string)$row['course']:edu_chat_analytics_row_label($row,$groupBy);$lines[]='• '.$name.': '.(int)$row['students'].' estudiantes · '.(int)$row['records'].' registros críticos';if(count($cards)<8)$cards[]=['label'=>$name,'value'=>(string)$row['students'],'tone'=>'danger'];}
    return edu_chat_result("Riesgo académico por grupo:\n".implode("\n",$lines), [], $cards, [edu_chat_action('Ver Reporte de Notas','grades_report','fa-chart-bar')]);
}
