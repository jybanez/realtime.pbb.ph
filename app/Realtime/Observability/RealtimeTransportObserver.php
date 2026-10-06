<?php

namespace App\Realtime\Observability;

use Ratchet\ConnectionInterface;

/** Bounded, opt-in observation. No sink callbacks, disk writes or blocking connects. */
class RealtimeTransportObserver
{
    private ?\Socket $socket = null;
    private int $started;
    private int $records = 0;
    private int $bytes = 0;
    private int $active = 0;
    private int $sequence = 0;
    private int $dropped = 0;

    public function __construct(private readonly int $port = 9997)
    {
        $this->started = hrtime(true);
        if (extension_loaded('sockets') && $port > 0 && $port <= 65535) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket !== false && @socket_set_nonblock($socket)) {
                $this->socket = $socket;
            } elseif ($socket !== false) {
                socket_close($socket);
            }
        }
    }

    public function accept(ConnectionInterface $conn): void
    {
        if ($this->active >= 256 || hrtime(true) - $this->started >= 300000000000) { return; }
        ++$this->active;
        $conn->realtimeTransportTrace = (object) [
            'id' => getmypid().'-'.(++$this->sequence),
            'accept_ns' => hrtime(true), 'accept_utc' => self::utc(),
            'first_ns' => null, 'first_utc' => null, 'attempt' => null,
        ];
    }

    public function firstData(ConnectionInterface $conn): void
    {
        $state = $conn->realtimeTransportTrace ?? null;
        if ($state && $state->first_ns === null) {
            $state->first_ns = hrtime(true);
            $state->first_utc = self::utc();
        }
    }

    public function upgraded(ConnectionInterface $conn): void
    {
        $state = $conn->realtimeTransportTrace ?? null;
        if (!$state) { return; }
        $now = hrtime(true);
        $query = ($conn->httpRequest ?? null)?->getUri()->getQuery() ?? '';
        // Extract only the dedicated field, never copy tokens/headers into records.
        $state->attempt = self::attemptId($query);
        $this->emit([
            'stage' => 'transport.upgraded', 'connection_id' => $state->id, 'attempt_id' => $state->attempt,
            'loop_accept_utc' => $state->accept_utc, 'first_data_utc' => $state->first_utc,
            'upgraded_utc' => self::utc(),
            'loop_accept_to_upgrade_ms' => round(($now - $state->accept_ns) / 1e6, 3),
            'first_data_to_upgrade_ms' => $state->first_ns === null ? null : round(($now - $state->first_ns) / 1e6, 3),
        ]);
    }

    public function close(ConnectionInterface $conn): void
    {
        $state = $conn->realtimeTransportTrace ?? null;
        if (!$state) { return; }
        unset($conn->realtimeTransportTrace);
        --$this->active;
        $this->emit(['stage' => 'transport.closed', 'connection_id' => $state->id,
            'attempt_id' => $state->attempt, 'closed_utc' => self::utc()]);
    }

    private function emit(array $record): void
    {
        if (!$this->socket || $this->records >= 4096 || hrtime(true) - $this->started >= 300000000000) { ++$this->dropped; return; }
        $packet = json_encode($record + ['pid' => getmypid()], JSON_UNESCAPED_SLASHES);
        if ($packet === false || strlen($packet) > 1024 || $this->bytes + strlen($packet) > 1048576) { ++$this->dropped; return; }
        ++$this->records;
        $this->bytes += strlen($packet);
        if (@socket_sendto($this->socket, $packet, strlen($packet), 0, '127.0.0.1', $this->port) === false) { ++$this->dropped; }
    }

    public function stats(): array { return ['active' => $this->active, 'records' => $this->records, 'bytes' => $this->bytes, 'dropped' => $this->dropped]; }
    public static function attemptId(string $query): ?string
    {
        if (preg_match_all('/(?:^|&)diag_attempt=([^&]*)/', $query, $matches) !== 1) { return null; }
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $matches[1][0]) ? $matches[1][0] : null;
    }
    private static function utc(): string { return gmdate('Y-m-d\TH:i:s').sprintf('.%03dZ', ((int) (microtime(true) * 1000)) % 1000); }
    public function __destruct() { if ($this->socket) { socket_close($this->socket); } }
}
