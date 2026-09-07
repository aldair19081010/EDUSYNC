<?php
include_once __DIR__.'/includes/session_check.php'; require_login_modal(); include __DIR__.'/db_connect.php';
$schoolId=(int)($_SESSION['login_school_id']??0);if(!$schoolId){http_response_code(401);exit('Sesión inválida');}
$yearId=(int)($_GET['academic_year_id']??0);$level=trim($_GET['level']??'');$grade=trim($_GET['grade']??'');$status=trim($_GET['status']??'all');$search=trim($_GET['search']??'');
$where=['ac.school_id=?'];$types='i';$params=[$schoolId];
if($yearId){$where[]='ac.academic_year_id=?';$types.='i';$params[]=$yearId;}if($level!==''){$where[]='ac.level=?';$types.='s';$params[]=$level;}if($grade!==''){$where[]="FIND_IN_SET(?,REPLACE(COALESCE(ac.grades,''),' ',''))>0";$types.='s';$params[]=str_replace(' ','',$grade);}if($status==='active')$where[]="ac.course_status='Activo'";if($status==='suspended')$where[]="ac.course_status='Suspendido'";if($status==='inactive')$where[]="ac.course_status='Inactivo'";if($search!==''){$like="%$search%";$where[]='(ac.name LIKE ? OR ac.course_code LIKE ? OR a.name LIKE ?)';$types.='sss';array_push($params,$like,$like,$like);}
$sql="SELECT ay.year,a.name area,ac.course_code,ac.name,ac.level,ac.grades,ac.weekly_hours,ac.course_type,ac.description,ac.course_status status,(SELECT COUNT(*) FROM general_course_competencies gcc WHERE gcc.course_id=ac.id AND gcc.is_active=1) competencies FROM academic_courses ac LEFT JOIN areas a ON a.id=ac.area_id LEFT JOIN academic_year ay ON ay.id=ac.academic_year_id WHERE ".implode(' AND ',$where).' ORDER BY ay.year DESC,ac.level,a.name,ac.display_order,ac.name';
$stmt=$conn->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();
$autoload=__DIR__.'/vendor/autoload.php';
if(file_exists($autoload)){
 require_once $autoload;
 $sheet=(new PhpOffice\PhpSpreadsheet\Spreadsheet())->getActiveSheet();$sheet->setTitle('Estructura académica');
 $headers=['Año','Área','Código','Curso','Nivel','Grados','Horas semanales','Tipo','Descripción','Estado','Competencias'];$sheet->fromArray($headers,null,'A1');$sheet->getStyle('A1:K1')->getFont()->setBold(true);$sheet->freezePane('A2');
 $i=2;foreach($rows as $r){$sheet->fromArray(array_values($r),null,'A'.$i++);}foreach(range('A','K') as $col)$sheet->getColumnDimension($col)->setAutoSize(true);$sheet->setAutoFilter('A1:K'.max(1,$i-1));
 while(ob_get_level())ob_end_clean();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="estructura_academica_'.date('Ymd_His').'.xlsx"');(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet->getParent()))->save('php://output');exit;
}
while(ob_get_level())ob_end_clean();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="estructura_academica_'.date('Ymd_His').'.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');fputcsv($out,['Año','Área','Código','Curso','Nivel','Grados','Horas semanales','Tipo','Descripción','Estado','Competencias'],',');foreach($rows as $r)fputcsv($out,array_values($r),',');fclose($out);
