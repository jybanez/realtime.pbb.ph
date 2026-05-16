<?php

require_once dirname(__DIR__, 3) . '/installer/lib/InstallerRuntime.php';

InstallerRuntime::jsonResponse(InstallerRuntime::buildStatus());
