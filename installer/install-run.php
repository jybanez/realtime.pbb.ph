<?php

require_once __DIR__ . '/lib/InstallerRuntime.php';

function usage(): void
{
    fwrite(STDERR, "Usage: php installer/install-run.php --config <path> --report <path> [--mode fresh|upgrade|repair|preflight] [--dry-run] [--no-service-register] [--verbose]\n");
}

function parseArguments(array $argv): array
{
    $args = [
        'config' => null,
        'report' => null,
        'mode' => null,
        'dry_run' => false,
        'no_service_register' => false,
        'verbose' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        switch ($arg) {
            case '--config':
                $args['config'] = $argv[++$i] ?? null;
                break;
            case '--report':
                $args['report'] = $argv[++$i] ?? null;
                break;
            case '--mode':
                $args['mode'] = $argv[++$i] ?? null;
                break;
            case '--dry-run':
                $args['dry_run'] = true;
                break;
            case '--no-service-register':
                $args['no_service_register'] = true;
                break;
            case '--verbose':
                $args['verbose'] = true;
                break;
            case '--help':
            case '-h':
                usage();
                exit(0);
            default:
                fwrite(STDERR, "Unknown argument: {$arg}\n");
                usage();
                exit(3);
        }
    }

    return $args;
}

function writeExternalReport(string $path, array $report): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$args = parseArguments($argv);

if (! is_string($args['config']) || $args['config'] === '' || ! is_file($args['config'])) {
    fwrite(STDERR, "Config file is required and must exist.\n");
    usage();
    exit(2);
}

if (! is_string($args['report']) || $args['report'] === '') {
    fwrite(STDERR, "Report path is required.\n");
    usage();
    exit(2);
}

$startedAt = date(DATE_ATOM);
$rawConfig = json_decode((string) file_get_contents($args['config']), true);
if (! is_array($rawConfig)) {
    $report = InstallerRuntime::buildKitReport(InstallerRuntime::configTemplate(), 'failed', [
        ['id' => 'config', 'status' => 'failed', 'message' => 'Config file is not valid JSON.'],
    ], [
        'started_at' => $startedAt,
        'errors' => [['id' => 'config.invalid_json', 'message' => 'Config file is not valid JSON.']],
    ]);
    writeExternalReport($args['report'], $report);
    exit(2);
}

$config = InstallerRuntime::normalizeImportedConfig($rawConfig);
if (is_string($args['mode']) && $args['mode'] !== '') {
    $config['mode'] = $args['mode'];
}
if ($args['no_service_register']) {
    $config['service']['registration_mode'] = 'template';
}

$mode = (string) ($config['mode'] ?? 'fresh');
if (! in_array($mode, ['fresh', 'upgrade', 'repair', 'preflight'], true)) {
    $report = InstallerRuntime::buildKitReport($config, 'failed', [
        ['id' => 'config', 'status' => 'failed', 'message' => 'Unsupported install mode.'],
    ], [
        'started_at' => $startedAt,
        'errors' => [['id' => 'config.unsupported_mode', 'message' => "Unsupported mode: {$mode}"]],
    ]);
    writeExternalReport($args['report'], $report);
    exit(3);
}

try {
    $draftErrors = InstallerRuntime::validateDraftConfig($config);
    if ($draftErrors !== []) {
        $report = InstallerRuntime::buildKitReport($config, 'failed', [
            ['id' => 'config', 'status' => 'failed', 'message' => 'Installer configuration is incomplete.'],
        ], [
            'started_at' => $startedAt,
            'errors' => array_map(
                static fn (string $field, string $message): array => ['id' => $field, 'message' => $message],
                array_keys($draftErrors),
                $draftErrors
            ),
        ]);
        writeExternalReport($args['report'], $report);
        exit(2);
    }

    $checks = InstallerRuntime::buildPreflightChecks($config);
    $preflight = InstallerRuntime::buildKitPreflight($checks);
    $preflightFailed = InstallerRuntime::hasBlockingFailures($checks);

    if ($mode === 'preflight' || $args['dry_run']) {
        $status = $preflightFailed ? 'failed' : 'success';
        $report = InstallerRuntime::buildKitReport($config, $status, [
            ['id' => 'preflight', 'status' => $preflightFailed ? 'failed' : 'success', 'message' => $preflightFailed ? 'Blocking preflight checks failed.' : 'Preflight checks passed.'],
            ['id' => 'dry-run', 'status' => $args['dry_run'] ? 'success' : 'skipped', 'message' => $args['dry_run'] ? 'Dry run completed without mutation.' : 'No install mutation requested.'],
        ], [
            'started_at' => $startedAt,
            'warnings' => $preflightFailed ? [] : [],
            'errors' => $preflightFailed ? $preflight['checks'] : [],
            'summary' => $preflightFailed ? 'Preflight failed.' : 'Preflight passed.',
        ]);
        $report['preflight'] = $preflight;
        writeExternalReport($args['report'], $report);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($preflightFailed ? 1 : 0);
    }

    if ($preflightFailed) {
        $report = InstallerRuntime::buildKitReport($config, 'failed', [
            ['id' => 'preflight', 'status' => 'failed', 'message' => 'Blocking preflight checks failed.'],
        ], [
            'started_at' => $startedAt,
            'errors' => $preflight['checks'],
            'summary' => 'Install stopped before mutation because preflight failed.',
        ]);
        $report['preflight'] = $preflight;
        writeExternalReport($args['report'], $report);
        exit(1);
    }

    InstallerRuntime::saveConfig($config);

    if ($mode === 'repair') {
        $repair = InstallerRuntime::runRepair($config);
        $validation = InstallerRuntime::validateInstalledState($config);
        $status = InstallerRuntime::summarizeValidation($validation)['failed'] === 0 ? 'success' : 'warning';
        $report = InstallerRuntime::buildKitReport($config, $status, [
            ['id' => 'preflight', 'status' => 'success', 'message' => 'Blocking preflight checks passed.'],
            ['id' => 'repair', 'status' => 'success', 'message' => 'Repair actions completed.'],
            ['id' => 'validate', 'status' => $status === 'success' ? 'success' : 'warning', 'message' => 'Validation completed after repair.'],
        ], [
            'started_at' => $startedAt,
            'validation' => $validation,
            'summary' => 'Repair mode completed.',
        ]);
        $report['repair'] = $repair;
        InstallerRuntime::writeReport($report);
        writeExternalReport($args['report'], $report);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($status === 'success' ? 0 : 5);
    }

    $upgradeBackup = $mode === 'upgrade' ? InstallerRuntime::backupUpgradeArtifacts($config) : null;
    $envResult = InstallerRuntime::writeEnvironment($config);
    $migrationResult = InstallerRuntime::runMigrations($config);
    $seedResult = InstallerRuntime::runOptionalSeeders($config);
    $adminResult = InstallerRuntime::bootstrapAdmin($config);
    $serviceArtifact = InstallerRuntime::writeServiceArtifact($config);
    $serviceRegistration = InstallerRuntime::registerServiceRuntime($config, $serviceArtifact);

    $manifest = InstallerRuntime::buildKitManifest($config, [
        'service_artifact' => $serviceArtifact,
        'health_status' => 'pending',
    ]);
    $manifest['artifacts'] = [
        'environment_path' => $envResult['path'],
        'environment_backup_path' => $envResult['backup_path'],
        'migration_exit_code' => $migrationResult['exit_code'] ?? 1,
        'seed_command' => $seedResult['command'] ?? null,
        'bootstrap_admin_email' => $adminResult['email'] ?? null,
        'service_artifact_path' => $serviceArtifact['artifact_path'] ?? null,
        'service_registration_status' => $serviceRegistration['status'] ?? null,
        'upgrade_backup_root' => $upgradeBackup['backup_root'] ?? null,
    ];
    InstallerRuntime::writeManifest($manifest);
    InstallerRuntime::writeCompletionMarker([
        'installed_at' => date(DATE_ATOM),
        'mode' => $mode,
        'install_path' => $config['app']['install_path'] ?? '',
        'app_url' => $config['app']['app_url'] ?? '',
    ]);

    $report = InstallerRuntime::buildKitReport($config, 'success', [
        ['id' => 'preflight', 'status' => 'success', 'message' => 'Blocking preflight checks passed.'],
        ['id' => 'environment', 'status' => 'success', 'message' => '.env was written.'],
        ['id' => 'migrate', 'status' => 'success', 'message' => 'Database migrations completed.'],
        ['id' => 'admin', 'status' => 'success', 'message' => 'Initial admin account is present.'],
        ['id' => 'services', 'status' => ($serviceRegistration['registered'] ?? false) ? 'success' : 'warning', 'message' => $serviceRegistration['message'] ?? 'Service artifact generated.'],
    ], [
        'started_at' => $startedAt,
        'service_artifact' => $serviceArtifact,
        'summary' => InstallerRuntime::installModeSummary($mode),
    ]);
    $report['environment'] = [
        'path' => $envResult['path'],
        'generated_app_key' => $envResult['generated_app_key'],
    ];
    $report['database'] = [
        'migrations_ran' => true,
        'migration_exit_code' => $migrationResult['exit_code'] ?? 1,
        'seeders_ran' => $seedResult !== null,
        'seed_command' => $seedResult['command'] ?? null,
    ];
    $report['admin'] = [
        'email' => $adminResult['email'] ?? null,
        'created' => (bool) ($adminResult['created'] ?? false),
        'updated' => (bool) ($adminResult['updated'] ?? false),
        'strategy' => $adminResult['strategy'] ?? 'create_if_missing',
        'overwrite_existing' => (bool) ($adminResult['overwrite_existing'] ?? false),
    ];
    $report['service_registration'] = $serviceRegistration;
    $report['upgrade'] = [
        'backup_root' => $upgradeBackup['backup_root'] ?? null,
        'backup_files' => $upgradeBackup['files'] ?? [],
    ];

    InstallerRuntime::writeReport($report);
    writeExternalReport($args['report'], $report);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $report = InstallerRuntime::buildKitReport($config, 'failed', [
        ['id' => 'install', 'status' => 'failed', 'message' => $e->getMessage()],
    ], [
        'started_at' => $startedAt,
        'errors' => [['id' => 'install.exception', 'message' => $e->getMessage()]],
        'summary' => 'Installer failed.',
    ]);
    InstallerRuntime::writeReport($report);
    writeExternalReport($args['report'], $report);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
