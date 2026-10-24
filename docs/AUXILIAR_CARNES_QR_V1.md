# EduSync · Carnés QR para registro de asistencia (Auxiliar)

**Estado:** rama `auxiliary-secure-qr-attendance-v1`. No desplegar ni mezclar con producción sin validación.

## Qué cambia
- El Auxiliar mantiene su escaneo continuo y registro manual por DNI/código.
- El escáner acepta un QR EduSync `EDUQR1-` con **48 caracteres hexadecimales aleatorios**. La imagen QR no lleva DNI, nombre, curso ni un ID secuencial.
- El servidor almacena **solo SHA-256** del secreto aleatorio. El token legible aparece únicamente tras su emisión.
- Una nueva emisión reemplaza y **revoca el QR anterior**; también hay un botón Revocar.
- No cambia `asistencia`: jornada actual, colegio de la sesión, estudiante activo, cierre de aula, duplicados, auditoría y notificaciones conservan sus reglas.
- Los códigos de barras y DNI previos siguen admitidos.

## Despliegue coordinado (antes de probar un QR nuevo)
1. Respaldar la base de datos. En phpMyAdmin ejecutar una sola vez `edusync/sql_student_attendance_qr.sql`. La tabla es nueva; no reemplaza datos existentes.
2. Subir desde **esta rama** los archivos `edusync/api/auxiliary_attendance_mobile.php`, `edusync/student_attendance_qr.php`, `edusync/students.php`, `edusync/students_table_data.php`, `edusync/js/vendor/qrcode.min.js` y `edusync/js/vendor/qrcode.LICENSE`.
3. Abrir el sistema web como **Administración**, ir a Estudiantes → menú de acciones → Carné QR de asistencia.
4. Generar el QR, verificar que se vea y usar **Imprimir carné**. Guardar/imprimir en ese momento: por seguridad no se puede recuperar el código exacto después.
5. Probar con la app Android de la rama `auxiliary-secure-qr-attendance-v1` y un auxiliar autorizado.

**El archivo .sql NO debe subirse como página pública.** Es solo para ejecutar en la base de datos, idealmente fuera del directorio web. La biblioteca QR es local: no se envía el token a servicios externos para crear la imagen.

## Casos que deben probarse
- QR emitido para un alumno activo → Entrada registrada, nombre/grado/hora correctos.
- Segundo escaneo de Entrada → duplicado rechazado, sin doble notificación.
- Cambiar a Salida y escanear → Salida registrada.
- Código de barras anterior y DNI manual → funcionan sin migrar carnés previos.
- QR inválido, URL o texto sin carné → rechazo sin marcación.
- QR revocado/reemplazado → el antiguo deja de identificar; el nuevo funciona.
- QR de otro colegio → rechazado por separación de `school_id`.
- Estudiante inactivo y aula con jornada cerrada → rechazo.
- Pérdida de conexión tras registrar → la app verifica `scan_status`, sin reintento automático.

## Consideraciones de seguridad
El QR es una credencial de presentación física; una fotografía puede copiarse. El auxiliar debe comprobar identidad cuando haya dudas. Solo Administración puede emitir o revocar, usando sesión activa y token CSRF. Nunca publicar tokens en logs, URLs o listados masivos.

Para volver atrás se puede ocultar la acción QR y restaurar el endpoint anterior conservando la tabla, **sin borrar asistencias previas**. No desplegar la opción de emisión antes de aplicar la migración.
