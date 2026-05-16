<?php

namespace Tests\Feature;

use Tests\TestCase;

class StatusTest extends TestCase
{
    public function test_health_endpoint_returns_service_metadata(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'service' => 'PBB Realtime',
                'status' => 'ok',
            ]);
    }

    public function test_ready_endpoint_reports_database_connectivity(): void
    {
        $this->getJson('/api/ready')
            ->assertOk()
            ->assertJson([
                'service' => 'PBB Realtime',
                'status' => 'ready',
            ]);
    }

    public function test_metrics_endpoint_returns_counters(): void
    {
        $this->getJson('/api/metrics')
            ->assertOk()
            ->assertJson([
                'service' => 'PBB Realtime',
                'status' => 'ok',
            ])
            ->assertJsonStructure([
                'metrics' => [
                    'auth.success',
                    'auth.failure',
                    'room.join',
                    'room.leave',
                    'presence.publish',
                    'presence.subscribe',
                    'event.publish',
                    'media.chunk.publish',
                    'chat.publish',
                    'call.signal',
                ],
            ]);
    }

    public function test_root_endpoint_reports_service_info(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('PBB Realtime', false)
            ->assertSee('data-page="/"', false)
            ->assertSee('js/app.js', false);
    }
}
