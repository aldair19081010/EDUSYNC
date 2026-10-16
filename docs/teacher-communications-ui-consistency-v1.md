# Comunicados Docentes: consistencia visual EduSync

## Alcance

Rama basada en fix-flutter-login-session-id-v1. Sólo cambia la vista web
edusync/pages/announcements.php; NO modifica PHP de API, SQL ni app Flutter.

Conserva ed-page-header, ed-stat-card, ed-content-card, ed-table,
ed-modal-section, ed-filter-card y el Select2 del sistema.

### Cambios
- Un solo selector de ámbito Estudiantes / Docentes, fuera del modal.
  En el modal sólo aparece una insignia de contexto (no un selector redundante).
- Opciones «Todos los docentes» y «Seleccionar docentes» convertidas en
  tarjetas del mismo estilo que «Todo el colegio», Nivel, Grado, etc.
  Cada tarjeta tiene estado visual activo, icono y foco de teclado.
- Select2 múltiple ajustado al tamaño y bordes de los otros formularios.
- Un resumen compacto muestra la selección, cuenta de docentes y estado.
- Vista previa identifica el perfil destinatario.
- Historial y detalle distinguen «Aceptado FCM» y lectura: una aceptación
  de FCM no garantiza que el usuario haya visto la notificación.
- El detalle marca la disponibilidad del comunicado en bandeja docente.
- No cambia el endpoint de estudiantes ni las validaciones y envío de docentes.
- Mantiene pantalla usable en móvil y modal desplazable.

## Pruebas locales
En PowerShell, sin descartar los cambios locales:

    cd C:\xampp\htdocs\interfaz
    git status --short
    git fetch origin
    git switch --track origin/teacher-communications-ui-consistency-v1
    C:\xampp\php\php.exe -l edusync/pages/announcements.php
    C:\xampp\php\php.exe tools/test_teacher_communications_ui_v1.php
    C:\xampp\php\php.exe tools/test_teacher_announcements_v1.php

Abrir http://localhost/interfaz/edusync/index.php?page=announcements
como administrador y comprobar los dos modos:
1. Estudiantes: audiencia, Select2, contador, vista previa, enviar a 1 alumno.
2. Docentes: Todos y Seleccionar, elegir 2 en Select2, quitar 1 y comprobar
   el contador y la confirmación; enviar a un profesor de prueba.
3. Historial: comprobar estadísticas, destinatarios, lectura y distintivos FCM.
4. Pantalla angosta: comprobar que no hay desplazamiento horizontal excesivo.
5. No es necesario aplicar nuevas migraciones SQL para esta rama.

## Estado
Cambios disponibles sólo en rama de prueba. No se ha ejecutado PHP/XAMPP,
navegador ni entrega Firebase real desde el conector de GitHub.
NO hacer merge a main o despliegue a producción hasta prueba local.
