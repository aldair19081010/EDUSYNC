<?php

/**
 * Motor de riesgo académico predictivo y explicable para EduSync.
 *
 * El modelo se entrena fuera de producción y se exporta como JSON. PHP solo
 * realiza inferencia de una regresión logística sobre variables académicas y
 * de asistencia. No se usan datos financieros como predictores para evitar
 * introducir un proxy socioeconómico en la alerta académica.
 */

function edu_predictive_table_exists(mysqli $conn, string $table): bool {
    if (function_exists('edu_chat_table_exists')) return edu_chat_table_exists($conn, $table);
    $stmt = $conn->prepare('SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function edu_predictive_column_exists(mysqli $conn, string $table, string $column): bool {
    if (function_exists('edu_chat_column_exists')) return edu_chat_column_exists($conn, $table, $column);
    $stmt = $conn->prepare('SELECT COUNT(*) total FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function edu_predictive_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '' || !$params) return;
    $refs = [];
    $refs[] = &$types;
    foreach ($params as $i => $value) $refs[] = &$params[$i];
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function edu_predictive_model_path(): string {
    $custom = trim((string)getenv('EDUSYNC_RISK_MODEL_PATH'));
    if ($custom !== '') return $custom;
    return dirname(__DIR__) . '/storage/ai_models/risk_model.json';
}

function edu_predictive_model_load(): array {
    $path = edu_predictive_model_path();
    if (!is_file($path) || !is_readable($path)) {
        return ['available'=>false,'reason'=>'model_missing','path'=>$path];
    }
    $raw = file_get_contents($path);
    $model = json_decode((string)$raw, true);
    if (!is_array($model)) return ['available'=>false,'reason'=>'model_invalid','path'=>$path];
    $features = (array)($model['features'] ?? []);
    $coefficients = (array)($model['coefficients'] ?? []);
    $mean = (array)($model['scaler']['mean'] ?? []);
    $scale = (array)($model['scaler']['scale'] ?? []);
    if (($model['model_type'] ?? '') !== 'logistic_regression' || !$features || !$coefficients || !$mean || !$scale || !isset($model['intercept'])) {
        return ['available'=>false,'reason'=>'model_schema','path'=>$path];
    }
    foreach ($features as $feature) {
        if (!array_key_exists($feature, $coefficients) || !array_key_exists($feature, $mean) || !array_key_exists($feature, $scale)) {
            return ['available'=>false,'reason'=>'model_schema','path'=>$path];
        }
    }
    $model['available'] = true;
    $model['path'] = $path;
    return $model;
}

function edu_predictive_grade_value($grade): ?float {
    $value = strtoupper(trim((string)$grade));
    if ($value === '') return null;
    if (is_numeric(str_replace(',', '.', $value))) return (float)str_replace(',', '.', $value);
    $map = ['C'=>5.0,'B'=>12.0,'A'=>15.0,'AD'=>19.0];
    return $map[$value] ?? null;
}

function edu_predictive_grade_is_critical($grade): bool {
    $value = strtoupper(trim((string)$grade));
    if ($value === 'C') return true;
    if ($value !== '' && is_numeric(str_replace(',', '.', $value))) return (float)str_replace(',', '.', $value) <= 10.0;
    return false;
}

function edu_predictive_academic_year(mysqli $conn, int $schoolId, ?int $yearId = null): ?array {
    if (!edu_predictive_table_exists($conn, 'academic_year')) return null;
    if ($yearId !== null && $yearId > 0) {
        $stmt = $conn->prepare('SELECT id,school_id,start_date,end_date,is_active FROM academic_year WHERE id=? AND school_id=? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('ii', $yearId, $schoolId);
    } else {
        $stmt = $conn->prepare('SELECT id,school_id,start_date,end_date,is_active FROM academic_year WHERE school_id=? ORDER BY is_active DESC,end_date DESC,id DESC LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $schoolId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function edu_predictive_student_grade_rows(mysqli $conn, int $studentId, int $schoolId, int $yearId, int $bimester): array {
    foreach (['evaluation_grades','evaluations','teacher_courses'] as $table) if (!edu_predictive_table_exists($conn, $table)) return [];
    $where = ['eg.student_id=?','tc.school_id=?','CAST(e.bimestre AS UNSIGNED)=?'];
    $types = 'iii';
    $params = [$studentId,$schoolId,$bimester];
    if ($yearId > 0 && edu_predictive_column_exists($conn,'teacher_courses','academic_year_id')) {
        $where[] = 'tc.academic_year_id=?';
        $types .= 'i';
        $params[] = $yearId;
    }
    $courseSelect = "'Sin curso' course_name,0 course_id";
    $courseJoin = '';
    if (edu_predictive_table_exists($conn,'academic_courses') && edu_predictive_column_exists($conn,'teacher_courses','course_id')) {
        $courseSelect = "COALESCE(NULLIF(TRIM(ac.name),''),'Sin curso') course_name,COALESCE(ac.id,0) course_id";
        $courseJoin = ' LEFT JOIN academic_courses ac ON ac.id=tc.course_id';
    }
    $sql = "SELECT eg.grade,$courseSelect
            FROM evaluation_grades eg
            INNER JOIN evaluations e ON e.id=eg.evaluation_id
            INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
            $courseJoin
            WHERE " . implode(' AND ', $where);
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    edu_predictive_bind($stmt,$types,$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function edu_predictive_grade_features(mysqli $conn, int $studentId, int $schoolId, int $yearId, int $bimester): array {
    $rows = edu_predictive_student_grade_rows($conn,$studentId,$schoolId,$yearId,$bimester);
    $values = [];
    $criticalRecords = 0;
    $criticalCourses = [];
    foreach ($rows as $row) {
        $numeric = edu_predictive_grade_value($row['grade'] ?? null);
        if ($numeric !== null) $values[] = $numeric;
        if (edu_predictive_grade_is_critical($row['grade'] ?? null)) {
            $criticalRecords++;
            $courseKey = (int)($row['course_id'] ?? 0) > 0 ? 'id:' . (int)$row['course_id'] : 'name:' . (string)($row['course_name'] ?? 'Sin curso');
            $criticalCourses[$courseKey] = (string)($row['course_name'] ?? 'Sin curso');
        }
    }
    return [
        'grade_mean'=>count($values) ? array_sum($values) / count($values) : null,
        'grade_records'=>count($rows),
        'critical_records'=>$criticalRecords,
        'critical_courses'=>count($criticalCourses),
        'critical_course_names'=>array_values($criticalCourses)
    ];
}

function edu_predictive_latest_bimester(mysqli $conn, int $studentId, int $schoolId, int $yearId): ?int {
    foreach (['evaluation_grades','evaluations','teacher_courses'] as $table) if (!edu_predictive_table_exists($conn,$table)) return null;
    $where = ['eg.student_id=?','tc.school_id=?','CAST(e.bimestre AS UNSIGNED) BETWEEN 1 AND 4'];
    $types='ii'; $params=[$studentId,$schoolId];
    if ($yearId > 0 && edu_predictive_column_exists($conn,'teacher_courses','academic_year_id')) {
        $where[]='tc.academic_year_id=?'; $types.='i'; $params[]=$yearId;
    }
    $sql='SELECT MAX(CAST(e.bimestre AS UNSIGNED)) b FROM evaluation_grades eg INNER JOIN evaluations e ON e.id=eg.evaluation_id INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id WHERE '.implode(' AND ',$where);
    $stmt=$conn->prepare($sql); if(!$stmt)return null;
    edu_predictive_bind($stmt,$types,$params); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    $b=(int)($row['b']??0); return $b>=1&&$b<=4?$b:null;
}

function edu_predictive_attendance_features(mysqli $conn, int $studentId, string $cutoffDate, int $windowDays = 30): array {
    $out = ['attendance_rate_30d'=>100.0,'late_30d'=>0.0,'absent_30d'=>0.0,'attendance_records_30d'=>0];
    if (!edu_predictive_table_exists($conn,'asistencia')) return $out;
    $windowDays = max(7,min(120,$windowDays));
    $end = date('Y-m-d', strtotime($cutoffDate));
    $start = date('Y-m-d', strtotime($end . ' -' . ($windowDays - 1) . ' days'));
    $cancel = edu_predictive_column_exists($conn,'asistencia','is_cancelled') ? ' AND COALESCE(a.is_cancelled,0)=0' : '';
    $sql = "SELECT d.estado,COUNT(*) total FROM (
                SELECT a.fecha,CASE
                    WHEN SUM(LOWER(TRIM(a.estado))='tarde')>0 THEN 'Tarde'
                    WHEN SUM(LOWER(TRIM(a.estado)) IN ('presente','normal','temprano'))>0 THEN 'Presente'
                    WHEN SUM(LOWER(TRIM(a.estado))='permiso')>0 THEN 'Permiso'
                    WHEN SUM(LOWER(TRIM(a.estado)) IN ('ausente justificada','ausencia justificada'))>0 THEN 'Ausente Justificada'
                    ELSE 'Ausente' END estado
                FROM asistencia a
                WHERE a.student_id=? AND a.tipo='Entrada' AND a.fecha BETWEEN ? AND ? $cancel
                GROUP BY a.fecha
            ) d GROUP BY d.estado";
    $stmt=$conn->prepare($sql); if(!$stmt)return $out;
    $stmt->bind_param('iss',$studentId,$start,$end); $stmt->execute(); $res=$stmt->get_result();
    $present=0;$late=0;$absent=0;$records=0;
    while($row=$res->fetch_assoc()){
        $count=(int)($row['total']??0); $records+=$count;
        if($row['estado']==='Presente')$present+=$count;
        elseif($row['estado']==='Tarde')$late+=$count;
        elseif($row['estado']==='Ausente')$absent+=$count;
    }
    $stmt->close();
    $out['attendance_records_30d']=$records;
    $out['late_30d']=(float)$late;
    $out['absent_30d']=(float)$absent;
    $out['attendance_rate_30d']=$records>0?(($present+$late)/$records)*100.0:100.0;
    return $out;
}

function edu_predictive_feature_vector(mysqli $conn, int $studentId, int $schoolId, int $yearId, int $bimester, ?string $cutoffDate = null, int $attendanceWindowDays = 30): array {
    $current=edu_predictive_grade_features($conn,$studentId,$schoolId,$yearId,$bimester);
    $previous=$bimester>1?edu_predictive_grade_features($conn,$studentId,$schoolId,$yearId,$bimester-1):['grade_mean'=>null,'grade_records'=>0,'critical_records'=>0,'critical_courses'=>0,'critical_course_names'=>[]];
    $currentMean=$current['grade_mean']!==null?(float)$current['grade_mean']:0.0;
    $previousMean=$previous['grade_mean']!==null?(float)$previous['grade_mean']:$currentMean;
    $cutoffDate=$cutoffDate?:date('Y-m-d');
    $attendance=edu_predictive_attendance_features($conn,$studentId,$cutoffDate,$attendanceWindowDays);
    return [
        'grade_mean_current'=>$currentMean,
        'grade_mean_previous'=>$previousMean,
        'grade_trend'=>$currentMean-$previousMean,
        'critical_records_current'=>(float)$current['critical_records'],
        'critical_courses_current'=>(float)$current['critical_courses'],
        'attendance_rate_30d'=>(float)$attendance['attendance_rate_30d'],
        'late_30d'=>(float)$attendance['late_30d'],
        'absent_30d'=>(float)$attendance['absent_30d'],
        '_grade_records_current'=>(int)$current['grade_records'],
        '_critical_course_names'=>(array)$current['critical_course_names'],
        '_attendance_records'=>(int)$attendance['attendance_records_30d']
    ];
}

function edu_predictive_target_next_bimester(mysqli $conn,int $studentId,int $schoolId,int $yearId,int $bimester): ?int {
    if($bimester<1||$bimester>=4)return null;
    $next=edu_predictive_grade_features($conn,$studentId,$schoolId,$yearId,$bimester+1);
    if((int)$next['grade_records']===0)return null;
    return (int)$next['critical_courses']>0?1:0;
}

function edu_predictive_sigmoid(float $z): float {
    if ($z >= 0) return 1.0 / (1.0 + exp(-min($z,700.0)));
    $ez = exp(max($z,-700.0));
    return $ez / (1.0 + $ez);
}

function edu_predictive_score(array $features, array $model): array {
    $z=(float)$model['intercept'];
    $contributions=[];
    foreach((array)$model['features'] as $feature){
        $x=(float)($features[$feature]??0.0);
        $mean=(float)($model['scaler']['mean'][$feature]??0.0);
        $scale=(float)($model['scaler']['scale'][$feature]??1.0); if(abs($scale)<1e-12)$scale=1.0;
        $standardized=($x-$mean)/$scale;
        $coef=(float)($model['coefficients'][$feature]??0.0);
        $contribution=$coef*$standardized;
        $z+=$contribution;
        $contributions[$feature]=['raw'=>$x,'standardized'=>$standardized,'coefficient'=>$coef,'contribution'=>$contribution];
    }
    $probability=edu_predictive_sigmoid($z);
    $medium=(float)($model['risk_thresholds']['medium']??0.40);
    $high=(float)($model['risk_thresholds']['high']??0.70);
    $level=$probability>=$high?'Alto':($probability>=$medium?'Medio':'Bajo');
    uasort($contributions,static fn($a,$b)=>abs($b['contribution'])<=>abs($a['contribution']));
    return ['probability'=>$probability,'level'=>$level,'logit'=>$z,'contributions'=>$contributions];
}

function edu_predictive_feature_label(string $feature): string {
    $labels=[
        'grade_mean_current'=>'promedio académico actual',
        'grade_mean_previous'=>'promedio académico previo',
        'grade_trend'=>'tendencia del rendimiento',
        'critical_records_current'=>'cantidad de registros críticos actuales',
        'critical_courses_current'=>'cantidad de cursos con registros críticos',
        'attendance_rate_30d'=>'porcentaje de asistencia de los últimos 30 días',
        'late_30d'=>'tardanzas de los últimos 30 días',
        'absent_30d'=>'ausencias de los últimos 30 días'
    ];
    return $labels[$feature]??$feature;
}

function edu_predictive_explanation(array $score,int $maxFactors=4): array {
    $up=[];$down=[];
    foreach($score['contributions'] as $feature=>$info){
        $value=(float)$info['contribution'];
        if(abs($value)<0.05)continue;
        $item=['feature'=>$feature,'label'=>edu_predictive_feature_label($feature),'value'=>(float)$info['raw'],'impact'=>$value];
        if($value>0)$up[]=$item; else $down[]=$item;
    }
    return ['raises'=>array_slice($up,0,$maxFactors),'reduces'=>array_slice($down,0,$maxFactors)];
}

function edu_predictive_student_prediction(mysqli $conn,array $actor,int $studentId,?int $bimester=null): array {
    $schoolId=(int)($actor['school_id']??0);
    $year=edu_predictive_academic_year($conn,$schoolId,null);
    if(!$year)return ['available'=>false,'reason'=>'academic_year'];
    $yearId=(int)$year['id'];
    $bimester=$bimester?:edu_predictive_latest_bimester($conn,$studentId,$schoolId,$yearId);
    if(!$bimester)return ['available'=>false,'reason'=>'no_academic_data'];
    if($bimester>=4)return ['available'=>false,'reason'=>'no_next_bimester','bimester'=>$bimester];
    $model=edu_predictive_model_load();
    if(empty($model['available']))return $model;
    $window=(int)($model['training']['attendance_window_days']??30);
    $features=edu_predictive_feature_vector($conn,$studentId,$schoolId,$yearId,$bimester,date('Y-m-d'),$window);
    if((int)$features['_grade_records_current']===0)return ['available'=>false,'reason'=>'no_academic_data'];
    $score=edu_predictive_score($features,$model);
    return [
        'available'=>true,
        'bimester'=>$bimester,
        'target_bimester'=>$bimester+1,
        'features'=>$features,
        'probability'=>$score['probability'],
        'level'=>$score['level'],
        'explanation'=>edu_predictive_explanation($score),
        'model'=>$model
    ];
}

function edu_predictive_apply_student_filters(array $entities,string $alias,array &$where,string &$types,array &$params): void {
    if(!empty($entities['level'])){$where[]="LOWER(TRIM($alias.nivel))=LOWER(TRIM(?))";$types.='s';$params[]=(string)$entities['level'];}
    if(!empty($entities['grade'])){$where[]="CAST($alias.grado AS UNSIGNED)=?";$types.='i';$params[]=(int)$entities['grade'];}
    if(!empty($entities['section'])){$where[]="UPPER(TRIM($alias.seccion))=UPPER(TRIM(?))";$types.='s';$params[]=(string)$entities['section'];}
}

function edu_predictive_students(mysqli $conn,array $actor,array $entities=[],string $nameSearch='',int $limit=80): array {
    $schoolId=(int)($actor['school_id']??0); if($schoolId<=0||!edu_predictive_table_exists($conn,'student'))return [];
    $where=['s.school_id=?',"LOWER(TRIM(COALESCE(s.status,'Activo'))) IN ('activo','active')"];$types='i';$params=[$schoolId];
    edu_predictive_apply_student_filters($entities,'s',$where,$types,$params);
    if(trim($nameSearch)!==''){$where[]='LOWER(s.name) LIKE LOWER(?)';$types.='s';$params[]='%'.trim($nameSearch).'%';}
    $limit=max(1,min(200,$limit));
    $sql="SELECT s.id,s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'Sin sección') seccion FROM student s WHERE ".implode(' AND ',$where)." ORDER BY FIELD(s.nivel,'Inicial','Primaria','Secundaria'),CAST(s.grado AS UNSIGNED),s.grado,s.seccion,s.name LIMIT $limit";
    $stmt=$conn->prepare($sql);if(!$stmt)return[];edu_predictive_bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;
}

function edu_predictive_format_factor(array $factor): string {
    $feature=(string)$factor['feature'];$raw=(float)$factor['value'];$label=(string)$factor['label'];
    if($feature==='attendance_rate_30d')return $label.': '.number_format($raw,1).'%';
    if(in_array($feature,['grade_mean_current','grade_mean_previous','grade_trend'],true))return $label.': '.number_format($raw,2);
    return $label.': '.number_format($raw,0);
}

function edu_predictive_model_unavailable_message(array $model): string {
    $reason=(string)($model['reason']??'model_missing');
    if($reason==='model_missing')return 'El módulo predictivo ya está instalado, pero todavía no existe un modelo entrenado. Primero exporta el dataset histórico y entrena el modelo; después sube risk_model.json al servidor.';
    if($reason==='model_schema'||$reason==='model_invalid')return 'El archivo del modelo predictivo existe, pero no tiene un formato válido. Vuelve a generarlo con el script oficial de entrenamiento.';
    return 'El modelo predictivo no está disponible en este momento.';
}

function edu_predictive_chat_result(mysqli $conn,array $actor,array $entities=[],string $nameSearch='',int $limit=30): array {
    if((int)($actor['type']??0)!==1)return function_exists('edu_chat_result')?edu_chat_result('La alerta predictiva está disponible únicamente para administración en esta primera versión.'):['message'=>'La alerta predictiva está disponible únicamente para administración en esta primera versión.'];
    $model=edu_predictive_model_load();
    if(empty($model['available'])){
        $message=edu_predictive_model_unavailable_message($model);
        $result=function_exists('edu_chat_result')?edu_chat_result($message):['message'=>$message,'cards'=>[],'actions'=>[],'follow_up'=>[]];
        $result['tools_used']=['predictive_risk'];
        return $result;
    }
    $students=edu_predictive_students($conn,$actor,$entities,$nameSearch,$nameSearch!==''?8:max(30,$limit));
    if(!$students){$result=edu_chat_result('No encontré estudiantes activos que coincidan con esos filtros.');$result['tools_used']=['predictive_risk'];return$result;}
    if($nameSearch!==''&&count($students)>1){$lines=[];foreach($students as $i=>$s)$lines[]=($i+1).'. '.$s['name'].' — '.$s['nivel'].' · '.$s['grado'].'° '.$s['seccion'];$result=edu_chat_result("Encontré varias coincidencias. Especifica el nombre completo o el aula:\n".implode("\n",$lines));$result['tools_used']=['predictive_risk'];return$result;}
    $predictions=[];
    foreach($students as $student){
        $p=edu_predictive_student_prediction($conn,$actor,(int)$student['id'],!empty($entities['bimestre'])?(int)$entities['bimestre']:null);
        if(empty($p['available']))continue;
        $predictions[]=['student'=>$student,'prediction'=>$p];
    }
    if(!$predictions){$result=edu_chat_result('No hay suficientes datos académicos actuales para calcular riesgo predictivo con esos filtros.');$result['tools_used']=['predictive_risk'];return$result;}
    usort($predictions,static fn($a,$b)=>$b['prediction']['probability']<=>$a['prediction']['probability']);
    $predictions=array_slice($predictions,0,max(1,min(50,$limit)));
    $lines=[];$high=0;$medium=0;$low=0;
    foreach($predictions as $i=>$item){
        $s=$item['student'];$p=$item['prediction'];$pct=$p['probability']*100.0;
        if($p['level']==='Alto')$high++;elseif($p['level']==='Medio')$medium++;else$low++;
        $line=($i+1).'. '.$s['name'].' — riesgo '.$p['level'].' ('.number_format($pct,1).'%) · '.$s['nivel'].' · '.$s['grado'].'° '.$s['seccion'];
        $factors=[];foreach(array_slice($p['explanation']['raises'],0,3) as $factor)$factors[]=edu_predictive_format_factor($factor);
        if($factors)$line.=' · factores: '.implode('; ',$factors);
        $lines[]=$line;
    }
    $modelMetrics=(array)($model['metrics']['holdout']??[]);
    $metricNote='';
    if(isset($modelMetrics['roc_auc']))$metricNote="\nModelo evaluado en holdout: ROC-AUC ".number_format((float)$modelMetrics['roc_auc'],3).'. Esta probabilidad es una alerta de apoyo y no una decisión automática.';
    else $metricNote="\nLa predicción es una alerta de apoyo y no una decisión automática.";
    $message='Riesgo estimado para el siguiente bimestre:'."\n".implode("\n",$lines).$metricNote;
    $cards=[['label'=>'Riesgo alto','value'=>(string)$high,'tone'=>'danger'],['label'=>'Riesgo medio','value'=>(string)$medium,'tone'=>'warning'],['label'=>'Riesgo bajo','value'=>(string)$low,'tone'=>'success']];
    $actions=[];if(function_exists('edu_chat_action'))$actions[] = edu_chat_action('Ver Reporte de Notas','grades_report','fa-chart-bar');
    $result=edu_chat_result($message,['¿Por qué está en riesgo el primero?','Muéstrame solo secundaria','Dame los de riesgo alto'],$cards,$actions);
    $result['tools_used']=['predictive_risk'];
    return $result;
}
