<?php

namespace App\Realtime\Rooms;

use App\Realtime\Auth\RealtimeTokenClaims;

class RealtimeRoomPolicy
{
    public function allows(RealtimeTokenClaims $claims, string $room): bool
    {
        $room = trim($room);

        if ($room === '') {
            return false;
        }

        if (in_array($room, $claims->allowedRooms, true)) {
            return true;
        }

        if (str_starts_with($room, 'presence.workspace.')) {
            $workspaceId = substr($room, strlen('presence.workspace.'));

            if ($claims->workspaceId !== null && $claims->workspaceId === $workspaceId) {
                return true;
            }
        }

        if (str_starts_with($room, 'presence.global.')) {
            $scopeId = substr($room, strlen('presence.global.'));

            if ($claims->orgId !== null && $claims->orgId === $scopeId) {
                return true;
            }
        }

        foreach ($claims->allowedRoomPrefixes as $prefix) {
            $prefix = trim($prefix);

            if ($prefix === '') {
                continue;
            }

            if ($room === $prefix || str_starts_with($room, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
