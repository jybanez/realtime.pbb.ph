<?php

namespace App\Realtime\Ingress;

use App\Models\RealtimeProject;
use App\Models\RealtimeServerEvent;
use Illuminate\Support\Str;

class RealtimeEventPublishQueue
{
    public function enqueue(
        string $clientCode,
        RealtimeProject $project,
        string $room,
        string $eventType,
        array $payload,
        array $meta = [],
        ?string $eventId = null
    ): RealtimeServerEvent {
        return RealtimeServerEvent::query()->create([
            'publish_id' => 'pub_' . Str::lower((string) Str::ulid()),
            'client_code' => substr(trim($clientCode), 0, 64),
            'project_code' => substr(trim((string) $project->project_code), 0, 64),
            'room' => substr(trim($room), 0, 180),
            'event_type' => substr(trim($eventType), 0, 180),
            'event_id' => $eventId ? trim($eventId) : null,
            'status' => 'pending',
            'attempts' => 0,
            'payload' => $payload,
            'meta' => $meta,
            'fanout_count' => 0,
            'queued_at' => now(),
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, RealtimeServerEvent>
     */
    public function pendingBatch(int $limit = 100)
    {
        return RealtimeServerEvent::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }
}
