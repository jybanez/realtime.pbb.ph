<?php

namespace Tests\Feature\Admin;

use App\Models\RealtimeAuditEvent;
use App\Models\RealtimeClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users_with_client_assignments(): void
    {
        $admin = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $client = RealtimeClient::create([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);

        $user = User::factory()->regularOperator()->create([
            'name' => 'Hotline Operator',
            'email' => 'hotline-operator@pbb.ph',
        ]);
        $user->realtimeClients()->sync([$client->id]);

        $this->actingAs($admin)
            ->getJson(route('admin.api.users'))
            ->assertOk()
            ->assertJsonFragment([
                'email' => $user->email,
                'client_code' => $client->client_code,
            ]);
    }

    public function test_admin_can_create_and_update_users_with_client_assignments(): void
    {
        $admin = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $clientA = RealtimeClient::create([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);

        $clientB = RealtimeClient::create([
            'name' => 'PBB HQ',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.api.users.store'), [
                'name' => 'Scoped Operator',
                'email' => 'scoped-operator@pbb.ph',
                'user_type' => 'regular',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'client_ids' => [$clientA->id],
            ])
            ->assertOk();

        $userId = $response->json('data.user.id');
        $user = User::query()->findOrFail($userId);
        $this->assertSame('regular', $user->user_type);
        $this->assertTrue($user->is_operator);
        $this->assertSame([$clientA->id], $user->realtimeClients()->pluck('realtime_clients.id')->all());

        $this->actingAs($admin)
            ->patchJson(route('admin.api.users.update', $user), [
                'name' => 'Scoped Operator Updated',
                'email' => 'scoped-operator@pbb.ph',
                'user_type' => 'regular',
                'client_ids' => [$clientA->id, $clientB->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.user.assigned_clients.1.client_code', $clientB->client_code);

        $this->assertEqualsCanonicalizing(
            [$clientA->id, $clientB->id],
            $user->fresh()->realtimeClients()->pluck('realtime_clients.id')->all()
        );
    }

    public function test_regular_user_cannot_access_user_management_surface(): void
    {
        $user = User::factory()->regularOperator()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson(route('admin.api.users'))
            ->assertForbidden();
    }

    public function test_admin_can_fetch_user_specific_audit_events(): void
    {
        $admin = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $user = User::factory()->regularOperator()->create([
            'name' => 'Scoped Operator',
            'email' => 'scoped-operator@pbb.ph',
        ]);

        RealtimeAuditEvent::create([
            'audit_id' => (string) \Illuminate\Support\Str::uuid(),
            'actor_user_id' => $admin->id,
            'actor_identity' => $admin->name,
            'action_type' => 'update',
            'target_type' => 'admin_user',
            'target_code' => $user->email,
            'before_state' => ['name' => 'Scoped Operator'],
            'after_state' => ['name' => 'Scoped Operator Updated'],
            'reason' => 'Updated via admin user management',
            'occurred_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.api.users.audit', $user))
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.events.0.action_type', 'update');
    }
}
