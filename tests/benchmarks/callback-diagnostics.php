<?php

// Standalone synthetic observer benchmark: no server, database or live logs.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Realtime\Observability\RealtimeCallbackDiagnostics;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$path = tempnam(sys_get_temp_dir(), 'rt-diagnostic-bench-');
$logger = new Logger('benchmark', [new StreamHandler($path, Logger::INFO)]);
$query = new QueryExecuted('SELECT 1', [], 0.1, new Connection(null));
$rounds = [];
try {
    foreach (range(1, 5) as $round) {
        foreach (['off', 'aggregate', 'disk_log'] as $mode) {
            $diagnostics = new RealtimeCallbackDiagnostics();
            $start = hrtime(true);
            for ($request = 0; $request < 1000; ++$request) {
                if ($mode === 'off') { continue; }
                $diagnostics->begin('request', ['request_id' => 'synthetic-'.$request]);
                foreach (range(1, 8) as $stage) {
                    $diagnostics->begin('child', []);
                    $diagnostics->query($query);
                    $context = $diagnostics->end(0.1);
                    if ($mode === 'disk_log') { $logger->info('Synthetic callback timing.', $context); }
                }
                $context = $diagnostics->end(1);
                if ($mode === 'disk_log') { $logger->info('Synthetic callback timing.', $context); }
            }
            $rounds[$mode][] = (hrtime(true) - $start) / 1e6 / 1000;
        }
    }
    foreach ($rounds as $mode => $samples) {
        sort($samples);
        echo json_encode(['mode' => $mode, 'median_ms_per_request' => $samples[2],
            'min_ms' => min($samples), 'max_ms' => max($samples), 'requests_per_round' => 1000,
            'children_per_request' => 8, 'rounds' => 5]).PHP_EOL;
    }
} finally {
    $logger->close();
    unlink($path);
}
