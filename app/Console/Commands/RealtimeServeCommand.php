<?php

namespace App\Console\Commands;

use App\Realtime\Auth\RealtimeTokenValidator;
use App\Realtime\Ingress\RealtimeEventPublishDispatcher;
use App\Realtime\Media\RealtimeMediaChunkDispatcher;
use App\Realtime\Media\RealtimeMediaChunkForwarder;
use App\Realtime\Media\RealtimeMediaChunkQueue;
use App\Realtime\Observability\RealtimeMaestroTelemetryClient;
use App\Realtime\Observability\RealtimeMetrics;
use App\Realtime\Observability\RealtimeProcessTelemetry;
use App\Realtime\Observability\RealtimeUsageTelemetry;
use App\Realtime\ProductQuery\RealtimeProductQueryForwarder;
use App\Realtime\Rooms\RealtimeRoomPolicy;
use App\Realtime\Sessions\RealtimeSessionRecorder;
use App\Realtime\WebSocket\RealtimeGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Ratchet\App as RatchetApp;
use React\EventLoop\Factory as LoopFactory;
use Throwable;

class RealtimeServeCommand extends Command
{
    protected $signature = 'realtime:serve {--host= : Bind host override} {--port= : Bind port override}';

    protected $description = 'Start the PBB Realtime websocket server.';

    public function handle(
        RealtimeTokenValidator $tokenValidator,
        RealtimeRoomPolicy $roomPolicy,
        RealtimeMetrics $metrics,
        RealtimeSessionRecorder $sessionRecorder,
        RealtimeUsageTelemetry $telemetry,
        RealtimeMediaChunkQueue $mediaChunkQueue,
        RealtimeMediaChunkDispatcher $mediaChunkDispatcher,
        RealtimeEventPublishDispatcher $eventDispatcher,
        RealtimeMaestroTelemetryClient $maestroTelemetryClient
    ): int {
        $this->logBootStage('boot.start');

        if (! $this->ensureDatabaseConnection()) {
            $this->logBootStage('boot.failed.database');
            return self::FAILURE;
        }

        $this->logBootStage('boot.database.ready');

        $bindAddress = (string) ($this->option('host') ?: config('realtime.ws_bind_address', config('realtime.ws_host', '127.0.0.1')));
        $publicHost = (string) config('realtime.ws_public_host', 'localhost');
        $port = (int) ($this->option('port') ?: config('realtime.ws_port', 8080));
        $allowedOrigins = config('realtime.allowed_origins', []);
        $loop = LoopFactory::create();

        $this->logBootStage('boot.config.loaded', [
            'bind_address' => $bindAddress,
            'public_host' => $publicHost,
            'port' => $port,
            'allowed_origins_count' => is_array($allowedOrigins) ? count($allowedOrigins) : 0,
        ]);

        if (extension_loaded('xdebug') && getenv('RATCHET_DISABLE_XDEBUG_WARN') === false) {
            putenv('RATCHET_DISABLE_XDEBUG_WARN=1');
            $this->logBootStage('boot.xdebug.warn_suppressed');
        }

        $this->info(sprintf(
            'Starting %s on %s:%d (public host: %s)',
            config('realtime.service_name'),
            $bindAddress,
            $port,
            $publicHost
        ));
        $this->logBootStage('boot.starting_banner_printed');

        $gateway = new RealtimeGateway(
            $tokenValidator,
            $roomPolicy,
            $metrics,
            $sessionRecorder,
            $telemetry,
            $mediaChunkQueue,
            new RealtimeProductQueryForwarder(),
            (string) config('realtime.service_name'),
            (int) config('realtime.heartbeat_interval_seconds', 30),
            (int) config('realtime.presence_stale_seconds', 90),
            (int) config('realtime.message_rate_limit_per_minute', 120),
            (int) config('realtime.room_join_rate_limit_per_minute', 30),
            (int) config('realtime.max_rooms_per_session', 50)
        );
        $this->logBootStage('boot.gateway.ready');

        $processTelemetry = new RealtimeProcessTelemetry(
            $maestroTelemetryClient,
            'realtime:serve',
            'websocket-gateway',
            $bindAddress,
            $port,
            app()->version()
        );
        $this->logBootStage('boot.process_telemetry.ready');

        register_shutdown_function(static function () use ($processTelemetry): void {
            $processTelemetry->stop('process shutdown');
        });
        $this->logBootStage('boot.shutdown_handler.registered');

        $app = new RatchetApp($publicHost, $port, $bindAddress, $loop);
        $this->logBootStage('boot.ratchet_app.ready');
        $app->route(
            '/realtime',
            $gateway,
            is_array($allowedOrigins) ? $allowedOrigins : []
        );
        $this->logBootStage('boot.websocket_route.registered', [
            'path' => '/realtime',
        ]);

        $processTelemetry->start();
        $this->logBootStage('boot.process_telemetry.started');

        $loop->addPeriodicTimer(
            max(1, (int) config('realtime.event_publish_drain_interval_seconds', 1)),
            static function () use ($eventDispatcher, $gateway, $processTelemetry, $mediaChunkDispatcher): void {
                $result = $eventDispatcher->drain(
                    $gateway,
                    (int) config('realtime.event_publish_drain_batch_size', 100)
                );

                $mediaResult = ['processed' => 0, 'failed' => 0];
                if ((bool) config('realtime.embedded_media_chunk_dispatch_enabled', true)) {
                    $mediaResult = $mediaChunkDispatcher->drain(
                        (int) config('realtime.media_chunk_dispatch_batch_size', 25),
                        $gateway
                    );
                }

                $processTelemetry->recordDispatch(
                    $result['processed'] + $mediaResult['processed'],
                    $result['failed'] + $mediaResult['failed']
                );
            }
        );
        $this->logBootStage('boot.dispatch_timer.registered', [
            'interval_seconds' => max(1, (int) config('realtime.event_publish_drain_interval_seconds', 1)),
            'embedded_media_chunk_dispatch_enabled' => (bool) config('realtime.embedded_media_chunk_dispatch_enabled', true),
            'media_chunk_dispatch_batch_size' => (int) config('realtime.media_chunk_dispatch_batch_size', 25),
        ]);

        $loop->addPeriodicTimer(
            (int) config('realtime.maestro_telemetry.heartbeat_seconds', 15),
            static function () use ($processTelemetry): void {
                $processTelemetry->heartbeat('idle');
            }
        );
        $this->logBootStage('boot.telemetry_timer.registered', [
            'interval_seconds' => (int) config('realtime.maestro_telemetry.heartbeat_seconds', 15),
        ]);

        $this->logBootStage('boot.event_loop.running');
        $app->run();
        $this->logBootStage('boot.event_loop.stopped');

        return self::SUCCESS;
    }

    private function ensureDatabaseConnection(): bool
    {
        $connectionName = (string) config('database.default', 'mysql');
        $host = (string) config("database.connections.{$connectionName}.host", '');
        $port = (string) config("database.connections.{$connectionName}.port", '');
        $database = (string) config("database.connections.{$connectionName}.database", '');

        try {
            DB::connection($connectionName)->getPdo();
        } catch (Throwable $e) {
            $this->error(sprintf(
                'Database connection failed before websocket startup for `%s` (%s:%s / %s).',
                $connectionName,
                $host !== '' ? $host : 'n/a',
                $port !== '' ? $port : 'n/a',
                $database !== '' ? $database : 'n/a'
            ));

            $this->line('Reason: ' . $e->getMessage());

            if (strtolower(trim($host)) === 'localhost') {
                $this->warn('On Windows/WAMP, set `DB_HOST=127.0.0.1` if MySQL is only listening on IPv4.');
            }

            $this->warn('Start MySQL and confirm the database settings in `.env`, then retry `php artisan realtime:serve`.');

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

        $path = storage_path('logs/realtime-serve-smoke.log');

        try {
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Ignore smoke-log write failures so the websocket server can still boot.
        }

        $this->line(sprintf('[boot] %s', $stage));
    }
}
