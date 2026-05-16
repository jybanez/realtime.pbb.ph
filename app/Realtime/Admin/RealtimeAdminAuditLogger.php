<?php

namespace App\Realtime\Admin;

use App\Models\RealtimeAuditEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

class RealtimeAdminAuditLogger
{
    public function record(
        ?Authenticatable $actor,
        string $actionType,
        string $targetType,
        string $targetCode,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?string $clientCode = null,
        ?string $projectCode = null
    ): RealtimeAuditEvent {
        return RealtimeAuditEvent::create([
            'audit_id' => (string) Str::uuid(),
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'actor_identity' => $actor?->getAuthIdentifier() ? ($actor->name ?? 'operator') : 'system',
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_code' => $targetCode,
            'client_code' => $clientCode,
            'project_code' => $projectCode,
            'before_state' => $before ?: null,
            'after_state' => $after ?: null,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
