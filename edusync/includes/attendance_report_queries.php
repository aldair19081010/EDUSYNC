<?php

function arq_escape(mysqli $db, $value): string { return $db->real_escape_string(trim((string)$value)); }

function arq_filters(mysqli $db, int $school, array $input): array {
    $year=(int)($input['academic_year_id']??0);
    $from=trim((string)($input['date_from']??''));
    $to=trim((string)($input['date_to']??''));
    if ($from!=='' && $to!=='' && $from>$to) [$from,$to]=[$to,$from];
    $historical=false;$classrooms=[];$validRange=true;
    if ($year>0) {
        $q=$db->query("SELECT year,is_active,start_date,end_date FROM academic_year
                        WHERE id=$year AND school_id=$school LIMIT 1");
        if ($q && ($r=$q->fetch_assoc())) {
            $min=(string)($r['start_date']??'');
            $max=(string)($r['end_date']??'');
            if ($min==='' || $max==='' || $min==='0000-00-00' || $max==='0000-00-00') {
                $min=(int)$r['year'].'-01-01';
                $max=(int)$r['year'].'-12-31';
            }
            if ($from==='') $from=$min;
            if ($to==='') $to=$max;
            // No atribuir asistencias fuera del calendario a otro año.
            $from=max($min,$from);$to=min($max,$to);
            $validRange=$from<=$to;
            $historical=(int)$r['is_active']!==1;
            if ($historical) {
                require_once __DIR__.'/grades_report_historical_classroom.php';
                $classrooms=grhc_student_classrooms($db,$school,$year);
            }
        } else {
            // Un año inexistente o de otro colegio jamás usa la nómina actual.
            $historical=true;$validRange=false;
        }
    }
    if ($from==='') $from=date('Y-m-01');
    if ($to==='') $to=date('Y-m-d');
    if ($year===0 && $from>$to) [$from,$to]=[$to,$from];
    return [
        'year'=>$year,'from'=>$from,'to'=>$to,
        'historical'=>$historical,'classrooms'=>$classrooms,'valid_range'=>$validRange,
        'level'=>arq_escape($db,$input['nivel']??''),
        'grade'=>arq_escape($db,$input['grado']??''),
        'section'=>arq_escape($db,$input['seccion']??''),
        'student'=>(int)($input['student_id']??0),
        'type'=>arq_escape($db,$input['tipo']??''),
        'status'=>arq_escape($db,$input['estado']??''),
        'source'=>arq_escape($db,$input['source']??''),
        'search'=>arq_escape($db,$input['search']['value']??($input['search']??''))
    ];
}

/** Comparte la nómina histórica entre listado, resumen, impresión y Excel. */
function arq_roster_join(array $f): string {
    if (empty($f['historical'])) return '';
    $rooms=[];
    foreach (($f['classrooms']??[]) as $studentId=>$key) {
        $sid=(int)$studentId;
        if ($sid>0) $rooms[$key][]=$sid;
    }
    if (!$rooms) {
        return " INNER JOIN (SELECT 0 student_id,'' nivel,'' grado,''
                 seccion WHERE 1=0) hc ON hc.student_id=s.id";
    }
    $parts=[];
    foreach ($rooms as $key=>$ids) {
        $segments=explode('|',$key);
        if (count($segments)!==3) continue;
        [$level,$grade,$section]=$segments;
        $level=ucfirst($level);
        $section=strtoupper($section);
        // La clave proviene de grhc_key; escapar aun así etiquetas SQL.
        $levelEsc=str_replace("'","''",$level);
        $gradeEsc=str_replace("'","''",$grade);
        $sectionEsc=str_replace("'","''",$section);
        $parts[]="SELECT id student_id,'$levelEsc' nivel,'$gradeEsc' grado,
                 '$sectionEsc' seccion FROM student WHERE id IN (".
                 implode(',',array_map('intval',$ids)).")";
    }
    return $parts
        ? ' INNER JOIN ('.implode(' UNION ALL ',$parts).') hc ON hc.student_id=s.id'
        : " INNER JOIN (SELECT 0 student_id,'' nivel,'' grado,'' seccion
            WHERE 1=0) hc ON hc.student_id=s.id";
}
function arq_class_alias(array $f): string {
    return !empty($f['historical'])?'hc':'s';
}

function arq_where(array $f, int $school, bool $entryOnly=false, bool $applyStatus=true): string {
    // Intervalo semiabierto: incluir todo el último día si fecha es DATETIME.
    $w=["a.school_id=$school","s.school_id=$school","a.is_cancelled=0",
        "a.fecha>='{$f['from']}'",
        "a.fecha<DATE_ADD('{$f['to']}', INTERVAL 1 DAY)"];
    if (empty($f['valid_range'])) $w[]='1=0';
    if ($f['year']>0) $w[]="(a.academic_year_id={$f['year']} OR a.academic_year_id IS NULL)";
    $alias=arq_class_alias($f);
    if ($f['level']!=='') $w[]="$alias.nivel='{$f['level']}'";
    if ($f['grade']!=='') {
        if (!empty($f['historical'])) {
            $want=preg_replace('/[°º\s]+/u','',$f['grade']);
            $w[]="$alias.grado='$want'";
        } else $w[]="$alias.grado='{$f['grade']}'";
    }
    if ($f['section']!=='') $w[]="UPPER($alias.seccion)=UPPER('{$f['section']}')";
    if ($f['student']>0) $w[]="s.id={$f['student']}";
    if ($entryOnly) $w[]="a.tipo='Entrada'";
    elseif ($f['type']!=='') $w[]="a.tipo='{$f['type']}'";
    if ($applyStatus && $f['status']!=='') {
        if ($f['status']==='Presente') $w[]="a.estado IN ('Presente','Normal','Temprano')";
        else $w[]="a.estado='{$f['status']}'";
    }
    if ($f['source']!=='') $w[]="a.source='{$f['source']}'";
    if ($f['search']!=='') {
        $like='%'.$f['search'].'%';
        $w[]="(s.name LIKE '$like' OR s.id_no LIKE '$like' OR CONCAT($alias.nivel,' ',$alias.grado,' ',$alias.seccion) LIKE '$like')";
    }
    return implode(' AND ',$w);
}

function arq_summary(mysqli $db, int $school, array $f): array {
    $where=arq_where($f,$school,true,true);
    $join=arq_roster_join($f);
    $dayKey="CONCAT(a.student_id,'|',a.fecha)";
    $sql="SELECT COUNT(DISTINCT $dayKey) total,
      COUNT(DISTINCT CASE WHEN a.estado IN('Presente','Normal','Temprano','Tarde') THEN $dayKey END) present,
      COUNT(DISTINCT CASE WHEN a.estado='Tarde' THEN $dayKey END) late,
      COUNT(DISTINCT CASE WHEN a.estado='Ausente' THEN $dayKey END) absent,
      COUNT(DISTINCT CASE WHEN a.estado='Ausente Justificada' THEN $dayKey END) justified,
      COUNT(DISTINCT CASE WHEN a.estado='Permiso' THEN $dayKey END) permission
      FROM asistencia a INNER JOIN student s ON s.id=a.student_id $join WHERE $where";
    $r=$db->query($sql);$x=$r?$r->fetch_assoc():[];
    foreach(['total','present','late','absent','justified','permission'] as $k)$x[$k]=(int)($x[$k]??0);
    $x['attendance_rate']=$x['total']?round(($x['present']/$x['total'])*100,1):0;
    return $x;
}

function arq_dataset(mysqli $db, int $school, array $f, string $view): array {
    $state="CASE WHEN a.estado IN('Normal','Temprano') THEN 'Presente' ELSE a.estado END";
    $join=arq_roster_join($f);
    $alias=arq_class_alias($f);
    $levels="$alias.nivel,$alias.grado,$alias.seccion";
    $from="FROM asistencia a INNER JOIN student s ON s.id=a.student_id $join";
    if ($view==='detail') {
        $where=arq_where($f,$school,false,true);
        return ["SELECT a.id,a.fecha,a.hora,a.tipo,$state estado,
            a.source,a.notes,a.justification_status,a.justification_reason,
            s.id_no,s.name student_name,$levels $from WHERE $where",
            'a.fecha DESC,a.hora DESC,s.name'];
    }
    $where=arq_where($f,$school,true,true);
    $metricKey=in_array($view,['student','incidents'],true)
        ? 'a.fecha' : "CONCAT(a.student_id,'|',a.fecha)";
    $metrics="COUNT(DISTINCT $metricKey) marks,
        COUNT(DISTINCT CASE WHEN $state IN('Presente','Tarde') THEN $metricKey END) present,
        COUNT(DISTINCT CASE WHEN $state='Tarde' THEN $metricKey END) late,
        COUNT(DISTINCT CASE WHEN $state='Ausente' THEN $metricKey END) absent,
        COUNT(DISTINCT CASE WHEN $state='Ausente Justificada' THEN $metricKey END) justified,
        COUNT(DISTINCT CASE WHEN $state='Permiso' THEN $metricKey END) permission";
    if ($view==='student') return [
        "SELECT s.id,s.id_no,s.name student_name,$levels,$metrics
         $from WHERE $where GROUP BY s.id,s.id_no,s.name,$levels",'s.name'];
    if ($view==='class') return [
        "SELECT $levels,COUNT(DISTINCT s.id) students,$metrics
         $from WHERE $where GROUP BY $levels",
        "FIELD($alias.nivel,'Inicial','Primaria','Secundaria'),$alias.grado,$alias.seccion"];
    if ($view==='incidents') return [
        "SELECT s.id,s.id_no,s.name student_name,$levels,$metrics
         $from WHERE $where GROUP BY s.id,s.id_no,s.name,$levels
         HAVING late+absent+justified+permission>0",'late DESC,absent DESC,s.name'];
    $base=arq_where($f,$school,true,false);
    return [
        "SELECT a.fecha,$levels,
          COUNT(DISTINCT s.id) marked,
          COUNT(DISTINCT CASE WHEN $state IN('Presente','Tarde') THEN s.id END) present,
          COUNT(DISTINCT CASE WHEN $state='Tarde' THEN s.id END) late,
          COUNT(DISTINCT CASE WHEN $state IN('Ausente','Ausente Justificada') THEN s.id END) absent,
          COALESCE(c.status,'Pendiente') closure_status,c.closed_at,c.reopened_at
          $from LEFT JOIN attendance_day_closures c ON c.school_id=a.school_id
          AND c.attendance_date=a.fecha AND c.nivel=$alias.nivel
          AND c.grado=$alias.grado AND c.seccion=$alias.seccion
          WHERE $base GROUP BY a.fecha,$levels,c.status,c.closed_at,c.reopened_at",
        "a.fecha DESC,$alias.nivel,$alias.grado,$alias.seccion"];
}

function arq_rows(mysqli $db, int $school, array $f, string $view, int $start=0, int $length=25): array {
    if (!in_array($view,['detail','student','class','incidents','daily'],true))$view='detail';
    [$base,$order]=arq_dataset($db,$school,$f,$view);
    $count=$db->query("SELECT COUNT(*) n FROM ($base) report_rows");
    $total=(int)($count?$count->fetch_assoc()['n']:0);
    $limit=$length>0?' LIMIT '.max(0,$start).','.min(500,$length):'';
    $q=$db->query("$base ORDER BY $order$limit");$rows=[];while($q&&($r=$q->fetch_assoc()))$rows[]=$r;
    return ['total'=>$total,'rows'=>$rows];
}
