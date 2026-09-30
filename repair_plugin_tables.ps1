$dataDir = 'C:\xampp\mysql\data'
Set-Location $dataDir
$aria = 'C:\xampp\mysql\bin\aria_chk.exe'
$files = @('mysql\plugin.MAI', 'mysql\columns_priv.MAI')
foreach ($f in $files) {
    if (Test-Path $f) {
        Write-Output "--- Recovering $f ---"
        & $aria --datadir=$dataDir --safe-recover --force $f
    } else {
        Write-Output "Missing file: $f"
    }
}
Write-Output 'Plugin table repair complete.'