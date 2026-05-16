<?php

namespace App\Realtime\Observability;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class RealtimeProcessTelemetry
{
    private string $workerId;
    private string $hostName;
    private int $processId;
    private CarbonImmutable $startedAt;
    private int $processedCount = 0;
    private int $failedCount = 0;
    private bool $stopped = false;

    public function __construct(
        private readonly RealtimeMaestroTelemetryClient $client,
        private readonly string $command,
        private readonly string $role,
        private readonly string $listenHost,
        private readonly int $listenPort,
        private readonly ?string $buildVersion = null,
    ) {
        $this->hostName = gethostname() ?: php_uname('n') ?: 'unknown-host';
        $this->processId = max(1, (int) getmypid());
        $this->startedAt = CarbonImmutable::now();
        $this->workerId = sprintf(
            '%s:%s:%d:%s:%s',
            $this->command,
            $this->hostName,
            $this->processId,
            $this->startedAt->format('YmdHis'),
            Str::lower(Str::random(6))
        );
    }

    public function start(): void
    {
        $this->client->sendWorkerEvent([
            'event_id' => (string) Str::uuid(),
            'app_code' => $this->appCode(),
            'worker_id' => $this->workerId,
            'event_type' => 'worker.started',
            'queue_name' => null,
            'job_id' => null,
            'occurred_at' => $this->startedAt->toIso8601String(),
            'payload' => $this->baseMeta(),
        ]);

        $this->heartbeat('idle', $this->startedAt);
    }

    public function heartbeat(string $status = 'idle', ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        $this->client->sendHeartbeat([
            'app_code' => $this->appCode(),
            'worker_id' => $this->workerId,
            'host_name' => $this->hostName,
            'queue_name' => null,
            'process_id' => $this->processId,
            'status' => $status,
            'started_at' => $this->startedAt->toIso8601String(),
            'last_heartbeat_at' => $at->toIso8601String(),
            'current_job_type' => null,
            'current_job_id' => null,
            'processed_count' => $this->processedCount,
            'failed_count' => $this->failedCount,
            'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
            'meta' => $this->baseMeta(),
        ]);

        $this->client->sendWorkerEvent([
            'event_id' => (string) Str::uuid(),
            'app_code' => $this->appCode(),
            'worker_id' => $this->workerId,
            'event_type' => 'worker.heartbeat',
            'queue_name' => null,
            'job_id' => null,
            'occurred_at' => $at->toIso8601String(),
            'payload' => $this->baseMeta(),
        ]);
    }

    public function recordDispatch(int $processed, int $failed): void
    {
        $this->processedCount += max(0, $processed);
        $this->failedCount += max(0, $failed);
    }

    public function stop(?string $notes = null): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $at = CarbonImmutable::now();

        $payload = $this->baseMeta();
        if (is_string($notes) && trim($notes) !== '') {
            $payload['notes'] = trim($notes);
        }

        $this->client->sendWorkerEvent([
            'event_id' => (string) Str::uuid(),
            'app_code' => $this->appCode(),
            'worker_id' => $this->workerId,
            'event_type' => 'worker.stopped',
            'queue_name' => null,
            'job_id' => null,
            'occurred_at' => $at->toIso8601String(),
            'payload' => $payload,
        ]);

        $this->client->sendHeartbeat([
            'app_code' => $this->appCode(),
            'worker_id' => $this->workerId,
            'host_name' => $this->hostName,
            'queue_name' => null,
            'process_id' => $this->processId,
            'status' => 'stopped',
            'started_at' => $this->startedAt->toIso8601String(),
            'last_heartbeat_at' => $at->toIso8601String(),
            'current_job_type' => null,
            'current_job_id' => null,
            'processed_count' => $this->processedCount,
            'failed_count' => $this->failedCount,
            'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
            'meta' => $payload,
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseMeta(): array
    {
        return array_filter([
            'command' => $this->command,
            'role' => $this->role,
            'listen_host' => $this->listenHost,
            'listen_port' => $this->listenPort,
            'build_version' => $this->buildVersion,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function appCode(): string
    {
        return $this->client->appCode();
    }
}
