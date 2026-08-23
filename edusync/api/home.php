<?php include 'db_connect.php' ?>
<style>
.inicio-dashboard {
	max-width: 900px;
	margin: 40px auto 0 auto;
	background: #fff;
	border-radius: 14px;
	box-shadow: 0 2px 16px rgba(0,0,0,0.10);
	padding: 40px 30px 30px 30px;
	text-align: center;
}
.inicio-dashboard img {
	width: 160px;
	margin-bottom: 18px;
}
.inicio-dashboard h2 {
	font-weight: 700;
	color: #007bff;
	margin-bottom: 10px;
}
.inicio-dashboard p {
	font-size: 1.15rem;
	color: #444;
	margin-bottom: 30px;
}
.inicio-cards {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: 30px;
	margin-top: 30px;
}
.inicio-card {
	background: #f8f9fa;
	border-radius: 10px;
	box-shadow: 0 1px 6px rgba(0,0,0,0.07);
	padding: 28px 24px;
	width: 210px;
	min-height: 120px;
	display: flex;
	flex-direction: column;
	align-items: center;
	transition: box-shadow 0.2s;
	text-decoration: none;
}
.inicio-card:hover {
	box-shadow: 0 4px 16px rgba(0,123,255,0.13);
	background: #e9f3ff;
}
.inicio-card i {
	font-size: 2.2rem;
	color: #007bff;
	margin-bottom: 10px;
}
.inicio-card span {
	font-size: 1.08rem;
	font-weight: 500;
	color: #222;
}
@media (max-width: 900px) {
	.inicio-cards {
		flex-direction: column;
		align-items: center;
	}
	.inicio-card {
		width: 90%;
	}
}
</style>
<div class="inicio-dashboard">
	<img src="assets/uploads/logo.jpg" alt="Logo EduSync">
	<h2>Inicio</h2>
	<p>
		<?php
		$user = $_SESSION['login_name'] ?? '';
		echo $user ? "Hola, <b>" . htmlspecialchars($user) . "</b>.<br>" : "";
		?>
		Selecciona una opción para continuar:
	</p>
	<div class="inicio-cards">
		<?php if ($_SESSION['login_type'] == 1): // ADMIN ?>
			<a href="index.php?page=students" class="inicio-card">
				<i class="fa fa-users"></i>
				<span>Estudiantes</span>
			</a>
			<a href="index.php?page=fees" class="inicio-card">
				<i class="fa fa-money-check"></i>
				<span>Pagos de Estudiantes</span>
			</a>
			<a href="index.php?page=grades_report" class="inicio-card">
				<i class="fa fa-chart-bar"></i>
				<span>Reporte de Notas</span>
			</a>
			<a href="index.php?page=asistencia" class="inicio-card">
				<i class="fa fa-calendar-check"></i>
				<span>Asistencia</span>
			</a>
			<a href="index.php?page=teachers" class="inicio-card">
				<i class="fa fa-chalkboard-teacher"></i>
				<span>Docentes</span>
			</a>
			<a href="index.php?page=users" class="inicio-card">
				<i class="fa fa-users-cog"></i>
				<span>Usuarios</span>
			</a>
			<a href="index.php?page=payments" class="inicio-card">
				<i class="fa fa-receipt"></i>
				<span>Pagos</span>
			</a>
			<a href="index.php?page=concepts" class="inicio-card">
				<i class="fa fa-scroll"></i>
				<span>Conceptos de Pagos</span>
			</a>
			<a href="index.php?page=academic_courses" class="inicio-card">
				<i class="fa fa-book"></i>
				<span>Cursos Académicos</span>
			</a>
			<a href="index.php?page=teacher_courses" class="inicio-card">
				<i class="fa fa-user-tag"></i>
				<span>Asignar Docentes a Cursos</span>
			</a>
			<a href="index.php?page=attendance_report" class="inicio-card">
				<i class="fa fa-calendar-alt"></i>
				<span>Reporte de Asistencia</span>
			</a>
		<?php elseif ($_SESSION['login_type'] == 2): // PROFESOR ?>
			<a href="index.php?page=my_courses" class="inicio-card">
				<i class="fa fa-book"></i>
				<span>Mis Cursos</span>
			</a>
			<a href="index.php?page=grades" class="inicio-card">
				<i class="fa fa-clipboard-list"></i>
				<span>Notas</span>
			</a>
		<?php elseif ($_SESSION['login_type'] == 3): // AUXILIAR ?>
			<a href="index.php?page=asistencia" class="inicio-card">
				<i class="fa fa-calendar-check"></i>
				<span>Asistencia</span>
			</a>
		<?php elseif ($_SESSION['login_type'] == 4): // ESTUDIANTE ?>
			<a href="index.php?page=my_payments" class="inicio-card">
				<i class="fa fa-receipt"></i>
				<span>Mis Pagos</span>
			</a>
			<a href="index.php?page=my_debts" class="inicio-card">
				<i class="fa fa-money-bill-wave"></i>
				<span>Mis Deudas</span>
			</a>
			<a href="index.php?page=my_grades" class="inicio-card">
				<i class="fa fa-clipboard-list"></i>
				<span>Mis Notas</span>
			</a>
		<?php endif; ?>
	</div>
</div>