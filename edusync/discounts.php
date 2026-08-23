<?php include 'db_connect.php'; ?>

<div class="container-fluid py-4">
    <div class="col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fa fa-percent mr-2"></i> Gestión de Descuentos/Becas
                </h6>
                <button class="btn btn-primary btn-sm" id="new_discount">
                    <i class="fa fa-plus"></i> Nuevo Descuento
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="discounts_table">
                        <thead>
                            <tr>
                                <th class="text-center" width="5%">#</th>
                                <th width="10%">DNI</th>
                                <th width="20%">Estudiante</th>
                                <th width="20%">Concepto</th>
                                <th width="12%">Monto Original</th>
                                <th width="12%">Monto con Descuento</th>
                                <th width="10%">% Descuento</th>
                                <th class="text-center" width="11%">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $i = 1;
                            $school_id = intval($_SESSION['login_school_id'] ?? 0);
                            $discounts = $conn->query("\n                                SELECT ef.id, ef.student_id, ef.course_id, ef.total_fee, ef.discounted_amount, \n                                       s.name as student_name, s.id_no, s.nivel, s.grado,\n                                       c.course as concept_name, c.level as course_level, c.grades, ay.year,\n                                       CONCAT(c.course, ' - ', c.level, ' (', s.grado, ') - ', COALESCE(ay.year, 'Sin año')) as concepto_concatenado\n                                FROM student_ef_list ef \n                                INNER JOIN student s ON s.id = ef.student_id \n                                INNER JOIN courses c ON c.id = ef.course_id \n                                LEFT JOIN academic_year ay ON c.academic_year_id = ay.id\n                                WHERE ef.discounted_amount IS NOT NULL \n                                  AND s.school_id = $school_id\n                                ORDER BY s.name ASC\n                            ");
                            if ($discounts && $discounts->num_rows > 0) :
                                while ($row = $discounts->fetch_assoc()) :
                                    $discount_percentage = round((($row['total_fee'] - $row['discounted_amount']) / $row['total_fee']) * 100, 1);
                            ?>
                                <tr>
                                    <td class="text-center font-weight-bold"><?php echo $i++ ?></td>
                                    <td>
                                        <span class="badge badge-info"><?php echo $row['id_no'] ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo ucwords($row['student_name']) ?></strong>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold"><?php echo $row['concepto_concatenado'] ?></div>
                                    </td>
                                    <td class="text-right">
                                        <span class="badge badge-success">S/ <?php echo number_format($row['total_fee'], 2) ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="badge badge-warning">S/ <?php echo number_format($row['discounted_amount'], 2) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-secondary"><?php echo $discount_percentage ?>%</span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-primary btn-sm edit_discount" type="button" data-id="<?php echo $row['id'] ?>" data-toggle="tooltip" title="Editar"><i class="fa fa-edit"></i></button>
                                        <?php 
                                        $student_id = isset($row['student_id']) && !empty($row['student_id']) ? $row['student_id'] : '';
                                        $course_id = isset($row['course_id']) && !empty($row['course_id']) ? $row['course_id'] : '';
                                        $combined_id = '';
                                        if (!empty($student_id) && !empty($course_id)) {
                                            $combined_id = $student_id . '_' . $course_id;
                                        }
                                        ?>
                                        <button class="btn btn-danger btn-sm remove_discount" type="button" data-id="<?php echo $combined_id ?>" data-toggle="tooltip" title="Quitar Descuento" <?php echo empty($combined_id) ? 'disabled="disabled"' : ''; ?>><i class="fa fa-times"></i></button>
                                    </td>
                                </tr>
                            <?php
                                endwhile;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('#new_discount').click(function() {
            uni_modal("Nuevo Descuento/Beca", "manage_discount.php", "mid-large");
        });

        $(document).on('click', '.edit_discount', function() {
            uni_modal("Editar Descuento/Beca", "manage_discount.php?id=" + $(this).attr('data-id'), "mid-large");
        });

        $(document).on('click', '.remove_discount', function() {
            var dataId = $(this).attr('data-id');
            if (!dataId || dataId === '') {
                alert_toast('Error: No se pudo identificar el descuento a eliminar', 'danger');
                return;
            }
            _conf("¿Deseas quitar este descuento? El estudiante volverá a pagar el monto completo.", "remove_discount", [dataId]);
        });

        try {
            $('#discounts_table').DataTable({
                language: {
                    processing: "Procesando...",
                    lengthMenu: "Mostrar _MENU_ registros",
                    zeroRecords: "No se encontraron resultados",
                    emptyTable: "Ningún descuento disponible en esta tabla",
                    info: "Mostrando registros del _START_ al _END_ de un total de _TOTAL_",
                    infoEmpty: "Mostrando registros del 0 al 0 de 0",
                    infoFiltered: "(filtrado de un total de _MAX_ registros)",
                    search: "Buscar:",
                    loadingRecords: "Cargando...",
                    paginate: {
                        first: "Primero",
                        last: "Último",
                        next: "Siguiente",
                        previous: "Anterior"
                    }
                },
                pageLength: 10,
                responsive: true,
                order: [[2, 'asc']],
                columnDefs: [
                    { targets: [0, 7], orderable: false }
                ]
            });
        } catch (e) {
            console.error('Error inicializando DataTable en descuentos:', e);
        }

        $('[data-toggle="tooltip"]').tooltip();
    });

    function remove_discount(id) {
        if(!id || id.indexOf('_') === -1) {
            alert_toast('Error: Formato de ID inválido', 'danger');
            return;
        }
        var student_id = id.split('_')[0];
        var course_id = id.split('_')[1];

        if(!student_id || !course_id) {
            alert_toast('Error: Componente ID faltante', 'danger');
            return;
        }

        start_load();
        $.ajax({
            url: 'ajax.php?action=delete_discount',
            method: 'POST',
            data: { 
                student_id: student_id,
                course_id: course_id
            },
            success: function(resp) {
                $('#confirm_modal').modal('hide');
                try {
                    if (typeof resp === 'string') {
                        resp = JSON.parse(resp);
                    }
                    if (resp.status == 1) {
                        alert_toast("Descuento removido exitosamente.", 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 500);
                    } else {
                        alert_toast(resp.message || 'Error al eliminar el descuento', 'danger');
                        end_load();
                    }
                } catch (err) {
                    alert_toast("Error inesperado. Intente nuevamente más tarde.", 'danger');
                    end_load();
                }
            },
            error: function(err) {
                $('#confirm_modal').modal('hide');
                alert_toast("Error en el servidor. Intente nuevamente más tarde.", 'danger');
                end_load();
            }
        });
    }
</script>
