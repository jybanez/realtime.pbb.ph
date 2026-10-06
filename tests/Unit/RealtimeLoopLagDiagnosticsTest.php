<?php

namespace Tests\Unit;

use App\Realtime\Observability\RealtimeDiagnosticEmitter;
use App\Realtime\Observability\RealtimeLoopLagDiagnostics;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RealtimeLoopLagDiagnosticsTest extends TestCase
{
    public function test_tracing_warning_uses_emitter_and_retains_counter_without_disk_fallback(): void
    {
        Log::spy();
        $emitter = new RealtimeDiagnosticEmitter(true, 0);
        $policy = new RealtimeLoopLagDiagnostics(true, $emitter);
        $policy->observe(999);
        $policy->observe(1500);
        $this->assertSame(1, $policy->stats()['warning_count']);
        $this->assertSame(1500.0, $policy->stats()['max_lag_ms']);
        $this->assertSame(1, $emitter->stats()['drops']['unavailable']);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_timing_off_retains_existing_warning(): void
    {
        Log::spy();
        $emitter = new RealtimeDiagnosticEmitter();
        $policy = new RealtimeLoopLagDiagnostics(false, $emitter);
        $policy->observe(1250);
        Log::shouldHaveReceived('warning')->with('Realtime event loop delayed.', ['pid' => getmypid(), 'lag_ms' => 1250.0])->once();
        $this->assertSame(0, $emitter->stats()['attempted']);
    }

    public function test_tracing_warning_has_occurrence_time_and_fixed_stage(): void
    {
        $emitter = \Mockery::mock(RealtimeDiagnosticEmitter::class);
        $emitter->shouldReceive('emit')->once()->with('warning', 'Realtime event loop delayed.', \Mockery::on(fn ($context) => $context['stage'] === 'event.loop.lag' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $context['observed_at']) === 1));
        (new RealtimeLoopLagDiagnostics(true, $emitter))->observe(1500);
    }
}
