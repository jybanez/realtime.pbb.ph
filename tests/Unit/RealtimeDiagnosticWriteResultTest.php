<?php

namespace Tests\Unit;

use App\Realtime\Observability\RealtimeDiagnosticWriteResult;
use PHPUnit\Framework\TestCase;

class RealtimeDiagnosticWriteResultTest extends TestCase
{
    public function test_short_write_retains_actual_bytes_without_complete_record_claim(): void
    {
        $this->assertSame(['bytes' => 4, 'complete' => false, 'partial' => true, 'terminal_reason' => 'short_or_failed_write'], RealtimeDiagnosticWriteResult::account(4, 10));
    }

    public function test_failed_and_complete_writes_are_distinct(): void
    {
        $this->assertSame(['bytes' => 0, 'complete' => false, 'partial' => false, 'terminal_reason' => 'short_or_failed_write'], RealtimeDiagnosticWriteResult::account(false, 10));
        $this->assertSame(['bytes' => 10, 'complete' => true, 'partial' => false, 'terminal_reason' => null], RealtimeDiagnosticWriteResult::account(10, 10));
    }
}
