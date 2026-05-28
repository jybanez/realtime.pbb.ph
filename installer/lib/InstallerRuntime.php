<?php

final class InstallerRuntime
{
    private const STATE_FILE = 'state.json';
    private const LOG_FILE = 'install.log';
    private const REPORT_FILE = 'install-report.json';
    private const MANIFEST_FILE = 'install-manifest.json';
    private const COMPLETION_FILE = 'completed.json';
    private const RELEASE_SNAPSHOT_FILE = 'release.json';
    private const GENERATED_DIR = 'generated';

    public static function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function storageDir(): string
    {
        return self::rootPath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'installer';
    }

    public static function ensureStorageDir(): void
    {
        $dir = self::storageDir();

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public static function ensureRuntimeDirectories(): void
    {
        $root = self::rootPath();
        $directories = [
            'storage',
            'storage' . DIRECTORY_SEPARATOR . 'app',
            'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public',
            'storage' . DIRECTORY_SEPARATOR . 'framework',
            'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache',
            'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'data',
            'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'sessions',
            'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'testing',
            'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'views',
            'storage' . DIRECTORY_SEPARATOR . 'logs',
            'bootstrap' . DIRECTORY_SEPARATOR . 'cache',
        ];

        foreach ($directories as $directory) {
            $path = $root . DIRECTORY_SEPARATOR . $directory;
            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    public static function statePath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    public static function logPath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . self::LOG_FILE;
    }

    public static function reportPath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . self::REPORT_FILE;
    }

    public static function manifestPath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
    }

    public static function completionPath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . self::COMPLETION_FILE;
    }

    public static function releaseMetadataPath(): string
    {
        return self::rootPath() . DIRECTORY_SEPARATOR . 'release.json';
    }

    public static function releaseSnapshotPath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . self::RELEASE_SNAPSHOT_FILE;
    }

    public static function generatedDir(): string
    {
        $dir = self::storageDir() . DIRECTORY_SEPARATOR . self::GENERATED_DIR;

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function backupDir(): string
    {
        $dir = self::generatedDir() . DIRECTORY_SEPARATOR . 'backups';

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function loadState(): array
    {
        self::ensureStorageDir();

        $path = self::statePath();

        if (! is_file($path)) {
            return self::defaultState();
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return self::defaultState();
        }

        return array_replace_recursive(self::defaultState(), $decoded);
    }

    public static function saveState(array $state): array
    {
        self::ensureStorageDir();

        $normalized = array_replace_recursive(self::defaultState(), $state);
        file_put_contents(
            self::statePath(),
            json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $normalized;
    }

    public static function appendLog(string $message, string $level = 'info'): void
    {
        self::ensureStorageDir();

        $line = sprintf(
            "[%s] %s: %s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            PHP_EOL
        );

        file_put_contents(self::logPath(), $line, FILE_APPEND);
    }

    public static function loadLog(): string
    {
        $path = self::logPath();

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public static function writeReport(array $report): array
    {
        self::ensureStorageDir();

        $report = self::withAcceptanceStamp($report);

        file_put_contents(
            self::reportPath(),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $report;
    }

    public static function summarizeValidation(array $validation): array
    {
        $total = count($validation);
        $failed = array_values(array_filter($validation, static fn (array $item): bool => ($item['status'] ?? 'fail') !== 'pass'));
        $passedCount = $total - count($failed);

        return [
            'total' => $total,
            'passed' => $passedCount,
            'failed' => count($failed),
            'failed_items' => array_map(
                static fn (array $item): array => [
                    'key' => $item['key'] ?? null,
                    'label' => $item['label'] ?? 'Unknown check',
                    'message' => $item['message'] ?? '',
                    'status' => $item['status'] ?? 'fail',
                ],
                $failed
            ),
        ];
    }

    public static function withAcceptanceStamp(array $report): array
    {
        $validation = is_array($report['validation'] ?? null) ? $report['validation'] : [];
        $summary = self::summarizeValidation($validation);
        $overrideUsed = (bool) ($report['acceptance']['override_used'] ?? false);

        $status = 'pending';
        $message = 'Validation has not been run yet.';

        if ($summary['total'] > 0 && $summary['failed'] === 0) {
            $status = 'pass';
            $message = 'Validation passed with no failing checks.';
        } elseif ($summary['total'] > 0 && $summary['failed'] > 0 && $overrideUsed) {
            $status = 'warn';
            $message = 'Validation still has failures, but finish override was used.';
        } elseif ($summary['total'] > 0 && $summary['failed'] > 0) {
            $status = 'fail';
            $message = 'Validation still has failures and should not be treated as accepted.';
        }

        $report['acceptance'] = array_merge($report['acceptance'] ?? [], [
            'status' => $status,
            'message' => $message,
            'override_used' => $overrideUsed,
            'summary' => $summary,
        ]);

        return $report;
    }

    public static function loadReport(): array
    {
        $path = self::reportPath();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function writeManifest(array $manifest): array
    {
        self::ensureStorageDir();

        file_put_contents(
            self::manifestPath(),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $manifest;
    }

    public static function writeReleaseSnapshot(): array
    {
        self::ensureStorageDir();

        $status = self::releaseMetadataStatus();
        if (! $status['readable']) {
            return [
                'status' => 'skipped',
                'reason' => $status['reason'],
                'source' => self::releaseMetadataPath(),
                'snapshot' => self::releaseSnapshotPath(),
            ];
        }

        copy(self::releaseMetadataPath(), self::releaseSnapshotPath());
        self::appendLog('Release metadata snapshot refreshed at ' . self::releaseSnapshotPath());

        return [
            'status' => 'success',
            'source' => self::releaseMetadataPath(),
            'snapshot' => self::releaseSnapshotPath(),
            'version' => $status['version'],
            'build_id' => $status['build_id'],
            'build_git_commit' => $status['build_git_commit'],
        ];
    }

    public static function loadManifest(): array
    {
        $path = self::manifestPath();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function releaseMetadata(): array
    {
        $path = self::releaseMetadataPath();

        if (! is_file($path)) {
            return [
                'schema_version' => 1,
                'app' => 'pbb-realtime',
                'name' => 'PBB Realtime',
                'version' => '0.0.0-dev',
                'display_version' => 'dev',
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function releaseMetadataStatus(): array
    {
        $path = self::releaseMetadataPath();

        if (! is_file($path)) {
            return [
                'readable' => false,
                'reason' => 'missing',
                'path' => $path,
                'message' => 'Installed release.json is missing.',
                'version' => null,
                'build_id' => null,
                'build_git_commit' => null,
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'readable' => false,
                'reason' => 'invalid_json',
                'path' => $path,
                'message' => 'Installed release.json is not valid JSON.',
                'version' => null,
                'build_id' => null,
                'build_git_commit' => null,
            ];
        }

        $app = (string) ($decoded['app'] ?? '');
        $version = (string) ($decoded['version'] ?? '');
        if ($app === '' || $version === '') {
            return [
                'readable' => false,
                'reason' => 'missing_identity',
                'path' => $path,
                'message' => 'Installed release.json is readable but missing app/version identity.',
                'version' => $version !== '' ? $version : null,
                'build_id' => $decoded['build']['id'] ?? null,
                'build_git_commit' => $decoded['build']['git_commit'] ?? null,
            ];
        }

        return [
            'readable' => true,
            'reason' => 'ok',
            'path' => $path,
            'message' => 'Installed release.json is readable.',
            'app' => $app,
            'version' => $version,
            'build_id' => $decoded['build']['id'] ?? null,
            'build_git_commit' => $decoded['build']['git_commit'] ?? null,
        ];
    }

    public static function restoreReleaseMetadata(): array
    {
        $current = self::releaseMetadataStatus();
        if ($current['readable']) {
            $snapshot = self::writeReleaseSnapshot();

            return [
                'status' => 'skipped',
                'reason' => 'already_readable',
                'current' => $current,
                'snapshot' => $snapshot,
            ];
        }

        self::ensureStorageDir();

        $snapshotPath = self::releaseSnapshotPath();
        if (is_file($snapshotPath)) {
            $decoded = json_decode((string) file_get_contents($snapshotPath), true);
            if (is_array($decoded) && ! empty($decoded['app']) && ! empty($decoded['version'])) {
                copy($snapshotPath, self::releaseMetadataPath());
                self::appendLog('Release metadata restored from installer snapshot.');

                return [
                    'status' => 'restored',
                    'source' => 'installer_snapshot',
                    'path' => self::releaseMetadataPath(),
                    'version' => (string) $decoded['version'],
                    'build_id' => $decoded['build']['id'] ?? null,
                    'build_git_commit' => $decoded['build']['git_commit'] ?? null,
                    'previous_reason' => $current['reason'],
                ];
            }
        }

        $manifest = self::loadManifest();
        $completion = self::loadCompletionMarker();
        $version = (string) ($manifest['version'] ?? $completion['version'] ?? self::appVersion());
        if ($version === '' || $version === '0.0.0-dev') {
            return [
                'status' => 'failed',
                'reason' => 'no_repair_source',
                'path' => self::releaseMetadataPath(),
                'previous_reason' => $current['reason'],
                'message' => 'No readable release snapshot or installed manifest version was available.',
            ];
        }

        $release = [
            'schema_version' => 1,
            'app' => 'pbb-realtime',
            'name' => 'PBB Realtime',
            'version' => $version,
            'display_version' => (string) ($manifest['display_version'] ?? ('v1-' . $version)),
            'build' => [
                'version' => $version,
                'id' => $manifest['build_id'] ?? null,
                'built_at' => null,
                'git_commit' => $manifest['build_git_commit'] ?? null,
                'builder' => 'installer-repair',
            ],
            'update' => [
                'contract_version' => 1,
                'channel' => 'testing',
                'immutable_release' => false,
                'from_versions' => [$version],
                'compatibility' => 'repair',
                'requires_database_migration' => false,
                'requires_data_prep_rerun' => true,
                'requires_service_restart' => true,
                'rollback_supported' => true,
            ],
            'repair' => [
                'restored_from' => 'install_manifest',
                'restored_at' => date(DATE_ATOM),
                'previous_reason' => $current['reason'],
            ],
        ];

        file_put_contents(self::releaseMetadataPath(), json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        copy(self::releaseMetadataPath(), self::releaseSnapshotPath());
        self::appendLog('Release metadata reconstructed from installed manifest.');

        return [
            'status' => 'restored',
            'source' => 'install_manifest',
            'path' => self::releaseMetadataPath(),
            'version' => $version,
            'build_id' => $release['build']['id'],
            'build_git_commit' => $release['build']['git_commit'],
            'previous_reason' => $current['reason'],
        ];
    }

    public static function appVersion(): string
    {
        $release = self::releaseMetadata();

        return (string) ($release['version'] ?? '0.0.0-dev');
    }

    public static function writeCompletionMarker(array $payload): array
    {
        self::ensureStorageDir();

        file_put_contents(
            self::completionPath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $payload;
    }

    public static function loadCompletionMarker(): array
    {
        $path = self::completionPath();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function configTemplate(): array
    {
        $targetOs = stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'windows' : 'linux';

        return [
            'mode' => 'fresh',
            'app' => [
                'install_path' => self::rootPath(),
                'app_url' => 'https://realtime.example.local',
                'app_env' => 'production',
                'app_debug' => false,
                'run_seeders' => false,
            ],
            'database' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'pbb_realtime',
                'username' => 'root',
                'password' => '',
            ],
            'realtime' => [
                'service_name' => 'PBB Realtime',
                'token_audience' => 'pbb-realtime',
                'token_signing_secret' => '',
                'trusted_issuers' => 'hub.example.local',
                'public_websocket_url' => 'wss://realtime.example.local/realtime',
                'ws_bind_address' => '127.0.0.1',
                'ws_port' => 8080,
                'allowed_origins' => 'https://hq.example.local',
                'embedded_media_chunk_dispatch_enabled' => false,
            ],
            'admin' => [
                'strategy' => 'create_if_missing',
                'name' => 'PBB Administrator',
                'email' => 'admin@pbb.local',
                'password' => '',
                'must_change_password' => false,
                'overwrite_existing' => false,
            ],
            'service' => [
                'target_os' => $targetOs,
                'service_manager' => $targetOs === 'windows' ? 'scheduled-task' : 'systemd',
                'startup_mode' => 'automatic',
                'registration_mode' => 'template',
                'allow_existing_install' => false,
                'allow_finish_with_failed_validation' => false,
            ],
        ];
    }

    public static function defaultState(): array
    {
        return [
            'current_step' => 'welcome',
            'completed_steps' => [],
            'config' => self::configTemplate(),
            'preflight' => [],
            'install' => [],
            'validation' => [],
            'last_updated_at' => null,
            'locked' => false,
            'completion_marker' => self::loadCompletionMarker(),
        ];
    }

    public static function saveConfig(array $incoming): array
    {
        $state = self::loadState();
        $state['config'] = array_replace_recursive($state['config'], $incoming);
        $state['last_updated_at'] = date(DATE_ATOM);

        return self::saveState($state);
    }

    public static function buildPreflightChecks(array $config): array
    {
        self::ensureRuntimeDirectories();

        $requiredExtensions = ['openssl', 'pdo', 'pdo_mysql', 'mbstring', 'json', 'fileinfo'];
        $root = self::rootPath();
        $storage = $root . DIRECTORY_SEPARATOR . 'storage';
        $cache = $root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache';
        $envPath = $root . DIRECTORY_SEPARATOR . '.env';
        $wsUrl = (string) ($config['realtime']['public_websocket_url'] ?? '');
        $secret = (string) ($config['realtime']['token_signing_secret'] ?? '');
        $installPath = (string) ($config['app']['install_path'] ?? '');
        $dbHost = (string) ($config['database']['host'] ?? '');
        $dbName = (string) ($config['database']['database'] ?? '');
        $dbUser = (string) ($config['database']['username'] ?? '');
        $dbPassword = (string) ($config['database']['password'] ?? '');
        $dbPort = (int) ($config['database']['port'] ?? 3306);
        $mysqlBinaryCheck = self::checkMysqlBinary($config);
        $wsPort = (int) ($config['realtime']['ws_port'] ?? 8080);
        $releaseMetadata = self::releaseMetadataStatus();

        $checks = [];
        $checks[] = self::check('php_version', 'PHP version', version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION);

        foreach ($requiredExtensions as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = self::check('ext_' . $extension, "PHP extension: {$extension}", $loaded, $loaded ? 'Loaded' : 'Missing', ! $loaded);
        }

        $checks[] = self::check('install_path', 'Install path safety', self::isSafeInstallPath($installPath), $installPath ?: 'Missing install path');
        $installRootCheck = self::checkInstallPathMatchesRoot($installPath);
        $checks[] = self::check('install_path_matches_root', 'Install path matches package root', $installRootCheck['ok'], $installRootCheck['message']);
        $checks[] = self::check('install_path_writable', 'Install path writable', is_dir($installPath) && is_writable($installPath), $installPath ?: 'Missing install path');
        $checks[] = self::check('storage_writable', 'Storage writable', is_dir($storage) && is_writable($storage), $storage);
        $checks[] = self::check('cache_writable', 'Bootstrap cache writable', is_dir($cache) && is_writable($cache), $cache);
        $checks[] = self::check('env_writable', '.env writable or creatable', (! is_file($envPath) && is_writable($root)) || (is_file($envPath) && is_writable($envPath)), $envPath);
        $checks[] = self::check('release_metadata', 'Installed release metadata', $releaseMetadata['readable'], $releaseMetadata['message'], false);
        $checks[] = self::check('ws_url', 'Public websocket URL', (bool) filter_var($wsUrl, FILTER_VALIDATE_URL), $wsUrl ?: 'Missing URL');
        $checks[] = self::check('token_secret', 'Token signing secret', self::isNonPlaceholderSecret($secret), self::maskSecret($secret), true);
        $checks[] = self::check('ws_port', 'Websocket port availability', self::isTcpPortAvailable($wsPort), "Port {$wsPort}", false);
        $checks[] = self::check('db_connection', 'Database connectivity', self::canConnectToDatabase($dbHost, $dbPort, $dbName, $dbUser, $dbPassword), "{$dbHost}:{$dbPort}/{$dbName}");
        $checks[] = self::check('platform_mysql_binary', 'Kit-provided MySQL client binary', $mysqlBinaryCheck['ok'], $mysqlBinaryCheck['message'], true);

        return $checks;
    }

    public static function hasBlockingFailures(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['blocking'] ?? false) && ($check['status'] ?? 'fail') !== 'pass') {
                return true;
            }
        }

        return false;
    }

    public static function normalizeImportedConfig(array $payload): array
    {
        $template = self::configTemplate();
        $payload = self::normalizeKitConfig($payload);
        $normalized = array_replace_recursive($template, $payload);
        $normalized['app']['run_seeders'] = false;
        unset($normalized['app']['seed_command']);

        if (isset($normalized['realtime']['trusted_issuers']) && is_array($normalized['realtime']['trusted_issuers'])) {
            $normalized['realtime']['trusted_issuers'] = implode(',', array_filter(array_map('trim', $normalized['realtime']['trusted_issuers'])));
        }

        if (isset($normalized['realtime']['allowed_origins']) && is_array($normalized['realtime']['allowed_origins'])) {
            $normalized['realtime']['allowed_origins'] = implode(',', self::normalizeOriginList($normalized['realtime']['allowed_origins']));
        }

        return $normalized;
    }

    public static function normalizeKitConfig(array $payload): array
    {
        if (isset($payload['services']) && is_array($payload['services'])) {
            $services = $payload['services'];
            $payload['service'] = array_replace_recursive($payload['service'] ?? [], [
                'target_os' => $services['target_os'] ?? null,
                'service_manager' => $services['manager'] ?? null,
                'startup_mode' => $services['startup_mode'] ?? null,
                'registration_mode' => $services['registration_mode'] ?? null,
            ]);
            unset($payload['services']);
        }

        if (isset($payload['database']['driver']) && ! isset($payload['database']['connection'])) {
            unset($payload['database']['driver']);
        }

        if (isset($payload['options']) && is_array($payload['options'])) {
            $payload['app'] = array_replace_recursive($payload['app'] ?? [], [
                'run_seeders' => false,
            ]);
        }

        $registrationMode = strtolower((string) ($payload['service']['registration_mode'] ?? ''));
        if (in_array($registrationMode, ['generate', 'manual'], true)) {
            $payload['service']['registration_mode'] = 'template';
        }

        if (isset($payload['realtime']['trusted_issuers']) && is_array($payload['realtime']['trusted_issuers'])) {
            $payload['realtime']['trusted_issuers'] = implode(',', array_filter(array_map('trim', $payload['realtime']['trusted_issuers'])));
        }

        if (isset($payload['realtime']['allowed_origins']) && is_array($payload['realtime']['allowed_origins'])) {
            $payload['realtime']['allowed_origins'] = implode(',', self::normalizeOriginList($payload['realtime']['allowed_origins']));
        }

        if (isset($payload['admin']) && is_array($payload['admin'])) {
            $payload['admin']['strategy'] = (string) ($payload['admin']['strategy'] ?? 'create_if_missing');
            $payload['admin']['overwrite_existing'] = (bool) ($payload['admin']['overwrite_existing'] ?? false);
            $payload['admin']['must_change_password'] = (bool) ($payload['admin']['must_change_password'] ?? false);
        }

        return $payload;
    }

    public static function validateDraftConfig(array $config): array
    {
        $errors = [];

        if (trim((string) ($config['app']['install_path'] ?? '')) === '') {
            $errors['app.install_path'] = 'Install path is required.';
        }

        if (! filter_var((string) ($config['app']['app_url'] ?? ''), FILTER_VALIDATE_URL)) {
            $errors['app.app_url'] = 'APP_URL must be a valid URL.';
        }

        if (trim((string) ($config['database']['host'] ?? '')) === '') {
            $errors['database.host'] = 'Database host is required.';
        }

        if (trim((string) ($config['database']['database'] ?? '')) === '') {
            $errors['database.database'] = 'Database name is required.';
        }

        if (trim((string) ($config['database']['username'] ?? '')) === '') {
            $errors['database.username'] = 'Database username is required.';
        }

        if (trim((string) ($config['realtime']['service_name'] ?? '')) === '') {
            $errors['realtime.service_name'] = 'Realtime service name is required.';
        }

        $targetOs = strtolower((string) ($config['service']['target_os'] ?? ''));
        if (! in_array($targetOs, ['windows', 'linux'], true)) {
            $errors['service.target_os'] = 'Target OS must be windows or linux.';
        }

        $serviceManager = strtolower((string) ($config['service']['service_manager'] ?? ''));
        $allowedManagers = $targetOs === 'linux'
            ? ['systemd']
            : ['windows-service', 'scheduled-task'];
        if ($serviceManager === '' || ! in_array($serviceManager, $allowedManagers, true)) {
            $errors['service.service_manager'] = 'Service manager is not valid for the selected target OS.';
        }

        $registrationMode = strtolower((string) ($config['service']['registration_mode'] ?? 'template'));
        if (! in_array($registrationMode, ['template', 'register'], true)) {
            $errors['service.registration_mode'] = 'Registration mode must be template or register.';
        }

        if (! filter_var((string) ($config['realtime']['public_websocket_url'] ?? ''), FILTER_VALIDATE_URL)) {
            $errors['realtime.public_websocket_url'] = 'Public websocket URL must be valid.';
        }

        if (! self::isNonPlaceholderSecret((string) ($config['realtime']['token_signing_secret'] ?? ''))) {
            $errors['realtime.token_signing_secret'] = 'Token signing secret is required and must not be a placeholder.';
        }

        if (trim((string) ($config['admin']['name'] ?? '')) === '') {
            $errors['admin.name'] = 'Admin name is required.';
        }

        if (! filter_var((string) ($config['admin']['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $errors['admin.email'] = 'Admin email must be valid.';
        }

        if (trim((string) ($config['admin']['password'] ?? '')) === '') {
            $errors['admin.password'] = 'Admin password is required.';
        } elseif (! self::isStrongAdminPassword((string) ($config['admin']['password'] ?? ''))) {
            $errors['admin.password'] = 'Admin password must be non-placeholder and at least 10 characters with letters and numbers.';
        }

        $strategy = strtolower((string) ($config['admin']['strategy'] ?? 'create_if_missing'));
        if (! in_array($strategy, ['create_if_missing'], true)) {
            $errors['admin.strategy'] = 'Admin strategy must be create_if_missing.';
        }

        if (! empty($config['app']['run_seeders'])) {
            $errors['app.run_seeders'] = 'Database seeders are source-only and are not included in the Kit installer bundle. Use the declared populate_initial_data tool for Data Prep instead.';
        }

        return $errors;
    }

    public static function environmentPath(array $config): string
    {
        $installPath = rtrim((string) ($config['app']['install_path'] ?? self::rootPath()), '\\/');

        return $installPath . DIRECTORY_SEPARATOR . '.env';
    }

    public static function environmentExamplePath(array $config): string
    {
        $installPath = rtrim((string) ($config['app']['install_path'] ?? self::rootPath()), '\\/');

        return $installPath . DIRECTORY_SEPARATOR . '.env.example';
    }

    public static function writeEnvironment(array $config): array
    {
        $envPath = self::environmentPath($config);
        $envExamplePath = self::environmentExamplePath($config);

        if (! is_dir(dirname($envPath))) {
            throw new RuntimeException('Install path does not exist: ' . dirname($envPath));
        }

        $mode = strtolower((string) ($config['mode'] ?? 'fresh'));
        $baseContent = '';
        $baseSource = 'env_template';
        if (in_array($mode, ['upgrade', 'repair'], true) && is_file($envPath)) {
            $baseContent = (string) file_get_contents($envPath);
            $baseSource = 'existing_env';
        } elseif (is_file($envExamplePath)) {
            $baseContent = (string) file_get_contents($envExamplePath);
        } elseif (is_file($envPath)) {
            $baseContent = (string) file_get_contents($envPath);
            $baseSource = 'existing_env';
        }

        if ($baseContent === '') {
            throw new RuntimeException('Could not locate .env.example or existing .env in install path.');
        }

        $existing = is_file($envPath) ? self::parseEnvString((string) file_get_contents($envPath)) : [];
        $appKey = trim((string) ($existing['APP_KEY'] ?? ''));
        $generatedAppKey = false;

        if ($appKey === '') {
            $appKey = self::generateAppKey();
            $generatedAppKey = true;
        }

        $realtime = $config['realtime'] ?? [];
        $database = $config['database'] ?? [];
        $app = $config['app'] ?? [];
        $publicWebsocketHost = self::derivePublicWebsocketHost($config);

        $values = [
            'APP_NAME' => $realtime['service_name'] ?? 'PBB Realtime',
            'APP_ENV' => $app['app_env'] ?? 'production',
            'APP_DEBUG' => ! empty($app['app_debug']) ? 'true' : 'false',
            'APP_URL' => $app['app_url'] ?? '',
            'APP_KEY' => $appKey,
            'DB_HOST' => $database['host'] ?? '127.0.0.1',
            'DB_PORT' => (string) ($database['port'] ?? 3306),
            'DB_DATABASE' => $database['database'] ?? '',
            'DB_USERNAME' => $database['username'] ?? '',
            'DB_PASSWORD' => (string) ($database['password'] ?? ''),
            'REALTIME_SERVICE_NAME' => $realtime['service_name'] ?? 'PBB Realtime',
            'REALTIME_TOKEN_AUDIENCE' => $realtime['token_audience'] ?? 'pbb-realtime',
            'REALTIME_TOKEN_SIGNING_SECRET' => (string) ($realtime['token_signing_secret'] ?? ''),
            'REALTIME_TRUSTED_ISSUERS' => (string) ($realtime['trusted_issuers'] ?? ''),
            'REALTIME_PUBLIC_WEBSOCKET_URL' => (string) ($realtime['public_websocket_url'] ?? ''),
            'REALTIME_WS_PUBLIC_HOST' => $publicWebsocketHost,
            'REALTIME_WS_BIND_ADDRESS' => (string) ($realtime['ws_bind_address'] ?? '127.0.0.1'),
            'REALTIME_WS_PORT' => (string) ($realtime['ws_port'] ?? 8080),
            'REALTIME_ALLOWED_ORIGINS' => implode(',', self::deriveAllowedOrigins($config)),
            'REALTIME_EMBEDDED_MEDIA_CHUNK_DISPATCH_ENABLED' => ! empty($realtime['embedded_media_chunk_dispatch_enabled']) ? 'true' : 'false',
        ];

        $maestro = is_array($config['dependencies']['maestro'] ?? null) ? $config['dependencies']['maestro'] : [];
        if ($maestro !== []) {
            $values = array_merge($values, [
                'MAESTRO_TELEMETRY_ENABLED' => 'true',
                'MAESTRO_BASE_URL' => (string) ($maestro['base_url'] ?? ''),
                'MAESTRO_TELEMETRY_TOKEN' => (string) ($maestro['telemetry_token'] ?? ''),
                'MAESTRO_TELEMETRY_APP_CODE' => (string) ($maestro['app_code'] ?? 'realtime'),
            ]);
        }

        $backupPath = null;
        if (is_file($envPath)) {
            $backupPath = $envPath . '.bak.' . date('YmdHis');
            copy($envPath, $backupPath);
        }

        $updated = self::replaceEnvValues($baseContent, $values);
        file_put_contents($envPath, $updated);

        self::appendLog('.env written to ' . $envPath);
        if ($backupPath) {
            self::appendLog('Existing .env backed up to ' . $backupPath);
        }
        if ($generatedAppKey) {
            self::appendLog('APP_KEY generated for installer target.');
        }

        return [
            'path' => $envPath,
            'backup_path' => $backupPath,
            'generated_app_key' => $generatedAppKey,
            'app_key' => $appKey,
            'base_source' => $baseSource,
        ];
    }

    public static function serviceTemplateFile(array $config): string
    {
        $targetOs = strtolower((string) ($config['service']['target_os'] ?? 'windows'));
        $root = dirname(__DIR__);

        if ($targetOs === 'linux') {
            return $root . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'realtime-ratchet.service.template';
        }

        return $root . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'realtime-ratchet-service.template.ps1';
    }

    public static function renderServiceTemplate(array $config): array
    {
        $templatePath = self::serviceTemplateFile($config);
        $contents = is_file($templatePath) ? (string) file_get_contents($templatePath) : '';
        $installPath = rtrim((string) ($config['app']['install_path'] ?? self::rootPath()), '\\/');
        $serviceName = (string) ($config['realtime']['service_name'] ?? 'PBB Realtime');
        $logPath = self::logPath();
        $targetOs = strtolower((string) ($config['service']['target_os'] ?? 'windows'));

        $rendered = strtr($contents, [
            '{{PHP_BINARY}}' => self::phpBinary(),
            '{{INSTALL_PATH}}' => $installPath,
            '{{SERVICE_NAME}}' => $serviceName,
            '{{LOG_PATH}}' => $logPath,
        ]);

        return [
            'target_os' => $targetOs,
            'service_manager' => (string) ($config['service']['service_manager'] ?? ($targetOs === 'linux' ? 'systemd' : 'windows-service')),
            'template_path' => $templatePath,
            'artifact_path' => self::serviceArtifactPath($config),
            'contents' => $rendered,
        ];
    }

    public static function serviceArtifactPath(array $config): string
    {
        $targetOs = strtolower((string) ($config['service']['target_os'] ?? 'windows'));
        $suffix = $targetOs === 'linux' ? 'service' : 'ps1';

        return self::generatedDir() . DIRECTORY_SEPARATOR . 'realtime-ratchet-service.' . $suffix;
    }

    public static function writeServiceArtifact(array $config): array
    {
        $template = self::renderServiceTemplate($config);
        $artifactPath = $template['artifact_path'];

        file_put_contents($artifactPath, $template['contents']);
        self::appendLog('Service artifact generated at ' . $artifactPath);

        return [
            'target_os' => $template['target_os'],
            'service_manager' => $template['service_manager'],
            'template_path' => $template['template_path'],
            'artifact_path' => $artifactPath,
        ];
    }

    public static function writeWindowsLauncher(array $config): ?array
    {
        $targetOs = strtolower((string) ($config['service']['target_os'] ?? 'windows'));
        if ($targetOs !== 'windows') {
            return null;
        }

        $installPath = rtrim((string) ($config['app']['install_path'] ?? self::rootPath()), '\\/');
        $bindAddress = (string) ($config['realtime']['ws_bind_address'] ?? '127.0.0.1');
        $port = (int) ($config['realtime']['ws_port'] ?? 8080);
        $launcherPath = self::generatedDir() . DIRECTORY_SEPARATOR . 'realtime-ratchet-launcher.cmd';
        $logPath = self::logPath();

        $contents = implode("\r\n", [
            '@echo off',
            'cd /d "' . $installPath . '"',
            'set "RATCHET_DISABLE_XDEBUG_WARN=1"',
            '"' . self::phpBinary() . '" artisan realtime:serve --host=' . $bindAddress . ' --port=' . $port . ' >> "' . $logPath . '" 2>&1',
            '',
        ]);

        file_put_contents($launcherPath, $contents);
        self::appendLog('Windows runtime launcher generated at ' . $launcherPath);

        return [
            'launcher_path' => $launcherPath,
            'command' => '"' . $launcherPath . '"',
        ];
    }

    public static function backupUpgradeArtifacts(array $config): array
    {
        $timestamp = date('YmdHis');
        $backupRoot = self::backupDir() . DIRECTORY_SEPARATOR . 'upgrade-' . $timestamp;
        mkdir($backupRoot, 0775, true);

        $installPath = rtrim((string) ($config['app']['install_path'] ?? self::rootPath()), '\\/');
        $targets = [
            '.env',
            'composer.json',
            'composer.lock',
            'artisan',
            'app',
            'bootstrap',
            'config',
            'routes',
        ];

        $backedUp = [];
        foreach ($targets as $target) {
            $source = $installPath . DIRECTORY_SEPARATOR . $target;
            if (! file_exists($source)) {
                continue;
            }

            $destination = $backupRoot . DIRECTORY_SEPARATOR . $target;
            self::copyPath($source, $destination);
            $backedUp[] = $destination;
        }

        $serviceArtifact = self::serviceArtifactPath($config);
        if (is_file($serviceArtifact)) {
            $destination = $backupRoot . DIRECTORY_SEPARATOR . basename($serviceArtifact);
            copy($serviceArtifact, $destination);
            $backedUp[] = $destination;
        }

        self::appendLog('Upgrade backup created at ' . $backupRoot);

        return [
            'backup_root' => $backupRoot,
            'files' => $backedUp,
        ];
    }

    public static function serviceInstructions(array $config, array $serviceArtifact): array
    {
        $targetOs = strtolower((string) ($serviceArtifact['target_os'] ?? ($config['service']['target_os'] ?? 'windows')));
        $manager = (string) ($serviceArtifact['service_manager'] ?? ($config['service']['service_manager'] ?? ''));
        $artifact = (string) ($serviceArtifact['artifact_path'] ?? self::serviceArtifactPath($config));

        if ($targetOs === 'linux') {
            return [
                'Copy the generated unit file into /etc/systemd/system.',
                'Run `systemctl daemon-reload`.',
                sprintf('Enable and start the service using systemd. Artifact: %s', $artifact),
            ];
        }

        if ($manager === 'scheduled-task') {
            return [
                'Review the generated PowerShell artifact and adapt it for a scheduled startup task.',
                sprintf('Register the task to run `php artisan realtime:serve` from the install path. Artifact: %s', $artifact),
                'Configure the task to restart or alert on failure.',
            ];
        }

        return [
            'Review the generated PowerShell service artifact.',
            sprintf('Register the Ratchet runtime as a Windows service using the generated artifact. Artifact: %s', $artifact),
            'Configure automatic restart and persistent logging.',
        ];
    }

    public static function serviceUnitName(array $config): string
    {
        $serviceName = strtolower((string) ($config['realtime']['service_name'] ?? 'pbb-realtime'));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $serviceName) ?: 'pbb-realtime';
        return trim($slug, '-') ?: 'pbb-realtime';
    }

    public static function runOptionalSeeders(array $config): ?array
    {
        if (empty($config['app']['run_seeders'])) {
            self::appendLog('Optional seeding skipped.');
            return null;
        }

        throw new RuntimeException('Database seeders are not included in the Kit installer bundle. Use the declared populate_initial_data tool for Data Prep instead.');
    }

    public static function registerServiceRuntime(array $config, array $serviceArtifact): array
    {
        $registrationMode = strtolower((string) ($config['service']['registration_mode'] ?? 'template'));
        $targetOs = strtolower((string) ($config['service']['target_os'] ?? 'windows'));
        $manager = strtolower((string) ($serviceArtifact['service_manager'] ?? ($config['service']['service_manager'] ?? '')));
        $serviceName = self::serviceUnitName($config);

        $result = [
            'registration_mode' => $registrationMode,
            'target_os' => $targetOs,
            'service_manager' => $manager,
            'service_name' => $serviceName,
            'attempted' => false,
            'registered' => false,
            'status' => 'skipped',
            'message' => 'Registration mode is template only.',
            'commands' => [],
        ];

        if ($registrationMode !== 'register') {
            return $result;
        }

        if (! self::hostMatchesTargetOs($targetOs)) {
            $result['message'] = 'Host OS does not match the selected target OS. Registration was not attempted.';
            return $result;
        }

        $result['attempted'] = true;

        if ($targetOs === 'linux' && $manager === 'systemd') {
            $unitName = $serviceName . '.service';
            $unitTarget = '/etc/systemd/system/' . $unitName;
            if (! @copy($serviceArtifact['artifact_path'], $unitTarget)) {
                $result['status'] = 'failed';
                $result['message'] = 'Unable to copy the generated unit file into /etc/systemd/system.';
                return $result;
            }

            $commands = [
                self::runHostCommand(['systemctl', 'daemon-reload']),
            ];

            if (($config['service']['startup_mode'] ?? 'automatic') === 'automatic') {
                $commands[] = self::runHostCommand(['systemctl', 'enable', '--now', $unitName]);
            } else {
                $commands[] = self::runHostCommand(['systemctl', 'start', $unitName]);
            }

            $result['commands'] = $commands;
            $failed = array_values(array_filter($commands, static fn (array $command): bool => ($command['exit_code'] ?? 1) !== 0));
            $result['registered'] = $failed === [];
            $result['status'] = $result['registered'] ? 'registered' : 'failed';
            $result['message'] = $result['registered']
                ? 'systemd unit registered successfully.'
                : 'systemd registration failed.';

            return $result;
        }

        if ($targetOs === 'windows' && $manager === 'scheduled-task') {
            $launcher = self::writeWindowsLauncher($config);
            $taskName = $serviceName;
            $taskAction = $launcher['command'] ?? '';
            $createCommand = [
                'schtasks',
                '/Create',
                '/TN',
                $taskName,
                '/SC',
                'ONSTART',
                '/TR',
                $taskAction,
                '/F',
            ];

            $commands = [
                self::runHostCommand($createCommand),
            ];

            if (($config['service']['startup_mode'] ?? 'automatic') !== 'automatic') {
                $commands[] = self::runHostCommand(['schtasks', '/Change', '/TN', $taskName, '/DISABLE']);
            }

            $result['commands'] = $commands;
            $failed = array_values(array_filter($commands, static fn (array $command): bool => ($command['exit_code'] ?? 1) !== 0));
            $result['registered'] = $failed === [];
            $result['status'] = $result['registered'] ? 'registered' : 'failed';
            $result['message'] = $result['registered']
                ? 'Scheduled task registered successfully.'
                : 'Scheduled task registration failed.';
            $result['launcher_path'] = $launcher['launcher_path'] ?? null;

            return $result;
        }

        $result['status'] = 'manual-only';
        $result['message'] = 'The selected service manager does not support automatic registration in this installer yet.';

        return $result;
    }

    public static function checkServiceRegistration(array $config): array
    {
        $targetOs = strtolower((string) ($config['service']['target_os'] ?? 'windows'));
        $manager = strtolower((string) ($config['service']['service_manager'] ?? ($targetOs === 'linux' ? 'systemd' : 'scheduled-task')));
        $serviceName = self::serviceUnitName($config);

        if ($targetOs === 'linux' && $manager === 'systemd') {
            $unitName = $serviceName . '.service';
            $unitTarget = '/etc/systemd/system/' . $unitName;

            return [
                'registered' => is_file($unitTarget),
                'message' => is_file($unitTarget)
                    ? 'systemd unit file present at ' . $unitTarget
                    : 'systemd unit file missing at ' . $unitTarget,
            ];
        }

        if ($targetOs === 'windows' && $manager === 'scheduled-task') {
            $query = self::runHostCommand(['schtasks', '/Query', '/TN', $serviceName]);

            return [
                'registered' => ($query['exit_code'] ?? 1) === 0,
                'message' => trim((string) ($query['stdout'] ?: $query['stderr'] ?: 'Scheduled task not found.')),
            ];
        }

        return [
            'registered' => false,
            'message' => 'Automatic registration is not supported for the selected service manager.',
        ];
    }

    public static function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function artisanPath(array $config): string
    {
        $installPath = rtrim((string) ($config['app']['install_path'] ?? self::rootPath()), '\\/');

        return $installPath . DIRECTORY_SEPARATOR . 'artisan';
    }

    public static function phpBinary(): string
    {
        return PHP_BINARY;
    }

    public static function runArtisan(array $config, array $arguments): array
    {
        $artisan = self::artisanPath($config);

        if (! is_file($artisan)) {
            throw new RuntimeException('Could not locate artisan in install path: ' . $artisan);
        }

        $parts = array_merge([self::phpBinary(), $artisan], $arguments);
        $command = implode(' ', array_map('escapeshellarg', $parts));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            dirname($artisan),
            self::artisanEnvironment($config)
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start artisan process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::appendLog('Artisan command: ' . implode(' ', $arguments));
        if ($stdout !== false && trim($stdout) !== '') {
            self::appendLog(trim($stdout));
        }
        if ($stderr !== false && trim($stderr) !== '') {
            self::appendLog(trim($stderr), $exitCode === 0 ? 'warn' : 'error');
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
        ];
    }

    private static function artisanEnvironment(array $config): array
    {
        $environment = getenv();
        if (! is_array($environment)) {
            $environment = $_ENV;
        }
        $path = (string) (getenv('PATH') ?: getenv('Path') ?: '');
        $mysqlBinary = self::resolveMysqlBinary($config, false);
        $clientBin = $mysqlBinary !== null ? dirname($mysqlBinary) : null;

        if ($clientBin !== null && ! str_contains(strtolower($path), strtolower($clientBin))) {
            $path = $clientBin . PATH_SEPARATOR . $path;
        }

        $environment['PATH'] = $path;
        $environment['Path'] = $path;
        if ($mysqlBinary !== null) {
            $environment['PBB_MYSQL_BINARY'] = $mysqlBinary;
        }

        return $environment;
    }

    private static function checkMysqlBinary(array $config): array
    {
        try {
            $binary = self::resolveMysqlBinary($config, true);

            return [
                'ok' => true,
                'message' => $binary,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private static function resolveMysqlBinary(array $config, bool $required): ?string
    {
        $configured = trim((string) ($config['platform']['mysql_binary'] ?? ''));
        $environment = trim((string) (getenv('PBB_MYSQL_BINARY') ?: ''));
        $binary = $configured !== '' ? $configured : $environment;

        if ($binary === '') {
            if ($required) {
                throw new RuntimeException('Kit did not provide platform.mysql_binary / PBB_MYSQL_BINARY.');
            }

            return null;
        }

        if (! is_file($binary)) {
            throw new RuntimeException('Kit-provided MySQL client binary does not exist: ' . $binary);
        }

        return $binary;
    }

    public static function runMigrations(array $config): array
    {
        $recovery = self::recoverFreshInstallMigrationResidue($config);
        self::resolveMysqlBinary($config, true);
        $result = self::runArtisan($config, ['migrate', '--force']);

        if (($result['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException('Database migrations failed: ' . trim((string) ($result['stderr'] ?: $result['stdout'])));
        }

        $result['recovery'] = $recovery;
        $result['schema_strategy'] = self::detectMigrationSchemaStrategy($result);
        $result['baseline_schema'] = self::baselineSchemaRelativePath();
        $result['baseline_schema_used'] = $result['schema_strategy'] === 'baseline_schema';
        $result['migration_rows'] = self::countMigrationRows($config);
        $result['upgrade_strategy'] = 'laravel_migrations';
        $result['database_setup'] = [
            'strategy' => $result['schema_strategy'],
            'baseline_schema' => $result['baseline_schema'],
            'baseline_schema_used' => $result['baseline_schema_used'],
            'migration_rows' => $result['migration_rows'],
            'upgrade_strategy' => $result['upgrade_strategy'],
        ];

        return $result;
    }

    public static function refreshRuntimeCaches(array $config): array
    {
        $commands = [
            ['optimize:clear'],
            ['config:cache'],
        ];
        $results = [];

        foreach ($commands as $arguments) {
            $result = self::runArtisan($config, $arguments);
            $results[] = [
                'command' => 'php artisan ' . implode(' ', $arguments),
                'exit_code' => $result['exit_code'] ?? 1,
                'stdout' => $result['stdout'] ?? '',
                'stderr' => $result['stderr'] ?? '',
            ];

            if (($result['exit_code'] ?? 1) !== 0) {
                throw new RuntimeException('Runtime cache refresh failed: ' . trim((string) (($result['stderr'] ?? '') ?: ($result['stdout'] ?? ''))));
            }
        }

        self::appendLog('Runtime caches refreshed with optimize:clear and config:cache.');

        return [
            'status' => 'success',
            'commands' => $results,
        ];
    }

    public static function rollbackSupportSummary(): array
    {
        return [
            'supported' => true,
            'scope' => 'file-and-config-artifact backup',
            'notes' => 'Upgrade mode backs up installer-managed release files, .env, and service artifacts before mutation. Database rollback is not automatic; bundles that require irreversible schema or data migrations must declare rollback_supported=false in release.json.',
        ];
    }

    public static function baselineSchemaRelativePath(): string
    {
        return 'database/schema/mysql-schema.sql';
    }

    private static function detectMigrationSchemaStrategy(array $result): string
    {
        $output = (string) (($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? ''));

        if (stripos($output, 'Loading stored database schemas') !== false) {
            return 'baseline_schema';
        }

        return 'migrations';
    }

    private static function countMigrationRows(array $config): int
    {
        $database = $config['database'] ?? [];
        $host = (string) ($database['host'] ?? '');
        $dbName = (string) ($database['database'] ?? '');
        $username = (string) ($database['username'] ?? '');
        $password = (string) ($database['password'] ?? '');
        $port = (int) ($database['port'] ?? 3306);

        if ($host === '' || $dbName === '' || $username === '') {
            return 0;
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$dbName}",
                $username,
                $password,
                [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            if (! self::mysqlTableExists($pdo, $dbName, 'migrations')) {
                return 0;
            }

            return (int) $pdo->query('SELECT COUNT(*) FROM `migrations`')->fetchColumn();
        } catch (Throwable $e) {
            self::appendLog('Migration row count probe failed: ' . $e->getMessage(), 'warn');

            return 0;
        }
    }

    public static function recoverFreshInstallMigrationResidue(array $config): array
    {
        if (self::loadManifest() !== [] || ! empty(self::loadCompletionMarker()['installed_at'])) {
            return ['status' => 'skipped', 'reason' => 'installed_marker_present'];
        }

        $mode = (string) ($config['mode'] ?? 'fresh');
        if ($mode === 'upgrade') {
            return ['status' => 'skipped', 'reason' => 'upgrade_mode'];
        }

        $database = $config['database'] ?? [];
        $host = (string) ($database['host'] ?? '');
        $dbName = (string) ($database['database'] ?? '');
        $username = (string) ($database['username'] ?? '');
        $password = (string) ($database['password'] ?? '');
        $port = (int) ($database['port'] ?? 3306);

        if ($host === '' || $dbName === '' || $username === '') {
            return ['status' => 'skipped', 'reason' => 'database_config_incomplete'];
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$dbName}",
                $username,
                $password,
                [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $actions = [];
            $droppedTables = [];
            $droppedColumns = [];

            $usageMigration = '2026_03_31_000300_create_realtime_usage_buckets_table';
            if (
                self::mysqlTableExists($pdo, $dbName, 'realtime_usage_buckets')
                && ! self::mysqlMigrationIsRecorded($pdo, $dbName, $usageMigration)
            ) {
                $pdo->exec('DROP TABLE `realtime_usage_buckets`');
                self::appendLog('Dropped partial realtime_usage_buckets table before fresh migration retry.', 'warn');
                $actions[] = 'dropped partial realtime_usage_buckets table';
                $droppedTables[] = 'realtime_usage_buckets';
            }

            $serverEventsMigration = '2026_04_06_141500_add_backend_ingress_secret_hash_to_realtime_clients_and_create_realtime_server_events_table';
            if (! self::mysqlMigrationIsRecorded($pdo, $dbName, $serverEventsMigration)) {
                if (self::mysqlTableExists($pdo, $dbName, 'realtime_server_events')) {
                    $pdo->exec('DROP TABLE `realtime_server_events`');
                    self::appendLog('Dropped partial realtime_server_events table before fresh migration retry.', 'warn');
                    $actions[] = 'dropped partial realtime_server_events table';
                    $droppedTables[] = 'realtime_server_events';
                }

                if (self::mysqlColumnExists($pdo, $dbName, 'realtime_clients', 'backend_ingress_secret_hash')) {
                    $pdo->exec('ALTER TABLE `realtime_clients` DROP COLUMN `backend_ingress_secret_hash`');
                    self::appendLog('Dropped partial realtime_clients.backend_ingress_secret_hash column before fresh migration retry.', 'warn');
                    $actions[] = 'dropped partial realtime_clients.backend_ingress_secret_hash column';
                    $droppedColumns[] = 'realtime_clients.backend_ingress_secret_hash';
                }
            }

            if ($actions !== []) {
                return [
                    'status' => 'recovered',
                    'actions' => $actions,
                    'dropped_tables' => $droppedTables,
                    'dropped_columns' => $droppedColumns,
                ];
            }

            return ['status' => 'clean'];
        } catch (Throwable $e) {
            self::appendLog('Fresh migration residue recovery skipped: ' . $e->getMessage(), 'warn');

            return ['status' => 'skipped', 'reason' => 'database_probe_failed'];
        }
    }

    public static function bootstrapAdmin(array $config): array
    {
        $admin = $config['admin'] ?? [];
        $name = (string) ($admin['name'] ?? '');
        $email = (string) ($admin['email'] ?? '');
        $password = (string) ($admin['password'] ?? '');
        $strategy = strtolower((string) ($admin['strategy'] ?? 'create_if_missing'));
        $overwriteExisting = (bool) ($admin['overwrite_existing'] ?? false);

        if ($name === '' || $email === '' || $password === '') {
            throw new RuntimeException('Admin bootstrap requires name, email, and password.');
        }

        if ($strategy !== 'create_if_missing') {
            throw new RuntimeException('Unsupported admin bootstrap strategy: ' . $strategy);
        }

        if (! self::isStrongAdminPassword($password)) {
            throw new RuntimeException('Admin bootstrap password must be non-placeholder and at least 10 characters with letters and numbers.');
        }

        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$user = App\Models\User::query()->where('email', $argv[1])->first();
$created = false;
$updated = false;
$overwrite = filter_var($argv[4] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($user === null) {
    $user = new App\Models\User();
    $user->email = $argv[1];
    $user->name = $argv[2];
    $user->password = $argv[3];
    $user->is_operator = true;
    $user->user_type = 'admin';
    $user->save();
    $created = true;
} elseif ($overwrite) {
    $user->name = $argv[2];
    $user->password = $argv[3];
    $user->is_operator = true;
    $user->user_type = 'admin';
    $user->save();
    $updated = true;
}

echo json_encode([
    'id' => $user->id,
    'email' => $user->email,
    'strategy' => 'create_if_missing',
    'created' => $created,
    'updated' => $updated,
    'overwrite_existing' => $overwrite,
], JSON_UNESCAPED_SLASHES);
PHP;

        $result = self::runPhpSnippet($config, $script, [$email, $name, $password, $overwriteExisting ? 'true' : 'false']);
        $stdout = $result['stdout'];
        $stderr = $result['stderr'];
        $exitCode = $result['exit_code'];

        self::appendLog('Admin bootstrap executed for ' . $email);
        if ($stdout !== false && trim($stdout) !== '') {
            self::appendLog(trim($stdout));
        }
        if ($stderr !== false && trim($stderr) !== '') {
            self::appendLog(trim($stderr), $exitCode === 0 ? 'warn' : 'error');
        }

        if ($exitCode !== 0) {
            throw new RuntimeException('Admin bootstrap failed: ' . trim((string) ($stderr ?: $stdout)));
        }

        $decoded = json_decode((string) $stdout, true);

        return is_array($decoded) ? $decoded : [
            'email' => $email,
            'created' => false,
        ];
    }

    public static function validateInstalledState(array $config): array
    {
        $results = [];
        $appUrl = (string) ($config['app']['app_url'] ?? '');
        $adminEmail = (string) ($config['admin']['email'] ?? '');
        $releaseMetadata = self::releaseMetadataStatus();

        $results[] = self::check(
            'release_metadata',
            'Installed release metadata',
            $releaseMetadata['readable'],
            $releaseMetadata['message']
        );

        $httpProbe = self::probeHttpUrl($appUrl);
        $results[] = self::check('http_url', 'HTTP app reachability', $httpProbe['ok'], $httpProbe['message'], false);

        $healthProbe = self::probeHttpEndpoints([
            rtrim($appUrl, '/') . '/api/health',
            rtrim($appUrl, '/') . '/health',
        ]);
        $results[] = self::check('health_url', 'Health endpoint reachability', $healthProbe['ok'], $healthProbe['message'], false);

        $readyProbe = self::probeHttpEndpoints([
            rtrim($appUrl, '/') . '/api/ready',
            rtrim($appUrl, '/') . '/ready',
        ]);
        $results[] = self::check('ready_url', 'Readiness endpoint reachability', $readyProbe['ok'], $readyProbe['message'], false);

        $migrateStatus = self::runArtisan($config, ['migrate:status', '--no-ansi']);
        $results[] = self::check(
            'migrate_status',
            'Database schema presence',
            ($migrateStatus['exit_code'] ?? 1) === 0,
            trim((string) ($migrateStatus['stdout'] ?: $migrateStatus['stderr']))
        );

        $pendingMigrations = self::detectPendingMigrations($config);
        $results[] = self::check(
            'pending_migrations',
            'Pending migrations',
            ! $pendingMigrations['pending'],
            $pendingMigrations['message'],
            false
        );

        $adminCheck = self::checkAdminExists($config, $adminEmail);
        $results[] = self::check(
            'admin_account',
            'Admin account presence',
            $adminCheck['exists'],
            $adminCheck['message']
        );

        $ratchetCheck = self::runArtisan($config, ['realtime:serve', '--help']);
        $results[] = self::check(
            'ratchet_command',
            'Ratchet command startability',
            ($ratchetCheck['exit_code'] ?? 1) === 0,
            trim((string) ($ratchetCheck['stdout'] ?: $ratchetCheck['stderr'])),
            false
        );

        $wsBind = self::probeTcpTarget(
            (string) ($config['realtime']['ws_bind_address'] ?? '127.0.0.1'),
            (int) ($config['realtime']['ws_port'] ?? 8080)
        );

        $results[] = self::check(
            'ws_bind_target',
            'Websocket bind target',
            $wsBind['ok'],
            $wsBind['message'],
            false
        );

        $serviceArtifactPath = self::serviceArtifactPath($config);
        $results[] = self::check(
            'service_artifact',
            'Service registration artifact',
            is_file($serviceArtifactPath),
            is_file($serviceArtifactPath)
                ? 'Generated artifact present at ' . $serviceArtifactPath
                : 'Service artifact missing: ' . $serviceArtifactPath,
            false
        );

        $registrationMode = strtolower((string) ($config['service']['registration_mode'] ?? 'template'));
        if ($registrationMode === 'register') {
            $serviceRegistration = self::checkServiceRegistration($config);
            $results[] = self::check(
                'service_registration',
                'Runtime startup registration',
                $serviceRegistration['registered'],
                $serviceRegistration['message'],
                false
            );
        }

        return $results;
    }

    public static function kitCheckStatus(string $status): string
    {
        return $status === 'pass' ? 'passed' : 'failed';
    }

    public static function buildKitPreflight(array $checks): array
    {
        $failed = array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? 'fail') !== 'pass'));

        return [
            'schema_version' => 1,
            'app' => 'pbb-realtime',
            'status' => $failed === [] ? 'passed' : 'failed',
            'checks' => array_map(static fn (array $check): array => [
                'id' => str_replace('_', '.', (string) ($check['key'] ?? 'unknown')),
                'label' => (string) ($check['label'] ?? 'Unknown check'),
                'status' => self::kitCheckStatus((string) ($check['status'] ?? 'fail')),
                'message' => (string) ($check['message'] ?? ''),
                'blocking' => (bool) ($check['blocking'] ?? true),
            ], $checks),
        ];
    }

    public static function buildKitManifest(array $config, array $details = []): array
    {
        $serviceArtifact = $details['service_artifact'] ?? self::renderServiceTemplate($config);
        $database = $config['database'] ?? [];
        $app = $config['app'] ?? [];
        $webServer = self::webServerRequirements($config);
        $runtimeServices = self::runtimeServices($config);
        $releaseMetadata = self::releaseMetadataStatus();

        return [
            'schema_version' => 1,
            'app' => 'pbb-realtime',
            'name' => 'PBB Realtime',
            'version' => self::appVersion(),
            'display_version' => (string) (self::releaseMetadata()['display_version'] ?? ''),
            'build_id' => $releaseMetadata['build_id'] ?? null,
            'build_git_commit' => $releaseMetadata['build_git_commit'] ?? null,
            'installed_at' => date(DATE_ATOM),
            'install_mode' => (string) ($config['mode'] ?? 'fresh'),
            'install_path' => (string) ($app['install_path'] ?? self::rootPath()),
            'public_path' => (string) ($app['public_path'] ?? $app['install_path'] ?? self::rootPath()),
            'app_url' => (string) ($app['app_url'] ?? ''),
            'environment' => (string) ($app['app_env'] ?? 'production'),
            'php_binary' => self::phpBinary(),
            'database' => [
                'driver' => 'mysql',
                'host' => (string) ($database['host'] ?? ''),
                'port' => (int) ($database['port'] ?? 3306),
                'database' => (string) ($database['database'] ?? ''),
                'username' => (string) ($database['username'] ?? ''),
            ],
            'services' => self::serviceDefinitions($config, $serviceArtifact),
            'runtime_services' => $runtimeServices,
            'web_server' => $webServer,
            'web_server_requirements' => $webServer['requirements'],
            'release_metadata' => $releaseMetadata,
            'release_metadata_snapshot' => $details['release_metadata_snapshot'] ?? null,
            'health' => [
                'last_checked_at' => $details['health_checked_at'] ?? null,
                'status' => $details['health_status'] ?? 'unknown',
            ],
        ];
    }

    public static function buildKitReport(array $config, string $status, array $steps, array $details = []): array
    {
        $appUrl = rtrim((string) ($config['app']['app_url'] ?? ''), '/');
        $validation = is_array($details['validation'] ?? null) ? $details['validation'] : [];
        $warnings = array_values($details['warnings'] ?? []);
        $errors = array_values($details['errors'] ?? []);
        $webServer = self::webServerRequirements($config);
        $runtimeServices = self::runtimeServices($config);
        $releaseMetadata = self::releaseMetadataStatus();

        foreach ($validation as $check) {
            if (($check['status'] ?? 'fail') !== 'pass' && ! (bool) ($check['blocking'] ?? true)) {
                $warnings[] = [
                    'id' => $check['key'] ?? 'validation',
                    'message' => $check['message'] ?? 'Validation warning.',
                ];
            }
        }

        return [
            'schema_version' => 1,
            'app' => 'pbb-realtime',
            'version' => self::appVersion(),
            'release_metadata' => $releaseMetadata,
            'run_id' => (string) ($config['kit']['run_id'] ?? ''),
            'mode' => (string) ($config['mode'] ?? 'fresh'),
            'status' => $status,
            'started_at' => $details['started_at'] ?? null,
            'finished_at' => date(DATE_ATOM),
            'summary' => $details['summary'] ?? self::installModeSummary((string) ($config['mode'] ?? 'fresh')),
            'steps' => $steps,
            'urls' => [
                'app' => $appUrl,
                'health' => $appUrl !== '' ? $appUrl . '/api/health' : '',
                'ready' => $appUrl !== '' ? $appUrl . '/api/ready' : '',
                'websocket' => (string) ($config['realtime']['public_websocket_url'] ?? ''),
            ],
            'services' => self::serviceDefinitions($config, $details['service_artifact'] ?? self::renderServiceTemplate($config)),
            'runtime_services' => $runtimeServices,
            'web_server' => $webServer,
            'web_server_requirements' => $webServer['requirements'],
            'warnings' => $warnings,
            'errors' => $errors,
            'artifacts' => [
                'install_manifest' => self::manifestPath(),
                'install_report' => self::reportPath(),
                'install_log' => self::logPath(),
            ],
        ];
    }

    public static function runtimeServices(array $config): array
    {
        $realtime = $config['realtime'] ?? [];
        $installPath = rtrim((string) ($config['app']['install_path'] ?? self::rootPath()), '\\/');
        $bindAddress = trim((string) ($realtime['ws_bind_address'] ?? '127.0.0.1'));
        $host = $bindAddress !== '' ? $bindAddress : '127.0.0.1';
        $publicHost = self::derivePublicWebsocketHost($config);
        $port = (int) ($realtime['ws_port'] ?? 8080);
        $port = $port > 0 ? $port : 8080;

        return [[
            'id' => 'pbb-realtime-websocket',
            'name' => 'PBB Realtime WebSocket',
            'type' => 'background_process',
            'required' => true,
            'required_for_smoke' => true,
            'manager' => 'kit',
            'working_directory' => $installPath,
            'command' => self::phpBinary(),
            'args' => [
                'artisan',
                'realtime:serve',
            ],
            'env' => [
                'REALTIME_WS_PUBLIC_HOST' => $publicHost,
                'REALTIME_EMBEDDED_MEDIA_CHUNK_DISPATCH_ENABLED' => 'false',
            ],
            'health_check' => [
                'type' => 'tcp',
                'host' => $host,
                'port' => $port,
                'timeout_seconds' => 3,
            ],
            'logs' => [
                'stdout' => 'storage/logs/pbb-realtime-websocket.out.log',
                'stderr' => 'storage/logs/pbb-realtime-websocket.err.log',
            ],
            'notes' => 'Kit starts and verifies this before public websocket smoke checks.',
        ], [
            'id' => 'pbb-realtime-media-dispatcher',
            'name' => 'PBB Realtime Media Dispatcher',
            'type' => 'background_process',
            'required' => true,
            'required_for_smoke' => false,
            'manager' => 'kit',
            'working_directory' => $installPath,
            'command' => self::phpBinary(),
            'args' => [
                'artisan',
                'realtime:dispatch',
            ],
            'health_check' => [
                'type' => 'process',
                'timeout_seconds' => 3,
            ],
            'logs' => [
                'stdout' => 'storage/logs/pbb-realtime-media-dispatcher.out.log',
                'stderr' => 'storage/logs/pbb-realtime-media-dispatcher.err.log',
            ],
            'notes' => 'Kit starts this alongside the websocket daemon when embedded media chunk dispatch is disabled.',
        ]];
    }

    public static function webServerRequirements(array $config): array
    {
        $realtime = $config['realtime'] ?? [];
        $bindAddress = trim((string) ($realtime['ws_bind_address'] ?? '127.0.0.1'));
        $port = (int) ($realtime['ws_port'] ?? 8080);
        $serverPath = '/realtime';
        $upstreamHost = $bindAddress !== '' ? $bindAddress : '127.0.0.1';
        $upstreamUrl = sprintf('ws://%s:%d%s', $upstreamHost, $port > 0 ? $port : 8080, $serverPath);

        return [
            'owner' => 'kit-setup',
            'requirements' => [[
                'id' => 'pbb-realtime-websocket-proxy',
                'type' => 'websocket_proxy',
                'server_path' => $serverPath,
                'path_prefix' => $serverPath,
                'upstream_url' => $upstreamUrl,
                'public_url' => (string) ($realtime['public_websocket_url'] ?? ''),
                'required_modules' => ['proxy', 'proxy_wstunnel'],
                'headers' => [
                    'Upgrade' => '$http_upgrade',
                    'Connection' => 'upgrade',
                ],
                'directives' => [
                    'ProxyWebsocketFallbackToProxyHttp' => 'Off',
                ],
                'set_env' => new stdClass(),
                'smoke_test' => [
                    'auth_required' => false,
                    'path' => $serverPath,
                    'query' => new stdClass(),
                    'headers' => [
                        'Host' => self::derivePublicWebsocketHost($config),
                        'Origin' => rtrim((string) ($config['app']['app_url'] ?? ''), '/'),
                    ],
                    'expect_status' => 101,
                    'expect_first_message_type' => 'session.awaiting-auth',
                ],
                'install_blocking' => false,
                'smoke_test_phase' => 'post-vhost',
                'smoke_test_owner' => 'kit-setup',
                'app_installer_validation' => 'local_service_readiness_only',
            ]],
        ];
    }

    public static function serviceDefinitions(array $config, array $serviceArtifact = []): array
    {
        $installPath = rtrim((string) ($config['app']['install_path'] ?? self::rootPath()), '\\/');
        $artifactPath = (string) ($serviceArtifact['artifact_path'] ?? self::serviceArtifactPath($config));
        $manager = (string) ($config['service']['service_manager'] ?? '');
        $registered = false;
        if (strtolower((string) ($config['service']['registration_mode'] ?? 'template')) === 'register') {
            $registered = self::checkServiceRegistration($config)['registered'] ?? false;
        }

        $services = [[
            'id' => 'pbb-realtime-websocket',
            'name' => 'PBB Realtime WebSocket',
            'kind' => 'daemon',
            'command' => 'php artisan realtime:serve',
            'working_directory' => $installPath,
            'env_file' => $installPath . DIRECTORY_SEPARATOR . '.env',
            'log_file' => self::logPath(),
            'startup_mode' => (string) ($config['service']['startup_mode'] ?? 'automatic'),
            'restart_policy' => 'always',
            'manager' => $manager,
            'registered' => $registered,
            'artifact' => $artifactPath,
            'healthcheck' => [
                'type' => 'http',
                'path' => '/api/metrics',
            ],
        ]];

        if (array_key_exists('embedded_media_chunk_dispatch_enabled', $config['realtime'] ?? []) && ! (bool) $config['realtime']['embedded_media_chunk_dispatch_enabled']) {
            $services[] = [
                'id' => 'pbb-realtime-media-dispatcher',
                'name' => 'PBB Realtime Media Dispatcher',
                'kind' => 'worker',
                'command' => 'php artisan realtime:dispatch',
                'working_directory' => $installPath,
                'env_file' => $installPath . DIRECTORY_SEPARATOR . '.env',
                'log_file' => self::logPath(),
                'startup_mode' => (string) ($config['service']['startup_mode'] ?? 'automatic'),
                'restart_policy' => 'always',
                'manager' => $manager,
                'registered' => false,
                'artifact' => null,
                'healthcheck' => [
                    'type' => 'maestro',
                    'app_code' => 'realtime',
                    'max_stale_seconds' => 60,
                ],
            ];
        }

        return $services;
    }

    public static function buildStatus(array $config = []): array
    {
        self::ensureRuntimeDirectories();

        $state = self::loadState();
        $manifest = self::loadManifest();
        $report = self::loadReport();
        $config = $config !== [] ? $config : ($state['config'] ?? self::configTemplate());
        $completion = self::loadCompletionMarker();
        $releaseMetadata = self::releaseMetadataStatus();
        $validation = is_array($state['validation'] ?? null) ? $state['validation'] : [];
        $failedValidation = array_values(array_filter($validation, static fn (array $check): bool => ($check['status'] ?? 'fail') !== 'pass' && (bool) ($check['blocking'] ?? true)));
        $installed = ! empty($completion['installed_at']) || $manifest !== [];

        $status = 'not-installed';
        if ($installed && ! $releaseMetadata['readable']) {
            $status = 'degraded';
        } elseif ($installed && $failedValidation === []) {
            $status = 'healthy';
        } elseif ($installed && $failedValidation !== []) {
            $status = 'degraded';
        } elseif (($state['install']['status'] ?? '') === 'failed') {
            $status = 'failed';
        }

        return [
            'schema_version' => 1,
            'app' => 'pbb-realtime',
            'version' => (string) ($manifest['version'] ?? self::appVersion()),
            'release_metadata' => $releaseMetadata,
            'repair_actions' => ! $releaseMetadata['readable'] ? [[
                'id' => 'release_metadata',
                'label' => 'Restore release metadata',
                'reason' => $releaseMetadata['message'],
            ]] : [],
            'installed' => $installed,
            'status' => $status,
            'mode' => $installed ? 'installed' : 'new',
            'installed_at' => $completion['installed_at'] ?? $manifest['installed_at'] ?? null,
            'health' => [
                'http' => self::validationStatus($validation, 'health_url'),
                'ready' => self::validationStatus($validation, 'ready_url'),
                'websocket' => self::validationStatus($validation, 'ws_bind_target'),
                'details' => [
                    'validation_checks' => count($validation),
                    'failed_required_checks' => count($failedValidation),
                ],
            ],
            'services' => self::serviceDefinitions($config),
            'warnings' => $report['warnings'] ?? [],
        ];
    }

    private static function validationStatus(array $validation, string $key): string
    {
        foreach ($validation as $check) {
            if (($check['key'] ?? '') === $key) {
                return ($check['status'] ?? 'fail') === 'pass' ? 'ok' : 'failed';
            }
        }

        return 'unknown';
    }

    public static function detectPendingMigrations(array $config): array
    {
        $status = self::runArtisan($config, ['migrate:status', '--no-ansi']);
        $output = trim((string) ($status['stdout'] ?: $status['stderr']));
        $pending = ($status['exit_code'] ?? 1) !== 0 || stripos($output, 'Pending') !== false;

        return [
            'pending' => $pending,
            'message' => $output !== '' ? $output : ($pending ? 'Pending migrations detected.' : 'No pending migrations.'),
        ];
    }

    public static function detectRepairActions(array $config): array
    {
        $actions = [];
        $releaseMetadata = self::releaseMetadataStatus();
        if (! $releaseMetadata['readable']) {
            $actions[] = [
                'id' => 'release_metadata',
                'label' => 'Restore release metadata',
                'reason' => $releaseMetadata['message'],
            ];
        }

        $envPath = self::environmentPath($config);
        $envValues = is_file($envPath) ? self::parseEnvString((string) file_get_contents($envPath)) : [];
        $appKey = trim((string) ($envValues['APP_KEY'] ?? ''));

        if ($appKey === '') {
            $actions[] = [
                'id' => 'app_key',
                'label' => 'Generate APP_KEY',
                'reason' => 'APP_KEY is missing from the current .env.',
            ];
        }

        $adminEmail = (string) ($config['admin']['email'] ?? '');
        $adminCheck = self::checkAdminExists($config, $adminEmail);
        if (! $adminCheck['exists']) {
            $actions[] = [
                'id' => 'admin_account',
                'label' => 'Bootstrap admin account',
                'reason' => $adminCheck['message'],
            ];
        }

        $pendingMigrations = self::detectPendingMigrations($config);
        if ($pendingMigrations['pending']) {
            $actions[] = [
                'id' => 'migrations',
                'label' => 'Run migrations',
                'reason' => 'Pending database migrations detected.',
            ];
        }

        $serviceArtifactPath = self::serviceArtifactPath($config);
        if (! is_file($serviceArtifactPath)) {
            $actions[] = [
                'id' => 'service_artifact',
                'label' => 'Generate service artifact',
                'reason' => 'No service registration artifact has been generated yet.',
            ];
        }

        if (strtolower((string) ($config['service']['registration_mode'] ?? 'template')) === 'register') {
            $serviceRegistration = self::checkServiceRegistration($config);
            if (! $serviceRegistration['registered']) {
                $actions[] = [
                    'id' => 'service_registration',
                    'label' => 'Register runtime startup',
                    'reason' => $serviceRegistration['message'],
                ];
            }
        }

        return $actions;
    }

    public static function runRepair(array $config): array
    {
        $actions = self::detectRepairActions($config);
        $performed = [];
        $skipped = [];

        if ($actions === []) {
            self::appendLog('Repair mode found nothing to repair.');
        }

        foreach ($actions as $action) {
            switch ($action['id']) {
                case 'release_metadata':
                    $releaseResult = self::restoreReleaseMetadata();
                    $performed[] = [
                        'id' => 'release_metadata',
                        'label' => 'Restore release metadata',
                        'result' => $releaseResult,
                    ];
                    break;

                case 'app_key':
                    $envResult = self::writeEnvironment($config);
                    $performed[] = [
                        'id' => 'app_key',
                        'label' => 'Generate APP_KEY',
                        'result' => $envResult,
                    ];
                    break;

                case 'admin_account':
                    $adminResult = self::bootstrapAdmin($config);
                    $performed[] = [
                        'id' => 'admin_account',
                        'label' => 'Bootstrap admin account',
                        'result' => $adminResult,
                    ];
                    break;

                case 'migrations':
                    $migrationResult = self::runMigrations($config);
                    $performed[] = [
                        'id' => 'migrations',
                        'label' => 'Run migrations',
                        'result' => $migrationResult,
                    ];
                    break;

                case 'service_artifact':
                    $serviceResult = self::writeServiceArtifact($config);
                    $performed[] = [
                        'id' => 'service_artifact',
                        'label' => 'Generate service artifact',
                        'result' => $serviceResult,
                    ];
                    break;

                case 'service_registration':
                    $serviceArtifact = is_file(self::serviceArtifactPath($config))
                        ? [
                            'target_os' => (string) ($config['service']['target_os'] ?? 'windows'),
                            'service_manager' => (string) ($config['service']['service_manager'] ?? 'scheduled-task'),
                            'artifact_path' => self::serviceArtifactPath($config),
                        ]
                        : self::writeServiceArtifact($config);
                    $registrationResult = self::registerServiceRuntime($config, $serviceArtifact);
                    $performed[] = [
                        'id' => 'service_registration',
                        'label' => 'Register runtime startup',
                        'result' => $registrationResult,
                    ];
                    break;

                default:
                    $skipped[] = $action;
                    break;
            }
        }

        return [
            'detected' => $actions,
            'performed' => $performed,
            'skipped' => $skipped,
        ];
    }

    public static function installModeSummary(string $mode): string
    {
        return match ($mode) {
            'upgrade' => 'Upgrade mode completed environment refresh, migration re-run, admin refresh, service artifact generation, and upgrade backup capture.',
            'repair' => 'Repair mode executed targeted fixes for missing APP_KEY, admin bootstrap, pending migrations, and service artifact generation when needed.',
            default => 'Installer completed the core deployment contract: environment file, APP_KEY, database migrations, admin bootstrap, service artifact generation, install manifest writing, and packaged websocket sandbox acceptance. Host go-live tasks such as proxy, TLS, firewall, DNS, and service registration policy still require operator or Kit Setup completion.',
        };
    }

    private static function copyPath(string $source, string $destination): void
    {
        if (is_dir($source)) {
            if (! is_dir($destination)) {
                mkdir($destination, 0775, true);
            }

            $items = scandir($source) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                self::copyPath($source . DIRECTORY_SEPARATOR . $item, $destination . DIRECTORY_SEPARATOR . $item);
            }

            return;
        }

        $parent = dirname($destination);
        if (! is_dir($parent)) {
            mkdir($parent, 0775, true);
        }

        copy($source, $destination);
    }

    private static function hostMatchesTargetOs(string $targetOs): bool
    {
        $family = strtolower(PHP_OS_FAMILY);

        return match ($targetOs) {
            'linux' => $family === 'linux',
            'windows' => $family === 'windows',
            default => false,
        };
    }

    private static function runHostCommand(array $parts, ?string $workingDirectory = null): array
    {
        $command = implode(' ', array_map('escapeshellarg', $parts));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $parts,
            $descriptorSpec,
            $pipes,
            $workingDirectory ?: self::rootPath(),
            null,
            ['bypass_shell' => true]
        );
        if (! is_resource($process)) {
            return [
                'command' => $command,
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Failed to start host command.',
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::appendLog('Host command: ' . $command);
        if ($stdout !== false && trim($stdout) !== '') {
            self::appendLog(trim($stdout));
        }
        if ($stderr !== false && trim($stderr) !== '') {
            self::appendLog(trim($stderr), $exitCode === 0 ? 'warn' : 'error');
        }

        return [
            'command' => $command,
            'exit_code' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
        ];
    }

    private static function runPhpSnippet(array $config, string $script, array $arguments = []): array
    {
        $artisan = self::artisanPath($config);
        $installPath = dirname($artisan);

        if (! is_file($artisan)) {
            return [
                'command' => '',
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Could not locate artisan in install path: ' . $artisan,
            ];
        }

        $tempScript = self::generatedDir() . DIRECTORY_SEPARATOR . 'snippet-' . bin2hex(random_bytes(6)) . '.php';
        $scriptContents = ltrim($script);
        if (! str_starts_with($scriptContents, '<?php')) {
            $scriptContents = "<?php\n" . $script;
        }
        file_put_contents($tempScript, $scriptContents);

        try {
            return self::runHostCommand(
                array_merge([self::phpBinary(), $tempScript], $arguments),
                $installPath
            );
        } finally {
            if (is_file($tempScript)) {
                @unlink($tempScript);
            }
        }
    }

    public static function checkAdminExists(array $config, string $email): array
    {
        if ($email === '') {
            return ['exists' => false, 'message' => 'Missing admin email'];
        }

        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$exists = App\Models\User::query()
    ->where('email', $argv[1])
    ->where('is_operator', true)
    ->where('user_type', 'admin')
    ->exists();
echo json_encode(['exists' => $exists], JSON_UNESCAPED_SLASHES);
PHP;

        $result = self::runPhpSnippet($config, $script, [$email]);
        $stdout = $result['stdout'];
        $stderr = $result['stderr'];
        $exitCode = $result['exit_code'];

        if ($exitCode !== 0) {
            return ['exists' => false, 'message' => trim((string) ($stderr ?: $stdout ?: 'Admin validation failed'))];
        }

        $decoded = json_decode((string) $stdout, true);
        $exists = (bool) ($decoded['exists'] ?? false);

        return [
            'exists' => $exists,
            'message' => $exists ? "Admin {$email} present" : "Admin {$email} not found",
        ];
    }

    private static function parseEnvString(string $content): array
    {
        $values = [];
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value);
        }

        return $values;
    }

    private static function replaceEnvValues(string $content, array $values): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $seen = [];

        foreach ($lines as $index => $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key] = explode('=', $line, 2);
            $key = trim($key);

            if (! array_key_exists($key, $values)) {
                continue;
            }

            $lines[$index] = $key . '=' . self::formatEnvValue((string) $values[$key]);
            $seen[$key] = true;
        }

        foreach ($values as $key => $value) {
            if (! isset($seen[$key])) {
                $lines[] = $key . '=' . self::formatEnvValue((string) $value);
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|"|\'/', $value)) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }

        return $value;
    }

    /**
     * @param  string|array<int, string>  $origins
     * @return array<int, string>
     */
    private static function normalizeOriginList(string|array $origins): array
    {
        $items = is_array($origins)
            ? $origins
            : explode(',', $origins);

        $normalized = [];

        foreach ($items as $origin) {
            $origin = trim((string) $origin);
            if ($origin === '') {
                continue;
            }

            $host = parse_url($origin, PHP_URL_HOST);
            $candidate = $host !== null && $host !== false && $host !== '' ? $host : $origin;
            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int, string>
     */
    private static function deriveAllowedOrigins(array $config): array
    {
        $realtime = $config['realtime'] ?? [];
        $origins = self::normalizeOriginList($realtime['allowed_origins'] ?? []);
        $publicHost = self::derivePublicWebsocketHost($config);

        if ($publicHost !== '') {
            $origins[] = $publicHost;
        }

        return array_values(array_unique(array_filter($origins, static fn (string $origin): bool => $origin !== '')));
    }

    private static function derivePublicWebsocketHost(array $config): string
    {
        $wsUrl = (string) ($config['realtime']['public_websocket_url'] ?? '');
        $appUrl = (string) ($config['app']['app_url'] ?? '');

        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            if (! self::isLocalHostname($appHost)) {
                return $appHost;
            }
        }

        $wsHost = parse_url($wsUrl, PHP_URL_HOST);
        if (is_string($wsHost) && $wsHost !== '') {
            if (! self::isLocalHostname($wsHost)) {
                return $wsHost;
            }
        }

        if (is_string($appHost) && $appHost !== '') {
            return $appHost;
        }

        if (is_string($wsHost) && $wsHost !== '') {
            return $wsHost;
        }

        return 'localhost';
    }

    private static function isLocalHostname(string $host): bool
    {
        $normalized = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        return in_array($normalized, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function check(string $key, string $label, bool $passed, string $message, bool $blocking = true): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $passed ? 'pass' : 'fail',
            'message' => $message,
            'blocking' => $blocking,
        ];
    }

    private static function isSafeInstallPath(string $path): bool
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            return false;
        }

        $normalized = str_replace('/', DIRECTORY_SEPARATOR, $trimmed);
        $systemRoots = ['C:\\', 'C:\\Windows', 'C:\\Program Files', 'C:\\Program Files (x86)'];

        return ! in_array(rtrim($normalized, '\\/'), $systemRoots, true);
    }

    private static function checkInstallPathMatchesRoot(string $installPath): array
    {
        $resolvedInstallPath = realpath($installPath);
        $resolvedRootPath = realpath(self::rootPath());

        if ($resolvedInstallPath === false || $resolvedRootPath === false) {
            return [
                'ok' => false,
                'message' => 'Unable to resolve install path or package root.',
            ];
        }

        $normalizedInstallPath = self::normalizePathForComparison($resolvedInstallPath);
        $normalizedRootPath = self::normalizePathForComparison($resolvedRootPath);

        if ($normalizedInstallPath !== $normalizedRootPath) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Kit app.install_path must be the extracted Realtime package root. install_path=%s package_root=%s',
                    $resolvedInstallPath,
                    $resolvedRootPath
                ),
            ];
        }

        return [
            'ok' => true,
            'message' => $resolvedInstallPath,
        ];
    }

    private static function normalizePathForComparison(string $path): string
    {
        return strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));
    }

    private static function isNonPlaceholderSecret(string $secret): bool
    {
        $trimmed = trim($secret);

        if ($trimmed === '') {
            return false;
        }

        return ! in_array(strtolower($trimmed), ['replace-me', 'changeme', 'secret', 'password'], true);
    }

    private static function isStrongAdminPassword(string $password): bool
    {
        $trimmed = trim($password);

        if (strlen($trimmed) < 10) {
            return false;
        }

        $lower = strtolower($trimmed);
        $placeholders = [
            'password',
            'password123',
            'changeme',
            'change-me',
            'replace-me',
            'replace-with-initial-password',
            'replace-with-real-password',
            'provided-once-in-kit-setup',
            'admin123',
        ];

        if (in_array($lower, $placeholders, true)
            || str_starts_with($lower, 'replace-with-')
            || str_starts_with($lower, 'provided-')
        ) {
            return false;
        }

        return preg_match('/[A-Za-z]/', $trimmed) === 1 && preg_match('/[0-9]/', $trimmed) === 1;
    }

    private static function maskSecret(string $secret): string
    {
        $trimmed = trim($secret);

        if ($trimmed === '') {
            return '[missing]';
        }

        if (strlen($trimmed) <= 4) {
            return str_repeat('*', strlen($trimmed));
        }

        return substr($trimmed, 0, 2) . str_repeat('*', max(strlen($trimmed) - 4, 2)) . substr($trimmed, -2);
    }

    private static function isTcpPortAvailable(int $port): bool
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private static function canConnectToDatabase(string $host, int $port, string $database, string $username, string $password): bool
    {
        if ($host === '' || $database === '' || $username === '') {
            return false;
        }

        try {
            new PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
                [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            return true;
        } catch (Throwable $e) {
            self::appendLog('Database connectivity check failed: ' . $e->getMessage(), 'warn');
            return false;
        }
    }

    private static function mysqlTableExists(PDO $pdo, string $database, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
        );
        $statement->execute([$database, $table]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function mysqlMigrationIsRecorded(PDO $pdo, string $database, string $migration): bool
    {
        if (! self::mysqlTableExists($pdo, $database, 'migrations')) {
            return false;
        }

        $statement = $pdo->prepare('SELECT COUNT(*) FROM `migrations` WHERE `migration` = ?');
        $statement->execute([$migration]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function mysqlColumnExists(PDO $pdo, string $database, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?'
        );
        $statement->execute([$database, $table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function probeHttpUrl(string $url): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'message' => 'Invalid URL'];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? 'No response';

        return [
            'ok' => is_string($result) || str_contains($statusLine, '200') || str_contains($statusLine, '302'),
            'message' => $statusLine,
        ];
    }

    private static function probeHttpEndpoints(array $urls): array
    {
        $lastProbe = ['ok' => false, 'message' => 'No endpoint configured'];

        foreach ($urls as $url) {
            $probe = self::probeHttpUrl($url);
            $probe['message'] = sprintf('%s -> %s', $url, $probe['message']);

            if ($probe['ok']) {
                return $probe;
            }

            $lastProbe = $probe;
        }

        return $lastProbe;
    }

    private static function probeTcpTarget(string $host, int $port): array
    {
        $target = sprintf('tcp://%s:%d', $host, $port);
        $socket = @stream_socket_client($target, $errno, $errstr, 2);

        if ($socket === false) {
            return [
                'ok' => false,
                'message' => sprintf('%s unreachable (%s)', $target, $errstr ?: $errno),
            ];
        }

        fclose($socket);

        return [
            'ok' => true,
            'message' => sprintf('%s reachable', $target),
        ];
    }
}
