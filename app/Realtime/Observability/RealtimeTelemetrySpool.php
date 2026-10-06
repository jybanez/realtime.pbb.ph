<?php

namespace App\Realtime\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

/** Best-effort, bounded local telemetry. Never contains transport credentials. */
class RealtimeTelemetrySpool
{
    private float $lastConsumptionWarningAt = -INF;

    public function __construct(private readonly string $directory, private readonly int $slots = 128) {}

    public function enqueue(string $kind, array $payload): void
    {
        try {
            if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
                throw new \RuntimeException('Cannot create telemetry spool.');
            }
            // Fixed slots bound disk growth even with no consumer. Latest snapshots win.
            $key = ($payload['worker_id'] ?? '').':'.$kind.':'.($payload['event_type'] ?? '');
            $slot = hexdec(substr(hash('sha256', $key), 0, 7)) % max(1, min(1024, $this->slots));
            $path = $this->directory.DIRECTORY_SEPARATOR.$slot.'.json';
            $lock = fopen($this->directory.DIRECTORY_SEPARATOR.$slot.'.lock', 'c');
            if ($lock === false) {
                throw new \RuntimeException('Cannot open telemetry spool lock.');
            }
            try {
                // Never wait for the consumer or another producer in the gateway.
                if (! flock($lock, LOCK_EX | LOCK_NB)) {
                    return;
                }
                $json = json_encode(['kind' => $kind, 'queued_at' => time(), 'payload' => $payload], JSON_THROW_ON_ERROR);
                if (strlen($json) > 32768) {
                    return;
                }
                if (file_put_contents($path, $json) === false) {
                    throw new \RuntimeException('Cannot write telemetry spool.');
                }
            } finally {
                fclose($lock);
            }
        } catch (Throwable $e) {
            Log::warning('Realtime telemetry snapshot dropped.', ['exception' => $e::class]);
        }
    }

    public function drain(RealtimeMaestroTelemetryClient $client): int
    {
        $processed = 0;
        // Scan a fixed number of slots, never an unbounded directory listing.
        for ($slot = 0; $slot < max(1, min(1024, $this->slots)); $slot++) {
            $path = $this->directory.DIRECTORY_SEPARATOR.$slot.'.json';
            if (! is_file($path)) {
                continue;
            }
            $lock = fopen($this->directory.DIRECTORY_SEPARATOR.$slot.'.lock', 'c');
            if ($lock === false) {
                continue;
            }
            try {
                if (! flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $json = is_file($path) ? file_get_contents($path, false, null, 0, 32769) : false;
                // Consume before network I/O: an uncertain HTTP outcome is never replayed.
                if (! $this->consumeRecord($path)) {
                    // Retain the unsent snapshot; future drains may consume it safely.
                    // Never deliver when durable consumption is unconfirmed.
                    $now = hrtime(true) / 1000000000;
                    if ($now - $this->lastConsumptionWarningAt >= 30) {
                        $this->lastConsumptionWarningAt = $now;
                        Log::warning('Realtime telemetry consumption failed; delivery skipped. Check spool permissions and file sharing.', ['slot' => $slot]);
                    }
                    continue;
                }
            } finally {
                fclose($lock);
            }
            try {
                $entry = is_string($json) ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
                if (! is_array($entry) || ! is_array($entry['payload'] ?? null)
                    || time() - (int) ($entry['queued_at'] ?? 0) > 60) {
                    continue;
                }
                if (($entry['kind'] ?? '') === 'heartbeat') {
                    $client->sendHeartbeat($entry['payload']);
                } elseif (($entry['kind'] ?? '') === 'worker-event') {
                    $client->sendWorkerEvent($entry['payload']);
                } else {
                    continue;
                }
                $processed++;
            } catch (Throwable $e) {
                Log::warning('Realtime telemetry snapshot discarded.', ['exception' => $e::class]);
            }
        }

        return $processed;
    }

    protected function consumeRecord(string $path): bool
    {
        // Suppress the filesystem warning; drain emits throttled, actionable feedback.
        return @unlink($path);
    }
}
