# EduSync · Alertas académicas accionables para docentes (v1)

Rama web: teacher-academic-alerts-v1, derivada de teacher-notifications-unified-v1.
La app continúa en teacher-notifications-unified-pilot-v1: ya lee
notification_events y abre la bandeja por destinatario.

## Avisos y frecuencia

| Tipo | Momento | Destinatario | Repetición |
| --- | --- | --- | --- |
| Próximo cierre | Entre tres días antes y el último día de academic_periods.end_date, si quedan cursos abiertos | Docente vinculado | Uno por docente y bimestre |
| Cierre pendiente | Desde el día siguiente al fin hasta 14 días después, mientras no esté bloqueado ni cerrado | Docente vinculado | Uno por docente y bimestre |
| Notas incompletas | Tres días antes del fin, si una evaluación activa tiene celdas sin nota | Docente vinculado | Uno por docente y bimestre |
| Corrección administrativa | Historial de cambio real de nota existente con changed_by perteneciente a users.type=1 | Docente del curso | Agrupado por curso, docente y hora |

Se envían resúmenes, no un aviso por estudiante ni por autoguardado. Los mensajes
push no incluyen DNI ni calificaciones individuales. Las fechas usadas son las
fechas de fin del periodo académico configurado: no inventamos un plazo diferente.
Si faltan fechas en academic_periods, no se generan recordatorios de cierre.

Solo se consideran años activos, periodos no bloqueados, cursos todavía no
cerrados, docentes activos y usuarios del mismo colegio. Los avisos de cada
tipo/periodo/docente se deduplican con fuente estable y un bloqueo MySQL.

## Correcciones por Administración

Se consultan los cambios del historial evaluation_grade_history.changed_by
que corresponden a usuarios de tipo Administrador. No se generan avisos por
ediciones del propio docente. El sistema de notas actual no concede permisos
nuevos de edición a Administración. Si algún editor administrativo no escribe
este historial con changed_by, esa modificación no se podrá detectar:
deberá integrarse ese editor al historial antes de considerarlo cubierto.

## Activación y dependencia operativa

El despachador existente edusync/cron/web_push_dispatch.php revisa los
avisos nuevos cada 10 minutos cuando se ejecuta de manera frecuente
(por ejemplo cada minuto). Reutiliza notification_events y las
suscripciones Web Push / app existentes. No requiere SQL nuevo, pero sí
las migraciones que ya utiliza la web para periodos académicos,
cierre de notas, notificaciones, historial de notas y Web Push.

Para generar avisos, el cron tiene que ejecutarse realmente en el
servidor. La rama Git por sí sola no activa el cron ni entrega FCM.

## Prueba local sin modificar la base

En PowerShell, desde C:\xampp\htdocs\interfaz:

  php -l edusync/includes/teacher_action_notifications.php
  php -l edusync/cron/teacher_action_notifications.php
  php -l edusync/cron/web_push_dispatch.php
  php -l edusync/notifications_api.php
  php tools/test_teacher_academic_alerts_v1.php
  php edusync/cron/teacher_action_notifications.php --dry-run

La opción --dry-run no inserta notificaciones. Los contadores pueden
ser cero si el periodo está lejos de su fin o no hay correcciones recientes.

Para pruebas de persistencia, usa SOLO una base de desarrollo y ejecuta:

  php edusync/cron/teacher_action_notifications.php --send

Después repite ese comando: no debe duplicar ningún evento del mismo
periodo/destinatario/tipo. Comprueba el mismo aviso en la web y app,
incluido marcado como leído; verifica push abierto/segundo plano/cerrado.
Prueba un bimestre que termina en tres días, evaluaciones incompletas
y que un curso ya cerrado o bloqueado no aparezca como pendiente.

NO ejecutar --send en producción sin autorización. Hacer copia de la
base de pruebas antes de introducir calificaciones artificiales.

## Archivos

- edusync/includes/teacher_action_notifications.php (nuevo)
- edusync/cron/teacher_action_notifications.php (nuevo)
- edusync/cron/web_push_dispatch.php (actualizado)
- edusync/notifications_api.php (actualizado)
- tools/test_teacher_academic_alerts_v1.php (nuevo)

Sin fusión a main ni despliegue a producción.
