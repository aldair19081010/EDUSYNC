<?php
ini_set('session.save_path', __DIR__ . '/tmp');
session_name('EDUSYNCSESSID');
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/db_connect.php';

$school_id = intval($_SESSION['login_school_id'] ?? 0);
$login_id = intval($_SESSION['login_id'] ?? 0);
if (!$school_id || !$login_id) { http_response_code(401); exit('Sesión inválida.'); }

$role = $conn->prepare('SELECT type,is_director FROM users WHERE id=? LIMIT 1');
$role->bind_param('i',$login_id); $role->execute();
$type=0; $director=0; $role->bind_result($type,$director); $allowed=$role->fetch() && (int)$type===1; $role->close();
if (!$allowed) { http_response_code(403); exit('No tienes permisos para exportar asignaciones.'); }

$year_id = intval($_GET['year'] ?? 0);
$year = $conn->query("SELECT year FROM academic_year WHERE id=$year_id AND school_id=$school_id LIMIT 1");
if (!$year || !$year->num_rows) { http_response_code(400); exit('Año académico inválido.'); }
$year_name = $year->fetch_assoc()['year'];

$ids = array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_GET['ids'] ?? ''))),function($id){return $id>0;})));
$id_filter = $ids ? ' AND tc.id IN ('.implode(',',array_slice($ids,0,1000)).')' : '';
$result = $conn->query("SELECT t.id_no dni,t.name teacher_name,ac.name course_name,ac.level,tc.grado,tc.seccion,ay.year academic_year FROM teacher_courses tc INNER JOIN teacher t ON t.id=tc.teacher_id INNER JOIN academic_courses ac ON ac.id=tc.course_id INNER JOIN academic_year ay ON ay.id=tc.academic_year_id WHERE tc.school_id=$school_id AND tc.academic_year_id=$year_id$id_filter ORDER BY t.name,ac.name,tc.grado,tc.seccion");
if (!$result) { http_response_code(500); exit('No se pudo generar la exportación.'); }

$filename = 'asignaciones_docentes_' . preg_replace('/[^0-9A-Za-z_-]/','_',$year_name) . '_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-store, no-cache, must-revalidate');
$output=fopen('php://output','w');
fwrite($output,"\xEF\xBB\xBF");
fputcsv($output,['DNI','Docente','Curso','Nivel','Grado','Sección','Año académico'],',');
while($row=$result->fetch_assoc()) fputcsv($output,[$row['dni'],$row['teacher_name'],$row['course_name'],$row['level'],$row['grado'],$row['seccion'] ?: 'U',$row['academic_year']],',');
fclose($output);
exit;
