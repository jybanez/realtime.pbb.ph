<?php

declare(strict_types=1);

namespace Tests\Unit\BackendSdk;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../sdk/php/pbb_realtime_backend_sdk.php';

class RealtimeBackendSdkTest extends TestCase
{
    public function test_builds_chat_admission_payload(): void
    {
        $admission = new \RealtimeAdmission($this->config());

        $payload = $admission->buildAdmission([
            'app_code' => 'clt_demo',
            'project_code' => 'prj_demo',
            'user_id' => 'user_123',
            'display_name' => 'Operator 123',
            'room' => 'hotline-room',
            'capabilities' => ['session.connect', 'room.join', 'chat.publish'],
        ]);

        self::assertArrayHasKey('token', $payload);
        self::assertSame('chat.thread.hotline-room', $payload['room']);
        self::assertSame('clt_demo', $payload['app_code']);
        self::assertSame('prj_demo', $payload['project_code']);
        self::assertArrayNotHasKey('call_room', $payload);
    }

    public function test_builds_conference_admission_payload(): void
    {
        $admission = new \RealtimeAdmission($this->config());

        $payload = $admission->buildAdmission([
            'app_code' => 'clt_demo',
            'project_code' => 'prj_demo',
            'user_id' => 'user_123',
            'display_name' => 'Operator 123',
            'room' => 'dispatch-room',
            'conference' => true,
            'capabilities' => ['session.connect', 'room.join', 'call.signal'],
        ]);

        self::assertSame('chat.thread.dispatch-room', $payload['room']);
        self::assertSame('call.session.dispatch-room', $payload['call_room']);
        self::assertContains('call.session.dispatch-room', $payload['session']['allowed_rooms']);
    }

    public function test_presence_builder_merges_presence_capabilities(): void
    {
        $builder = new \RealtimeTokenBuilder($this->config());

        $claims = $builder->forPresenceSession([
            'app_code' => 'clt_demo',
            'project_code' => 'prj_demo',
            'user_id' => 'user_123',
            'display_name' => 'Operator 123',
            'room' => 'presence-room',
        ]);

        self::assertContains('presence.publish', $claims['capabilities']);
        self::assertContains('presence.subscribe', $claims['capabilities']);
    }

    public function test_attachment_policy_is_normalized(): void
    {
        $normalizer = new \RealtimeClaimNormalizer();

        $policy = $normalizer->normalizeAttachmentPolicy([
            'max_attachment_count' => '3',
            'max_attachment_bytes' => '4096',
            'max_total_bytes_per_message' => 8192,
            'chunk_events_per_minute' => '180',
            'chunk_bytes_per_minute' => '1048576',
        ]);

        self::assertSame(3, $policy['max_attachment_count']);
        self::assertSame(4096, $policy['max_attachment_bytes']);
        self::assertSame(8192, $policy['max_total_bytes_per_message']);
        self::assertSame(180, $policy['chunk_events_per_minute']);
        self::assertSame(1048576, $policy['chunk_bytes_per_minute']);
    }

    public function test_sign_returns_jwt_like_token(): void
    {
        $builder = new \RealtimeTokenBuilder($this->config());

        $token = $builder->buildSignedToken([
            'sub' => 'session:user_123',
            'project_code' => 'prj_demo',
            'app_code' => 'clt_demo',
            'user_id' => 'user_123',
            'display_name' => 'Operator 123',
            'capabilities' => ['session.connect'],
            'allowed_rooms' => ['chat.thread.demo-room'],
        ]);

        $segments = explode('.', $token);

        self::assertCount(3, $segments);
        self::assertNotSame('', $segments[0]);
        self::assertNotSame('', $segments[1]);
        self::assertNotSame('', $segments[2]);
    }

    public function test_conference_guardrail_throws_when_limit_is_exceeded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \RealtimeConferenceHelper::enforceParticipantGuardrail(6, 5);
    }

    private function config(): \RealtimeConfig
    {
        return new \RealtimeConfig([
            'issuer' => 'pbb-hotline-backend',
            'audience' => 'pbb-realtime',
            'signing_secret' => 'secret',
            'websocket_url' => 'wss://realtime.pbb.ph/realtime',
            'token_ttl_seconds' => 3600,
        ]);
    }
}
