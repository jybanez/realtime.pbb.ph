<?php

namespace Tests\Feature\Admin;

use App\Models\RealtimeAuditEvent;
use App\Models\RealtimeClient;
use App\Models\RealtimeProject;
use App\Models\RealtimePolicy;
use App\Models\RealtimeSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrowserDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_fetch_admin_browser_data(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $client = RealtimeClient::create([
            'name' => 'PBB HQ',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $project = RealtimeProject::create([
            'client_id' => $client->id,
            'name' => 'PBB HQ',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
            'allowed_origins' => ['https://hq.pbb.ph'],
            'capability_profile_code' => 'hq-default-capabilities',
            'room_policy_profile_code' => 'hq-default-rooms',
        ]);

        $policy = RealtimePolicy::create([
            'client_id' => $client->id,
            'name' => 'Default HQ Policy',
            'status' => 'draft',
            'allow_deny_mode' => 'allowlist',
        ]);

        $project->update([
            'policy_profile_code' => $policy->policy_code,
        ]);

        RealtimeAuditEvent::create([
            'audit_id' => (string) Str::uuid(),
            'action_type' => 'create',
            'target_type' => 'realtime_client',
            'target_code' => $client->client_code,
            'actor_identity' => 'operator@example.test',
            'occurred_at' => now(),
        ]);

        $this->actingAs($operator)
            ->getJson(route('admin.api.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.counts.clients', 1)
            ->assertJsonPath('data.counts.projects', 1)
            ->assertJsonPath('data.counts.policies', 1)
            ->assertJsonPath('data.counts.audit', 1);

        $clientResponse = $this->actingAs($operator)
            ->getJson(route('admin.api.clients.show', $client))
            ->assertOk()
            ->assertJsonPath('data.client.project_count', 1)
            ->assertJsonPath('data.client.policy_count', 1);

        $this->assertNotEmpty(data_get($clientResponse->json(), 'data.client.projects.0.project_code'));

        $this->actingAs($operator)
            ->getJson(route('admin.api.projects.show', $project))
            ->assertOk()
            ->assertJsonPath('data.project.name', 'PBB HQ');

        $this->actingAs($operator)
            ->getJson(route('admin.api.policies.show', $policy))
            ->assertOk()
            ->assertJsonPath('data.policy.policy_code', $policy->policy_code);
    }

    public function test_regular_user_only_sees_assigned_client_records(): void
    {
        $user = User::factory()->regularOperator()->create();

        $assignedClient = RealtimeClient::create([
            'name' => 'Assigned Client',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $otherClient = RealtimeClient::create([
            'name' => 'Other Client',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $user->realtimeClients()->attach($assignedClient->id, ['assignment_role' => 'manager']);

        $assignedProject = RealtimeProject::create([
            'client_id' => $assignedClient->id,
            'name' => 'Assigned Project',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
        ]);

        $otherProject = RealtimeProject::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Project',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
        ]);

        $assignedPolicy = RealtimePolicy::create([
            'client_id' => $assignedClient->id,
            'name' => 'Assigned Policy',
            'status' => 'active',
            'allow_deny_mode' => 'allowlist',
        ]);

        $otherPolicy = RealtimePolicy::create([
            'client_id' => $otherClient->id,
            'name' => 'Other Policy',
            'status' => 'active',
            'allow_deny_mode' => 'allowlist',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.api.clients'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client_code', $assignedClient->client_code);

        $this->actingAs($user)
            ->getJson(route('admin.api.clients.show', $assignedClient))
            ->assertOk()
            ->assertJsonPath('data.client.client_code', $assignedClient->client_code);

        $this->actingAs($user)
            ->getJson(route('admin.api.clients.show', $otherClient))
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson(route('admin.api.projects.show', $assignedProject))
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('admin.api.projects.show', $otherProject))
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson(route('admin.api.policies.show', $assignedPolicy))
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('admin.api.policies.show', $otherPolicy))
            ->assertForbidden();
    }

    public function test_sessions_api_includes_client_and_project_names(): void
    {
        $admin = User::factory()->admin()->create();

        $client = RealtimeClient::create([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $project = RealtimeProject::create([
            'client_id' => $client->id,
            'name' => 'Hotline Caller',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
        ]);

        RealtimeSession::create([
            'session_id' => 'sbx_test_session_1',
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'app_code' => $client->client_code,
            'display_name' => 'Operator One',
            'user_identity' => 'operator-1',
            'status' => 'connected',
            'connected_at' => now()->subMinute(),
            'last_activity_at' => now(),
            'room_count' => 2,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.api.sessions'))
            ->assertOk()
            ->assertJsonPath('data.items.0.client_code', $client->client_code)
            ->assertJsonPath('data.items.0.client_name', 'PBB Hotline')
            ->assertJsonPath('data.items.0.project_code', $project->project_code)
            ->assertJsonPath('data.items.0.project_name', 'Hotline Caller')
            ->assertJsonPath('data.items.0.display_name', 'Operator One');
    }
}
