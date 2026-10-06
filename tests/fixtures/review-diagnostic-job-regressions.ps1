# Prepared fixtures only; no execution before source/dependency/owner review.
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
Add-Type -Path (Join-Path $taskRoot 'tools/WindowsDiagnosticJob.cs')
$taskShell = (Get-Process -Id $PID).Path
foreach ($taskCase in @('natural-child','timeout-tree','large-output')) {
    $taskResult = [WindowsDiagnosticJob]::Run($taskShell, [string[]]@('-NoProfile','-File',(Join-Path $PSScriptRoot 'diagnostic-job-root.ps1'),'-Case',$taskCase), $taskRoot, 2000)
    if (!$taskResult.Assigned -or !$taskResult.TreeCleanupVerified) { throw 'Unverified tree: stop all checks' }
    if ($taskResult.Stdout.Failed -or $taskResult.Stderr.Failed -or !$taskResult.Stdout.Complete -or !$taskResult.Stderr.Complete) { throw 'Incomplete/failed streams: stop all checks' }
    if ($taskResult.Stdout.Text.Length -gt 8192 -or $taskResult.Stderr.Text.Length -gt 8192) { throw 'Retention cap exceeded' }
    if ($taskCase -eq 'natural-child' -and ($taskResult.Timeout -or !$taskResult.TerminationRequested -or $taskResult.RootExit -ne 0 -or $taskResult.ExitCode -ne 0 -or $taskResult.Stdout.Truncated -or $taskResult.Stderr.Truncated)) { throw 'Natural root survivor cleanup failed' }
    if ($taskCase -eq 'timeout-tree' -and (!$taskResult.Timeout -or $taskResult.ExitCode -ne 124)) { throw 'Timeout tree outcome failed' }
    if ($taskCase -eq 'large-output' -and ($taskResult.RootExit -ne 0 -or $taskResult.ExitCode -ne 0 -or !$taskResult.Stdout.Truncated -or !$taskResult.Stderr.Truncated -or $taskResult.Stdout.Text.Length -ne 8192 -or $taskResult.Stderr.Text.Length -ne 8192)) { throw 'Bounded drain evidence failed' }
}
$taskFailure = [WindowsDiagnosticJob]::Run((Join-Path $taskRoot 'nonexistent-review-executable.exe'), [string[]]@(), $taskRoot, 1000)
if ($taskFailure.Pid -ne 0 -or $taskFailure.Assigned -or $taskFailure.ExitCode -ne 124 -or $null -eq $taskFailure.Failure) { throw 'Launch failure incorrectly certified' }
$taskFailure = [WindowsDiagnosticJob]::Run($taskShell, [string[]]@('-NoProfile','-Command','exit 0'), $taskRoot, 1000, [WindowsDiagnosticJob+ReviewFault]::Assignment)
if ($taskFailure.Assigned -or !$taskFailure.RootReaped -or !$taskFailure.TreeCleanupVerified -or $taskFailure.ExitCode -ne 124 -or $null -eq $taskFailure.Failure) { throw 'Suspended assignment failure cleanup not verified: stop all checks' }
