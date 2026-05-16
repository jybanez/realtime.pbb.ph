<?php

namespace Tests\Feature\Admin;

use App\Models\RealtimeClient;
use App\Models\RealtimeProject;
use App\Models\RealtimeUsageBucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_telemetry_payload_includes_usage_aggregates(): void
    {
        $operator = User::factory()->admin()->create();

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

        RealtimeUsageBucket::query()->create([
            'bucket_start' => now()->startOfHour(),
            'bucket_granularity' => 'hour',
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'event_type' => 'chat.publish',
            'event_count' => 12,
            'bytes_in' => 4096,
            'bytes_out' => 8192,
            'error_count' => 1,
            'rate_limited_count' => 2,
        ]);

        $this->actingAs($operator)
            ->getJson(route('admin.api.telemetry'))
            ->assertOk()
            ->assertJsonPath('data.window_hours', 24)
            ->assertJsonPath('data.summary.event_count', 12)
            ->assertJsonPath('data.summary.bytes_in', 4096)
            ->assertJsonPath('data.top_clients.0.client_code', $client->client_code)
            ->assertJsonPath('data.top_clients.0.client_name', 'PBB Hotline')
            ->assertJsonPath('data.top_projects.0.project_code', $project->project_code)
            ->assertJsonPath('data.top_projects.0.project_name', 'Hotline Caller')
            ->assertJsonPath('data.event_mix.0.event_type', 'chat.publish');
    }

    public function test_operations_payload_stays_narrow(): void
    {
        $operator = User::factory()->admin()->create();

        $this->actingAs($operator)
            ->getJson(route('admin.api.operations'))
            ->assertOk()
            ->assertJsonMissingPath('data.usage');
    }
}
