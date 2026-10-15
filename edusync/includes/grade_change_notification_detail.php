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
    $gradeTone=static function(string $old,string $new): string {
        if ($new==='') return 'edn-grade--removed';
        $rank=['C'=>0,'B'=>1,'A'=>2,'AD'=>3];
        $oldUpper=mb_strtoupper(trim($old),'UTF-8');
        $newUpper=mb_strtoupper(trim($new),'UTF-8');
        if (isset($rank[$oldUpper],$rank[$newUpper])) {
            return $rank[$newUpper]<$rank[$oldUpper]?'edn-grade--removed':'edn-grade--new';
        }
        if (is_numeric($old) && is_numeric($new)) {
            return (float)$new<(float)$old?'edn-grade--removed':'edn-grade--new';
        }
        return 'edn-grade--updated';
    };
    ob_start();
    ?>
    <div class="row mb-3">
  <div class="col-md-6 mb-2">
    <div class="card shadow-sm h-100"><div class="card-body py-3">
      <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Docente responsable</div>
      <div class="font-weight-bold text-gray-800"><?= $e($row['teacher_name']??'Docente') ?></div>
      <small class="text-muted">Registro de auditoría académica</small>
    </div></div>
  </div>
  <div class="col-md-6 mb-2">
    <div class="card shadow-sm h-100"><div class="card-body py-3">
      <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Curso y aula</div>
      <div class="font-weight-bold text-gray-800"><?= $e($info['course']??'Curso') ?></div>
      <small class="text-muted"><?= $e(trim(($info['grade']??'').' '.($info['section']??''))) ?> · <?= (int)($info['bimester']??0) ?>° bimestre</small>
    </div></div>
  </div>
</div>
<div class="card shadow-sm mb-3">
  <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap">
    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-history mr-2"></i>Historial de modificaciones</h6>
    <span class="badge badge-warning mt-1"><?= $total ?> <?= $total===1?'cambio':'cambios' ?></span>
  </div>
  <div class="card-body py-3">
    <?php if (($info['source']??'')==='autosave'): ?>
      <div class="small text-muted mb-2"><i class="fas fa-info-circle mr-1"></i>Las modificaciones del autoguardado se agrupan durante 10 minutos.</div>
    <?php endif; ?>
    <?php if ($updated!==''): ?>
      <div class="small text-muted mb-3">Última modificación: <?= $e($updated) ?> (Perú)</div>
    <?php endif; ?>
    <div class="table-responsive edn-desktop-grades">
      <table class="table table-sm table-hover table-bordered mb-0">
        <thead class="thead-light"><tr><th>Estudiante</th><th>Evaluación</th><th>Anterior</th><th>Nueva</th></tr></thead>
        <tbody>
        <?php foreach ($visible as $item):
            $sid=(int)($item['student_id']??0);$eid=(int)($item['evaluation_id']??0);
            $old=(string)($item['old_grade']??'');$new=(string)($item['new_grade']??''); ?>
            <tr>
              <td><strong><?= $e($students[$sid]??'Estudiante no disponible') ?></strong></td>
              <td><?= $e($evaluations[$eid]??'Evaluación no disponible') ?></td>
              <td class="text-center"><span class="badge edn-grade--old"><?= $e($old!==''?$old:'—') ?></span></td>
              <td class="text-center"><span class="badge <?= $gradeTone($old,$new) ?>"><?= $e($new!==''?$new:'Sin nota') ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="edn-responsive-grades">
      <?php foreach ($visible as $item):
          $sid=(int)($item['student_id']??0);$eid=(int)($item['evaluation_id']??0);
          $old=(string)($item['old_grade']??'');$new=(string)($item['new_grade']??''); ?>
        <div class="edn-grade-row">
          <div class="edn-grade-row-head"><?= $e($students[$sid]??'Estudiante no disponible') ?></div>
          <div class="edn-grade-row-context"><?= $e($evaluations[$eid]??'Evaluación no disponible') ?></div>
          <div class="edn-grade-row-values">
            <span class="badge edn-grade--old"><?= $e($old!==''?$old:'—') ?></span>
            <i class="fas fa-arrow-right edn-arrow"></i>
            <span class="badge <?= $gradeTone($old,$new) ?>"><?= $e($new!==''?$new:'Sin nota') ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($total>count($visible)): ?>
      <p class="small text-muted mb-0">Se muestran <?= count($visible) ?> de <?= $total ?> cambios. Los restantes se conservan en el historial de notas.</p>
    <?php endif; ?>
  </div>
</div>
    <?php
    return [
        'title'=>(string)$row['title'],'category'=>'Académica',
        'priority'=>(string)$row['priority'],'created_at'=>$row['created_at'],
        'message'=>(string)$row['message'],'content'=>ob_get_clean(),
    ];
}
