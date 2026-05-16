<?php

namespace App\Realtime\Sessions;

use App\Models\RealtimeSession;
use App\Realtime\Auth\RealtimeTokenClaims;
use DateTimeImmutable;

class RealtimeSessionRecorder
{
    public function recordAuthentication(RealtimeTokenClaims $claims, string $sessionId): RealtimeSession
    {
        return RealtimeSession::updateOrCreate(
            ['session_id' => $sessionId],
            [
                'client_code' => $claims->appCode,
                'project_code' => $claims->projectCode,
                'app_code' => $claims->appCode,
                'display_name' => $claims->displayName ?: $claims->userId,
                'user_identity' => $claims->userId,
                'status' => 'connected',
                'connected_at' => new DateTimeImmutable(),
                'last_activity_at' => new DateTimeImmutable(),
                'disconnect_reason' => null,
                'room_count' => 0,
            ]
        );
    }

    public function touch(string $sessionId, ?string $status = null, ?string $disconnectReason = null, ?int $roomCount = null): void
    {
        $session = RealtimeSession::query()->where('session_id', $sessionId)->first();

        if (! $session) {
            return;
        }

        $session->forceFill(array_filter([
            'status' => $status,
            'disconnect_reason' => $disconnectReason,
            'room_count' => $roomCount,
            'last_activity_at' => new DateTimeImmutable(),
        ], static fn ($value) => $value !== null))->save();
    }

    public function leaveClosedSession(string $sessionId, ?string $disconnectReason = null): void
    {
        $session = RealtimeSession::query()->where('session_id', $sessionId)->first();

        if (! $session) {
            return;
        }

        $session->forceFill([
            'status' => 'disconnected',
            'disconnect_reason' => $disconnectReason,
            'last_activity_at' => new DateTimeImmutable(),
        ])->save();
    }
}
