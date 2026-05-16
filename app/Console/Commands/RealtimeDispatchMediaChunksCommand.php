<?php

namespace App\Console\Commands;

use App\Realtime\Media\RealtimeMediaChunkDispatcher;
use App\Realtime\Observability\RealtimeMaestroTelemetryClient;
use App\Realtime\Observability\RealtimeProcessTelemetry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class RealtimeDispatchMediaChunksCommand extends Command
{
    protected $signature = 'realtime:dispatch
        {--once : Drain one batch and exit}
        {--sleep=1 : Seconds to sleep between drain attempts}
        {--limit= : Batch size override}';

    protected $description = 'Forward queued Realtime media chunks to downstream ingest and queue outcome events.';

    public function handle(
        RealtimeMediaChunkDispatcher $dispatcher,
        RealtimeMaestroTelemetryClient $maestroTelemetryClient
    ): int {
        $this->logBootStage('boot.start');

        if (!$this->ensureDatabaseConnection()) {
            $this->logBootStage('boot.failed.database');
            return self::FAILURE;
        }

        $this->logBootStage('boot.database.ready');

        $limit = (int) ($this->option('limit') ?: config('realtime.media_chunk_dispatch_batch_size', 25));
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $once = (bool) $this->option('once');
        $claimTimeoutSeconds = (int) config('realtime.media_chunk_dispatch_claim_timeout_seconds', 300);

        $this->logBootStage('boot.config.loaded', [
            'limit' => $limit,
            'sleep_seconds' => $sleepSeconds,
            'once' => $once,
            'claim_timeout_seconds' => $claimTimeoutSeconds,
        ]);

        if (extension_loaded('xdebug')) {
            $this->logBootStage('boot.xdebug.loaded');
        }

        $telemetry = new RealtimeProcessTelemetry(
            $maestroTelemetryClient,
            'realtime:dispatch',
            'media-chunk-dispatcher',
            'n/a',
            0,
            app()->version()
        );
        $this->logBootStage('boot.process_telemetry.ready');

        register_shutdown_function(static function () use ($telemetry): void {
            $telemetry->stop('process shutdown');
        });
        $this->logBootStage('boot.shutdown_handler.registered');

        $telemetry->start();
        $this->logBootStage('boot.process_telemetry.started');
        $this->info(sprintf(
            'Starting Realtime media chunk dispatcher (limit: %d, sleep: %ds, once: %s).',
            $limit,
            $sleepSeconds,
            $once ? 'yes' : 'no'
        ));
        $this->logBootStage('boot.starting_banner_printed');

        $this->logBootStage('boot.dispatch_loop.running');
        do {
            $result = $dispatcher->drain($limit, null, queueOutcomesWhenNoGateway: true);
            $telemetry->recordDispatch($result['processed'], $result['failed']);

            if ($result['processed'] > 0 || $result['failed'] > 0) {
                $this->line(sprintf(
                    'Drained media chunks: processed=%d failed=%d',
                    $result['processed'],
                    $result['failed']
                ));
                $telemetry->heartbeat('busy');
            } else {
                $telemetry->heartbeat('idle');
            }

            if ($once) {
                break;
            }

            sleep($sleepSeconds);
        } while (true);
        $this->logBootStage('boot.dispatch_loop.stopped');

        $telemetry->stop($once ? 'one-shot drain complete' : null);

        return self::SUCCESS;
    }

    private function ensureDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('Database connection failed before media dispatcher startup.');
            $this->line('Reason: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logBootStage(string $stage, array $context = []): void
    {
        $record = array_filter([
            'ts' => now()->toIso8601String(),
            'pid' => getmypid(),
            'stage' => $stage,
            'context' => $context !== [] ? $context : null,
        ], static fn ($value) => $value !== null);

        $path = storage_path('logs/realtime-dispatch-smoke.log');

        try {
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Ignore smoke-log write failures so the worker can still boot.
        }

        $this->line(sprintf('[boot] %s', $stage));
    }
}
