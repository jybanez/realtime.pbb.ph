<?php

namespace Tests\Feature;

use App\Models\RealtimeAuditEvent;
use App\Models\RealtimeRuntimeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_admin_meta_is_disabled_until_db_settings_enable_it(): void
    {
        $this->getJson('/api/account-admin/meta', [
            'Authorization' => 'Bearer secret',
            'X-PBB-Account-Client' => 'pbb-account',
        ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'account_admin_disabled');
    }

    public function test_account_admin_meta_requires_db_backed_token(): void
    {
        $this->enableAccountAdmin('correct-token');

        $this->getJson('/api/account-admin/meta', [
            'Authorization' => 'Bearer wrong-token',
            'X-PBB-Account-Client' => 'pbb-account',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_app_admin_token');

        $this->getJson('/api/account-admin/meta', $this->accountAdminHeaders('correct-token'))
            ->assertOk()
            ->assertJsonPath('data.app.id', 'pbb-realtime')
            ->assertJsonPath('data.roles.0.value', 'admin')
            ->assertJsonPath('data.roles.1.value', 'regular')
            ->assertJsonPath('data.statuses.1.value', 'disabled')
            ->assertJsonPath('data.capabilities.operatorCapability.field', 'is_operator');
    }

    public function test_account_admin_can_provision_lookup_update_role_and_disable_user(): void
    {
        $this->enableAccountAdmin('secret-token');

        $pbbUserId = '01JREALTIMEACCOUNT00000001';

        $this->getJson("/api/account-admin/users/{$pbbUserId}", $this->accountAdminHeaders())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'linked_user_not_found');

        $this->putJson("/api/account-admin/users/{$pbbUserId}", [
            'name' => 'Realtime Account User',
            'email' => 'account-user@example.test',
            'defaultRole' => 'regular',
        ], $this->accountAdminHeaders())
            ->assertCreated()
            ->assertJsonPath('data.user.pbbUserId', $pbbUserId)
            ->assertJsonPath('data.user.role', 'regular')
            ->assertJsonPath('data.user.status', 'active')
            ->assertJsonPath('data.user.isOperator', true);

        $user = User::query()->where('pbb_user_id', $pbbUserId)->firstOrFail();
        $this->assertTrue($user->is_operator);

        $this->patchJson("/api/account-admin/users/{$pbbUserId}/role", [
            'role' => 'admin',
            'reason' => 'Promoted from Account',
        ], $this->accountAdminHeaders())
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin');

        $this->patchJson("/api/account-admin/users/{$pbbUserId}/status", [
            'status' => 'disabled',
            'reason' => 'Disabled from Account',
        ], $this->accountAdminHeaders())
            ->assertOk()
            ->assertJsonPath('data.user.status', 'disabled');

        $this->assertDatabaseHas('users', [
            'pbb_user_id' => $pbbUserId,
            'user_type' => 'admin',
            'status' => 'disabled',
        ]);
        $this->assertSame(2, RealtimeAuditEvent::query()->where('target_type', 'admin_user')->count());
    }

    public function test_account_sso_callback_provisions_operator_session(): void
    {
        config([
            'account.enabled' => true,
            'account.base_url' => 'https://account.pbb.ph',
            'account.client_id' => 'pbb-realtime',
            'account.client_secret' => 'oauth-secret',
            'account.redirect_uri' => 'https://realtime.pbb.ph/auth/account/callback',
        ]);

        Http::fake([
            'https://account.pbb.ph/oauth/token' => Http::response([
                'user' => [
                    'pbb_user_id' => '01JREALTIMEACCOUNT00000002',
                    'name' => 'SSO Realtime User',
                    'email' => 'sso-user@example.test',
                    'status' => 'active',
                ],
            ]),
        ]);

        $this->withSession([
            'pbb_account.state' => 'known-state',
            'pbb_account.return_to' => '/admin',
        ])
            ->get('/auth/account/callback?code=known-code&state=known-state')
            ->assertRedirect('/admin')
            ->assertSessionHas('account_login_success', true);

        $user = User::query()->where('pbb_user_id', '01JREALTIMEACCOUNT00000002')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('regular', $user->user_type);
        $this->assertTrue($user->is_operator);
        $this->assertSame('active', $user->status);
    }

    public function test_disabled_user_cannot_login_to_admin_surface(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'disabled@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'disabled',
        ]);

        $this->postJson('/admin/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    private function enableAccountAdmin(string $token): void
    {
        RealtimeRuntimeSetting::query()->updateOrCreate(
            ['setting_key' => 'account_admin_api_enabled'],
            ['setting_value' => '1']
        );
        RealtimeRuntimeSetting::query()->updateOrCreate(
            ['setting_key' => 'account_admin_api_token'],
            ['setting_value' => $token]
        );
        RealtimeRuntimeSetting::query()->updateOrCreate(
            ['setting_key' => 'account_admin_api_client'],
            ['setting_value' => 'pbb-account']
        );
    }

    /**
     * @return array<string, string>
     */
    private function accountAdminHeaders(string $token = 'secret-token'): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'X-PBB-Account-Client' => 'pbb-account',
        ];
    }
}
