param(
    [string]$ProjectRoot = "",
    [string]$PhpPath = "C:\xampp\php\php.exe",
    [string]$CredentialsPath = "C:\xampp\firebase\edusync-service-account.json",
    [int]$SchoolId = 0
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
}

$CronPath = Join-Path $ProjectRoot "edusync\cron\student_debt_notifications.php"
$LogDirectory = Join-Path $ProjectRoot "edusync\tmp"
$LogPath = Join-Path $LogDirectory "student_debt_notifications.log"

if (-not (Test-Path $PhpPath)) {
    throw "No se encontró PHP en: $PhpPath"
}
if (-not (Test-Path $CronPath)) {
    throw "No se encontró el cron en: $CronPath"
}
if (-not (Test-Path $CredentialsPath)) {
    throw "No se encontró la credencial Firebase en: $CredentialsPath"
}
if (-not (Test-Path $LogDirectory)) {
    New-Item -ItemType Directory -Path $LogDirectory -Force | Out-Null
}

$arguments = @(
    $CronPath,
    "--credentials=$CredentialsPath"
)
if ($SchoolId -gt 0) {
    $arguments += "--school-id=$SchoolId"
}

$started = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
Add-Content -Path $LogPath -Value "[$started] Inicio cron de deudas"

& $PhpPath @arguments 2>&1 | Tee-Object -FilePath $LogPath -Append
$exitCode = $LASTEXITCODE

$finished = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
Add-Content -Path $LogPath -Value "[$finished] Fin cron de deudas. Código: $exitCode"

exit $exitCode
