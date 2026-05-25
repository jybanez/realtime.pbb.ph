<?php

namespace App\Realtime\Media;

use App\Realtime\Auth\RealtimeTokenClaims;
use App\Models\RealtimeProject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeMediaChunkForwarder
{
    public function isConfiguredForProject(string $projectCode): bool
    {
        return $this->resolveRoute($projectCode) !== null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function forward(RealtimeTokenClaims $claims, string $room, string $sessionId, array $payload, ?string $binaryPath = null): RealtimeMediaChunkForwardResult
    {
        $route = $this->resolveRoute($claims->projectCode);

        if ($route === null) {
            return RealtimeMediaChunkForwardResult::rejected(
                'media.ingest-unavailable',
                'Media chunk ingest is not configured for this integration.'
            );
        }

        $url = rtrim((string) $route['base_url'], '/') . $this->normalizePath((string) ($route['path'] ?? '/api/internal/media/chunks'));
        $headers = array_filter([
            'Accept' => 'application/json',
            (string) ($route['auth_header'] ?? 'X-Realtime-Media-Ingest-Secret') => $this->stringValue($route['auth_token'] ?? null),
        ]);

        try {
            $request = Http::withHeaders($headers)
                ->connectTimeout((int) ($route['connect_timeout_seconds'] ?? 3))
                ->timeout((int) ($route['timeout_seconds'] ?? 10))
                ->withOptions([
                    'verify' => $this->verifyOption($route),
                ]);

            if ($binaryPath !== null && is_file($binaryPath)) {
                $response = $request
                    ->attach('chunk', file_get_contents($binaryPath) ?: '', $this->binaryFilename($payload))
                    ->post($url, $this->multipartFormFields($claims, $room, $payload));
            } else {
                $response = $request->asJson()->post($url, [
                    'type' => 'media.chunk.publish',
                    'room' => $room,
                    'client_code' => $claims->appCode,
                    'project_code' => $claims->projectCode,
                    'payload' => $payload,
                    'meta' => $this->metaPayload($claims, $sessionId),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Realtime media chunk forward request threw an exception.', [
                'url' => $url,
                'project_code' => $claims->projectCode,
                'app_code' => $claims->appCode,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return RealtimeMediaChunkForwardResult::rejected(
                'media.ingest-failed',
                'Realtime could not forward the media chunk to downstream ingest.'
            );
        }

        if (!$response->successful()) {
            Log::warning('Realtime media chunk forward request was rejected.', [
                'url' => $url,
                'project_code' => $claims->projectCode,
                'app_code' => $claims->appCode,
                'status' => $response->status(),
                'response' => $response->json() ?: $response->body(),
            ]);

            return RealtimeMediaChunkForwardResult::rejected(
                'media.ingest-failed',
                'Realtime downstream media ingest rejected the chunk.',
                $response->status(),
            );
        }

        return RealtimeMediaChunkForwardResult::accepted($response->status());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRoute(string $projectCode): ?array
    {
        $project = RealtimeProject::query()
            ->where('project_code', trim($projectCode))
            ->first();

        $route = is_array($project?->media_ingest_settings) ? $project->media_ingest_settings : null;
        if (!is_array($route)) {
            return null;
        }

        if (!(bool) ($route['enabled'] ?? false)) {
            return null;
        }

        if ($this->stringValue($route['base_url'] ?? null) === null) {
            return null;
        }

        return $route;
    }

    private function serviceName(): string
    {
        return (string) config('realtime.service_name', 'PBB Realtime');
    }

    /**
     * @return array<string, mixed>
     */
    private function metaPayload(RealtimeTokenClaims $claims, string $sessionId): array
    {
        return [
            'service' => $this->serviceName(),
            'source' => 'client',
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'received_at' => now()->toIso8601String(),
            'sender' => [
                'user_id' => $claims->userId,
                'display_name' => $claims->displayName,
                'project_code' => $claims->projectCode,
                'app_code' => $claims->appCode,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function binaryFilename(array $payload): string
    {
        $index = isset($payload['chunk_index']) ? (string) $payload['chunk_index'] : 'chunk';
        $extension = $this->stringValue($payload['extension'] ?? null) ?? 'bin';

        return $index . '.' . ltrim($extension, '.');
    }

    /**
     * Hotline's binary ingest endpoint receives multipart form data and validates
     * the media fields at the top level. The JSON transport keeps those fields
     * under payload, but multipart must not wrap them or overwrite payload.type.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function multipartFormFields(RealtimeTokenClaims $claims, string $room, array $payload): array
    {
        $fields = [];

        foreach ($payload as $key => $value) {
            if (!is_string($key) || $key === 'chunk_data') {
                continue;
            }

            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $fields[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        $fields['sender_user_id'] = $fields['sender_user_id'] ?? $claims->userId;
        $fields['project_code'] = $fields['project_code'] ?? $claims->projectCode;
        $fields['room'] = $fields['room'] ?? $room;

        return array_filter($fields, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param mixed $value
     */
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
            return '/api/internal/media/chunks';
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function verifyOption(array $route): bool|string
    {
        if ((bool) ($route['verify_tls'] ?? true) === false) {
            return false;
        }

        return $this->stringValue($route['ca_bundle'] ?? null) ?? true;
    }
}
