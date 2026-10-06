<?php

namespace Tests\Unit;

use App\Realtime\Observability\RealtimeDiagnosticEmitter;
use PHPUnit\Framework\TestCase;

class RealtimeDiagnosticEmitterTest extends TestCase
{
    public function test_disabled_emitter_has_no_work_or_socket(): void
    {
        $emitter = new RealtimeDiagnosticEmitter();
        $emitter->emit('info', 'ignored', ['payload' => 'secret']);
        $this->assertSame(0, $emitter->stats()['attempted']);
        $this->assertNull((new \ReflectionProperty($emitter, 'socket'))->getValue($emitter));
    }

    public function test_unavailable_sink_caps_attempts_without_fallback(): void
    {
        $emitter = new RealtimeDiagnosticEmitter(true, 0);
        for ($i = 0; $i < 4100; ++$i) { $emitter->emit('info', 'test', []); }
        $stats = $emitter->stats();
        $this->assertSame(4096, $stats['attempted']);
        $this->assertSame(4096, $stats['drops']['unavailable']);
        $this->assertSame(4, $stats['drops']['cap']);
        $this->assertLessThanOrEqual(4194304, $stats['bytes']);
    }

    public function test_expiry_and_encoding_failure_are_isolated(): void
    {
        $emitter = new RealtimeDiagnosticEmitter(true, 0);
        $emitter->emit('info', 'test', ['elapsed_ms' => INF]);
        $this->assertSame(1, $emitter->stats()['drops']['encoding']);
        (new \ReflectionProperty($emitter, 'started'))->setValue($emitter, hrtime(true) - 300000000001);
        $emitter->emit('info', 'test', []);
        $this->assertSame(1, $emitter->stats()['drops']['expired']);
    }

    public function test_byte_budget_and_oversize_drop_whole_records(): void
    {
        $emitter = new RealtimeDiagnosticEmitter(true, 0);
        (new \ReflectionProperty($emitter, 'bytes'))->setValue($emitter, 4194300);
        $emitter->emit('info', 'test', []);
        $this->assertSame(1, $emitter->stats()['drops']['cap']);
        $this->assertSame(4194300, $emitter->stats()['bytes']);
        $emitter = new RealtimeDiagnosticEmitter(true, 0);
        $context = array_fill_keys(['connection_id', 'session_id', 'stage', 'received_at', 'observed_at', 'request_id', 'request_type', 'room', 'event_type', 'signal_type', 'correlation_id'], str_repeat('x', 160));
        $emitter->emit('info', str_repeat('x', 160), $context);
        $this->assertSame(1, $emitter->stats()['drops']['oversize']);
        $this->assertSame(0, $emitter->stats()['bytes']);
    }

    public function test_stalled_receiver_and_private_fields_do_not_enter_output(): void
    {
        $receiver = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($receiver, '127.0.0.1', 0);
        socket_getsockname($receiver, $host, $port);
        socket_set_nonblock($receiver);
        socket_set_option($receiver, SOL_SOCKET, SO_RCVBUF, 1024);
        $emitter = new RealtimeDiagnosticEmitter(true, $port);
        try {
            $emitter->emit('info', 'test', ['request_id' => 'request-one', 'payload' => 'PRIVATE', 'sql_operations' => ['SELECT' => 1, 'PRIVATE' => 42]]);
            $data = ''; $from = ''; $fromPort = 0;
            $this->assertGreaterThan(0, socket_recvfrom($receiver, $data, 2048, 0, $from, $fromPort));
            $this->assertStringContainsString('request-one', $data);
            $this->assertStringNotContainsString('PRIVATE', $data);
            for ($i = 0; $i < 32; ++$i) { $emitter->emit('info', 'stalled', []); }
            $this->assertSame(33, $emitter->stats()['attempted']);
        } finally { socket_close($receiver); }
    }
}
