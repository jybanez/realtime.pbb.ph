<?php

namespace App\Console\Commands;

use App\Models\RealtimeUsageBucket;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PruneRealtimeUsageTelemetryCommand extends Command
{
    protected $signature = 'realtime:prune-usage-telemetry {--days= : Override retention days}';

    protected $description = 'Prune persisted realtime usage telemetry buckets older than the retention window.';

    public function handle(): int
    {
        $retentionDays = (int) ($this->option('days') ?: config('realtime.usage_telemetry_retention_days', 90));
        $retentionDays = max(1, $retentionDays);
        $cutoff = CarbonImmutable::now('UTC')->subDays($retentionDays)->startOfHour();

        $deleted = RealtimeUsageBucket::query()
            ->where('bucket_start', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Pruned %d realtime usage bucket(s) older than %s (%d day retention).',
            $deleted,
            $cutoff->toIso8601String(),
            $retentionDays
        ));

        return self::SUCCESS;
    }
}
