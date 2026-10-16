# EduSync · Entrega de notificaciones docentes en Android

Rama web: teacher-mobile-push-status-v1 (deriva de teacher-followup-fast-table-v1).
Rama Flutter complementaria: teacher-push-inbox-reliability-v1 (deriva de teacher-notifications-unified-pilot-v1).

## Diagnóstico y corrección

Los navegadores Android y la aplicación Flutter se registran como destinos
FCM independientes, aun si están en el mismo teléfono. Que Chrome muestre
una notificación NO confirma que EduSync Flutter esté registrado.

Los dispositivos identificados como browser_id que comienza con app- reciben
notification + data en FCM, usando el canal Android staff_alerts.
Cuando la app está cerrada, Android muestra una sola notificación del sistema;
el clic preserva data.tag (edusync-staff-COLEGIO-EVENTO) para abrir la bandeja.
Cuando la app está al frente, Flutter muestra una notificación local; cuando
está en segundo plano con payload notification, el handler evita duplicarla.

Los navegadores mantienen el payload data-only gestionado por Service Worker.
El despacho inmediato y el cron usan la misma detección de tipo de destino.
Los avisos de estudiantes conservan su mecanismo existente.

## Diagnóstico seguro

GET api/web_push.php?action=app_status&browser_id=app-DEVICE_ID,
con la sesión docente actual (sid en la solicitud XHR), informa:
- registered: si este dispositivo Flutter específico figura como activo;
- push_ready: tablas de Web Push disponibles;
- sender_project_id: identificador público del proyecto Firebase que envía;
- last_seen_at_utc y latest_delivery_status: diagnóstico sin revelar token.

El endpoint exige usuario docente y restringe cada registro a su colegio,
usuario y browser_id. No devuelve fcm_token ni credenciales.

## Comprobar en local

1. Sin mezclar ramas, probar primero la rama web. php -l en:
   edusync/api/web_push.php
   edusync/includes/web_push_fcm_sender.php
   edusync/includes/web_push_staff_events.php
   edusync/cron/web_push_dispatch.php
2. Ejecutar C:\xampp\php\php.exe tools/test_teacher_native_push_v1.php
3. Compilar Flutter con su archivo local android/app/google-services.json.
   La configuración de Android debe pertenecer al MISMO proyecto Firebase
   que la cuenta de servicio del PHP. No subir google-services.json ni secretos.
4. Instalar el APK modificado en el Android, iniciar sesión como docente y
   abrir la campana. «Activar» permite reintentar el registro nativo.
5. Comprobar dentro de la app que la suscripción está activa y el permiso
   Android está concedido. Enviar un AVISO NUEVO desde Seguimiento docente.
6. Probar app visible, en segundo plano y al tocar una notificación recibida.
   Probar también Chrome para verificar que no se duplica.
7. Confirmar que el aviso aparece en la bandeja de la app y en el contador.

La prueba funcional FCM exige una BD y dispositivos de pruebas. No modificar
producción hasta validar el servidor y el APK conjuntamente.

## Archivos web afectados

- edusync/api/web_push.php
- edusync/includes/web_push_fcm_sender.php
- edusync/includes/web_push_staff_events.php
- edusync/cron/web_push_dispatch.php
- tools/test_teacher_native_push_v1.php
