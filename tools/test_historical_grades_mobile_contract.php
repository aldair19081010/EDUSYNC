<?php
/**
 * Contrato sin base de datos para la lectura del historial.
 * Ejecutar: php tools/test_historical_grades_mobile_contract.php
 */
declare(strict_types=1);
$root=dirname(__DIR__).'/edusync/';
$files=[
    'teacher'=>$root.'api/teacher_courses_mobile.php',
    'book'=>$root.'gradebook_api.php',
    'director'=>$root.'api/director_academic_mobile.php',
    'student'=>$root.'api/my_grades_v2.php',
    'period'=>$root.'includes/historical_grade_period.php',
];
foreach ($files as $key=>$path) {
    if (!is_file($path)) { fwrite(STDERR,"Falta $key\n"); exit(1); }
    $files[$key]=(string)file_get_contents($path);
}
function contract_has(string $source,string $literal,string $reason): void {
    if (strpos($source,$literal)===false) {
        fwrite(STDERR,"FALLÓ: $reason ($literal)\n");exit(1);
    }
}
contract_has($files['student'],'tc.academic_year_id','Año de asignación en la ficha estudiantil');
contract_has($files['teacher'],"'historical_grades'","La nómina docente informa su fuente");
contract_has($files['teacher'],"e.teacher_course_id=?","Nómina histórica acotada a la asignación");
contract_has($files['teacher'],"COUNT(DISTINCT st.id)","Conteo histórico no basado en el aula actual");
contract_has($files['teacher'],"edh_historical_bimester_sql('ev.bimestre')","Lectura del bimestre histórico normalizado");
contract_has($files['period'],"'IV','CUARTO'","Alias romanos y ordinales de periodos");
contract_has($files['period'],"'II','SEGUNDO'","Alias históricos del segundo bimestre");
require_once $root.'includes/historical_grade_period.php';
$periodSql=edh_historical_bimester_sql('ev.bimestre');
contract_has($periodSql,"CASE","SQL de periodos históricos");
contract_has($periodSql,"ELSE 1","Respaldo del primer bimestre como Estudiantes");
contract_has($files['book'],'$historicalRead',"Modo histórico del libro");
contract_has($files['book'],'$historicPeriodSql',"Filtro de bimestre histórico");
contract_has($files['book'],"edh_historical_bimester_sql('e.bimestre')","Libro comparte normalización");
contract_has($files['book'],'$historicalGradedCompetencies',"Competencias con notas reales");
contract_has($files['book'],'eg.competencia_id>0',"Competencia heredada de la nota");
contract_has($files['book'],"LEFT JOIN general_course_competencies gcc","Notas sin metadatos de competencia en el Libro");
contract_has($files['book'],"THEN gcc.percentage ELSE 0","Sin inventar ponderaciones en competencias antiguas");
contract_has($files['book'],'$yearReadOnly',"El historial nunca se vuelve editable");
contract_has($files['director'],"$historicalRead","Filtro histórico de Dirección");
contract_has($files['director'],"$historicalPeriod","Bimestres históricos de Dirección");
contract_has($files['director'],"edh_historical_bimester_sql('ev.bimestre')","Dirección comparte normalización");
contract_has($files['director'],'$gradedCompetencies',"Peso de competencias con notas");
contract_has($files['director'],"eg.competencia_id>0","Vínculos legacy de Dirección");
contract_has($files['director'],"LEFT JOIN general_course_competencies gcc","Respaldo sin metadata de competencia");
contract_has($files['director'],"THEN gcc.percentage ELSE 0","Dirección no inventa porcentajes");
contract_has($files['director'],"'read_only'=>true","Dirección solo consulta");
contract_has($files['director'],'tc.academic_year_id=?',"Año institucional validado");
echo "Contrato histórico Docentes/Dirección: OK\n";
