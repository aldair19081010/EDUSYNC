# EduSync FCM Web Push - configuración PÚBLICA para XAMPP, fuera de htdocs.
# No carga ni imprime credenciales privadas. No ejecuta SQL ni modifica Apache.
$ErrorActionPreference = 'Stop'

$android = 'C:\gestion_pagos_app\android\app\google-services.json'
$existing = $null
if (Test-Path -LiteralPath $android) {
    try { $existing = Get-Content -LiteralPath $android -Raw -Encoding UTF8 | ConvertFrom-Json }
    catch { Write-Warning 'No se pudo leer la configuración Android. Se pedirán todos los campos.' }
}
$projectId = [string]($existing.project_info.project_id)
$projectNumber = [string]($existing.project_info.project_number)

function Ask-Value {
    param(
        [string]$Title,
        [string]$Default = ''
    )
    if ($Default) { $value = Read-Host "$Title [$Default]" }
    else { $value = Read-Host $Title }
    if ([string]::IsNullOrWhiteSpace($value)) { $value = $Default }
    return $value.Trim()
}

Write-Host ''
Write-Host 'Configuración pública de Firebase Web para EduSync' -ForegroundColor Cyan
Write-Host 'Usa el MISMO proyecto Firebase que la app Android.'
Write-Host 'Los datos se encuentran en Firebase > Configuración del proyecto > Tus aplicaciones > Web.'
Write-Host 'La clave VAPID pública está en Firebase > Cloud Messaging > Certificados push web.'
Write-Host 'Nunca pegues aquí una cuenta de servicio JSON ni claves privadas.'
Write-Host ''

$projectId = Ask-Value 'Project ID' $projectId
$senderId = Ask-Value 'Messaging Sender ID / Project Number' $projectNumber
$authDomain = Ask-Value 'Auth domain' ($projectId + '.firebaseapp.com')
$apiKey = Ask-Value 'Web apiKey (Firebase config WEB)'
$appId = Ask-Value 'Web appId (ejemplo: 1:123:web:abc)'
$vapid = Ask-Value 'Clave VAPID PÚBLICA (Web Push certificates)'

if ($projectId -notmatch '^[a-zA-Z0-9-]{5,}$') { throw 'Project ID no válido.' }
if ($senderId -notmatch '^\d{5,20}$') { throw 'Messaging Sender ID no válido.' }
if ($authDomain -notmatch '^[a-zA-Z0-9.-]+$') { throw 'Auth domain no válido.' }
if ($apiKey -notmatch '^[A-Za-z0-9_-]{20,}$') { throw 'Web apiKey no válido.' }
if ($appId -notmatch '^1:\d+:web:.+') { throw 'Web appId debe ser de la aplicación WEB de Firebase.' }
if ($vapid -notmatch '^[A-Za-z0-9_-]{50,}$') { throw 'Clave VAPID pública no válida.' }

if ($existing -and $existing.project_info.project_id -and
    $projectId -ne [string]$existing.project_info.project_id) {
    throw 'El proyecto Web indicado no coincide con el proyecto Firebase Android EduSync.'
}

$targetFolder = 'C:\xampp\firebase'
New-Item -ItemType Directory -Path $targetFolder -Force | Out-Null
$target = Join-Path $targetFolder 'edusync-web-public.json'
$data = @{
    firebase = @{
        apiKey = $apiKey
        authDomain = $authDomain
        projectId = $projectId
        messagingSenderId = $senderId
        appId = $appId
    }
    vapid_key = $vapid
}
$data | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $target -Encoding UTF8
Write-Host ''
Write-Host 'Configuración PÚBLICA guardada fuera de htdocs:' -ForegroundColor Green
Write-Host $target
Write-Host 'No es necesario editar Apache para usar esos datos en XAMPP.'
Write-Host 'A continuación ejecuta las validaciones locales y luego prepara la BD de prueba.'
