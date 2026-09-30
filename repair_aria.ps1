Set-Location 'C:\xampp\mysql\data'
$backup = Join-Path $PWD.Path ('aria_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
New-Item -ItemType Directory -Force -Path $backup | Out-Null
Get-ChildItem -Filter 'aria_log*' -File | ForEach-Object { Copy-Item -Path $_.FullName -Destination $backup -Force }
Write-Output "Backed up aria logs to $backup"
$aria = 'C:\xampp\mysql\bin\aria_chk.exe'
Get-ChildItem -Path 'mysql' -Filter '*.MAI' -File | ForEach-Object {
    Write-Output "Repairing $($_.FullName)"
    & $aria --safe-recover --force $_.FullName
}
Get-ChildItem -Filter 'aria_log*' -File | Remove-Item -Force
Write-Output 'Removed aria_log files'
Write-Output "Repair complete; backup available at $backup"