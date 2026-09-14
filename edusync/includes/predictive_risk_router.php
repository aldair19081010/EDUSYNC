<?php

require_once __DIR__ . '/predictive_risk.php';
require_once __DIR__ . '/predictive_interventions.php';

function edu_predictive_is_query(string $message): bool {
    $n = function_exists('edu_chat_normalize') ? edu_chat_normalize($message) : strtolower(trim($message));
    $needles = [
        'riesgo predictivo','riesgo futuro','predecir riesgo','predice riesgo','prediccion de riesgo',
        'probabilidad de riesgo','proximo bimestre','siguiente bimestre','alerta temprana',
        'mayor riesgo futuro','mayor probabilidad de riesgo'
    ];
    foreach ($needles as $needle) if (strpos($n,$needle)!==false) return true;
    return false;
}

function edu_predictive_requested_name(string $message, array $history = []): string {
    $n = function_exists('edu_chat_normalize') ? edu_chat_normalize($message) : strtolower(trim($message));
    $patterns = [
        '/\b(?:riesgo predictivo|riesgo futuro|probabilidad de riesgo|predecir riesgo|predice riesgo)\s+(?:de|del|para)\s+(.+)$/',
        '/\b(?:por que|porque)\s+(?:esta|estaria)\s+en riesgo\s+(.+)$/',
    ];
    foreach($patterns as $pattern){
        if(!preg_match($pattern,$n,$m))continue;
        $name=trim((string)$m[1]);
        $name=preg_replace('/\s+(?:de\s+)?(?:inicial|primaria|secundaria)\b.*$/','',$name);
        $name=preg_replace('/\s+(?:de\s+)?[1-6]\s*(?:ro|do|to|er|°)(?:\s+[a-z])?\b.*$/','',$name);
        $name=preg_replace('/\s+seccion\s+[a-z0-9]+\b.*$/','',$name);
        $name=trim((string)$name," .,:;?¿!¡\t\n\r\0\x0B");
        if($name!==''&&!in_array($name,['alto','medio','bajo','el primero','la primera'],true))return $name;
    }

    if(strpos($n,'el primero')!==false||strpos($n,'la primera')!==false){
        for($i=count($history)-1;$i>=0;$i--){
            if(($history[$i]['role']??'')!=='assistant')continue;
            $text=(string)($history[$i]['text']??'');
            if(preg_match('/(?:^|\n)1\.\s+(.+?)\s+—\s+riesgo\s+/u',$text,$m))return trim((string)$m[1]);
        }
    }
    return '';
}

function edu_predictive_action_name(string $message,array $history=[]): string {
    $n=function_exists('edu_chat_normalize')?edu_chat_normalize($message):strtolower(trim($message));
    $patterns=[
        '/\b(?:tendria que mejorar|deberia mejorar|puede mejorar|mejorar)\s+(.+?)(?:\s+para\b|\s+si\b|$)/',
        '/\b(?:bajar|reducir|disminuir)\s+(?:el\s+)?riesgo\s+(?:de|del)\s+(.+?)(?:\s+para\b|\s+si\b|$)/',
        '/\b(?:simula|simular|escenario)\b.*?\briesgo\s+(?:de|del)\s+(.+?)(?:\s+para\b|\s+si\b|$)/',
        '/\b(?:intervenciones|intervencion|seguimiento)\s+(?:de|del|para)\s+(.+?)$/',
    ];
    foreach($patterns as $pattern){
        if(!preg_match($pattern,$n,$m))continue;
        $name=trim((string)$m[1]," .,:;?¿!¡\t\n\r\0\x0B");
        if($name!==''&&!in_array($name,['alto','medio','bajo','el primero','la primera'],true))return $name;
    }
    return edu_predictive_requested_name($message,$history);
}

function edu_predictive_detail_name(string $message,array $history=[]): string {
    $n=function_exists('edu_chat_normalize')?edu_chat_normalize($message):strtolower(trim($message));
    $patterns=[
        '/\b(?:factores? criticos?|factores? de riesgo|cursos? criticos?|registros? criticos?|registros? de cursos?|dias? de tardanzas?|dias? de tardanza|tardanzas?|ausencias?)\s+(?:de|del|para)\s+(.+)$/',
        '/\b(?:detalle del riesgo|detalle de riesgo|explica(?:me)? el riesgo)\s+(?:de|del|para)\s+(.+)$/',
        '/\b(?:por que|porque)\s+(?:tiene|presenta)\s+(?:ese\s+)?riesgo\s+(.+)$/',
    ];
    foreach($patterns as $pattern){
        if(!preg_match($pattern,$n,$m))continue;
        $name=trim((string)$m[1]," .,:;?¿!¡\t\n\r\0\x0B");
        if($name!==''&&!in_array($name,['alto','medio','bajo','el primero','la primera'],true))return $name;
    }
    return edu_predictive_requested_name($message,$history);
}

function edu_predictive_critical_course_breakdown(mysqli $conn,array $actor,int $studentId,int $bimester): array {
    $schoolId=(int)($actor['school_id']??0);
    $year=edu_predictive_academic_year($conn,$schoolId,null);
    if(!$year)return[];
    $rows=edu_predictive_student_grade_rows($conn,$studentId,$schoolId,(int)$year['id'],$bimester);
    $courses=[];
    foreach($rows as $row){
        if(!edu_predictive_grade_is_critical($row['grade']??null))continue;
        $name=trim((string)($row['course_name']??'Sin curso')) ?: 'Sin curso';
        if(!isset($courses[$name]))$courses[$name]=['course'=>$name,'records'=>0,'grades'=>[]];
        $courses[$name]['records']++;
        $grade=trim((string)($row['grade']??''));
        if($grade!=='')$courses[$name]['grades'][]=$grade;
    }
    uasort($courses,static fn($a,$b)=>($b['records']<=>$a['records']) ?: strcmp($a['course'],$b['course']));
    return array_values($courses);
}

function edu_predictive_detail_chat_result(mysqli $conn,array $actor,string $name,array $entities=[]): array {
    $name=trim($name);
    if($name===''){
        $result=edu_chat_result('Indica el nombre del estudiante. Por ejemplo: “Dime los factores críticos de Juan Pérez”.');
        $result['tools_used']=['predictive_risk_detail'];
        return$result;
    }
    $students=edu_predictive_students($conn,$actor,$entities,$name,8);
    if(!$students){$result=edu_chat_result('No encontré un estudiante activo que coincida con ese nombre.');$result['tools_used']=['predictive_risk_detail'];return$result;}
    if(count($students)>1){
        $lines=[];foreach($students as $i=>$student)$lines[]=($i+1).'. '.$student['name'].' — '.$student['nivel'].' · '.$student['grado'].'° '.$student['seccion'];
        $result=edu_chat_result("Encontré varias coincidencias. Especifica el nombre completo o el aula:\n".implode("\n",$lines));$result['tools_used']=['predictive_risk_detail'];return$result;
    }
    $student=$students[0];
    $requestedBimester=!empty($entities['bimestre'])?(int)$entities['bimestre']:null;
    $prediction=edu_predictive_student_prediction($conn,$actor,(int)$student['id'],$requestedBimester);
    if(empty($prediction['available'])){$result=edu_chat_result('No hay un bimestre cerrado con datos suficientes para detallar el riesgo de '.$student['name'].'.');$result['tools_used']=['predictive_risk_detail'];return$result;}

    $f=(array)$prediction['features'];
    $base=$prediction['bimester_label']??edu_predictive_bimester_label((int)$prediction['bimester']);
    $target=$prediction['target_bimester_label']??edu_predictive_bimester_label((int)$prediction['target_bimester']);
    $lines=[];
    $lines[]=$student['name'].' — riesgo estimado en el '.$target.' bimestre: '.$prediction['level'].' ('.number_format((float)$prediction['probability']*100,1).'%).';
    $lines[]='Base del cálculo: '.$base.' bimestre cerrado, corte '.($prediction['base_closed_at']??'sin fecha').'.';
    $lines[]='Promedio del bimestre base: '.number_format((float)($f['grade_mean_current']??0),2).'.';

    if((float)($f['previous_bimester_available']??0)>=0.5){
        $trend=(float)($f['grade_trend']??0);
        $direction=$trend>0.05?'mejoró':($trend<-0.05?'disminuyó':'se mantuvo estable');
        $lines[]='Tendencia académica: '.$direction.' ('.($trend>=0?'+':'').number_format($trend,2).' puntos respecto al bimestre anterior).';
    }else{
        $lines[]='Tendencia académica: no existe un bimestre previo comparable; no se interpreta como estabilidad.';
    }

    $criticalRecords=(int)round((float)($f['critical_records_current']??0));
    $criticalCourses=(int)round((float)($f['critical_courses_current']??0));
    $lines[]='Registros críticos: '.$criticalRecords.' en '.$criticalCourses.' curso(s).';
    $breakdown=edu_predictive_critical_course_breakdown($conn,$actor,(int)$student['id'],(int)$prediction['bimester']);
    if($breakdown){
        $lines[]='Cursos con registros críticos:';
        foreach($breakdown as $course){
            $gradeText=$course['grades']?' · notas/registros: '.implode(', ',array_slice($course['grades'],0,8)):'';
            if(count($course['grades'])>8)$gradeText.='…';
            $lines[]='• '.$course['course'].': '.$course['records'].' registro(s) crítico(s)'.$gradeText.'.';
        }
    }else{
        $lines[]='Cursos con registros críticos: ninguno en el bimestre base.';
    }

    if(!empty($prediction['data_quality']['attendance_available'])){
        $lines[]='Asistencia en los 30 días previos al cierre: '.number_format((float)$f['attendance_rate_30d'],1).'%. Tardanzas: '.(int)round((float)$f['late_30d']).' día(s). Ausencias: '.(int)round((float)$f['absent_30d']).' día(s).';
    }else{
        $lines[]='Asistencia/tardanzas/ausencias: no hay registros suficientes en la ventana de 30 días; el modelo los trató como datos faltantes.';
    }

    $raises=(array)($prediction['explanation']['raises']??[]);
    if($raises){
        $lines[]='Factores que más elevan el riesgo según el modelo:';
        foreach(array_slice($raises,0,5) as $factor)$lines[]='• '.edu_predictive_format_factor($factor).'.';
    }else{
        $lines[]='El modelo no encontró un factor individual dominante que eleve el riesgo por encima del umbral de explicación.';
    }
    $lines[]='Estos factores explican la estimación del modelo; no significan que una sola variable sea la causa del resultado.';
    $result=edu_chat_result(implode("\n",$lines),['¿Qué tendría que mejorar este estudiante?','Simula cómo bajar su riesgo','Muéstrame sus intervenciones']);
    $result['tools_used']=['predictive_risk_detail'];
    return$result;
}

function edu_predictive_model_report_result(): array {
    $model=edu_predictive_model_load();
    if(empty($model['available']))return edu_chat_result(edu_predictive_model_unavailable_message($model));
    $training=(array)($model['training']??[]);$metrics=(array)($model['metrics']['holdout']??[]);$bench=(array)($model['metrics']['benchmark_random_forest']??[]);
    $lines=[];
    $lines[]='Modelo de producción: regresión logística explicable.';
    $lines[]='Objetivo: estimar riesgo de presentar al menos un curso crítico en el bimestre siguiente.';
    if(isset($training['rows']))$lines[]='Entrenamiento: '.(int)$training['rows'].' observaciones de '.(int)($training['students']??0).' estudiantes.';
    if(isset($metrics['roc_auc'])&&$metrics['roc_auc']!==null)$lines[]='ROC-AUC holdout: '.number_format((float)$metrics['roc_auc'],3).'.';
    if(isset($metrics['f1']))$lines[]='F1 holdout: '.number_format((float)$metrics['f1'],3).'.';
    if(isset($metrics['recall']))$lines[]='Recall holdout: '.number_format((float)$metrics['recall'],3).'.';
    if(isset($bench['roc_auc'])&&$bench['roc_auc']!==null)$lines[]='Benchmark Random Forest ROC-AUC: '.number_format((float)$bench['roc_auc'],3).'.';
    $lines[]='La evaluación separa estudiantes entre entrenamiento y prueba para reducir fuga de información.';
    $lines[]='La alerta apoya la decisión humana; no determina automáticamente acciones sobre el estudiante.';
    $result=edu_chat_result(implode("\n",$lines));$result['tools_used']=['predictive_risk_model_report'];return$result;
}

function edu_predictive_try(mysqli $conn,array $actor,string $message,array $state=[],array $history=[]): ?array {
    $n=function_exists('edu_chat_normalize')?edu_chat_normalize($message):strtolower(trim($message));
    if((int)($actor['type']??0)!==1)return null;
    $entities=function_exists('edu_chat_adv_entities')?edu_chat_adv_entities($n,$state):[];

    $isCounterfactual=edu_chat_has($n,['tendria que mejorar','deberia mejorar','bajar el riesgo','reducir el riesgo','disminuir el riesgo','simula el riesgo','simular el riesgo','escenario de mejora','escenario para bajar']);
    if($isCounterfactual){
        $name=edu_predictive_action_name($message,$history);
        return edu_risk_counterfactual_chat_result($conn,$actor,$name,$entities);
    }

    if(edu_chat_has($n,['intervencion','intervenciones','seguimiento de riesgo','seguimiento del riesgo'])){
        $name=edu_predictive_action_name($message,$history);
        return edu_risk_interventions_chat_result($conn,$actor,$name,$entities);
    }

    $isDetail=edu_chat_has($n,[
        'factores criticos','factor critico','factores de riesgo','factor de riesgo','cursos criticos','curso critico',
        'registros criticos','registro critico','registros de cursos','dias de tardanza','dias de tardanzas','tardanzas',
        'ausencias','detalle del riesgo','detalle de riesgo','explica el riesgo','explicame el riesgo','por que tiene riesgo','porque tiene riesgo'
    ]);
    if($isDetail){
        $name=edu_predictive_detail_name($message,$history);
        return edu_predictive_detail_chat_result($conn,$actor,$name,$entities);
    }

    if((strpos($n,'modelo predictivo')!==false||strpos($n,'precision del modelo')!==false||strpos($n,'metricas del modelo')!==false||strpos($n,'rendimiento del modelo')!==false)
       && (strpos($n,'precision')!==false||strpos($n,'metrica')!==false||strpos($n,'rendimiento')!==false||strpos($n,'modelo predictivo')!==false)){
        return edu_predictive_model_report_result();
    }

    if(!edu_predictive_is_query($message))return null;
    $name=edu_predictive_requested_name($message,$history);
    $limit=30;
    if(preg_match('/\b(?:top|primeros|primeras|dame|muestrame|lista)\s+(?:los\s+|las\s+)?([1-9]|[1-4][0-9]|50)\b/',$n,$m))$limit=(int)$m[1];
    return edu_predictive_chat_result($conn,$actor,$entities,$name,$limit);
}
