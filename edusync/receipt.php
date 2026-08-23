<?php
include 'db_connect.php';
include_once 'includes/session_check.php';
require_login_modal();

$session_school_id = intval($_SESSION['login_school_id'] ?? 0);

// Obtener el pago específico y su número de boleta
$pid = intval($_GET['pid'] ?? 0);
$ef_id = intval($_GET['ef_id'] ?? 0);
$receipt_no = '';
$current_payment = null;

if ($pid > 0) {
	$payment_query = $conn->query("SELECT p.* FROM payments p INNER JOIN student_ef_list ef ON ef.id = p.ef_id INNER JOIN student s ON s.id = ef.student_id WHERE p.id = $pid" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . " LIMIT 1");
    if ($payment_query && $payment_query->num_rows > 0) {
        $current_payment = $payment_query->fetch_assoc();
        $receipt_no = $current_payment['receipt_no'] ?? '';
        $ef_id = $current_payment['ef_id']; // Usar ef_id del pago actual
    }
}

$receipt_no_esc = $conn->real_escape_string($receipt_no);

// Obtener todos los pagos del mismo receipt_no (multipago)
$concept_fees = [];
if ($receipt_no) {
    // Multipago: obtener todos los conceptos con el mismo receipt_no
    $multi_query = $conn->query("
        SELECT ef.*, s.name as sname, s.id_no, s.school_id, s.nivel, s.grado,
        c.course, c.level as course_level, c.grades, ay.year,
        CONCAT(c.course, ' - ', COALESCE(ay.year, 'Sin año')) as concepto_simple,
        concat(c.course,' - ',c.level) as `class`,
        p.id as payment_id, p.amount as payment_amount, p.date_created as payment_date
        FROM payments p
        INNER JOIN student_ef_list ef ON p.ef_id = ef.id
        INNER JOIN student s ON s.id = ef.student_id 
        INNER JOIN courses c ON c.id = ef.course_id  
        LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
		WHERE p.receipt_no = '$receipt_no_esc'" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . "
        ORDER BY p.id ASC
    ");
    if ($multi_query && $multi_query->num_rows > 0) {
        while ($row = $multi_query->fetch_assoc()) {
            $concept_fees[] = $row;
        }
    }
} else {
    // Pago simple: obtener solo el concepto actual
    $fees = $conn->query("
        SELECT ef.*, s.name as sname, s.id_no, s.school_id, s.nivel, s.grado,
        c.course, c.level as course_level, c.grades, ay.year,
        CONCAT(c.course, ' - ', COALESCE(ay.year, 'Sin año')) as concepto_simple,
        concat(c.course,' - ',c.level) as `class`
        FROM student_ef_list ef 
        INNER JOIN student s ON s.id = ef.student_id 
        INNER JOIN courses c ON c.id = ef.course_id  
        LEFT JOIN academic_year ay ON c.academic_year_id = ay.id
		WHERE ef.id = $ef_id" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . "
    ");
    if ($fees && $fees->num_rows > 0) {
        $concept_fees[] = $fees->fetch_assoc();
    }
}

// Usar la primera entrada para datos comunes (estudiante, colegio)
if (empty($concept_fees)) {
    die("No se encontraron conceptos para esta boleta.");
}
$primary_fee = $concept_fees[0];
foreach ($primary_fee as $k => $v) {
    $$k = $v;
}

// Obtener información del colegio
$school_query = $conn->query("SELECT * FROM schools WHERE id = $school_id");
$school_data = $school_query->fetch_assoc();
$school_name = $school_data['name'] ?? 'Colegio';
$school_address = $school_data['address'] ?? '';
$school_contact = $school_data['contact_number'] ?? '';
$school_logo = $school_data['logo_path'] ?? '';

// Verificar si el logo existe
$logo_path = '';
if (!empty($school_logo)) {
    if (file_exists($school_logo)) {
        $logo_path = $school_logo;
    }
}

// Obtener pagos para historial (solo de este concepto si es pago simple, o todos si multipago)
$payments = $conn->query("
    SELECT p.*, pm.name as payment_method 
    FROM payments p 
	INNER JOIN student_ef_list ef ON ef.id = p.ef_id
	INNER JOIN student s ON s.id = ef.student_id
    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id 
	WHERE p.ef_id = $id" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . "
");
$pay_arr = array();
while ($row = $payments->fetch_array()) {
    $pay_arr[$row['id']] = $row;
}
?>
<style>
	.flex {
		display: inline-flex;
		width: 100%;
	}

	.w-50 {
		width: 50%;
	}

	.text-center {
		text-align: center;
	}

	.text-right {
		text-align: right;
	}

	table.wborder {
		width: 100%;
		border-collapse: collapse;
	}

	table.wborder>tbody>tr,
	table.wborder>tbody>tr>td {
		border: 1px solid;
	}

	p {
		margin: unset;
	}

	.school-logo {
		max-height: 60px;
		max-width: 90px;
		border: none;
		margin-right: 12px;
	}

	.header-flex {
		display: flex;
		align-items: center;
		justify-content: flex-start;
		margin-bottom: 15px;
		padding-bottom: 8px;
		border-bottom: 2px solid #007bff;
	}

	.school-info {
		flex: 1;
		text-align: left;
	}

	.school-info h4 {
		margin: 0 0 3px 0;
		font-size: 1.1em;
		color: #333;
	}

	.school-info p {
		margin: 1px 0;
		font-size: 0.85em;
		color: #666;
	}
</style>
<div class="container-fluid">
	<div class="header-flex">
		<?php if (!empty($logo_path)): ?>
			<img src="<?php echo htmlspecialchars($logo_path); ?>" alt="Logo del Colegio" class="school-logo" onerror="this.style.display='none';">
		<?php else: ?>
			<!-- Debug info -->
			<div style="color: #888; font-size: 12px; margin-right: 15px;">
				<?php if (empty($school_logo)): ?>
					[Sin logo]
				<?php else: ?>
					[Logo: <?php echo htmlspecialchars($school_logo); ?>]<br>
					[¿Existe?: <?php echo file_exists($school_logo) ? 'Sí' : 'No'; ?>]
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="school-info">
			<h4><b><?php echo htmlspecialchars($school_name); ?></b></h4>
			<?php if (!empty($school_address)): ?>
				<p><?php echo htmlspecialchars($school_address); ?></p>
			<?php endif; ?>
			<?php if (!empty($school_contact)): ?>
				<p>Tel: <?php echo htmlspecialchars($school_contact); ?></p>
			<?php endif; ?>
		</div>
	</div>
	<div class="text-center mb-3">
		<hr>
		<h5><b><?php echo $_GET['pid'] == 0 ? "Factura de Pago" : 'Recibo de Pago' ?></b></h5>
		<?php if ($receipt_no): ?>
			<p><b>N° Boleta: <?php echo htmlspecialchars($receipt_no); ?></b></p>
		<?php endif; ?>
	</div>
	<hr>
	<div class="flex">
		<div class="w-50">
			<?php if (count($concept_fees) > 1): ?>
				<p>Conceptos de pago: <b></b></p>
				<ul style="margin-left: 20px;">
					<?php foreach ($concept_fees as $cf): ?>
						<li><?php echo htmlspecialchars($cf['concepto_simple'] ?? $cf['class']); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else: ?>
				<p>Concepto de pago: <b>
					<?php
						echo isset($concepto_simple) && $concepto_simple ? htmlspecialchars($concepto_simple) : htmlspecialchars($class);
					?>
				</b></p>
			<?php endif; ?>
			<p>Estudiante: <b><?php echo ucwords($sname) ?></b></p>
			<p>Nivel/Grado: <b><?php echo $nivel . ' - ' . $grado ?></b></p>
		</div>
		<?php if ($pid > 0) : ?>
			<div class="w-50">
				<p>Fecha de Pago: <b><?php echo $current_payment ? date("M d - Y", strtotime($current_payment['date_created'])) : '' ?></b></p>
				<p>Monto Total: <b><?php 
					$total_payment = 0;
					foreach ($concept_fees as $cf) {
						$total_payment += floatval($cf['payment_amount'] ?? 0);
					}
					echo number_format($total_payment, 2);
				?></b></p>
				<p>Método de Pago: <br><b>
				<?php 
					// Para multipago, agregar todos los splits con el mismo receipt_no
					$method_query = $conn->query("
						SELECT pm.name as method_name, SUM(ps.amount) as total_amount
						FROM payment_split ps 
						INNER JOIN payments p ON ps.payment_id = p.id
						INNER JOIN student_ef_list ef ON ef.id = p.ef_id
						INNER JOIN student s ON s.id = ef.student_id
						LEFT JOIN payment_methods pm ON ps.payment_method_id = pm.id 
						WHERE p.receipt_no = '$receipt_no_esc'" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . "
						GROUP BY ps.payment_method_id, pm.name
						ORDER BY pm.name ASC
					");
					$has_splits = $method_query && $method_query->num_rows > 0;
					if ($has_splits) {
						while($method = $method_query->fetch_assoc()) {
							echo htmlspecialchars($method['method_name'] ?? 'Sin método') . ': S/ ' . number_format($method['total_amount'], 2) . '<br>';
						}
					} else {
						// Fallback: Si no hay splits pero sí hay pagos, buscar payment_method_id directamente
						$fallback_query = $conn->query("
							SELECT p.payment_method_id, pm.name as method_name, SUM(p.amount) as total_amount
							FROM payments p
							INNER JOIN student_ef_list ef ON ef.id = p.ef_id
							INNER JOIN student s ON s.id = ef.student_id
							LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
							WHERE p.receipt_no = '$receipt_no_esc' AND p.payment_method_id > 0" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . "
							GROUP BY p.payment_method_id, pm.name
							ORDER BY pm.name ASC
						");
						if ($fallback_query && $fallback_query->num_rows > 0) {
							while($method = $fallback_query->fetch_assoc()) {
								echo htmlspecialchars($method['method_name'] ?? 'Sin método') . ': S/ ' . number_format($method['total_amount'], 2) . '<br>';
							}
						} else {
							echo 'No especificado';
						}
					}
				?>
				</b></p>
				<p>Observación: <b><?php echo $current_payment ? htmlspecialchars($current_payment['remarks'] ?? '') : '' ?></b></p>
			</div>
		<?php endif; ?>
	</div>
	<hr>
	<p><b>Resumen de Pago</b></p>
	<table class="wborder">
		<tr>
			<td width="50%">
				<p><b>Detalles de la(s) tarifa(s)</b></p>
				<hr>
				<table width="100%">
					<tr>
						<td width="50%">Tipo de tarifa</td>
						<td width="50%" class='text-right'>Monto</td>
					</tr>
					<?php
					$ftotal = 0;
					foreach ($concept_fees as $cf) {
						$concepto = htmlspecialchars($cf['class'] ?? 'Sin información');
						$monto = floatval($cf['total_fee'] ?? 0);
						$ftotal += $monto;
					?>
						<tr>
							<td><b><?php echo $concepto ?></b></td>
							<td class='text-right'><b><?php echo number_format($monto, 2) ?></b></td>
						</tr>
					<?php } ?>
					<tr>
						<th>Total</th>
						<th class='text-right'><b><?php echo number_format($ftotal, 2) ?></b></th>
					</tr>
				</table>
			</td>
			<td width="50%">
				<p><b>Información de Pago</b></p>
				<table width="100%" class="wborder">
					<tr>
						<td width="50%">Fecha</td>
						<td width="50%" class='text-right'>Monto</td>
					</tr>
					<?php
					$ptotal = 0;
					// Mostrar solo los pagos con receipt_no igual
					$payment_query = $conn->query("SELECT p.* FROM payments p INNER JOIN student_ef_list ef ON ef.id = p.ef_id INNER JOIN student s ON s.id = ef.student_id WHERE p.receipt_no = '$receipt_no_esc'" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . " ORDER BY p.id ASC");
					if ($payment_query) {
						while ($row = $payment_query->fetch_assoc()) {
							$ptotal += floatval($row['amount']);
					?>
							<tr>
								<td><b><?php echo date("Y-m-d", strtotime($row['date_created'])) ?></b></td>
								<td class='text-right'><b><?php echo number_format($row['amount'], 2) ?></b></td>
							</tr>
					<?php
						}
					}
					?>
					<tr>
						<th>Total</th>
						<th class='text-right'><b><?php echo number_format($ptotal, 2) ?></b></th>
					</tr>
				</table>
				<?php
				// Calcular saldo por CADA concepto y sumarlos
				$total_con_descuento = 0;
				$tiene_descuento = false;
				$saldo_pendiente_total = 0;
				$pagos_anteriores_total = 0;
				
				foreach ($concept_fees as $cf) {
					$monto_concepto = floatval($cf['total_fee'] ?? 0);
					$monto_descuento = isset($cf['discounted_amount']) && $cf['discounted_amount'] !== '' ? floatval($cf['discounted_amount']) : 0;
					$monto_final = ($monto_descuento > 0 && $monto_descuento < $monto_concepto) ? $monto_descuento : $monto_concepto;
					
					if ($monto_descuento > 0 && $monto_descuento < $monto_concepto) {
						$tiene_descuento = true;
					}
					
					$total_con_descuento += $monto_final;
					
					// Obtener pagos PREVIOS a esta boleta para este concepto
					$ef_id = intval($cf['id'] ?? 0);
					$pagos_previos_concepto = 0;
					if ($ef_id > 0) {
						$pago_previo_query = $conn->query("SELECT SUM(p.amount) as total_paid FROM payments p INNER JOIN student_ef_list ef ON ef.id = p.ef_id INNER JOIN student s ON s.id = ef.student_id WHERE p.ef_id = $ef_id" . ($session_school_id ? " AND s.school_id = $session_school_id" : ""));
						if ($pago_previo_query) {
							$r = $pago_previo_query->fetch_assoc();
							$pagos_previos_concepto = floatval($r['total_paid'] ?? 0);
						}
					}
					
					$pagos_anteriores_total += $pagos_previos_concepto;
					
					// Saldo pendiente por concepto = Total concepto - (Pagos previos + lo pagado en esta boleta)
					// Pero como no sabemos cuánto pagó de CADA concepto en esta boleta, usamos la proporción
					// O mejor: calculamos el saldo al final del bloque de información de pago
				}
				
				// Obtener desglose de pagos por concepto EN ESTA BOLETA
				$payments_esta_boleta = $conn->query("SELECT p.ef_id, SUM(p.amount) as total_pago FROM payments p INNER JOIN student_ef_list ef ON ef.id = p.ef_id INNER JOIN student s ON s.id = ef.student_id WHERE p.receipt_no = '$receipt_no_esc'" . ($session_school_id ? " AND s.school_id = $session_school_id" : "") . " GROUP BY p.ef_id");
				
				$total_pagado_esta_boleta = 0;
				$pago_por_concepto_esta_boleta = [];
				if ($payments_esta_boleta) {
					while ($pago = $payments_esta_boleta->fetch_assoc()) {
						$ef_id_pago = intval($pago['ef_id']);
						$monto_pago = floatval($pago['total_pago'] ?? 0);
						$pago_por_concepto_esta_boleta[$ef_id_pago] = $monto_pago;
						$total_pagado_esta_boleta += $monto_pago;
					}
				}
				
				// Si no hay desglose, asumir que se distribuyó proporcionalmente
				if (empty($pago_por_concepto_esta_boleta) && $ptotal > 0 && count($concept_fees) > 0) {
					$proporcion = $ptotal / count($concept_fees);
					foreach ($concept_fees as $cf) {
						$ef_id = intval($cf['id'] ?? 0);
						if ($ef_id > 0) {
							$pago_por_concepto_esta_boleta[$ef_id] = $proporcion;
						}
					}
				}
				
				// Calcular saldo pendiente por concepto
				foreach ($concept_fees as $cf) {
					$ef_id = intval($cf['id'] ?? 0);
					$monto_concepto = floatval($cf['total_fee'] ?? 0);
					$monto_descuento = isset($cf['discounted_amount']) && $cf['discounted_amount'] !== '' ? floatval($cf['discounted_amount']) : 0;
					$monto_final = ($monto_descuento > 0 && $monto_descuento < $monto_concepto) ? $monto_descuento : $monto_concepto;
					
					if ($ef_id > 0) {
						$pago_previo = 0;
						$pago_query = $conn->query("SELECT SUM(p.amount) as total_paid FROM payments p INNER JOIN student_ef_list ef ON ef.id = p.ef_id INNER JOIN student s ON s.id = ef.student_id WHERE p.ef_id = $ef_id" . ($session_school_id ? " AND s.school_id = $session_school_id" : ""));
						if ($pago_query) {
							$r = $pago_query->fetch_assoc();
							$pago_previo = floatval($r['total_paid'] ?? 0);
						}
						
						$saldo = max(0, $monto_final - $pago_previo);
						$saldo_pendiente_total += $saldo;
					}
				}
				?>
				<table width="100%">
					<tr>
						<td><b>Tarifa total (sin descuento)</b></td>
						<td class='text-right'><b><?php echo number_format($ftotal, 2) ?></b></td>
					</tr>
					<?php if ($tiene_descuento && $total_con_descuento < $ftotal): ?>
					<tr>
						<td><b>Descuento/Beca aplicada</b></td>
						<td class='text-right text-success'><b>- <?php echo number_format($ftotal - $total_con_descuento, 2) ?></b></td>
					</tr>
					<?php endif; ?>
					<tr style="background:#f8f9fc;">
						<td><b>Total del concepto</b></td>
						<td class='text-right'><b><?php echo number_format($total_con_descuento, 2) ?></b></td>
					</tr>
					<tr>
						<td><b>Monto pagado en esta boleta</b></td>
						<td class='text-right'><b><?php echo number_format($ptotal, 2) ?></b></td>
					</tr>
					<tr style="background:#e9f7ef;">
						<td><b>Saldo Pendiente (por concepto)</b></td>
						<td class='text-right text-primary' style="font-size:1.1em;"><b><?php echo number_format($saldo_pendiente_total, 2) ?></b></td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</div>