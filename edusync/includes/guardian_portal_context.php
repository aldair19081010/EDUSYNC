<?php

function guardian_portal_context(mysqli $conn, ?string $requiredPermission = null): array
{
    $userId = (int)($_SESSION['login_id'] ?? 0);
    $schoolId = (int)($_SESSION['login_school_id'] ?? 0);
    $loginType = (int)($_SESSION['login_type'] ?? 0);

    if ($loginType !== 5 || !$userId || !$schoolId) {
        throw new RuntimeException('Sesión de apoderado no válida.');
    }

    $stmt = $conn->prepare("SELECT g.id,g.user_id,g.dni,g.nombres,g.apellido_paterno,g.apellido_materno,g.telefono,g.email,g.direccion,g.status
        FROM guardians g
        WHERE g.user_id=? AND g.school_id=? LIMIT 1");
    if (!$stmt) throw new RuntimeException('No se pudo validar el perfil del apoderado.');
    $stmt->bind_param('ii', $userId, $schoolId);
    $stmt->execute();
    $guardian = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$guardian || ($guardian['status'] ?? 'Inactivo') !== 'Activo') {
        throw new RuntimeException('El perfil del apoderado no está activo.');
    }

    $guardianId = (int)$guardian['id'];
    $_SESSION['guardian_id'] = $guardianId;

    $stmt = $conn->prepare("SELECT gs.student_id,gs.parentesco,gs.is_primary,
        gs.can_view_grades,gs.can_view_attendance,gs.can_view_payments,gs.can_receive_communications,
        s.id_no,s.name,s.nivel,s.grado,COALESCE(NULLIF(TRIM(s.seccion),''),'U') seccion,s.status AS student_status
        FROM guardian_students gs
        INNER JOIN student s ON s.id=gs.student_id AND s.school_id=gs.school_id
        WHERE gs.guardian_id=? AND gs.school_id=? AND gs.status='Activo'
        ORDER BY gs.is_primary DESC,s.name ASC");
    if (!$stmt) throw new RuntimeException('No se pudieron cargar los estudiantes vinculados.');
    $stmt->bind_param('ii', $guardianId, $schoolId);
    $stmt->execute();
    $result = $stmt->get_result();
    $children = [];
    while ($row = $result->fetch_assoc()) {
        foreach (['student_id','is_primary','can_view_grades','can_view_attendance','can_view_payments','can_receive_communications'] as $key) {
            $row[$key] = (int)$row[$key];
        }
        $children[] = $row;
    }
    $stmt->close();

    $activeId = (int)($_SESSION['guardian_active_student_id'] ?? 0);
    $active = null;
    foreach ($children as $child) {
        if ((int)$child['student_id'] === $activeId) {
            $active = $child;
            break;
        }
    }
    if (!$active && $children) {
        $active = $children[0];
        $activeId = (int)$active['student_id'];
        $_SESSION['guardian_active_student_id'] = $activeId;
    }

    if ($active) {
        $_SESSION['student_id'] = (int)$active['student_id'];
        $_SESSION['student_name'] = (string)$active['name'];
        $_SESSION['student_dni'] = (string)$active['id_no'];
        $_SESSION['student_school_id'] = $schoolId;
        $_SESSION['guardian_active_student_name'] = (string)$active['name'];
        $_SESSION['guardian_active_student_dni'] = (string)$active['id_no'];
    } else {
        unset(
            $_SESSION['student_id'],
            $_SESSION['student_name'],
            $_SESSION['student_dni'],
            $_SESSION['student_school_id'],
            $_SESSION['guardian_active_student_id'],
            $_SESSION['guardian_active_student_name'],
            $_SESSION['guardian_active_student_dni']
        );
    }

    $permissionGranted = true;
    if ($requiredPermission !== null) {
        $valid = ['can_view_grades','can_view_attendance','can_view_payments','can_receive_communications'];
        if (!in_array($requiredPermission, $valid, true)) {
            throw new InvalidArgumentException('Permiso del portal no válido.');
        }
        $permissionGranted = $active && !empty($active[$requiredPermission]);
    }

    return [
        'guardian' => $guardian,
        'children' => $children,
        'active_student' => $active,
        'permission_granted' => $permissionGranted,
        'school_id' => $schoolId,
    ];
}

function guardian_portal_set_active_student(mysqli $conn, int $studentId): bool
{
    $ctx = guardian_portal_context($conn);
    foreach ($ctx['children'] as $child) {
        if ((int)$child['student_id'] === $studentId) {
            $_SESSION['guardian_active_student_id'] = $studentId;
            $_SESSION['student_id'] = $studentId;
            $_SESSION['student_name'] = (string)$child['name'];
            $_SESSION['student_dni'] = (string)$child['id_no'];
            $_SESSION['student_school_id'] = (int)$ctx['school_id'];
            $_SESSION['guardian_active_student_name'] = (string)$child['name'];
            $_SESSION['guardian_active_student_dni'] = (string)$child['id_no'];
            return true;
        }
    }
    return false;
}

function guardian_portal_guardian_name(array $guardian): string
{
    return trim(implode(' ', array_filter([
        trim((string)($guardian['nombres'] ?? '')),
        trim((string)($guardian['apellido_paterno'] ?? '')),
        trim((string)($guardian['apellido_materno'] ?? '')),
    ], static fn($v) => $v !== '')));
}
