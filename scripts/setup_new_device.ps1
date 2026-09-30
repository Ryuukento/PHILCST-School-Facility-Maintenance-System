param(
    [string]$LaravelRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path,
    [string]$DbName = "school_facility_maintenance",
    [string]$DbUser = "root",
    [string]$DbPassword = "",
    [string]$DbHost = "127.0.0.1",
    [int]$DbPort = 3306,
    [string]$SqlFile = "",
    [switch]$SkipComposer,
    [switch]$SkipNpm,
    [switch]$SkipDbImport
)

$ErrorActionPreference = "Stop"

function Write-Section([string]$Title) {
    Write-Host ""
    Write-Host "==== $Title ===="
}

function Ensure-Command([string]$Name) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Required command not found: $Name"
    }
}

function Get-LatestImportReadyBackup([string]$WorkspaceRoot) {
    $backupDir = Join-Path $WorkspaceRoot "db_backups"
    if (-not (Test-Path $backupDir)) {
        return $null
    }

    $candidates = Get-ChildItem -Path $backupDir -File -Filter "*IMPORT_READY*.sql" | Sort-Object LastWriteTime -Descending
    if ($candidates.Count -gt 0) {
        return $candidates[0].FullName
    }

    $fallback = Get-ChildItem -Path $backupDir -File -Filter "*.sql" | Sort-Object LastWriteTime -Descending
    if ($fallback.Count -gt 0) {
        return $fallback[0].FullName
    }

    return $null
}

$LaravelRoot = (Resolve-Path $LaravelRoot).Path
$WorkspaceRoot = (Resolve-Path (Join-Path $LaravelRoot "..")).Path
$envPath = Join-Path $LaravelRoot ".env"
$envExamplePath = Join-Path $LaravelRoot ".env.example"

Write-Section "PROJECT PATHS"
Write-Host "Laravel root : $LaravelRoot"
Write-Host "Workspace root: $WorkspaceRoot"

if (-not (Test-Path (Join-Path $LaravelRoot "artisan"))) {
    throw "artisan not found. Check -LaravelRoot path."
}

Push-Location $LaravelRoot
try {
    Write-Section "PRECHECKS"
    Ensure-Command "php"
    if (-not $SkipComposer) { Ensure-Command "composer" }
    if (-not $SkipNpm) { Ensure-Command "npm" }

    if (-not (Test-Path $envPath)) {
        if (-not (Test-Path $envExamplePath)) {
            throw ".env and .env.example are both missing."
        }

        Copy-Item -Path $envExamplePath -Destination $envPath
        Write-Host "Created .env from .env.example"
    }

    Write-Section "DEPENDENCIES"
    if (-not $SkipComposer) {
        Write-Host "Running composer install..."
        composer install
    } else {
        Write-Host "Skipped composer install"
    }

    if (-not $SkipNpm) {
        Write-Host "Running npm install..."
        npm install
    } else {
        Write-Host "Skipped npm install"
    }

    Write-Section "APP KEY"
    php artisan key:generate --force

    Write-Section "ENV REFRESH"
    php artisan config:clear
    php artisan cache:clear
    php artisan route:clear
    php artisan view:clear

    if (-not $SkipDbImport) {
        Write-Section "DATABASE IMPORT"
        Ensure-Command "mysql"

        if ([string]::IsNullOrWhiteSpace($SqlFile)) {
            $SqlFile = Get-LatestImportReadyBackup -WorkspaceRoot $WorkspaceRoot
        }

        if ([string]::IsNullOrWhiteSpace($SqlFile) -or -not (Test-Path $SqlFile)) {
            throw "No SQL backup file found. Place a backup in db_backups or pass -SqlFile explicitly."
        }

        $SqlFile = (Resolve-Path $SqlFile).Path
        Write-Host "Using SQL file: $SqlFile"

        $passwordArg = ""
        if (-not [string]::IsNullOrEmpty($DbPassword)) {
            $passwordArg = "-p$DbPassword"
        }

        $createDbSql = "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        $createCmd = "mysql --host=$DbHost --port=$DbPort --user=$DbUser $passwordArg --default-character-set=utf8mb4 -e `"$createDbSql`""
        Write-Host "Creating database if not exists..."
        Invoke-Expression $createCmd

        $importCmd = "mysql --host=$DbHost --port=$DbPort --user=$DbUser $passwordArg --default-character-set=utf8mb4 $DbName < `"$SqlFile`""
        Write-Host "Importing SQL backup..."
        Invoke-Expression $importCmd

        Write-Host "Database import completed."
    } else {
        Write-Host "Skipped database import"
    }

    Write-Section "DONE"
    Write-Host "Setup complete."
    Write-Host ""
    Write-Host "Next commands (run in separate terminals):"
    Write-Host "1) php artisan serve"
    Write-Host "2) npm run dev"
    Write-Host ""
    Write-Host "Open: http://127.0.0.1:8000"
}
finally {
    Pop-Location
}
