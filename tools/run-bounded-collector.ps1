param([Parameter(Mandatory=$true)][string]$PhpPath, [Parameter(Mandatory=$true)][string]$OutputFile, [Parameter(Mandatory=$true)][string]$EvidenceDirectory, [ValidateRange(1,65535)][int]$Port = 9998, [ValidateRange(3,30)][int]$Seconds = 15)
# Collector-only launcher. Never called by gateway/offline-test launcher.
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if (!(Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'PHP executable missing' }
if (Test-Path -LiteralPath $OutputFile) { throw 'New collector output required' }
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'New evidence directory required' }
New-Item -ItemType Directory -Path $EvidenceDirectory | Out-Null
Add-Type -Path (Join-Path $PSScriptRoot 'WindowsDiagnosticJob.cs')
$taskResult = [WindowsDiagnosticJob]::Run([IO.Path]::GetFullPath($PhpPath), [string[]]@((Join-Path $taskRoot 'tools/collect-callback-diagnostics.php'), [IO.Path]::GetFullPath($OutputFile), [string]$Port, [string]($Seconds-2)), $taskRoot, $Seconds*1000)
. (Join-Path $PSScriptRoot 'save-diagnostic-job-result.ps1')
Save-DiagnosticJobResult $taskResult $EvidenceDirectory 'collector'
exit $taskResult.ExitCode
