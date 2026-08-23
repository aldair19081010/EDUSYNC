<?php
/**
 * Script para convertir Certificados P12/PFX de SUNAT a formato PEM
 * Ejecuta este archivo remotamente desde tu navegador en XAMPP.
 */

// ¡MODIFICA ESTOS DOS VALORES CON TUS DATOS DE PRODUCCIÓN!
$archivo_p12 = 'certificadoSG.p12';
$password_certificado = 'Sg2024IEP';

echo "<h2>Convertidor Local de P12 a PEM - Soporte para Hostinger/OpenSSL 3</h2>";

if (!file_exists($archivo_p12)) {
    die("<b style='color:red'>Error:</b> El archivo <code>$archivo_p12</code> no se encuentra.<br><b>Instrucción:</b> Copia tu certificado de producción dentro de la carpeta <code>" . __DIR__ . "</code> y actualiza el nombre en el código de este script.");
}

$pfxContent = file_get_contents($archivo_p12);
$certs = [];

if (openssl_pkcs12_read($pfxContent, $certs, $password_certificado)) {
    $pem = $certs['cert'] . "\n" . $certs['pkey'];

    $nuevo_nombre = 'certificado_produccion.pem';
    file_put_contents($nuevo_nombre, $pem);

    echo "<b style='color:green'>¡ÉXITO!</b><br>";
    echo "Se ha decodificado el certificado y guardado en el archivo: <b>$nuevo_nombre</b><br><br>";
    echo "<b>Pasos Siguientes:</b><br>";
    echo "1. Sube el archivo <code>$nuevo_nombre</code> a tu servidor de producción Hostinger.<br>";
    echo "2. Entra a la configuración de tu sistema EduSync en producción.<br>";
    echo "3. Ubica la configuración de la Empresa y donde te pide adjuntar tu P12, elige subir el archivo <code>$nuevo_nombre</code>.<br>";
    echo "4. Greenter lo procesará nativamente como archivo PEM ¡Y desaparecerán todos los errores 500!";
}
else {
    echo "<b style='color:red'>Fallo la Extracción.</b><br>";
    echo "No se pudo descifrar el P12. Razones probables:<br>";
    echo "- La contraseña <code>$password_certificado</code> es incorrecta.<br>";
    echo "- El archivo está corrupto.<br>";
    echo "Asegúrate de ejecutar esto en tu XAMPP local, ya que aquí sí funcionan las librerías antiguas.";
}
?>
