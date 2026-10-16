# EduSync: diagnóstico de login Flutter tras recuperar main

## Causa verificada

El main de EduSync Flutter conserva la sesión con AuthService/SharedPreferences,
pero para los perfiles de personal exige un session_id PHP válido en la respuesta
de auth_login.php. El main web mantenía «Mantener sesión durante 30 días»
pero había dejado de devolver session_id en los endpoints de personal y alumnos.

Este hotfix deriva estrictamente del main web actual y añade la respuesta
session_id después de que se regenere el ID, antes de session_write_close().
No modifica las cookies ni la persistencia del navegador, y no agrega SQL.

## Archivos

- edusync/auth_login.php
- edusync/api/login.php
- tools/test_mobile_login_session_contract_v1.php

## Test en el entorno local (PowerShell)

    cd C:\xampp\htdocs\interfaz
    git status --short
    git fetch origin
    git switch --track origin/hotfix-flutter-login-main-v1
    C:\xampp\php\php.exe -l edusync/auth_login.php
    C:\xampp\php\php.exe -l edusync/api/login.php
    C:\xampp\php\php.exe tools/test_mobile_login_session_contract_v1.php

En Flutter (main o rama de docentes), el proyecto ApiService selecciona la
base por EDUSYNC_WEB_BASE_URL; actualmente el valor predeterminado en main
es http://192.168.1.11/interfaz/edusync. Esa IP debe ser accesible desde el
teléfono o emulador y corresponder a la web local con el hotfix:

    flutter run --dart-define=EDUSYNC_WEB_BASE_URL=http://IP_PC/interfaz/edusync

Si se prueba contra https://edusync.site, el hotfix local NO surtirá efecto
hasta actualizar de forma autorizada esos archivos en el servidor.

No registrar ni compartir session_id o contraseñas en el diagnóstico.
La casilla web para mantener sesión por 30 días no es el mecanismo de la app.

## Recuperar mejoras de la app sin perder main

La rama Flutter teacher-announcement-inbox-v1 contiene todas las mejoras
previas de notificaciones y comunicados docentes; es descendiente del main
remoto comparado, con commits adicionales y sin commits de main pendientes.
Antes de cambiar de rama, ejecutar git status --short para identificar
modificaciones locales y conservarlas sin reset ni clean.

La rama web fix-flutter-login-session-id-v1 contiene el hotfix junto con
las funcionalidades de comunicados anteriores, pero esa rama y main web
divergieron: NO reemplazar main con esa rama sin una integración revisada.

## Estado

Código verificado estáticamente en la rama. Para validar credenciales y
persistencia real debe ejecutarse contra MySQL y PHP con la app enlazada al
servidor de pruebas. No se hizo merge a main ni despliegue a producción.
