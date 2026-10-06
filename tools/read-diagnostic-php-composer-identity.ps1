param([Parameter(Mandatory=$true)][string]$PhpPath, [Parameter(Mandatory=$true)][string]$ComposerPath)
# Prepared identity acquisition only, under independent php-identity Job/deadline.
$ErrorActionPreference = 'Stop'
if ((Get-Item -LiteralPath $ComposerPath).Length -gt 4194304) { throw 'Composer size cap exceeded' }
$taskBinary = [IO.File]::OpenRead($PhpPath)
try {
    $taskReader = [IO.BinaryReader]::new($taskBinary)
    if ($taskReader.ReadUInt16() -ne 0x5A4D) { throw 'PHP DOS signature invalid' }
    $taskBinary.Position=0x3C; $taskPeOffset=$taskReader.ReadUInt32()
    if ($taskPeOffset -gt 1048576 -or $taskPeOffset -gt $taskBinary.Length-6) { throw 'PHP PE offset invalid' }
    $taskBinary.Position=$taskPeOffset
    if ($taskReader.ReadUInt32() -ne 0x4550) { throw 'PHP PE signature invalid' }
    $taskMachine=$taskReader.ReadUInt16()
} finally { $taskBinary.Dispose() }
$taskPhpRaw = & $PhpPath (Join-Path $PSScriptRoot 'read-diagnostic-php-identity.php')
if ($LASTEXITCODE -ne 0) { throw 'PHP identity command failed' }
$taskPhpText=$taskPhpRaw -join "`n"
if ($taskPhpText.Length -gt 4096) { throw 'PHP identity record exceeds cap' }
$taskPhp=$taskPhpText | ConvertFrom-Json
$taskComposerRaw = & $PhpPath $ComposerPath --no-plugins --no-scripts --no-ansi --version
if ($LASTEXITCODE -ne 0) { throw 'Composer identity command failed' }
$taskComposerText=$taskComposerRaw -join "`n"
if ($taskComposerText.Length -gt 2048) { throw 'Composer identity record exceeds cap' }
[ordered]@{
    php=$taskPhp
    php_pe_machine=('0x{0:X4}' -f $taskMachine)
    php_file_version=(Get-Item -LiteralPath $PhpPath).VersionInfo.FileVersion
    php_sha256=(Get-FileHash -LiteralPath $PhpPath -Algorithm SHA256).Hash.ToLowerInvariant()
    composer_version=$taskComposerText
    composer_bytes=(Get-Item -LiteralPath $ComposerPath).Length
    composer_sha256=(Get-FileHash -LiteralPath $ComposerPath -Algorithm SHA256).Hash.ToLowerInvariant()
    scope='installed PHP and Composer reproducibility inventory only'
} | ConvertTo-Json -Depth 4 -Compress
