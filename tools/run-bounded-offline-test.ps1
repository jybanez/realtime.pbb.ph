param(
    [Parameter(Mandatory=$true)][string]$PhpPath,
    [string]$Filter,
    [Parameter(Mandatory=$true)][string]$EvidenceDirectory,
    [ValidateSet('Test','Collector')][string]$Mode = 'Test',
    [string]$CollectorOutput,
    [ValidateRange(1,65535)][int]$CollectorPort = 9998,
    [ValidateRange(1,30)][int]$Seconds = 15
)
# Preparation only; no invocation by application code. Launch one offline targeted
# PHPUnit process under a .NET watchdog; Kill(true) includes its fixture descendants.
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if (!(Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'PHP executable missing' }
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'Use a new evidence directory' }
$taskEvidence = [IO.Path]::GetFullPath($EvidenceDirectory)
New-Item -ItemType Directory -Path $taskEvidence | Out-Null
$taskInfo = [Diagnostics.ProcessStartInfo]::new()
$taskInfo.FileName = $PhpPath
$taskInfo.WorkingDirectory = $taskRoot
$taskInfo.UseShellExecute = $false
$taskInfo.CreateNoWindow = $true
if ($Mode -eq 'Test') {
    if ([string]::IsNullOrWhiteSpace($Filter)) { throw 'Explicit targeted filter required' }
    $taskInfo.ArgumentList.Add((Join-Path $taskRoot 'vendor/phpunit/phpunit/phpunit'))
    $taskInfo.ArgumentList.Add('--filter')
    $taskInfo.ArgumentList.Add($Filter)
} else {
    if ([string]::IsNullOrWhiteSpace($CollectorOutput) -or $Seconds -lt 3) { throw 'New collector output and deadline at least3seconds required' }
    $taskInfo.ArgumentList.Add((Join-Path $taskRoot 'tools/collect-callback-diagnostics.php'))
    $taskInfo.ArgumentList.Add([IO.Path]::GetFullPath($CollectorOutput))
    $taskInfo.ArgumentList.Add([string]$CollectorPort)
    $taskInfo.ArgumentList.Add([string]($Seconds - 2))
}
# Capture at most8192 characters per stream. Excess output may encounter pipe
# backpressure; the independent process deadline still applies and is reported.
$taskInfo.RedirectStandardOutput = $true
$taskInfo.RedirectStandardError = $true
$taskProcess = [Diagnostics.Process]::new()
$taskProcess.StartInfo = $taskInfo
$taskStarted = [DateTime]::UtcNow
$taskProcess.Start() | Out-Null
$taskOutBuffer = [char[]]::new(8192)
$taskErrBuffer = [char[]]::new(8192)
$taskOutRead = $taskProcess.StandardOutput.ReadAsync($taskOutBuffer, 0, 8192)
$taskErrRead = $taskProcess.StandardError.ReadAsync($taskErrBuffer, 0, 8192)
$taskTimedOut = !$taskProcess.WaitForExit($Seconds * 1000)
$taskKillError = $null
if ($taskTimedOut) {
    try { $taskProcess.Kill($true) } catch { $taskKillError = $_.Exception.GetType().FullName }
}
$taskReaped = $taskProcess.WaitForExit(1000)
# Never wait for pending stream reads after the process deadline.
if ($taskOutRead.IsCompletedSuccessfully) {
    [IO.File]::WriteAllText((Join-Path $taskEvidence 'stdout'), [string]::new($taskOutBuffer, 0, $taskOutRead.Result))
}
if ($taskErrRead.IsCompletedSuccessfully) {
    [IO.File]::WriteAllText((Join-Path $taskEvidence 'stderr'), [string]::new($taskErrBuffer, 0, $taskErrRead.Result))
}
@{ mode=$Mode; pid=$taskProcess.Id; started_utc=$taskStarted.ToString('o'); timeout=$taskTimedOut; reaped=$taskReaped; kill_error=$taskKillError; exit=$(if ($taskReaped) {$taskProcess.ExitCode} else {$null}); deadline_seconds=$Seconds; output_limit_chars=8192; stdout_available=$taskOutRead.IsCompletedSuccessfully; stderr_available=$taskErrRead.IsCompletedSuccessfully } |
    ConvertTo-Json | Set-Content -LiteralPath (Join-Path $taskEvidence 'supervisor.json')
if (!$taskReaped -or $taskTimedOut) { exit 124 }
exit $taskProcess.ExitCode
