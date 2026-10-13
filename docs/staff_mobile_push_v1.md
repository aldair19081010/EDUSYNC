# Notificaciones móviles para todos los perfiles — prueba v1

## Alcance
Los estudiantes mantienen íntegramente su flujo actual de Firebase: entradas/salidas por escáner, pagos nuevos, vencimientos del día, notas al guardar manualmente, comunicados y navegación a sus módulos. No hay recordatorios recurrentes nuevos.

Administrador (1), Docente (2) y Auxiliar (3) cuentan con registro FCM por sesión/dispositivo, bandeja en Flutter y apertura de avisos. Los avisos del personal provienen **solo de** `notification_events`, filtrados por colegio y destinatario (usuario o rol) exactamente como en la bandeja web. No se envían indiscriminadamente a todos los roles.

## Instalación de prueba (no producción)
1. Utilizar las ramas `push-all-roles-v1` de repositorios web y Flutter. La rama Flutter deriva de `teacher-app-integrada-v1` para conservar el módulo docente en progreso.
2. Hacer respaldo de base de datos y ejecutar manualmente `sql/staff_push_notifications.sql`. Mantener `sql/notifications_module_upgrade.sql` previamente instalado.
3. Configurar credenciales de Firebase existentes **fuera del repositorio** con `EDUSYNC_FIREBASE_CREDENTIALS` o `EDUSYNC_FIREBASE_CREDENTIALS_JSON`, tanto para PHP web como para el proceso de consola.
4. Ejecutar manualmente desde consola la prueba de entrega: `php edusync/cron/staff_push_notifications.php`. En hosting configurar una tarea cron **cada minuto** apuntando a este archivo con la variable de credenciales accesible al proceso. No exponer el archivo como una URL.
5. Instalar un APK compilado de la rama de prueba; salir y volver a iniciar sesión del personal para que se guarde el `userId` y se vincule el token.
6. Desde la web, generar una notificación existente para el rol correspondiente (p. ej., un docente cierra un bimestre para avisar a Administración). Comprobar la bandeja del móvil, notificación del sistema, apertura y estado de leído sincronizado con la web.
7. Repetir para Docente y Auxiliar con eventos propios. Comprobar que no reciben eventos de otros usuarios/colegios y que al cambiar de cuenta el token deja de estar vinculado a la sesión previa.

## Límites de esta primera versión
- El envío FCM **requiere** la tarea cron; subir el código no basta.
- La bandeja móvil del personal presenta avisos existentes de `notification_events`. Los avisos virtuales generados mediante consultas agregadas dentro de `notifications_api.php` (no persistidos en `notification_events`) todavía no generan push.
- Al pulsar una notificación se abre la bandeja y el detalle accesible a ese usuario. No redirige a un módulo administrativo que todavía no exista en la app.
- No se probaron aquí la base de datos real, las credenciales de producción, la entrega FCM en teléfonos, ni la compilación con Flutter del módulo docente. No pasar a `main` sin completar pruebas reales.

## Archivos web
- `edusync/api/staff_device_notifications.php` (nuevo)
- `edusync/api/staff_notifications.php` (nuevo)
- `edusync/api/device_notifications.php` (aislamiento al cambiar entre estudiante y personal)
- `edusync/cron/staff_push_notifications.php` (nuevo)
- `sql/staff_push_notifications.sql` (nuevo)

La tabla `staff_push_delivery` evita notificaciones duplicadas por evento y dispositivo, y limita reintentos; la tabla `staff_device_tokens` asocia cada dispositivo a una cuenta válida.
