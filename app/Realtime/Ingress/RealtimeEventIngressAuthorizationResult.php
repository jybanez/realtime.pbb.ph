<?php

namespace App\Realtime\Ingress;

use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;

class RealtimeEventIngressAuthorizationResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $reason,
        public readonly ?string $message,
        public readonly ?RealtimeClient $client,
        public readonly ?RealtimeProject $project,
        public readonly ?RealtimePolicy $policy,
    ) {
    }

    public static function accept(
        RealtimeClient $client,
        RealtimeProject $project,
        RealtimePolicy $policy
    ): self {
        return new self(true, null, null, $client, $project, $policy);
    }

    public static function reject(string $reason, string $message): self
    {
        return new self(false, $reason, $message, null, null, null);
    }
}
