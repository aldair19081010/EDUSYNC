<?php
// EduSync: validación de transición de alertas sin tocar base de datos.
declare(strict_types=1);
require_once __DIR__.'/../edusync/includes/academic_alert_policy.php';
function ni_check(bool $condition,string $msg): void {
    if (!$condition) { fwrite(STDERR, "ERROR: $msg\n"); exit(1); }
}
$admin=['student'=>11,'teacher'=>null,'count'=>3,'json'=>'[{"name":"Matemática","count":3}]'];
$teacher=['student'=>11,'teacher'=>8,'count'=>3,'json'=>$admin['json']];
$planned=['11:0'=>$admin,'11:8'=>$teacher];
$initial=edun_academic_alert_plan([],$planned);
ni_check(count($initial['create'])===2,'Un riesgo nuevo se registra una sola vez por destinatario.');
ni_check(!$initial['refresh'] && !$initial['resolved'],'No debe haber cambios artificiales al crear.');
$prior=[
    '11:0'=>['id'=>101,'student_id'=>11,'teacher_id'=>null,'count_low_grades'=>3,'failed_courses'=>$admin['json']],
    '11:8'=>['id'=>102,'student_id'=>11,'teacher_id'=>8,'count_low_grades'=>3,'failed_courses'=>$teacher['json']]
];
$repeat=edun_academic_alert_plan($prior,$planned);
ni_check(count($repeat['unchanged'])===2,'Guardar iguales las notas conserva las dos alertas.');
ni_check(!$repeat['create'] && !$repeat['refresh'] && !$repeat['resolved'],
    'No debe reinsertar ni reactivar una alerta ya existente.');
$updated=$planned;
$updated['11:0']['count']=4;
$updated['11:0']['json']='[{"name":"Matemática","count":4}]';
$change=edun_academic_alert_plan($prior,$updated);
ni_check(count($change['refresh'])===1 && !$change['create'] && !$change['resolved'],
    'Más notas bajas en un caso existente solo actualizan el resumen.');
ni_check((int)$change['refresh']['11:0']['existing']['id']===101,
    'Conservar identificador original y lecturas al actualizar.');
$resolved=edun_academic_alert_plan($prior,[]);
ni_check(count($resolved['resolved'])===2 && !$resolved['create'],
    'Cuando el estudiante mejora desaparece la alerta.');
$newRisk=edun_academic_alert_plan($prior,[
    '11:0'=>$admin,
    '11:8'=>$teacher,
    '12:0'=>['student'=>12,'teacher'=>null,'count'=>3,'json'=>'[]']
]);
ni_check(count($newRisk['create'])===1 && isset($newRisk['create']['12:0']),
    'Al aparecer un segundo estudiante se notifica solo el nuevo caso.');
ni_check(count($newRisk['unchanged'])===2,
    'La alerta del primer estudiante no debe repetirse.');
$code=file_get_contents(__DIR__.'/../edusync/admin_class.php');
ni_check(strpos($code,'edun_academic_alert_plan($existing,$wanted)')!==false,
    'La generación real debe usar la política probada.');
ni_check(strpos($code,"DELETE FROM low_grade_notifications WHERE student_id IN")===false,
    'No borrar todas las alertas por cada modificación de notas.');
$topbar=file_get_contents(__DIR__.'/../edusync/includes/topbar.php');
ni_check(strpos($topbar,"key:row.nkey")!==false
    && strpos($topbar,"notifications_api.php?action=mark")!==false,
    'Abrir la campana debe guardar lectura en la bandeja real.');
$push=file_get_contents(__DIR__.'/../edusync/cron/web_push_dispatch.php');
ni_check(strpos($push,"'EduSync · Seguimiento académico' title")!==false
    && strpos($push,"&push_kind=low_grade")!==false,
    'FCM debe usar mensaje académico discreto y destino específico.');
echo "OK: riesgo nuevo, repetición, actualización, recuperación, permisos por destinatario y Push.\n";
