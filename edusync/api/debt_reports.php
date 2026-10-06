<?php
ob_start();include_once __DIR__.'/../includes/session_check.php';include __DIR__.'/../db_connect.php';header('Content-Type: application/json; charset=utf-8');
function drOut(array $d,int $c=200):void{while(ob_get_level())ob_end_clean();http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}function drBind(mysqli_stmt $s,string $t,array &$p):void{if($t==='')return;$a=[$t];foreach($p as &$v)$a[]=&$v;call_user_func_array([$s,'bind_param'],$a);}function drMoney($v):string{return 'S/ '.number_format((float)$v,2);}
$draw=(int)($_GET['draw']??1);$school=(int)($_SESSION['login_school_id']??0);if(!$school||empty($_SESSION['login_id']))drOut(['draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[]],403);$check=$conn->query("SHOW COLUMNS FROM student_ef_list LIKE 'debt_status'");if(!$check||!$check->num_rows)drOut(['draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>'Ejecute sql/fees_module_upgrade.sql.'],409);
$start=max(0,(int)($_GET['start']??0));$length=min(100,max(10,(int)($_GET['length']??25)));$where=['s.school_id=?'];$types='i';$params=[$school];$add=function($sql,$type,$value)use(&$where,&$types,&$params){if($value===''||$value===0)return;$where[]=$sql;$types.=$type;$params[]=$value;};$add('c.academic_year_id=?','i',(int)($_GET['academic_year_id']??0));$add('s.id=?','i',(int)($_GET['student_id']??0));$add('s.nivel=?','s',trim($_GET['nivel']??''));$add('s.grado=?','s',trim($_GET['grado']??''));$add('s.seccion=?','s',trim($_GET['seccion']??''));$add('ef.course_id=?','i',(int)($_GET['concepto']??0));$add('s.status=?','s',trim($_GET['student_status']??''));$add('ef.debt_status=?','s',trim($_GET['debt_status']??'Activa'));
$dateType=trim($_GET['date_type']??'assignment');$from=trim($_GET['start_date']??'');$to=trim($_GET['end_date']??'');if($dateType==='due'){$dateColumn='ef.due_date';if($from){$where[]="$dateColumn>=?";$types.='s';$params[]=$from;}if($to){$where[]="$dateColumn<=?";$types.='s';$params[]=$to;}}elseif($dateType==='payment'){if($from){$where[]="EXISTS(SELECT 1 FROM payments pd WHERE pd.ef_id=ef.id AND COALESCE(pd.payment_status,'Confirmado')='Confirmado' AND DATE(pd.date_created)>=?)";$types.='s';$params[]=$from;}if($to){$where[]="EXISTS(SELECT 1 FROM payments pd WHERE pd.ef_id=ef.id AND COALESCE(pd.payment_status,'Confirmado')='Confirmado' AND DATE(pd.date_created)<=?)";$types.='s';$params[]=$to;}}else{$dateColumn='COALESCE(ef.issue_date,DATE(ef.date_created))';if($from){$where[]="$dateColumn>=?";$types.='s';$params[]=$from;}if($to){$where[]="$dateColumn<=?";$types.='s';$params[]=$to;}}
$search=trim($_GET['search']['value']??'');if($search!==''){$like='%'.$search.'%';$where[]='(s.name LIKE ? OR s.id_no LIKE ? OR c.course LIKE ? OR ef.billing_period LIKE ?)';$types.='ssss';array_push($params,$like,$like,$like,$like);}$whereSql=implode(' AND ',$where);
$base="SELECT ef.id,ef.course_id,ef.total_fee,ef.discounted_amount,ef.debt_status,ef.issue_date,ef.due_date,ef.billing_period,ef.cancellation_reason,s.id student_id,s.id_no,s.name student_name,s.nivel,s.grado,s.seccion,s.status student_status,c.course,ay.year,COALESCE(ef.discounted_amount,ef.total_fee) effective,(SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.ef_id=ef.id AND COALESCE(p.payment_status,'Confirmado')='Confirmado') paid FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id INNER JOIN courses c ON c.id=ef.course_id LEFT JOIN academic_year ay ON ay.id=c.academic_year_id WHERE $whereSql";
$state="CASE WHEN q.discounted_amount IS NOT NULL AND q.total_fee>0 AND q.effective<=0.009 THEN 'Exonerada' WHEN q.effective>0 AND q.paid+0.009>=q.effective THEN 'Pagada' WHEN q.paid>0 THEN 'Parcial' ELSE 'Pendiente' END";$financial=trim($_GET['financial_status']??'Todas');$outerTypes=$types;$outerParams=$params;$outerConditions=[];if($financial==='Pendiente'){$outerConditions[]="q.effective-q.paid>0.009";$outerConditions[]="q.paid<=0.009";}elseif($financial==='Parcial'){$outerConditions[]="q.effective-q.paid>0.009";$outerConditions[]="q.paid>0.009";}elseif($financial==='Exonerada'){$outerConditions[]="q.discounted_amount IS NOT NULL";$outerConditions[]="q.total_fee>0";$outerConditions[]="q.effective<=0.009";}elseif($financial==='Vencidas'){$outerConditions[]="q.debt_status='Activa'";$outerConditions[]="q.effective-q.paid>0.009";$outerConditions[]="(q.due_date IS NULL OR q.due_date='' OR q.due_date<CURDATE())";}elseif($financial==='Por vencer'){$outerConditions[]="q.debt_status='Activa'";$outerConditions[]="q.effective-q.paid>0.009";$outerConditions[]="q.due_date IS NOT NULL AND q.due_date<>'' AND q.due_date>=CURDATE()";}else{$outerConditions[]="(q.effective-q.paid>0.009 OR (q.discounted_amount IS NOT NULL AND q.total_fee>0 AND q.effective<=0.009) OR q.debt_status IN('Suspendida','Anulada'))";}$outerWhere=' WHERE '.implode(' AND ',$outerConditions);
$totalStmt=$conn->prepare('SELECT COUNT(*) total FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id WHERE s.school_id=?');$totalStmt->bind_param('i',$school);$totalStmt->execute();$recordsTotal=(int)$totalStmt->get_result()->fetch_assoc()['total'];$totalStmt->close();$countStmt=$conn->prepare("SELECT COUNT(*) total FROM ($base) q$outerWhere");drBind($countStmt,$outerTypes,$outerParams);$countStmt->execute();$recordsFiltered=(int)$countStmt->get_result()->fetch_assoc()['total'];$countStmt->close();
$summaryStmt=$conn->prepare("SELECT COUNT(*) count,COALESCE(SUM(total_fee),0) original,COALESCE(SUM(GREATEST(0,total_fee-effective)),0) discounts,COALESCE(SUM(effective),0) effective,COALESCE(SUM(LEAST(paid,effective)),0) collected,COALESCE(SUM(GREATEST(0,effective-paid)),0) balance,COALESCE(SUM(CASE WHEN (due_date IS NULL OR due_date='' OR due_date<CURDATE()) AND debt_status='Activa' THEN GREATEST(0,effective-paid) ELSE 0 END),0) overdue FROM ($base) q$outerWhere");drBind($summaryStmt,$outerTypes,$outerParams);$summaryStmt->execute();$summary=$summaryStmt->get_result()->fetch_assoc();$summaryStmt->close();
$view=trim($_GET['view']??'detail');
if(in_array($view,['student','concept','classroom','state'],true)){
    $filtered="SELECT q.*,GREATEST(0,q.effective-q.paid) balance,$state financial_state FROM ($base) q$outerWhere";
    if($view==='student'){
        $groupBase="SELECT student_id,id_no,student_name,nivel,grado,seccion,COUNT(*) debts,COALESCE(SUM(total_fee),0) original_amount,COALESCE(SUM(GREATEST(0,total_fee-effective)),0) discount_amount,COALESCE(SUM(effective),0) effective_amount,COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,COALESCE(SUM(balance),0) balance,COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount FROM ($filtered) z GROUP BY student_id,id_no,student_name,nivel,grado,seccion";
        $groupOrder='balance DESC,student_name ASC';
    }elseif($view==='concept'){
        $groupBase="SELECT course_id,course concept_name,MAX(year) academic_year,COUNT(*) debts,COUNT(DISTINCT student_id) students,COALESCE(SUM(total_fee),0) original_amount,COALESCE(SUM(GREATEST(0,total_fee-effective)),0) discount_amount,COALESCE(SUM(effective),0) effective_amount,COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,COALESCE(SUM(balance),0) balance,COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount FROM ($filtered) z GROUP BY course_id,course";
        $groupOrder='balance DESC,concept_name ASC';
    }elseif($view==='classroom'){
        $groupBase="SELECT nivel,grado,seccion,COUNT(DISTINCT student_id) students,COUNT(*) debts,COALESCE(SUM(effective),0) effective_amount,COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,COALESCE(SUM(balance),0) balance,COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount FROM ($filtered) z GROUP BY nivel,grado,seccion";
        $groupOrder='nivel ASC,grado ASC,seccion ASC';
    }else{
        $groupBase="SELECT financial_state,debt_status,COUNT(*) debts,COUNT(DISTINCT student_id) students,COALESCE(SUM(effective),0) effective_amount,COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,COALESCE(SUM(balance),0) balance,COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount FROM ($filtered) z GROUP BY financial_state,debt_status";
        $groupOrder='balance DESC,financial_state ASC,debt_status ASC';
    }
    $groupCountParams=$outerParams;
    $groupCountStmt=$conn->prepare("SELECT COUNT(*) total FROM ($groupBase) grouped_rows");
    if(!$groupCountStmt)drOut(['draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>$conn->error],500);
    drBind($groupCountStmt,$outerTypes,$groupCountParams);
    $groupCountStmt->execute();
    $groupTotal=(int)$groupCountStmt->get_result()->fetch_assoc()['total'];
    $groupCountStmt->close();

    $groupParams=$outerParams;
    $groupStmt=$conn->prepare("$groupBase ORDER BY $groupOrder LIMIT $start,$length");
    if(!$groupStmt)drOut(['draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>$conn->error],500);
    drBind($groupStmt,$outerTypes,$groupParams);
    $groupStmt->execute();
    $groupResult=$groupStmt->get_result();
    $groupData=[];
    while($row=$groupResult->fetch_assoc())$groupData[]=$row;
    $groupStmt->close();

    drOut(['draw'=>$draw,'recordsTotal'=>$groupTotal,'recordsFiltered'=>$groupTotal,'data'=>$groupData,'summary'=>array_map('floatval',$summary)]);
}
$orderMap=['q.student_name','q.course','q.year','q.total_fee','q.effective','q.paid','balance','q.due_date','financial_state'];$oi=(int)($_GET['order'][0]['column']??0);$od=strtolower($_GET['order'][0]['dir']??'asc')==='desc'?'DESC':'ASC';$order=$orderMap[$oi]??'q.student_name';$sql="SELECT q.*,GREATEST(0,q.effective-q.paid) balance,$state financial_state FROM ($base) q$outerWhere ORDER BY $order $od,q.id DESC LIMIT $start,$length";$stmt=$conn->prepare($sql);if(!$stmt)drOut(['draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'error'=>$conn->error],500);drBind($stmt,$outerTypes,$outerParams);$stmt->execute();$result=$stmt->get_result();$data=[];
while($r=$result->fetch_assoc()){
    $stateValue=$r['financial_state'];
    $badge=['Pagada'=>'success','Exonerada'=>'info','Parcial'=>'warning','Pendiente'=>'primary'][$stateValue]??'secondary';
    $debtState=(string)$r['debt_status'];
    $debtBadge=['Activa'=>'primary','Suspendida'=>'warning','Anulada'=>'dark'][$debtState]??'secondary';
    $discount=max(0,(float)$r['total_fee']-(float)$r['effective']);
    $balance=(float)$r['balance'];
    $due=$r['due_date']?date('d/m/Y',strtotime($r['due_date'])):'Sin fecha';
    $isOverdue=$debtState==='Activa'&&$balance>0.009&&(!$r['due_date']||$r['due_date']<date('Y-m-d'));
    $isUpcoming=$debtState==='Activa'&&$balance>0.009&&!empty($r['due_date'])&&$r['due_date']>=date('Y-m-d');
    $dueState=$isOverdue?'Vencida':($isUpcoming?'Por vencer':'');
    $dueBadge=$isOverdue?'danger':'warning';
    $dueHtml='<span class="'.($isOverdue?'text-danger font-weight-bold':'text-muted').'">'.$due.'</span>'.($dueState?'<div><span class="badge badge-'.$dueBadge.'">'.$dueState.'</span></div>':'');
    $reason=$debtState==='Anulada'&&trim((string)$r['cancellation_reason'])!==''?'<div class="small text-muted mt-1">Motivo: '.htmlspecialchars($r['cancellation_reason']).'</div>':'';
    $stateHtml='<span class="badge badge-'.$badge.'">'.$stateValue.'</span><div class="mt-1"><span class="badge badge-'.$debtBadge.'">'.$debtState.'</span></div>'.$reason;
    $data[]=[
        '<strong>'.htmlspecialchars($r['student_name']).'</strong><div class="small text-muted">'.htmlspecialchars($r['id_no'].' · '.$r['nivel'].' · '.$r['grado'].' '.$r['seccion']).'</div>',
        '<strong>'.htmlspecialchars($r['course']).'</strong><div class="small text-muted">'.htmlspecialchars(($r['year']?:'Sin año').($r['billing_period']?' · '.$r['billing_period']:'')).'</div>',
        htmlspecialchars($r['year']?:'—'),
        '<div class="text-right">'.drMoney($r['total_fee']).'</div>',
        '<div class="text-right font-weight-bold">'.drMoney($r['effective']).($discount>0?'<div class="small text-success">− '.drMoney($discount).'</div>':'').'</div>',
        '<div class="text-right">'.drMoney($r['paid']).'</div>',
        '<div class="text-right font-weight-bold '.($balance>0?'text-danger':'text-success').'">'.drMoney($balance).'</div>',
        $dueHtml,
        $stateHtml
    ];
}
$stmt->close();drOut(['draw'=>$draw,'recordsTotal'=>$recordsTotal,'recordsFiltered'=>$recordsFiltered,'data'=>$data,'summary'=>array_map('floatval',$summary)]);
?>
