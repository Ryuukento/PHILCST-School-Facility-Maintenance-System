param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path,
    [switch]$Execute,
    [switch]$Revert,
    [string]$ManifestPath = ""
)

$ErrorActionPreference = "Stop"

function Write-Section([string]$Title) {
    Write-Host ""
    Write-Host "==== $Title ===="
}

function Get-DefaultManifestPath([string]$Root) {
    return Join-Path $Root "laravel_app\scripts\.safe-cleanup-manifest.json"
}

$root = (Resolve-Path $ProjectRoot).Path
$defaultManifest = Get-DefaultManifestPath -Root $root
if ([string]::IsNullOrWhiteSpace($ManifestPath)) {
    $ManifestPath = $defaultManifest
}

$laravelPath = Join-Path $root "laravel_app"
$laravelPublicPath = Join-Path $laravelPath "public"

if (!(Test-Path $laravelPath)) {
    throw "laravel_app folder not found at: $laravelPath"
}

if (!(Test-Path $laravelPublicPath)) {
    throw "laravel_app/public folder not found at: $laravelPublicPath"
}

if ($Revert) {
    Write-Section "SAFE CLEANUP REVERT"

    if (!(Test-Path $ManifestPath)) {
        throw "Manifest file not found: $ManifestPath"
    }

    $manifest = Get-Content -Path $ManifestPath -Raw | ConvertFrom-Json
    $archiveFolder = [string]$manifest.archiveFolder

    if ([string]::IsNullOrWhiteSpace($archiveFolder) -or !(Test-Path $archiveFolder)) {
        throw "Archive folder in manifest does not exist: $archiveFolder"
    }

    foreach ($entry in $manifest.items) {
        $from = [string]$entry.archivedPath
        $to = [string]$entry.originalPath

        if (!(Test-Path $from)) {
            Write-Host "[SKIP] Missing archived item: $from"
            continue
        }

        if (Test-Path $to) {
            Write-Host "[SKIP] Original path already exists: $to"
            continue
        }

        Write-Host "[RESTORE] $from -> $to"
        Move-Item -Path $from -Destination $to
    }

    Write-Host ""
    Write-Host "Revert completed."
    exit 0
}

Write-Section "SAFE CLEANUP PLAN"

$keepNames = @(
    ".git",
    ".vscode",
    "laravel_app",
    "laravel_app.zip"
)

$rootItems = Get-ChildItem -Path $root -Force

$candidates = @()
foreach ($item in $rootItems) {
    if ($keepNames -contains $item.Name) {
        continue
    }

    if ($item.Name -like "_legacy_archive_*") {
        continue
    }

    $candidates += $item
}

if ($candidates.Count -eq 0) {
    Write-Host "No cleanup candidates found."
    exit 0
}

Write-Host "Project root: $root"
$modeLabel = "DRY-RUN"
if ($Execute) {
    $modeLabel = "EXECUTE"
}
Write-Host "Mode: $modeLabel"
Write-Host "Candidates to archive: $($candidates.Count)"
Write-Host ""

foreach ($c in $candidates) {
    $kind = if ($c.PSIsContainer) { "DIR " } else { "FILE" }
    Write-Host "[$kind] $($c.Name)"
}

if (!$Execute) {
    Write-Host ""
    Write-Host "Dry-run only. No files were moved."
    Write-Host "Run with -Execute to apply cleanup."
    Write-Host ""
    Write-Host "Example:"
    Write-Host "  powershell -ExecutionPolicy Bypass -File .\laravel_app\scripts\safe_cleanup_to_laravel.ps1 -Execute"
    exit 0
}

Write-Section "APPLY SAFE CLEANUP"

$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$archiveFolder = Join-Path $root "_legacy_archive_$stamp"
New-Item -Path $archiveFolder -ItemType Directory -Force | Out-Null

$manifestItems = @()

foreach ($item in $candidates) {
    $from = $item.FullName
    $to = Join-Path $archiveFolder $item.Name

    Write-Host "[MOVE] $from -> $to"
    Move-Item -Path $from -Destination $to

    $manifestItems += [PSCustomObject]@{
        name = $item.Name
        originalPath = $from
        archivedPath = $to
        isDirectory = [bool]$item.PSIsContainer
    }
}

$manifest = [PSCustomObject]@{
    createdAt = (Get-Date).ToString("o")
    projectRoot = $root
    archiveFolder = $archiveFolder
    items = $manifestItems
}

$manifestDir = Split-Path -Parent $ManifestPath
if (!(Test-Path $manifestDir)) {
    New-Item -Path $manifestDir -ItemType Directory -Force | Out-Null
}

$manifest | ConvertTo-Json -Depth 8 | Set-Content -Path $ManifestPath

Write-Host ""
Write-Host "Safe cleanup completed."
Write-Host "Archive folder: $archiveFolder"
Write-Host "Manifest: $ManifestPath"
Write-Host ""
Write-Host "To restore later:"
Write-Host "  powershell -ExecutionPolicy Bypass -File .\laravel_app\scripts\safe_cleanup_to_laravel.ps1 -Revert"
