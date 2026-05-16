<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

$input = InstallerRuntime::jsonInput();
$config = InstallerRuntime::normalizeImportedConfig($input['config'] ?? []);
$state = InstallerRuntime::saveConfig($config);
$serviceTemplate = InstallerRuntime::renderServiceTemplate($state['config']);
$mode = (string) ($state['config']['mode'] ?? 'fresh');

if (
    ! empty($state['completion_marker']['installed_at'])
    && ($mode === 'fresh')
    && empty($state['config']['service']['allow_existing_install'])
) {
    InstallerRuntime::appendLog('Fresh install blocked because a completion marker already exists and override was not granted.', 'error');
    InstallerRuntime::jsonResponse([
        'state' => $state,
        'message' => 'A prior install marker exists. Use upgrade/repair mode or explicitly allow re-running fresh install.',
    ], 422);
}

$upgradeBackup = null;
if ($mode === 'upgrade') {
    $upgradeBackup = InstallerRuntime::backupUpgradeArtifacts($state['config']);
}

$checks = InstallerRuntime::buildPreflightChecks($state['config']);
$state['preflight'] = $checks;

if (InstallerRuntime::hasBlockingFailures($checks)) {
    InstallerRuntime::appendLog('Install blocked because one or more blocking preflight checks failed.', 'error');
    InstallerRuntime::jsonResponse([
        'state' => InstallerRuntime::saveState($state),
        'message' => 'Install blocked. Resolve the failed blocking checks first.',
    ], 422);
}

$envResult = InstallerRuntime::writeEnvironment($state['config']);
$migrationResult = InstallerRuntime::runMigrations($state['config']);
$seedResult = InstallerRuntime::runOptionalSeeders($state['config']);
$adminResult = InstallerRuntime::bootstrapAdmin($state['config']);
$serviceArtifact = InstallerRuntime::writeServiceArtifact($state['config']);
$serviceRegistration = InstallerRuntime::registerServiceRuntime($state['config'], $serviceArtifact);

$manifest = InstallerRuntime::writeManifest(array_merge(InstallerRuntime::buildKitManifest($state['config'], [
    'service_artifact' => $serviceArtifact,
    'health_status' => 'pending',
]), [
    'mode' => $mode,
    'generated_at' => date(DATE_ATOM),
    'app' => [
        'install_path' => $state['config']['app']['install_path'] ?? '',
        'app_url' => $state['config']['app']['app_url'] ?? '',
        'app_env' => $state['config']['app']['app_env'] ?? '',
    ],
    'realtime' => [
        'service_name' => $state['config']['realtime']['service_name'] ?? '',
        'token_audience' => $state['config']['realtime']['token_audience'] ?? '',
        'public_websocket_url' => $state['config']['realtime']['public_websocket_url'] ?? '',
        'ws_port' => $state['config']['realtime']['ws_port'] ?? '',
    ],
    'artifacts' => [
        'environment_path' => $envResult['path'],
        'environment_backup_path' => $envResult['backup_path'],
        'migration_exit_code' => $migrationResult['exit_code'] ?? 1,
        'seed_command' => $seedResult['command'] ?? null,
        'bootstrap_admin_email' => $adminResult['email'] ?? null,
        'service_template_path' => $serviceTemplate['template_path'] ?? null,
        'service_artifact_path' => $serviceArtifact['artifact_path'] ?? null,
        'service_registration_status' => $serviceRegistration['status'] ?? null,
        'upgrade_backup_root' => $upgradeBackup['backup_root'] ?? null,
    ],
]));

$completion = InstallerRuntime::writeCompletionMarker([
    'installed_at' => date(DATE_ATOM),
    'mode' => $mode,
    'install_path' => $state['config']['app']['install_path'] ?? '',
    'app_url' => $state['config']['app']['app_url'] ?? '',
]);

$serviceInstructions = InstallerRuntime::serviceInstructions($state['config'], $serviceArtifact);

$kitReport = InstallerRuntime::buildKitReport($state['config'], 'success', [
    ['id' => 'preflight', 'status' => 'success', 'message' => 'Blocking preflight checks passed.'],
    ['id' => 'environment', 'status' => 'success', 'message' => '.env was written.'],
    ['id' => 'migrate', 'status' => 'success', 'message' => 'Database migrations completed.'],
    ['id' => 'admin', 'status' => 'success', 'message' => 'Initial admin account is present.'],
    ['id' => 'services', 'status' => ($serviceRegistration['registered'] ?? false) ? 'success' : 'warning', 'message' => $serviceRegistration['message'] ?? 'Service artifact generated.'],
], [
    'started_at' => date(DATE_ATOM),
    'service_artifact' => $serviceArtifact,
    'summary' => InstallerRuntime::installModeSummary($mode),
]);

$report = InstallerRuntime::writeReport(array_merge($kitReport, [
    'mode' => $mode,
    'generated_at' => date(DATE_ATOM),
    'summary' => InstallerRuntime::installModeSummary($mode),
    'artifacts' => [
        'state' => InstallerRuntime::statePath(),
        'log' => InstallerRuntime::logPath(),
        'report' => InstallerRuntime::reportPath(),
        'manifest' => InstallerRuntime::manifestPath(),
        'environment' => $envResult['path'],
        'environment_backup' => $envResult['backup_path'],
        'upgrade_backup_root' => $upgradeBackup['backup_root'] ?? null,
    ],
    'environment' => [
        'path' => $envResult['path'],
        'generated_app_key' => $envResult['generated_app_key'],
    ],
    'database' => [
        'migrations_ran' => true,
        'migration_exit_code' => $migrationResult['exit_code'] ?? 1,
        'seeders_ran' => $seedResult !== null,
        'seed_command' => $seedResult['command'] ?? null,
    ],
    'admin' => [
        'email' => $adminResult['email'] ?? null,
        'created' => (bool) ($adminResult['created'] ?? false),
        'updated' => (bool) ($adminResult['updated'] ?? false),
        'strategy' => $adminResult['strategy'] ?? 'create_if_missing',
        'overwrite_existing' => (bool) ($adminResult['overwrite_existing'] ?? false),
    ],
    'service' => [
        'target_os' => $serviceTemplate['target_os'],
        'template_path' => $serviceTemplate['template_path'],
        'artifact_path' => $serviceArtifact['artifact_path'],
        'service_manager' => $serviceArtifact['service_manager'],
        'registration_mode' => $state['config']['service']['registration_mode'] ?? 'template',
        'registration' => $serviceRegistration,
        'instructions' => $serviceInstructions,
    ],
    'upgrade' => [
        'backup_root' => $upgradeBackup['backup_root'] ?? null,
        'backup_files' => $upgradeBackup['files'] ?? [],
    ],
    'manual_tasks' => array_merge($serviceInstructions, [
        'Run `/admin/sandbox` and confirm websocket connection from the installed admin surface.',
        'Lock or remove the installer after go-live validation.',
    ]),
    'completion_marker' => $completion,
    'acceptance' => [
        'override_used' => (bool) ($state['config']['service']['allow_finish_with_failed_validation'] ?? false),
    ],
]));

$state['install'] = [
    'status' => 'completed',
    'log' => InstallerRuntime::loadLog(),
    'completed_at' => date(DATE_ATOM),
    'environment' => $envResult,
    'migration' => $migrationResult,
    'seeds' => $seedResult,
    'admin' => $adminResult,
    'service_template' => $serviceTemplate,
    'service_artifact' => $serviceArtifact,
    'service_registration' => $serviceRegistration,
    'upgrade_backup' => $upgradeBackup,
    'manifest' => $manifest,
];
$state['current_step'] = 'install';
$state['last_updated_at'] = date(DATE_ATOM);
$state['completed_steps'] = array_values(array_unique(array_merge($state['completed_steps'] ?? [], ['install'])));
$state['completion_marker'] = $completion;
$state = InstallerRuntime::saveState($state);

InstallerRuntime::jsonResponse([
    'state' => $state,
    'report' => $report,
    'message' => 'Installer completed.',
]);
