<?php
include __DIR__ . '/db_connect.php';
// Asegurar sesión activa para acceder a $_SESSION tanto en vista completa como en carga parcial
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
$selected_status = $_GET['status'] ?? 'Activo';
$session_school_id = intval($_SESSION['login_school_id'] ?? 0);
$active_year_id = 0;
$active_year_name = 'Sin año activo';
if ($session_school_id > 0) {
	$active_year_result = $conn->query("SELECT id, year FROM academic_year WHERE school_id = $session_school_id AND is_active = 1 LIMIT 1");
	$active_year = $active_year_result ? $active_year_result->fetch_assoc() : null;
	if ($active_year) {
		$active_year_id = intval($active_year['id']);
		$active_year_name = $active_year['year'];
	}
}

function get_teachers_for_list($conn, $school_id, $status, $academic_year_id) {
	$school_id = (int)$school_id;
	$academic_year_id = (int)$academic_year_id;
	$status = in_array($status, ['Activo', 'Inactivo', 'all'], true) ? $status : 'Activo';
	$status_sql = $status === 'all' ? '' : " AND t.status = '" . $conn->real_escape_string($status) . "'";
	$sql = "SELECT t.*,
		(SELECT COUNT(*) FROM users u WHERE u.teacher_id = t.id AND u.school_id = t.school_id AND u.type = 2) AS user_count,
		(SELECT COUNT(*) FROM teacher_courses tc WHERE tc.teacher_id = t.id AND tc.school_id = t.school_id AND tc.academic_year_id = $academic_year_id) AS course_count
		FROM teacher t
		WHERE t.school_id = $school_id$status_sql
		ORDER BY t.name ASC";
	return $conn->query($sql);
}

function get_teacher_summary($conn, $school_id, $academic_year_id) {
	$school_id = (int)$school_id;
	$academic_year_id = (int)$academic_year_id;
	$sql = "SELECT COUNT(*) AS total,
		COALESCE(SUM(t.status = 'Activo'), 0) AS active_count,
		COALESCE(SUM(t.status = 'Inactivo'), 0) AS inactive_count,
		COALESCE(SUM(t.status = 'Activo' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.teacher_id = t.id AND u.school_id = t.school_id AND u.type = 2)), 0) AS without_user,
		COALESCE(SUM(t.status = 'Activo' AND NOT EXISTS (SELECT 1 FROM teacher_courses tc WHERE tc.teacher_id = t.id AND tc.school_id = t.school_id AND tc.academic_year_id = $academic_year_id)), 0) AS without_courses
		FROM teacher t WHERE t.school_id = $school_id";
	$result = $conn->query($sql);
	$summary = $result ? $result->fetch_assoc() : null;
	return $summary ?: ['total' => 0, 'active_count' => 0, 'inactive_count' => 0, 'without_user' => 0, 'without_courses' => 0];
}
?>
<?php
if (isset($_GET['partial']) && $_GET['partial'] === 'summary') {
	header('Content-Type: application/json; charset=utf-8');
	if (empty($_SESSION['login_id'])) { http_response_code(401); echo json_encode(['status' => 0]); exit; }
	echo json_encode(['status' => 1, 'summary' => get_teacher_summary($conn, $session_school_id, $active_year_id)]);
	exit;
}

// Endpoint parcial: devolver solo el tbody para refrescar la tabla sin recargar la página
if (isset($_GET['partial']) && $_GET['partial'] === 'tbody') {
	if (empty($_SESSION['login_id'])) {
		http_response_code(401);
		exit;
	}
	$i = 1;
	$school_id = $session_school_id;
	$status_filter = $selected_status;
	if (!in_array($status_filter, ['Activo', 'Inactivo', 'all'], true)) $status_filter = 'Activo';
	$teacher = get_teachers_for_list($conn, $school_id, $status_filter, $active_year_id);
	include __DIR__ . '/teacher_rows.php';
	exit;
}
$teacher_summary = get_teacher_summary($conn, $session_school_id, $active_year_id);
?>
<style>
/* Estilos generales para la página */
.ed-teachers-page .main-content-area {
    padding: 10px 5px;
}
.teacher-summary-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:14px; margin-bottom:18px; }
.teacher-summary-card { background:#fff; border:1px solid #e3e6f0; border-radius:9px; padding:15px; display:flex; align-items:center; box-shadow:0 2px 10px rgba(0,0,0,.04); }
.teacher-summary-icon { width:42px; height:42px; border-radius:9px; display:flex; align-items:center; justify-content:center; margin-right:12px; font-size:1.1rem; }
.teacher-summary-value { font-size:1.35rem; line-height:1; font-weight:800; color:#2c4964; }
.teacher-summary-label { color:#6c757d; font-size:.78rem; margin-top:5px; }
.summary-active .teacher-summary-icon { background:#d4edda; color:#218838; }
.summary-inactive .teacher-summary-icon { background:#e2e3e5; color:#5a5c69; }
.summary-user .teacher-summary-icon { background:#fff3cd; color:#856404; }
.summary-course .teacher-summary-icon { background:#d9edf7; color:#31708f; }
.teacher-actions-menu .dropdown-item i { width:20px; margin-right:6px; text-align:center; }
.teacher-actions-menu .dropdown-item.text-danger:hover { background:#f8d7da; color:#a71d2a !important; }
@media (max-width:991px) { .teacher-summary-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
@media (max-width:575px) { .teacher-summary-grid { grid-template-columns:1fr; } }

/* Nuevo encabezado compacto y limpio */
.ed-teachers-page .page-title-wrapper {
    margin-bottom: 16px;
}
.ed-teachers-page .page-title {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 6px 10px;
}
.page-title .title-left {
	display: flex;
	align-items: center;
}
.page-title .title-icon {
	width: 44px;
	height: 44px;
	border-radius: 10px;
	background: linear-gradient(135deg, rgba(66,133,244,0.12), rgba(42,117,243,0.08));
	display: flex;
	align-items: center;
	justify-content: center;
	margin-right: 10px;
	color: #2a75f3;
	font-size: 1.1rem;
}
.page-title .page-title-text {
	font-size: 1.05rem;
	font-weight: 700;
	color: #233b52;
}
.page-title .page-title-sub {
	font-size: 0.85rem;
	color: #6c757d;
	margin-top: 2px;
	font-weight: 500;
}
.page-title .title-actions { 
	display: flex;
	align-items: center;
}

@media (max-width: 575px) {
	.page-title .page-title-sub { display: none; }
	.page-title .title-icon { width: 40px; height: 40px; }
}

.action-buttons .btn {
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    font-weight: 500;
}

.action-buttons .btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.12);
}

.action-buttons .btn i {
    margin-right: 6px;
}

.ed-teachers-page .content-card { /* Clase genérica para la tarjeta */
    border-radius: 8px;
    box-shadow: 0 2px 15px rgba(0,0,0,0.05);
    border: none;
    margin-bottom: 20px;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}
.ed-teachers-page .content-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}
.ed-teachers-page .card-header {
	padding: 12px 18px;
	background: #fff;
	border-bottom: 1px solid #e3e6f0;
	font-weight: 600;
	font-size: 1.05rem;
	display: flex;
	align-items: center;
	justify-content: space-between;
	position: relative;
	overflow: visible;
}
.card-header .btn {
    border-radius: 6px;
    font-weight: 500;
    transition: all 0.2s;
    background: #fff;
    color: #2a75f3;
    border: 1px solid #2a75f3;
    box-shadow: 0 2px 5px rgba(66,133,244,0.10);
}
.card-header .btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(66,133,244,0.15);
    background: #e8f4fe;
    color: #1a65e3;
}
.card-header .dropdown-menu {
	position: absolute;
	top: 100%;
	right: 18px;
	margin-top: 8px;
	z-index: 1050;
}
.teacher-add-menu { width:310px; max-width:calc(100vw - 30px); padding:8px; border:0; border-radius:10px; box-shadow:0 10px 30px rgba(35,59,82,.18); }
.teacher-add-menu .dropdown-header { padding:7px 10px 5px; color:#858796; font-size:.7rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
.teacher-add-menu .dropdown-item { display:flex; align-items:center; padding:9px 10px; border-radius:7px; white-space:normal; }
.teacher-add-menu .dropdown-item:hover { background:#f3f6ff; }
.teacher-add-menu .menu-icon { width:38px; height:38px; flex:0 0 38px; display:flex; align-items:center; justify-content:center; border-radius:8px; margin-right:10px; }
.teacher-add-menu .menu-copy { min-width:0; }
.teacher-add-menu .menu-title { display:block; color:#2c4964; font-size:.88rem; font-weight:700; line-height:1.2; }
.teacher-add-menu .menu-description { display:block; color:#858796; font-size:.72rem; line-height:1.25; margin-top:3px; }
.menu-icon-new { background:#e8f0fe; color:#2e59d9; }.menu-icon-upload { background:#e8f9f0; color:#1e7e34; }.menu-icon-template { background:#fff3cd; color:#856404; }.menu-icon-export { background:#e2e3e5; color:#5a5c69; }
.ed-teachers-page .table-custom { /* Clase genérica para la tabla */
    box-shadow: none;
    border-radius: 0;
    background: #fff;
}
.table-custom th, .table-custom td {
    vertical-align: middle !important;
    padding: 12px 15px;
}
.table-custom thead th {
    background: #f8f9fa;
    color: #2c4964;
    font-weight: 600;
    border-bottom: 2px solid #e3e6f0;
    border-top: none;
}
.table-hover tbody tr {
    transition: background-color 0.2s;
}
.table-hover tbody tr:hover {
    background-color: rgba(252, 125, 28, 0.05);
}
.ed-teachers-page .btn {
    border-radius: 6px;
    font-weight: 500;
    transition: all 0.2s;
}
.ed-teachers-page .btn-primary, .ed-teachers-page .btn-primary:focus {
  background:var(--edu-primary,#4285f4);
  border-color:var(--edu-primary,#4285f4);
  color:#fff;
}
.ed-teachers-page .btn-primary:hover {
	background: #357ae8;
	border-color: #2a63d6;
	color: #fff;
}
.ed-teachers-page .btn-danger {
    background: #fff;
    color: #dc3545;
    border: 1px solid #dc3545;
}
.ed-teachers-page .btn-danger:hover {
    background: #f8d7da;
    color: #a71d2a;
}
.ed-teachers-page .btn-success {
    background: #fff;
    color: #28a745;
    border: 1px solid #28a745;
}
.ed-teachers-page .btn-success:hover {
    background: #e8f9f0;
    color: #218838;
}
.ed-teachers-page .btn-success:hover {
    background: #e8f9f0;
    color: #218838;
}
.dataTables_wrapper .dataTables_filter input {
    border: 1px solid #e3e6f0;
    border-radius: 6px;
    padding: 6px 12px;
    margin-left: 8px;
}
.ed-teachers-page .dataTables_info, .ed-teachers-page .dataTables_paginate {
    margin-top: 15px;
}
.dataTables_paginate .paginate_button {
    border-radius: 4px !important;
    margin: 0 2px;
}
.dataTables_paginate .paginate_button.current {
    background: #4285f4 !important;
    border-color: #2a75f3 !important;
    color: white !important;
}
@media (max-width: 768px) {
    .ed-teachers-page .btn {
        padding: 5px 8px;
        min-width: 32px;
    }
    .ed-teachers-page .table-responsive {
        overflow-x: auto;
    }
}
@media (max-width: 576px) {
    .ed-teachers-page .btn {
        padding: 4px 6px;
        min-width: 28px;
    }
    .btn .badge {
        font-size: 0.65rem;
    }
}
td { vertical-align: middle !important; }
td p { margin: unset }
img { max-width: 100px; max-height: 150px; }

/* Docentes: cabecera y menú de acciones coordinados con Gestión de Estudiantes. */
.ed-teachers-page{padding:.25rem 0!important}
.ed-teachers-page .ed-teachers-header{margin-bottom:.85rem!important;gap:.65rem;padding:.75rem 1rem!important}
.ed-teachers-page .ed-page-heading{min-width:0}
.ed-teachers-page .ed-page-title{font-size:1.17rem!important;line-height:1.3;margin:0}
.ed-teachers-page .ed-page-subtitle{font-size:.77rem;line-height:1.3}
.ed-teachers-page .ed-page-icon{width:38px;height:38px;flex:0 0 38px}
.ed-teachers-page .ed-page-actions .btn{height:34px;padding:.35rem .7rem;font-size:.8rem}
.ed-teachers-page .teacher-summary-grid{gap:.7rem;margin-bottom:.85rem}
.ed-teachers-page .teacher-summary-card{
    min-height:70px;padding:.7rem .85rem;box-shadow:none;border-radius:.55rem;
    border:1px solid #e3e6f0;gap:.65rem
}
.ed-teachers-page .teacher-summary-icon{width:36px;height:36px;flex:0 0 36px;margin:0;border-radius:.5rem}
.ed-teachers-page .teacher-summary-value{font-size:1.2rem;font-weight:700}
.ed-teachers-page .teacher-summary-label{font-size:.76rem;margin-top:.2rem;line-height:1.3}
.ed-teachers-page .content-card{
    border:1px solid #e3e6f0;box-shadow:none!important;
    border-radius:.55rem;margin-bottom:1rem;transition:none
}
.ed-teachers-page .content-card:hover{box-shadow:none!important;transform:none!important}
.ed-teachers-page .content-card > .card-header{
    min-height:40px;padding:.55rem .85rem!important;
    font-size:.92rem!important;background:#fff
}
.ed-teachers-page .content-card > .card-body{padding:.7rem .85rem}
.ed-teachers-page .ed-teachers-filterbar{
    gap:.4rem;background:#f8f9fc;border:1px solid #edf0f5;
    padding:.6rem .75rem;border-radius:.45rem;margin-bottom:.75rem!important
}
.ed-teachers-page .ed-teachers-filterbar label{font-size:.75rem;margin:0!important}
.ed-teachers-page .ed-teachers-filterbar select{height:32px;font-size:.79rem}
.ed-teachers-page .table-custom th,.ed-teachers-page .table-custom td{
    padding:.55rem .7rem!important;font-size:.81rem
}
.ed-teachers-page .table-custom tbody tr:hover{background:#f7faff!important;box-shadow:none!important;transform:none!important}
.ed-teachers-page .content-card .table-responsive{margin:0}
#uploadTeacherExcelModal .ed-import-modal-header{background:#fff;border-bottom:1px solid #edf0f5}
#uploadTeacherExcelModal .modal-content,#bulkTeachersModal .modal-content,
#teacherEmploymentActionModal .modal-content{border-radius:.6rem}
@media(max-width:767.98px){
    .ed-teachers-page .ed-teachers-header{padding:.65rem .75rem!important}
    .ed-teachers-page .teacher-summary-grid{gap:.6rem}
    .ed-teachers-page .content-card > .card-body{padding:.5rem}
    .ed-teachers-page .ed-teachers-filterbar{padding:.55rem .6rem}
}

/* Gestión de Docentes: armonía con Estudiantes, sin modificar datos ni otros módulos. */
.ed-teachers-page .ed-teachers-header{
  border-left:4px solid var(--edu-primary,#4285f4);
  background:#fff;
  margin-bottom:.7rem!important;
}
.ed-teachers-page .ed-page-heading{min-width:0;flex:1 1 280px}
.ed-teachers-page .ed-page-subtitle{margin:0!important;color:#6e7891}
.ed-teachers-page .ed-page-actions .btn-group{width:auto}
.ed-teachers-page .ed-page-actions .btn{
  min-width:108px;
  margin:0!important;
  box-shadow:none!important;
  transform:none!important;
}
.ed-teachers-page .teacher-summary-grid{
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:.55rem;
  margin-bottom:.75rem;
}
.ed-teachers-page .teacher-summary-card{
  min-width:0;
  background:#fff;
  box-shadow:none;
  padding:.65rem .75rem;
}
.ed-teachers-page .teacher-summary-value{
  color:#233b52;
  font-variant-numeric:tabular-nums;
}
.ed-teachers-page .content-card{overflow:visible}
.ed-teachers-page .content-card > .card-body{padding:.7rem .8rem!important}
.ed-teachers-page .ed-teachers-filterbar{
  display:flex;
  gap:.55rem;
  justify-content:flex-start;
  align-items:end;
  flex-wrap:wrap;
  margin-bottom:.65rem!important;
  background:#f8fafd;
  padding:.6rem .7rem!important;
}
.ed-teachers-page .ed-teachers-filterbar label{font-weight:700;color:#526274}
.ed-teachers-page .ed-teachers-filterbar select.form-control{
  min-width:160px;
  min-height:34px;
  border:1px solid #d8deea;
  border-radius:.4rem;
}
.ed-teachers-page #teacher-bulk-toolbar{
  background:#f4f8ff!important;
  border-color:#dbe7fa!important;
  padding:.55rem .7rem;
  border-radius:.45rem;
  gap:.55rem;
}
.ed-teachers-page #teachers-table thead th{
  background:#f8fafd;
  border-bottom:1px solid #dce4ef;
  color:#526274;
  font-size:.75rem;
  white-space:nowrap;
}
.ed-teachers-page #teachers-table td{
  padding:.54rem .65rem!important;
  line-height:1.32;
  font-size:.79rem;
  color:#344054;
}
.ed-teachers-page #teachers-table .badge{
  font-size:.73rem;
  border-radius:.38rem;
  box-shadow:none;
}
.ed-teachers-page .dataTables_wrapper .dataTables_filter,
.ed-teachers-page .dataTables_wrapper .dataTables_length,
.ed-teachers-page .dataTables_wrapper .dataTables_info,
.ed-teachers-page .dataTables_wrapper .dataTables_paginate{font-size:.79rem}
.ed-teachers-page .dataTables_wrapper .dataTables_filter input{
  min-width:155px;
  min-height:33px;
  box-shadow:none;
  border-radius:.4rem;
  font-size:.79rem;
}
.ed-teachers-page .teacher-add-menu{
  max-width:calc(100vw - 1.5rem);
  padding:.5rem;
}
.ed-teachers-page .teacher-add-menu .dropdown-item{
  padding:.56rem .65rem;
}
/* Sólo los modales del módulo Docentes; no se alteran los de otros módulos. */
body[data-edusync-page="teachers"] #uploadTeacherExcelModal .ed-teacher-upload-actions{
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:.5rem;
  flex-wrap:wrap;
  padding-top:.7rem;
  border-top:1px solid #edf0f5;
}
body[data-edusync-page="teachers"] #uploadTeacherExcelModal .ed-teacher-upload-actions .btn{
  min-height:34px;
  min-width:98px;
  box-shadow:none!important;
  margin:0!important;
}
body[data-edusync-page="teachers"] :is(#bulkTeachersModal,#teacherEmploymentActionModal) .modal-footer{
  gap:.45rem;
}
body[data-edusync-page="teachers"] :is(#bulkTeachersModal,#teacherEmploymentActionModal) .modal-footer .btn{
  min-height:34px;
  font-size:.8rem;
  margin:0!important;
  box-shadow:none!important;
}
@media(max-width:991.98px){
  .ed-teachers-page .teacher-summary-grid{
    grid-template-columns:repeat(2,minmax(0,1fr));
  }
}
@media(max-width:767.98px){
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] #content .ed-teachers-page .ed-teachers-header{
    display:flex!important;
    flex-direction:row!important;
    flex-wrap:nowrap!important;
    justify-content:space-between!important;
    align-items:center!important;
    gap:.44rem!important;
    padding:.45rem .62rem!important;
    min-height:52px;
  }
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] #content .ed-teachers-page .ed-page-heading{
    display:flex!important;
    align-items:center!important;
    flex:1 1 auto;
    gap:.48rem!important;
    min-width:0;
  }
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] #content .ed-teachers-page .ed-page-icon{
    width:31px!important;height:31px!important;flex:0 0 31px!important;
    border-radius:.42rem!important;font-size:.88rem;
  }
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] #content .ed-teachers-page .ed-page-title{
    font-size:.98rem!important;
    line-height:1.25!important;
    white-space:nowrap;
  }
  .ed-teachers-page .ed-teachers-title-prefix,
  .ed-teachers-page .ed-page-subtitle{display:none!important}
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] #content .ed-teachers-page .ed-page-actions{
    flex:0 0 auto!important;
    display:flex!important;
    width:auto!important;
    max-width:none!important;
    margin:0!important;
    align-items:center!important;
  }
  .ed-teachers-page .ed-page-actions .btn-group,
  .ed-teachers-page .ed-page-actions .btn{
    flex:0 0 auto!important;
    width:auto!important;
    margin:0!important;
    max-width:none!important;
  }
  .ed-teachers-page .ed-page-actions .btn{
    height:32px!important;min-height:32px!important;
    min-width:0!important;padding:.27rem .53rem!important;
    font-size:.77rem!important;
  }
  .ed-teachers-page .teacher-add-menu{
    width:min(300px,calc(100vw - 2rem));
    max-width:calc(100vw - 2rem);
    right:0;left:auto;
  }
  .ed-teachers-page .ed-teachers-filterbar{
    margin-bottom:.55rem!important;
  }
  .ed-teachers-page .ed-teachers-filterbar select.form-control{
    min-width:min(200px,100%);
  }
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] :is(#uni_modal,#uploadTeacherExcelModal,#bulkTeachersModal,#teacherEmploymentActionModal) .modal-dialog{
    margin:.48rem auto!important;
    width:calc(100% - .96rem)!important;
    max-width:calc(100% - .96rem)!important;
  }
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] :is(#uni_modal,#uploadTeacherExcelModal,#bulkTeachersModal,#teacherEmploymentActionModal) .modal-content{
    display:flex;
    flex-direction:column;
    max-height:calc(100dvh - .96rem);
    overflow:hidden;
  }
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] :is(#uni_modal,#uploadTeacherExcelModal,#bulkTeachersModal,#teacherEmploymentActionModal) .modal-header{
    min-height:44px!important;
    padding:.48rem .72rem!important;
    flex:0 0 auto;
  }
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] :is(#uni_modal,#uploadTeacherExcelModal,#bulkTeachersModal,#teacherEmploymentActionModal) .modal-body{
    min-height:0;
    overflow-y:auto;
    overscroll-behavior-y:contain;
    -webkit-overflow-scrolling:touch;
    padding:.68rem .72rem!important;
    flex:1 1 auto;
  }
  body[data-edusync-ui="standard-v2"][data-edusync-page="teachers"] :is(#bulkTeachersModal,#teacherEmploymentActionModal) .modal-footer{
    flex:0 0 auto;
    padding:.64rem .72rem!important;
  }
  .ed-teachers-page #teacher-bulk-toolbar{
    flex-direction:column;
    align-items:stretch!important;
  }
  .ed-teachers-page #teacher-bulk-toolbar > div{
    display:flex;flex-wrap:wrap;gap:.4rem;
  }
  .ed-teachers-page #teacher-bulk-toolbar .btn{margin:0!important}
}
@media(max-width:359.98px){
  .ed-teachers-page .ed-page-actions .btn{padding:.25rem .42rem!important}
  .ed-teachers-page .ed-page-title{font-size:.91rem!important}
  .ed-teachers-page .teacher-summary-card{
    padding:.53rem .55rem;
    gap:.4rem;
  }
  .ed-teachers-page .teacher-summary-icon{
    width:30px;height:30px;flex-basis:30px;
  }
  .ed-teachers-page .teacher-summary-value{font-size:1.05rem}
  .ed-teachers-page .teacher-summary-label{font-size:.69rem}
}
@media print{
  body[data-edusync-page="teachers"] #uni_modal .modal-content,
  body[data-edusync-page="teachers"] #uni_modal #uni_modal_body{
    max-height:none!important;overflow:visible!important;
  }
}
</style>

<!-- Contenido principal -->
<div class="container-fluid">
    <div class="main-content-area ed-teachers-page">
        <!-- Encabezado compacto de gestión: mismas acciones y menú existente -->
        <div class="ed-page-header ed-teachers-header">
            <div class="ed-page-heading">
                <div class="ed-page-icon"><i class="fa fa-chalkboard-teacher" aria-hidden="true"></i></div>
                <div>
                    <h1 class="ed-page-title"><span class="ed-teachers-title-prefix">Gestión de </span>Docentes</h1>
                    <p class="ed-page-subtitle">Lista y administración de docentes</p>
                </div>
            </div>
            <div class="ed-page-actions">
                <div class="btn-group teacher-add-dropdown">
                            <button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fa fa-ellipsis-h mr-1"></i>Acciones
                            </button>
                            <div class="dropdown-menu dropdown-menu-right teacher-add-menu">
                                <h6 class="dropdown-header">Registro</h6>
                                <a class="dropdown-item" href="javascript:void(0)" id="action_new_teacher">
                                    <span class="menu-icon menu-icon-new"><i class="fa fa-user-plus"></i></span><span class="menu-copy"><span class="menu-title">Nuevo docente</span><span class="menu-description">Registrar manualmente un docente.</span></span>
                                </a>
                                <div class="dropdown-divider"></div><h6 class="dropdown-header">Importación</h6>
                                <a class="dropdown-item" href="javascript:void(0)" id="action_upload_teacher_excel">
                                    <span class="menu-icon menu-icon-upload"><i class="fa fa-file-excel"></i></span><span class="menu-copy"><span class="menu-title">Importar desde Excel</span><span class="menu-description">Agregar varios docentes desde un archivo.</span></span>
                                </a>
                                <a class="dropdown-item" href="javascript:void(0)" id="action_download_teacher_format">
                                    <span class="menu-icon menu-icon-template"><i class="fa fa-file-download"></i></span><span class="menu-copy"><span class="menu-title">Descargar plantilla</span><span class="menu-description">Obtener el formato correcto para importar.</span></span>
                                </a>
                                <div class="dropdown-divider"></div><h6 class="dropdown-header">Exportación</h6>
                                <a class="dropdown-item" href="javascript:void(0)" id="action_export_teachers">
                                    <span class="menu-icon menu-icon-export"><i class="fa fa-download"></i></span><span class="menu-copy"><span class="menu-title">Exportar docentes</span><span class="menu-description">Descargar en CSV según el filtro actual.</span></span>
                                </a>
                            </div>
                        </div>
            </div>
        </div>

        <div class="teacher-summary-grid" id="teacher-summary-grid">
            <div class="teacher-summary-card summary-active"><div class="teacher-summary-icon"><i class="fa fa-user-check"></i></div><div><div class="teacher-summary-value" id="summary-active"><?php echo (int)$teacher_summary['active_count']; ?></div><div class="teacher-summary-label">Docentes activos</div></div></div>
            <div class="teacher-summary-card summary-inactive"><div class="teacher-summary-icon"><i class="fa fa-user-slash"></i></div><div><div class="teacher-summary-value" id="summary-inactive"><?php echo (int)$teacher_summary['inactive_count']; ?></div><div class="teacher-summary-label">Docentes inactivos</div></div></div>
            <div class="teacher-summary-card summary-user"><div class="teacher-summary-icon"><i class="fa fa-user-lock"></i></div><div><div class="teacher-summary-value" id="summary-without-user"><?php echo (int)$teacher_summary['without_user']; ?></div><div class="teacher-summary-label">Activos sin usuario</div></div></div>
            <div class="teacher-summary-card summary-course"><div class="teacher-summary-icon"><i class="fa fa-book-open"></i></div><div><div class="teacher-summary-value" id="summary-without-courses"><?php echo (int)$teacher_summary['without_courses']; ?></div><div class="teacher-summary-label">Activos sin cursos · <?php echo htmlspecialchars($active_year_name, ENT_QUOTES, 'UTF-8'); ?></div></div></div>
        </div>

        <!-- Panel de la tabla -->
        <div class="row">
            <div class="col-md-12">
                <div class="card content-card">
                    <div class="card-header">
                        <span><b>Lista de docentes</b></span>
                    </div>
                    <div class="card-body">
						<div id="teacher-bulk-toolbar" class="alert alert-light border d-none align-items-center justify-content-between mb-3">
							<span><strong id="teacher-selected-count">0</strong> docentes seleccionados</span>
							<div><button type="button" id="export-selected-teachers" class="btn btn-outline-success btn-sm mr-2"><i class="fas fa-file-csv mr-1"></i>Exportar seleccionados</button><button type="button" id="open-bulk-teachers" class="btn btn-primary btn-sm"><i class="fas fa-tasks mr-1"></i>Acciones masivas</button></div>
						</div>
						<div class="form-inline mb-3 ed-teachers-filterbar">
							<label for="teacher-status-filter" class="mr-2 font-weight-bold">Estado:</label>
							<select id="teacher-status-filter" class="form-control form-control-sm">
								<option value="all" <?php echo $selected_status === 'all' ? 'selected' : ''; ?>>Todos</option>
								<option value="Activo" <?php echo $selected_status === 'Activo' ? 'selected' : ''; ?>>Activos</option>
								<option value="Inactivo" <?php echo $selected_status === 'Inactivo' ? 'selected' : ''; ?>>Inactivos</option>
							</select>
						</div>
						<div class="table-responsive">
						<table id="teachers-table" class="table table-condensed table-bordered table-hover table-custom">
							<thead>
								<tr>
									<th class="text-center"><input type="checkbox" id="teacher-check-all" title="Seleccionar todos"></th>
									<th class="text-center">#</th>
									<th>Dni</th>
									<th>Nombre</th>
									<th>Información</th>
									<th>Especialidad</th>
									<th>Estado</th>
									<th>Cuenta</th>
									<th>Cursos <?php echo htmlspecialchars($active_year_name, ENT_QUOTES, 'UTF-8'); ?></th>
									<th class="text-center">Acción</th>
								</tr>
							</thead>
							<tbody>
								<?php
								$i = 1;
								// Filtrar por school_id del usuario logueado
								$school_id = $session_school_id;
								$initial_status = $selected_status;
								if (!in_array($initial_status, ['Activo', 'Inactivo', 'all'], true)) $initial_status = 'Activo';
								$teacher = get_teachers_for_list($conn, $school_id, $initial_status, $active_year_id);
								include __DIR__ . '/teacher_rows.php';
								?>
							</tbody>
						</table>
						</div>
					</div>
				</div>
			</div>
			<!-- Table Panel -->
		</div>
	</div>
</div>

<!-- Modal para subir Excel (unificado) -->
<div class="modal fade" id="uploadTeacherExcelModal" tabindex="-1" role="dialog" aria-labelledby="uploadTeacherExcelModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
			<div class="modal-header ed-import-modal-header">
				<h5 class="modal-title" id="uploadTeacherExcelModalLabel"><i class="fa fa-file-excel text-success mr-2"></i>Importar docentes</h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<div class="upload-info-box mb-3">
					<div class="upload-info-icon"><i class="fa fa-info-circle"></i></div>
					<div class="upload-info-content">
						<p class="mb-1">Sube un archivo Excel con los docentes. Usa el formato correcto para evitar errores.</p>
						<p class="mb-0">Puedes <a href="#" id="download_teacher_format_link" style="color: #4285f4; font-weight: 500;">descargar el formato aquí</a>.</p>
					</div>
				</div>
				<form id="upload-teacher-excel-form" enctype="multipart/form-data">
					<div class="form-group">
						<label for="teacher_excel_file">Seleccionar archivo Excel:</label>
						<div class="custom-file">
							<input type="file" class="custom-file-input" id="teacher_excel_file" name="excel_file" accept=".xls,.xlsx" required>
							<label class="custom-file-label" for="teacher_excel_file">Seleccionar archivo...</label>
						</div>
						<small class="form-text text-muted">Formatos permitidos: .xls, .xlsx</small>
					</div>
					<div class="form-group mt-3 mb-0 ed-teacher-upload-actions">
						<button type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal"><i class="fa fa-times mr-1"></i>Cancelar</button>
						<button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-upload mr-1"></i>Subir Excel</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="bulkTeachersModal" tabindex="-1" role="dialog" aria-labelledby="bulkTeachersModalLabel" aria-hidden="true">
	<div class="modal-dialog" role="document"><div class="modal-content">
		<div class="modal-header"><h5 class="modal-title" id="bulkTeachersModalLabel"><i class="fas fa-tasks mr-2"></i>Acciones masivas</h5><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button></div>
		<div class="modal-body">
			<div class="alert alert-info py-2"><strong id="bulk-teachers-modal-count">0</strong> docentes seleccionados</div>
			<div class="form-group"><label for="bulk-teacher-action">Acción</label><select id="bulk-teacher-action" class="form-control"><option value="Inactivo">Desactivar docentes</option><option value="Activo">Recontratar docentes</option></select></div>
			<div class="form-group" id="bulk-departure-reason-group"><label for="bulk-departure-reason">Motivo de salida <span class="text-danger">*</span></label><select id="bulk-departure-reason" class="form-control"><option value="">Seleccione...</option><option>No renovación</option><option>Renuncia</option><option>Despido</option><option>Licencia</option><option>Otro</option></select></div>
			<div class="form-group"><label for="bulk-teacher-notes">Observaciones <span class="text-muted">(opcional)</span></label><textarea id="bulk-teacher-notes" class="form-control" rows="3" maxlength="1000"></textarea></div>
			<div id="bulk-teachers-error" class="alert alert-danger d-none"></div>
		</div>
		<div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button><button type="button" id="apply-bulk-teachers" class="btn btn-primary"><i class="fas fa-check mr-1"></i>Aplicar cambios</button></div>
	</div></div>
</div>

<div class="modal fade" id="teacherEmploymentActionModal" tabindex="-1" role="dialog" aria-labelledby="teacher-employment-title" aria-hidden="true">
	<div class="modal-dialog" role="document"><div class="modal-content">
		<div class="modal-header"><h5 class="modal-title" id="teacher-employment-title">Cambiar vínculo laboral</h5><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button></div>
		<form id="teacher-employment-form">
			<div class="modal-body">
				<input type="hidden" id="employment-teacher-id">
				<input type="hidden" id="employment-action">
				<div id="employment-action-info" class="alert alert-info"></div>
				<div class="form-group" id="departure-reason-group">
					<label for="departure-reason" class="font-weight-bold">Motivo de salida <span class="text-danger">*</span></label>
					<select id="departure-reason" class="form-control"><option value="">Seleccione...</option><option>No renovación</option><option>Renuncia</option><option>Despido</option><option>Licencia</option><option>Otro</option></select>
				</div>
				<div class="form-group mb-0"><label for="employment-notes" class="font-weight-bold">Observaciones <span class="text-muted">(opcional)</span></label><textarea id="employment-notes" class="form-control" rows="3" maxlength="1000" placeholder="Detalle adicional del cambio laboral..."></textarea></div>
			</div>
			<div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary" id="confirm-employment-action">Confirmar</button></div>
		</form>
	</div></div>
</div>

<script>
	var teacherTable;
	var teachersRefreshRequest = null;

	function hasNewDT() { return typeof $.fn.DataTable !== 'undefined'; }
	function isDTInit() {
		try {
			if (hasNewDT() && $.fn.DataTable.isDataTable('#teachers-table')) return true;
			if ($.fn.dataTable && $.fn.dataTable.isDataTable && $.fn.dataTable.isDataTable('#teachers-table')) return true;
		} catch(_) {}
		return false;
	}
	function destroyDT() {
		try {
			if (hasNewDT()) {
				$('#teachers-table').DataTable().destroy();
			} else if ($.fn.dataTable) {
				$('#teachers-table').dataTable().fnDestroy();
			}
		} catch(_) {}
	}
	function initTeachersTable() {
		try {
			if (isDTInit()) destroyDT();
			if (hasNewDT()) {
				teacherTable = $('#teachers-table').DataTable({
					autoWidth: false,
					pageLength: 10,
					order: [[3, 'asc']],
					columnDefs: [{ orderable: false, targets: [0, 9] }],
					language: {
						emptyTable: 'No hay docentes para mostrar.',
						zeroRecords: 'No se encontraron docentes con estos criterios.',
						search: 'Buscar:',
						lengthMenu: 'Mostrar _MENU_ docentes',
						info: 'Mostrando _START_ a _END_ de _TOTAL_ docentes',
						infoEmpty: 'No hay docentes para mostrar',
						paginate: { previous: 'Anterior', next: 'Siguiente' }
					}
				});
			} else if ($.fn.dataTable) {
				teacherTable = $('#teachers-table').dataTable({
					bAutoWidth: false,
					iDisplayLength: 10,
					aaSorting: [[3, 'asc']],
					aoColumnDefs: [{ bSortable: false, aTargets: [0, 9] }],
					oLanguage: { sEmptyTable: 'No hay docentes para mostrar.', sZeroRecords: 'No se encontraron docentes con estos criterios.', sSearch: 'Buscar:' }
				});
			}
		} catch(_) {}
	}

	function refreshTeachersTable() {
		var status = $('#teacher-status-filter').val() || 'Activo';
		if (teachersRefreshRequest) teachersRefreshRequest.abort();

		destroyDT();
		teachersRefreshRequest = $.ajax({
			url: 'teachers.php',
			method: 'GET',
			data: { partial: 'tbody', status: status },
			dataType: 'html',
			success: function(html) {
				$('#teachers-table tbody').html(html);
				initTeachersTable();
				$('#teacher-check-all').prop('checked', false);
				updateTeacherSelection();
				refreshTeacherSummary();
			},
			error: function(xhr, textStatus) {
				if (textStatus !== 'abort') {
					alert_toast('No se pudo actualizar la lista de docentes.', 'danger');
					initTeachersTable();
				}
			},
			complete: function() {
				teachersRefreshRequest = null;
				end_load();
			}
		});
	}

	function refreshTeacherSummary() {
		$.getJSON('teachers.php', { partial: 'summary' }, function(resp) {
			if (!resp || resp.status != 1 || !resp.summary) return;
			$('#summary-active').text(resp.summary.active_count || 0);
			$('#summary-inactive').text(resp.summary.inactive_count || 0);
			$('#summary-without-user').text(resp.summary.without_user || 0);
			$('#summary-without-courses').text(resp.summary.without_courses || 0);
		});
	}

	$(document).ready(function() {
		initTeachersTable();
		$('#teacher-status-filter').on('change', function() {
			start_load();
			refreshTeachersTable();
		});

		// Escuchar guardado desde el modal y refrescar sin recargar toda la página
		$(document).on('teacher:saved', function(e, payload){
			refreshTeachersTable();
		});
	});

	$('#new_teacher').click(function() {
		uni_modal("Nuevo Docente", "manage_teacher.php", "mid-large");
	});

	// Dropdown actions unified
	$(document).off('click.teachers', '#action_new_teacher').on('click.teachers', '#action_new_teacher', function(e){ e.preventDefault(); uni_modal("Nuevo Docente", "manage_teacher.php", "mid-large"); });
	$(document).off('click.teachers', '#action_upload_teacher_excel').on('click.teachers', '#action_upload_teacher_excel', function(e){ e.preventDefault(); $('#uploadTeacherExcelModal').modal('show'); });
	$(document).off('click.teachers', '#action_export_teachers').on('click.teachers', '#action_export_teachers', function(e){ e.preventDefault(); window.location.href = 'export_teachers.php?status=' + encodeURIComponent($('#teacher-status-filter').val() || 'Activo'); });
	$(document).off('click.teachers', '#action_download_teacher_format, #download_teacher_format_link').on('click.teachers', '#action_download_teacher_format, #download_teacher_format_link', function(e){
		e && e.preventDefault();
		// Descargar formato sin recargar la página
		start_load();
		var iframe = document.createElement('iframe');
		iframe.style.display = 'none';
		iframe.src = 'download_teacher_format.php';
		document.body.appendChild(iframe);
		setTimeout(function(){ try { document.body.removeChild(iframe); } catch(_) {} end_load(); }, 2500);
	});

	// Delegar eventos para que sigan funcionando tras refrescar el tbody
	$(document).off('click.teachers', '#teachers-table .edit_teacher').on('click.teachers', '#teachers-table .edit_teacher', function(e) {
		e.preventDefault();
		uni_modal("Editar docente", "manage_teacher.php?id=" + $(this).attr('data-id'), "mid-large");
	});

	$(document).off('click.teachers', '#teachers-table .view_teacher_history').on('click.teachers', '#teachers-table .view_teacher_history', function(e) {
		e.preventDefault();
		uni_modal("Ficha del Docente", "view_teacher_employment.php?id=" + $(this).attr('data-id'), "large");
	});

	$(document).off('click.teachers', '#teachers-table .delete_teacher').on('click.teachers', '#teachers-table .delete_teacher', function(e) {
		e.preventDefault();
		var inactive = $(this).attr('data-status') === 'Inactivo';
		openTeacherEmploymentAction($(this).attr('data-id'), inactive ? 'rehire' : 'deactivate');
	});

	function openTeacherEmploymentAction(id, action) {
		var rehire = action === 'rehire';
		$('#employment-teacher-id').val(id);
		$('#employment-action').val(action);
		$('#employment-notes').val('');
		$('#departure-reason').val('');
		$('#departure-reason-group').toggle(!rehire);
		$('#teacher-employment-title').text(rehire ? 'Recontratar docente' : 'Desactivar docente');
		$('#employment-action-info').text(rehire ? 'Se registrará hoy como fecha de ingreso y se usará el año académico activo.' : 'Se registrará hoy como fecha de salida. Sus cursos, notas y usuario se conservarán.');
		$('#confirm-employment-action').toggleClass('btn-success', rehire).toggleClass('btn-danger', !rehire).text(rehire ? 'Recontratar' : 'Desactivar');
		$('#teacherEmploymentActionModal').modal('show');
	}

	function selectedTeacherIds() {
		return $('.teacher-row-check:checked').map(function(){ return parseInt(this.value, 10); }).get();
	}

	function updateTeacherSelection() {
		var count = selectedTeacherIds().length;
		$('#teacher-selected-count').text(count);
		$('#teacher-bulk-toolbar').toggleClass('d-none', count === 0).toggleClass('d-flex', count > 0);
		$('#teacher-check-all').prop('checked', count > 0 && count === $('.teacher-row-check').length);
	}

	$('#teachers-table').off('change.teachersBulk', '.teacher-row-check').on('change.teachersBulk', '.teacher-row-check', updateTeacherSelection);
	$('#teacher-check-all').off('click.teachersBulk change.teachersBulk').on('click.teachersBulk', function(e){ e.stopPropagation(); }).on('change.teachersBulk', function(){ $('.teacher-row-check').prop('checked', this.checked); updateTeacherSelection(); });
	$('#teachers-table').off('draw.teachersBulk').on('draw.teachersBulk', function(){ $('#teacher-check-all').prop('checked', false); updateTeacherSelection(); });
	$('#export-selected-teachers').off('click.teachersBulk').on('click.teachersBulk', function(){ var ids=selectedTeacherIds(); if(ids.length) window.location.assign('export_teachers.php?status=all&ids='+encodeURIComponent(ids.join(','))+'&download='+Date.now()); });
	$('#open-bulk-teachers').off('click.teachersBulk').on('click.teachersBulk', function(){ var ids=selectedTeacherIds(); if(!ids.length)return; $('#bulk-teachers-modal-count').text(ids.length); $('#bulk-teachers-error').addClass('d-none').empty(); $('#bulk-teacher-action').val('Inactivo').trigger('change'); $('#bulk-departure-reason').val(''); $('#bulk-teacher-notes').val(''); $('#bulkTeachersModal').modal('show'); });
	$('#bulk-teacher-action').off('change.teachersBulk').on('change.teachersBulk', function(){ $('#bulk-departure-reason-group').toggleClass('d-none', this.value === 'Activo'); });
	$('#apply-bulk-teachers').off('click.teachersBulk').on('click.teachersBulk', function(){
		var ids=selectedTeacherIds(), target=$('#bulk-teacher-action').val(), reason=$('#bulk-departure-reason').val();
		if(!ids.length){ $('#bulkTeachersModal').modal('hide'); return; }
		if(target==='Inactivo' && !reason){ $('#bulk-teachers-error').removeClass('d-none').text('Seleccione el motivo de salida.'); return; }
		var button=$(this).prop('disabled',true);
		bulk_teacher_status(ids.join(','), target, reason, $('#bulk-teacher-notes').val(), button);
	});

	$(document).on('teacher:rehire-request', function(e, teacherId) {
		try { $('#uni_modal').modal('hide'); } catch (_) {}
		setTimeout(function(){ openTeacherEmploymentAction(teacherId, 'rehire'); }, 250);
	});

	$('#teacher-employment-form').on('submit', function(e) {
		e.preventDefault();
		var action = $('#employment-action').val();
		var reason = $('#departure-reason').val();
		if (action === 'deactivate' && !reason) {
			alert_toast('Seleccione el motivo de salida.', 'warning');
			return;
		}
		delete_teacher($('#employment-teacher-id').val(), reason, $('#employment-notes').val());
	});

	function bulk_teacher_status(ids, targetStatus, reason, notes, button) {
		start_load();
		$.ajax({url:'ajax.php?action=bulk_teacher_status', method:'POST', dataType:'json', data:{ids:ids, target_status:targetStatus, departure_reason:reason || '', notes:notes || ''}, success:function(resp){
			if(resp.status==1){ $('#bulkTeachersModal').modal('hide'); alert_toast(resp.message,'success'); refreshTeachersTable(); }
			else { $('#bulk-teachers-error').removeClass('d-none').text(resp.message || 'No se pudieron actualizar los docentes.'); end_load(); }
		}, error:function(xhr){ $('#bulk-teachers-error').removeClass('d-none').text((xhr.responseJSON&&xhr.responseJSON.message)||'Error en el servidor.'); end_load(); }, complete:function(){ if(button)button.prop('disabled',false); }});
	}

	function delete_teacher($id, reason, notes) {
		start_load();
		$.ajax({
			url: 'ajax.php?action=delete_teacher',
			method: 'POST',
			data: { id: $id, departure_reason: reason || '', notes: notes || '' },
			dataType: 'json',
			success: function(resp) {
				if (resp.status == 1) {
					$('#teacherEmploymentActionModal').modal('hide');
					alert_toast(resp.message || "Estado del docente actualizado correctamente.", 'success');
					refreshTeachersTable();
				} else {
					alert_toast(resp.message || "Error al eliminar el docente.", 'danger');
					end_load();
				}
			},
			error: function(err) {
				console.error("Error en la solicitud AJAX:", err);
				alert_toast("Error en el servidor. Intente nuevamente más tarde.", 'danger');
				end_load();
			}
		});
	}

	// Abrir modal para subir Excel
	$('#upload_teacher_excel').click(function() { $('#uploadTeacherExcelModal').modal('show'); });

	// Mostrar nombre de archivo seleccionado
	$(document).on('change', '.custom-file-input', function (e) {
		var fileName = e.target.files[0] ? e.target.files[0].name : '';
		$(this).next('.custom-file-label').html(fileName ? fileName : 'Seleccionar archivo...');
	});

	// Manejar el envío del formulario de Excel
	$(document).on('submit', '#upload-teacher-excel-form', function(e) {
		e.preventDefault();
		e.stopPropagation();
		
		start_load();
		
		if (!$('#teacher_excel_file').val()) {
			alert_toast('Por favor, seleccione un archivo Excel.', 'warning');
			end_load();
			return false;
		}

		var formData = new FormData(this);
		
		$.ajax({
			url: 'ajax.php?action=upload_teacher_excel',
			method: 'POST',
			data: formData,
			cache: false,
			contentType: false,
			processData: false,
			success: function(resp) {
				end_load();
				try {
					if (typeof resp === 'string') {
						resp = JSON.parse(resp);
					}
					
					if (resp.status === 'success') {
						alert_toast(resp.message, 'success');
						$('#uploadTeacherExcelModal').modal('hide');
						refreshTeachersTable();
					} else {
						alert_toast(resp.message || 'Error desconocido.', 'danger');
					}
				} catch (err) {
					console.error('Error al procesar la respuesta:', err);
					console.log('Respuesta del servidor:', resp);
					alert_toast('Error inesperado. Verifique la consola.', 'danger');
				}
			},
			error: function(xhr, status, error) {
				end_load();
				console.error('Error en la solicitud AJAX:', status, error);
				console.log('Respuesta del servidor:', xhr.responseText);
				alert_toast('Error en el servidor. Intente nuevamente.', 'danger');
			}
		});
		
		return false;
	});

	// Restaurar la funcionalidad para crear usuarios a los docentes
	// Delegar también el botón de crear usuario
	$(document).off('click.teachers', '#teachers-table .create_user_teacher').on('click.teachers', '#teachers-table .create_user_teacher', function(e) {
		e.preventDefault();
		var id = $(this).attr('data-id');
		var name = encodeURIComponent($(this).attr('data-name'));
		var email = encodeURIComponent($(this).attr('data-email'));
		var modalTitle = $(this).attr('title') === 'Gestionar usuario' ? 'Gestionar Usuario del Docente' : 'Crear Usuario para Docente';
		uni_modal(modalTitle, "manage_teacher_user.php?id=" + id + "&name=" + name + "&email=" + email, "mid-large");
	});

	$(document).off('click.teachers', '#teachers-table .manage_teacher_courses').on('click.teachers', '#teachers-table .manage_teacher_courses', function(e) {
		e.preventDefault();
		uni_modal("Asignar cursos al docente", "manage_teacher_course.php?source=teachers&academic_year_id=<?php echo (int)$active_year_id; ?>&teacher_id=" + $(this).attr('data-id'), "mid-large");
	});

	$(document).on('teacher:user-saved', function() {
		refreshTeachersTable();
	});
</script>
