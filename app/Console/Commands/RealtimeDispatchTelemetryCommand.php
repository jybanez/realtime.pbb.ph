<?php

namespace App\Console\Commands;

use App\Realtime\Observability\RealtimeMaestroTelemetryClient;
use App\Realtime\Observability\RealtimeTelemetrySpool;
use Illuminate\Console\Command;

class RealtimeDispatchTelemetryCommand extends Command
{
    protected $signature = 'realtime:dispatch-telemetry {--once : Drain once and exit}';

    protected $description = 'Deliver best-effort Maestro telemetry outside the websocket process.';

    public function handle(RealtimeMaestroTelemetryClient $client): int
    {
        $spool = new RealtimeTelemetrySpool(
            (string) config('realtime.telemetry_spool_path'),
            (int) config('realtime.telemetry_spool_slots', 128)
        );
        do {
            $spool->drain($client);
            if (! $this->option('once')) {
                usleep(250000);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
