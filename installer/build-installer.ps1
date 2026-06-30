param(
    [string]$ZipPath = ""
)

$ErrorActionPreference = "Stop"

$Root = Split-Path -Parent $PSScriptRoot
$OutputDir = Join-Path $Root "storage\\app\\installer-build"
$PhpBin = if (Test-Path "C:\\wamp64\\bin\\php\\php8.2.29\\php.exe") {
    "C:\\wamp64\\bin\\php\\php8.2.29\\php.exe"
} else {
    (Get-Command php).Source
}
$ComposerPhar = if (Test-Path "C:\\ProgramData\\ComposerSetup\\bin\\composer.phar") {
    "C:\\ProgramData\\ComposerSetup\\bin\\composer.phar"
} else {
    $null
}

if ([string]::IsNullOrWhiteSpace($ZipPath)) {
    $ZipPath = Join-Path $OutputDir "pbb-realtime-installer.zip"
}

if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir | Out-Null
}

if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force
}

$Items = @(
    "public",
    "installer",
    "installer\\docs\\post-install-checklist.md",
    "docs\\pbb-realtime-installer-proposal.md",
    "docs\\pbb-realtime-installer-spec.md",
    "docs\\pbb-realtime-installer-implementation-checklist.md",
    "docs\\pbb-realtime-installer-ui-and-actions-spec.md",
    "docs\\pbb-realtime-installer-quickstart.md",
    "docs\\pbb-realtime-installer-hub-operator-guide.md",
    "docs\\pbb-realtime-installer-upgrade-guide.md",
    "docs\\pbb-realtime-installer-repair-guide.md",
    "docs\\pbb-realtime-installer-troubleshooting-guide.md",
    "docs\\pbb-realtime-installer-clean-windows-host-checklist.md",
    "docs\\pbb-realtime-installer-clean-linux-host-checklist.md",
    "docs\\pbb-realtime-data-prep-contract.md",
    "docs\\pbb-realtime-account-integration.md",
    ".env.example",
    "release.json",
    "checksums.sha256",
    "artisan",
    "bootstrap",
    "config",
    "app",
    "database",
    "resources",
    "routes",
    "tools",
    "vendor",
    "composer.json",
    "composer.lock"
)

$StageRoot = Join-Path $OutputDir ("package-stage-" + [System.Guid]::NewGuid().ToString("N"))
$PackageRoot = $Root

try {
    New-Item -ItemType Directory -Path $StageRoot | Out-Null

    foreach ($Item in $Items) {
        if ($Item -eq "vendor") {
            continue
        }

        $SourcePath = Join-Path $Root $Item
        if (-not (Test-Path $SourcePath)) {
            continue
        }

        $DestinationPath = Join-Path $StageRoot $Item
        $DestinationParent = Split-Path -Parent $DestinationPath
        if (-not [string]::IsNullOrWhiteSpace($DestinationParent) -and -not (Test-Path $DestinationParent)) {
            New-Item -ItemType Directory -Path $DestinationParent -Force | Out-Null
        }

        Copy-Item -LiteralPath $SourcePath -Destination $DestinationPath -Recurse -Force
    }

    $PreviousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        if ($ComposerPhar -ne $null) {
            $ComposerOutput = & $PhpBin $ComposerPhar install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir $StageRoot 2>&1
        } else {
            $ComposerOutput = & composer install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir $StageRoot 2>&1
        }
        $ComposerExitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $PreviousErrorActionPreference
    }
    if ($ComposerExitCode -ne 0) {
        throw ("Production Composer install failed. " + ($ComposerOutput -join "`n"))
    }

    $PackageRoot = $StageRoot
}
catch {
    Remove-Item $StageRoot -Recurse -Force -ErrorAction SilentlyContinue
    throw
}

$Resolved = @()
foreach ($Item in $Items) {
    $Path = Join-Path $PackageRoot $Item
    if (Test-Path $Path) {
        $Resolved += [pscustomobject]@{
            Source = $Path
            Target = $Item
        }
    }
}

$HelperRuntimeAllowList = @(
    "public/vendor/helpers.pbb.ph/dist/",
    "public/vendor/helpers.pbb.ph/js/ui/ui.loader.js",
    "public/vendor/helpers.pbb.ph/js/vendor/marked.esm.js",
    "public/vendor/helpers.pbb.ph/css/ui/ui.tokens.css",
    "public/vendor/helpers.pbb.ph/css/ui/ui.components.css"
)

$HelperRuntimeAllowGlobs = @(
    "public/vendor/helpers.pbb.ph/boot.*.json"
)

$BundleExcludeGlobs = @(
    "installer/build-installer.ps1",
    "installer/test-installer-bundle.ps1",
    ".github/*",
    "tests/*",
    "database/factories/*",
    "database/seeders/*",
    "public/sdk-demo/*",
    "public/sdk-demo-attachments/*",
    "public/sdk-demo-conference/*",
    "public/tests/*"
)

$ReleasePath = Join-Path $Root "release.json"
$Release = Get-Content $ReleasePath -Raw | ConvertFrom-Json
if (-not $Release.PSObject.Properties.Name.Contains("milestone")) {
    $Release | Add-Member -NotePropertyName "milestone" -NotePropertyValue 1
}
$Release.display_version = "v$($Release.milestone)-$($Release.version)"

$GitCommit = $null
try {
    $GitCommitOutput = & git -C $Root rev-parse --short HEAD 2>$null
    if ($LASTEXITCODE -eq 0 -and -not [string]::IsNullOrWhiteSpace($GitCommitOutput)) {
        $GitCommit = ($GitCommitOutput | Select-Object -First 1).Trim()
    }
}
catch {
    $GitCommit = $null
}

$BuiltAt = (Get-Date).ToUniversalTime().ToString("o")
$BuildId = "pbb-realtime-" + (Get-Date -Format "yyyyMMddHHmmss")
$BuilderName = if (-not [string]::IsNullOrWhiteSpace($env:USERNAME)) {
    $env:USERNAME
} elseif (-not [string]::IsNullOrWhiteSpace($env:USER)) {
    $env:USER
} else {
    "installer/build-installer.ps1"
}

$Release.build = [pscustomobject]@{
    version = $Release.version
    id = $BuildId
    built_at = $BuiltAt
    git_commit = $GitCommit
    builder = $BuilderName
}
$ReleaseJson = $Release | ConvertTo-Json -Depth 10

$TempPhp = [System.IO.Path]::GetTempFileName() + ".php"
$TempJson = [System.IO.Path]::GetTempFileName() + ".json"

try {
    @'
<?php
$payload = json_decode(file_get_contents($argv[1]), true);
$sources = $payload['sources'];
$zipPath = $payload['zip_path'];
$helperRuntimeAllowList = $payload['helper_runtime_allow_list'];
$helperRuntimeAllowGlobs = $payload['helper_runtime_allow_globs'];
$bundleExcludeGlobs = $payload['bundle_exclude_globs'];
$releaseJson = $payload['release_json'];

function glob_to_regex(string $glob): string
{
    return '#^' . str_replace('\*', '[^/]*', preg_quote($glob, '#')) . '$#';
}

function should_include_path(string $targetPath, array $helperRuntimeAllowList, array $helperRuntimeAllowGlobs): bool
{
    $path = str_replace('\\', '/', $targetPath);
    $helperRoot = 'public/vendor/helpers.pbb.ph/';

    if (! str_starts_with($path, $helperRoot)) {
        return true;
    }

    foreach ($helperRuntimeAllowList as $allowed) {
        $allowed = str_replace('\\', '/', $allowed);
        if (str_ends_with($allowed, '/')) {
            if (str_starts_with($path, $allowed)) {
                return true;
            }
            continue;
        }

        if ($path === $allowed) {
            return true;
        }
    }

    foreach ($helperRuntimeAllowGlobs as $glob) {
        if (preg_match(glob_to_regex(str_replace('\\', '/', $glob)), $path) === 1) {
            return true;
        }
    }

    return false;
}

function should_exclude_path(string $targetPath, array $bundleExcludeGlobs): bool
{
    $path = str_replace('\\', '/', $targetPath);

    foreach ($bundleExcludeGlobs as $glob) {
        $glob = str_replace('\\', '/', $glob);
        if (str_ends_with($glob, '/*') && str_starts_with($path, substr($glob, 0, -1))) {
            return true;
        }

        if (preg_match(glob_to_regex($glob), $path) === 1) {
            return true;
        }
    }

    return false;
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Unable to create installer archive.\n");
    exit(1);
}

$added = [];
$checksums = [];

foreach ($sources as $entry) {
    $source = $entry['Source'];
    $target = str_replace('\\', '/', $entry['Target']);

    if (is_file($source)) {
        if (should_exclude_path($target, $bundleExcludeGlobs)) {
            continue;
        }
        if ($target === 'release.json') {
            if (! isset($added[$target])) {
                $zip->addFromString($target, $releaseJson);
                $checksums[$target] = hash('sha256', $releaseJson);
                $added[$target] = true;
            }
            continue;
        }
        if ($target === 'checksums.sha256') {
            continue;
        }
        if (! should_include_path($target, $helperRuntimeAllowList, $helperRuntimeAllowGlobs)) {
            continue;
        }
        if (! isset($added[$target])) {
            $zip->addFile($source, $target);
            $checksums[$target] = hash_file('sha256', $source);
            $added[$target] = true;
        }
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (! $fileInfo->isFile()) {
            continue;
        }

        $absolutePath = $fileInfo->getPathname();
        $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($source) + 1));
        $zipTarget = trim($target, '/') . '/' . $relativePath;
        if (should_exclude_path($zipTarget, $bundleExcludeGlobs)) {
            continue;
        }
        if ($zipTarget === 'release.json') {
            if (! isset($added[$zipTarget])) {
                $zip->addFromString($zipTarget, $releaseJson);
                $checksums[$zipTarget] = hash('sha256', $releaseJson);
                $added[$zipTarget] = true;
            }
            continue;
        }
        if ($zipTarget === 'checksums.sha256') {
            continue;
        }
        if (! should_include_path($zipTarget, $helperRuntimeAllowList, $helperRuntimeAllowGlobs)) {
            continue;
        }
        if (! isset($added[$zipTarget])) {
            $zip->addFile($absolutePath, $zipTarget);
            $checksums[$zipTarget] = hash_file('sha256', $absolutePath);
            $added[$zipTarget] = true;
        }
    }
}

ksort($checksums, SORT_STRING);
$checksumLines = [];
foreach ($checksums as $path => $hash) {
    $checksumLines[] = $hash . '  ' . $path;
}
$zip->addFromString('checksums.sha256', implode("\n", $checksumLines) . "\n");

$zip->close();
echo $zipPath;
'@ | Set-Content -Path $TempPhp -Encoding UTF8

    if (Test-Path $ZipPath) {
        Remove-Item $ZipPath -Force
    }

    $BuildPayload = @{
        sources = $Resolved
        zip_path = $ZipPath
        helper_runtime_allow_list = $HelperRuntimeAllowList
        helper_runtime_allow_globs = $HelperRuntimeAllowGlobs
        bundle_exclude_globs = $BundleExcludeGlobs
        release_json = $ReleaseJson
    }
    $ResolvedJson = $BuildPayload | ConvertTo-Json -Compress -Depth 6
    $Utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($TempJson, $ResolvedJson, $Utf8NoBom)
    $PhpOutput = & $PhpBin $TempPhp $TempJson $ZipPath 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ("Installer ZIP build failed. " + ($PhpOutput -join "`n"))
    }
    if (-not (Test-Path $ZipPath)) {
        throw ("Installer ZIP build did not produce an output file. " + ($PhpOutput -join "`n"))
    }
}
finally {
    Remove-Item $TempPhp -ErrorAction SilentlyContinue
    Remove-Item $TempJson -ErrorAction SilentlyContinue
}

Write-Host "Installer ZIP created at: $ZipPath"

Remove-Item $StageRoot -Recurse -Force -ErrorAction SilentlyContinue
