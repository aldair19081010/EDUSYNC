<?php
// Configurar sesión igual que ajax.php
ini_set('session.save_path', __DIR__ . '/tmp');
if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp');
session_name('EDUSYNCSESSID');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db_connect.php';

$comprobante_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$school_id = $_SESSION['login_school_id'] ?? 0;

if (!$comprobante_id || !$school_id) {
    exit('ID de comprobante no válido o sesión expirada');
}

// Obtener datos del comprobante
$query = $conn->query("SELECT c.*, s.name as student_name, s.id_no as student_dni, 
                       p.amount as payment_amount, p.date_created as payment_date
                       FROM comprobantes_electronicos c 
                       LEFT JOIN student s ON c.student_id = s.id 
                       LEFT JOIN payments p ON c.payment_id = p.id
                       WHERE c.id = $comprobante_id AND c.school_id = $school_id");

if (!$query || $query->num_rows == 0) {
    exit('Comprobante no encontrado');
}

$comp = $query->fetch_assoc();

// Obtener detalle del comprobante
$detalle_query = $conn->query("SELECT * FROM comprobante_detalle WHERE comprobante_id = $comprobante_id ORDER BY item");
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <!-- Encabezado del comprobante -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-file-invoice"></i> 
                        <?php echo $comp['tipo_comprobante'] == '01' ? 'FACTURA ELECTRÓNICA' : 'BOLETA DE VENTA ELECTRÓNICA'; ?>
                        <?php echo $comp['numero_completo']; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary">Datos del Cliente</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td width="40%"><strong><?php echo $comp['cliente_tipo_doc'] == '1' ? 'DNI' : 'RUC'; ?>:</strong></td>
                                    <td><?php echo $comp['cliente_num_doc']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Razón Social:</strong></td>
                                    <td><?php echo $comp['cliente_razon_social']; ?></td>
                                </tr>
                                <?php if ($comp['cliente_direccion']): ?>
                                <tr>
                                    <td><strong>Dirección:</strong></td>
                                    <td><?php echo $comp['cliente_direccion']; ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($comp['student_name']): ?>
                                <tr>
                                    <td><strong>Estudiante:</strong></td>
                                    <td><?php echo $comp['student_name']; ?> (DNI: <?php echo $comp['student_dni']; ?>)</td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Datos del Comprobante</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td width="50%"><strong>Fecha Emisión:</strong></td>
                                    <td><?php echo date('d/m/Y', strtotime($comp['fecha_emision'])); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Moneda:</strong></td>
                                    <td><?php echo $comp['moneda']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Estado SUNAT:</strong></td>
                                    <td>
                                        <?php
                                        $badge_class = 'secondary';
                                        switch ($comp['estado_sunat']) {
                                            case 'aceptado':
                                                $badge_class = 'success';
                                                break;
                                            case 'rechazado':
                                                $badge_class = 'danger';
                                                break;
                                            case 'pendiente':
                                                $badge_class = 'warning';
                                                break;
                                        }
                                        ?>
                                        <span class="badge badge-<?php echo $badge_class; ?>">
                                            <?php echo strtoupper($comp['estado_sunat']); ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                            <?php if ($comp['codigo_hash']): ?>
                            <div style="margin-top:10px; padding:8px; background:#f8f9fa; border-radius:4px; word-break:break-all;">
                                <strong>Código Hash:</strong><br>
                                <small style="font-family:monospace; font-size:11px;"><?php echo $comp['codigo_hash']; ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detalle de items -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-list"></i> Detalle de Items</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered" style="table-layout:fixed;">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width:5%;" class="text-center">#</th>
                                    <th style="width:55%;">Descripción</th>
                                    <th style="width:12%;" class="text-center">Cantidad</th>
                                    <th style="width:14%;" class="text-right">P. Unitario</th>
                                    <th style="width:14%;" class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($detalle_query && $detalle_query->num_rows > 0): ?>
                                    <?php while ($det = $detalle_query->fetch_assoc()): ?>
                                    <tr>
                                        <td class="text-center"><?php echo $det['item']; ?></td>
                                        <td><?php echo $det['descripcion']; ?></td>
                                        <td class="text-center"><?php echo number_format($det['cantidad'], 2); ?></td>
                                        <td class="text-right"><?php echo number_format($det['precio_unitario'], 2); ?></td>
                                        <td class="text-right"><strong><?php echo number_format($det['total'], 2); ?></strong></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No hay items registrados</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Totales -->
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <?php if ($comp['observaciones']): ?>
                            <h6 class="text-primary">Observaciones</h6>
                            <p><?php echo nl2br(htmlspecialchars($comp['observaciones'])); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td class="text-right"><strong>Op. Gravadas:</strong></td>
                                    <td class="text-right"><?php echo $comp['moneda']; ?> <?php echo number_format($comp['total_operaciones_gravadas'], 2); ?></td>
                                </tr>
                                <?php if ($comp['total_operaciones_exoneradas'] > 0): ?>
                                <tr>
                                    <td class="text-right"><strong>Op. Exoneradas:</strong></td>
                                    <td class="text-right"><?php echo $comp['moneda']; ?> <?php echo number_format($comp['total_operaciones_exoneradas'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($comp['total_operaciones_inafectas'] > 0): ?>
                                <tr>
                                    <td class="text-right"><strong>Op. Inafectas:</strong></td>
                                    <td class="text-right"><?php echo $comp['moneda']; ?> <?php echo number_format($comp['total_operaciones_inafectas'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="text-right"><strong>IGV (18%):</strong></td>
                                    <td class="text-right"><?php echo $comp['moneda']; ?> <?php echo number_format($comp['total_igv'], 2); ?></td>
                                </tr>
                                <?php if ($comp['total_descuentos'] > 0): ?>
                                <tr>
                                    <td class="text-right"><strong>Descuentos:</strong></td>
                                    <td class="text-right">-<?php echo $comp['moneda']; ?> <?php echo number_format($comp['total_descuentos'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr class="table-active">
                                    <td class="text-right"><strong>TOTAL:</strong></td>
                                    <td class="text-right"><h5 class="mb-0"><strong><?php echo $comp['moneda']; ?> <?php echo number_format($comp['total_precio_venta'], 2); ?></strong></h5></td>
                                </tr>
                            </table>
                            <?php if ($comp['leyenda']): ?>
                            <div class="alert alert-info py-2 mt-2">
                                <small><strong>SON:</strong> <?php echo $comp['leyenda']; ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Respuesta SUNAT -->
            <?php if ($comp['sunat_description']): ?>
            <div class="card mb-3">
                <div class="card-header bg-<?php echo $comp['estado_sunat'] == 'aceptado' ? 'success' : 'danger'; ?> text-white">
                    <h6 class="mb-0"><i class="fas fa-server"></i> Respuesta de SUNAT</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless">
                        <?php if ($comp['sunat_code']): ?>
                        <tr>
                            <td width="30%"><strong>Código:</strong></td>
                            <td><?php echo $comp['sunat_code']; ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Descripción:</strong></td>
                            <td><?php echo $comp['sunat_description']; ?></td>
                        </tr>
                        <?php if ($comp['sunat_notes']): ?>
                        <tr>
                            <td><strong>Notas:</strong></td>
                            <td><?php echo $comp['sunat_notes']; ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($comp['fecha_envio_sunat']): ?>
                        <tr>
                            <td><strong>Fecha Envío:</strong></td>
                            <td><?php echo date('d/m/Y H:i:s', strtotime($comp['fecha_envio_sunat'])); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($comp['fecha_respuesta_sunat']): ?>
                        <tr>
                            <td><strong>Fecha Respuesta:</strong></td>
                            <td><?php echo date('d/m/Y H:i:s', strtotime($comp['fecha_respuesta_sunat'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Botones de acción -->
            <div class="text-center mb-3">
                <?php if ($comp['xml_firmado']): ?>
                <a href="ajax.php?action=descargar_xml&comprobante_id=<?php echo $comprobante_id; ?>" class="btn btn-info" download>
                    <i class="fa fa-download"></i> Descargar XML
                </a>
                <?php endif; ?>
                <?php if ($comp['cdr_content']): ?>
                <a href="ajax.php?action=descargar_cdr&comprobante_id=<?php echo $comprobante_id; ?>" class="btn btn-success" download>
                    <i class="fa fa-download"></i> Descargar CDR
                </a>
                <?php endif; ?>
                <a href="ajax.php?action=descargar_pdf&comprobante_id=<?php echo $comprobante_id; ?>" class="btn btn-primary" target="_blank">
                    <i class="fa fa-file-pdf"></i> Ver PDF
                </a>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fa fa-times"></i> Cerrar
                </button>
            </div>
        </div>
    </div>
</div>
