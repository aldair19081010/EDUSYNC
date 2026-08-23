<?php
// Endpoints para la gestión de años académicos
// Para incluir en ajax.php

// Helper: asegurar tabla de bloqueos de bimestres
function ensure_bimester_locks_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS bimester_locks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        academic_year_id INT NOT NULL,
        school_id INT NOT NULL,
        bimester TINYINT NOT NULL,
        is_locked TINYINT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_year_school_bim (academic_year_id, school_id, bimester)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Guardar o actualizar año académico
if ($action == 'save_academic_year') {
    header('Content-Type: application/json');
    
    // Validación de datos
    $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : null;
    $year = isset($_POST['year']) ? $conn->real_escape_string($_POST['year']) : '';
    $description = isset($_POST['description']) ? $conn->real_escape_string($_POST['description']) : '';
    $start_date = isset($_POST['start_date']) ? $conn->real_escape_string($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? $conn->real_escape_string($_POST['end_date']) : '';
    $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 0;
    $school_id = isset($_POST['school_id']) ? intval($_POST['school_id']) : 0;
    
    // Validar permisos (solo admin puede hacer esto)
    if (!isset($_SESSION['login_type']) || $_SESSION['login_type'] != 1) {
        echo json_encode([
            'status' => 0,
            'msg' => 'No tiene permisos para realizar esta acción.'
        ]);
        exit;
    }
    
    // Si es activo, primero desactivar todos los demás años académicos
    if ($is_active) {
        $conn->query("UPDATE academic_year SET is_active = 0 WHERE school_id = $school_id");
    }
    
    // Insertar o actualizar
    if ($id) {
        $sql = "UPDATE academic_year SET 
                year = '$year', 
                description = '$description', 
                start_date = '$start_date', 
                end_date = '$end_date', 
                is_active = $is_active 
                WHERE id = $id AND school_id = $school_id";
    } else {
        $sql = "INSERT INTO academic_year (year, description, start_date, end_date, is_active, school_id) 
                VALUES ('$year', '$description', '$start_date', '$end_date', $is_active, $school_id)";
    }
    
    if ($conn->query($sql)) {
        echo json_encode(['status' => 1]);
    } else {
        echo json_encode([
            'status' => 0,
            'msg' => 'Error al guardar el año académico: ' . $conn->error
        ]);
    }
    exit;
} 

// Obtener un año académico por ID
elseif ($action == 'get_academic_year') {
    header('Content-Type: application/json');
    
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
    
    if (!$id || !$school_id) {
        echo json_encode(null);
        exit;
    }
    
    $query = $conn->query("SELECT * FROM academic_year WHERE id = $id AND school_id = $school_id");
    if ($query && $query->num_rows > 0) {
        $data = $query->fetch_assoc();
        // Formatear fechas para input date HTML
        $data['start_date'] = date('Y-m-d', strtotime($data['start_date']));
        $data['end_date'] = date('Y-m-d', strtotime($data['end_date']));
        echo json_encode($data);
    } else {
        echo json_encode(null);
    }
    exit;
}

// Activar un año académico
elseif ($action == 'activate_academic_year') {
    header('Content-Type: application/json');
    
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
    
    if (!$id || !$school_id) {
        echo json_encode([
            'status' => 0,
            'msg' => 'Parámetros inválidos'
        ]);
        exit;
    }
    
    // Verificar permisos
    if (!isset($_SESSION['login_type']) || $_SESSION['login_type'] != 1) {
        echo json_encode([
            'status' => 0,
            'msg' => 'No tiene permisos para realizar esta acción.'
        ]);
        exit;
    }
    
    // Desactivar todos los años
    $conn->query("UPDATE academic_year SET is_active = 0 WHERE school_id = $school_id");
    
    // Activar el año seleccionado
    if ($conn->query("UPDATE academic_year SET is_active = 1 WHERE id = $id AND school_id = $school_id")) {
        echo json_encode(['status' => 1]);
    } else {
        echo json_encode([
            'status' => 0,
            'msg' => 'Error al activar el año académico: ' . $conn->error
        ]);
    }
    exit;
}

// Eliminar un año académico
elseif ($action == 'delete_academic_year') {
    header('Content-Type: application/json');
    
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
    
    if (!$id || !$school_id) {
        echo json_encode([
            'status' => 0,
            'msg' => 'Parámetros inválidos'
        ]);
        exit;
    }
    
    // Verificar permisos
    if (!isset($_SESSION['login_type']) || $_SESSION['login_type'] != 1) {
        echo json_encode([
            'status' => 0,
            'msg' => 'No tiene permisos para realizar esta acción.'
        ]);
        exit;
    }
    
    // Verificar que no sea el año activo
    $check = $conn->query("SELECT is_active FROM academic_year WHERE id = $id AND school_id = $school_id");
    if ($check && $check->num_rows > 0) {
        $is_active = $check->fetch_assoc()['is_active'];
        if ($is_active) {
            echo json_encode([
                'status' => 0,
                'msg' => 'No se puede eliminar un año académico activo.'
            ]);
            exit;
        }
    }
    
    // Verificar si hay evaluaciones asociadas
    $check_evals = $conn->query("SELECT COUNT(*) as total FROM evaluations WHERE academic_year_id = $id");
    if ($check_evals && $check_evals->fetch_assoc()['total'] > 0) {
        echo json_encode([
            'status' => 0,
            'msg' => 'No se puede eliminar este año académico porque tiene evaluaciones asociadas.'
        ]);
        exit;
    }
    
    // Eliminar
    if ($conn->query("DELETE FROM academic_year WHERE id = $id AND school_id = $school_id AND is_active = 0")) {
        echo json_encode(['status' => 1]);
    } else {
        echo json_encode([
            'status' => 0,
            'msg' => 'Error al eliminar el año académico: ' . $conn->error
        ]);
    }
    exit;
}

// Obtener bloqueos de bimestres para un año académico
elseif ($action == 'get_bimester_locks') {
    header('Content-Type: application/json');
    $academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : (isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0);
    $school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
    if (!$academic_year_id || !$school_id) {
        echo json_encode(['status' => 0, 'msg' => 'Parámetros inválidos']);
        exit;
    }
    ensure_bimester_locks_table($conn);
    $locks = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $res = $conn->query("SELECT bimester, is_locked FROM bimester_locks WHERE academic_year_id = $academic_year_id AND school_id = $school_id");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $b = intval($row['bimester']);
            if ($b >= 1 && $b <= 4) $locks[$b] = intval($row['is_locked']);
        }
    }
    echo json_encode(['status' => 1, 'locks' => $locks]);
    exit;
}

// Guardar bloqueos de bimestres para un año académico
elseif ($action == 'save_bimester_locks') {
    header('Content-Type: application/json');
    $academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;
    $school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
    if (!$academic_year_id || !$school_id) {
        echo json_encode(['status' => 0, 'msg' => 'Parámetros inválidos']);
        exit;
    }
    ensure_bimester_locks_table($conn);
    // Parsear valores enviados (lock_1..lock_4)
    for ($b = 1; $b <= 4; $b++) {
        $key = 'lock_' . $b;
        $is_locked = isset($_POST[$key]) ? intval($_POST[$key]) : 0;
        // Upsert
        $sql = "INSERT INTO bimester_locks (academic_year_id, school_id, bimester, is_locked)
                VALUES ($academic_year_id, $school_id, $b, $is_locked)
                ON DUPLICATE KEY UPDATE is_locked = VALUES(is_locked)";
        if (!$conn->query($sql)) {
            echo json_encode(['status' => 0, 'msg' => 'Error al guardar: ' . $conn->error]);
            exit;
        }
    }
    echo json_encode(['status' => 1]);
    exit;
}
