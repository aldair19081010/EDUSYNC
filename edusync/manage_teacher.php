
<?php
include_once 'includes/session_check.php';
require_login_modal();
include 'db_connect.php';

// Obtener el school_id del administrador automáticamente
$school_id = $_SESSION['login_school_id'] ?? 0;

if (isset($_GET['id'])) {
    // Asegurarse de que solo se puedan editar docentes del mismo colegio
    $qry = $conn->query("SELECT * FROM teacher WHERE id = " . $_GET['id'] . " AND school_id = " . $school_id);
    foreach ($qry->fetch_array() as $k => $val) {
        $$k = $val;
    }
}
?>
<style>
/* Formulario docente dentro del modal universal, sin estilos globales. */
#manage-teacher{
  font-size:.81rem;
  color:#344054;
}
#manage-teacher .card-lite{
  border:0;
  background:transparent;
  box-shadow:none!important;
  border-radius:0;
  margin-bottom:.4rem!important;
}
#manage-teacher .card-lite > .card-body{
  padding:0!important;
}
#manage-teacher .form-section-title{
  display:flex;
  align-items:center;
  border:0;
  border-bottom:1px solid #e1e8f3;
  margin:0 0 .75rem;
  padding:0 0 .44rem;
  font-size:.9rem;
  line-height:1.35;
  color:#233b52;
  font-weight:700;
}
#manage-teacher .form-section-title i{
  color:var(--edu-primary,#4285f4);
}
#manage-teacher .form-group{
  margin-bottom:.72rem;
}
#manage-teacher label{
  display:block;
  color:#526274;
  font-size:.75rem;
  margin:0 0 .26rem;
  font-weight:700;
}
#manage-teacher .form-control{
  min-height:36px;
  font-size:.81rem;
  border:1px solid #d8deea;
  box-shadow:none;
  border-radius:.42rem;
}
#manage-teacher textarea.form-control{
  min-height:67px;
}
#manage-teacher .form-control:focus{
  border-color:#9cbcf9;
  box-shadow:0 0 0 .14rem rgba(66,133,244,.12);
}
#manage-teacher .helper-text{
  font-size:.73rem;
  color:#758397;
  line-height:1.38;
  margin-top:.2rem;
}
#manage-teacher > .d-flex.justify-content-end{
  gap:.5rem;
  border-top:1px solid #edf0f5;
  background:#fff;
  padding:.75rem 0 .1rem;
  margin:0!important;
}
#manage-teacher > .d-flex.justify-content-end .btn{
  min-height:35px;
  margin:0!important;
  padding:.4rem .77rem;
  font-size:.8rem;
  border-radius:.4rem;
  box-shadow:none!important;
  transform:none!important;
}
@media(max-width:575.98px){
  #manage-teacher .form-group{margin-bottom:.68rem}
  #manage-teacher > .d-flex.justify-content-end{flex-wrap:wrap}
}
</style>

<div class="container-fluid px-0">
    <form action="" id="manage-teacher">
        <input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
        <!-- Establecer el school_id como valor oculto que no se puede modificar -->
        <input type="hidden" name="school_id" value="<?php echo $school_id ?>">

        <div id="msg" class="form-group"></div>

        <div class="card card-lite mb-3">
            <div class="card-body">
                <div class="form-section-title">
                    <i class="fas fa-chalkboard-teacher mr-2"></i>Datos del Docente
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">DNI <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="id_no" value="<?php echo isset($id_no) ? $id_no : '' ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Nombre Completo <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="<?php echo isset($name) ? $name : '' ?>" required>
                        </div>
                    </div>
                </div>

				<div class="form-group">
					<label class="font-weight-bold">Fecha de nacimiento</label>
					<input type="date" class="form-control" name="birth_date" max="<?php echo date('Y-m-d'); ?>" value="<?php echo isset($birth_date) ? htmlspecialchars($birth_date, ENT_QUOTES, 'UTF-8') : ''; ?>">
					<div class="helper-text">Se utilizará para calcular la edad automáticamente.</div>
				</div>

			<div class="form-group">
				<label class="font-weight-bold" for="status">Estado</label>
				<select class="form-control" name="status" id="status" <?php echo isset($id) ? 'disabled' : ''; ?>>
					<option value="Activo" <?php echo (!isset($status) || $status === 'Activo') ? 'selected' : ''; ?>>Activo</option>
					<option value="Inactivo" <?php echo (isset($status) && $status === 'Inactivo') ? 'selected' : ''; ?>>Inactivo</option>
				</select>
				<div class="helper-text"><?php echo isset($id) ? 'Utilice el botón de desactivar o recontratar de la lista para cambiar el estado y registrar el historial laboral.' : 'El docente activo quedará vinculado automáticamente al año académico en curso.'; ?></div>
			</div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Especialidad</label>
                            <input type="text" class="form-control" name="specialty" value="<?php echo isset($specialty) ? $specialty : '' ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Contacto</label>
                            <input type="text" class="form-control" name="contact" value="<?php echo isset($contact) ? $contact : '' ?>">
                            <div class="helper-text">Teléfono o celular de referencia.</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Correo</label>
                            <input type="email" class="form-control" name="email" value="<?php echo isset($email) ? $email : '' ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Dirección</label>
                            <textarea name="address" rows="3" class="form-control"><?php echo isset($address) ? $address : '' ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <button type="button" class="btn btn-light mr-2" data-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Guardar</button>
        </div>
    </form>
</div>

<script>
    $('#manage-teacher').on('reset', function() {
        $('#msg').html('');
        $('input:hidden').val('');
    });

    $('#manage-teacher').submit(function(e) {
        e.preventDefault();
        start_load();
        $('#msg').html('');
        $.ajax({
            url: 'ajax.php?action=save_teacher',
            data: new FormData($(this)[0]),
            cache: false,
            contentType: false,
            processData: false,
            method: 'POST',
            dataType: 'json',
            success: function(resp) {
                if (resp.status == 1) {
                    alert_toast(resp.message, 'success');
                    // Emitir evento para refrescar la tabla sin recargar toda la página
					$(document).trigger('teacher:saved', [resp]);
                    try { window.parent.$('#uni_modal').modal('hide'); } catch(_) {}
                    end_load();
                } else if (resp.status == 2) {
					var alertBox = $('<div class="alert alert-warning mx-2"></div>');
					alertBox.append($('<div></div>').text(resp.message || 'El DNI ya está registrado.'));
					if (resp.teacher_name) alertBox.append($('<div class="small mt-1"></div>').text('Docente encontrado: ' + resp.teacher_name));
					if (resp.teacher_status === 'Inactivo' && resp.teacher_id) {
						var rehireButton = $('<button type="button" class="btn btn-success btn-sm mt-2"><i class="fa fa-user-check mr-1"></i>Recontratar docente existente</button>');
						rehireButton.on('click', function(){ $(document).trigger('teacher:rehire-request', [resp.teacher_id]); });
						alertBox.append('<br>').append(rehireButton);
					}
					$('#msg').empty().append(alertBox);
                    end_load();
                } else {
                    alert_toast(resp.message, 'danger');
                    end_load();
                }
            },
            error: function(err) {
                console.error('Error en la solicitud AJAX:', err);
				console.error('Respuesta del servidor:', err.responseText);
				$('#msg').html('<div class="alert alert-danger mx-2">No se pudo guardar. Revise los datos e inténtelo nuevamente.</div>');
                end_load();
            }
        });
    });
</script>
