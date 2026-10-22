<?php
// Pruebas puras sin base de datos: php tests/test_attendance_historical_scope.php
require_once __DIR__.'/../includes/attendance_report_queries.php';
require_once __DIR__.'/../includes/grades_report_historical_classroom.php';

function atest(bool $valid,string $message): void {
    if (!$valid) throw new RuntimeException('FAIL: '.$message);
}
$historical=[
    'year'=>2024,'from'=>'2024-03-01','to'=>'2024-12-01',
    'historical'=>true,'valid_range'=>true,
    'classrooms'=>[11=>'primaria|3|a',12=>'primaria|3|b',13=>'primaria|3|a'],
    'level'=>'Primaria','grade'=>'3°','section'=>'A','student'=>0,
    'type'=>'','status'=>'','source'=>'','search'=>''
];
$join=arq_roster_join($historical);
$where=arq_where($historical,1,true,true);
atest(strpos($join,"id IN (11,13)")!==false,'Aula A conserva 11 y 13');
atest(strpos($join,"id IN (12)")!==false,'Aula B no se mezcla');
atest(strpos($where,"hc.nivel='Primaria'")!==false,'Nivel histórico');
atest(strpos($where,"hc.grado='3'")!==false,'Grado histórico normalizado');
atest(strpos($where,"UPPER(hc.seccion)")!==false,'Sección histórica');
atest(strpos($where,"a.academic_year_id=2024")!==false,'Solo año consultado');
$active=$historical;
$active['historical']=false;
atest(arq_roster_join($active)==='','Año actual conserva nómina vigente');
atest(strpos(arq_where($active,1),"s.nivel='Primaria'")!==false,
    'Año actual sigue filtrando aula actual');
$empty=$historical;$empty['classrooms']=[];
atest(strpos(arq_roster_join($empty),'1=0')!==false,
    'Sin matrícula histórica no se inventan alumnos');
$invalid=$historical;$invalid['valid_range']=false;
atest(strpos(arq_where($invalid,1),'1=0')!==false,
    'Rango fuera del año no devuelve registros');
echo "OK: 10 pruebas de asistencia histórica.\n";
