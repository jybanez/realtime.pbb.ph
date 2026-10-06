<?php

namespace Tests\Unit;

use App\Realtime\Observability\RealtimeMaestroTelemetryClient;
use App\Realtime\Observability\RealtimeProcessTelemetry;
use App\Realtime\Observability\RealtimeTelemetrySpool;
use App\Realtime\Settings\RealtimeRuntimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealtimeTelemetrySpoolTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/telemetry-'.bin2hex(random_bytes(5)));
        config([
            'realtime.maestro_telemetry.enabled' => true,
            'realtime.maestro_telemetry.base_url' => 'https://maestro.test',
            'realtime.maestro_telemetry.token' => 'test-telemetry-secret',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_gateway_lifecycle_never_sends_http_and_worker_uses_current_credentials(): void
    {
        Http::fake();
        $spool = new RealtimeTelemetrySpool($this->directory);
        $client = new RealtimeMaestroTelemetryClient(new RealtimeRuntimeSettings);
        $client->useSpool($spool);
        $process = new RealtimeProcessTelemetry($client, 'realtime:serve', 'websocket-gateway', '127.0.0.1', 8080);
        $process->start();
        $process->heartbeat();
        $process->stop();
        Http::assertNothingSent();
        foreach (glob($this->directory.'/*.json') as $path) {
            $this->assertStringNotContainsString('test-telemetry-secret', file_get_contents($path));
        }
        config(['realtime.maestro_telemetry.token' => 'rotated-secret']);
        $worker = new RealtimeMaestroTelemetryClient(new RealtimeRuntimeSettings);
        $this->assertGreaterThan(0, $spool->drain($worker));
        Http::assertSent(fn ($request) => $request->hasHeader('X-Telemetry-Token', 'rotated-secret'));
        $this->assertSame([], glob($this->directory.'/*.json'));
    }

    public function test_absent_worker_has_bounded_disk_growth_and_latest_snapshot_wins(): void
    {
        $spool = new RealtimeTelemetrySpool($this->directory, 4);
        for ($i = 0; $i < 100; $i++) {
            $spool->enqueue('heartbeat', ['worker_id' => 'worker-'.$i]);
        }
        $this->assertLessThanOrEqual(4, count(glob($this->directory.'/*.json')));
        $spool->enqueue('heartbeat', ['worker_id' => 'same-worker', 'processed_count' => 1]);
        $spool->enqueue('heartbeat', ['worker_id' => 'same-worker', 'processed_count' => 2]);
        $entries = array_map(fn ($path) => json_decode(file_get_contents($path), true), glob($this->directory.'/*.json'));
        $same = array_values(array_filter($entries, fn ($entry) => $entry['payload']['worker_id'] === 'same-worker'));
        $this->assertCount(1, $same);
        $this->assertSame(2, $same[0]['payload']['processed_count']);
    }

    public function test_busy_slot_drops_without_waiting_and_stale_or_corrupt_entries_are_discarded(): void
    {
        Http::fake();
        $spool = new RealtimeTelemetrySpool($this->directory, 1);
        $spool->enqueue('heartbeat', ['worker_id' => 'worker']);
        $lock = fopen($this->directory.'/0.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $started = microtime(true);
            $spool->enqueue('heartbeat', ['worker_id' => 'new-worker']);
            $this->assertLessThan(0.2, microtime(true) - $started);
        } finally {
            fclose($lock);
        }
        $entry = json_decode(file_get_contents($this->directory.'/0.json'), true);
        $entry['queued_at'] = time() - 61;
        file_put_contents($this->directory.'/0.json', json_encode($entry));
        $worker = new RealtimeMaestroTelemetryClient(new RealtimeRuntimeSettings);
        $this->assertSame(0, $spool->drain($worker));
        file_put_contents($this->directory.'/0.json', '{invalid');
        $this->assertSame(0, $spool->drain($worker));
        Http::assertNothingSent();
    }

    public function test_uncertain_timeout_is_consumed_once_without_replay(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $spool = new RealtimeTelemetrySpool($this->directory);
        $spool->enqueue('worker-event', ['worker_id' => 'worker', 'event_id' => 'event-1']);
        $worker = new RealtimeMaestroTelemetryClient(new RealtimeRuntimeSettings);
        $this->assertSame(1, $spool->drain($worker));
        $this->assertSame(0, $spool->drain($worker));
        $this->assertSame([], glob($this->directory.'/*.json'));
    }

    public function test_delivery_command_caps_timeouts_and_preserves_tls_and_loopback_auth(): void
    {
        config([
            'realtime.telemetry_spool_path' => $this->directory,
            'realtime.maestro_telemetry.base_url' => 'https://maestro.pbb.ph',
            'realtime.maestro_telemetry.local_bypass_enabled' => true,
            'realtime.maestro_telemetry.local_bypass_base_url' => 'http://127.0.0.1',
            'realtime.maestro_telemetry.connect_timeout_seconds' => 10,
            'realtime.maestro_telemetry.timeout_seconds' => 20,
        ]);
        $observedOptions = null;
        Http::fake(function ($request, $options) use (&$observedOptions) {
            $observedOptions = $options;
            return Http::response(['ok' => true]);
        });
        (new RealtimeTelemetrySpool($this->directory))->enqueue('heartbeat', ['worker_id' => 'command-worker']);
        $this->artisan('realtime:dispatch-telemetry', ['--once' => true])->assertExitCode(0);
        $this->assertSame(3, $observedOptions['connect_timeout']);
        $this->assertSame(5, $observedOptions['timeout']);
        $this->assertTrue($observedOptions['verify']);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1/api/v1/telemetry/workers/heartbeat'
            && $request->hasHeader('Host', 'maestro.pbb.ph')
            && $request->hasHeader('X-Telemetry-Token', 'test-telemetry-secret'));
    }

    public function test_gateway_heartbeat_uses_startup_settings_without_database_reads(): void
    {
        Http::fake();
        $client = new RealtimeMaestroTelemetryClient(new RealtimeRuntimeSettings());
        $client->useSpool(new RealtimeTelemetrySpool($this->directory));
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) { $queries++; });
        $process = new RealtimeProcessTelemetry($client, 'realtime:serve', 'websocket-gateway', '127.0.0.1', 8080);
        $process->start();
        $process->heartbeat();
        $process->stop();
        $this->assertSame(0, $queries);
        Http::assertNothingSent();
    }
}
