<?php

namespace Tests\Feature\Admin;

use App\Models\RealtimeAuditEvent;
use App\Models\RealtimeClient;
use App\Models\User;
use App\Realtime\Ingress\BackendIngressSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_a_client_record(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $this->actingAs($operator)
            ->post(route('admin.clients.store'), [
                'name' => 'PBB HQ',
                'status' => 'active',
                'integration_owner' => 'PBB HQ',
                'issuer_identity' => 'hq-app-backend',
                'token_issuance_mode' => 'app_backend_signed',
                'origin_policy_mode' => 'allowlist',
                'policy_profile_code' => null,
                'capability_profile_code' => null,
                'room_policy_profile_code' => null,
                'allowed_origins_text' => "https://hq.pbb.ph\nhttps://localhost",
                'description' => 'Primary HQ client.',
                'trust_notes' => 'Signed by the HQ backend.',
                'integration_notes' => 'Initial onboarding record.',
            ])
            ->assertRedirect();

        $client = RealtimeClient::query()->where('name', 'PBB HQ')->firstOrFail();
        $this->assertNotEmpty($client->client_code);
        $this->assertNotEmpty($client->project_code);
        $this->assertStringStartsWith('clt_', $client->client_code);
        $this->assertStringStartsWith('prj_', $client->project_code);
        $this->assertSame(['https://hq.pbb.ph', 'https://localhost'], $client->allowed_origins);
        $this->assertDatabaseHas('realtime_audit_events', [
            'action_type' => 'create',
            'target_type' => 'realtime_client',
            'target_code' => $client->client_code,
        ]);
    }

    public function test_operator_can_disable_a_client(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $client = RealtimeClient::create([
            'name' => 'PBB Workspace',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);

        $this->actingAs($operator)
            ->delete(route('admin.clients.destroy', $client))
            ->assertRedirect();

        $client->refresh();
        $this->assertSame('inactive', $client->status);
        $this->assertDatabaseHas('realtime_audit_events', [
            'action_type' => 'status_change',
            'target_code' => $client->client_code,
        ]);
    }

    public function test_client_create_and_edit_routes_redirect_to_browser_shell(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $client = RealtimeClient::create([
            'name' => 'PBB Workspace',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);

        $this->actingAs($operator)
            ->get(route('admin.clients.create'))
            ->assertRedirect(route('admin.clients.index', ['form' => 'create']));

        $this->actingAs($operator)
            ->get(route('admin.clients.edit', $client))
            ->assertRedirect(route('admin.clients.index', ['form' => 'edit', 'client' => $client->id]));
    }

    public function test_regular_user_cannot_create_a_client_record(): void
    {
        $user = User::factory()->regularOperator()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $this->actingAs($user)
            ->post(route('admin.clients.store'), [
                'name' => 'Restricted Client',
                'status' => 'active',
                'token_issuance_mode' => 'app_backend_signed',
                'origin_policy_mode' => 'allowlist',
            ])
            ->assertForbidden();
    }

    public function test_operator_can_rotate_backend_ingress_secret_for_a_client(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $client = RealtimeClient::create([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);

        $this->actingAs($operator)
            ->patch(route('admin.clients.update', $client), [
                'name' => 'PBB Hotline',
                'status' => 'active',
                'integration_owner' => 'PBB Hotline',
                'issuer_identity' => 'hotline-auth@pbb.ph',
                'token_issuance_mode' => 'app_backend_signed',
                'origin_policy_mode' => 'allowlist',
                'trusted_signing_profile' => 'hotline-app-backend',
                'backend_ingress_secret' => 'super-secret-backend-key',
            ])
            ->assertRedirect();

        $client->refresh();

        $this->assertNotEmpty($client->backend_ingress_secret_hash);
        $this->assertNotEmpty($client->backend_ingress_secret_digest);
        $this->assertTrue(Hash::check('super-secret-backend-key', $client->backend_ingress_secret_hash));
        $this->assertSame(
            BackendIngressSecret::digest('super-secret-backend-key'),
            $client->backend_ingress_secret_digest
        );
        $this->assertDatabaseHas('realtime_audit_events', [
            'action_type' => 'backend_ingress_secret.created',
            'target_type' => 'realtime_client',
            'target_code' => $client->client_code,
        ]);
    }
}
