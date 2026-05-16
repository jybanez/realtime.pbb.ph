<?php

namespace App\Realtime\ProductQuery;

use App\Models\RealtimeProject;
use App\Realtime\Auth\RealtimeTokenClaims;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeProductQueryForwarder
{
    /**
     * @return array<string, mixed>|null
     */
    public function settingsForProject(string $projectCode): ?array
    {
        $project = RealtimeProject::query()
            ->where('project_code', trim($projectCode))
            ->first();

        $settings = is_array($project?->product_query_forwarding_settings)
            ? $project->product_query_forwarding_settings
            : null;

        if (!is_array($settings) || !(bool) ($settings['enabled'] ?? false)) {
            return null;
        }

        if ($this->stringValue($settings['base_url'] ?? null) === null) {
            return null;
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $requestPayload
     * @param array<string, mixed> $settings
     */
    public function forward(
        RealtimeTokenClaims $claims,
        string $room,
        string $sessionId,
        array $requestPayload,
        ?string $correlationId,
        array $settings
    ): RealtimeProductQueryForwardResult {
        $url = rtrim((string) $settings['base_url'], '/') . $this->normalizePath((string) ($settings['path'] ?? '/api/internal/realtime/product-query'));
        $headers = array_filter([
            'Accept' => 'application/json',
            (string) ($settings['auth_header'] ?? 'X-Realtime-Backend-Secret') => $this->stringValue($settings['auth_token'] ?? null),
        ]);

        try {
            $response = Http::withHeaders($headers)
                ->asJson()
                ->connectTimeout((int) ($settings['connect_timeout_seconds'] ?? 3))
                ->timeout((int) ($settings['timeout_seconds'] ?? 8))
                ->withOptions([
                    'verify' => (bool) ($settings['verify_tls'] ?? true),
                ])
                ->post($url, [
                    'type' => 'product.query.request',
                    'schema_version' => (int) $requestPayload['schema_version'],
                    'client_code' => $claims->appCode,
                    'project_code' => $claims->projectCode,
                    'room' => $room,
                    'request' => [
                        'request_id' => $requestPayload['request_id'],
                        'query' => $requestPayload['query'],
                        'context' => $requestPayload['context'] ?? (object) [],
                        'projection' => $requestPayload['projection'] ?? (object) [],
                        'client_state' => $requestPayload['client_state'] ?? (object) [],
                    ],
                    'meta' => [
                        'service' => (string) config('realtime.service_name', 'PBB Realtime'),
                        'source' => 'client',
                        'session_id' => $sessionId !== '' ? $sessionId : null,
                        'received_at' => now()->toIso8601String(),
                        'correlation_id' => $correlationId,
                        'sender' => [
                            'user_id' => $claims->userId,
                            'display_name' => $claims->displayName,
                            'project_code' => $claims->projectCode,
                            'app_code' => $claims->appCode,
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Realtime product query forward request threw an exception.', [
                'url' => $url,
                'project_code' => $claims->projectCode,
                'app_code' => $claims->appCode,
                'query' => $requestPayload['query'] ?? null,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return RealtimeProductQueryForwardResult::rejected(
                'product-query.forward-failed',
                'Realtime could not forward the product query to the product backend.'
            );
        }

        if (!$response->successful()) {
            Log::warning('Realtime product query forward request was rejected.', [
                'url' => $url,
                'project_code' => $claims->projectCode,
                'app_code' => $claims->appCode,
                'query' => $requestPayload['query'] ?? null,
                'status' => $response->status(),
                'response' => $response->json() ?: $response->body(),
            ]);

            return RealtimeProductQueryForwardResult::rejected(
                'product-query.forward-failed',
                'Product backend did not accept the query.',
                $response->status()
            );
        }

        return RealtimeProductQueryForwardResult::accepted($response->status());
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/api/internal/realtime/product-query';
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }
}
