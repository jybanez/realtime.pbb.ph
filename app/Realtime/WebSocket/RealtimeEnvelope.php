<?php

namespace App\Realtime\WebSocket;

use InvalidArgumentException;
use JsonException;

class RealtimeEnvelope
{
    public function __construct(
        public readonly string $namespace,
        public readonly string $phase,
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $room,
        public readonly array $payload,
        public readonly array $meta,
    ) {
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function fromArray(array $message): self
    {
        foreach (['namespace', 'phase', 'id', 'type'] as $field) {
            if (!isset($message[$field]) || !is_string($message[$field]) || trim($message[$field]) === '') {
                throw new InvalidArgumentException("Missing or invalid {$field} field.");
            }
        }

        if ($message['namespace'] !== 'pbb.realtime.v1') {
            throw new InvalidArgumentException('Invalid realtime namespace.');
        }

        if (!in_array($message['phase'], ['request', 'ack', 'event', 'error', 'system'], true)) {
            throw new InvalidArgumentException('Invalid realtime phase.');
        }

        $payload = $message['payload'] ?? [];
        $meta = $message['meta'] ?? [];

        if (!is_array($payload) || !is_array($meta)) {
            throw new InvalidArgumentException('Payload and meta must be objects.');
        }

        $room = null;
        if (array_key_exists('room', $message)) {
            if ($message['room'] !== null && !is_string($message['room'])) {
                throw new InvalidArgumentException('Room must be a string or null.');
            }

            $room = $message['room'];
        }

        return new self(
            namespace: $message['namespace'],
            phase: $message['phase'],
            id: $message['id'],
            type: $message['type'],
            room: $room,
            payload: $payload,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $message = [
            'namespace' => $this->namespace,
            'phase' => $this->phase,
            'id' => $this->id,
            'type' => $this->type,
            'payload' => $this->payload,
            'meta' => $this->meta,
        ];

        if ($this->room !== null) {
            $message['room'] = $this->room;
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function encode(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $json
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON payload.');
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Realtime message must decode to an object.');
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }
}
