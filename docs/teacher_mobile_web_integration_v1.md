# EduSync · Integración web + app docente (rama de pruebas)

**Rama:** `teacher-mobile-web-integrated-v1` (partiendo de `notifications-intelligent-v1`).
**Estado:** propuesta para pruebas **locales**; no fusionada con `main` ni publicada en producción.

## Funcionalidades preservadas

- **Notificaciones web:** se conserva el código de `notifications-intelligent-v1`, incluidas las alertas académicas y Web Push.
- **Mantener sesión 30 días (navegador):** se conserva `remember_me` y la cookie segura. No se utiliza como autenticación de la app móvil.
- **Login móvil:** `auth_login.php` y `api/login.php` vuelven a entregar `session_id` después de regenerar la sesión PHP; así la app puede consumir las API con `sid`. No se cambia el tiempo de vida de la app a 30 días.
- **Docentes Flutter:** API de cursos, alumnos, asistencia y libro de notas de `teacher-mobile-api-v1`.
- **Libro de Notas:** los cambios de nota se guardan manualmente. La API preserva competencias y notas históricas; no se añadió autoguardado a la app.
- **Cierre de bimestre:** el porcentaje del servidor considera el peso de **todas** las competencias. Sin evaluaciones en una competencia, esa competencia aporta 0 %. El cierre exige progreso 100 %, notas completas y demás reglas vigentes.

## Ramas de origen

| Funcionalidad | Origen |
| --- | --- |
| Sesiones web y notificaciones | `notifications-intelligent-v1` |
| API docente web | `teacher-mobile-api-v1` |
| Avance de cierre | `grade-closure-coverage-v1` |
| Compatibilidad del inicio de sesión con la app | `fix-remember-mobile-session-v1` |
| UI Flutter y guardado manual | `teacher-app-integrada-v1` (repo de la app; **sin merge**) |

Los endpoints mantienen los archivos compartidos de recordatorio de sesión y notificaciones de la rama base.

## Pruebas locales sin modificar datos

Desde PowerShell, si el árbol de trabajo está limpio:

```powershell
cd C:\xampp\htdocs\interfaz
git status --short
git fetch origin
git switch --track origin/teacher-mobile-web-integrated-v1

$php = 'C:\xampp\php\php.exe'
$files = @(
  'edusync\auth_login.php',
  'edusync\api\login.php',
  'edusync\api\teacher_courses_mobile.php',
  'edusync\api\teacher_attendance_mobile.php',
  'edusync\api\teacher_gradebook_mobile.php',
  'edusync\api\my_attendance.php',
  'edusync\gradebook_api.php',
  'edusync\grade_closure_api.php',
  'edusync\includes\grade_closure_progress.php'
)
foreach ($file in $files) { & $php -l $file; if ($LASTEXITCODE -ne 0) { throw "Error PHP: $file" } }

& $php tools\test_teacher_mobile_web_integration_v1.php
& $php tools\test_remember_mobile_session_contract.php
& $php tools\test_remember_session_30d_v1.php
& $php tools\test_notifications_intelligent_v1.php
& $php tools\teacher_attendance_history_smoke_test.php
& $php tools\grade_closure_progress_test.php
```

Si `git status --short` indica cambios locales, **no descartarlos**: guardarlos o utilizar un worktree separado. Con un worktree hay que configurar su `db_connect.php` y la URL propia antes de probar en el navegador o la app.

Para Flutter:

```powershell
cd C:\gestion_pagos_app
git fetch origin
git switch teacher-app-integrada-v1
git pull --ff-only origin teacher-app-integrada-v1
flutter test test/auth_session_guard_test.dart
flutter test test/teacher_gradebook_cells_test.dart
flutter test test/teacher_grade_closure_progress_test.dart
flutter test test/teacher_gradebook_history_review_test.dart
flutter run
```

**Importante:** `flutter run` debe usar la misma instalación web local integrada. Verificar `EDUSYNC_WEB_BASE_URL`; para una URL diferente ejecutar, por ejemplo,
`flutter run --dart-define=EDUSYNC_WEB_BASE_URL=http://IP_DE_TU_PC/interfaz/edusync`.
No compartir sesión PHP, cookies o contraseñas en reportes de prueba.

## Migraciones: comprobación previa

El código de módulos existentes puede requerir que ya estén aplicadas las migraciones:
`sql/gradebook_history_upgrade.sql`, `sql/grade_closure_upgrade.sql` y
`sql/remember_session_30d_v1.sql`. **No ejecutar migraciones a ciegas.**
Hacer respaldo de la BD de pruebas, verificar las tablas que ya existen y aplicar
manualmente solo las migraciones faltantes, siguiendo el orden indicado en cada SQL.

## Validación funcional con datos de demostración

1. Iniciar sesión como **docente** desde la app; comprobar carga de Inicio, cursos y alumnos; cerrar y volver a iniciar sesión. Confirmar que no aparece «Tu sesión no está disponible».
2. Iniciar sesión como **estudiante**; verificar pantalla e información académica.
3. En el Libro de Notas de un curso de **prueba**, editar dos notas mediante Guardar; cerrar y volver a abrir. Las demás notas deben permanecer.
4. Revisar una evaluación antigua vinculada a varias competencias; no debe mezclar calificaciones ni permitir cambios si faltan asociaciones verificables.
5. Consultar cierre con una competencia **sin evaluaciones**: la barra no debe marcar 100 % y el botón debe permanecer inhabilitado. Completar todas las evaluaciones y notas de un **curso de prueba** y comprobar que el servidor cambia a «Listo».
6. Ejecutar el cierre real solo en curso de **pruebas** con autorización, pues se genera registro/auditoría y queda en solo lectura; comprobar solicitud de reapertura.
7. En web, marcar «Mantener sesión durante 30 días» y comprobar el mecanismo del navegador; repetir sin marcarlo. Verificar notificaciones y que la identidad de otros perfiles no se mezcle.

Las pruebas puras verifican estructura y reglas; **no sustituyen** las pruebas con PHP/MySQL, sesión real, FCM o Flutter. No publicar ni fusionar antes de validarlas.
