<?php
/**
 * Avisos docentes de cierre, notas incompletas y cambios hechos por administración.
 * Reutiliza notification_events + web_push_dispatch; no requiere nuevas tablas.
 * Se invoca desde el cron existente. Nunca expone notas ni DNI en el push.
 */
declare(strict_types=1);

function tna_table(mysqli $db, string $name): bool {
    $name = $db->real_escape_string($name);
    $q = $db->query("SHOW TABLES LIKE '$name'");
    return (bool)($q && $q->num_rows > 0);
}

function tna_required_tables(mysqli $db): bool {
    foreach (['notification_events', 'academic_year', 'academic_periods',
        'teacher_courses', 'academic_courses', 'teacher', 'users',
        'grade_period_closures', 'bimester_locks'] as $table) {
        if (!tna_table($db, $table)) return false;
    }
    return true;
}

/** La misma clave estable permite consultar o emitir sin duplicar. */
function tna_notice_exists(
    mysqli $db, int $school, int $user, string $source, int $sourceId
): bool {
    $check = $db->prepare(
        'SELECT id FROM notification_events
         WHERE school_id=? AND recipient_user_id=? AND source_type=? AND source_id=?
         LIMIT 1'
    );
    if (!$check) throw new RuntimeException($db->error);
    $check->bind_param('iisi', $school, $user, $source, $sourceId);
    $check->execute();
    $exists = (bool)$check->get_result()->fetch_assoc();
    $check->close();
    return $exists;
}

/** Asegura como máximo un aviso por docente, periodo y clase de evento. */
function tna_emit(
    mysqli $db, int $school, int $user, string $source, int $sourceId,
    string $title, string $body, string $priority, bool $dryRun
): bool {
    if ($school <= 0 || $user <= 0 || $sourceId <= 0) return false;
    if (tna_notice_exists($db,$school,$user,$source,$sourceId)) return false;
    if ($dryRun) return true;

    $category='Académica';
    $title=mb_substr($title,0,180,'UTF-8');
    $body=mb_substr($body,0,500,'UTF-8');
    $stmt=$db->prepare(
        'INSERT INTO notification_events
         (school_id,recipient_user_id,notification_type,priority,title,message,source_type,source_id)
         VALUES(?,?,?,?,?,?,?,?)'
    );
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('iisssssi', $school,$user,$category,$priority,$title,$body,$source,$sourceId);
    $ok=$stmt->execute();
    if (!$ok) throw new RuntimeException($stmt->error);
    $stmt->close();
    return true;
}

/** Cuenta solo celdas realmente faltantes de evaluaciones activas. */
function tna_missing_cells(mysqli $db, int $teacherCourseId, int $bimester): int {
    // Mismo criterio de sección única que el cierre bimestral vigente.
    $sql="SELECT COUNT(*) total
      FROM evaluations e
      INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
      INNER JOIN academic_courses ac ON ac.id=tc.course_id AND ac.school_id=tc.school_id
      INNER JOIN evaluation_competencias ec ON ec.evaluation_id=e.id
      INNER JOIN general_course_competencies gcc
        ON gcc.id=ec.competencia_id AND gcc.course_id=tc.course_id
        AND gcc.teacher_id=tc.teacher_id AND gcc.academic_year_id=tc.academic_year_id
        AND gcc.is_active=1 AND gcc.percentage>0
      INNER JOIN student st ON st.school_id=tc.school_id
        AND (st.status='Activo' OR st.status IS NULL)
        AND LOWER(TRIM(st.nivel))=LOWER(TRIM(ac.level))
        AND st.grado=tc.grado
        AND (
          st.seccion=tc.seccion OR (
            UPPER(TRIM(COALESCE(tc.seccion,''))) IN ('U','UNICA','ÚNICA')
            AND UPPER(TRIM(COALESCE(st.seccion,''))) IN ('','U','UNICA','ÚNICA')
          )
        )
      WHERE tc.id=? AND CAST(e.bimestre AS UNSIGNED)=?
        AND e.academic_year_id=tc.academic_year_id
        AND NOT EXISTS (
          SELECT 1 FROM evaluation_grades eg
          WHERE eg.evaluation_id=e.id AND eg.student_id=st.id
            AND eg.competencia_id=ec.competencia_id
            AND eg.grade IS NOT NULL AND TRIM(eg.grade)<>''
        )";
    $status=$db->query("SHOW COLUMNS FROM evaluations LIKE 'status'");
    if ($status && $status->num_rows>0) {
        $sql.=" AND (e.status IS NULL OR e.status<>'Anulada')";
    }
    $stmt=$db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('ii',$teacherCourseId,$bimester);
    $stmt->execute();
    $count=(int)($stmt->get_result()->fetch_assoc()['total']??0);
    $stmt->close();
    return $count;
}

function tna_reminders(mysqli $db, DateTimeImmutable $now, bool $dryRun): array {
    $summary=['closure_soon'=>0,'closure_pending'=>0,'grades_incomplete'=>0];
    if (!tna_required_tables($db)
        || !tna_table($db, 'evaluation_grades')
        || !tna_table($db, 'evaluation_competencias')
        || !tna_table($db, 'general_course_competencies')
        || !tna_table($db, 'student')
        || !tna_table($db, 'evaluations')) return $summary;

    $today=$now->format('Y-m-d');
    $old=$now->modify('-14 days')->format('Y-m-d');
    $future=$now->modify('+14 days')->format('Y-m-d');
    $hasSettings=tna_table($db,'teacher_alert_settings');
    $settingsJoin=$hasSettings
        ? " LEFT JOIN teacher_alert_settings tns ON tns.school_id=ap.school_id
                AND tns.academic_year_id=ap.academic_year_id
                AND tns.bimester=ap.period_number "
        : "";
    $dueSql=$hasSettings?'COALESCE(tns.deadline_date,ap.end_date)':'ap.end_date';
    $daysSql=$hasSettings?'COALESCE(tns.notice_days_before,3)':'3';
    $closureSql=$hasSettings?'COALESCE(tns.closure_enabled,1)':'1';
    $incompleteSql=$hasSettings?'COALESCE(tns.incomplete_enabled,1)':'1';
    $sql="SELECT ap.id period_id,ap.school_id,ap.academic_year_id,
          ap.period_number bimester,$dueSql due_date,
          $daysSql days_before,$closureSql closure_enabled,
          $incompleteSql incomplete_enabled,ay.year,tc.id teacher_course_id,
          ac.name course_name,u.id recipient
      FROM academic_periods ap
      INNER JOIN academic_year ay ON ay.id=ap.academic_year_id
        AND ay.school_id=ap.school_id AND ay.is_active=1
      INNER JOIN teacher_courses tc ON tc.school_id=ap.school_id
        AND tc.academic_year_id=ap.academic_year_id
      INNER JOIN academic_courses ac ON ac.id=tc.course_id
        AND ac.school_id=tc.school_id AND ac.is_active=1
      INNER JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
        AND t.status='Activo'
      INNER JOIN users u ON u.teacher_id=tc.teacher_id AND u.school_id=tc.school_id
        AND u.type=2
      $settingsJoin
      LEFT JOIN grade_period_closures gpc ON gpc.school_id=tc.school_id
        AND gpc.teacher_course_id=tc.id AND gpc.bimester=ap.period_number
        AND gpc.status='Cerrado'
      LEFT JOIN bimester_locks bl ON bl.school_id=ap.school_id
        AND bl.academic_year_id=ap.academic_year_id AND bl.bimester=ap.period_number
      WHERE ap.period_number BETWEEN 1 AND 4
        AND $dueSql BETWEEN ? AND ?
        AND COALESCE(ap.start_date,ay.start_date)<=?
        AND ap.is_locked=0 AND COALESCE(bl.is_locked,0)=0
        AND gpc.id IS NULL
      ORDER BY ap.school_id,u.id,ap.id,tc.id";
    // Instalaciones anteriores pueden no tener status en users.
    $hasStatus=$db->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($hasStatus && $hasStatus->num_rows>0) {
        $sql=str_replace("AND u.type=2", "AND u.type=2 AND u.status='Activo'", $sql);
    }
    $stmt=$db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('sss',$old,$future,$today);
    $stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $groups=[];
    foreach ($rows as $row) {
        $key=$row['school_id'].':'.$row['recipient'].':'.$row['period_id'];
        if (!isset($groups[$key])) {
            $groups[$key]=['meta'=>$row,'courses'=>[]];
        }
        $groups[$key]['courses'][(int)$row['teacher_course_id']]=true;
    }

    foreach ($groups as $group) {
        $meta=$group['meta'];
        $due=(string)$meta['due_date'];
        $days=(int)(new DateTimeImmutable($today))->diff(
            new DateTimeImmutable($due)
        )->format('%r%a');
        $school=(int)$meta['school_id'];$user=(int)$meta['recipient'];
        $periodId=(int)$meta['period_id'];$bim=(int)$meta['bimester'];
        $courses=count($group['courses']);
        $msg="Tienes $courses ".($courses===1?'curso':'cursos').
            " sin cierre registrado del $bim.º bimestre de ".$meta['year'].
            ". Fecha límite de cierre: ".$due.".";

        if ($days>=0 && $days<=(int)$meta['days_before']) {
            if ((int)$meta['closure_enabled']===1
                && tna_emit($db,$school,$user,'teacher_closure_soon',$periodId,
                    'Cierre bimestral próximo',$msg,'Normal',$dryRun)) {
                $summary['closure_soon']++;
            }
            if ((int)$meta['incomplete_enabled']!==1) continue;
            // Evitar releer miles de celdas de notas cada vez que el
            // despachador se ejecuta, si ya se emitió este resumen.
            if (tna_notice_exists($db,$school,$user,
                'teacher_grades_incomplete',$periodId)) continue;
            $missingCourses=0;$missingCells=0;
            foreach (array_keys($group['courses']) as $tcid) {
                $cells=tna_missing_cells($db,(int)$tcid,$bim);
                if ($cells>0) { $missingCourses++;$missingCells+=$cells; }
            }
            if ($missingCells>0) {
                $body="$missingCourses ".($missingCourses===1?'curso tiene':'cursos tienen').
                    " calificaciones pendientes ($missingCells celdas sin nota) ".
                    "en el $bim.º bimestre. Revisa el Libro de Notas antes del ".
                    "cierre ($due).";
                if (tna_emit($db,$school,$user,'teacher_grades_incomplete',
                    $periodId,'Evaluaciones con notas pendientes',
                    $body,'Alta',$dryRun)) {
                    $summary['grades_incomplete']++;
                }
            }
        } elseif ($days<0 && (int)$meta['closure_enabled']===1) {
            if (tna_emit($db,$school,$user,'teacher_closure_pending',$periodId,
                'Cierre bimestral pendiente',$msg.' Revisa el estado del cierre.',
                'Alta',$dryRun)) {
                $summary['closure_pending']++;
            }
        }
    }
    return $summary;
}

/**
 * Consolida en una sola alerta por hora, curso y docente los cambios
 * existentes hechos por un usuario de tipo administrador.
 * Usa la autoría que ya guarda evaluation_grade_history.
 */
function tna_admin_grade_changes(mysqli $db, bool $dryRun): int {
    if (!tna_table($db,'evaluation_grade_history')
        || !tna_table($db,'notification_events')) return 0;

    $settingsJoin=tna_table($db,'teacher_alert_settings')
        ? " LEFT JOIN teacher_alert_settings tns
          ON tns.school_id=h.school_id AND tns.academic_year_id=h.academic_year_id
          AND tns.bimester=CAST(e.bimestre AS UNSIGNED) "
        : "";
    $settingsFilter=$settingsJoin!=='' ? ' AND COALESCE(tns.admin_edits_enabled,1)=1 ' : '';
    $sql="SELECT h.school_id,u.id recipient,
        e.teacher_course_id course_id,MIN(h.id) first_history_id,
        COUNT(*) changes,COUNT(DISTINCT h.student_id) students,
        ac.name course_name,tc.grado,tc.seccion,e.bimestre,
        MAX(editor.name) editor_name
      FROM evaluation_grade_history h
      INNER JOIN users editor ON editor.id=h.changed_by
        AND editor.school_id=h.school_id AND editor.type=1
      INNER JOIN evaluations e ON e.id=h.evaluation_id
        AND e.academic_year_id=h.academic_year_id
      INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
        AND tc.school_id=h.school_id AND tc.teacher_id=e.teacher_id
      INNER JOIN academic_courses ac ON ac.id=tc.course_id
        AND ac.school_id=tc.school_id
      INNER JOIN teacher t ON t.id=tc.teacher_id AND t.school_id=tc.school_id
        AND t.status='Activo'
      INNER JOIN users u ON u.teacher_id=tc.teacher_id
        AND u.school_id=h.school_id AND u.type=2
      $settingsJoin
      WHERE h.created_at>=DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 2 HOUR),'%Y-%m-%d %H:00:00')
        AND h.previous_grade IS NOT NULL AND TRIM(h.previous_grade)<>''
        AND COALESCE(h.previous_grade,'')<>COALESCE(h.new_grade,'')
        $settingsFilter
      GROUP BY h.school_id,u.id,e.teacher_course_id,
        FLOOR(UNIX_TIMESTAMP(h.created_at)/3600),
        ac.name,tc.grado,tc.seccion,e.bimestre";
    $hasStatus=$db->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($hasStatus && $hasStatus->num_rows>0) {
        $sql=str_replace("AND u.type=2", "AND u.type=2 AND u.status='Activo'", $sql);
    }
    $rows=$db->query($sql);
    if (!$rows) throw new RuntimeException($db->error);
    $added=0;
    while ($row=$rows->fetch_assoc()) {
        $total=(int)$row['changes'];$students=(int)$row['students'];
        $body='Administración modificó '.$total.' '.
            ($total===1?'calificación':'calificaciones').' de '.$students.
            ($students===1?' estudiante':' estudiantes').
            ' · '.$row['course_name'].' · '.$row['grado'].' '.$row['seccion'].
            ' · Bimestre '.$row['bimestre'].'.';
        if (tna_emit($db,(int)$row['school_id'],(int)$row['recipient'],
            'teacher_admin_grade_change',(int)$row['first_history_id'],
            'Calificaciones ajustadas por Administración',
            $body,'Alta',$dryRun)) {
            $added++;
        }
    }
    $rows->close();
    return $added;
}

function tna_run(mysqli $db, bool $dryRun=false, ?DateTimeImmutable $now=null): array {
    $result=['closure_soon'=>0,'closure_pending'=>0,
        'grades_incomplete'=>0,'admin_grade_changes'=>0,
        'skipped'=>false,'dry_run'=>$dryRun];
    if (!tna_table($db,'notification_events')) {
        $result['skipped']=true;
        return $result;
    }
    $lock=$db->query("SELECT GET_LOCK('edusync_teacher_alerts_v1',0) acquired");
    $acquired=$lock ? (int)($lock->fetch_assoc()['acquired']??0) : 0;
    if ($acquired!==1) {
        $result['skipped']=true;
        return $result;
    }
    try {
        $now=$now??new DateTimeImmutable('now',new DateTimeZone('America/Lima'));
        $reminders=tna_reminders($db,$now,$dryRun);
        foreach ($reminders as $name=>$value) $result[$name]=$value;
        $result['admin_grade_changes']=tna_admin_grade_changes($db,$dryRun);
    } finally {
        $db->query("SELECT RELEASE_LOCK('edusync_teacher_alerts_v1')");
    }
    return $result;
}
