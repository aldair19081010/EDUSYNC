# EduSync | Web Push real con Firebase Cloud Messaging

**Rama de prueba:** web-push-fcm-v1 del repositorio `aldair19081010/EDUSYNC`.
No se modificó Flutter, `main` ni producción.

## Diferencia respecto a la campana actual

La campana administrativa existente sigue funcionando. Esta función añade avisos del **sistema operativo incluso con EduSync cerrado** mediante FCM y `firebase-messaging-sw.js`. No depende del sondeo de 7/12 segundos de una pestaña abierta.

- Administrador, Docente y Auxiliar: nuevos avisos persistidos en `notification_events`, entregados según colegio y usuario/rol.
- Estudiante: nuevos avisos persistidos en `student_notification_events`, **la misma fuente utilizada por la app EduSync Android**, sin copiar ni mover los tokens Android.
- En navegador, los avisos se activan **por dispositivo** con consentimiento explícito.
- Cerrar sesión desactiva Web Push solamente en el navegador actual; las otras sesiones del mismo usuario siguen suscritas.
- Al pulsar un aviso de estudiante se abre Mis Asistencias, Mis Pagos, Mis Deudas, Mis Notas o Mis notificaciones. Un aviso de personal abre su Centro de notificaciones autorizado.

**Alcance administrativo:** además de `notification_events`, el despachador contempla las solicitudes y resoluciones de asistencia, solicitudes de reapertura, evaluaciones y alertas de notas bajas que ya aparecen en la bandeja web. Los eventos se deduplican por origen e identificador; no se crean mensajes nuevos ni se duplican tablas académicas.

## 1. Antes de activar: configurar Firebase web

Usa **el mismo proyecto Firebase que usa la app Android EduSync**. No reutilices un token Android para el navegador. No subas el JSON de la cuenta de servicio a GitHub.

1. En Firebase Console → Configuración del proyecto → General → **Tus aplicaciones**, agrega una **aplicación Web** si no existe. Copia los datos públicos de `firebaseConfig`: `apiKey`, `authDomain`, `projectId`, `messagingSenderId`, `appId`.
2. En Configuración del proyecto → Cloud Messaging → **Web Push certificates**, genera una clave y copia la **clave VAPID pública**. FCM administra el par de claves; la clave privada **no** se publica.
3. Configura en el entorno de PHP/Apache:
   - `EDUSYNC_FIREBASE_WEB_CONFIG_JSON`: objeto JSON de `firebaseConfig`.
   - `EDUSYNC_FIREBASE_VAPID_KEY`: clave VAPID pública.
4. Para el envío desde PHP se reutiliza la configuración privada **que ya usa la app**: `EDUSYNC_FIREBASE_CREDENTIALS` (ruta absoluta al JSON fuera de la carpeta pública) o `EDUSYNC_FIREBASE_CREDENTIALS_JSON`. Debe estar disponible también para el proceso **cron/CLI**, no solamente para Apache.

Ejemplo de valor **ilustrativo** de `EDUSYNC_FIREBASE_WEB_CONFIG_JSON` (no son credenciales reales):

```json
{
  "apiKey":"TU_API_KEY_PUBLICA",
  "authDomain":"tu-proyecto.firebaseapp.com",
  "projectId":"tu-proyecto",
  "messagingSenderId":"123456789",
  "appId":"1:123456789:web:identificador"
}
```

Los datos públicos son parte del cliente Firebase, pero **el JSON de cuenta de servicio y la clave privada nunca deben ponerse en el frontend**.

### Configuración automática en tu XAMPP (recomendada)

En Windows, EduSync ya dispone de Firebase Android en `C:\gestion_pagos_app\android\app\google-services.json` y de su cuenta de servicio privada fuera del repositorio en `C:\xampp\firebase\edusync-service-account.json`.

Por eso se agregó `tools/setup_web_push_firebase_local.ps1`, que lee los identificadores públicos del proyecto Android y **solo pide los datos faltantes de la aplicación Web**, incluidos `apiKey`, `appId` y la clave VAPID pública. Ejecuta desde el proyecto web:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\setup_web_push_firebase_local.ps1
```

Los datos se guardan en `C:\xampp\firebase\edusync-web-public.json` (fuera de `htdocs` y de Git). La API web PHP detecta ese archivo local sin necesitar editar Apache. El envío PHP Web Push puede reutilizar automáticamente el servicio privado que ya tiene XAMPP.

**No requiere introducir ni mostrar secretos de Firebase.** En el hosting de producción seguir utilizando las variables de entorno, no las rutas locales Windows.

## 2. Migración de base de datos de prueba

1. Haz una copia de seguridad de la base de datos local o de prueba.
2. Ejecuta **sólo** `sql/web_push_fcm_v1.sql` (crea `web_push_subscriptions` y `web_push_delivery`).
3. Comprueba que la base ya contiene las tablas preexistentes `notification_events` (personal) y `student_notification_events` (estudiantes). Si no están, se necesitan los esquemas antiguos correspondientes, pero **no ejecutes scripts antiguos a ciegas sobre producción**.

No se habilitan envíos masivos hasta activar el cron manualmente.

## 3. Probar en Windows / XAMPP

```powershell
cd C:\xampp\htdocs\interfaz
git fetch origin
git switch --track origin/web-push-fcm-v1
```

Si ya tienes esa rama localmente: `git switch web-push-fcm-v1` y `git pull --ff-only origin web-push-fcm-v1`.

Usa **Chrome o Edge** desde `http://localhost/interfaz/edusync` para probar: `localhost` es una excepción de origen seguro para Service Workers. Una URL de red local `http://192.168.x.x` **no** basta: usa HTTPS en dispositivos externos.

1. Abre EduSync y entra con un perfil real.
2. En la campana, pulsa **Activar notificaciones** y concede el permiso del navegador.
3. Pulsa **Enviar prueba**; la notificación debería aparecer en Windows. Comprueba también que se muestre con la pestaña cerrada.
4. Para probar los avisos automáticos de la institución, ejecuta desde otra terminal (con PHP y sus variables Firebase configuradas):
   ```powershell
   php C:\xampp\htdocs\interfaz\edusync\cron\web_push_dispatch.php
   ```
5. Genera un evento real DESPUÉS de suscribirte (por ejemplo, que un docente cierre un bimestre). Repite el paso 4 y verifica que Administración lo recibe con EduSync cerrado.
6. Usa un aviso estudiantil existente de asistencia/pago/nota/comunicado y comprueba que llegue a la app y al navegador suscrito.
7. Prueba que **otro colegio**, otra cuenta o un perfil sin permiso no reciba el mensaje.

## 4. Configurar en hosting (solo tras aprobar las pruebas)

- Publicar los archivos de esta rama respetando las rutas. `firebase-messaging-sw.js` debe estar servido bajo el mismo origen HTTPS y dominio/ruta de la web de EduSync, con MIME JavaScript.
- Configurar en hosting un **cron PHP CLI cada minuto** apuntando a `edusync/cron/web_push_dispatch.php`. Si el hosting no permite cron, hay que usar un proceso de cola autorizado, nunca un endpoint público sin autenticación.
- El trabajo cron se protege con `GET_LOCK`, limita los envíos por ejecución, registra resultados, desactiva tokens inválidos y efectúa reintentos limitados.
- No configurar cron hasta que se hayan verificado permisos, roles y credenciales en entorno de pruebas.

## 5. iPhone / Safari y límites de compatibilidad

Apple admite Web Push para aplicaciones web **añadidas a pantalla de inicio** en iOS/iPadOS 16.4 o posterior. Para ello se añade `manifest.webmanifest` y EduSync debe servirse con HTTPS. Sin embargo, **FCM Web JavaScript no garantiza soporte en todos los Safari/iOS**: este primer piloto se valida en Chrome/Edge (Windows y Android compatibles). Para ampliar el soporte a Safari/iPhone puede hacer falta la entrega nativa de Web Push usando la Push API y Web Push estándar, sin depender del SDK FCM del navegador.

Los avisos requieren conexión del dispositivo y permiso de notificaciones; sistemas operativos y navegadores pueden retrasarlos.

## Archivos principales

- `sql/web_push_fcm_v1.sql` — migración manual
- `edusync/api/web_push.php` — configuración pública, registro, baja y prueba
- `edusync/firebase-messaging-sw.js` — recepción y apertura en segundo plano
- `edusync/includes/web_push_fcm_sender.php` — envío usando OAuth FCM HTTP v1
- `edusync/cron/web_push_dispatch.php` — despacho automático
- `edusync/js/web_push_fcm.js` — activación y registro por navegador
- `edusync/manifest.webmanifest` — instalación web
- `edusync/includes/topbar.php`, `edusync/index.php`, `edusync/logout.php` — integración
- `edusync/pages/notifications.php`, `edusync/pages/student_notifications_web.php`, `edusync/js/student_web_notifications.js`, `edusync/css/web_notifications.css` — bandeja de estudiantes.

**Nunca ejecutar una migración ni desplegar en producción sin pruebas locales y aprobación.**
