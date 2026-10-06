# Prepared fixtures only; no execution before source/dependency/owner review.
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
Add-Type -Path (Join-Path $taskRoot 'tools/WindowsDiagnosticJob.cs')
$taskShell = (Get-Process -Id $PID).Path
foreach ($taskCase in @('natural-child','timeout-tree','large-output')) {
    $taskResult = [WindowsDiagnosticJob]::Run($taskShell, [string[]]@('-NoProfile','-File',(Join-Path $PSScriptRoot 'diagnostic-job-root.ps1'),'-Case',$taskCase), $taskRoot, 2000)
    if (!$taskResult.Assigned -or !$taskResult.TreeCleanupVerified) { throw 'Unverified tree: stop all checks' }
    if ($taskCase -eq 'natural-child' -and ($taskResult.Timeout -or !$taskResult.TerminationRequested)) { throw 'Natural root survivor cleanup failed' }
    if ($taskCase -eq 'timeout-tree' -and (!$taskResult.Timeout -or $taskResult.ExitCode -ne 124)) { throw 'Timeout tree outcome failed' }
    if ($taskCase -eq 'large-output' -and (!$taskResult.Stdout.Complete -or !$taskResult.Stdout.Truncated -or $taskResult.Stdout.Text.Length -ne 8192 -or !$taskResult.Stderr.Complete)) { throw 'Bounded drain evidence failed' }
}
