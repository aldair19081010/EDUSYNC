<?php
/**
 * Prueba estática sin MySQL ni credenciales.
 * Protege la compatibilidad entre recordar sesión en web y el login móvil.
 */
$base = dirname(__DIR__);
$cases = [
    'Personal' => $base . '/edusync/auth_login.php',
    'Estudiante' => $base . '/edusync/api/login.php',
];

foreach ($cases as $label => $file) {
    $source = file_get_contents($file);
    if ($source === false) throw new RuntimeException("$label: falta $file");
    $regen = strpos($source, 'session_regenerate_id(true);');
    $remember = strpos($source, 'edrs_process_login(');
    $capture = strpos($source, '$mobile_session_id = session_id();');
    $close = strpos($source, 'session_write_close();', $remember === false ? 0 : $remember);
    $response = strpos($source, "'session_id' => \$mobile_session_id");
    if ($regen === false || $remember === false || $capture === false ||
        $close === false || $response === false ||
        !($regen < $remember && $remember < $capture &&
          $capture < $close && $close < $response)) {
        throw new RuntimeException(
            "$label: se perdió el contrato session_id del móvil o la "
            . "regeneración de sesión/recordatorio del navegador."
        );
    }
    if (strpos($source, "'remember_saved' => \$remember_saved") === false) {
        throw new RuntimeException("$label: se perdió remember_saved de web.");
    }
    echo "[OK] $label: session_id posterior a regeneración, "
        . "web remember_me conservado.\n";
}
echo "2 contratos correctos; sin conexión a la base de datos.\n";
