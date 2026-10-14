# EduSync: validación de Web Push inmediato + cron (sin tocar datos reales).
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$phpExe = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $phpExe)) {
    $cmd=Get-Command php -ErrorAction SilentlyContinue
    if (-not $cmd) { throw 'No se encontró PHP de XAMPP.' }
    $phpExe=$cmd.Source
}
$paths=@(
    'edusync/includes/web_push_dispatcher.php',
    'edusync/includes/web_push_fcm_sender.php',
    'edusync/includes/push_notifications.php',
    'edusync/cron/web_push_dispatch.php',
    'edusync/collection_notifications_api.php',
    'edusync/grade_closure_api.php',
    'edusync/admin_grade_closure_api.php',
    'edusync/admin_class.php',
    'edusync/attendance_api.php',
    'tools/test_web_push_unified_v1.php'
)
$errorsFound=0
foreach ($path in $paths) {
    $file=Join-Path $root $path
    if (-not (Test-Path -LiteralPath $file)) {
        Write-Host "[FALTA] $path" -ForegroundColor Red
        $errorsFound++
        continue
    }
    & $phpExe -l $file
    if ($LASTEXITCODE -ne 0) { $errorsFound++ }
}
if ($errorsFound -eq 0) {
    & $phpExe (Join-Path $root 'tools/test_web_push_unified_v1.php')
    if ($LASTEXITCODE -ne 0) { $errorsFound++ }
}
if ($errorsFound -gt 0) { throw "Se encontraron $errorsFound errores. No desplegar." }
Write-Host '[OK] Sintaxis e integración estática correctas. Falta comprobar Firebase y la BD local.' -ForegroundColor Green
