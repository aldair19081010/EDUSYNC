# EduSync · Panel administrativo de avisos docentes

Rama original: teacher-alerts-admin-control-v1.
Rama de pantalla independiente: teacher-followup-standalone-v1.
NO integrado en main ni producción.

## Ubicación
Administrador > menú «Académico» > «Seguimiento Docente».

Este es un módulo administrativo independiente. La pantalla de «Años Académicos
> Gestionar > Cierre de notas» queda dedicada exclusivamente a cierres y
bloqueos. Comunicados, Cobranza y Notificaciones conservan su ubicación y
funcionamiento.

La nueva página usa SB Admin 2, filtros compactos y acciones bajo demanda.
Ofrece:
- Tres indicadores compactos: asignaciones cerradas, pendientes y con notas
  incompletas; filtro de año, bimestre, estado y búsqueda.
- Una fila resumida por docente con progreso y botón «Avisar».
- Botón «Configuración» que abre un modal para definir fechas límite y activar
  o desactivar tipos de avisos automáticos para ese colegio/año/bimestre.
- Botón «Historial» que abre otro modal con los últimos 30 avisos,
  destinatarios y estados FCM.
- Envío manual con confirmación y observación opcional.
- Años anteriores consultables sin permitir guardado ni avisos manuales.
- Se conserva la migración y la API original; no hay tablas nuevas en
  esta reubicación.

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

Después de importar la migración, entrar al perfil Administrador >
Académico > Seguimiento Docente. Configurar una fecha en el modal y guardar.
Recargar para comprobar persistencia; comprobar cada bimestre.
Verificar que NO se habiliten los envíos ni el guardado al elegir un año
cerrado. Filtrar un docente con cursos abiertos; enviar recordatorio.
Repetir en menos de 24 h debe rechazar duplicado. El docente correcto verá
un solo evento en su bandeja. Comprobar que otro docente no lo ve.

**No usar --send en producción**. Se puede probar la notificación
persistente con el botón de la pantalla local, pero si la base de prueba
tiene tokens FCM reales conectados, el envío puede llegar a esos dispositivos.
Configurar tokens de prueba y usar una base de datos de pruebas.

El cron ya existente edusync/cron/web_push_dispatch.php genera avisos
automáticos cuando la tarea esté configurada y ejecutándose. El barrido de
evaluaciones se limita a una vez cada 10 minutos usando
teacher_alert_scan_state para proteger el rendimiento; la cola FCM sigue
despachándose en cada ejecución del cron. Tener la rama en Git no habilita
la tarea programada por sí mismo.

## Archivos nuevos y modificados

- sql/teacher_alert_admin_control_v1.sql
- edusync/teacher_notifications_admin_api.php
- edusync/includes/teacher_action_notifications.php
- edusync/academic_year.php (se retiró el panel añadido previamente)
- edusync/index.php (ruta en la lista blanca de administradores)
- edusync/includes/navbar_view.php (entrada de menú)
- edusync/pages/teacher_followup.php (nueva pantalla)
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
