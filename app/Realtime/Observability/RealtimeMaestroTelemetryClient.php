<?php

namespace App\Realtime\Observability;

use App\Realtime\Settings\RealtimeRuntimeSettings;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeMaestroTelemetryClient
{
    public function __construct(
        private readonly RealtimeRuntimeSettings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendHeartbeat(array $payload): void
    {
        $this->post('/api/v1/telemetry/workers/heartbeat', $payload, 'heartbeat');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendWorkerEvent(array $payload): void
    {
        $this->post('/api/v1/telemetry/worker-events', $payload, 'worker-event');
    }

    public function isEnabled(): bool
    {
        $config = $this->settings->maestroTelemetry();

        return (bool) ($config['enabled'] ?? false)
            && $this->stringValue($config['base_url'] ?? null) !== null
            && $this->stringValue($config['token'] ?? null) !== null
            && $this->stringValue($config['app_code'] ?? null) !== null;
    }

    public function appCode(): string
    {
        return (string) ($this->settings->maestroTelemetry()['app_code'] ?? 'realtime');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $path, array $payload, string $kind): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $config = $this->settings->maestroTelemetry();
        $requestTarget = $this->requestTarget($config, $path);
        $url = $requestTarget['url'];
        $tokenHeader = (string) ($config['token_header'] ?? 'X-Telemetry-Token');
        $token = (string) $config['token'];
        $handlerStats = [];

        try {
            $verify = (bool) ($config['verify_tls'] ?? true);
            $caBundle = $this->stringValue($config['ca_bundle'] ?? null);
            if ($verify && $caBundle !== null && is_file($caBundle)) {
                $verify = $caBundle;
            }

            $response = Http::acceptJson()
                ->withHeaders(array_filter([
                    $tokenHeader => $token,
                    'Host' => $requestTarget['host_header'],
                ]))
                ->connectTimeout((int) ($config['connect_timeout_seconds'] ?? 3))
                ->timeout((int) ($config['timeout_seconds'] ?? 5))
                ->withOptions([
                    'verify' => $verify,
                    'on_stats' => static function (TransferStats $stats) use (&$handlerStats): void {
                        $handlerStats = $stats->getHandlerStats();
                    },
                ])
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::warning('Realtime Maestro telemetry request failed.', [
                    'kind' => $kind,
                    'url' => $url,
                    'base_url' => $config['base_url'] ?? null,
                    'host_header' => $requestTarget['host_header'],
                    'status' => $response->status(),
                    'timing' => $this->normalizeHandlerStats($response->handlerStats()),
                    'response' => $response->json() ?: $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Realtime Maestro telemetry request threw an exception.', [
                'kind' => $kind,
                'url' => $url,
                'base_url' => $config['base_url'] ?? null,
                'host_header' => $requestTarget['host_header'],
                'message' => $e->getMessage(),
                'exception' => $e::class,
                'timing' => $this->normalizeHandlerStats($handlerStats),
                'connect_timeout_seconds' => (int) ($config['connect_timeout_seconds'] ?? 3),
                'timeout_seconds' => (int) ($config['timeout_seconds'] ?? 5),
                'verify_tls' => (bool) ($config['verify_tls'] ?? true),
                'ca_bundle_configured' => $this->stringValue($config['ca_bundle'] ?? null) !== null,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{url: string, host_header: ?string}
     */
    private function requestTarget(array $config, string $path): array
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');

        if (!(bool) ($config['local_bypass_enabled'] ?? false)) {
            return [
                'url' => $baseUrl . $path,
                'host_header' => null,
            ];
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || trim($host) !== 'maestro.pbb.ph') {
            return [
                'url' => $baseUrl . $path,
                'host_header' => null,
            ];
        }

        $bypassBaseUrl = rtrim((string) ($config['local_bypass_base_url'] ?? 'http://127.0.0.1'), '/');
        $hostHeader = trim((string) ($config['local_bypass_host'] ?? 'maestro.pbb.ph'));

        return [
            'url' => $bypassBaseUrl . $path,
            'host_header' => $hostHeader !== '' ? $hostHeader : null,
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function normalizeHandlerStats(array $stats): array
    {
        if ($stats === []) {
            return [];
        }

        $httpVersion = $stats['http_version'] ?? null;
        if (is_numeric($httpVersion)) {
            $httpVersion = match ((int) $httpVersion) {
                1 => '1.0',
                2 => '1.1',
                3 => '2',
                4 => '3',
                default => (string) $httpVersion,
            };
        }

        return array_filter([
            'primary_ip' => $stats['primary_ip'] ?? null,
            'primary_port' => $stats['primary_port'] ?? null,
            'local_ip' => $stats['local_ip'] ?? null,
            'local_port' => $stats['local_port'] ?? null,
            'http_version' => $httpVersion,
            'namelookup_time_ms' => $this->milliseconds($stats['namelookup_time'] ?? null),
            'connect_time_ms' => $this->milliseconds($stats['connect_time'] ?? null),
            'appconnect_time_ms' => $this->milliseconds($stats['appconnect_time'] ?? null),
            'pretransfer_time_ms' => $this->milliseconds($stats['pretransfer_time'] ?? null),
            'starttransfer_time_ms' => $this->milliseconds($stats['starttransfer_time'] ?? null),
            'total_time_ms' => $this->milliseconds($stats['total_time'] ?? null),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function milliseconds(mixed $seconds): ?float
    {
        if (!is_numeric($seconds)) {
            return null;
        }

        return round(((float) $seconds) * 1000, 3);
    }
}
