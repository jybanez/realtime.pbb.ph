<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

$input = InstallerRuntime::jsonInput();
$config = InstallerRuntime::normalizeImportedConfig($input['config'] ?? []);
$state = InstallerRuntime::saveConfig($config);

$repair = InstallerRuntime::runRepair($state['config']);
$validation = InstallerRuntime::validateInstalledState($state['config']);

$state['install'] = [
    'status' => 'repaired',
    'log' => InstallerRuntime::loadLog(),
    'completed_at' => date(DATE_ATOM),
    'repair' => $repair,
];
$state['validation'] = $validation;
$state['current_step'] = 'validate';
$state['last_updated_at'] = date(DATE_ATOM);
$state['completed_steps'] = array_values(array_unique(array_merge($state['completed_steps'] ?? [], ['install', 'validate'])));
$state = InstallerRuntime::saveState($state);

$report = InstallerRuntime::loadReport();
$report['mode'] = 'repair';
$report['schema_version'] = 1;
$report['app'] = 'pbb-realtime';
$report['version'] = InstallerRuntime::appVersion();
$report['status'] = InstallerRuntime::summarizeValidation($validation)['failed'] === 0 ? 'success' : 'warning';
$report['generated_at'] = date(DATE_ATOM);
$report['summary'] = 'Repair mode executed targeted fixes for missing APP_KEY, admin bootstrap, pending migrations, and service artifact generation when needed.';
$report['repair'] = $repair;
$report['validation'] = $validation;
$serviceArtifact = InstallerRuntime::renderServiceTemplate($state['config']);
$serviceInstructions = InstallerRuntime::serviceInstructions($state['config'], $serviceArtifact);
$report['service']['instructions'] = $serviceInstructions;
$report['manual_tasks'] = array_merge($serviceInstructions, [
    'Verify the public websocket reverse proxy route for /realtime.',
    'Run the sandbox checklist before returning the hub to service.',
]);
$report['sandbox_validation_checklist'] = [
    'Issue a sandbox admission token successfully.',
    'Confirm websocket connect succeeds.',
    'Confirm room join succeeds.',
    'Confirm presence publish succeeds.',
    'Confirm chat publish succeeds.',
];
$report['acceptance']['override_used'] = (bool) ($state['config']['service']['allow_finish_with_failed_validation'] ?? false);
InstallerRuntime::writeReport($report);

InstallerRuntime::jsonResponse([
    'state' => $state,
    'report' => InstallerRuntime::loadReport(),
    'repair' => $repair,
    'validation' => $validation,
    'message' => $repair['performed'] === [] ? 'Repair mode found nothing to change.' : 'Repair actions completed.',
]);
