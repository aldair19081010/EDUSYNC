# Cuenta docente — cambio voluntario de contraseña (EduSync Web)

Esta rama complementa la pantalla Cuenta de Flutter en
`aldair19081010/app_edusync`, rama `teacher-bottom-account-v1`.

## Endpoint nuevo

`edusync/api/teacher_change_password.php`

- Sólo POST; autenticación por sesión PHP del docente (rol 2, colegio e ID).
- La app aporta el SID autenticado y encabezado de petición XMLHttpRequest.
- En uso web con cookie, el endpoint exige el CSRF de la sesión.
- Comprueba que el usuario, vínculo docente y cuenta estén activos.
- Exige la contraseña actual; soporta hashes modernos y MD5 heredado.
- Nueva contraseña distinta, entre 8 y 64 caracteres, con letras y números.
- Usa sentencias preparadas y almacena sólo `password_hash`.
- No cambia ni cierra la sesión actual, ni obliga a modificar contraseñas.
- Detecta cambios concurrentes usando el hash actual como condición del UPDATE.
- Devuelve estado y mensaje JSON, nunca datos de la contraseña.

## En local antes de probar Flutter

```powershell
cd C:\xampp\htdocs\interfaz
git status --short
git fetch origin
git switch --track origin/teacher-account-password-secure-v1
C:\xampp\php\php.exe -l edusync/api/teacher_change_password.php
C:\xampp\php\php.exe -l tools/test_teacher_change_password_v1.php
C:\xampp\php\php.exe tools/test_teacher_change_password_v1.php
```

Si la rama ya existe localmente, `git switch teacher-account-password-secure-v1`.
No descartar los archivos locales de conexión a MySQL.

Probar sólo con una cuenta docente de prueba, con un colegio de prueba.
Validar: sesión inválida, cuenta de otro colegio, contraseñas incorrectas,
contraseña demasiado corta, falta de números, confirmación diferente,
éxito y reingreso con el secreto nuevo. Asegurar que no se almacenan ni
exponen contraseñas en logs o capturas.

No hay migración SQL nueva. La integración de backend y app aún necesita
prueba real en XAMPP/Android. No se ha pasado a main ni a producción.
