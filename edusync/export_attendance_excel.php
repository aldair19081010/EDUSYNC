<?php
ob_start();
include_once __DIR__.'/includes/session_check.php';
require_login_modal();
include __DIR__.'/db_connect.php';
include_once __DIR__.'/includes/attendance_report_queries.php';

$school=(int)($_SESSION['login_school_id']??0);
if(!$school)die('No autorizado.');
$autoload=__DIR__.'/vendor/autoload.php';
if(!file_exists($autoload))die('PhpSpreadsheet no está instalado.');
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$f=arq_filters($conn,$school,$_GET);
$start=new DateTimeImmutable($f['from']);$end=new DateTimeImmutable($f['to']);
$dayCount=(int)$start->diff($end)->days+1;
if($dayCount>370)die('Seleccione un rango máximo de 370 días para generar la matriz.');
$days=[];for($date=$start;$date<=$end;$date=$date->modify('+1 day'))$days[]=$date->format('Y-m-d');

// La matriz conserva el formato tradicional: un estudiante por fila y una fecha por columna.
$studentWhere=["s.school_id=$school","s.status='Activo'"];
if($f['level']!=='')$studentWhere[]="s.nivel='{$f['level']}'";
if($f['grade']!=='')$studentWhere[]="s.grado='{$f['grade']}'";
if($f['section']!=='')$studentWhere[]="s.seccion='{$f['section']}'";
if($f['student']>0)$studentWhere[]="s.id={$f['student']}";
$students=[];$studentIds=[];
$studentsQuery=$conn->query('SELECT s.id,s.id_no,s.name,s.nivel,s.grado,s.seccion FROM student s WHERE '.implode(' AND ',$studentWhere)." ORDER BY FIELD(s.nivel,'Inicial','Primaria','Secundaria'),s.grado,s.seccion,s.name");
while($studentsQuery&&($student=$studentsQuery->fetch_assoc())){$students[]=$student;$studentIds[]=(int)$student['id'];}

$attendance=[];
if($studentIds){
    $where=arq_where($f,$school,true,true).' AND a.student_id IN ('.implode(',',$studentIds).')';
    $query=$conn->query("SELECT a.student_id,a.fecha,a.hora,CASE WHEN a.estado IN('Normal','Temprano') THEN 'Presente' ELSE a.estado END estado FROM asistencia a INNER JOIN student s ON s.id=a.student_id WHERE $where ORDER BY a.id");
    while($query&&($row=$query->fetch_assoc()))$attendance[(int)$row['student_id']][$row['fecha']]=$row;
}

$book=new Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Matriz de asistencia');
$firstDayColumn=4;$lastDayColumn=$firstDayColumn+count($days)-1;$totalsStart=$lastDayColumn+1;$lastColumn=$totalsStart+4;
$lastLetter=Coordinate::stringFromColumnIndex($lastColumn);
$sheet->mergeCells("A1:{$lastLetter}1")->setCellValue('A1','Matriz de asistencia');
$sheet->mergeCells("A2:{$lastLetter}2")->setCellValue('A2','Periodo: '.$start->format('d/m/Y').' al '.$end->format('d/m/Y'));
$filtersText=array_filter([$f['level'],$f['grade'],$f['section']]);
$sheet->mergeCells("A3:{$lastLetter}3")->setCellValue('A3','Aula: '.($filtersText?implode(' · ',$filtersText):'Todos los niveles y aulas'));
$sheet->mergeCells('A5:A6')->setCellValue('A5','DNI');$sheet->mergeCells('B5:B6')->setCellValue('B5','Estudiante');$sheet->mergeCells('C5:C6')->setCellValue('C5','Aula');
$dayNames=['Mon'=>'L','Tue'=>'M','Wed'=>'X','Thu'=>'J','Fri'=>'V','Sat'=>'S','Sun'=>'D'];
foreach($days as $index=>$day){$column=$firstDayColumn+$index;$letter=Coordinate::stringFromColumnIndex($column);$sheet->setCellValue($letter.'5',date('d/m',strtotime($day)));$sheet->setCellValue($letter.'6',$dayNames[date('D',strtotime($day))]??'');$sheet->getColumnDimension($letter)->setWidth(10);}
$totalHeaders=['Tardanzas','Ausencias','Justificadas','Permisos','Asistencia %'];
foreach($totalHeaders as $index=>$header){$letter=Coordinate::stringFromColumnIndex($totalsStart+$index);$sheet->mergeCells($letter.'5:'.$letter.'6')->setCellValue($letter.'5',$header);$sheet->getColumnDimension($letter)->setWidth(14);}

$rowNumber=7;
foreach($students as $student){
    $sheet->setCellValue('A'.$rowNumber,$student['id_no'])->setCellValue('B'.$rowNumber,$student['name'])->setCellValue('C'.$rowNumber,trim($student['nivel'].' · '.$student['grado'].' '.$student['seccion']));
    $totals=['late'=>0,'absent'=>0,'justified'=>0,'permission'=>0,'present'=>0,'marked'=>0];
    foreach($days as $index=>$day){
        $mark=$attendance[(int)$student['id']][$day]??null;$value='-';
        if($mark){$state=$mark['estado'];$totals['marked']++;if($state==='Tarde'){$totals['late']++;$value=substr($mark['hora'],0,5).' T';}elseif($state==='Ausente'){$totals['absent']++;$value='A';}elseif($state==='Ausente Justificada'){$totals['justified']++;$value='AJ';}elseif($state==='Permiso'){$totals['permission']++;$value='P';}else{$totals['present']++;$value=substr($mark['hora'],0,5);}}
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($firstDayColumn+$index).$rowNumber,$value);
    }
    $rate=$totals['marked']?round((($totals['present']+$totals['late'])/$totals['marked'])*100,1):0;
    $sheet->fromArray([$totals['late'],$totals['absent'],$totals['justified'],$totals['permission'],$rate],null,Coordinate::stringFromColumnIndex($totalsStart).$rowNumber);
    $rowNumber++;
}

$lastDataRow=max(7,$rowNumber-1);$headerRange="A5:{$lastLetter}6";$tableRange="A5:{$lastLetter}{$lastDataRow}";
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF263754');$sheet->getStyle('A1:A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');$sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4E73DF');$sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFD8DEEA');$sheet->getStyle("D7:{$lastLetter}{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);$sheet->getStyle(Coordinate::stringFromColumnIndex($lastColumn).'7:'.Coordinate::stringFromColumnIndex($lastColumn).$lastDataRow)->getNumberFormat()->setFormatCode('0.0"%"');
$sheet->getColumnDimension('A')->setWidth(14);$sheet->getColumnDimension('B')->setWidth(38);$sheet->getColumnDimension('C')->setWidth(24);$sheet->freezePane('D7');
$legendRow=$rowNumber+1;$sheet->mergeCells("A{$legendRow}:{$lastLetter}{$legendRow}")->setCellValue("A{$legendRow}",'Leyenda: hora = presente · T = tardanza · A = ausencia · AJ = ausencia justificada · P = permiso · - = sin marcación');$sheet->getStyle("A{$legendRow}")->getFont()->setItalic(true)->getColor()->setARGB('FF6E7891');

while(ob_get_level())ob_end_clean();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="matriz_asistencia_'.$start->format('Ymd').'_'.$end->format('Ymd').'.xlsx"');header('Cache-Control: max-age=0');(new Xlsx($book))->save('php://output');exit;
