<?php

namespace Tests\Unit;

use App\Realtime\Auth\RealtimeTokenClaims;
use App\Realtime\Rooms\RealtimeRoomPolicy;
use DateTimeImmutable;
use Tests\TestCase;

class RealtimeRoomPolicyTest extends TestCase
{
    public function test_it_allows_exact_room_matches(): void
    {
        $policy = new RealtimeRoomPolicy();
        $claims = $this->claims([
            'allowedRooms' => ['chat.thread.thread_123'],
            'allowedRoomPrefixes' => [],
        ]);

        $this->assertTrue($policy->allows($claims, 'chat.thread.thread_123'));
    }

    public function test_it_allows_prefix_matches(): void
    {
        $policy = new RealtimeRoomPolicy();
        $claims = $this->claims([
            'allowedRooms' => [],
            'allowedRoomPrefixes' => ['chat.thread.'],
        ]);

        $this->assertTrue($policy->allows($claims, 'chat.thread.thread_123'));
    }

    public function test_it_rejects_unauthorized_rooms(): void
    {
        $policy = new RealtimeRoomPolicy();
        $claims = $this->claims([
            'allowedRooms' => ['chat.thread.thread_123'],
            'allowedRoomPrefixes' => ['chat.thread.'],
        ]);

        $this->assertFalse($policy->allows($claims, 'call.room.room_999'));
    }

    public function test_it_does_not_implicitly_allow_reserved_chat_or_call_rooms_without_explicit_grants(): void
    {
        $policy = new RealtimeRoomPolicy();
        $claims = $this->claims([
            'allowedRooms' => [],
            'allowedRoomPrefixes' => [],
        ]);

        $this->assertFalse($policy->allows($claims, 'chat.thread.thread_123'));
        $this->assertFalse($policy->allows($claims, 'call.session.session_456'));
        $this->assertFalse($policy->allows($claims, 'stream.session.session_789'));
    }

    /**
     * @param array{allowedRooms: array<int, string>, allowedRoomPrefixes: array<int, string>} $overrides
     */
    private function claims(array $overrides): RealtimeTokenClaims
    {
        return new RealtimeTokenClaims(
            issuer: 'local.pbb.test',
            subject: 'user_1024',
            audience: 'pbb-realtime',
            expiresAt: new DateTimeImmutable('+5 minutes'),
            issuedAt: new DateTimeImmutable(),
            tokenId: 'rt_001',
            projectCode: 'hq',
            appCode: 'pbb-hq',
            userId: '1024',
            email: 'operator@pbb.ph',
            displayName: 'Operator One',
            roles: ['administrator'],
            capabilities: ['session.connect', 'room.join'],
            tenantId: 'tenant_001',
            orgId: 'org_001',
            workspaceId: 'workspace_001',
            allowedRooms: $overrides['allowedRooms'],
            allowedRoomPrefixes: $overrides['allowedRoomPrefixes'],
            origin: 'https://pbb.example',
            attachmentPolicy: [],
        );
    }
}
