<?php
/**
 * Revisión offline de criterios de asistencia institucional.
 * No modifica MySQL; valida el helper compartido con Docentes/Familias.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/edusync/includes/attendance_history_helper.php';

function assertDirectorAttendance(bool $condition,string $message): void {
    if (!$condition) {
        fwrite(STDERR,"[ERROR] ".$message."\n");
        exit(1);
    }
}
foreach (['Normal'=>'Presente','Temprano'=>'Presente','Tarde'=>'Tarde',
    'Ausente'=>'Ausente','Ausente Justificada'=>'Ausente Justificada',
    'Permiso'=>'Permiso'] as $raw=>$expected) {
    assertDirectorAttendance(attendance_normalize_status($raw)===$expected,
        'Normalización incorrecta: '.$raw);
}

$raw = [
    ['fecha'=>'2026-10-21','tipo'=>'Salida','hora'=>'13:00:00','estado'=>'Normal',
     'anio_academico'=>'2026','anio_descripcion'=>''],
    ['fecha'=>'2026-10-21','tipo'=>'Entrada','hora'=>'07:40:00','estado'=>'Tarde',
     'anio_academico'=>'2026','anio_descripcion'=>''],
    ['fecha'=>'2026-10-21','tipo'=>'Entrada','hora'=>'07:45:00','estado'=>'Presente',
     'anio_academico'=>'2026','anio_descripcion'=>''],
];
$days=attendance_group_days($raw);
assertDirectorAttendance(count($days)===1,'Debe contarse una asistencia diaria, no una por marcación.');
assertDirectorAttendance($days[0]['estado']==='Tarde','La primera entrada decide el estado.');
assertDirectorAttendance($days[0]['entrada']==='07:40:00','La primera entrada debe conservarse.');
assertDirectorAttendance($days[0]['salida']==='13:00:00','La última salida debe conservarse.');
assertDirectorAttendance(count(attendance_group_days([]))===0,
    'Sin marcaciones significa sin registro, nunca ausencia automática.');

$source=(string)file_get_contents(dirname(__DIR__).'/edusync/api/director_academic_mobile.php');
foreach (['attendance_overview','attendance_classroom','attendance_student',
    'u.is_director=1','st.school_id=?','attendance_group_days',
    "'unmarked'", "'absent'", 'read_only'] as $part) {
    assertDirectorAttendance(str_contains($source,$part),'Falta control: '.$part);
}
echo "Asistencia de Dirección: helper diario y contrato de solo consulta OK.\n";
