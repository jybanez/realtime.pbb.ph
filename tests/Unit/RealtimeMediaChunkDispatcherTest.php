<?php

namespace Tests\Unit;

use App\Models\RealtimeClient;
use App\Models\RealtimeMediaChunk;
use App\Models\RealtimeProject;
use App\Models\RealtimeServerEvent;
use App\Realtime\Media\RealtimeMediaChunkDispatcher;
use App\Realtime\Media\RealtimeMediaChunkForwarder;
use App\Realtime\Media\RealtimeMediaChunkQueue;
use App\Realtime\Auth\RealtimeTokenClaims;
use App\Realtime\WebSocket\RealtimeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RealtimeMediaChunkDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $client = new RealtimeClient([
            'name' => 'PBB HQ',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);
        $client->client_code = 'pbb-hq';
        $client->project_code = 'prj_client_default';
        $client->save();

        $project = new RealtimeProject([
            'client_id' => $client->id,
            'name' => 'HQ',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
            'media_ingest_settings' => [
                'enabled' => true,
                'base_url' => 'https://media-ingest.pbb.ph',
                'path' => '/api/internal/media/chunks',
                'auth_header' => 'X-Realtime-Media-Ingest-Secret',
                'auth_token' => 'test-media-ingest-secret',
                'connect_timeout_seconds' => 3,
                'timeout_seconds' => 10,
                'verify_tls' => true,
            ],
        ]);
        $project->project_code = 'hq';
        $project->save();

        $this->purgeSpoolPath((new RealtimeMediaChunkQueue())->spoolBasePath());
    }

    public function test_it_forwards_queued_media_chunks(): void
    {
        Http::fake([
            'https://media-ingest.pbb.ph/api/internal/media/chunks' => Http::response([
                'status' => 'accepted',
            ], 202),
        ]);

        $queue = new RealtimeMediaChunkQueue();
        $chunk = $queue->enqueue($this->claims(), 'call.session.dispatcher_001', 'rt_dispatcher_001', [
            'media_id' => 'media_001',
            'chunk_index' => 0,
            'chunk_data' => 'YWJjMTIz',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
        ]);

        $dispatcher = new RealtimeMediaChunkDispatcher(
            new RealtimeMediaChunkForwarder(),
            $queue,
        );

        $result = $dispatcher->drain();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);

        $this->assertDatabaseMissing('realtime_media_chunks', [
            'chunk_id' => $chunk->chunk_id,
        ]);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://media-ingest.pbb.ph/api/internal/media/chunks'
                && $request->hasHeader('X-Realtime-Media-Ingest-Secret', 'test-media-ingest-secret')
                && $body['type'] === 'media.chunk.publish'
                && $body['room'] === 'call.session.dispatcher_001'
                && $body['client_code'] === 'pbb-hq'
                && $body['project_code'] === 'hq'
                && $body['payload']['media_id'] === 'media_001'
                && $body['payload']['chunk_index'] === 0
                && $body['meta']['sender']['user_id'] === '1024';
        });
    }

    public function test_it_marks_queued_media_chunks_failed_when_downstream_ingest_fails(): void
    {
        Http::fake([
            'https://media-ingest.pbb.ph/api/internal/media/chunks' => Http::response([
                'status' => 'rejected',
            ], 500),
        ]);

        $queue = new RealtimeMediaChunkQueue();
        $chunk = $queue->enqueue($this->claims(), 'call.session.dispatcher_002', 'rt_dispatcher_002', [
            'segment_key' => 'seg_002',
            'chunk_index' => 1,
            'chunk_data' => 'YWJjMTIz',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
        ]);

        $dispatcher = new RealtimeMediaChunkDispatcher(
            new RealtimeMediaChunkForwarder(),
            $queue,
        );

        $result = $dispatcher->drain();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);

        $record = RealtimeMediaChunk::query()->where('chunk_id', $chunk->chunk_id)->firstOrFail();
        $this->assertSame('failed', $record->status);
        $this->assertSame(500, $record->downstream_status);
        $this->assertNotNull($record->failed_at);
        $this->assertSame('Realtime downstream media ingest rejected the chunk.', $record->failure_reason);
    }

    public function test_it_queues_outcome_events_when_running_without_live_gateway(): void
    {
        Http::fake([
            'https://media-ingest.pbb.ph/api/internal/media/chunks' => Http::response([
                'status' => 'accepted',
            ], 201),
        ]);

        $queue = new RealtimeMediaChunkQueue();
        $chunk = $queue->enqueue($this->claims(), 'call.session.dispatcher_003', 'rt_dispatcher_003', [
            'media_id' => 'media_003',
            'chunk_index' => 2,
            'chunk_data' => 'YWJjMTIz',
            'correlation_id' => 'corr_003',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
        ]);

        $dispatcher = new RealtimeMediaChunkDispatcher(
            new RealtimeMediaChunkForwarder(),
            $queue,
        );

        $result = $dispatcher->drain(25, null, queueOutcomesWhenNoGateway: true);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);

        $event = RealtimeServerEvent::query()->firstOrFail();
        $this->assertSame('pending', $event->status);
        $this->assertSame('media.chunk.forwarded', $event->event_type);
        $this->assertSame('call.session.dispatcher_003', $event->room);
        $this->assertSame($chunk->chunk_id . ':media.chunk.forwarded', $event->event_id);
        $this->assertSame('media_003', $event->payload['media_id']);
        $this->assertSame(2, $event->payload['chunk_index']);
        $this->assertSame('corr_003', $event->payload['correlation_id']);
        $this->assertSame(201, $event->payload['downstream_status']);
        $this->assertSame('media-ingest', $event->meta['source']);
    }

    public function test_it_forwards_binary_chunks_as_flat_multipart_fields(): void
    {
        $payload = [
            'incident_id' => 321,
            'call_session_id' => 142,
            'media_id' => 311,
            'type' => 'audio_peer',
            'peer_user_id' => 2048,
            'peer_role' => 'operator',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
            'extension' => 'webm',
            'segment_key' => 'citizen-audio-test',
            'chunk_index' => 40,
            'chunk_total' => 60,
            'total_bytes' => 123,
            'transfer_id' => 'transfer_binary_001',
            'chunk_data' => 'must-not-be-forwarded-in-multipart',
        ];

        $forwarder = new RealtimeMediaChunkForwarder();
        $method = new \ReflectionMethod($forwarder, 'multipartFormFields');
        $method->setAccessible(true);

        $fields = $method->invoke($forwarder, $this->claims(), 'call.session.142', $payload);

        $this->assertSame(321, $fields['incident_id']);
        $this->assertSame(142, $fields['call_session_id']);
        $this->assertSame(311, $fields['media_id']);
        $this->assertSame('audio_peer', $fields['type']);
        $this->assertSame('audio', $fields['track_kind']);
        $this->assertSame('audio/webm', $fields['mime_type']);
        $this->assertSame(40, $fields['chunk_index']);
        $this->assertSame('1024', $fields['sender_user_id']);
        $this->assertSame('hq', $fields['project_code']);
        $this->assertSame('call.session.142', $fields['room']);
        $this->assertArrayNotHasKey('payload', $fields);
        $this->assertArrayNotHasKey('chunk_data', $fields);
    }

    public function test_it_keeps_forwarded_status_when_outcome_publish_throws_after_downstream_accepts(): void
    {
        Http::fake([
            'https://media-ingest.pbb.ph/api/internal/media/chunks' => Http::response([
                'status' => 'accepted',
            ], 201),
        ]);

        $queue = new RealtimeMediaChunkQueue();
        $chunk = $queue->enqueue($this->claims(), 'call.session.dispatcher_003b', 'rt_dispatcher_003b', [
            'media_id' => 'media_003b',
            'chunk_index' => 3,
            'chunk_data' => 'YWJjMTIz',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
        ]);

        $gateway = Mockery::mock(RealtimeGateway::class);
        $gateway->shouldReceive('publishServerEvent')
            ->once()
            ->andThrow(new \RuntimeException('fanout unavailable'));

        $dispatcher = new RealtimeMediaChunkDispatcher(
            new RealtimeMediaChunkForwarder(),
            $queue,
        );

        $result = $dispatcher->drain(25, $gateway);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);

        $this->assertDatabaseMissing('realtime_media_chunks', [
            'chunk_id' => $chunk->chunk_id,
        ]);
    }

    public function test_it_claims_pending_chunks_as_dispatching_before_forwarding(): void
    {
        Http::fake([
            'https://media-ingest.pbb.ph/api/internal/media/chunks' => Http::response([
                'status' => 'accepted',
            ], 202),
        ]);

        $queue = new RealtimeMediaChunkQueue();
        $chunk = $queue->enqueue($this->claims(), 'call.session.dispatcher_004', 'rt_dispatcher_004', [
            'media_id' => 'media_004',
            'chunk_index' => 0,
            'chunk_data' => 'YWJjMTIz',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
        ]);

        $claimed = $queue->claimBatch(25, 300);

        $this->assertCount(1, $claimed);
        $this->assertSame('dispatching', $claimed[0]->status);
        $this->assertSame($chunk->chunk_id, $claimed[0]->chunk_id);
    }

    public function test_it_can_reclaim_stale_dispatching_chunks(): void
    {
        $queue = new RealtimeMediaChunkQueue();
        $chunk = $queue->enqueue($this->claims(), 'call.session.dispatcher_005', 'rt_dispatcher_005', [
            'media_id' => 'media_005',
            'chunk_index' => 1,
            'chunk_data' => 'YWJjMTIz',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
        ]);

        $processingPath = $queue->spoolBasePath()
            . DIRECTORY_SEPARATOR . $chunk->client_code
            . DIRECTORY_SEPARATOR . 'processing'
            . DIRECTORY_SEPARATOR . basename($chunk->spool_path);
        File::ensureDirectoryExists(dirname($processingPath));
        rename($chunk->spool_path, $processingPath);
        file_put_contents($processingPath, json_encode([
            ...$chunk->toArray(),
            'status' => 'dispatching',
            'attempts' => 1,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        touch($processingPath, now()->subMinutes(10)->getTimestamp());

        $reclaimed = $queue->claimBatch(25, 300);

        $this->assertCount(1, $reclaimed);
        $this->assertSame('dispatching', $reclaimed[0]->status);
        $this->assertSame($chunk->chunk_id, $reclaimed[0]->chunk_id);
        $this->assertSame(2, $reclaimed[0]->attempts);
    }

    private function claims(): RealtimeTokenClaims
    {
        return new RealtimeTokenClaims(
            issuer: 'local.pbb.test',
            subject: '1024',
            audience: 'pbb-realtime',
            expiresAt: new \DateTimeImmutable('+10 minutes'),
            issuedAt: new \DateTimeImmutable(),
            tokenId: 'tok_test_dispatcher',
            projectCode: 'hq',
            appCode: 'pbb-hq',
            userId: '1024',
            email: null,
            displayName: 'Gateway User',
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
    }

    private function purgeSpoolPath(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) {
                $this->purgeSpoolPath($fullPath);
                @rmdir($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
