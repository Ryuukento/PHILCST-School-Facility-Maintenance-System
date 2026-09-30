Set-Location 'C:\xampp\mysql\data\mysql'
$aria = 'C:\xampp\mysql\bin\aria_chk.exe'
$files = @('plugin.MAI', 'plugin.MAD', 'columns_priv.MAI', 'columns_priv.MAD')
foreach ($f in $files) {
    if (Test-Path $f) {
        Write-Output "--- Checking $f ---"
        & $aria --check --safe-recover --force $f
    } else {
        Write-Output "Missing file: $f"
    }
}
Write-Output 'Check complete.'