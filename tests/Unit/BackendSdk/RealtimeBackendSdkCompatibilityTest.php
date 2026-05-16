<?php

declare(strict_types=1);

namespace Tests\Unit\BackendSdk;

use App\Realtime\Auth\RealtimeTokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__ . '/../../../sdk/php/pbb_realtime_backend_sdk.php';

class RealtimeBackendSdkCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_backend_sdk_token_is_accepted_by_realtime_validator(): void
    {
        config()->set('realtime.token_signing_secret', 'secret');
        config()->set('realtime.trusted_issuers', ['pbb-hotline-backend']);
        config()->set('realtime.token_audience', 'pbb-realtime');

        $config = new \RealtimeConfig([
            'issuer' => 'pbb-hotline-backend',
            'audience' => 'pbb-realtime',
            'signing_secret' => 'secret',
            'websocket_url' => 'wss://realtime.pbb.ph/realtime',
            'token_ttl_seconds' => 3600,
        ]);

        $admission = new \RealtimeAdmission($config);
        $payload = $admission->buildAdmission([
            'app_code' => 'clt_demo',
            'project_code' => 'prj_demo',
            'user_id' => 'user_123',
            'display_name' => 'Operator 123',
            'room' => 'dispatch-room',
            'conference' => true,
            'presence' => true,
            'capabilities' => [
                'session.connect',
                'room.join',
                'presence.publish',
                'presence.subscribe',
                'chat.publish',
                'chat.subscribe',
                'call.signal',
            ],
            'allowed_room_prefixes' => [
                'chat.thread.',
                'call.session.',
            ],
        ]);

        $validator = new RealtimeTokenValidator();
        $claims = $validator->validate($payload['token']);

        self::assertSame('clt_demo', $claims->appCode);
        self::assertSame('prj_demo', $claims->projectCode);
        self::assertContains('chat.thread.dispatch-room', $claims->allowedRooms);
        self::assertContains('call.session.dispatch-room', $claims->allowedRooms);
    }
}
