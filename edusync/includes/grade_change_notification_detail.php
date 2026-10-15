<?php
/**
 * Detalle solo para Administración. La notificación referencia un registro
 * de evaluation_audit_log, no contiene nombres o notas en la carga Web Push.
 */
function edun_grade_change_detail(mysqli $db,int $schoolId,int $auditId): ?array {
    if ($schoolId<=0 || $auditId<=0) return null;
    $q=$db->prepare("SELECT ne.title,ne.message,ne.priority,ne.notification_type,
                            ne.created_at,a.details,u.name teacher_name
        FROM notification_events ne
        INNER JOIN evaluation_audit_log a ON a.id=ne.source_id
           AND a.school_id=ne.school_id AND a.action='grade_change_admin_notice'
        LEFT JOIN users u ON u.id=a.user_id AND u.school_id=a.school_id
        WHERE a.id=? AND ne.school_id=? AND ne.source_type='grade_changes'
          AND ne.recipient_role='Administrador' AND ne.recipient_user_id IS NULL
        LIMIT 1");
    if (!$q) return null;
    $q->bind_param('ii',$auditId,$schoolId);
    $q->execute();
    $row=$q->get_result()->fetch_assoc();
    $q->close();
    if (!$row) return null;
    $info=json_decode((string)$row['details'],true);
    if (!is_array($info)) return null;
    $items=(array)($info['items']??[]);
    $studentIds=[];$evaluationIds=[];
    foreach ($items as $item) {
        $sid=(int)($item['student_id']??0);
        $eid=(int)($item['evaluation_id']??0);
        if ($sid>0) $studentIds[$sid]=$sid;
        if ($eid>0) $evaluationIds[$eid]=$eid;
    }
    $students=[];$evaluations=[];
    if ($studentIds) {
        $ids=implode(',',array_values($studentIds));
        $result=$db->query("SELECT id,name FROM student WHERE school_id=$schoolId AND id IN ($ids)");
        while ($result && ($student=$result->fetch_assoc())) $students[(int)$student['id']]=$student['name'];
    }
    if ($evaluationIds) {
        $ids=implode(',',array_values($evaluationIds));
        $result=$db->query("SELECT e.id,e.title FROM evaluations e
            INNER JOIN teacher_courses tc ON tc.id=e.teacher_course_id
            WHERE tc.school_id=$schoolId AND e.id IN ($ids)");
        while ($result && ($evaluation=$result->fetch_assoc()))
            $evaluations[(int)$evaluation['id']]=$evaluation['title'];
    }
    $e=static function($value): string {
        return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    };
    $total=(int)($info['total']??count($items));
    $visible=array_slice($items,0,200);
    $updated='';
    if (!empty($info['updated_at_utc'])) {
        try {
            $date=new DateTime((string)$info['updated_at_utc'],new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone('America/Lima'));
            $updated=$date->format('d/m/Y H:i');
        } catch(Throwable $exception) {}
    }
    ob_start();
    ?>
    <div class="row mb-2">
        <div class="col-md-6 mb-2"><div class="nd-box">
            <span>Docente</span><strong><?= $e($row['teacher_name']??'Docente') ?></strong>
        </div></div>
        <div class="col-md-6 mb-2"><div class="nd-box">
            <span>Curso y aula</span><strong><?= $e($info['course']??'Curso') ?></strong>
            <small><?= $e(trim(($info['grade']??'').' '.($info['section']??''))) ?>
                · <?= (int)($info['bimester']??0) ?>° Bimestre</small>
        </div></div>
    </div>
    <div class="nd-section">
        <span>Resumen de modificaciones</span>
        <div><strong><?= $total ?></strong> <?= $total===1?'calificación modificada':'calificaciones modificadas' ?>
        <?php if (($info['source']??'')==='autosave'): ?>
            <small class="d-block text-muted">Los autoguardados se agrupan en un aviso durante 10 minutos.</small>
        <?php endif; ?>
        <?php if ($updated!==''): ?>
            <small class="d-block text-muted">Última modificación: <?= $e($updated) ?> (Perú)</small>
        <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive mt-3">
        <table class="table table-sm table-hover table-bordered">
            <thead class="thead-light"><tr><th>Estudiante</th><th>Evaluación</th><th>Antes</th><th>Después</th></tr></thead>
            <tbody>
            <?php foreach ($visible as $item):
                $sid=(int)($item['student_id']??0);
                $eid=(int)($item['evaluation_id']??0);
                $old=(string)($item['old_grade']??'');
                $new=(string)($item['new_grade']??'');
            ?>
                <tr>
                    <td><?= $e($students[$sid]??'Estudiante no disponible') ?></td>
                    <td><?= $e($evaluations[$eid]??'Evaluación no disponible') ?></td>
                    <td><span class="badge badge-secondary"><?= $e($old!==''?$old:'—') ?></span></td>
                    <td><span class="badge <?= $new===''?'badge-light border':'badge-primary' ?>"><?= $e($new!==''?$new:'Sin nota') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total>count($visible)): ?>
        <div class="small text-muted">Se muestran los primeros <?= count($visible) ?> cambios.
            Los demás se conservan en el historial académico del sistema.</div>
    <?php endif; ?>
    <?php
    return [
        'title'=>(string)$row['title'],'category'=>'Académica',
        'priority'=>(string)$row['priority'],'created_at'=>$row['created_at'],
        'message'=>(string)$row['message'],'content'=>ob_get_clean(),
    ];
}
