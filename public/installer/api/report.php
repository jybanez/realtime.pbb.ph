<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

InstallerRuntime::jsonResponse([
    'report' => InstallerRuntime::loadReport(),
    'log' => InstallerRuntime::loadLog(),
    'service_template' => InstallerRuntime::renderServiceTemplate(InstallerRuntime::loadState()['config'] ?? InstallerRuntime::configTemplate()),
    'paths' => [
        'state' => InstallerRuntime::statePath(),
        'log' => InstallerRuntime::logPath(),
        'report' => InstallerRuntime::reportPath(),
        'manifest' => InstallerRuntime::manifestPath(),
        'completion' => InstallerRuntime::completionPath(),
        'generated' => InstallerRuntime::generatedDir(),
    ],
]);
