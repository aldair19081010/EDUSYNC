<?php

function arq_escape(mysqli $db, $value): string { return $db->real_escape_string(trim((string)$value)); }

function arq_filters(mysqli $db, int $school, array $input): array {
    $year = (int)($input['academic_year_id'] ?? 0);
    $from = trim((string)($input['date_from'] ?? ''));
    $to = trim((string)($input['date_to'] ?? ''));
    if ($year > 0) {
        $q = $db->query("SELECT start_date,end_date FROM academic_year WHERE id=$year AND school_id=$school LIMIT 1");
        if ($q && ($r=$q->fetch_assoc())) { if ($from==='') $from=$r['start_date']; if ($to==='') $to=$r['end_date']; }
    }
    if ($from==='') $from=date('Y-m-01');
    if ($to==='') $to=date('Y-m-d');
    if ($from>$to) { $tmp=$from; $from=$to; $to=$tmp; }
    return [
        'year'=>$year,'from'=>$from,'to'=>$to,
        'level'=>arq_escape($db,$input['nivel']??''),'grade'=>arq_escape($db,$input['grado']??''),
        'section'=>arq_escape($db,$input['seccion']??''),'student'=>(int)($input['student_id']??0),
        'type'=>arq_escape($db,$input['tipo']??''),'status'=>arq_escape($db,$input['estado']??''),
        'source'=>arq_escape($db,$input['source']??''),'search'=>arq_escape($db,$input['search']['value']??($input['search']??''))
    ];
}

function arq_where(array $f, int $school, bool $entryOnly=false, bool $applyStatus=true): string {
    $w=["a.school_id=$school","a.is_cancelled=0","a.fecha BETWEEN '{$f['from']}' AND '{$f['to']}'"];
    if ($f['year']>0) $w[]="(a.academic_year_id={$f['year']} OR a.academic_year_id IS NULL)";
    if ($f['level']!=='') $w[]="s.nivel='{$f['level']}'";
    if ($f['grade']!=='') $w[]="s.grado='{$f['grade']}'";
    if ($f['section']!=='') $w[]="s.seccion='{$f['section']}'";
    if ($f['student']>0) $w[]="s.id={$f['student']}";
    if ($entryOnly) $w[]="a.tipo='Entrada'"; elseif ($f['type']!=='') $w[]="a.tipo='{$f['type']}'";
    if ($applyStatus && $f['status']!=='') {
        if ($f['status']==='Presente') $w[]="a.estado IN ('Presente','Normal','Temprano')";
        else $w[]="a.estado='{$f['status']}'";
    }
    if ($f['source']!=='') $w[]="a.source='{$f['source']}'";
    if ($f['search']!=='') { $like='%'.$f['search'].'%'; $w[]="(s.name LIKE '$like' OR s.id_no LIKE '$like' OR CONCAT(s.nivel,' ',s.grado,' ',s.seccion) LIKE '$like')"; }
    return implode(' AND ',$w);
}

function arq_summary(mysqli $db, int $school, array $f): array {
    $where=arq_where($f,$school,true,true);
    $sql="SELECT COUNT(*) total,
      SUM(CASE WHEN a.estado IN('Presente','Normal','Temprano') THEN 1 ELSE 0 END) present,
      SUM(CASE WHEN a.estado='Tarde' THEN 1 ELSE 0 END) late,
      SUM(CASE WHEN a.estado='Ausente' THEN 1 ELSE 0 END) absent,
      SUM(CASE WHEN a.estado='Ausente Justificada' THEN 1 ELSE 0 END) justified,
      SUM(CASE WHEN a.estado='Permiso' THEN 1 ELSE 0 END) permission
      FROM asistencia a INNER JOIN student s ON s.id=a.student_id WHERE $where";
    $r=$db->query($sql);$x=$r?$r->fetch_assoc():[];
    foreach(['total','present','late','absent','justified','permission'] as $k)$x[$k]=(int)($x[$k]??0);
    $x['attendance_rate']=$x['total']?round((($x['present']+$x['late'])/$x['total'])*100,1):0;
    return $x;
}

function arq_dataset(mysqli $db, int $school, array $f, string $view): array {
    $state="CASE WHEN a.estado IN('Normal','Temprano') THEN 'Presente' ELSE a.estado END";
    if ($view==='detail') {
        $where=arq_where($f,$school,false,true);
        return ["SELECT a.id,a.fecha,a.hora,a.tipo,$state estado,a.source,a.notes,a.justification_status,a.justification_reason,s.id_no,s.name student_name,s.nivel,s.grado,s.seccion FROM asistencia a INNER JOIN student s ON s.id=a.student_id WHERE $where",'a.fecha DESC,a.hora DESC,s.name'];
    }
    $where=arq_where($f,$school,true,true);
    $metrics="COUNT(*) marks,SUM($state='Presente') present,SUM($state='Tarde') late,SUM($state='Ausente') absent,SUM($state='Ausente Justificada') justified,SUM($state='Permiso') permission";
    if ($view==='student') return ["SELECT s.id,s.id_no,s.name student_name,s.nivel,s.grado,s.seccion,$metrics FROM asistencia a INNER JOIN student s ON s.id=a.student_id WHERE $where GROUP BY s.id,s.id_no,s.name,s.nivel,s.grado,s.seccion",'s.name'];
    if ($view==='class') return ["SELECT s.nivel,s.grado,s.seccion,COUNT(DISTINCT s.id) students,$metrics FROM asistencia a INNER JOIN student s ON s.id=a.student_id WHERE $where GROUP BY s.nivel,s.grado,s.seccion",'FIELD(s.nivel,\'Inicial\',\'Primaria\',\'Secundaria\'),s.grado,s.seccion'];
    if ($view==='incidents') return ["SELECT s.id,s.id_no,s.name student_name,s.nivel,s.grado,s.seccion,$metrics FROM asistencia a INNER JOIN student s ON s.id=a.student_id WHERE $where GROUP BY s.id,s.id_no,s.name,s.nivel,s.grado,s.seccion HAVING late+absent+justified+permission>0",'late DESC,absent DESC,s.name'];
    $base=arq_where($f,$school,true,false);
    return ["SELECT a.fecha,s.nivel,s.grado,s.seccion,COUNT(DISTINCT s.id) marked,SUM($state='Presente') present,SUM($state='Tarde') late,SUM($state IN('Ausente','Ausente Justificada')) absent,COALESCE(c.status,'Pendiente') closure_status,c.closed_at,c.reopened_at FROM asistencia a INNER JOIN student s ON s.id=a.student_id LEFT JOIN attendance_day_closures c ON c.school_id=a.school_id AND c.attendance_date=a.fecha AND c.nivel=s.nivel AND c.grado=s.grado AND c.seccion=s.seccion WHERE $base GROUP BY a.fecha,s.nivel,s.grado,s.seccion,c.status,c.closed_at,c.reopened_at",'a.fecha DESC,s.nivel,s.grado,s.seccion'];
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
