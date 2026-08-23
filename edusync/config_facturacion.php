<?php
include 'db_connect.php';

// Asegurar que la sesión esté iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$school_id = isset($_SESSION['login_school_id']) ? $_SESSION['login_school_id'] : 0;
$config = null;

// Cargar configuración existente solo si hay un school_id válido
if ($school_id > 0) {
    $query = $conn->query("SELECT * FROM company_config WHERE school_id = {$school_id} LIMIT 1");
    if ($query && $query->num_rows > 0) {
        $config = $query->fetch_assoc();
    }
}
?>

<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-file-invoice"></i> Configuración de Facturación Electrónica SUNAT
            </h6>
        </div>
        <div class="card-body">
            <form id="form-config-facturacion">
                <input type="hidden" name="id" value="<?= $config['id'] ?? '' ?>">
                <input type="hidden" name="school_id" value="<?= $school_id ?>">
                
                <h5 class="text-primary border-bottom pb-2 mb-3">
                    <i class="fas fa-building"></i> Datos de la Empresa
                </h5>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="font-weight-bold">RUC <span class="text-danger">*</span></label>
                            <input type="text" name="ruc" class="form-control" maxlength="11" pattern="[0-9]{11}" 
                                   value="<?= $config['ruc'] ?? '' ?>" required>
                            <small class="text-muted">11 dígitos</small>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group">
                            <label class="font-weight-bold">Razón Social <span class="text-danger">*</span></label>
                            <input type="text" name="razon_social" class="form-control" 
                                   value="<?= $config['razon_social'] ?? '' ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Nombre Comercial</label>
                            <input type="text" name="nombre_comercial" class="form-control" 
                                   value="<?= $config['nombre_comercial'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Dirección <span class="text-danger">*</span></label>
                            <input type="text" name="direccion" class="form-control" 
                                   value="<?= $config['direccion'] ?? '' ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="font-weight-bold">Departamento <span class="text-danger">*</span></label>
                            <input type="text" name="departamento" class="form-control" 
                                   value="<?= $config['departamento'] ?? '' ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="font-weight-bold">Provincia <span class="text-danger">*</span></label>
                            <input type="text" name="provincia" class="form-control" 
                                   value="<?= $config['provincia'] ?? '' ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="font-weight-bold">Distrito <span class="text-danger">*</span></label>
                            <input type="text" name="distrito" class="form-control" 
                                   value="<?= $config['distrito'] ?? '' ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="font-weight-bold">Ubigeo <span class="text-danger">*</span></label>
                            <input type="text" name="ubigeo" class="form-control" maxlength="6" pattern="[0-9]{6}"
                                   value="<?= $config['ubigeo'] ?? '' ?>" required>
                            <small class="text-muted">6 dígitos</small>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="font-weight-bold">Teléfono</label>
                            <input type="text" name="telefono" class="form-control" 
                                   value="<?= $config['telefono'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="font-weight-bold">Email</label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?= $config['email'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="font-weight-bold">Sitio Web</label>
                            <input type="url" name="website" class="form-control" 
                                   value="<?= $config['website'] ?? '' ?>">
                        </div>
                    </div>
                </div>
                
                <h5 class="text-primary border-bottom pb-2 mb-3 mt-4">
                    <i class="fas fa-certificate"></i> Certificado Digital
                </h5>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> <strong>Importante:</strong> Greenter requiere tu certificado en formato <strong>.pem</strong> (que contiene la llave pública y privada).
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label class="font-weight-bold">Certificado (.pem)</label>
                            <input type="file" name="certificado" class="form-control-file" accept=".pem,.txt">
                            <?php if (!empty($config['certificado_path'])): ?>
                                <small class="text-success"><i class="fas fa-check"></i> Certificado cargado: <?= basename($config['certificado_path']) ?></small>
                            <?php endif; ?>
                            <small class="form-text text-muted">Sube tu certificado ya convertido a formato PEM. No se requiere contraseña.</small>
                        </div>
                    </div>
                </div>
                
                <h5 class="text-primary border-bottom pb-2 mb-3 mt-4">
                    <i class="fas fa-key"></i> Credenciales SUNAT SOL
                </h5>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="font-weight-bold">Usuario SOL secundario <span class="text-danger">*</span></label>
                            <input type="text" name="sunat_usuario" class="form-control" 
                                   value="<?= $config['sunat_usuario'] ?? '' ?>" required>
                            <small class="text-muted">Usuario secundario de SOL para emisión electrónica.</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="font-weight-bold">Clave SOL</label>
                            <input type="password" name="sunat_password" class="form-control">
                            <small class="text-muted">Dejar vacío para conservar la contraseña actual.</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="font-weight-bold">Modo</label>
                            <select name="sunat_modo" class="form-control">
                                <option value="beta" <?= ($config['sunat_modo'] ?? 'beta') == 'beta' ? 'selected' : '' ?>>Pruebas (Beta)</option>
                                <option value="produccion" <?= ($config['sunat_modo'] ?? '') == 'produccion' ? 'selected' : '' ?>>Producción</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Client ID</label>
                            <input type="text" name="sunat_client_id" class="form-control"
                                   value="<?= $config['sunat_client_id'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Client Secret</label>
                            <input type="password" name="sunat_client_secret" class="form-control">
                        </div>
                    </div>
                </div>
                
                <h5 class="text-primary border-bottom pb-2 mb-3 mt-4">
                    <i class="fas fa-list-ol"></i> Series de Comprobantes
                </h5>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="font-weight-bold">Serie Factura</label>
                            <input type="text" name="serie_factura" class="form-control" maxlength="4" 
                                   value="<?= $config['serie_factura'] ?? 'F001' ?>" placeholder="F001">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="font-weight-bold">Serie Boleta</label>
                            <input type="text" name="serie_boleta" class="form-control" maxlength="4" 
                                   value="<?= $config['serie_boleta'] ?? 'B001' ?>" placeholder="B001">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="font-weight-bold">Serie Nota Crédito</label>
                            <input type="text" name="serie_nota_credito" class="form-control" maxlength="4" 
                                   value="<?= $config['serie_nota_credito'] ?? 'FC01' ?>" placeholder="FC01">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="font-weight-bold">Serie Nota Débito</label>
                            <input type="text" name="serie_nota_debito" class="form-control" maxlength="4" 
                                   value="<?= $config['serie_nota_debito'] ?? 'FD01' ?>" placeholder="FD01">
                        </div>
                    </div>
                </div>
                
                <div class="text-right mt-4">
                    <button type="button" class="btn btn-info mr-2" id="btn-test-sunat">
                        <i class="fas fa-plug"></i> Probar Conexión SUNAT
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Configuración
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$('#btn-test-sunat').click(function(e) {
    e.preventDefault();
    start_load();
    
    $.ajax({
        url: 'ajax.php?action=test_sunat_connection',
        method: 'POST',
        dataType: 'json',
        success: function(resp) {
            end_load();
            if (resp.status == 1) {
                alert_toast("✅ " + (resp.message || "Conexión exitosa"), 'success');
            } else {
                alert_toast("❌ " + (resp.message || "Error al conectar"), 'danger');
            }
        },
        error: function(err) {
            end_load();
            console.error("Error:", err);
            alert_toast("Error crítico en el servidor", 'danger');
        }
    });
});

$('#form-config-facturacion').submit(function(e) {
    e.preventDefault();
    start_load();
    
    var formData = new FormData(this);
    
    $.ajax({
        url: 'ajax.php?action=save_config_facturacion',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            end_load();
            if (resp.status == 1) {
                alert_toast("Configuración guardada correctamente", 'success');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                alert_toast(resp.message || "Error al guardar configuración", 'danger');
            }
        },
        error: function(err) {
            end_load();
            console.error("Error:", err);
            alert_toast("Error en el servidor", 'danger');
        }
    });
});
</script>
