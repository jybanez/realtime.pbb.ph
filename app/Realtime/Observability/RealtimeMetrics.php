<?php

namespace App\Realtime\Observability;

use Illuminate\Support\Facades\Cache;

class RealtimeMetrics
{
    private const PREFIX = 'realtime.metrics.';

    public function increment(string $name, int $amount = 1): void
    {
        $key = self::PREFIX . $name;
        $current = (int) Cache::get($key, 0);
        Cache::forever($key, $current + $amount);
    }

    /**
     * @return array<string, int>
     */
    public function snapshot(): array
    {
        $keys = [
            'auth.success',
            'auth.failure',
            'room.join',
            'room.leave',
            'presence.publish',
            'presence.subscribe',
            'event.publish',
            'media.chunk.publish',
            'chat.publish',
            'call.signal',
        ];

        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = (int) Cache::get(self::PREFIX . $key, 0);
        }

        return $snapshot;
    }
}
