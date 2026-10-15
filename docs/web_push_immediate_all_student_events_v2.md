# EduSync · Web Push inmediato para todos los eventos estudiantiles

**Rama de pruebas:** `notifications-intelligent-v1`. Sin cambios en `main` ni producción.

## Causa corregida

La prueba manual `api/web_push.php?action=test` enviaba FCM directamente; el botón funcionaba.
Sin embargo, `push_send_student_event()` sólo invocaba el envío inmediato Web Push cuando el tipo empezaba por `attendance_`. Un envío manual desde Cobranza generaba el evento `collection_notice`, llegaba a Android y se conservaba en `student_notification_events`, pero la web debía esperar a `cron/web_push_dispatch.php`.

Ahora **cada evento nuevo creado mediante** `push_send_student_event()` intenta Web Push en el momento, independientemente del tipo: asistencia, cobranza, pagos, deuda, evaluaciones/notas, comunicados, etc. Conserva el envío Android y las reglas vigentes del evento.

Los casos de asistencia administrativa siguen usando su despachador inmediato separado. Para nuevos eventos administrativos ajenos a asistencia, el cron existente sigue aplicando.

## Condiciones y control de duplicados

- El navegador debe haberse suscrito **con la cuenta del estudiante destinatario** antes de crear el evento. La sesión administrativa que envía la cobranza **no** es la destinataria del push de cobranza.
- Se usa `edwp_send_student_event_now()` y el mismo emisor Firebase del botón de prueba.
- `web_push_delivery` y el candado global `edusync_web_push_fcm_v1` se comparten con el cron. Un evento ya enviado no se vuelve a enviar en el siguiente cron.
- Si Firebase falla, los tokens están caducados o el candado está ocupado, el evento continúa en el Centro de Notificaciones y el cron puede reintentarlo si es elegible.
- Un mismo evento histórico deduplicado **no** genera nuevamente una notificación inmediata.
- Cobranza reporta ahora por separado el número aceptado por Firebase para Android y para Web, los fallos y los casos diferidos. `web_push_subscriptions` es el conteo de navegadores elegibles identificados al enviar. «Aceptado por Firebase» no garantiza la presentación visual final.
- Ninguna migración SQL nueva.

## Prueba local en Windows

1. En `C:\xampp\htdocs\interfaz`, verificar que la rama sea `notifications-intelligent-v1` y revisar `git status --short` para proteger modificaciones locales.
2. `git pull --ff-only origin notifications-intelligent-v1`, sólo cuando no se vayan a pisar cambios locales. No usar `reset --hard`.
3. Validar PHP con:
   ```powershell
   C:\xampp\php\php.exe -l .\edusync\includes\push_notifications.php
   C:\xampp\php\php.exe -l .\edusync\includes\web_push_immediate.php
   C:\xampp\php\php.exe -l .\edusync\collection_notifications_api.php
   ```
4. En una ventana/perfil separado de Chrome, iniciar sesión en **EduSync como estudiante**, activar las notificaciones del dispositivo y pulsar «Enviar prueba». La cuenta de Administración puede permanecer en otra ventana/perfil.
5. Desde Administración, generar **una campaña nueva de cobranza** que incluya al estudiante de prueba. Observar su notificación emergente en el Chrome del estudiante **sin ejecutar el cron**.
6. Comprobar el mensaje tras enviar: «Android: X aceptados por Firebase. Web: Y aceptados por Firebase». Si dice «Ningún navegador estudiantil suscrito», el problema es la suscripción o los destinatarios; si indica fallos Web, revisar el registro de entrega. Si aparece «pendientes de reintento», ejecutar el cron en local para verificarlos.
7. Consulta de diagnóstico sin mostrar tokens:
   ```sql
   SELECT d.event_kind,d.event_id,d.status,d.attempts,
          d.provider_code,d.updated_at
   FROM web_push_delivery d
   ORDER BY d.id DESC LIMIT 20;
   ```
8. Ejecutar `C:\xampp\php\php.exe .\edusync\cron\web_push_dispatch.php` para comprobar reintentos y ausencia de duplicados entregados.

## Archivos modificados

- `edusync/includes/push_notifications.php`
- `edusync/includes/web_push_immediate.php`
- `edusync/collection_notifications_api.php`
- `edusync/pages/collections.php`

**Pendiente:** validación completa de sintaxis PHP y entrega real contra la base de datos y Firebase del entorno XAMPP. No promover la rama a `main` hasta probarla.
