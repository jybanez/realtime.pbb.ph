<?php

namespace Tests\Unit;

use App\Models\RealtimeClient;
use App\Realtime\Auth\RealtimeTokenClaims;
use App\Realtime\Auth\RealtimeTokenValidationException;
use App\Realtime\Auth\RealtimeTokenValidator;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeTokenValidatorTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'unit-test-realtime-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'realtime.token_signing_secret' => $this->secret,
            'realtime.token_audience' => 'pbb-realtime',
            'realtime.trusted_issuers' => ['local.pbb.test'],
        ]);
    }

    public function test_it_validates_a_signed_token(): void
    {
        $client = new RealtimeClient([
            'name' => 'PBB HQ',
            'status' => 'active',
            'issuer_identity' => 'local.pbb.test',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);
        $client->client_code = 'pbb-hq';
        $client->save();

        $claims = [
            'iss' => 'local.pbb.test',
            'sub' => 'user_1024',
            'aud' => 'pbb-realtime',
            'exp' => time() + 300,
            'iat' => time(),
            'jti' => 'rt_001',
            'project_code' => 'hq',
            'app_code' => 'pbb-hq',
            'user_id' => '1024',
            'email' => 'operator@pbb.ph',
            'display_name' => 'Operator One',
            'roles' => ['administrator'],
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_rooms' => ['chat.thread.thread_123'],
            'allowed_room_prefixes' => ['chat.thread.'],
        ];

        $validated = (new RealtimeTokenValidator())->validate($this->makeToken($claims));

        $this->assertInstanceOf(RealtimeTokenClaims::class, $validated);
        $this->assertSame('local.pbb.test', $validated->issuer);
        $this->assertSame('pbb-realtime', $validated->audience);
        $this->assertSame('hq', $validated->projectCode);
        $this->assertSame(['administrator'], $validated->roles);
        $this->assertSame(['session.connect', 'room.join'], $validated->capabilities);
    }

    public function test_it_rejects_an_untrusted_issuer(): void
    {
        $client = new RealtimeClient([
            'name' => 'PBB HQ',
            'status' => 'active',
            'issuer_identity' => 'local.pbb.test',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);
        $client->client_code = 'pbb-hq';
        $client->save();

        $this->expectException(RealtimeTokenValidationException::class);
        $this->expectExceptionMessage('issuer is not trusted');

        (new RealtimeTokenValidator())->validate($this->makeToken([
            'iss' => 'untrusted.example',
            'sub' => 'user_1024',
            'aud' => 'pbb-realtime',
            'exp' => time() + 300,
            'project_code' => 'hq',
            'app_code' => 'pbb-hq',
            'user_id' => '1024',
            'capabilities' => ['session.connect'],
        ]));
    }

    public function test_it_accepts_a_client_backed_issuer_even_when_not_globally_trusted(): void
    {
        $client = new RealtimeClient([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'issuer_identity' => 'pbb-hotline-backend',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);
        $client->client_code = 'clt_hotline';
        $client->save();

        $validated = (new RealtimeTokenValidator())->validate($this->makeToken([
            'iss' => 'pbb-hotline-backend',
            'sub' => 'user_2048',
            'aud' => 'pbb-realtime',
            'exp' => time() + 300,
            'project_code' => 'prj_hotline_citizen',
            'app_code' => 'clt_hotline',
            'user_id' => '2048',
            'capabilities' => ['session.connect'],
        ]));

        $this->assertSame('pbb-hotline-backend', $validated->issuer);
        $this->assertSame('clt_hotline', $validated->appCode);
    }

    public function test_it_accepts_a_client_backed_trusted_signing_profile_match(): void
    {
        $client = new RealtimeClient([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'trusted_signing_profile' => 'hotline-app-backend',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);
        $client->client_code = 'clt_hotline';
        $client->save();

        $validated = (new RealtimeTokenValidator())->validate($this->makeToken([
            'iss' => 'hotline-app-backend',
            'sub' => 'user_2049',
            'aud' => 'pbb-realtime',
            'exp' => time() + 300,
            'project_code' => 'prj_hotline_citizen',
            'app_code' => 'clt_hotline',
            'user_id' => '2049',
            'capabilities' => ['session.connect'],
        ]));

        $this->assertSame('hotline-app-backend', $validated->issuer);
    }

    public function test_it_rejects_an_expired_token(): void
    {
        $this->expectException(RealtimeTokenValidationException::class);
        $this->expectExceptionMessage('expired');

        (new RealtimeTokenValidator())->validate($this->makeToken([
            'iss' => 'local.pbb.test',
            'sub' => 'user_1024',
            'aud' => 'pbb-realtime',
            'exp' => time() - 10,
            'project_code' => 'hq',
            'app_code' => 'pbb-hq',
            'user_id' => '1024',
            'capabilities' => ['session.connect'],
        ]));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function makeToken(array $claims): string
    {
        return JWT::encode($claims, $this->secret, 'HS256');
    }
}
