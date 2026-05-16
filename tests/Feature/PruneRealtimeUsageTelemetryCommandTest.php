<?php

namespace Tests\Feature;

use App\Models\RealtimeUsageBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneRealtimeUsageTelemetryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_usage_buckets_older_than_retention_window(): void
    {
        RealtimeUsageBucket::query()->create([
            'bucket_start' => now()->subDays(120)->startOfHour(),
            'bucket_granularity' => 'hour',
            'client_code' => 'clt_old_001',
            'project_code' => 'prj_old_001',
            'event_type' => 'chat.publish',
            'event_count' => 4,
            'bytes_in' => 256,
            'bytes_out' => 512,
            'error_count' => 0,
            'rate_limited_count' => 0,
        ]);

        RealtimeUsageBucket::query()->create([
            'bucket_start' => now()->subDays(10)->startOfHour(),
            'bucket_granularity' => 'hour',
            'client_code' => 'clt_keep_001',
            'project_code' => 'prj_keep_001',
            'event_type' => 'chat.publish',
            'event_count' => 2,
            'bytes_in' => 128,
            'bytes_out' => 256,
            'error_count' => 0,
            'rate_limited_count' => 0,
        ]);

        $this->artisan('realtime:prune-usage-telemetry', ['--days' => 90])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('realtime_usage_buckets', [
            'client_code' => 'clt_old_001',
        ]);

        $this->assertDatabaseHas('realtime_usage_buckets', [
            'client_code' => 'clt_keep_001',
        ]);
    }
}
