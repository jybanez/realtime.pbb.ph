<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

$path = InstallerRuntime::logPath();

if (! is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Installer log not found.';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="pbb-realtime-installer.log"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
