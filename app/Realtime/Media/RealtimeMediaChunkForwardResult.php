<?php

namespace App\Realtime\Media;

readonly class RealtimeMediaChunkForwardResult
{
    public function __construct(
        public bool $accepted,
        public string $code,
        public string $message,
        public ?int $status = null,
    ) {
    }

    public static function accepted(?int $status = null): self
    {
        return new self(
            accepted: true,
            code: 'media.chunk.accepted',
            message: 'Media chunk accepted for downstream ingest.',
            status: $status,
        );
    }

    public static function rejected(string $code, string $message, ?int $status = null): self
    {
        return new self(
            accepted: false,
            code: $code,
            message: $message,
            status: $status,
        );
    }
}
