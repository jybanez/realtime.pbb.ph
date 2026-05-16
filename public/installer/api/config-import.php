<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

$input = InstallerRuntime::jsonInput();
$payload = $input['config'] ?? [];

if (is_string($payload)) {
    $decoded = json_decode($payload, true);
    if (! is_array($decoded)) {
        InstallerRuntime::jsonResponse([
            'message' => 'Invalid JSON config payload.',
        ], 422);
    }
    $payload = $decoded;
}

$normalized = InstallerRuntime::normalizeImportedConfig(is_array($payload) ? $payload : []);
$state = InstallerRuntime::saveConfig($normalized);
$state['current_step'] = 'config';
$state['last_updated_at'] = date(DATE_ATOM);
$state = InstallerRuntime::saveState($state);

InstallerRuntime::appendLog('Installer config imported.');

InstallerRuntime::jsonResponse([
    'state' => $state,
    'message' => 'Installer config imported.',
]);
