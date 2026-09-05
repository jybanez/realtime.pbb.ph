<?php

namespace Tests\Feature;

use App\Models\RealtimeAuditEvent;
use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;
use App\Models\RealtimeServerEvent;
use App\Models\RealtimeUsageBucket;
use App\Realtime\Ingress\BackendIngressSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EventPublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_backend_publish_is_accepted_for_authorized_client_project_and_room(): void
    {
        [$client, $project] = $this->makeAuthorizedPublishScope();

        $response = $this->postJson(route('api.events.publish'), [
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'room' => 'hotline.settings.global',
            'event_type' => 'hotline.alert_level.changed',
            'payload' => [
                'alert_level' => 'high',
            ],
            'meta' => [
                'source_module' => 'hotline-beta',
            ],
            'event_id' => 'evt_hotline_settings_001',
        ], [
            'X-Realtime-Backend-Secret' => 'hotline-backend-secret',
        ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('data.client_code', $client->client_code)
            ->assertJsonPath('data.project_code', $project->project_code)
            ->assertJsonPath('data.room', 'hotline.settings.global')
            ->assertJsonPath('data.event_type', 'hotline.alert_level.changed')
            ->assertJsonPath('data.event_id', 'evt_hotline_settings_001');

        $event = RealtimeServerEvent::query()->firstOrFail();
        $this->assertSame('pending', $event->status);
        $this->assertSame($client->client_code, $event->client_code);
        $this->assertSame($project->project_code, $event->project_code);
        $this->assertSame('hotline.settings.global', $event->room);
        $this->assertSame('hotline.alert_level.changed', $event->event_type);
        $this->assertSame('evt_hotline_settings_001', $event->event_id);

        $this->assertDatabaseHas('realtime_audit_events', [
            'action_type' => 'event_publish.accepted',
            'target_type' => 'realtime_server_event',
            'target_code' => $event->publish_id,
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
        ]);

        $this->assertDatabaseHas('realtime_usage_buckets', [
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'event_type' => 'event.publish.accepted',
            'event_count' => 1,
        ]);
    }

    public function test_backend_publish_endpoint_is_not_blocked_by_csrf_middleware(): void
    {
        $response = $this->postJson(route('api.events.publish'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'client_code',
            'project_code',
            'room',
            'event_type',
            'payload',
        ]);
    }

    public function test_backend_publish_is_rejected_for_invalid_secret(): void
    {
        [$client, $project] = $this->makeAuthorizedPublishScope();

        $this->postJson(route('api.events.publish'), [
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'room' => 'hotline.settings.global',
            'event_type' => 'hotline.alert_level.changed',
            'payload' => [
                'alert_level' => 'high',
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'wrong-secret',
        ])
            ->assertStatus(401)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'invalid-backend-secret');

        $this->assertDatabaseCount('realtime_server_events', 0);
        $this->assertDatabaseHas('realtime_audit_events', [
            'action_type' => 'event_publish.rejected',
            'target_type' => 'realtime_server_event',
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'reason' => 'invalid-backend-secret',
        ]);

        $this->assertDatabaseHas('realtime_usage_buckets', [
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'event_type' => 'event.publish.rejected',
            'error_count' => 1,
        ]);
    }

    public function test_backend_publish_is_rejected_when_room_is_not_allowed_by_policy(): void
    {
        [$client, $project] = $this->makeAuthorizedPublishScope();

        $this->postJson(route('api.events.publish'), [
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'room' => 'private.room.outside.scope',
            'event_type' => 'hotline.alert_level.changed',
            'payload' => [
                'alert_level' => 'high',
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'hotline-backend-secret',
        ])
            ->assertStatus(403)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'room-not-allowed');

        $this->assertDatabaseCount('realtime_server_events', 0);
    }

    public function test_backend_publish_is_rejected_for_unknown_project(): void
    {
        [$client] = $this->makeAuthorizedPublishScope();

        $this->postJson(route('api.events.publish'), [
            'client_code' => $client->client_code,
            'project_code' => 'prj_UNKNOWNPROJECT',
            'room' => 'hotline.settings.global',
            'event_type' => 'hotline.alert_level.changed',
            'payload' => [
                'alert_level' => 'high',
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'hotline-backend-secret',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'unknown-project');
    }

    public function test_backend_publish_is_rejected_for_client_project_mismatch(): void
    {
        [$client] = $this->makeAuthorizedPublishScope();
        [, $otherProject] = $this->makeAuthorizedPublishScope('other-backend-secret');

        $this->postJson(route('api.events.publish'), [
            'client_code' => $client->client_code,
            'project_code' => $otherProject->project_code,
            'room' => 'hotline.settings.global',
            'event_type' => 'hotline.alert_level.changed',
            'payload' => [
                'alert_level' => 'high',
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'hotline-backend-secret',
        ])
            ->assertStatus(403)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'client-project-mismatch');
    }

    public function test_backend_publish_is_rejected_when_policy_lacks_event_publish_capability(): void
    {
        [$client, $project, $policy] = $this->makeAuthorizedPublishScope(returnPolicy: true);

        $policy->update([
            'capability_profile' => [
                'events' => ['subscribe'],
            ],
        ]);

        $this->postJson(route('api.events.publish'), [
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'room' => 'hotline.settings.global',
            'event_type' => 'hotline.alert_level.changed',
            'payload' => [
                'alert_level' => 'high',
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'hotline-backend-secret',
        ])
            ->assertStatus(403)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'missing-capability');
    }

    public function test_backend_publish_is_rejected_when_event_type_is_not_allowed_by_policy(): void
    {
        [$client, $project, $policy] = $this->makeAuthorizedPublishScope(returnPolicy: true);

        $policy->update([
            'capability_profile' => [
                'events' => ['publish'],
                'allowed_event_types' => ['hotline.alert_level.changed'],
            ],
        ]);

        $this->postJson(route('api.events.publish'), [
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'room' => 'hotline.settings.global',
            'event_type' => 'hotline.alert_level.deleted',
            'payload' => [
                'alert_level' => 'high',
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'hotline-backend-secret',
        ])
            ->assertStatus(403)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'event-type-not-allowed');

        $this->assertDatabaseCount('realtime_server_events', 0);
    }

    public function test_backend_publish_is_rate_limited_per_client_policy_limit(): void
    {
        [$client, $project, $policy] = $this->makeAuthorizedPublishScope(returnPolicy: true);

        $policy->update([
            'rate_limit_profile' => [
                'event_publish_per_minute' => 1,
            ],
        ]);

        RealtimeServerEvent::query()->create([
            'publish_id' => 'pub_existing_limit_hit',
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'room' => 'hotline.settings.global',
            'event_type' => 'hotline.alert_level.changed',
            'status' => 'pending',
            'attempts' => 0,
            'payload' => ['alert_level' => 'medium'],
            'meta' => [],
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson(route('api.events.publish'), [
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'room' => 'hotline.settings.global',
            'event_type' => 'hotline.alert_level.changed',
            'payload' => [
                'alert_level' => 'high',
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'hotline-backend-secret',
        ])
            ->assertStatus(429)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'rate-limit-exceeded');

        $bucket = RealtimeUsageBucket::query()
            ->where('client_code', $client->client_code)
            ->where('project_code', $project->project_code)
            ->where('event_type', 'event.publish.rate_limited')
            ->first();

        $this->assertNotNull($bucket);
        $this->assertSame(1, $bucket->rate_limited_count);
        $this->assertSame(1, $bucket->error_count);
    }

    public function test_backend_publish_is_rejected_when_payload_exceeds_limit(): void
    {
        [$client, $project] = $this->makeAuthorizedPublishScope();

        config()->set('realtime.event_publish_payload_max_bytes', 128);

        $this->postJson(route('api.events.publish'), [
            'client_code' => $client->client_code,
            'project_code' => $project->project_code,
            'room' => 'hotline.settings.global',
            'event_type' => 'hotline.alert_level.changed',
            'payload' => [
                'message' => str_repeat('A', 512),
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'hotline-backend-secret',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('reason', 'payload-too-large');

        $this->assertDatabaseCount('realtime_server_events', 0);
    }

    /**
     * @return array{0: RealtimeClient, 1: RealtimeProject}|array{0: RealtimeClient, 1: RealtimeProject, 2: RealtimePolicy}
     */
    private function makeAuthorizedPublishScope(string $backendSecret = 'hotline-backend-secret', bool $returnPolicy = false): array
    {
        $client = RealtimeClient::create([
            'name' => 'Hotline Beta',
            'status' => 'active',
            'token_issuance_mode' => 'app_backend_signed',
            'origin_policy_mode' => 'allowlist',
            'backend_ingress_secret_hash' => Hash::make($backendSecret),
            'backend_ingress_secret_digest' => BackendIngressSecret::digest($backendSecret),
        ]);

        $policy = RealtimePolicy::create([
            'client_id' => $client->id,
            'name' => 'Hotline Server Broadcast',
            'status' => 'active',
            'allow_deny_mode' => 'allowlist',
            'capability_profile' => [
                'events' => ['publish'],
            ],
            'room_policy_profile' => [
                'mode' => 'allowlist',
                'prefixes' => ['hotline.settings.'],
            ],
            'rate_limit_profile' => [
                'event_publish_per_minute' => 10,
            ],
        ]);

        $project = RealtimeProject::create([
            'client_id' => $client->id,
            'name' => 'Hotline Beta Project',
            'status' => 'active',
            'origin_policy_mode' => 'allowlist',
            'policy_profile_code' => $policy->policy_code,
        ]);

        if ($returnPolicy) {
            return [$client, $project, $policy];
        }

        return [$client, $project];
    }
}
