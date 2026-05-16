<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

$path = InstallerRuntime::reportPath();

if (! is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Installer report not found.';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="pbb-realtime-installer-report.json"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
