<?php

namespace Tests\Feature;

use App\Models\RealtimeClient;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeSessionTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'feature-test-realtime-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'realtime.token_signing_secret' => $this->secret,
            'realtime.token_audience' => 'pbb-realtime',
            'realtime.trusted_issuers' => ['local.pbb.test'],
        ]);
    }

    public function test_it_accepts_a_valid_session_token(): void
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

        $response = $this->postJson('/api/realtime/session', [
            'token' => $this->makeToken([
                'iss' => 'local.pbb.test',
                'sub' => 'user_1024',
                'aud' => 'pbb-realtime',
                'exp' => time() + 300,
                'iat' => time(),
                'jti' => 'rt_002',
                'project_code' => 'hq',
                'app_code' => 'pbb-hq',
                'user_id' => '1024',
                'capabilities' => ['session.connect', 'room.join'],
            ]),
        ]);

        $response->assertOk()
            ->assertJson([
                'service' => 'PBB Realtime',
                'status' => 'accepted',
            ])
            ->assertJsonPath('session.project_code', 'hq')
            ->assertJsonPath('session.user_id', '1024');
    }

    public function test_it_accepts_a_client_backed_issuer_not_present_in_global_config(): void
    {
        $client = new RealtimeClient([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'issuer_identity' => 'pbb-hotline-backend',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);
        $client->client_code = 'clt_01KMXFPRXCTHJAG10DMACJFMYB';
        $client->save();

        $response = $this->postJson('/api/realtime/session', [
            'token' => $this->makeToken([
                'iss' => 'pbb-hotline-backend',
                'sub' => 'user_2048',
                'aud' => 'pbb-realtime',
                'exp' => time() + 300,
                'iat' => time(),
                'jti' => 'rt_hotline_001',
                'project_code' => 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9',
                'app_code' => 'clt_01KMXFPRXCTHJAG10DMACJFMYB',
                'user_id' => '2048',
                'capabilities' => ['session.connect', 'room.join'],
            ]),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('session.app_code', 'clt_01KMXFPRXCTHJAG10DMACJFMYB');
    }

    public function test_it_rejects_an_invalid_session_token(): void
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

        $response = $this->postJson('/api/realtime/session', [
            'token' => $this->makeToken([
                'iss' => 'bad.example',
                'sub' => 'user_1024',
                'aud' => 'pbb-realtime',
                'exp' => time() + 300,
                'project_code' => 'hq',
                'app_code' => 'pbb-hq',
                'user_id' => '1024',
                'capabilities' => ['session.connect'],
            ]),
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'invalid-issuer');
    }

    public function test_it_rejects_a_session_token_missing_the_session_capability(): void
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

        $response = $this->postJson('/api/realtime/session', [
            'token' => $this->makeToken([
                'iss' => 'local.pbb.test',
                'sub' => 'user_1024',
                'aud' => 'pbb-realtime',
                'exp' => time() + 300,
                'project_code' => 'hq',
                'app_code' => 'pbb-hq',
                'user_id' => '1024',
                'capabilities' => ['room.join'],
            ]),
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'missing-capability');
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function makeToken(array $claims): string
    {
        return JWT::encode($claims, $this->secret, 'HS256');
    }
}
