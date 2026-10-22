<?php
/** Contrato offline: seguridad y consistencia académica del rol Director. */
declare(strict_types=1);
$src=(string)file_get_contents(dirname(__DIR__).'/edusync/api/director_academic_mobile.php');
function guardDirector(bool $ok,string $msg): void {
    if (!$ok) { fwrite(STDERR,"[ERROR] $msg\n"); exit(1); }
}
foreach ([
    "if (\$action === 'change_password')",
    "hash_equals(session_id(), \$sid)",
    "HTTP_X_REQUESTED_WITH",
    "u.is_director=1",
    "type IN (1,2)",
    "password_verify(\$old,\$storedHash)",
    "password_hash(\$new,PASSWORD_DEFAULT)",
    "UPDATE users SET password=? WHERE id=? AND school_id=? AND is_director=1 AND password=?",
    "affected_rows",
] as $item) guardDirector(str_contains($src,$item),'Control de contraseña: '.$item);
foreach ([
    "grade_period_closures",
    "closure_status",
    "closed_courses",
    "closure_supported",
    "gpc.status='Cerrado'",
    "\$classrooms=[];",
    "\$byKey=[];",
    "empty(\$selectedYear['is_active'])",
] as $item) guardDirector(str_contains($src,$item),'Control histórico/cierre: '.$item);
guardDirector(str_contains($src,"'student_course'"),'Se conserva ficha de curso');
guardDirector(str_contains($src,"'attendance_overview'"),'Se conserva asistencia de Dirección');
echo "Contrato Dirección: contraseña, historial, cierre y permisos OK.\n";
