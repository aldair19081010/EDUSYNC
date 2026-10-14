# EduSync · Mantener sesión iniciada durante 30 días (v1)

**Rama de pruebas:** `remember-session-30d-v1`, creada desde `web-push-fcm-v1`.
No fusionar a `main` ni publicar antes de completar las pruebas locales y aprobar.

## Qué cambia

En `login.php` aparece la casilla **Mantener sesión iniciada durante 30 días**,
**desmarcada por defecto**, para Administrador, Docente, Auxiliar y Estudiante.
Las rutas existentes de autenticación continúan funcionando con contraseña:
`auth_login.php` para personal y `api/login.php` para estudiantes.

Con la casilla marcada, el servidor genera un par selector/verificador aleatorio
por navegador. La cookie `edusync_remember_session` es **HttpOnly, SameSite=Lax,
Secure bajo HTTPS** y caduca como máximo a los **30 días** desde que se marca.
El servidor almacena **solo SHA-256 del verificador**, además de la cuenta,
el colegio, la fecha límite y un resumen que permite invalidar el dispositivo
si cambia la contraseña. No se almacenan contraseñas en cookies/localStorage.

Al caducar por inactividad la sesión PHP, EduSync puede reconstruir una sesión
nueva en las rutas del login, del inicio, de AJAX y en los controladores que
reutilizan `session_config.php` o `includes/session_check.php`.
No se reutiliza el antiguo identificador de sesión: se regenera y se
**rota el secreto de la cookie recordada** en la recuperación.

**Cerrar sesión** mediante `logout.php` invalida inmediatamente el token
de ese navegador, borra su cookie y conserva el comportamiento previo de
desactivar las suscripciones Web Push de ese navegador.

La expiración es absoluta: **ningún acceso renueva otros 30 días** sin
un nuevo inicio explícito con credenciales. Otros equipos mantienen sus propios
tokens. Una cuenta inactiva o un cambio de contraseña invalidan el acceso recordado.

Si no se marca, el acceso es una sesión PHP normal y se elimina cualquier
credencial persistente previa en ese navegador.

## Preparar las pruebas (no se ejecuta SQL automáticamente)

1. Haz copia de seguridad de tu BD **local** de EduSync.
2. En phpMyAdmin, selecciona la BD local que realmente utiliza XAMPP.
3. Ejecuta una sola vez `sql/remember_session_30d_v1.sql`, que crea
   `remembered_login_devices`.
4. En PowerShell:

```powershell
cd C:\xampp\htdocs\interfaz
git fetch origin
git switch remember-session-30d-v1
git pull --ff-only origin remember-session-30d-v1
powershell -ExecutionPolicy Bypass -File .\tools\check_remember_session_30d_v1.ps1
```

5. Inicia sesión en `http://localhost/interfaz/edusync/login.php`
   o tu ruta real de EduSync, marcando **Mantener sesión iniciada**.
   La URL local debe usar `localhost` o `127.0.0.1`; para producción se
   **requiere HTTPS**. En HTTP público el modo persistente no se habilita.
6. Cierra la pestaña o el navegador **sin pulsar Cerrar sesión**, ábrelo
   nuevamente y comprueba que puedes regresar sin contraseña incluso si ya
   terminó la sesión PHP. La cookie sigue presente hasta los 30 días.
7. Pulsa **Cerrar sesión**. Comprueba que ahora sí requiere credenciales y que
   la cookie ya no existe.
8. Repite con Estudiante y Docente. Comprueba que otra cuenta o colegio no
   pueden restaurarse con un token ajeno.
9. Desmarca la casilla al entrar nuevamente y comprueba que no se recuerda.
10. Prueba un cambio de contraseña y la desactivación del usuario:
    la cookie anterior debe dejar de dar acceso.

La prueba funcional de expiración y revocación necesita una BD local real.
El script de validación no conecta a MySQL ni modifica tus datos.

## Consejos de seguridad y límites

- En dispositivos compartidos **no marques** la casilla.
- No se debe probar compartiendo cookies o tokens con terceros.
- No se altera el flujo de Firebase FCM ni su Service Worker.
- Las notificaciones pueden seguir recibiéndose con la página cerrada;
  esto es independiente de la duración de la sesión PHP.
- Tras una recuperación de sesión durante una acción AJAX que cambie datos,
  el servidor genera un **nuevo CSRF**, por lo que alguna acción antigua puede
  requerir recargar EduSync y repetirse. **No se omite la comprobación CSRF**.
- La disponibilidad depende de que MySQL, la cookie y la cuenta sigan válidos.
- Se recomienda que la infraestructura de producción use HTTPS real y
  que la carpeta de sesiones PHP no sea accesible por URL.

## Archivos de esta mejora

- `sql/remember_session_30d_v1.sql`
- `edusync/includes/remember_session.php`
- `edusync/login.php`, `edusync/auth_login.php`, `edusync/api/login.php`
- `edusync/index.php`, `edusync/ajax.php`
- `edusync/session_config.php`, `edusync/includes/session_check.php`
- `edusync/logout.php`
- `tools/check_remember_session_30d_v1.ps1`
- `tools/test_remember_session_30d_v1.php`
