# EduSync · Panel administrativo de avisos docentes

Rama: teacher-alerts-admin-control-v1 (basada en teacher-academic-alerts-v1).
NO integrado en main ni producción.

## Ubicación
Administrador > Años académicos > Gestionar año > Cierre de notas >
Notificaciones a docentes.

Se mantiene la pantalla de cierres que usa SB Admin 2. Se añade una sección
debajo del control habitual con:
- Indicadores de asignaciones cerradas, pendientes y con calificaciones faltantes.
- Tabla compacta por docente y cursos abiertos, búsqueda, bimestre y progreso.
- Configuración por colegio / año / bimestre con fecha límite opcional y avisos
  automáticos configurables: cierres, notas incompletas y correcciones de notas
  hechas por administración (según historial con usuario editor de tipo 1).
- Un botón «Recordar» por docente con confirmación y observación opcional.
- Historial de 30 avisos de ese bimestre y estados de entrega FCM cuando existen.

## Diferencia entre fecha y bloqueo
La fecha límite de avisos PUEDE diferir de academic_periods.end_date.
Por defecto se toma end_date y se avisa con 3 días de anticipación;
se puede seleccionar 1–14 días. Una fecha nueva debe estar dentro del periodo
de gestión del año (desde el inicio del bimestre o del año, hasta 30 días
después del fin académico del año).

Esta fecha controla LOS AVISOS. NO cierra automáticamente calificaciones ni
anula el bloqueo institucional del bimestre, que continúa en sus controles
existentes. Los recordatorios automáticos no se envían a cursos ya cerrados
ni en periodos bloqueados; el manual tampoco.

## Seguridad y antispam
- API sólo acepta usuarios Administrador del mismo colegio.
- Las acciones POST exigen token CSRF de sesión.
- La acción manual confirma que destinatario y asignaciones pertenecen al
  colegio, año y bimestre, y que todavía hay cursos sin cerrar.
- Bloqueo MySQL por docente/año/bimestre y ventana de 24 horas entre avisos
  manuales al mismo usuario (no importa qué administrador inició el envío).
- Se registran los envíos manuales y su usuario emisor en
  teacher_alert_manual_log, y se crea un evento en notification_events.
- Los avisos se sincronizan con la bandeja móvil y la web. No se incluyen
  notas individuales ni DNI en el mensaje push.
- El estado «Aceptado por FCM» no demuestra lectura, sino aceptación de
  la notificación por el servicio de mensajería.

## Migración requerida (no automática)

En phpMyAdmin de la BASE LOCAL, importar/ejecutar:
    sql/teacher_alert_admin_control_v1.sql

Requiere las migraciones anteriores de año académico, cierre bimestral,
notificaciones de personal e historial de notas. No se modifican ni
eliminan datos de esas tablas.

## Probar en local
PowerShell (solo si tu árbol Git no tiene cambios por conservar):

    cd C:\xampp\htdocs\interfaz
    git status --short
    git fetch origin
    git switch --track origin/teacher-alerts-admin-control-v1

Con PHP de XAMPP:

    C:\xampp\php\php.exe -l edusync/teacher_notifications_admin_api.php
    C:\xampp\php\php.exe -l edusync/includes/teacher_action_notifications.php
    C:\xampp\php\php.exe -l edusync/academic_year.php
    C:\xampp\php\php.exe -l edusync/notifications_api.php
    C:\xampp\php\php.exe tools/test_teacher_alert_admin_control_v1.php
    C:\xampp\php\php.exe edusync/cron/teacher_action_notifications.php --dry-run

Después de importar la migración, entrar al perfil Administrador, configurar
una fecha y guardar. Recargar para comprobar persistencia; comprobar cada
bimestre de forma independiente. Verificar que NO se habiliten botones para
un año cerrado. Filtrar un docente con cursos abiertos; enviar recordatorio.
Repetir en menos de 24 h debe rechazar duplicado. El docente correcto verá
un solo evento en su bandeja. Comprobar que otro docente no lo ve.

**No usar --send en producción**. Se puede probar la notificación
persistente con el botón de la pantalla local, pero si la base de prueba
tiene tokens FCM reales conectados, el envío puede llegar a esos dispositivos.
Configurar tokens de prueba y usar una base de datos de pruebas.

El cron ya existente edusync/cron/web_push_dispatch.php genera avisos
automáticos cuando la tarea esté configurada y ejecutándose. Tener la rama
en Git no habilita la tarea programada por sí mismo.

## Archivos nuevos y modificados

- sql/teacher_alert_admin_control_v1.sql
- edusync/teacher_notifications_admin_api.php
- edusync/includes/teacher_action_notifications.php
- edusync/academic_year.php
- edusync/js/teacher_notifications_admin.js
- edusync/css/teacher_notifications_admin.css
- edusync/notifications_api.php
- tools/test_teacher_alert_admin_control_v1.php

También arrastra cambios anteriores de teacher-academic-alerts-v1:
los cron existentes y su integración FCM. No transferir solo esta lista
a un sistema que no tiene instaladas las ramas base de notificaciones.

## Pendiente antes de producción
Validar pruebas de base de datos, permisos de otros colegios, estados de
cierres y bloqueos, compatibilidad con las variantes de esquema ya desplegadas,
sintaxis PHP/JS y entrega FCM con la app cerrada. No fusionar sin aprobación.
