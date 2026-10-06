<?php

use App\Realtime\Observability\RealtimeMaestroTelemetryClient;
use App\Realtime\Observability\RealtimeTelemetrySpool;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// Dedicated process: model a slow Maestro response and an uncertain timeout.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'realtime.maestro_telemetry.enabled' => true,
    'realtime.maestro_telemetry.base_url' => 'https://maestro.test',
    'realtime.maestro_telemetry.token' => 'fixture-secret',
]);
Http::fake(function () use ($argv) {
    file_put_contents($argv[2], 'sending');
    usleep(2000000);
    if ($argv[3] === 'timeout') {
        throw new ConnectionException('Simulated Maestro timeout');
    }

    return Http::response(['ok' => true]);
});
$spool = new RealtimeTelemetrySpool($argv[1]);
$spool->drain($app->make(RealtimeMaestroTelemetryClient::class));
