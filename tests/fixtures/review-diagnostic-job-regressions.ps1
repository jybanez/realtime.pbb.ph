param([Parameter(Mandatory=$true)][string]$EvidenceDirectory, [Parameter(Mandatory=$true)][string]$AssemblyPath, [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{64}$')][string]$AssemblySha256, [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{64}$')][string]$SourceSha256)
# Prepared fixtures only; no execution before source/dependency/owner review.
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if ((Get-Item -LiteralPath $AssemblyPath).Length -gt 4194304) { throw 'Assembly size cap exceeded' }
if ((Get-FileHash -LiteralPath $AssemblyPath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $AssemblySha256) { throw 'Assembly identity mismatch' }
if ((Get-FileHash -LiteralPath (Join-Path $taskRoot 'tools/WindowsDiagnosticJob.cs') -Algorithm SHA256).Hash.ToLowerInvariant() -ne $SourceSha256) { throw 'Source identity mismatch' }
Add-Type -Path $AssemblyPath
$taskShell = (Get-Process -Id $PID).Path
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'New evidence directory required' }
New-Item -ItemType Directory -Path $EvidenceDirectory | Out-Null
. (Join-Path $taskRoot 'tools/save-diagnostic-job-result.ps1')
foreach ($taskCase in @('natural-child','timeout-tree','large-output')) {
    $taskResult = [WindowsDiagnosticJob]::Run($taskShell, [string[]]@('-NoProfile','-File',(Join-Path $PSScriptRoot 'diagnostic-job-root.ps1'),'-Case',$taskCase), $taskRoot, 2000)
    $taskCaseEvidence = Join-Path $EvidenceDirectory $taskCase
    New-Item -ItemType Directory -Path $taskCaseEvidence | Out-Null
    Save-DiagnosticJobResult $taskResult $taskCaseEvidence $taskCase
    if ($taskCase -ne 'large-output' -and $taskResult.Stdout.Text -notmatch 'fixture\.child\.started:[1-9][0-9]*') { throw 'Intended fixture descendant creation not established' }
    if (!$taskResult.Assigned -or !$taskResult.TreeCleanupVerified) { throw 'Unverified tree: stop all checks' }
    if ($taskResult.Stdout.Failed -or $taskResult.Stderr.Failed -or !$taskResult.Stdout.Complete -or !$taskResult.Stderr.Complete) { throw 'Incomplete/failed streams: stop all checks' }
    if ($taskResult.Stdout.Text.Length -gt 8192 -or $taskResult.Stderr.Text.Length -gt 8192) { throw 'Retention cap exceeded' }
    if ($taskCase -eq 'natural-child' -and ($taskResult.Timeout -or !$taskResult.TerminationRequested -or $taskResult.RootExit -ne 0 -or $taskResult.ExitCode -ne 0 -or $taskResult.Stdout.Truncated -or $taskResult.Stderr.Truncated)) { throw 'Natural root survivor cleanup failed' }
    if ($taskCase -eq 'timeout-tree' -and (!$taskResult.Timeout -or $taskResult.ExitCode -ne 124)) { throw 'Timeout tree outcome failed' }
    if ($taskCase -eq 'large-output' -and ($taskResult.RootExit -ne 0 -or $taskResult.ExitCode -ne 0 -or !$taskResult.Stdout.Truncated -or !$taskResult.Stderr.Truncated -or $taskResult.Stdout.Text.Length -ne 8192 -or $taskResult.Stderr.Text.Length -ne 8192)) { throw 'Bounded drain evidence failed' }
}
$taskFailure = [WindowsDiagnosticJob]::Run($taskShell, [string[]]@('-NoProfile','-File',(Join-Path $PSScriptRoot 'diagnostic-job-root.ps1'),'-Case','incomplete-utf8'), $taskRoot, 2000)
$taskCaseEvidence = Join-Path $EvidenceDirectory 'incomplete-utf8'
New-Item -ItemType Directory -Path $taskCaseEvidence | Out-Null
Save-DiagnosticJobResult $taskFailure $taskCaseEvidence 'incomplete-utf8'
if (!$taskFailure.TreeCleanupVerified -or !$taskFailure.Stdout.Failed -or $taskFailure.Stdout.Complete -or $taskFailure.ExitCode -ne 124) { throw 'Incomplete UTF8 was incorrectly certified: stop all checks' }
$taskFailure = [WindowsDiagnosticJob]::Run((Join-Path $taskRoot 'nonexistent-review-executable.exe'), [string[]]@(), $taskRoot, 1000)
$taskCaseEvidence = Join-Path $EvidenceDirectory 'launch-failure'
New-Item -ItemType Directory -Path $taskCaseEvidence | Out-Null
Save-DiagnosticJobResult $taskFailure $taskCaseEvidence 'launch-failure'
if ($taskFailure.Pid -ne 0 -or $taskFailure.Assigned -or $taskFailure.ExitCode -ne 124 -or $null -eq $taskFailure.Failure) { throw 'Launch failure incorrectly certified' }
$taskFailure = [WindowsDiagnosticJob]::Run($taskShell, [string[]]@('-NoProfile','-Command','exit 0'), $taskRoot, 1000, [WindowsDiagnosticJob+ReviewFault]::Assignment)
$taskCaseEvidence = Join-Path $EvidenceDirectory 'assignment-failure'
New-Item -ItemType Directory -Path $taskCaseEvidence | Out-Null
Save-DiagnosticJobResult $taskFailure $taskCaseEvidence 'assignment-failure'
if ($taskFailure.Assigned -or !$taskFailure.RootReaped -or !$taskFailure.TreeCleanupVerified -or $taskFailure.ExitCode -ne 124 -or $null -eq $taskFailure.Failure) { throw 'Suspended assignment failure cleanup not verified: stop all checks' }
