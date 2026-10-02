param(
    [string]$ProjectRoot = "",
    [string]$PhpPath = "C:\xampp\php\php.exe",
    [string]$CredentialsPath = "C:\xampp\firebase\edusync-service-account.json",
    [string]$TaskName = "EduSync - Notificaciones de deudas",
    [string]$DailyTime = "08:00",
    [int]$SchoolId = 0
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
}

$RunnerPath = Join-Path $ProjectRoot "tools\run_student_debt_notifications.ps1"
if (-not (Test-Path $RunnerPath)) {
    throw "No se encontró el ejecutor: $RunnerPath"
}
if (-not (Test-Path $PhpPath)) {
    throw "No se encontró PHP: $PhpPath"
}
if (-not (Test-Path $CredentialsPath)) {
    throw "No se encontró la credencial Firebase: $CredentialsPath"
}

try {
    $time = [DateTime]::ParseExact($DailyTime, "HH:mm", $null)
} catch {
    throw "DailyTime debe usar formato HH:mm, por ejemplo 08:00."
}

$PowerShellExe = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$RunnerPath`" -ProjectRoot `"$ProjectRoot`" -PhpPath `"$PhpPath`" -CredentialsPath `"$CredentialsPath`" -SchoolId $SchoolId"

$action = New-ScheduledTaskAction -Execute $PowerShellExe -Argument $arguments
$trigger = New-ScheduledTaskTrigger -Daily -At $time
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description "Evalúa deudas EduSync y envía recordatorios push de vencimiento." -Force | Out-Null

Write-Host "Tarea creada: $TaskName"
Write-Host "Hora diaria: $DailyTime"
Write-Host "Para probar ahora:"
Write-Host "Start-ScheduledTask -TaskName `"$TaskName`""
