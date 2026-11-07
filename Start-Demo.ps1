param(
    [string]$XamppPath = 'C:\xampp',
    [int]$DatabasePort = 3307,
    [int]$WebPort = 8088
)
$ErrorActionPreference = 'Stop'
$projectPath = $PSScriptRoot
$runtimePath = Join-Path $projectPath 'var'
$databasePath = Join-Path $runtimePath 'mysql'
$phpPath = Join-Path $XamppPath 'php\php.exe'
$mysqlPath = Join-Path $XamppPath 'mysql\bin\mysql.exe'
$daemonPath = Join-Path $XamppPath 'mysql\bin\mysqld.exe'
$installerPath = Join-Path $XamppPath 'mysql\bin\mysql_install_db.exe'
$adminPath = Join-Path $XamppPath 'mysql\bin\mysqladmin.exe'
foreach ($binaryPath in @($phpPath,$mysqlPath,$daemonPath,$installerPath,$adminPath)) {
    if (-not (Test-Path -LiteralPath $binaryPath)) { throw "XAMPP binary not found: $binaryPath" }
}
foreach ($portNumber in @($WebPort)) {
    if (Get-NetTCPConnection -LocalPort $portNumber -State Listen -ErrorAction SilentlyContinue) {
        throw "Port $portNumber is already in use. Close your previous demo or choose another port."
    }
}
New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null
if (-not (Test-Path -LiteralPath (Join-Path $databasePath 'my.ini'))) {
    & $installerPath "--datadir=$databasePath" "--port=$DatabasePort"
    if ($LASTEXITCODE -ne 0) { throw 'Could not initialize the isolated database.' }
}
$configurationPath = Join-Path $databasePath 'my.ini'
$databaseProcess = $null
$savedEnvironment = @{}
foreach ($variableName in @('DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASSWORD')) {
    $savedEnvironment[$variableName] = [Environment]::GetEnvironmentVariable($variableName,'Process')
}
try {
    $existingDatabase = Get-NetTCPConnection -LocalPort $DatabasePort -State Listen -ErrorAction SilentlyContinue
    if ($existingDatabase) {
        $runningDataPath = & $mysqlPath --host=127.0.0.1 "--port=$DatabasePort" --user=root --batch --skip-column-names --execute='SELECT @@datadir'
        if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect the database already using the demo port.' }
        $expectedDataPath = [IO.Path]::GetFullPath($databasePath).TrimEnd([char[]]'\/')
        $actualDataPath = [IO.Path]::GetFullPath([string]$runningDataPath).TrimEnd([char[]]'\/')
        if ($expectedDataPath -ne $actualDataPath) { throw 'The database port belongs to a different installation. Choose another port or use that installation explicitly.' }
        Write-Host 'Reusing the existing workspace database.'
    } else {
    $databaseProcess = Start-Process -FilePath $daemonPath -ArgumentList @("--defaults-file=`"$configurationPath`"", "--port=$DatabasePort", '--bind-address=127.0.0.1', '--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimePath 'demo-db-output.log') -RedirectStandardError (Join-Path $runtimePath 'demo-db-error.log')
    }
    $ready = $false
    for ($attempt=0; $attempt -lt 20; $attempt++) {
        & $adminPath --host=127.0.0.1 "--port=$DatabasePort" --user=root --connect-timeout=1 ping 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        if ($databaseProcess -and $databaseProcess.HasExited) { break }
        Start-Sleep -Milliseconds 250
    }
    if (-not $ready) { throw 'Database did not start. Read var/demo-db-error.log.' }
    $schemaExists = & $mysqlPath --host=127.0.0.1 "--port=$DatabasePort" --user=root --batch --skip-column-names --execute="SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='bella_vista_spa'"
    if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect the isolated database.' }
    if ($schemaExists -eq '0') {
        $sqlPath = (Join-Path $projectPath 'database\schema.sql').Replace('\','/')
        & $mysqlPath --host=127.0.0.1 "--port=$DatabasePort" --user=root --default-character-set=utf8mb4 "--execute=source $sqlPath"
        if ($LASTEXITCODE -ne 0) { throw 'Schema import failed. Read the database output before continuing.' }
    }
    $env:DB_HOST='127.0.0.1'; $env:DB_PORT=[string]$DatabasePort
    $env:DB_NAME='bella_vista_spa'; $env:DB_USER='root'; $env:DB_PASSWORD=''
    & $phpPath (Join-Path $projectPath 'tools\check-installation.php') "--target-port=$DatabasePort"
    if ($LASTEXITCODE -ne 0) { throw 'Database configuration does not match the selected demo database.' }
    & $phpPath (Join-Path $projectPath 'tools\migrate.php')
    if ($LASTEXITCODE -ne 0) { throw 'Database migration failed. Review the output before starting the site.' }
    & $phpPath (Join-Path $projectPath 'tools\check-installation.php')
    if ($LASTEXITCODE -ne 0) { throw 'Database schema is incomplete. Review the installation report.' }
    Write-Host "Bella Vista: http://127.0.0.1:$WebPort/"
    Write-Host 'Keep this terminal open. Ctrl+C stops the demonstration.'
    & $phpPath -d "session.save_path=$runtimePath" -S "127.0.0.1:$WebPort" -t (Join-Path $projectPath 'public') (Join-Path $projectPath 'router.php')
} finally {
    if ($databaseProcess -and -not $databaseProcess.HasExited) {
        & $adminPath --host=127.0.0.1 "--port=$DatabasePort" --user=root --connect-timeout=2 shutdown 2>$null | Out-Null
    }
    foreach ($variableName in $savedEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($variableName,$savedEnvironment[$variableName],'Process')
    }
}
