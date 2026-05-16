<?php

declare(strict_types=1);

namespace Tests\Unit\BackendSdk;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../sdk/php/pbb_realtime_backend_sdk.php';

class RealtimeBackendSdkFrontendContractTest extends TestCase
{
    public function test_admission_payload_matches_frontend_contract_shape(): void
    {
        $admission = new \RealtimeAdmission(new \RealtimeConfig([
            'issuer' => 'pbb-hotline-backend',
            'audience' => 'pbb-realtime',
            'signing_secret' => 'secret',
            'websocket_url' => 'wss://realtime.pbb.ph/realtime',
            'token_ttl_seconds' => 3600,
        ]));

        $payload = $admission->buildAdmission([
            'app_code' => 'clt_demo',
            'project_code' => 'prj_demo',
            'user_id' => 'user_123',
            'display_name' => 'Operator 123',
            'room' => 'hotline-room',
            'conference' => true,
            'presence' => true,
            'attachments' => true,
            'capabilities' => [
                'session.connect',
                'room.join',
                'presence.publish',
                'presence.subscribe',
                'chat.publish',
                'chat.subscribe',
                'call.signal',
            ],
        ]);

        self::assertArrayHasKey('token', $payload);
        self::assertArrayHasKey('websocket_url', $payload);
        self::assertArrayHasKey('app_code', $payload);
        self::assertArrayHasKey('project_code', $payload);
        self::assertArrayHasKey('room', $payload);
        self::assertArrayHasKey('expires_at', $payload);
        self::assertArrayHasKey('session', $payload);
        self::assertArrayHasKey('call_room', $payload);

        self::assertIsString($payload['token']);
        self::assertIsString($payload['websocket_url']);
        self::assertIsString($payload['room']);
        self::assertIsArray($payload['session']);

        self::assertArrayHasKey('token_id', $payload['session']);
        self::assertArrayHasKey('user_id', $payload['session']);
        self::assertArrayHasKey('display_name', $payload['session']);
        self::assertArrayHasKey('capabilities', $payload['session']);
        self::assertArrayHasKey('allowed_rooms', $payload['session']);
        self::assertArrayHasKey('allowed_room_prefixes', $payload['session']);
        self::assertArrayHasKey('attachment_policy', $payload['session']);
        self::assertArrayHasKey('call_room', $payload['session']);
    }
}
