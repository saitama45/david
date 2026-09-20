param([ValidateSet('Start','Run','Stop','Status')][string]$Mode = 'Start')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$artisanPath = Join-Path $projectRoot 'artisan'
$runtimeDir = Join-Path $projectRoot 'storage/framework'
$statePath = Join-Path $runtimeDir 'pos-local-runner.json'
$stopPath = Join-Path $runtimeDir 'pos-local-runner.stop'
$lockPath = Join-Path $runtimeDir 'pos-local-runner.lock'
$logsDir = Join-Path $projectRoot 'storage/logs'
$phpPath = (Get-Command php -ErrorAction Stop).Source

function Read-RunnerState {
    if (Test-Path -LiteralPath $statePath) {
        try { return Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json } catch { return $null }
    }
    return $null
}
function Test-Supervisor($state) {
    if (!$state) { return $false }
    $process = Get-CimInstance Win32_Process -Filter "ProcessId=$($state.supervisor)" -ErrorAction SilentlyContinue
    return $process -and $process.CommandLine -like "*$PSCommandPath*" -and $process.CommandLine -like '*-Mode Run*'
}

$existing = Read-RunnerState
if ($Mode -eq 'Status') {
    if (Test-Supervisor $existing) { $existing | ConvertTo-Json; Write-Output "Stop requested: $(Test-Path -LiteralPath $stopPath)" }
    else { Write-Output 'POS local runner is stopped.' }
    exit 0
}
if ($Mode -eq 'Stop') {
    if (Test-Supervisor $existing) {
        Set-Content -LiteralPath $stopPath -Value 'stop'
        Write-Output 'Stop requested. The current POS batch will finish before the worker exits.'
    } else { Write-Output 'POS local runner is already stopped.' }
    exit 0
}
if ($Mode -eq 'Start') {
    if (Test-Supervisor $existing) { Write-Output 'POS local runner is already running.'; exit 0 }
    & $phpPath $artisanPath pos:doctor
    if ($LASTEXITCODE -ne 0) { throw 'POS readiness checks failed. Runner not started.' }
    $args = @('-NoProfile','-ExecutionPolicy','Bypass','-File', ('"' + $PSCommandPath + '"'),'-Mode','Run')
    Start-Process powershell.exe -ArgumentList $args -WorkingDirectory $projectRoot -WindowStyle Hidden | Out-Null
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        Start-Sleep -Milliseconds 250
        if (Test-Supervisor (Read-RunnerState)) { Write-Output 'POS local runner started. Logs are in storage/logs/pos-local-*.log.'; exit 0 }
    }
    throw 'Runner did not become ready. Check storage/logs.'
}

# The OS file lock prevents duplicate supervisors, including simultaneous starts.
try { $lockStream = [System.IO.File]::Open($lockPath, 'OpenOrCreate', 'ReadWrite', 'None') }
catch { exit 0 }
$scanner = $null
$worker = $null
try {
    if (Test-Path -LiteralPath $stopPath) { Remove-Item -LiteralPath $stopPath }
    while ($true) {
        $stopping = Test-Path -LiteralPath $stopPath
        if ($stopping) {
            if ($scanner -and !$scanner.HasExited) { Stop-Process -Id $scanner.Id -ErrorAction SilentlyContinue }
            if (!$worker -or $worker.HasExited) { break }
        } else {
            if (!$scanner -or $scanner.HasExited) {
                $scanner = Start-Process $phpPath -ArgumentList @(('"' + $artisanPath + '"'),'pos:scan-loop') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logsDir 'pos-local-scanner.log') -RedirectStandardError (Join-Path $logsDir 'pos-local-scanner-error.log')
            }
            if (!$worker -or $worker.HasExited) {
                $worker = Start-Process $phpPath -ArgumentList @(('"' + $artisanPath + '"'),'queue:work','database','--queue=pos-sales','--sleep=1','--tries=1','--timeout=3600','--max-jobs=1','--max-time=60','--stop-when-empty') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logsDir 'pos-local-worker.log') -RedirectStandardError (Join-Path $logsDir 'pos-local-worker-error.log')
            }
        }
        @{ supervisor=$PID; scanner=if($scanner){$scanner.Id}else{$null}; worker=if($worker){$worker.Id}else{$null}; updated=(Get-Date).ToString('o'); project=$projectRoot } | ConvertTo-Json | Set-Content -LiteralPath $statePath
        Start-Sleep -Seconds 2
    }
} finally {
    if ($scanner -and !$scanner.HasExited) { Stop-Process -Id $scanner.Id -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $statePath) { Remove-Item -LiteralPath $statePath }
    $lockStream.Dispose()
}
