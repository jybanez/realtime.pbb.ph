<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithClientAccess;
use App\Http\Controllers\Controller;
use App\Models\RealtimeClient;
use App\Models\RealtimeProject;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SandboxController extends Controller
{
    use InteractsWithClientAccess;

    public function index(): View
    {
        return view('admin.app');
    }

    public function context(Request $request): JsonResponse
    {
        $clients = $this->scopeVisibleClients(RealtimeClient::query(), $request)
            ->with(['projects.policyProfile'])
            ->orderBy('name')
            ->get()
            ->map(function (RealtimeClient $client): array {
                return [
                    'client_code' => $client->client_code,
                    'name' => $client->name,
                    'status' => $client->status,
                    'issuer_identity' => $client->issuer_identity,
                    'trusted_signing_profile' => $client->trusted_signing_profile,
                    'token_issuance_mode' => $client->token_issuance_mode,
                    'projects' => $client->projects
                        ->sortBy('name')
                        ->values()
                        ->map(function (RealtimeProject $project): array {
                            return [
                                'project_code' => $project->project_code,
                                'name' => $project->name,
                                'status' => $project->status,
                                'origin_policy_mode' => $project->origin_policy_mode,
                                'policy_profile_code' => $project->policy_profile_code,
                                'policy_profile_name' => $project->policyProfile?->name,
                                'attachment_policy' => $this->resolveAttachmentTransportPolicy($project),
                                'allowed_origins' => collect($project->allowed_origins ?? [])->values()->all(),
                            ];
                        })
                        ->all(),
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'data' => [
                'service_name' => (string) config('realtime.service_name'),
                'websocket_url' => $this->resolveWebsocketUrl($request),
                'room_prefix' => 'chat.thread.',
                'capabilities' => [
                    'session.connect',
                    'room.join',
                    'presence.subscribe',
                    'presence.publish',
                    'event.publish',
                    'chat.publish',
                    'call.signal',
                ],
                'clients' => $clients,
            ],
        ]);
    }

    public function admission(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_code' => ['required', 'string', 'max:80'],
            'project_code' => ['required', 'string', 'max:80'],
            'display_name' => ['required', 'string', 'max:120'],
            'user_id' => ['nullable', 'string', 'max:120'],
            'room' => ['required', 'string', 'max:180'],
            'room_mode' => ['nullable', 'string', 'in:prefixed,raw'],
        ]);

        $client = RealtimeClient::query()
            ->where('client_code', $validated['client_code'])
            ->firstOrFail();
        $this->ensureClientAccess($request, $client);

        $project = RealtimeProject::query()
            ->with(['client', 'policyProfile'])
            ->where('project_code', $validated['project_code'])
            ->where('client_id', $client->getKey())
            ->firstOrFail();
        $this->ensureProjectAccess($request, $project);

        if ($client->status !== 'active') {
            return response()->json([
                'status' => false,
                'message' => 'The selected client is not active.',
            ], 422);
        }

        if ($project->status !== 'active') {
            return response()->json([
                'status' => false,
                'message' => 'The selected project scope is not active.',
            ], 422);
        }

        $secret = (string) config('realtime.token_signing_secret');
        if ($secret === '') {
            return response()->json([
                'status' => false,
                'message' => 'Realtime token signing secret is not configured.',
            ], 500);
        }

        $issuer = collect([
            $client->trusted_signing_profile,
            $client->issuer_identity,
            ...collect(config('realtime.trusted_issuers', []))
                ->map(fn ($value) => trim((string) $value))
                ->all(),
        ])->map(fn ($value) => trim((string) $value))
            ->first(fn ($value) => $value !== '');

        if (!is_string($issuer) || $issuer === '') {
            return response()->json([
                'status' => false,
                'message' => 'Realtime trusted issuers are not configured.',
            ], 500);
        }

        $roomMode = $validated['room_mode'] ?? 'prefixed';
        $effectiveRoom = $roomMode === 'raw'
            ? $this->normalizeInspectorRoom($validated['room'])
            : $this->normalizeRoom($validated['room']);
        $effectiveCallRoom = $this->normalizeCallRoom($validated['room']);
        $userId = trim((string) ($validated['user_id'] ?? ''));
        if ($userId === '') {
            $userId = 'sandbox_' . Str::lower(Str::random(10));
        }

        $expiresAt = now()->addMinutes((int) config('realtime.token_ttl_minutes', 15));
        $tokenId = 'sbx_' . Str::lower((string) Str::ulid());

        $claims = [
            'iss' => $issuer,
            'sub' => 'sandbox:' . $userId,
            'aud' => (string) config('realtime.token_audience'),
            'iat' => now()->timestamp,
            'exp' => $expiresAt->timestamp,
            'jti' => $tokenId,
            'project_code' => $project->project_code,
            'app_code' => $client->client_code,
            'user_id' => $userId,
            'display_name' => trim((string) $validated['display_name']),
            'email' => null,
            'capabilities' => [
                'session.connect',
                'room.join',
                'presence.subscribe',
                'presence.publish',
                'event.publish',
                'chat.publish',
                'call.signal',
            ],
            'allowed_rooms' => [$effectiveRoom, $effectiveCallRoom],
            'allowed_room_prefixes' => [],
            'sandbox' => [
                'client_code' => $client->client_code,
                'project_code' => $project->project_code,
                'policy_profile_code' => $project->policy_profile_code,
                'trusted_signing_profile' => $client->trusted_signing_profile,
                'call_room' => $effectiveCallRoom,
            ],
            'attachment_policy' => $this->resolveAttachmentTransportPolicy($project),
        ];

        $token = JWT::encode($claims, $secret, 'HS256');

        return response()->json([
            'status' => true,
            'data' => [
                'token' => $token,
                'websocket_url' => $this->resolveWebsocketUrl($request),
                'effective_room' => $effectiveRoom,
                'effective_call_room' => $effectiveCallRoom,
                'expires_at' => $expiresAt->toIso8601String(),
                'session' => [
                    'token_id' => $tokenId,
                    'client_code' => $client->client_code,
                    'client_name' => $client->name,
                    'project_code' => $project->project_code,
                    'project_name' => $project->name,
                    'policy_profile_code' => $project->policy_profile_code,
                    'policy_profile_name' => $project->policyProfile?->name,
                    'attachment_policy' => $this->resolveAttachmentTransportPolicy($project),
                    'user_id' => $userId,
                    'display_name' => trim((string) $validated['display_name']),
                    'capabilities' => $claims['capabilities'],
                    'call_room' => $effectiveCallRoom,
                ],
            ],
        ]);
    }

    private function resolveWebsocketUrl(Request $request): string
    {
        $configured = trim((string) config('realtime.public_websocket_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        $scheme = $request->isSecure() ? 'wss' : 'ws';
        $host = (string) $request->getHost();
        $port = (int) $request->getPort();

        $defaultPort = ($scheme === 'wss' && $port === 443) || ($scheme === 'ws' && $port === 80);
        $authority = $defaultPort ? $host : sprintf('%s:%d', $host, $port);

        return sprintf('%s://%s/realtime', $scheme, $authority);
    }

    private function normalizeRoom(string $value): string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, 'chat.thread.')) {
            return $trimmed;
        }

        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $trimmed) ?: 'sandbox-room';
        $sanitized = trim($sanitized, '.-');

        return 'chat.thread.' . ($sanitized !== '' ? $sanitized : 'sandbox-room');
    }

    private function normalizeInspectorRoom(string $value): string
    {
        $trimmed = trim($value);
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $trimmed) ?: 'sandbox-room';
        $sanitized = trim($sanitized, '.-');

        return $sanitized !== '' ? $sanitized : 'sandbox-room';
    }

    private function normalizeCallRoom(string $value): string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, 'call.session.')) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, 'chat.thread.')) {
            $trimmed = substr($trimmed, strlen('chat.thread.'));
        }

        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $trimmed) ?: 'sandbox-room';
        $sanitized = trim($sanitized, '.-');

        return 'call.session.' . ($sanitized !== '' ? $sanitized : 'sandbox-room');
    }

    /**
     * @return array<string, int>
     */
    private function resolveAttachmentTransportPolicy(RealtimeProject $project): array
    {
        $defaults = config('realtime.sandbox_attachment_transport', []);
        $profile = $project->policyProfile?->rate_limit_profile;
        $attachment = is_array($profile['attachment_transport'] ?? null)
            ? $profile['attachment_transport']
            : [];

        return [
            'max_attachment_count' => max(0, (int) ($attachment['max_attachment_count'] ?? $defaults['max_attachment_count'] ?? 0)),
            'max_attachment_bytes' => max(0, (int) ($attachment['max_attachment_bytes'] ?? $defaults['max_attachment_bytes'] ?? 0)),
            'max_total_bytes_per_message' => max(0, (int) ($attachment['max_total_bytes_per_message'] ?? $defaults['max_total_bytes_per_message'] ?? 0)),
            'chunk_events_per_minute' => max(
                0,
                (int) (
                    $attachment['chunk_events_per_minute']
                    ?? $profile['media_events_per_minute']
                    ?? $defaults['chunk_events_per_minute']
                    ?? 0
                )
            ),
            'chunk_bytes_per_minute' => max(0, (int) ($attachment['chunk_bytes_per_minute'] ?? $defaults['chunk_bytes_per_minute'] ?? 0)),
        ];
    }
}
