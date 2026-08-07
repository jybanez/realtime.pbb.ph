<?php

namespace Tests\Feature\Admin;

use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_a_project_scope(): void
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
            ->postJson(route('admin.api.projects.store'), [
                'client_id' => $client->id,
                'name' => 'PBB HQ Core',
                'status' => 'active',
                'description' => 'Core HQ browser scope.',
                'scope_notes' => 'Seeded project scope for testing.',
                'allowed_origins_text' => "https://hq.pbb.ph\nhttps://localhost",
                'origin_policy_mode' => 'allowlist',
                'policy_profile_code' => null,
                'capability_profile_code' => null,
                'room_policy_profile_code' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.project.name', 'PBB HQ Core');

        $this->assertDatabaseHas('realtime_projects', [
            'client_id' => $client->id,
            'name' => 'PBB HQ Core',
        ]);
    }

    public function test_operator_can_store_project_scoped_media_ingest_settings(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $client = RealtimeClient::create([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $response = $this->actingAs($operator)
            ->postJson(route('admin.api.projects.store'), [
                'client_id' => $client->id,
                'name' => 'Hotline Operator',
                'status' => 'active',
                'description' => 'Operator media scope.',
                'scope_notes' => 'Allows media ingest.',
                'allowed_origins_text' => "https://hotline.pbb.ph",
                'origin_policy_mode' => 'allowlist',
                'policy_profile_code' => null,
                'capability_profile_code' => null,
                'room_policy_profile_code' => null,
                'media_ingest_enabled' => true,
                'media_ingest_base_url' => 'https://hotline.pbb.ph',
                'media_ingest_path' => '/api/internal/media/chunks',
                'media_ingest_auth_header' => 'X-Realtime-Media-Ingest-Secret',
                'media_ingest_auth_token' => 'secret-token',
                'media_ingest_connect_timeout_seconds' => 3,
                'media_ingest_timeout_seconds' => 10,
                'media_ingest_verify_tls' => true,
                'media_ingest_ca_bundle' => 'C:/wamp64/certs/pbb.ph/pbb.ph.fullchain.crt',
                'media_ingest_binary_enabled' => true,
                'media_ingest_max_binary_chunk_bytes' => 1048576,
            ])
            ->assertOk();

        $projectCode = (string) $response->json('data.project.project_code');
        $project = RealtimeProject::where('project_code', $projectCode)->firstOrFail();

        $this->assertTrue((bool) data_get($project->media_ingest_settings, 'enabled'));
        $this->assertSame('https://hotline.pbb.ph', data_get($project->media_ingest_settings, 'base_url'));
        $this->assertSame('/api/internal/media/chunks', data_get($project->media_ingest_settings, 'path'));
        $this->assertSame('X-Realtime-Media-Ingest-Secret', data_get($project->media_ingest_settings, 'auth_header'));
        $this->assertSame('secret-token', data_get($project->media_ingest_settings, 'auth_token'));
        $this->assertSame('C:/wamp64/certs/pbb.ph/pbb.ph.fullchain.crt', data_get($project->media_ingest_settings, 'ca_bundle'));
        $this->assertTrue((bool) data_get($project->media_ingest_settings, 'binary_enabled'));
        $this->assertSame(1048576, data_get($project->media_ingest_settings, 'max_binary_chunk_bytes'));
    }

    public function test_operator_can_store_project_scoped_product_query_forwarding_settings(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $client = RealtimeClient::create([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $response = $this->actingAs($operator)
            ->postJson(route('admin.api.projects.store'), [
                'client_id' => $client->id,
                'name' => 'Hotline Citizen',
                'status' => 'active',
                'description' => 'Citizen query scope.',
                'scope_notes' => 'Allows product query forwarding.',
                'allowed_origins_text' => "https://hotline.pbb.ph",
                'origin_policy_mode' => 'allowlist',
                'policy_profile_code' => null,
                'capability_profile_code' => null,
                'room_policy_profile_code' => null,
                'product_query_forwarding_enabled' => true,
                'product_query_forwarding_base_url' => 'https://hotline.pbb.ph',
                'product_query_forwarding_path' => '/api/internal/realtime/product-query',
                'product_query_forwarding_auth_header' => 'X-Realtime-Backend-Secret',
                'product_query_forwarding_auth_token' => 'query-secret',
                'product_query_forwarding_allowed_queries_text' => "hotline.incident.snapshot\nhotline.incident.call_state",
                'product_query_forwarding_max_payload_bytes' => 4096,
                'product_query_forwarding_rate_limit_per_minute' => 12,
                'product_query_forwarding_connect_timeout_seconds' => 3,
                'product_query_forwarding_timeout_seconds' => 8,
                'product_query_forwarding_verify_tls' => true,
            ])
            ->assertOk();

        $projectCode = (string) $response->json('data.project.project_code');
        $project = RealtimeProject::where('project_code', $projectCode)->firstOrFail();

        $this->assertTrue((bool) data_get($project->product_query_forwarding_settings, 'enabled'));
        $this->assertSame('https://hotline.pbb.ph', data_get($project->product_query_forwarding_settings, 'base_url'));
        $this->assertSame('/api/internal/realtime/product-query', data_get($project->product_query_forwarding_settings, 'path'));
        $this->assertSame('X-Realtime-Backend-Secret', data_get($project->product_query_forwarding_settings, 'auth_header'));
        $this->assertSame('query-secret', data_get($project->product_query_forwarding_settings, 'auth_token'));
        $this->assertSame(['product.query.request'], data_get($project->product_query_forwarding_settings, 'allowed_event_types'));
        $this->assertSame(['hotline.incident.snapshot', 'hotline.incident.call_state'], data_get($project->product_query_forwarding_settings, 'allowed_queries'));
        $this->assertSame(4096, data_get($project->product_query_forwarding_settings, 'max_payload_bytes'));
        $this->assertSame(12, data_get($project->product_query_forwarding_settings, 'rate_limit_per_minute'));
    }

    public function test_project_code_routes_bind_by_project_code(): void
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
            'name' => 'PBB HQ Core',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
        ]);

        $this->actingAs($operator)
            ->getJson(route('admin.api.projects.show', $project))
            ->assertOk()
            ->assertJsonPath('data.project.name', 'PBB HQ Core');
    }

    public function test_project_policy_must_belong_to_same_client(): void
    {
        $operator = User::factory()->admin()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $clientA = RealtimeClient::create([
            'name' => 'PBB HQ',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $clientB = RealtimeClient::create([
            'name' => 'PBB Hotline',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
        ]);

        $policy = RealtimePolicy::create([
            'client_id' => $clientB->id,
            'name' => 'Hotline Policy',
            'status' => 'active',
            'allow_deny_mode' => 'allowlist',
        ]);

        $this->actingAs($operator)
            ->postJson(route('admin.api.projects.store'), [
                'client_id' => $clientA->id,
                'name' => 'PBB HQ Core',
                'status' => 'active',
                'description' => 'Core HQ browser scope.',
                'scope_notes' => 'Seeded project scope for testing.',
                'allowed_origins_text' => "https://hq.pbb.ph\nhttps://localhost",
                'origin_policy_mode' => 'allowlist',
                'policy_profile_code' => $policy->policy_code,
                'capability_profile_code' => null,
                'room_policy_profile_code' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['policy_profile_code']);
    }

    public function test_regular_user_cannot_create_project_scope_for_unassigned_client(): void
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
            ->postJson(route('admin.api.projects.store'), [
                'client_id' => $restrictedClient->id,
                'name' => 'Restricted Project',
                'status' => 'active',
                'description' => 'Restricted scope.',
                'scope_notes' => 'Should fail.',
                'allowed_origins_text' => "https://restricted.pbb.ph",
                'origin_policy_mode' => 'allowlist',
                'policy_profile_code' => null,
                'capability_profile_code' => null,
                'room_policy_profile_code' => null,
            ])
            ->assertForbidden();
    }

    public function test_regular_user_cannot_update_project_scope_for_unassigned_client(): void
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

        $project = RealtimeProject::create([
            'client_id' => $restrictedClient->id,
            'name' => 'Restricted Project',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
        ]);

        $user->realtimeClients()->sync([$assignedClient->id]);

        $this->actingAs($user)
            ->patchJson(route('admin.api.projects.update', $project), [
                'client_id' => $restrictedClient->id,
                'name' => 'Restricted Project Updated',
                'status' => 'active',
                'description' => 'Restricted scope.',
                'scope_notes' => 'Should fail.',
                'allowed_origins_text' => "https://restricted.pbb.ph",
                'origin_policy_mode' => 'allowlist',
                'policy_profile_code' => null,
                'capability_profile_code' => null,
                'room_policy_profile_code' => null,
            ])
            ->assertForbidden();
    }
}
