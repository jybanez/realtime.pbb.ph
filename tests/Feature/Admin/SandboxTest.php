<?php

namespace Tests\Feature\Admin;

use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;
use App\Models\User;
use App\Realtime\Auth\RealtimeTokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'realtime.token_signing_secret' => 'sandbox-feature-secret',
            'realtime.token_audience' => 'pbb-realtime',
            'realtime.trusted_issuers' => ['local.pbb.test'],
            'realtime.public_websocket_url' => 'wss://sandbox-ws.pbb.ph/realtime',
        ]);
    }

    public function test_operator_can_view_sandbox_shell_and_context(): void
    {
        $operator = User::factory()->admin()->create();

        $client = RealtimeClient::query()->create([
            'name' => 'Sandbox Client',
            'status' => 'active',
            'issuer_identity' => 'sandbox-auth@pbb.ph',
            'trusted_signing_profile' => 'sandbox-signing',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $policy = RealtimePolicy::query()->create([
            'client_id' => $client->getKey(),
            'name' => 'Sandbox Policy',
            'status' => 'active',
            'allow_deny_mode' => 'allow',
            'rate_limit_profile' => [
                'attachment_transport' => [
                    'max_attachment_count' => 4,
                    'max_attachment_bytes' => 512000,
                    'max_total_bytes_per_message' => 1048576,
                    'chunk_events_per_minute' => 120,
                    'chunk_bytes_per_minute' => 2097152,
                ],
            ],
        ]);

        $project = RealtimeProject::query()->create([
            'client_id' => $client->getKey(),
            'name' => 'Sandbox Scope',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
            'policy_profile_code' => $policy->policy_code,
            'allowed_origins' => ['https://sandbox.pbb.ph'],
        ]);

        $this->actingAs($operator)
            ->get(route('admin.sandbox.index'))
            ->assertOk();

        $this->actingAs($operator)
            ->get(route('admin.presence.index'))
            ->assertOk();

        $this->actingAs($operator)
            ->getJson(route('admin.api.sandbox.context'))
            ->assertOk()
            ->assertJsonPath('data.clients.0.client_code', $client->client_code)
            ->assertJsonPath('data.clients.0.projects.0.project_code', $project->project_code)
            ->assertJsonPath('data.clients.0.projects.0.policy_profile_code', $policy->policy_code)
            ->assertJsonPath('data.clients.0.projects.0.attachment_policy.max_attachment_count', 4)
            ->assertJsonPath('data.websocket_url', 'wss://sandbox-ws.pbb.ph/realtime');
    }

    public function test_operator_can_issue_a_sandbox_admission_token(): void
    {
        $operator = User::factory()->admin()->create();

        $client = RealtimeClient::query()->create([
            'name' => 'Sandbox Client',
            'status' => 'active',
            'issuer_identity' => 'sandbox-auth@pbb.ph',
            'trusted_signing_profile' => 'sandbox-signing',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $policy = RealtimePolicy::query()->create([
            'client_id' => $client->getKey(),
            'name' => 'Sandbox Policy',
            'status' => 'active',
            'allow_deny_mode' => 'allow',
            'rate_limit_profile' => [
                'attachment_transport' => [
                    'max_attachment_count' => 4,
                    'max_attachment_bytes' => 512000,
                    'max_total_bytes_per_message' => 1048576,
                    'chunk_events_per_minute' => 120,
                    'chunk_bytes_per_minute' => 2097152,
                ],
            ],
        ]);

        $project = RealtimeProject::query()->create([
            'client_id' => $client->getKey(),
            'name' => 'Sandbox Scope',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
            'policy_profile_code' => $policy->policy_code,
            'allowed_origins' => ['https://sandbox.pbb.ph'],
        ]);

        $response = $this->actingAs($operator)
            ->postJson(route('admin.api.sandbox.admission'), [
                'client_code' => $client->client_code,
                'project_code' => $project->project_code,
                'display_name' => 'Realtime Sandbox User',
                'user_id' => 'sandbox-user-01',
                'room' => 'sandbox-room',
            ])
            ->assertOk()
            ->assertJsonPath('data.session.client_code', $client->client_code)
            ->assertJsonPath('data.session.project_code', $project->project_code)
            ->assertJsonPath('data.session.attachment_policy.max_attachment_bytes', 512000)
            ->assertJsonPath('data.effective_room', 'chat.thread.sandbox-room')
            ->assertJsonPath('data.websocket_url', 'wss://sandbox-ws.pbb.ph/realtime');

        $token = $response->json('data.token');
        $claims = (new RealtimeTokenValidator())->validate($token);

        $this->assertSame($project->project_code, $claims->projectCode);
        $this->assertSame($client->client_code, $claims->appCode);
        $this->assertSame('sandbox-user-01', $claims->userId);
        $this->assertContains('chat.publish', $claims->capabilities);
        $this->assertContains('presence.publish', $claims->capabilities);
        $this->assertContains('presence.subscribe', $claims->capabilities);
        $this->assertContains('call.signal', $claims->capabilities);
        $this->assertSame(['chat.thread.sandbox-room', 'call.session.sandbox-room'], $claims->allowedRooms);
        $this->assertSame(4, $claims->attachmentPolicy['max_attachment_count']);
    }

    public function test_regular_user_only_receives_assigned_clients_in_sandbox_context(): void
    {
        $user = User::factory()->regularOperator()->create();

        $assignedClient = RealtimeClient::query()->create([
            'name' => 'Assigned Client',
            'status' => 'active',
            'issuer_identity' => 'assigned-auth@pbb.ph',
            'trusted_signing_profile' => 'assigned-signing',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $otherClient = RealtimeClient::query()->create([
            'name' => 'Other Client',
            'status' => 'active',
            'issuer_identity' => 'other-auth@pbb.ph',
            'trusted_signing_profile' => 'other-signing',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $user->realtimeClients()->attach($assignedClient->id, ['assignment_role' => 'manager']);

        RealtimeProject::query()->create([
            'client_id' => $assignedClient->getKey(),
            'name' => 'Assigned Scope',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
        ]);

        RealtimeProject::query()->create([
            'client_id' => $otherClient->getKey(),
            'name' => 'Other Scope',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.api.sandbox.context'))
            ->assertOk()
            ->assertJsonCount(1, 'data.clients')
            ->assertJsonPath('data.clients.0.client_code', $assignedClient->client_code);
    }
}
