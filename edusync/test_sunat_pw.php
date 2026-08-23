<?php
include 'db_connect.php';
$q = $conn->query("SELECT school_id, ruc, sunat_usuario, sunat_password, sunat_modo FROM company_config LIMIT 1");
if ($q && $q->num_rows > 0) {
    $r = $q->fetch_assoc();
    echo "RUC: " . $r['ruc'] . "\n";
    echo "Usuario SOL: " . $r['sunat_usuario'] . "\n";
    echo "Password en BD (encriptado): " . $r['sunat_password'] . "\n";
    echo "Modo: " . $r['sunat_modo'] . "\n";
    // Descifrar
    $key = 'EduSync2024Secret';
    $method = 'AES-256-CBC';
    $iv = substr(hash('sha256', $key), 0, 16);
    $plain = openssl_decrypt(base64_decode($r['sunat_password']), $method, $key, 0, $iv);
    echo "Password descifrado: [" . $plain . "]\n";
} else {
    echo "No hay configuración.\n";
}
