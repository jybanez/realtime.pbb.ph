<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

$state = InstallerRuntime::loadState();
$config = $state['config'] ?? InstallerRuntime::configTemplate();
$service = InstallerRuntime::renderServiceTemplate($config);
$path = $service['artifact_path'] ?? null;

if (! is_string($path) || ! is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Generated service artifact not found.';
    exit;
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$contentType = $extension === 'service'
    ? 'text/plain; charset=utf-8'
    : 'text/x-powershell; charset=utf-8';

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
