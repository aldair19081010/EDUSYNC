<?php
/** Filas reutilizables de la tabla de docentes. Requiere $teacher e $i. */
if (!isset($teacher, $i)) {
    return;
}

if (!($teacher instanceof mysqli_result)) {
    error_log('No se pudo consultar la lista de docentes.');
    echo '<tr><td colspan="10" class="text-center text-danger py-4">No se pudo cargar la lista de docentes. Revisa la estructura de la base de datos en producción.</td></tr>';
    return;
}

while ($row = $teacher->fetch_assoc()):
    $teacherStatus = $row['status'] ?? 'Activo';
    $userCount = (int)($row['user_count'] ?? 0);
    $courseCount = (int)($row['course_count'] ?? 0);
?>
<tr>
    <td class="text-center"><input type="checkbox" class="teacher-row-check" value="<?php echo (int)$row['id']; ?>" data-status="<?php echo htmlspecialchars($teacherStatus, ENT_QUOTES, 'UTF-8'); ?>"></td>
    <td class="text-center"><?php echo $i++; ?></td>
    <td><?php echo htmlspecialchars($row['id_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php echo htmlspecialchars(ucwords($row['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
    <td><p>Correo: <?php echo htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p><p># Móvil: <?php echo htmlspecialchars($row['contact'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p><p>Dirección: <?php echo htmlspecialchars($row['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p></td>
    <td><?php echo htmlspecialchars($row['specialty'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><span class="badge <?php echo $teacherStatus === 'Activo' ? 'badge-success' : 'badge-secondary'; ?>"><?php echo htmlspecialchars($teacherStatus, ENT_QUOTES, 'UTF-8'); ?></span></td>
    <td class="text-center"><?php if ($userCount === 0): ?><span class="badge badge-warning">Sin usuario</span><?php elseif ($teacherStatus === 'Activo'): ?><span class="badge badge-success">Acceso activo</span><?php else: ?><span class="badge badge-secondary">Acceso inactivo</span><?php endif; ?></td>
    <td class="text-center"><span class="badge badge-info"><?php echo $courseCount; ?> curso(s)</span></td>
    <td class="text-center"><div class="dropdown teacher-actions-menu">
        <button class="btn btn-light btn-sm" type="button" data-toggle="dropdown" data-boundary="window" aria-haspopup="true" aria-expanded="false" title="Acciones"><i class="fa fa-ellipsis-v"></i></button>
        <div class="dropdown-menu dropdown-menu-right">
            <a class="dropdown-item view_teacher_history" href="#" data-id="<?php echo (int)$row['id']; ?>"><i class="fa fa-id-card text-info"></i>Ver ficha</a>
            <a class="dropdown-item edit_teacher" href="#" data-id="<?php echo (int)$row['id']; ?>"><i class="fa fa-edit text-primary"></i>Editar información</a>
            <a class="dropdown-item create_user_teacher" href="#" data-id="<?php echo (int)$row['id']; ?>" data-name="<?php echo htmlspecialchars($row['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-email="<?php echo htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo $userCount > 0 ? 'Gestionar usuario' : 'Crear usuario'; ?>"><i class="fa fa-user-cog text-success"></i><?php echo $userCount > 0 ? 'Gestionar usuario' : 'Crear usuario'; ?></a>
            <a class="dropdown-item manage_teacher_courses" href="#" data-id="<?php echo (int)$row['id']; ?>"><i class="fa fa-book text-warning"></i>Gestionar cursos</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item delete_teacher <?php echo $teacherStatus === 'Activo' ? 'text-danger' : 'text-success'; ?>" href="#" data-id="<?php echo (int)$row['id']; ?>" data-status="<?php echo htmlspecialchars($teacherStatus, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa <?php echo $teacherStatus === 'Activo' ? 'fa-user-slash' : 'fa-user-check'; ?>"></i><?php echo $teacherStatus === 'Activo' ? 'Desactivar' : 'Recontratar'; ?></a>
        </div>
    </div></td>
</tr>
<?php endwhile; ?>
