<?php
ob_start();
include_once __DIR__ . '/includes/session_check.php';
require_login_modal();
include __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');

function discountOut(array $data, int $code=200): void { if (ob_get_length()) ob_clean(); http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function discountTable($db,string $table): bool { $t=$db->real_escape_string($table); $q=$db->query("SHOW TABLES LIKE '$t'"); return $q && $q->num_rows>0; }
function discountBind($stmt,string $types,array &$params): void {
    $bindings=[$types];
    foreach($params as &$value)$bindings[]=&$value;
    if(!call_user_func_array([$stmt,'bind_param'],$bindings))throw new RuntimeException('No se pudieron enlazar los filtros de la consulta.');
}
function discountAudit($db,int $school,int $discount,int $debt,int $student,string $action,array $details=[]): void {
    $user=(int)($_SESSION['login_id']??0); $ip=$_SERVER['REMOTE_ADDR']??null; $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $s=$db->prepare('INSERT INTO discount_audit_log(school_id,discount_id,debt_id,student_id,user_id,action,details,ip_address) VALUES(?,NULLIF(?,0),NULLIF(?,0),NULLIF(?,0),NULLIF(?,0),?,?,?)');
    if($s){$s->bind_param('iiiiisss',$school,$discount,$debt,$student,$user,$action,$json,$ip);$s->execute();$s->close();}
}
function effectiveValue(string $type,float $value,float $current): float {
    if($type==='Porcentaje') return round($current*(1-min(100,max(0,$value))/100),2);
    if($type==='Monto descontado') return round($current-max(0,$value),2);
    return round($value,2);
}
function syncDiscountReceipts($db,int $school,int $debt,float $final,float $discount,string $reason,int $sourceId=0,int $operationId=0): int {
    $conditions="school_id=$school AND debt_id=$debt";
    if($operationId>0)$conditions.=" AND payment_operation_id=$operationId";
    elseif($sourceId>0)$conditions.=" AND source_id=$sourceId AND source_type IN('Administración','Combinado')";
    else return 0;
    $operations=[];$q=$db->query("SELECT payment_operation_id FROM payment_discount_snapshots WHERE $conditions");while($q&&($row=$q->fetch_assoc()))$operations[]=(int)$row['payment_operation_id'];
    $reasonSafe=$db->real_escape_string($reason);$db->query("UPDATE payment_discount_snapshots SET discount_amount=$discount,final_effective_amount=$final,reason='$reasonSafe' WHERE $conditions");
    foreach(array_unique($operations) as $operation){$db->query("UPDATE payment_concept_snapshots SET effective_amount_snapshot=$final,balance_after=GREATEST($final-paid_before-amount_applied,0) WHERE school_id=$school AND payment_operation_id=$operation AND debt_id=$debt");}
    return count(array_unique($operations));
}
function reissueDiscountReceipt($db,int $school,int $user,int $oldOperation,int $targetDebt,string $source,int $sourceId,string $calculation,float $base,float $final,float $discount,string $reason): array {
    foreach(['payment_operations','payment_counters','payment_operation_methods','payment_concept_snapshots','payment_discount_snapshots'] as $table){
        if(!discountTable($db,$table))throw new Exception('Falta completar la actualización del módulo de pagos para reemitir la boleta.');
    }
    $column=$db->query("SHOW COLUMNS FROM payment_operations LIKE 'corrected_from_id'");
    if(!$column||!$column->num_rows)throw new Exception('Ejecute manualmente sql/payment_corrections_upgrade.sql antes de corregir una boleta.');
    $opStmt=$db->prepare("SELECT * FROM payment_operations WHERE id=? AND school_id=? AND status='Confirmado' FOR UPDATE");
    $opStmt->bind_param('ii',$oldOperation,$school);$opStmt->execute();$old=$opStmt->get_result()->fetch_assoc();$opStmt->close();
    if(!$old)throw new Exception('La boleta relacionada ya no está disponible para corrección.');

    $series='REC-'.date('Y',strtotime($old['payment_date']));
    $counter=$db->prepare('INSERT INTO payment_counters(school_id,series,last_number) VALUES(?,?,0) ON DUPLICATE KEY UPDATE last_number=last_number');
    $counter->bind_param('is',$school,$series);if(!$counter->execute())throw new Exception($counter->error);$counter->close();
    $counter=$db->prepare('SELECT last_number FROM payment_counters WHERE school_id=? AND series=? FOR UPDATE');
    $counter->bind_param('is',$school,$series);$counter->execute();$number=(int)$counter->get_result()->fetch_assoc()['last_number']+1;$counter->close();
    $counter=$db->prepare('UPDATE payment_counters SET last_number=? WHERE school_id=? AND series=?');
    $counter->bind_param('iis',$number,$school,$series);if(!$counter->execute())throw new Exception($counter->error);$counter->close();
    $receipt=$series.'-'.str_pad((string)$number,6,'0',STR_PAD_LEFT);
    $correctionReason='Corrección de descuento'.($reason!==''?': '.$reason:'');
    $student=(int)$old['student_id'];$total=(float)$old['total_amount'];$paymentDate=(string)$old['payment_date'];$remarks=(string)($old['remarks']??'');$cash=(int)($old['cash_session_id']??0);
    $newStmt=$db->prepare("INSERT INTO payment_operations(school_id,student_id,receipt_series,receipt_number,receipt_full,total_amount,payment_date,status,remarks,cash_session_id,created_by,corrected_from_id,correction_reason) VALUES(?,?,?,?,?,?,?,'Confirmado',?,NULLIF(?,0),?,?,?)");
    $newStmt->bind_param('iisisdssiiis',$school,$student,$series,$number,$receipt,$total,$paymentDate,$remarks,$cash,$user,$oldOperation,$correctionReason);
    if(!$newStmt->execute())throw new Exception($newStmt->error);$newOperation=(int)$newStmt->insert_id;$newStmt->close();

    $firstMethod=(int)(($db->query("SELECT payment_method_id FROM payment_operation_methods WHERE operation_id=$oldOperation ORDER BY id LIMIT 1")->fetch_assoc())['payment_method_id']??0);
    $copyPayments=$db->prepare("INSERT INTO payments(operation_id,ef_id,receipt_no,amount,remarks,date_created,payment_method_id,payment_status,created_by) SELECT ?,ef_id,?,amount,remarks,date_created,COALESCE(NULLIF(payment_method_id,0),NULLIF(?,0)),'Confirmado',? FROM payments WHERE operation_id=? AND COALESCE(payment_status,'Confirmado')='Confirmado'");
    $copyPayments->bind_param('isiii',$newOperation,$receipt,$firstMethod,$user,$oldOperation);if(!$copyPayments->execute()||$copyPayments->affected_rows<1)throw new Exception('La boleta no contiene pagos enlazados que puedan reemitirse.');$copyPayments->close();
    if(!$db->query("INSERT INTO payment_operation_methods(operation_id,payment_method_id,amount,reference_number,bank_name,operation_date) SELECT $newOperation,payment_method_id,amount,reference_number,bank_name,operation_date FROM payment_operation_methods WHERE operation_id=$oldOperation"))throw new Exception($db->error);
    if(!$db->query("INSERT INTO payment_concept_snapshots(school_id,payment_operation_id,debt_id,course_id,concept_name,level_name,academic_year_label,original_amount_snapshot,effective_amount_snapshot,paid_before,amount_applied,balance_after) SELECT school_id,$newOperation,debt_id,course_id,concept_name,level_name,academic_year_label,original_amount_snapshot,effective_amount_snapshot,paid_before,amount_applied,balance_after FROM payment_concept_snapshots WHERE payment_operation_id=$oldOperation"))throw new Exception($db->error);
    if(!$db->query("INSERT INTO payment_discount_snapshots(school_id,payment_operation_id,debt_id,source_type,source_id,original_amount,previous_effective_amount,discount_amount,final_effective_amount,reason) SELECT school_id,$newOperation,debt_id,source_type,source_id,original_amount,previous_effective_amount,discount_amount,final_effective_amount,reason FROM payment_discount_snapshots WHERE payment_operation_id=$oldOperation"))throw new Exception($db->error);

    $receiptFinal=$final;$paymentDiscountId=0;
    if($source==='payment'){
        $oldDiscount=$db->prepare("SELECT * FROM debt_discounts WHERE id=? AND school_id=? AND payment_operation_id=? AND status='Aplicado' FOR UPDATE");
        $oldDiscount->bind_param('iii',$sourceId,$school,$oldOperation);$oldDiscount->execute();$dd=$oldDiscount->get_result()->fetch_assoc();$oldDiscount->close();if(!$dd)throw new Exception('No se encontró el descuento aplicado durante el pago.');
        $type=$calculation==='Porcentaje'?'percentage':($calculation==='Monto final'?'final_amount':'fixed');$previousDiscounted=$dd['previous_discounted_amount'];
        $insert=$db->prepare("INSERT INTO debt_discounts(school_id,debt_id,payment_operation_id,discount_type,discount_value,discount_amount,previous_effective_amount,previous_discounted_amount,final_effective_amount,reason,status,authorized_by) VALUES(?,?,?,?,?,?,?,?,?,?,'Aplicado',?)");
        $value=$type==='percentage'?round((1-$final/$base)*100,2):($type==='final_amount'?$final:$discount);
        $insert->bind_param('iiisdddddsi',$school,$targetDebt,$newOperation,$type,$value,$discount,$base,$previousDiscounted,$final,$reason,$user);if(!$insert->execute())throw new Exception($insert->error);$paymentDiscountId=(int)$insert->insert_id;$insert->close();
        $reverse=$db->prepare("UPDATE debt_discounts SET status='Revertido',reversed_at=NOW(),reversed_by=?,reversal_reason=? WHERE id=? AND school_id=?");
        $reverse->bind_param('isii',$user,$correctionReason,$sourceId,$school);if(!$reverse->execute())throw new Exception($reverse->error);$reverse->close();
    }else{
        $extraRow=$db->query("SELECT * FROM debt_discounts WHERE payment_operation_id=$oldOperation AND debt_id=$targetDebt AND school_id=$school AND status='Aplicado' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $extra=(float)($extraRow['discount_amount']??0);$receiptFinal=max(0,round($final-$extra,2));
        if($extraRow){
            $extraType=(string)$extraRow['discount_type'];$extraValue=(float)$extraRow['discount_value'];$extraBase=$final;$extraPrevious=$final;$extraReason=(string)$extraRow['reason'];
            $insert=$db->prepare("INSERT INTO debt_discounts(school_id,debt_id,payment_operation_id,discount_type,discount_value,discount_amount,previous_effective_amount,previous_discounted_amount,final_effective_amount,reason,status,authorized_by) VALUES(?,?,?,?,?,?,?,?,?,?,'Aplicado',?)");
            $insert->bind_param('iiisdddddsi',$school,$targetDebt,$newOperation,$extraType,$extraValue,$extra,$extraBase,$extraPrevious,$receiptFinal,$extraReason,$user);if(!$insert->execute())throw new Exception($insert->error);$insert->close();
            $extraId=(int)$extraRow['id'];$reverse=$db->prepare("UPDATE debt_discounts SET status='Revertido',reversed_at=NOW(),reversed_by=?,reversal_reason=? WHERE id=? AND school_id=?");
            $reverse->bind_param('isii',$user,$correctionReason,$extraId,$school);if(!$reverse->execute())throw new Exception($reverse->error);$reverse->close();
        }
    }
    $snapshotDiscount=max(0,round($base-$receiptFinal,2));$reasonSafe=$db->real_escape_string($reason);$snapshotSource=$source==='payment'?'Pago':($extraRow?'Combinado':'Administración');$snapshotSourceId=$source==='payment'?$paymentDiscountId:$sourceId;
    if(!$db->query("UPDATE payment_discount_snapshots SET source_type='$snapshotSource',source_id=$snapshotSourceId,previous_effective_amount=$base,discount_amount=$snapshotDiscount,final_effective_amount=$receiptFinal,reason='$reasonSafe' WHERE payment_operation_id=$newOperation AND debt_id=$targetDebt AND school_id=$school"))throw new Exception($db->error);
    $amountApplied=(float)(($db->query("SELECT COALESCE(SUM(amount),0) amount FROM payments WHERE operation_id=$newOperation AND ef_id=$targetDebt")->fetch_assoc())['amount']??0);
    // La nueva boleta conserva el punto histórico de la original: los pagos
    // posteriores no deben alterar el saldo que existía al emitirla.
    $paidBefore=(float)(($db->query("SELECT COALESCE(paid_before,0) amount FROM payment_concept_snapshots WHERE payment_operation_id=$oldOperation AND debt_id=$targetDebt LIMIT 1")->fetch_assoc())['amount']??0);
    $balance=max(0,round($receiptFinal-$paidBefore-$amountApplied,2));
    $concept=$db->prepare('UPDATE payment_concept_snapshots SET effective_amount_snapshot=?,paid_before=?,amount_applied=?,balance_after=? WHERE school_id=? AND payment_operation_id=? AND debt_id=?');
    $concept->bind_param('ddddiii',$receiptFinal,$paidBefore,$amountApplied,$balance,$school,$newOperation,$targetDebt);if(!$concept->execute())throw new Exception($concept->error);$concept->close();
    $debtUpdate=$db->prepare('UPDATE student_ef_list SET discounted_amount=? WHERE id=?');$debtUpdate->bind_param('di',$receiptFinal,$targetDebt);if(!$debtUpdate->execute())throw new Exception($debtUpdate->error);$debtUpdate->close();
    $oldUpdate=$db->prepare("UPDATE payment_operations SET status='Anulado',cancelled_at=NOW(),cancelled_by=?,cancellation_reason=?,corrected_by_id=? WHERE id=? AND school_id=?");
    $oldUpdate->bind_param('isiii',$user,$correctionReason,$newOperation,$oldOperation,$school);if(!$oldUpdate->execute())throw new Exception($oldUpdate->error);$oldUpdate->close();
    $oldPayments=$db->prepare("UPDATE payments SET payment_status='Anulado' WHERE operation_id=?");$oldPayments->bind_param('i',$oldOperation);if(!$oldPayments->execute())throw new Exception($oldPayments->error);$oldPayments->close();
    if(discountTable($db,'payment_audit_log')){
        $ip=$_SERVER['REMOTE_ADDR']??null;$newDetails=json_encode(['receipt'=>$receipt,'corrected_from_id'=>$oldOperation,'reason'=>$correctionReason],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$oldDetails=json_encode(['replacement_operation_id'=>$newOperation,'reason'=>$correctionReason],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $audit=$db->prepare('INSERT INTO payment_audit_log(school_id,operation_id,payment_id,user_id,action,details,ip_address) VALUES(?,?,NULL,?,?,?,?)');
        $newAction='payment_corrected';$audit->bind_param('iiisss',$school,$newOperation,$user,$newAction,$newDetails,$ip);if(!$audit->execute())throw new Exception($audit->error);$oldAction='payment_replaced';$audit->bind_param('iiisss',$school,$oldOperation,$user,$oldAction,$oldDetails,$ip);if(!$audit->execute())throw new Exception($audit->error);$audit->close();
    }
    return ['old_operation_id'=>$oldOperation,'operation_id'=>$newOperation,'receipt'=>$receipt,'balance_after'=>$balance,'final_effective'=>$receiptFinal];
}

$school=(int)($_SESSION['login_school_id']??0); $user=(int)($_SESSION['login_id']??0); $type=(int)($_SESSION['login_type']??0); $action=$_GET['action']??'';
if(!$school||!$user||$type!==1) discountOut(['status'=>0,'message'=>'No tiene permisos para gestionar descuentos.'],403);
$requiredDiscountTables=['discount_benefits','discount_audit_log','debt_discounts','payment_discount_snapshots'];
$missingDiscountTables=[];
foreach($requiredDiscountTables as $requiredTable){if(!discountTable($conn,$requiredTable))$missingDiscountTables[]=$requiredTable;}
if($missingDiscountTables){
    discountOut([
        'status'=>0,
        'migration_required'=>true,
        'missing_tables'=>$missingDiscountTables,
        'message'=>'Faltan tablas del módulo de descuentos: '.implode(', ',$missingDiscountTables).'. Ejecute manualmente sql/discounts_module_upgrade.sql, sql/payment_discounts_upgrade.sql y sql/unified_discount_corrections_upgrade.sql, en ese orden.'
    ],409);
}
if(in_array($action,['save','update','update_payment_discount','revoke','bulk_apply','bulk_revoke'],true)){ $a=(string)($_POST['csrf_token']??'');$b=(string)($_SESSION['csrf_token']??'');if(!$a||!$b||!hash_equals($b,$a)) discountOut(['status'=>0,'message'=>'La sesión de seguridad venció. Recargue la página.'],403); }

if($action==='detail'){
    $id=(int)($_GET['id']??0);$source=($_GET['source']??'admin')==='payment'?'payment':'admin';
    if($source==='payment'){$s=$conn->prepare("SELECT dd.id,dd.debt_id,ef.student_id,c.id course_id,c.academic_year_id,s.id_no,s.name student_name,c.course,ay.year,'Descuento' benefit_type,CASE dd.discount_type WHEN 'percentage' THEN 'Porcentaje' WHEN 'final_amount' THEN 'Monto final' ELSE 'Monto descontado' END calculation_type,dd.discount_value value,ef.total_fee original_amount,dd.previous_effective_amount,dd.final_effective_amount,dd.discount_amount,NULL valid_from,NULL valid_until,dd.reason,'' notes,dd.payment_operation_id,COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.ef_id=dd.debt_id AND COALESCE(p.payment_status,'Confirmado')='Confirmado'),0) paid FROM debt_discounts dd INNER JOIN student_ef_list ef ON ef.id=dd.debt_id INNER JOIN student s ON s.id=ef.student_id INNER JOIN courses c ON c.id=ef.course_id LEFT JOIN academic_year ay ON ay.id=c.academic_year_id WHERE dd.id=? AND dd.school_id=? AND dd.status='Aplicado' LIMIT 1");}else{$s=$conn->prepare("SELECT db.*,s.id_no,s.name student_name,c.course,ay.year,(SELECT pds.payment_operation_id FROM payment_discount_snapshots pds INNER JOIN payment_operations po ON po.id=pds.payment_operation_id WHERE pds.school_id=db.school_id AND pds.debt_id=db.debt_id AND pds.source_id=db.id AND pds.source_type IN('Administración','Combinado') AND po.status='Confirmado' ORDER BY po.id DESC LIMIT 1) payment_operation_id,COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.ef_id=db.debt_id AND COALESCE(p.payment_status,'Confirmado')='Confirmado'),0) paid FROM discount_benefits db INNER JOIN student s ON s.id=db.student_id LEFT JOIN courses c ON c.id=db.course_id LEFT JOIN academic_year ay ON ay.id=db.academic_year_id WHERE db.id=? AND db.school_id=? AND db.status='Aplicado' LIMIT 1");}
    $s->bind_param('ii',$id,$school);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();if(!$row)discountOut(['status'=>0,'message'=>'El descuento no existe o ya no está activo.'],404);
    discountOut(['status'=>1,'data'=>$row]);
}

if($action==='bootstrap'){
    // Al abrir el módulo se cierran beneficios vencidos y se restaura el monto anterior.
    $expired=$conn->prepare("SELECT id,debt_id,student_id,previous_effective_amount FROM discount_benefits WHERE school_id=? AND status='Aplicado' AND valid_until IS NOT NULL AND valid_until<CURDATE()");$expired->bind_param('i',$school);$expired->execute();$expiredRows=$expired->get_result()->fetch_all(MYSQLI_ASSOC);$expired->close();
    foreach($expiredRows as $item){$conn->begin_transaction();try{$id=(int)$item['id'];$debt=(int)$item['debt_id'];$student=(int)$item['student_id'];$restore=(float)$item['previous_effective_amount'];$u=$conn->prepare('UPDATE student_ef_list SET discounted_amount=IF(ABS(total_fee-?)<0.01,NULL,?) WHERE id=?');$u->bind_param('ddi',$restore,$restore,$debt);$u->execute();$u->close();$u=$conn->prepare("UPDATE discount_benefits SET status='Vencido' WHERE id=? AND school_id=? AND status='Aplicado'");$u->bind_param('ii',$id,$school);$u->execute();$changed=$u->affected_rows;$u->close();$conn->commit();if($changed)discountAudit($conn,$school,$id,$debt,$student,'discount_expired',['restored_amount'=>$restore]);}catch(Throwable $e){$conn->rollback();}}
    $years=[];$q=$conn->prepare('SELECT id,year,is_active FROM academic_year WHERE school_id=? ORDER BY year DESC');$q->bind_param('i',$school);$q->execute();$r=$q->get_result();while($x=$r->fetch_assoc())$years[]=$x;$q->close();
    $stats=$conn->query("SELECT COUNT(*) total,COALESCE(SUM(discount_amount),0) amount,COUNT(DISTINCT student_id) students,SUM(status='Aplicado') active FROM (SELECT student_id,discount_amount,status FROM discount_benefits WHERE school_id=$school UNION ALL SELECT ef.student_id,dd.discount_amount,IF(dd.status='Aplicado','Aplicado','Revertido') status FROM debt_discounts dd INNER JOIN student_ef_list ef ON ef.id=dd.debt_id WHERE dd.school_id=$school) unified_discounts")->fetch_assoc();
    discountOut(['status'=>1,'years'=>$years,'stats'=>$stats]);
}
if($action==='students'){
    $items=[];
    $stmt=$conn->prepare("SELECT id,id_no,name,nivel,grado,seccion,status FROM student WHERE school_id=? AND status='Activo' ORDER BY name ASC");
    $stmt->bind_param('i',$school);$stmt->execute();$result=$stmt->get_result();while($row=$result->fetch_assoc())$items[]=$row;$stmt->close();
    discountOut(['status'=>1,'data'=>$items]);
}
if($action==='debts'){
    $student=(int)($_GET['student_id']??0);$items=[];
    if($student<=0) discountOut(['status'=>0,'message'=>'Seleccione un estudiante para consultar sus deudas.'],422);
    $owner=$conn->prepare('SELECT id FROM student WHERE id=? AND school_id=? LIMIT 1');$owner->bind_param('ii',$student,$school);$owner->execute();$belongs=$owner->get_result()->fetch_assoc();$owner->close();if(!$belongs)discountOut(['status'=>0,'message'=>'El estudiante no pertenece a la institución.'],404);
    $s=$conn->prepare("SELECT ef.id debt_id,ef.total_fee,ef.discounted_amount,c.course,ay.year,s.name,
      COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.ef_id=ef.id AND COALESCE(p.payment_status,'Confirmado')='Confirmado'),0) paid
      FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id INNER JOIN courses c ON c.id=ef.course_id LEFT JOIN academic_year ay ON ay.id=c.academic_year_id
      WHERE s.school_id=? AND s.id=? AND COALESCE(ef.debt_status,'Activa')='Activa' ORDER BY ay.year DESC,c.course");
    $s->bind_param('ii',$school,$student);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc()){ $x['effective']=$x['discounted_amount']!==null?(float)$x['discounted_amount']:(float)$x['total_fee'];$x['available']=max(0,$x['effective']-(float)$x['paid']);if($x['available']>0.009)$items[]=$x; }$s->close();discountOut(['status'=>1,'data'=>$items]);
}
if($action==='list'){
    $draw=(int)($_GET['draw']??1);
    try{
        $start=max(0,(int)($_GET['start']??0));$length=min(100,max(10,(int)($_GET['length']??10)));$search=trim($_GET['search']['value']??'');$status=trim($_GET['status']??'');$year=(int)($_GET['year_id']??0);$origin=trim($_GET['origin']??'');
        $union="SELECT
            CONVERT(CONCAT('A-',db.id) USING utf8mb4) COLLATE utf8mb4_general_ci record_key,
            db.id record_id,
            CONVERT('Administración' USING utf8mb4) COLLATE utf8mb4_general_ci origin,
            (SELECT pds.payment_operation_id FROM payment_discount_snapshots pds WHERE pds.source_type IN('Administración','Combinado') AND pds.source_id=db.id ORDER BY pds.id DESC LIMIT 1) payment_operation_id,
            db.debt_id,db.academic_year_id,db.student_id,
            CONVERT(db.benefit_type USING utf8mb4) COLLATE utf8mb4_general_ci benefit_type,
            CONVERT(db.calculation_type USING utf8mb4) COLLATE utf8mb4_general_ci calculation_type,
            db.value,db.previous_effective_amount,db.final_effective_amount,db.discount_amount,
            db.valid_from,db.valid_until,
            CONVERT(db.reason USING utf8mb4) COLLATE utf8mb4_general_ci reason,
            CONVERT(db.status USING utf8mb4) COLLATE utf8mb4_general_ci status,
            db.created_at,
            CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_general_ci student_name,
            CONVERT(s.id_no USING utf8mb4) COLLATE utf8mb4_general_ci id_no,
            CONVERT(COALESCE(c.course,'') USING utf8mb4) COLLATE utf8mb4_general_ci course,
            CONVERT(COALESCE(ay.year,'') USING utf8mb4) COLLATE utf8mb4_general_ci year
          FROM discount_benefits db
          INNER JOIN student s ON s.id=db.student_id
          LEFT JOIN courses c ON c.id=db.course_id
          LEFT JOIN academic_year ay ON ay.id=db.academic_year_id
          WHERE db.school_id=?
          UNION ALL
          SELECT
            CONVERT(CONCAT('P-',dd.id) USING utf8mb4) COLLATE utf8mb4_general_ci,
            dd.id,
            CONVERT('Durante el pago' USING utf8mb4) COLLATE utf8mb4_general_ci,
            dd.payment_operation_id,dd.debt_id,c.academic_year_id,ef.student_id,
            CONVERT('Descuento en pago' USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(CASE dd.discount_type WHEN 'percentage' THEN 'Porcentaje' WHEN 'final_amount' THEN 'Monto final' ELSE 'Monto descontado' END USING utf8mb4) COLLATE utf8mb4_general_ci,
            dd.discount_value,dd.previous_effective_amount,dd.final_effective_amount,dd.discount_amount,
            NULL,NULL,
            CONVERT(dd.reason USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(IF(dd.status='Aplicado','Aplicado','Revertido') USING utf8mb4) COLLATE utf8mb4_general_ci,
            dd.created_at,
            CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(s.id_no USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(COALESCE(c.course,'') USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(COALESCE(ay.year,'') USING utf8mb4) COLLATE utf8mb4_general_ci
          FROM debt_discounts dd
          INNER JOIN student_ef_list ef ON ef.id=dd.debt_id
          INNER JOIN student s ON s.id=ef.student_id
          LEFT JOIN courses c ON c.id=ef.course_id
          LEFT JOIN academic_year ay ON ay.id=c.academic_year_id
          WHERE dd.school_id=?";
        $where='1=1';$types='ii';$params=[$school,$school];if($status!==''){$where.=' AND u.status=?';$types.='s';$params[]=$status;}if($year){$where.=' AND u.academic_year_id=?';$types.='i';$params[]=$year;}if($origin!==''){$where.=' AND u.origin=?';$types.='s';$params[]=$origin;}if($search!==''){$where.=' AND (u.student_name LIKE ? OR u.id_no LIKE ? OR u.course LIKE ? OR u.reason LIKE ?)';$like="%$search%";$types.='ssss';array_push($params,$like,$like,$like,$like);}
        $count=$conn->prepare("SELECT COUNT(*) n FROM ($union) u WHERE $where");
        if(!$count)throw new RuntimeException($conn->error);
        discountBind($count,$types,$params);if(!$count->execute())throw new RuntimeException($count->error);$n=(int)$count->get_result()->fetch_assoc()['n'];$count->close();
        $st=$conn->prepare("SELECT u.* FROM ($union) u WHERE $where ORDER BY u.created_at DESC LIMIT $start,$length");
        if(!$st)throw new RuntimeException($conn->error);
        discountBind($st,$types,$params);if(!$st->execute())throw new RuntimeException($st->error);$r=$st->get_result();$data=[];while($x=$r->fetch_assoc())$data[]=$x;$st->close();discountOut(['draw'=>$draw,'recordsTotal'=>$n,'recordsFiltered'=>$n,'data'=>$data]);
    }catch(Throwable $e){
        error_log('discounts_api list: '.$e->getMessage());
        discountOut(['status'=>0,'draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[],'message'=>'No se pudo consultar la tabla de descuentos. Detalle: '.$e->getMessage()],500);
    }
}
if($action==='save'){
    $debt=(int)($_POST['debt_id']??0);$benefit=trim($_POST['benefit_type']??'Descuento');$calc=trim($_POST['calculation_type']??'Monto final');$value=round((float)($_POST['value']??0),2);$reason=trim($_POST['reason']??'');$notes=trim($_POST['notes']??'');$from=trim($_POST['valid_from']??'')?:null;$until=trim($_POST['valid_until']??'')?:null;
    if(!$debt||$value<0||!in_array($calc,['Monto final','Monto descontado','Porcentaje'],true)) discountOut(['status'=>0,'message'=>'Complete correctamente la deuda y el cálculo.']);if($from&&$until&&$until<$from)discountOut(['status'=>0,'message'=>'La fecha final no puede ser anterior a la inicial.']);if($from&&$from>date('Y-m-d'))discountOut(['status'=>0,'message'=>'La vigencia no puede comenzar en una fecha futura.']);if($until&&$until<date('Y-m-d'))discountOut(['status'=>0,'message'=>'La vigencia del beneficio ya terminó.']);
    $conn->begin_transaction();try{
      $s=$conn->prepare("SELECT ef.*,s.school_id,c.academic_year_id FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id INNER JOIN courses c ON c.id=ef.course_id WHERE ef.id=? AND s.school_id=? FOR UPDATE");$s->bind_param('ii',$debt,$school);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();if(!$d)throw new Exception('La deuda no pertenece a la institución.');
      $q=$conn->query("SELECT COALESCE(SUM(amount),0) paid FROM payments WHERE ef_id=$debt AND COALESCE(payment_status,'Confirmado')='Confirmado'");$paid=(float)$q->fetch_assoc()['paid'];$current=$d['discounted_amount']!==null?(float)$d['discounted_amount']:(float)$d['total_fee'];$final=effectiveValue($calc,$value,$current);
      if($final<$paid-.009)throw new Exception('El monto final no puede ser menor que S/ '.number_format($paid,2).', porque ese importe ya fue pagado.');if($final>=$current-.009)throw new Exception('El beneficio debe reducir el monto exigible actual.');
      $active=$conn->query("SELECT id FROM discount_benefits WHERE debt_id=$debt AND status='Aplicado' LIMIT 1");if($active&&$active->num_rows)throw new Exception('Esta deuda ya tiene un beneficio activo. Revóquelo antes de aplicar otro.');
      $discount=round($current-$final,2);$course=(int)$d['course_id'];$student=(int)$d['student_id'];$year=(int)$d['academic_year_id'];
      $st=$conn->prepare("INSERT INTO discount_benefits(school_id,academic_year_id,student_id,debt_id,course_id,benefit_type,calculation_type,value,original_amount,paid_before,previous_effective_amount,final_effective_amount,discount_amount,scope_type,valid_from,valid_until,reason,notes,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'Deuda',?,?,?,?, 'Aplicado',?)");
      $original=(float)$d['total_fee'];$st->bind_param('iiiiissddddddssssi',$school,$year,$student,$debt,$course,$benefit,$calc,$value,$original,$paid,$current,$final,$discount,$from,$until,$reason,$notes,$user);if(!$st->execute())throw new Exception($st->error);$id=(int)$conn->insert_id;$st->close();
      $u=$conn->prepare('UPDATE student_ef_list SET discounted_amount=? WHERE id=?');$u->bind_param('di',$final,$debt);if(!$u->execute())throw new Exception($u->error);$u->close();$conn->commit();discountAudit($conn,$school,$id,$debt,$student,'discount_applied',['paid_before'=>$paid,'previous'=>$current,'final'=>$final]);discountOut(['status'=>1,'message'=>'Beneficio aplicado correctamente. El saldo pagado fue respetado.']);
    }catch(Throwable $e){$conn->rollback();discountOut(['status'=>0,'message'=>$e->getMessage()]);}
}
if($action==='update'){
    $id=(int)($_POST['id']??0);$benefit=trim($_POST['benefit_type']??'Descuento');$calc=trim($_POST['calculation_type']??'Monto final');$value=round((float)($_POST['value']??0),2);$reason=trim($_POST['reason']??'');$notes=trim($_POST['notes']??'');$from=trim($_POST['valid_from']??'')?:null;$until=trim($_POST['valid_until']??'')?:null;
    if(!$id||$value<0||!in_array($calc,['Monto final','Monto descontado','Porcentaje'],true))discountOut(['status'=>0,'message'=>'Complete correctamente el cálculo.']);if($from&&$until&&$until<$from)discountOut(['status'=>0,'message'=>'La fecha final no puede ser anterior a la inicial.']);
    $conn->begin_transaction();try{$s=$conn->prepare("SELECT db.*,ef.total_fee FROM discount_benefits db INNER JOIN student_ef_list ef ON ef.id=db.debt_id WHERE db.id=? AND db.school_id=? AND db.status='Aplicado' FOR UPDATE");$s->bind_param('ii',$id,$school);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();if(!$d)throw new Exception('El descuento no existe o ya no está activo.');$debt=(int)$d['debt_id'];$linked=(int)(($conn->query("SELECT pds.payment_operation_id FROM payment_discount_snapshots pds INNER JOIN payment_operations po ON po.id=pds.payment_operation_id WHERE pds.school_id=$school AND pds.debt_id=$debt AND pds.source_id=$id AND pds.source_type IN('Administración','Combinado') AND po.status='Confirmado' ORDER BY po.id DESC LIMIT 1")->fetch_assoc())['payment_operation_id']??0);$paid=(float)$conn->query("SELECT COALESCE(SUM(p.amount),0) paid FROM payments p INNER JOIN payment_operations po ON po.id=p.operation_id WHERE p.ef_id=$debt AND p.payment_status='Confirmado' AND po.status='Confirmado'".($linked?" AND po.id<>$linked":''))->fetch_assoc()['paid'];$base=(float)$d['previous_effective_amount'];$final=effectiveValue($calc,$value,$base);if($final<$paid-.009)throw new Exception('El monto final no puede ser menor que S/ '.number_format($paid,2).', porque ese importe ya fue pagado antes de la boleta que será corregida.');if($final>=$base-.009)throw new Exception('El beneficio debe reducir el monto exigible anterior.');$discount=round($base-$final,2);$st=$conn->prepare('UPDATE discount_benefits SET benefit_type=?,calculation_type=?,value=?,final_effective_amount=?,discount_amount=?,valid_from=?,valid_until=?,reason=?,notes=? WHERE id=? AND school_id=?');$st->bind_param('ssdddssssii',$benefit,$calc,$value,$final,$discount,$from,$until,$reason,$notes,$id,$school);if(!$st->execute())throw new Exception($st->error);$st->close();$replacement=null;if($linked){$replacement=reissueDiscountReceipt($conn,$school,$user,$linked,$debt,'admin',$id,$calc,$base,$final,$discount,$reason);}else{$u=$conn->prepare('UPDATE student_ef_list SET discounted_amount=? WHERE id=?');$u->bind_param('di',$final,$debt);if(!$u->execute())throw new Exception($u->error);$u->close();}$conn->commit();discountAudit($conn,$school,$id,$debt,(int)$d['student_id'],$replacement?'discount_updated_with_receipt_reissue':'discount_updated',['previous_final'=>(float)$d['final_effective_amount'],'new_final'=>$final,'replacement'=>$replacement]);discountOut(['status'=>1,'message'=>$replacement?'Descuento corregido. La boleta anterior fue anulada y se emitió '.$replacement['receipt'].'.':'Descuento actualizado correctamente.','operation_id'=>$replacement['operation_id']??null,'receipt'=>$replacement['receipt']??null]);}catch(Throwable $e){$conn->rollback();discountOut(['status'=>0,'message'=>$e->getMessage()]);}
}
if($action==='update_payment_discount'){
    $id=(int)($_POST['id']??0);$calc=trim($_POST['calculation_type']??'Monto final');$value=round((float)($_POST['value']??0),2);$reason=trim($_POST['reason']??'');if(!$id||$value<0||!in_array($calc,['Monto final','Monto descontado','Porcentaje'],true))discountOut(['status'=>0,'message'=>'Complete correctamente el cálculo.']);
    $conn->begin_transaction();try{$s=$conn->prepare("SELECT dd.*,ef.student_id FROM debt_discounts dd INNER JOIN student_ef_list ef ON ef.id=dd.debt_id WHERE dd.id=? AND dd.school_id=? AND dd.status='Aplicado' FOR UPDATE");$s->bind_param('ii',$id,$school);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();if(!$d)throw new Exception('El descuento no existe o ya no está activo.');$debt=(int)$d['debt_id'];$operation=(int)$d['payment_operation_id'];$paid=(float)$conn->query("SELECT COALESCE(SUM(p.amount),0) paid FROM payments p INNER JOIN payment_operations po ON po.id=p.operation_id WHERE p.ef_id=$debt AND p.payment_status='Confirmado' AND po.status='Confirmado' AND po.id<>$operation")->fetch_assoc()['paid'];$base=(float)$d['previous_effective_amount'];$final=effectiveValue($calc,$value,$base);if($final<$paid-.009)throw new Exception('El monto final no puede ser menor que S/ '.number_format($paid,2).', porque ese importe ya fue pagado antes de la boleta que será corregida.');if($final>=$base-.009)throw new Exception('El descuento debe reducir el monto exigible anterior.');$discount=round($base-$final,2);$replacement=reissueDiscountReceipt($conn,$school,$user,$operation,$debt,'payment',$id,$calc,$base,$final,$discount,$reason);$conn->commit();discountAudit($conn,$school,0,$debt,(int)$d['student_id'],'payment_discount_corrected',['payment_discount_id'=>$id,'original_operation_id'=>$operation,'replacement_operation_id'=>$replacement['operation_id'],'previous_final'=>(float)$d['final_effective_amount'],'new_final'=>$final]);discountOut(['status'=>1,'message'=>'Descuento corregido. La boleta anterior fue anulada y se emitió '.$replacement['receipt'].'.','operation_id'=>$replacement['operation_id'],'receipt'=>$replacement['receipt']]);}catch(Throwable $e){$conn->rollback();discountOut(['status'=>0,'message'=>$e->getMessage()]);}
}
if($action==='revoke'){
    $id=(int)($_POST['id']??0);$reason=trim($_POST['reason']??'');if(!$id||strlen($reason)<3)discountOut(['status'=>0,'message'=>'Indique el motivo de la revocación.']);$conn->begin_transaction();try{
      $s=$conn->prepare("SELECT * FROM discount_benefits WHERE id=? AND school_id=? AND status='Aplicado' FOR UPDATE");$s->bind_param('ii',$id,$school);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();if(!$d)throw new Exception('El beneficio ya no está activo o no existe.');$affected=$conn->prepare("SELECT payment_operation_id FROM payment_discount_snapshots WHERE source_type IN('Administración','Combinado') AND source_id=? LIMIT 1");$affected->bind_param('i',$id);$affected->execute();$receiptAffected=$affected->get_result()->fetch_assoc();$affected->close();if($receiptAffected)throw new Exception('Este descuento ya afectó un recibo. Debe corregirse desde el botón amarillo para conservar la trazabilidad.');$debt=(int)$d['debt_id'];$student=(int)$d['student_id'];$restore=(float)$d['previous_effective_amount'];
      $u=$conn->prepare('UPDATE student_ef_list SET discounted_amount=IF(ABS(total_fee-?)<0.01,NULL,?) WHERE id=?');$u->bind_param('ddi',$restore,$restore,$debt);if(!$u->execute())throw new Exception($u->error);$u->close();$st=$conn->prepare("UPDATE discount_benefits SET status='Revocado',revoked_at=NOW(),revoked_by=?,revocation_reason=? WHERE id=?");$st->bind_param('isi',$user,$reason,$id);if(!$st->execute())throw new Exception($st->error);$st->close();$conn->commit();discountAudit($conn,$school,$id,$debt,$student,'discount_revoked',['reason'=>$reason,'restored_amount'=>$restore]);discountOut(['status'=>1,'message'=>'Beneficio revocado y monto anterior restaurado.']);
    }catch(Throwable $e){$conn->rollback();discountOut(['status'=>0,'message'=>$e->getMessage()]);}
}
if($action==='bulk_apply'){
    $ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_POST['ids']??'')))))); $calc=trim($_POST['calculation_type']??'Porcentaje'); $value=round((float)($_POST['value']??0),2); $reason=trim($_POST['reason']??'');
    if(!$ids||count($ids)>200||!in_array($calc,['Porcentaje','Monto descontado'],true)||$value<=0||strlen($reason)<3) discountOut(['status'=>0,'message'=>'Seleccione hasta 200 deudas e indique un beneficio y motivo válidos.']);
    $applied=0;$skipped=[];
    foreach($ids as $debt){$conn->begin_transaction();try{
        $s=$conn->prepare("SELECT ef.*,s.school_id,c.academic_year_id FROM student_ef_list ef INNER JOIN student s ON s.id=ef.student_id INNER JOIN courses c ON c.id=ef.course_id WHERE ef.id=? AND s.school_id=? FOR UPDATE");$s->bind_param('ii',$debt,$school);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();if(!$d)throw new Exception('No pertenece a la institución');
        $active=$conn->query("SELECT id FROM discount_benefits WHERE debt_id=$debt AND status='Aplicado' LIMIT 1");if($active&&$active->num_rows)throw new Exception('Ya tiene beneficio');
        $paid=(float)$conn->query("SELECT COALESCE(SUM(amount),0) paid FROM payments WHERE ef_id=$debt AND COALESCE(payment_status,'Confirmado')='Confirmado'")->fetch_assoc()['paid'];$current=$d['discounted_amount']!==null?(float)$d['discounted_amount']:(float)$d['total_fee'];$final=effectiveValue($calc,$value,$current);if($final<$paid-.009)throw new Exception('Supera el saldo disponible');if($final>=$current-.009)throw new Exception('No reduce la deuda');
        $discount=round($current-$final,2);$student=(int)$d['student_id'];$course=(int)$d['course_id'];$year=(int)$d['academic_year_id'];$original=(float)$d['total_fee'];$benefit='Descuento masivo';$scope='Deuda';
        $st=$conn->prepare("INSERT INTO discount_benefits(school_id,academic_year_id,student_id,debt_id,course_id,benefit_type,calculation_type,value,original_amount,paid_before,previous_effective_amount,final_effective_amount,discount_amount,scope_type,reason,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Aplicado',?)");$st->bind_param('iiiiissddddddssi',$school,$year,$student,$debt,$course,$benefit,$calc,$value,$original,$paid,$current,$final,$discount,$scope,$reason,$user);if(!$st->execute())throw new Exception($st->error);$discountId=(int)$conn->insert_id;$st->close();
        $u=$conn->prepare('UPDATE student_ef_list SET discounted_amount=? WHERE id=?');$u->bind_param('di',$final,$debt);if(!$u->execute())throw new Exception($u->error);$u->close();$conn->commit();$applied++;discountAudit($conn,$school,$discountId,$debt,$student,'discount_bulk_applied',['calculation_type'=>$calc,'value'=>$value,'final'=>$final]);
    }catch(Throwable $e){$conn->rollback();$skipped[]=['debt_id'=>$debt,'reason'=>$e->getMessage()];}}
    discountOut(['status'=>$applied?1:0,'applied'=>$applied,'skipped'=>$skipped,'message'=>"Se aplicaron $applied beneficio(s).".(count($skipped)?' '.count($skipped).' fueron omitidos.':'')]);
}
if($action==='bulk_revoke' && !empty($_POST['ids_are_debts'])){
    $debts=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_POST['ids']??''))))));$reason=trim($_POST['reason']??'');if(!$debts||count($debts)>200||strlen($reason)<3)discountOut(['status'=>0,'message'=>'Seleccione hasta 200 beneficios e indique el motivo.']);$done=0;$skipped=0;
    foreach($debts as $debtLookup){$q=$conn->prepare("SELECT id FROM discount_benefits WHERE debt_id=? AND school_id=? AND status='Aplicado' LIMIT 1");$q->bind_param('ii',$debtLookup,$school);$q->execute();$found=$q->get_result()->fetch_assoc();$q->close();if(!$found){$skipped++;continue;}$id=(int)$found['id'];$conn->begin_transaction();try{$s=$conn->prepare("SELECT * FROM discount_benefits WHERE id=? AND school_id=? AND status='Aplicado' FOR UPDATE");$s->bind_param('ii',$id,$school);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();if(!$d)throw new Exception('No activo');$debt=(int)$d['debt_id'];$student=(int)$d['student_id'];$restore=(float)$d['previous_effective_amount'];$u=$conn->prepare('UPDATE student_ef_list SET discounted_amount=IF(ABS(total_fee-?)<0.01,NULL,?) WHERE id=?');$u->bind_param('ddi',$restore,$restore,$debt);if(!$u->execute())throw new Exception($u->error);$u->close();$st=$conn->prepare("UPDATE discount_benefits SET status='Revocado',revoked_at=NOW(),revoked_by=?,revocation_reason=? WHERE id=?");$st->bind_param('isi',$user,$reason,$id);if(!$st->execute())throw new Exception($st->error);$st->close();$conn->commit();$done++;discountAudit($conn,$school,$id,$debt,$student,'discount_bulk_revoked',['reason'=>$reason,'restored_amount'=>$restore]);}catch(Throwable $e){$conn->rollback();$skipped++;}}
    discountOut(['status'=>$done?1:0,'message'=>"Se revocaron $done beneficio(s).".($skipped?" $skipped fueron omitidos.":'')]);
}
if($action==='bulk_revoke'){
    $ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_POST['ids']??''))))));$reason=trim($_POST['reason']??'');if(!$ids||count($ids)>200||strlen($reason)<3)discountOut(['status'=>0,'message'=>'Seleccione hasta 200 beneficios e indique el motivo.']);$done=0;$skipped=0;
    foreach($ids as $id){$conn->begin_transaction();try{$s=$conn->prepare("SELECT * FROM discount_benefits WHERE id=? AND school_id=? AND status='Aplicado' FOR UPDATE");$s->bind_param('ii',$id,$school);$s->execute();$d=$s->get_result()->fetch_assoc();$s->close();if(!$d)throw new Exception('No activo');$debt=(int)$d['debt_id'];$student=(int)$d['student_id'];$restore=(float)$d['previous_effective_amount'];$u=$conn->prepare('UPDATE student_ef_list SET discounted_amount=IF(ABS(total_fee-?)<0.01,NULL,?) WHERE id=?');$u->bind_param('ddi',$restore,$restore,$debt);if(!$u->execute())throw new Exception($u->error);$u->close();$st=$conn->prepare("UPDATE discount_benefits SET status='Revocado',revoked_at=NOW(),revoked_by=?,revocation_reason=? WHERE id=?");$st->bind_param('isi',$user,$reason,$id);if(!$st->execute())throw new Exception($st->error);$st->close();$conn->commit();$done++;discountAudit($conn,$school,$id,$debt,$student,'discount_bulk_revoked',['reason'=>$reason,'restored_amount'=>$restore]);}catch(Throwable $e){$conn->rollback();$skipped++;}}
    discountOut(['status'=>$done?1:0,'message'=>"Se revocaron $done beneficio(s).".($skipped?" $skipped fueron omitidos.":'')]);
}
if($action==='audit'){
    $id=(int)($_GET['id']??0);$s=$conn->prepare('SELECT dal.*,u.name user_name FROM discount_audit_log dal LEFT JOIN users u ON u.id=dal.user_id WHERE dal.school_id=? AND (?=0 OR dal.discount_id=?) ORDER BY dal.created_at DESC LIMIT 100');$s->bind_param('iii',$school,$id,$id);$s->execute();$r=$s->get_result();$data=[];while($x=$r->fetch_assoc())$data[]=$x;$s->close();discountOut(['status'=>1,'data'=>$data]);
}
discountOut(['status'=>0,'message'=>'Acción no válida.'],404);
