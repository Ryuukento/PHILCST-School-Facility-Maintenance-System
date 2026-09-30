param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path,
    [switch]$Clean
)

$ErrorActionPreference = "Stop"

$sourceFrontend = Join-Path $ProjectRoot "frontend"
$sourceBackend = Join-Path $ProjectRoot "backend"
$targetPublic = Join-Path $ProjectRoot "laravel_app\public"
$targetFrontend = Join-Path $targetPublic "frontend"
$targetBackend = Join-Path $targetPublic "backend"

if (!(Test-Path $sourceFrontend)) { throw "Source frontend folder not found: $sourceFrontend" }
if (!(Test-Path $sourceBackend)) { throw "Source backend folder not found: $sourceBackend" }
if (!(Test-Path $targetPublic)) { throw "Laravel public folder not found: $targetPublic" }

if ($Clean) {
    if (Test-Path $targetFrontend) { Remove-Item -Recurse -Force $targetFrontend }
    if (Test-Path $targetBackend) { Remove-Item -Recurse -Force $targetBackend }
}

New-Item -ItemType Directory -Path $targetFrontend -Force | Out-Null
New-Item -ItemType Directory -Path $targetBackend -Force | Out-Null

Copy-Item -Path (Join-Path $sourceFrontend "*") -Destination $targetFrontend -Recurse -Force
Copy-Item -Path (Join-Path $sourceBackend "*") -Destination $targetBackend -Recurse -Force

Write-Host "Synced legacy frontend/backend into laravel_app/public successfully."
Write-Host "- Frontend: $targetFrontend"
Write-Host "- Backend : $targetBackend"
