<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$realtimeBootstrapTrace = [
    [
        'stage' => 'index.start',
        'elapsed_ms' => 0.0,
    ],
];

$recordRealtimeBootstrapStage = static function (string $stage) use (&$realtimeBootstrapTrace): void {
    $realtimeBootstrapTrace[] = [
        'stage' => $stage,
        'elapsed_ms' => round((microtime(true) - LARAVEL_START) * 1000, 3),
    ];
};

$GLOBALS['realtime_bootstrap_trace'] = &$realtimeBootstrapTrace;

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

$recordRealtimeBootstrapStage('maintenance.checked');

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

require __DIR__.'/../vendor/autoload.php';

$recordRealtimeBootstrapStage('autoload.loaded');

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

$recordRealtimeBootstrapStage('app.created');

$kernel = $app->make(Kernel::class);

$recordRealtimeBootstrapStage('kernel.resolved');

$recordRealtimeBootstrapStage('request.capture.start');

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$recordRealtimeBootstrapStage('response.sent');

$kernel->terminate($request, $response);
