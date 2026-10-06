<?php

namespace Tests\Unit;

use App\Realtime\Observability\RealtimeTransportComponent;
use App\Realtime\Observability\RealtimeTransportObserver;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class RealtimeTransportObserverTest extends TestCase
{
    public function test_invalid_or_duplicate_attempt_ids_are_not_retained(): void
    {
        $uuid = '12345678-1234-1234-1234-123456789abc';
        $this->assertSame($uuid, RealtimeTransportObserver::attemptId('token=secret&diag_attempt='.$uuid));
        $this->assertNull(RealtimeTransportObserver::attemptId('diag_attempt=private-sensitive-text'));
        $this->assertNull(RealtimeTransportObserver::attemptId('diag_attempt='.$uuid.'&diag_attempt='.$uuid));
        $this->assertNull(RealtimeTransportObserver::attemptId('token=secret'));
    }
    public function test_actual_upgrade_preserves_uuid_through_decorators_without_secrets(): void
    {
        $receiver = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($receiver, '127.0.0.1', 0);
        socket_getsockname($receiver, $address, $port);
        socket_set_nonblock($receiver);
        $observer = new RealtimeTransportObserver($port);
        $product = $this->product();
        $stack = new RealtimeTransportComponent(new HttpServer(new WsServer(new RealtimeTransportComponent($product, $observer, true))), $observer);
        $conn = $this->connection();
        $stack->onOpen($conn);
        $uuid = '12345678-1234-1234-1234-123456789abc';
        $stack->onMessage($conn, "GET /realtime?token=PRIVATE-TOKEN&diag_attempt=$uuid HTTP/1.1\r\nHost: realtime.pbb.ph\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $packet = '';
        socket_recv($receiver, $packet, 2048, 0);
        $record = json_decode($packet, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($uuid, $record['attempt_id']);
        $this->assertSame($conn->realtimeTransportTrace->id, $product->opened->realtimeTransportTrace->id);
        $this->assertSame(spl_object_hash($product->opened), $record['gateway_connection_id']);
        $this->assertSame('transport.upgraded', $record['stage']);
        $this->assertStringNotContainsString('PRIVATE-TOKEN', $packet);
        $this->assertStringContainsString('101 Switching Protocols', $conn->sent[0]);
        $stack->onClose($conn);
        $this->assertSame(0, $observer->stats()['active']);
        $this->assertFalse(isset($conn->realtimeTransportTrace));
        socket_close($receiver);
    }

    public function test_exception_cleanup_and_forwarding_are_exact(): void
    {
        $observer = new RealtimeTransportObserver(0);
        $product = $this->product();
        $stack = new RealtimeTransportComponent($product, $observer);
        $conn = $this->connection();
        $stack->onOpen($conn);
        $product->fail = true;
        try { $stack->onMessage($conn, 'same message'); $this->fail('Expected product exception'); }
        catch (\RuntimeException $e) { $this->assertSame('product failure', $e->getMessage()); }
        $this->assertSame(['same message'], $product->messages);
        $this->assertSame(0, $observer->stats()['active']);
        $stack->onClose($conn);
        $this->assertSame(1, $product->closed);
        $this->assertSame(0, $observer->stats()['active']);
    }

    public function test_stalled_udp_receiver_does_not_wait_or_accumulate_records(): void
    {
        $receiver = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($receiver, '127.0.0.1', 0);
        socket_getsockname($receiver, $address, $port);
        socket_set_option($receiver, SOL_SOCKET, SO_RCVBUF, 1024);
        $observer = new RealtimeTransportObserver($port);
        $start = hrtime(true);
        for ($i = 0; $i < 6000; ++$i) {
            $conn = $this->connection();
            $observer->accept($conn);
            $observer->close($conn); // Receiver deliberately never reads.
        }
        // Generous watchdog for 6,000 synthetic connections on a shared Windows host.
        // Protection is socket_set_nonblock, not a per-connection latency promise.
        $this->assertLessThan(15e9, hrtime(true) - $start);
        $this->assertLessThanOrEqual(4096, $observer->stats()['records']);
        $this->assertLessThanOrEqual(1048576, $observer->stats()['bytes']);
        $this->assertGreaterThan(0, $observer->stats()['dropped']);
        $this->assertSame(0, $observer->stats()['active']);
        socket_close($receiver);
    }

    public function test_connection_cap_drops_observer_only(): void
    {
        $observer = new RealtimeTransportObserver(0);
        $product = $this->product();
        $stack = new RealtimeTransportComponent($product, $observer);
        $connections = [];
        for ($i = 0; $i < 300; ++$i) { $connections[] = $conn = $this->connection(); $stack->onOpen($conn); }
        $this->assertSame(300, $product->opens);
        $this->assertSame(256, $observer->stats()['active']);
        foreach ($connections as $conn) { $stack->onClose($conn); }
        $this->assertSame(0, $observer->stats()['active']);
    }

    private function connection(): ConnectionInterface
    {
        return new class extends \stdClass implements ConnectionInterface {
            public array $sent = [];
            public function send($data) { $this->sent[] = (string) $data; return $this; }
            public function close() {}
        };
    }
    private function product(): MessageComponentInterface
    {
        return new class implements MessageComponentInterface {
            public $opened; public int $opens = 0; public int $closed = 0; public bool $fail = false; public array $messages = [];
            public function onOpen(ConnectionInterface $conn) { $this->opened = $conn; ++$this->opens; }
            public function onMessage(ConnectionInterface $conn, $msg) { $this->messages[] = $msg; if ($this->fail) { throw new \RuntimeException('product failure'); } }
            public function onClose(ConnectionInterface $conn) { ++$this->closed; }
            public function onError(ConnectionInterface $conn, \Exception $e) { throw $e; }
        };
    }
}
