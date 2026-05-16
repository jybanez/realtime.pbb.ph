<?php

namespace App\Realtime\ProductQuery;

class RealtimeProductQueryForwardResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly ?int $downstreamStatus = null,
    ) {
    }

    public static function accepted(?int $downstreamStatus = null): self
    {
        return new self(true, downstreamStatus: $downstreamStatus);
    }

    public static function rejected(string $code, string $message, ?int $downstreamStatus = null): self
    {
        return new self(false, $code, $message, $downstreamStatus);
    }
}
