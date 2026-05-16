<?php

namespace Tests\Unit;

use App\Realtime\Auth\RealtimeTokenClaims;
use App\Realtime\Observability\RealtimeUsageTelemetry;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeUsageTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_and_summarizes_hourly_usage_buckets(): void
    {
        $telemetry = app(RealtimeUsageTelemetry::class);
        $claims = new RealtimeTokenClaims(
            issuer: 'local.pbb.test',
            subject: 'user_telemetry_001',
            audience: 'pbb-realtime',
            tokenId: 'tok_telemetry_001',
            expiresAt: new DateTimeImmutable('+5 minutes'),
            issuedAt: null,
            projectCode: 'prj_telemetry_001',
            appCode: 'clt_telemetry_001',
            userId: 'user_telemetry_001',
            email: null,
            displayName: 'Telemetry User',
            roles: [],
            capabilities: ['chat.publish'],
            tenantId: null,
            orgId: null,
            workspaceId: null,
            allowedRooms: [],
            allowedRoomPrefixes: ['chat.thread.'],
            origin: null,
            attachmentPolicy: [],
        );

        $telemetry->record('chat.publish', $claims, bytesIn: 128, bytesOut: 512);
        $telemetry->record('chat.publish', $claims, bytesIn: 64, bytesOut: 256);
        $telemetry->record('chat.publish', $claims, errorCount: 1);

        $summary = $telemetry->summarizeLastHours();
        $topClients = $telemetry->topClientsLastHours();
        $topProjects = $telemetry->topProjectsLastHours();
        $eventMix = $telemetry->eventMixLastHours();

        $this->assertSame(3, $summary['event_count']);
        $this->assertSame(192, $summary['bytes_in']);
        $this->assertSame(768, $summary['bytes_out']);
        $this->assertSame(1, $summary['error_count']);

        $this->assertSame('clt_telemetry_001', $topClients[0]['client_code']);
        $this->assertSame(3, $topClients[0]['event_count']);
        $this->assertSame('prj_telemetry_001', $topProjects[0]['project_code']);
        $this->assertSame('chat.publish', $eventMix[0]['event_type']);
        $this->assertSame(3, $eventMix[0]['event_count']);
    }
}
