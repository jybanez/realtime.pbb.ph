<?php

namespace App\Realtime\Observability;

/** Lossy diagnostics only: no disk, retry, queue, DNS or receiver acknowledgment. */
class RealtimeDiagnosticEmitter
{
    private mixed $socket = null;
    private int $started;
    private int $attempted = 0;
    private int $bytes = 0;
    private int $sent = 0;
    private array $drops = ['expired' => 0, 'cap' => 0, 'oversize' => 0, 'unavailable' => 0, 'send' => 0, 'encoding' => 0];

    public function __construct(private readonly bool $enabled = false, private readonly int $port = 9998)
    {
        $this->started = hrtime(true);
        if ($enabled && $port > 0 && $port <= 65535 && function_exists('socket_create')) {
            try {
                $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                if ($socket !== false && @socket_set_nonblock($socket)) {
                    $this->socket = $socket;
                } elseif ($socket !== false) {
                    @socket_close($socket);
                }
            } catch (\Throwable) {
                // Diagnostics must never prevent gateway startup.
            }
        }
    }

    public function emit(string $level, string $message, array $context): void
    {
        if (!$this->enabled) { return; }
        try {
            if (hrtime(true) - $this->started >= 300000000000) { ++$this->drops['expired']; return; }
            if ($this->attempted >= 4096 || $this->bytes >= 4194304) { ++$this->drops['cap']; return; }
            ++$this->attempted;
            // Fixed schema, bounded strings and fixed operation maps, never arbitrary nested payloads.
            $safe = [];
            foreach (['pid', 'connection_id', 'session_id', 'stage', 'received_at', 'observed_at', 'elapsed_ms', 'request_id', 'request_type', 'room', 'event_type', 'signal_type', 'correlation_id', 'span_id', 'parent_span_id', 'measured_child_ms', 'unaccounted_ms', 'sql_count', 'sql_ms', 'sql_max_ms', 'sql_scope', 'fanout_count'] as $key) {
                $value = $context[$key] ?? null;
                $safe[$key] = is_string($value) ? mb_strcut($value, 0, 160, 'UTF-8') : (is_int($value) || is_float($value) || $value === null ? $value : null);
            }
            foreach (['sql_operations', 'sql_operation_ms'] as $key) {
                $safe[$key] = [];
                foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'OTHER'] as $operation) {
                    $value = $context[$key][$operation] ?? null;
                    if (is_int($value) || is_float($value)) { $safe[$key][$operation] = $value; }
                }
            }
            $record = json_encode(['sequence' => $this->attempted, 'level' => $level === 'warning' ? 'warning' : 'info', 'message' => mb_strcut($message, 0, 160, 'UTF-8'), 'context' => $safe], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $length = strlen($record);
            if ($length > 2048) { ++$this->drops['oversize']; return; }
            if ($this->bytes + $length > 4194304) { ++$this->drops['cap']; return; }
            $this->bytes += $length;
            if ($this->socket === null) { ++$this->drops['unavailable']; return; }
            if (@socket_sendto($this->socket, $record, $length, 0, '127.0.0.1', $this->port) === $length) { ++$this->sent; }
            else { ++$this->drops['send']; }
        } catch (\Throwable) { ++$this->drops['encoding']; }
    }

    public function stats(): array
    {
        return ['attempted' => $this->attempted, 'bytes' => $this->bytes, 'sent' => $this->sent, 'drops' => $this->drops];
    }

    public function __destruct()
    {
        if ($this->socket !== null) { @socket_close($this->socket); }
    }
}
