<?php
include_once 'includes/session_check.php';
require_login_modal();
include 'db_connect.php';

$school=(int)($_SESSION['login_school_id']??0);

function pdrBind(mysqli_stmt $s,string $t,array &$p):void{
    if(!$t)return;
    $a=[$t];
    foreach($p as &$v)$a[]=&$v;
    call_user_func_array([$s,'bind_param'],$a);
}
function pdrMoney($value):string{
    return 'S/ '.number_format((float)$value,2);
}

$schoolData=['name'=>'Institución Educativa','address'=>'','logo_path'=>''];
$s=$conn->prepare('SELECT name,address,logo_path FROM schools WHERE id=?');
$s->bind_param('i',$school);
$s->execute();
$schoolData=array_merge($schoolData,$s->get_result()->fetch_assoc()?:[]);
$s->close();

$where=['s.school_id=?'];
$types='i';
$params=[$school];
$add=function($sql,$type,$value)use(&$where,&$types,&$params){
    if($value===''||$value===0)return;
    $where[]=$sql;
    $types.=$type;
    $params[]=$value;
};

$add('c.academic_year_id=?','i',(int)($_GET['academic_year_id']??0));
$add('s.id=?','i',(int)($_GET['student_id']??0));
$add('s.nivel=?','s',trim($_GET['nivel']??''));
$add('s.grado=?','s',trim($_GET['grado']??''));
$add('s.seccion=?','s',trim($_GET['seccion']??''));
$add('ef.course_id=?','i',(int)($_GET['concepto']??0));
$add('s.status=?','s',trim($_GET['student_status']??''));
$add('ef.debt_status=?','s',trim($_GET['debt_status']??'Activa'));

$dateType=trim($_GET['date_type']??'assignment');
$from=trim($_GET['start_date']??'');
$to=trim($_GET['end_date']??'');
if($dateType==='payment'){
    if($from){
        $where[]="EXISTS(SELECT 1 FROM payments pd WHERE pd.ef_id=ef.id AND COALESCE(pd.payment_status,'Confirmado')='Confirmado' AND DATE(pd.date_created)>=?)";
        $types.='s';$params[]=$from;
    }
    if($to){
        $where[]="EXISTS(SELECT 1 FROM payments pd WHERE pd.ef_id=ef.id AND COALESCE(pd.payment_status,'Confirmado')='Confirmado' AND DATE(pd.date_created)<=?)";
        $types.='s';$params[]=$to;
    }
}else{
    $col=$dateType==='due'?'ef.due_date':'COALESCE(ef.issue_date,DATE(ef.date_created))';
    if($from){$where[]="$col>=?";$types.='s';$params[]=$from;}
    if($to){$where[]="$col<=?";$types.='s';$params[]=$to;}
}

$base="SELECT
    ef.id,ef.course_id,ef.total_fee,ef.discounted_amount,ef.debt_status,ef.issue_date,ef.due_date,
    ef.billing_period,ef.cancellation_reason,
    s.id student_id,s.id_no,s.name student_name,s.nivel,s.grado,s.seccion,s.status student_status,
    c.course,ay.year,
    COALESCE(ef.discounted_amount,ef.total_fee) effective,
    (SELECT COALESCE(SUM(p.amount),0)
       FROM payments p
      WHERE p.ef_id=ef.id
        AND COALESCE(p.payment_status,'Confirmado')='Confirmado') paid
FROM student_ef_list ef
INNER JOIN student s ON s.id=ef.student_id
INNER JOIN courses c ON c.id=ef.course_id
LEFT JOIN academic_year ay ON ay.id=c.academic_year_id
WHERE ".implode(' AND ',$where);

$state="CASE
    WHEN q.discounted_amount IS NOT NULL AND q.total_fee>0 AND q.effective<=0.009 THEN 'Exonerada'
    WHEN q.effective>0 AND q.paid+0.009>=q.effective THEN 'Pagada'
    WHEN q.paid>0 THEN 'Parcial'
    ELSE 'Pendiente'
END";

$financial=trim($_GET['financial_status']??'Todas');
$outerConditions=[];
if($financial==='Pendiente'){
    $outerConditions[]="q.effective-q.paid>0.009";
    $outerConditions[]="q.paid<=0.009";
}elseif($financial==='Parcial'){
    $outerConditions[]="q.effective-q.paid>0.009";
    $outerConditions[]="q.paid>0.009";
}elseif($financial==='Exonerada'){
    $outerConditions[]="q.discounted_amount IS NOT NULL";
    $outerConditions[]="q.total_fee>0";
    $outerConditions[]="q.effective<=0.009";
}elseif($financial==='Vencidas'){
    $outerConditions[]="q.debt_status='Activa'";
    $outerConditions[]="q.effective-q.paid>0.009";
    $outerConditions[]="(q.due_date IS NULL OR q.due_date='' OR q.due_date<CURDATE())";
}elseif($financial==='Por vencer'){
    $outerConditions[]="q.debt_status='Activa'";
    $outerConditions[]="q.effective-q.paid>0.009";
    $outerConditions[]="q.due_date IS NOT NULL AND q.due_date<>'' AND q.due_date>=CURDATE()";
}else{
    $outerConditions[]="(q.effective-q.paid>0.009 OR (q.discounted_amount IS NOT NULL AND q.total_fee>0 AND q.effective<=0.009) OR q.debt_status IN('Suspendida','Anulada'))";
}
$outer=' WHERE '.implode(' AND ',$outerConditions);
$filtered="SELECT q.*,GREATEST(0,q.effective-q.paid) balance,$state financial_state FROM ($base) q$outer";

$summarySql="SELECT
    COALESCE(SUM(total_fee),0) original,
    COALESCE(SUM(GREATEST(0,total_fee-effective)),0) discount,
    COALESCE(SUM(effective),0) effective,
    COALESCE(SUM(LEAST(paid,effective)),0) paid,
    COALESCE(SUM(balance),0) balance,
    COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue
FROM ($filtered) z";
$summaryStmt=$conn->prepare($summarySql);
pdrBind($summaryStmt,$types,$params);
$summaryStmt->execute();
$tot=$summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

$view=trim($_GET['view']??'detail');
if(!in_array($view,['detail','student','concept','classroom','state'],true))$view='detail';
$viewLabels=[
    'detail'=>'Detalle',
    'student'=>'Por estudiante',
    'concept'=>'Por concepto',
    'classroom'=>'Por aula',
    'state'=>'Por estado'
];
$viewLabel=$viewLabels[$view];

if($view==='student'){
    $sql="SELECT student_id,id_no,student_name,nivel,grado,seccion,COUNT(*) debts,
        COALESCE(SUM(total_fee),0) original_amount,
        COALESCE(SUM(GREATEST(0,total_fee-effective)),0) discount_amount,
        COALESCE(SUM(effective),0) effective_amount,
        COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,
        COALESCE(SUM(balance),0) balance,
        COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount
      FROM ($filtered) z
      GROUP BY student_id,id_no,student_name,nivel,grado,seccion
      ORDER BY balance DESC,student_name";
}elseif($view==='concept'){
    $sql="SELECT course_id,course concept_name,MAX(year) academic_year,COUNT(*) debts,COUNT(DISTINCT student_id) students,
        COALESCE(SUM(total_fee),0) original_amount,
        COALESCE(SUM(GREATEST(0,total_fee-effective)),0) discount_amount,
        COALESCE(SUM(effective),0) effective_amount,
        COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,
        COALESCE(SUM(balance),0) balance,
        COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount
      FROM ($filtered) z
      GROUP BY course_id,course
      ORDER BY balance DESC,concept_name";
}elseif($view==='classroom'){
    $sql="SELECT nivel,grado,seccion,COUNT(DISTINCT student_id) students,COUNT(*) debts,
        COALESCE(SUM(effective),0) effective_amount,
        COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,
        COALESCE(SUM(balance),0) balance,
        COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount
      FROM ($filtered) z
      GROUP BY nivel,grado,seccion
      ORDER BY nivel,grado,seccion";
}elseif($view==='state'){
    $sql="SELECT financial_state,debt_status,COUNT(*) debts,COUNT(DISTINCT student_id) students,
        COALESCE(SUM(effective),0) effective_amount,
        COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,
        COALESCE(SUM(balance),0) balance,
        COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount
      FROM ($filtered) z
      GROUP BY financial_state,debt_status
      ORDER BY balance DESC,financial_state,debt_status";
}else{
    $sql="$filtered ORDER BY student_name,course";
}

$stmt=$conn->prepare($sql);
pdrBind($stmt,$types,$params);
$stmt->execute();
$result=$stmt->get_result();
$rows=[];
while($x=$result->fetch_assoc())$rows[]=$x;
$stmt->close();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte de deudas - <?php echo htmlspecialchars($viewLabel);?></title>
<style>
@page{size:A4 landscape;margin:9mm}
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;color:#253858;font-size:8.7px;margin:0}
.toolbar{text-align:right;margin-bottom:8px}
.toolbar button{background:#2f6fed;color:#fff;border:0;border-radius:5px;padding:8px 14px}
.head{display:flex;align-items:center;border-bottom:2px solid #2f6fed;padding-bottom:8px}
.logo{width:55px;height:55px;object-fit:contain;margin-right:10px}
.head h1{font-size:17px;margin:0}
.head p{margin:3px 0;color:#667085}
.title{margin-left:auto;text-align:right;font-size:16px;font-weight:bold}
.subtitle{font-size:9px;color:#667085;font-weight:normal;margin-top:3px}
.stats{display:grid;grid-template-columns:repeat(6,1fr);gap:6px;margin:9px 0}
.stat{border:1px solid #dfe5ee;border-radius:5px;padding:6px}
.stat span{display:block;color:#667085}
.stat b{font-size:11px}
table{width:100%;border-collapse:collapse}
thead{display:table-header-group}
tr{page-break-inside:avoid}
th{background:#eef3fb;padding:5px;border:1px solid #d9e0ea;text-align:left}
td{padding:4px 5px;border:1px solid #d9e0ea;vertical-align:top}
.num{text-align:right;white-space:nowrap}
.danger{color:#b42318}
.success{color:#067647}
.muted{font-size:8px;color:#667085}
.center{text-align:center}
@media print{.toolbar{display:none}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Imprimir</button></div>
<div class="head">
    <?php if($schoolData['logo_path']&&file_exists($schoolData['logo_path'])):?><img class="logo" src="<?php echo htmlspecialchars($schoolData['logo_path']);?>"><?php endif;?>
    <div>
        <h1><?php echo htmlspecialchars($schoolData['name']);?></h1>
        <p><?php echo htmlspecialchars($schoolData['address']);?></p>
    </div>
    <div class="title">
        Reporte de deudas
        <div class="subtitle">Vista: <?php echo htmlspecialchars($viewLabel);?> · Generado <?php echo date('d/m/Y H:i');?></div>
    </div>
</div>

<div class="stats">
<?php foreach([
    'original'=>'Monto original',
    'discount'=>'Descuentos',
    'effective'=>'Total exigible',
    'paid'=>'Cobrado aplicable',
    'balance'=>'Saldo pendiente',
    'overdue'=>'Saldo vencido'
] as $k=>$label):?>
    <div class="stat"><span><?php echo $label;?></span><b><?php echo pdrMoney($tot[$k]??0);?></b></div>
<?php endforeach;?>
</div>

<?php if($view==='student'):?>
<table>
<thead><tr><th>Estudiante</th><th>Aula</th><th class="num">Deudas</th><th class="num">Original</th><th class="num">Descuentos</th><th class="num">Exigible</th><th class="num">Pagado</th><th class="num">Saldo</th><th class="num">Saldo vencido</th></tr></thead>
<tbody>
<?php if(!$rows):?><tr><td colspan="9" class="center">No se encontraron datos.</td></tr><?php endif;?>
<?php foreach($rows as $x):?>
<tr>
<td><b><?php echo htmlspecialchars($x['student_name']);?></b><div class="muted"><?php echo htmlspecialchars($x['id_no']);?></div></td>
<td><?php echo htmlspecialchars($x['nivel'].' · '.$x['grado'].' '.$x['seccion']);?></td>
<td class="num"><?php echo number_format((int)$x['debts']);?></td>
<td class="num"><?php echo pdrMoney($x['original_amount']);?></td>
<td class="num success"><?php echo pdrMoney($x['discount_amount']);?></td>
<td class="num"><?php echo pdrMoney($x['effective_amount']);?></td>
<td class="num"><?php echo pdrMoney($x['paid_amount']);?></td>
<td class="num danger"><b><?php echo pdrMoney($x['balance']);?></b></td>
<td class="num danger"><b><?php echo pdrMoney($x['overdue_amount']);?></b></td>
</tr>
<?php endforeach;?>
</tbody>
</table>

<?php elseif($view==='concept'):?>
<table>
<thead><tr><th>Concepto</th><th>Año</th><th class="num">Deudas</th><th class="num">Estudiantes</th><th class="num">Original</th><th class="num">Descuentos</th><th class="num">Exigible</th><th class="num">Pagado</th><th class="num">Saldo</th><th class="num">Saldo vencido</th></tr></thead>
<tbody>
<?php if(!$rows):?><tr><td colspan="10" class="center">No se encontraron datos.</td></tr><?php endif;?>
<?php foreach($rows as $x):?>
<tr>
<td><b><?php echo htmlspecialchars($x['concept_name']);?></b></td>
<td><?php echo htmlspecialchars($x['academic_year']?:'—');?></td>
<td class="num"><?php echo number_format((int)$x['debts']);?></td>
<td class="num"><?php echo number_format((int)$x['students']);?></td>
<td class="num"><?php echo pdrMoney($x['original_amount']);?></td>
<td class="num success"><?php echo pdrMoney($x['discount_amount']);?></td>
<td class="num"><?php echo pdrMoney($x['effective_amount']);?></td>
<td class="num"><?php echo pdrMoney($x['paid_amount']);?></td>
<td class="num danger"><b><?php echo pdrMoney($x['balance']);?></b></td>
<td class="num danger"><b><?php echo pdrMoney($x['overdue_amount']);?></b></td>
</tr>
<?php endforeach;?>
</tbody>
</table>

<?php elseif($view==='classroom'):?>
<table>
<thead><tr><th>Nivel</th><th>Grado</th><th>Sección</th><th class="num">Estudiantes</th><th class="num">Deudas</th><th class="num">Exigible</th><th class="num">Pagado</th><th class="num">Saldo</th><th class="num">Saldo vencido</th></tr></thead>
<tbody>
<?php if(!$rows):?><tr><td colspan="9" class="center">No se encontraron datos.</td></tr><?php endif;?>
<?php foreach($rows as $x):?>
<tr>
<td><?php echo htmlspecialchars($x['nivel']);?></td>
<td><?php echo htmlspecialchars($x['grado']);?></td>
<td><?php echo htmlspecialchars($x['seccion']);?></td>
<td class="num"><?php echo number_format((int)$x['students']);?></td>
<td class="num"><?php echo number_format((int)$x['debts']);?></td>
<td class="num"><?php echo pdrMoney($x['effective_amount']);?></td>
<td class="num"><?php echo pdrMoney($x['paid_amount']);?></td>
<td class="num danger"><b><?php echo pdrMoney($x['balance']);?></b></td>
<td class="num danger"><b><?php echo pdrMoney($x['overdue_amount']);?></b></td>
</tr>
<?php endforeach;?>
</tbody>
</table>

<?php elseif($view==='state'):?>
<table>
<thead><tr><th>Estado financiero</th><th>Estado deuda</th><th class="num">Deudas</th><th class="num">Estudiantes</th><th class="num">Exigible</th><th class="num">Pagado</th><th class="num">Saldo</th><th class="num">Saldo vencido</th></tr></thead>
<tbody>
<?php if(!$rows):?><tr><td colspan="8" class="center">No se encontraron datos.</td></tr><?php endif;?>
<?php foreach($rows as $x):?>
<tr>
<td><b><?php echo htmlspecialchars($x['financial_state']);?></b></td>
<td><?php echo htmlspecialchars($x['debt_status']);?></td>
<td class="num"><?php echo number_format((int)$x['debts']);?></td>
<td class="num"><?php echo number_format((int)$x['students']);?></td>
<td class="num"><?php echo pdrMoney($x['effective_amount']);?></td>
<td class="num"><?php echo pdrMoney($x['paid_amount']);?></td>
<td class="num danger"><b><?php echo pdrMoney($x['balance']);?></b></td>
<td class="num danger"><b><?php echo pdrMoney($x['overdue_amount']);?></b></td>
</tr>
<?php endforeach;?>
</tbody>
</table>

<?php else:?>
<table>
<thead><tr><th>Estudiante</th><th>Concepto</th><th>Año</th><th class="num">Original</th><th class="num">Descuento</th><th class="num">Exigible</th><th class="num">Pagado</th><th class="num">Saldo</th><th>Vencimiento</th><th>Estado financiero</th><th>Estado deuda</th><th>Motivo anulación</th></tr></thead>
<tbody>
<?php if(!$rows):?><tr><td colspan="12" class="center">No se encontraron deudas.</td></tr><?php endif;?>
<?php foreach($rows as $x):$discount=max(0,(float)$x['total_fee']-(float)$x['effective']);?>
<tr>
<td><b><?php echo htmlspecialchars($x['student_name']);?></b><div class="muted"><?php echo htmlspecialchars($x['id_no'].' · '.$x['nivel'].' · '.$x['grado'].' '.$x['seccion']);?></div></td>
<td><?php echo htmlspecialchars($x['course'].($x['billing_period']?' · '.$x['billing_period']:''));?></td>
<td><?php echo htmlspecialchars($x['year']?:'—');?></td>
<td class="num"><?php echo pdrMoney($x['total_fee']);?></td>
<td class="num success">− <?php echo pdrMoney($discount);?></td>
<td class="num"><?php echo pdrMoney($x['effective']);?></td>
<td class="num"><?php echo pdrMoney($x['paid']);?></td>
<td class="num <?php echo $x['balance']>0?'danger':'success';?>"><b><?php echo pdrMoney($x['balance']);?></b></td>
<td><?php echo $x['due_date']?date('d/m/Y',strtotime($x['due_date'])):'Sin fecha';?></td>
<td><b><?php echo htmlspecialchars($x['financial_state']);?></b></td>
<td><b><?php echo htmlspecialchars($x['debt_status']);?></b></td>
<td><?php echo htmlspecialchars($x['debt_status']==='Anulada'?($x['cancellation_reason']?:'Sin motivo registrado'):'—');?></td>
</tr>
<?php endforeach;?>
</tbody>
</table>
<?php endif;?>
</body>
</html>
