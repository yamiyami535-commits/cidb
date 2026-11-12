param(
    [string]$PhpExe = $env:CIDB_PHP_EXE
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$cronScript = Join-Path $projectRoot 'bin\cron_runner.php'
$envFile = Join-Path $projectRoot '.env'
$logDirectory = Join-Path $projectRoot 'var\cron-logs'
$logFile = Join-Path $logDirectory ("cron-sequence-{0}.log" -f (Get-Date -Format 'yyyy-MM-dd'))
$mutex = $null
$mutexHeld = $false
$result = 1

try {
    if (-not (Test-Path -LiteralPath $envFile -PathType Leaf)) {
        throw "Application .env file was not found at $envFile"
    }
    if (-not (Test-Path -LiteralPath $cronScript -PathType Leaf)) {
        throw "Cron entry point was not found at $cronScript"
    }
    if ([string]::IsNullOrWhiteSpace($PhpExe)) {
        $phpCommand = Get-Command 'php.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($null -eq $phpCommand) {
            throw 'PHP was not found. Set CIDB_PHP_EXE to the full path of php.exe.'
        }
        $PhpExe = $phpCommand.Source
    }
    if (-not (Test-Path -LiteralPath $PhpExe -PathType Leaf)) {
        throw "PHP executable was not found at $PhpExe"
    }

    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
    $mutex = [System.Threading.Mutex]::new($false, 'Local\CIDB_Cron_Sequence')
    if (-not $mutex.WaitOne(0)) {
        Add-Content -LiteralPath $logFile -Value ("[{0}] Another Cron1 -> Cron2 sequence is already running; skipping." -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))
        $result = 0
    }
    else {
        $mutexHeld = $true
        Set-Location -LiteralPath $projectRoot
        Add-Content -LiteralPath $logFile -Value ("[{0}] Starting Cron1 -> Cron2; PHP: {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $PhpExe)

        foreach ($step in @(
            @{ Name = 'Cron1'; Arguments = @('bin/cron_runner.php') },
            @{ Name = 'Cron2'; Arguments = @('bin/cron_runner.php', '--cron2') }
        )) {
            Add-Content -LiteralPath $logFile -Value ("[{0}] Starting {1}." -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $step.Name)
            $arguments = $step.Arguments
            & $PhpExe @arguments 2>&1 | Tee-Object -FilePath $logFile -Append
            $stepExitCode = $LASTEXITCODE
            Add-Content -LiteralPath $logFile -Value ("[{0}] {1} finished with exit code {2}." -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $step.Name, $stepExitCode)
            if ($stepExitCode -ne 0) {
                throw "$($step.Name) failed with exit code $stepExitCode; Cron2 will not run after a Cron1 failure."
            }
        }

        $result = 0
        Add-Content -LiteralPath $logFile -Value ("[{0}] Cron1 -> Cron2 sequence completed successfully." -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))
    }
}
catch {
    try {
        New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
        Add-Content -LiteralPath $logFile -Value ("[{0}] ERROR: {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $_.Exception.Message)
    }
    catch {
        Write-Error $_
    }
    $result = 1
}
finally {
    if ($mutexHeld -and $null -ne $mutex) {
        $mutex.ReleaseMutex()
    }
    if ($null -ne $mutex) {
        $mutex.Dispose()
    }
}

exit $result
