<?php

use App\Realtime\Observability\RealtimeMaestroTelemetryClient;
use App\Realtime\Observability\RealtimeTelemetrySpool;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// Dedicated process: model a slow Maestro response and an uncertain timeout.
$stage = function (string $name) use ($argv): void {
    file_put_contents($argv[1].'/startup.jsonl', json_encode(['stage' => $name, 'pid' => getmypid(), 'at' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
};
$stage('php.entry');
require dirname(__DIR__, 2).'/vendor/autoload.php';
$stage('autoload.complete');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$stage('kernel.bootstrap.start');
$app->make(Kernel::class)->bootstrap();
$stage('kernel.bootstrap.complete');
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'realtime.maestro_telemetry.enabled' => true,
    'realtime.maestro_telemetry.base_url' => 'https://maestro.test',
    'realtime.maestro_telemetry.token' => 'fixture-secret',
]);
Http::fake(function () use ($argv, $stage) {
    $stage('http.fake.entry');
    file_put_contents($argv[2], 'sending');
    $stage('sending.marker');
    usleep(2000000);
    if ($argv[3] === 'timeout') {
        throw new ConnectionException('Simulated Maestro timeout');
    }

    return Http::response(['ok' => true]);
});
$spool = new RealtimeTelemetrySpool($argv[1]);
$spool->drain($app->make(RealtimeMaestroTelemetryClient::class));
