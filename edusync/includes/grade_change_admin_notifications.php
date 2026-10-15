<?php
/**
 * Aviso administrativo por cambios reales de calificaciones existentes.
 * Aprovecha evaluation_audit_log y notification_events; no requiere tablas
 * nuevas. Los autoguardados se agrupan por docente/curso/bimestre en bloques
 * de 10 minutos. Una edición sin cambio real no produce notificaciones.
 */
if (!function_exists('edun_notify_admin_grade_changes')) {
    function edun_notify_admin_grade_changes(
        mysqli $db, int $schoolId, int $teacherId, int $userId,
        int $teacherCourseId, int $yearId, int $bimester,
        string $course, string $grade, string $section,
        string $source, array $changes
    ): array {
        $result = ['created'=>false, 'updated'=>false, 'event_id'=>0, 'changes'=>0];
        if ($schoolId<=0 || $teacherId<=0 || $userId<=0 || $teacherCourseId<=0
            || $yearId<=0 || $bimester<1 || $bimester>4 || !$changes) return $result;

        require_once __DIR__ . '/push_notifications.php';
        if (!push_table_exists($db, 'evaluation_audit_log')
            || !push_table_exists($db, 'notification_events')) {
            error_log('[EduSync grade change admin] Faltan tablas evaluation_audit_log o notification_events.');
            return $result;
        }

        $items = [];
        foreach ($changes as $change) {
            $student=(int)($change['student_id']??0);
            $evaluation=(int)($change['evaluation_id']??0);
            $old=trim((string)($change['old_grade']??''));
            $new=trim((string)($change['new_grade']??''));
            if ($student<=0 || $evaluation<=0 || $old==='' || $old===$new) continue;
            $items[]=['student_id'=>$student,'evaluation_id'=>$evaluation,
                      'old_grade'=>$old,'new_grade'=>$new];
        }
        if (!$items) return $result;
        $result['changes']=count($items);
        $source=$source==='autosave' ? 'autosave' : 'manual';

        // Protege la agrupación cuando llegan dos autoguardados simultáneos.
        $lockName='edusync_grade_change_'.substr(hash('sha256',
            $schoolId.'|'.$userId.'|'.$teacherCourseId.'|'.$bimester),0,35);
        $lockStmt=$db->prepare('SELECT GET_LOCK(?,5) locked');
        if (!$lockStmt) return $result;
        $lockStmt->bind_param('s',$lockName);
        $lockStmt->execute();
        $lockRow=$lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        if ((int)($lockRow['locked']??0)!==1) {
            error_log('[EduSync grade change admin] Timeout del bloqueo de agrupación.');
            return $result;
        }
        try {
            $db->begin_transaction();
            try {
                $batchId=0;
                $existingEventId=0;
                $payload=null;
                if ($source==='autosave') {
                    $lookup=$db->prepare("SELECT id,details FROM evaluation_audit_log
                         WHERE school_id=? AND teacher_id=? AND user_id=?
                           AND action='grade_change_admin_notice'
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                         ORDER BY id DESC LIMIT 20");
                    if (!$lookup) throw new RuntimeException($db->error);
                    $lookup->bind_param('iii',$schoolId,$teacherId,$userId);
                    $lookup->execute();
                    $rows=$lookup->get_result();
                    while ($candidate=$rows->fetch_assoc()) {
                        $data=json_decode((string)$candidate['details'],true);
                        if (!is_array($data)
                            || (int)($data['teacher_course_id']??0)!==$teacherCourseId
                            || (int)($data['bimester']??0)!==$bimester
                            || ($data['source']??'')!=='autosave') continue;
                        $batchId=(int)$candidate['id'];
                        $payload=$data;
                        break;
                    }
                    $lookup->close();
                    if ($batchId) {
                        $find=$db->prepare("SELECT id FROM notification_events
                            WHERE school_id=? AND source_type='grade_changes'
                              AND source_id=? AND recipient_role='Administrador'
                            LIMIT 1");
                        if (!$find) throw new RuntimeException($db->error);
                        $find->bind_param('ii',$schoolId,$batchId);
                        $find->execute();
                        $row=$find->get_result()->fetch_assoc();
                        $find->close();
                        $existingEventId=(int)($row['id']??0);
                        if (!$existingEventId) {
                            $batchId=0;
                            $payload=null;
                        }
                    }
                }
                if (!$payload) {
                    $payload=[
                        'teacher_course_id'=>$teacherCourseId,
                        'bimester'=>$bimester,
                        'source'=>$source,
                        'course'=>$course,
                        'grade'=>$grade,
                        'section'=>$section,
                        'items'=>[],
                        'total'=>0,
                    ];
                }
                // El historial completo sigue en evaluation_grade_history.
                // Se conserva un máximo de 1200 detalles en la notificación.
                $payload['items']=array_slice(
                    array_merge((array)($payload['items']??[]),$items),0,1200);
                $payload['total']=(int)($payload['total']??0)+count($items);
                $payload['updated_at_utc']=gmdate('Y-m-d H:i:s');
                $studentIds=[];
                foreach ($payload['items'] as $item) $studentIds[(int)($item['student_id']??0)]=1;
                $total=(int)$payload['total'];
                $students=count($studentIds);
                $userStmt=$db->prepare("SELECT name FROM users WHERE id=? AND school_id=? LIMIT 1");
                if (!$userStmt) throw new RuntimeException($db->error);
                $userStmt->bind_param('ii',$userId,$schoolId);
                $userStmt->execute();
                $teacherName=(string)($userStmt->get_result()->fetch_assoc()['name']??'Un docente');
                $userStmt->close();
                $title=mb_substr('Calificaciones modificadas · '.$course,0,180,'UTF-8');
                $msg=mb_substr($teacherName.' modificó '.$total.' '.($total===1?'calificación':'calificaciones')
                    .' · '.$students.' '.($students===1?'estudiante':'estudiantes')
                    .' · '.$grade.' '.$section.' · '.$bimester.'° Bimestre',0,500,'UTF-8');
                $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if ($json===false) throw new RuntimeException('No se pudo serializar el historial de cambios.');

                if ($batchId && $existingEventId) {
                    $update=$db->prepare('UPDATE evaluation_audit_log SET details=? WHERE id=? AND school_id=?');
                    if (!$update) throw new RuntimeException($db->error);
                    $update->bind_param('sii',$json,$batchId,$schoolId);
                    if (!$update->execute()) throw new RuntimeException($update->error);
                    $update->close();
                    $update=$db->prepare("UPDATE notification_events SET title=?,message=?
                        WHERE id=? AND school_id=? AND source_type='grade_changes'");
                    if (!$update) throw new RuntimeException($db->error);
                    $update->bind_param('ssii',$title,$msg,$existingEventId,$schoolId);
                    if (!$update->execute()) throw new RuntimeException($update->error);
                    $update->close();
                    $result['updated']=true;
                    $result['event_id']=$existingEventId;
                } else {
                    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
                    $audit=$db->prepare("INSERT INTO evaluation_audit_log
                        (school_id,academic_year_id,evaluation_id,teacher_id,user_id,action,details,ip_address)
                        VALUES (?,?,NULL,?,?,'grade_change_admin_notice',?,?)");
                    if (!$audit) throw new RuntimeException($db->error);
                    $audit->bind_param('iiiiss',$schoolId,$yearId,$teacherId,$userId,$json,$ip);
                    if (!$audit->execute()) throw new RuntimeException($audit->error);
                    $batchId=(int)$audit->insert_id;
                    $audit->close();

                    $role='Administrador';$category='Académica';$priority='Normal';$kind='grade_changes';
                    $event=$db->prepare('INSERT INTO notification_events
                        (school_id,recipient_role,notification_type,priority,title,message,source_type,source_id,created_by)
                        VALUES (?,?,?,?,?,?,?,?,?)');
                    if (!$event) throw new RuntimeException($db->error);
                    $event->bind_param('issssssii',$schoolId,$role,$category,$priority,
                        $title,$msg,$kind,$batchId,$userId);
                    if (!$event->execute()) throw new RuntimeException($event->error);
                    $result['event_id']=(int)$event->insert_id;
                    $event->close();
                    $result['created']=true;
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
        } catch (Throwable $e) {
            error_log('[EduSync grade change admin] '.$e->getMessage());
            $result['created']=false;
            $result['updated']=false;
            $result['event_id']=0;
        } finally {
            $release=$db->prepare('SELECT RELEASE_LOCK(?)');
            if ($release) {
                $release->bind_param('s',$lockName);
                $release->execute();
                $release->close();
            }
        }
        if ($result['created'] && $result['event_id']>0) {
            try {
                require_once __DIR__.'/web_push_staff_events.php';
                edwp_queue_staff_event($db,$schoolId,'staff',$result['event_id']);
            } catch (Throwable $e) {
                error_log('[EduSync grade change admin push] '.$e->getMessage());
            }
        }
        return $result;
    }
}
