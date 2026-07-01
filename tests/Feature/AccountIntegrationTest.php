<?php

namespace Tests\Feature;

use App\Models\RealtimeAuditEvent;
use App\Models\RealtimeRuntimeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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
            ->assertJsonPath('data.capabilities.removeUser', true)
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

    public function test_account_admin_can_remove_access_idempotently(): void
    {
        $this->enableAccountAdmin('secret-token');

        $pbbUserId = '01JREALTIMEACCOUNT00000003';
        $user = User::factory()->admin()->create([
            'pbb_user_id' => $pbbUserId,
            'status' => 'active',
            'is_operator' => true,
        ]);

        $this->deleteJson("/api/account-admin/users/{$pbbUserId}", [
            'reason' => 'Remove app access from Account',
        ], $this->accountAdminHeaders())
            ->assertOk()
            ->assertJsonPath('data.removed', true)
            ->assertJsonPath('data.alreadyRemoved', false)
            ->assertJsonPath('data.pbbUserId', $pbbUserId)
            ->assertJsonPath('data.user.pbbUserId', null)
            ->assertJsonPath('data.user.status', 'disabled')
            ->assertJsonPath('data.user.isOperator', false);

        $user->refresh();
        $this->assertNull($user->pbb_user_id);
        $this->assertSame('disabled', $user->status);
        $this->assertFalse((bool) $user->is_operator);
        $this->assertDatabaseHas('realtime_audit_events', [
            'action_type' => 'account_admin_access_removed',
            'target_type' => 'admin_user',
            'target_code' => $user->email,
        ]);

        $this->deleteJson("/api/account-admin/users/{$pbbUserId}", [], $this->accountAdminHeaders())
            ->assertOk()
            ->assertJsonPath('data.removed', true)
            ->assertJsonPath('data.alreadyRemoved', true)
            ->assertJsonPath('data.pbbUserId', $pbbUserId);
    }

    public function test_account_admin_routes_use_api_stack_without_web_csrf(): void
    {
        $route = Route::getRoutes()->getByName('account-admin.users.provision');

        $this->assertNotNull($route);
        $this->assertSame('api/account-admin/users/{pbbUserId}', $route->uri());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('api', $middleware);
        $this->assertContains('account-admin', $middleware);
        $this->assertContains('throttle:120,1', $middleware);
        $this->assertNotContains('web', $middleware);
    }

    public function test_account_sso_callback_provisions_operator_session(): void
    {
        $this->enableAccountSso();

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

    public function test_admin_can_manage_account_settings_without_exposing_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->patchJson('/api/admin/runtime-settings/account', [
            'sso' => [
                'enabled' => true,
                'base_url' => 'https://account.pbb.ph',
                'client_id' => 'pbb-realtime',
                'client_secret' => 'oauth-secret',
                'redirect_uri' => 'https://realtime.pbb.ph/auth/account/callback',
                'post_logout_redirect_uri' => 'https://realtime.pbb.ph',
                'scopes' => 'openid profile',
                'timeout_seconds' => 10,
                'ca_bundle' => '',
            ],
            'app_admin' => [
                'enabled' => true,
                'client' => 'pbb-account',
                'token' => 'service-token',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.account.sso.client_secret_configured', true)
            ->assertJsonMissing(['client_secret' => 'oauth-secret'])
            ->assertJsonPath('data.account.app_admin.token_configured', true)
            ->assertJsonMissing(['token' => 'service-token']);

        $this->patchJson('/api/admin/runtime-settings/account', [
            'sso' => [
                'enabled' => true,
                'base_url' => 'https://account.pbb.ph',
                'client_id' => 'pbb-realtime',
                'client_secret' => '',
                'redirect_uri' => 'https://realtime.pbb.ph/auth/account/callback',
                'post_logout_redirect_uri' => 'https://realtime.pbb.ph',
                'scopes' => 'openid profile',
                'timeout_seconds' => 10,
                'ca_bundle' => '',
            ],
            'app_admin' => [
                'enabled' => true,
                'client' => 'pbb-account',
                'token' => '',
            ],
        ])->assertOk();

        $this->assertSame('oauth-secret', RealtimeRuntimeSetting::query()
            ->where('setting_key', 'account_sso_client_secret')
            ->firstOrFail()
            ->setting_value);
        $this->assertSame('service-token', RealtimeRuntimeSetting::query()
            ->where('setting_key', 'account_admin_api_token')
            ->firstOrFail()
            ->setting_value);

        $this->getJson('/api/admin/operations')
            ->assertOk()
            ->assertJsonPath('data.runtime_settings.account.sso.client_secret_configured', true)
            ->assertJsonMissing(['client_secret' => 'oauth-secret'])
            ->assertJsonPath('data.runtime_settings.account.app_admin.token_configured', true)
            ->assertJsonMissing(['token' => 'service-token']);
    }

    public function test_bootstrap_exposes_sanitized_account_sso_login_state(): void
    {
        $this->getJson('/api/admin/bootstrap')
            ->assertOk()
            ->assertJsonPath('settings.accountSso.enabled', false)
            ->assertJsonPath('settings.accountSso.redirectUrl', '/auth/account/redirect');

        $this->enableAccountSso();

        $this->getJson('/api/admin/bootstrap')
            ->assertOk()
            ->assertJsonPath('settings.accountSso.enabled', true)
            ->assertJsonPath('settings.accountSso.clientId', 'pbb-realtime')
            ->assertJsonPath('settings.accountSso.baseUrl', 'https://account.pbb.ph')
            ->assertJsonPath('settings.accountSso.redirectUrl', '/auth/account/redirect')
            ->assertJsonMissing(['client_secret' => 'oauth-secret']);
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

    private function enableAccountSso(): void
    {
        $settings = [
            'account_sso_enabled' => '1',
            'account_sso_base_url' => 'https://account.pbb.ph',
            'account_sso_client_id' => 'pbb-realtime',
            'account_sso_client_secret' => 'oauth-secret',
            'account_sso_redirect_uri' => 'https://realtime.pbb.ph/auth/account/callback',
            'account_sso_post_logout_redirect_uri' => 'https://realtime.pbb.ph',
            'account_sso_scopes' => 'openid profile',
            'account_sso_timeout_seconds' => '10',
        ];

        foreach ($settings as $key => $value) {
            RealtimeRuntimeSetting::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value]
            );
        }
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
