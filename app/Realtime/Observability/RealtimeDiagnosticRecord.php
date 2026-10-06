<?php

namespace App\Realtime\Observability;

/** Validate collector input before retaining bytes; not an authentication boundary. */
class RealtimeDiagnosticRecord
{
    public static function valid(string $data): bool
    {
        if (strlen($data) > 2048) { return false; }
        try { $record = json_decode($data, true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return false; }
        if (!is_array($record) || array_diff(array_keys($record), ['sequence', 'level', 'message', 'context']) !== []
            || !is_int($record['sequence'] ?? null) || $record['sequence'] < 1 || $record['sequence'] > 4096
            || !in_array($record['level'] ?? null, ['info', 'warning'], true)
            || !in_array($record['message'] ?? null, ['Realtime gateway callback timing.', 'Realtime gateway signal fanout completed.', 'Realtime gateway publish fanout completed.', 'Realtime gateway ACK sent to connection.'], true)
            || !is_array($record['context'] ?? null)) { return false; }
        $context = $record['context'];
        $keys = ['pid', 'connection_id', 'session_id', 'stage', 'received_at', 'observed_at', 'elapsed_ms', 'request_id', 'request_type', 'room', 'event_type', 'signal_type', 'correlation_id', 'span_id', 'parent_span_id', 'measured_child_ms', 'unaccounted_ms', 'sql_count', 'sql_ms', 'sql_max_ms', 'sql_scope', 'fanout_count', 'sql_operations', 'sql_operation_ms'];
        if (array_diff(array_keys($context), $keys) !== [] || !is_int($context['pid'] ?? null)) { return false; }
        foreach ($context as $key => $value) {
            if (in_array($key, ['sql_operations', 'sql_operation_ms'], true)) {
                if (!is_array($value) || array_diff(array_keys($value), ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'OTHER']) !== []) { return false; }
                foreach ($value as $number) { if (!is_int($number) && !is_float($number)) { return false; } }
            } elseif (is_string($value)) {
                if (strlen($value) > 160 || !mb_check_encoding($value, 'UTF-8')) { return false; }
            } elseif ($value !== null && !is_int($value) && !is_float($value)) { return false; }
        }
        return true;
    }
}
