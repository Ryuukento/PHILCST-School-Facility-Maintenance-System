$mysqldPath = 'C:\xampp\mysql\bin\mysqld.exe'
$myIni = 'C:\xampp\mysql\bin\my.ini'
$pidFile = 'C:\xampp\mysql\data\mysqld_skipgrant.pid'
$logFile = 'C:\xampp\mysql\data\repair_mysql_tables.log'
if (Get-Process -Name mysqld -ErrorAction SilentlyContinue) {
    Get-Process -Name mysqld | Stop-Process -Force
    Start-Sleep -Seconds 2
}
$proc = Start-Process -FilePath $mysqldPath -ArgumentList "--defaults-file=$myIni","--skip-grant-tables" -WindowStyle Hidden -PassThru
$proc.Id | Out-File -FilePath $pidFile -Encoding ascii
Start-Sleep -Seconds 8
"Started mysqld PID=$($proc.Id)" | Out-File -FilePath $logFile -Encoding ascii
$client = 'C:\xampp\mysql\bin\mysql.exe'
$sql = @"
REPAIR TABLE mysql.plugin;
REPAIR TABLE mysql.columns_priv;
SELECT COUNT(*) AS plugin_count FROM mysql.plugin;
SELECT COUNT(*) AS columns_priv_count FROM mysql.columns_priv;
FLUSH PRIVILEGES;
"@
$sqlFile = 'C:\xampp\mysql\data\repair_commands.sql'
$sql | Out-File -FilePath $sqlFile -Encoding ascii
try {
    & $client -u root --protocol=TCP --execute="source $sqlFile" 2>&1 | Out-File -FilePath $logFile -Append -Encoding ascii
} catch {
    $_ | Out-File -FilePath $logFile -Append -Encoding ascii
}
"Repair complete" | Out-File -FilePath $logFile -Append -Encoding ascii
Get-Content -Path $logFile -Tail 60 | Write-Output
