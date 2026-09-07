<?php
include 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();
require_once 'includes/report_builder_service.php';
$schoolId=(int)($_SESSION['login_school_id']??0);$userId=(int)($_SESSION['login_id']??0);
if(!$schoolId||!$userId)die('No autorizado');
$permission=$conn->prepare('SELECT type,is_director FROM users WHERE id=? AND school_id=? LIMIT 1');$permission->bind_param('ii',$userId,$schoolId);$permission->execute();$role=$permission->get_result()->fetch_assoc();$permission->close();
if(!$role||(int)$role['type']!==1)die('No tiene permiso para generar este reporte.');
$definitions=rbDefinitions();$filters=rbFilters($conn);$definition=$definitions[$filters['report']];
$catalogTitles=['roster'=>'Nómina de matriculados por aula','risk'=>'Estudiantes en riesgo académico','attendance_alert'=>'Alertas de asistencia','morosity'=>'Deudas vencidas con saldo','partial_payments'=>'Pagos parciales con saldo','teacher_load'=>'Carga académica docente'];
$definition['title']=$catalogTitles[$filters['preset']]??$definition['title'];
try{$rows=rbBuild($conn,$schoolId,$filters);}catch(Throwable $e){http_response_code(500);die('No se pudo generar el reporte: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));}
$totalRows=count($rows);$previewLimit=500;$previewRows=array_slice($rows,0,$previewLimit);
$viewLabels=['detail'=>'Detallado','grouped'=>'Agrupado','consolidated'=>'Consolidado'];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><title><?=htmlspecialchars($definition['title'])?></title><style>
body{font-family:Arial,sans-serif;color:#26344d;margin:0;background:#f5f7fb}.page{max-width:1400px;margin:20px auto;background:#fff;padding:24px;border-radius:10px}.head{display:flex;justify-content:space-between;gap:20px;border-bottom:3px solid #4e73df;padding-bottom:14px;margin-bottom:18px}.head h1{font-size:24px;margin:0}.head p{margin:5px 0 0;color:#667085}.badge{background:#eef3ff;color:#3159c9;padding:7px 11px;border-radius:16px;font-size:12px}table{width:100%;border-collapse:collapse;font-size:12px}th{background:#eef2f7;color:#344767;text-align:left;padding:9px;border:1px solid #dbe1ea}td{padding:8px;border:1px solid #e0e5ed}tbody tr:nth-child(even){background:#fafbfc}.empty{text-align:center;padding:35px;color:#7d8799}.footer{margin-top:15px;font-size:11px;color:#8993a4}.num{text-align:right;white-space:nowrap}@media print{body{background:#fff}.page{margin:0;max-width:none;padding:10px}.no-print{display:none}}
</style></head><body><div class="page"><div class="head"><div><h1><?=htmlspecialchars($definition['title'])?></h1><p>Vista <?=htmlspecialchars(strtolower($viewLabels[$filters['view']]))?> · <?=count($rows)?> resultado(s)</p></div><div><span class="badge">Generado <?=date('d/m/Y H:i')?></span></div></div>
<table><thead><tr><?php foreach($filters['columns'] as $key):?><th><?=htmlspecialchars($definition['columns'][$key])?></th><?php endforeach?></tr></thead><tbody>
<?php if(!$previewRows):?><tr><td class="empty" colspan="<?=count($filters['columns'])?>">No se encontraron datos con los filtros seleccionados.</td></tr><?php else:foreach($previewRows as $row):?><tr><?php foreach($filters['columns'] as $key):$formatted=rbFormat($key,$row[$key]??null);?><td class="<?=in_array($key,['average','minimum','maximum','original_amount','discount','effective_amount','paid','balance','weekly_hours','evaluations','records','present','late','absent','justified','attendance_rate','assignments','courses','classrooms','debts'],true)?'num':''?>"><?=htmlspecialchars($formatted,ENT_QUOTES,'UTF-8')?></td><?php endforeach?></tr><?php endforeach;endif?></tbody></table>
<?php if($totalRows>$previewLimit):?><div class="footer"><strong>Vista previa:</strong> se muestran <?=number_format($previewLimit)?> de <?=number_format($totalRows)?> filas. La exportación Excel contiene el reporte completo.</div><?php endif?><div class="footer">Reporte institucional generado por EduSync. Los filtros se aplicaron directamente en la consulta.</div></div></body></html>
