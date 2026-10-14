# EduSync: validar notificaciones sin conectar a BD ni enviar Push.
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$phpExe='C:\xampp\php\php.exe'
if(-not(Test-Path -LiteralPath $phpExe)){
  $cmd=Get-Command php -ErrorAction SilentlyContinue
  if($null -eq $cmd){throw 'No se encontró PHP. Revisa C:\xampp\php\php.exe.'}
  $phpExe=$cmd.Source
}
$files=@(
  'edusync/includes/academic_alert_policy.php',
  'edusync/admin_class.php',
  'edusync/notifications_api.php',
  'edusync/cron/web_push_dispatch.php',
  'edusync/includes/topbar.php',
  'edusync/pages/notifications.php',
  'edusync/pages/student_notifications_web.php',
  'tools/test_notifications_intelligent_v1.php'
)
$failed=0
foreach($relative in $files){
  $full=Join-Path $root $relative
  if(-not(Test-Path -LiteralPath $full)){
    Write-Host "[FALTA] $relative" -ForegroundColor Red
    $failed++
    continue
  }
  & $phpExe -l $full
  if($LASTEXITCODE -ne 0){$failed++}
}
if($failed -eq 0){
  & $phpExe (Join-Path $root 'tools/test_notifications_intelligent_v1.php')
  if($LASTEXITCODE -ne 0){$failed++}
}
if($failed -gt 0){throw "$failed pruebas fallaron. No subir a producción."}
Write-Host '[OK] Sintaxis y política de alertas correctas. Falta prueba real en XAMPP.' -ForegroundColor Green
