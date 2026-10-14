# EduSync · Entrega unificada de notificaciones Web Push y Android

**Rama de pruebas:** `web-push-unified-immediate-v1`.
**Base:** `notifications-intelligent-v1`, incluye sesión recordada de 30 días.
**Estado:** pendiente de pruebas funcionales locales; sin despliegue a `main` ni producción.
**Base de datos:** no requiere migración nueva (reutiliza `web_push_delivery` y eventos existentes).

## Problema y solución

Antes, los avisos de cobranza, notas o comunicados se guardaban en
`student_notification_events` y se intentaban enviar a los tokens de la
**app Android** de inmediato. Los tokens de **Chrome/Edge** solo se
procesaban cuando alguien ejecutaba `cron/web_push_dispatch.php`.

Ahora **la misma notificación persistida** puede activar también la entrega
Web Push automáticamente. Ambos caminos siguen utilizando el mismo evento,
colegio y destinatario, pero tienen registros de entrega independientes:

- **Android:** `student_device_tokens`, envío FCM ya implementado y sus logs.
- **Navegador:** `web_push_subscriptions`, entrega FCM vía
  `edusync/includes/web_push_dispatcher.php` y
  `web_push_delivery`.
- **Centro de notificaciones:** `student_notification_events` para
  estudiantes, `notification_events` y las fuentes virtuales existentes
  para personal.
- **Cron:** llama a **ese mismo despachador**, no a un algoritmo paralelo.
  Sirve para pendientes, fallos transitorios y lotes grandes.

**No se modifica el registro Firebase del navegador, sus permisos, la
campana, el Service Worker, el estilo visual ni el módulo Flutter.**

## Envíos automáticos conectados

| Evento registrado | Destino Push |
|---|---|
| Cobranza manual | Navegadores suscritos del estudiante + app Android |
| Notas, asistencia, pagos, deudas y comunicados | Navegador del estudiante + Android cuando corresponde |
| Cierre bimestral del docente | Administradores suscritos |
| Cierre realizado por Administración | Docente correspondiente |
| Reapertura de notas / resultado | Administrador / docente correspondiente |
| Nueva evaluación | Administración |
| Nuevo caso de notas bajas (episodio nuevo) | Administración y docente de sus cursos |
| Solicitud de asistencia / resultado | Administración o solicitante según sus permisos |

No se generan eventos nuevos para hechos que antes no estaban
notificados. Se respetan las reglas existentes de destinatarios, roles y
colegios: **una cobranza enviada a estudiantes no implica enviar un Push
al administrador que creó la campaña**.

La cola estudiantil automática se vacía al finalizar la operación PHP y
comprueba que ya no exista una transacción abierta. Así evita avisar
antes de confirmar una modificación en MySQL.

La cobranza a varias familias se maneja **en lote**, no enviando todos
los Web Push desde cada iteración. Para evitar bloquear la respuesta:
se intentan hasta **20 entregas web** por petición de campaña;
los pendientes quedan para el cron de respaldo.

El bloqueo MySQL `edusync_web_push_fcm_v1` impide que el cron y el
despacho inmediato envíen simultáneamente el mismo evento al mismo
dispositivo. Los envíos confirmados se conservan en `web_push_delivery`
y no se repiten por recalcular o abrir la campana.

## Privacidad y resultados

Las alertas de cobranza mostradas **fuera de EduSync** ya no incluyen el
importe ni el concepto detallado. La notificación del sistema avisa que
hay un pendiente y dirige a **Mis Deudas**, donde el estudiante deberá
acceder a su cuenta.

La respuesta del envío de cobranza distingue:
- `push_sent`, `push_failed`: dispositivos de la **app Android**.
- `web_push_sent`, `web_push_failed`: intentos inmediatos de **navegador**.
- `web_push_deferred`: puede haber más avisos pendientes de completar.

**Una respuesta HTTP 200 de Firebase es aceptación para la entrega, no
garantía de que Windows haya mostrado la notificación**: el navegador,
la conexión y las restricciones del sistema operativo pueden influir.

Si falla FCM, EduSync mantiene registrada la cobranza o la nota y no
deshace una operación escolar ya confirmada; los envíos pendientes se
procesan en ejecuciones posteriores.

## Probar en XAMPP (no producción)

Conserva tus cambios locales de `db.php` y `db_connect.php`.

```powershell
cd C:\xampp\htdocs\interfaz
git fetch origin
git switch web-push-unified-immediate-v1
git pull --ff-only origin web-push-unified-immediate-v1
powershell -ExecutionPolicy Bypass -File .\tools\check_web_push_unified_v1.ps1
```

1. En Edge o Chrome, inicia sesión como un **estudiante de prueba**.
   Desde la campana, activa las notificaciones y pulsa **Enviar prueba**.
2. En Administración, envía **una cobranza nueva** a ese estudiante.
   No ejecutes el cron a mano todavía. El aviso debe registrarse en
   **Mis notificaciones** y, habitualmente en segundos, aparecer en Windows.
3. Revisa el resumen de cobranza: `web_push_sent` identifica los envíos
   web aceptados, aunque `push_sent` (Android) sea 0.
4. Vuelve a enviar una campaña de prueba distinta al mismo estudiante;
   su nuevo evento puede notificar. Reintentar el **mismo evento** mediante
   cron no debe duplicarlo.
5. Si el navegador no recibió nada, ejecuta una vez el cron:
   ```powershell
   & 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\interfaz\edusync\cron\web_push_dispatch.php'
   ```
6. Prueba cierre bimestral del docente, nuevos casos académicos y solicitudes
   de asistencia, verificando el rol receptor correcto.

### Diagnóstico de entregas (solo lectura en phpMyAdmin local)

```sql
SELECT e.id, e.notification_type, e.created_at,
       d.status, d.attempts, d.provider_code, d.delivered_at,
       s.account_kind, s.is_active
FROM student_notification_events e
LEFT JOIN web_push_delivery d
  ON d.event_kind = 'student' AND d.event_id = e.id
LEFT JOIN web_push_subscriptions s ON s.id = d.subscription_id
WHERE e.notification_type = 'collection_notice'
ORDER BY e.id DESC
LIMIT 20;
```

- `status='delivered'` y `provider_code=200`: Firebase aceptó el aviso.
- `status='retry'`: cron debe reintentar cuando llegue el momento.
- Sin fila de `web_push_delivery`: comprobar si el estudiante activó
  Web Push en ese navegador **antes** de generarse el evento, si la cuenta
  coincide y si el despacho está configurado.

**No compartas ni publiques `fcm_token`, cookies ni credenciales Firebase.**

## En producción y campañas grandes

Mantener **cron cada minuto**:
`edusync/cron/web_push_dispatch.php` (PHP CLI, nunca URL pública).
El envío inmediato no reemplaza la necesidad de una cola recuperable en
caso de desconexión, saturación, más de 20 destinatarios con navegador
suscrito o límite de tiempo de PHP.

## Archivos

- **Nuevo:** `edusync/includes/web_push_dispatcher.php`
- `edusync/cron/web_push_dispatch.php`
- `edusync/includes/push_notifications.php`
- `edusync/collection_notifications_api.php`
- `edusync/grade_closure_api.php`
- `edusync/admin_grade_closure_api.php`
- `edusync/admin_class.php`
- `edusync/attendance_api.php`
- **Nuevos:** `tools/test_web_push_unified_v1.php`,
  `tools/check_web_push_unified_v1.ps1`

**Reversión:** volver a `notifications-intelligent-v1`; no hay
migraciones SQL ni actualizaciones de tokens que revertir.
