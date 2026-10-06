<?php

namespace App\Realtime\Observability;

use Illuminate\Support\Facades\Log;

class RealtimeLoopLagDiagnostics
{
    private int $warningCount = 0;
    private float $maxLagMs = 0;

    public function __construct(private readonly bool $tracing, private readonly RealtimeDiagnosticEmitter $emitter) {}

    public function observe(float $lagMs): void
    {
        if ($lagMs < 1000 || !is_finite($lagMs)) { return; }
        $this->warningCount = min(65535, $this->warningCount + 1);
        $this->maxLagMs = max($this->maxLagMs, $lagMs);
        if ($this->tracing) {
            $this->emitter->emit('warning', 'Realtime event loop delayed.', [
                'pid' => getmypid(), 'lag_ms' => round($lagMs, 3), 'warning_count' => $this->warningCount,
                'stage' => 'event.loop.lag',
                'observed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            ]);
        } else {
            Log::warning('Realtime event loop delayed.', ['pid' => getmypid(), 'lag_ms' => round($lagMs, 3)]);
        }
    }

    public function stats(): array
    {
        return ['warning_count' => $this->warningCount, 'count_saturated' => $this->warningCount === 65535, 'max_lag_ms' => $this->maxLagMs];
    }
}
