<?php
// Ejecutar desde CLI: php tools/notification_detail_ui_smoke_test.php
require_once __DIR__ . '/../edusync/includes/notification_detail_ui.php';
function assertContains(string $expected,string $html): void {
    if (strpos($html,$expected)===false) {
        fwrite(STDERR,"ERROR: no se encontró: $expected\n");
        exit(1);
    }
}
ob_start();
edn_header('grade_changes','Notas <editadas>','Docente & curso','2026-10-15 10:30:00','Revisar');
edn_footer();
$html=ob_get_clean();
assertContains('edn-view edn-theme--warning',$html);
assertContains('Notas &lt;editadas&gt;',$html);
assertContains('Docente &amp; curso',$html);
assertContains('15/10/2026 · 10:30 am',$html);
assertContains('data-dismiss="modal"',$html);
assertContains('class="edn-footer"',$html);
ob_start();
edn_header('low_grade','Riesgo académico');
edn_footer();
assertContains('edn-theme--danger',ob_get_clean());
ob_start();
edn_header('attendance','Cambio de asistencia');
edn_footer();
assertContains('edn-theme--teal',ob_get_clean());
echo "OK: temas, caracteres escapados, fecha local y cierre del modal.\n";
