# EduSync web-push-fcm-v1: validación local sin Internet, BD ni Firebase.
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$phpExe = 'C:\xampp\php\php.exe'
if (-not (Test-Path $phpExe)) {
    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $command) {
        throw 'No se encontró PHP. Revisa C:\xampp\php\php.exe o agrega PHP a PATH.'
    }
    $phpExe = $command.Source
}
$phpFiles = @(
    'edusync/api/web_push.php',
    'edusync/includes/web_push_fcm_sender.php',
    'edusync/cron/web_push_dispatch.php',
    'edusync/includes/topbar.php',
    'edusync/index.php',
    'edusync/logout.php',
    'edusync/notifications_api.php',
    'edusync/pages/notifications.php',
    'edusync/pages/student_notifications_web.php'
)
$errorsFound = 0
foreach ($relative in $phpFiles) {
    $file = Join-Path $root $relative
    if (-not (Test-Path $file)) {
        Write-Host "[FALTA] $relative" -ForegroundColor Red
        $errorsFound++
        continue
    }
    & $phpExe -l $file
    if ($LASTEXITCODE -ne 0) { $errorsFound++ }
}
$node = Get-Command node -ErrorAction SilentlyContinue
if ($null -ne $node) {
    $jsFiles = @(
        'edusync/js/web_push_fcm.js',
        'edusync/js/student_web_notifications.js',
        'edusync/firebase-messaging-sw.js'
    )
    foreach ($relative in $jsFiles) {
        & $node.Source --check (Join-Path $root $relative)
        if ($LASTEXITCODE -ne 0) { $errorsFound++ }
        else { Write-Host "[OK JS] $relative" -ForegroundColor Green }
    }
} else {
    Write-Host 'Node.js no está disponible: se omite validación JS (opcional).' -ForegroundColor Yellow
}
$required = @(
    'edusync/manifest.webmanifest',
    'sql/web_push_fcm_v1.sql'
)
foreach ($relative in $required) {
    if (-not (Test-Path (Join-Path $root $relative))) {
        Write-Host "[FALTA] $relative" -ForegroundColor Red
        $errorsFound++
    }
}
if ($errorsFound -gt 0) {
    throw "$errorsFound comprobaciones fallaron. No subir a producción."
}
Write-Host 'Comprobaciones estáticas correctas. Faltan las pruebas reales de Firebase y la BD.' -ForegroundColor Green
