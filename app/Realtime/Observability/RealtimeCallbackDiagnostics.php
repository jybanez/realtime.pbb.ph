<?php

namespace App\Realtime\Observability;

use Illuminate\Database\Events\QueryExecuted;

/** Bounded in-memory aggregates for the current synchronous callback stack. */
class RealtimeCallbackDiagnostics
{
    private array $frames = [];
    private int $sequence = 0;

    public static function safeSignalType(mixed $value): string
    {
        return is_string($value) && in_array($value, [
            'ready', 'hangup', 'disconnect-request', 'hangup-confirm', 'hangup-complete',
            'heartbeat', 'browser-offline', 'browser-online', 'video-state', 'caller-location',
            'offer', 'answer', 'ice-candidate',
        ], true) ? $value : 'other';
    }

    public function begin(string $stage, array $context): void
    {
        $parent = $this->frames === [] ? null : $this->frames[array_key_last($this->frames)];
        $this->frames[] = [
            'span_id' => ++$this->sequence,
            'parent_span_id' => $parent['span_id'] ?? null,
            'context' => array_merge($parent['context'] ?? [], $context),
            'sql_count' => 0, 'sql_ms' => 0.0, 'sql_max_ms' => 0.0,
            'sql_operations' => [], 'sql_operation_ms' => [], 'child_ms' => 0.0,
        ];
    }

    public function query(QueryExecuted $query): void
    {
        if ($this->frames === []) {
            return;
        }
        $operation = strtoupper(strtok(ltrim($query->sql), " \t\r\n") ?: 'OTHER');
        if (!in_array($operation, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)) {
            $operation = 'OTHER';
        }
        foreach ($this->frames as &$frame) {
            ++$frame['sql_count'];
            $frame['sql_ms'] += $query->time;
            $frame['sql_max_ms'] = max($frame['sql_max_ms'], $query->time);
            $frame['sql_operations'][$operation] = ($frame['sql_operations'][$operation] ?? 0) + 1;
            $frame['sql_operation_ms'][$operation] = ($frame['sql_operation_ms'][$operation] ?? 0.0) + $query->time;
        }
    }

    public function end(float $elapsedMs): array
    {
        $frame = array_pop($this->frames);
        if ($this->frames !== []) {
            $this->frames[array_key_last($this->frames)]['child_ms'] += $elapsedMs;
        }
        return array_merge($frame['context'], [
            'span_id' => $frame['span_id'], 'parent_span_id' => $frame['parent_span_id'],
            'measured_child_ms' => round($frame['child_ms'], 3),
            'unaccounted_ms' => round(max(0, $elapsedMs - $frame['child_ms']), 3),
            'sql_count' => $frame['sql_count'], 'sql_ms' => round($frame['sql_ms'], 3),
            'sql_max_ms' => round($frame['sql_max_ms'], 3),
            'sql_operations' => $frame['sql_operations'], 'sql_scope' => 'inclusive',
            'sql_operation_ms' => array_map(fn ($ms) => round($ms, 3), $frame['sql_operation_ms']),
        ]);
    }
}
