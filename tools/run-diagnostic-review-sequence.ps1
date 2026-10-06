param([Parameter(Mandatory=$true)][string]$PythonPath, [Parameter(Mandatory=$true)][string]$PowerShellPath, [Parameter(Mandatory=$true)][string]$EvidenceDirectory)
# Prepared sequencing child: invoke ONLY under independent outer --phase sequence.
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'New sequence evidence required' }
New-Item -ItemType Directory -Path $EvidenceDirectory | Out-Null
$taskCompile = Join-Path $EvidenceDirectory 'compile'
$taskDriver = Join-Path $EvidenceDirectory 'driver'
$taskSupervisor = Join-Path $PSScriptRoot 'supervise-diagnostic-review.py'
& $PythonPath $taskSupervisor --phase compile --powershell $PowerShellPath --evidence $taskCompile
if ($LASTEXITCODE -ne 0) { throw 'Compile failed: sequence stopped' }
$taskOutcomePath = Join-Path $taskCompile 'outer.json'
if ((Get-Item -LiteralPath $taskOutcomePath).Length -gt 8192) { throw 'Compile outcome size exceeded' }
$taskOutcome = Get-Content -LiteralPath $taskOutcomePath -Raw | ConvertFrom-Json
if ($taskOutcome.exit -ne 0 -or !$taskOutcome.root_reaped -or !$taskOutcome.tree_cleanup_verified -or !$taskOutcome.drainers_stopped -or $taskOutcome.timeout -or $null -ne $taskOutcome.failure -or $taskOutcome.cleanup_failures.Count -ne 0 -or !$taskOutcome.stdout.complete -or !$taskOutcome.stderr.complete -or $taskOutcome.stdout.failed -or $taskOutcome.stderr.failed) { throw 'Compile outcome unverified: sequence stopped' }
& $PythonPath $taskSupervisor --phase driver --powershell $PowerShellPath --evidence $taskDriver --compiled-evidence $taskCompile
if ($LASTEXITCODE -ne 0) { throw 'Driver failed: sequence stopped' }
exit 0
