param(
    [Parameter(Mandatory=$true)][string]$PhpPath,
    [Parameter(Mandatory=$true)][string]$Filter,
    [Parameter(Mandatory=$true)][string]$EvidenceDirectory,
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
$taskInfo.ArgumentList.Add((Join-Path $taskRoot 'vendor/phpunit/phpunit/phpunit'))
$taskInfo.ArgumentList.Add('--filter')
$taskInfo.ArgumentList.Add($Filter)
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
@{ pid=$taskProcess.Id; started_utc=$taskStarted.ToString('o'); timeout=$taskTimedOut; reaped=$taskReaped; kill_error=$taskKillError; exit=$(if ($taskReaped) {$taskProcess.ExitCode} else {$null}); deadline_seconds=$Seconds; output_limit_chars=8192; stdout_available=$taskOutRead.IsCompletedSuccessfully; stderr_available=$taskErrRead.IsCompletedSuccessfully } |
    ConvertTo-Json | Set-Content -LiteralPath (Join-Path $taskEvidence 'supervisor.json')
if (!$taskReaped -or $taskTimedOut) { exit 124 }
exit $taskProcess.ExitCode
