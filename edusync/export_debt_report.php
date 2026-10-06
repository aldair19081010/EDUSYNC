<?php
include_once __DIR__.'/includes/session_check.php';
require_login_modal();
include __DIR__.'/db_connect.php';

$school=(int)($_SESSION['login_school_id']??0);
if(!$school)die('No autorizado.');

$autoload=__DIR__.'/vendor/autoload.php';
if(!file_exists($autoload))die('PhpSpreadsheet no está instalado.');
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function edrBind(mysqli_stmt $s,string $t,array &$p):void{
    if($t==='')return;
    $a=[$t];
    foreach($p as &$v)$a[]=&$v;
    call_user_func_array([$s,'bind_param'],$a);
}

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

$view=trim($_GET['view']??'detail');
if(!in_array($view,['detail','student','concept','classroom','state'],true))$view='detail';

$headers=[];
$rows=[];
$sheetTitle='Reporte de deudas';
$fileSuffix='detalle';

if($view==='student'){
    $sheetTitle='Deudas por estudiante';
    $fileSuffix='por_estudiante';
    $headers=['DNI','Estudiante','Nivel','Grado','Sección','Deudas','Original','Descuentos','Exigible','Pagado','Saldo','Saldo vencido'];
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
    $sheetTitle='Deudas por concepto';
    $fileSuffix='por_concepto';
    $headers=['Concepto','Año','Deudas','Estudiantes','Original','Descuentos','Exigible','Pagado','Saldo','Saldo vencido'];
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
    $sheetTitle='Deudas por aula';
    $fileSuffix='por_aula';
    $headers=['Nivel','Grado','Sección','Estudiantes','Deudas','Exigible','Pagado','Saldo','Saldo vencido'];
    $sql="SELECT nivel,grado,seccion,COUNT(DISTINCT student_id) students,COUNT(*) debts,
        COALESCE(SUM(effective),0) effective_amount,
        COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,
        COALESCE(SUM(balance),0) balance,
        COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount
      FROM ($filtered) z
      GROUP BY nivel,grado,seccion
      ORDER BY nivel,grado,seccion";
}elseif($view==='state'){
    $sheetTitle='Deudas por estado';
    $fileSuffix='por_estado';
    $headers=['Estado financiero','Estado deuda','Deudas','Estudiantes','Exigible','Pagado','Saldo','Saldo vencido'];
    $sql="SELECT financial_state,debt_status,COUNT(*) debts,COUNT(DISTINCT student_id) students,
        COALESCE(SUM(effective),0) effective_amount,
        COALESCE(SUM(LEAST(paid,effective)),0) paid_amount,
        COALESCE(SUM(balance),0) balance,
        COALESCE(SUM(CASE WHEN debt_status='Activa' AND balance>0.009 AND (due_date IS NULL OR due_date='' OR due_date<CURDATE()) THEN balance ELSE 0 END),0) overdue_amount
      FROM ($filtered) z
      GROUP BY financial_state,debt_status
      ORDER BY balance DESC,financial_state,debt_status";
}else{
    $headers=['Año','DNI','Estudiante','Nivel','Grado','Sección','Concepto','Periodo','Asignación','Vencimiento','Original','Descuento','Exigible','Pagado confirmado','Saldo','Estado financiero','Estado deuda','Motivo anulación','Estado estudiante'];
    $sql="$filtered ORDER BY year DESC,student_name,course";
}

$stmt=$conn->prepare($sql);
if(!$stmt)die('No se pudo preparar el reporte.');
edrBind($stmt,$types,$params);
$stmt->execute();
$result=$stmt->get_result();

while($x=$result->fetch_assoc()){
    if($view==='student'){
        $rows[]=[
            $x['id_no'],$x['student_name'],$x['nivel'],$x['grado'],$x['seccion'],(int)$x['debts'],
            (float)$x['original_amount'],(float)$x['discount_amount'],(float)$x['effective_amount'],
            (float)$x['paid_amount'],(float)$x['balance'],(float)$x['overdue_amount']
        ];
    }elseif($view==='concept'){
        $rows[]=[
            $x['concept_name'],$x['academic_year']?:'—',(int)$x['debts'],(int)$x['students'],
            (float)$x['original_amount'],(float)$x['discount_amount'],(float)$x['effective_amount'],
            (float)$x['paid_amount'],(float)$x['balance'],(float)$x['overdue_amount']
        ];
    }elseif($view==='classroom'){
        $rows[]=[
            $x['nivel'],$x['grado'],$x['seccion'],(int)$x['students'],(int)$x['debts'],
            (float)$x['effective_amount'],(float)$x['paid_amount'],(float)$x['balance'],(float)$x['overdue_amount']
        ];
    }elseif($view==='state'){
        $rows[]=[
            $x['financial_state'],$x['debt_status'],(int)$x['debts'],(int)$x['students'],
            (float)$x['effective_amount'],(float)$x['paid_amount'],(float)$x['balance'],(float)$x['overdue_amount']
        ];
    }else{
        $discount=max(0,(float)$x['total_fee']-(float)$x['effective']);
        $rows[]=[
            $x['year'],$x['id_no'],$x['student_name'],$x['nivel'],$x['grado'],$x['seccion'],$x['course'],$x['billing_period'],
            $x['issue_date'],$x['due_date'],(float)$x['total_fee'],$discount,(float)$x['effective'],(float)$x['paid'],
            (float)$x['balance'],$x['financial_state'],$x['debt_status'],$x['cancellation_reason'],$x['student_status']
        ];
    }
}
$stmt->close();

$book=new Spreadsheet();
$sheet=$book->getActiveSheet();
$sheet->setTitle(substr($sheetTitle,0,31));
$sheet->fromArray($headers,null,'A1');
if($rows)$sheet->fromArray($rows,null,'A2');

$lastColumn=Coordinate::stringFromColumnIndex(count($headers));
$sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F6FED');
for($i=1;$i<=count($headers);$i++){
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
}
$sheet->freezePane('A2');
$sheet->setAutoFilter("A1:{$lastColumn}".max(1,count($rows)+1));

while(ob_get_level())ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="reporte_deudas_'.$fileSuffix.'_'.date('Ymd_His').'.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($book))->save('php://output');
exit;
?>