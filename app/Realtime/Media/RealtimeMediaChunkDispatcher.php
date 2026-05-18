<?php

namespace App\Realtime\Media;

use App\Models\RealtimeServerEvent;
use App\Realtime\Auth\RealtimeTokenClaims;
use App\Realtime\WebSocket\RealtimeGateway;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RealtimeMediaChunkDispatcher
{
    public function __construct(
        private readonly RealtimeMediaChunkForwarder $forwarder,
        private readonly RealtimeMediaChunkQueue $queue,
    ) {
    }

    /**
     * @return array{processed:int, failed:int}
     */
    public function drain(int $limit = 25, ?RealtimeGateway $gateway = null, bool $queueOutcomesWhenNoGateway = false): array
    {
        $processed = 0;
        $failed = 0;

        foreach ($this->queue->claimBatch($limit, (int) config('realtime.media_chunk_dispatch_claim_timeout_seconds', 300)) as $chunk) {
            if ($this->dispatchOne($chunk, $gateway, $queueOutcomesWhenNoGateway)) {
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

    public function dispatchOne(RealtimeMediaChunkSpoolEntry $chunk, ?RealtimeGateway $gateway = null, bool $queueOutcomesWhenNoGateway = false): bool
    {
        try {
            $claims = new RealtimeTokenClaims(
                issuer: 'realtime.media.chunk.dispatcher',
                subject: $chunk->user_id ? (string) $chunk->user_id : 'unknown-user',
                audience: 'pbb-realtime',
                expiresAt: new DateTimeImmutable('+1 minute'),
                issuedAt: new DateTimeImmutable(),
                tokenId: null,
                projectCode: (string) $chunk->project_code,
                appCode: (string) $chunk->client_code,
                userId: $chunk->user_id ? (string) $chunk->user_id : 'unknown-user',
                email: null,
                displayName: $chunk->display_name ? (string) $chunk->display_name : null,
                roles: [],
                capabilities: [],
                tenantId: null,
                orgId: null,
                workspaceId: null,
                allowedRooms: [],
                allowedRoomPrefixes: [],
                origin: null,
                attachmentPolicy: [],
            );

            $forward = $this->forwarder->forward(
                $claims,
                (string) $chunk->room,
                (string) $chunk->session_id,
                is_array($chunk->payload) ? $chunk->payload : [],
                $chunk->binary_path
            );

            if (!$forward->accepted) {
                $this->queue->markFailed($chunk, $forward->message, $forward->status);
                $this->publishOutcomeSafely($gateway, $chunk, 'media.chunk.failed', $forward->code, $forward->message, $queueOutcomesWhenNoGateway);

                Log::warning('Realtime media chunk downstream forward failed.', [
                    'chunk_id' => $chunk->chunk_id,
                    'project_code' => $chunk->project_code,
                    'room' => $chunk->room,
                    'session_id' => $chunk->session_id,
                    'media_id' => $chunk->payload['media_id'] ?? null,
                    'segment_key' => $chunk->payload['segment_key'] ?? null,
                    'chunk_index' => $chunk->payload['chunk_index'] ?? null,
                    'error_code' => $forward->code,
                    'error_message' => $forward->message,
                ]);

                return false;
            }

            $this->queue->markForwarded($chunk, $forward->status);
            $this->publishOutcomeSafely($gateway, $chunk, 'media.chunk.forwarded', $forward->code, $forward->message, $queueOutcomesWhenNoGateway);

            return true;
        } catch (Throwable $e) {
            $this->queue->markFailed($chunk, $e->getMessage(), $chunk->downstream_status);
            $this->publishOutcomeSafely($gateway, $chunk, 'media.chunk.failed', 'media.chunk.dispatch-failed', $e->getMessage(), $queueOutcomesWhenNoGateway);

            Log::warning('Realtime media chunk dispatcher threw an exception.', [
                'chunk_id' => $chunk->chunk_id,
                'project_code' => $chunk->project_code,
                'room' => $chunk->room,
                'session_id' => $chunk->session_id,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return false;
        }
    }

    private function publishOutcomeSafely(
        ?RealtimeGateway $gateway,
        RealtimeMediaChunkSpoolEntry $chunk,
        string $eventType,
        string $code,
        string $message,
        bool $queueWhenNoGateway = false
    ): void {
        try {
            $this->publishOutcome($gateway, $chunk, $eventType, $code, $message, $queueWhenNoGateway);
        } catch (Throwable $e) {
            Log::warning('Realtime media chunk outcome publish failed.', [
                'chunk_id' => $chunk->chunk_id,
                'project_code' => $chunk->project_code,
                'room' => $chunk->room,
                'event_type' => $eventType,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    private function publishOutcome(
        ?RealtimeGateway $gateway,
        RealtimeMediaChunkSpoolEntry $chunk,
        string $eventType,
        string $code,
        string $message,
        bool $queueWhenNoGateway = false
    ): void {
        if (!$gateway instanceof RealtimeGateway && !$queueWhenNoGateway) {
            return;
        }

        $payload = is_array($chunk->payload) ? $chunk->payload : [];
        $eventPayload = array_filter([
            'chunk_id' => $chunk->chunk_id,
            'transfer_id' => $payload['transfer_id'] ?? null,
            'call_session_id' => $payload['call_session_id'] ?? null,
            'media_id' => $payload['media_id'] ?? null,
            'segment_key' => $payload['segment_key'] ?? null,
            'chunk_index' => $payload['chunk_index'] ?? null,
            'correlation_id' => $payload['correlation_id'] ?? null,
            'status' => $chunk->status,
            'downstream_status' => $chunk->downstream_status,
            'code' => $code,
            'message' => $message,
            'forwarded_at' => $chunk->forwarded_at?->toIso8601String(),
            'failed_at' => $chunk->failed_at?->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');
        $meta = [
            'source' => 'media-ingest',
            'client_code' => $chunk->client_code,
            'project_code' => $chunk->project_code,
        ];

        if (!$gateway instanceof RealtimeGateway) {
            RealtimeServerEvent::query()->create([
                'publish_id' => 'pub_' . Str::lower((string) Str::ulid()),
                'client_code' => substr((string) $chunk->client_code, 0, 64),
                'project_code' => substr((string) $chunk->project_code, 0, 64),
                'room' => substr((string) $chunk->room, 0, 180),
                'event_type' => substr($eventType, 0, 180),
                'event_id' => (string) $chunk->chunk_id . ':' . $eventType,
                'status' => 'pending',
                'attempts' => 0,
                'payload' => $eventPayload,
                'meta' => $meta,
                'fanout_count' => 0,
                'queued_at' => now(),
            ]);

            return;
        }

        $gateway->publishServerEvent(
            (string) $chunk->room,
            $eventType,
            $eventPayload,
            $meta
        );
    }
}
