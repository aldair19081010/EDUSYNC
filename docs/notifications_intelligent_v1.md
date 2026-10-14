# EduSync · Notificaciones coherentes por perfil (fase 1)

**Rama:** `notifications-intelligent-v1`, basada en `remember-session-30d-v1`.
**Objetivo:** corregir la repetición de alertas académicas y armonizar el centro
de avisos con la interfaz vigente de EduSync. No agrega módulos nuevos ni cambia
la tecnología Firebase que ya funciona. **No está desplegado a producción.**

## Causa identificada y solución

Antes, `Action::generate_low_grade_notifications()` eliminaba **todas** las
alertas de una sección y bimestre al guardar notas y las volvía a insertar con
nuevos ID. Por ello, se perdían estados de lectura, el sondeo de la campana
mostraba avisos nuevos y el cron de Web Push podía volver a enviar avisos.

Ahora la misma función calcula un **estado deseado** y concilia por:
`estudiante:bimestre:destinatario` (Administración o cada docente).

- Si aparece por primera vez un caso con **3 o más notas <= 10 en un mismo
  curso**, genera una alerta para Administración y otra para el docente
  que corresponde (regla vigente, no se cambió).
- Si el caso continúa, conserva el mismo **ID, fecha de creación y lecturas**.
  Si cambia la cantidad de notas bajas, solo actualiza el resumen.
- Si deja de cumplir el umbral, elimina la alerta vigente y sus lecturas.
- Si luego vuelve a caer, es **un episodio nuevo** y puede notificar de nuevo.
- Varias evaluaciones modificadas en una operación se concilian sin reiniciar
  alertas ya existentes. Los cálculos simultáneos del mismo grupo se serializan
  con un bloqueo MySQL que se libera siempre.
- Si existen duplicados históricos en `low_grade_notifications`, conserva
  un registro y fusiona las lecturas tanto de `low_grade_notification_read`
  como de la bandeja unificada `notification_user_state` antes de eliminar los
  duplicados. También retira estados obsoletos de casos que se resolvieron.

El cron `web_push_dispatch.php` utiliza el ID estable del episodio; su tabla
`web_push_delivery` evita reenviar el mismo evento a un navegador que ya lo
recibió. La notificación del sistema operativo ahora muestra el nivel, grado,
sección y bimestre **sin revelar nombre completo ni notas del estudiante**.
Al tocarla, abre el detalle tras validar colegio, rol y usuario.

## Presentación por perfil (funciones existentes)

| Perfil | Contenido | Destino |
|---|---|---|
| Administrador | Nuevos casos de notas bajas, evaluaciones, cierres de notas, solicitudes de asistencia o reapertura | Centro de notificaciones y detalle autorizado |
| Docente | Casos académicos de sus propios cursos, avisos expresamente dirigidos al docente | Centro de notificaciones y detalle autorizado |
| Auxiliar | Solicitudes de asistencia y comunicaciones de su perfil | Centro de notificaciones, sin mostrar riesgo de otros docentes |
| Estudiante | Sus notas, asistencia, pagos, deudas y comunicados | Bandeja individual enlazada con los eventos de su app |

**No se crean envíos inexistentes.** Se mantienen los generadores y permisos
por perfil actuales; esta fase corrige específicamente el ciclo de vida de
las alertas de **notas bajas**. El modelo predictivo de riesgo académico
inteligente no se ha modificado.

La interfaz utiliza los estilos **SB Admin 2** ya presentes en EduSync:
azul institucional, fondo claro, tarjetas blancas, iconos Font Awesome,
botones Bootstrap y tablas existentes. Los textos de cada perfil se
ajustan sin introducir un diseño visual ajeno al sistema. En el estudiante
se aclaran las categorías `Notas y deudas` y `Asistencia y pagos`.

## Campana: cuándo se actualiza y cómo se marca lectura

El sondeo para personal pasa de **cada 7 a cada 20 segundos**, solo en
pestañas visibles. La campana también se actualiza al volver al navegador,
al abrir su desplegable o al revisar solicitudes de asistencia. Esto **no**
retrasa la entrega real de Web Push, que depende de Firebase/Service Worker.

Abrir desde la campana una notificación sin leer ahora registra la lectura
en `notification_user_state`, aunque se abra como modal o como enlace. La
misma alerta no debe volver a mostrarse como `Nueva` en las siguientes
consultas. La fecha e ID permanecen estables mientras persista el riesgo.

## Pruebas locales (SIN tocar producción)

```powershell
cd C:\xampp\htdocs\interfaz
git fetch origin
git switch notifications-intelligent-v1
git pull --ff-only origin notifications-intelligent-v1
powershell -ExecutionPolicy Bypass -File .\tools\check_notifications_intelligent_v1.ps1
```

**No hay SQL nuevo para esta fase** y los archivos de conexión locales deben
conservarse. Antes de probar cambios reales de notas en tu base de desarrollo,
haz copia de seguridad.

1. Usa una **sección y evaluación de prueba** con un estudiante que cumpla
   el umbral de 3 notas bajas. Comprueba alerta para Administración y su
   docente (no para Auxiliar ni otra cuenta).
2. Abre desde la **campana** y comprueba que se marca leída. Guarda de
   nuevo exactamente las mismas notas: **no debe reaparecer como nueva**
   ni generar otro Push del mismo episodio.
3. Modifica una nota manteniendo el riesgo. Debe cambiar el resumen,
   conservarse el mismo ID y su estado de lectura.
4. Corrige las notas hasta no cumplir el umbral. La alerta debe salir de
   la bandeja activa.
5. Introduce nuevamente un caso que sí cumpla el umbral; ahora procede
   una **nueva alerta**.
6. Con cada perfil, comprueba navegación, botones, filtros, detalles y
   adaptación a pantallas pequeñas. Verifica que los estudiantes continúen
   viendo únicamente sus avisos personales.
7. Revisa que sigan llegando los avisos de **cierre bimestral**, pagos,
   deudas y asistencia ya disponibles.

El script solo verifica sintaxis y reglas sin conectarse a MySQL. No garantiza
por sí solo la entrega de Firebase: valida los casos anteriores en XAMPP.

## Despliegue y reversión

No fusionar a `main` sin autorización y pruebas aprobadas. Archivos:
- `edusync/includes/academic_alert_policy.php` (nuevo)
- `edusync/admin_class.php`
- `edusync/notifications_api.php`
- `edusync/includes/topbar.php`
- `edusync/pages/notifications.php`
- `edusync/pages/student_notifications_web.php`
- `edusync/cron/web_push_dispatch.php`
- `tools/test_notifications_intelligent_v1.php` (nuevo)
- `tools/check_notifications_intelligent_v1.ps1` (nuevo)

Reversión: regresar a `remember-session-30d-v1` y restaurar sus archivos,
sin necesidad de deshacer ninguna migración. El servidor de producción no
debe actualizarse hasta que se autorice expresamente.
