<?php

namespace Tests\Unit;

use App\Realtime\WebSocket\RealtimeEnvelope;
use InvalidArgumentException;
use Tests\TestCase;

class RealtimeEnvelopeTest extends TestCase
{
    public function test_it_parses_a_valid_envelope(): void
    {
        $envelope = RealtimeEnvelope::fromArray([
            'namespace' => 'pbb.realtime.v1',
            'phase' => 'request',
            'id' => 'msg_001',
            'type' => 'room.join.request',
            'room' => 'chat.thread.thread_123',
            'payload' => [],
            'meta' => [],
        ]);

        $this->assertSame('pbb.realtime.v1', $envelope->namespace);
        $this->assertSame('request', $envelope->phase);
        $this->assertSame('msg_001', $envelope->id);
        $this->assertSame('room.join.request', $envelope->type);
    }

    public function test_it_rejects_an_invalid_namespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RealtimeEnvelope::fromArray([
            'namespace' => 'bad.namespace',
            'phase' => 'request',
            'id' => 'msg_001',
            'type' => 'room.join.request',
            'payload' => [],
            'meta' => [],
        ]);
    }
}
