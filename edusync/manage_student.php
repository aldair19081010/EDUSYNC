<?php
include_once 'db_connect.php';
// Asegurar que la sesión esté iniciada (puede que este archivo se cargue por AJAX)
include_once 'includes/session_check.php';
require_login_modal();
if (session_status() == PHP_SESSION_NONE) session_start();
if (($_SESSION['login_type'] ?? 0) != 1 && ($_SESSION['login_is_director'] ?? 0) != 1) {
    echo "<div class='alert alert-danger'>No tienes permisos para modificar estudiantes.</div>";
    return;
}
if (!isset($_SESSION['login_id'])) {
    echo "<div class='alert alert-danger'>No hay sesión activa. Por favor, inicie sesión.</div>";
    return;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$school_id = intval($_SESSION['login_school_id'] ?? 0);
$academic_years = [];
$years_query = $conn->prepare('SELECT id, year, description, is_active FROM academic_year WHERE school_id = ? ORDER BY is_active DESC, start_date DESC');
if ($years_query) {
    $years_query->bind_param('i', $school_id);
    $years_query->execute();
    $years_result = $years_query->get_result();
    while ($year_row = $years_result->fetch_assoc()) {
        $academic_years[] = $year_row;
    }
    $years_query->close();
}
if (!isset($academic_year_id) && !empty($academic_years)) {
    $academic_year_id = intval($academic_years[0]['id']);
}

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    // Usar sentencia preparada para cargar de forma segura
    $stmt = $conn->prepare("SELECT * FROM student WHERE id = ? AND school_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $id, $school_id);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                foreach ($row as $k => $val) {
                    $$k = $val;
                }
            }
        }
        $stmt->close();
    }
}
?>
<style>
    .form-section-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #2e59d9;
        margin-top: 1.5rem;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #2e59d9;
    }

    .tutor-box {
        background-color: #f8f9fa;
        border-left: 4px solid #2e59d9;
        padding: 1.25rem;
        border-radius: 0.35rem;
        margin-bottom: 1.5rem;
    }

    .tutor-box.secondary {
        background-color: #f0f2f5;
        border-left-color: #858796;
    }

    .tutor-title {
        font-weight: 600;
        color: #2e59d9;
        font-size: 0.95rem;
        margin-bottom: 1rem;
    }

    .tutor-title.secondary {
        color: #858796;
    }
</style>

<form action="" id="manage-student">
    <input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">
    <input type="hidden" name="school_id" value="<?php echo $school_id ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    
    <div id="msg"></div>

    <div class="card shadow mb-4">
        <div class="card-body">

    <!-- Información del Estudiante -->
    <div class="form-section-title">
        <i class="fas fa-user-circle mr-2"></i>Información del Estudiante
    </div>
    
    <div class="row">
        <div class="col-md-4">
            <div class="form-group">
                <label for="id_no" class="font-weight-bold">DNI <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="id_no" name="id_no" value="<?php echo isset($id_no) ? $id_no : '' ?>" required>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label for="name" class="font-weight-bold">Nombre Completo <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" value="<?php echo isset($name) ? $name : '' ?>" required>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label for="genero" class="font-weight-bold">Género <span class="text-danger">*</span></label>
                <select name="genero" id="genero" class="form-control" required>
                    <option value="">Seleccionar...</option>
                    <option value="Masculino" <?php echo (isset($genero) && $genero == 'Masculino') ? 'selected' : '' ?>>♂ Masculino</option>
                    <option value="Femenino"  <?php echo (isset($genero) && $genero == 'Femenino')  ? 'selected' : '' ?>>♀ Femenino</option>
                    <option value="Otro"      <?php echo (isset($genero) && $genero == 'Otro')      ? 'selected' : '' ?>>⚧ Otro</option>
                </select>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label for="email" class="font-weight-bold">Correo</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo isset($email) ? $email : '' ?>">
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label for="contact" class="font-weight-bold">Teléfono</label>
                <input type="text" class="form-control" id="contact" name="contact" value="<?php echo isset($contact) ? $contact : '' ?>">
            </div>
        </div>
    </div>

    <div class="form-group">
        <label for="address" class="font-weight-bold">Dirección</label>
        <textarea name="address" id="address" rows="2" class="form-control"><?php echo isset($address) ? $address : '' ?></textarea>
    </div>

    <!-- Información Académica -->
    <div class="form-section-title">
        <i class="fas fa-book mr-2"></i>Información Académica
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="form-group">
                <label for="nivel_select" class="font-weight-bold">Nivel <span class="text-danger">*</span></label>
                <select id="nivel_select" class="form-control" required>
                    <option value="">Seleccionar...</option>
                    <option value="Inicial" <?php echo (isset($nivel) && $nivel == 'Inicial') ? 'selected' : '' ?>>Inicial</option>
                    <option value="Primaria" <?php echo (isset($nivel) && $nivel == 'Primaria') ? 'selected' : '' ?>>Primaria</option>
                    <option value="Secundaria" <?php echo (isset($nivel) && $nivel == 'Secundaria') ? 'selected' : '' ?>>Secundaria</option>
                </select>
                <input type="hidden" name="nivel" id="nivel_hidden" value="<?php echo isset($nivel) ? $nivel : '' ?>">
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label for="grado_select" class="font-weight-bold">Grado <span class="text-danger">*</span></label>
                <select id="grado_select" class="form-control" required>
                    <option value="">Seleccionar...</option>
                    <option value="1°" <?php echo (isset($grado) && $grado == '1°') ? 'selected' : '' ?>>1°</option>
                    <option value="2°" <?php echo (isset($grado) && $grado == '2°') ? 'selected' : '' ?>>2°</option>
                    <option value="3°" <?php echo (isset($grado) && $grado == '3°') ? 'selected' : '' ?>>3°</option>
                    <option value="4°" <?php echo (isset($grado) && $grado == '4°') ? 'selected' : '' ?>>4°</option>
                    <option value="5°" <?php echo (isset($grado) && $grado == '5°') ? 'selected' : '' ?>>5°</option>
                    <option value="6°" <?php echo (isset($grado) && $grado == '6°') ? 'selected' : '' ?>>6°</option>
                </select>
                <input type="hidden" name="grado" id="grado_hidden" value="<?php echo isset($grado) ? $grado : '' ?>">
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label for="seccion" class="font-weight-bold">Sección <span class="text-danger">*</span></label>
                <select name="seccion" id="seccion" class="form-control" required>
                    <option value="">Seleccionar...</option>
                    <option value="U" <?php echo (isset($seccion) && ($seccion == 'Única' || $seccion == 'U')) ? 'selected' : '' ?>>Única</option>
                    <option value="A" <?php echo (isset($seccion) && $seccion == 'A') ? 'selected' : '' ?>>A</option>
                    <option value="B" <?php echo (isset($seccion) && $seccion == 'B') ? 'selected' : '' ?>>B</option>
                    <option value="C" <?php echo (isset($seccion) && $seccion == 'C') ? 'selected' : '' ?>>C</option>
                    <option value="D" <?php echo (isset($seccion) && $seccion == 'D') ? 'selected' : '' ?>>D</option>
                    <option value="E" <?php echo (isset($seccion) && $seccion == 'E') ? 'selected' : '' ?>>E</option>
                    <option value="F" <?php echo (isset($seccion) && $seccion == 'F') ? 'selected' : '' ?>>F</option>
                </select>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label for="status" class="font-weight-bold">Estado <span class="text-danger">*</span></label>
                <select name="status" id="status" class="form-control" required>
                    <option value="Activo" <?php echo (!isset($status) || $status == 'Activo') ? 'selected' : '' ?>>Activo</option>
                    <option value="Egresado" <?php echo (isset($status) && $status == 'Egresado') ? 'selected' : '' ?>>Egresado</option>
                    <option value="Retirado" <?php echo (isset($status) && $status == 'Retirado') ? 'selected' : '' ?>>Retirado</option>
                </select>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="form-group">
                <label for="academic_year_id" class="font-weight-bold">Año académico <span class="text-danger">*</span></label>
                <select name="academic_year_id" id="academic_year_id" class="form-control" required>
                    <option value="">Seleccionar...</option>
                    <?php foreach ($academic_years as $year): ?>
                        <option value="<?php echo (int)$year['id']; ?>" <?php echo ((int)$academic_year_id === (int)$year['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year['year'] . (!empty($year['description']) ? ' - ' . $year['description'] : '') . ($year['is_active'] ? ' (Activo)' : ''), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Tutores/Padres -->
    <div class="form-section-title">
        <i class="fas fa-users mr-2"></i>Tutores / Padres
    </div>

    <!-- Tutor Principal -->
    <div class="tutor-box">
        <div class="tutor-title">
            <i class="fas fa-user-tie mr-2"></i>Tutor Principal
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="form-group mb-3">
                    <label for="tutor1_nombre" class="small font-weight-bold">Nombres</label>
                    <input type="text" class="form-control form-control-sm" id="tutor1_nombre" name="tutor1_nombre" 
                           value="<?php echo isset($tutor1_nombre) ? $tutor1_nombre : '' ?>">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group mb-3">
                    <label for="tutor1_apellido" class="small font-weight-bold">Apellidos</label>
                    <input type="text" class="form-control form-control-sm" id="tutor1_apellido" name="tutor1_apellido" 
                           value="<?php echo isset($tutor1_apellido) ? $tutor1_apellido : '' ?>">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-3">
                <div class="form-group mb-3">
                    <label for="tutor1_dni" class="small font-weight-bold">DNI</label>
                    <input type="text" class="form-control form-control-sm" id="tutor1_dni" name="tutor1_dni" 
                           value="<?php echo isset($tutor1_dni) ? $tutor1_dni : '' ?>">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group mb-3">
                    <label for="tutor1_telefono" class="small font-weight-bold">Teléfono</label>
                    <input type="text" class="form-control form-control-sm" id="tutor1_telefono" name="tutor1_telefono" 
                           value="<?php echo isset($tutor1_telefono) ? $tutor1_telefono : '' ?>">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group mb-3">
                    <label for="tutor1_relacion" class="small font-weight-bold">Parentesco</label>
                    <select name="tutor1_relacion" id="tutor1_relacion" class="form-control form-control-sm">
                        <option value="">Seleccionar...</option>
                        <option value="Madre" <?php echo (isset($tutor1_relacion) && $tutor1_relacion == 'Madre') ? 'selected' : '' ?>>Madre</option>
                        <option value="Padre" <?php echo (isset($tutor1_relacion) && $tutor1_relacion == 'Padre') ? 'selected' : '' ?>>Padre</option>
                        <option value="Tutor" <?php echo (isset($tutor1_relacion) && $tutor1_relacion == 'Tutor') ? 'selected' : '' ?>>Tutor</option>
                        <option value="Apoderado" <?php echo (isset($tutor1_relacion) && $tutor1_relacion == 'Apoderado') ? 'selected' : '' ?>>Apoderado</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group mb-3">
                    <label for="tutor1_direccion" class="small font-weight-bold">Dirección</label>
                    <input type="text" class="form-control form-control-sm" id="tutor1_direccion" name="tutor1_direccion" 
                           value="<?php echo isset($tutor1_direccion) ? $tutor1_direccion : '' ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Tutor Secundario (Opcional) -->
    <div class="tutor-box secondary">
        <div class="tutor-title secondary">
            <i class="fas fa-user-friends mr-2"></i>Tutor Secundario (Opcional)
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="form-group mb-3">
                    <label for="tutor2_nombre" class="small font-weight-bold">Nombres</label>
                    <input type="text" class="form-control form-control-sm" id="tutor2_nombre" name="tutor2_nombre" 
                           value="<?php echo isset($tutor2_nombre) ? $tutor2_nombre : '' ?>">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group mb-3">
                    <label for="tutor2_apellido" class="small font-weight-bold">Apellidos</label>
                    <input type="text" class="form-control form-control-sm" id="tutor2_apellido" name="tutor2_apellido" 
                           value="<?php echo isset($tutor2_apellido) ? $tutor2_apellido : '' ?>">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-3">
                <div class="form-group mb-3">
                    <label for="tutor2_dni" class="small font-weight-bold">DNI</label>
                    <input type="text" class="form-control form-control-sm" id="tutor2_dni" name="tutor2_dni" 
                           value="<?php echo isset($tutor2_dni) ? $tutor2_dni : '' ?>">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group mb-3">
                    <label for="tutor2_telefono" class="small font-weight-bold">Teléfono</label>
                    <input type="text" class="form-control form-control-sm" id="tutor2_telefono" name="tutor2_telefono" 
                           value="<?php echo isset($tutor2_telefono) ? $tutor2_telefono : '' ?>">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group mb-3">
                    <label for="tutor2_relacion" class="small font-weight-bold">Parentesco</label>
                    <select name="tutor2_relacion" id="tutor2_relacion" class="form-control form-control-sm">
                        <option value="">Seleccionar...</option>
                        <option value="Madre" <?php echo (isset($tutor2_relacion) && $tutor2_relacion == 'Madre') ? 'selected' : '' ?>>Madre</option>
                        <option value="Padre" <?php echo (isset($tutor2_relacion) && $tutor2_relacion == 'Padre') ? 'selected' : '' ?>>Padre</option>
                        <option value="Tutor" <?php echo (isset($tutor2_relacion) && $tutor2_relacion == 'Tutor') ? 'selected' : '' ?>>Tutor</option>
                        <option value="Apoderado" <?php echo (isset($tutor2_relacion) && $tutor2_relacion == 'Apoderado') ? 'selected' : '' ?>>Apoderado</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group mb-3">
                    <label for="tutor2_direccion" class="small font-weight-bold">Dirección</label>
                    <input type="text" class="form-control form-control-sm" id="tutor2_direccion" name="tutor2_direccion" 
                           value="<?php echo isset($tutor2_direccion) ? $tutor2_direccion : '' ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Botones -->
        </div> <!-- /.card-body -->
        <div class="card-footer text-right bg-white">
            <button type="button" class="btn btn-light btn-sm mr-2" id="btn-cancel" data-dismiss="modal">
                <i class="fas fa-times mr-1"></i>Cancelar
            </button>

            <button type="submit" class="btn btn-primary btn-sm" id="submit-btn">
                <i class="fas fa-save mr-2"></i>Guardar
            </button>
        </div>
    </div> <!-- /.card -->
</form>

<script>
    $(document).ready(function() {
        // Aplicar estilos compactos
        $("#manage-student").find('input[type=text], input[type=email], select, textarea').addClass('form-control-sm');
        // Filtrar grados según nivel
        $('#nivel_select').change(function() {
            let nivel = $(this).val();
            $('#nivel_hidden').val(nivel);
            $('#grado_select option').show();
            
            if (nivel === 'Inicial') {
                $('#grado_select option[value="1°"]').hide();
                $('#grado_select option[value="2°"]').hide();
                $('#grado_select option[value="6°"]').hide();
            }
            
            if ($('#grado_select option:selected').is(':hidden')) {
                $('#grado_select').val($('#grado_select option:visible').not('[value=""]').first().val());
                $('#grado_hidden').val($('#grado_select').val());
            }
        });

        // Actualizar campo oculto de grado
        $('#grado_select').change(function() {
            $('#grado_hidden').val($(this).val());
        });

        // Submit form
        $('#manage-student').submit(function(e) {
            e.preventDefault();
            $.ajax({
                url: 'ajax.php?action=save_student',
                type: 'POST',
                data: new FormData(this),
                contentType: false,
                processData: false,
                success: function(resp) {
                    try {
                        // Aceptar tanto string JSON como objeto ya parseado
                        if (typeof resp === 'string' || resp instanceof String) {
                            resp = JSON.parse(resp);
                        }
                        if (resp && resp.status == 1) {
                            var parentWindow = window.parent;
                            if (parentWindow.studentTable) {
                                parentWindow.studentTable.ajax.reload(null, false);
                            }
                            parentWindow.$('#uni_modal').modal('hide');
                            if (typeof parentWindow.alert_toast === 'function') {
                                parentWindow.alert_toast(resp.message || 'Estudiante actualizado correctamente.', 'success');
                            }
                        } else {
                            var msg = (resp && resp.message) ? resp.message : 'Error desconocido.';
                            $('#msg').html('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                                '<i class="fas fa-exclamation-circle mr-2"></i>' + msg + 
                                '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>');
                        }
                    } catch(err) {
                        console.error('Error procesando respuesta save_student:', err, resp);
                        var display = (err && err.message) ? err.message : 'Respuesta inválida del servidor.';
                        $('#msg').html('<div class="alert alert-danger">Error: ' + display + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error AJAX save_student:', status, error, xhr.responseText);
                    $('#msg').html('<div class="alert alert-danger">Error en la solicitud: ' + (error || status) + '</div>');
                }
            });
        });

        // Disparar cambio de nivel al cargar
        $('#nivel_select').trigger('change');

        // Asegurar que los campos de grado y sección se rellenen desde los valores PHP (si existen)
        var _initialGrado = <?php echo json_encode(isset($grado) ? $grado : ''); ?>;
        var _initialSeccion = <?php echo json_encode(isset($seccion) ? $seccion : ''); ?>;
        if (_initialGrado) {
            // Establecer el select visual y el campo oculto que se envía
            $('#grado_select').val(_initialGrado).trigger('change');
            $('#grado_hidden').val(_initialGrado);
        }
        if (_initialSeccion) {
            var $seccion = $('#seccion');
            // Primer intento: asignar por value tal cual
            if ($seccion.find('option[value="' + _initialSeccion + '"]').length) {
                $seccion.val(_initialSeccion);
            } else {
                // Normalizar texto: quitar acentos, mayúsculas y espacios para comparar
                function normalizeStr(s){
                    try { return (s||'').toString().trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); } catch(e) { return (s||'').toString().trim().toLowerCase(); }
                }
                var target = (function(){ try { return _initialSeccion.toString().trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); } catch(e) { return _initialSeccion.toString().trim().toLowerCase(); } })();
                var matched = false;
                $seccion.find('option').each(function(){
                    if (matched) return;
                    var val = $(this).attr('value') || '';
                    var text = $(this).text() || '';
                    var nval = normalizeStr(val);
                    var ntext = normalizeStr(text);
                    if (nval === target || ntext === target) {
                        $seccion.val($(this).attr('value'));
                        matched = true;
                        return;
                    }
                });
                if (!matched) {
                    // intentar búsqueda por coincidencia parcial
                    $seccion.find('option').each(function(){
                        if (matched) return;
                        var val = $(this).attr('value') || '';
                        var text = $(this).text() || '';
                        var nval = normalizeStr(val);
                        var ntext = normalizeStr(text);
                        if (nval.indexOf(target) !== -1 || ntext.indexOf(target) !== -1) {
                            $seccion.val($(this).attr('value'));
                            matched = true;
                            return;
                        }
                    });
                }
            }
            $seccion.trigger('change');
        }
    });
</script>
