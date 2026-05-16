<?php

namespace App\Realtime\Ingress;

use App\Models\RealtimeServerEvent;
use App\Realtime\Admin\RealtimeAdminAuditLogger;
use App\Realtime\Observability\RealtimeMetrics;
use App\Realtime\Observability\RealtimeUsageTelemetry;
use App\Realtime\WebSocket\RealtimeGateway;
use Throwable;

class RealtimeEventPublishDispatcher
{
    public function __construct(
        private readonly RealtimeMetrics $metrics,
        private readonly RealtimeUsageTelemetry $telemetry,
        private readonly RealtimeAdminAuditLogger $audit,
        private readonly RealtimeEventPublishQueue $queue,
    ) {
    }

    /**
     * @return array{processed:int, failed:int}
     */
    public function drain(RealtimeGateway $gateway, int $limit = 100): array
    {
        $processed = 0;
        $failed = 0;

        foreach ($this->queue->pendingBatch($limit) as $event) {
            if ($this->dispatchOne($gateway, $event)) {
                $processed += 1;
            } else {
                $failed += 1;
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    public function dispatchOne(RealtimeGateway $gateway, RealtimeServerEvent $event): bool
    {
        $event->attempts = (int) $event->attempts + 1;

        try {
            $meta = array_filter([
                'source' => 'server',
                'client_code' => $event->client_code,
                'project_code' => $event->project_code,
                'event_id' => $event->event_id,
                ...((is_array($event->meta) ? $event->meta : [])),
            ], static fn ($value) => $value !== null && $value !== '');

            $fanoutCount = $gateway->publishServerEvent(
                $event->room,
                $event->event_type,
                is_array($event->payload) ? $event->payload : [],
                $meta
            );

            $event->status = 'published';
            $event->fanout_count = $fanoutCount;
            $event->published_at = now();
            $event->failed_at = null;
            $event->failure_reason = null;
            $event->save();

            $this->metrics->increment('event.publish');
            $this->telemetry->record(
                'event.publish.accepted',
                null,
                bytesIn: strlen(json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
                bytesOut: $fanoutCount * $gateway->measureServerEventBytes(
                    $event->event_type,
                    $event->room,
                    is_array($event->payload) ? $event->payload : [],
                    $meta
                ),
                clientCode: $event->client_code,
                projectCode: $event->project_code
            );

            $this->audit->record(
                null,
                'event_publish.delivered',
                'realtime_server_event',
                $event->publish_id,
                before: [],
                after: [
                    'client_code' => $event->client_code,
                    'project_code' => $event->project_code,
                    'room' => $event->room,
                    'event_type' => $event->event_type,
                    'fanout_count' => $fanoutCount,
                ],
                reason: null,
                clientCode: $event->client_code,
                projectCode: $event->project_code
            );

            return true;
        } catch (Throwable $e) {
            $event->status = 'failed';
            $event->failed_at = now();
            $event->failure_reason = $e->getMessage();
            $event->save();

            $this->telemetry->record(
                'event.publish.rejected',
                null,
                errorCount: 1,
                clientCode: $event->client_code,
                projectCode: $event->project_code
            );

            $this->audit->record(
                null,
                'event_publish.failed',
                'realtime_server_event',
                $event->publish_id,
                before: [],
                after: [
                    'client_code' => $event->client_code,
                    'project_code' => $event->project_code,
                    'room' => $event->room,
                    'event_type' => $event->event_type,
                ],
                reason: $e->getMessage(),
                clientCode: $event->client_code,
                projectCode: $event->project_code
            );

            return false;
        }
    }
}
