# Códigos QR de asistencia EduSync (ESQ1)

Se mantiene el lector de código de barras existente (por `student.id_no`) y el registro manual por DNI. Un QR firmado **no** contiene DNI ni nombre: `ESQ1.<school_id>.<student_id>.<firma HMAC>`.

## Configuración obligatoria en el hosting

En la configuración **privada** del proceso PHP, definir `EDUSYNC_ATTENDANCE_QR_KEY` con un secreto aleatorio de al menos 32 caracteres, compartido por las instancias de la API y el emisor. Nunca guardar el valor en el repositorio, en Flutter, en la web pública ni dentro del QR. Sin esa variable, la emisión y comprobación del QR firmado quedan deshabilitadas; los carnés de barras anteriores siguen operativos.

Mantener este secreto respaldado de forma privada. Cambiarlo invalida todos los carnés QR emitidos anteriormente. El formato ESQ1 no caduca por sí solo: desactivar al estudiante impide marcar asistencia; una revocación individual de carné requerirá una versión posterior.

## Emisión para el colegio

`POST /edusync/api/attendance_qr_admin.php` desde sesión web de Administración autenticada:

- `student_id`: ID interno del estudiante.
- `csrf_token`: token de la sesión administrativa.

La API verifica el rol, el colegio, la cuenta activa y que el estudiante esté activo en la institución. Retorna `code` y datos de presentación. Convertir **exactamente** `code` en un código QR al imprimir el carné; no modificarlo ni transformarlo en un enlace público.

Para una interfaz de generación/impresión masiva de carnés, conectar el módulo administrativo de estudiantes a esta API; la emisión no está habilitada desde la sesión Auxiliar.

## Lectura

La app Auxiliar reconoce tanto códigos de barras legados como ESQ1. La API `auxiliary_attendance_mobile.php` verifica firma y colegio en el servidor y, dentro de la misma transacción, verifica alumno activo, cierre de aula, duplicados de Entrada/Salida y registra la asistencia. No confiar en la interpretación del contenido del QR realizada por Flutter.

## Despliegue

1. Respaldar archivos y revisar el servidor de producción.
2. Subir `includes/attendance_qr_code.php`, la API administrativa y la nueva API de asistencia; configurar la clave de entorno fuera del repositorio.
3. Probar con QR auténtico, firma adulterada, otro colegio, estudiante inactivo y marcación duplicada.
4. Actualizar/probar la app Auxiliar.
5. Habilitar la impresión de carnés una vez validada la emisión.

Prueba PHP CLI: `php edusync/tests/attendance_qr_code_test.php`.
