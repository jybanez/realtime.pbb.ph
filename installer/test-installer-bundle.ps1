param(
    [int]$InstallerPort = 9310,
    [int]$AppPort = 9312,
    [int]$WsPort = 9311,
    [int]$ZipRetention = 3,
    [switch]$KeepArtifacts
)

$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$BuildScript = Join-Path $PSScriptRoot "build-installer.ps1"
$BuildRoot = Join-Path $RepoRoot "storage\app\installer-build"
$Timestamp = Get-Date -Format "yyyyMMddHHmmss"
$ExtractRoot = Join-Path $BuildRoot ("acceptance-" + $Timestamp)
$ReportPath = Join-Path $BuildRoot "installer-acceptance-report.json"
$PhpBin = if (Test-Path "C:\wamp64\bin\php\php8.2.29\php.exe") {
    "C:\wamp64\bin\php\php8.2.29\php.exe"
} else {
    (Get-Command php).Source
}
$AdminEmail = "installer.acceptance+$Timestamp@example.local"
$AdminPassword = "Realtime!23456"
$InstallConfig = $null
$InstallerProcess = $null
$AppProcess = $null
$WsProcess = $null
$TempDbName = $null
$ScheduledTaskName = $null

function Parse-EnvFile([string]$Path) {
    $values = @{}
    foreach ($line in Get-Content $Path) {
        if ([string]::IsNullOrWhiteSpace($line)) { continue }
        if ($line.TrimStart().StartsWith("#")) { continue }
        $parts = $line.Split("=", 2)
        if ($parts.Count -ne 2) { continue }
        $key = $parts[0].Trim()
        $value = $parts[1].Trim().Trim('"').Trim("'")
        $values[$key] = $value
    }
    return $values
}

function Env-OrDefault($Table, [string]$Key, [string]$DefaultValue) {
    if ($null -ne $Table -and $Table.ContainsKey($Key) -and -not [string]::IsNullOrWhiteSpace([string]$Table[$Key])) {
        return [string]$Table[$Key]
    }

    return $DefaultValue
}

function Invoke-PhpInline([string]$Code, [string[]]$Arguments = @(), [string]$WorkingDirectory = $RepoRoot) {
    if (-not (Test-Path $BuildRoot)) {
        New-Item -ItemType Directory -Path $BuildRoot -Force | Out-Null
    }
    $tempFile = Join-Path $BuildRoot ("php-inline-" + [System.Guid]::NewGuid().ToString("N") + ".php")
    Set-Content -Path $tempFile -Value $Code -Encoding UTF8
    try {
        $output = & $PhpBin $tempFile @Arguments 2>&1
        return [pscustomobject]@{
            ExitCode = $LASTEXITCODE
            Output = ($output -join "`n")
        }
    }
    finally {
        Remove-Item $tempFile -ErrorAction SilentlyContinue
    }
}

function New-AcceptanceDatabase([hashtable]$EnvValues, [string]$DatabaseName) {
    $result = Invoke-PhpInline -Code @'
<?php
$host = $argv[1];
$port = $argv[2];
$database = $argv[3];
$username = $argv[4];
$password = $argv[5];
$pdo = new PDO("mysql:host={$host};port={$port}", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$check = new PDO("mysql:host={$host};port={$port};dbname={$database}", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
echo json_encode(['created' => true, 'database' => $database], JSON_UNESCAPED_SLASHES);
'@ -Arguments @(
        $EnvValues["DB_HOST"],
        (Env-OrDefault $EnvValues "DB_PORT" "3306"),
        $DatabaseName,
        $EnvValues["DB_USERNAME"],
        (Env-OrDefault $EnvValues "DB_PASSWORD" "")
    )

    if ($result.ExitCode -ne 0) {
        throw "Failed to create temporary acceptance database: $($result.Output)"
    }

    return $result
}

function Remove-AcceptanceDatabase([hashtable]$EnvValues, [string]$DatabaseName) {
    if ([string]::IsNullOrWhiteSpace($DatabaseName)) {
        return
    }

    $result = Invoke-PhpInline -Code @'
<?php
$host = $argv[1];
$port = $argv[2];
$database = $argv[3];
$username = $argv[4];
$password = $argv[5];
$pdo = new PDO("mysql:host={$host};port={$port}", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
echo json_encode(['dropped' => true, 'database' => $database], JSON_UNESCAPED_SLASHES);
'@ -Arguments @(
        $EnvValues["DB_HOST"],
        (Env-OrDefault $EnvValues "DB_PORT" "3306"),
        $DatabaseName,
        $EnvValues["DB_USERNAME"],
        (Env-OrDefault $EnvValues "DB_PASSWORD" "")
    )

    return $result
}

function Wait-HttpReady([string]$Url, [int]$TimeoutSeconds = 30) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        try {
            $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 3
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 500) {
                return
            }
        } catch {
            Start-Sleep -Milliseconds 500
        }
    } while ((Get-Date) -lt $deadline)

    throw "Timed out waiting for HTTP readiness: $Url"
}

function Wait-TcpReady([string]$HostName, [int]$Port, [int]$TimeoutSeconds = 20) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        try {
            $client = New-Object System.Net.Sockets.TcpClient
            $async = $client.BeginConnect($HostName, $Port, $null, $null)
            $ok = $async.AsyncWaitHandle.WaitOne(750)
            if ($ok -and $client.Connected) {
                $client.EndConnect($async)
                $client.Close()
                return
            }
            $client.Close()
        } catch {
        }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)

    throw ("Timed out waiting for TCP {0}:{1}" -f $HostName, $Port)
}

function Get-FreeTcpPort([int]$PreferredPort) {
    try {
        $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $PreferredPort)
        $listener.Start()
        $actualPort = ([System.Net.IPEndPoint]$listener.LocalEndpoint).Port
        $listener.Stop()
        return $actualPort
    }
    catch {
        $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
        $listener.Start()
        $actualPort = ([System.Net.IPEndPoint]$listener.LocalEndpoint).Port
        $listener.Stop()
        return $actualPort
    }
}

function Send-WebSocketJson($Socket, $Object) {
    $json = $Object | ConvertTo-Json -Depth 10 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $segment = [ArraySegment[byte]]::new($bytes)
    $Socket.SendAsync($segment, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, [System.Threading.CancellationToken]::None).GetAwaiter().GetResult()
}

function Receive-WebSocketJson($Socket, [int]$TimeoutMs = 5000) {
    $buffer = New-Object byte[] 8192
    $stream = New-Object System.IO.MemoryStream
    $cts = [System.Threading.CancellationTokenSource]::new()
    $cts.CancelAfter($TimeoutMs)

    try {
        do {
            $segment = [ArraySegment[byte]]::new($buffer)
            $result = $Socket.ReceiveAsync($segment, $cts.Token).GetAwaiter().GetResult()
            if ($result.MessageType -eq [System.Net.WebSockets.WebSocketMessageType]::Close) {
                throw "WebSocket closed unexpectedly."
            }
            $stream.Write($buffer, 0, $result.Count)
        } while (-not $result.EndOfMessage)

        $json = [System.Text.Encoding]::UTF8.GetString($stream.ToArray())
        return $json | ConvertFrom-Json
    }
    finally {
        $stream.Dispose()
        $cts.Dispose()
    }
}

function Wait-WebSocketEnvelope($Socket, [scriptblock]$Predicate, [int]$TimeoutMs = 10000) {
    $deadline = (Get-Date).AddMilliseconds($TimeoutMs)
    do {
        $envelope = Receive-WebSocketJson -Socket $Socket -TimeoutMs 2500
        if (& $Predicate $envelope) {
            return $envelope
        }
    } while ((Get-Date) -lt $deadline)

    throw "Timed out waiting for expected websocket envelope."
}

function Remove-ScheduledTaskIfPresent([string]$TaskName) {
    if ([string]::IsNullOrWhiteSpace($TaskName)) { return }
    try {
        schtasks /Delete /TN $TaskName /F | Out-Null
    } catch {
    }
}

function Stop-ProcessIfPresent($Process) {
    if ($null -eq $Process) { return }
    try {
        if (-not $Process.HasExited) {
            Stop-Process -Id $Process.Id -Force
        }
    } catch {
    }
}

function Stop-PhpProcessesForPath([string]$Path) {
    if ([string]::IsNullOrWhiteSpace($Path)) { return }

    $needle = $Path.Replace('\', '\\')
    Get-CimInstance Win32_Process -Filter "name = 'php.exe'" -ErrorAction SilentlyContinue | Where-Object {
        $commandLine = [string]$_.CommandLine
        $commandLine.Contains($Path) -or $commandLine.Contains($needle)
    } | ForEach-Object {
        try {
            Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop
            Write-Host "[cleanup] stopped PHP process $($_.ProcessId) for $Path"
        } catch {
            Write-Warning ("Could not stop PHP process {0}: {1}" -f $_.ProcessId, $_.Exception.Message)
        }
    }
}

function Remove-InstallerAcceptanceExtracts {
    if (-not (Test-Path $BuildRoot)) { return }

    $buildRootPath = (Resolve-Path -LiteralPath $BuildRoot).Path
    Get-ChildItem -LiteralPath $BuildRoot -Directory -Filter "acceptance-*" -ErrorAction SilentlyContinue | ForEach-Object {
        $target = $_.FullName
        if (-not $target.StartsWith($buildRootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
            return
        }

        try {
            Remove-Item -LiteralPath $target -Recurse -Force -ErrorAction Stop
            Write-Host "[cleanup] removed acceptance extract $target"
        } catch {
            Write-Warning ("Could not remove acceptance extract {0}: {1}" -f $target, $_.Exception.Message)
        }
    }
}

function Remove-OldInstallerZips([int]$KeepLatest = 3) {
    if (-not (Test-Path $BuildRoot)) { return }
    if ($KeepLatest -lt 1) { $KeepLatest = 1 }

    $buildRootPath = (Resolve-Path -LiteralPath $BuildRoot).Path
    $zips = @(Get-ChildItem -LiteralPath $BuildRoot -File -Filter "pbb-realtime-installer-*.zip" -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending)

    if ($zips.Count -le $KeepLatest) { return }

    $zips | Select-Object -Skip $KeepLatest | ForEach-Object {
        $target = $_.FullName
        if (-not $target.StartsWith($buildRootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
            return
        }

        try {
            Remove-Item -LiteralPath $target -Force -ErrorAction Stop
            Write-Host "[cleanup] removed old installer ZIP $target"
        } catch {
            Write-Warning ("Could not remove installer ZIP {0}: {1}" -f $target, $_.Exception.Message)
        }
    }
}

$Result = [ordered]@{
    started_at = (Get-Date).ToString("o")
    status = "running"
    checks = [ordered]@{}
}

try {
    $InstallerPort = Get-FreeTcpPort -PreferredPort $InstallerPort
    $AppPort = Get-FreeTcpPort -PreferredPort $AppPort
    $WsPort = Get-FreeTcpPort -PreferredPort $WsPort
    $InstallerBaseUrl = "http://127.0.0.1:$InstallerPort"
    $AppBaseUrl = "http://127.0.0.1:$AppPort"
    $WebsocketUrl = "ws://127.0.0.1:$WsPort/realtime"

    Write-Host "[acceptance] loading environment"
    $envValues = Parse-EnvFile (Join-Path $RepoRoot ".env")
    $Result.checks.env_loaded = $true

    Write-Host "[acceptance] building installer bundle"
    $ZipPath = Join-Path $BuildRoot ("pbb-realtime-installer-$Timestamp.zip")
    & $BuildScript -ZipPath $ZipPath
    Start-Sleep -Seconds 1
    if (-not (Test-Path $ZipPath)) {
        throw "Installer ZIP was not created."
    }
    $Result.checks.bundle_built = $true

    Write-Host "[acceptance] extracting installer bundle to $ExtractRoot"
    if (Test-Path $ExtractRoot) {
        Remove-Item $ExtractRoot -Recurse -Force
    }
    New-Item -ItemType Directory -Path $ExtractRoot | Out-Null
    Expand-Archive -Path $ZipPath -DestinationPath $ExtractRoot -Force

    $requiredPaths = @(
        "public\installer\index.php",
        "public\index.php",
        "artisan",
        "vendor\autoload.php",
        "app\Http\Controllers\Admin\SandboxController.php"
    )

    foreach ($requiredPath in $requiredPaths) {
        $fullPath = Join-Path $ExtractRoot $requiredPath
        if (-not (Test-Path $fullPath)) {
            throw "Missing extracted path: $requiredPath"
        }
    }

    Write-Host "[acceptance] creating runtime directories"
    $storagePaths = @(
        "storage",
        "storage\app",
        "storage\framework",
        "storage\framework\cache",
        "storage\framework\sessions",
        "storage\framework\views",
        "storage\logs",
        "bootstrap\cache"
    )
    foreach ($storagePath in $storagePaths) {
        $fullPath = Join-Path $ExtractRoot $storagePath
        if (-not (Test-Path $fullPath)) {
            New-Item -ItemType Directory -Path $fullPath -Force | Out-Null
        }
    }
    $Result.checks.bundle_extracted = $true

    $TempDbName = "pbb_realtime_acceptance_$Timestamp"
    Write-Host "[acceptance] creating temporary database $TempDbName"
    $createDbResult = New-AcceptanceDatabase -EnvValues $envValues -DatabaseName $TempDbName
    $Result.checks.temp_database_created = $true

    Write-Host "[acceptance] starting installer server on $InstallerBaseUrl"
    $InstallerProcess = Start-Process -FilePath $PhpBin -ArgumentList @("-S", "127.0.0.1:$InstallerPort", "-t", (Join-Path $ExtractRoot "public")) -WorkingDirectory $ExtractRoot -PassThru -WindowStyle Hidden
    Wait-HttpReady "$InstallerBaseUrl/installer/index.php"
    $Result.checks.installer_http_open = $true

    $targetOs = if ([System.Runtime.InteropServices.RuntimeInformation]::IsOSPlatform([System.Runtime.InteropServices.OSPlatform]::Windows)) { "windows" } else { "linux" }
    $serviceManager = if ($targetOs -eq "windows") { "scheduled-task" } else { "systemd" }
    $InstallConfig = @{
        mode = "fresh"
        app = @{
            install_path = $ExtractRoot
            app_url = $AppBaseUrl
            app_env = "local"
            app_debug = $true
        }
        database = @{
            host = $envValues["DB_HOST"]
            port = [int](Env-OrDefault $envValues "DB_PORT" "3306")
            database = $TempDbName
            username = $envValues["DB_USERNAME"]
            password = $envValues["DB_PASSWORD"]
        }
        realtime = @{
            service_name = "PBB Realtime Acceptance $Timestamp"
            token_audience = (Env-OrDefault $envValues "REALTIME_TOKEN_AUDIENCE" "pbb-realtime")
            token_signing_secret = $envValues["REALTIME_TOKEN_SIGNING_SECRET"]
            trusted_issuers = $envValues["REALTIME_TRUSTED_ISSUERS"]
            public_websocket_url = $WebsocketUrl
            ws_bind_address = "127.0.0.1"
            ws_port = $WsPort
            allowed_origins = $AppBaseUrl
            populate = @{
                enabled = $true
                options = @{
                    overwrite_secrets = $false
                }
                clients = @(
                    @{
                        client_code = "clt_ACCEPTANCE"
                        name = "PBB Realtime Acceptance"
                        status = "active"
                        description = "Acceptance fixture loaded through the Kit Data Prep population tool."
                        integration_owner = "PBB Realtime"
                        issuer_identity = "acceptance.local"
                        token_issuance_mode = "app_backend_signed"
                        trusted_signing_profile = "acceptance"
                        allowed_origins = @($AppBaseUrl)
                        origin_policy_mode = "allowlist"
                        policies = @(
                            @{
                                policy_code = "pol_ACCEPTANCE_OPERATOR"
                                name = "Acceptance Operator Policy"
                                status = "active"
                                policy_category = "acceptance"
                                owner_team = "PBB Realtime"
                                capability_profile = @{
                                    rooms = @("join", "leave", "publish")
                                    presence = @("publish", "subscribe")
                                    chat = @("publish", "subscribe")
                                    media = @("request", "stream")
                                    call = @("signal")
                                    events = @("publish")
                                }
                                room_policy_profile = @{
                                    mode = "allowlist"
                                    prefixes = @("installer-acceptance-")
                                }
                                allow_deny_mode = "allowlist"
                            }
                        )
                        projects = @(
                            @{
                                project_code = "prj_ACCEPTANCE_OPERATOR"
                                name = "Acceptance Operator"
                                status = "active"
                                description = "Acceptance websocket sandbox project."
                                allowed_origins = @($AppBaseUrl)
                                origin_policy_mode = "allowlist"
                                policy_profile_code = "pol_ACCEPTANCE_OPERATOR"
                                capability_profile_code = "acceptance-operator"
                                room_policy_profile_code = "acceptance-rooms"
                            }
                        )
                    }
                )
            }
        }
        admin = @{
            name = "Installer Acceptance Admin"
            email = $AdminEmail
            password = $AdminPassword
        }
        service = @{
            target_os = $targetOs
            service_manager = $serviceManager
            startup_mode = "manual"
            registration_mode = "template"
            allow_existing_install = $true
            allow_finish_with_failed_validation = $false
        }
    }

    Write-Host "[acceptance] running install"
    $installPayload = (@{ config = $InstallConfig } | ConvertTo-Json -Depth 10)
    $installHttp = $null
    $installRaw = $null
    try {
        $installHttp = Invoke-WebRequest -UseBasicParsing -Uri "$InstallerBaseUrl/installer/api/install-run.php" -Method Post -ContentType "application/json" -Body $installPayload
        $installRaw = $installHttp.Content
    } catch {
        if ($_.Exception.Response -and $_.Exception.Response.GetResponseStream) {
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            try {
                $installRaw = $reader.ReadToEnd()
            } finally {
                $reader.Close()
            }
            $statusCode = [int]$_.Exception.Response.StatusCode
            throw ("Installer returned HTTP {0}: {1}" -f $statusCode, $installRaw)
        }
        throw
    }
    try {
        $installResponse = $installRaw | ConvertFrom-Json
    } catch {
        throw "Installer returned a non-JSON response: $installRaw"
    }
    $installStatus = ""
    if ($null -ne $installResponse.state -and $null -ne $installResponse.state.install -and $null -ne $installResponse.state.install.status) {
        $installStatus = [string]$installResponse.state.install.status
    }
    if ($installStatus -ne "completed") {
        throw "Installer did not complete successfully. Raw response: $installRaw"
    }
    $Result.checks.install_completed = $true

    Write-Host "[acceptance] populating sandbox fixture through Data Prep tool"
    $populateConfigPath = Join-Path $ExtractRoot "storage\\app\\installer\\acceptance-populate.json"
    $populateReportPath = Join-Path $ExtractRoot "storage\\app\\installer\\acceptance-populate-report.json"
    [System.IO.File]::WriteAllText($populateConfigPath, ($InstallConfig | ConvertTo-Json -Depth 20), [System.Text.UTF8Encoding]::new($false))
    Push-Location $ExtractRoot
    try {
        $populateOutput = & $PhpBin "tools\\populate-initial-data.php" --config $populateConfigPath --report $populateReportPath --mode initial 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw ("Population tool failed: " + ($populateOutput -join "`n"))
        }
    } finally {
        Pop-Location
    }
    $Result.checks.populate_initial_data_completed = $true

    Write-Host "[acceptance] starting Laravel app server on $AppBaseUrl"
    $AppProcess = Start-Process -FilePath $PhpBin -ArgumentList @("artisan", "serve", "--host=127.0.0.1", "--port=$AppPort") -WorkingDirectory $ExtractRoot -PassThru -WindowStyle Hidden
    Wait-HttpReady "$AppBaseUrl/api/health"
    $Result.checks.app_http_open = $true

    Write-Host "[acceptance] running validation"
    $validationResponse = Invoke-RestMethod -Uri "$InstallerBaseUrl/installer/api/validate-run.php" -Method Post -ContentType "application/json" -Body $installPayload
    $failedValidation = @($validationResponse.validation | Where-Object { $_.status -ne "pass" -and $_.key -notin @("ws_bind_target", "service_artifact") })
    if ($failedValidation.Count -gt 0) {
        throw "Validation returned unexpected failures: $((($failedValidation | ForEach-Object { $_.key + '=' + $_.message }) -join '; '))"
    }
    $Result.checks.validation_completed = $true

    Write-Host "[acceptance] starting websocket runtime on $WebsocketUrl"
    $env:RATCHET_DISABLE_XDEBUG_WARN = "1"
    $WsProcess = Start-Process -FilePath $PhpBin -ArgumentList @("artisan", "realtime:serve", "--host=127.0.0.1", "--port=$WsPort") -WorkingDirectory $ExtractRoot -PassThru -WindowStyle Hidden
    Wait-TcpReady -HostName "127.0.0.1" -Port $WsPort
    $Result.checks.websocket_runtime_started = $true

    Write-Host "[acceptance] logging into admin"
    $webSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrfResponse = Invoke-RestMethod -Uri "$AppBaseUrl/api/admin/csrf-token" -WebSession $webSession
    $csrfToken = $csrfResponse.data.csrfToken
    $loginResponse = Invoke-RestMethod -Uri "$AppBaseUrl/api/admin/login" -Method Post -WebSession $webSession -Headers @{
        "X-CSRF-TOKEN" = $csrfToken
        "Accept" = "application/json"
    } -ContentType "application/json" -Body (@{
        email = $AdminEmail
        password = $AdminPassword
    } | ConvertTo-Json)
    if (-not $loginResponse.status) {
        throw "Admin login did not succeed."
    }
    $Result.checks.admin_login_usable = $true

    Write-Host "[acceptance] loading sandbox context"
    $sandboxPage = Invoke-WebRequest -Uri "$AppBaseUrl/admin/sandbox" -WebSession $webSession -UseBasicParsing
    if ($sandboxPage.StatusCode -lt 200 -or $sandboxPage.StatusCode -ge 400) {
        throw "Sandbox page is not reachable after install."
    }

    $bootstrapResponse = Invoke-RestMethod -Uri "$AppBaseUrl/api/admin/bootstrap" -WebSession $webSession
    $csrfToken = $bootstrapResponse.security.csrfToken

    $contextResponse = Invoke-RestMethod -Uri "$AppBaseUrl/api/admin/sandbox/context" -WebSession $webSession
    $client = $contextResponse.data.clients | Select-Object -First 1
    if ($null -eq $client) {
        throw "Sandbox context returned no visible clients."
    }
    $project = $client.projects | Select-Object -First 1
    if ($null -eq $project) {
        throw "Sandbox context returned no visible projects."
    }

    Write-Host "[acceptance] issuing sandbox admission"
    $admissionResponse = Invoke-RestMethod -Uri "$AppBaseUrl/api/admin/sandbox/admission" -Method Post -WebSession $webSession -Headers @{
        "X-CSRF-TOKEN" = $csrfToken
        "Accept" = "application/json"
    } -ContentType "application/json" -Body (@{
        client_code = $client.client_code
        project_code = $project.project_code
        display_name = "Installer Acceptance User"
        user_id = "installer_acceptance_$Timestamp"
        room = "installer-acceptance-room"
    } | ConvertTo-Json)

    $token = $admissionResponse.data.token
    $effectiveRoom = $admissionResponse.data.effective_room
    if ([string]::IsNullOrWhiteSpace($token) -or [string]::IsNullOrWhiteSpace($effectiveRoom)) {
        throw "Sandbox admission did not return a realtime token and room."
    }
    $Result.checks.sandbox_admission_issued = $true

    Write-Host "[acceptance] connecting websocket and running transport roundtrip"
    $socket = [System.Net.WebSockets.ClientWebSocket]::new()
    try {
        $socket.Options.SetRequestHeader("Origin", $AppBaseUrl)
        $socketUri = [Uri]($WebsocketUrl + "?token=" + [Uri]::EscapeDataString($token))
        $socket.ConnectAsync($socketUri, [System.Threading.CancellationToken]::None).GetAwaiter().GetResult()

        Send-WebSocketJson -Socket $socket -Object @{
            namespace = "pbb.realtime.v1"
            phase = "request"
            id = "req_join"
            type = "room.join.request"
            room = $effectiveRoom
            payload = @{}
            meta = @{}
        }

        $joinAck = Wait-WebSocketEnvelope -Socket $socket -Predicate {
            param($envelope)
            $envelope.phase -eq "ack" -and $envelope.type -eq "room.join.request"
        }
        if (-not $joinAck.payload.joined) {
            throw "Room join acknowledgement did not confirm joined=true."
        }

        Send-WebSocketJson -Socket $socket -Object @{
            namespace = "pbb.realtime.v1"
            phase = "request"
            id = "req_presence"
            type = "presence.publish"
            room = $effectiveRoom
            payload = @{
                state = "online"
                status_text = "Installer acceptance online"
            }
            meta = @{}
        }

        $presenceAck = Wait-WebSocketEnvelope -Socket $socket -Predicate {
            param($envelope)
            $envelope.phase -eq "ack" -and $envelope.type -eq "presence.publish"
        }
        if (-not $presenceAck.payload.published) {
            throw "Presence publish acknowledgement did not confirm published=true."
        }

        Send-WebSocketJson -Socket $socket -Object @{
            namespace = "pbb.realtime.v1"
            phase = "request"
            id = "req_chat"
            type = "chat.message.publish"
            room = $effectiveRoom
            payload = @{
                text = "Installer acceptance smoke message"
            }
            meta = @{}
        }

        $chatAck = Wait-WebSocketEnvelope -Socket $socket -Predicate {
            param($envelope)
            $envelope.phase -eq "ack" -and $envelope.type -eq "chat.message.publish"
        }
        if (-not $chatAck.payload.published) {
            throw "Chat publish acknowledgement did not confirm published=true."
        }

        $Result.checks.websocket_transport_roundtrip = $true
        $Result.checks.sandbox_connect_successful = $true
    }
    finally {
        if ($socket.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
            $socket.CloseAsync([System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure, "done", [System.Threading.CancellationToken]::None).GetAwaiter().GetResult()
        }
        $socket.Dispose()
    }

    $Result.status = "pass"
    $Result.accepted = $true
}
catch {
    $Result.status = "fail"
    $Result.accepted = $false
    $Result.error = $_.Exception.Message
    throw
}
finally {
    $Result.finished_at = (Get-Date).ToString("o")
    $Result.installer_port = $InstallerPort
    $Result.app_port = $AppPort
    $Result.ws_port = $WsPort
    $Result.extract_root = $ExtractRoot
    $Result.temp_database = $TempDbName

    $Result | ConvertTo-Json -Depth 10 | Set-Content -Path $ReportPath -Encoding UTF8

    Stop-ProcessIfPresent $WsProcess
    Stop-ProcessIfPresent $AppProcess
    Stop-ProcessIfPresent $InstallerProcess
    Stop-PhpProcessesForPath -Path $ExtractRoot
    Remove-Item Env:RATCHET_DISABLE_XDEBUG_WARN -ErrorAction SilentlyContinue

    if ($TempDbName) {
        $dropDbResult = Remove-AcceptanceDatabase -EnvValues $envValues -DatabaseName $TempDbName
    }

    if ($Result.accepted -and -not $KeepArtifacts) {
        Remove-InstallerAcceptanceExtracts
        Remove-OldInstallerZips -KeepLatest $ZipRetention
    } elseif ($KeepArtifacts) {
        Write-Host "[cleanup] keeping acceptance artifacts because -KeepArtifacts was passed"
    }
}

Write-Host "Acceptance report written to: $ReportPath"
