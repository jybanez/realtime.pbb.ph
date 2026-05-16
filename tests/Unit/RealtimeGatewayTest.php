<?php

namespace Tests\Unit;

use App\Models\RealtimeMediaChunk;
use App\Realtime\Media\RealtimeMediaChunkDispatcher;
use App\Realtime\Media\RealtimeMediaChunkForwarder;
use App\Realtime\Media\RealtimeMediaChunkQueue;
use App\Models\RealtimeClient;
use App\Models\RealtimeProject;
use App\Realtime\Auth\RealtimeTokenValidator;
use App\Realtime\Observability\RealtimeMetrics;
use App\Realtime\Observability\RealtimeUsageTelemetry;
use App\Realtime\ProductQuery\RealtimeProductQueryForwarder;
use App\Realtime\Rooms\RealtimeRoomPolicy;
use App\Realtime\Sessions\RealtimeSessionRecorder;
use App\Realtime\WebSocket\RealtimeGateway;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Ratchet\ConnectionInterface;
use Tests\TestCase;

class RealtimeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'gateway-test-realtime-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'realtime.token_signing_secret' => $this->secret,
            'realtime.token_audience' => 'pbb-realtime',
            'realtime.trusted_issuers' => ['local.pbb.test'],
            'realtime.service_name' => 'PBB Realtime',
            'realtime.heartbeat_interval_seconds' => 30,
            'realtime.presence_stale_seconds' => 90,
            'realtime.message_rate_limit_per_minute' => 120,
            'realtime.room_join_rate_limit_per_minute' => 30,
            'realtime.max_rooms_per_session' => 50,
        ]);

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
                'binary_enabled' => true,
                'max_binary_chunk_bytes' => 1024,
            ],
            'product_query_forwarding_settings' => [
                'enabled' => true,
                'base_url' => 'https://hotline.pbb.ph',
                'path' => '/api/internal/realtime/product-query',
                'auth_header' => 'X-Realtime-Backend-Secret',
                'auth_token' => 'test-product-query-secret',
                'allowed_event_types' => ['product.query.request'],
                'allowed_queries' => ['hotline.incident.snapshot'],
                'max_payload_bytes' => 4096,
                'rate_limit_per_minute' => 12,
                'connect_timeout_seconds' => 3,
                'timeout_seconds' => 8,
                'verify_tls' => true,
            ],
        ]);
        $project->project_code = 'hq';
        $project->save();

        $projectWithoutIngest = new RealtimeProject([
            'client_id' => $client->id,
            'name' => 'HQ No Media',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
        ]);
        $projectWithoutIngest->project_code = 'hq-no-media';
        $projectWithoutIngest->save();

        $this->purgeSpoolPath((new RealtimeMediaChunkQueue())->spoolBasePath());
    }

    public function test_it_accepts_auth_and_room_join(): void
    {
        $gateway = $this->gateway();
        $conn = $this->connection($this->token([
            'jti' => 'rt_gateway_001',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['chat.thread.'],
        ]));

        $gateway->onOpen($conn);
        $this->assertDatabaseHas('realtime_sessions', [
            'session_id' => 'rt_gateway_001',
            'client_code' => 'pbb-hq',
            'project_code' => 'hq',
            'app_code' => 'pbb-hq',
            'display_name' => 'Gateway User',
            'user_identity' => '1024',
            'status' => 'connected',
        ]);

        $gateway->onMessage($conn, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_join_001',
            'type' => 'room.join.request',
            'room' => 'chat.thread.thread_123',
            'payload' => (object) [],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($conn);

        $this->assertSame('ack', $messages[1]['phase']);
        $this->assertSame('room.join.request', $messages[1]['type']);
        $this->assertTrue($messages[1]['payload']['joined']);
    }

    public function test_it_accepts_authenticated_session_health_requests_without_a_room(): void
    {
        $gateway = $this->gateway();
        $conn = $this->connection($this->token([
            'jti' => 'rt_gateway_health_001',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['chat.thread.'],
        ]));

        $gateway->onOpen($conn);
        $this->send($gateway, $conn, 'room.join.request', 'chat.thread.thread_123', []);
        $this->send($gateway, $conn, 'session.health.request', null, [
            'client_time' => '2026-05-06T04:00:00.000Z',
            'sequence' => 1,
        ]);

        $messages = $this->decodedMessages($conn);
        $health = $messages[array_key_last($messages)];

        $this->assertSame('ack', $health['phase']);
        $this->assertSame('session.health.request', $health['type']);
        $this->assertArrayNotHasKey('room', $health);
        $this->assertTrue($health['payload']['ok']);
        $this->assertTrue($health['payload']['authenticated']);
        $this->assertSame('rt_gateway_health_001', $health['payload']['session_id']);
        $this->assertIsString($health['payload']['connection_id']);
        $this->assertSame(1, $health['payload']['rooms_joined_count']);
        $this->assertSame(30, $health['payload']['heartbeat_interval_seconds']);
        $this->assertIsString($health['payload']['server_time']);
    }

    public function test_it_rejects_session_health_requests_before_authentication(): void
    {
        $gateway = $this->gateway();
        $conn = new FakeRealtimeConnection();

        $this->send($gateway, $conn, 'session.health.request', null, []);

        $messages = $this->decodedMessages($conn);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('auth.required', $last['payload']['code']);
    }

    public function test_it_rejects_query_token_auth_without_session_connect_capability(): void
    {
        $gateway = $this->gateway();
        $conn = $this->connection($this->token([
            'jti' => 'rt_gateway_auth_002',
            'capabilities' => ['room.join'],
            'allowed_room_prefixes' => ['chat.thread.'],
        ]));

        $gateway->onOpen($conn);

        $this->assertTrue($conn->closed);
        $this->assertDatabaseMissing('realtime_sessions', [
            'session_id' => 'rt_gateway_auth_002',
        ]);
    }

    public function test_it_fans_out_chat_messages_to_room_members(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_002',
            'capabilities' => ['session.connect', 'room.join', 'chat.publish'],
            'allowed_room_prefixes' => ['chat.thread.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $this->send($gateway, $publisher, 'room.join.request', 'chat.thread.thread_123', []);
        $this->send($gateway, $subscriber, 'room.join.request', 'chat.thread.thread_123', []);

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_chat_001',
            'type' => 'chat.message.publish',
            'room' => 'chat.thread.thread_123',
            'payload' => [
                'text' => 'Team is en route to Lahug.',
                'attachments' => [[
                    'transfer_id' => 'xfer_scene_001',
                    'attachment_id' => 'att_scene_001',
                    'kind' => 'image',
                    'name' => 'scene.jpg',
                    'mime_type' => 'image/jpeg',
                    'url' => 'data:image/jpeg;base64,abc123',
                    'preview_url' => 'data:image/jpeg;base64,abc123',
                    'size_label' => '12 KB',
                    'byte_size' => 12288,
                ]],
            ],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($subscriber);

        $this->assertSame('event', $messages[2]['phase']);
        $this->assertSame('chat.message.event', $messages[2]['type']);
        $this->assertSame('Team is en route to Lahug.', $messages[2]['payload']['text']);
        $this->assertSame('xfer_scene_001', $messages[2]['payload']['attachments'][0]['transfer_id']);
        $this->assertSame('att_scene_001', $messages[2]['payload']['attachments'][0]['attachment_id']);
        $this->assertSame('image', $messages[2]['payload']['attachments'][0]['kind']);
        $this->assertSame('scene.jpg', $messages[2]['payload']['attachments'][0]['name']);
        $this->assertSame('image/jpeg', $messages[2]['payload']['attachments'][0]['mime_type']);
        $this->assertSame(12288, $messages[2]['payload']['attachments'][0]['byte_size']);
    }

    public function test_it_fans_out_server_owned_events_to_current_room_members(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_002b',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['hotline.settings.'],
        ]);
        $memberOne = $this->connection($token);
        $memberTwo = $this->connection($token);

        $gateway->onOpen($memberOne);
        $gateway->onOpen($memberTwo);
        $this->send($gateway, $memberOne, 'room.join.request', 'hotline.settings.global', []);
        $this->send($gateway, $memberTwo, 'room.join.request', 'hotline.settings.global', []);

        $fanoutCount = $gateway->publishServerEvent(
            'hotline.settings.global',
            'hotline.alert_level.changed',
            ['alert_level' => 'Red'],
            [
                'source' => 'server',
                'client_code' => 'pbb-hq',
                'project_code' => 'hq',
                'event_id' => 'evt_hotline_alert_001',
            ]
        );

        $this->assertSame(2, $fanoutCount);

        $messages = $this->decodedMessages($memberTwo);

        $this->assertSame('event', $messages[2]['phase']);
        $this->assertSame('hotline.alert_level.changed', $messages[2]['type']);
        $this->assertSame('hotline.settings.global', $messages[2]['room']);
        $this->assertSame('Red', $messages[2]['payload']['alert_level']);
        $this->assertSame('server', $messages[2]['meta']['source']);
        $this->assertSame('pbb-hq', $messages[2]['meta']['client_code']);
        $this->assertSame('hq', $messages[2]['meta']['project_code']);
        $this->assertSame('evt_hotline_alert_001', $messages[2]['meta']['event_id']);
    }

    public function test_it_emits_presence_state_events(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_003',
            'capabilities' => ['session.connect', 'room.join', 'presence.publish', 'presence.subscribe'],
            'org_id' => 'org_001',
            'allowed_room_prefixes' => ['presence.global.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $room = 'presence.global.org_001';
        $this->send($gateway, $subscriber, 'presence.subscribe', $room, ['room' => $room]);
        $this->send($gateway, $publisher, 'presence.subscribe', $room, ['room' => $room]);

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_presence_001',
            'type' => 'presence.publish',
            'room' => $room,
            'payload' => [
                'state' => 'online',
                'status_text' => 'On duty',
                'updated_at' => '2026-03-28T10:05:00Z',
            ],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($subscriber);
        $this->assertSame('event', $messages[2]['phase']);
        $this->assertSame('presence.state.event', $messages[2]['type']);
        $this->assertSame('online', $messages[2]['payload']['state']);
    }

    public function test_it_preserves_presence_metadata_in_events_and_roster_replay(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_presence_meta_001',
            'capabilities' => ['session.connect', 'room.join', 'presence.publish', 'presence.subscribe'],
            'org_id' => 'org_001',
            'allowed_room_prefixes' => ['presence.global.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);
        $room = 'presence.global.org_001';

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $this->send($gateway, $publisher, 'presence.subscribe', $room, ['room' => $room]);
        $this->send($gateway, $subscriber, 'presence.subscribe', $room, ['room' => $room]);

        $this->send($gateway, $publisher, 'presence.publish', $room, [
            'state' => 'busy',
            'status_text' => 'Handling incident',
            'meta' => [
                'incident_id' => 96,
                'workbench_active' => true,
                'note' => 'dispatch',
            ],
        ]);

        $messages = $this->decodedMessages($subscriber);
        $presenceEvents = array_values(array_filter($messages, static fn (array $message): bool => ($message['type'] ?? null) === 'presence.state.event'));
        $latest = $presenceEvents[array_key_last($presenceEvents)];

        $this->assertSame([
            'incident_id' => 96,
            'workbench_active' => true,
            'note' => 'dispatch',
        ], $latest['payload']['meta']);

        $replaySubscriber = $this->connection($token);
        $gateway->onOpen($replaySubscriber);
        $this->send($gateway, $replaySubscriber, 'presence.subscribe', $room, ['room' => $room]);

        $replayMessages = $this->decodedMessages($replaySubscriber);
        $replayedEvents = array_values(array_filter($replayMessages, static fn (array $message): bool => ($message['type'] ?? null) === 'presence.state.event'));
        $this->assertCount(1, $replayedEvents);
        $this->assertSame([
            'incident_id' => 96,
            'workbench_active' => true,
            'note' => 'dispatch',
        ], $replayedEvents[0]['payload']['meta']);
    }

    public function test_it_rejects_presence_metadata_with_nested_values(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_presence_meta_002',
            'capabilities' => ['session.connect', 'room.join', 'presence.publish', 'presence.subscribe'],
            'org_id' => 'org_001',
            'allowed_room_prefixes' => ['presence.global.'],
        ]);
        $publisher = $this->connection($token);
        $room = 'presence.global.org_001';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'presence.subscribe', $room, ['room' => $room]);
        $this->send($gateway, $publisher, 'presence.publish', $room, [
            'state' => 'busy',
            'meta' => [
                'context' => [
                    'incident_id' => 96,
                ],
            ],
        ]);

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('validation.invalid-payload', $last['payload']['code']);
    }

    public function test_it_rejects_presence_metadata_that_exceeds_size_limit(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_presence_meta_003',
            'capabilities' => ['session.connect', 'room.join', 'presence.publish', 'presence.subscribe'],
            'org_id' => 'org_001',
            'allowed_room_prefixes' => ['presence.global.'],
        ]);
        $publisher = $this->connection($token);
        $room = 'presence.global.org_001';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'presence.subscribe', $room, ['room' => $room]);
        $this->send($gateway, $publisher, 'presence.publish', $room, [
            'state' => 'busy',
            'meta' => [
                'oversized' => str_repeat('a', 1100),
            ],
        ]);

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('validation.invalid-payload', $last['payload']['code']);
    }

    public function test_it_replays_full_presence_roster_to_new_subscribers(): void
    {
        $gateway = $this->gateway();
        $room = 'presence.global.org_001';

        $publisherOne = $this->connection($this->token([
            'jti' => 'rt_gateway_presence_001',
            'user_id' => 'citizen_001',
            'capabilities' => ['session.connect', 'room.join', 'presence.publish', 'presence.subscribe'],
            'org_id' => 'org_001',
            'allowed_room_prefixes' => ['presence.global.'],
        ]));
        $publisherTwo = $this->connection($this->token([
            'jti' => 'rt_gateway_presence_002',
            'user_id' => 'operator_001',
            'capabilities' => ['session.connect', 'room.join', 'presence.publish', 'presence.subscribe'],
            'org_id' => 'org_001',
            'allowed_room_prefixes' => ['presence.global.'],
        ]));
        $subscriber = $this->connection($this->token([
            'jti' => 'rt_gateway_presence_003',
            'user_id' => 'observer_001',
            'capabilities' => ['session.connect', 'room.join', 'presence.subscribe'],
            'org_id' => 'org_001',
            'allowed_room_prefixes' => ['presence.global.'],
        ]));

        $gateway->onOpen($publisherOne);
        $gateway->onOpen($publisherTwo);
        $gateway->onOpen($subscriber);

        $this->send($gateway, $publisherOne, 'presence.subscribe', $room, ['room' => $room]);
        $this->send($gateway, $publisherTwo, 'presence.subscribe', $room, ['room' => $room]);

        $this->send($gateway, $publisherOne, 'presence.publish', $room, [
            'state' => 'online',
            'status_text' => 'Citizen ready',
            'updated_at' => '2026-03-31T10:00:00Z',
        ]);
        $this->send($gateway, $publisherTwo, 'presence.publish', $room, [
            'state' => 'online',
            'status_text' => 'Operator ready',
            'updated_at' => '2026-03-31T10:00:01Z',
        ]);

        $this->send($gateway, $subscriber, 'presence.subscribe', $room, ['room' => $room]);

        $messages = $this->decodedMessages($subscriber);
        $presenceEvents = array_values(array_filter($messages, static fn (array $message): bool => ($message['type'] ?? null) === 'presence.state.event'));

        $this->assertCount(2, $presenceEvents);
        $this->assertSame(
            ['citizen_001', 'operator_001'],
            collect($presenceEvents)
                ->pluck('payload.subject.user_id')
                ->sort()
                ->values()
                ->all()
        );
    }

    public function test_it_emits_call_signal_events(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_004',
            'capabilities' => ['session.connect', 'room.join', 'call.signal'],
            'allowed_room_prefixes' => ['call.session.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $room = 'call.session.session_456';
        $this->send($gateway, $subscriber, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_call_001',
            'type' => 'call.signal.publish',
            'room' => $room,
            'payload' => [
                'signal_type' => 'offer',
                'target_user_id' => '2048',
                'sdp' => 'dummy-sdp',
                'candidate_json' => json_encode(['candidate' => 'candidate:1 1 udp 1 127.0.0.1 999 typ host'], JSON_THROW_ON_ERROR),
                'meta_json' => json_encode(['mode' => 'video'], JSON_THROW_ON_ERROR),
            ],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($subscriber);
        $this->assertSame('event', $messages[2]['phase']);
        $this->assertSame('call.signal.event', $messages[2]['type']);
        $this->assertSame('offer', $messages[2]['payload']['signal_type']);
        $this->assertSame('dummy-sdp', $messages[2]['payload']['sdp']);
        $this->assertSame('video', json_decode($messages[2]['payload']['meta_json'], true, 512, JSON_THROW_ON_ERROR)['mode']);
    }

    public function test_it_fans_out_browser_published_app_events(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_004b',
            'capabilities' => ['session.connect', 'room.join', 'event.publish'],
            'allowed_room_prefixes' => ['hotline.discovery.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);
        $room = 'hotline.discovery.city-cebu';

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $subscriber, 'room.join.request', $room, []);

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_event_001',
            'type' => 'app.event.publish',
            'room' => $room,
            'payload' => [
                'event_type' => 'citizen.call.request',
                'data' => [
                    'call_id' => 'call_001',
                    'priority' => 'high',
                ],
                'correlation_id' => 'corr_001',
            ],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($subscriber);

        $this->assertSame('event', $messages[2]['phase']);
        $this->assertSame('citizen.call.request', $messages[2]['type']);
        $this->assertSame($room, $messages[2]['room']);
        $this->assertSame('call_001', $messages[2]['payload']['call_id']);
        $this->assertSame('high', $messages[2]['payload']['priority']);
        $this->assertSame('client', $messages[2]['meta']['source']);
        $this->assertSame('1024', $messages[2]['meta']['sender']['user_id']);
        $this->assertSame('Gateway User', $messages[2]['meta']['sender']['display_name']);
        $this->assertSame('corr_001', $messages[2]['meta']['correlation_id']);

        $publisherMessages = $this->decodedMessages($publisher);
        $ack = $publisherMessages[array_key_last($publisherMessages)];
        $this->assertSame('ack', $ack['phase']);
        $this->assertSame('app.event.publish', $ack['type']);
        $this->assertTrue($ack['payload']['published']);
        $this->assertSame('citizen.call.request', $ack['payload']['event_type']);
    }

    public function test_it_forwards_product_query_requests_to_product_backend(): void
    {
        Http::fake([
            'https://hotline.pbb.ph/api/internal/realtime/product-query' => Http::response(['status' => 'accepted'], 202),
        ]);

        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_product_query_001',
            'capabilities' => ['session.connect', 'room.join', 'event.publish'],
            'allowed_room_prefixes' => ['presence.global.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);
        $room = 'presence.global.hotline';

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $subscriber, 'room.join.request', $room, []);

        $this->send($gateway, $publisher, 'app.event.publish', $room, [
            'event_type' => 'product.query.request',
            'data' => [
                'schema_version' => 1,
                'request_id' => 'qry_001',
                'query' => 'hotline.incident.snapshot',
                'context' => ['incident_id' => 204],
                'projection' => ['preset' => 'status'],
                'client_state' => ['reason' => 'post-call-reconcile'],
            ],
            'correlation_id' => 'qry_001',
        ]);

        $publisherMessages = $this->decodedMessages($publisher);
        $ack = $publisherMessages[array_key_last($publisherMessages)];

        $this->assertSame('ack', $ack['phase']);
        $this->assertSame('app.event.publish', $ack['type']);
        $this->assertTrue($ack['payload']['accepted']);
        $this->assertSame('forwarded', $ack['payload']['delivery']);
        $this->assertSame('qry_001', $ack['payload']['request_id']);
        $this->assertSame('hotline.incident.snapshot', $ack['payload']['query']);

        $subscriberMessages = $this->decodedMessages($subscriber);
        $this->assertCount(2, $subscriberMessages);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://hotline.pbb.ph/api/internal/realtime/product-query'
                && $request->hasHeader('X-Realtime-Backend-Secret', 'test-product-query-secret')
                && $body['type'] === 'product.query.request'
                && $body['schema_version'] === 1
                && $body['client_code'] === 'pbb-hq'
                && $body['project_code'] === 'hq'
                && $body['room'] === 'presence.global.hotline'
                && $body['request']['request_id'] === 'qry_001'
                && $body['request']['query'] === 'hotline.incident.snapshot'
                && $body['request']['context']['incident_id'] === 204
                && $body['request']['projection']['preset'] === 'status'
                && $body['meta']['correlation_id'] === 'qry_001'
                && $body['meta']['sender']['user_id'] === '1024';
        });
    }

    public function test_it_rejects_product_query_requests_for_disallowed_queries(): void
    {
        Http::fake();

        $gateway = $this->gateway();
        $publisher = $this->connection($this->token([
            'jti' => 'rt_gateway_product_query_002',
            'capabilities' => ['session.connect', 'room.join', 'event.publish'],
            'allowed_room_prefixes' => ['presence.global.'],
        ]));
        $room = 'presence.global.hotline';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'app.event.publish', $room, [
            'event_type' => 'product.query.request',
            'data' => [
                'schema_version' => 1,
                'request_id' => 'qry_002',
                'query' => 'hotline.incident.full-admin',
            ],
        ]);

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('product-query.query-not-allowed', $last['payload']['code']);
        Http::assertNothingSent();
    }

    public function test_it_emits_product_query_response_error_when_backend_forward_fails(): void
    {
        Http::fake([
            'https://hotline.pbb.ph/api/internal/realtime/product-query' => Http::response(['status' => false], 503),
        ]);

        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_product_query_003',
            'capabilities' => ['session.connect', 'room.join', 'event.publish'],
            'allowed_room_prefixes' => ['presence.global.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);
        $room = 'presence.global.hotline';

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $subscriber, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'app.event.publish', $room, [
            'event_type' => 'product.query.request',
            'data' => [
                'schema_version' => 1,
                'request_id' => 'qry_003',
                'query' => 'hotline.incident.snapshot',
            ],
        ]);

        $publisherMessages = $this->decodedMessages($publisher);
        $last = $publisherMessages[array_key_last($publisherMessages)];
        $this->assertSame('error', $last['phase']);
        $this->assertSame('product-query.forward-failed', $last['payload']['code']);

        $subscriberMessages = $this->decodedMessages($subscriber);
        $response = $subscriberMessages[array_key_last($subscriberMessages)];
        $this->assertSame('event', $response['phase']);
        $this->assertSame('product.query.response', $response['type']);
        $this->assertSame('qry_003', $response['payload']['request_id']);
        $this->assertSame('hotline.incident.snapshot', $response['payload']['query']);
        $this->assertSame('error', $response['payload']['status']);
        $this->assertSame('product-query.forward-failed', $response['payload']['error']['code']);
    }

    public function test_it_rejects_browser_app_events_without_capability(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_004c',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['hotline.discovery.'],
        ]);
        $publisher = $this->connection($token);
        $room = 'hotline.discovery.city-cebu';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'app.event.publish', $room, [
            'event_type' => 'citizen.call.request',
            'data' => ['call_id' => 'call_001'],
        ]);

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('auth.missing-capability', $last['payload']['code']);
    }

    public function test_it_rejects_browser_app_events_until_the_room_is_joined(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_004d',
            'capabilities' => ['session.connect', 'room.join', 'event.publish'],
            'allowed_room_prefixes' => ['hotline.discovery.'],
        ]);
        $publisher = $this->connection($token);
        $room = 'hotline.discovery.city-cebu';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'app.event.publish', $room, [
            'event_type' => 'citizen.call.request',
            'data' => ['call_id' => 'call_001'],
        ]);

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('room.not-joined', $last['payload']['code']);
    }

    public function test_it_rejects_browser_app_events_with_invalid_payload_shape(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_004e',
            'capabilities' => ['session.connect', 'room.join', 'event.publish'],
            'allowed_room_prefixes' => ['hotline.discovery.'],
        ]);
        $publisher = $this->connection($token);
        $room = 'hotline.discovery.city-cebu';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_event_002',
            'type' => 'app.event.publish',
            'room' => $room,
            'payload' => [
                'event_type' => 'citizen.call.request',
                'data' => 'not-an-object',
            ],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('validation.invalid-payload', $last['payload']['code']);
    }

    public function test_it_forwards_media_chunks_without_room_fanout(): void
    {
        Http::fake();

        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_media_001',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['call.session.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);
        $room = 'call.session.session_media_001';

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $subscriber, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'media.chunk.publish', $room, [
            'incident_id' => 'inc_001',
            'call_session_id' => 'call_001',
            'media_id' => 'media_001',
            'type' => 'recording',
            'peer_user_id' => '2048',
            'peer_role' => 'citizen',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
            'extension' => 'webm',
            'chunk_index' => 0,
            'chunk_total' => 2,
            'total_bytes' => 6,
            'chunk_data' => 'YWJjMTIz',
            'correlation_id' => 'corr_media_001',
        ]);

        $publisherMessages = $this->decodedMessages($publisher);
        $ack = $publisherMessages[array_key_last($publisherMessages)];
        $this->assertSame('ack', $ack['phase']);
        $this->assertSame('media.chunk.publish', $ack['type']);
        $this->assertTrue($ack['payload']['accepted']);
        $this->assertSame('queued', $ack['payload']['delivery']);
        $this->assertSame('media_001', $ack['payload']['media_id']);
        $this->assertSame('corr_media_001', $ack['payload']['correlation_id']);

        $subscriberMessages = $this->decodedMessages($subscriber);
        $this->assertCount(2, $subscriberMessages);

        $queued = $this->latestSpooledChunk();
        $this->assertNotNull($queued);
        $this->assertSame('pending', $queued['status']);
        $this->assertSame('call.session.session_media_001', $queued['room']);
        $this->assertSame('pbb-hq', $queued['client_code']);
        $this->assertSame('hq', $queued['project_code']);
        $this->assertSame('media_001', $queued['payload']['media_id']);
        $this->assertSame(0, $queued['payload']['chunk_index']);
        Http::assertNothingSent();
    }

    public function test_it_emits_media_chunk_forwarded_after_downstream_ingest_succeeds(): void
    {
        Http::fake([
            'https://media-ingest.pbb.ph/api/internal/media/chunks' => Http::response([
                'status' => 'accepted',
            ], 201),
        ]);

        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_media_forwarded_001',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['call.session.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);
        $room = 'call.session.session_media_forwarded_001';

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $subscriber, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'media.chunk.publish', $room, [
            'media_id' => 'media_forwarded_001',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
            'chunk_index' => 0,
            'chunk_data' => 'YWJjMTIz',
            'correlation_id' => 'corr_forwarded_001',
        ]);

        $chunk = $this->latestSpooledChunk();
        $this->assertNotNull($chunk);

        $dispatcher = new RealtimeMediaChunkDispatcher(
            new RealtimeMediaChunkForwarder(),
            new RealtimeMediaChunkQueue(),
        );
        $result = $dispatcher->drain(25, $gateway);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);

        $subscriberMessages = $this->decodedMessages($subscriber);
        $outcome = $subscriberMessages[array_key_last($subscriberMessages)];

        $this->assertSame('event', $outcome['phase']);
        $this->assertSame('media.chunk.forwarded', $outcome['type']);
        $this->assertSame($room, $outcome['room']);
        $this->assertSame($chunk['chunk_id'], $outcome['payload']['chunk_id']);
        $this->assertSame('media_forwarded_001', $outcome['payload']['media_id']);
        $this->assertSame(0, $outcome['payload']['chunk_index']);
        $this->assertSame('corr_forwarded_001', $outcome['payload']['correlation_id']);
        $this->assertSame('forwarded', $outcome['payload']['status']);
        $this->assertSame(201, $outcome['payload']['downstream_status']);
        $this->assertSame('media.chunk.accepted', $outcome['payload']['code']);
        $this->assertSame('media-ingest', $outcome['meta']['source']);
        $this->assertDatabaseMissing('realtime_media_chunks', [
            'chunk_id' => $chunk['chunk_id'],
        ]);
    }

    public function test_it_accepts_prepared_binary_media_chunks_into_spool(): void
    {
        Http::fake();

        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_media_binary_001',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['call.session.'],
        ]);
        $publisher = $this->connection($token);
        $room = 'call.session.session_media_binary_001';
        $binary = 'abc123';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'media.chunk.prepare', $room, [
            'transfer_id' => 'xfer_binary_001',
            'media_id' => 'media_binary_001',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
            'extension' => 'webm',
            'chunk_index' => 0,
            'total_bytes' => strlen($binary),
            'correlation_id' => 'corr_binary_001',
        ]);

        $messages = $this->decodedMessages($publisher);
        $prepareAck = $messages[array_key_last($messages)];
        $this->assertSame('ack', $prepareAck['phase']);
        $this->assertSame('media.chunk.prepare', $prepareAck['type']);
        $this->assertSame('awaiting_binary', $prepareAck['payload']['delivery']);
        $this->assertSame('xfer_binary_001', $prepareAck['payload']['transfer_id']);

        $gateway->onMessage($publisher, $this->binaryFrame('xfer_binary_001', $binary));

        $messages = $this->decodedMessages($publisher);
        $queuedEvent = $messages[array_key_last($messages)];
        $this->assertSame('event', $queuedEvent['phase']);
        $this->assertSame('media.chunk.queued', $queuedEvent['type']);
        $this->assertSame('queued', $queuedEvent['payload']['delivery']);
        $this->assertSame('xfer_binary_001', $queuedEvent['payload']['transfer_id']);
        $this->assertSame(strlen($binary), $queuedEvent['payload']['bytes']);

        $queued = $this->latestSpooledChunk();
        $this->assertNotNull($queued);
        $this->assertSame('xfer_binary_001', $queued['payload']['transfer_id']);
        $this->assertSame('media_binary_001', $queued['payload']['media_id']);
        $this->assertArrayNotHasKey('chunk_data', $queued['payload']);
        $this->assertNotEmpty($queued['binary_path']);

        $basePath = (new RealtimeMediaChunkQueue())->spoolBasePath();
        $binaryPath = dirname($this->latestSpooledChunkPath()) . DIRECTORY_SEPARATOR . $queued['binary_path'];
        $this->assertStringStartsWith($basePath, $binaryPath);
        $this->assertSame($binary, File::get($binaryPath));
        Http::assertNothingSent();
    }

    public function test_it_rejects_orphan_binary_media_frames(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_media_binary_orphan_001',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['call.session.'],
        ]);
        $publisher = $this->connection($token);

        $gateway->onOpen($publisher);
        $gateway->onMessage($publisher, $this->binaryFrame('missing_xfer', 'abc123'));

        $messages = $this->decodedMessages($publisher);
        $error = $messages[array_key_last($messages)];
        $this->assertSame('error', $error['phase']);
        $this->assertSame('media.unknown-transfer', $error['payload']['code']);
    }

    public function test_it_emits_media_chunk_failed_after_downstream_ingest_fails(): void
    {
        Http::fake([
            'https://media-ingest.pbb.ph/api/internal/media/chunks' => Http::response([
                'status' => 'rejected',
            ], 500),
        ]);

        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_media_failed_001',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['call.session.'],
        ]);
        $publisher = $this->connection($token);
        $room = 'call.session.session_media_failed_001';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'media.chunk.publish', $room, [
            'segment_key' => 'seg_failed_001',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
            'chunk_index' => 1,
            'chunk_data' => 'YWJjMTIz',
            'correlation_id' => 'corr_failed_001',
        ]);

        $chunk = $this->latestSpooledChunk();
        $this->assertNotNull($chunk);

        $dispatcher = new RealtimeMediaChunkDispatcher(
            new RealtimeMediaChunkForwarder(),
            new RealtimeMediaChunkQueue(),
        );
        $result = $dispatcher->drain(25, $gateway);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);

        $publisherMessages = $this->decodedMessages($publisher);
        $outcome = $publisherMessages[array_key_last($publisherMessages)];

        $this->assertSame('event', $outcome['phase']);
        $this->assertSame('media.chunk.failed', $outcome['type']);
        $this->assertSame($room, $outcome['room']);
        $this->assertSame($chunk['chunk_id'], $outcome['payload']['chunk_id']);
        $this->assertSame('seg_failed_001', $outcome['payload']['segment_key']);
        $this->assertSame(1, $outcome['payload']['chunk_index']);
        $this->assertSame('corr_failed_001', $outcome['payload']['correlation_id']);
        $this->assertSame('failed', $outcome['payload']['status']);
        $this->assertSame(500, $outcome['payload']['downstream_status']);
        $this->assertSame('media.ingest-failed', $outcome['payload']['code']);
        $this->assertSame('Realtime downstream media ingest rejected the chunk.', $outcome['payload']['message']);
        $this->assertDatabaseHas('realtime_media_chunks', [
            'chunk_id' => $chunk['chunk_id'],
            'status' => 'failed',
        ]);
    }

    public function test_it_rejects_media_chunks_when_the_project_scope_has_no_media_ingest(): void
    {
        Http::fake();

        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_media_002',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['call.session.'],
            'project_code' => 'hq-no-media',
        ]);
        $publisher = $this->connection($token);
        $room = 'call.session.session_media_002';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'media.chunk.publish', $room, [
            'media_id' => 'media_002',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
            'chunk_index' => 0,
            'chunk_data' => 'YWJjMTIz',
        ]);

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('media.ingest-unavailable', $last['payload']['code']);
        Http::assertNothingSent();
    }

    public function test_it_rejects_media_chunks_with_invalid_payload_shape(): void
    {
        Http::fake();

        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_media_003',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['call.session.'],
        ]);
        $publisher = $this->connection($token);
        $room = 'call.session.session_media_003';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'media.chunk.publish', $room, [
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
            'chunk_index' => 0,
            'chunk_data' => 'not-base64!',
        ]);

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('validation.invalid-payload', $last['payload']['code']);
        Http::assertNothingSent();
    }

    public function test_it_rejects_media_chunks_when_downstream_ingest_fails(): void
    {
        Http::fake();

        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_media_004',
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_room_prefixes' => ['call.session.'],
        ]);
        $publisher = $this->connection($token);
        $room = 'call.session.session_media_004';

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', $room, []);
        $this->send($gateway, $publisher, 'media.chunk.publish', $room, [
            'segment_key' => 'seg_004',
            'type' => 'recording',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm',
            'chunk_index' => 1,
            'chunk_data' => 'YWJjMTIz',
        ]);

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('ack', $last['phase']);
        $this->assertTrue($last['payload']['accepted']);
        $this->assertSame('queued', $last['payload']['delivery']);
        $queued = $this->latestSpooledChunk();
        $this->assertNotNull($queued);
        $this->assertSame('pending', $queued['status']);
        Http::assertNothingSent();
    }

    public function test_it_fans_out_sandbox_attachment_chunks(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_005',
            'capabilities' => ['session.connect', 'room.join', 'chat.publish'],
            'allowed_room_prefixes' => ['chat.thread.'],
        ]);
        $publisher = $this->connection($token);
        $subscriber = $this->connection($token);

        $gateway->onOpen($publisher);
        $gateway->onOpen($subscriber);
        $this->send($gateway, $publisher, 'room.join.request', 'chat.thread.thread_123', []);
        $this->send($gateway, $subscriber, 'room.join.request', 'chat.thread.thread_123', []);

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_chunk_001',
            'type' => 'sandbox.attachment.chunk.publish',
            'room' => 'chat.thread.thread_123',
            'payload' => [
                'transfer_id' => 'xfer_001',
                'attachment_id' => 'att_001',
                'name' => 'scene.jpg',
                'kind' => 'image',
                'mime_type' => 'image/jpeg',
                'size_label' => '12 KB',
                'total_bytes' => 6,
                'chunk_index' => 0,
                'chunk_total' => 2,
                'chunk_data' => 'abc123',
            ],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($subscriber);
        $this->assertSame('event', $messages[2]['phase']);
        $this->assertSame('sandbox.attachment.chunk.event', $messages[2]['type']);
        $this->assertSame('xfer_001', $messages[2]['payload']['transfer_id']);
        $this->assertSame('abc123', $messages[2]['payload']['chunk_data']);
        $this->assertSame(6, $messages[2]['payload']['byte_size']);
    }

    public function test_it_rejects_chat_attachment_metadata_that_exceeds_policy_limit(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_006',
            'capabilities' => ['session.connect', 'room.join', 'chat.publish'],
            'allowed_room_prefixes' => ['chat.thread.'],
            'attachment_policy' => [
                'max_attachment_count' => 3,
                'max_attachment_bytes' => 1024,
                'max_total_bytes_per_message' => 4096,
                'chunk_events_per_minute' => 120,
                'chunk_bytes_per_minute' => 1024 * 1024,
            ],
        ]);
        $publisher = $this->connection($token);

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', 'chat.thread.thread_123', []);

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_chat_oversize_001',
            'type' => 'chat.message.publish',
            'room' => 'chat.thread.thread_123',
            'payload' => [
                'text' => 'oversize attachment',
                'attachments' => [[
                    'attachment_id' => 'att_oversize_001',
                    'name' => 'oversize.jpg',
                    'kind' => 'image',
                    'mime_type' => 'image/jpeg',
                    'byte_size' => 2048,
                ]],
            ],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('attachment-too-large', $last['payload']['code']);
    }

    public function test_it_rejects_attachment_chunk_when_declared_bytes_exceed_policy_limit(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_007',
            'capabilities' => ['session.connect', 'room.join', 'chat.publish'],
            'allowed_room_prefixes' => ['chat.thread.'],
            'attachment_policy' => [
                'max_attachment_count' => 3,
                'max_attachment_bytes' => 4,
                'max_total_bytes_per_message' => 4096,
                'chunk_events_per_minute' => 120,
                'chunk_bytes_per_minute' => 1024 * 1024,
            ],
        ]);
        $publisher = $this->connection($token);

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', 'chat.thread.thread_123', []);

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_chunk_oversize_001',
            'type' => 'sandbox.attachment.chunk.publish',
            'room' => 'chat.thread.thread_123',
            'payload' => [
                'transfer_id' => 'xfer_oversize_001',
                'attachment_id' => 'att_oversize_001',
                'name' => 'scene.jpg',
                'kind' => 'image',
                'mime_type' => 'image/jpeg',
                'size_label' => '12 KB',
                'total_bytes' => 5,
                'chunk_index' => 0,
                'chunk_total' => 1,
                'chunk_data' => 'abc123',
            ],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('attachment-too-large', $last['payload']['code']);
    }

    public function test_it_rate_limits_attachment_chunks_using_attachment_transport_bucket(): void
    {
        $gateway = $this->gateway();
        $token = $this->token([
            'jti' => 'rt_gateway_008',
            'capabilities' => ['session.connect', 'room.join', 'chat.publish'],
            'allowed_room_prefixes' => ['chat.thread.'],
            'attachment_policy' => [
                'max_attachment_count' => 3,
                'max_attachment_bytes' => 1024 * 1024,
                'max_total_bytes_per_message' => 2 * 1024 * 1024,
                'chunk_events_per_minute' => 1,
                'chunk_bytes_per_minute' => 1024 * 1024,
            ],
        ]);
        $publisher = $this->connection($token);

        $gateway->onOpen($publisher);
        $this->send($gateway, $publisher, 'room.join.request', 'chat.thread.thread_123', []);

        $payload = [
            'transfer_id' => 'xfer_rate_001',
            'attachment_id' => 'att_rate_001',
            'name' => 'scene.jpg',
            'kind' => 'image',
            'mime_type' => 'image/jpeg',
            'size_label' => '12 KB',
            'total_bytes' => 6,
            'chunk_total' => 2,
            'chunk_data' => 'abc123',
        ];

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_chunk_rate_001',
            'type' => 'sandbox.attachment.chunk.publish',
            'room' => 'chat.thread.thread_123',
            'payload' => $payload + ['chunk_index' => 0],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $gateway->onMessage($publisher, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_chunk_rate_002',
            'type' => 'sandbox.attachment.chunk.publish',
            'room' => 'chat.thread.thread_123',
            'payload' => $payload + ['chunk_index' => 1],
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $messages = $this->decodedMessages($publisher);
        $last = $messages[array_key_last($messages)];

        $this->assertSame('error', $last['phase']);
        $this->assertSame('rate-limited', $last['payload']['code']);
    }

    private function gateway(): RealtimeGateway
    {
        return new RealtimeGateway(
            new RealtimeTokenValidator(),
            new RealtimeRoomPolicy(),
            new RealtimeMetrics(),
            new RealtimeSessionRecorder(),
            new RealtimeUsageTelemetry(),
            new RealtimeMediaChunkQueue(),
            new RealtimeProductQueryForwarder(),
            (string) config('realtime.service_name'),
            (int) config('realtime.heartbeat_interval_seconds', 30),
            (int) config('realtime.presence_stale_seconds', 90),
            (int) config('realtime.message_rate_limit_per_minute', 120),
            (int) config('realtime.room_join_rate_limit_per_minute', 30),
            (int) config('realtime.max_rooms_per_session', 50)
        );
    }

    private function connection(string $token): FakeRealtimeConnection
    {
        $conn = new FakeRealtimeConnection();
        $conn->httpRequest = new Request('GET', '/realtime?token=' . urlencode($token));

        return $conn;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function token(array $claims): string
    {
        $claims = array_merge([
            'iss' => 'local.pbb.test',
            'sub' => 'user_1024',
            'aud' => 'pbb-realtime',
            'exp' => time() + 300,
            'project_code' => 'hq',
            'app_code' => 'pbb-hq',
            'user_id' => '1024',
            'display_name' => 'Gateway User',
        ], $claims);

        return JWT::encode($claims, $this->secret, 'HS256');
    }

    /**
     * @param FakeRealtimeConnection $conn
     * @return array<int, array<string, mixed>>
     */
    private function decodedMessages(FakeRealtimeConnection $conn): array
    {
        return array_map(static fn (string $message): array => json_decode($message, true, 512, JSON_THROW_ON_ERROR), $conn->sent);
    }

    /**
     * @param RealtimeGateway $gateway
     * @param FakeRealtimeConnection $conn
     * @param string $type
     * @param string|null $room
     * @param array<string, mixed> $payload
     */
    private function send(RealtimeGateway $gateway, FakeRealtimeConnection $conn, string $type, ?string $room, array $payload): void
    {
        $gateway->onMessage($conn, json_encode([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_' . bin2hex(random_bytes(4)),
            'type' => $type,
            'room' => $room,
            'payload' => $payload,
            'meta' => (object) [],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestSpooledChunk(): ?array
    {
        $basePath = (new RealtimeMediaChunkQueue())->spoolBasePath();
        if (!is_dir($basePath)) {
            return null;
        }

        $files = File::allFiles($basePath);
        if ($files === []) {
            return null;
        }

        $paths = array_values(array_filter(
            array_map(static fn ($file): ?string => is_file($file->getPathname()) && $file->getExtension() === 'json' ? $file->getPathname() : null, $files)
        ));
        if ($paths === []) {
            return null;
        }

        usort($paths, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $data = json_decode(File::get($paths[0]), true);

        return is_array($data) ? $data : null;
    }

    private function latestSpooledChunkPath(): ?string
    {
        $basePath = (new RealtimeMediaChunkQueue())->spoolBasePath();
        if (!is_dir($basePath)) {
            return null;
        }

        $paths = array_values(array_filter(
            array_map(static fn ($file): ?string => is_file($file->getPathname()) && $file->getExtension() === 'json' ? $file->getPathname() : null, File::allFiles($basePath))
        ));

        usort($paths, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        return $paths[0] ?? null;
    }

    private function binaryFrame(string $transferId, string $bytes): string
    {
        $header = json_encode(['transfer_id' => $transferId], JSON_THROW_ON_ERROR);

        return 'PBBM' . chr(1) . pack('N', strlen($header)) . $header . $bytes;
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

class FakeRealtimeConnection implements ConnectionInterface
{
    public mixed $httpRequest = null;

    /**
     * @var array<int, string>
     */
    public array $sent = [];

    public bool $closed = false;

    public function send($data)
    {
        $this->sent[] = (string) $data;

        return $this;
    }

    public function close()
    {
        $this->closed = true;
    }
}
