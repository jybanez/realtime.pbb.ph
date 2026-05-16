<?php

namespace Tests\Feature\Admin;

use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PolicyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_a_policy_record(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $client = RealtimeClient::create([
            'name' => 'PBB HQ',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $this->actingAs($operator)
            ->post(route('admin.policies.store'), [
                'client_id' => $client->id,
                'name' => 'Default HQ Policy',
                'status' => 'draft',
                'policy_category' => 'client',
                'allow_deny_mode' => 'allowlist',
                'description' => 'Initial policy definition.',
                'capability_profile_text' => json_encode(['chat' => true, 'presence' => true]),
                'room_policy_profile_text' => json_encode(['allowed_prefixes' => ['chat.thread.']]),
                'rate_limit_profile_text' => json_encode(['auth_per_minute' => 30]),
                'session_limit_profile_text' => json_encode(['concurrent_sessions' => 20]),
            ])
            ->assertRedirect();

        $policy = RealtimePolicy::query()->where('name', 'Default HQ Policy')->firstOrFail();
        $this->assertStringStartsWith('pol_', $policy->policy_code);
        $this->assertSame(['chat' => true, 'presence' => true], $policy->capability_profile);
        $this->assertSame($client->id, $policy->client_id);
    }

    public function test_policy_create_and_edit_routes_redirect_to_browser_shell(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $client = RealtimeClient::create([
            'name' => 'PBB HQ',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $policy = RealtimePolicy::create([
            'client_id' => $client->id,
            'name' => 'Default HQ Policy',
            'status' => 'draft',
            'allow_deny_mode' => 'allowlist',
        ]);

        $this->actingAs($operator)
            ->get(route('admin.policies.create'))
            ->assertRedirect(route('admin.clients.index'));

        $this->actingAs($operator)
            ->get(route('admin.policies.edit', $policy))
            ->assertRedirect(route('admin.clients.show', $client));
    }

    public function test_regular_user_cannot_create_policy_for_unassigned_client(): void
    {
        $user = User::factory()->regularOperator()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $assignedClient = RealtimeClient::create([
            'name' => 'Assigned Client',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $restrictedClient = RealtimeClient::create([
            'name' => 'Restricted Client',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $user->realtimeClients()->sync([$assignedClient->id]);

        $this->actingAs($user)
            ->postJson(route('admin.api.policies.store'), [
                'client_id' => $restrictedClient->id,
                'name' => 'Restricted Policy',
                'status' => 'active',
                'policy_category' => 'client',
                'allow_deny_mode' => 'allowlist',
                'capability_profile_text' => json_encode(['chat' => true]),
                'room_policy_profile_text' => json_encode(['allowed_prefixes' => ['chat.thread.']]),
                'rate_limit_profile_text' => json_encode(['auth_per_minute' => 30]),
                'session_limit_profile_text' => json_encode(['concurrent_sessions' => 20]),
            ])
            ->assertForbidden();
    }

    public function test_regular_user_cannot_update_policy_for_unassigned_client(): void
    {
        $user = User::factory()->regularOperator()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $assignedClient = RealtimeClient::create([
            'name' => 'Assigned Client',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $restrictedClient = RealtimeClient::create([
            'name' => 'Restricted Client',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $policy = RealtimePolicy::create([
            'client_id' => $restrictedClient->id,
            'name' => 'Restricted Policy',
            'status' => 'active',
            'allow_deny_mode' => 'allowlist',
        ]);

        $user->realtimeClients()->sync([$assignedClient->id]);

        $this->actingAs($user)
            ->patchJson(route('admin.api.policies.update', $policy), [
                'client_id' => $restrictedClient->id,
                'name' => 'Restricted Policy Updated',
                'status' => 'active',
                'policy_category' => 'client',
                'allow_deny_mode' => 'allowlist',
                'capability_profile_text' => json_encode(['chat' => true]),
                'room_policy_profile_text' => json_encode(['allowed_prefixes' => ['chat.thread.']]),
                'rate_limit_profile_text' => json_encode(['auth_per_minute' => 30]),
                'session_limit_profile_text' => json_encode(['concurrent_sessions' => 20]),
            ])
            ->assertForbidden();
    }
}
