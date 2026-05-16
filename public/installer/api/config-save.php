<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

$input = InstallerRuntime::jsonInput();
$config = InstallerRuntime::normalizeImportedConfig($input['config'] ?? []);
$step = (string) ($input['current_step'] ?? 'welcome');

$errors = InstallerRuntime::validateDraftConfig($config);

if ($step !== 'welcome' && $step !== 'checks' && ! empty($errors)) {
    InstallerRuntime::jsonResponse([
        'message' => 'Installer configuration is incomplete.',
        'errors' => $errors,
    ], 422);
}

$state = InstallerRuntime::saveConfig($config);
$state['current_step'] = $step;
$state['last_updated_at'] = date(DATE_ATOM);
$state = InstallerRuntime::saveState($state);

InstallerRuntime::appendLog("Installer draft saved for step {$step}.");

InstallerRuntime::jsonResponse([
    'state' => $state,
    'message' => 'Installer draft saved.',
]);
