<?php
include 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();
require_once 'includes/report_builder_service.php';
require_once 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
$schoolId=(int)($_SESSION['login_school_id']??0);$userId=(int)($_SESSION['login_id']??0);
if(!$schoolId||!$userId)die('No autorizado');
$permission=$conn->prepare('SELECT type,is_director FROM users WHERE id=? AND school_id=? LIMIT 1');$permission->bind_param('ii',$userId,$schoolId);$permission->execute();$role=$permission->get_result()->fetch_assoc();$permission->close();
if(!$role||(int)$role['type']!==1)die('No tiene permiso para exportar este reporte.');
$definitions=rbDefinitions();$filters=rbFilters($conn);$definition=$definitions[$filters['report']];
$catalogTitles=['roster'=>'Nómina de matriculados por aula','risk'=>'Estudiantes en riesgo académico','attendance_alert'=>'Alertas de asistencia','morosity'=>'Deudas vencidas con saldo','partial_payments'=>'Pagos parciales con saldo','teacher_load'=>'Carga académica docente'];
$definition['title']=$catalogTitles[$filters['preset']]??$definition['title'];
try{$rows=rbBuild($conn,$schoolId,$filters);}catch(Throwable $e){http_response_code(500);die('No se pudo exportar el reporte: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));}
$book=new Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle(substr($definition['title'],0,31));
$headers=[];foreach($filters['columns'] as $key)$headers[]=$definition['columns'][$key];$sheet->fromArray($headers,null,'A1');$sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);$sheet->freezePane('A2');$sheet->setAutoFilter($sheet->calculateWorksheetDimension());
$rowNumber=2;foreach($rows as $row){$values=[];foreach($filters['columns'] as $key)$values[]=rbFormat($key,$row[$key]??null);$sheet->fromArray($values,null,'A'.$rowNumber++);}foreach(range('A',$sheet->getHighestColumn())as$column)$sheet->getColumnDimension($column)->setAutoSize(true);
$filename='reporte_'.$filters['report'].'_'.$filters['view'].'_'.date('Y-m-d_His').'.xlsx';header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: max-age=0');(new Xlsx($book))->save('php://output');exit;
