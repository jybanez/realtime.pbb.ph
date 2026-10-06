param([Parameter(Mandatory=$true)][string]$PhpPath, [Parameter(Mandatory=$true)][string]$AssemblyPath, [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{64}$')][string]$AssemblySha256, [Parameter(Mandatory=$true)][string]$EvidenceDirectory)
# Prepared inner PHP stage only; separate independent outer containment is mandatory.
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'New evidence directory required' }
if ((Test-Path -LiteralPath (Join-Path $taskRoot '.env')) -or (Test-Path -LiteralPath (Join-Path $taskRoot 'bootstrap/cache/config.php'))) { throw 'Environment/cached configuration isolation not established' }
$taskVendor = Get-Item -LiteralPath (Join-Path $taskRoot 'vendor')
if ($taskVendor.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Shared vendor root prohibited' }
if ((Get-Item -LiteralPath $AssemblyPath).Length -gt 4194304 -or (Get-FileHash -LiteralPath $AssemblyPath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $AssemblySha256) { throw 'Assembly identity mismatch' }
Add-Type -Path $AssemblyPath
New-Item -ItemType Directory -Path $EvidenceDirectory | Out-Null
$taskReport = Join-Path $EvidenceDirectory 'junit.xml'
$taskResult = [WindowsDiagnosticJob]::Run([IO.Path]::GetFullPath($PhpPath), [string[]]@((Join-Path $taskRoot 'vendor/phpunit/phpunit/phpunit'),'--configuration',(Join-Path $taskRoot 'phpunit.diagnostics.xml'),'--log-junit',$taskReport), $taskRoot, 15000)
. (Join-Path $PSScriptRoot 'save-diagnostic-job-result.ps1')
Save-DiagnosticJobResult $taskResult $EvidenceDirectory 'offline-diagnostic-php'
if ($taskResult.ExitCode -ne 0 -or $taskResult.RootExit -ne 0 -or !$taskResult.RootReaped -or !$taskResult.Assigned -or !$taskResult.TreeCleanupVerified -or $taskResult.Timeout -or $null -ne $taskResult.Failure -or !$taskResult.Stdout.Complete -or !$taskResult.Stderr.Complete -or $taskResult.Stdout.Failed -or $taskResult.Stderr.Failed -or $taskResult.Stdout.Truncated -or $taskResult.Stderr.Truncated) { throw 'PHP outcome failed or incomplete: stop' }
& (Join-Path $PSScriptRoot 'assert-diagnostic-junit.ps1') -ReportPath $taskReport
if (!$?) { throw 'JUnit enforcement failed: stop' }
exit 0
