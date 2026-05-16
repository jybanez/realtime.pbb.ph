<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionStateController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->loadMissing('realtimeClients');

        return response()->json([
            'app' => [
                'name' => config('realtime.service_name'),
                'surface' => 'admin',
            ],
            'auth' => [
                'authenticated' => $user !== null,
                'account' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_operator' => (bool) $user->is_operator,
                    'user_type' => (string) ($user->user_type ?? ''),
                    'is_admin' => method_exists($user, 'isAdmin') ? $user->isAdmin() : false,
                    'assigned_clients' => $user->realtimeClients
                        ->map(fn ($client) => [
                            'id' => $client->id,
                            'client_code' => $client->client_code,
                            'name' => $client->name,
                        ])
                        ->values()
                        ->all(),
                ] : null,
            ],
            'security' => [
                'csrfToken' => csrf_token(),
            ],
            'settings' => [
                'sessionLifetimeMinutes' => max(1, (int) config('session.lifetime', 120)),
                'keepaliveThresholdSeconds' => $this->keepaliveThresholdSeconds(),
                'heartbeatIntervalSeconds' => (int) config('realtime.heartbeat_interval_seconds', 30),
            ],
        ]);
    }

    public function csrfToken(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => [
                'csrfToken' => csrf_token(),
            ],
        ]);
    }

    public function ping(Request $request): JsonResponse
    {
        $sessionLifetimeMinutes = max(1, (int) config('session.lifetime', 120));

        if (! $request->user()) {
            return response()->json([
                'status' => false,
                'reason' => 'session-expired',
                'data' => [
                    'csrfToken' => csrf_token(),
                    'session_lifetime_minutes' => $sessionLifetimeMinutes,
                ],
            ], 401);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'csrfToken' => csrf_token(),
                'touched_at' => now()->toIso8601String(),
                'session_lifetime_minutes' => $sessionLifetimeMinutes,
            ],
        ]);
    }

    private function keepaliveThresholdSeconds(): int
    {
        $lifetimeMinutes = max(1, (int) config('session.lifetime', 120));
        $lifetimeSeconds = $lifetimeMinutes * 60;

        return max(15, min(120, (int) floor($lifetimeSeconds * 0.2)));
    }
}
