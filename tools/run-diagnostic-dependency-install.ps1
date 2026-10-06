param([Parameter(Mandatory=$true)][string]$PhpPath, [Parameter(Mandatory=$true)][string]$ComposerPath, [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{64}$')][string]$ComposerSha256, [Parameter(Mandatory=$true)][string]$EvidenceDirectory)
# Prepared install child: independent outer install Job/deadline required.
$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if (Test-Path -LiteralPath $EvidenceDirectory) { throw 'New install evidence required' }
foreach ($taskForbidden in @('.env','vendor','bootstrap/cache/config.php','bootstrap/cache/packages.php','bootstrap/cache/services.php')) { if (Test-Path -LiteralPath (Join-Path $taskRoot $taskForbidden)) { throw 'Install requires clean isolated source checkout' } }
if ((Get-Item -LiteralPath $ComposerPath).Length -gt 4194304 -or (Get-FileHash -LiteralPath $ComposerPath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $ComposerSha256) { throw 'Composer identity mismatch' }
$taskLock = Join-Path $taskRoot 'composer.lock'
$taskLockHash = (Get-FileHash -LiteralPath $taskLock -Algorithm SHA256).Hash.ToLowerInvariant()
New-Item -ItemType Directory -Path $EvidenceDirectory | Out-Null
$env:COMPOSER = Join-Path $taskRoot 'composer.json'
$env:COMPOSER_VENDOR_DIR = Join-Path $taskRoot 'vendor'
$env:COMPOSER_HOME = Join-Path $EvidenceDirectory 'composer-home'
$env:COMPOSER_CACHE_DIR = Join-Path $EvidenceDirectory 'composer-cache'
Push-Location -LiteralPath $taskRoot
try {
    & $PhpPath $ComposerPath install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
    if ($LASTEXITCODE -ne 0) { throw 'Composer installation failed: stop' }
    if ((Get-FileHash -LiteralPath $taskLock -Algorithm SHA256).Hash.ToLowerInvariant() -ne $taskLockHash) { throw 'Lockfile changed: stop' }
    $taskVendor = Join-Path $taskRoot 'vendor'
    if ((Get-Item -LiteralPath $taskVendor).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Shared vendor root prohibited' }
    Get-ChildItem -LiteralPath $taskVendor -Recurse -Force | ForEach-Object { if ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Vendor reparse point prohibited' } }
    & $PhpPath (Join-Path $PSScriptRoot 'assert-offline-diagnostic-config.php')
    if ($LASTEXITCODE -ne 0) { throw 'Installed offline config/origin guard failed' }
    [ordered]@{lock_sha256=$taskLockHash;composer_sha256=$ComposerSha256;scripts_enabled=$false;plugins_enabled=$false;vendor_reparse_points=0;offline_guard_exit=0} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $EvidenceDirectory 'installation.json') -Encoding utf8
} finally { Pop-Location }
exit 0
