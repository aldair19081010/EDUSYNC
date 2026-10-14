# EduSync · Web Push inmediato de asistencia (rama de prueba)

**Rama:** `notifications-intelligent-v1`. No fusionar a `main` ni publicar en producción sin autorización.

## Cambio

- Asistencia estudiantil: al persistir un nuevo `student_notification_events` de tipo `attendance_*`, el mismo flujo que ya envía FCM a Android intenta entregar Web Push en ese momento. No necesita esperar al cron.
- Personal: una solicitud nueva en `attendance_change_requests` envía Web Push a administradores suscritos al finalizar la petición y después del COMMIT. Al resolverla, se intenta notificar al solicitante y a otros administradores autorizados.
- El remitente Web Push FCM existente (`edwp_send_message`) sigue siendo único. Tanto el envío inmediato como `cron/web_push_dispatch.php` respetan `web_push_delivery` y el bloqueo MySQL `edusync_web_push_fcm_v1` para evitar duplicados.
- Si el envío falla, se conserva el evento en el Centro de Notificaciones y el cron puede intentar reentregarlo; si el bloqueo está ocupado, se omite el envío inmediato y lo recupera el cron.
- La entrega a Android, la política de roles/colegios, los estados de lectura y el navegador no cambian.

## Prueba en Windows (XAMPP)

1. En `C:\xampp\htdocs\interfaz`, revisar `git status --short` y `git branch --show-current`. Confirmar que estás en `notifications-intelligent-v1`. Conservar las modificaciones locales de conexiones y login.
2. `git pull --ff-only origin notifications-intelligent-v1`. Si Git informa que cambios locales impiden el pull, detenerse y conservarlos; no ejecutar `git reset --hard`.
3. Validar sintaxis desde PowerShell:
   `C:\xampp\php\php.exe -l .\edusync\includes\web_push_immediate.php`
   `C:\xampp\php\php.exe -l .\edusync\includes\web_push_staff_immediate.php`
   `C:\xampp\php\php.exe -l .\edusync\includes\push_notifications.php`
   `C:\xampp\php\php.exe -l .\edusync\attendance_api.php`
4. Con Chrome Web Push ya activado (la campana muestra «Desactivar avisos del dispositivo»), generar una **nueva** asistencia para un estudiante suscrito. Debe registrarse en el centro y saltar en Windows sin ejecutar el cron.
5. Para probar personal, abrir una solicitud de ajuste de asistencia desde Auxiliar y verificar aviso al Administrador suscrito. Resolverla y verificar aviso al solicitante suscrito.
6. En phpMyAdmin, consultar los últimos estados sin compartir tokens FCM:
   ```sql
   SELECT event_kind,event_id,status,attempts,provider_code,updated_at
   FROM web_push_delivery ORDER BY id DESC LIMIT 20;
   ```
7. Ejecutar el cron manualmente para verificar que **no reenvía** eventos ya marcados `delivered`:
   `C:\xampp\php\php.exe .\edusync\cron\web_push_dispatch.php`

## Archivos cambiados

- `edusync/includes/web_push_immediate.php` (nuevo)
- `edusync/includes/web_push_staff_immediate.php` (nuevo)
- `edusync/includes/push_notifications.php`
- `edusync/attendance_api.php`

Los avisos requieren una suscripción Web Push activa para el destinatario **antes** de crear el evento. El resultado `delivered` indica aceptación por FCM; la aparición visual se valida en Windows. El envío inline puede aumentar ligeramente el tiempo de respuesta en registros masivos de asistencia; si se observa latencia, habrá que implementar un proceso de cola no bloqueante.
