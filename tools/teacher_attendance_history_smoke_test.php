<?php
// Ejecutar desde la raíz: php tools/teacher_attendance_history_smoke_test.php
require_once __DIR__ . '/../edusync/includes/attendance_history_helper.php';

function expect_same($actual, $expected, string $message): void {
    if ($actual !== $expected) {
        throw new RuntimeException($message.'; esperado='.json_encode($expected).
            '; recibido='.json_encode($actual));
    }
}
function mark(string $date, string $type, ?string $time, string $status): array {
    return [
        'fecha'=>$date,'tipo'=>$type,'hora'=>$time,'estado'=>$status,
        'anio_academico'=>'2026','anio_descripcion'=>''
    ];
}

// Un día con entradas repetidas cuenta una vez; usa la primera entrada y
// la última salida. Se conserva la tardanza de la entrada elegida.
$days = attendance_group_days([
    mark('2026-10-05','Salida','15:00:00','Normal'),
    mark('2026-10-05','Entrada','08:10:00','Normal'),
    mark('2026-10-05','entrada','08:00:00','Tarde'),
    mark('2026-10-05','SALIDA','14:00:00','Normal')
]);
expect_same(count($days),1,'Un solo día por estudiante');
expect_same($days[0]['entrada'],'08:00:00','Primera entrada');
expect_same($days[0]['salida'],'15:00:00','Última salida');
expect_same($days[0]['estado'],'Tarde','Estado de la primera entrada');
expect_same($days[0]['marcaciones'],4,'No perder marcaciones originales');

// Estos días quedaban fuera de la consulta docente que filtraba Entrada.
$days = attendance_group_days([
    mark('2026-10-31 17:30:00','Salida','17:30:00','Normal'),
    mark('2026-10-30','','00:00:00','ausencia justificada'),
    mark('2026-10-29','Otro',null,'Permiso')
]);
expect_same(count($days),3,'Conservar salida y tipos históricos');
expect_same($days[0]['fecha'],'2026-10-31','Normalizar fecha con hora');
expect_same($days[0]['entrada'],null,'No inventar una entrada');
expect_same($days[0]['salida'],'17:30:00','Conservar salida sin entrada');
expect_same($days[0]['estado'],'Presente','Normalizar Normal como la sesión familiar');
expect_same($days[1]['estado'],'Ausente Justificada','Ausencia justificada');
expect_same($days[1]['entrada'],null,'Hora vacía no es una entrada real');
expect_same($days[2]['estado'],'Permiso','Conservar permiso');
expect_same(attendance_normalize_status(''),'Sin estado','No contar estado vacío como presente');
expect_same(attendance_group_days([]),[],'Mes sin registros');
expect_same(
    attendance_normalize_classroom(['level'=>' Secundaria ','grado'=>'4º','seccion'=>' b ']),
    ['secundaria','4','B'],
    'Normalizar aula sin ampliar a otro nivel'
);
expect_same(
    attendance_normalize_classroom(['level'=>'Primaria','grado'=>'4°','seccion'=>'']),
    ['primaria','4','U'],
    'Sección única'
);
echo "OK: historial compartido de asistencia\n";
