<?php
// Endpoints para gestionar evaluaciones con año académico
// Para incluir en ajax.php

// Obtener evaluaciones con filtro por año académico
if ($action == 'get_teacher_evaluations') {
    header('Content-Type: application/json');
    
    $teacher_id = isset($_POST['teacher_id']) ? intval($_POST['teacher_id']) : 0;
    $academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;
    
    // Nuevos filtros
    $level = isset($_POST['level']) ? $_POST['level'] : '';
    $grado = isset($_POST['grado']) ? $_POST['grado'] : '';
    $seccion = isset($_POST['seccion']) ? $_POST['seccion'] : '';
    $course = isset($_POST['course']) ? $_POST['course'] : '';
    $bimestre = isset($_POST['bimestre']) ? $_POST['bimestre'] : '';
    $type = isset($_POST['type']) ? $_POST['type'] : '';
    $date = isset($_POST['date']) ? $_POST['date'] : '';
    
    // Validación de usuario
    if (!isset($_SESSION['login_id'])) {
        echo json_encode([
            'status' => 0,
            'msg' => 'Usuario no autenticado'
        ]);
        exit;
    }
    
    // Construir la consulta según el filtro
    $where_clause = " WHERE e.teacher_id = $teacher_id ";
    
    // Si no se especifica año académico, usar el año activo por defecto
    if ($academic_year_id <= 0) {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        $active_year_query = $conn->query("SELECT id FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
        if ($active_year_query && $active_year_query->num_rows > 0) {
            $academic_year_id = $active_year_query->fetch_assoc()['id'];
        }
    }
    
    if ($academic_year_id > 0) {
        $where_clause .= " AND e.academic_year_id = $academic_year_id ";
    }
    
    // Aplicar filtro de nivel si está presente
    if (!empty($level)) {
        $level = $conn->real_escape_string($level);
        $where_clause .= " AND TRIM(LOWER(ac.level)) = TRIM(LOWER('$level')) ";
    }
    
    // Aplicar filtro de grado si está presente
    if (!empty($grado)) {
        // Limpiar el valor de grado: quitar espacios y símbolos, luego escapar
        $cleaned_grado = preg_replace('/[°º\s]+/', '', $grado);
        $cleaned_grado = $conn->real_escape_string($cleaned_grado);
        
        if (!empty($cleaned_grado)) {
            // Comparar en la BD de forma normalizada
            $where_clause .= " AND REPLACE(REPLACE(REPLACE(LOWER(tc.grado), '°', ''), 'º', ''), ' ', '') = LOWER('$cleaned_grado') ";
        }
    }
    
    // Aplicar filtro de sección si está presente
    if (!empty($seccion)) {
        $seccion = $conn->real_escape_string($seccion);
        $where_clause .= " AND TRIM(LOWER(tc.seccion)) = TRIM(LOWER('$seccion')) ";
    }
    
    // Aplicar filtro de curso si está presente
    if (!empty($course)) {
        $course = $conn->real_escape_string($course);
        $where_clause .= " AND TRIM(ac.name) = TRIM('$course') ";
    }
    
    // Aplicar filtro de bimestre si está presente
    if (!empty($bimestre)) {
        $bimestre = $conn->real_escape_string($bimestre);
        $where_clause .= " AND e.bimestre = '$bimestre' ";
    }
    
    // Aplicar filtro de tipo si está presente
    if (!empty($type)) {
        $type = $conn->real_escape_string($type);
        $where_clause .= " AND e.type = '$type' ";
    }
    
    // Aplicar filtro de fecha si está presente
    if (!empty($date)) {
        $date = $conn->real_escape_string($date);
        $where_clause .= " AND DATE(e.created_at) = '$date' ";
    }
    
    $query = "SELECT e.*, ac.name as course_name, ac.level, tc.grado, tc.seccion,
              ay.year as academic_year, ay.is_active,
              (SELECT COUNT(*) FROM evaluation_grades WHERE evaluation_id = e.id) as grades_count
              FROM evaluations e
              INNER JOIN teacher_courses tc ON tc.id = e.teacher_course_id
              INNER JOIN academic_courses ac ON ac.id = tc.course_id
              INNER JOIN academic_year ay ON ay.id = e.academic_year_id
              $where_clause
              AND ay.school_id = {$_SESSION['login_school_id']}
              ORDER BY e.created_at DESC";
    
    $result = $conn->query($query);
    if ($result) {
        $evaluations = array();
        while ($row = $result->fetch_assoc()) {
            // Sanitizar datos para la salida JSON
            $evaluations[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'description' => $row['description'] ?: '',
                'type' => $row['type'],
                'bimestre' => $row['bimestre'],
                'course_name' => $row['course_name'],
                'level' => $row['level'],
                'grado' => $row['grado'],
                'seccion' => $row['seccion'],
                'academic_year' => $row['academic_year'],
                'is_active' => (bool)$row['is_active'],
                'grades_count' => (int)$row['grades_count'],
                'created_at' => $row['created_at']
            ];
        }
        
        echo json_encode([
            'status' => 1,
            'data' => $evaluations
        ]);
    } else {
        echo json_encode([
            'status' => 0,
            'msg' => 'Error al consultar evaluaciones: ' . $conn->error
        ]);
    }
    exit;
}
