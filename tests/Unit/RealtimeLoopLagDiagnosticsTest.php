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
}
