<?php

function edu_chat_router_explicit_grade(string $text): ?string {
    $patterns = [
        '/\b(?:grado\s*)?([1-6])\s*(?:ro|do|to|er|°)\b/',
        '/\bgrado\s+([1-6])\b/',
        '/\b([1-6])\s+grado\b/',
        '/\b(?:de|del)\s+([1-6])\s+(?:de\s+)?(?:primaria|secundaria)\b/'
    ];
    foreach ($patterns as $pattern) if (preg_match($pattern,$text,$m)) return (string)$m[1];
    return null;
}

function edu_chat_router_explicit_section(string $text): ?string {
    if (preg_match('/\bseccion\s+([a-z0-9]+)\b/',$text,$m)) return strtoupper((string)$m[1]);
    if (preg_match('/\b[1-6]\s*(?:ro|do|to|er|°)\s+([a-z])\b/',$text,$m)) return strtoupper((string)$m[1]);
    if (preg_match('/\bgrado\s+[1-6]\s+([a-z])\b/',$text,$m)) return strtoupper((string)$m[1]);
    return null;
}

function edu_chat_router_number_value(string $token): ?int {
    $token=trim($token);
    if($token!==''&&ctype_digit($token))return(int)$token;
    $map=['un'=>1,'uno'=>1,'una'=>1,'dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7,'ocho'=>8,'nueve'=>9,'diez'=>10,'once'=>11,'doce'=>12,'trece'=>13,'catorce'=>14,'quince'=>15,'dieciseis'=>16,'diecisiete'=>17,'dieciocho'=>18,'diecinueve'=>19,'veinte'=>20];
    return $map[$token]??null;
}

function edu_chat_router_count_minimum(string $text,string $metricPattern): ?int {
    $n='(?:[0-9]{1,3}|un|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieciseis|diecisiete|dieciocho|diecinueve|veinte)';
    if(preg_match('/\b(?:mas de|mayor(?:es)? a)\s+('.$n.')\s+'.$metricPattern.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);return$v===null?null:$v+1;}
    if(preg_match('/\b(?:al menos|como minimo|minimo)\s+('.$n.')\s+'.$metricPattern.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);return$v===null?null:max(1,$v);}
    if(preg_match('/\b('.$n.')\s+(?:o mas|o mayor)\s+'.$metricPattern.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);return$v===null?null:max(1,$v);}
    if(preg_match('/\bcon\s+('.$n.')\s+'.$metricPattern.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);return$v===null?null:max(1,$v);}
    return null;
}

function edu_chat_router_debt_count_range(string $text): ?array {
    $n='(?:[0-9]{1,3}|un|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieciseis|diecisiete|dieciocho|diecinueve|veinte)';
    $debt='(?:deuda|deudas|obligacion|obligaciones|cuota|cuotas|pension|pensiones)';
    if(preg_match('/\bentre\s+('.$n.')\s+(?:y|a)\s+('.$n.')\s+'.$debt.'\b/',$text,$m)){$a=edu_chat_router_number_value($m[1]);$b=edu_chat_router_number_value($m[2]);if($a!==null&&$b!==null)return['min'=>min($a,$b),'max'=>max($a,$b)];}
    if(preg_match('/\b(?:mas de|mayor(?:es)? a)\s+('.$n.')\s+'.$debt.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);if($v!==null)return['min'=>$v+1,'max'=>null];}
    if(preg_match('/\b(?:al menos|como minimo|minimo)\s+('.$n.')\s+'.$debt.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);if($v!==null)return['min'=>max(1,$v),'max'=>null];}
    if(preg_match('/\b('.$n.')\s+(?:o mas|o mayor)\s+'.$debt.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);if($v!==null)return['min'=>max(1,$v),'max'=>null];}
    if(preg_match('/\b(?:menos de|menor(?:es)? a)\s+('.$n.')\s+'.$debt.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);if($v!==null&&$v>1)return['min'=>1,'max'=>$v-1];}
    if(preg_match('/\b(?:hasta|como maximo|maximo)\s+('.$n.')\s+'.$debt.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);if($v!==null&&$v>=1)return['min'=>1,'max'=>$v];}
    if(preg_match('/\b(?:exactamente|solo)\s+('.$n.')\s+'.$debt.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);if($v!==null&&$v>=1)return['min'=>$v,'max'=>$v];}
    if(preg_match('/\bcon\s+('.$n.')\s+'.$debt.'\b/',$text,$m)){$v=edu_chat_router_number_value($m[1]);if($v!==null&&$v>=1)return['min'=>$v,'max'=>$v];}
    return null;
}

function edu_chat_router_requested_limit(string $text,int $default=100,int $max=200): int {
    $patterns=['/\b(?:dame|lista|listame|muestrame|mostrar)\s+(?:los\s+|las\s+)?([0-9]{1,3})\s+(?:estudiantes|alumnos|morosos)\b/','/\b(?:primeros|primeras|top)\s+([0-9]{1,3})\b/'];
    foreach($patterns as $pattern)if(preg_match($pattern,$text,$m))return max(1,min($max,(int)$m[1]));
    return $default;
}

function edu_chat_router_profile_name(string $text): ?string {
    $patterns=[
        '/\b(?:ficha|perfil)\s+(?:360\s+)?(?:del\s+|de la\s+|de\s+)?(?:estudiante\s+|alumno\s+|alumna\s+)?(.+)$/',
        '/\b(?:resumen|informacion completa|estado general)\s+(?:del\s+|de la\s+|de\s+)?(?:estudiante\s+|alumno\s+|alumna\s+)?(.+)$/'
    ];
    foreach($patterns as $pattern){
        if(!preg_match($pattern,$text,$m))continue;
        $name=trim((string)$m[1]);
        $name=preg_replace('/\s+(?:de\s+)?(?:inicial|primaria|secundaria)\b.*$/','',$name);
        $name=preg_replace('/\s+(?:de\s+)?[1-6]\s*(?:ro|do|to|er|°)(?:\s+[a-z])?\b.*$/','',$name);
        $name=preg_replace('/\s+seccion\s+[a-z0-9]+\b.*$/','',$name);
        $name=trim((string)$name);
        if($name!==''&&!in_array($name,['estudiante','alumno','alumna'],true))return $name;
    }
    return null;
}

function edu_chat_ai_forced_route(array $actor,string $message): ?array {
    $type=(int)($actor['type']??0);
    if(!in_array($type,[1,2,3],true))return null;
    $text=edu_chat_normalize($message);if($text==='')return null;

    $level=edu_chat_extract_level($text);$grade=edu_chat_router_explicit_grade($text);$section=edu_chat_router_explicit_section($text)?:edu_chat_extract_section($text);$period=edu_chat_extract_period($text);
    $args=[];if($level)$args['level']=$level;if($grade)$args['grade']=$grade;if($section)$args['section']=$section;if($period)$args['period']=$period;

    $isStudentTopic=edu_chat_has($text,['estudiante','estudiantes','alumno','alumnos','matricula','matriculados']);
    $isDebtTopic=edu_chat_has($text,['deuda','deudas','debe','deben','moroso','morosos','morosidad','pendiente','pendientes','obligacion','obligaciones','cuota','cuotas','pension','pensiones']);
    $isAttendanceTopic=edu_chat_has($text,['asistencia','asistencias','tardanza','tardanzas','ausencia','ausencias','ausente','ausentes','falto','faltaron','falta','faltas','presente','presentes','permiso','permisos','llego tarde','llegaron tarde']);
    $isRiskTopic=edu_chat_has($text,['riesgo','riesgos','critico','criticos','critica','criticas','nota baja','notas bajas','desaprobado','desaprobados','reprobado','reprobados','con c','tienen c']);
    $wantsBreakdown=edu_chat_has($text,['cada seccion','por seccion','por aula','cada aula','por grado','cada grado','por nivel','cada nivel','distribucion','desglose','desglosado','desglosada','secciones','aulas','nivel grado y seccion','nivel grado seccion']);
    $wantsRoster=edu_chat_has($text,['lista','listar','listame','quienes son','nombres de','muestrame','mostrar estudiantes','dime los alumnos','dime los estudiantes','quien falto','quienes faltaron','quien llego tarde','quienes llegaron tarde']);

    $groupBy='grade_section';
    if(edu_chat_has($text,['por nivel','cada nivel']))$groupBy='level';
    elseif(edu_chat_has($text,['por grado','cada grado'])&&!edu_chat_has($text,['seccion','aula']))$groupBy='grade';
    $args['group_by']=$groupBy;

    if($type===1){
        $profileName=edu_chat_router_profile_name($text);
        if($profileName!==null){$profileArgs=$args;unset($profileArgs['group_by'],$profileArgs['period']);$profileArgs['name_search']=$profileName;return['name'=>'get_student_360','arguments'=>$profileArgs];}
    }

    if($type===1&&$isDebtTopic){
        $range=edu_chat_router_debt_count_range($text);
        if($range!==null&&($isStudentTopic||$wantsRoster||edu_chat_has($text,['moroso','morosos']))){$debtArgs=$args;unset($debtArgs['period']);$debtArgs['min_obligations']=max(1,(int)$range['min']);if($range['max']!==null)$debtArgs['max_obligations']=max(1,(int)$range['max']);$debtArgs['limit']=edu_chat_router_requested_limit($text,100,200);$debtArgs['group_by']=$groupBy;$debtArgs['sort_by']='obligations';if(edu_chat_has($text,['por monto','mayor monto','mas dinero','mayor saldo','ordenado por deuda','ordenados por deuda']))$debtArgs['sort_by']='debt';elseif(edu_chat_has($text,['por nombre','alfabetico','alfabeticamente']))$debtArgs['sort_by']='name';elseif(edu_chat_has($text,['ordenado por aula','ordenados por aula','por ubicacion']))$debtArgs['sort_by']='location';return['name'=>'get_students_by_debt_count','arguments'=>$debtArgs];}
    }

    if($type===1&&$isDebtTopic&&edu_chat_has($text,['mas deben','mayor deuda','mayores deudores','top ','ranking','morosos con mas'])){$limit=10;if(preg_match('/\b([1-9]|1[0-9]|20)\b/',$text,$m))$limit=(int)$m[1];$debtArgs=$args;unset($debtArgs['group_by'],$debtArgs['period'],$debtArgs['section']);$debtArgs['limit']=$limit;return['name'=>'get_top_debtors','arguments'=>$debtArgs];}

    if(in_array($type,[1,3],true)&&$isAttendanceTopic){
        $nominal=$wantsRoster||edu_chat_has($text,['faltaron hoy','ausentes hoy','tardes hoy','llegaron tarde','con tardanzas','con ausencias','con faltas']);
        $min=edu_chat_router_count_minimum($text,'(?:tardanza|tardanzas|ausencia|ausencias|falta|faltas|permiso|permisos)');
        if($min!==null)$nominal=true;
        if($nominal){$a=$args;unset($a['group_by']);if(empty($a['period']))$a['period']=edu_chat_has($text,['hoy'])?'today':'month';$a['limit']=edu_chat_router_requested_limit($text,100,200);$a['min_occurrences']=$min??1;$a['status']='all';if(edu_chat_has($text,['tardanza','tardanzas','llego tarde','llegaron tarde']))$a['status']='late';elseif(edu_chat_has($text,['ausente','ausentes','ausencia','ausencias','falto','faltaron','falta','faltas']))$a['status']='absent';elseif(edu_chat_has($text,['permiso','permisos']))$a['status']='permission';elseif(edu_chat_has($text,['presente','presentes']))$a['status']='present';return['name'=>'get_attendance_roster','arguments'=>$a];}
    }

    if(in_array($type,[1,2],true)&&$isRiskTopic){
        $min=edu_chat_router_count_minimum($text,'(?:registro critico|registros criticos|nota critica|notas criticas|c)');
        if($wantsRoster||$min!==null||edu_chat_has($text,['quienes estan en riesgo','estudiantes en riesgo','alumnos en riesgo','nombres en riesgo'])){$r=$args;unset($r['group_by'],$r['period']);$r['min_critical_records']=$min??1;$r['limit']=edu_chat_router_requested_limit($text,100,200);return['name'=>'get_academic_risk_roster','arguments'=>$r];}
    }

    if($wantsBreakdown){
        if($type===1&&$isDebtTopic)return['name'=>'get_debt_distribution','arguments'=>$args];
        if(in_array($type,[1,3],true)&&$isAttendanceTopic){if(empty($args['period']))$args['period']='today';return['name'=>'get_attendance_distribution','arguments'=>$args];}
        if(in_array($type,[1,2],true)&&$isRiskTopic){if(edu_chat_has($text,['por curso','cada curso','por area']))$args['group_by']='course';return['name'=>'get_academic_risk_distribution','arguments'=>$args];}
        if($isStudentTopic)return['name'=>'get_student_distribution','arguments'=>$args];
    }

    if($wantsBreakdown&&edu_chat_has($text,['cuantos','cantidad','hay'])&&!$isDebtTopic&&!$isAttendanceTopic&&!$isRiskTopic)return['name'=>'get_student_distribution','arguments'=>$args];

    if($isStudentTopic&&$wantsRoster&&!edu_chat_has($text,['cuantos','cantidad'])){$rosterArgs=$args;unset($rosterArgs['group_by'],$rosterArgs['period']);$rosterArgs['limit']=20;if(preg_match('/\b([1-9]|[1-4][0-9]|50)\b/',$text,$m))$rosterArgs['limit']=(int)$m[1];return['name'=>'get_student_roster','arguments'=>$rosterArgs];}
    return null;
}
