<?php
/**
 * Prueba rápida de contrato visual/funcional sin acceso a BD ni Firebase.
 * php tools/test_teacher_communications_ui_v1.php
 */
$page=file_get_contents(dirname(__DIR__).'/edusync/pages/announcements.php');
function expect_ui(bool $ok,string $name):void {
    if(!$ok){fwrite(STDERR,"FALLO: ".$name.PHP_EOL);exit(1);}
    echo "OK: ".$name.PHP_EOL;
}
expect_ui(strpos($page,'id="an-student-target"')!==false &&
    strpos($page,'id="an-teacher-target"')!==false,
    'Conserva las vistas Estudiantes y Docentes.');
expect_ui(strpos($page,'class="btn btn-primary an-compose-kind"')===false &&
    strpos($page,'id="an-compose-kind-label"')!==false,
    'Evita repetir el conmutador de público dentro del modal.');
expect_ui(strpos($page,'an-teacher-option is-active')!==false &&
    strpos($page,'an-audience-option')!==false &&
    strpos($page,'data-scope="selected"')!==false,
    'Usa los mismos patrones de selección de público.');
expect_ui(strpos($page,"dropdownParent:$('#an-compose-modal')")!==false &&
    strpos($page,'select2-selection--multiple')!==false,
    'Select2 múltiple se renderiza dentro del modal con altura y bordes consistentes.');
expect_ui(strpos($page,"data['teacher_ids[]']")!==false &&
    strpos($page,"data.audience_type=$('#an-teacher-scope').val()")!==false,
    'El envío docente utiliza la selección visual activa.');
expect_ui(strpos($page,"const api='announcements_api.php'")!==false &&
    strpos($page,"const teacherApi='teacher_announcements_api.php'")!==false,
    'Conserva los dos flujos del backend.');
expect_ui(strpos($page,"$('#an-stat-sent-label').text(isTeacher?")!==false &&
    strpos($page,'id="an-detail-sent-heading"')!==false,
    'Muestra estados diferentes de envío y aceptación FCM.');
expect_ui(strpos($page,'an-teacher-recipient-count')!==false &&
    strpos($page,'id="an-preview-audience"')!==false,
    'Sincroniza conteo y vista previa al cambiar público.');
echo "Comprobaciones estáticas correctas. Validar visualmente en XAMPP y móvil.".PHP_EOL;
