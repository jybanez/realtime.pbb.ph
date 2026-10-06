<?php

namespace App\Realtime\Observability;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Throwable;

/** Forward product callbacks exactly once; observer failures never replace them. */
class RealtimeTransportComponent implements MessageComponentInterface
{
    public function __construct(private readonly MessageComponentInterface $delegate,
        private readonly RealtimeTransportObserver $observer, private readonly bool $afterUpgrade = false) {}

    private function observe(callable $callback): void { try { $callback(); } catch (Throwable) {} }
    public function onOpen(ConnectionInterface $conn)
    {
        $this->observe(fn () => $this->afterUpgrade ? $this->observer->upgraded($conn) : $this->observer->accept($conn));
        try { return $this->delegate->onOpen($conn); }
        catch (Throwable $e) { $this->observe(fn () => $this->observer->close($conn)); throw $e; }
    }
    public function onMessage(ConnectionInterface $from, $msg)
    {
        if (!$this->afterUpgrade) { $this->observe(fn () => $this->observer->firstData($from)); }
        try { return $this->delegate->onMessage($from, $msg); }
        catch (Throwable $e) { $this->observe(fn () => $this->observer->close($from)); throw $e; }
    }
    public function onClose(ConnectionInterface $conn)
    {
        try { return $this->delegate->onClose($conn); }
        finally { $this->observe(fn () => $this->observer->close($conn)); }
    }
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        try { return $this->delegate->onError($conn, $e); }
        finally { $this->observe(fn () => $this->observer->close($conn)); }
    }
}
