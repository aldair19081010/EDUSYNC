# EduSync: chequeo estático de recordar sesión (no toca MySQL ni producción).
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$phpExe='C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $phpExe)) {
    $cmd=Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $cmd) {throw 'PHP no disponible.'}
    $phpExe=$cmd.Source
}
$files=@(
 'edusync/includes/remember_session.php',
 'edusync/auth_login.php',
 'edusync/api/login.php',
 'edusync/login.php',
 'edusync/index.php',
 'edusync/ajax.php',
 'edusync/session_config.php',
 'edusync/includes/session_check.php',
 'edusync/logout.php',
 'tools/test_remember_session_30d_v1.php'
)
$errorsFound=0
foreach($relative in $files){
    $full=Join-Path $root $relative
    if(-not(Test-Path -LiteralPath $full)){
        Write-Host "[FALTA] $relative" -ForegroundColor Red
        $errorsFound++
        continue
    }
    & $phpExe -l $full
    if($LASTEXITCODE -ne 0){$errorsFound++}
}
$sql=Join-Path $root 'sql/remember_session_30d_v1.sql'
if(-not(Test-Path -LiteralPath $sql)){
    Write-Host '[FALTA] sql/remember_session_30d_v1.sql' -ForegroundColor Red
    $errorsFound++
}
if($errorsFound -eq 0){
    & $phpExe (Join-Path $root 'tools/test_remember_session_30d_v1.php')
    if($LASTEXITCODE -ne 0){$errorsFound++}
}
if($errorsFound -gt 0){throw "$errorsFound comprobaciones fallaron. No desplegar."}
Write-Host '[OK] Recordar sesión: pruebas estáticas correctas. Falta SQL manual y pruebas locales reales.' -ForegroundColor Green
