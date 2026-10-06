<?php

namespace App\Realtime\Observability;

class RealtimeDiagnosticWriteResult
{
    public static function account(int|false $written, int $expected): array
    {
        return [
            'bytes' => $written === false ? 0 : $written,
            'complete' => $written === $expected,
            'partial' => $written !== false && $written > 0 && $written < $expected,
            'terminal_reason' => $written === $expected ? null : 'short_or_failed_write',
        ];
    }
}
