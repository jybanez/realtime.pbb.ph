<?php

namespace App\Realtime\Media;

use Illuminate\Support\Carbon;

class RealtimeMediaChunkSpoolEntry
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public string $chunk_id,
        public string $client_code,
        public string $project_code,
        public string $room,
        public ?string $session_id,
        public ?string $user_id,
        public ?string $display_name,
        public string $status,
        public int $attempts,
        public array $payload,
        public ?array $meta,
        public ?Carbon $queued_at,
        public ?Carbon $forwarded_at,
        public ?Carbon $failed_at,
        public ?string $failure_reason,
        public ?int $downstream_status,
        public string $spool_path,
        public ?string $binary_path = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $spoolPath): self
    {
        return new self(
            chunk_id: (string) ($data['chunk_id'] ?? ''),
            client_code: (string) ($data['client_code'] ?? ''),
            project_code: (string) ($data['project_code'] ?? ''),
            room: (string) ($data['room'] ?? ''),
            session_id: isset($data['session_id']) ? (string) $data['session_id'] : null,
            user_id: isset($data['user_id']) ? (string) $data['user_id'] : null,
            display_name: isset($data['display_name']) ? (string) $data['display_name'] : null,
            status: (string) ($data['status'] ?? 'pending'),
            attempts: max(0, (int) ($data['attempts'] ?? 0)),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : null,
            queued_at: self::parseDate($data['queued_at'] ?? null),
            forwarded_at: self::parseDate($data['forwarded_at'] ?? null),
            failed_at: self::parseDate($data['failed_at'] ?? null),
            failure_reason: isset($data['failure_reason']) ? (string) $data['failure_reason'] : null,
            downstream_status: isset($data['downstream_status']) ? (int) $data['downstream_status'] : null,
            spool_path: $spoolPath,
            binary_path: isset($data['binary_path']) && is_string($data['binary_path']) && trim($data['binary_path']) !== ''
                ? (str_contains($data['binary_path'], DIRECTORY_SEPARATOR) ? $data['binary_path'] : dirname($spoolPath) . DIRECTORY_SEPARATOR . $data['binary_path'])
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunk_id,
            'client_code' => $this->client_code,
            'project_code' => $this->project_code,
            'room' => $this->room,
            'session_id' => $this->session_id,
            'user_id' => $this->user_id,
            'display_name' => $this->display_name,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'payload' => $this->payload,
            'meta' => $this->meta,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'forwarded_at' => $this->forwarded_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'downstream_status' => $this->downstream_status,
            'binary_path' => $this->binary_path !== null ? basename($this->binary_path) : null,
        ];
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
