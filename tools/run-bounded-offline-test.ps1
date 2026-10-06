param([Parameter(Mandatory=$true)][string]$PhpPath, [Parameter(Mandatory=$true)][string]$Filter, [Parameter(Mandatory=$true)][string]$EvidenceDirectory, [ValidateRange(1,30)][int]$Seconds = 15)
# Offline PHPUnit only. Unexecuted preparation pending independent review.
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ([string]::IsNullOrWhiteSpace($Filter)) { throw 'Explicit targeted filter required' }
if (!(Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'PHP executable missing' }
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'New evidence directory required' }
New-Item -ItemType Directory -Path $EvidenceDirectory | Out-Null
Add-Type -Path (Join-Path $PSScriptRoot 'WindowsDiagnosticJob.cs')
$taskResult = [WindowsDiagnosticJob]::Run([IO.Path]::GetFullPath($PhpPath), [string[]]@((Join-Path $taskRoot 'vendor/phpunit/phpunit/phpunit'), '--filter', $Filter), $taskRoot, $Seconds*1000)
. (Join-Path $PSScriptRoot 'save-diagnostic-job-result.ps1')
Save-DiagnosticJobResult $taskResult $EvidenceDirectory 'offline-test'
exit $taskResult.ExitCode
