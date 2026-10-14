# EduSync web: notificaciones para todos los perfiles (rama web-notifications-all-roles-v1)

Esta implementación modifica únicamente el proyecto PHP/JavaScript **EDUSYNC web**. No modifica ni requiere recompilar la app Flutter.

## Perfiles y origen de la información

- Administrador, Docente y Auxiliar: conservan la campana y el Centro de notificaciones unificado (`notifications_api.php`). Se marca como leído al abrir desde la campana.
- Estudiante: la campana y la nueva vista de «Mis notificaciones» consultan la tabla `student_notification_events` a través del endpoint `api/student_notifications.php`, el mismo que alimenta la app Android. Muestra asistencias, pagos, deudas, notas y comunicados ya generados. La lectura se sincroniza con la app.
- En ningún caso se mezclan avisos entre usuarios o colegios; los endpoints existentes validan las sesiones.

## Funcionalidades
1. Campana con contador de avisos sin leer y lista resumida.
2. Actualización automática con la página abierta (personal: 7 s ya existente; estudiantes: 10 s).
3. Notificación flotante discreta para nuevos avisos después de cargar la pantalla.
4. Permiso opcional, concedido con clic explícito, para avisos del navegador en un origen seguro (HTTPS o localhost). No se pide permiso automáticamente.
5. Centro de estudiantes con filtros de Comunicados, Alertas y Actividad; detalle de comunicados consultado con autorización de usuario.
6. Marcar avisos como leídos individualmente y todos a la vez; accesos a los módulos estudiantiles permitidos.

**Limitación:** no es Web Push en segundo plano. Los avisos emergentes y notificaciones del navegador funcionan mientras la web está abierta, incluidas pestañas minimizadas cuando el navegador lo permita. Para notificar con el sitio cerrado se requeriría otra fase con Service Worker, suscripción Web Push y permisos.

## Prueba local
```powershell
cd C:\xampp\htdocs\interfaz
git fetch origin
git switch --track origin/web-notifications-all-roles-v1
```

No subir a producción ni pasar a `main` sin la aprobación de quien mantiene EduSync.

### Casos de aceptación
- Estudiante: recibir un evento de prueba (por ejemplo, un pago registrado) y comprobar que se ve tanto en la app como en la campana web de la misma cuenta. Al marcarlo leído en la web debe aparecer como leído en la app al actualizar.
- Docente: comprobar que sigue recibiendo únicamente avisos de su sesión y colegio; verificar campana y Centro de notificaciones.
- Administrador: cerrar un bimestre de prueba desde un docente; comprobar el aviso administrativo, abrir el detalle y estado de lectura.
- Auxiliar: comprobar avisos dirigidos a su rol, no avisos de otros roles.
- Iniciar sesión con un estudiante de otro colegio: comprobar que no ve avisos de la cuenta anterior.
- Activar «Avisos del navegador» mediante clic y generar un aviso nuevo con la web abierta. Comprobar además el comportamiento con permisos denegados, red caída y ventana estrecha.

## Requisitos existentes
- Módulo de notificaciones administrativas instalado mediante `sql/notifications_module_upgrade.sql`.
- Tablas de notificaciones estudiantiles instaladas según `sql/student_push_notifications.sql`, porque la app ya las usa.
- No se crea ninguna tabla adicional ni se añaden notificaciones que antes no existían.
