<?php
// Configuración de sesión consistente con login.php y ajax.php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', __DIR__ . '/tmp');
    if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
    session_name('EDUSYNCSESSID');
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
ini_set('display_errors', 1);
Class Action {
    private $db;

    public function __construct() {
		date_default_timezone_set('America/Lima'); // Zona horaria de la institución
        ob_start();
    	include 'db_connect.php';
        $this->db = $conn;
	}
	private function bind_params($stmt, $types, &$params) {
		$bind = [$types];
		foreach ($params as $key => &$value) {
			$bind[] = &$value;
		}
		call_user_func_array([$stmt, 'bind_param'], $bind);
	}
	private function audit_student($student_id, $school_id, $action, $details = []) {
		$user_id = intval($_SESSION['login_id'] ?? 0) ?: null;
		$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
		$details_json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$stmt = $this->db->prepare('INSERT INTO student_audit_log (student_id, school_id, user_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
		if (!$stmt) return false;
		$stmt->bind_param('iiisss', $student_id, $school_id, $user_id, $action, $details_json, $ip_address);
		$result = $stmt->execute();
		$stmt->close();
		return $result;
	}
	private function record_student_academic_history($student_id, $school_id, $academic_year_id, $nivel, $grado, $seccion, $status, $change_type, $notes = null) {
		$user_id = intval($_SESSION['login_id'] ?? 0) ?: null;
		$stmt = $this->db->prepare('INSERT INTO student_academic_history (student_id, school_id, academic_year_id, nivel, grado, seccion, status, change_type, user_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		if (!$stmt) return false;
		$stmt->bind_param('iiisssssis', $student_id, $school_id, $academic_year_id, $nivel, $grado, $seccion, $status, $change_type, $user_id, $notes);
		$result = $stmt->execute();
		$stmt->close();
		return $result;
	}
	private function audit_teacher($teacher_id, $school_id, $action, $details = []) {
		$table = $this->db->query("SHOW TABLES LIKE 'teacher_audit_log'");
		if (!$table || $table->num_rows === 0) return false;
		$user_id = intval($_SESSION['login_id'] ?? 0) ?: null;
		$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
		$details_json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$stmt = $this->db->prepare('INSERT INTO teacher_audit_log (teacher_id, school_id, user_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
		if (!$stmt) return false;
		$stmt->bind_param('iiisss', $teacher_id, $school_id, $user_id, $action, $details_json, $ip_address);
		$result = $stmt->execute();
		$stmt->close();
		return $result;
	}
	private function teacher_employment_history_table_exists() {
		$result = $this->db->query("SHOW TABLES LIKE 'teacher_employment_history'");
		return $result && $result->num_rows > 0;
	}
	private function teacher_employment_details_columns_exist() {
		$reason = $this->db->query("SHOW COLUMNS FROM teacher_employment_history LIKE 'departure_reason'");
		$notes = $this->db->query("SHOW COLUMNS FROM teacher_employment_history LIKE 'notes'");
		return $reason && $reason->num_rows > 0 && $notes && $notes->num_rows > 0;
	}
	private function get_active_academic_year($school_id) {
		$stmt = $this->db->prepare('SELECT id, year, start_date, end_date FROM academic_year WHERE school_id = ? AND is_active = 1 LIMIT 1');
		if (!$stmt) return null;
		$stmt->bind_param('i', $school_id);
		$stmt->execute();
		$year = $stmt->get_result()->fetch_assoc();
		$stmt->close();
		return $year ?: null;
	}
	function __destruct() {
	    $this->db->close();
	    ob_end_flush();
	}

	function login() {
        extract($_POST);

        // Validar que los campos no estén vacíos
        if (empty($username) || empty($password)) {
            return json_encode(['status' => 0, 'message' => 'Por favor, complete todos los campos.']);
        }

		$school_id = intval($_POST['school_id'] ?? 0);
		if ($school_id <= 0) {
			return json_encode(['status' => 0, 'message' => 'Seleccione un colegio.']);
		}

		$stmt = $this->db->prepare('SELECT u.*, t.status AS teacher_status FROM users u LEFT JOIN teacher t ON t.id = u.teacher_id WHERE u.username = ? AND u.school_id = ? LIMIT 1');
		$stmt->bind_param('si', $username, $school_id);
		$stmt->execute();
		$row = $stmt->get_result()->fetch_assoc();
		$stmt->close();
		if ($row) {
            if ($row['password'] === md5($password)) {
				if ((int)$row['type'] === 2 && ($row['teacher_status'] ?? 'Activo') === 'Inactivo') {
					return json_encode(['status' => 0, 'message' => 'Este docente está inactivo y no puede iniciar sesión.']);
				}
                foreach ($row as $key => $value) {
					if ($key != 'password' && $key != 'teacher_status' && !is_numeric($key)) {
                        $_SESSION['login_' . $key] = $value;
                    }
                }
                $_SESSION['login_id'] = $row['id'];
                return json_encode(['status' => 1, 'message' => 'Inicio de sesión exitoso.']);
            } else {
                return json_encode(['status' => 0, 'message' => 'Credenciales incorrectas.']);
            }
        } else {
            return json_encode(['status' => 0, 'message' => 'Credenciales incorrectas.']);
        }
    }

	function login2(){
		
		extract($_POST);		
		$qry = $this->db->query("SELECT * FROM complainants where email = '".$email."' and password = '".$password."' ");
		if($qry->num_rows > 0){
			foreach ($qry->fetch_array() as $key => $value) {
				if($key != 'password' && !is_numeric($key))
					$_SESSION['login_'.$key] = $value;
			}
				return 1;
		}else{
			return 3;
		}
	}
	function logout(){
		session_destroy();
		foreach ($_SESSION as $key => $value) {
			unset($_SESSION[$key]);
		}
		header("location:login.php");
	}
	function logout2(){
		session_destroy();
		foreach ($_SESSION as $key => $value) {
			unset($_SESSION[$key]);
		}
		header("location:../index.php");
	}

	function save_user() {
	extract($_POST);
	$school_id = intval($_SESSION['login_school_id'] ?? 0);
	$data = " name = '$name', username = '$username' ";
    if (!empty($password)) {
        $hashed_password = md5($password);
        $data .= ", password = '$hashed_password' ";
    }
    
    // Verificar si se va a eliminar el avatar
    $remove_avatar = isset($_POST['remove_avatar']) && $_POST['remove_avatar'] == '1';
    
    if ($remove_avatar) {
        // Si se elimina el avatar, quitarlo de la base de datos
        $data .= ", avatar = '' ";
        
        // Si existe el usuario, intentar obtener y eliminar el archivo físico
        if (!empty($id)) {
            $user_query = $this->db->query("SELECT avatar FROM users WHERE id = '$id'");
            if ($user_query && $user_query->num_rows > 0) {
                $old_avatar = $user_query->fetch_assoc()['avatar'];
                if (!empty($old_avatar)) {
                    $avatar_path = "assets/uploads/" . $old_avatar;
                    if (file_exists($avatar_path)) {
                        @unlink($avatar_path); // Intentar eliminar el archivo físico
                    }
                }
            }
        }
        
        // Actualizar la sesión para quitar el avatar
        $_SESSION['login_avatar'] = '';
    }
    else if (!empty($_FILES['avatar']['tmp_name'])) {
        $avatar = strtotime(date('Y-m-d H:i')) . '_' . $_FILES['avatar']['name'];
        move_uploaded_file($_FILES['avatar']['tmp_name'], 'assets/uploads/' . $avatar);
        $data .= ", avatar = '$avatar' ";
        
        // Update the session with the new avatar
        $_SESSION['login_avatar'] = $avatar;
        
        // Si existe el usuario, intentar eliminar el avatar anterior
        if (!empty($id)) {
            $user_query = $this->db->query("SELECT avatar FROM users WHERE id = '$id'");
            if ($user_query && $user_query->num_rows > 0) {
                $old_avatar = $user_query->fetch_assoc()['avatar'];
                if (!empty($old_avatar) && $old_avatar != $avatar) {
                    $avatar_path = "assets/uploads/" . $old_avatar;
                    if (file_exists($avatar_path)) {
                        @unlink($avatar_path); // Intentar eliminar el archivo físico anterior
                    }
                }
            }
        }
    }
    
	$chk = $this->db->query("SELECT * FROM users WHERE username = '$username' AND id != '$id'" . ($school_id ? " AND school_id = $school_id" : ""))->num_rows;
    if ($chk > 0) {
        return 2; // Usuario ya existe
    }
    if (empty($id)) {
		if ($school_id) {
			$data .= ", school_id = $school_id ";
		}
		$save = $this->db->query("INSERT INTO users SET $data");
    } else {
		$save = $this->db->query("UPDATE users SET $data WHERE id = $id" . ($school_id ? " AND school_id = $school_id" : ""));
    }
    return $save ? 1 : 0;
	}

	function delete_user(){
		extract($_POST);
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		$delete = $this->db->query("DELETE FROM users where id = ".$id . ($school_id ? " AND school_id = $school_id" : ""));
		if($delete)
			return 1;
	}
	function signup(){
		extract($_POST);
		$data = " name = '$name' ";
		$data .= ", email = '$email' ";
		$data .= ", address = '$address' ";
		$data .= ", contact = '$contact' ";
		$data .= ", password = '".$password."' ";
		$chk = $this->db->query("SELECT * from complainants where email ='$email' ".(!empty($id) ? " and id != '$id' " : ''))->num_rows;
		if($chk > 0){
			return 3;
		}
		if(empty($id))
			$save = $this->db->query("INSERT INTO complainants set $data");
		else
			$save = $this->db->query("UPDATE complainants set $data where id=$id ");
		if($save){
			if(empty($id))
				$id = $this->db->insert_id;
				$qry = $this->db->query("SELECT * FROM complainants where id = $id ");
				if($qry->num_rows > 0){
					foreach ($qry->fetch_array() as $key => $value) {
						if($key != 'password' && !is_numeric($key))
							$_SESSION['login_'.$key] = $value;
					}
						return 1;
				}else{
					return 3;
				}
		}
	}
	function update_account(){
		extract($_POST);
		$data = " name = '".$firstname.' '.$lastname."' ";
		$data .= ", username = '$email' ";
		if(!empty($password)) {
			$hashed_password = md5($password);
			$data .= ", password = '$hashed_password' ";
		}
		$chk = $this->db->query("SELECT * FROM users where username = '$email' and id != '{$_SESSION['login_id']}' ")->num_rows;
		if($chk > 0){
			return 2;
		}
		$save = $this->db->query("UPDATE users set $data where id = '{$_SESSION['login_id']}' ");
		if($save){
			$data = '';
			foreach($_POST as $k => $v){
				if($k =='password')
					continue;
				if(empty($data) && !is_numeric($k) )
					$data = " $k = '$v' ";
				else
					$data .= ", $k = '$v' ";
			}
			if($_FILES['img']['tmp_name'] != ''){
				$fname = strtotime(date('y-m-d H:i')).'_'.$_FILES['img']['name'];
				$move = move_uploaded_file($_FILES['img']['tmp_name'],'assets/uploads/'. $fname);
				$data .= ", avatar = '$fname' ";
			}
			$save_alumni = $this->db->query("UPDATE alumnus_bio set $data where id = '{$_SESSION['bio']['id']}' ");
			if($data){
				foreach ($_SESSION as $key => $value) {
					unset($_SESSION[$key]);
				}
				$login = $this->login2();
				if($login)
					return 1;
			}
		}
	}

	function save_settings(){
		extract($_POST);
		$data = " name = '".str_replace("'","&#x2019;",$name)."' ";
		$data .= ", email = '$email' ";
		$data .= ", contact = '$contact' ";
		$data .= ", about_content = '".htmlentities(str_replace("'","&#x2019;",$about))."' ";
		if($_FILES['img']['tmp_name'] != ''){
						$fname = strtotime(date('y-m-d H:i')).'_'.$_FILES['img']['name'];
						$move = move_uploaded_file($_FILES['img']['tmp_name'],'assets/uploads/'. $fname);
					$data .= ", cover_img = '$fname' ";

		}
		
		// echo "INSERT INTO system_settings set ".$data;
		$chk = $this->db->query("SELECT * FROM system_settings");
		if($chk->num_rows > 0){
			$save = $this->db->query("UPDATE system_settings set ".$data);
		}else{
			$save = $this->db->query("INSERT INTO system_settings set ".$data);
		}
		if($save){
		$query = $this->db->query("SELECT * FROM system_settings limit 1")->fetch_array();
		foreach ($query as $key => $value) {
			if(!is_numeric($key))
				$_SESSION['system'][$key] = $value;
		}

			return 1;
				}
	}
	function save_course() {
	    extract($_POST);
	    $data = "";
	    foreach ($_POST as $k => $v) {
	        if (!in_array($k, array('id')) && !is_numeric($k)) {
	            // Si es un array (grados), convertir a string separado por coma
	            if ($k == 'grades' && is_array($v)) {
	                $v = implode(',', $v);
	            }
	            if (empty($data)) {
	                $data .= " $k='$v' ";
	            } else {
	                $data .= ", $k='$v' ";
	            }
	        }
	    }    // Validar que las variables necesarias estén definidas
    if (!isset($course) || !isset($level) || !isset($academic_year_id)) {
        return json_encode(['status' => 0, 'message' => 'Faltan datos requeridos para guardar el curso.']);
    }

    // Verificar si el curso, nivel y año académico ya existen con los mismos grados
    $check = $this->db->query("SELECT * FROM courses WHERE course ='$course' AND level ='$level' AND academic_year_id = '$academic_year_id' AND grades = '$grades' " . (!empty($id) ? " AND id != {$id} " : ''));
    if ($check->num_rows > 0) {
        return json_encode(['status' => 2, 'message' => 'Ya existe un concepto con el mismo nombre, nivel, año académico y grados.']);
    }

	    if (empty($id)) {
	        // Insertar nuevo curso
	        $save = $this->db->query("INSERT INTO courses SET $data");
	        if ($save) {
	            return json_encode(['status' => 1, 'message' => 'Curso guardado exitosamente.']);
	        }
	    } else {
	        // Actualizar curso existente
	        $save = $this->db->query("UPDATE courses SET $data WHERE id = $id");
	        if ($save) {
	            return json_encode(['status' => 1, 'message' => 'Curso actualizado exitosamente.']);
	        }
	    }

	    return json_encode(['status' => 0, 'message' => 'Error al guardar el curso.']);
	}

	function delete_course(){
		extract($_POST);
		$delete = $this->db->query("DELETE FROM courses where id = ".$id);
		$delete2 = $this->db->query("DELETE FROM fees where course_id = ".$id);
		if($delete && $delete2){
			return 1;
		}
	}
	function save_student(){
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		$id = intval($_POST['id'] ?? 0);
		$id_no = trim($_POST['id_no'] ?? '');
		$name = trim($_POST['name'] ?? '');
		$nivel = $_POST['nivel'] ?? '';
		$grado = $_POST['grado'] ?? '';
		$status = $_POST['status'] ?? 'Activo';
		$academic_year_id = intval($_POST['academic_year_id'] ?? 0);
		$year_check = $this->db->prepare('SELECT id FROM academic_year WHERE id = ? AND school_id = ? LIMIT 1');
		$year_check->bind_param('ii', $academic_year_id, $school_id);
		$year_check->execute();
		$valid_year = $year_check->get_result()->num_rows > 0;
		$year_check->close();

		if ($school_id <= 0 || !$valid_year || $id_no === '' || $name === '' || $nivel === '' || $grado === '') {
			return 0;
		}
		if (!in_array($nivel, ['Inicial', 'Primaria', 'Secundaria'], true) || !in_array($status, ['Activo', 'Egresado', 'Retirado'], true)) {
			return 0;
		}

		$duplicate = $this->db->prepare('SELECT id FROM student WHERE school_id = ? AND id_no = ? AND id <> ? LIMIT 1');
		$duplicate->bind_param('isi', $school_id, $id_no, $id);
		$duplicate->execute();
		if ($duplicate->get_result()->num_rows > 0) {
			$duplicate->close();
			return 2;
		}
		$duplicate->close();

		$fields = ['id_no', 'name', 'genero', 'contact', 'address', 'tutor1_nombre', 'tutor1_apellido', 'tutor1_dni', 'tutor1_telefono', 'tutor1_direccion', 'tutor1_relacion', 'tutor2_nombre', 'tutor2_apellido', 'tutor2_dni', 'tutor2_telefono', 'tutor2_direccion', 'tutor2_relacion', 'email', 'nivel', 'grado', 'seccion', 'status'];
		$previous_academic = null;
		if ($id > 0) {
			$previous_query = $this->db->prepare('SELECT academic_year_id, nivel, grado, seccion, status FROM student WHERE id = ? AND school_id = ? LIMIT 1');
			if ($previous_query) {
				$previous_query->bind_param('ii', $id, $school_id);
				$previous_query->execute();
				$previous_academic = $previous_query->get_result()->fetch_assoc() ?: null;
				$previous_query->close();
			}
		}
		$values = [];
		foreach ($fields as $field) {
			$values[] = trim((string)($_POST[$field] ?? ''));
		}
		$types = str_repeat('s', count($values));

		if ($id === 0) {
			$insert_fields = array_merge(['academic_year_id'], $fields);
			$columns = implode(', ', array_merge(['school_id'], $insert_fields));
			$placeholders = implode(', ', array_fill(0, count($fields) + 2, '?'));
			$stmt = $this->db->prepare("INSERT INTO student ($columns) VALUES ($placeholders)");
			$params = array_merge([$school_id, $academic_year_id], $values);
			$this->bind_params($stmt, 'ii' . $types, $params);
		} else {
			$ownership = $this->db->prepare('SELECT id FROM student WHERE id = ? AND school_id = ? LIMIT 1');
			$ownership->bind_param('ii', $id, $school_id);
			$ownership->execute();
			if ($ownership->get_result()->num_rows === 0) {
				$ownership->close();
				return 0;
			}
			$ownership->close();

			$update_fields = array_merge(['academic_year_id'], $fields);
			$assignments = implode(', ', array_map(static function ($field) { return "$field = ?"; }, $update_fields));
			$stmt = $this->db->prepare("UPDATE student SET $assignments WHERE id = ? AND school_id = ?");
			$params = array_merge([$academic_year_id], $values, [$id, $school_id]);
			$this->bind_params($stmt, 'i' . $types . 'ii', $params);
		}

		$save = $stmt->execute();
		$saved_id = $id > 0 ? $id : $this->db->insert_id;
		$stmt->close();
		if ($save) {
			$this->audit_student($saved_id, $school_id, $id > 0 ? 'update' : 'create', [
				'id_no' => $id_no,
				'name' => $name,
				'academic_year_id' => $academic_year_id
			]);
			$history_changed = !$previous_academic || (int)$previous_academic['academic_year_id'] !== $academic_year_id || $previous_academic['nivel'] !== $nivel || $previous_academic['grado'] !== $grado || $previous_academic['seccion'] !== ($_POST['seccion'] ?? '') || $previous_academic['status'] !== $status;
			if ($history_changed) {
				$this->record_student_academic_history($saved_id, $school_id, $academic_year_id, $nivel, $grado, $_POST['seccion'] ?? '', $status, $id > 0 ? 'update' : 'initial');
			}
		}
		return $save ? 1 : 0;
	}
	function delete_student(){
		$id = intval($_POST['id'] ?? 0);
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		if ($id <= 0 || $school_id <= 0) return 0;

		$ownership = $this->db->prepare('SELECT id FROM student WHERE id = ? AND school_id = ? LIMIT 1');
		$ownership->bind_param('ii', $id, $school_id);
		$ownership->execute();
		$belongs_to_school = $ownership->get_result()->num_rows > 0;
		$ownership->close();
		if (!$belongs_to_school) return 0;

		$this->db->begin_transaction();
		try {
			$this->db->query("DELETE FROM asistencia WHERE student_id = $id");
			$this->db->query("DELETE FROM payments WHERE ef_id IN (SELECT id FROM student_ef_list WHERE student_id = $id)");
			$this->db->query("DELETE FROM student_ef_list WHERE student_id = $id");
			$delete = $this->db->query("DELETE FROM student WHERE id = $id AND school_id = $school_id");
			if (!$delete) throw new Exception($this->db->error);
			$this->audit_student($id, $school_id, 'delete', ['deleted_student_id' => $id]);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return 0;
		}
	}
	function save_fees(){
		extract($_POST);
		$data = "";
		foreach($_POST as $k => $v){
			if(!in_array($k, array('id')) && !is_numeric($k)){
				if($k == 'total_fee'){
					$v = str_replace(',', '', $v);
				}
				if(empty($data)){
					$data .= " $k='$v' ";
				}else{
					$data .= ", $k='$v' ";
				}
			}
		}
		// Validar duplicados por combinación de student_id y course_id si ef_no no está definido
		if (isset($ef_no) && $ef_no !== '') {
			$check = $this->db->query("SELECT * FROM student_ef_list WHERE ef_no ='$ef_no' ".(!empty($id) ? " and id != {$id} " : ''))->num_rows;
		} else if (isset($student_id) && isset($course_id)) {
			$check = $this->db->query("SELECT * FROM student_ef_list WHERE student_id ='$student_id' AND course_id ='$course_id' ".(!empty($id) ? " and id != {$id} " : ''))->num_rows;
		} else {
			$check = 0;
		}
		if($check > 0){
			return 2;
		}
		if(empty($id)){
			$save = $this->db->query("INSERT INTO student_ef_list set $data");
		}else{
			$save = $this->db->query("UPDATE student_ef_list set $data where id = $id");
		}
		if($save)
			return 1;
	}
	function delete_fees(){
		extract($_POST);
		$delete = $this->db->query("DELETE FROM student_ef_list where id = ".$id);
		if($delete){
			return 1;
		}
	}
	
	function bulk_delete_fees(){
		extract($_POST);
		if(empty($ids) || !is_array($ids)) return 2;
		$ids_list = implode(',', array_map('intval', $ids));
		$delete = $this->db->query("DELETE FROM student_ef_list where id IN ($ids_list)");
		if($delete){
			return 1;
		}
	}
	
	function get_student_concepts(){
		extract($_POST);
		
		// Obtener school_id de la sesión
		$school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;

		// Fallback: intentar obtener school_id desde el usuario logueado
		if ($school_id === 0 && isset($_SESSION['login_id'])) {
			$u = intval($_SESSION['login_id']);
			$uq = $this->db->query("SELECT school_id FROM users WHERE id = $u LIMIT 1");
			if ($uq && $uq->num_rows > 0) {
				$school_id = intval($uq->fetch_assoc()['school_id']);
				if ($school_id > 0) $_SESSION['login_school_id'] = $school_id;
			}
		}
		// Fallback adicional: si no hay colegio en sesión y sólo existe uno en DB, usar ese
		if ($school_id === 0) {
			$sc_q = $this->db->query("SELECT id FROM schools LIMIT 2");
			if ($sc_q && $sc_q->num_rows == 1) {
				$school_id = intval($sc_q->fetch_assoc()['id']);
				$_SESSION['login_school_id'] = $school_id;
			}
		}
		
		// Obtener edit_id si existe (para modo edición)
		$edit_id = isset($edit_id) ? intval($edit_id) : 0;
		
		// Construir la consulta para filtrar conceptos según el nivel y grado del estudiante
		// Y excluir los que ya están asignados al estudiante (excepto el que se está editando)
		$query = "
			SELECT c.*,
				   ay.year,
				   CONCAT(c.course, ' - ', c.level, ' (', COALESCE(c.grades, 'Todos los grados'), ') - ', COALESCE(ay.year, 'Sin año')) as display_text
			FROM courses c
			LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
			WHERE c.level = '$nivel'
			AND (c.grades IS NULL OR c.grades = '' OR FIND_IN_SET('$grado', c.grades) > 0)
			AND c.id NOT IN (
				SELECT course_id 
				FROM student_ef_list 
				WHERE student_id = '$student_id'";
		
		// Si estamos editando, excluir el registro actual de la verificación de duplicados
		if ($edit_id > 0) {
			$query .= " AND id != '$edit_id'";
		}
		
		$query .= "
			)";
		
		// Filtrar por school_id si está disponible
		if ($school_id > 0) {
			$query .= " AND ay.school_id = '$school_id'";
		}
		
		$query .= " ORDER BY ay.year DESC, c.course ASC";
		
		$result = $this->db->query($query);
		$concepts = array();
		
		if($result && $result->num_rows > 0){
			while($row = $result->fetch_assoc()){
				$concepts[] = $row;
			}
			return json_encode(['status' => 1, 'concepts' => $concepts]);
		} else {
			return json_encode(['status' => 0, 'message' => 'No hay conceptos disponibles para este estudiante (todos ya están asignados o no hay conceptos para su nivel/grado)']);
		}
	}
	
function get_all_students(){
	// Obtener school_id de la sesión
	$school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;

	// Si no hay school_id en sesión, intentar obtenerlo desde el usuario logueado (fallback)
	if ($school_id === 0 && isset($_SESSION['login_id'])) {
		$uid = intval($_SESSION['login_id']);
		$res = $this->db->query("SELECT school_id FROM users WHERE id = $uid LIMIT 1");
		if ($res && $res->num_rows > 0) {
			$school_id = intval($res->fetch_assoc()['school_id']);
			// Guardarlo en sesión para próximas consultas
			$_SESSION['login_school_id'] = $school_id;
		}
	}

	if ($school_id === 0) {
		// Intentar asignar automáticamente si sólo existe un colegio en la base de datos
		$sc_q = $this->db->query("SELECT id FROM schools LIMIT 2");
		if ($sc_q && $sc_q->num_rows == 1) {
			$single_school = intval($sc_q->fetch_assoc()['id']);
			$_SESSION['login_school_id'] = $single_school;
			$school_id = $single_school;
		} else {
			return json_encode(['status' => 0, 'message' => 'No hay colegio activo en la sesión.']);
		}
	}

	// Consulta para obtener todos los estudiantes activos
	$query = "SELECT id, name, id_no, nivel, grado FROM student WHERE school_id = '$school_id' ORDER BY name ASC";
	
	$result = $this->db->query($query);
	$students = array();
	
	if($result && $result->num_rows > 0){
		while($row = $result->fetch_assoc()){
			$students[] = $row;
		}
		return json_encode(['status' => 1, 'students' => $students]);
	} else {
		return json_encode(['status' => 0, 'message' => 'No hay estudiantes disponibles']);
	}
}

function get_student_fees($student_id){
	$query = "SELECT * FROM student_ef_list WHERE student_id = '$student_id'";
	$result = $this->db->query($query);
	$fees = array();
	
	if($result && $result->num_rows > 0){
		while($row = $result->fetch_assoc()){
			$fees[] = $row;
		}
	}
	
	return $fees;
}

function get_active_students(){
	// Obtener school_id de la sesión
	$school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
	
	// Consulta para obtener todos los estudiantes activos
	$query = "SELECT id, name, id_no, nivel, grado, seccion, status FROM student WHERE school_id = '$school_id' ORDER BY name ASC";
	
	$result = $this->db->query($query);
	$students = array();
	
	if($result && $result->num_rows > 0){
		while($row = $result->fetch_assoc()){
			$students[] = $row;
		}
		return json_encode(['status' => 1, 'students' => $students]);
	} else {
		return json_encode(['status' => 0, 'message' => 'No hay estudiantes disponibles']);
	}
}

function get_available_concepts_for_bulk(){
	extract($_POST);
	
	// Obtener school_id de la sesión
	$school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
	
	// Si hay estudiantes seleccionados, filtrar conceptos disponibles para ellos
	if (isset($student_ids) && is_array($student_ids) && count($student_ids) > 0) {
		// Obtener información de los estudiantes para saber sus niveles y grados
		$student_ids_str = implode(',', array_map('intval', $student_ids));
		
		// Consulta más simple: obtener todos los conceptos y para cada uno
		// verificar cuántos estudiantes pueden recibirlo
		$query = "
			SELECT c.*,
				   ay.year,
				   CONCAT(c.course, ' - ', c.level, ' (', COALESCE(c.grades, 'Todos los grados'), ') - ', COALESCE(ay.year, 'Sin año')) as display_text
			FROM courses c
			LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
			WHERE EXISTS (
				SELECT 1 FROM student s 
				WHERE s.id IN ($student_ids_str)
				AND s.nivel = c.level
				AND (c.grades IS NULL OR c.grades = '' OR FIND_IN_SET(s.grado, c.grades) > 0)
			)
		";
		
		// Filtrar por school_id si está disponible
		if ($school_id > 0) {
			$query .= " AND ay.school_id = '$school_id'";
		}
		
		$query .= " ORDER BY ay.year DESC, c.course ASC";
		
	} else {
		// Si no hay estudiantes seleccionados, obtener todos los conceptos disponibles
		$query = "
			SELECT c.*,
				   ay.year,
				   CONCAT(c.course, ' - ', c.level, ' (', COALESCE(c.grades, 'Todos los grados'), ') - ', COALESCE(ay.year, 'Sin año')) as display_text
			FROM courses c
			LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
		";
		
		// Filtrar por school_id si está disponible
		if ($school_id > 0) {
			$query .= " WHERE ay.school_id = '$school_id'";
		}
		
		$query .= " ORDER BY ay.year DESC, c.course ASC";
	}
	
	$result = $this->db->query($query);
	$concepts = array();
	
	if($result && $result->num_rows > 0){
		while($row = $result->fetch_assoc()){
			$concepts[] = $row;
		}
		return json_encode(['status' => 1, 'concepts' => $concepts]);
	} else {
		return json_encode(['status' => 0, 'message' => 'No hay conceptos disponibles']);
	}
}

function bulk_assign_fees(){
	extract($_POST);
	
	// Obtener school_id de la sesión
	$school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
	
	// Validar entrada
	if (!isset($students) || !is_array($students) || count($students) === 0) {
		return json_encode(['status' => 0, 'message' => 'No hay estudiantes seleccionados']);
	}
	
	if (!isset($concepts) || !is_array($concepts) || count($concepts) === 0) {
		return json_encode(['status' => 0, 'message' => 'No hay conceptos seleccionados']);
	}
	
	$students_arr = is_array($students) ? $students : json_decode($students, true);
	$concepts_arr = is_array($concepts) ? $concepts : json_decode($concepts, true);
	
	$assigned_count = 0;
	$skipped_count = 0;
	$errors = array();
	
	// Iterar sobre los estudiantes
	foreach ($students_arr as $student_id) {
		$student_id = intval($student_id);
		
		// Obtener información del estudiante
		$student_query = "SELECT nivel, grado FROM student WHERE id = '$student_id' AND school_id = '$school_id'";
		$student_result = $this->db->query($student_query);
		
		if (!$student_result || $student_result->num_rows === 0) {
			$errors[] = "Estudiante $student_id no encontrado";
			continue;
		}
		
		$student_row = $student_result->fetch_assoc();
		$nivel = $student_row['nivel'];
		$grado = $student_row['grado'];
		
		// Iterar sobre los conceptos
		foreach ($concepts_arr as $course_id) {
			$course_id = intval($course_id);
			
			// Verificar si el concepto es compatible con el nivel y grado del estudiante
			$concept_query = "
				SELECT c.id, c.total_amount, c.level, c.grades 
				FROM courses c 
				WHERE c.id = '$course_id' 
				AND c.level = '$nivel'
				AND (c.grades IS NULL OR c.grades = '' OR FIND_IN_SET('$grado', c.grades) > 0)
			";
			
			$concept_result = $this->db->query($concept_query);
			
			if (!$concept_result || $concept_result->num_rows === 0) {
				// El concepto no es compatible con este estudiante, saltar
				$skipped_count++;
				continue;
			}
			
			$concept_row = $concept_result->fetch_assoc();
			
			// Verificar si ya está asignado
			$existing_query = "
				SELECT id FROM student_ef_list 
				WHERE student_id = '$student_id' AND course_id = '$course_id'
			";
			
			$existing_result = $this->db->query($existing_query);
			
			if ($existing_result && $existing_result->num_rows > 0) {
				// Ya está asignado, saltar
				$skipped_count++;
				continue;
			}
			
			// Asignar el concepto: usar columna total_fee (no 'amount')
			$insert_query = "
				INSERT INTO student_ef_list 
				(student_id, course_id, total_fee) 
				VALUES ('$student_id', '$course_id', '" . $concept_row['total_amount'] . "')
			";
			
			if ($this->db->query($insert_query)) {
				$assigned_count++;
			} else {
				$errors[] = "Error al asignar concepto $course_id al estudiante $student_id: " . $this->db->error;
			}
		}
	}
	
	// Preparar mensaje de respuesta
	$message = "Se asignaron $assigned_count deudas exitosamente";
	if ($skipped_count > 0) {
		$message .= " ($skipped_count conceptos ya estaban asignados o no son compatibles)";
	}
	
	if (count($errors) > 0) {
		$message .= ". Se encontraron algunos errores.";
	}
	
	return json_encode([
		'status' => $assigned_count > 0 ? 1 : 0,
		'message' => $message,
		'assigned' => $assigned_count,
		'skipped' => $skipped_count,
		'errors' => $errors
	]);
}

function save_payment(){
	extract($_POST);

	// Parseo y validaciones básicas
	$main_amount = isset($_POST['amount']) ? floatval(str_replace(',', '', $_POST['amount'])) : 0;
	$student_id_int = isset($student_id) ? intval($student_id) : 0;
	$receipt_no_safe = isset($receipt_no) ? $this->db->real_escape_string($receipt_no) : '';
	$remarks_safe = isset($remarks) ? $this->db->real_escape_string($remarks) : '';

	// Desgloses de medios de pago (splits)
	$splits = [];
	if (isset($_POST['payment_splits']) && $_POST['payment_splits'] !== '') {
		$tmp = json_decode($_POST['payment_splits'], true);
		if (is_array($tmp)) $splits = $tmp;
	}

	// Conceptos seleccionados para multipago
	$selectedConcepts = [];
	if (isset($_POST['selected_concepts']) && $_POST['selected_concepts'] !== '') {
		$tmpc = json_decode($_POST['selected_concepts'], true);
		if (is_array($tmpc)) $selectedConcepts = $tmpc;
	}

	// Si vienen conceptos (>=1), realizar multipago creando un pago por concepto
	if (is_array($selectedConcepts) && count($selectedConcepts) >= 1) {
		// Validar sumatoria de conceptos = monto total
		$concepts_sum = 0.0;
		foreach ($selectedConcepts as $c) {
			$concepts_sum += floatval($c['amount'] ?? 0);
		}
		if (abs($concepts_sum - $main_amount) > 0.01) {
			return json_encode(['status' => 0, 'message' => 'La suma de conceptos no coincide con el monto total.']);
		}

		// Validar sumatoria de medios de pago = monto total
		$splits_sum = 0.0;
		foreach ($splits as $s) {
			$splits_sum += floatval($s['amount'] ?? 0);
		}
		if (abs($splits_sum - $main_amount) > 0.01) {
			return json_encode(['status' => 0, 'message' => 'La suma de medios de pago no coincide con el monto total.']);
		}

		$now = date('Y-m-d H:i:s');
		$payments = [];

		foreach ($selectedConcepts as $idx => $c) {
			$ef = intval($c['ef_id'] ?? 0);
			$cam = floatval($c['amount'] ?? 0);
			if ($ef <= 0 || $cam <= 0) continue;

			// Insertar pago por concepto (sin student_id para compatibilidad con esquema actual)
			$sql = "INSERT INTO payments (ef_id, amount, receipt_no, remarks, date_created) VALUES ($ef, $cam, '$receipt_no_safe', '$remarks_safe', '$now')";
			$save = $this->db->query($sql);
			if (!$save) {
				return json_encode(['status' => 0, 'message' => 'Error al guardar el pago: ' . $this->db->error]);
			}
			$pid = $this->db->insert_id;

			// Reparto proporcional de medios de pago al pago del concepto
			if (!empty($splits)) {
				$allocated = 0.0;
				$count_s = count($splits);
				foreach ($splits as $i => $s) {
					$method_id = intval($s['method_id'] ?? 0);
					$samt = floatval($s['amount'] ?? 0);
					if ($method_id <= 0 || $samt <= 0) continue;
					$alloc = $main_amount > 0 ? round(($samt / $main_amount) * $cam, 2) : 0.0;
					if ($i == $count_s - 1) {
						// Ajuste por redondeo para que sume exactamente al monto del concepto
						$alloc = round($cam - $allocated, 2);
					}
					if ($alloc > 0.009) { // evitar residuos muy pequeños
						$this->db->query("INSERT INTO payment_split (payment_id, payment_method_id, amount) VALUES ($pid, $method_id, $alloc)");
						$allocated += $alloc;
					}
				}
			}

			$payments[] = ['ef_id' => $ef, 'pid' => $pid];
		}

		return json_encode(['status' => 1, 'payments' => $payments]);
	}

	// Fallback: comportamiento anterior para un solo concepto (sin selected_concepts)
	// Construir datos desde POST
	$data = "";
	foreach ($_POST as $k => $v) {
		if (!in_array($k, ['id', 'student_id', 'selected_concepts', 'payment_splits']) && !is_numeric($k)) {
			if ($k == 'amount') $v = str_replace(',', '', $v);
			if (empty($data)) $data .= " $k='$v' "; else $data .= ", $k='$v' ";
		}
	}
	if (empty($id)) {
		$now = date('Y-m-d H:i:s');
		$data .= (empty($data) ? '' : ',') . " date_created='$now' ";
		$save = $this->db->query("INSERT INTO payments SET $data");
		if ($save) $id = $this->db->insert_id;
	} else {
		$save = $this->db->query("UPDATE payments SET $data WHERE id = $id");
	}

	if ($save) {
		if (!empty($splits)) {
			$this->db->query("DELETE FROM payment_split WHERE payment_id = $id");
			foreach ($splits as $split) {
				$method_id = intval($split['method_id'] ?? 0);
				$amt = floatval($split['amount'] ?? 0);
				if ($amt > 0 && $method_id > 0) {
					$this->db->query("INSERT INTO payment_split (payment_id, payment_method_id, amount) VALUES ($id, $method_id, $amt)");
				}
			}
		}
		// Tratar de obtener ef_id desde POST si existe
		$post_ef_id = null;
		if (isset($_POST['ef_id'])) {
			if (is_array($_POST['ef_id'])) { $post_ef_id = intval($_POST['ef_id'][0]); }
			else { $post_ef_id = intval($_POST['ef_id']); }
		}
		return json_encode(['ef_id' => $post_ef_id, 'pid' => $id, 'status' => 1]);
	}
}

	function delete_payment(){
		extract($_POST);
		$delete = $this->db->query("DELETE FROM payments where id = ".$id);
		if($delete){
			return 1;
		}
	}

	function bulk_delete_payment(){
		extract($_POST);
		if(empty($ids) || !is_array($ids)) return 2;
		$ids_list = implode(',', array_map('intval', $ids));
		$delete = $this->db->query("DELETE FROM payments where id IN ($ids_list)");
		if($delete){
			return 1;
		}
	}

	function save_teacher(){
		extract($_POST);
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		$posted_status = $_POST['status'] ?? 'Activo';
		$status = in_array($posted_status, ['Activo', 'Inactivo'], true) ? $posted_status : 'Activo';
		$id = intval($_POST['id'] ?? 0);
		
		if ($school_id <= 0) {
			return json_encode(['status'=>0, 'message'=>'No tiene permiso para crear o editar docentes en este colegio.']);
		}
		$birth_column = $this->db->query("SHOW COLUMNS FROM teacher LIKE 'birth_date'");
		if (!$birth_column || $birth_column->num_rows === 0) {
			return json_encode(['status'=>0, 'message'=>'Falta actualizar el módulo de docentes. Ejecute sql/teacher_module_upgrade.sql.']);
		}
		if (!empty($_POST['birth_date']) && $_POST['birth_date'] > date('Y-m-d')) {
			return json_encode(['status'=>0, 'message'=>'La fecha de nacimiento no puede ser futura.']);
		}

		$active_year = null;
		if (empty($id) && $status === 'Activo') {
			$active_year = $this->get_active_academic_year($school_id);
			if (!$active_year) {
				return json_encode(['status'=>0, 'message'=>'Debe configurar un año académico activo antes de registrar un docente activo.']);
			}
			if (!$this->teacher_employment_history_table_exists()) {
				return json_encode(['status'=>0, 'message'=>'Falta instalar el historial laboral. Ejecute el script sql/teacher_employment_history.sql.']);
			}
		}
		
		$data = "";
		foreach($_POST as $k => $v){
			if(!in_array($k, ['id']) && !is_numeric($k)){
				// El estado de un docente existente solo cambia mediante el flujo de
				// desactivación/recontratación, donde se registra su historial laboral.
				if ($id > 0 && $k === 'status') continue;
				if(empty($data)){
					$data .= " $k='$v' ";
				}else{
					$data .= ", $k='$v' ";
				}
			}
		}
		
		if(empty($id)){
			// Verificar si el ID ya existe
			$normalized_id_no = trim($id_no ?? '');
			$duplicate_stmt = $this->db->prepare('SELECT id, name, status FROM teacher WHERE id_no = ? AND school_id = ? LIMIT 1');
			$duplicate_stmt->bind_param('si', $normalized_id_no, $school_id);
			$duplicate_stmt->execute();
			$duplicate = $duplicate_stmt->get_result()->fetch_assoc();
			$duplicate_stmt->close();
			if($duplicate){
				$message = $duplicate['status'] === 'Inactivo'
					? 'Este DNI pertenece a un docente registrado anteriormente. Puede recontratarlo sin crear un registro duplicado.'
					: 'Este DNI ya pertenece a un docente activo en la institución.';
				return json_encode(['status'=>2, 'message'=>$message, 'teacher_id'=>(int)$duplicate['id'], 'teacher_name'=>$duplicate['name'], 'teacher_status'=>$duplicate['status']]);
			}
			
			$save = $this->db->query("INSERT INTO teacher set $data");
			if ($save && $status === 'Activo') {
				$teacher_id = intval($this->db->insert_id);
				$year_id = intval($active_year['id']);
				$user_id = intval($_SESSION['login_id'] ?? 0);
				$today = date('Y-m-d');
				$stmt = $this->db->prepare("INSERT INTO teacher_employment_history (teacher_id, school_id, academic_year_id, start_date, status, created_by) VALUES (?, ?, ?, ?, 'Activo', ?)");
				$stmt->bind_param('iiisi', $teacher_id, $school_id, $year_id, $today, $user_id);
				$save = $stmt->execute();
				$stmt->close();
			}
		}else{
			// Asegurarse de que solo edite docentes del mismo colegio
			$check = $this->db->query("SELECT * FROM teacher WHERE id ='$id' AND school_id = '$school_id'")->num_rows;
			if($check <= 0){
				return json_encode(['status'=>0, 'message'=>'No tiene permiso para editar este docente.']);
			}
			
			$save = $this->db->query("UPDATE teacher set $data where id = $id");
		}
		
		if($save) {
			$saved_teacher_id = empty($id) ? intval($teacher_id ?? $this->db->insert_id) : $id;
			$this->audit_teacher($saved_teacher_id, $school_id, empty($id) ? 'teacher_created' : 'teacher_updated', ['name' => $name ?? '', 'dni' => $id_no ?? '']);
			return json_encode(['status'=>1, 'message'=>'Docente guardado exitosamente.']);
		} else
			return json_encode(['status'=>0, 'message'=>'Error al guardar los datos.']);
	}

	function delete_teacher(){
		$id = intval($_POST['id'] ?? 0);
		
		// Obtener el school_id del administrador
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		
		// Verificar que el docente pertenezca al colegio del administrador
		$check = $this->db->query("SELECT * FROM teacher WHERE id = $id AND school_id = $school_id");
		if (!$check || $check->num_rows == 0) {
			return json_encode(['status' => 0, 'message' => 'No tiene permiso para cambiar el estado de este docente.']);
		}

		$current = $check->fetch_assoc();
		$new_status = (($current['status'] ?? 'Activo') === 'Inactivo') ? 'Activo' : 'Inactivo';
		$active_year = $this->get_active_academic_year($school_id);
		if (!$active_year) {
			return json_encode(['status' => 0, 'message' => 'No hay un año académico activo. Configúrelo antes de desactivar o recontratar docentes.']);
		}
		if (!$this->teacher_employment_history_table_exists()) {
			return json_encode(['status' => 0, 'message' => 'Falta instalar el historial laboral. Ejecute el script sql/teacher_employment_history.sql.']);
		}
		if (!$this->teacher_employment_details_columns_exist()) {
			return json_encode(['status' => 0, 'message' => 'Falta actualizar el historial laboral. Ejecute sql/teacher_employment_history_upgrade.sql.']);
		}

		$reason = trim($_POST['departure_reason'] ?? '');
		$notes = trim($_POST['notes'] ?? '');
		$allowed_reasons = ['No renovación', 'Renuncia', 'Despido', 'Licencia', 'Otro'];
		if ($new_status === 'Inactivo' && !in_array($reason, $allowed_reasons, true)) {
			return json_encode(['status' => 0, 'message' => 'Seleccione un motivo de salida válido.']);
		}

		$year_id = intval($active_year['id']);
		$year_name = $active_year['year'];
		$user_id = intval($_SESSION['login_id'] ?? 0);
		$today = date('Y-m-d');
		$this->db->begin_transaction();

		try {
			$stmt = $this->db->prepare('UPDATE teacher SET status = ? WHERE id = ? AND school_id = ?');
			$stmt->bind_param('sii', $new_status, $id, $school_id);
			if (!$stmt->execute()) throw new Exception($stmt->error);
			$stmt->close();

			if ($new_status === 'Inactivo') {
				$stmt = $this->db->prepare("UPDATE teacher_employment_history SET end_date = ?, status = 'Finalizado', departure_reason = ?, notes = ? WHERE teacher_id = ? AND school_id = ? AND status = 'Activo' AND end_date IS NULL");
				$stmt->bind_param('sssii', $today, $reason, $notes, $id, $school_id);
				if (!$stmt->execute()) throw new Exception($stmt->error);
				$closed_periods = $stmt->affected_rows;
				$stmt->close();

				// Compatibilidad para docentes existentes que todavía no tenían historial.
				if ($closed_periods === 0) {
					$start_date = $active_year['start_date'] ?: $today;
					if ($start_date > $today) $start_date = $today;
					$stmt = $this->db->prepare("INSERT INTO teacher_employment_history (teacher_id, school_id, academic_year_id, start_date, end_date, status, departure_reason, notes, created_by) VALUES (?, ?, ?, ?, ?, 'Finalizado', ?, ?, ?)");
					$stmt->bind_param('iiissssi', $id, $school_id, $year_id, $start_date, $today, $reason, $notes, $user_id);
					if (!$stmt->execute()) throw new Exception($stmt->error);
					$stmt->close();
				}
				$message = "Docente desactivado. Fecha de salida: " . date('d/m/Y') . ". Año académico: $year_name.";
			} else {
				$stmt = $this->db->prepare("INSERT INTO teacher_employment_history (teacher_id, school_id, academic_year_id, start_date, status, notes, created_by) VALUES (?, ?, ?, ?, 'Activo', ?, ?)");
				$stmt->bind_param('iiissi', $id, $school_id, $year_id, $today, $notes, $user_id);
				if (!$stmt->execute()) throw new Exception($stmt->error);
				$stmt->close();
				$message = "Docente recontratado desde " . date('d/m/Y') . " para el año académico $year_name.";
			}

			$this->db->commit();
			$this->audit_teacher($id, $school_id, $new_status === 'Activo' ? 'teacher_rehired' : 'teacher_deactivated', ['academic_year_id' => $year_id, 'date' => $today, 'departure_reason' => $reason, 'notes' => $notes]);
			return json_encode(['status' => 1, 'message' => $message, 'new_status' => $new_status]);
		} catch (Throwable $e) {
			$this->db->rollback();
			return json_encode(['status' => 0, 'message' => 'No se pudo actualizar el vínculo laboral: ' . $e->getMessage()]);
		}
	}
	function bulk_teacher_status(){
		$raw_ids = $_POST['ids'] ?? [];
		$ids = is_array($raw_ids) ? $raw_ids : explode(',', $raw_ids);
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		$target_status = $_POST['target_status'] ?? '';
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		if (!$ids || !in_array($target_status, ['Activo', 'Inactivo'], true) || $school_id <= 0) {
			return json_encode(['status'=>0, 'message'=>'Selección o acción masiva inválida.']);
		}
		if (count($ids) > 200) return json_encode(['status'=>0, 'message'=>'Seleccione como máximo 200 docentes por operación.']);

		$changed = 0; $skipped = 0; $errors = [];
		$original_post = $_POST;
		foreach ($ids as $teacher_id) {
			$stmt = $this->db->prepare('SELECT status FROM teacher WHERE id = ? AND school_id = ? LIMIT 1');
			$stmt->bind_param('ii', $teacher_id, $school_id); $stmt->execute();
			$current = $stmt->get_result()->fetch_assoc(); $stmt->close();
			if (!$current) { $errors[] = "Docente $teacher_id no encontrado"; continue; }
			if (($current['status'] ?? 'Activo') === $target_status) { $skipped++; continue; }
			$_POST = ['id'=>$teacher_id, 'departure_reason'=>$original_post['departure_reason'] ?? '', 'notes'=>$original_post['notes'] ?? ''];
			$result = json_decode($this->delete_teacher(), true);
			if (($result['status'] ?? 0) == 1) $changed++; else $errors[] = $result['message'] ?? "Error en docente $teacher_id";
		}
		$_POST = $original_post;
		if ($changed > 0) {
			$message = "$changed docente(s) actualizado(s)." . ($skipped ? " $skipped ya tenían el estado solicitado." : '') . ($errors ? ' Algunos registros no pudieron actualizarse.' : '');
			return json_encode(['status'=>1, 'message'=>$message, 'changed'=>$changed, 'skipped'=>$skipped, 'errors'=>$errors]);
		}
		return json_encode(['status'=>0, 'message'=>$errors ? implode(' ', array_slice($errors,0,3)) : 'Los docentes seleccionados ya tenían el estado solicitado.']);
	}
	function save_grade() {
		extract($_POST);
		// Si es profesor, forzar el teacher_id de la sesión
		if (isset($_SESSION['login_type']) && $_SESSION['login_type'] == 2 && isset($_SESSION['login_teacher_id'])) {
			$teacher_id = $_SESSION['login_teacher_id'];
		}
		$data = "";
		foreach ($_POST as $k => $v) {
			if (!in_array($k, array('id')) && !is_numeric($k)) {
				if ($k == 'teacher_id' && isset($_SESSION['login_type']) && $_SESSION['login_type'] == 2) {
					$v = $_SESSION['login_teacher_id'];
				}
				if (empty($data)) {
					$data .= " $k='$v' ";
				} else {
					$data .= ", $k='$v' ";
				}
			}
		}
		if (empty($id)) {
			$save = $this->db->query("INSERT INTO grades SET $data");
		} else {
			$save = $this->db->query("UPDATE grades SET $data WHERE id = $id");
		}
		if ($save)
			return json_encode(['status' => 1, 'message' => 'Nota guardada exitosamente.']);
		return json_encode(['status' => 0, 'message' => 'Error al guardar la nota.']);
	}
	function delete_grade() {
		extract($_POST);
		$delete = $this->db->query("DELETE FROM grades WHERE id = " . $id);
		if ($delete) {
			return json_encode(['status' => 1]);
		}
		return json_encode(['status' => 0, 'message' => 'Error al eliminar la nota.']);
	}

	// Nueva función para asignar cursos a docentes
	function assign_teacher_course() {
		extract($_POST);
		
		// Validar que school_id sea el mismo que el del administrador actual
		if ($school_id != $_SESSION['login_school_id']) {
			return json_encode(['status' => 0, 'message' => 'No tiene permiso para asignar docentes a otro colegio.']);
		}
		
		// Verificar que los campos obligatorios estén presentes
		if (empty($teacher_id) || empty($course_id) || empty($grado) || empty($seccion) || empty($academic_year_id)) {
			return json_encode(['status' => 0, 'message' => 'Todos los campos son obligatorios']);
		}
		
		// Convert input to arrays if they aren't already
		$courses = is_array($course_id) ? $course_id : [$course_id];
		$grades = is_array($grado) ? $grado : [$grado];
		$sections = is_array($seccion) ? $seccion : [$seccion];
		
		$teacher_id = $this->db->real_escape_string($teacher_id);
		$academic_year_id = $this->db->real_escape_string($academic_year_id);
		$school_id = $this->db->real_escape_string($school_id);
		
		if (empty($id)) {
			$inserted = 0;
			$duplicates = 0;
			$errors = 0;
			$last_error = '';

			$count = count($courses);
			for ($i = 0; $i < $count; $i++) {
				$c_id = $this->db->real_escape_string($courses[$i]);
				$g = $this->db->real_escape_string($grades[$i]);
				$s = $this->db->real_escape_string($sections[$i]);
				
				// Obtener el level real del curso desde la tabla academic_courses
				$level_query = $this->db->query("SELECT level FROM academic_courses WHERE id = '$c_id'");
				$c_level = ($level_query && $level_query->num_rows > 0) ? $level_query->fetch_assoc()['level'] : 'Primaria';
				$c_level = $this->db->real_escape_string($c_level);

				$check = $this->db->query("SELECT id FROM teacher_courses 
									WHERE teacher_id = '$teacher_id' 
									AND course_id = '$c_id' 
									AND grado = '$g' 
									AND seccion = '$s' 
									AND level = '$c_level'
									AND academic_year_id = '$academic_year_id'");
				if ($check && $check->num_rows > 0) {
					$duplicates++;
					continue;
				}

				$save = $this->db->query("INSERT INTO teacher_courses (teacher_id, course_id, grado, seccion, school_id, level, academic_year_id) 
										VALUES ('$teacher_id', '$c_id', '$g', '$s', '$school_id', '$c_level', '$academic_year_id')");
				if ($save) {
					$inserted++;
				} else {
					$errors++;
					$last_error = $this->db->error;
				}
			}
			
			if ($inserted > 0) {
				$this->audit_teacher((int)$teacher_id, (int)$school_id, 'courses_assigned', ['academic_year_id' => (int)$academic_year_id, 'count' => $inserted]);
				return json_encode(['status' => 1, 'message' => 'Se asignaron ' . $inserted . ' cursos.' . ($duplicates > 0 ? " ($duplicates duplicados omitidos)" : "")]);
			} else if ($duplicates > 0) {
				return json_encode(['status' => 2, 'message' => 'Todas las asignaciones seleccionadas ya existían.']);
			} else {
				return json_encode(['status' => 0, 'message' => 'Error al guardar: ' . $last_error]);
			}
		} else {
			// Actualizar asignación existente (toma el primer elemento si es array)
			$id = $this->db->real_escape_string($id);
			$c_id = $this->db->real_escape_string($courses[0]);
			$g = $this->db->real_escape_string($grades[0]);
			$s = $this->db->real_escape_string($sections[0]);
			
			$level_query = $this->db->query("SELECT level FROM academic_courses WHERE id = '$c_id'");
			$c_level = ($level_query && $level_query->num_rows > 0) ? $level_query->fetch_assoc()['level'] : 'Primaria';
			$c_level = $this->db->real_escape_string($c_level);
			
			// Verificar que esta asignación pertenece al colegio del administrador
			$check_ownership = $this->db->query("SELECT tc.* 
										   FROM teacher_courses tc 
										   INNER JOIN teacher t ON tc.teacher_id = t.id
										   WHERE tc.id = '$id' AND t.school_id = '$school_id'");
			
			if (!$check_ownership || $check_ownership->num_rows === 0) {
				return json_encode(['status' => 0, 'message' => 'No tiene permiso para editar esta asignación.']);
			}
			
			$save = $this->db->query("UPDATE teacher_courses SET 
								 teacher_id = '$teacher_id', 
								 course_id = '$c_id', 
								 grado = '$g', 
								 seccion = '$s', 
								 school_id = '$school_id',
								 level = '$c_level',
								 academic_year_id = '$academic_year_id'
								 WHERE id = '$id'");
			
			if ($save) {
				$this->audit_teacher((int)$teacher_id, (int)$school_id, 'course_assignment_updated', ['assignment_id' => (int)$id, 'academic_year_id' => (int)$academic_year_id]);
				return json_encode(['status' => 1]);
			} else {
				return json_encode(['status' => 0, 'message' => 'Error al guardar: ' . $this->db->error]);
			}
		}
	}

	function delete_teacher_course() {
		$id = intval($_POST['id'] ?? 0);
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		if ($id <= 0 || $school_id <= 0) {
			return json_encode(['status' => 0, 'message' => 'Asignación inválida.']);
		}
		$assignment_stmt = $this->db->prepare('SELECT teacher_id, course_id, academic_year_id, grado, seccion FROM teacher_courses WHERE id = ? AND school_id = ? LIMIT 1');
		$assignment_stmt->bind_param('ii', $id, $school_id);
		$assignment_stmt->execute();
		$assignment = $assignment_stmt->get_result()->fetch_assoc();
		$assignment_stmt->close();
		$stmt = $this->db->prepare('DELETE FROM teacher_courses WHERE id = ? AND school_id = ?');
		$stmt->bind_param('ii', $id, $school_id);
		$delete = $stmt->execute();
		$affected = $stmt->affected_rows;
		$stmt->close();
		if ($delete && $affected > 0) {
			if ($assignment) $this->audit_teacher((int)$assignment['teacher_id'], $school_id, 'course_unassigned', $assignment);
			return json_encode(['status' => 1]);
		}
		return json_encode(['status' => 0, 'message' => 'La asignación no existe o pertenece a otra institución.']);
	}

	function save_teacher_user() {
		extract($_POST);
		
		// Asegurarnos de que school_id sea un valor numérico válido
		$school_id = intval($school_id);
		
		// Validar que school_id sea el mismo que del administrador actual
		if ($school_id != $_SESSION['login_school_id']) {
			return 3; // Error: Intento de asignar a otro colegio
		}
		
		// Obtener el nombre del docente
		$teacher_info = $this->db->query("SELECT name FROM teacher WHERE id = '$teacher_id' LIMIT 1");
		$teacher_name = "";
		
		$is_director = isset($is_director) ? 1 : 0;
		
		if ($teacher_info && $teacher_info->num_rows > 0) {
			$teacher_data = $teacher_info->fetch_assoc();
			$teacher_name = $teacher_data['name'];
		}
		
		if (empty($id)) {
			// Crear nuevo usuario
			$check = $this->db->query("SELECT * FROM users WHERE username = '$username'")->num_rows;
			if ($check > 0) {
				return 2; // Error: Nombre de usuario ya existe
			}
			
			// Hashear la contraseña
			$password = md5($password);
			
			// Insertar usuario asociado al docente y al colegio, incluyendo el nombre
			$save = $this->db->query("INSERT INTO users 
                                 (username, password, name, type, teacher_id, school_id, is_director) 
                                 VALUES ('$username', '$password', '$teacher_name', 2, '$teacher_id', '$school_id', $is_director)");
		} else {
			// Actualizar usuario existente
			$check = $this->db->query("SELECT * FROM users WHERE username = '$username' AND id != '$id'")->num_rows;
			if ($check > 0) {
				return 2; // Error: Nombre de usuario ya existe
			}
			
			// Actualizar sin cambiar la contraseña si está vacía
			if (empty($password)) {
				$save = $this->db->query("UPDATE users 
                                   SET username = '$username', name = '$teacher_name', school_id = '$school_id', is_director = $is_director 
                                   WHERE id = '$id'");
			} else {
				$password = md5($password);
				$save = $this->db->query("UPDATE users 
                                   SET username = '$username', password = '$password', name = '$teacher_name', school_id = '$school_id', is_director = $is_director 
                                   WHERE id = '$id'");
			}
		}
		
		if ($save) {
			$this->audit_teacher((int)$teacher_id, $school_id, empty($id) ? 'user_created' : 'user_updated', ['username' => $username, 'is_director' => $is_director]);
			return 1;
		}
		return 0;
	}

	function save_evaluation() {
    extract($_POST);
    
    // Obtener el año académico activo (primero del formulario, si no del sistema)
    $school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
    $academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;
    $competency_change_mode = isset($_POST['competency_change_mode']) ? trim($_POST['competency_change_mode']) : 'preserve';
    if ($academic_year_id <= 0 && $school_id > 0) {
        $ay_query = $this->db->query("SELECT id FROM academic_year WHERE school_id = $school_id AND is_active = 1 LIMIT 1");
        if ($ay_query && $ay_query->num_rows > 0) {
            $academic_year_id = $ay_query->fetch_assoc()['id'];
        }
    }
    
    $data = [
        "title" => $this->db->real_escape_string($title),
        "description" => $this->db->real_escape_string($description),
        "type" => $this->db->real_escape_string($type),
        // "course_id" => intval($course_id), // Ya no se usa
        // "grado" => $this->db->real_escape_string($grado), // Ya no se usa
        // "seccion" => $this->db->real_escape_string($seccion), // Ya no se usa
        // "level" => isset($level) ? $this->db->real_escape_string($level) : '', // Ya no se usa
        "bimestre" => $this->db->real_escape_string($bimestre),
        "teacher_id" => intval($teacher_id),
        "teacher_course_id" => intval($teacher_course_id),
        "academic_year_id" => $academic_year_id // Añadir el año académico
    ];
    
	$columns = [];
	$values = [];
	$updates = [];
    
	foreach ($data as $key => $value) {
		$columns[] = "`$key`";
		$values[] = "'$value'";
		$updates[] = "`$key` = '$value'";
	}

	// De-duplicación: si ya existe una evaluación equivalente, convertir a UPDATE
	if (empty($id)) {
		$esc_title = $this->db->real_escape_string($title);
		$esc_bim = $this->db->real_escape_string($bimestre);
		$tid = intval($teacher_id);
		$tcid = intval($teacher_course_id);
		$ayid = intval($academic_year_id);
		$check = $this->db->query("SELECT id FROM evaluations WHERE title = '$esc_title' AND teacher_id = $tid AND teacher_course_id = $tcid AND academic_year_id = $ayid AND bimestre = '$esc_bim' LIMIT 1");
		if ($check && $check->num_rows > 0) {
			$id = intval($check->fetch_assoc()['id']);
		}
	}

	if (empty($id)) {
		$sql = "INSERT INTO evaluations (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ")";
	} else {
		$id = intval($id);
		$sql = "UPDATE evaluations SET " . implode(", ", $updates) . " WHERE id = $id";
	}
    
	// Bloqueos de bimestre: impedir crear/editar evaluaciones en bimestres bloqueados
	$school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
	$bimestre_int = isset($bimestre) ? intval($bimestre) : 0;
	if ($academic_year_id > 0 && $school_id > 0 && $bimestre_int >= 1 && $bimestre_int <= 4) {
		$this->db->query("CREATE TABLE IF NOT EXISTS bimester_locks (
			id INT AUTO_INCREMENT PRIMARY KEY,
			academic_year_id INT NOT NULL,
			school_id INT NOT NULL,
			bimester TINYINT NOT NULL,
			is_locked TINYINT NOT NULL DEFAULT 0,
			UNIQUE KEY uniq_year_school_bim (academic_year_id, school_id, bimester)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$lockRes = $this->db->query("SELECT is_locked FROM bimester_locks WHERE academic_year_id = $academic_year_id AND school_id = $school_id AND bimester = $bimestre_int");
		if ($lockRes && $lockRes->num_rows > 0) {
			$locked = intval($lockRes->fetch_assoc()['is_locked']);
			if ($locked == 1 && isset($_SESSION['login_type']) && $_SESSION['login_type'] == 2) {
				return json_encode(['status' => 0, 'message' => 'Este bimestre está bloqueado para ediciones.']);
			}
		}
	}

	$save = $this->db->query($sql);
    
    if ($save) {
        // Obtener el id de la evaluación (insert o update)
        $eval_id = empty($id) ? $this->db->insert_id : $id;
        // Guardar competencias asociadas (ahora usando competencias por curso)
		if (isset($_POST['competencias']) && is_array($_POST['competencias'])) {
			// Forzar a una sola competencia: tomar solo la primera seleccionada
			$comp_list = array_values($_POST['competencias']);
			$first_comp_input = count($comp_list) ? $comp_list[0] : 0;
			
			// Lógica para evaluación no oficial (Genera una competencia con 0%)
			if ($first_comp_input === 'unofficial') {
				$tcid = intval($teacher_course_id);
				$check_unofficial = $this->db->query("SELECT course_id, teacher_id FROM teacher_courses WHERE id = $tcid LIMIT 1");
				if ($check_unofficial && $check_unofficial->num_rows > 0) {
					$row_tc = $check_unofficial->fetch_assoc();
					$u_course = $row_tc['course_id'];
					$u_teacher = $row_tc['teacher_id'];
					$u_name = 'Evaluación No Oficial (No Promedia)';
					
					$check_exist = $this->db->query("SELECT id FROM general_course_competencies WHERE course_id = $u_course AND teacher_id = $u_teacher AND name = '$u_name' AND academic_year_id = $academic_year_id LIMIT 1");
					if ($check_exist && $check_exist->num_rows > 0) {
						$first_comp = intval($check_exist->fetch_assoc()['id']);
					} else {
						$this->db->query("INSERT INTO general_course_competencies (course_id, teacher_id, name, percentage, academic_year_id, is_active) VALUES ($u_course, $u_teacher, '$u_name', 0.00, $academic_year_id, 1)");
						$first_comp = $this->db->insert_id;
					}
				} else {
					$first_comp = 0;
				}
			} else {
				$first_comp = intval($first_comp_input);
			}
			
			// Obtener la competencia anterior para actualizar las notas
			$old_comp_id = 0;
			$old_comp_query = $this->db->query("SELECT competencia_id FROM evaluation_competencias WHERE evaluation_id = $eval_id LIMIT 1");
			if ($old_comp_query && $old_comp_query->num_rows > 0) {
				$old_comp_id = intval($old_comp_query->fetch_assoc()['competencia_id']);
			}
			
			// Mantener el vínculo histórico de la evaluación si ya hay notas registradas.
			$has_existing_grades = false;
			$grades_check = $this->db->query("SELECT id FROM evaluation_grades WHERE evaluation_id = $eval_id LIMIT 1");
			if ($grades_check && $grades_check->num_rows > 0) {
				$has_existing_grades = true;
			}
			
			if ($first_comp > 0) {
				// Verificar que la competencia pertenece a general_course_competencies
				$comp_check = $this->db->query("SELECT id FROM general_course_competencies WHERE id = $first_comp LIMIT 1");
				if ($comp_check && $comp_check->num_rows > 0) {
					$this->db->query("DELETE FROM evaluation_competencias WHERE evaluation_id = $eval_id");
					$target_comp_id = $first_comp;
					if ($has_existing_grades && $old_comp_id > 0 && $old_comp_id != $first_comp && $competency_change_mode !== 'replace') {
						$target_comp_id = $old_comp_id;
					}
					$insert_result = $this->db->query("INSERT INTO evaluation_competencias (evaluation_id, competencia_id) VALUES ($eval_id, $target_comp_id)");
					if (!$insert_result) {
						return json_encode(['status' => 0, 'message' => 'Error al guardar la competencia: ' . $this->db->error]);
					}
					if ($has_existing_grades && $competency_change_mode === 'replace' && $old_comp_id > 0 && $old_comp_id != $first_comp) {
						$update_grades = $this->db->query("UPDATE evaluation_grades SET competencia_id = $first_comp WHERE evaluation_id = $eval_id AND competencia_id = $old_comp_id");
						if (!$update_grades) {
							return json_encode(['status' => 0, 'message' => 'Error al actualizar las notas con la nueva competencia: ' . $this->db->error]);
						}
					}
				} else {
					return json_encode(['status' => 0, 'message' => 'La competencia seleccionada no existe.']);
				}
			}
		}
        return json_encode(['status' => 1, 'message' => 'Evaluación guardada exitosamente.']);
    } else {
        return json_encode(['status' => 0, 'message' => 'Error al guardar la evaluación: ' . $this->db->error]);
    }
}
	function delete_evaluation() {
		extract($_POST);
		$this->db->query("DELETE FROM evaluation_grades WHERE evaluation_id = $id");
		$delete = $this->db->query("DELETE FROM evaluations WHERE id = $id");
		if ($delete) {
			return json_encode(['status' => 1]);
		}
		return json_encode(['status' => 0, 'message' => 'Error al eliminar la evaluación.']);
	}
	function save_evaluation_grades() {
		extract($_POST);
		
		if (!isset($grades) || !is_array($grades)) {
			return json_encode(['status' => 0, 'message' => 'No hay notas para guardar.']);
		}
		
		// Verificar bloqueo de bimestre antes de guardar notas
		$school_id = isset($_SESSION['login_school_id']) ? intval($_SESSION['login_school_id']) : 0;
		$eval_q = $this->db->query("SELECT bimestre, academic_year_id FROM evaluations WHERE id = " . intval($evaluation_id) . " LIMIT 1");
		if ($eval_q && $eval_q->num_rows > 0) {
			$eval_row = $eval_q->fetch_assoc();
			$bim = intval($eval_row['bimestre']);
			$ay = intval($eval_row['academic_year_id']);
			if ($bim >= 1 && $bim <= 4 && $ay > 0 && $school_id > 0) {
				$this->db->query("CREATE TABLE IF NOT EXISTS bimester_locks (
					id INT AUTO_INCREMENT PRIMARY KEY,
					academic_year_id INT NOT NULL,
					school_id INT NOT NULL,
					bimester TINYINT NOT NULL,
					is_locked TINYINT NOT NULL DEFAULT 0,
					UNIQUE KEY uniq_year_school_bim (academic_year_id, school_id, bimester)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
				$lr = $this->db->query("SELECT is_locked FROM bimester_locks WHERE academic_year_id = $ay AND school_id = $school_id AND bimester = $bim");
				if ($lr && $lr->num_rows > 0) {
					$locked = intval($lr->fetch_assoc()['is_locked']);
					if ($locked == 1 && isset($_SESSION['login_type']) && $_SESSION['login_type'] == 2) {
						return json_encode(['status' => 0, 'message' => 'Este bimestre está bloqueado para registrar o editar notas.']);
					}
				}
			}
		}

		// Determinar si se está usando sistema de letras o numérico
		$grading_system = $_POST['grading_system_used'] ?? 'numeric';
		
		// Nuevo formato: grades[competencia_id][student_id] = grade
		foreach ($grades as $comp_id => $stu_grades) {
            $comp_id = intval($comp_id);
            if (!is_array($stu_grades)) continue;
            
            foreach ($stu_grades as $student_id => $grade) {
                $student_id = intval($student_id);
                
                // Procesar la calificación según el sistema usado
                if ($grading_system === 'letters') {
                    // Validar que sea una letra válida
                    $grade = trim(strtoupper($grade));
                    if (!in_array($grade, ['C', 'B', 'A', 'AD']) && $grade !== '') {
                        return json_encode(['status' => 0, 'message' => 'Calificación inválida: ' . $grade . '. Use C, B, A o AD.']);
                    }
                    $grade_value = $this->db->real_escape_string($grade);
                } else {
                    // Sistema numérico
                    if ($grade !== '') {
                        $grade = floatval($grade);
                        if ($grade < 0 || $grade > 20) {
                            return json_encode(['status' => 0, 'message' => 'Las calificaciones numéricas deben estar entre 0 y 20.']);
                        }
                    }
                    $grade_value = $grade === '' ? '' : floatval($grade);
                }
                
                // Verificar si ya existe
                $q = $this->db->query("SELECT id FROM evaluation_grades WHERE evaluation_id = '$evaluation_id' AND student_id = '$student_id' AND competencia_id = '$comp_id'");
                if ($q && $q->num_rows > 0) {
                    if ($grade_value === '') {
                        // Si la calificación está vacía, eliminar el registro
                        $this->db->query("DELETE FROM evaluation_grades WHERE evaluation_id = '$evaluation_id' AND student_id = '$student_id' AND competencia_id = '$comp_id'");
                    } else {
                        $this->db->query("UPDATE evaluation_grades SET grade = '$grade_value' WHERE evaluation_id = '$evaluation_id' AND student_id = '$student_id' AND competencia_id = '$comp_id'");
                    }
                } else {
                    if ($grade_value !== '') {
                        $this->db->query("INSERT INTO evaluation_grades (evaluation_id, student_id, competencia_id, grade) VALUES ('$evaluation_id', '$student_id', '$comp_id', '$grade_value')");
                    }
                }
            }
        }
        
        // Generar notificaciones de notas bajas después de guardar las notas
        $this->generate_low_grade_notifications($evaluation_id);
        return json_encode(['status' => 1, 'message' => 'Notas guardadas exitosamente.']);
	}
    // --- REGLAS DE ASISTENCIA ---
    function save_attendance_settings() {
        $use_day_rules = isset($_POST['use_day_rules']) ? intval($_POST['use_day_rules']) : 1;
        $default_early_time = $_POST['default_early_time'] ?? '07:00';
        $default_late_time = $_POST['default_late_time'] ?? '07:30';
        $ok = true;
        $ok = $ok && $this->save_setting('use_day_rules', $use_day_rules);
        $ok = $ok && $this->save_setting('default_early_time', $default_early_time);
        $ok = $ok && $this->save_setting('default_late_time', $default_late_time);
        return $ok ? 1 : 0;
    }
    function save_setting($key, $value) {
        $key = $this->db->real_escape_string($key);
        $value = $this->db->real_escape_string($value);
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		$has_school_col = false;
		$col = $this->db->query("SHOW COLUMNS FROM attendance_settings LIKE 'school_id'");
		if ($col && $col->num_rows > 0) {
			$has_school_col = true;
		}

		if ($has_school_col) {
			$exists = $this->db->query("SELECT id FROM attendance_settings WHERE setting_key = '$key' AND school_id = $school_id");
			if ($exists && $exists->num_rows > 0) {
				return $this->db->query("UPDATE attendance_settings SET setting_value = '$value' WHERE setting_key = '$key' AND school_id = $school_id");
			}
			return $this->db->query("INSERT INTO attendance_settings (school_id, setting_key, setting_value) VALUES ($school_id, '$key', '$value')");
		}

		$exists = $this->db->query("SELECT id FROM attendance_settings WHERE setting_key = '$key'");
		if ($exists && $exists->num_rows > 0) {
			return $this->db->query("UPDATE attendance_settings SET setting_value = '$value' WHERE setting_key = '$key'");
		}
		return $this->db->query("INSERT INTO attendance_settings (setting_key, setting_value) VALUES ('$key', '$value')");
    }
    function save_day_attendance_rule() {
        $day_of_week = $_POST['day_of_week'] ?? '';
        $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 0;
        $early_time = $_POST['early_time'] ?? '07:00:00';
        $late_time = $_POST['late_time'] ?? '07:30:00';
        if (!$day_of_week) return 0;
        $day_of_week = $this->db->real_escape_string($day_of_week);
        $early_time = $this->db->real_escape_string($early_time);
        $late_time = $this->db->real_escape_string($late_time);
		$school_id = intval($_SESSION['login_school_id'] ?? 0);
		$has_school_col = false;
		$col = $this->db->query("SHOW COLUMNS FROM attendance_rules LIKE 'school_id'");
		if ($col && $col->num_rows > 0) {
			$has_school_col = true;
		}

		if ($has_school_col) {
			$exists = $this->db->query("SELECT id FROM attendance_rules WHERE day_of_week = '$day_of_week' AND school_id = $school_id");
			if ($exists && $exists->num_rows > 0) {
				$ok = $this->db->query("UPDATE attendance_rules SET is_active = $is_active, early_time = '$early_time', late_time = '$late_time' WHERE day_of_week = '$day_of_week' AND school_id = $school_id");
				return $ok ? 1 : 0;
			}

			$day_map = [
				'Monday' => 'Lunes',
				'Tuesday' => 'Martes',
				'Wednesday' => 'Miércoles',
				'Thursday' => 'Jueves',
				'Friday' => 'Viernes',
				'Saturday' => 'Sábado',
				'Sunday' => 'Domingo'
			];
			$day_name_es = $this->db->real_escape_string($day_map[$day_of_week] ?? $day_of_week);
			$ok = $this->db->query("INSERT INTO attendance_rules (school_id, day_of_week, early_time, late_time, day_name_es, is_active) VALUES ($school_id, '$day_of_week', '$early_time', '$late_time', '$day_name_es', $is_active)");
			return $ok ? 1 : 0;
		}

		$ok = $this->db->query("UPDATE attendance_rules SET is_active = $is_active, early_time = '$early_time', late_time = '$late_time' WHERE day_of_week = '$day_of_week'");
		return $ok ? 1 : 0;
    }

    // Discount Management Methods
    function save_discount(){
        extract($_POST);
        if(empty($ef_id) || empty($discount_amount)) {
            return 0;
        }
        // Actualizar el registro de la deuda por su id
        $sql = "UPDATE student_ef_list SET discounted_amount = '$discount_amount' WHERE id = '$ef_id'";
        $save = $this->db->query($sql);
        return $save ? 1 : 0;
    }
    
    function delete_discount(){
        $student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
        $course_id = isset($_POST['course_id']) ? trim($_POST['course_id']) : '';
        error_log("[delete_discount] student_id: '".$student_id."' course_id: '".$course_id."'");
        if($student_id === '' || $course_id === '') {
            error_log("[delete_discount] Parámetros faltantes");
            return 0;
        }
        $stmt = $this->db->prepare("UPDATE student_ef_list SET discounted_amount = NULL WHERE student_id = ? AND course_id = ?");
        $stmt->bind_param('ss', $student_id, $course_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        error_log("[delete_discount] Filas afectadas: $affected");
        $stmt->close();
        return $affected > 0 ? 1 : 0;
    }
    
    function get_discount($id){
        $qry = $this->db->query("SELECT ef.*, s.name as student_name, s.id_no, s.school_id, s.nivel, s.grado,
                                c.course, c.description, c.level as course_level, c.grades, ay.year,
                                CONCAT(c.course, ' - ', c.level, ' (', s.grado, ') - ', COALESCE(ay.year, 'Sin año')) as concepto_concatenado
                                FROM student_ef_list ef 
                                INNER JOIN student s ON ef.student_id = s.id 
                                INNER JOIN courses c ON ef.course_id = c.id 
                                LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
                                WHERE ef.id = $id");
        $data = array();
        if($qry && $qry->num_rows > 0){
            foreach($qry->fetch_array() as $key => $val){
                if($key != 'password' && !is_numeric($key))
                    $data[$key] = $val;
            }
        }
        return $data;
    }
    
    function get_student_list(){
        $school_id = $_SESSION['login_school_id'] ?? 0;
        // Si no hay school_id en la sesión, no filtrar por colegio para evitar lista vacía
        if($school_id && is_numeric($school_id) && $school_id > 0){
            $qry = $this->db->query("SELECT * FROM student WHERE school_id = $school_id ORDER BY name ASC");
        } else {
            $qry = $this->db->query("SELECT * FROM student ORDER BY name ASC");
        }
        $data = array();
        if($qry && $qry->num_rows > 0){
            while($row = $qry->fetch_assoc()){
                $data[] = $row;
            }
        }
        return $data;
    }
    
    function get_course_list(){
        $qry = $this->db->query("SELECT * FROM courses ORDER BY course ASC");
        $data = array();
        while($row = $qry->fetch_assoc()){
            $data[] = $row;
        }
        return $data;
    }
    
    function clean_all_teacher_courses() {
        // Verificar que se ha confirmado la acción
        if (!isset($_POST['confirm']) || empty($_POST['confirm'])) {
            return json_encode(['status' => 0, 'message' => 'Acción no confirmada']);
        }
        
        // Obtener el school_id del usuario actual
        $school_id = $_SESSION['login_school_id'] ?? 0;
        if (!$school_id) {
            return json_encode(['status' => 0, 'message' => 'No se encontró el ID de la escuela']);
        }
        
        // Eliminar todas las asignaciones para esta escuela
        $delete = $this->db->query("DELETE FROM teacher_courses WHERE school_id = $school_id");
        
        if ($delete) {
            return json_encode(['status' => 1, 'message' => 'Se han eliminado todas las asignaciones de docentes a cursos exitosamente']);
        } else {
            return json_encode(['status' => 0, 'message' => 'Error al limpiar las asignaciones: ' . $this->db->error]);
        }
    }
    
    function clean_teacher_assignments() {
        // Verificar que se ha confirmado la acción
        if (!isset($_POST['confirm']) || empty($_POST['confirm'])) {
            return json_encode(['status' => 0, 'message' => 'Acción no confirmada']);
        }
        
        // Verificar que se proporcionó el ID del docente
        if (!isset($_POST['teacher_id']) || empty($_POST['teacher_id'])) {
            return json_encode(['status' => 0, 'message' => 'No se proporcionó el ID del docente']);
        }
        
        $teacher_id = intval($_POST['teacher_id']);
        
        // Obtener el school_id del usuario actual
        $school_id = $_SESSION['login_school_id'] ?? 0;
        if (!$school_id) {
            return json_encode(['status' => 0, 'message' => 'No se encontró el ID de la escuela']);
        }
        
        // Verificar que el docente pertenezca a la escuela del administrador
        $check = $this->db->query("SELECT id FROM teacher WHERE id = $teacher_id AND school_id = $school_id");
        if (!$check || $check->num_rows == 0) {
            return json_encode(['status' => 0, 'message' => 'No tiene permiso para eliminar las asignaciones de este docente']);
        }
        
        // Eliminar las asignaciones del docente
        $delete = $this->db->query("DELETE FROM teacher_courses WHERE teacher_id = $teacher_id AND school_id = $school_id");
        
        if ($delete) {
            return json_encode(['status' => 1, 'message' => 'Se han eliminado todas las asignaciones del docente exitosamente']);
        } else {
            return json_encode(['status' => 0, 'message' => 'Error al limpiar las asignaciones: ' . $this->db->error]);
        }
    }
    
    function generate_low_grade_notifications($evaluation_id) {
        // Umbral de notas desaprobadas para generar una notificación
        $min_low_grades = 3;
        $school_id = $_SESSION['login_school_id'] ?? 0;

        if (!$school_id || empty($evaluation_id)) {
            error_log("generate_low_grade_notifications: Missing school_id or evaluation_id.");
            return 0;
        }
        
        $evaluation_id = intval($evaluation_id);

        // 1. Obtener el contexto de la evaluación (bimestre, nivel, grado, sección, año académico)
        $eval_query = $this->db->query("
            SELECT e.bimestre, ac.level, tc.grado, tc.seccion, tc.academic_year_id
            FROM evaluations e
            JOIN teacher_courses tc ON e.teacher_course_id = tc.id
            JOIN academic_courses ac ON tc.course_id = ac.id
            WHERE e.id = $evaluation_id AND tc.school_id = $school_id
        ");

        if (!$eval_query || $eval_query->num_rows == 0) {
            error_log("generate_low_grade_notifications: Evaluation context not found for id: $evaluation_id");
            return 0;
        }
        $eval_data = $eval_query->fetch_assoc();
        $bimestre_context = $this->db->real_escape_string($eval_data['bimestre']);
        $level_context = $this->db->real_escape_string($eval_data['level']);
        $grado_context = $this->db->real_escape_string($eval_data['grado']);
        $seccion_context = $this->db->real_escape_string($eval_data['seccion']);
        $academic_year_id = intval($eval_data['academic_year_id']);

        // 2. Obtener la lista de estudiantes en ese grupo
        $students_query = $this->db->query("
            SELECT id FROM student 
            WHERE nivel = '$level_context' 
            AND grado = '$grado_context' 
            AND seccion = '$seccion_context' 
            AND school_id = $school_id
        ");
        
        if (!$students_query || $students_query->num_rows == 0) {
            return 0; // No hay estudiantes en este grupo
        }

        $student_ids = [];
        while ($row = $students_query->fetch_assoc()) {
            $student_ids[] = $row['id'];
        }
        $student_ids_list = implode(',', $student_ids);

        // 3. Limpiar notificaciones viejas para este grupo y bimestre
        // Primero los registros de lectura
        $this->db->query("DELETE FROM low_grade_notification_read WHERE notification_id IN (SELECT id FROM low_grade_notifications WHERE student_id IN ($student_ids_list) AND bimestre = '$bimestre_context')");
        // Luego las notificaciones
        $this->db->query("DELETE FROM low_grade_notifications WHERE student_id IN ($student_ids_list) AND bimestre = '$bimestre_context'");

        // 4. Obtener todas las notas bajas (<= 10) para este grupo de estudiantes en este bimestre
        $low_grades_query = $this->db->query("
            SELECT 
                eg.student_id,
                s.name as student_name,
                tc.course_id,
                c.name as course_name,
                tc.teacher_id
            FROM evaluation_grades eg
            JOIN evaluations e ON eg.evaluation_id = e.id
            JOIN student s ON eg.student_id = s.id
            JOIN teacher_courses tc ON e.teacher_course_id = tc.id
            JOIN academic_courses c ON tc.course_id = c.id
            WHERE eg.grade <= 10
            AND s.id IN ($student_ids_list)
            AND e.bimestre = '$bimestre_context'
            AND s.school_id = $school_id
            AND tc.academic_year_id = $academic_year_id
            ORDER BY eg.student_id, tc.course_id
        ");

        if (!$low_grades_query || $low_grades_query->num_rows == 0) {
            return 0; // No hay notas bajas, no hay nada que hacer
        }

        // 5. Procesar y agrupar las notas en PHP
        $student_course_data = [];
        while ($row = $low_grades_query->fetch_assoc()) {
            $student_id = $row['student_id'];
            $course_id = $row['course_id'];

            if (!isset($student_course_data[$student_id])) {
                $student_course_data[$student_id] = ['name' => $row['student_name'], 'courses' => []];
            }
            if (!isset($student_course_data[$student_id]['courses'][$course_id])) {
                $student_course_data[$student_id]['courses'][$course_id] = [
                    'name' => $row['course_name'],
                    'teacher_id' => $row['teacher_id'],
                    'count' => 0
                ];
            }
            $student_course_data[$student_id]['courses'][$course_id]['count']++;
        }

        $created_count = 0;

        // 6. Filtrar, agrupar por profesor y generar notificaciones
        foreach ($student_course_data as $student_id => $student_data) {
            $failing_courses = array_filter($student_data['courses'], function($course) use ($min_low_grades) {
                return $course['count'] >= $min_low_grades;
            });

            if (empty($failing_courses)) {
                continue; // No hay cursos con suficientes notas bajas para este estudiante
            }

            // Agrupar cursos por profesor
            $courses_by_teacher = [];
            $admin_courses = [];
            $total_low_grades = 0;

            foreach ($failing_courses as $course_id => $course) {
                $teacher_id = $course['teacher_id'];
                if (!isset($courses_by_teacher[$teacher_id])) {
                    $courses_by_teacher[$teacher_id] = [];
                }
                $courses_by_teacher[$teacher_id][] = ['name' => $course['name'], 'count' => $course['count']];
                $admin_courses[] = ['name' => $course['name'], 'count' => $course['count']];
                $total_low_grades += $course['count'];
            }

            // 7. Crear notificación para Administradores (teacher_id = NULL)
            if (!empty($admin_courses)) {
                $admin_json = json_encode($admin_courses, JSON_UNESCAPED_UNICODE);
                $this->db->query("
                    INSERT INTO low_grade_notifications (student_id, bimestre, count_low_grades, failed_courses, teacher_id, created_at) 
                    VALUES ($student_id, '$bimestre_context', $total_low_grades, '$admin_json', NULL, NOW())
                ");
                $created_count++;
            }

            // 8. Crear notificaciones para cada Profesor
            foreach ($courses_by_teacher as $teacher_id => $teacher_courses) {
                $teacher_json = json_encode($teacher_courses, JSON_UNESCAPED_UNICODE);
                $teacher_total_low_grades = array_sum(array_column($teacher_courses, 'count'));
                
                $this->db->query("
                    INSERT INTO low_grade_notifications (student_id, bimestre, count_low_grades, failed_courses, teacher_id, created_at) 
                    VALUES ($student_id, '$bimestre_context', $teacher_total_low_grades, '$teacher_json', $teacher_id, NOW())
                ");
                $created_count++;
            }
        }
        
        return $created_count;
    }
}
