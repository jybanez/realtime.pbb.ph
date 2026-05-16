<?php

require_once __DIR__ . '/lib/InstallerRuntime.php';

$status = InstallerRuntime::buildStatus();
echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
