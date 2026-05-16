<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

$input = InstallerRuntime::jsonInput();
$config = InstallerRuntime::normalizeImportedConfig($input['config'] ?? []);
$state = InstallerRuntime::saveConfig($config);

$validation = InstallerRuntime::validateInstalledState($state['config']);

$state['validation'] = $validation;
$state['current_step'] = 'validate';
$state['last_updated_at'] = date(DATE_ATOM);
$state['completed_steps'] = array_values(array_unique(array_merge($state['completed_steps'] ?? [], ['validate'])));
$state = InstallerRuntime::saveState($state);

InstallerRuntime::appendLog('Validation scaffold executed.');

$report = InstallerRuntime::loadReport();
if ($report !== []) {
    $report['validation_generated_at'] = date(DATE_ATOM);
    $report['validation'] = $validation;
    $report['health'] = [
        'last_checked_at' => date(DATE_ATOM),
        'status' => InstallerRuntime::summarizeValidation($validation)['failed'] === 0 ? 'healthy' : 'degraded',
    ];
    $serviceArtifact = InstallerRuntime::renderServiceTemplate($state['config']);
    $serviceInstructions = InstallerRuntime::serviceInstructions($state['config'], $serviceArtifact);
    $report['manual_tasks'] = array_merge($serviceInstructions, [
        'Expose and verify the public websocket path for /realtime.',
        'Run sandbox checklist: admission, websocket connect, room join, presence publish, chat publish.',
    ]);
    $report['service']['instructions'] = $serviceInstructions;
    $report['sandbox_validation_checklist'] = [
        'Issue a sandbox admission token successfully.',
        'Confirm websocket connect succeeds.',
        'Confirm room join succeeds.',
        'Confirm presence publish succeeds.',
        'Confirm chat publish succeeds.',
    ];
    $report['acceptance']['override_used'] = (bool) ($state['config']['service']['allow_finish_with_failed_validation'] ?? false);
    InstallerRuntime::writeReport($report);
}

InstallerRuntime::jsonResponse([
    'state' => $state,
    'report' => InstallerRuntime::loadReport(),
    'validation' => $validation,
]);
