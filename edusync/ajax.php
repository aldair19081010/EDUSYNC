<?php
ob_start();
// Alinear ruta y cookie de sesión con login.php
ini_set('session.save_path', __DIR__ . '/tmp');
if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) session_start();
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
use PhpOffice\PhpSpreadsheet\IOFactory;
include 'db_connect.php';
$action = $_GET['action'] ?? null;
include 'admin_class.php';
$crud = new Action();

$evaluation_write_actions = ['save_evaluation', 'delete_evaluation', 'save_evaluation_grades'];
if (in_array($action, $evaluation_write_actions, true)) {
    $csrf_token = (string)($_POST['csrf_token'] ?? '');
    $session_token = (string)($_SESSION['csrf_token'] ?? '');
    if ($csrf_token === '' || $session_token === '' || !hash_equals($session_token, $csrf_token)) {
        header('Content-Type: application/json'); http_response_code(403);
        echo json_encode(['status'=>0,'message'=>'La sesión de seguridad venció. Recargue la página.']); exit;
    }
}

// Proteger las operaciones que modifican datos del módulo de estudiantes.
$student_write_actions = ['save_student', 'delete_student', 'upload_excel', 'bulk_update_students', 'save_fees', 'delete_fees', 'bulk_delete_fees', 'bulk_assign_fees', 'save_payment', 'delete_payment', 'bulk_delete_payment', 'process_payment_excel'];
if (in_array($action, $student_write_actions, true)) {
    $can_manage_students = false;
    if (!empty($_SESSION['login_id'])) {
        $role_query = $conn->prepare('SELECT type, is_director FROM users WHERE id = ? LIMIT 1');
        if ($role_query) {
            $login_id = (int)$_SESSION['login_id'];
            $role_query->bind_param('i', $login_id);
            $role_query->execute();
            $role = $role_query->get_result()->fetch_assoc();
            $can_manage_students = $role && (int)$role['type'] === 1;
            $role_query->close();
        }
    }
    if (!$can_manage_students) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'No tienes permisos para modificar estudiantes.']);
        exit;
    }
    $csrf_token = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    if ($session_token === '' || $csrf_token === '' || !hash_equals($session_token, $csrf_token)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Solicitud no autorizada. Recarga la página e inténtalo nuevamente.']);
        exit;
    }
}

$teacher_write_actions = ['save_teacher', 'delete_teacher', 'bulk_teacher_status', 'assign_teacher_course', 'delete_teacher_course', 'bulk_delete_teacher_courses', 'bulk_update_teacher_courses', 'replace_teacher_courses', 'clean_all_teacher_courses', 'copy_teacher_courses_from_previous_year', 'save_teacher_user', 'upload_teacher_excel'];
if (in_array($action, $teacher_write_actions, true)) {
    $can_manage_teachers = false;
    if (!empty($_SESSION['login_id'])) {
        $role_query = $conn->prepare('SELECT type, is_director FROM users WHERE id = ? LIMIT 1');
        if ($role_query) {
            $login_id = (int)$_SESSION['login_id'];
            $role_query->bind_param('i', $login_id); $role_query->execute();
			$role_type = 0; $role_is_director = 0;
			$role_query->bind_result($role_type, $role_is_director);
			if ($role_query->fetch()) $can_manage_teachers = ((int)$role_type === 1);
			$role_query->close();
        }
    }
    if (!$can_manage_teachers) {
        header('Content-Type: application/json'); http_response_code(403);
        echo json_encode(['status'=>0, 'message'=>'No tienes permisos para modificar docentes.']); exit;
    }
}

// Función para generar HTML de paginación
function generatePagination($current_page, $total_pages, $function_name, $level = null) {
    if ($total_pages <= 1) return '';
    
    $html = '<nav aria-label="Paginación de tabla">';
    $html .= '<ul class="pagination justify-content-center">';
    
    // Botón Anterior
    if ($current_page > 1) {
        $html .= '<li class="page-item">';
        if ($level !== null) {
            $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(\'' . $level . '\', ' . ($current_page - 1) . '); return false;">';
        } else {
            $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(' . ($current_page - 1) . '); return false;">';
        }
        $html .= '<i class="fa fa-chevron-left"></i> Anterior';
        $html .= '</a>';
        $html .= '</li>';
    } else {
        $html .= '<li class="page-item disabled">';
        $html .= '<span class="page-link"><i class="fa fa-chevron-left"></i> Anterior</span>';
        $html .= '</li>';
    }
    
    // Números de página
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $html .= '<li class="page-item">';
        if ($level !== null) {
            $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(\'' . $level . '\', 1); return false;">1</a>';
        } else {
            $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(1); return false;">1</a>';
        }
        $html .= '</li>';
        if ($start_page > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $html .= '<li class="page-item active">';
            $html .= '<span class="page-link">' . $i . '</span>';
            $html .= '</li>';
        } else {
            $html .= '<li class="page-item">';
            if ($level !== null) {
                $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(\'' . $level . '\', ' . $i . '); return false;">' . $i . '</a>';
            } else {
                $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(' . $i . '); return false;">' . $i . '</a>';
            }
            $html .= '</li>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item">';
        if ($level !== null) {
            $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(\'' . $level . '\', ' . $total_pages . '); return false;">' . $total_pages . '</a>';
        } else {
            $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(' . $total_pages . '); return false;">' . $total_pages . '</a>';
        }
        $html .= '</li>';
    }
    
    // Botón Siguiente
    if ($current_page < $total_pages) {
        $html .= '<li class="page-item">';
        if ($level !== null) {
            $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(\'' . $level . '\', ' . ($current_page + 1) . '); return false;">';
        } else {
            $html .= '<a class="page-link" href="#" onclick="' . $function_name . '(' . ($current_page + 1) . '); return false;">';
        }
        $html .= 'Siguiente <i class="fa fa-chevron-right"></i>';
        $html .= '</a>';
        $html .= '</li>';
    } else {
        $html .= '<li class="page-item disabled">';
        $html .= '<span class="page-link">Siguiente <i class="fa fa-chevron-right"></i></span>';
        $html .= '</li>';
    }
    
    $html .= '</ul>';
    $html .= '</nav>';
    
    return $html;
}

try {
	if ($action == "login") {
		header('Content-Type: application/json');
		$username = $_POST['username'] ?? '';
		$password = $_POST['password'] ?? '';
		$school_id = intval($_POST['school_id'] ?? 0);

		if (!$school_id) {
			echo json_encode(['status' => 0, 'message' => 'Debe seleccionar un colegio.']);
			exit;
		}

		$stmt = $conn->prepare(
			'SELECT u.*, t.id AS linked_teacher_id, t.status AS teacher_status
			 FROM users u
			 LEFT JOIN teacher t ON t.id = u.teacher_id AND t.school_id = u.school_id
			 WHERE u.username = ? AND u.password = MD5(?) AND u.school_id = ?
			 LIMIT 1'
		);
		$stmt->bind_param('ssi', $username, $password, $school_id);
		$stmt->execute();
		$user = $stmt->get_result();
		if ($user && $user->num_rows > 0) {
			$row = $user->fetch_assoc();
			$stmt->close();

			if ((int)$row['type'] === 2) {
				if (empty($row['teacher_id']) || empty($row['linked_teacher_id'])) {
					echo json_encode(['status' => 0, 'message' => 'Este usuario ya no pertenece a la institución. Comuníquese con el administrador.']);
					exit;
				}

				if (($row['teacher_status'] ?? 'Inactivo') !== 'Activo') {
					echo json_encode(['status' => 0, 'message' => 'Su usuario está inactivo y no puede iniciar sesión. Comuníquese con el administrador.']);
					exit;
				}
			}

			foreach ($row as $key => $value) {
				if ($key != 'password' && $key != 'linked_teacher_id' && $key != 'teacher_status' && !is_numeric($key)) {
					$_SESSION['login_' . $key] = $value;
				}
			}
			$_SESSION['user_name'] = $row['name']; // For topbar compatibility
			$_SESSION['login_school_id'] = $row['school_id'];
			
			// Ensure the avatar is loaded into the session
			if(isset($row['avatar']) && !empty($row['avatar'])) {
				$_SESSION['login_avatar'] = $row['avatar'];
			}
			
			// Asignar login_teacher_id si es docente
			if ($row['type'] == 2) {
				if (!empty($row['teacher_id'])) {
					$_SESSION['login_teacher_id'] = $row['teacher_id'];
				} else {
					// Buscar teacher_id por nombre si no está en users
					$tq = $conn->query("SELECT id FROM teacher WHERE name = '" . $conn->real_escape_string($row['name']) . "' LIMIT 1");
					if ($tq && $tq->num_rows > 0) {
						$_SESSION['login_teacher_id'] = $tq->fetch_assoc()['id'];
					}
				}
			}
            
            // Guardar valores antes de cerrar sesión
            $login_type = $_SESSION['login_type'] ?? null;
            $login_id = $_SESSION['login_id'] ?? null;
            $session_id_val = session_id();
            
            // Solo escribir la sesión sin regenerar ID
            // En algunos servidores, regenerate_id causa problemas con cookies
            session_write_close();
            
            // Retornar respuesta DESPUÉS de cerrar sesión
            echo json_encode([
                'status' => 1,
                'login_type' => $login_type,
                'login_id' => $login_id,
                'session_id' => $session_id_val
            ]);
			exit;
		} else {
			$stmt->close();
			echo json_encode(['status' => 0, 'message' => 'Usuario, contraseña o colegio incorrectos.']);
			exit;
		}
	}
	if ($action == 'login2') {
		$login = $crud->login2();
		if ($login)
			echo $login;
	}

    // Obtener conceptos de pago pendientes por alumno
    if ($action == 'get_pending_concepts') {
        header('Content-Type: application/json');
        $student_id = intval($_POST['student_id'] ?? 0);
        $school_id = intval($_SESSION['login_school_id'] ?? 0);
        if (!$student_id) {
            echo json_encode(['status' => 0, 'message' => 'student_id requerido']);
            exit;
        }

        $sql = "SELECT 
                ef.id AS ef_id,
                s.name AS student_name,
                s.id_no,
                s.nivel,
                s.grado,
                c.course,
                c.level,
                c.grades,
                COALESCE(ay.year, 'Sin año') AS year,
                COALESCE(eff.discounted_amount, eff.total_fee, ef.total_fee) AS total_fee,
                COALESCE(eff.discounted_amount, eff.total_fee, ef.total_fee) - COALESCE(SUM(p.amount), 0) AS balance,
                CONCAT(c.course, ' - ', c.level, ' (', s.grado, ') - ', COALESCE(ay.year, 'Sin año')) AS concepto_concatenado
            FROM student_ef_list ef
            INNER JOIN student s ON s.id = ef.student_id
            INNER JOIN courses c ON c.id = ef.course_id
            LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
            LEFT JOIN payments p ON p.ef_id = ef.id AND p.payment_status = 'Confirmado'
            LEFT JOIN student_ef_list eff ON eff.id = ef.id
            WHERE ef.student_id = $student_id" . ($school_id ? " AND s.school_id = $school_id" : "") . "
            GROUP BY ef.id
            HAVING balance > 0.0001
            ORDER BY
                CAST(COALESCE(ay.year, '9999') AS UNSIGNED) ASC,
                CASE WHEN ef.due_date IS NULL THEN 1 ELSE 0 END ASC,
                ef.due_date ASC,
                CASE WHEN ef.installment_number IS NULL THEN 1 ELSE 0 END ASC,
                ef.installment_number ASC,
                CASE
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%enero%' THEN 1
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%febrero%' THEN 2
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%marzo%' THEN 3
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%abril%' THEN 4
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%mayo%' THEN 5
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%junio%' THEN 6
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%julio%' THEN 7
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%agosto%' THEN 8
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%septiembre%' THEN 9
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%octubre%' THEN 10
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%noviembre%' THEN 11
                    WHEN LOWER(CONCAT(c.course, ' ', COALESCE(ef.billing_period, ''))) LIKE '%diciembre%' THEN 12
                    ELSE 99
                END ASC,
                c.course ASC,
                ef.id ASC";

        $res = $conn->query($sql);
        $data = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $data[] = [
                    'ef_id' => $row['ef_id'],
                    'concepto_concatenado' => $row['concepto_concatenado'],
                    'balance' => (float)$row['balance'],
                    'total_fee' => (float)$row['total_fee']
                ];
            }
            echo json_encode(['status' => 1, 'data' => $data]);
        } else {
            echo json_encode(['status' => 0, 'message' => 'Error al consultar conceptos pendientes']);
        }
        exit;
    }
	if ($action == 'logout') {
		$logout = $crud->logout();
		if ($logout)
			echo $logout;
	}
	if ($action == 'logout2') {
		$logout = $crud->logout2();
		if ($logout)
			echo $logout;
	}
	if ($action == 'save_user') {
		header('Content-Type: application/json');
		$save = $crud->save_user();
		if ($save == 1) {
			echo json_encode(['status' => 1, 'message' => 'Perfil actualizado con éxito.']);
		} else if ($save == 2) {
			echo json_encode(['status' => 2, 'message' => 'El nombre de usuario ya existe.']);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Ocurrió un error al guardar los datos.']);
		}
		exit;
	}
	if ($action == 'delete_user') {
		$save = $crud->delete_user();
		if ($save)
			echo $save;
	}
	if ($action == 'signup') {
		$save = $crud->signup();
		if ($save)
			echo $save;
	}
	if ($action == 'update_account') {
		$save = $crud->update_account();
		if ($save)
			echo $save;
	}
	if ($action == "save_settings") {
		$save = $crud->save_settings();
		if ($save)
			echo $save;
	}
	if ($action == "save_course") {
		// Compatibilidad: usar la API segura del módulo de conceptos.
		$action = 'save';
		include 'concepts_api.php';
		exit;
	}
	if ($action == "delete_course") {
		// Compatibilidad: elimina solo conceptos vacíos; con historial los archiva.
		$action = 'delete';
		include 'concepts_api.php';
		exit;
	}
	if ($action == "save_student") {
		header('Content-Type: application/json'); // Asegurar el encabezado JSON
		$save = $crud->save_student();
		if ($save == 1) {
			echo json_encode(['status' => 1, 'message' => 'Estudiante guardado exitosamente.']);
		} elseif ($save == 2) {
			echo json_encode(['status' => 2, 'message' => 'El ID del estudiante ya existe.']);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al guardar el estudiante.']);
		}
		exit;
	}
	if ($action == "delete_student") {
		header('Content-Type: application/json');
		$delete = $crud->delete_student();
		if ($delete == 1) {
			echo json_encode(['status' => 1, 'message' => 'Estudiante eliminado exitosamente.']);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al eliminar el estudiante.']);
		}
		exit;
	}
	if ($action == "save_fees") {
		$result = $crud->save_fees();
		if ($result == 1) {
			echo json_encode(['status' => 1, 'message' => 'Datos guardados exitósamente']);
		} else if ($result == 2) {
			echo json_encode(['status' => 2, 'message' => 'Número de Curso Existe Actualmente']);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al guardar los datos.']);
		}
		exit;
	}
	if ($action == "delete_fees") {
		$delete = $crud->delete_fees();
		header('Content-Type: application/json'); // Asegurar el encabezado JSON
		if ($delete == 1) {
			echo json_encode(['status' => 1, 'message' => 'Tarifas eliminadas exitosamente.']);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al eliminar las tarifas.']);
		}
		exit;
	}
	if ($action == "bulk_delete_fees") {
		$delete = $crud->bulk_delete_fees();
		header('Content-Type: application/json');
		if ($delete == 1) {
			echo json_encode(['status' => 1, 'message' => 'Tarifas seleccionadas eliminadas exitosamente.']);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al procesar la solicitud.']);
		}
		exit;
	}
	if ($action == "get_student_concepts") {
		header('Content-Type: application/json');
		$result = $crud->get_student_concepts();
		echo $result;
		exit;
	}
	if ($action == "save_payment") {
		header('Content-Type: application/json'); // Asegurar el encabezado JSON
		$operation_column = $conn->query("SHOW COLUMNS FROM payments LIKE 'operation_id'");
		if ($operation_column && $operation_column->num_rows > 0) {
			echo json_encode(['status' => 0, 'message' => 'Utilice el nuevo flujo transaccional de pagos. Los pagos confirmados ya no se editan directamente.']);
			exit;
		}
		$save = $crud->save_payment();
		if ($save) {
			echo $save; // La función `save_payment` ya devuelve un JSON válido
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al guardar el pago.']);
		}
		exit;
	}
	if ($action == "get_active_students") {
		header('Content-Type: application/json');
		$result = $crud->get_active_students();
		echo $result;
		exit;
	}
	if ($action == "get_available_concepts_for_bulk") {
		header('Content-Type: application/json');
		$result = $crud->get_available_concepts_for_bulk();
		echo $result;
		exit;
	}
	if ($action == "bulk_assign_fees") {
		header('Content-Type: application/json');
		$result = $crud->bulk_assign_fees();
		echo $result;
		exit;
	}
	if ($action == "delete_payment") {
		$delete = $crud->delete_payment();
		header('Content-Type: application/json'); // Asegurar el encabezado JSON
		if ($delete == 1) {
			echo json_encode(['status' => 1, 'message' => 'Pago eliminado exitosamente.']);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al eliminar el pago.']);
		}
		exit;
	}
	if ($action == "bulk_delete_payment") {
		$delete = $crud->bulk_delete_payment();
		header('Content-Type: application/json');
		if ($delete == 1) {
			echo json_encode(['status' => 1, 'message' => 'Pagos eliminados exitosamente.']);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al eliminar los pagos en masa.']);
		}
		exit;
	}
	if ($action == 'upload_excel') {
		include 'upload_excel.php'; // Incluir el archivo que maneja la subida de Excel
		exit; // Asegurarse de que no se ejecute más código después
	}

	if ($action == 'upload_teacher_excel') {
		include 'upload_teacher_excel.php'; // Incluir el archivo que maneja la subida de Excel para docentes
		exit; // Asegurarse de que no se ejecute más código después
	}

	if ($action == 'process_payment_excel') {
		include 'process_payment_excel.php';
		exit;
	}

	// Las rutas antiguas no validaban colegio, cierre diario ni CSRF. El módulo
	// actualizado usa attendance_api.php y se bloquean aquí para evitar bypasses.
	if (in_array($action, ['save_asistencia', 'get_asistencia', 'save_asistencia_barcode', 'get_students_for_asistencia'], true)) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(410);
		echo json_encode(['status' => 0, 'message' => 'Esta ruta de asistencia fue reemplazada. Recargue el módulo.'], JSON_UNESCAPED_UNICODE);
		exit;
	}
	if ($action == 'save_asistencia') {
		header('Content-Type: application/json');
		$student_id = $_POST['student_id'];
		$fecha = $_POST['fecha'];
		$tipo = $_POST['tipo'];
		$hora = isset($_POST['hora']) && $_POST['hora'] ? $_POST['hora'] : date('H:i:s'); // USAR la hora enviada por el usuario si existe
		$estado = $_POST['estado'];
		// Si es Ausente (incluida 'Ausente Justificada'), forzar hora 00:00:00
		if (stripos($estado, 'Ausente') === 0) {
			$hora = '00:00:00';
		}
        // Si no es Ausente, calcular estado (Temprano/Normal/Tarde) según reglas
        if (stripos($estado, 'Ausente') !== 0) {
            require_once 'attendance_state_helper.php';
            // usar HH:mm para el cálculo, fecha/tipo provienen del formulario
            $school_id = intval($_SESSION['login_school_id'] ?? 0);
            $estado = calcular_estado_asistencia(substr($hora,0,5), $fecha, $tipo, $conn, $school_id);
        }
		$check = $conn->query("SELECT id FROM asistencia WHERE student_id='$student_id' AND fecha='$fecha' AND tipo='$tipo'");
		if ($check && $check->num_rows > 0) {
			echo json_encode(['status' => 0, 'message' => 'Ya existe un registro de ' . $tipo . ' para este estudiante en esta fecha.']);
		} else {
			$save = $conn->query("INSERT INTO asistencia (student_id, fecha, tipo, hora, estado) VALUES ('$student_id', '$fecha', '$tipo', '$hora', '$estado')");
			if ($save) {
				echo json_encode(['status' => 1, 'message' => 'Asistencia guardada correctamente.']);
			} else {
				echo json_encode(['status' => 0, 'message' => 'Error al guardar la asistencia.']);
			}
		}
		exit;
	}
	if ($action == 'get_asistencia') {
		header('Content-Type: application/json');
		$fecha = $_POST['fecha'];
			$tipo = isset($_POST['tipo']) ? $_POST['tipo'] : '';
		$where_tipo = $tipo ? " AND a.tipo = '$tipo'" : "";
		$q = $conn->query("SELECT a.*, s.name, s.id_no FROM asistencia a INNER JOIN student s ON s.id = a.student_id WHERE a.fecha = '$fecha' $where_tipo ORDER BY a.tipo ASC, a.hora ASC");
		$data = [];
		while($row = $q->fetch_assoc()) {
			$data[] = $row;
		}
		echo json_encode($data);
		exit;
	}
	if ($action == 'save_asistencia_barcode') {
		header('Content-Type: application/json');
		$dni = $_POST['dni'];
		$fecha = $_POST['fecha'];
		$tipo = isset($_POST['tipo']) ? $_POST['tipo'] : 'Entrada';
		$hora = isset($_POST['hora_actual']) ? $_POST['hora_actual'] : date('H:i:s');

		$q = $conn->query("SELECT id, name FROM student WHERE id_no = '$dni' LIMIT 1");
		if ($q && $q->num_rows > 0) {
			$student = $q->fetch_assoc();
			$student_id = $student['id'];
			$check = $conn->query("SELECT id FROM asistencia WHERE student_id='$student_id' AND fecha='$fecha' AND tipo='$tipo'");
			if ($check && $check->num_rows > 0) {
				echo json_encode(['status' => 0, 'message' => "Ya se registró $tipo para este estudiante hoy."]);
				exit;
			}
            require_once 'attendance_state_helper.php';
            $school_id = intval($_SESSION['login_school_id'] ?? 0);
            $estado = calcular_estado_asistencia(substr($hora,0,5), $fecha, $tipo, $conn, $school_id);
			$save = $conn->query("INSERT INTO asistencia (student_id, fecha, tipo, hora, estado) VALUES ('$student_id', '$fecha', '$tipo', '$hora', '$estado')");
			if ($save) {
				echo json_encode(['status' => 1, 'message' => "Asistencia registrada ($tipo/$estado) para " . $student['name']]);
			} else {
				echo json_encode(['status' => 0, 'message' => 'Error al guardar la asistencia.']);
			}
		} else {
			echo json_encode(['status' => 0, 'message' => 'DNI no encontrado.']);
		}
		exit;
	}
	if ($action == 'get_students_for_asistencia') {
		header('Content-Type: application/json');
		$q = $conn->query("SELECT id, name, id_no FROM student ORDER BY name ASC");
		$data = [];
		while($row = $q->fetch_assoc()) {
			$data[] = $row;
		}
		echo json_encode($data);
		exit;
	}
	if ($action == "save_teacher") {
		header('Content-Type: application/json');
		$save = $crud->save_teacher();
		echo $save;
		exit;
	}
	if ($action == "delete_teacher") {
		header('Content-Type: application/json');
		echo $crud->delete_teacher();
		exit;
	}
	if ($action == "bulk_teacher_status") {
		header('Content-Type: application/json');
		echo $crud->bulk_teacher_status();
		exit;
	}
	if ($action == "save_grade") {
		header('Content-Type: application/json');
		$save = $crud->save_grade();
		echo $save;
		exit;
	}
	if ($action == "get_course_competencies") {
		header('Content-Type: application/json');
		$course_id = intval($_POST['course_id'] ?? 0);
		$teacher_id = $_SESSION['login_teacher_id'] ?? 0;
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		$academic_year_id = intval($_POST['academic_year_id'] ?? 0);
		$selected_competency_id = intval($_POST['selected_competency_id'] ?? 0);
		
		if (!$course_id || !$teacher_id) {
			echo json_encode(['status' => 0, 'message' => 'Parámetros inválidos']);
			exit;
		}
		
		if ($academic_year_id <= 0 && $school_id > 0) {
			$year_query = $conn->query("SELECT id FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
			if ($year_query && $year_query->num_rows > 0) {
				$academic_year_id = intval($year_query->fetch_assoc()['id']);
			}
		}
		
		$year_filter = $academic_year_id > 0 ? "AND gcc.academic_year_id = $academic_year_id" : "";
		$query = $conn->query("
			SELECT gcc.id, gcc.name, gcc.percentage, ac.name as course_name, a.name as area_name, a.color as area_color, gcc.academic_year_id
			FROM general_course_competencies gcc
			JOIN academic_courses ac ON ac.id = gcc.course_id
			LEFT JOIN areas a ON a.id = ac.area_id
			WHERE gcc.course_id = $course_id AND gcc.teacher_id = $teacher_id AND gcc.is_active = 1 $year_filter
			ORDER BY gcc.name ASC
		");
		
		$competencias = [];
		if ($query && $query->num_rows > 0) {
			while ($row = $query->fetch_assoc()) {
				$competencias[$row['id']] = $row;
			}
		}
		if ($selected_competency_id > 0) {
			$selected_query = $conn->query("
				SELECT gcc.id, gcc.name, gcc.percentage, ac.name as course_name, a.name as area_name, a.color as area_color, gcc.academic_year_id
				FROM general_course_competencies gcc
				JOIN academic_courses ac ON ac.id = gcc.course_id
				LEFT JOIN areas a ON a.id = ac.area_id
				WHERE gcc.id = $selected_competency_id AND gcc.teacher_id = $teacher_id
				LIMIT 1
			");
			if ($selected_query && $selected_query->num_rows > 0) {
				$selected_row = $selected_query->fetch_assoc();
				if (!isset($competencias[$selected_competency_id])) {
					$competencias[$selected_competency_id] = $selected_row;
				}
			}
		}
		
		echo json_encode(['status' => 1, 'competencies' => array_values($competencias)]);
		exit;
	}
	if ($action == "get_course_id_by_name") {
		header('Content-Type: application/json');
		$course_name = $_POST['course_name'] ?? '';
		$level = $_POST['level'] ?? '';
		$grado = $_POST['grado'] ?? '';
		$seccion = $_POST['seccion'] ?? '';
		$teacher_id = $_SESSION['login_teacher_id'] ?? 0;
		
		if (!$course_name || !$level || !$grado || !$seccion || !$teacher_id) {
			echo json_encode(['status' => 0, 'message' => 'Parámetros inválidos']);
			exit;
		}
		
		// Obtener el course_id del curso seleccionado
		$query = $conn->query("
			SELECT ac.id as course_id
			FROM teacher_courses tc
			JOIN academic_courses ac ON ac.id = tc.course_id
			WHERE tc.teacher_id = $teacher_id 
			AND ac.name = '" . $conn->real_escape_string($course_name) . "'
			AND ac.level = '" . $conn->real_escape_string($level) . "'
			AND tc.grado = '" . $conn->real_escape_string($grado) . "'
			AND (tc.seccion = '" . $conn->real_escape_string($seccion) . "' OR (tc.seccion IS NULL AND '" . $conn->real_escape_string($seccion) . "' = 'U'))
			LIMIT 1
		");
		
		if ($query && $query->num_rows > 0) {
			$row = $query->fetch_assoc();
			echo json_encode(['status' => 1, 'course_id' => $row['course_id']]);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Curso no encontrado']);
		}
		exit;
	}
	if ($action == "get_course_competencies_by_name_level") {
		header('Content-Type: application/json');
		$course_name = $_POST['course_name'] ?? '';
		$level = $_POST['level'] ?? '';
		$teacher_id = $_SESSION['login_teacher_id'] ?? 0;
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		$academic_year_id = intval($_POST['academic_year_id'] ?? 0);
		$selected_competency_id = intval($_POST['selected_competency_id'] ?? 0);
		
		if (!$course_name || !$level || !$teacher_id) {
			echo json_encode(['status' => 0, 'message' => 'Parámetros inválidos']);
			exit;
		}
		
		if ($academic_year_id <= 0 && $school_id > 0) {
			$year_query = $conn->query("SELECT id FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
			if ($year_query && $year_query->num_rows > 0) {
				$academic_year_id = intval($year_query->fetch_assoc()['id']);
			}
		}
		
		$year_filter = $academic_year_id > 0 ? "AND gcc.academic_year_id = $academic_year_id" : "";
		$query = $conn->query("
			SELECT gcc.id, gcc.name, gcc.percentage, ac.name as course_name, a.name as area_name, a.color as area_color, gcc.academic_year_id
			FROM general_course_competencies gcc
			JOIN academic_courses ac ON ac.id = gcc.course_id
			LEFT JOIN areas a ON a.id = ac.area_id
			WHERE gcc.teacher_id = $teacher_id 
			AND gcc.is_active = 1
			AND ac.name = '" . $conn->real_escape_string($course_name) . "'
			AND ac.level = '" . $conn->real_escape_string($level) . "'
			$year_filter
			ORDER BY gcc.name ASC
		");
		
		$competencias = [];
		if ($query && $query->num_rows > 0) {
			while ($row = $query->fetch_assoc()) {
				$competencias[$row['id']] = $row;
			}
		}
		if ($selected_competency_id > 0) {
			$selected_query = $conn->query("
				SELECT gcc.id, gcc.name, gcc.percentage, ac.name as course_name, a.name as area_name, a.color as area_color, gcc.academic_year_id
				FROM general_course_competencies gcc
				JOIN academic_courses ac ON ac.id = gcc.course_id
				LEFT JOIN areas a ON a.id = ac.area_id
				WHERE gcc.id = $selected_competency_id AND gcc.teacher_id = $teacher_id
				LIMIT 1
			");
			if ($selected_query && $selected_query->num_rows > 0) {
				$selected_row = $selected_query->fetch_assoc();
				if (!isset($competencias[$selected_competency_id])) {
					$competencias[$selected_competency_id] = $selected_row;
				}
			}
		}
		
		echo json_encode(['status' => 1, 'competencies' => array_values($competencias)]);
		exit;
	}
	if ($action == "delete_grade") {
		header('Content-Type: application/json');
		$delete = $crud->delete_grade();
		echo $delete;
		exit;
	}
	if ($action == "save_academic_course") {
		header('Content-Type: application/json');
		$id = $_POST['id'] ?? '';
		$name = $conn->real_escape_string($_POST['name']);
		$level = $conn->real_escape_string($_POST['level']);
		$description = $conn->real_escape_string($_POST['description']);
		$area_id = !empty($_POST['area_id']) ? intval($_POST['area_id']) : 'NULL';
		
		// Obtener el school_id del administrador actual
		$school_id = $_SESSION['login_school_id'] ?? 0;
		
		// Verificar que el área pertenezca al mismo colegio si se especifica
		if ($area_id !== 'NULL') {
			$area_check = $conn->query("SELECT * FROM areas WHERE id = $area_id AND school_id = $school_id");
			if (!$area_check || $area_check->num_rows == 0) {
				echo json_encode(['status' => 0, 'message' => 'El área seleccionada no es válida.']);
				exit;
			}
		}
		
		if (empty($id)) {
			// Al crear nuevo curso, asignar el school_id del administrador
			$save = $conn->query("INSERT INTO academic_courses (name, level, description, school_id, area_id) 
							 VALUES ('$name', '$level', '$description', $school_id, $area_id)");
		} else {
			// Al actualizar, verificar que el curso pertenezca al mismo colegio
			$check = $conn->query("SELECT * FROM academic_courses WHERE id = $id AND school_id = $school_id");
			if ($check && $check->num_rows > 0) {
				$save = $conn->query("UPDATE academic_courses SET name='$name', level='$level', description='$description', area_id=$area_id WHERE id=$id");
			} else {
				echo json_encode(['status' => 0, 'message' => 'No tiene permiso para editar este curso.']);
				exit;
			}
		}
		
		if ($save) {
			echo json_encode(['status' => 1, 'message' => 'Curso guardado exitosamente.']);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al guardar el curso: ' . $conn->error]);
		}
		exit;
	}
	if ($action == "delete_academic_course") {
		header('Content-Type: application/json');
		$id = $_POST['id'];
		$delete = $conn->query("DELETE FROM academic_courses WHERE id = $id");
		if ($delete) {
			echo json_encode(['status' => 1]);
		} else {
			echo json_encode(['status' => 0, 'message' => 'Error al eliminar el curso.']);
		}
		exit;
	}
	if ($action == "assign_teacher_course") {
		header('Content-Type: application/json');
		echo $crud->assign_teacher_course();
		exit;
	}
	if ($action == "delete_teacher_course") {
		header('Content-Type: application/json');
		echo $crud->delete_teacher_course();
		exit;
	}
	if ($action == "bulk_delete_teacher_courses") {
		header('Content-Type: application/json');
		echo $crud->bulk_delete_teacher_courses();
		exit;
	}
	if ($action == "replace_teacher_courses") {
		header('Content-Type: application/json');
		echo $crud->replace_teacher_courses();
		exit;
	}
	if ($action == "bulk_update_teacher_courses") {
		header('Content-Type: application/json');
		echo $crud->bulk_update_teacher_courses();
		exit;
	}
	if ($action == "save_teacher_user") {
		header('Content-Type: application/json');
		echo $crud->save_teacher_user();
		exit;
	}
	if ($action == "save_evaluation") {
		header('Content-Type: application/json');
		echo $crud->save_evaluation();
		exit;
	}
	if ($action == "delete_evaluation") {
		header('Content-Type: application/json');
		echo $crud->delete_evaluation();
		exit;
	}
	if ($action == 'get_students_by_grado_seccion') {
		header('Content-Type: application/json');
		$grado = $_POST['grado'] ?? '';
		$seccion = $_POST['seccion'] ?? '';
        $level = $_POST['level'] ?? '';
        $academic_year_id = $_POST['academic_year_id'] ?? '';
        $school_id = $_SESSION['login_school_id'] ?? 0;
		
		$students = [];
		$student_ids = []; // Para evitar duplicados
		
		if ($grado && $seccion && $level) {
			// Normalizamos el nivel para evitar problemas con espacios y mayúsculas
			$normalized_level = strtolower(str_replace(' ', '', trim($level)));
			
			// QUERY 1: Estudiantes activos en la tabla student que pertenecen al grado/sección
			$sql1 = "SELECT DISTINCT s.id, s.name, s.id_no 
					FROM student s
					WHERE s.school_id = ? 
					AND s.grado = ? 
					AND s.seccion = ? 
					AND LOWER(REPLACE(TRIM(s.nivel), ' ', '')) = ? 
					AND (s.status = 'Activo' OR s.status IS NULL)
					ORDER BY s.name ASC";
			
			$q1 = $conn->prepare($sql1);
			$q1->bind_param("isss", $school_id, $grado, $seccion, $normalized_level);
			$q1->execute();
			$result1 = $q1->get_result();
			
			while($stu = $result1->fetch_assoc()) {
				$student_ids[$stu['id']] = true;
				$students[] = [
					'id' => $stu['id'],
					'name' => ucwords($stu['name']),
					'id_no' => $stu['id_no']
				];
			}
			$q1->close();
			
			// QUERY 2: Agregar estudiantes que tienen evaluaciones (pueden haber sido promovidos)
			if ($academic_year_id) {
				$sql2 = "SELECT DISTINCT s.id, s.name, s.id_no 
						FROM student s
						INNER JOIN evaluation_grades eg ON s.id = eg.student_id
						INNER JOIN evaluations e ON eg.evaluation_id = e.id
						INNER JOIN teacher_courses tc ON e.teacher_course_id = tc.id
						WHERE s.school_id = ? 
						AND tc.grado = ? 
						AND tc.seccion = ? 
						AND LOWER(REPLACE(TRIM(s.nivel), ' ', '')) = ? 
						AND tc.academic_year_id = ?
						AND (s.status = 'Activo' OR s.status IS NULL)
						ORDER BY s.name ASC";
				
				$q2 = $conn->prepare($sql2);
				$q2->bind_param("isssi", $school_id, $grado, $seccion, $normalized_level, $academic_year_id);
				$q2->execute();
				$result2 = $q2->get_result();
				
				while($stu = $result2->fetch_assoc()) {
					if (!isset($student_ids[$stu['id']])) {
						$student_ids[$stu['id']] = true;
						$students[] = [
							'id' => $stu['id'],
							'name' => ucwords($stu['name']),
							'id_no' => $stu['id_no']
						];
					}
				}
				$q2->close();
			}
			
			// Ordenar por nombre
			usort($students, function($a, $b) {
				return strcmp($a['name'], $b['name']);
			});
		}
		
		if (count($students) > 0) {
			echo json_encode(['status' => 1, 'students' => $students]);
		} else {
			echo json_encode(['status' => 1, 'students' => [], 'message' => 'No hay alumnos para el grado, sección y nivel seleccionados.']);
		}
		exit;
	}
	if ($action == 'get_evaluations_by_filters') {
		header('Content-Type: application/json');
		// Permitir course_id o course_name
		$course_id = $_POST['course_id'] ?? '';
		$course_name = $_POST['course_name'] ?? '';
		$level = $_POST['level'] ?? '';
		$grado = $_POST['grado'] ?? '';
		$seccion = $_POST['seccion'] ?? '';
		$bimestre = $_POST['bimestre'] ?? '';
		$academic_year_id = $_POST['academic_year_id'] ?? '';
		$school_id = $_SESSION['login_school_id'] ?? 0;
		
		$evaluations = [];
		
		// Si solo viene course_id, buscar el nombre
		if ($course_id && !$course_name) {
			$qcn = $conn->prepare("SELECT name FROM academic_courses WHERE id = ?");
			$qcn->bind_param("i", $course_id);
			$qcn->execute();
			$result = $qcn->get_result();
			if ($result->num_rows > 0) {
				$course_name = $result->fetch_assoc()['name'];
			}
			$qcn->close();
		}
		
		// Buscar el teacher_course_id correspondiente con filtro de año académico
		$sql = "SELECT tc.id FROM teacher_courses tc 
				INNER JOIN academic_courses ac ON ac.id = tc.course_id 
				WHERE tc.school_id = ?";
		
		$params = [$school_id];
		$types = "i";
		
		if ($course_name) {
			$sql .= " AND ac.name = ?";
			$params[] = $course_name;
			$types .= "s";
		}
		
		if ($level) {
			$sql .= " AND ac.level = ?";
			$params[] = $level;
			$types .= "s";
		}
		
		if ($grado) {
			$sql .= " AND tc.grado = ?";
			$params[] = $grado;
			$types .= "s";
		}
		
		if ($seccion) {
			$sql .= " AND tc.seccion = ?";
			$params[] = $seccion;
			$types .= "s";
		}
		
		if ($academic_year_id) {
			$sql .= " AND tc.academic_year_id = ?";
			$params[] = $academic_year_id;
			$types .= "i";
		}
		
		$teacher_course_ids = [];
		$qtc = $conn->prepare($sql);
		$qtc->bind_param($types, ...$params);
		$qtc->execute();
		$result = $qtc->get_result();
		
		while($row = $result->fetch_assoc()) {
			$teacher_course_ids[] = $row['id'];
		}
		$qtc->close();
		
		if (!empty($teacher_course_ids)) {
			$placeholders = str_repeat('?,', count($teacher_course_ids) - 1) . '?';
			$eval_sql = "SELECT id, title FROM evaluations WHERE teacher_course_id IN ($placeholders)";
			$eval_params = $teacher_course_ids;
			$eval_types = str_repeat('i', count($teacher_course_ids));
			
			if ($bimestre) {
				$eval_sql .= " AND bimestre = ?";
				$eval_params[] = $bimestre;
				$eval_types .= "s";
			}
			
			$eval_sql .= " ORDER BY title ASC";
			
			$q = $conn->prepare($eval_sql);
			$q->bind_param($eval_types, ...$eval_params);
			$q->execute();
			$result = $q->get_result();
			
			while($ev = $result->fetch_assoc()) {
				$evaluations[] = [
					'id' => $ev['id'],
					'title' => $ev['title']
				];
			}
			$q->close();
		}
		
		echo json_encode(['status' => 1, 'evaluations' => $evaluations]);
		exit;
	}
	if ($action == 'get_grados_by_nivel') {
		header('Content-Type: application/json');
		$level = $_POST['level'] ?? '';
		$academic_year_id = $_POST['academic_year_id'] ?? '';
		$school_id = $_SESSION['login_school_id'] ?? 0;
		
		// Normalizar nivel para evitar problemas con espacios y mayúsculas
		$normalized_level = strtolower(str_replace(' ', '', trim($level)));
		
		$grados = [];
		if ($level) {
			if ($_SESSION['login_type'] == 2 && isset($_SESSION['login_teacher_id'])) {
				// Para profesores, solo mostrar sus grados asignados
				$teacher_id = $_SESSION['login_teacher_id'];
				$sql = "SELECT DISTINCT tc.grado 
						FROM teacher_courses tc 
						INNER JOIN academic_courses ac ON tc.course_id = ac.id 
						WHERE tc.teacher_id = ? 
						AND tc.school_id = ? 
						AND LOWER(REPLACE(TRIM(ac.level), ' ', '')) = ?";
				
				if ($academic_year_id) {
					$sql .= " AND tc.academic_year_id = ?";
				}
				
				$sql .= " ORDER BY tc.grado";
				
				$q = $conn->prepare($sql);
				if ($academic_year_id) {
					$q->bind_param("iisi", $teacher_id, $school_id, $normalized_level, $academic_year_id);
				} else {
					$q->bind_param("iis", $teacher_id, $school_id, $normalized_level);
				}
			} else {
				// Para administradores, usar teacher_courses con academic_year_id
				$sql = "SELECT DISTINCT tc.grado 
						FROM teacher_courses tc 
						INNER JOIN academic_courses ac ON tc.course_id = ac.id 
						WHERE tc.school_id = ? 
						AND LOWER(REPLACE(TRIM(ac.level), ' ', '')) = ?";
				
				if ($academic_year_id) {
					$sql .= " AND tc.academic_year_id = ?";
				}
				
				$sql .= " ORDER BY tc.grado";
				
				$q = $conn->prepare($sql);
				if ($academic_year_id) {
					$q->bind_param("isi", $school_id, $normalized_level, $academic_year_id);
				} else {
					$q->bind_param("is", $school_id, $normalized_level);
				}
			}
			
			if ($q) {
				$q->execute();
				$result = $q->get_result();
				while($row = $result->fetch_assoc()) {
					$grados[] = $row['grado'];
				}
				$q->close();
			}
		}
		
		echo json_encode(['status' => 1, 'grados' => $grados]);
		exit;
	}
	
	if ($action == 'get_secciones_by_grado_nivel') {
		header('Content-Type: application/json');
		$level = $_POST['level'] ?? '';
		$grado = $_POST['grado'] ?? '';
		$academic_year_id = $_POST['academic_year_id'] ?? '';
		$school_id = $_SESSION['login_school_id'] ?? 0;
		
		// Normalizar nivel para evitar problemas con espacios y mayúsculas
		$normalized_level = strtolower(str_replace(' ', '', trim($level)));
		
		$secciones = [];
		if ($level && $grado) {
			if ($_SESSION['login_type'] == 2 && isset($_SESSION['login_teacher_id'])) {
				// Para profesores, solo mostrar sus secciones asignadas
				$teacher_id = $_SESSION['login_teacher_id'];
				$sql = "SELECT DISTINCT tc.seccion 
						FROM teacher_courses tc 
						INNER JOIN academic_courses ac ON tc.course_id = ac.id 
						WHERE tc.teacher_id = ? 
						AND tc.school_id = ? 
						AND LOWER(REPLACE(TRIM(ac.level), ' ', '')) = ? 
						AND tc.grado = ?";
				
				if ($academic_year_id) {
					$sql .= " AND tc.academic_year_id = ?";
				}
				
				$sql .= " ORDER BY tc.seccion";
				
				$q = $conn->prepare($sql);
				if ($academic_year_id) {
					$q->bind_param("iissi", $teacher_id, $school_id, $normalized_level, $grado, $academic_year_id);
				} else {
					$q->bind_param("iiss", $teacher_id, $school_id, $normalized_level, $grado);
				}
			} else {
				// Para administradores, usar teacher_courses para ser consistente con el año académico
				$sql = "SELECT DISTINCT tc.seccion 
						FROM teacher_courses tc 
						INNER JOIN academic_courses ac ON tc.course_id = ac.id 
						WHERE tc.school_id = ? 
						AND LOWER(REPLACE(TRIM(ac.level), ' ', '')) = ? 
						AND tc.grado = ?";
				
				if ($academic_year_id) {
					$sql .= " AND tc.academic_year_id = ?";
				}
				
				$sql .= " ORDER BY tc.seccion";
				
				$q = $conn->prepare($sql);
				if ($academic_year_id) {
					$q->bind_param("issi", $school_id, $normalized_level, $grado, $academic_year_id);
				} else {
					$q->bind_param("iss", $school_id, $normalized_level, $grado);
				}
			}
			
			if ($q) {
				$q->execute();
				$result = $q->get_result();
				while($row = $result->fetch_assoc()) {
					$secciones[] = $row['seccion'];
				}
				$q->close();
			}
		}
		
		echo json_encode(['status' => 1, 'secciones' => $secciones]);
		exit;
	}
	
	if ($action == 'get_courses_by_aula') {
		header('Content-Type: application/json');
		$level = $_POST['level'] ?? '';
		$grado = $_POST['grado'] ?? '';
		$seccion = $_POST['seccion'] ?? '';
		$academic_year_id = $_POST['academic_year_id'] ?? '';
		$school_id = $_SESSION['login_school_id'] ?? 0;
		
		// Normalizar nivel para evitar problemas con espacios y mayúsculas
		$normalized_level = strtolower(str_replace(' ', '', trim($level)));
		
		$courses = [];
		if ($level && $grado && $seccion) {
			if ($_SESSION['login_type'] == 2 && isset($_SESSION['login_teacher_id'])) {
				// Para profesores, solo mostrar sus cursos asignados
				$teacher_id = $_SESSION['login_teacher_id'];
				$sql = "SELECT DISTINCT ac.id, ac.name 
						FROM teacher_courses tc 
						INNER JOIN academic_courses ac ON tc.course_id = ac.id 
						WHERE tc.teacher_id = ? 
						AND tc.school_id = ? 
						AND LOWER(REPLACE(TRIM(ac.level), ' ', '')) = ? 
						AND tc.grado = ? 
						AND tc.seccion = ?";
				
				if ($academic_year_id) {
					$sql .= " AND tc.academic_year_id = ?";
				}
				
				$sql .= " ORDER BY ac.name";
				
				$q = $conn->prepare($sql);
				if ($academic_year_id) {
					$q->bind_param("iisssi", $teacher_id, $school_id, $normalized_level, $grado, $seccion, $academic_year_id);
				} else {
					$q->bind_param("iisss", $teacher_id, $school_id, $normalized_level, $grado, $seccion);
				}
			} else {
				// Para administradores, mostrar todos los cursos
				$sql = "SELECT DISTINCT ac.id, ac.name 
						FROM academic_courses ac 
						INNER JOIN teacher_courses tc ON ac.id = tc.course_id 
						WHERE ac.school_id = ? 
						AND LOWER(REPLACE(TRIM(ac.level), ' ', '')) = ? 
						AND tc.grado = ? 
						AND tc.seccion = ?";
				
				if ($academic_year_id) {
					$sql .= " AND tc.academic_year_id = ?";
				}
				
				$sql .= " ORDER BY ac.name";
				
				$q = $conn->prepare($sql);
				if ($academic_year_id) {
					$q->bind_param("isssi", $school_id, $normalized_level, $grado, $seccion, $academic_year_id);
				} else {
					$q->bind_param("isss", $school_id, $normalized_level, $grado, $seccion);
				}
			}
			
			if ($q) {
				$q->execute();
				$result = $q->get_result();
				while($row = $result->fetch_assoc()) {
					$courses[] = [
						'id' => $row['id'],
						'name' => $row['name']
					];
				}
				$q->close();
			}
		}
		
		echo json_encode(['status' => 1, 'courses' => $courses]);
		exit;
	}
	
	if ($action == 'get_levels_by_academic_year') {
		header('Content-Type: application/json');
		$academic_year_id = $_POST['academic_year_id'] ?? '';
		$school_id = $_SESSION['login_school_id'] ?? 0;
		$login_type = $_SESSION['login_type'] ?? null;
		
		$levels = [];
		if ($academic_year_id && $school_id) {
			if ($login_type == 1) {
				// Para administradores, obtener todos los niveles del año académico
				$q = $conn->prepare("SELECT DISTINCT ac.level FROM academic_courses ac 
									 INNER JOIN teacher_courses tc ON ac.id = tc.course_id 
									 WHERE ac.school_id = ? AND tc.academic_year_id = ? AND ac.level != '' 
									 ORDER BY ac.level");
				$q->bind_param("ii", $school_id, $academic_year_id);
			} else if ($login_type == 2 && isset($_SESSION['login_teacher_id'])) {
				// Para profesores, solo niveles de sus cursos asignados
				$teacher_id = $_SESSION['login_teacher_id'];
				$q = $conn->prepare("SELECT DISTINCT ac.level FROM teacher_courses tc 
									 INNER JOIN academic_courses ac ON tc.course_id = ac.id 
									 WHERE tc.teacher_id = ? AND tc.school_id = ? AND tc.academic_year_id = ? AND ac.level != '' 
									 ORDER BY ac.level");
				$q->bind_param("iii", $teacher_id, $school_id, $academic_year_id);
			}
			
			if (isset($q)) {
				$q->execute();
				$result = $q->get_result();
				while($row = $result->fetch_assoc()) {
					$levels[] = $row['level'];
				}
				$q->close();
			}
		}
		
		echo json_encode(['status' => 1, 'levels' => $levels]);
		exit;
	}
	
	if ($action == "save_attendance_settings") {
        $save = $crud->save_attendance_settings();
        echo $save;
        exit;
    }
    if ($action == "save_day_attendance_rule") {
        $save = $crud->save_day_attendance_rule();
        echo $save;
        exit;
    }
	if ($action == 'marcar_ausentes_dia') {
	header('Content-Type: application/json');
	$fecha = $_POST['fecha'] ?? date('Y-m-d');
	$tipo = $_POST['tipo'] ?? 'Entrada';
	// Respetar reglas de asistencia: si el día está inactivo, no marcar
	$dayOfWeek = date('l', strtotime($fecha)); // Monday, Tuesday, ...
    $use_day_rules = '0';
    $school_id = intval($_SESSION['login_school_id'] ?? 0);
    $school_filter_teacher_courses = $school_id ? " AND tc.school_id = $school_id" : " AND 1 = 0";
    $school_filter_students = $school_id ? " AND s.school_id = $school_id" : " AND 1 = 0";
    $has_settings_school = false;
    $has_rules_school = false;
    $col_settings = $conn->query("SHOW COLUMNS FROM attendance_settings LIKE 'school_id'");
    if ($col_settings && $col_settings->num_rows > 0) {
        $has_settings_school = true;
    }
    $col_rules = $conn->query("SHOW COLUMNS FROM attendance_rules LIKE 'school_id'");
    if ($col_rules && $col_rules->num_rows > 0) {
        $has_rules_school = true;
    }

    $cfg_sql = "SELECT setting_value FROM attendance_settings WHERE setting_key='use_day_rules'";
    if ($has_settings_school) {
        $cfg_sql .= " AND school_id = $school_id";
    }
    $cfg_sql .= " LIMIT 1";
    $cfg = $conn->query($cfg_sql);
    if ($cfg && $cfg->num_rows > 0) {
        $use_day_rules = $cfg->fetch_assoc()['setting_value'];
    }
	if ($use_day_rules === '1') {
		$is_active = 0; // por defecto inactivo si se usan reglas y no hay fila
        $rq_sql = "SELECT is_active FROM attendance_rules WHERE day_of_week = '" . $conn->real_escape_string($dayOfWeek) . "'";
        if ($has_rules_school) {
            $rq_sql .= " AND school_id = $school_id";
        }
        $rq_sql .= " LIMIT 1";
        $rq = $conn->query($rq_sql);
		if ($rq && $rq->num_rows > 0) {
			$is_active = intval($rq->fetch_assoc()['is_active']);
		}
		if ($is_active === 0) {
			echo json_encode([
				'status' => 1,
				'skipped' => true,
				'message' => "No se marcaron ausentes para $fecha ($dayOfWeek): día inactivo según reglas.",
				'fecha' => $fecha,
				'resumen' => []
			]);
			exit;
		}
	}
	// Permitir marcar ambos tipos en una sola llamada si se indica 'Ambos'
	$tipos = [];
	if (strcasecmp($tipo, 'Ambos') === 0) {
		$tipos = ['Entrada', 'Salida'];
	} else {
		$tipos = [$tipo];
	}

	// Considerar solo estudiantes activos (o sin estado definido por compatibilidad)
	$students = [];
	$sq = $conn->query("SELECT id FROM student WHERE status = 'Activo' OR status IS NULL");
	while ($sq && ($row = $sq->fetch_assoc())) {
		$students[] = $row['id'];
	}

	$detalles = [];
	$totalMarcados = 0;
	foreach ($tipos as $t) {
		$marcados = 0;
		foreach ($students as $student_id) {
			$check = $conn->query("SELECT id FROM asistencia WHERE student_id='$student_id' AND fecha='$fecha' AND tipo='$t'");
			if (!$check || $check->num_rows == 0) {
				$conn->query("INSERT INTO asistencia (student_id, fecha, tipo, hora, estado) VALUES ('$student_id', '$fecha', '$t', '00:00:00', 'Ausente')");
				$marcados++;
			}
		}
		$detalles[] = [ 'tipo' => $t, 'marcados' => $marcados ];
		$totalMarcados += $marcados;
	}

	echo json_encode([
		'status' => 1,
		'message' => "Ausentes marcados para $fecha: $totalMarcados en total",
		'fecha' => $fecha,
		'resumen' => $detalles
	]);
	exit;
}

	// Discount Management Actions
	if ($action == "save_discount") {
		$save = $crud->save_discount();
		echo $save;
		exit;
	}
	
	if ($action == "delete_discount") {
		header('Content-Type: application/json');
		
		// Log de depuración
		error_log("[ajax.php] delete_discount - Datos recibidos: " . json_encode($_POST));
		
		// Validar parámetros
		if (!isset($_POST['student_id']) || !isset($_POST['course_id'])) {
			error_log("[ajax.php] delete_discount - Parámetros faltantes");
			echo json_encode(['status' => 0, 'message' => 'Parámetros faltantes: student_id o course_id']);
			exit;
		}
		
		$delete = $crud->delete_discount();
		if ($delete === 1) {
			error_log("[ajax.php] delete_discount - Éxito");
			echo json_encode(['status' => 1, 'message' => 'Descuento eliminado exitosamente.']);
		} else {
			error_log("[ajax.php] delete_discount - Error: " . $delete);
			echo json_encode([
				'status' => 0, 
				'message' => 'Error al eliminar el descuento. No se encontró o ya fue eliminado.',
				'detail' => $delete
			]);
		}
		exit;
	}
	
	if ($action == "get_student_fee") {
		$student_id = $_POST['student_id'];
		$fees = $crud->get_student_fees($student_id);
		echo json_encode($fees);
		exit;
	} elseif ($action == "mark_notification_read") {
		header('Content-Type: application/json');
    $user_id = isset($_SESSION['login_id']) ? intval($_SESSION['login_id']) : 0;
    $evaluation_id = isset($_POST['evaluation_id']) ? intval($_POST['evaluation_id']) : 0;

    if ($user_id > 0 && $evaluation_id > 0) {
        // Asegurarse de que la tabla `notification_read` existe
        $conn->query("CREATE TABLE IF NOT EXISTS `notification_read` (
            `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `evaluation_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `read_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `user_evaluation` (`user_id`, `evaluation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $stmt = $conn->prepare("INSERT INTO notification_read (evaluation_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP");
        $stmt->bind_param("ii", $evaluation_id, $user_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 1, 'message' => 'Notificación marcada como leída.']);
        } else {
            echo json_encode(['status' => 0, 'message' => 'Error al marcar la notificación como leída: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 0, 'message' => 'Datos de usuario o notificación no válidos.']);
    }
    exit;
	} elseif ($action == "mark_all_notifications_read") {
    header('Content-Type: application/json');
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $user_id = isset($_SESSION['login_id']) ? intval($_SESSION['login_id']) : 0;
    $user_type = isset($_SESSION['login_type']) ? $_SESSION['login_type'] : 0;
    $teacher_id = isset($_SESSION['login_teacher_id']) ? intval($_SESSION['login_teacher_id']) : 0;
    $school_id = intval($_SESSION['login_school_id'] ?? 0);
    $school_filter_teacher_courses = $school_id ? " AND tc.school_id = $school_id" : " AND 1 = 0";
    $school_filter_students = $school_id ? " AND s.school_id = $school_id" : " AND 1 = 0";

    // Debug info
    $debug_info = [
        'user_id' => $user_id,
        'user_type' => $user_type,
        'teacher_id' => $teacher_id
    ];

    if ($user_id > 0) {
        // Asegurar existencia de tablas de lectura antes de operar
        $conn->query("CREATE TABLE IF NOT EXISTS `notification_read` (
            `id` int(30) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `evaluation_id` int(30) NOT NULL,
            `user_id` int(30) NOT NULL,
            `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `evaluation_id` (`evaluation_id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Asegurar clave única (orden de la BD: evaluation_id, user_id)
        $check_nr = $conn->query("SHOW INDEX FROM `notification_read` WHERE Key_name = 'user_evaluation'");
        if (!$check_nr || $check_nr->num_rows == 0) {
            $conn->query("ALTER TABLE `notification_read` ADD UNIQUE KEY `user_evaluation` (`evaluation_id`, `user_id`)");
        }

        $conn->query("CREATE TABLE IF NOT EXISTS `low_grade_notification_read` (
            `id` int(30) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `notification_id` int(30) NOT NULL,
            `user_id` int(30) NOT NULL,
            `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `notification_id` (`notification_id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Asegurar clave única (orden de la BD: user_id, notification_id)
        $check_lgn = $conn->query("SHOW INDEX FROM `low_grade_notification_read` WHERE Key_name IN ('user_notification', 'user_notification_unique')");
        if (!$check_lgn || $check_lgn->num_rows == 0) {
            $conn->query("ALTER TABLE `low_grade_notification_read` ADD UNIQUE KEY `user_notification_unique` (`user_id`, `notification_id`)");
        }

        $conn->begin_transaction();
        $success = true;
        $total_marked = 0;
        $eval_marked = 0;
        $low_grade_marked = 0;

        // Primero contar cuántas hay sin leer
        if ($user_type != 2) {
            $count_eval = $conn->query("
                SELECT COUNT(*) as total FROM evaluations e 
                JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                LEFT JOIN notification_read nr ON nr.evaluation_id = e.id AND nr.user_id = $user_id
                WHERE nr.id IS NULL" . $school_filter_teacher_courses . "
            ");
            if ($count_eval) {
                $row = $count_eval->fetch_assoc();
                $debug_info['eval_unread_before'] = $row['total'];
            }
        }

        // Marcar notificaciones de EVALUACIONES como leídas (solo para no-profesores)
        if ($user_type != 2) {
            $insert_eval_result = $conn->query("
                INSERT IGNORE INTO notification_read (evaluation_id, user_id)
                SELECT e.id, $user_id 
                FROM evaluations e 
                JOIN teacher_courses tc ON e.teacher_course_id = tc.id
                LEFT JOIN notification_read nr ON nr.evaluation_id = e.id AND nr.user_id = $user_id
                WHERE nr.id IS NULL" . $school_filter_teacher_courses . "
            ");
            
            if ($insert_eval_result) {
                $eval_marked = $conn->affected_rows;
                $total_marked += $eval_marked;
            } else {
                $success = false;
                $debug_info['eval_error'] = $conn->error;
                error_log("Error marking evaluations: " . $conn->error);
            }
        }

        // Marcar notificaciones de NOTAS BAJAS como leídas
        if ($success) {
            $low_grade_where = "";
            if ($user_type == 2 && $teacher_id > 0) {
                $low_grade_where = " AND lgn.teacher_id = $teacher_id";
            } elseif ($user_type == 1) {
                $low_grade_where = " AND lgn.teacher_id IS NULL";
            } else {
                $low_grade_where = " AND 1 = 0";
            }

            $debug_info['low_grade_where'] = $low_grade_where;

            if ($low_grade_where !== " AND 1 = 0") {
                // Contar antes
                                $count_lg = $conn->query("
                                        SELECT COUNT(*) as total FROM low_grade_notifications lgn
                                        JOIN student s ON s.id = lgn.student_id
                                        LEFT JOIN low_grade_notification_read lgnr ON lgnr.notification_id = lgn.id AND lgnr.user_id = $user_id
                                        WHERE lgnr.id IS NULL" . $school_filter_students . "
                                            $low_grade_where
                                ");
                if ($count_lg) {
                    $row = $count_lg->fetch_assoc();
                    $debug_info['low_grade_unread_before'] = $row['total'];
                }

                                $query_str = "
                                        INSERT IGNORE INTO low_grade_notification_read (user_id, notification_id)
                                        SELECT $user_id, lgn.id 
                                        FROM low_grade_notifications lgn
                                        JOIN student s ON s.id = lgn.student_id
                                        LEFT JOIN low_grade_notification_read lgnr ON lgnr.notification_id = lgn.id AND lgnr.user_id = $user_id
                                        WHERE lgnr.id IS NULL" . $school_filter_students . "
                                            $low_grade_where
                                ";
                
                $insert_low_grade_result = $conn->query($query_str);
                
                if ($insert_low_grade_result) {
                    $low_grade_marked = $conn->affected_rows;
                    $total_marked += $low_grade_marked;
                } else {
                    $success = false;
                    $debug_info['low_grade_error'] = $conn->error;
                    error_log("Error marking low grade notifications: " . $conn->error);
                }
            }
        }

        if ($success) {
            $conn->commit();
            $debug_info['eval_marked'] = $eval_marked;
            $debug_info['low_grade_marked'] = $low_grade_marked;
            $debug_info['total_marked'] = $total_marked;
            echo json_encode([
                'status' => 1, 
                'message' => 'Todas las notificaciones han sido marcadas como leídas.', 
                'marked' => $total_marked,
                'debug' => $debug_info
            ]);
        } else {
            $conn->rollback();
            echo json_encode(['status' => 0, 'message' => 'Error al marcar las notificaciones como leídas.', 'debug' => $debug_info]);
        }

    } else {
        echo json_encode(['status' => 0, 'message' => 'Usuario no autenticado.', 'debug' => $debug_info]);
    }
    exit;
	} elseif ($action == "mark_all_notifications_unread") {
		header('Content-Type: application/json');
        // Verificar si el usuario está logueado
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_SESSION['login_id'])) {
            error_log("mark_all_notifications_unread: Usuario no logueado");
            echo 0;
            exit;
        }
        $user_id = $_SESSION['login_id']; // ID del usuario actual
        error_log("mark_all_notifications_unread: Iniciando para usuario ID: $user_id");
        $success = true;
        
        // Verificar que existan las tablas
        $conn->query("CREATE TABLE IF NOT EXISTS `notification_read` (
            `id` int(30) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `evaluation_id` int(30) NOT NULL,
            `user_id` int(30) NOT NULL,
            `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `evaluation_id` (`evaluation_id`),
            KEY `user_id` (`user_id`),
            UNIQUE KEY `user_evaluation` (`evaluation_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        $conn->query("CREATE TABLE IF NOT EXISTS `low_grade_notification_read` (
            `id` int(30) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `notification_id` int(30) NOT NULL,
            `user_id` int(30) NOT NULL,
            `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `notification_id` (`notification_id`),
            KEY `user_id` (`user_id`),
            UNIQUE KEY `user_notification` (`notification_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Eliminar todas las marcas de lectura para evaluaciones
        try {
            $stmt_eval = $conn->prepare("DELETE FROM notification_read WHERE user_id = ?");
            $stmt_eval->bind_param("i", $user_id);
            $stmt_eval->execute();
            $stmt_eval->close();
        } catch (Exception $e) {
            $success = false;
            error_log("Error al desmarcar evaluaciones como leídas: " . $e->getMessage());
        }
        
        // Eliminar todas las marcas de lectura para notificaciones de notas bajas
        try {
            $stmt_low = $conn->prepare("DELETE FROM low_grade_notification_read WHERE user_id = ?");
            $stmt_low->bind_param("i", $user_id);
            $stmt_low->execute();
            $stmt_low->close();
        } catch (Exception $e) {
            $success = false;
            error_log("Error al desmarcar notificaciones de notas bajas como leídas: " . $e->getMessage());
        }
        
        // NO actualizar is_read en la tabla principal (eliminado)
        error_log("Marcando TODAS las notificaciones como NO leídas para usuario ID: $user_id, Resultado final: " . ($success ? "éxito" : "fallido"));
        
        // Devolver resultado en formato que se pueda procesar tanto como JSON o texto plano
        echo $success ? "1" : "0";
    } elseif ($action == 'mark_low_grade_notification_read') {
    header('Content-Type: application/json');
    $user_id = isset($_SESSION['login_id']) ? intval($_SESSION['login_id']) : 0;
    $notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;

    if ($user_id > 0 && $notification_id > 0) {
        $stmt = $conn->prepare("INSERT INTO low_grade_notification_read (notification_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id=user_id");
        $stmt->bind_param("ii", $notification_id, $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 1, 'message' => 'Notificación marcada como leída.']);
        } else {
            echo json_encode(['status' => 0, 'message' => 'Error al marcar la notificación: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 0, 'message' => 'Datos inválidos.']);
    }
    exit;
} elseif ($action == 'get_students_for_bulk_update') {
    header('Content-Type: application/json');

    $allowed_updates = ['nivel_grado','status_activo','status_egresado','status_retirado','reactivar_estudiante'];
    $update_type = isset($_POST['update_type']) ? trim($_POST['update_type']) : '';
    if (!in_array($update_type, $allowed_updates, true)) {
        echo json_encode(['status' => 0, 'msg' => 'Tipo de actualización inválido.']);
        exit;
    }

    $role_query = $conn->prepare('SELECT type, is_director FROM users WHERE id = ? LIMIT 1');
    $bulk_user_id = (int)($_SESSION['login_id'] ?? 0);
    if (!$role_query) {
        echo json_encode(['status' => 0, 'msg' => 'No se pudo validar el usuario.']);
        exit;
    }
    $role_query->bind_param('i', $bulk_user_id);
    $role_query->execute();
    $bulk_role = $role_query->get_result()->fetch_assoc();
    $role_query->close();
    if (!$bulk_role || (int)$bulk_role['type'] !== 1) {
        echo json_encode(['status' => 0, 'msg' => 'No tiene permisos para realizar esta acción.']);
        exit;
    }

    $school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
    if ($school_id <= 0) {
        echo json_encode(['status' => 0, 'msg' => 'No se encontró el colegio en sesión.']);
        exit;
    }

    $nivel = isset($_POST['current_nivel']) ? trim($_POST['current_nivel']) : '';
    $grado = isset($_POST['current_grado']) ? trim($_POST['current_grado']) : '';
    $seccion = isset($_POST['current_seccion']) ? trim($_POST['current_seccion']) : '';
    $preview_only = isset($_POST['preview_only']) && $_POST['preview_only'] === 'on';

    $clauses = ['s.school_id = ?'];
    $params = [$school_id];
    $types = 'i';

    if ($nivel !== '') {
        $clauses[] = 's.nivel = ?';
        $params[] = $nivel;
        $types .= 's';
    }
    if ($grado !== '') {
        $clauses[] = 's.grado = ?';
        $params[] = $grado;
        $types .= 's';
    }
    if ($seccion !== '') {
        $clauses[] = 's.seccion = ?';
        $params[] = $seccion;
        $types .= 's';
    }

    if ($update_type === 'reactivar_estudiante') {
        $clauses[] = "(s.status = 'Retirado' OR s.status = 'Egresado')";
    } else {
        $clauses[] = "(s.status IS NULL OR s.status = '' OR s.status = 'Activo')";
    }

    $sql = "SELECT s.id, s.id_no, s.name AS name, s.nivel, s.grado, s.seccion, s.status
            FROM student s
            WHERE " . implode(' AND ', $clauses) . "
            ORDER BY s.name";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['status' => 0, 'msg' => 'Error al preparar la consulta.']);
        exit;
    }

    if (!empty($params)) {
        $bind_params = [];
        $bind_params[] = $types;
        foreach ($params as $k => $v) {
            $bind_params[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $new_nivel = isset($_POST['new_nivel']) ? trim($_POST['new_nivel']) : '';
    $new_grado = isset($_POST['new_grado']) ? trim($_POST['new_grado']) : '';
    $new_seccion = isset($_POST['new_seccion']) ? trim($_POST['new_seccion']) : '';

    $students = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['update_type'] = $update_type;
            if ($update_type === 'nivel_grado') {
                $row['new_nivel'] = $new_nivel;
                $row['new_grado'] = $new_grado;
                $row['new_seccion'] = $new_seccion;
            } elseif ($update_type === 'status_egresado') {
                $row['new_status'] = 'Egresado';
            } elseif ($update_type === 'status_retirado') {
                $row['new_status'] = 'Retirado';
            } elseif ($update_type === 'reactivar_estudiante') {
                $row['new_status'] = 'Activo';
                $row['new_nivel'] = $new_nivel;
                $row['new_grado'] = $new_grado;
                $row['new_seccion'] = $new_seccion;
            }
            $students[] = $row;
        }
    }
    $stmt->close();

    echo json_encode([
        'status' => 1,
        'data' => $students,
        'preview_only' => $preview_only
    ]);
    exit;
} elseif ($action == 'bulk_update_students') {
    header('Content-Type: application/json');

    $allowed_updates = ['nivel_grado','status_activo','status_egresado','status_retirado','reactivar_estudiante'];
    $update_type = isset($_POST['update_type']) ? trim($_POST['update_type']) : '';
    if (!in_array($update_type, $allowed_updates, true)) {
        echo json_encode(['status' => 0, 'msg' => 'Tipo de actualización inválido.']);
        exit;
    }

    $bulk_user_id = (int)($_SESSION['login_id'] ?? 0);
    $role_query = $conn->prepare('SELECT type, is_director FROM users WHERE id = ? LIMIT 1');
    if (!$role_query) {
        echo json_encode(['status' => 0, 'msg' => 'No se pudo validar el usuario.']);
        exit;
    }
    $role_query->bind_param('i', $bulk_user_id);
    $role_query->execute();
    $bulk_role = $role_query->get_result()->fetch_assoc();
    $role_query->close();
    if (!$bulk_role || (int)$bulk_role['type'] !== 1) {
        echo json_encode(['status' => 0, 'msg' => 'No tiene permisos para realizar esta acción.']);
        exit;
    }

    $school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
    if ($school_id <= 0) {
        echo json_encode(['status' => 0, 'msg' => 'No se encontró el colegio en sesión.']);
        exit;
    }

    $student_ids = isset($_POST['student_ids']) ? json_decode($_POST['student_ids'], true) : [];
    if (!is_array($student_ids)) $student_ids = [];
    $student_ids = array_values(array_filter(array_map('intval', $student_ids), function($v){ return $v > 0; }));

    if (empty($student_ids)) {
        echo json_encode(['status' => 0, 'msg' => 'No se han seleccionado estudiantes para actualizar.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        if ($update_type === 'nivel_grado') {
            $new_nivel = isset($_POST['new_nivel']) ? trim($_POST['new_nivel']) : '';
            $new_grado = isset($_POST['new_grado']) ? trim($_POST['new_grado']) : '';
            $new_seccion = isset($_POST['new_seccion']) ? trim($_POST['new_seccion']) : '';
            if (!in_array($new_nivel, ['Inicial', 'Primaria', 'Secundaria'], true) || !in_array($new_seccion, ['U', 'A', 'B', 'C', 'D', 'E', 'F'], true) || !$new_grado) {
                throw new Exception('Debe proporcionar nivel, grado y sección.');
            }
            $stmt = $conn->prepare("UPDATE student SET nivel = ?, grado = ?, seccion = ? WHERE id = ? AND school_id = ?");
            if (!$stmt) throw new Exception('No se pudo preparar la consulta.');
            foreach ($student_ids as $sid) {
                $stmt->bind_param("sssii", $new_nivel, $new_grado, $new_seccion, $sid, $school_id);
                if (!$stmt->execute()) throw new Exception("Error al actualizar al estudiante ID: $sid");
            }
            $stmt->close();

        } elseif (in_array($update_type, ['status_activo', 'status_egresado', 'status_retirado'], true)) {
            $new_status = str_replace('status_', '', $update_type);
            $new_status = ucfirst($new_status);
            $stmt = $conn->prepare("UPDATE student SET status = ? WHERE id = ? AND school_id = ?");
            if (!$stmt) throw new Exception('No se pudo preparar la consulta.');
            foreach ($student_ids as $sid) {
                $stmt->bind_param("sii", $new_status, $sid, $school_id);
                if (!$stmt->execute()) throw new Exception("Error al actualizar el estado del estudiante ID: $sid");
            }
            $stmt->close();

        } elseif ($update_type === 'reactivar_estudiante') {
            $new_nivel = isset($_POST['new_nivel']) ? trim($_POST['new_nivel']) : '';
            $new_grado = isset($_POST['new_grado']) ? trim($_POST['new_grado']) : '';
            $new_seccion = isset($_POST['new_seccion']) ? trim($_POST['new_seccion']) : '';
            if (!in_array($new_nivel, ['Inicial', 'Primaria', 'Secundaria'], true) || !in_array($new_seccion, ['U', 'A', 'B', 'C', 'D', 'E', 'F'], true) || !$new_grado) {
                throw new Exception('Para reactivar estudiantes debe proporcionar nivel, grado y sección.');
            }
            $stmt = $conn->prepare("UPDATE student SET status = 'Activo', nivel = ?, grado = ?, seccion = ? WHERE id = ? AND school_id = ?");
            if (!$stmt) throw new Exception('No se pudo preparar la consulta.');
            foreach ($student_ids as $sid) {
                $stmt->bind_param("sssii", $new_nivel, $new_grado, $new_seccion, $sid, $school_id);
                if (!$stmt->execute()) throw new Exception("Error al reactivar al estudiante ID: $sid");
            }
            $stmt->close();
        }

        $audit_details = ['bulk' => true, 'update_type' => $update_type];
        if (isset($new_status)) $audit_details['new_status'] = $new_status;
        if (isset($new_nivel)) $audit_details['new_nivel'] = $new_nivel;
        if (isset($new_grado)) $audit_details['new_grado'] = $new_grado;
        if (isset($new_seccion)) $audit_details['new_seccion'] = $new_seccion;
        $audit_details_json = json_encode($audit_details, JSON_UNESCAPED_UNICODE);
        $audit_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $audit_stmt = $conn->prepare("INSERT INTO student_audit_log (student_id, school_id, user_id, action, details, ip_address) VALUES (?, ?, ?, 'update', ?, ?)");
        if ($audit_stmt) {
            foreach ($student_ids as $sid) {
                $audit_stmt->bind_param('iiiss', $sid, $school_id, $bulk_user_id, $audit_details_json, $audit_ip);
                $audit_stmt->execute();
            }
            $audit_stmt->close();
        }
        $history_stmt = $conn->prepare("INSERT INTO student_academic_history (student_id, school_id, academic_year_id, nivel, grado, seccion, status, change_type, user_id, notes) SELECT id, school_id, academic_year_id, nivel, grado, seccion, status, 'bulk', ?, ? FROM student WHERE id = ? AND school_id = ?");
        if ($history_stmt) {
            $history_note = 'Actualización masiva: ' . $update_type;
            foreach ($student_ids as $sid) {
                $history_stmt->bind_param('isii', $bulk_user_id, $history_note, $sid, $school_id);
                $history_stmt->execute();
            }
            $history_stmt->close();
        }

        $conn->commit();
        echo json_encode(['status' => 1, 'msg' => 'Estudiantes actualizados exitosamente.']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 0, 'msg' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}
} catch (Throwable $e) {
	error_log("Error en el servidor: " . $e->getMessage());
	header('Content-Type: application/json');
	echo json_encode(['status' => 0, 'message' => 'Error interno del servidor: ' . $e->getMessage()]);
	exit;
}

// === ENDPOINTS DE AÑO ACADÉMICO ===
// Guardar o actualizar año académico
$academic_year_lifecycle_actions = [
    'archive_academic_year', 'close_academic_year', 'reopen_academic_year',
    'get_year_summary', 'get_close_checklist', 'get_academic_periods',
    'save_academic_periods', 'copy_academic_year', 'compare_academic_years',
    'get_academic_year_audit'
];
if (in_array($action, $academic_year_lifecycle_actions, true)) {
    include('academic_year_api.php');
    exit;
}
if ($action == 'save_academic_year') {
    include('academic_year_api.php');
    exit;
}

// Obtener un año académico por ID
elseif ($action == 'get_academic_year') {
    include('academic_year_api.php');
    exit;
}

// Activar un año académico
elseif ($action == 'activate_academic_year') {
    include('academic_year_api.php');
    exit;
}

// Eliminar un año académico
elseif ($action == 'delete_academic_year') {
    include('academic_year_api.php');
    exit;
}

// Obtener bloqueos de bimestres
elseif ($action == 'get_bimester_locks') {
	include('academic_year_api.php');
	exit;
}

// Guardar bloqueos de bimestres
elseif ($action == 'save_bimester_locks') {
	include('academic_year_api.php');
	exit;
}

// === ENDPOINTS DE EVALUACIONES ===
// Obtener evaluaciones con filtro por año académico
elseif ($action == 'get_teacher_evaluations') {
    include('evaluations_api.php');
    exit;
}

// === NUEVAS FUNCIONES PARA TEACHER_COURSES CON AÑOS ACADÉMICOS ===
elseif ($action == 'get_teachers_by_year') {
    header('Content-Type: application/json');
    $academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    $teachers = [];
    
    if ($academic_year_id > 0 && $school_id > 0) {
        $query = "SELECT DISTINCT t.id, t.name, 
            (SELECT COUNT(*) FROM teacher_courses WHERE teacher_id = t.id AND academic_year_id = $academic_year_id) as course_count
            FROM teacher t 
            INNER JOIN teacher_courses tc ON t.id = tc.teacher_id
            WHERE t.school_id = $school_id AND t.status = 'Activo' AND tc.academic_year_id = $academic_year_id
            ORDER BY t.name ASC";
            
        $result = $conn->query($query);
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $teachers[] = [
                    'id' => $row['id'],
                    'name' => ucwords($row['name']),
                    'course_count' => $row['course_count']
                ];
            }
        }
    }
    
    echo json_encode(['status' => 1, 'teachers' => $teachers]);
    exit;
}

elseif ($action == 'clean_teacher_assignments') {
    header('Content-Type: application/json');
    $teacher_id = isset($_POST['teacher_id']) ? intval($_POST['teacher_id']) : 0;
    $academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if ($teacher_id > 0 && $academic_year_id > 0 && $school_id > 0) {
        // Verificar que el docente pertenece al colegio del administrador
        $check = $conn->query("SELECT id FROM teacher WHERE id = $teacher_id AND school_id = $school_id");
        if ($check && $check->num_rows > 0) {
            $delete = $conn->query("DELETE FROM teacher_courses WHERE teacher_id = $teacher_id AND academic_year_id = $academic_year_id");
            if ($delete) {
                echo json_encode(['status' => 1]);
            } else {
                echo json_encode(['status' => 0, 'message' => 'Error al eliminar las asignaciones del docente.']);
            }
        } else {
            echo json_encode(['status' => 0, 'message' => 'No tiene permiso para eliminar las asignaciones de este docente.']);
        }
    } else {
        echo json_encode(['status' => 0, 'message' => 'Faltan parámetros requeridos.']);
    }
    exit;
}

elseif ($action == 'clean_all_teacher_courses') {
    header('Content-Type: application/json');
    $academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;
    $school_id = $_SESSION['login_school_id'] ?? 0;
	$clean_year = ($academic_year_id > 0 && $school_id > 0) ? $conn->query("SELECT id FROM academic_year WHERE id=$academic_year_id AND school_id=$school_id AND is_active=1 LIMIT 1") : false;
    
    if ($academic_year_id > 0 && $school_id > 0 && $clean_year && $clean_year->num_rows > 0) {
        // Solo eliminar las asignaciones de docentes que pertenecen al colegio del administrador
        $delete = $conn->query("DELETE tc FROM teacher_courses tc 
                               INNER JOIN teacher t ON tc.teacher_id = t.id 
                               WHERE t.school_id = $school_id AND tc.academic_year_id = $academic_year_id");
        if ($delete) {
            echo json_encode(['status' => 1]);
        } else {
            echo json_encode(['status' => 0, 'message' => 'Error al eliminar todas las asignaciones.']);
        }
    } else {
        echo json_encode(['status' => 0, 'message' => 'Faltan parámetros requeridos.']);
    }
    exit;
}

elseif ($action == 'copy_teacher_courses_from_previous_year') {
    header('Content-Type: application/json');
    $current_year_id = isset($_POST['current_year_id']) ? intval($_POST['current_year_id']) : 0;
	$source_year_id = isset($_POST['source_year_id']) ? intval($_POST['source_year_id']) : 0;
    $school_id = $_SESSION['login_school_id'] ?? 0;
	$target_year_query = ($current_year_id > 0 && $school_id > 0)
		? $conn->query("SELECT id FROM academic_year WHERE id = $current_year_id AND school_id = $school_id AND is_active = 1 LIMIT 1")
		: false;
    
    if ($current_year_id > 0 && $source_year_id > 0 && $source_year_id !== $current_year_id && $school_id > 0 && $target_year_query && $target_year_query->num_rows > 0) {
        // Validar el año de origen seleccionado
        $previous_year_query = $conn->query("SELECT id FROM academic_year
                                           WHERE school_id = $school_id
                                           AND id = $source_year_id
                                           LIMIT 1");
        
        if ($previous_year_query && $previous_year_query->num_rows > 0) {
            $previous_year = $previous_year_query->fetch_assoc();
            $previous_year_id = $previous_year['id'];
            
            // Obtener las asignaciones del año seleccionado
            $course_year_column = $conn->query("SHOW COLUMNS FROM academic_courses LIKE 'academic_year_id'");
            $has_versioned_courses = $course_year_column && $course_year_column->num_rows > 0;
            $previous_assignments = $conn->query("SELECT tc.teacher_id, tc.course_id, tc.grado, tc.seccion, tc.level, ac.name AS course_name, ac.level AS course_level
                                                FROM teacher_courses tc
                                                INNER JOIN teacher t ON tc.teacher_id = t.id
                                                INNER JOIN academic_courses ac ON ac.id = tc.course_id
                                                WHERE t.school_id = $school_id AND tc.academic_year_id = $previous_year_id");
            
            $copied = 0;
            if ($previous_assignments && $previous_assignments->num_rows > 0) {
                while ($assignment = $previous_assignments->fetch_assoc()) {
                    $target_course_id = (int)$assignment['course_id'];
                    if ($has_versioned_courses) {
                        $course_name_safe = $conn->real_escape_string($assignment['course_name']);
                        $course_level_safe = $conn->real_escape_string($assignment['course_level']);
                        $target_course_query = $conn->query("SELECT id FROM academic_courses WHERE school_id=$school_id AND academic_year_id=$current_year_id AND name='$course_name_safe' AND level='$course_level_safe' LIMIT 1");
                        if (!$target_course_query || $target_course_query->num_rows === 0) continue;
                        $target_course_id = (int)$target_course_query->fetch_assoc()['id'];
                    }
                    // Verificar si ya existe esta asignación en el año actual
                    $check = $conn->query("SELECT id FROM teacher_courses 
                                         WHERE teacher_id = {$assignment['teacher_id']} 
                                         AND course_id = $target_course_id
                                         AND grado = '{$assignment['grado']}' 
                                         AND seccion = '{$assignment['seccion']}' 
                                         AND level = '{$assignment['level']}'
                                         AND academic_year_id = $current_year_id");
                    
                    if ($check->num_rows == 0) {
                        // No existe, crear la nueva asignación
                        $insert = $conn->query("INSERT INTO teacher_courses (teacher_id, course_id, grado, seccion, school_id, level, academic_year_id) 
                                              VALUES ({$assignment['teacher_id']}, $target_course_id, '{$assignment['grado']}', '{$assignment['seccion']}', $school_id, '{$assignment['level']}', $current_year_id)");
                        if ($insert) {
                            $copied++;
                        }
                    }
                }
            }
            
            echo json_encode(['status' => 1, 'copied' => $copied]);
        } else {
            echo json_encode(['status' => 0, 'message' => 'El año de origen no existe o no pertenece a la institución.']);
        }
    } else {
        echo json_encode(['status' => 0, 'message' => 'Selecciona años de origen y destino diferentes y válidos.']);
    }
    exit;
}

// Obtener estudiantes activos para asignación masiva
if ($action == 'get_active_students') {
    header('Content-Type: application/json');
    
    $school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
    if (!$school_id) {
        echo json_encode(array('status' => 0, 'message' => 'No se ha establecido la escuela.'));
        exit;
    }
    
    $students = array();
    $qry = $conn->query("SELECT id, name, id_no, nivel, grado FROM student WHERE (status = 'Activo' OR status IS NULL) AND school_id = $school_id ORDER BY name ASC");
    
    if ($qry) {
        while ($row = $qry->fetch_assoc()) {
            $students[] = $row;
        }
        echo json_encode(array('status' => 1, 'students' => $students));
    } else {
        echo json_encode(array('status' => 0, 'message' => 'Error al obtener estudiantes.'));
    }
    exit;
}

// Obtener todos los conceptos disponibles
if ($action == 'get_all_concepts') {
    header('Content-Type: application/json');
    
    $school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
    if (!$school_id) {
        echo json_encode(array('status' => 0, 'message' => 'No se ha establecido la escuela.'));
        exit;
    }
    
    $concepts = array();
    $qry = $conn->query("
        SELECT c.id, c.course, c.level, c.grades, c.total_amount, ay.year 
        FROM courses c 
        LEFT JOIN academic_year ay ON c.academic_year_id = ay.id 
        WHERE ay.school_id = $school_id
        AND c.concept_status = 'Activo'
        AND ay.status IN ('Borrador','Activo')
        ORDER BY ay.year DESC, c.course ASC
    ");
    
    if ($qry) {
        while ($row = $qry->fetch_assoc()) {
            $concepts[] = $row;
        }
        echo json_encode(array('status' => 1, 'concepts' => $concepts));
    } else {
        echo json_encode(array('status' => 0, 'message' => 'Error al obtener conceptos.'));
    }
    exit;
}

// Asignación masiva de deudas
if ($action == 'bulk_assign_fees') {
    header('Content-Type: application/json');
    
    $school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
    if (!$school_id) {
        echo json_encode(array('status' => 0, 'message' => 'No se ha establecido la escuela.'));
        exit;
    }
    
    $students = isset($_POST['students']) ? $_POST['students'] : array();
    $concepts = isset($_POST['concepts']) ? $_POST['concepts'] : array();
    
    if (empty($students) || empty($concepts)) {
        echo json_encode(array('status' => 0, 'message' => 'Debe seleccionar al menos un estudiante y un concepto.'));
        exit;
    }
    
    $success_count = 0;
    $error_count = 0;
    $duplicates = 0;
    
    $conn->autocommit(false); // Iniciar transacción
    
    try {
        foreach ($students as $student_id) {
            // Obtener información del estudiante
            $student_qry = $conn->query("SELECT nivel, grado FROM student WHERE id = $student_id AND school_id = $school_id");
            if (!$student_qry || $student_qry->num_rows == 0) {
                $error_count++;
                error_log("Estudiante no encontrado: $student_id");
                continue;
            }
            $student_info = $student_qry->fetch_assoc();
            
            foreach ($concepts as $concept_id) {
                // Verificar si ya existe esta asignación
                $check = $conn->query("SELECT id FROM student_ef_list WHERE student_id = $student_id AND course_id = $concept_id");
                
                if ($check && $check->num_rows > 0) {
                    $duplicates++;
                    continue;
                }
                
                // Obtener información del concepto/curso
                $concept_qry = $conn->query("SELECT c.level, c.grades, c.total_amount FROM courses c INNER JOIN academic_year ay ON ay.id=c.academic_year_id WHERE c.id = $concept_id AND ay.school_id=$school_id AND c.concept_status='Activo' AND ay.status IN ('Borrador','Activo')");
                if (!$concept_qry || $concept_qry->num_rows == 0) {
                    $error_count++;
                    error_log("Concepto no encontrado: $concept_id");
                    continue;
                }
                $concept = $concept_qry->fetch_assoc();
                
                // Verificar compatibilidad de nivel
                if ($student_info['nivel'] !== $concept['level']) {
                    $error_count++;
                    error_log("Incompatibilidad de nivel: Estudiante {$student_info['nivel']} vs Concepto {$concept['level']}");
                    continue;
                }
                
                // Verificar compatibilidad de grado si el concepto tiene grados específicos
                if (!empty($concept['grades'])) {
                    $concept_grades = array_map('trim', explode(',', $concept['grades']));
                    if (!in_array($student_info['grado'], $concept_grades)) {
                        $error_count++;
                        error_log("Incompatibilidad de grado: Estudiante {$student_info['grado']} no está en " . implode(',', $concept_grades));
                        continue;
                    }
                }
                
                $total_fee = $concept['total_amount'];
                
                // Insertar la nueva asignación
                $insert = $conn->query("
                    INSERT INTO student_ef_list (student_id, course_id, total_fee) 
                    VALUES ($student_id, $concept_id, $total_fee)
                ");
                
                if ($insert) {
                    $success_count++;
                } else {
                    $error_count++;
                    error_log("Error al insertar asignación: " . $conn->error);
                }
            }
        }
        
        if ($error_count == 0) {
            $conn->commit();
            
            $message = "Asignación completada exitosamente: $success_count nuevas deudas creadas";
            if ($duplicates > 0) {
                $message .= " ($duplicates asignaciones ya existían)";
            }
            
            echo json_encode(array('status' => 1, 'message' => $message));
        } else {
            $conn->rollback();
            echo json_encode(array('status' => 0, 'message' => "Error en la asignación: $error_count errores encontrados"));
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(array('status' => 0, 'message' => 'Error en la base de datos: ' . $e->getMessage()));
    }
    
    $conn->autocommit(true);
    exit;
}

if ($action == 'get_course_info') {
    header('Content-Type: application/json');
    
    $course_id = isset($_POST['course_id']) ? $_POST['course_id'] : 0;
    $school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
    
    if (!$course_id) {
        echo json_encode(array('status' => 0, 'message' => 'ID de curso no proporcionado'));
        exit;
    }
    
    $query = "
        SELECT c.*, ay.year,
               CONCAT(c.course, ' - ', c.level, ' (', COALESCE(c.grades, 'Todos los grados'), ') - $', FORMAT(c.total_amount, 2), ' - ', COALESCE(ay.year, 'Sin año')) as display_text
        FROM courses c 
        LEFT JOIN academic_year ay ON c.academic_year_id = ay.id 
        WHERE c.id = $course_id";
    
    // Filtrar por school_id si está disponible
    if ($school_id > 0) {
        $query .= " AND ay.school_id = '$school_id'";
    }
    
    $qry = $conn->query($query);
    
    if ($qry && $qry->num_rows > 0) {
        $course = $qry->fetch_assoc();
        echo json_encode(array('status' => 1, 'course' => $course));
    } else {
        echo json_encode(array('status' => 0, 'message' => 'Curso no encontrado'));
    }
    exit;
}

// Obtener conceptos disponibles en asignación masiva considerando estudiantes seleccionados
if ($action == 'get_available_concepts_for_bulk') {
    header('Content-Type: application/json');
    
    $school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
    if (!$school_id) {
        echo json_encode(array('status' => 0, 'message' => 'No se ha establecido la escuela.'));
        exit;
    }
    
    $student_ids = isset($_POST['student_ids']) ? $_POST['student_ids'] : array();
    
    if (empty($student_ids)) {
        // Si no hay estudiantes seleccionados, mostrar todos los conceptos pero sin filtro específico
        $concepts = array();
        $qry = $conn->query("
            SELECT c.id, c.course, c.level, c.grades, c.total_amount, ay.year 
            FROM courses c 
            LEFT JOIN academic_year ay ON c.academic_year_id = ay.id 
            WHERE ay.school_id = $school_id
            AND c.concept_status = 'Activo'
            AND ay.status IN ('Borrador','Activo')
            ORDER BY ay.year DESC, c.level ASC, c.course ASC
        ");
        
        if ($qry) {
            while ($row = $qry->fetch_assoc()) {
                $concepts[] = $row;
            }
            echo json_encode(array('status' => 1, 'concepts' => $concepts));
        } else {
            echo json_encode(array('status' => 0, 'message' => 'Error al obtener conceptos.'));
        }
        exit;
    }
    
    // Crear lista de IDs para la consulta
    $student_ids_str = implode(',', array_map('intval', $student_ids));
    
    $concepts = array();
    
    // Obtener niveles y grados únicos de los estudiantes seleccionados
    $students_info_qry = $conn->query("
        SELECT DISTINCT nivel, grado 
        FROM student 
        WHERE id IN ($student_ids_str) AND school_id = $school_id
    ");
    
    $student_levels = array();
    $student_grades = array();
    if ($students_info_qry) {
        while ($student_info = $students_info_qry->fetch_assoc()) {
            if (!in_array($student_info['nivel'], $student_levels)) {
                $student_levels[] = $student_info['nivel'];
            }
            if (!in_array($student_info['grado'], $student_grades)) {
                $student_grades[] = $student_info['grado'];
            }
        }
    }
    
    // Si no hay información de estudiantes, salir
    if (empty($student_levels)) {
        echo json_encode(array('status' => 0, 'message' => 'No se encontró información de nivel de los estudiantes seleccionados.'));
        exit;
    }
    
    // Crear condición para filtrar por nivel
    $level_condition = "c.level IN ('" . implode("','", $student_levels) . "')";
    
    $qry = $conn->query("
        SELECT c.id, c.course, c.level, c.grades, c.total_amount, ay.year,
               COUNT(DISTINCT s.id) as student_count,
               COUNT(DISTINCT sel.student_id) as already_assigned_count
        FROM courses c 
        LEFT JOIN academic_year ay ON c.academic_year_id = ay.id 
        LEFT JOIN student s ON s.id IN ($student_ids_str)
        LEFT JOIN student_ef_list sel ON sel.course_id = c.id AND sel.student_id IN ($student_ids_str)
        WHERE ay.school_id = $school_id
        AND c.concept_status = 'Activo'
        AND ay.status IN ('Borrador','Activo')
        AND $level_condition
        GROUP BY c.id, c.course, c.level, c.grades, c.total_amount, ay.year
        HAVING already_assigned_count < student_count OR already_assigned_count IS NULL
        ORDER BY ay.year DESC, c.course ASC
    ");
    
    if ($qry) {
        while ($row = $qry->fetch_assoc()) {
            // Verificar compatibilidad de grados si el curso tiene grados específicos
            $course_is_compatible = true;
            
            if (!empty($row['grades'])) {
                // El curso tiene grados específicos, verificar compatibilidad
                $course_grades = array_map('trim', explode(',', $row['grades']));
                $compatible_grades = array_intersect($student_grades, $course_grades);
                
                if (empty($compatible_grades)) {
                    // No hay compatibilidad de grados, saltar este curso
                    $course_is_compatible = false;
                }
            }
            
            if ($course_is_compatible) {
                $available_for = $row['student_count'] - ($row['already_assigned_count'] ?: 0);
                $row['available_for_students'] = $available_for;
                $row['total_students'] = $row['student_count'];
                $concepts[] = $row;
            }
        }
        echo json_encode(array('status' => 1, 'concepts' => $concepts));
    } else {
        echo json_encode(array('status' => 0, 'message' => 'Error al obtener conceptos disponibles.'));
    }
    exit;
}

// Proteger todos los catálogos usados por fichas institucionales.
$ficha_catalog_actions = ['get_students_for_ficha','get_teachers_for_ficha','get_niveles_for_ficha','get_grados_by_nivel_for_ficha','get_secciones_by_nivel_grado_for_ficha','get_academic_years'];
if (in_array($action,$ficha_catalog_actions,true)) {
    $school_id = intval($_SESSION['login_school_id'] ?? 0);$user_id=intval($_SESSION['login_id']??0);$allowed=false;
    if($school_id>0&&$user_id>0){$permission=$conn->prepare('SELECT type,is_director FROM users WHERE id=? AND school_id=? LIMIT 1');if($permission){$permission->bind_param('ii',$user_id,$school_id);$permission->execute();$role=$permission->get_result()->fetch_assoc();$permission->close();$allowed=$role&&(int)$role['type']===1;}}
    if(!$allowed){header('Content-Type: application/json; charset=utf-8');http_response_code(403);echo json_encode(['status'=>0,'message'=>'No tiene permiso para consultar fichas institucionales.']);exit;}
}

// Obtener estudiantes para fichas
if ($action == "get_students_for_ficha") {
    $school_id = intval($_SESSION['login_school_id'] ?? 0);
    $students = array();
    if ($school_id <= 0) { echo json_encode(['status'=>0,'message'=>'No hay una institución activa.']); exit; }
    // El padrón institucional no depende del año activo: incluye activos,
    // retirados y egresados. El año solo cambia el contexto mostrado en la ficha.
    $query = $conn->query("SELECT id, id_no, name, nivel, grado, status FROM student WHERE school_id = $school_id ORDER BY name");
    
    if ($query) {
        while ($row = $query->fetch_assoc()) {
            $students[] = $row;
        }
        echo json_encode(array('status' => 1, 'students' => $students));
    } else {
        echo json_encode(array('status' => 0, 'message' => 'Error al obtener estudiantes.'));
    }
    exit;
}

// Obtener docentes para fichas
if ($action == "get_teachers_for_ficha") {
    $school_id = intval($_SESSION['login_school_id'] ?? 0);
    $teachers = array();
    if ($school_id <= 0) { echo json_encode(['status'=>0,'message'=>'No hay una institución activa.']); exit; }
    $query = $conn->query("SELECT id, id_no, name, status FROM teacher WHERE school_id = $school_id ORDER BY name");
    
    if ($query) {
        while ($row = $query->fetch_assoc()) {
            $teachers[] = $row;
        }
        echo json_encode(array('status' => 1, 'teachers' => $teachers));
    } else {
        echo json_encode(array('status' => 0, 'message' => 'Error al obtener docentes.'));
    }
    exit;
}

// Obtener niveles para fichas
if ($action == "get_niveles_for_ficha") {
    $school_id = intval($_SESSION['login_school_id'] ?? 0);
    $year_id = intval($_GET['year_id'] ?? 0);
    $niveles = array();
    $level_expr=$year_id?"COALESCE((SELECT sah.nivel FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$school_id AND sah.academic_year_id=$year_id ORDER BY sah.id DESC LIMIT 1),s.nivel)":"s.nivel";
    $query = $conn->query("SELECT DISTINCT $level_expr nivel FROM student s WHERE s.school_id = $school_id" . ($year_id ? " AND (s.academic_year_id=$year_id OR EXISTS(SELECT 1 FROM student_academic_history hx WHERE hx.student_id=s.id AND hx.school_id=$school_id AND hx.academic_year_id=$year_id))" : '') . " ORDER BY nivel");
    
    if ($query) {
        while ($row = $query->fetch_assoc()) {
            $niveles[] = $row['nivel'];
        }
        echo json_encode(array('status' => 1, 'niveles' => $niveles));
    } else {
        echo json_encode(array('status' => 0, 'message' => 'Error al obtener niveles.'));
    }
    exit;
}

// Obtener grados por nivel para fichas
if ($action == "get_grados_by_nivel_for_ficha") {
    $school_id = intval($_SESSION['login_school_id'] ?? 0);
    $year_id = intval($_GET['year_id'] ?? 0);
    $nivel = $_GET['nivel'] ?? '';
    $grados = array();
    
    if ($nivel) {
        $safe_nivel=$conn->real_escape_string($nivel);$level_expr=$year_id?"COALESCE((SELECT sah.nivel FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$school_id AND sah.academic_year_id=$year_id ORDER BY sah.id DESC LIMIT 1),s.nivel)":"s.nivel";$grade_expr=$year_id?"COALESCE((SELECT sah.grado FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$school_id AND sah.academic_year_id=$year_id ORDER BY sah.id DESC LIMIT 1),s.grado)":"s.grado";
        $query = $conn->query("SELECT DISTINCT $grade_expr grado FROM student s WHERE s.school_id=$school_id AND $level_expr='$safe_nivel'" . ($year_id ? " AND (s.academic_year_id=$year_id OR EXISTS(SELECT 1 FROM student_academic_history hx WHERE hx.student_id=s.id AND hx.school_id=$school_id AND hx.academic_year_id=$year_id))" : '') . " ORDER BY grado");
        
        if ($query) {
            while ($row = $query->fetch_assoc()) {
                $grados[] = $row['grado'];
            }
        }
    }
    
    echo json_encode(array('status' => 1, 'grados' => $grados));
    exit;
}

// Obtener secciones por nivel y grado para fichas
if ($action == "get_secciones_by_nivel_grado_for_ficha") {
    $school_id = intval($_SESSION['login_school_id'] ?? 0);
    $year_id = intval($_GET['year_id'] ?? 0);
    $nivel = $_GET['nivel'] ?? '';
    $grado = $_GET['grado'] ?? '';
    $secciones = array();
    
    if ($nivel && $grado) {
        $safe_nivel=$conn->real_escape_string($nivel);$safe_grado=$conn->real_escape_string($grado);$level_expr=$year_id?"COALESCE((SELECT sah.nivel FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$school_id AND sah.academic_year_id=$year_id ORDER BY sah.id DESC LIMIT 1),s.nivel)":"s.nivel";$grade_expr=$year_id?"COALESCE((SELECT sah.grado FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$school_id AND sah.academic_year_id=$year_id ORDER BY sah.id DESC LIMIT 1),s.grado)":"s.grado";$section_expr=$year_id?"COALESCE((SELECT sah.seccion FROM student_academic_history sah WHERE sah.student_id=s.id AND sah.school_id=$school_id AND sah.academic_year_id=$year_id ORDER BY sah.id DESC LIMIT 1),s.seccion)":"s.seccion";
        $query = $conn->query("SELECT DISTINCT $section_expr seccion FROM student s WHERE s.school_id=$school_id AND $level_expr='$safe_nivel' AND $grade_expr='$safe_grado'" . ($year_id ? " AND (s.academic_year_id=$year_id OR EXISTS(SELECT 1 FROM student_academic_history hx WHERE hx.student_id=s.id AND hx.school_id=$school_id AND hx.academic_year_id=$year_id))" : '') . " HAVING seccion IS NOT NULL AND seccion<>'' ORDER BY seccion");
        
        if ($query) {
            while ($row = $query->fetch_assoc()) {
                $secciones[] = $row['seccion'];
            }
        }
    }
    
    echo json_encode(array('status' => 1, 'secciones' => $secciones));
    exit;
}

// Obtener años académicos
if ($action == "get_academic_years") {
    $school_id = intval($_SESSION['login_school_id'] ?? 0);
    $years = array();
    if ($school_id <= 0) { echo json_encode(['status'=>0,'message'=>'No hay una institución activa.']); exit; }
    $query = $conn->query("SELECT id, year, is_active FROM academic_year WHERE school_id = $school_id ORDER BY year DESC");
    
    if ($query) {
        while ($row = $query->fetch_assoc()) {
            $years[] = $row;
        }
    echo json_encode(array('status' => 1, 'years' => $years));
} else {
    echo json_encode(array('status' => 0, 'message' => 'Error al obtener años académicos.'));
}
exit;
}

// ==================== FUNCIONES PARA GESTIÓN DE ÁREAS ====================

if ($action == "save_area") {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? '';
    $name = $conn->real_escape_string($_POST['name']);
    $description = $conn->real_escape_string($_POST['description']);
    $color = $conn->real_escape_string($_POST['color']);
    $is_active = intval($_POST['is_active']);
    
    // Obtener el school_id del administrador actual
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (empty($id)) {
        // Verificar que no exista otra área con el mismo nombre en el mismo colegio
        $check = $conn->query("SELECT id FROM areas WHERE name = '$name' AND school_id = $school_id");
        if ($check && $check->num_rows > 0) {
            echo json_encode(['status' => 0, 'message' => 'Ya existe un área con ese nombre.']);
            exit;
        }
        
        // Crear nueva área
        $save = $conn->query("INSERT INTO areas (name, description, color, is_active, school_id) 
                             VALUES ('$name', '$description', '$color', $is_active, $school_id)");
    } else {
        // Verificar que el área pertenezca al mismo colegio
        $check = $conn->query("SELECT * FROM areas WHERE id = $id AND school_id = $school_id");
        if ($check && $check->num_rows > 0) {
            // Verificar que no exista otra área con el mismo nombre (excluyendo la actual)
            $check_name = $conn->query("SELECT id FROM areas WHERE name = '$name' AND school_id = $school_id AND id != $id");
            if ($check_name && $check_name->num_rows > 0) {
                echo json_encode(['status' => 0, 'message' => 'Ya existe otra área con ese nombre.']);
                exit;
            }
            
            $save = $conn->query("UPDATE areas SET name='$name', description='$description', color='$color', is_active=$is_active WHERE id=$id");
        } else {
            echo json_encode(['status' => 0, 'message' => 'No tiene permiso para editar esta área.']);
            exit;
        }
    }
    
    if ($save) {
        echo json_encode(['status' => 1, 'message' => 'Área guardada exitosamente.']);
    } else {
        echo json_encode(['status' => 0, 'message' => 'Error al guardar el área: ' . $conn->error]);
    }
    exit;
}

if ($action == "delete_area") {
    header('Content-Type: application/json');
    $id = $_POST['id'];
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    // Debug: Log de valores
    error_log("Delete area - ID: $id, School ID: $school_id");
    
    // Verificar que el área exista
    $check = $conn->query("SELECT * FROM areas WHERE id = $id");
    error_log("Check query result: " . ($check ? $check->num_rows : 'false'));
    
    if ($check && $check->num_rows > 0) {
        $area = $check->fetch_assoc();
        error_log("Area school_id: " . $area['school_id'] . ", User school_id: $school_id");
        
        // Verificar permisos
        if ($area['school_id'] != $school_id) {
            echo json_encode(['status' => 0, 'message' => 'No tiene permiso para eliminar esta área.']);
            exit;
        }
        // Verificar si hay cursos asignados a esta área
        $courses_check = $conn->query("SELECT COUNT(*) as count FROM academic_courses WHERE area_id = $id");
        $courses_count = $courses_check->fetch_assoc()['count'];
        
        if ($courses_count > 0) {
            echo json_encode(['status' => 0, 'message' => "No se puede eliminar el área porque tiene $courses_count curso(s) asignado(s). Primero mueva los cursos a otra área."]);
            exit;
        }
        
        $delete = $conn->query("DELETE FROM areas WHERE id = $id");
        if ($delete) {
            echo json_encode(['status' => 1, 'message' => 'Área eliminada exitosamente.']);
        } else {
            echo json_encode(['status' => 0, 'message' => 'Error al eliminar el área: ' . $conn->error]);
        }
    } else {
        echo json_encode(['status' => 0, 'message' => 'No tiene permiso para eliminar esta área.']);
    }
    exit;
}

if ($action == "assign_course_to_area") {
    header('Content-Type: application/json');
    $course_id = $_POST['course_id'];
    $area_id = $_POST['area_id'];
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    // Verificar que tanto el curso como el área pertenezcan al colegio del administrador
    $course_check = $conn->query("SELECT * FROM academic_courses WHERE id = $course_id AND school_id = $school_id");
    $area_check = $conn->query("SELECT * FROM areas WHERE id = $area_id AND school_id = $school_id");
    
    if ($course_check && $course_check->num_rows > 0 && $area_check && $area_check->num_rows > 0) {
        $update = $conn->query("UPDATE academic_courses SET area_id = $area_id WHERE id = $course_id");
        if ($update) {
            echo json_encode(['status' => 1, 'message' => 'Curso asignado exitosamente.']);
        } else {
            echo json_encode(['status' => 0, 'message' => 'Error al asignar el curso: ' . $conn->error]);
        }
    } else {
        echo json_encode(['status' => 0, 'message' => 'No tiene permiso para realizar esta acción.']);
    }
    exit;
}

if ($action == "remove_course_from_area") {
    header('Content-Type: application/json');
    $course_id = $_POST['id'];
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    // Verificar que el curso pertenezca al colegio del administrador
    $course_check = $conn->query("SELECT * FROM academic_courses WHERE id = $course_id AND school_id = $school_id");
    
    if ($course_check && $course_check->num_rows > 0) {
        $update = $conn->query("UPDATE academic_courses SET area_id = NULL WHERE id = $course_id");
        if ($update) {
            echo json_encode(['status' => 1, 'message' => 'Curso removido de la área exitosamente.']);
        } else {
            echo json_encode(['status' => 0, 'message' => 'Error al remover el curso: ' . $conn->error]);
        }
    } else {
        echo json_encode(['status' => 0, 'message' => 'No tiene permiso para realizar esta acción.']);
    }
    exit;
}

// ==================== FUNCIONES PARA VISTA UNIFICADA ====================

if ($action == "get_academic_stats") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    // Obtener estadísticas
    $total_areas = $conn->query("SELECT COUNT(*) as count FROM areas WHERE school_id = $school_id")->fetch_assoc()['count'];
    $total_courses = $conn->query("SELECT COUNT(*) as count FROM academic_courses WHERE school_id = $school_id")->fetch_assoc()['count'];
    $assigned_courses = $conn->query("SELECT COUNT(*) as count FROM academic_courses WHERE school_id = $school_id AND area_id IS NOT NULL")->fetch_assoc()['count'];
    $unassigned_courses = $total_courses - $assigned_courses;
    
    echo json_encode([
        'status' => 1,
        'stats' => [
            'total_areas' => $total_areas,
            'total_courses' => $total_courses,
            'assigned_courses' => $assigned_courses,
            'unassigned_courses' => $unassigned_courses
        ]
    ]);
    exit;
}

if ($action == "get_unified_view") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    // Obtener todos los niveles únicos con sus áreas y cursos
    $levels = $conn->query("
        SELECT DISTINCT ac.level 
        FROM academic_courses ac 
        WHERE ac.school_id = $school_id 
        ORDER BY 
            CASE ac.level 
                WHEN 'Inicial' THEN 1
                WHEN 'Primaria' THEN 2
                WHEN 'Secundaria' THEN 3
                ELSE 4
            END
    ");
    
    $html = '';
    
    if ($levels && $levels->num_rows > 0) {
        while ($level = $levels->fetch_assoc()) {
            $level_name = $level['level'];
            
            $html .= '<div class="level-container">';
            $html .= '<div class="level-header">';
            $html .= '<h4 class="level-title">';
            $html .= '<i class="fa fa-graduation-cap"></i> ' . $level_name;
            $html .= '</h4>';
            $html .= '</div>';
            
            // Obtener áreas para este nivel
            $areas = $conn->query("
                SELECT DISTINCT a.*, 
                       COUNT(ac.id) as course_count
                FROM areas a 
                INNER JOIN academic_courses ac ON a.id = ac.area_id 
                WHERE ac.school_id = $school_id AND ac.level = '" . $level_name . "'
                GROUP BY a.id 
                ORDER BY a.name ASC
            ");
            
            if ($areas && $areas->num_rows > 0) {
                $html .= '<div class="areas-in-level">';
                while ($area = $areas->fetch_assoc()) {
                    $html .= '<div class="area-card">';
                    $html .= '<div class="area-header">';
                    $html .= '<h5 class="area-title">';
                    $html .= '<span class="area-color-badge" style="background-color: ' . $area['color'] . ';"></span>';
                    $html .= $area['name'];
                    $html .= '<span class="badge badge-info ml-2">' . $area['course_count'] . ' cursos</span>';
                    $html .= '</h5>';
                    $html .= '<p class="text-muted mb-0">' . $area['description'] . '</p>';
                            $html .= '<div class="area-actions">';
                            $html .= '<button class="btn btn-primary btn-sm" onclick="uni_modal(\'Editar Área\', \'manage_area.php?id=' . $area['id'] . '\', \'mid-large\')">';
                            $html .= '<i class="fa fa-edit"></i>';
                            $html .= '</button>';
                            $html .= '<button class="btn btn-info btn-sm" onclick="uni_modal(\'Cursos / Capacidades de ' . $area['name'] . ' - ' . $level_name . '\', \'area_courses.php?id=' . $area['id'] . '&level=' . $level_name . '\', \'modal-xl\')">';
                            $html .= '<i class="fa fa-eye"></i>';
                            $html .= '</button>';
                            $html .= '<button class="btn btn-success btn-sm" onclick="uni_modal(\'Crear Curso / Capacidad\', \'manage_academic_course.php?area_id=' . $area['id'] . '&level=' . $level_name . '\', \'mid-large\')">';
                            $html .= '<i class="fa fa-plus"></i>';
                            $html .= '</button>';
                            $html .= '<button class="btn btn-danger btn-sm" onclick="deleteArea(' . $area['id'] . ', \'' . $area['name'] . '\')">';
                            $html .= '<i class="fa fa-trash"></i>';
                            $html .= '</button>';
                            $html .= '</div>';
                    $html .= '</div>';
                    
                    // Obtener cursos de esta área para este nivel
                    $courses = $conn->query("
                        SELECT * FROM academic_courses 
                        WHERE area_id = " . $area['id'] . " 
                        AND school_id = $school_id 
                        AND level = '" . $level_name . "'
                        ORDER BY name ASC
                    ");
                    
                    $html .= '<div class="courses-in-area">';
                    if ($courses && $courses->num_rows > 0) {
                        $html .= '<div class="courses-grid">';
                        while ($course = $courses->fetch_assoc()) {
                            $html .= '<div class="course-item">';
                            $html .= '<div class="course-info">';
                            $html .= '<div class="course-name">' . $course['name'] . '</div>';
                            if (!empty($course['description'])) {
                                $html .= '<div class="course-description">' . $course['description'] . '</div>';
                            }
                            $html .= '</div>';
                            $html .= '<div class="course-actions">';
                            $html .= '<button class="btn btn-warning btn-sm" onclick="removeCourseFromArea(' . $course['id'] . ', \'' . $course['name'] . '\')">';
                            $html .= '<i class="fa fa-times"></i> Quitar';
                            $html .= '</button>';
                            $html .= '</div>';
                            $html .= '</div>';
                        }
                        $html .= '</div>';
                    } else {
                        $html .= '<div class="no-courses">No hay cursos asignados a esta área para ' . $level_name . '</div>';
                    }
                    $html .= '</div>';
                    
                    $html .= '</div>';
                }
                $html .= '</div>';
            } else {
                $html .= '<div class="no-areas">No hay áreas con cursos en ' . $level_name . '</div>';
            }
            
            $html .= '</div>';
        }
    } else {
        $html = '<div class="text-center text-muted">';
        $html .= '<i class="fa fa-folder-open fa-3x mb-3"></i>';
        $html .= '<h5>No hay cursos académicos creados</h5>';
        $html .= '<p>Comienza creando tu primer curso académico para ver la organización por niveles.</p>';
        $html .= '</div>';
    }
    
    echo json_encode(['status' => 1, 'html' => $html]);
    exit;
}

if ($action == "get_level_view") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    $level = $_GET['level'] ?? '';
    
    if (empty($level)) {
        echo json_encode(['status' => 0, 'message' => 'Nivel no especificado']);
        exit;
    }
    
    // Obtener áreas para el nivel específico
    $areas = $conn->query("
        SELECT DISTINCT a.*, 
               COUNT(ac.id) as course_count
        FROM areas a 
        INNER JOIN academic_courses ac ON a.id = ac.area_id 
        WHERE ac.school_id = $school_id AND ac.level = '$level'
        GROUP BY a.id 
        ORDER BY a.name ASC
    ");
    
    $html = '';
    
    if ($areas && $areas->num_rows > 0) {
        $html .= '<div class="level-container">';
        $html .= '<div class="level-header">';
        $html .= '<h4 class="level-title">';
        $html .= '<i class="fa fa-graduation-cap"></i> ' . $level;
        $html .= '</h4>';
        $html .= '</div>';
        
        $html .= '<div class="areas-in-level">';
        while ($area = $areas->fetch_assoc()) {
            $html .= '<div class="area-card">';
            $html .= '<div class="area-header">';
            $html .= '<h5 class="area-title">';
            $html .= '<span class="area-color-badge" style="background-color: ' . $area['color'] . ';"></span>';
            $html .= $area['name'];
            $html .= '<span class="badge badge-info ml-2">' . $area['course_count'] . ' cursos</span>';
            $html .= '</h5>';
            $html .= '<p class="text-muted mb-0">' . $area['description'] . '</p>';
            $html .= '<div class="area-actions">';
            $html .= '<button class="btn btn-primary btn-sm" onclick="uni_modal(\'Editar Área\', \'manage_area.php?id=' . $area['id'] . '\', \'mid-large\')">';
            $html .= '<i class="fa fa-edit"></i>';
            $html .= '</button>';
            $html .= '<button class="btn btn-info btn-sm" onclick="uni_modal(\'Cursos / Capacidades de ' . $area['name'] . ' - ' . $level . '\', \'area_courses.php?id=' . $area['id'] . '&level=' . $level . '\', \'modal-xl\')">';
            $html .= '<i class="fa fa-eye"></i>';
            $html .= '</button>';
            $html .= '<button class="btn btn-success btn-sm" onclick="uni_modal(\'Crear Curso / Capacidad\', \'manage_academic_course.php?area_id=' . $area['id'] . '&level=' . $level . '\', \'mid-large\')">';
            $html .= '<i class="fa fa-plus"></i>';
            $html .= '</button>';
            $html .= '<button class="btn btn-danger btn-sm" onclick="deleteArea(' . $area['id'] . ', \'' . $area['name'] . '\')">';
            $html .= '<i class="fa fa-trash"></i>';
            $html .= '</button>';
            $html .= '</div>';
            $html .= '</div>';
            
            // Obtener cursos de esta área para este nivel
            $courses = $conn->query("
                SELECT * FROM academic_courses 
                WHERE area_id = " . $area['id'] . " 
                AND school_id = $school_id 
                AND level = '$level'
                ORDER BY name ASC
            ");
            
            $html .= '<div class="courses-in-area">';
            if ($courses && $courses->num_rows > 0) {
                $html .= '<div class="courses-grid">';
                while ($course = $courses->fetch_assoc()) {
                    $html .= '<div class="course-item">';
                    $html .= '<div class="course-info">';
                    $html .= '<div class="course-name">' . $course['name'] . '</div>';
                    if (!empty($course['description'])) {
                        $html .= '<div class="course-description">' . $course['description'] . '</div>';
                    }
                    $html .= '</div>';
                    $html .= '<div class="course-actions">';
                    $html .= '<button class="btn btn-warning btn-sm" onclick="removeCourseFromArea(' . $course['id'] . ', \'' . $course['name'] . '\')">';
                    $html .= '<i class="fa fa-times"></i> Quitar';
                    $html .= '</button>';
                    $html .= '</div>';
                    $html .= '</div>';
                }
                $html .= '</div>';
            } else {
                $html .= '<div class="no-courses">No hay cursos asignados a esta área para ' . $level . '</div>';
            }
            $html .= '</div>';
            $html .= '</div>'; // Cerrar area-card
        }
        $html .= '</div>';
        $html .= '</div>';
    } else {
        $html = '<div class="text-center text-muted">';
        $html .= '<i class="fa fa-info-circle fa-3x mb-3"></i>';
        $html .= '<h5>No hay áreas con cursos en ' . $level . '</h5>';
        $html .= '<p>Este nivel no tiene áreas académicas con cursos asignados.</p>';
        $html .= '</div>';
    }
    
    echo json_encode(['status' => 1, 'html' => $html]);
    exit;
}

if ($action == "get_areas_table") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    $page = intval($_GET['page'] ?? 1);
    $search = $_GET['search'] ?? '';
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    // Construir WHERE clause con búsqueda
    $where_clause = "a.school_id = $school_id";
    if (!empty($search)) {
        $search_escaped = $conn->real_escape_string($search);
        $where_clause .= " AND (a.name LIKE '%$search_escaped%' OR a.description LIKE '%$search_escaped%')";
    }
    
    // Contar total de registros
    $total_query = $conn->query("SELECT COUNT(*) as total FROM areas a WHERE $where_clause");
    $total_records = $total_query->fetch_assoc()['total'];
    $total_pages = ceil($total_records / $limit);
    
    $areas = $conn->query("SELECT a.*, 
        (SELECT COUNT(*) FROM academic_courses ac WHERE ac.area_id = a.id) as course_count
        FROM areas a 
        WHERE $where_clause
        ORDER BY a.name ASC
        LIMIT $limit OFFSET $offset");
    $html = '';
    $i = $offset + 1;
    
    if ($areas && $areas->num_rows > 0) {
        while ($area = $areas->fetch_assoc()) {
            $html .= '<tr>';
            $html .= '<td class="text-center">' . $i++ . '</td>';
            $html .= '<td>' . htmlspecialchars(ucwords($area['name'])) . '</td>';
            $html .= '<td>' . htmlspecialchars($area['description']) . '</td>';
            $html .= '<td class="text-center">';
            $html .= '<span class="badge" style="background-color: ' . htmlspecialchars($area['color']) . '; color: white; padding: 5px 10px;">';
            $html .= htmlspecialchars($area['color']);
            $html .= '</span>';
            $html .= '</td>';
            $html .= '<td class="text-center">';
            $html .= '<span class="badge badge-info">' . htmlspecialchars($area['course_count']) . ' cursos</span>';
            $html .= '</td>';
            $html .= '<td class="text-center">';
            if ($area['is_active']) {
                $html .= '<span class="badge badge-success">Activo</span>';
            } else {
                $html .= '<span class="badge badge-secondary">Inactivo</span>';
            }
            $html .= '</td>';
            $html .= '<td class="text-center">';
            $html .= '<button class="btn btn-primary btn-sm edit_area" type="button" data-id="' . htmlspecialchars($area['id']) . '">';
            $html .= '<i class="fa fa-edit"></i>';
            $html .= '</button>';
            $html .= '<button class="btn btn-danger btn-sm delete_area" type="button" data-id="' . htmlspecialchars($area['id']) . '">';
            $html .= '<i class="fa fa-trash-alt"></i>';
            $html .= '</button>';
            $html .= '<button class="btn btn-info btn-sm view_courses" type="button" data-id="' . htmlspecialchars($area['id']) . '" data-name="' . htmlspecialchars($area['name'], ENT_QUOTES) . '">';
            $html .= '<i class="fa fa-eye"></i>';
            $html .= '</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }
    } else {
        $html = '<tr><td colspan="7" class="text-center text-muted">No hay áreas académicas creadas</td></tr>';
    }
    
    // Generar HTML de paginación
    $pagination_html = generatePagination($page, $total_pages, 'loadAreasTable', null);
    
    echo json_encode([
        'status' => 1, 
        'html' => $html, 
        'pagination' => $pagination_html,
        'total_records' => $total_records,
        'current_page' => $page,
        'total_pages' => $total_pages
    ]);
    exit;
}

if ($action == "get_courses_table") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    $page = intval($_GET['page'] ?? 1);
    $search = $_GET['search'] ?? '';
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    // Construir WHERE clause con búsqueda
    $where_clause = "ac.school_id = $school_id";
    if (!empty($search)) {
        $search_escaped = $conn->real_escape_string($search);
        $where_clause .= " AND (ac.name LIKE '%$search_escaped%' OR ac.description LIKE '%$search_escaped%' OR a.name LIKE '%$search_escaped%')";
    }
    
    // Contar total de registros
    $total_query = $conn->query("SELECT COUNT(*) as total FROM academic_courses ac LEFT JOIN areas a ON ac.area_id = a.id WHERE $where_clause");
    $total_records = $total_query->fetch_assoc()['total'];
    $total_pages = ceil($total_records / $limit);
    
    $courses = $conn->query("
        SELECT ac.*, a.name as area_name, a.color as area_color 
        FROM academic_courses ac 
        LEFT JOIN areas a ON ac.area_id = a.id 
        WHERE $where_clause
        ORDER BY a.name ASC, ac.name ASC
        LIMIT $limit OFFSET $offset
    ");
    $html = '';
    $i = $offset + 1;
    
    if ($courses && $courses->num_rows > 0) {
        while ($course = $courses->fetch_assoc()) {
            $html .= '<tr>';
            $html .= '<td class="text-center">' . $i++ . '</td>';
            $html .= '<td>' . htmlspecialchars(ucwords($course['name'])) . '</td>';
            $html .= '<td>';
            if ($course['area_name']) {
                $html .= '<span class="badge" style="background-color: ' . htmlspecialchars($course['area_color']) . '; color: white;">';
                $html .= htmlspecialchars($course['area_name']);
                $html .= '</span>';
            } else {
                $html .= '<span class="badge badge-secondary">Sin área</span>';
            }
            $html .= '</td>';
            $html .= '<td>' . htmlspecialchars($course['level']) . '</td>';
            $html .= '<td>' . htmlspecialchars($course['description']) . '</td>';
            $html .= '<td class="text-center">';
            $html .= '<button class="btn btn-primary btn-sm edit_course" type="button" data-id="' . htmlspecialchars($course['id']) . '">';
            $html .= '<i class="fa fa-edit"></i>';
            $html .= '</button>';
            $html .= '<button class="btn btn-danger btn-sm delete_course" type="button" data-id="' . htmlspecialchars($course['id']) . '">';
            $html .= '<i class="fa fa-trash-alt"></i>';
            $html .= '</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }
    } else {
        $html = '<tr><td colspan="6" class="text-center text-muted">No hay cursos académicos creados</td></tr>';
    }
    
    // Generar HTML de paginación
    $pagination_html = generatePagination($page, $total_pages, 'loadCoursesTable', null);
    
    echo json_encode([
        'status' => 1, 
        'html' => $html, 
        'pagination' => $pagination_html,
        'total_records' => $total_records,
        'current_page' => $page,
        'total_pages' => $total_pages
    ]);
    exit;
}

// Acción para obtener áreas por nivel
if ($action == "get_areas_by_level") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    $level = $_GET['level'] ?? 'all';
    $page = intval($_GET['page'] ?? 1);
    $search = $_GET['search'] ?? '';
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    $where_clause = "a.school_id = $school_id";
    if ($level !== 'all') {
        $where_clause .= " AND EXISTS (SELECT 1 FROM academic_courses ac WHERE ac.area_id = a.id AND ac.level = '$level')";
    }
    if (!empty($search)) {
        $search_escaped = $conn->real_escape_string($search);
        $where_clause .= " AND (a.name LIKE '%$search_escaped%' OR a.description LIKE '%$search_escaped%')";
    }
    
    // Contar total de registros
    $total_query = $conn->query("SELECT COUNT(*) as total FROM areas a WHERE $where_clause");
    $total_records = $total_query->fetch_assoc()['total'];
    $total_pages = ceil($total_records / $limit);
    
    $areas = $conn->query("SELECT a.*, 
        (SELECT COUNT(*) FROM academic_courses ac WHERE ac.area_id = a.id" . 
        ($level !== 'all' ? " AND ac.level = '$level'" : "") . ") as course_count
        FROM areas a 
        WHERE $where_clause
        ORDER BY a.name ASC
        LIMIT $limit OFFSET $offset");
    
    $html = '';
    $i = $offset + 1;
    
    if ($areas && $areas->num_rows > 0) {
        while ($area = $areas->fetch_assoc()) {
            $html .= '<tr>';
            $html .= '<td class="text-center">' . $i++ . '</td>';
            $html .= '<td>' . htmlspecialchars(ucwords($area['name'])) . '</td>';
            $html .= '<td>' . htmlspecialchars($area['description']) . '</td>';
            $html .= '<td class="text-center">';
            $html .= '<span class="badge" style="background-color: ' . htmlspecialchars($area['color']) . '; color: white; padding: 5px 10px;">';
            $html .= htmlspecialchars($area['color']);
            $html .= '</span>';
            $html .= '</td>';
            $html .= '<td class="text-center">';
            $html .= '<span class="badge badge-info">' . $area['course_count'] . ' cursos</span>';
            $html .= '</td>';
            $html .= '<td class="text-center">';
            if ($area['is_active']) {
                $html .= '<span class="badge badge-success">Activo</span>';
            } else {
                $html .= '<span class="badge badge-secondary">Inactivo</span>';
            }
            $html .= '</td>';
            $html .= '<td class="text-center">';
            $html .= '<button class="btn btn-primary btn-sm edit_area" type="button" data-id="' . htmlspecialchars($area['id']) . '">';
            $html .= '<i class="fa fa-edit"></i>';
            $html .= '</button>';
            $html .= '<button class="btn btn-danger btn-sm delete_area" type="button" data-id="' . htmlspecialchars($area['id']) . '">';
            $html .= '<i class="fa fa-trash-alt"></i>';
            $html .= '</button>';
            $html .= '<button class="btn btn-info btn-sm view_courses" type="button" data-id="' . htmlspecialchars($area['id']) . '" data-name="' . htmlspecialchars($area['name']) . '">';
            $html .= '<i class="fa fa-eye"></i>';
            $html .= '</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }
    } else {
        $level_text = $level === 'all' ? 'todos los niveles' : 'el nivel ' . ucfirst($level);
        $html = '<tr><td colspan="7" class="text-center text-muted">No hay áreas académicas para ' . $level_text . '</td></tr>';
    }
    
    // Generar HTML de paginación
    $pagination_html = generatePagination($page, $total_pages, 'loadAreasByLevel', $level);
    
    echo json_encode([
        'status' => 1, 
        'html' => $html, 
        'pagination' => $pagination_html,
        'total_records' => $total_records,
        'current_page' => $page,
        'total_pages' => $total_pages
    ]);
    exit;
}

// Acción para obtener cursos por nivel
if ($action == "get_courses_by_level") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    $level = $_GET['level'] ?? 'all';
    $page = intval($_GET['page'] ?? 1);
    $search = $_GET['search'] ?? '';
    $limit = 10;
    $offset = ($page - 1) * $limit;
    
    $where_clause = "ac.school_id = $school_id";
    if ($level !== 'all') {
        $where_clause .= " AND ac.level = '$level'";
    }
    if (!empty($search)) {
        $search_escaped = $conn->real_escape_string($search);
        $where_clause .= " AND (ac.name LIKE '%$search_escaped%' OR ac.description LIKE '%$search_escaped%' OR a.name LIKE '%$search_escaped%')";
    }
    
    // Contar total de registros
    $total_query = $conn->query("SELECT COUNT(*) as total FROM academic_courses ac WHERE $where_clause");
    $total_records = $total_query->fetch_assoc()['total'];
    $total_pages = ceil($total_records / $limit);
    
    $courses = $conn->query("
        SELECT ac.*, a.name as area_name, a.color as area_color 
        FROM academic_courses ac 
        LEFT JOIN areas a ON ac.area_id = a.id 
        WHERE $where_clause
        ORDER BY a.name ASC, ac.name ASC
        LIMIT $limit OFFSET $offset
    ");
    
    $html = '';
    $i = $offset + 1;
    
    if ($courses && $courses->num_rows > 0) {
        while ($course = $courses->fetch_assoc()) {
            $html .= '<tr>';
            $html .= '<td class="text-center">' . $i++ . '</td>';
            $html .= '<td>' . htmlspecialchars(ucwords($course['name'])) . '</td>';
            $html .= '<td>';
            if ($course['area_name']) {
                $html .= '<span class="badge" style="background-color: ' . htmlspecialchars($course['area_color']) . '; color: white;">';
                $html .= htmlspecialchars($course['area_name']);
                $html .= '</span>';
            } else {
                $html .= '<span class="badge badge-secondary">Sin área</span>';
            }
            $html .= '</td>';
            $html .= '<td>' . htmlspecialchars($course['level']) . '</td>';
            $html .= '<td>' . htmlspecialchars($course['description']) . '</td>';
            $html .= '<td class="text-center">';
            $html .= '<button class="btn btn-primary btn-sm edit_course" type="button" data-id="' . htmlspecialchars($course['id']) . '">';
            $html .= '<i class="fa fa-edit"></i>';
            $html .= '</button>';
            $html .= '<button class="btn btn-danger btn-sm delete_course" type="button" data-id="' . htmlspecialchars($course['id']) . '">';
            $html .= '<i class="fa fa-trash-alt"></i>';
            $html .= '</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }
    } else {
        $level_text = $level === 'all' ? 'todos los niveles' : 'el nivel ' . ucfirst($level);
        $html = '<tr><td colspan="6" class="text-center text-muted">No hay cursos académicos para ' . $level_text . '</td></tr>';
    }
    
    // Generar HTML de paginación
    $pagination_html = generatePagination($page, $total_pages, 'loadCoursesByLevel', $level);
    
    echo json_encode([
        'status' => 1, 
        'html' => $html, 
        'pagination' => $pagination_html,
        'total_records' => $total_records,
        'current_page' => $page,
        'total_pages' => $total_pages
    ]);
    exit;
}

if ($action == "get_area_courses_table") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    $area_id = intval($_GET['area_id'] ?? 0);
    
    if (!$area_id) {
        echo json_encode(['status' => 0, 'message' => 'ID de área no especificado']);
        exit;
    }
    
    $courses = $conn->query("
        SELECT * FROM academic_courses 
        WHERE area_id = $area_id AND school_id = $school_id 
        ORDER BY name ASC
    ");
    $html = '';
    $i = 1;
    
    if ($courses && $courses->num_rows > 0) {
        while ($course = $courses->fetch_assoc()) {
            $html .= '<tr>';
            $html .= '<td class="text-center">' . $i++ . '</td>';
            $html .= '<td>' . htmlspecialchars(ucwords($course['name'])) . '</td>';
            $html .= '<td>';
            $html .= '<span class="badge badge-info">' . htmlspecialchars($course['level']) . '</span>';
            $html .= '</td>';
            $html .= '<td>' . htmlspecialchars($course['description']) . '</td>';
            $html .= '<td class="text-center">';
            $html .= '<button class="btn btn-warning btn-sm remove_course_from_area" type="button" data-id="' . htmlspecialchars($course['id']) . '" data-name="' . htmlspecialchars($course['name'], ENT_QUOTES) . '">';
            $html .= '<i class="fa fa-times"></i> Quitar';
            $html .= '</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }
    } else {
        $html = '<tr><td colspan="5" class="text-center text-muted"><i class="fa fa-info-circle"></i> No hay cursos asignados a esta área</td></tr>';
    }
    
    echo json_encode(['status' => 1, 'html' => $html]);
    exit;
}

if ($action == "get_unassigned_courses_table") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    $area_id = intval($_GET['area_id'] ?? 0);
    
    error_log("get_unassigned_courses_table - school_id: $school_id, area_id: $area_id");
    
    if (!$area_id) {
        echo json_encode(['status' => 0, 'message' => 'ID de área no especificado']);
        exit;
    }
    
    $query = "
        SELECT ac.*, a.name as current_area_name, a.color as current_area_color 
        FROM academic_courses ac 
        LEFT JOIN areas a ON ac.area_id = a.id 
        WHERE ac.school_id = $school_id 
        AND ac.area_id IS NULL
        ORDER BY ac.name ASC
    ";
    
    error_log("Query ejecutada: " . $query);
    
    $courses = $conn->query($query);
    $html = '';
    $i = 1;
    
    error_log("Número de cursos encontrados: " . ($courses ? $courses->num_rows : 'Error en query'));
    
    if ($courses && $courses->num_rows > 0) {
        while ($course = $courses->fetch_assoc()) {
            $html .= '<tr>';
            $html .= '<td class="text-center">';
            $html .= '<input type="checkbox" class="course_checkbox" data-id="' . htmlspecialchars($course['id']) . '" data-name="' . htmlspecialchars($course['name'], ENT_QUOTES) . '">';
            $html .= '</td>';
            $html .= '<td class="text-center">' . $i++ . '</td>';
            $html .= '<td>' . htmlspecialchars(ucwords($course['name'])) . '</td>';
            $html .= '<td>';
            $html .= '<span class="badge badge-info">' . htmlspecialchars($course['level']) . '</span>';
            $html .= '</td>';
            $html .= '<td>';
            if ($course['current_area_name']) {
                $html .= '<span class="badge" style="background-color: ' . htmlspecialchars($course['current_area_color']) . '; color: white;">';
                $html .= htmlspecialchars($course['current_area_name']);
                $html .= '</span>';
            } else {
                $html .= '<span class="badge badge-secondary">Sin área</span>';
            }
            $html .= '</td>';
            $html .= '<td>' . htmlspecialchars($course['description']) . '</td>';
            $html .= '</tr>';
        }
    } else {
        $html = '<tr><td colspan="6" class="text-center text-muted"><i class="fa fa-info-circle"></i> No hay cursos disponibles para asignar</td></tr>';
    }
    
    echo json_encode(['status' => 1, 'html' => $html]);
    exit;
}

if ($action == "debug_unassigned_courses") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    // Verificar todos los cursos
    $all_courses = $conn->query("SELECT id, name, area_id FROM academic_courses WHERE school_id = $school_id");
    $all_courses_data = [];
    if ($all_courses) {
        while ($row = $all_courses->fetch_assoc()) {
            $all_courses_data[] = $row;
        }
    }
    
    // Verificar cursos sin área
    $unassigned_courses = $conn->query("SELECT id, name, area_id FROM academic_courses WHERE school_id = $school_id AND area_id IS NULL");
    $unassigned_courses_data = [];
    if ($unassigned_courses) {
        while ($row = $unassigned_courses->fetch_assoc()) {
            $unassigned_courses_data[] = $row;
        }
    }
    
    echo json_encode([
        'status' => 1,
        'all_courses' => $all_courses_data,
        'unassigned_courses' => $unassigned_courses_data,
        'total_all' => count($all_courses_data),
        'total_unassigned' => count($unassigned_courses_data)
    ]);
    exit;
}

// ============== GESTIÓN DE ESTUDIANTES ==============

if ($action == "save_student") {
    header('Content-Type: application/json');
    echo $crud->save_student();
    exit;
}

if ($action == "delete_student") {
    header('Content-Type: application/json');
    echo $crud->delete_student();
    exit;
}

if ($action == "upload_excel") {
    header('Content-Type: application/json');
    echo $crud->upload_excel();
    exit;
}

// ==================== FACTURACIÓN ELECTRÓNICA SUNAT ====================

// Guardar configuración de facturación
if ($action == "save_config_facturacion") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$school_id) {
        echo json_encode(['status' => 0, 'message' => 'No se ha establecido el colegio.']);
        exit;
    }
    
    // Verificar si ya existe configuración
    $check = $conn->query("SELECT id FROM company_config WHERE school_id = $school_id");
    $config_id = $check && $check->num_rows > 0 ? $check->fetch_assoc()['id'] : null;
    
    // Recoger datos del formulario
    $ruc = $conn->real_escape_string($_POST['ruc']);
    $razon_social = $conn->real_escape_string($_POST['razon_social']);
    $nombre_comercial = $conn->real_escape_string($_POST['nombre_comercial'] ?? '');
    $direccion = $conn->real_escape_string($_POST['direccion']);
    $ubigeo = $conn->real_escape_string($_POST['ubigeo']);
    $urbanizacion = $conn->real_escape_string($_POST['urbanizacion'] ?? '');
    $provincia = $conn->real_escape_string($_POST['provincia']);
    $departamento = $conn->real_escape_string($_POST['departamento']);
    $distrito = $conn->real_escape_string($_POST['distrito']);
    $telefono = $conn->real_escape_string($_POST['telefono'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $website = $conn->real_escape_string($_POST['website'] ?? '');
    
    // Datos SUNAT
    $sunat_usuario = $conn->real_escape_string($_POST['sunat_usuario']);
    $sunat_password = $conn->real_escape_string($_POST['sunat_password']);
    $sunat_client_id = $conn->real_escape_string($_POST['sunat_client_id'] ?? '');
    $sunat_client_secret = $conn->real_escape_string($_POST['sunat_client_secret'] ?? '');
    $sunat_modo = $conn->real_escape_string($_POST['sunat_modo']);
    
    // Series
    $serie_factura = $conn->real_escape_string($_POST['serie_factura']);
    $serie_boleta = $conn->real_escape_string($_POST['serie_boleta']);
    
    // Función simple de encriptación (sin necesidad de instanciar la clase completa)
    function encrypt_password($password) {
        $key = 'EduSync2024Secret';
        $method = 'AES-256-CBC';
        $iv = substr(hash('sha256', $key), 0, 16);
        return base64_encode(openssl_encrypt($password, $method, $key, 0, $iv));
    }
    
    // Encriptar contraseña SUNAT solo si viene una nueva
    $sunat_password_sql = '';
    $sunat_password_encrypted = '';
    if (!empty($sunat_password)) {
        $sunat_password_encrypted = encrypt_password($sunat_password);
        $sunat_password_sql = ", sunat_password = '$sunat_password_encrypted'";
    }
    
    // Encriptar client secret solo si viene una nueva
    $sunat_client_secret_sql = '';
    $sunat_client_secret_encrypted = '';
    if (!empty($sunat_client_secret)) {
        $sunat_client_secret_encrypted = encrypt_password($sunat_client_secret);
        $sunat_client_secret_sql = ", sunat_client_secret = '$sunat_client_secret_encrypted'";
    }
    
    // Asegurar columnas adicionales en company_config
    $requiredColumns = [
        'sunat_client_id' => "ADD COLUMN sunat_client_id varchar(100) DEFAULT NULL",
        'sunat_client_secret' => "ADD COLUMN sunat_client_secret varchar(200) DEFAULT NULL"
    ];
    foreach ($requiredColumns as $column => $alterSql) {
        $colCheck = $conn->query("SHOW COLUMNS FROM company_config LIKE '$column'");
        if (!$colCheck || $colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE company_config $alterSql");
        }
    }
    
    // Manejo de certificado digital
    $certificado_path = '';
    $certificado_password_encrypted = '';
    
    if (isset($_FILES['certificado']) && $_FILES['certificado']['error'] == 0) {
        $upload_dir = __DIR__ . '/uploads/certificados/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $cert_name = $ruc . '_' . time() . '.pem';
        $cert_path = $upload_dir . $cert_name;
        
        if (move_uploaded_file($_FILES['certificado']['tmp_name'], $cert_path)) {
            $certificado_path = 'uploads/certificados/' . $cert_name;
            $cert_password = $_POST['certificado_password'] ?? '';
            $certificado_password_encrypted = encrypt_password($cert_password);
        }
    }
    
    if ($config_id) {
        // Actualizar configuración existente
        $sql = "UPDATE company_config SET 
                ruc = '$ruc',
                razon_social = '$razon_social',
                nombre_comercial = '$nombre_comercial',
                direccion = '$direccion',
                ubigeo = '$ubigeo',
                urbanizacion = '$urbanizacion',
                provincia = '$provincia',
                departamento = '$departamento',
                distrito = '$distrito',
                telefono = '$telefono',
                email = '$email',
                website = '$website',
                sunat_usuario = '$sunat_usuario',
                sunat_client_id = '$sunat_client_id',
                sunat_modo = '$sunat_modo',
                serie_factura = '$serie_factura',
                serie_boleta = '$serie_boleta'"
                . $sunat_password_sql
                . $sunat_client_secret_sql;
        
        if ($certificado_path) {
            $sql .= ", certificado_path = '$certificado_path', certificado_password = '$certificado_password_encrypted'";
        }
        
        $sql .= " WHERE id = $config_id";
    } else {
        // Insertar nueva configuración
        $sql = "INSERT INTO company_config (
                school_id, ruc, razon_social, nombre_comercial, direccion, ubigeo,
                urbanizacion, provincia, departamento, distrito, telefono, email,
                website, certificado_path, certificado_password, sunat_usuario,
                sunat_password, sunat_client_id, sunat_client_secret, sunat_modo, serie_factura, serie_boleta
            ) VALUES (
                $school_id, '$ruc', '$razon_social', '$nombre_comercial', '$direccion', '$ubigeo',
                '$urbanizacion', '$provincia', '$departamento', '$distrito', '$telefono', '$email',
                '$website', '$certificado_path', '$certificado_password_encrypted', '$sunat_usuario',
                '$sunat_password_encrypted', '$sunat_client_id', '$sunat_client_secret_encrypted', '$sunat_modo', '$serie_factura', '$serie_boleta'
            )";
    }
    
    if ($conn->query($sql)) {
        echo json_encode(['status' => 1, 'message' => 'Configuración guardada exitosamente.']);
    } else {
        echo json_encode(['status' => 0, 'message' => 'Error al guardar la configuración: ' . $conn->error]);
    }
    exit;
}

// Probar conexión a SUNAT
if ($action == "test_sunat_connection") {
    header('Content-Type: application/json');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$school_id) {
        echo json_encode(['status' => 0, 'message' => 'No se ha establecido el colegio.']);
        exit;
    }
    
    try {
        require_once 'includes/FacturacionElectronica.php';
        $fac = new FacturacionElectronica($conn, $school_id);
        
        $result = $fac->testConnection();
        
        if ($result['success']) {
            echo json_encode(['status' => 1, 'message' => 'Conexión a SUNAT exitosa (' . $result['env'] . ').']);
        } else {
            echo json_encode(['status' => 0, 'message' => 'Fallo en la conexión: ' . $result['error']]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 0, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Generar boleta de venta
if ($action == "generar_boleta") {
    header('Content-Type: application/json');
    $payment_id = intval($_POST['payment_id'] ?? 0);
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$payment_id || !$school_id) {
        echo json_encode(['status' => 0, 'message' => 'Parámetros inválidos.']);
        exit;
    }
    
    try {
        require_once 'includes/FacturacionElectronica.php';
        $fac = new FacturacionElectronica($conn, $school_id);
        
        $result = $fac->generarBoleta($payment_id);
        
        if ($result['success']) {
            echo json_encode([
                'status' => 1,
                'message' => 'Boleta generada exitosamente.',
                'comprobante_id' => $result['comprobante_id'],
                'numero' => $result['numero'],
                'estado_sunat' => $result['estado_sunat']
            ]);
        } else {
            echo json_encode(['status' => 0, 'message' => $result['message']]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 0, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Generar factura
if ($action == "generar_factura") {
    header('Content-Type: application/json');
    $payment_id = intval($_POST['payment_id'] ?? 0);
    $ruc = $conn->real_escape_string($_POST['ruc'] ?? '');
    $razon_social = $conn->real_escape_string($_POST['razon_social'] ?? '');
    $direccion = $conn->real_escape_string($_POST['direccion'] ?? '');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$payment_id || !$ruc || !$razon_social || !$school_id) {
        echo json_encode(['status' => 0, 'message' => 'Todos los campos son obligatorios.']);
        exit;
    }
    
    // Validar RUC (11 dígitos)
    if (!preg_match('/^\d{11}$/', $ruc)) {
        echo json_encode(['status' => 0, 'message' => 'El RUC debe tener 11 dígitos.']);
        exit;
    }
    
    try {
        require_once 'includes/FacturacionElectronica.php';
        $fac = new FacturacionElectronica($conn, $school_id);
        
        $result = $fac->generarFactura($payment_id, $ruc, $razon_social, $direccion);
        
        if ($result['success']) {
            echo json_encode([
                'status' => 1,
                'message' => 'Factura generada exitosamente.',
                'comprobante_id' => $result['comprobante_id'],
                'numero' => $result['numero'],
                'estado_sunat' => $result['estado_sunat']
            ]);
        } else {
            echo json_encode(['status' => 0, 'message' => $result['message']]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 0, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Generar boleta desde deuda (student_ef_list)
if ($action == "generar_boleta_deuda") {
    header('Content-Type: application/json');
    $ef_id = intval($_POST['ef_id'] ?? $_POST['payment_id'] ?? 0); // Acepta ef_id o payment_id por compatibilidad
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$ef_id || !$school_id) {
        echo json_encode(['status' => 0, 'message' => 'Parámetros inválidos.', 'validation_error' => true]);
        exit;
    }
    
    // Validar que el estudiante tenga tutor registrado ANTES de llamar al método
    $validation = $conn->query("
        SELECT s.tutor1_nombre, s.tutor1_apellido, s.tutor1_dni 
        FROM student_ef_list ef
        INNER JOIN student s ON s.id = ef.student_id 
        WHERE ef.id = {$ef_id} AND s.school_id = {$school_id}
        LIMIT 1
    ");
    
    if (!$validation || $validation->num_rows == 0) {
        echo json_encode(['status' => 0, 'message' => 'Deuda no encontrada.', 'validation_error' => true]);
        exit;
    }
    
    $student = $validation->fetch_assoc();
    if (empty($student['tutor1_dni']) || empty($student['tutor1_nombre'])) {
        echo json_encode(['status' => 0, 'message' => 'El estudiante no tiene un apoderado registrado. Por favor, registre los datos del tutor/apoderado antes de generar la boleta.', 'validation_error' => true]);
        exit;
    }
    
    try {
        require_once 'includes/FacturacionElectronica.php';
        $fac = new FacturacionElectronica($conn, $school_id);
        
        $result = $fac->generarBoletaDeuda($ef_id);
        
        if ($result['success']) {
            echo json_encode([
                'status' => 1,
                'message' => 'Boleta generada exitosamente.',
                'comprobante_id' => $result['comprobante_id'],
                'numero' => $result['numero'],
                'estado_sunat' => $result['estado_sunat']
            ]);
        } else {
            echo json_encode(['status' => 0, 'message' => $result['message']]);
        }
    } catch (Throwable $e) {
        $errorMsg = get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
        error_log('ERROR generar_boleta_deuda: ' . $errorMsg);
        echo json_encode(['status' => 0, 'message' => 'Error interno: ' . $e->getMessage()]);
    }
    exit;
}

// Generar factura desde deuda (student_ef_list)
if ($action == "generar_factura_deuda") {
    header('Content-Type: application/json; charset=utf-8');
    
    // Debugging
    error_log('=== INICIO GENERAR_FACTURA_DEUDA ===');
    error_log('POST DATA: ' . print_r($_POST, true));
    
    $ef_id = intval($_POST['ef_id'] ?? $_POST['payment_id'] ?? 0);
    $ruc = $conn->real_escape_string($_POST['ruc'] ?? '');
    $razon_social = $conn->real_escape_string($_POST['razon_social'] ?? '');
    $direccion = $conn->real_escape_string($_POST['direccion'] ?? '');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    error_log("ef_id: $ef_id, ruc: $ruc, school_id: $school_id");
    
    if (!$ef_id || !$ruc || !$razon_social || !$school_id) {
        $msg = "Parámetros faltantes: ef_id=$ef_id, ruc=$ruc, razon_social=$razon_social, school_id=$school_id";
        error_log($msg);
        echo json_encode(['status' => 0, 'message' => $msg, 'validation_error' => true]);
        exit;
    }
    
    // Validar RUC (11 dígitos)
    if (!preg_match('/^\d{11}$/', $ruc)) {
        echo json_encode(['status' => 0, 'message' => 'El RUC debe tener 11 dígitos.', 'validation_error' => true]);
        exit;
    }
    
    // Validar que el estudiante tenga tutor registrado
    $validation = $conn->query("
        SELECT s.tutor1_nombre, s.tutor1_apellido, s.tutor1_dni 
        FROM student_ef_list ef
        INNER JOIN student s ON s.id = ef.student_id 
        WHERE ef.id = {$ef_id} AND s.school_id = {$school_id}
        LIMIT 1
    ");
    
    if (!$validation || $validation->num_rows == 0) {
        echo json_encode(['status' => 0, 'message' => 'Deuda no encontrada.', 'validation_error' => true]);
        exit;
    }
    
    $student = $validation->fetch_assoc();
    if (empty($student['tutor1_dni']) || empty($student['tutor1_nombre'])) {
        echo json_encode(['status' => 0, 'message' => 'El estudiante no tiene un apoderado registrado. Por favor, registre los datos del tutor/apoderado antes de generar la factura.', 'validation_error' => true]);
        exit;
    }
    
    try {
        error_log('Intentando cargar FacturacionElectronica.php...');
        
        if (!file_exists('includes/FacturacionElectronica.php')) {
            throw new Exception('Archivo FacturacionElectronica.php no encontrado');
        }
        
        require_once 'includes/FacturacionElectronica.php';
        error_log('FacturacionElectronica.php cargado exitosamente');
        
        $fac = new FacturacionElectronica($conn, $school_id);
        error_log('Objeto FacturacionElectronica creado');
        
        $result = $fac->generarFacturaDeuda($ef_id, $ruc, $razon_social, $direccion);
        error_log('generarFacturaDeuda completado. Resultado: ' . print_r($result, true));
        
        if ($result['success']) {
            echo json_encode([
                'status' => 1,
                'message' => 'Factura generada exitosamente.',
                'comprobante_id' => $result['comprobante_id'],
                'numero' => $result['numero'],
                'estado_sunat' => $result['estado_sunat']
            ]);
        } else {
            echo json_encode(['status' => 0, 'message' => $result['message']]);
        }
    } catch (Exception $e) {
        error_log('EXCEPCIÓN: ' . $e->getMessage());
        error_log('TRACE: ' . $e->getTraceAsString());
        echo json_encode(['status' => 0, 'message' => 'Error: ' . $e->getMessage()]);
    }
    error_log('=== FIN GENERAR_FACTURA_DEUDA ===');
    exit;
}

// Listar comprobantes electrónicos
if ($action == "listar_comprobantes") {
    header('Content-Type: application/json');
    
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        
        if (!$school_id) {
            echo json_encode(['status' => 0, 'message' => 'Sesión inválida']);
            exit;
        }
        
        $fecha_inicio = $_POST['fecha_inicio'] ?? '';
        $fecha_fin = $_POST['fecha_fin'] ?? '';
        $tipo = $_POST['tipo'] ?? '';
        $estado = $_POST['estado'] ?? '';
        
        $where = ["c.school_id = $school_id"];
        
        if ($fecha_inicio) {
            $where[] = "c.fecha_emision >= '$fecha_inicio'";
        }
        if ($fecha_fin) {
            $where[] = "c.fecha_emision <= '$fecha_fin'";
        }
        if ($tipo) {
            $where[] = "c.tipo_comprobante = '$tipo'";
        }
        if ($estado) {
            $where[] = "c.estado_sunat = '$estado'";
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = "SELECT c.*, s.name as student_name, s.id_no as student_dni 
                  FROM comprobantes_electronicos c 
                  LEFT JOIN student s ON c.student_id = s.id 
                  WHERE $where_clause 
                  ORDER BY c.created_at DESC";
        
        $result = $conn->query($query);
        
        if (!$result) {
            echo json_encode(['status' => 0, 'message' => 'Error SQL: ' . $conn->error]);
            exit;
        }
        
        $comprobantes = [];
        while ($row = $result->fetch_assoc()) {
            $comprobantes[] = $row;
        }
        
        echo json_encode(['status' => 1, 'comprobantes' => $comprobantes]);
        
    } catch (Exception $e) {
        echo json_encode(['status' => 0, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Descargar XML del comprobante
if ($action == "descargar_xml") {
    $comprobante_id = intval($_GET['comprobante_id'] ?? 0);
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$comprobante_id) {
        die('ID de comprobante inválido');
    }
    
    $query = $conn->query("SELECT numero_completo, xml_content FROM comprobantes_electronicos 
                          WHERE id = $comprobante_id AND school_id = $school_id");
    
    if ($query && $query->num_rows > 0) {
        $comp = $query->fetch_assoc();
        
        // Limpiar buffer de salida
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="' . $comp['numero_completo'] . '.xml"');
        header('Content-Length: ' . strlen($comp['xml_content']));
        echo $comp['xml_content'];
        flush();
    } else {
        die('Comprobante no encontrado');
    }
    exit;
}

// Descargar CDR (Constancia de recepción de SUNAT)
if ($action == "descargar_cdr") {
    $comprobante_id = intval($_GET['comprobante_id'] ?? 0);
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$comprobante_id) {
        die('ID de comprobante inválido');
    }
    
    $query = $conn->query("SELECT numero_completo, cdr_content FROM comprobantes_electronicos 
                          WHERE id = $comprobante_id AND school_id = $school_id");
    
    if ($query && $query->num_rows > 0) {
        $comp = $query->fetch_assoc();
        if ($comp['cdr_content']) {
            // El CDR está almacenado en base64, decodificar antes de enviar
            $cdr_zip = base64_decode($comp['cdr_content']);
            
            // Limpiar TODOS los niveles de buffer de salida
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="R-' . $comp['numero_completo'] . '.zip"');
            header('Content-Length: ' . strlen($cdr_zip));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            echo $cdr_zip;
            flush();
        } else {
            die('CDR no disponible');
        }
    } else {
        die('Comprobante no encontrado');
    }
    exit;
}

// Descargar PDF del comprobante
if ($action == "descargar_pdf") {
    $comprobante_id = intval($_GET['comprobante_id'] ?? 0);
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$comprobante_id || !$school_id) {
        die('ID de comprobante inválido o sesión expirada');
    }
    
    try {
        require_once 'includes/FacturacionElectronica.php';
        $fac = new FacturacionElectronica($conn, $school_id);
        
        // Obtener número del comprobante para el nombre del archivo
        $query = $conn->query("SELECT numero_completo FROM comprobantes_electronicos 
                              WHERE id = $comprobante_id AND school_id = $school_id");
        
        if (!$query || $query->num_rows == 0) {
            die('Comprobante no encontrado');
        }
        
        $comp = $query->fetch_assoc();
        
        // Generar PDF
        $pdf_content = $fac->generarPDF($comprobante_id);
        
        // Limpiar buffer
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Enviar PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $comp['numero_completo'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf_content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        echo $pdf_content;
        flush();
        
    } catch (Exception $e) {
        die('Error al generar PDF: ' . $e->getMessage());
    }
    exit;
}

// ============================================================================
// EMISIÓN MASIVA DE BOLETAS
// ============================================================================

/**
 * Listar estudiantes activos de la escuela
 */
if ($action === 'listar_estudiantes_activos') {
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    $query = $conn->query(
        "SELECT id, name, id_no 
         FROM student 
         ORDER BY name ASC"
    );
    
    $estudiantes = [];
    if ($query) {
        while ($row = $query->fetch_assoc()) {
            $estudiantes[] = $row;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($estudiantes);
    exit;
}

/**
 * Generar boletas masivas
 */
if ($action === 'generar_boletas_masivas') {
    $periodo = $conn->real_escape_string($_POST['periodo']);
    $concepto = $conn->real_escape_string($_POST['concepto']);
    $monto = floatval($_POST['monto']);
    $estudiantes = $_POST['estudiantes'] ?? [];
    
    if (empty($estudiantes) || !$periodo || !$concepto || $monto <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Datos incompletos o inválidos'
        ]);
        exit;
    }
    
    require_once __DIR__ . '/includes/FacturacionElectronica.php';
    
    $aceptadas = 0;
    $rechazadas = 0;
    $resultados = [];
    
    foreach ($estudiantes as $student_id) {
        try {
            $fac = new FacturacionElectronica($conn, $school_id);
            $result = $fac->generarBoletaMasiva($student_id, $concepto, $monto, $periodo);
            
            if ($result['success']) {
                $aceptadas++;
            } else {
                $rechazadas++;
            }
            
            $resultados[] = $result;
            
        } catch (Exception $e) {
            $rechazadas++;
            $resultados[] = [
                'success' => false,
                'student_id' => $student_id,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'total' => count($estudiantes),
        'aceptadas' => $aceptadas,
        'rechazadas' => $rechazadas,
        'resultados' => $resultados
    ]);
    exit;
}

/**
 * Listar últimas boletas masivas generadas
 */
if ($action === 'listar_ultimas_boletas_masivas') {
    $query = $conn->query(
        "SELECT bm.id, bm.numero_completo, bm.estado_sunat, 
                bm.concepto, bm.monto, bm.periodo, 
                s.nombre_estudiante, bm.fecha_emision
         FROM boletas_masivas bm
         LEFT JOIN students s ON s.id = bm.student_id
         WHERE bm.school_id = {$school_id}
         ORDER BY bm.fecha_emision DESC
         LIMIT 10"
    );
    
    $boletas = [];
    while ($row = $query->fetch_assoc()) {
        $boletas[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'boletas' => $boletas
    ]);
    exit;
}

/**
 * Listar deudas para facturación (basado en student_ef_list)
 */
if ($action === 'listar_pagos_facturacion') {
    $school_id = $_SESSION['login_school_id'] ?? 0;
    $estado = $_GET['estado'] ?? '';
    $fecha_desde = $_GET['fecha_desde'] ?? '';
    $fecha_hasta = $_GET['fecha_hasta'] ?? '';
    
    // Verificar si la columna comprobante_id existe en student_ef_list
    $check_column = $conn->query("SHOW COLUMNS FROM student_ef_list LIKE 'comprobante_id'");
    $has_comprobante = $check_column && $check_column->num_rows > 0;
    
    $where = "WHERE s.school_id = {$school_id}";
    
    // No filtrar por balance, mostrar todas las deudas
    // La emisión de boletas es independiente del estado de pago
    
    if ($has_comprobante) {
        if ($estado == 'sin_comprobante') {
            $where .= " AND ef.comprobante_id IS NULL";
        } elseif ($estado == 'con_comprobante') {
            $where .= " AND ef.comprobante_id IS NOT NULL";
        }
    }
    
    if ($fecha_desde) {
        $where .= " AND DATE(ef.date_created) >= '{$fecha_desde}'";
    }
    if ($fecha_hasta) {
        $where .= " AND DATE(ef.date_created) <= '{$fecha_hasta}'";
    }
    
    $comprobante_join = $has_comprobante ? "LEFT JOIN comprobantes_electronicos ce ON ce.id = ef.comprobante_id" : "";
    $comprobante_fields = $has_comprobante ? "ef.comprobante_id, ce.numero_completo, ce.tipo_comprobante, ce.estado_sunat," : "NULL as comprobante_id, NULL as numero_completo, NULL as tipo_comprobante, NULL as estado_sunat,";
    
    $query = $conn->query(
        "SELECT ef.id, 
                ef.total_fee,
                ef.discounted_amount,
                ef.date_created,
                {$comprobante_fields}
                s.name as nombre_estudiante, 
                s.id_no as dni,
                c.course, c.level, s.grado,
                ay.year,
                CONCAT(c.course, ' - ', c.level, ' (', s.grado, ')') as concepto,
                DATE_FORMAT(ef.date_created, '%d/%m/%Y') as fecha_deuda,
                CONCAT('DEU-', LPAD(ef.id, 6, '0')) as codigo_deuda,
                COALESCE(SUM(p.amount), 0) as paid,
                (COALESCE(ef.discounted_amount, ef.total_fee)) as amount_total,
                (COALESCE(ef.discounted_amount, ef.total_fee) - COALESCE(SUM(p.amount), 0)) as balance
         FROM student_ef_list ef
         INNER JOIN student s ON s.id = ef.student_id
         INNER JOIN courses c ON c.id = ef.course_id
         LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
         LEFT JOIN payments p ON p.ef_id = ef.id
         {$comprobante_join}
         {$where}
         GROUP BY ef.id
         ORDER BY ef.date_created DESC
         LIMIT 100"
    );
    
    $deudas = [];
    if ($query) {
        while ($row = $query->fetch_assoc()) {
            $row['amount'] = $row['amount_total']; // Usar el total, no el balance
            $row['receipt_no'] = $row['codigo_deuda'];
            $deudas[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'pagos' => $deudas,
        'has_comprobante_field' => $has_comprobante
    ]);
    exit;
}

// ========== NUEVOS ENDPOINTS PARA FACTURACIÓN DE DEUDAS ==========

// ==== LISTAR DEUDAS PARA FACTURACIÓN ====
if ($action === 'listar_deudas_facturacion') {
    header('Content-Type: application/json; charset=utf-8');
    $school_id = $_SESSION['login_school_id'] ?? 0;
    $estado = $_GET['estado'] ?? '';
    $fecha_desde = $_GET['fecha_desde'] ?? '';
    $fecha_hasta = $_GET['fecha_hasta'] ?? '';
    
    if (!$school_id) {
        echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
        exit;
    }
    
    // Verificar si comprobante_id existe en student_ef_list
    $check_column = $conn->query("SHOW COLUMNS FROM student_ef_list LIKE 'comprobante_id'");
    $has_comprobante = $check_column && $check_column->num_rows > 0;
    
    $where = "WHERE s.school_id = {$school_id}";
    
    if ($has_comprobante) {
        if ($estado == 'sin_comprobante') {
            $where .= " AND ef.comprobante_id IS NULL";
        } elseif ($estado == 'con_comprobante') {
            $where .= " AND ef.comprobante_id IS NOT NULL";
        }
    }
    
    if ($fecha_desde) {
        $where .= " AND DATE(ef.date_created) >= '{$fecha_desde}'";
    }
    if ($fecha_hasta) {
        $where .= " AND DATE(ef.date_created) <= '{$fecha_hasta}'";
    }
    
    $comprobante_join = $has_comprobante ? "LEFT JOIN comprobantes_electronicos ce ON ce.id = ef.comprobante_id" : "";
    $comprobante_fields = $has_comprobante ? "
        ef.comprobante_id, 
        ce.numero_completo, 
        ce.tipo_comprobante, 
        ce.estado_sunat," : "
        NULL as comprobante_id, 
        NULL as numero_completo, 
        NULL as tipo_comprobante, 
        NULL as estado_sunat,";
    
    $query = $conn->query("
        SELECT 
            ef.id as ef_id,
            ef.total_fee,
            ef.discounted_amount,
            ef.date_created,
            {$comprobante_fields}
            s.name as student_name, 
            s.id_no as student_dni,
            s.tutor1_dni,
            s.tutor1_nombre,
            s.tutor1_apellido,
            CONCAT(s.tutor1_nombre, ' ', s.tutor1_apellido) as tutor_nombre_completo,
            c.course, 
            c.level, 
            s.grado,
            ay.year,
            CONCAT(c.course, ' - ', c.level, ' (', s.grado, ')') as concepto,
            DATE_FORMAT(ef.date_created, '%d/%m/%Y') as fecha_deuda,
            COALESCE(ef.discounted_amount, ef.total_fee) as amount
        FROM student_ef_list ef
        INNER JOIN student s ON s.id = ef.student_id
        INNER JOIN courses c ON c.id = ef.course_id
        LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
        {$comprobante_join}
        {$where}
        ORDER BY ef.date_created DESC
    ");
    
    $deudas = [];
    if ($query) {
        while ($row = $query->fetch_assoc()) {
            $deudas[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'deudas' => $deudas,
        'has_comprobante_field' => $has_comprobante
    ]);
    exit;
}

// ==== OBTENER DATOS DEL TUTOR ====
if ($action === 'obtener_datos_tutor') {
    header('Content-Type: application/json; charset=utf-8');
    $ef_id = intval($_GET['ef_id'] ?? 0);
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$ef_id || !$school_id) {
        echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
        exit;
    }
    
    $query = $conn->query("
        SELECT 
            s.tutor1_nombre,
            s.tutor1_apellido,
            s.tutor1_dni,
            s.tutor1_direccion,
            s.name as student_name
        FROM student_ef_list ef
        INNER JOIN student s ON s.id = ef.student_id
        WHERE ef.id = {$ef_id} AND s.school_id = {$school_id}
        LIMIT 1
    ");
    
    if (!$query || $query->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Deuda no encontrada']);
        exit;
    }
    
    $student = $query->fetch_assoc();
    
    if (empty($student['tutor1_dni']) || empty($student['tutor1_nombre'])) {
        echo json_encode([
            'success' => false, 
            'message' => 'El estudiante no tiene datos completos del tutor. Por favor, actualice la información del apoderado.'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'student_name' => $student['student_name'],
        'tutor_nombre' => trim($student['tutor1_nombre'] . ' ' . $student['tutor1_apellido']),
        'tutor_dni' => $student['tutor1_dni'],
        'tutor_direccion' => $student['tutor1_direccion'] ?? 'No registrada'
    ]);
    exit;
}

// ==== GENERAR BOLETA DESDE DEUDA (INAFECTO) ====
if ($action === 'generar_boleta_deuda') {
    header('Content-Type: application/json; charset=utf-8');
    
    $ef_id = intval($_POST['ef_id'] ?? 0);
    $monto_custom = isset($_POST['monto']) ? floatval($_POST['monto']) : null;
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    // Datos opcionales del receptor (si es diferente al tutor)
    $receptor_dni = trim($_POST['receptor_dni'] ?? '');
    $receptor_nombre = trim($_POST['receptor_nombre'] ?? '');
    $receptor_direccion = trim($_POST['receptor_direccion'] ?? '');
    
    if (!$ef_id || !$school_id) {
        echo json_encode(['status' => 0, 'message' => 'Parámetros inválidos', 'validation_error' => true]);
        exit;
    }
    
    // Validar datos del receptor alternativo si se proporcionan
    if (!empty($receptor_dni) || !empty($receptor_nombre)) {
        if (!preg_match('/^\d{8}$/', $receptor_dni)) {
            echo json_encode(['status' => 0, 'message' => 'El DNI del receptor debe tener 8 dígitos', 'validation_error' => true]);
            exit;
        }
        if (empty($receptor_nombre) || strlen($receptor_nombre) < 3) {
            echo json_encode(['status' => 0, 'message' => 'El nombre del receptor es inválido', 'validation_error' => true]);
            exit;
        }
    }
    
    // Si NO se proporciona receptor alternativo, validar que tenga tutor
    if (empty($receptor_dni)) {
        $validation = $conn->query("
            SELECT s.tutor1_dni, s.tutor1_nombre 
            FROM student_ef_list ef
            INNER JOIN student s ON s.id = ef.student_id 
            WHERE ef.id = {$ef_id} AND s.school_id = {$school_id}
            LIMIT 1
        ");
        
        if (!$validation || $validation->num_rows == 0) {
            echo json_encode(['status' => 0, 'message' => 'Deuda no encontrada', 'validation_error' => true]);
            exit;
        }
        
        $student = $validation->fetch_assoc();
        if (empty($student['tutor1_dni']) || empty($student['tutor1_nombre'])) {
            echo json_encode([
                'status' => 0, 
                'message' => 'El estudiante no tiene apoderado registrado. Use la opción "Emitir a otro receptor".',
                'validation_error' => true
            ]);
            exit;
        }
    }
    
    try {
        require_once 'includes/FacturacionElectronica.php';
        $fac = new FacturacionElectronica($conn, $school_id);
        
        // Si hay receptor alternativo, usar método específico
        if (!empty($receptor_dni) && !empty($receptor_nombre)) {
            $result = $fac->generarBoletaDeudaConReceptor($ef_id, $receptor_dni, $receptor_nombre, $receptor_direccion, $monto_custom);
        } else {
            $result = $fac->generarBoletaDeuda($ef_id, $monto_custom);
        }
        
        if ($result['success']) {
            echo json_encode([
                'status' => 1,
                'message' => 'Boleta generada exitosamente',
                'numero' => $result['numero'],
                'comprobante_id' => $result['comprobante_id']
            ]);
        } else {
            echo json_encode(['status' => 0, 'message' => $result['message']]);
        }
    } catch (Throwable $e) {
        error_log('Error en generar_boleta_deuda: ' . $e->getMessage());
        echo json_encode(['status' => 0, 'message' => 'Error Interno: ' . $e->getMessage()]);
    }
    exit;
}

// ==== GENERAR FACTURA DESDE DEUDA (INAFECTO) ====
if ($action === 'generar_factura_deuda') {
    header('Content-Type: application/json; charset=utf-8');
    
    $ef_id = intval($_POST['ef_id'] ?? 0);
    $ruc = $conn->real_escape_string($_POST['ruc'] ?? '');
    $razon_social = $conn->real_escape_string($_POST['razon_social'] ?? '');
    $direccion = $conn->real_escape_string($_POST['direccion'] ?? '');
    $monto_custom = isset($_POST['monto']) ? floatval($_POST['monto']) : null;
    $school_id = $_SESSION['login_school_id'] ?? 0;
    
    if (!$ef_id || !$ruc || !$razon_social || !$school_id) {
        echo json_encode(['status' => 0, 'message' => 'Todos los campos son obligatorios', 'validation_error' => true]);
        exit;
    }
    
    if (!preg_match('/^\d{11}$/', $ruc)) {
        echo json_encode(['status' => 0, 'message' => 'El RUC debe tener 11 dígitos', 'validation_error' => true]);
        exit;
    }
    
    // Validar que tenga tutor
    $validation = $conn->query("
        SELECT s.tutor1_dni, s.tutor1_nombre 
        FROM student_ef_list ef
        INNER JOIN student s ON s.id = ef.student_id 
        WHERE ef.id = {$ef_id} AND s.school_id = {$school_id}
        LIMIT 1
    ");
    
    if (!$validation || $validation->num_rows == 0) {
        echo json_encode(['status' => 0, 'message' => 'Deuda no encontrada', 'validation_error' => true]);
        exit;
    }
    
    $student = $validation->fetch_assoc();
    if (empty($student['tutor1_dni']) || empty($student['tutor1_nombre'])) {
        echo json_encode([
            'status' => 0, 
            'message' => 'El estudiante no tiene apoderado registrado',
            'validation_error' => true
        ]);
        exit;
    }
    
    try {
        require_once 'includes/FacturacionElectronica.php';
        $fac = new FacturacionElectronica($conn, $school_id);
        $result = $fac->generarFacturaDeuda($ef_id, $ruc, $razon_social, $direccion, $monto_custom);
        
        if ($result['success']) {
            echo json_encode([
                'status' => 1,
                'message' => 'Factura generada exitosamente',
                'numero' => $result['numero'],
                'comprobante_id' => $result['comprobante_id']
            ]);
        } else {
            echo json_encode(['status' => 0, 'message' => $result['message']]);
        }
    } catch (Throwable $e) {
        error_log('Error en generar_factura_deuda: ' . $e->getMessage());
        echo json_encode(['status' => 0, 'message' => 'Error Interno: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// ENDPOINTS PARA GENERACIÓN MASIVA DE BOLETAS
// ============================================

if ($action === 'obtener_anios_academicos') {
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        
        if (!$school_id) {
            echo json_encode(['success' => false, 'message' => 'No hay sesión activa']);
            exit;
        }
        
        $query = $conn->query("
            SELECT id, year, description, is_active
            FROM academic_year
            WHERE school_id = {$school_id}
            ORDER BY is_active DESC, year DESC
        ");
        
        if (!$query) {
            echo json_encode(['success' => false, 'message' => 'Error en consulta: ' . $conn->error]);
            exit;
        }
        
        $anios = [];
        while ($row = $query->fetch_assoc()) {
            $anios[] = [
                'id' => $row['id'],
                'year' => $row['year'],
                'description' => $row['description'],
                'is_active' => $row['is_active']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'anios' => $anios
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'obtener_niveles') {
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        
        if (!$school_id) {
            echo json_encode(['success' => false, 'message' => 'No hay sesión activa']);
            exit;
        }
        
        $query = $conn->query("
            SELECT DISTINCT c.level 
            FROM courses c
            INNER JOIN academic_year ay ON c.academic_year_id = ay.id
            WHERE ay.school_id = {$school_id} 
            AND c.level IS NOT NULL 
            AND c.level != ''
            ORDER BY c.level
        ");
        
        if (!$query) {
            echo json_encode(['success' => false, 'message' => 'Error en consulta: ' . $conn->error]);
            exit;
        }
        
        $niveles = [];
        while ($row = $query->fetch_assoc()) {
            $niveles[] = $row['level'];
        }
        
        echo json_encode([
            'success' => true,
            'niveles' => $niveles
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'obtener_grados') {
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        
        if (!$school_id) {
            echo json_encode(['success' => false, 'message' => 'No hay sesión activa']);
            exit;
        }
        
        $query = $conn->query("
            SELECT DISTINCT grado 
            FROM student
            WHERE school_id = {$school_id} 
            AND grado IS NOT NULL 
            AND grado != ''
            ORDER BY grado
        ");
        
        if (!$query) {
            echo json_encode(['success' => false, 'message' => 'Error en consulta: ' . $conn->error]);
            exit;
        }
        
        $grados = [];
        while ($row = $query->fetch_assoc()) {
            $grados[] = $row['grado'];
        }
        
        echo json_encode([
            'success' => true,
            'grados' => $grados
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'obtener_conceptos') {
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        
        if (!$school_id) {
            echo json_encode(['success' => false, 'message' => 'No hay sesión activa']);
            exit;
        }
        
        $academic_year_id = $_GET['academic_year_id'] ?? '';
        $nivel = $_GET['nivel'] ?? '';
        $grado = $_GET['grado'] ?? '';
        
        $where = ["ay.school_id = {$school_id}"];
        
        if (!empty($academic_year_id)) {
            $academic_year_id_int = (int)$academic_year_id;
            $where[] = "c.academic_year_id = {$academic_year_id_int}";
        }
        
        if (!empty($nivel)) {
            $nivel_escaped = $conn->real_escape_string($nivel);
            $where[] = "c.level = '{$nivel_escaped}'";
        }
        
        if (!empty($grado)) {
            $grado_escaped = $conn->real_escape_string($grado);
            $where[] = "FIND_IN_SET('{$grado_escaped}', c.grades) > 0";
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = $conn->query("
            SELECT DISTINCT c.id, c.course as nombre, c.level, c.grades
            FROM courses c
            INNER JOIN academic_year ay ON c.academic_year_id = ay.id
            WHERE {$where_clause}
            ORDER BY c.course
        ");
        
        if (!$query) {
            echo json_encode(['success' => false, 'message' => 'Error en consulta: ' . $conn->error]);
            exit;
        }
        
        $conceptos = [];
        while ($row = $query->fetch_assoc()) {
            $conceptos[] = [
                'id' => $row['id'],
                'nombre' => $row['nombre']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'conceptos' => $conceptos
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'listar_deudas_masivas') {
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        
        if (!$school_id) {
            echo json_encode(['success' => false, 'message' => 'No hay sesión activa']);
            exit;
        }
        
        $nivel = $_GET['nivel'] ?? '';
        $grado = $_GET['grado'] ?? '';
        $concepto = $_GET['concepto'] ?? '';
        
        // Construir WHERE dinámico
        $where = ["s.school_id = {$school_id}"];
        $where[] = "ef.comprobante_id IS NULL"; // Solo deudas sin comprobante generado
        
        if (!empty($nivel)) {
            $nivel_escaped = $conn->real_escape_string($nivel);
            $where[] = "s.nivel = '{$nivel_escaped}'";
        }
        
        if (!empty($grado)) {
            $grado_escaped = $conn->real_escape_string($grado);
            $where[] = "s.grado = '{$grado_escaped}'";
        }
        
        if (!empty($concepto)) {
            $concepto_int = (int)$concepto;
            $where[] = "c.id = {$concepto_int}";
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = $conn->query("
            SELECT 
                ef.id as ef_id,
                ef.comprobante_id,
                COALESCE(ef.discounted_amount, ef.total_fee) as amount,
                s.id as student_id,
                s.name as student_name,
                s.id_no as student_dni,
                s.tutor1_dni as tutor1_dni,
                CONCAT(s.tutor1_nombre, ' ', s.tutor1_apellido) as tutor1_nombre,
                s.tutor1_direccion,
                s.tutor2_dni as tutor2_dni,
                CONCAT(s.tutor2_nombre, ' ', s.tutor2_apellido) as tutor2_nombre,
                s.tutor2_direccion,
                c.course as concepto,
                c.level,
                s.grado
            FROM student_ef_list ef
            INNER JOIN student s ON s.id = ef.student_id
            INNER JOIN courses c ON c.id = ef.course_id
            WHERE {$where_clause}
            ORDER BY s.name, c.course
        ");
        
        if (!$query) {
            echo json_encode(['success' => false, 'message' => 'Error en consulta: ' . $conn->error]);
            exit;
        }
        
        $deudas = [];
        while ($row = $query->fetch_assoc()) {
            $deudas[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'deudas' => $deudas,
            'total' => count($deudas)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ENDPOINTS PARA COMPROBANTES (HISTORIAL Y REPORTES)
// ============================================

/**
 * Listar comprobantes con filtros avanzados
 * GET: ajax.php?action=listar_comprobantes_historial&estudiante=&tutor=&fecha_desde=&fecha_hasta=&tipo=&estado=
 */
if ($action === 'listar_comprobantes_historial') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        
        if (!$school_id) {
            echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
            exit;
        }
        
        // Obtener filtros
        $estudiante = trim($_GET['estudiante'] ?? '');
        $tutor = trim($_GET['tutor'] ?? '');
        $fecha_desde = trim($_GET['fecha_desde'] ?? '');
        $fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
        $tipo = trim($_GET['tipo'] ?? '');
        $estado = trim($_GET['estado'] ?? '');
        
        // Construir WHERE
        $where = ["ce.school_id = {$school_id}"];
        
        if (!empty($estudiante)) {
            $est = $conn->real_escape_string($estudiante);
            $where[] = "s.name LIKE '%{$est}%'";
        }
        
        if (!empty($tutor)) {
            $tut = $conn->real_escape_string($tutor);
            $where[] = "CONCAT(s.tutor1_nombre, ' ', s.tutor1_apellido) LIKE '%{$tut}%'";
        }
        
        if (!empty($fecha_desde)) {
            $fecha_desde = $conn->real_escape_string($fecha_desde);
            $where[] = "DATE(ce.fecha_emision) >= '{$fecha_desde}'";
        }
        
        if (!empty($fecha_hasta)) {
            $fecha_hasta = $conn->real_escape_string($fecha_hasta);
            $where[] = "DATE(ce.fecha_emision) <= '{$fecha_hasta}'";
        }
        
        if (!empty($tipo)) {
            $tipo = $conn->real_escape_string($tipo);
            $where[] = "ce.tipo_comprobante = '{$tipo}'";
        }
        
        if (!empty($estado)) {
            $estado = $conn->real_escape_string($estado);
            $where[] = "ce.estado_sunat = '{$estado}'";
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = $conn->query("
            SELECT 
                ce.id,
                ce.numero_completo,
                ce.tipo_comprobante,
                ce.fecha_emision,
                ce.total_precio_venta,
                ce.estado_sunat,
                ce.xml_content,
                ce.cdr_content,
                ce.cliente_tipo_doc,
                ce.cliente_num_doc,
                ce.cliente_razon_social,
                s.name as student_name,
                s.id_no as student_dni,
                CONCAT(s.tutor1_nombre, ' ', s.tutor1_apellido) as tutor_name
            FROM comprobantes_electronicos ce
            LEFT JOIN student s ON ce.student_id = s.id
            WHERE {$where_clause}
            ORDER BY ce.fecha_emision DESC
            LIMIT 500
        ");
        
        if (!$query) {
            echo json_encode(['success' => false, 'message' => 'Error en consulta: ' . $conn->error]);
            exit;
        }
        
        $comprobantes = [];
        while ($row = $query->fetch_assoc()) {
            $comprobantes[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'comprobantes' => $comprobantes
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/**
 * Reintentar envío a SUNAT para comprobantes rechazados
 * POST: ajax.php?action=reintentar_sunat
 */
if ($action === 'reintentar_sunat') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        $comprobante_id = intval($_POST['comprobante_id'] ?? 0);
        
        if (!$school_id || !$comprobante_id) {
            echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
            exit;
        }
        
        // Obtener datos del comprobante
        $comp_query = $conn->query("
            SELECT * FROM comprobantes_electronicos 
            WHERE id = {$comprobante_id} AND school_id = {$school_id}
        ");
        
        if (!$comp_query || $comp_query->num_rows == 0) {
            echo json_encode(['success' => false, 'message' => 'Comprobante no encontrado']);
            exit;
        }
        
        $comprobante = $comp_query->fetch_assoc();
        
        // Solo se pueden reintentar comprobantes rechazados o pendientes
        if (!in_array($comprobante['estado_sunat'], ['rechazado', 'pendiente'])) {
            echo json_encode(['success' => false, 'message' => 'Solo se pueden reintentar comprobantes rechazados o pendientes']);
            exit;
        }
        
        // Si el comprobante tiene XML, intentar reenviar
        if (empty($comprobante['xml_content'])) {
            echo json_encode(['success' => false, 'message' => 'No hay contenido XML para reintentar']);
            exit;
        }
        
        try {
            require_once 'includes/FacturacionElectronica.php';
            $fac = new FacturacionElectronica($conn, $school_id);
            
            // Reenviar el comprobante
            $result = $fac->reenviarComprobante($comprobante_id);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Reintento enviado. El estado se actualizará en breve.',
                    'nuevo_estado' => $result['estado_sunat'] ?? 'pendiente'
                ]);

            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $result['message'] ?? 'Error al reintentar el envío'
                ]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/**
 * Obtener log de auditoría de un comprobante
 * GET: ajax.php?action=obtener_auditoria&comprobante_id=123
 */
if ($action === 'obtener_auditoria') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        $comprobante_id = intval($_GET['comprobante_id'] ?? 0);
        
        if (!$school_id || !$comprobante_id) {
            echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
            exit;
        }
        
        // Crear tabla sunat_log si no existe
        $conn->query("CREATE TABLE IF NOT EXISTS `sunat_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `comprobante_id` INT NOT NULL,
            `operacion` VARCHAR(100),
            `estado` VARCHAR(50),
            `respuesta_sunat` LONGTEXT,
            `user_id` INT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `comprobante_id` (`comprobante_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Obtener logs
        $query = $conn->query("
            SELECT 
                id,
                comprobante_id,
                operacion,
                estado,
                created_at,
                user_id
            FROM sunat_log 
            WHERE comprobante_id = {$comprobante_id}
            ORDER BY created_at DESC
        ");
        
        if (!$query) {
            echo json_encode(['success' => false, 'message' => 'Error en consulta: ' . $conn->error]);
            exit;
        }
        
        $logs = [];
        while ($row = $query->fetch_assoc()) {
            $logs[] = [
                'id' => $row['id'],
                'operacion' => $row['operacion'],
                'estado' => $row['estado'],
                'created_at' => $row['created_at'],
                'user_id' => $row['user_id']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'logs' => $logs
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


/**
 * Exportar comprobantes a Excel
 * GET: ajax.php?action=exportar_comprobantes_excel&...filtros
 */
if ($action === 'exportar_comprobantes_excel') {
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        
        if (!$school_id) {
            die('Sesión inválida');
        }
        
        // Obtener filtros
        $estudiante = trim($_GET['estudiante'] ?? '');
        $tutor = trim($_GET['tutor'] ?? '');
        $fecha_desde = trim($_GET['fecha_desde'] ?? '');
        $fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
        $tipo = trim($_GET['tipo'] ?? '');
        $estado = trim($_GET['estado'] ?? '');
        
        // Construir WHERE
        $where = ["ce.school_id = {$school_id}"];
        
        if (!empty($estudiante)) {
            $est = $conn->real_escape_string($estudiante);
            $where[] = "s.name LIKE '%{$est}%'";
        }
        
        if (!empty($tutor)) {
            $tut = $conn->real_escape_string($tutor);
            $where[] = "CONCAT(s.tutor1_nombre, ' ', s.tutor1_apellido) LIKE '%{$tut}%'";
        }
        
        if (!empty($fecha_desde)) {
            $fecha_desde = $conn->real_escape_string($fecha_desde);
            $where[] = "DATE(ce.fecha_emision) >= '{$fecha_desde}'";
        }
        
        if (!empty($fecha_hasta)) {
            $fecha_hasta = $conn->real_escape_string($fecha_hasta);
            $where[] = "DATE(ce.fecha_emision) <= '{$fecha_hasta}'";
        }
        
        if (!empty($tipo)) {
            $tipo = $conn->real_escape_string($tipo);
            $where[] = "ce.tipo_comprobante = '{$tipo}'";
        }
        
        if (!empty($estado)) {
            $estado = $conn->real_escape_string($estado);
            $where[] = "ce.estado_sunat = '{$estado}'";
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = $conn->query("
            SELECT 
                ce.numero_completo as 'Número',
                ce.fecha_emision as 'Fecha',
                CASE ce.tipo_comprobante WHEN '01' THEN 'FACTURA' WHEN '03' THEN 'BOLETA' ELSE ce.tipo_comprobante END as 'Tipo',
                ce.cliente_razon_social as 'Cliente',
                CONCAT(ce.cliente_tipo_doc = '1' ? 'DNI' : 'RUC', ': ', ce.cliente_num_doc) as 'Documento',
                ce.total_precio_venta as 'Monto Total',
                CASE ce.estado_sunat WHEN 'aceptado' THEN 'ACEPTADO' WHEN 'rechazado' THEN 'RECHAZADO' ELSE 'PENDIENTE' END as 'Estado SUNAT',
                s.name as 'Estudiante',
                CONCAT(s.tutor1_nombre, ' ', s.tutor1_apellido) as 'Tutor'
            FROM comprobantes_electronicos ce
            LEFT JOIN student s ON ce.student_id = s.id
            WHERE {$where_clause}
            ORDER BY ce.fecha_emision DESC
        ");
        
        if (!$query) {
            die('Error en consulta: ' . $conn->error);
        }
        
        // Crear datos para Excel
        $data = [];
        while ($row = $query->fetch_assoc()) {
            $data[] = $row;
        }
        
        // Crear libro Excel con datos
        require_once __DIR__ . '/vendor/autoload.php';
        
        $spreadsheet = new \PhpOffice\lPhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Encabezados
        if (!empty($data)) {
            $col = 1;
            foreach (array_keys($data[0]) as $header) {
                $sheet->setCellValueByColumnAndRow($col, 1, $header);
                $col++;
            }
            
            // Datos
            $row = 2;
            foreach ($data as $Item) {
                $col = 1;
                foreach ($item as $value) {
                    $sheet->setCellValueByColumnAndRow($col, $row, $value);
                    $col++;
                }
                $row++;
            }
        }
        
        // Ajustar ancho de columnas
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
        
        // Generar archivo
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Comprobantes_' . date('YmdHis') . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        
    } catch (Exception $e) {
        die('Error: ' . $e->getMessage());
    }
    exit;
}

/**
 * Reintentar envío a SUNAT de un comprobante electrónico rechazado
 * POST: ajax.php?action=reintentar_sunat
 */
if ($action === 'reintentar_sunat') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        $comprobante_id = intval($_POST['comprobante_id'] ?? 0);
        
        if (!$school_id || !$comprobante_id) {
            echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
            exit;
        }
        
        // Obtener datos del comprobante
        $comp_query = $conn->query("
            SELECT * FROM comprobantes_electronicos 
            WHERE id = {$comprobante_id} AND school_id = {$school_id}
        ");
        
        if (!$comp_query || $comp_query->num_rows == 0) {
            echo json_encode(['success' => false, 'message' => 'Comprobante no encontrado']);
            exit;
        }
        
        $comprobante = $comp_query->fetch_assoc();
        
        // Solo se pueden reintentar comprobantes rechazados o pendientes
        if (!in_array($comprobante['estado_sunat'], ['rechazado', 'pendiente'])) {
            echo json_encode(['success' => false, 'message' => 'Solo se pueden reintentar comprobantes rechazados o pendientes']);
            exit;
        }
        
        try {
            require_once 'includes/FacturacionElectronica.php';
            $fac = new FacturacionElectronica($conn, $school_id);
            
            // Reenviar el comprobante
            $result = $fac->reenviarComprobante($comprobante_id);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Reintento enviado. El estado se actualizará en breve.',
                    'nuevo_estado' => $result['estado_sunat'] ?? 'pendiente'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $result['message'] ?? 'Error al reintentar el envío'
                ]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/**
 * Obtener log de auditoría de un comprobante
 * GET: ajax.php?action=obtener_auditoria&comprobante_id=123
 */
if ($action === 'obtener_auditoria') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        $comprobante_id = intval($_GET['comprobante_id'] ?? 0);
        
        if (!$school_id || !$comprobante_id) {
            echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
            exit;
        }
        
        $query = $conn->query("SELECT operacion, estado, created_at FROM comprobantes_auditoria WHERE comprobante_id = {$comprobante_id} ORDER BY created_at DESC");
        
        $logs = [];
        if ($query) {
            while ($row = $query->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        
        echo json_encode([
            'success' => true,
            'logs' => $logs
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'eliminar_comprobante_rechazado') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $school_id = $_SESSION['login_school_id'] ?? 0;
        $comprobante_id = intval($_POST['comprobante_id'] ?? 0);
        
        if (!$school_id || !$comprobante_id) {
            echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
            exit;
        }
        
        $comp_query = $conn->query("SELECT estado_sunat FROM comprobantes_electronicos WHERE id = {$comprobante_id} AND school_id = {$school_id}");
        if (!$comp_query || $comp_query->num_rows == 0) {
            echo json_encode(['success' => false, 'message' => 'Comprobante no encontrado']);
            exit;
        }
        
        $comprobante = $comp_query->fetch_assoc();
        if ($comprobante['estado_sunat'] === 'aceptado') {
            echo json_encode(['success' => false, 'message' => 'No se puede eliminar un comprobante aceptado']);
            exit;
        }
        
        $conn->begin_transaction();
        try {
            $conn->query("UPDATE student_ef_list SET comprobante_id = NULL WHERE comprobante_id = {$comprobante_id}");
            $conn->query("UPDATE payments SET comprobante_id = NULL WHERE comprobante_id = {$comprobante_id}");
            $conn->query("DELETE FROM comprobante_detalle WHERE comprobante_id = {$comprobante_id}");
            $conn->query("DELETE FROM comprobantes_electronicos WHERE id = {$comprobante_id} AND school_id = {$school_id}");
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Eliminado']);
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action == "save_evaluation_grades") {
    $save = $crud->save_evaluation_grades();
    echo $save;
    exit;
}

// ENDPOINT: EMITIR COMPROBANTE LIBRE
if ($action == 'emitir_comprobante_libre') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once 'includes/FacturacionElectronica.php';
        $school_id = $_SESSION['login_school_id'] ?? 0;
        if (!$school_id) throw new Exception("Sesión inválida");
        
        $facturacion = new FacturacionElectronica($conn, $school_id);
        
        $data = [
            'tipo_comprobante' => $_POST['tipo_comprobante'],
            'student_id' => $_POST['student_id'] ?? null,
            'cliente_tipo_doc' => $_POST['cliente_tipo_doc'],
            'cliente_num_doc' => $_POST['cliente_num_doc'],
            'cliente_razon_social' => $_POST['cliente_razon_social'],
            'cliente_direccion' => $_POST['cliente_direccion'] ?? '',
            'cliente_email' => $_POST['cliente_email'] ?? '',
            'moneda' => $_POST['moneda'] ?? 'PEN'
        ];
        
        $detalles = [];
        if (isset($_POST['item_desc'])) {
            foreach ($_POST['item_desc'] as $key => $desc) {
                $qty = (float)$_POST['item_qty'][$key];
                $price = (float)$_POST['item_price'][$key];
                $total = $qty * $price;
                
                $detalles[] = [
                    'descripcion' => $desc,
                    'cantidad' => $qty,
                    'precio_unitario' => $price,
                    'total' => $total
                ];
            }
        }
        
        if (empty($detalles)) throw new Exception("El comprobante debe tener al menos un concepto");
        
        $result = $facturacion->generarComprobanteLibre($data, $detalles);
        echo json_encode($result);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
