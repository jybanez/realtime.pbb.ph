<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

$input = InstallerRuntime::jsonInput();
$config = InstallerRuntime::normalizeImportedConfig($input['config'] ?? []);
$state = InstallerRuntime::saveConfig($config);
$checks = InstallerRuntime::buildPreflightChecks($state['config']);

$state['preflight'] = $checks;
$state['current_step'] = 'checks';
$state['last_updated_at'] = date(DATE_ATOM);
$state['completed_steps'] = array_values(array_unique(array_merge($state['completed_steps'] ?? [], ['checks'])));
$state = InstallerRuntime::saveState($state);

InstallerRuntime::appendLog('Preflight checks executed.');
InstallerRuntime::jsonResponse([
    'state' => $state,
    'checks' => $checks,
    'preflight' => InstallerRuntime::buildKitPreflight($checks),
]);
